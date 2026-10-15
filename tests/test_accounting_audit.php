<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); die('CLI only'); }
// ============================================================================
//  THE ACCOUNTING HEALTH CHECK (includes/accounting_audit.php)
//
//  It explains a Trial Balance difference as a list of reasons. The one thing
//  it must never do is explain it wrongly, so: whatever is in the books, the
//  reasons add up to the difference to the paisa - checked on the data as it
//  is, then again after each kind of entry is added, one at a time, and each
//  entry must move exactly the reason it belongs to. And it writes nothing.
// ============================================================================
require_once __DIR__ . '/../includes/accounting_audit.php';
require_once __DIR__ . '/../includes/health.php';

t_group('Accounting check — the reasons add up to the Trial Balance difference');
$T = '2099-12-31';
$sum = fn() => round(array_sum(array_column(acct_tb_breakdown($T), 'amount')), 2);
$part = function ($key) use ($T) { foreach (acct_tb_breakdown($T) as $r) if ($r['key'] === $key) return $r['amount']; return null; };
$tb = acct_tb_totals($T);
t_eq('on the books as they are: reasons = debit − credit', $sum(), $tb['diff']);

$co = (int)val('SELECT id FROM companies ORDER BY id LIMIT 1');
$loc = (int)val('SELECT id FROM locations ORDER BY id LIMIT 1');
$uid = (int)val('SELECT id FROM users ORDER BY id LIMIT 1');
$step = function ($label, callable $do, $key, $expect) use ($T, $sum, $part) {
    $before = $part($key);
    $do();
    $tb = acct_tb_totals($T);
    t_eq("$label: still adds up exactly", $sum(), $tb['diff']);
    t_eq("$label: moves \"$key\" by " . money($expect), round($part($key) - $before, 2), $expect);
};

$p = t_party(); $s = t_party();
$step('a party\'s opening balance', fn() => q('UPDATE parties SET opening_balance = opening_balance + 1000 WHERE id = ?', [$p]), 'party_opening', 1000);
$step('a bank account\'s opening balance', function () { q("INSERT INTO bank_accounts (account_name, bank_name, opening_balance, is_active) VALUES ('Audit Bank', 'T', 2000, 1)"); }, 'bank_opening', 2000);
$bank = (int)val("SELECT id FROM bank_accounts WHERE account_name = 'Audit Bank' ORDER BY id DESC LIMIT 1");
$step('a credit bill to a party', function () use ($p) { t_sale($p, 590); }, 'returns_twice', 0);
$step('a walk-in bill, half paid in cash', function () use ($co, $loc, $uid) {
    q("INSERT INTO sales (company_id, location_id, invoice_no, sale_date, subtotal, total, paid, payment_mode, status, created_by) VALUES (?,?,?, '2026-01-05', 300, 300, 100, 'cash', ?, ?)", [$co, $loc, 'AUD-' . bin2hex(random_bytes(3)), payment_status(300, 100), $uid]);
    q("INSERT INTO payments (party_id, direction, amount, mode, ref_type, ref_id, pay_date, notes, created_by) VALUES (NULL, 'in', 100, 'cash', 'sale', ?, '2026-01-05', 'audit', ?)", [insert_id(), $uid]);
}, 'walkin_sales', -300);
$step('money received from a party in cash', fn() => q("INSERT INTO payments (party_id, direction, amount, mode, pay_date, notes, created_by) VALUES (?, 'in', 200, 'cash', '2026-01-06', 'audit', 1)", [$p]), 'noncash_party', 0);
$step('UPI received with no bank account (the default "upi" method)', fn() => q("INSERT INTO payments (party_id, direction, amount, mode, pay_date, notes, created_by) VALUES (?, 'in', 150, 'upi', '2026-01-06', 'audit', 1)", [$p]), 'noncash_party', -150);
$step('a settlement discount', fn() => q("INSERT INTO payments (party_id, direction, amount, mode, pay_date, notes, created_by) VALUES (?, 'in', 40, 'discount', '2026-01-06', 'audit', 1)", [$p]), 'noncash_party', -40);
$step('a payment marked cash that also carries a bank account', fn() => q("INSERT INTO payments (party_id, direction, amount, mode, bank_account_id, pay_date, notes, created_by) VALUES (?, 'in', 70, 'cash', ?, '2026-01-07', 'audit', 1)", [$p, $bank]), 'double_counted', 70);
$step('a purchase bill', function () use ($co, $loc, $s, $uid) {
    q("INSERT INTO purchases (company_id, bill_no, party_id, location_id, purchase_date, subtotal, tax_amount, total, paid, status, created_by) VALUES (?, 'AUD-P', ?, ?, '2026-01-04', 1000, 180, 1180, 0, ?, ?)", [$co, $s, $loc, payment_status(1180, 0), $uid]);
}, 'purchases', -1180);
$step('a cash expense', fn() => q("INSERT INTO expenses (exp_date, category, amount, mode, notes, location_id, created_by) VALUES ('2026-01-08', 'Tea & Snacks', 55, 'cash', 'audit', ?, 1)", [$loc]), 'expenses', -55);
$step('a bank expense', fn() => q("INSERT INTO expenses (exp_date, category, amount, mode, bank_account_id, notes, location_id, created_by) VALUES ('2026-01-08', 'Office Rent', 500, 'bank', ?, 'audit', ?, 1)", [$bank, $loc]), 'expenses', -500);
$step('a sales return to a party', fn() => q("INSERT INTO sales_returns (return_no, party_id, location_id, return_date, total, refund_mode, created_by) VALUES ('AUD-R1', ?, ?, '2026-01-09', 90, 'credit', 1)", [$p, $loc]), 'returns_twice', 90);
$step('a cash adjustment (add)', fn() => q("INSERT INTO money_transfers (txn_type, amount, adjust_dir, txn_date, status, created_by) VALUES ('cash_adjust', 333, 'add', '2026-01-09', 'done', 1)"), 'adjustments', 333);
$step('stock on the shelf', function () use ($loc) { t_item(4, $loc, 200); }, 'stock_value', 400);
$step('cash put into the bank (moves nothing in total)', fn() => q("INSERT INTO money_transfers (txn_type, amount, to_bank_id, txn_date, status, created_by) VALUES ('cash_to_bank', 120, ?, '2026-01-10', 'done', 1)", [$bank]), 'adjustments', 0);
$acc = (int)val("SELECT id FROM chart_of_accounts WHERE code = '3000'");
$step('an unbalanced journal entry', function () use ($acc) {
    q("INSERT INTO journal_entries (entry_date, ref_no, narration, created_by) VALUES ('2026-01-11', 'JV-AUD', 'audit', 1)");
    q('INSERT INTO journal_lines (entry_id, account_id, debit, credit) VALUES (?,?,77,0)', [insert_id(), $acc]);
}, 'unbalanced_journals', 77);

