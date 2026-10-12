// ---------------------------------------------------------------------------
// Everything that moves money or stock, from the phone, with no signal:
// a GST bill, a supplier's purchase bill, money paid out, an expense and
// money received - then the network comes back and the SERVER's books are
// checked: GST, stock, the cost price, the cash book.
//
//     php -S 127.0.0.1:8088 -t .  ;  php -S 127.0.0.1:8099 -t mobile/www
//     node mobile/test/05-money-in-and-out.mjs
// ---------------------------------------------------------------------------
import pkg from '/opt/node22/lib/node_modules/playwright/index.js';
import { execFileSync } from 'child_process';
const ROOT = new URL('../../', import.meta.url).pathname;
const php = (code) => execFileSync('php', ['-r', 'require "' + ROOT + 'includes/init.php"; ' + code]).toString().trim();

// fixtures: a GST item, a supplier we owe, on a GST firm
const RUN = Date.now().toString(36);
const [ITEM, SUP, CO] = php(`
  $co = (int)val('SELECT id FROM companies WHERE is_active = 1 AND is_gst = 1 ORDER BY id LIMIT 1');
  q("INSERT INTO items (name, selling_price, purchase_price, tax_rate, is_active, item_type) VALUES (?, 1000, 600, 18, 1, 'product')", ['APPGST ${RUN}']);
  $it = insert_id(); adjust_stock($it, (int)val('SELECT id FROM locations ORDER BY id LIMIT 1'), 5, 'opening', null, 'app test');
  q("INSERT INTO parties (name, type, mobile, opening_balance) VALUES (?, 'supplier', '', -500)", ['APPSUP ${RUN}']);
  echo $it, ' ', insert_id(), ' ', $co;`).split(' ').map(Number);

const b = await pkg.chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
const ctx = await b.newContext({ viewport: { width: 390, height: 844 } });
const p = await ctx.newPage();
let errs = [], bad = 0;
p.on('pageerror', e => errs.push(e.message));
p.on('dialog', d => d.accept());
const ok = (label, cond, extra) => { if (!cond) bad++; console.log((cond ? '  ✔ ' : '  ✘ ') + label + (extra !== undefined ? '  → ' + extra : '')); };
const pick = async (input, text, res) => { await p.fill(input, text); await p.waitForTimeout(400); await p.locator(res + ' div[data-i]').first().click(); await p.waitForTimeout(200); };

await p.goto('http://127.0.0.1:8099/index.html');
await p.evaluate(async () => { await DB.clearAll(); });
await p.reload(); await p.waitForTimeout(500);
await p.click('#l_adv'); await p.fill('#l_srv', 'http://127.0.0.1:8088');
await p.fill('#l_user', 'admin'); await p.fill('#l_pass', 'Test@1234');
await p.click('#l_go'); await p.waitForTimeout(4500);

console.log('=== Home: the day\'s jobs, as this login may do them ===');
const acts = await p.$$eval('[data-act]', bs => bs.map(b => b.dataset.act));
ok('bill, money in, purchase, money out and expense are all on Home', ['newbill', 'collect', 'purchase', 'payout', 'expense'].every(a => acts.includes(a)), acts.join(','));
const wide = await p.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth);
ok('Home fits a phone with no sideways scroll', wide);

await ctx.setOffline(true);
await p.evaluate(() => window.dispatchEvent(new Event('offline')));

console.log('\n=== A GST bill, offline ===');
await p.click('[data-act="newbill"]'); await p.waitForTimeout(500);
if (CO) await p.evaluate((co) => { App.bill.company_id = co; App.renderLines(); }, CO);
await pick('#b_item', 'APPGST ' + RUN, '#b_itemres');
await p.fill('#b_cust', 'GST buyer ' + RUN);
await p.waitForTimeout(200);
const shown = await p.locator('#b_grand').textContent();
if (CO) ok('the phone shows the total WITH GST, rounded', /1,180\.00/.test(shown), shown);
await p.click('#b_full'); await p.click('#b_save'); await p.waitForTimeout(700);
ok('bill saved with no network', await p.locator('#dn_new').count() > 0);

