// ---------------------------------------------------------------------------
// The whole shop on the phone. After one sync every book is there (not a
// 90-day window), the network goes off, and every screen still opens - and a
// party's balance on the phone equals the website's to the paisa, for EVERY
// party. Then a bill deleted on the website disappears from the phone.
//
//     php -S 127.0.0.1:8088 -t .  ;  php -S 127.0.0.1:8099 -t mobile/www
//     node mobile/test/08-whole-shop-offline.mjs
// ---------------------------------------------------------------------------
import pkg from '/opt/node22/lib/node_modules/playwright/index.js';
import { execFileSync } from 'child_process';
const ROOT = new URL('../../', import.meta.url).pathname;
const php = (code) => execFileSync('php', ['-r', 'require "' + ROOT + 'includes/init.php"; ' + code]).toString().trim();

const srv = JSON.parse(php(`echo json_encode([
  'bal' => array_column(all('SELECT p.id, ' . party_balance_expr('p') . ' b FROM parties p'), 'b', 'id'),
  'n' => ['sales' => (int)val('SELECT COUNT(*) FROM sales'), 'purchases' => (int)val('SELECT COUNT(*) FROM purchases'),
          'payments' => (int)val('SELECT COUNT(*) FROM payments'), 'expenses' => (int)val('SELECT COUNT(*) FROM expenses')]]);`));

const b = await pkg.chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
const ctx = await b.newContext({ viewport: { width: 390, height: 844 } });
const p = await ctx.newPage();
let errs = [], bad = 0;
p.on('pageerror', e => errs.push(e.message));
const ok = (label, cond, extra) => { if (!cond) bad++; console.log((cond ? '  ✔ ' : '  ✘ ') + label + (extra !== undefined ? '  → ' + extra : '')); };

await p.goto('http://127.0.0.1:8099/index.html');
await p.evaluate(async () => { await DB.clearAll(); }); await p.reload(); await p.waitForTimeout(500);
await p.click('#l_adv'); await p.fill('#l_srv', 'http://127.0.0.1:8088');
await p.fill('#l_user', 'admin'); await p.fill('#l_pass', 'Test@1234'); await p.click('#l_go');
await p.waitForTimeout(7000);

console.log('=== Every book is on the phone, not a window ===');
const n = await p.evaluate(async () => ({ sales: await DB.count('ssales'), purchases: await DB.count('spurchases'),
  payments: await DB.count('spayments'), expenses: await DB.count('sexpenses') }));
for (const k of Object.keys(srv.n)) ok(`all ${k}: ${n[k]} of ${srv.n[k]}`, n[k] === srv.n[k]);
ok('a bill carries its lines', await p.evaluate(async () => (await DB.all('ssales')).every(s => Array.isArray(s.lines))));

console.log('\n=== Every party\'s balance equals the website\'s ===');
const phone = await p.evaluate(async () => Books.balances(await Books.load()));
const diffs = Object.keys(srv.bal).filter(id => Math.abs((phone[id] || 0) - parseFloat(srv.bal[id])) > 0.009);
ok(`${Object.keys(srv.bal).length} parties checked, ${diffs.length} different`, diffs.length === 0,
   diffs.slice(0, 5).map(id => id + ': phone ' + phone[id] + ' / site ' + srv.bal[id]).join(', '));
const stmtOk = await p.evaluate(async () => {
  const bk = await Books.load(), bal = Books.balances(bk);
  return bk.parties.every(pt => Math.abs(Books.ledger(bk, pt).balance - (bal[pt.id] || 0)) < 0.01);
});
ok('every statement ends at that same balance (opening + every line)', stmtOk);

console.log('\n=== No network: every screen still opens ===');
await ctx.setOffline(true);
await p.evaluate(() => window.dispatchEvent(new Event('offline')));
const ids = await p.evaluate(async () => ({ party: ((await DB.all('ssales')).find(s => s.party_id) || {}).party_id,
  sale: (await DB.all('ssales'))[0].id, item: (await DB.all('items'))[0].id }));
for (const [s, a] of [['parties'], ['party', ids.party], ['items'], ['item', ids.item], ['sales'], ['billview', ids.sale], ['purchases'],
                      ['payments'], ['expenses'], ['cashbank'], ['reports'], ['daybook', 'all'], ['salesreg'], ['outstanding'],
                      ['gstsum', 'all'], ['stocksum'], ['settings'], ['partyedit'], ['itemedit']]) {
  await p.evaluate(([s, a]) => App.go(s, a), [s, a]); await p.waitForTimeout(300);
  const t = (await p.locator('#main').innerText()).trim();
  const wide = await p.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth);
  ok(`${s} opens offline, fits the phone`, t.length > 20 && !/Sync once/.test(t) && wide, t.replace(/\s+/g, ' ').slice(0, 50));
}
await p.evaluate(() => App.go('outstanding')); await p.waitForTimeout(300);
const sum = await p.evaluate(async () => (await DB.meta('summary', {})).receivable);
ok('"They owe you" total equals the books\' receivable', (await p.locator('#main h3').first().textContent()).includes(sum.toLocaleString('en-IN', { minimumFractionDigits: 2 })), sum);

console.log('\n=== Add a party with no network; it reaches the shop ===');
const RUN = Date.now().toString(36);
await p.evaluate(() => App.go('partyedit')); await p.waitForTimeout(300);
await p.fill('#pe_name', 'Offline Party ' + RUN); await p.fill('#pe_city', 'Dwarka'); await p.click('#pe_save'); await p.waitForTimeout(500);
ok('saved on the phone, listed as not sent', /Offline Party/.test(await p.locator('#main').innerText()));
await ctx.setOffline(false);
await p.evaluate(() => window.dispatchEvent(new Event('online'))); await p.waitForTimeout(7000);
ok('it is on the website now', php(`echo (int)val('SELECT COUNT(*) FROM parties WHERE name = ?', ['Offline Party ${RUN}']);`) === '1');

console.log('\n=== A bill deleted on the website leaves the phone ===');
const gone = php(`q("INSERT INTO sales (company_id, location_id, invoice_no, sale_date, subtotal, total, paid, payment_mode, status, created_by) VALUES (1, 1, 'DEL-${RUN}', CURDATE(), 1, 1, 0, 'credit', ?, 1)", [payment_status(1, 0)]); echo insert_id();`);
await p.evaluate(() => App.syncNow(true)); await p.waitForTimeout(5000);
ok('the new bill came down', await p.evaluate(async (id) => !!(await DB.get('ssales', +id)), gone));
php(`q('DELETE FROM sales WHERE id = ?', [${gone}]);`);
await p.evaluate(() => App.syncNow(true)); await p.waitForTimeout(5000);
ok('...and is gone from the phone once deleted there', await p.evaluate(async (id) => !(await DB.get('ssales', +id)), gone));

php(`q('DELETE FROM parties WHERE name = ?', ['Offline Party ${RUN}']); q("UPDATE api_tokens SET revoked = 1 WHERE device = 'Android'");`);
console.log('\nJS errors: ' + (errs.length ? errs.join(' | ') : 'none'));
await b.close();
console.log(bad ? `\n✗ ${bad} FAILED` : '\n✓ ALL PASS');
process.exit(bad ? 1 : 0);
