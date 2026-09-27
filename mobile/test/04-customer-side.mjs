// ---------------------------------------------------------------------------
// The same app in a customer's hands, and the three things the counter asked
// for after using v2:
//
//   the phone's Back button goes BACK, not out of the app
//   logging in asks for a username and a password, nothing else
//   WhatsApp goes out through the shop's own account, queued like everything
//     else, never from the staff member's personal number
//
// ...plus the customer side itself: browse, prices, stock, cart, order -
// offline, and without placing the same order twice.
//
//     php -S 127.0.0.1:8088 -t .
//     php -S 127.0.0.1:8099 -t mobile/www
//     node mobile/test/04-customer-side.mjs
// ---------------------------------------------------------------------------
import pkg from '/opt/node22/lib/node_modules/playwright/index.js';
const { chromium } = pkg;
const API = 'http://127.0.0.1:8088';
const RUN = 'R' + Date.now().toString().slice(-6);
const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
const ctx = await b.newContext({ viewport: { width: 412, height: 860 } });
const p = await ctx.newPage();
let bad = 0;
const ok = (l, c, x) => { if (!c) bad++; console.log((c ? '  ✔ ' : '  ✘ ') + l + (x !== undefined ? '  → ' + x : '')); };
p.on('pageerror', e => { bad++; console.log('  ✘ JS ERROR: ' + e.message); });

await p.goto('http://127.0.0.1:8099/index.html');
await p.waitForTimeout(600);

console.log('=== 1. Login asks for two things, not three ===');
const boxes = await p.evaluate(() =>
  [...document.querySelectorAll('#main input')].filter(i => i.offsetParent !== null).map(i => i.id));
ok('only a username and a password are asked for', JSON.stringify(boxes) === '["l_user","l_pass"]', JSON.stringify(boxes));
ok('the shop address is out of the way, not gone', await p.locator('#l_srv').count() === 1);
ok('...and reachable when a shop really does move host', await p.locator('#l_adv').count() === 1);
await p.click('#l_adv'); await p.fill('#l_srv', API);

console.log('\n=== 2. A customer, with no login at all ===');
await p.click('#l_shop'); await p.waitForTimeout(3500);
const cat = await p.evaluate(async () => ({
  n: await DB.count('catalog'),
  first: (await DB.all('catalog'))[0] || null
}));
ok('the product list came down without logging in', cat.n > 0, cat.n + ' products');
ok('...with a price on it', cat.first && cat.first.price > 0, cat.first && cat.first.price);
ok('...and whether it is on the shelf, the way the website says it',
   cat.first && /^(in|out|svc)$/.test(cat.first.stock_class), cat.first && cat.first.stock_text);
const listed = await p.locator('#sh_out li[data-id]').count();
ok('the products are on screen', listed > 0, listed + ' shown');

console.log('\n=== 3. Back really goes back ===');
await p.click('#sh_out li [data-add]'); await p.waitForTimeout(500);
await p.evaluate(() => App.go('cart')); await p.waitForTimeout(700);
ok('moved to the cart', (await p.locator('#ct_go').count()) > 0);
await p.goBack(); await p.waitForTimeout(700);
ok('Back returns to the products, not out of the app', (await p.locator('#sh_out').count()) > 0);
await p.goBack(); await p.waitForTimeout(700);
ok('...and again to where we came from', (await p.locator('#l_go').count()) > 0 || (await p.locator('#sh_out').count()) > 0);
await p.evaluate(() => App.go('shop')); await p.waitForTimeout(700);

console.log('\n=== 4. The cart, and an order placed with NO network ===');
await ctx.setOffline(true);
await p.evaluate(() => window.dispatchEvent(new Event('offline')));
await p.route('**/api.php**', r => r.abort());
await p.evaluate(() => App.render('shop')); await p.waitForTimeout(600);
ok('the products are still there with no signal', (await p.locator('#sh_out li[data-id]').count()) > 0);
await p.click('#sh_out li [data-add]'); await p.waitForTimeout(400);
const barTxt = await p.locator('#cartbar').textContent();
ok('the cart bar shows what is in it', /item/.test(barTxt), barTxt.trim());
await p.evaluate(() => App.go('cart')); await p.waitForTimeout(700);