t_group('Accounting check — it only reads');
$src = file_get_contents(__DIR__ . '/../includes/accounting_audit.php');
t_ok('no INSERT, UPDATE, DELETE or ALTER anywhere in it', !preg_match('/\b(INSERT|UPDATE|DELETE|ALTER|DROP|TRUNCATE)\b|\bq\(|set_setting\(/', preg_replace('#//.*#', '', $src)));
$page = @file_get_contents(__DIR__ . '/../accounting_check.php') ?: '';
t_ok('its screen asks for the accounting-reports permission', strpos($page, "require_perm('reports.accounting')") !== false);
t_ok('...and has no form that posts anything', stripos($page, 'method="post"') === false && stripos($page, "REQUEST_METHOD") === false);
$checks = acct_checks('2026-01-01', $T);
$by = []; foreach ($checks as $c) $by[$c['title']] = $c;
t_ok('the UPI without a bank account is found', !$by['UPI / card / cheque payments saved without a bank account']['ok']);
t_ok('GST is kept out of sales (and the amount is shown)', isset($by['GST kept out of sales']));
t_eq('the walk-in bill\'s unpaid ₹200 is found', $by['Unpaid amount on walk-in bills (no party)']['amount'] >= 200 ? 1 : 0, 1);

t_group('Data Health warnings, entry by entry — each one sorted correctly');
$u0 = row('SELECT u.*, l.name location_name FROM users u JOIN locations l ON l.id = u.location_id ORDER BY u.id LIMIT 1'); $u0['perms'] = ['*'];
q("INSERT INTO parties (name, type, mobile) VALUES ('EB Both', 'both', '9844444444')"); $pb = insert_id();
q("INSERT INTO items (name, selling_price, purchase_price, tax_rate, is_active, item_type) VALUES ('EB Item', 500, 300, 18, 1, 'product')"); $ib = insert_id();
adjust_stock($ib, $loc, 5, 'opening', null, 't');
$_POST = ['company_id' => $co, 'location_id' => $loc, 'party_id' => $pb, 'sale_date' => '2026-02-01', 'payment_mode' => 'cash', 'paid' => 300,
          'row_n' => [1], 'item_id' => [$ib], 'qty' => [1], 'price' => [500], 'tax_rate' => [0]];
