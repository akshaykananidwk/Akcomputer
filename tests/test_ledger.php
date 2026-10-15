<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); die('CLI only'); }
// ============================================================================
//  THE BOOKS IN DOUBLE ENTRY (includes/ledger.php)
//
//  Every kind of entry the shop makes is added one at a time, through the same
//  save functions the screens use where there is one. After EACH: debit must
//  still equal credit, and exactly the right accounts must move by exactly the
//  right amounts. Then the P&L and Balance Sheet are checked against the
//  figures worked out by hand.
// ============================================================================
t_group('Double entry — every entry keeps debit = credit and moves the right accounts');
$T = '2099-12-31';
$u = row('SELECT u.*, l.name location_name FROM users u JOIN locations l ON l.id = u.location_id ORDER BY u.id LIMIT 1'); $u['perms'] = ['*'];
$co = (int)val('SELECT id FROM companies ORDER BY id LIMIT 1');
$loc = (int)$u['location_id'];
// debit − credit can only ever be what unbalanced manual journals leave (none, normally)
$unbal = fn() => round((float)val('SELECT COALESCE(SUM(debit - credit), 0) FROM journal_lines'), 2);
$tbOk = function ($label) use ($T, $unbal) {
    $rows = ledger_trial_balance($T);
    t_eq("$label: debit = credit", round(array_sum(array_column($rows, 'dr')) - array_sum(array_column($rows, 'cr')) - $unbal(), 2), 0);
};
$tbOk('the books as they are');
// $step: do something, then every account must have moved by $want (code => net debit) and nothing else
$step = function ($label, callable $do, array $want) use ($T, $tbOk) {
    $before = ledger_balances($T);
    $do();
    $after = ledger_balances($T);
    $tbOk($label);
    $moved = [];
    foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $c) {
        $d = round(($after[$c] ?? 0) - ($before[$c] ?? 0), 2);
        if (abs($d) > 0.004) $moved[$c] = $d;
    }
    ksort($moved); ksort($want);
    t_ok("$label: moves " . json_encode($want), $moved == $want, json_encode($moved));
};

$pa = t_party(); $ps = t_party();
q("INSERT INTO items (name, selling_price, purchase_price, tax_rate, is_active, item_type) VALUES ('LD Item', 500, 300, 18, 1, 'product')"); $it = insert_id();
q("INSERT INTO bank_accounts (account_name, bank_name, opening_balance, is_active) VALUES ('LD Bank', 'T', 0, 1)"); $bank = insert_id();

$step('opening stock: 4 pieces at ₹300', fn() => adjust_stock($it, $loc, 4, 'opening', null, 't'), ['1300' => 1200, '3010' => -1200]);
$step('a party\'s opening due of ₹1,000', fn() => q('UPDATE parties SET opening_balance = 1000 WHERE id = ?', [$pa]), ['1200' => 1000, '3010' => -1000]);
$step('a bank account opened with ₹5,000', fn() => q('UPDATE bank_accounts SET opening_balance = 5000 WHERE id = ?', [$bank]), ['1100' => 5000, '3010' => -5000]);

$_POST = ['company_id' => $co, 'location_id' => $loc, 'party_id' => $ps, 'bill_no' => 'LD-P1', 'purchase_date' => '2026-04-02', 'paid' => 0, 'payment_mode' => 'credit',
          'item_id' => [$it], 'qty' => [2], 'price' => [300], 'tax_rate' => [18], 'serials' => ['']];
$step('a purchase on credit: 2 × ₹300 + 18% GST', function () use ($u) { purchase_save_form($u); }, ['1300' => 600, '1400' => 108, '2000' => -708]);

$_POST = ['company_id' => $co, 'location_id' => $loc, 'party_id' => $pa, 'sale_date' => '2026-04-03', 'payment_mode' => 'credit', 'paid' => 0,
          'row_n' => [1], 'item_id' => [$it], 'qty' => [1], 'price' => [500], 'tax_rate' => [18]];
