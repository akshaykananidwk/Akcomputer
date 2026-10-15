<?php
// ============================================================================
//  THE BOOKS, DOUBLE ENTRY - every document posted as debit AND credit.
//
//  The Trial Balance used to work each line out on its own (cash from
//  payments, stock from today's shelf, receivables from party balances...), so
//  nothing made debit equal credit - and they were ₹1.5 lakh apart. Here every
//  sale, purchase, return, payment, expense, transfer, adjustment, opening
//  balance and stock movement is turned into lines that each carry BOTH sides,
//  from the very tables the rest of the app reads. Debit = credit by
//  construction; the only thing that can still break it is a manual journal
//  that does not balance, and the accounting check names those.
//
//  One definition (ledger_sql) feeds the Trial Balance, General Ledger, P&L
//  and Balance Sheet, so they cannot disagree. Nothing here writes a row.
// ============================================================================

/** The accounts the books post to: code => [name, type]. 'P' is one line per
 *  party (split into receivable / payable by its sign), not an account row. */
function ledger_accounts() {
    return [
        '1000' => ['Cash in Hand', 'asset'],
        '1100' => ['Bank Accounts', 'asset'],
        '1150' => ['Money not in a bank yet (UPI / card / cheque saved without a bank account)', 'asset'],
        '1200' => ['Accounts Receivable', 'asset'],
        '1210' => ['Walk-in customers (bills with no party)', 'asset'],
        '1300' => ['Inventory / Stock', 'asset'],
        '1400' => ['GST input credit (on purchases)', 'asset'],
        '1500' => ['Staff advances', 'asset'],
        '1900' => ['Contra settlements (should be zero)', 'asset'],
        '2000' => ['Accounts Payable', 'liability'],
        '2100' => ['GST collected (on sales)', 'liability'],
        '2200' => ['Loans', 'liability'],
        '3000' => ['Owner\'s Capital', 'equity'],
        '3010' => ['Opening balances', 'equity'],
        '3090' => ['Cash / bank adjustments - not yet classified', 'equity'],
        '3100' => ['Drawings (household / personal)', 'equity'],
        '3900' => ['Retained Earnings', 'equity'],
        '4000' => ['Sales Revenue', 'income'],
        '4100' => ['Sales Returns', 'income'],
        '4200' => ['Other income', 'income'],
        '4300' => ['Warranty credit notes from suppliers', 'income'],
        '4400' => ['Discount received', 'income'],
        '5000' => ['Cost of Goods Sold', 'expense'],
        '5100' => ['Stock adjustments (shortage / excess / write-off)', 'expense'],
        '5200' => ['Discount allowed', 'expense'],
        '5300' => ['Loan interest', 'expense'],
        '5400' => ['Bank charges / cash shortage or excess', 'expense'],
        '6000' => ['Operating expenses', 'expense'],
    ];
}

/** What a cash / bank adjustment was, chosen by the owner: key => account. */
function ledger_adjust_kinds() {
    return [
        'capital' => ['3000', '💼 Owner\'s money put in / taken out (capital)'],
        'opening' => ['3010', '📂 Old balance from before the software (opening)'],
        'income'  => ['4200', '💰 Other income (interest, cashback...)'],
        'expense' => ['5400', '➖ Bank charges, or a shortage / excess on counting (expense)'],
        'drawings' => ['3100', '🏠 Taken home / personal (drawings)'],
    ];
}

/** Stock movements that are not part of a document (a sale or a purchase
 *  posts its own stock), valued at the item's purchase price: ref => account. */
function ledger_stock_moves() {
    return ['opening' => '3010', 'import' => '3010',
            'manual_adjust' => '5100', 'cycle_count' => '5100', 'cycle_count_undo' => '5100', 'serial_fix' => '5100',
            'warranty_out' => '5100', 'warranty_back' => '5100', 'warranty_replace' => '5100',
            'repair_use' => '5000', 'repair_use_undo' => '5000', 'repair_delete' => '5000'];
}