$sb = sale_save_form($u0);
$_POST = ['party_id' => $pb, 'direction' => 'in', 'amount' => 200, 'mode' => 'cash', 'pay_date' => '2026-02-02'];
payment_save_form($u0);
$hit = array_values(array_filter(acct_detail_paid(), fn($r) => $r['id'] == $sb['id']));
t_ok('a bill paid partly with it and partly later is shown as correct (a fault of the old check)', $hit && $hit[0]['kind'] === 'software' && abs($hit[0]['gap']) < 0.01);
$flagged = function ($title, $id) { foreach (health_checks() as $c) if (strpos($c['t'] ?? '', $title) === 0) foreach ($c['rows'] ?? [] as $r) if ((int)($r['id'] ?? 0) === (int)$id) return true; return false; };
t_ok('...and the Data Health check no longer flags it either', !$flagged('Bill "paid"', $sb['id']));
q('UPDATE sales SET paid = paid + 50 WHERE id = ?', [$sb['id']]);
$hit = array_values(array_filter(acct_detail_paid(), fn($r) => $r['id'] == $sb['id']));
t_ok('...and a real gap is shown as an entry problem, with its amount', $hit && $hit[0]['kind'] === 'entry' && abs($hit[0]['gap'] - 50) < 0.01);
q('UPDATE sales SET paid = paid - 50 WHERE id = ?', [$sb['id']]);
$_POST = ['company_id' => $co, 'location_id' => $loc, 'party_id' => $pb, 'bill_no' => 'EB-FREE', 'purchase_date' => '2026-02-03', 'paid' => 0, 'payment_mode' => 'credit',
          'item_id' => [$ib], 'qty' => [2], 'price' => [0], 'tax_rate' => [0], 'serials' => ['']];
$pf = purchase_save_form($u0);
$hit = array_values(array_filter(acct_detail_zero_purchases(), fn($r) => $r['id'] == $pf['id']));
t_ok('a free item at ₹0 price is recognised as free/gift (kept, not an error)', $hit && $hit[0]['kind'] === 'gift');
t_eq('a free piece no longer wipes the item\'s purchase price', (float)val('SELECT purchase_price FROM items WHERE id = ?', [$ib]), 300);
q('UPDATE items SET purchase_price = 0 WHERE id = ?', [$ib]);   // what an older free entry did
$hit = array_values(array_filter(acct_detail_zero_purchases(), fn($r) => $r['id'] == $pf['id']));
t_ok('...and damage an older one did is named: the item\'s purchase price is now ₹0', $hit && preg_match('/purchase price is now ₹0/', implode(' ', $hit[0]['effects'])));
q('UPDATE items SET purchase_price = 300 WHERE id = ?', [$ib]);
$_POST = ['company_id' => $co, 'location_id' => $loc, 'party_id' => $pb, 'bill_no' => 'EB-DISC', 'purchase_date' => '2026-02-04', 'paid' => 0, 'payment_mode' => 'credit',
          'discount_type' => 'percent', 'discount_val' => 100, 'item_id' => [$ib], 'qty' => [1], 'price' => [300], 'tax_rate' => [0], 'serials' => ['']];
$pd = purchase_save_form($u0);
$hit = array_values(array_filter(acct_detail_zero_purchases(), fn($r) => $r['id'] == $pd['id']));
t_ok('a 100% discount purchase is recognised as such', $hit && $hit[0]['kind'] === 'gift' && strpos($hit[0]['what'], '100% discount') === 0);
$_POST = ['company_id' => $co, 'location_id' => $loc, 'party_id' => $pb, 'bill_no' => 'EB-BUY', 'purchase_date' => '2026-02-05', 'paid' => 0, 'payment_mode' => 'credit',
          'item_id' => [$ib], 'qty' => [1], 'price' => [250], 'tax_rate' => [0], 'serials' => ['']];
purchase_save_form($u0);
$_POST = ['company_id' => $co, 'location_id' => $loc, 'party_id' => $pb, 'sale_date' => '2026-02-06', 'payment_mode' => 'credit', 'paid' => 0,
          'row_n' => [1], 'item_id' => [$ib], 'qty' => [1], 'price' => [500], 'tax_rate' => [0]];
sale_save_form($u0);
$hit = array_values(array_filter(acct_detail_parties(), fn($r) => $r['id'] == $pb));
t_ok('a party we both sell to and buy from is explained by the purchases, not "payment not linked"', $hit && stripos($hit[0]['why'], 'you also bought from them') !== false);
t_ok('...and the Data Health check no longer flags a party just for buying from them too', !$flagged('Party whose bills', $pb));
$_POST = [];
$src2 = file_get_contents(__DIR__ . '/../includes/accounting_check_entries.php');
t_ok('the entry-by-entry screen has no form and writes nothing', stripos($src2, '<form') === false && !preg_match('/\b(INSERT|UPDATE|DELETE)\b|\bq\(/', $src2));
t_ok('unencrypted keys are listed by name only, never their value', strpos($src, "\$o['plain_keys'][] = \$k;") !== false);
