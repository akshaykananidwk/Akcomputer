// ---------------------------------------------------------------------------
// Linking payments to bills after the fact, through the website at phone
// width: take money as an advance, link part of it to one bill and the rest
// to another, see the colours change, unlink from the bill's own page,
// relink - and the party's balance never moves, no payment is created or
// deleted on the way.
//
//     php -S 127.0.0.1:8088 -t .  ;  node mobile/test/10-payment-links.mjs
// ---------------------------------------------------------------------------
import pkg from '/opt/node22/lib/node_modules/playwright/index.js';
import { execFileSync } from 'child_process';
const ROOT = new URL('../../', import.meta.url).pathname;
const php = (code) => execFileSync('php', ['-r', 'require "' + ROOT + 'includes/init.php"; ' + code]).toString().trim();
const S = 'http://127.0.0.1:8088/';
const RUN = Date.now().toString(36);
const [PARTY, B1, B2] = php(`
  q("INSERT INTO parties (name, type, mobile) VALUES (?, 'both', '')", ['PL Party ${RUN}']); $p = insert_id();
  $co = (int)val('SELECT id FROM companies ORDER BY id LIMIT 1'); $loc = (int)val('SELECT id FROM locations ORDER BY id LIMIT 1');
  $mk = function ($t, $d) use ($co, $loc, $p) { q("INSERT INTO sales (company_id, location_id, party_id, customer_name, invoice_no, sale_date, subtotal, total, paid, payment_mode, status, created_by) VALUES (?,?,?,?,?,?,?,?,0,'credit','due',1)", [$co, $loc, $p, 'PL Party', 'PL-' . bin2hex(random_bytes(3)), $d, $t, $t]); return insert_id(); };
  echo $p, ' ', $mk(1000, '2026-01-10'), ' ', $mk(800, '2026-01-20');`).split(' ').map(Number);
const bal = () => parseFloat(php(`echo party_balance(${PARTY});`));
const pays = () => parseInt(php(`echo val('SELECT COUNT(*) FROM payments WHERE party_id = ?', [${PARTY}]);`));
const paid = (b) => parseFloat(php(`echo val('SELECT paid FROM sales WHERE id = ?', [${b}]);`));

const b = await pkg.chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
const p = await (await b.newContext({ viewport: { width: 390, height: 844 } })).newPage();
let bad = 0; const errs = [];
p.on('pageerror', e => errs.push(e.message)); p.on('dialog', d => d.accept());
const ok = (l, c, x) => { if (!c) bad++; console.log((c ? '  ✔ ' : '  ✘ ') + l + (x !== undefined ? '  → ' + x : '')); };
const flash = async () => (await p.evaluate(() => [...document.querySelectorAll('.flash')].map(f => f.innerText).join(' | '))).slice(0, 160);
await p.goto(S + 'login.php'); await p.fill('input[name=username]', 'admin'); await p.fill('input[name=password]', 'Test@1234');
await Promise.all([p.waitForNavigation(), p.click('button[type=submit]')]);

console.log('=== 1. Money taken, kept as an advance ===');
await p.goto(S + 'payments.php?action=new&dir=in&party=' + PARTY); await p.waitForTimeout(600);
await p.evaluate((pid) => { const s = document.querySelector('select[name=party_id]'); s.value = pid; s.dispatchEvent(new Event('change', { bubbles: true })); }, String(PARTY));
await p.fill('input[name=amount]', '1500');
await p.check('input[name=on_account][value="1"]');
await Promise.all([p.waitForNavigation(), p.locator('form button[type=submit]').last().click()]);
const pid = parseInt(php(`echo val('SELECT MAX(id) FROM payments WHERE party_id = ?', [${PARTY}]);`));
ok('saved', pid > 0, await flash());
ok('nothing put on the bills', paid(B1) === 0 && paid(B2) === 0);
const bal0 = bal(), n0 = pays();
ok('party balance counts it at once', Math.abs(bal0 - 300) < 0.01, bal0);