/**
 * Every posting as SQL: rows of (d, acct, party, dr, cr, src, ref, descr).
 * Each document contributes rows whose dr and cr add up to the same amount.
 */
function ledger_sql() {
    $quote = fn($s) => db()->quote($s);
    $party = "IF(EXISTS (SELECT 1 FROM parties pp WHERE pp.id = %1\$s.party_id), 'P', '1210')";
    $pid = "IF(EXISTS (SELECT 1 FROM parties pp WHERE pp.id = %1\$s.party_id), %1\$s.party_id, NULL)";
    $P = fn($a) => sprintf($party, $a) . ' acct, ' . sprintf($pid, $a) . ' party';
    // where the money went / came from
    $money = "CASE WHEN %1\$s.mode = 'cash' THEN '1000' WHEN %1\$s.bank_account_id IS NOT NULL THEN '1100'
                   WHEN %1\$s.mode = 'discount' THEN IF(%1\$s.direction = 'in', '5200', '4400')
                   WHEN %1\$s.mode = 'contra' THEN '1900' WHEN %1\$s.mode = 'credit_note' THEN '4300' ELSE '1150' END";
    $expMoney = "CASE WHEN x.mode = 'cash' THEN '1000' WHEN x.bank_account_id IS NOT NULL THEN '1100' ELSE '1150' END";
    $home = implode(',', array_map($quote, expense_category_groups()[expense_home_group()]));
    $retCost = "(SELECT COALESCE(SUM(ri.qty * IF(i.item_type = 'service', 0, COALESCE(NULLIF((SELECT si.cost_price FROM sale_items si WHERE si.sale_id = r.sale_id AND si.item_id = ri.item_id LIMIT 1), 0), i.purchase_price))), 0)
                 FROM sales_return_items ri JOIN items i ON i.id = ri.item_id WHERE ri.return_id = r.id)";
    $retGst = "COALESCE((SELECT IF(s.total > 0, r.total * s.tax_amount / s.total, 0) FROM sales s WHERE s.id = r.sale_id), 0)";
    $moves = [];
    foreach (ledger_stock_moves() as $ref => $acct) $moves[] = "WHEN " . $quote($ref) . " THEN " . $quote($acct);
    $moveRefs = implode(',', array_map($quote, array_keys(ledger_stock_moves())));
    $adj = [];
    foreach (ledger_adjust_kinds() as $k => [$acct]) $adj[] = "WHEN " . $quote($k) . " THEN " . $quote($acct);
    $adjAcct = "CASE t.counter " . implode(' ', $adj) . " ELSE '3090' END";
    $tradeIn = "COALESCE((SELECT SUM(ti.value) FROM trade_ins ti WHERE ti.sale_id = s.id), 0)";

    $parts = [
        // SALE: the customer owes the total; sales (without GST) and GST collected
        "SELECT s.sale_date d, {$P('s')}, s.total dr, 0 cr, 'sale' src, s.id ref, s.invoice_no descr FROM sales s WHERE s.is_cancelled = 0",
        "SELECT s.sale_date, '4000', NULL, 0, s.total - s.tax_amount + $tradeIn, 'sale', s.id, s.invoice_no FROM sales s WHERE s.is_cancelled = 0",
        "SELECT s.sale_date, '2100', NULL, 0, s.tax_amount, 'sale', s.id, s.invoice_no FROM sales s WHERE s.is_cancelled = 0 AND s.tax_amount <> 0",
        // ...an old piece taken in exchange: onto the shelf (or written off when it is not a stock item)
        "SELECT s.sale_date, IF(ti.item_id IS NULL, '5100', '1300'), NULL, ti.value, 0, 'sale', s.id, CONCAT(s.invoice_no, ' exchange') FROM trade_ins ti JOIN sales s ON s.id = ti.sale_id WHERE s.is_cancelled = 0",
        // ...and the goods leave the shelf at their cost (free pieces too). A
        // service line's cost is the outside job cost typed on the bill: it is
        // paid as an expense or a purchase, so it is not posted a second time.
        "SELECT s.sale_date, '5000', NULL, SUM((si.qty + COALESCE(si.free_qty, 0)) * IF(si.cost_price > 0, si.cost_price, i.purchase_price)), 0, 'sale', s.id, s.invoice_no
           FROM sales s JOIN sale_items si ON si.sale_id = s.id JOIN items i ON i.id = si.item_id WHERE s.is_cancelled = 0 AND i.item_type <> 'service' GROUP BY s.id",
        "SELECT s.sale_date, '1300', NULL, 0, SUM((si.qty + COALESCE(si.free_qty, 0)) * IF(si.cost_price > 0, si.cost_price, i.purchase_price)), 'sale', s.id, s.invoice_no
           FROM sales s JOIN sale_items si ON si.sale_id = s.id JOIN items i ON i.id = si.item_id WHERE s.is_cancelled = 0 AND i.item_type <> 'service' GROUP BY s.id",
        // SALES RETURN: sales and GST taken back, the customer owes less; the goods come back at cost
        "SELECT r.return_date, '4100', NULL, r.total - $retGst, 0, 'sales_return', r.id, r.return_no FROM sales_returns r",
        "SELECT r.return_date, '2100', NULL, $retGst, 0, 'sales_return', r.id, r.return_no FROM sales_returns r",
        "SELECT r.return_date, {$P('r')}, 0, r.total, 'sales_return', r.id, r.return_no FROM sales_returns r",
        "SELECT r.return_date, '1300', NULL, $retCost, 0, 'sales_return', r.id, r.return_no FROM sales_returns r",
        "SELECT r.return_date, '5000', NULL, 0, $retCost, 'sales_return', r.id, r.return_no FROM sales_returns r",
        // PURCHASE: goods (with freight, after discount) and GST paid; the supplier is owed the total
        "SELECT pu.purchase_date, '1300', NULL, pu.total - pu.tax_amount, 0, 'purchase', pu.id, pu.bill_no FROM purchases pu WHERE pu.is_cancelled = 0",
        "SELECT pu.purchase_date, '1400', NULL, pu.tax_amount, 0, 'purchase', pu.id, pu.bill_no FROM purchases pu WHERE pu.is_cancelled = 0 AND pu.tax_amount <> 0",
        "SELECT pu.purchase_date, {$P('pu')}, 0, pu.total, 'purchase', pu.id, pu.bill_no FROM purchases pu WHERE pu.is_cancelled = 0",
        // PURCHASE RETURN: the supplier owes us back, the goods leave
        "SELECT r.return_date, {$P('r')}, r.total, 0, 'purchase_return', r.id, r.return_no FROM purchase_returns r",
        "SELECT r.return_date, '1300', NULL, 0, r.total, 'purchase_return', r.id, r.return_no FROM purchase_returns r",
        // PAYMENT in / out: money on one side, the party on the other
        "SELECT p.pay_date, " . sprintf($money, 'p') . ", NULL, IF(p.direction = 'in', p.amount, 0), IF(p.direction = 'out', p.amount, 0), 'payment', p.id, p.mode FROM payments p",
        "SELECT p.pay_date, {$P('p')}, IF(p.direction = 'out', p.amount, 0), IF(p.direction = 'in', p.amount, 0), 'payment', p.id, p.mode FROM payments p",
        // EXPENSE: what it really is - household is drawings, a staff advance is
        // money the staff owes, a loan EMI is principal (loan goes down) + interest
        "SELECT x.exp_date, $expMoney, NULL, 0, x.amount, 'expense', x.id, x.category FROM expenses x",
        "SELECT x.exp_date, CASE WHEN x.category IN ($home) THEN '3100' WHEN x.category = 'Staff Advance' THEN '1500' ELSE '6000' END, NULL,
                x.amount - COALESCE(lp.principal_part + lp.interest, 0), 0, 'expense', x.id, x.category
           FROM expenses x LEFT JOIN loan_payments lp ON lp.expense_id = x.id",
        "SELECT x.exp_date, '2200', NULL, lp.principal_part, 0, 'expense', x.id, x.category FROM expenses x JOIN loan_payments lp ON lp.expense_id = x.id",
        "SELECT x.exp_date, '5300', NULL, lp.interest, 0, 'expense', x.id, x.category FROM expenses x JOIN loan_payments lp ON lp.expense_id = x.id",
        // salary paid net of an advance: the advance is used up as salary
        "SELECT DATE(ps.paid_at), '6000', NULL, ps.advance_deduct, 0, 'payslip', ps.id, 'Salary & Wages' FROM payslips ps WHERE ps.paid_at IS NOT NULL AND ps.advance_deduct > 0",
        "SELECT DATE(ps.paid_at), '1500', NULL, 0, ps.advance_deduct, 'payslip', ps.id, 'Salary & Wages' FROM payslips ps WHERE ps.paid_at IS NOT NULL AND ps.advance_deduct > 0",
        // a LOAN taken: owed from the start (the loans register)
        "SELECT l.start_date, '2200', NULL, 0, l.principal, 'loan', l.id, l.lender FROM loans l",
        "SELECT l.start_date, '3010', NULL, l.principal, 0, 'loan', l.id, l.lender FROM loans l",
        // CASH <-> BANK, and ADJUSTMENTS against what the owner says they were
        "SELECT t.txn_date, IF(t.txn_type = 'cash_to_bank', '1100', '1000'), NULL, t.amount, 0, 'transfer', t.id, t.txn_type FROM money_transfers t WHERE t.status = 'done' AND t.txn_type IN ('cash_to_bank','bank_to_cash')",
        "SELECT t.txn_date, IF(t.txn_type = 'cash_to_bank', '1000', '1100'), NULL, 0, t.amount, 'transfer', t.id, t.txn_type FROM money_transfers t WHERE t.status = 'done' AND t.txn_type IN ('cash_to_bank','bank_to_cash')",
        "SELECT t.txn_date, IF(t.txn_type = 'cash_adjust', '1000', '1100'), NULL, IF(t.adjust_dir = 'add', t.amount, 0), IF(t.adjust_dir = 'add', 0, t.amount), 'adjust', t.id, t.notes FROM money_transfers t WHERE t.status = 'done' AND t.txn_type IN ('cash_adjust','bank_adjust')",
        "SELECT t.txn_date, $adjAcct, NULL, IF(t.adjust_dir = 'add', 0, t.amount), IF(t.adjust_dir = 'add', t.amount, 0), 'adjust', t.id, t.notes FROM money_transfers t WHERE t.status = 'done' AND t.txn_type IN ('cash_adjust','bank_adjust')",
        // OPENING balances: parties and banks, against Opening balances
        "SELECT '0001-01-01', 'P', p.id, GREATEST(p.opening_balance, 0), GREATEST(-p.opening_balance, 0), 'opening', p.id, p.name FROM parties p WHERE p.opening_balance <> 0",
        "SELECT '0001-01-01', '3010', NULL, GREATEST(-p.opening_balance, 0), GREATEST(p.opening_balance, 0), 'opening', p.id, p.name FROM parties p WHERE p.opening_balance <> 0",
        "SELECT '0001-01-01', '1100', NULL, GREATEST(b.opening_balance, 0), GREATEST(-b.opening_balance, 0), 'bank_opening', b.id, b.account_name FROM bank_accounts b WHERE b.opening_balance <> 0",
        "SELECT '0001-01-01', '3010', NULL, GREATEST(-b.opening_balance, 0), GREATEST(b.opening_balance, 0), 'bank_opening', b.id, b.account_name FROM bank_accounts b WHERE b.opening_balance <> 0",
        // STOCK that came or went outside a bill: opening stock, counts, corrections
        "SELECT DATE(sl.created_at), '1300', NULL, GREATEST(sl.change_qty * i.purchase_price, 0), GREATEST(-sl.change_qty * i.purchase_price, 0), 'stock', sl.id, sl.ref_type
           FROM stock_ledger sl JOIN items i ON i.id = sl.item_id WHERE sl.ref_type IN ($moveRefs) AND i.item_type <> 'service'",
        "SELECT DATE(sl.created_at), CASE sl.ref_type " . implode(' ', $moves) . " END, NULL, GREATEST(-sl.change_qty * i.purchase_price, 0), GREATEST(sl.change_qty * i.purchase_price, 0), 'stock', sl.id, sl.ref_type
           FROM stock_ledger sl JOIN items i ON i.id = sl.item_id WHERE sl.ref_type IN ($moveRefs) AND i.item_type <> 'service'",
        // MANUAL journal entries, on whatever account they name
        "SELECT je.entry_date, a.code, NULL, jl.debit, jl.credit, 'journal', je.id, je.ref_no FROM journal_lines jl JOIN journal_entries je ON je.id = jl.entry_id JOIN chart_of_accounts a ON a.id = jl.account_id",
    ];
    return '(' . implode("\nUNION ALL\n", $parts) . ')';
}

