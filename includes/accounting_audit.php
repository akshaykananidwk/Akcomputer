<?php
// ACCOUNTING HEALTH CHECK - READ ONLY. Nothing here writes a single row.
//
// The Trial Balance (report_body.php) is not a double-entry ledger: each line
// is worked out on its own from a different table (cash from payments, stock
// from today's shelf value, receivables from party balances...). So debit and
// credit only agree when every money movement happens to appear on both
// sides. This file explains, line by line, WHY they do not - each reason as
// an amount, and the reasons add up to the Trial Balance difference exactly
// (the test proves it). Diagnosis only: it changes nothing.

/** Debit and credit totals exactly as the Trial Balance screen adds them. */
function acct_tb_totals($to) {
    $dr = 0; $cr = 0;
    foreach (coa_all() as $a) {
        $bal = coa_balance_asof($a, $to);
        if ($bal > 0) $dr += $bal; elseif ($bal < 0) $cr += -$bal;
    }
    return ['dr' => round($dr, 2), 'cr' => round($cr, 2), 'diff' => round($dr - $cr, 2)];
}

/**
 * The difference (debit − credit), taken apart. Each part is
 * ['key', 'amount' (signed: + makes debit bigger), 'kind', 'what', 'why', 'fix'].
 * kind: 'software' (the way the report calculates), 'opening' (balances from
 * before the books were kept here), 'entry' (how particular entries were made).
 */