$sale = null;
$step('a credit sale: ₹500 + 18% GST, cost ₹300', function () use ($u, &$sale) { $sale = sale_save_form($u); },
      ['1200' => 590, '4000' => -500, '2100' => -90, '5000' => 300, '1300' => -300]);

$_POST = ['company_id' => $co, 'location_id' => $loc, 'sale_date' => '2026-04-04', 'payment_mode' => 'cash', 'paid' => 200, 'customer_name' => 'Walk-in',
          'row_n' => [1], 'item_id' => [$it], 'qty' => [1], 'price' => [400], 'tax_rate' => [0]];
$step('a walk-in sale of ₹400, ₹200 paid in cash', function () use ($u) { sale_save_form($u); },
      ['1000' => 200, '1210' => 200, '4000' => -400, '5000' => 300, '1300' => -300]);

$_POST = ['party_id' => $pa, 'direction' => 'in', 'amount' => 300, 'mode' => 'bank', 'bank_account_id' => $bank, 'pay_date' => '2026-04-05'];
$step('₹300 received into the bank', function () use ($u) { payment_save_form($u); }, ['1100' => 300, '1200' => -300]);
$step('₹150 by UPI with no bank account chosen', fn() => q("INSERT INTO payments (party_id, direction, amount, mode, pay_date, created_by) VALUES (?, 'in', 150, 'upi', '2026-04-05', 1)", [$pa]),
      ['1150' => 150, '1200' => -150]);
$step('a ₹40 settlement discount', fn() => q("INSERT INTO payments (party_id, direction, amount, mode, pay_date, created_by) VALUES (?, 'in', 40, 'discount', '2026-04-05', 1)", [$pa]),
      ['5200' => 40, '1200' => -40]);
$_POST = ['party_id' => $ps, 'direction' => 'out', 'amount' => 708, 'mode' => 'cash', 'pay_date' => '2026-04-06'];
$step('the supplier paid ₹708 in cash', function () use ($u) { payment_save_form($u); }, ['1000' => -708, '2000' => 708]);

$step('a sales return of ₹590 (with GST) against the credit bill', function () use ($sale, $pa, $loc, $it) {
    q("INSERT INTO sales_returns (return_no, sale_id, party_id, location_id, return_date, total, refund_mode, created_by) VALUES ('LD-SR', ?, ?, ?, '2026-04-07', 590, 'adjust', 1)", [$sale['id'], $pa, $loc]);
    $r = insert_id();
    q('INSERT INTO sales_return_items (return_id, item_id, qty, price, total) VALUES (?,?,1,590,590)', [$r, $it]);
    adjust_stock($it, $loc, 1, 'sales_return', $r);
}, ['4100' => 500, '2100' => 90, '1200' => -590, '1300' => 300, '5000' => -300]);
$step('a walk-in return refunded in cash', function () use ($loc) {
    q("INSERT INTO sales_returns (return_no, location_id, return_date, total, refund_mode, created_by) VALUES ('LD-SR2', ?, '2026-04-07', 50, 'cash', 1)", [$loc]);
    q("INSERT INTO payments (direction, amount, mode, ref_type, ref_id, pay_date, created_by) VALUES ('out', 50, 'cash', 'sales_return', ?, '2026-04-07', 1)", [insert_id()]);
}, ['4100' => 50, '1000' => -50]);
$step('a purchase return of ₹300 after the supplier was paid in full: now THEY owe us', function () use ($ps, $loc, $it) {
    q("INSERT INTO purchase_returns (return_no, party_id, location_id, return_date, total, refund_mode, created_by) VALUES ('LD-PR', ?, ?, '2026-04-08', 300, 'adjust', 1)", [$ps, $loc]);
    adjust_stock($it, $loc, -1, 'purchase_return', insert_id());
}, ['1200' => 300, '1300' => -300]);