/** Net debit (dr − cr) per account from $from through $to. Parties come back
 *  split: '1200' = parties who owe us, '2000' = parties we owe (each party
 *  netted on its own first), plus whatever journals put on 1200 / 2000. */
function ledger_balances($to, $from = '0001-01-01') {
    $out = [];
    foreach (all("SELECT acct, SUM(dr - cr) b FROM " . ledger_sql() . " x WHERE d BETWEEN ? AND ? AND acct <> 'P' GROUP BY acct", [$from, $to]) as $r)
        $out[$r['acct']] = round((float)$r['b'], 2);
    $ar = 0; $ap = 0;
    foreach (all("SELECT party, SUM(dr - cr) b FROM " . ledger_sql() . " x WHERE d BETWEEN ? AND ? AND acct = 'P' GROUP BY party", [$from, $to]) as $r) {
        $b = (float)$r['b'];
        if ($b > 0) $ar += $b; else $ap += $b;
    }
    $out['1200'] = round(($out['1200'] ?? 0) + $ar, 2);
    $out['2000'] = round(($out['2000'] ?? 0) + $ap, 2);
    return $out;
}

/** Every account with a balance, as Trial Balance rows: code, name, type, dr, cr. */
function ledger_trial_balance($to) {
    $bal = ledger_balances($to);
    $names = ledger_accounts();
    foreach (all('SELECT code, name, type, is_active FROM chart_of_accounts') as $a)
        if (!isset($names[$a['code']])) $names[$a['code']] = [$a['name'] . ($a['is_active'] ? '' : ' (switched off)'), $a['type']];
    $rows = [];
    foreach ($bal as $code => $b) {
        if (abs($b) < 0.005) continue;
        [$name, $type] = $names[$code] ?? ['Account ' . $code, 'asset'];
        $rows[] = ['code' => (string)$code, 'name' => $name, 'type' => $type, 'dr' => $b > 0 ? $b : 0, 'cr' => $b < 0 ? -$b : 0];
    }
    usort($rows, fn($a, $b) => strcmp($a['code'], $b['code']));
    return $rows;
}

