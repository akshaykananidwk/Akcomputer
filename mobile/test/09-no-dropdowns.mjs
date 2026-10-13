// ---------------------------------------------------------------------------
// No drop-down lists anywhere: every choice is a box you type in, the way an
// item is found on the bill. Checked three ways:
//   1. the customer on the bill: type part of a name, tap it, the hidden
//      select holds the party and the page reacts as it did before (its own
//      onchange still runs);
//   2. a payment saved through the typed box reaches the books;
//   3. every website page in the menu: no visible <select> left, no errors.
// And the app's own screens (mode, firm, category) are the same.
//
//     php -S 127.0.0.1:8088 -t .  ;  php -S 127.0.0.1:8099 -t mobile/www
//     node mobile/test/09-no-dropdowns.mjs
// ---------------------------------------------------------------------------
import pkg from '/opt/node22/lib/node_modules/playwright/index.js';
import { execFileSync } from 'child_process';
import fs from 'fs';
const ROOT = new URL('../../', import.meta.url).pathname;
const php = (code) => execFileSync('php', ['-r', 'require "' + ROOT + 'includes/init.php"; ' + code]).toString().trim();
const S = 'http://127.0.0.1:8088/';

const b = await pkg.chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
let bad = 0;
const ok = (label, cond, extra) => { if (!cond) bad++; console.log((cond ? '  ✔ ' : '  ✘ ') + label + (extra !== undefined ? '  → ' + extra : '')); };

ok('the website and the app run the very same file', fs.readFileSync(ROOT + 'assets/searchselect.js', 'utf8') === fs.readFileSync(ROOT + 'mobile/www/searchselect.js', 'utf8'));

const ctx = await b.newContext({ viewport: { width: 390, height: 844 } });
const p = await ctx.newPage();
const errs = []; p.on('pageerror', e => errs.push(p.url().replace(S, '') + ': ' + e.message));
p.on('dialog', d => d.accept());
await p.goto(S + 'login.php'); await p.fill('input[name=username]', 'admin'); await p.fill('input[name=password]', 'Test@1234');
await Promise.all([p.waitForNavigation(), p.click('button[type=submit]')]);

console.log('=== 1. The customer on a bill ===');
const party = JSON.parse(php(`echo json_encode(row("SELECT id, name, mobile FROM parties WHERE is_active = 1 AND CHAR_LENGTH(name) > 6 AND CHAR_LENGTH(mobile) = 10 ORDER BY id DESC LIMIT 1"));`));
await p.goto(S + 'sales.php?action=new'); await p.waitForTimeout(500);
// the type-in box of a select: the bill's own (SearchPick) or the general one
const boxOf = async (css) => p.evaluate((css) => {
  const s = document.querySelector(css); if (!s) return '';
  const i = s._ss ? s._ss.inp : (s.previousElementSibling && s.previousElementSibling.querySelector('input'));
  if (!i) return '';
  if (!i.id) i.id = 'tbox_' + Math.random().toString(36).slice(2, 7);
  return '#' + i.id;
}, css);
const LIST = '.ss-list.show .ss-o, .isearch-results.show .ir';
const pb = await boxOf('#party_id');
ok('no drop-down: the customer is a type-in box', await p.locator('#party_id').isHidden() && !!pb && await p.locator(pb).isVisible());
ok('...exactly one box, not two', await p.locator('#party_id').evaluate(s => s.closest('.bf-card').querySelectorAll('.isearch-wrap, .ss-wrap').length) === 1);
await p.evaluate(() => { window.__changed = 0; document.getElementById('party_id').addEventListener('change', () => window.__changed++); });
await p.click(pb);
const all = await p.locator(LIST).count();
ok('tapping it shows the list', all > 3, all);
await p.fill(pb, party.mobile.slice(-6)); await p.waitForTimeout(200);
ok('a party is found by part of its mobile number', /\S/.test(await p.locator(LIST).first().innerText()) && (await p.locator(LIST).first().innerText()).includes(party.name), party.mobile.slice(-6));
const word = party.name.split(/\s+/).pop().slice(0, 4);
await p.fill(pb, word); await p.waitForTimeout(200);
const few = await p.locator(LIST).count();
ok(`typing "${word}" narrows it`, few >= 1 && few < all, few);
await p.locator(LIST, { hasText: party.name }).first().dispatchEvent('mousedown');
await p.waitForTimeout(400);
ok('tapping a name chooses that party', await p.evaluate(() => document.getElementById('party_id').value) == party.id);
ok('...the box shows the name', (await p.inputValue(pb)) === party.name);
ok('...and the page reacts as before (its change handler ran)', await p.evaluate(() => window.__changed) >= 1);

console.log('\n=== 1b. The general box (any other drop-down) ===');
await p.goto(S + 'expenses.php'); await p.waitForTimeout(500);
const gsel = await p.evaluate(() => { const s = [...document.querySelectorAll('select')].find(x => x._ss && x.options.length > 2); if (!s) return ''; s.id = s.id || 'gtest'; return '#' + s.id; });
ok('a drop-down on the expenses screen became a type-in box', !!gsel);
if (gsel) {
  const gb = await boxOf(gsel);
  await p.click(gb); await p.fill(gb, 'zzqqxx'); await p.waitForTimeout(100);
  ok('nothing matching says so', /Nothing found/.test(await p.locator('.ss-list.show').innerText()));
  await p.keyboard.press('Escape');
  ok('a half-typed word is not left as if it were chosen', (await p.inputValue(gb)) !== 'zzqqxx');
  await p.evaluate((g) => { const s = document.querySelector(g); s.value = s.options[1].value; }, gsel);
  ok('a script that sets the choice shows in the box', (await p.inputValue(gb)) === await p.evaluate((g) => document.querySelector(g).options[1].text.trim(), gsel));
}