$exp = function ($cat, $amt, $mode = 'cash', $bankId = null) use ($loc) {
    q('INSERT INTO expenses (exp_date, category, amount, mode, bank_account_id, location_id, created_by) VALUES (?,?,?,?,?,?,1)', ['2026-04-09', $cat, $amt, $mode, $bankId, $loc]);
    return insert_id();
};
$step('rent paid from the bank', fn() => $exp('Office Rent', 1000, 'bank', $bank), ['1100' => -1000, '6000' => 1000]);
$step('money given home is drawings, not an expense', fn() => $exp('Money Given Home', 2000), ['1000' => -2000, '3100' => 2000]);
$step('a staff advance is money the staff owes', fn() => $exp('Staff Advance', 500), ['1000' => -500, '1500' => 500]);
$step('salary paid net of that advance uses the advance up as salary', function () use ($u) {
    q("INSERT INTO payslips (user_id, month, days_in_month, paid_days, salary, earned, advance_deduct, net, paid_at, created_by) VALUES (?, '2026-04', 30, 30, 10000, 10000, 500, 9500, '2026-04-30 10:00:00', 1)", [$u['id']]);
}, ['6000' => 500, '1500' => -500]);
$step('a loan of ₹1,00,000 in the Loans register', fn() => q("INSERT INTO loans (lender, principal, rate_pct, start_date, months, emi, notes) VALUES ('LD Bank Loan', 100000, 12, '2026-04-01', 12, 8885, '')"),
      ['2200' => -100000, '3010' => 100000]);
$loan = (int)val('SELECT MAX(id) FROM loans');
$step('an EMI paid through Books → Loans: principal lowers the loan, only interest is an expense', function () use ($exp, $loan, $bank) {
    $e = $exp('EMI / Loan', 8885, 'bank', $bank);
    q('INSERT INTO loan_payments (loan_id, n, due_date, emi, interest, principal_part, paid_on, expense_id) VALUES (?, 1, ?, 8885, 1000, 7885, ?, ?)', [$loan, '2026-05-01', '2026-04-09', $e]);
}, ['1100' => -8885, '2200' => 7885, '5300' => 1000]);

$step('cash deposited in the bank', fn() => q("INSERT INTO money_transfers (txn_type, amount, to_bank_id, txn_date, status, created_by) VALUES ('cash_to_bank', 100, ?, '2026-04-10', 'done', 1)", [$bank]),
      ['1000' => -100, '1100' => 100]);
$step('a bank adjustment nobody has classified yet is shown, not hidden', fn() => q("INSERT INTO money_transfers (txn_type, amount, adjust_dir, to_bank_id, txn_date, notes, status, created_by) VALUES ('bank_adjust', 160889.60, 'add', ?, '2026-04-11', 'Mom gold', 'done', 1)", [$bank]),
      ['1100' => 160889.60, '3090' => -160889.60]);
$adj = (int)val('SELECT MAX(id) FROM money_transfers');
$step('...classified as the owner\'s money put in: it moves to capital, the bank does not change', fn() => q("UPDATE money_transfers SET counter = 'capital' WHERE id = ?", [$adj]),
      ['3090' => 160889.60, '3000' => -160889.60]);
$step('a bank charge adjustment is an expense', fn() => q("INSERT INTO money_transfers (txn_type, amount, adjust_dir, to_bank_id, txn_date, status, created_by, counter) VALUES ('bank_adjust', 59, 'reduce', ?, '2026-04-12', 'done', 1, 'expense')", [$bank]),
      ['1100' => -59, '5400' => 59]);
$step('a stock count found one piece short', fn() => adjust_stock($it, $loc, -1, 'cycle_count', null, 't'), ['1300' => -300, '5100' => 300]);
$step('a warranty credit note from the supplier (who already owes us): they owe more', fn() => q("INSERT INTO payments (party_id, direction, amount, mode, ref_type, pay_date, created_by) VALUES (?, 'out', 120, 'credit_note', 'warranty', '2026-04-12', 1)", [$ps]),
      ['1200' => 120, '4300' => -120]);