/** Type of an account code (system or one the owner added). */
function ledger_type($code) {
    static $t = null;
    if ($t === null) {
        $t = array_map(fn($a) => $a[1], ledger_accounts());
        foreach (all('SELECT code, type FROM chart_of_accounts') as $a) $t[$a['code']] = $t[$a['code']] ?? $a['type'];
    }
    return $t[$code] ?? 'asset';
}

/** Profit & Loss for a period, from the same postings:
 *  ['income' => [code => amount], 'cogs', 'expenses' => [label => amount], 'gross', 'net', ...].
 *  Income is shown positive, expenses positive. Operating expenses come by category. */
function ledger_pl($from, $to) {
    $b = ledger_balances($to, $from);
    $inc = []; $exp = [];
    foreach ($b as $code => $v) {
        $code = (string)$code;   // PHP turns numeric keys into ints
        $type = ledger_type($code);
        if ($type === 'income') $inc[$code] = round(-$v, 2);
        elseif ($type === 'expense' && $code !== '5000' && $code !== '6000') $exp[$code] = round($v, 2);
    }
    $sales = $inc['4000'] ?? 0; $returns = -($inc['4100'] ?? 0);   // 4100 carries a debit
    unset($inc['4000'], $inc['4100']);
    $cogs = $b['5000'] ?? 0;
    $byCat = ledger_expenses_by_category($from, $to);
    $names = ledger_accounts();
    foreach ($exp as $code => $v) if (abs($v) > 0.004) $byCat[$names[$code][0] ?? $code] = $v;
    $netSales = round($sales - $returns, 2);
    $other = round(array_sum($inc), 2);
    $gross = round($netSales - $cogs, 2);
    $opex = round(array_sum($byCat), 2);
    return ['sales' => round($sales, 2), 'returns' => round($returns, 2), 'net_sales' => $netSales, 'cogs' => round($cogs, 2), 'gross' => $gross,
            'other_income' => array_filter($inc, fn($v) => abs($v) > 0.004), 'other_total' => $other,
            'expenses' => $byCat, 'expense_total' => $opex, 'net' => round($gross + $other - $opex, 2),
            'drawings' => round($b['3100'] ?? 0, 2), 'household' => ledger_household_by_category($from, $to),
            // what is left after the business's costs AND what went home - each
            // cost counted once: household is never inside 'net', purchases sit
            // in stock until sold (COGS), GST is not income, EMI principal is the loan
            'final' => round($gross + $other - $opex - ($b['3100'] ?? 0), 2),
            'gst' => round(-($b['2100'] ?? 0), 2)];
}

