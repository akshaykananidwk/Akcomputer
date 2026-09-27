// ---------------------------------------------------------------------------
// The app as a whole shop, not just a billing pad:
//   serial numbers on a bill, offline
//   the draft survives walking away from the screen
//   a party's statement and balance, offline
//   who owes what, offline
//   the day's figures, offline
//   what is on the shelf and which serials, offline
//   every website screen listed, and one of them actually opening
//
// Same two servers as 01-normal-day.mjs, then:
//     node mobile/test/03-the-whole-shop.mjs
// ---------------------------------------------------------------------------
import pkg from '/opt/node22/lib/node_modules/playwright/index.js';
const { chromium } = pkg;
const API = 'http://127.0.0.1:8088';
const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
const ctx = await b.newContext({ viewport: { width: 412, height: 860 } });
const p = await ctx.newPage();
let bad = 0;
const ok = (l, c, x) => { if (!c) bad++; console.log((c ? '  ✔ ' : '  ✘ ') + l + (x !== undefined ? '  → ' + x : '')); };
p.on('pageerror', e => { bad++; console.log('  ✘ JS ERROR: ' + e.message); });

const cutApi  = () => p.route('**/api.php**', r => r.abort());
const healApi = () => p.unroute('**/api.php**');

await p.goto('http://127.0.0.1:8099/index.html');
await p.waitForTimeout(500);
// the shop address now lives behind the "Server:" link, as it does for a real user
await p.click('#l_adv');
await p.fill('#l_srv', API);
await p.fill('#l_user', 'admin'); await p.fill('#l_pass', 'Test@1234');
await p.click('#l_go'); await p.waitForTimeout(5000);

console.log('=== 1. Everything landed on the phone ===');
const got = await p.evaluate(async () => ({
  items: await DB.count('items'), parties: await DB.count('parties'),
  serials: await DB.count('serials'), stock: await DB.count('stock'),
  ssales: await DB.count('ssales'), spayments: await DB.count('spayments'),
  menu: ((await DB.meta('menu', null)) || []).length
}));
console.log('  ' + JSON.stringify(got));
ok('items and parties', got.items > 0 && got.parties > 0);
ok('serial numbers from the shelf', got.serials > 0, got.serials);
ok('where the stock is', got.stock > 0, got.stock);
ok('the shop\'s own bills, not just this phone\'s', got.ssales > 0, got.ssales);
ok('its payments too', got.spayments > 0, got.spayments);
ok('and every screen the website has', got.menu >= 10, got.menu + ' groups');

console.log('\n=== 2. Cut the network — it must all still work ===');
await ctx.setOffline(true);
await p.evaluate(() => window.dispatchEvent(new Event('offline')));
await cutApi();
await p.waitForTimeout(300);

// --- who owes what
await p.click('.tabs button[data-go=dues]'); await p.waitForTimeout(700);
const duesTxt = await p.locator('#main').textContent();
ok('Dues opens with no signal', /Total to receive/.test(duesTxt));
const dueCount = await p.locator('#d_list li').count();
ok('...and lists the parties who owe', dueCount > 0, dueCount + ' parties');

// --- a party's statement
if (dueCount > 0) {
  await p.locator('#d_list li').first().click();
  await p.waitForTimeout(700);
  const t = await p.locator('#main').textContent();
  ok('a party\'s balance shows, offline', /To receive|Advance held|All settled/.test(t));
  ok('...with the statement under it', /Recent account/.test(t));
  ok('...and a WhatsApp reminder button', await p.locator('#p_remind').count() + await p.locator('#p_collect').count() > 0);
}