$step('a contra (in and out on the same party) nets to nothing', function () use ($pa) {
    q("INSERT INTO payments (party_id, direction, amount, mode, pay_date, created_by) VALUES (?, 'in', 80, 'contra', '2026-04-12', 1)", [$pa]);
    q("INSERT INTO payments (party_id, direction, amount, mode, pay_date, created_by) VALUES (?, 'out', 80, 'contra', '2026-04-12', 1)", [$pa]);
}, []);
$step('a cancelled bill posts nothing', fn() => q('UPDATE sales SET is_cancelled = 1 WHERE id = ?', [t_sale($pa, 999, 0, '2026-04-12')]), []);
$acc = (int)val("SELECT id FROM chart_of_accounts WHERE code = '3000'"); $cash = (int)val("SELECT id FROM chart_of_accounts WHERE code = '1000'");
$step('a balanced manual journal moves just its two accounts', function () use ($acc, $cash) {
    q("INSERT INTO journal_entries (entry_date, ref_no, narration, created_by) VALUES ('2026-04-13', 'JV-LD', 't', 1)"); $j = insert_id();
    q('INSERT INTO journal_lines (entry_id, account_id, debit, credit) VALUES (?,?,250,0), (?,?,0,250)', [$j, $cash, $j, $acc]);
}, ['1000' => 250, '3000' => -250]);

t_group('Double entry — the reports read the same books');
$pl = ledger_pl('2026-04-01', '2026-04-30');
$byCode = fn($c) => ledger_balances('2026-04-30', '2026-04-01')[$c] ?? 0;
t_ok('sales in the P&L are without GST', $pl['sales'] >= 900 && abs($byCode('4000') + $pl['sales']) < 0.01);
t_ok('...and sales returns too (₹500 + ₹50, not ₹640)', $pl['returns'] >= 550 && abs($byCode('4100') - $pl['returns']) < 0.01);
t_ok('cost of goods returned is taken back out of COGS', abs($pl['cogs'] - $byCode('5000')) < 0.01);
t_ok('drawings are not an expense', !isset($pl['expenses']['Money Given Home']) && $pl['drawings'] >= 2000);
t_ok('the loan principal is not an expense, its interest is', ($pl['expenses']['Loan interest'] ?? 0) >= 1000 && ($pl['expenses']['EMI / Loan'] ?? 0) < 0.01);
t_ok('the staff advance became salary only when it was recovered', ($pl['expenses']['Salary & Wages'] ?? 0) >= 500 && !isset($pl['expenses']['Staff Advance']));
t_eq('net profit = net sales − COGS + other income − expenses', $pl['net'], $pl['net_sales'] - $pl['cogs'] + $pl['other_total'] - $pl['expense_total']);
$tb = ledger_trial_balance($T);
$sum = fn($type) => array_sum(array_map(fn($r) => $r['type'] === $type ? $r['dr'] - $r['cr'] : 0, $tb));
t_eq('Balance Sheet: assets = liabilities + equity + profit to date', $sum('asset'), -$sum('liability') - $sum('equity') + ledger_net_profit('0001-01-01', $T) + $unbal());
$gl = ledger_gl_rows('1100', '0001-01-01', $T);
t_eq('a General Ledger account adds up to its Trial Balance figure', round(array_sum(array_column($gl, 'debit')) - array_sum(array_column($gl, 'credit')), 2), ledger_balances($T)['1100']);
t_eq('Accounts Receivable is every party who owes, each netted on its own', ledger_balances($T)['1200'],
     (float)val('SELECT COALESCE(SUM(GREATEST(b, 0)), 0) FROM (SELECT ' . party_balance_expr('p') . ' b FROM parties p) x') + 0);
$st = ledger_stock_check();
t_ok('the stock check compares the books with the shelf', isset($st['book'], $st['shelf'], $st['diff']));

t_group('Double entry — it only reads');
$src = file_get_contents(__DIR__ . '/../includes/ledger.php');
t_ok('no INSERT, UPDATE, DELETE or ALTER in the books engine', !preg_match('/\b(INSERT|UPDATE|DELETE|ALTER|DROP|TRUNCATE)\b|\bq\(|set_setting\(/', preg_replace('#//.*#', '', $src)));
$_POST = [];