console.log('\n=== A supplier\'s purchase bill, offline ===');
await p.click('[data-go="home"]'); await p.waitForTimeout(300);
await p.click('[data-act="purchase"]'); await p.waitForTimeout(400);
await pick('#u_party', 'APPSUP ' + RUN, '#u_pres');
if (CO) await p.evaluate((co) => { Entries.pur.company_id = co; }, CO);
await p.fill('#u_no', 'SB-' + RUN);
await pick('#u_item', 'APPGST ' + RUN, '#u_ires');
await p.locator('[data-f="qty"]').fill('3'); await p.locator('[data-f="qty"]').dispatchEvent('change');
await p.locator('[data-f="price"]').fill('500'); await p.locator('[data-f="price"]').dispatchEvent('change');
await p.waitForTimeout(200);
ok('purchase total = 3 × 500 + 18% GST', /1,770\.00/.test(await p.locator('#u_tot').textContent()), await p.locator('#u_tot').textContent());
await p.click('#u_save'); await p.waitForTimeout(600);
ok('purchase saved, back on Home', await p.locator('[data-act="purchase"]').count() > 0);
const refused = await (async () => { await p.click('[data-act="purchase"]'); await p.waitForTimeout(300); await p.click('#u_save'); await p.waitForTimeout(200); return p.locator('#u_err').textContent(); })();
ok('a purchase with no supplier is stopped on the phone', /supplier/i.test(refused), refused);

console.log('\n=== Money paid to that supplier, offline ===');
await p.click('[data-go="home"]'); await p.waitForTimeout(300);
await p.click('[data-act="payout"]'); await p.waitForTimeout(400);
await pick('#o_party', 'APPSUP ' + RUN, '#o_res');
ok('it shows what we owe them', /We owe ₹500\.00/.test(await p.locator('#o_bal').textContent()), await p.locator('#o_bal').textContent());
await p.fill('#o_amt', '200'); await p.click('#o_save'); await p.waitForTimeout(500);

console.log('\n=== An expense, offline ===');
await p.click('[data-act="expense"]'); await p.waitForTimeout(400);
await p.fill('#e_amt', '45'); await p.fill('#e_note', 'tea ' + RUN); await p.click('#e_save'); await p.waitForTimeout(500);
const q = await p.evaluate(async () => (await DB.all('outbox')).map(r => r.kind).sort().join(','));
ok('all four wait in the queue', q === 'expense,payout,purchase,sale', q);

console.log('\n=== Network back: they go up by themselves ===');
await ctx.setOffline(false);
await p.evaluate(() => window.dispatchEvent(new Event('online')));
await p.waitForTimeout(6000);
const left = await p.evaluate(async () => (await DB.all('outbox')).map(r => r.kind + ':' + r.state + ':' + r.last_error));
ok('queue empty', left.length === 0, left.join(' | '));

const srv = JSON.parse(php(`
  $s = row("SELECT * FROM sales WHERE customer_name = ?", ['GST buyer ${RUN}']);
  $pu = row("SELECT * FROM purchases WHERE bill_no = ?", ['SB-${RUN}']);
  echo json_encode(['sale' => $s, 'pur' => $pu,
    'stock' => (float)val('SELECT COALESCE(SUM(qty),0) FROM stock WHERE item_id = ?', [${ITEM}]),
    'cost' => (float)val('SELECT purchase_price FROM items WHERE id = ?', [${ITEM}]),
    'out' => (float)val("SELECT COALESCE(SUM(amount),0) FROM payments WHERE party_id = ? AND direction = 'out'", [${SUP}]),
    'exp' => (float)val("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE notes = ?", ['tea ${RUN}']),
    'sale_cash' => $s ? (float)val("SELECT COALESCE(SUM(amount),0) FROM payments WHERE ref_type = 'sale' AND ref_id = ?", [$s['id']]) : 0]);`));
