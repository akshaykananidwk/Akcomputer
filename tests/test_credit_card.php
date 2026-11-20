<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); die('CLI only'); }
// ============================================================================
//  CREDIT CARD - kept like a bank account, seen by the main admin alone
//  (20-11-2026). A card spend raises what is owed on the card, paying the
//  card bill from a bank brings it down, and in the books it is a debt
//  (2300), never bank money.
// ============================================================================
require_once __DIR__ . '/../includes/accounting_audit.php';
t_group('Credit card: spends, card bill, and the books');
$T = '2099-12-31';
$tb = function () use ($T) {
    $rows = ledger_trial_balance($T);
    $unbal = round((float)val('SELECT COALESCE(SUM(debit - credit), 0) FROM journal_lines'), 2);
    return round(array_sum(array_column($rows, 'dr')) - array_sum(array_column($rows, 'cr')) - $unbal, 2);
};
$before = ledger_balances($T);
$bankBefore = coa_bank_balance($T);
q("INSERT INTO bank_accounts (account_name, bank_name, opening_balance, is_active) VALUES ('CC Test Bank', 'SBI', 10000, 1)");
$bank = (int)val('SELECT MAX(id) FROM bank_accounts');
q("INSERT INTO bank_accounts (account_name, bank_name, opening_balance, is_active, account_type, admin_only, credit_limit) VALUES ('My HDFC card', 'HDFC', -5000, 1, 'credit_card', 1, 50000)");
$card = (int)val('SELECT MAX(id) FROM bank_accounts');
$d = fn($code) => round(($after = ledger_balances($T))[$code] ?? 0, 2) - round($before[$code] ?? 0, 2);

t_eq('a card already owing ₹5,000 shows -5,000 (owed)', bank_account_balance($card), -5000.0);
t_eq('in the books the ₹5,000 is a debt on 2300, not bank money', [$d('2300'), $d('1100')], [-5000.0, 10000.0]);

q("INSERT INTO expenses (exp_date, category, amount, mode, bank_account_id, notes, created_by, location_id) VALUES ('2026-04-10', 'Software & AI', 1200, 'bank', ?, 'Ai claude', 1, 1)", [$card]);
t_eq('a ₹1,200 spend on the card: owed goes to ₹6,200', bank_account_balance($card), -6200.0);
t_eq('...the bank is untouched by a card spend', bank_account_balance($bank), 10000.0);
t_eq('...and in the books the card debt grows, the bank does not move', [$d('2300'), $d('1100')], [-6200.0, 10000.0]);

q("INSERT INTO money_transfers (txn_type, amount, from_bank_id, to_bank_id, txn_date, status, created_by) VALUES ('bank_to_bank', 6200, ?, ?, '2026-04-20', 'done', 1)", [$bank, $card]);
t_eq('paying the ₹6,200 card bill from the bank: nothing owed on the card', bank_account_balance($card), 0.0);
t_eq('...the bank went down by ₹6,200', bank_account_balance($bank), 3800.0);
t_eq('...the books agree: 2300 back to zero, bank 3,800', [$d('2300'), $d('1100')], [0.0, 3800.0]);
t_eq('the old bank total leaves the card out too', round(coa_bank_balance($T) - $bankBefore, 2), 3800.0);
t_eq('debit = credit throughout', $tb(), 0.0);
$audit = array_values(array_filter(acct_checks('2000-01-01', $T), fn($c) => strpos($c['title'] ?? '', 'Credit cards') === 0));
t_ok('the accounting check compares the card debt with the card pages', $audit && $audit[0]['ok']);

t_group('Credit card: only the main admin sees it');
$staffSees = fn($id) => (int)val('SELECT COUNT(*) FROM bank_accounts WHERE id = ?' . bank_hide_sql(false, '', false), [$id]);
t_eq('a staff login does not see the card, but does see the bank', [$staffSees($card), $staffSees($bank)], [0, 1]);
t_eq('the main admin sees both', [(int)val('SELECT COUNT(*) FROM bank_accounts WHERE id IN (?, ?)' . bank_hide_sql(false, '', true), [$card, $bank])], [2]);
t_eq('a card never becomes the invoice / POS default', (int)val("SELECT COUNT(*) FROM bank_accounts WHERE id = ?" . bank_hide_sql(true), [$card]), 0);
t_ok('a card id sent in a form is only taken if the person may see it', strpos(file_get_contents(__DIR__ . '/../includes/helpers.php'), 'bank_hide_sql(), [$bank])) $bank = 0;') !== false);
foreach (['sales.php', 'payments.php', 'expenses.php', 'purchases.php', 'cash_bank.php', 'reports.php', 'bank_reconcile.php'] as $f)
    t_ok("$f lists banks through the admin-only filter", strpos(file_get_contents(__DIR__ . '/../' . $f), "bank_hide_sql()") !== false);
