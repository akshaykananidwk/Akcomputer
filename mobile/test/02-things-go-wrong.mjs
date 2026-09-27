// ---------------------------------------------------------------------------
// The app when things go wrong - the cases that decide whether money is
// lost or duplicated:
//   the reply disappears on the way back   (must not become two bills)
//   the app is killed with a bill unsent   (must survive)
//   the server refuses the bill for ever   (must stop retrying, must be shown)
//   the token is revoked                   (must keep the queue)
//
// Same two servers as 01-normal-day.mjs, then:
//     node mobile/test/02-things-go-wrong.mjs
// ---------------------------------------------------------------------------
import pkg from '/opt/node22/lib/node_modules/playwright/index.js';
const { chromium } = pkg;
const RUN = 'R' + Date.now().toString().slice(-6);     // unique per run
const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
const ctx = await b.newContext({ viewport: { width: 412, height: 860 } });
const p = await ctx.newPage();
let bad = 0;
const ok = (l, c, x) => { if (!c) bad++; console.log((c ? '  ✔ ' : '  ✘ ') + l + (x !== undefined ? '  → ' + x : '')); };

await p.goto('http://127.0.0.1:8099/index.html');
await p.waitForTimeout(500);
await p.fill('#l_srv', 'http://127.0.0.1:8088');
await p.fill('#l_user', 'admin'); await p.fill('#l_pass', 'Test@1234');
await p.click('#l_go'); await p.waitForTimeout(4000);
const token = await p.evaluate(() => DB.meta('token', ''));

async function makeBill(customer) {
  await p.click('[data-go="newbill"]'); await p.waitForTimeout(400);
  await p.fill('#b_item', 'a'); await p.waitForTimeout(500);
  await p.locator('#b_itemres div').first().click(); await p.waitForTimeout(250);
  await p.fill('#b_cust', customer);
  await p.click('#b_full'); await p.waitForTimeout(150);
  await p.click('#b_save'); await p.waitForTimeout(700);
}
// "no signal" without breaking the page itself, which in the real app is
// bundled in the apk and never needs the network
const cutApi = async () => p.route('**/api.php**', r => r.abort('failed'));
const healApi = async () => p.unroute('**/api.php**');

console.log('=== 4. જવાબ ખોવાઈ જાય (સૌથી ખતરનાક કેસ) ===');
const NAME4 = 'જવાબ ખોવાયો ' + RUN;
let killed = 0;
await p.route('**/api.php?r=sales', async route => {
  if (killed++ === 0) {
    const req = route.request();
    await fetch('http://127.0.0.1:8088/api.php?r=sales', {          // server DOES save it
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Authorization': req.headers()['authorization'] },
      body: req.postData()
    });
    await route.abort('failed');                                     // reply lost on the way back
  } else { await route.continue(); }
});
await makeBill(NAME4);
await p.waitForTimeout(2500);
let q = await p.evaluate(async () => (await DB.all('outbox')).length);
ok('the phone kept it and will try again', q === 1, 'queue=' + q);
await p.click('#syncBtn'); await p.waitForTimeout(3500);
const st4 = await p.evaluate(async (nm) => {
  const d = (await DB.all('docs')).find(x => x.customer === nm);
  return { q: (await DB.all('outbox')).length, inv: d && d.invoice_no };
}, NAME4);
ok('the retry went through', st4.q === 0, 'queue=' + st4.q);
ok('and it has ONE invoice number', !!st4.inv, st4.inv);
await p.unroute('**/api.php?r=sales');

const list = await (await fetch('http://127.0.0.1:8088/api.php?r=sales&from=2000-01-01&to=2099-01-01&limit=200',
  { headers: { Authorization: 'Bearer ' + token } })).json();
const dupes = (list.data || []).filter(s => s.customer_name === NAME4);
ok('the server has exactly ONE such bill (not two)', dupes.length === 1, dupes.length + ' found');

console.log('\n=== 5. એપ બંધ કરીને ફરી ખોલો ===');
await cutApi();
await makeBill('બંધ-ખોલ ' + RUN);
await p.reload();                    // app killed and reopened
await p.waitForTimeout(1500);
const after = await p.evaluate(async () => ({
  q: (await DB.all('outbox')).length, docs: (await DB.all('docs')).length,
  onHome: !!document.getElementById('h_new')
}));
ok('the unsent bill survived the restart', after.q === 1, 'queue=' + after.q);
ok('every bill is still on the phone', after.docs >= 2, after.docs + ' docs');
ok('it opened straight to Home — no login again', after.onHome);
await healApi();

console.log('\n=== 6. સર્વર કાયમ માટે ના પાડે ===');
await p.route('**/api.php?r=sales', route => route.fulfill({
  status: 422, contentType: 'application/json', body: JSON.stringify({ error: 'Invalid item_id 999' })
}));
await p.click('#syncBtn'); await p.waitForTimeout(3000);
const att = await p.evaluate(async () => (await DB.all('outbox')).filter(r => r.state === 'attention'));
ok('parked as "needs attention", not retried for ever', att.length === 1, att.length ? att[0].last_error : '(none)');
await p.evaluate(() => App.go('sync')); await p.waitForTimeout(700);
ok('the owner is shown it, with the server\'s own words', (await p.locator('body').textContent()).includes('Invalid item_id'));
ok('and the bill itself is still on the phone', (await p.evaluate(async () => (await DB.all('docs')).length)) >= 2);
await p.unroute('**/api.php?r=sales');

console.log('\n=== 7. ટોકન રદ થાય ===');
await cutApi();                       // so the bill really sits in the queue
await makeBill('ટોકન ટેસ્ટ ' + RUN);
await healApi();
await p.evaluate(() => DB.setMeta('token', 'deadtokendeadtokendeadtokendead'));
await p.click('#syncBtn'); await p.waitForTimeout(3000);
const q7 = await p.evaluate(async () => (await DB.all('outbox')).filter(r => r.state === 'pending').length);
ok('the queue is NOT thrown away when the token dies', q7 >= 1, 'pending=' + q7);
ok('and it asks to log in again', (await p.locator('body').textContent()).includes('લોગિન'));

await b.close();
console.log(bad ? `\n\u2717 ${bad} FAILED` : '\n\u2713 ALL PASS');
process.exit(bad ? 1 : 0);