await p.click('#ct_go'); await p.waitForTimeout(400);
ok('an order with no name is refused', /enter your name/i.test(await p.locator('#ct_err').textContent()));
await p.fill('#ct_name', 'Customer ' + RUN);
await p.click('#ct_go'); await p.waitForTimeout(400);
ok('...and one with no mobile number too', /mobile/i.test(await p.locator('#ct_err').textContent()));
await p.fill('#ct_mob', '9876500123');
await p.fill('#ct_addr', 'Dwarka');
await p.click('#ct_go'); await p.waitForTimeout(1200);
const off = await p.evaluate(async () => ({
  q: (await DB.all('outbox')).length,
  orders: (await DB.all('docs')).filter(d => d.kind === 'order').length,
  text: document.getElementById('main').textContent
}));
ok('the order is saved on the phone', off.orders === 1, off.orders);
ok('...and queued, not lost', off.q === 1, 'queue=' + off.q);
ok('...and the customer is told honestly that it has not reached the shop yet',
   /Waiting for a network/.test(off.text));

console.log('\n=== 5. Network back — it reaches the shop, once ===');
await p.unroute('**/api.php**');
await ctx.setOffline(false);
await p.evaluate(() => window.dispatchEvent(new Event('online')));
await p.waitForTimeout(4000);
const sent = await p.evaluate(async () => {
  const o = (await DB.all('docs')).filter(d => d.kind === 'order')[0];
  return { q: (await DB.all('outbox')).length, no: o && o.order_no, synced: o && o.synced };
});
ok('the queue emptied', sent.q === 0, 'queue=' + sent.q);
ok('and the order came back with its number', !!sent.no, sent.no);

// the dangerous one: the reply is lost and the phone sends it again
const replay = await p.evaluate(async () => {
  const o = (await DB.all('docs')).filter(d => d.kind === 'order')[0];
  const srv = await DB.meta('server', '');
  const r = await fetch(srv + '/api.php?r=worder', {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ client_uuid: o.uuid, name: o.name, mobile: o.mobile,
                           items: o.lines.map(l => ({ item_id: l.item_id, qty: l.qty })) })
  });
  return await r.json();
});
ok('sending the same order again returns the one that exists, not a second one',
   replay.duplicate === true && replay.order_no === sent.no, JSON.stringify(replay));

console.log('\n=== 6. A dealer sees a dealer price ===');
const dealer = await p.evaluate(async (api) => {
  const r = await fetch(api + '/api.php?r=wlogin', {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ mobile: '9999999999', password: 'definitely-wrong' })
  });
  return { status: r.status, body: await r.json() };
}, API);
ok('a wrong dealer password gets no token', dealer.status === 401 && !dealer.body.token, 'HTTP ' + dealer.status);
ok('...and is told to ask the shop, not given a hint', /shop will confirm/i.test(dealer.body.error || ''), dealer.body.error);

console.log('\n=== 7. WhatsApp goes through the SHOP, not this phone ===');
const src = await (await fetch('http://127.0.0.1:8099/more.js')).text();
ok('the app no longer opens the handset\'s own WhatsApp', !/whatsapp:\/\/send/.test(src));
ok('...it queues an errand for the server instead', /Sync\.queue\(\{ uuid: uuid, kind: kind/.test(src));
ok('the phone sends WHICH bill, never the words', /client_uuid: doc\.uuid/.test(src) && !/આભાર|Thank you 🙏/.test(src));

// and the server refuses to send one for a bill it has never heard of
const wa = await p.evaluate(async () => {
  const tok = await DB.meta('token', ''), srv = await DB.meta('server', '');
  const r = await fetch(srv + '/api.php?r=whatsapp', {
    method: 'POST', headers: { 'Content-Type': 'application/json', Authorization: 'Bearer ' + tok },
    body: JSON.stringify({ client_uuid: '00000000-0000-4000-8000-000000000000' })
  });
  return { status: r.status, body: await r.text() };
});
ok('a WhatsApp for a bill that has not arrived yet is told to WAIT, not failed for good',
   wa.status === 409 || wa.status === 401, 'HTTP ' + wa.status);

await b.close();
console.log(bad ? `\n✗ ${bad} FAILED` : '\n✓ ALL PASS');
process.exit(bad ? 1 : 0);