console.log('\n=== 2. The payment list and the ledger show it 🔵 ===');
await p.goto(S + 'payments.php?ls=advance#recent'); await p.waitForTimeout(400);
ok('in the 🔵 Advance filter', (await p.locator('#recent tbody').innerText()).includes('P-' + String(pid).padStart(5, '0')));
await p.goto(S + 'parties.php?action=ledger&id=' + PARTY); await p.waitForTimeout(400);
ok('the ledger line says 🔵 Advance', /Advance/.test(await p.locator('main').innerText()));
ok('...and the ledger tells how much is not on a bill', /₹1,500.00<\/b> not on any bill|1,500.00 not on any bill/.test(await p.locator('main').innerHTML()) || /not on any bill/.test(await p.locator('main').innerText()));

console.log('\n=== 3. Link it: ₹1,000 to bill 1, then ₹500 to bill 2 ===');
await p.goto(S + 'payments.php?action=link&id=' + pid); await p.waitForTimeout(400);
const rows = p.locator('#linkForm .pl-amt');
ok('the link screen lists both open bills with what is left', await rows.count() === 2 && /left ₹1,000.00/.test(await p.locator('#linkForm').innerText()));
await p.locator('#linkForm .pl-fill').first().click();
ok('"Full" fills what the bill still owes', (await rows.first().inputValue()) === '1000.00');
await rows.nth(1).fill('900');
ok('more than the payment has free: the Link button is off', await p.locator('#plGo').isDisabled());
await rows.nth(1).fill('');
await Promise.all([p.waitForNavigation(), p.click('#plGo')]);
ok('₹1,000 linked', /1,000.00 linked/.test(await flash()), await flash());
ok('bill 1 is paid', paid(B1) === 1000);
await p.locator('#linkForm .pl-amt').first().fill('500');
await Promise.all([p.waitForNavigation(), p.click('#plGo')]);
ok('then ₹500 to bill 2', paid(B2) === 500);
ok('the payment is now 🟢 fully linked', /🟢 Linked/.test(await p.locator('main').innerText()));
ok('the party balance did not move', Math.abs(bal() - bal0) < 0.01, bal());
ok('no payment was created or deleted', pays() === n0);

console.log('\n=== 4. The bill page: status, the payment on it, unlink, relink ===');
await p.goto(S + 'sale_view.php?id=' + B2); await p.waitForTimeout(400);
const card = await p.locator('#paystatus').innerText();
ok('bill 2 shows 🟡 partly paid, ₹800 total, ₹300 outstanding', /Partly paid/.test(card) && /800\.00/.test(card) && /300\.00/.test(card));
await Promise.all([p.waitForNavigation(), p.locator('#paystatus form button:has-text("Unlink")').first().click()]);
ok('unlinked from the bill page', paid(B2) === 0, await flash());
ok('...the bill is 🔴 unpaid again', /Unpaid/.test(await p.locator('#paystatus').innerText()));
ok('...the payment is still there and the balance did not move', pays() === n0 && Math.abs(bal() - bal0) < 0.01);
ok('...and its ₹500 is offered back to link here', await p.locator('#paystatus form button:has-text("Link")').count() >= 1);
await Promise.all([p.waitForNavigation(), p.locator('#paystatus form button:has-text("Link")').first().click()]);
ok('relinked from the bill page', paid(B2) === 500, await flash());

console.log('\n=== 5. Phone width ===');
for (const u of ['payments.php?action=link&id=' + pid, 'sale_view.php?id=' + B2, 'payments.php#recent', 'parties.php?action=ledger&id=' + PARTY]) {
  await p.goto(S + u); await p.waitForTimeout(300);
  // the bill page's own item table is wider than a phone (as before); the new card must fit
  ok(u.split('?')[0] + ' fits the phone', await p.evaluate((u) => u.startsWith('sale_view')
    ? document.querySelector('#paystatus').getBoundingClientRect().right <= window.innerWidth + 1
    : document.documentElement.scrollWidth <= window.innerWidth + 1, u));
}
ok('no JS errors', errs.length === 0, errs.join(' | '));
php(`foreach (all('SELECT id FROM payments WHERE party_id = ?', [${PARTY}]) as $x) payment_reverse($x['id']);
     q('DELETE FROM sales WHERE party_id = ?', [${PARTY}]); q('DELETE FROM parties WHERE id = ?', [${PARTY}]);`);
await b.close();
console.log(bad ? `\n✗ ${bad} FAILED` : '\n✓ ALL PASS');
process.exit(bad ? 1 : 0);