// --- reports
await p.evaluate(() => App.go('reports')); await p.waitForTimeout(700);
const rep = await p.locator('#main').textContent();
ok('the day\'s figures work out offline', /Today's sales/.test(rep));
ok('...sales for today, the week and the month', /Last 7 days/.test(rep) && /This month/.test(rep));
ok('...and what is owed both ways', /To receive/.test(rep) && /To pay/.test(rep));
ok('...honestly labelled as coming from the last sync', /last synced/.test(rep));

// --- stock
await p.evaluate(() => App.go('stock')); await p.waitForTimeout(500);
const stockItem = await p.evaluate(async () => {
  const ser = await DB.all('serials');
  const it = ser.length ? await DB.get('items', ser[0].item_id) : null;
  return it ? it.name : '';
});
await p.fill('#k_q', stockItem); await p.waitForTimeout(600);
const nres = await p.locator('#k_res div').count();
ok('an item is findable offline', nres > 0, nres + ' results');
if (nres) {
  await p.locator('#k_res div').first().click();
  await p.waitForTimeout(600);
  const st = await p.locator('#k_out').textContent();
  ok('...it says how much and where', /Where it is/.test(st));
  ok('...and lists the serial numbers on the shelf', /Serial numbers/.test(st));
}

console.log('\n=== 3. A bill with serial numbers, offline ===');
await p.evaluate(() => { App.bill = null; return DB.setMeta('draft', null); });
await p.evaluate(() => App.go('newbill')); await p.waitForTimeout(600);
await p.fill('#b_cust', 'Serial Test ' + Date.now().toString().slice(-6));
await p.fill('#b_mob', '9876500000');
// pick whatever serial-tracked item actually has serials on the shelf,
// rather than naming one: an earlier run may have sold them all
const snItem = await p.evaluate(async () => {
  const ser = await DB.all('serials');
  if (!ser.length) return null;
  const it = await DB.get('items', ser[0].item_id);
  return it ? it.name : null;
});
ok('there is a serial-tracked item with stock to test with', !!snItem, snItem);
await p.fill('#b_item', snItem || 'Test Mouse'); await p.waitForTimeout(600);
await p.locator('#b_itemres div').first().click();
await p.waitForTimeout(600);
const snBtn = await p.locator('[data-sn="0"]').count();
ok('a serial-tracked item asks for its serial numbers', snBtn > 0);

// the bill must REFUSE to save while the serial is missing
await p.click('#b_save'); await p.waitForTimeout(800);
const errTxt = await p.locator('#main').textContent();
const queuedEarly = await p.evaluate(async () => (await DB.all('outbox')).length);
ok('saving is refused while a serial is missing — nothing was written', queuedEarly === 0, 'queue=' + queuedEarly);
ok('...and it takes you straight to the box to fill', /On the shelf/.test(errTxt));

// pick one off the shelf
const shelf = await p.locator('#sn_list li[data-add]').count();
ok('the shelf\'s own serials are offered, with no signal', shelf > 0, shelf + ' on the shelf');
if (shelf > 0) {
  await p.locator('#sn_list li[data-add]').first().click();
  await p.waitForTimeout(400);
  ok('picked one', /1 \/ 1/.test(await p.locator('#main').textContent()));
  // and it cannot be picked twice
  const left = await p.locator('#sn_list li[data-add]').count();
  ok('...and it is gone from the shelf list, so it cannot go on twice', left === shelf - 1, left + ' left');
}
await p.click('#sn_done'); await p.waitForTimeout(600);

console.log('\n=== 4. The half-written bill is not lost ===');
const draft = await p.evaluate(async () => {
  const d = await DB.meta('draft', null);
  return d ? { lines: d.lines.length, serials: (d.lines[0].serials || []).length, cust: d.customer } : null;
});
ok('the half-written bill is on disk, not just in memory', draft && draft.lines === 1, JSON.stringify(draft));
ok('...with the serial it had already been given', draft && draft.serials === 1);
ok('...and the customer name typed at the top', draft && /Serial Test/.test(draft.cust || ''));

// Simulate Android killing the app mid-bill. The real app's page lives in
// the apk, so it reloads with no network; a desktop browser has to fetch it
// over http, so the transport goes back up while the API stays cut - the
// app still cannot reach the server, which is the thing being tested.
await p.evaluate(() => { App.bill = null; });
await ctx.setOffline(false);
await p.reload(); await p.waitForTimeout(1500);
await ctx.setOffline(true);
await p.evaluate(() => window.dispatchEvent(new Event('offline')));
await p.evaluate(() => App.go('newbill')); await p.waitForTimeout(900);
const after = await p.locator('#main').textContent();
ok('after the app is killed and reopened, the bill is still there', after.includes(snItem));
ok('...and so is the serial on it', /Serials 1\/1/.test(after));

console.log('\n=== 5. Save the bill + WhatsApp ===');
await p.fill('#b_paid', '100');
await p.click('#b_save'); await p.waitForTimeout(1200);
const done = await p.locator('#main').textContent();
ok('the bill saved offline', /Bill done|✅/.test(done) || (await p.locator('#dn_new').count()) > 0);
ok('...and says the number comes after syncing, rather than inventing one', /Number comes after syncing/.test(done));
ok('...with a WhatsApp button ready', (await p.locator('#dn_wa').count()) > 0);
const q = await p.evaluate(async () => (await DB.all('outbox')).length);
ok('...and it is queued to send', q === 1, 'queue=' + q);

console.log('\n=== 6. Network back — it goes up with its serial ===');
await healApi();
await ctx.setOffline(false);
await p.evaluate(() => window.dispatchEvent(new Event('online')));
await p.waitForTimeout(5000);
const sent = await p.evaluate(async () => {
  const out = await DB.all('outbox');
  const docs = (await DB.all('docs')).filter(d => !d.synced);
  const last = (await DB.all('docs')).sort((a, c) => (c.created_at || '').localeCompare(a.created_at || ''))[0];
  return { queue: out.length, unsynced: docs.length, inv: last && last.invoice_no };
});
ok('the queue emptied', sent.queue === 0, 'queue=' + sent.queue);
ok('and the bill came back with its invoice number', !!sent.inv, sent.inv);

// THE one that proves the serial really travelled: the piece that was picked
// offline must now be OFF the shop's shelf, in the shop's own database.
const soldSn = await p.evaluate(async () => {
  const d = (await DB.all('docs')).sort((a, c) => (c.created_at || '').localeCompare(a.created_at || ''))[0];
  return d && d.lines && d.lines[0] && (d.lines[0].serials || [])[0];
});
const stillOnShelf = await p.evaluate(async (sn) => {
  const tok = await DB.meta('token', ''), srv = await DB.meta('server', '');
  const r = await fetch(srv + '/api.php?r=sync&t=' + Date.now(), { cache: 'no-store', headers: { Authorization: 'Bearer ' + tok } });
  const j = await r.json();
  return (j.serials || []).some(s => s.serial_no === sn);
}, soldSn);
ok('the serial picked offline is now sold on the server, not on the shelf', !stillOnShelf, soldSn);

console.log('\n=== 7. Everything — every website screen ===');
await p.evaluate(() => App.go('all')); await p.waitForTimeout(900);
const groups = await p.locator('#a_out .card').count();
const links = await p.locator('#a_out li[data-h]').count();
ok('every group of the website menu is listed', groups >= 10, groups + ' groups');
ok('...and every screen under them', links >= 50, links + ' screens');
ok('the ones that work offline are marked', (await p.locator('#a_out li:has-text("Works offline")').count()) > 0);
await p.fill('#a_q', 'repair'); await p.waitForTimeout(400);
ok('the list is searchable', (await p.locator('#a_out li[data-h]').count()) < links);
await p.fill('#a_q', ''); await p.waitForTimeout(400);

// and one of them must actually open, logged in, without typing a password
const opened = await p.evaluate(async () => {
  try { return await Sync.webLink('reports.php'); } catch (e) { return 'ERR ' + e.message; }
});
ok('a one-time handover link is issued', /login\.php\?app=[0-9a-f]{64}$/.test(opened), opened);

// Opened the way the app opens it - by NAVIGATING, exactly as the WebView
// does when the owner taps the row.
await p.goto(opened); await p.waitForTimeout(1200);
ok('...and it lands on the real page, already logged in', /reports\.php/.test(p.url()), p.url());
ok('...not back at the login form', (await p.locator('input[name=password]').count()) === 0);

// THE one that matters: the key is spent. A link left in a log or a
// history must open nothing. The cookies go first - otherwise the second
// visit is already logged in from the first and proves nothing.
await ctx.clearCookies();
await p.goto(opened); await p.waitForTimeout(1200);
const replayed = p.url();
ok('the same link a second time does NOT log anybody in',
   /login\.php/.test(replayed) && !/reports\.php/.test(replayed), replayed);

// back into the app
await p.goto('http://127.0.0.1:8099/index.html'); await p.waitForTimeout(1500);

console.log('\n=== 8. Ask for a website screen with no network ===');
await ctx.setOffline(true);
await p.evaluate(() => window.dispatchEvent(new Event('offline')));
await p.evaluate(() => App.go('all')); await p.waitForTimeout(700);
await p.evaluate(() => More.openWeb('reports.php'));
await p.waitForTimeout(700);
const toast = await p.locator('#toast').textContent();
ok('it says plainly that this one needs a network', /needs a network/.test(toast), toast);
ok('...and the app is still on its own screen, not a browser error', (await p.locator('#a_out').count()) > 0);

await b.close();
console.log(bad ? `\n✗ ${bad} FAILED` : '\n✓ ALL PASS');
process.exit(bad ? 1 : 0);