ok('the bill reached the shop', !!srv.sale, srv.sale && srv.sale.invoice_no);
if (CO) ok('...with GST charged by the server: ₹180', srv.sale && Math.abs(srv.sale.tax_amount - 180) < 0.01, srv.sale && srv.sale.tax_amount);
ok('...and the cash taken is in the cash book', Math.abs(srv.sale_cash - (srv.sale ? srv.sale.total : -1)) < 0.01, srv.sale_cash);
ok('the purchase reached the shop', !!srv.pur, srv.pur && srv.pur.total);
if (CO) ok('...with the same total the phone showed: ₹1,770 (GST firm)', srv.pur && Math.abs(srv.pur.total - 1770) < 0.01, srv.pur && srv.pur.total);
ok('stock: 5 − 1 sold + 3 bought = 7', srv.stock === 7, srv.stock);
ok('the cost price is now the supplier\'s ₹500', srv.cost === 500, srv.cost);
ok('₹200 paid out to the supplier is in the books', srv.out === 200, srv.out);
ok('the ₹45 expense is in the books', srv.exp === 45, srv.exp);

console.log('\n=== Money received uses the shop\'s own ways of paying ===');
await p.click('[data-go="home"]'); await p.waitForTimeout(300);
await p.click('[data-act="collect"]'); await p.waitForTimeout(500);
const modes = await p.$$eval('#c_mode option', os => os.map(o => o.value));
ok('the list is the shop\'s payment methods', modes.length > 0 && modes.includes('cash'), modes.join(','));
ok('...and "credit" is not offered as a way of paying', !modes.includes('credit'));
ok('a WhatsApp receipt can go with it', await p.locator('#c_wa').count() === 1);

console.log('\n=== A bill from the list opens its own screen ===');
await p.click('[data-go="bills"]'); await p.waitForTimeout(600);
await p.fill('#bl_q', 'GST buyer ' + RUN); await p.waitForTimeout(300);
await p.locator('#bl_box li').first().click(); await p.waitForTimeout(400);
ok('the bill screen offers the full bill (print, PDF, e-mail)', await p.locator('#bv_open').count() === 1);

console.log('\nJS errors: ' + (errs.length ? errs.join(' | ') : 'none'));
await b.close();
// leave the shop as it was
php(`
  foreach (all("SELECT id, location_id FROM sales WHERE customer_name = ?", ['GST buyer ${RUN}']) as $s) {
    adjust_stock(${ITEM}, $s['location_id'], 1, 'test', $s['id'], 'cleanup');
    q("DELETE FROM payments WHERE ref_type = 'sale' AND ref_id = ?", [$s['id']]); q('DELETE FROM sale_items WHERE sale_id = ?', [$s['id']]); q('DELETE FROM sales WHERE id = ?', [$s['id']]);
    q("DELETE FROM parties WHERE name = ?", ['GST buyer ${RUN}']); }
  foreach (all("SELECT id FROM purchases WHERE bill_no = ?", ['SB-${RUN}']) as $pu) {
    q('DELETE FROM purchase_items WHERE purchase_id = ?', [$pu['id']]); q("DELETE FROM payments WHERE ref_type = 'purchase' AND ref_id = ?", [$pu['id']]); q('DELETE FROM purchases WHERE id = ?', [$pu['id']]); }
  q("DELETE FROM payment_allocations WHERE payment_id IN (SELECT id FROM payments WHERE party_id = ?)", [${SUP}]);
  q('DELETE FROM payments WHERE party_id = ?', [${SUP}]); q('DELETE FROM parties WHERE id = ?', [${SUP}]);
  q("DELETE FROM expenses WHERE notes = ?", ['tea ${RUN}']);
  q('DELETE FROM stock_ledger WHERE item_id = ?', [${ITEM}]); q('DELETE FROM stock WHERE item_id = ?', [${ITEM}]); q('DELETE FROM items WHERE id = ?', [${ITEM}]);
  q("UPDATE api_tokens SET revoked = 1 WHERE device = 'Android' AND user_id = (SELECT id FROM users WHERE username = 'admin')");`);
console.log(bad ? `\n✗ ${bad} FAILED` : '\n✓ ALL PASS');
process.exit(bad ? 1 : 0);