/** Household / personal spending (Drawings) in a period, by category. Money
 *  taken out through a cash / bank adjustment marked "taken home" is its own line. */
function ledger_household_by_category($from, $to) {
    $out = [];
    foreach (all("SELECT IF(src = 'adjust', 'Taken home from cash / bank (adjustment)', descr) cat, SUM(dr - cr) amt FROM " . ledger_sql() . " x
                  WHERE acct = '3100' AND d BETWEEN ? AND ? GROUP BY cat ORDER BY amt DESC", [$from, $to]) as $r)
        if (abs((float)$r['amt']) > 0.004) $out[$r['cat'] ?: 'Other'] = round((float)$r['amt'], 2);
    return $out;
}

/** The three figures every profit screen shows, in the same words:
 *  business profit, household, and what is left after both. */
function ledger_final_label() { return 'Net Profit After Business & Household Expenses (બધા ખર્ચા બાદ બાકી)'; }

/** The three figures as cards, the same on the P&L, the dashboard and the
 *  Business Report. A minus is spelled out as a LOSS, in red. */
function ledger_three_cards(array $pl, $link = '') {
    $card = function ($label, $v, $note, $main = false) use ($link) {
        $loss = $v < -0.004;
        $tag = $link ? 'a href="' . e($link) . '"' : 'div';
        return '<' . $tag . ' class="stat ' . ($loss ? 's-bad' : 's-ok') . '"' . ($main ? ' style="outline:2px solid ' . ($loss ? 'var(--bad)' : 'var(--ok)') . ';outline-offset:-2px"' : '') . '>'
             . '<div class="stat-label">' . e($label) . '</div>'
             . '<div class="stat-value">' . ($loss ? '−₹' . money(-$v) : '₹' . money($v)) . '</div>'
             . '<div class="muted" style="font-size:12px">' . ($loss ? '<b style="color:var(--bad)">LOSS (ખોટ)</b> · ' : '') . e($note) . '</div></' . ($link ? 'a' : 'div') . '>';
    };
    return '<div class="grid-stats">'
         . $card('🏪 Business Net Profit (બિઝનેસનો નફો)', $pl['net'], 'sales − cost of goods − discount − business expenses')
         . '<div class="stat s-warn"><div class="stat-label">🏠 Household / Personal (ઘરખર્ચ)</div><div class="stat-value">₹' . money($pl['drawings']) . '</div><div class="muted" style="font-size:12px">household, personal and owner drawings</div></div>'
         . $card('✅ ' . ledger_final_label(), $pl['final'], 'business profit − household', true)
         . '</div>';
}

/** Operating expenses (account 6000) by category in a period - the part of
 *  each expense that IS an expense (not drawings, staff advance or loan principal). */
function ledger_expenses_by_category($from, $to) {
    $out = [];
    foreach (all("SELECT descr cat, SUM(dr - cr) amt FROM " . ledger_sql() . " x WHERE acct = '6000' AND d BETWEEN ? AND ? GROUP BY descr ORDER BY amt DESC", [$from, $to]) as $r)
        if (abs((float)$r['amt']) > 0.004) $out[$r['cat'] ?: 'Other'] = round((float)$r['amt'], 2);
    return $out;
}

/** Net profit for a period. */
function ledger_net_profit($from, $to) { return ledger_pl($from, $to)['net']; }

/** General Ledger lines of one account in a period: date, desc, debit, credit, link. */
function ledger_gl_rows($code, $from, $to) {
    $links = ['sale' => 'sale_view.php?id=', 'purchase' => 'purchase_view.php?id=', 'payment' => 'payments.php?action=view&id=',
              'journal' => 'journal.php?action=view&id=', 'sales_return' => 'sales_return.php?action=view&id=', 'purchase_return' => 'purchase_return.php?action=view&id='];
    $what = ['sale' => 'Sale', 'purchase' => 'Purchase', 'payment' => 'Payment', 'journal' => 'Journal', 'sales_return' => 'Sales return',
             'purchase_return' => 'Purchase return', 'expense' => 'Expense', 'payslip' => 'Salary (advance used)', 'loan' => 'Loan', 'transfer' => 'Cash ↔ bank',
             'adjust' => 'Adjustment', 'opening' => 'Party opening', 'bank_opening' => 'Bank opening', 'stock' => 'Stock movement'];
    $where = in_array($code, ['1200', '2000'], true) ? "(acct = 'P' OR acct = ?)" : 'acct = ?';
    $rows = [];
    foreach (all("SELECT x.*, pt.name pname FROM " . ledger_sql() . " x LEFT JOIN parties pt ON pt.id = x.party WHERE $where AND d BETWEEN ? AND ? AND (dr <> 0 OR cr <> 0) ORDER BY d, src, ref", [$code, $from, $to]) as $r)
        $rows[] = ['date' => $r['d'] === '0001-01-01' ? $from : $r['d'],
                   'desc' => ($what[$r['src']] ?? $r['src']) . ($r['descr'] !== null && $r['descr'] !== '' ? ' ' . $r['descr'] : '') . ($r['pname'] ? ' - ' . $r['pname'] : ''),
                   'debit' => round((float)$r['dr'], 2), 'credit' => round((float)$r['cr'], 2),
                   'link' => isset($links[$r['src']]) ? $links[$r['src']] . $r['ref'] : null];
    return $rows;
}

/** Stock: the books' figure (opening + purchases − cost of sales ± corrections)
 *  next to the shelf's (today's quantity × today's purchase price), and why they differ. */
function ledger_stock_check() {
    $book = (float)(ledger_balances('9999-12-31')['1300'] ?? 0);
    $shelf = stock_value();
    // quantity the stock table holds vs what its movement log adds up to
    $qtyGap = (float)val("SELECT COALESCE(SUM((sq.q - COALESCE(lg.q, 0)) * i.purchase_price), 0)
                          FROM " . stock_qty_sql() . " sq JOIN items i ON i.id = sq.item_id
                          LEFT JOIN (SELECT item_id, SUM(change_qty) q FROM stock_ledger GROUP BY item_id) lg ON lg.item_id = sq.item_id
                          WHERE i.item_type <> 'service'");
    return ['book' => round($book, 2), 'shelf' => round($shelf, 2), 'diff' => round($shelf - $book, 2), 'qty_gap' => round($qtyGap, 2)];
}