function acct_tb_breakdown($to) {
    $v = fn($sql, $a = []) => round((float)val($sql, $a), 2);
    $P = "EXISTS (SELECT 1 FROM parties pp WHERE pp.id = %s.party_id)";
    $out = [];
    $add = function ($key, $amt, $kind, $what, $why, $fix) use (&$out) { $out[] = compact('key', 'kind', 'what', 'why', 'fix') + ['amount' => round($amt, 2)]; };

    $add('party_opening', $v('SELECT COALESCE(SUM(opening_balance),0) FROM parties'), 'opening',
         'Parties\' opening balances (old dues before the software)',
         'They sit in Receivables/Payables, but no Opening Capital line holds the other side.',
         'An "Opening balances" equity line equal to all opening figures - posted once, after your approval.');
    $add('bank_opening', $v('SELECT COALESCE(SUM(opening_balance),0) FROM bank_accounts'), 'opening',
         'Bank accounts\' opening balances',
         'Counted in Bank, with no Opening Capital on the other side.', 'Same Opening Capital line as above.');
    $add('stock_value', coa_stock_value(), 'software',
         'Stock shown at today\'s shelf value (qty × purchase price)',
         'It is a count of the shelf, not the result of entries: it is not "opening stock + purchases − cost of sales". Opening stock, stock corrections and price changes all land here.',
         'Show stock as opening stock + purchases − cost of sales, and post the difference to a "Stock adjustment" line.');
    $add('cogs', coa_cogs('0001-01-01', $to), 'software', 'Cost of goods sold (debit)', 'Paired below with purchases and the stock value.', '');
    $add('purchases', -$v("SELECT COALESCE(SUM(total),0) FROM purchases WHERE is_cancelled = 0 AND purchase_date <= ? AND " . sprintf($P, 'purchases'), [$to]), 'software',
         'Purchase bills (credit to suppliers)',
         'Suppliers are credited, but there is no Purchases (or Stock) debit for them - the stock line is the shelf count instead. Purchase totals also include GST paid (input credit) and freight, which are not stock.',
         'A Purchases/Stock debit for every purchase bill, with input GST to its own line.');
    $add('purchase_returns', $v("SELECT COALESCE(SUM(total),0) FROM purchase_returns WHERE return_date <= ? AND " . sprintf($P, 'purchase_returns'), [$to]), 'software',
         'Purchase returns (debit to suppliers)', 'No matching credit to purchases/stock.', 'Paired with the line above.');
    $add('expenses', -$v("SELECT COALESCE(SUM(amount * ((mode = 'cash') + (bank_account_id IS NOT NULL))),0) FROM expenses WHERE exp_date <= ?", [$to]), 'software',
         'Expenses paid from cash or bank',
         'Cash/bank go down, but the Trial Balance has NO expense line at all - expenses only appear in the Profit & Loss.',
         'Add expense lines (by category) to the Trial Balance.');
    $add('returns_twice', $v('SELECT COALESCE(SUM(total),0) FROM sales_returns WHERE return_date <= ?', [$to]), 'software',
         'Sales returns counted twice',
         'The Sales Revenue line is already net of returns, and the separate "Sales Returns" line deducts them again.',
         'Show Sales Revenue gross (before returns) when the Sales Returns line is shown.');
    $add('walkin_sales', -$v("SELECT COALESCE(SUM(total),0) FROM sales WHERE is_cancelled = 0 AND sale_date <= ? AND NOT " . sprintf($P, 'sales'), [$to]), 'software',
         'Bills with no party (walk-in)',
         'Credited to Sales; the money taken on them is in Cash/Bank (next line), but whatever was NOT paid is in no receivable at all.',
         'Count walk-in dues as a receivable ("Walk-in customers").');
    // (LEAST: a walk-in receipt marked both cash and bank is counted once here,
    // its second count is in 'double_counted' below)
    $add('walkin_receipts', $v("SELECT COALESCE(SUM(IF(direction='in',1,-1) * amount * LEAST(1, (mode = 'cash') + (bank_account_id IS NOT NULL))),0) FROM payments WHERE pay_date <= ? AND NOT " . sprintf($P, 'payments'), [$to]), 'software',
         'Money taken / paid with no party (walk-in bills)', 'The other side of the line above.', '');
    $add('walkin_returns', $v("SELECT COALESCE(SUM(total),0) FROM sales_returns WHERE return_date <= ? AND NOT " . sprintf($P, 'sales_returns'), [$to]), 'software',
         'Sales returns with no party', 'Deducted from sales, but no customer or cash line takes the other side.', '');
    $add('noncash_party', $v("SELECT COALESCE(SUM(IF(direction='in',1,-1) * amount * (((mode = 'cash') + (bank_account_id IS NOT NULL)) - 1)),0) FROM payments WHERE pay_date <= ? AND " . sprintf($P, 'payments') . " AND ((mode = 'cash') + (bank_account_id IS NOT NULL)) = 0", [$to]), 'entry',
         'Party payments that are neither cash nor a bank account',
         'Settlement discounts, contras, credit notes - and UPI/card/cheque entries saved without a bank account - change what the party owes but appear in no cash, bank or expense line.',
         'Discount → "Discount allowed" expense; credit note → purchase return; UPI/card/cheque → pick the bank account.');
    $add('double_counted', $v("SELECT COALESCE(SUM(IF(direction='in',1,-1) * amount),0) FROM payments WHERE pay_date <= ? AND mode = 'cash' AND bank_account_id IS NOT NULL", [$to]), 'entry',
         'Payments marked "cash" that also carry a bank account',
         'Counted in Cash AND in Bank.', 'Clear the bank account on these (old entries; new ones cannot happen).');
    $add('adjustments', $v("SELECT COALESCE(SUM(IF(adjust_dir='add',1,-1) * amount),0) FROM money_transfers WHERE status='done' AND txn_type IN ('cash_adjust','bank_adjust') AND txn_date <= ?", [$to]), 'entry',
         'Cash / bank balance adjustments',
         'They change cash or bank directly, with no account on the other side (it is usually opening cash, or a counting difference).',
         'Each adjustment against an account: Opening Capital, Drawings, or "Cash short/excess".');
    // a switched-off account drops out of the Trial Balance entirely: its
    // journal lines, and for an automatic account its whole computed figure
    $inactive = 0;
    foreach (coa_all(false) as $a) if (!$a['is_active']) $inactive += coa_balance_asof($a, $to);
    $add('inactive_accounts', -$inactive, 'entry', 'Accounts that were switched off',
         'The Trial Balance lists only active accounts, so whatever is on a switched-off account (journal lines, or an automatic account\'s figure) disappears from one side.', 'Switch the account back on, or move the lines.');
    $add('unbalanced_journals', $v("SELECT COALESCE(SUM(jl.debit - jl.credit),0) FROM journal_lines jl JOIN journal_entries je ON je.id = jl.entry_id WHERE je.entry_date <= ?", [$to]), 'entry',
         'Journal entries whose debit ≠ credit', 'A journal must balance; these do not.', 'Correct each entry.');
    return $out;
}