console.log('\n=== 2. A payment through the type-in boxes reaches the books ===');
await p.goto(S + 'payments.php?action=new&dir=in'); await p.waitForTimeout(500);
const box = p.locator('select[name="party_id"]');
ok('the payment form has no drop-down for the party', await box.isHidden());
const pin = await boxOf('select[name="party_id"]');
await p.click(pin); await p.fill(pin, word); await p.waitForTimeout(200);
await p.locator(LIST, { hasText: party.name }).first().dispatchEvent('mousedown');
await p.waitForTimeout(400);
const RUN = 'SS' + Date.now().toString(36);
await p.fill('input[name="amount"]', '11');
await p.evaluate((n) => { const f = document.querySelector('[name=notes]'); if (f) f.value = n; }, RUN);
await Promise.all([p.waitForNavigation(), p.locator('form:has(select[name="party_id"]) button[type=submit]').first().click()]);
const pay = php(`echo json_encode(row("SELECT party_id, amount FROM payments WHERE notes LIKE ? ORDER BY id DESC LIMIT 1", ['${RUN}%']));`);
ok('saved against the party chosen by typing', /"party_id":\s*"?\d+/.test(pay) && JSON.parse(pay).party_id == party.id, pay);
php(`foreach (all("SELECT id FROM payments WHERE notes LIKE ?", ['${RUN}%']) as $x) { foreach (all('SELECT * FROM payment_allocations WHERE payment_id = ?', [$x['id']]) as $a) money_reverse_bill_paid($a['ref_type'], $a['ref_id'], $a['amount']); q('DELETE FROM payment_allocations WHERE payment_id = ?', [$x['id']]); q('DELETE FROM payments WHERE id = ?', [$x['id']]); }`);

console.log('\n=== 3. Every website page: no drop-down left ===');
const pages = JSON.parse(php(`$o = []; foreach (nav_menu() as $e) { if ($e[0] === 'link') $o[] = $e[1]; else foreach ($e[4] as $it) { $o[] = $it[0]; if (!empty($it[3])) $o[] = $it[3]; } } echo json_encode(array_values(array_unique($o)));`))
  .concat(['settings.php?cat=billing', 'my_account.php', 'repairs.php?action=new', 'estimates.php?action=new', 'expenses.php']);
const left = [], doubled = [];
for (const u of pages) {
  await p.goto(S + u).catch(() => {}); await p.waitForTimeout(250);
  const vis = await p.evaluate(() => [...document.querySelectorAll('select')].filter(s => s.offsetParent !== null && !s.multiple && !(s.size > 1) && !s.classList.contains('no-search')).map(s => s.name || s.id));
  if (vis.length) left.push(u + ' [' + vis.join(',') + ']');
  const two = await p.evaluate(() => [...document.querySelectorAll('select')].filter(s => s._ss && s.dataset.pickReady).map(s => s.name || s.id));
  if (two.length) doubled.push(u + ' [' + two.join(',') + ']');
}
ok(`${pages.length} pages: no drop-down list visible`, left.length === 0, left.slice(0, 6).join(' | '));
ok('...and no field anywhere got two search boxes', doubled.length === 0, doubled.slice(0, 4).join(' | '));
ok('...and no page broke', errs.length === 0, errs.slice(0, 4).join(' | '));

console.log('\n=== 4. The app\'s own screens ===');
const a = await (await b.newContext({ viewport: { width: 390, height: 844 } })).newPage();
const aerr = []; a.on('pageerror', e => aerr.push(e.message));
await a.goto('http://127.0.0.1:8099/index.html'); await a.evaluate(async () => { await DB.clearAll(); }); await a.reload(); await a.waitForTimeout(400);
await a.click('#l_adv'); await a.fill('#l_srv', 'http://127.0.0.1:8088'); await a.fill('#l_user', 'admin'); await a.fill('#l_pass', 'Test@1234');
await a.click('#l_go'); await a.waitForTimeout(6000);
const appLeft = [];
for (const s of ['newbill', 'collect', 'purchase', 'payout', 'expense', 'itemedit', 'settings']) {
  await a.evaluate((s) => App.go(s), s); await a.waitForTimeout(500);
  const vis = await a.evaluate(() => [...document.querySelectorAll('select')].filter(x => x.offsetParent !== null).map(x => x.id));
  if (vis.length) appLeft.push(s + ' [' + vis.join(',') + ']');
}
ok('app screens: no drop-down list visible', appLeft.length === 0, appLeft.join(' | '));
await a.evaluate(() => App.go('expense')); await a.waitForTimeout(400);
await a.click('#e_cat_ss'); await a.fill('#e_cat_ss', 'tea'); await a.waitForTimeout(100);
await a.locator('.ss-list.show .ss-o').first().dispatchEvent('mousedown');
ok('an expense kind is found by typing "tea"', /tea/i.test(await a.evaluate(() => document.getElementById('e_cat').value)), await a.evaluate(() => document.getElementById('e_cat').value));
ok('no errors in the app', aerr.length === 0, aerr.join(' | '));
php(`q("UPDATE api_tokens SET revoked = 1 WHERE device = 'Android'");`);

await b.close();
console.log(bad ? `\n✗ ${bad} FAILED` : '\n✓ ALL PASS');
process.exit(bad ? 1 : 0);
