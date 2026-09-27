// ---------------------------------------------------------------------------
// The app on an ordinary day: log in, cache the masters, lose the network,
// write a bill anyway, get the network back, watch it go up by itself.
//
// Run (needs the website running and the app served over http, because
// IndexedDB is not allowed on file:// in a desktop browser - on the phone
// it is, inside the apk):
//     php -S 127.0.0.1:8088 -t .          # the website
//     php -S 127.0.0.1:8099 -t mobile/www # the app
//     node mobile/test/01-normal-day.mjs
// ---------------------------------------------------------------------------
import pkg from '/opt/node22/lib/node_modules/playwright/index.js';
const { chromium } = pkg;
const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
const ctx = await b.newContext({ viewport: { width: 412, height: 860 } });
const p = await ctx.newPage();
let errs = [];
p.on('pageerror', e => errs.push(e.message));
p.on('console', m => { if (m.type() === 'error') errs.push('console: ' + m.text()); });

let bad = 0;
const ok = (label, cond, extra) => { if (!cond) bad++; console.log((cond ? '  ✔ ' : '  ✘ ') + label + (extra !== undefined ? '  → ' + extra : '')); };

await p.goto('http://127.0.0.1:8099/index.html');
await p.waitForTimeout(600);

console.log('=== 1. Login ===');
ok('login screen shows', await p.locator('#l_go').count() > 0);
// the shop address now lives behind the "Server:" link, as it does for a real user
await p.click('#l_adv');
await p.fill('#l_srv', 'http://127.0.0.1:8088');
await p.click('#l_go');
await p.waitForTimeout(400);
ok('empty username is refused, nothing sent', (await p.locator('#l_err').textContent()).includes('username'));
await p.fill('#l_user', 'admin');
await p.fill('#l_pass', 'wrongpass');
await p.click('#l_go');
await p.waitForTimeout(1500);
ok('wrong password says so', (await p.locator('#l_err').textContent()).length > 3, await p.locator('#l_err').textContent());
await p.fill('#l_pass', 'Test@1234');
await p.click('#l_go');
await p.waitForTimeout(4000);
ok('logged in, home screen', await p.locator('#h_new').count() > 0);

const counts = await p.evaluate(async () => ({
  items: await DB.count('items'), parties: await DB.count('parties'),
  since: await DB.meta('since', null), token: (await DB.meta('token','')).length
}));
console.log('  master data pulled to the phone:', JSON.stringify(counts));
ok('items cached', counts.items > 0);
ok('parties cached', counts.parties > 0);
ok('sync cursor is the SERVER time', !!counts.since, counts.since);

console.log('\n=== 2. A bill with no network ===');
await ctx.setOffline(true);
await p.evaluate(() => window.dispatchEvent(new Event('offline')));
await p.waitForTimeout(300);
ok('offline banner shows', await p.locator('#netbar').isVisible());
await p.click('[data-go="newbill"]');
await p.waitForTimeout(400);
await p.fill('#b_item', 'a');
await p.waitForTimeout(500);
const found = await p.locator('#b_itemres div').count();
ok('items searchable with NO network', found > 0, found + ' results');
await p.locator('#b_itemres div').first().click();
await p.waitForTimeout(300);
await p.fill('#b_cust', 'Offline Customer');
await p.click('#b_full');
await p.waitForTimeout(200);
const grand = await p.locator('#b_grand').textContent();
await p.click('#b_save');
await p.waitForTimeout(800);
// saving now lands on the "done" screen - what a shop does next is send it
ok('bill saved while offline', (await p.locator('#dn_new').count()) > 0, 'total ' + grand);
ok('...and the number is honestly shown as pending, not invented',
   /Number comes after syncing/.test(await p.locator('#main').textContent()));
const q1 = await p.evaluate(async () => (await DB.all('outbox')).length);
ok('it is queued to send', q1 === 1, 'queue=' + q1);

console.log('\n=== 3. Network back — it goes by itself ===');
await ctx.setOffline(false);
await p.evaluate(() => window.dispatchEvent(new Event('online')));
await p.waitForTimeout(4000);
const after = await p.evaluate(async () => ({
  queue: (await DB.all('outbox')).length,
  docs: (await DB.all('docs')).map(d => ({ synced: d.synced, inv: d.invoice_no, total: d.total }))
}));
ok('queue emptied by itself', after.queue === 0, 'queue=' + after.queue);
ok('bill came back with its invoice number', !!(after.docs[0] && after.docs[0].inv), JSON.stringify(after.docs[0]));

console.log('\nJS errors: ' + (errs.length ? errs.join(' | ') : 'none'));
await b.close();
console.log(bad ? `\n\u2717 ${bad} FAILED` : '\n\u2713 ALL PASS');
process.exit(bad ? 1 : 0);