/** The other checks of the audit: banks, P&L, stock, parties, entries.
 *  Each is ['area', 'ok' (bool), 'title', 'amount' (or null), 'detail', 'kind']. */
function acct_checks($from, $to) {
    $v = fn($sql, $a = []) => round((float)val($sql, $a), 2);
    $c = [];
    $add = function ($area, $ok, $title, $amount, $detail, $kind = 'software') use (&$c) { $c[] = compact('area', 'ok', 'title', 'amount', 'detail', 'kind'); };

    // --- 2. Bank: the Trial Balance's one Bank line vs each bank's own ledger
    $tbBank = round(coa_bank_balance($to), 2);
    $sumActive = 0;
    $banks = all('SELECT * FROM bank_accounts ORDER BY id');
    foreach ($banks as $b) {
        $led = round(bank_account_balance($b['id']), 2);   // the Bank Ledger / Cash & Bank figure (all dates)
        if ($b['is_active']) $sumActive += $led;
    }
    $inactiveBal = round(array_sum(array_map(fn($b) => $b['is_active'] ? 0 : bank_account_balance($b['id']), $banks)), 2);
    $orphan = $v("SELECT COALESCE(SUM(IF(direction='in',1,-1) * amount),0) FROM payments WHERE bank_account_id IS NOT NULL AND pay_date <= ? AND bank_account_id NOT IN (SELECT id FROM bank_accounts)", [$to])
            - $v("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE bank_account_id IS NOT NULL AND exp_date <= ? AND bank_account_id NOT IN (SELECT id FROM bank_accounts)", [$to]);
    $future = round(array_sum(array_map(fn($b) => bank_account_balance($b['id']), $banks)) - coa_bank_balance($to), 2) + $orphan;
    $jBank = round(journal_balance((int)val("SELECT id FROM chart_of_accounts WHERE code = '1100'"), '0001-01-01', $to), 2);
    $add('bank', abs($tbBank + $jBank - $sumActive) < 0.01, 'Trial Balance Bank vs Cash & Bank page (active banks)', round($tbBank + $jBank - $sumActive, 2),
         sprintf('Trial Balance bank ₹%s (of which journal entries ₹%s) · active bank accounts ₹%s · switched-off accounts ₹%s · entries on a deleted bank account ₹%s · entries dated after %s ₹%s',
                 money($tbBank + $jBank), money($jBank), money($sumActive), money($inactiveBal), money($orphan), dmy($to), money($future)));
    $noBank = all("SELECT mode, COUNT(*) n, SUM(amount) amt FROM payments WHERE bank_account_id IS NULL AND mode <> 'cash' AND mode NOT IN ('contra','discount','credit_note') AND pay_date <= ? GROUP BY mode", [$to]);
    $add('bank', !$noBank, 'UPI / card / cheque payments saved without a bank account', $noBank ? round(array_sum(array_column($noBank, 'amt')), 2) : 0,
         $noBank ? implode(', ', array_map(fn($r) => $r['mode'] . ': ' . $r['n'] . ' entries ₹' . money($r['amt']), $noBank)) . ' - these are in no cash and no bank figure.' : 'None.', 'entry');
    $noBankExp = all("SELECT mode, COUNT(*) n, SUM(amount) amt FROM expenses WHERE bank_account_id IS NULL AND mode <> 'cash' AND exp_date <= ? GROUP BY mode", [$to]);
    $add('bank', !$noBankExp, 'Expenses paid by UPI / bank without a bank account', $noBankExp ? round(array_sum(array_column($noBankExp, 'amt')), 2) : 0,
         $noBankExp ? implode(', ', array_map(fn($r) => $r['mode'] . ': ' . $r['n'] . ' entries ₹' . money($r['amt']), $noBankExp)) : 'None.', 'entry');
    $jCash = round(journal_balance((int)val("SELECT id FROM chart_of_accounts WHERE code = '1000'"), '0001-01-01', $to), 2);
    $add('cash', abs($jCash) < 0.01, 'Journal entries posted straight to Cash in Hand', $jCash,
         'They change the Trial Balance cash but NOT the Cash & Bank page or the day closing, so the two cash figures differ by this.', 'software');

    // --- 4. Profit & Loss in the period
    $gst = $v('SELECT COALESCE(SUM(tax_amount),0) FROM sales WHERE is_cancelled = 0 AND sale_date BETWEEN ? AND ?', [$from, $to]);
    $add('pl', $gst < 0.01, 'GST collected is counted as sales revenue', $gst,
         'P&L revenue = bill totals including GST. The GST belongs to the government (minus input credit): profit is overstated by this, and the Balance Sheet has no "GST payable".');
    $retCost = 0;
    try {
        $retCost = $v("SELECT COALESCE(SUM(ri.qty * COALESCE(NULLIF((SELECT si.cost_price FROM sale_items si WHERE si.sale_id = r.sale_id AND si.item_id = ri.item_id LIMIT 1), 0), i.purchase_price)),0)
                       FROM sales_return_items ri JOIN sales_returns r ON r.id = ri.return_id JOIN items i ON i.id = ri.item_id
                       WHERE r.return_date BETWEEN ? AND ? AND i.item_type <> 'service'", [$from, $to]);
    } catch (Exception $e) { }
    $add('pl', $retCost < 0.01, 'Cost of returned goods is not taken back out of COGS', $retCost,
         'A sales return reduces revenue, but the cost of the goods that came back to the shelf stays in Cost of Goods Sold - profit is understated by this.');
    $retInP = $v('SELECT COALESCE(SUM(total),0) FROM sales_returns WHERE return_date BETWEEN ? AND ?', [$from, $to]);
    $add('pl', true, 'Sales returns in the P&L', $retInP, 'Deducted once from revenue in the P&L - correct (it is only the Trial Balance that deducts them twice).');
    $cats = all('SELECT category, SUM(amount) amt FROM expenses WHERE exp_date BETWEEN ? AND ? GROUP BY category', [$from, $to]);
    $notPL = array_values(array_filter($cats, fn($r) => preg_match('/advance|loan|emi|drawing|personal|household|home|owner/i', $r['category']) || expense_is_home($r['category'])));
    $add('pl', !$notPL, 'Expense kinds that are not really a business expense', $notPL ? round(array_sum(array_column($notPL, 'amt')), 2) : 0,
         $notPL ? implode(', ', array_map(fn($r) => $r['category'] . ' ₹' . money($r['amt']), $notPL))
               . ' - staff advance is money the staff owes (an asset), a loan EMI is mostly the loan being repaid (a liability going down; only the interest is an expense), household/personal/owner spending is Drawings (out of capital). All are deducted as expenses today, so profit is understated by this.'
               : 'None in this period.');

    // --- 5. Stock
    $add('stock', true, 'Stock value used in the reports', round(coa_stock_value(), 2),
         'Today\'s shelf quantity × each item\'s current purchase price, also for a past date. It is the same figure in the Balance Sheet, Trial Balance and Stock report.');
    $negStock = (int)val('SELECT COUNT(*) FROM stock WHERE qty < 0');
    $add('stock', $negStock === 0, 'Items with negative stock', $negStock,
         $negStock ? $negStock . ' item/location rows are below zero (sold before the purchase was entered). Their value counts as minus in the stock total.' : 'None.', 'entry');
    $noCost = $v("SELECT COUNT(*) FROM sale_items si JOIN sales s ON s.id = si.sale_id JOIN items i ON i.id = si.item_id WHERE s.is_cancelled = 0 AND s.sale_date BETWEEN ? AND ? AND i.item_type <> 'service' AND si.cost_price <= 0 AND i.purchase_price <= 0", [$from, $to]);
    $add('stock', $noCost < 1, 'Bill lines with no cost at all', $noCost, $noCost ? 'These items have no purchase price, so their Cost of Goods Sold is ₹0 and the profit on them is the whole sale.' : 'None.', 'entry');

    // --- 6. Parties
    $walkDue = round(walkin_due(), 2);
    $add('party', $walkDue < 0.01, 'Unpaid amount on walk-in bills (no party)', $walkDue,
         'Not in any party ledger and not in Receivables / Balance Sheet. It cannot be collected through Payment-In.', 'entry');
    $billGap = $v("SELECT COALESCE(SUM(x.gap),0) FROM (SELECT p.id, ABS(" . party_balance_expr('p') . " - p.opening_balance
                   - COALESCE((SELECT SUM(s.total - s.paid) FROM sales s WHERE s.party_id = p.id AND s.is_cancelled = 0),0)
                   + COALESCE((SELECT SUM(pu.total - pu.paid) FROM purchases pu WHERE pu.party_id = p.id AND pu.is_cancelled = 0),0)) gap FROM parties p) x WHERE x.gap > 0.01");
    $add('party', true, 'Party balance vs their open bills (information)', $billGap,
         'The ledger balance and the sum of each bill\'s unpaid amount differ by this in total. Normal when there are advances, opening balances not linked to bills, returns or contras - it is not a calculation error by itself.');
    $paidGap = (int)val("SELECT COUNT(*) FROM sales s WHERE s.is_cancelled = 0 AND s.paid > s.total + 0.01");
    $add('party', $paidGap === 0, 'Bills marked paid more than their total', $paidGap, $paidGap ? 'Paid exceeds the bill total on these bills.' : 'None.', 'entry');

    // --- 9. Journals and duplicates
    $unbal = all('SELECT je.id, je.ref_no, SUM(jl.debit) d, SUM(jl.credit) c FROM journal_entries je JOIN journal_lines jl ON jl.entry_id = je.id GROUP BY je.id HAVING ABS(d - c) > 0.01');
    $add('journal', !$unbal, 'Journal entries that do not balance', count($unbal), $unbal ? implode(', ', array_map(fn($j) => $j['ref_no'] . ' (Dr ' . money($j['d']) . ' / Cr ' . money($j['c']) . ')', $unbal)) : 'None.', 'entry');
    $sysJ = all("SELECT a.code, a.name, COUNT(*) n FROM journal_lines jl JOIN chart_of_accounts a ON a.id = jl.account_id WHERE a.is_system = 1 GROUP BY a.id");
    $add('journal', !$sysJ, 'Journal entries posted to the automatic accounts', $sysJ ? array_sum(array_column($sysJ, 'n')) : 0,
         $sysJ ? implode(', ', array_map(fn($j) => $j['code'] . ' ' . $j['name'] . ': ' . $j['n'] . ' lines', $sysJ)) . ' - these move the Trial Balance / Balance Sheet, but not the screens those accounts come from (Cash & Bank, party ledgers, stock).' : 'None.', 'software');
    $dupPay = all("SELECT ref_type, ref_id, amount, pay_date, COUNT(*) n FROM payments WHERE ref_type IN ('sale','purchase') AND ref_id IS NOT NULL GROUP BY ref_type, ref_id, amount, pay_date, mode HAVING n > 1 LIMIT 50");
    $add('journal', !$dupPay, 'Possible duplicate payments (same bill, amount, date and mode)', count($dupPay),
         $dupPay ? implode(', ', array_map(fn($d) => $d['ref_type'] . ' #' . $d['ref_id'] . ' ₹' . money($d['amount']) . ' ×' . $d['n'] . ' on ' . dmy($d['pay_date']), array_slice($dupPay, 0, 10))) . ' - check each; two real payments of the same amount on one day are possible.' : 'None.', 'entry');
    $paidMismatch = all("SELECT s.id, s.invoice_no, s.paid, COALESCE((SELECT SUM(amount) FROM payments WHERE ref_type='sale' AND ref_id = s.id),0)
                         + COALESCE((SELECT SUM(pa.amount) FROM payment_allocations pa JOIN payments p ON p.id = pa.payment_id WHERE pa.ref_type='sale' AND pa.ref_id = s.id AND NOT (p.ref_type <=> 'sale' AND p.ref_id <=> s.id)),0) got
                         FROM sales s WHERE s.is_cancelled = 0 HAVING ABS(s.paid - got) > 0.01 LIMIT 50");
    $add('journal', !$paidMismatch, 'Bills whose "paid" does not match the payments recorded against them', count($paidMismatch),
         $paidMismatch ? implode(', ', array_map(fn($s) => $s['invoice_no'] . ' (paid ₹' . money($s['paid']) . ', payments ₹' . money($s['got']) . ')', array_slice($paidMismatch, 0, 10))) : 'None.', 'entry');
    return $c;
}
