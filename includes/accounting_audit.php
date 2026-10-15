<?php
// ACCOUNTING HEALTH CHECK - READ ONLY. Nothing here writes a single row.
//
// The Trial Balance is now true double entry (includes/ledger.php) and
// balances by construction. The Trial Balance BEFORE that worked each line out
// on its own from a different table (cash from payments, stock from today's
// shelf value, receivables from party balances...), so debit and credit only
// agreed by accident - the shop saw ₹1,50,279.62 between them. This file still
// takes that OLD difference apart, reason by reason, adding up to it exactly
// (the test proves it), so the owner can see where each rupee of it went; and
// it lists what the new books need the owner to look at. Diagnosis only.

/** Debit and credit totals as the OLD Trial Balance screen added them. */
function acct_tb_totals($to) {
    $dr = 0; $cr = 0;
    foreach (coa_all() as $a) {
        $bal = acct_old_balance($a, $to);
        if ($bal > 0) $dr += $bal; elseif ($bal < 0) $cr += -$bal;
    }
    return ['dr' => round($dr, 2), 'cr' => round($cr, 2), 'diff' => round($dr - $cr, 2)];
}
/** One account's balance the old way (each figure from its own table). */
function acct_old_balance($account, $to) {
    $bal = 0;
    if ($account['is_system']) {
        switch ($account['code']) {
            case '1000': $bal = coa_cash_balance($to); break;
            case '1100': $bal = coa_bank_balance($to); break;
            case '1200': $bal = coa_ar_ap($to)['ar']; break;
            case '1300': $bal = coa_stock_value(); break;
            case '2000': $bal = -coa_ar_ap($to)['ap']; break;
            case '4000': $bal = -coa_sales_revenue('0001-01-01', $to); break;
            case '4100': $bal = (float)val("SELECT COALESCE(SUM(total),0) FROM sales_returns WHERE return_date <= ?", [$to]); break;
            case '5000': $bal = coa_cogs('0001-01-01', $to); break;
        }
    }
    return $bal + journal_balance($account['id'], '0001-01-01', $to);
}

/** The NEW (double-entry) Trial Balance totals. */
function acct_new_tb_totals($to) {
    $rows = ledger_trial_balance($to);
    $dr = round(array_sum(array_column($rows, 'dr')), 2); $cr = round(array_sum(array_column($rows, 'cr')), 2);
    return ['dr' => $dr, 'cr' => $cr, 'diff' => round($dr - $cr, 2), 'rows' => $rows];
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
    foreach (coa_all(false) as $a) if (!$a['is_active']) $inactive += acct_old_balance($a, $to);
    $add('inactive_accounts', -$inactive, 'entry', 'Accounts that were switched off',
         'The Trial Balance lists only active accounts, so whatever is on a switched-off account (journal lines, or an automatic account\'s figure) disappears from one side.', 'Switch the account back on, or move the lines.');
    $add('unbalanced_journals', $v("SELECT COALESCE(SUM(jl.debit - jl.credit),0) FROM journal_lines jl JOIN journal_entries je ON je.id = jl.entry_id WHERE je.entry_date <= ?", [$to]), 'entry',
         'Journal entries whose debit ≠ credit', 'A journal must balance; these do not.', 'Correct each entry.');
    return $out;
}

/** What the double-entry books need the owner to look at, each with its
 *  amount and the entries behind it. Read only. */
function acct_attention($to) {
    $b = ledger_balances($to);
    $v = fn($sql, $a = []) => round((float)val($sql, $a), 2);
    $o = [];
    // what "Opening balances" is made of
    $o['opening'] = [];
    foreach (all("SELECT src, SUM(cr - dr) amt FROM " . ledger_sql() . " x WHERE acct = '3010' AND d <= ? GROUP BY src", [$to]) as $r)
        $o['opening'][$r['src']] = round((float)$r['amt'], 2);
    $o['opening_total'] = round(-($b['3010'] ?? 0), 2);
    // adjustments nobody has said the meaning of
    $o['unclassified'] = all("SELECT t.id, t.txn_type, t.adjust_dir, t.amount, t.txn_date, t.notes, ba.account_name bank
                              FROM money_transfers t LEFT JOIN bank_accounts ba ON ba.id = t.to_bank_id
                              WHERE t.status = 'done' AND t.txn_type IN ('cash_adjust','bank_adjust') AND t.counter IS NULL AND t.txn_date <= ?
                              ORDER BY t.amount DESC", [$to]);
    $o['unclassified_total'] = round(-($b['3090'] ?? 0), 2);
    // money saved with no bank account
    $o['no_bank'] = all("SELECT 'payment' kind, mode, COUNT(*) n, SUM(IF(direction='in',1,-1) * amount) amt FROM payments
                         WHERE mode <> 'cash' AND bank_account_id IS NULL AND mode NOT IN ('contra','discount','credit_note') AND pay_date <= ? GROUP BY mode
                         UNION ALL SELECT 'expense', mode, COUNT(*), -SUM(amount) FROM expenses WHERE mode <> 'cash' AND bank_account_id IS NULL AND exp_date <= ? GROUP BY mode", [$to, $to]);
    $o['no_bank_total'] = round($b['1150'] ?? 0, 2);
    $o['walkin'] = round($b['1210'] ?? 0, 2);
    $o['contra'] = round($b['1900'] ?? 0, 2);
    $o['gst_out'] = round(-($b['2100'] ?? 0), 2);
    $o['gst_in'] = round($b['1400'] ?? 0, 2);
    $o['staff_adv'] = round($b['1500'] ?? 0, 2);
    $o['loans'] = round(-($b['2200'] ?? 0), 2);
    $o['stock'] = ledger_stock_check();
    $o['cash_books'] = round($b['1000'] ?? 0, 2);
    $o['cash_page'] = round(coa_cash_balance($to), 2);
    $o['unbalanced'] = all('SELECT je.id, je.ref_no, je.entry_date, SUM(jl.debit) d, SUM(jl.credit) c FROM journal_entries je JOIN journal_lines jl ON jl.entry_id = je.id WHERE je.entry_date <= ? GROUP BY je.id HAVING ABS(d - c) > 0.01', [$to]);
    return $o;
}

/** The other checks of the audit: banks, P&L, stock, parties, entries.
 *  Each is ['area', 'ok' (bool), 'title', 'amount' (or null), 'detail', 'kind']. */
function acct_checks($from, $to) {
    $v = fn($sql, $a = []) => round((float)val($sql, $a), 2);
    $c = [];
    $add = function ($area, $ok, $title, $amount, $detail, $kind = 'software') use (&$c) { $c[] = compact('area', 'ok', 'title', 'amount', 'detail', 'kind'); };

    // --- 2. Bank: the Trial Balance's one Bank line vs each bank's own ledger
    $tbBank = round(coa_bank_balance($to), 2);   // (old method, for the breakdown below)
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
    $newBank = round(ledger_balances($to)['1100'] ?? 0, 2);
    $dbl = $v("SELECT COALESCE(SUM(IF(direction='in',1,-1) * amount),0) FROM payments WHERE pay_date <= ? AND mode = 'cash' AND bank_account_id IS NOT NULL", [$to]);
    $add('bank', abs($newBank - $sumActive) < 0.01, 'Books bank vs Cash & Bank page (active banks)', round($newBank - $sumActive, 2),
         sprintf('Books ₹%s · Cash & Bank page ₹%s. A gap comes from: payments marked "cash" that also carry a bank account (the page counts them in the bank too) ₹%s, journal entries on 1100 Bank ₹%s, switched-off bank accounts ₹%s, entries on a deleted bank account ₹%s, entries dated after %s.',
                 money($newBank), money($sumActive), money($dbl), money($jBank), money($inactiveBal), money($orphan), dmy($to)), 'entry');
    $add('bank', abs($tbBank + $jBank - $sumActive) < 0.01, 'Old Trial Balance Bank vs Cash & Bank page (active banks)', round($tbBank + $jBank - $sumActive, 2),
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

    // --- 4. Profit & Loss in the period (the double-entry P&L)
    $pl = ledger_pl($from, $to);
    $gst = $v('SELECT COALESCE(SUM(tax_amount),0) FROM sales WHERE is_cancelled = 0 AND sale_date BETWEEN ? AND ?', [$from, $to]);
    $add('pl', true, 'GST kept out of sales', $gst,
         'Sales in the P&L are now without GST; the GST collected (₹' . money($pl['gst']) . ' net of returns) is a liability on the Balance Sheet. It used to be counted as income.');
    $add('pl', true, 'Cost of returned goods taken back out of COGS', null,
         'A sales return now puts the goods back in stock at the cost they were sold at, and takes that cost out of Cost of Goods Sold.');
    $notExp = $v("SELECT COALESCE(SUM(dr - cr),0) FROM " . ledger_sql() . " x WHERE src IN ('expense','payslip') AND acct IN ('3100','1500','2200') AND d BETWEEN ? AND ?", [$from, $to]);
    $add('pl', true, 'Expenses that are not business expenses, kept out of the P&L', $notExp,
         'Household / personal → Drawings ₹' . money($pl['drawings']) . '; staff advances → money the staff owe (asset); the principal part of a loan EMI → the loan goes down. Only real business costs are deducted.');
    $emiLoose = $v("SELECT COALESCE(SUM(x.amount),0) FROM expenses x WHERE x.category = 'EMI / Loan' AND x.exp_date BETWEEN ? AND ? AND NOT EXISTS (SELECT 1 FROM loan_payments lp WHERE lp.expense_id = x.id)", [$from, $to]);
    $add('pl', $emiLoose < 0.01, 'Loan EMIs not paid through Books → Loans', $emiLoose,
         $emiLoose > 0.009 ? 'These EMIs were typed as ordinary expenses, so the software cannot split them into principal and interest: the whole EMI is counted as an expense. Pay EMIs from Books → Loans → "EMI paid" and the split is automatic.' : 'None.', 'entry');
    $capital = all("SELECT category, SUM(amount) amt FROM expenses WHERE category IN ('Office Equipment & Furniture','GST & Government') AND exp_date BETWEEN ? AND ? GROUP BY category", [$from, $to]);
    $add('pl', true, 'Expenses worth a second look', $capital ? round(array_sum(array_column($capital, 'amt')), 2) : 0,
         $capital ? implode(', ', array_map(fn($r) => $r['category'] . ' ₹' . money($r['amt']), $capital)) . ' - furniture / equipment is an asset (Books → Assets, with depreciation), and GST paid to the government reduces the GST you owe rather than being a cost. Kept as expenses until you decide.' : 'None.', 'entry');

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
    $paidMismatch = all("SELECT s.id, s.invoice_no, s.paid, ROUND(" . money_bill_recorded_sql('s') . ", 2) got
                         FROM sales s WHERE s.is_cancelled = 0 HAVING ABS(s.paid - got) > 0.01 LIMIT 50");
    $add('journal', !$paidMismatch, 'Bills whose "paid" does not match the payments recorded against them', count($paidMismatch),
         $paidMismatch ? implode(', ', array_map(fn($s) => $s['invoice_no'] . ' (paid ₹' . money($s['paid']) . ', payments ₹' . money($s['got']) . ')', array_slice($paidMismatch, 0, 10))) : 'None.', 'entry');
    return $c;
}

// ---------------------------------------------------------------------------
// ENTRY BY ENTRY - the Data Health Check's warnings, every affected entry,
// each with what it most likely is. Still read only.
// ---------------------------------------------------------------------------

/** Bills whose "paid" differs from the money recorded against them, counted
 *  the right way: payments made WITH the bill (ref_type sale) plus later
 *  payments linked to it. The Data Health check counts only the second kind,
 *  so a bill paid partly at the counter and partly later shows up there even
 *  though it is right. Returns every bill the health check flags, each marked. */
function acct_detail_paid() {
    $rows = all("SELECT s.id, s.invoice_no, s.sale_date, s.total, s.paid, COALESCE(p.name, s.customer_name, 'Walk-in') party,
                   ROUND(" . money_bill_recorded_sql('s') . ", 2) recorded,
                   COALESCE((SELECT SUM(pa.amount) FROM payment_allocations pa WHERE pa.ref_type = 'sale' AND pa.ref_id = s.id), 0) linked
                 FROM sales s LEFT JOIN parties p ON p.id = s.party_id
                 WHERE s.is_cancelled = 0
                 HAVING (linked > 0 AND ABS(s.paid - linked) > 1) OR ABS(s.paid - recorded) > 0.01
                 ORDER BY s.sale_date, s.id");
    foreach ($rows as &$r) {
        $r['gap'] = round($r['paid'] - $r['recorded'], 2);
        $r['verdict'] = abs($r['gap']) < 0.01
            ? 'Correct - paid partly with the bill and partly by a later payment (or a return credit). The old Data Health check counted only the later part.'
            : ($r['gap'] > 0 ? 'The bill says ₹' . money($r['gap']) . ' more was paid than any payment shows - a payment was deleted or edited after the bill, or "paid" was typed on the bill.'
                             : 'Payments of ₹' . money(-$r['gap']) . ' more than the bill shows as paid are recorded against it - a payment was linked twice, or the bill was edited after payment.');
        $r['kind'] = abs($r['gap']) < 0.01 ? 'software' : 'entry';
    }
    return $rows;
}

/** Every purchase bill at ₹0 (or fully discounted), with its lines and what it is. */
function acct_detail_zero_purchases() {
    $rows = all("SELECT pu.*, COALESCE(p.name, '(no party)') party,
                   (SELECT COUNT(*) FROM purchase_items pi WHERE pi.purchase_id = pu.id) n_lines
                 FROM purchases pu LEFT JOIN parties p ON p.id = pu.party_id
                 WHERE pu.is_cancelled = 0 AND (pu.total <= 0.009 OR (pu.subtotal > 0 AND pu.discount >= pu.subtotal - 0.009))
                 ORDER BY pu.purchase_date, pu.id");
    foreach ($rows as &$r) {
        $r['lines'] = all('SELECT pi.qty, pi.price, pi.tax_rate, pi.total, i.id item_id, i.name, i.purchase_price now_cost, i.serial_tracked,
                             (SELECT MAX(pu2.purchase_date) FROM purchase_items pi2 JOIN purchases pu2 ON pu2.id = pi2.purchase_id
                              WHERE pi2.item_id = pi.item_id AND pu2.is_cancelled = 0 AND pi2.price > 0) last_paid_date
                           FROM purchase_items pi LEFT JOIN items i ON i.id = pi.item_id WHERE pi.purchase_id = ?', [$r['id']]);
        $allZeroPrice = $r['lines'] && !array_filter($r['lines'], fn($l) => (float)$l['price'] > 0);
        $full = (float)$r['subtotal'] > 0 && (float)$r['discount'] >= (float)$r['subtotal'] - 0.009;
        if (!$r['n_lines']) { $r['what'] = 'Incomplete - the bill has no item lines.'; $r['kind'] = 'entry'; }
        elseif ($allZeroPrice) { $r['what'] = 'Free items entered at ₹0 price.'; $r['kind'] = 'gift'; }
        elseif ($full) { $r['what'] = '100% discount on the bill.' . ((float)$r['total'] > 0.009 ? ' But GST of ₹' . money($r['tax_amount']) . ' is still left as owed to the supplier (GST is worked out before the discount).' : ''); $r['kind'] = 'gift'; }
        else { $r['what'] = 'Lines carry a price but the total is ₹0 - check this bill.'; $r['kind'] = 'entry'; }
        $r['effects'] = [];
        foreach ($r['lines'] as $l) {
            if ((float)$l['price'] <= 0 && (float)$l['now_cost'] <= 0)
                $r['effects'][] = $l['name'] . ': its purchase price is now ₹0 - this free entry overwrote it, so later sales of this item show no cost (profit too high) and its stock is valued at ₹0.';
            elseif ((float)$l['price'] <= 0)
                $r['effects'][] = $l['name'] . ': its purchase price is ₹' . money($l['now_cost']) . ' - fine (a free piece no longer changes it; an older free entry may have set it to ₹0 until the next paid purchase).';
            elseif ($full)
                $r['effects'][] = $l['name'] . ': stock is valued at ₹' . money($l['price']) . ' each although it cost nothing.';
            if ($l['serial_tracked']) $r['effects'][] = $l['name'] . ': serial numbers were entered with it - correct.';
        }
    }
    return $rows;
}

/** Negative stock rows, and serial-tracked items whose serial count ≠ stock. */
function acct_detail_stock() {
    $neg = all("SELECT st.item_id, st.location_id, st.qty, i.name, l.name loc, i.serial_tracked,
                  (SELECT MAX(pu.purchase_date) FROM purchase_items pi JOIN purchases pu ON pu.id = pi.purchase_id WHERE pi.item_id = st.item_id AND pu.is_cancelled = 0) last_buy,
                  (SELECT MAX(s.sale_date) FROM sale_items si JOIN sales s ON s.id = si.sale_id WHERE si.item_id = st.item_id AND s.is_cancelled = 0) last_sale
                FROM stock st JOIN items i ON i.id = st.item_id LEFT JOIN locations l ON l.id = st.location_id WHERE st.qty < -0.01 ORDER BY st.qty");
    foreach ($neg as &$n) $n['why'] = !$n['last_buy'] ? 'Sold, but no purchase of it was ever entered.'
        : ($n['last_sale'] > $n['last_buy'] ? 'Sold more after the last purchase (' . dmy($n['last_buy']) . ') than was bought - a purchase bill is probably not entered yet.'
                                          : 'More sold than bought in total (an old purchase, opening stock or a transfer may be missing).');
    unset($n);
    $ser = all("SELECT i.id, i.name, COALESCE((SELECT SUM(qty) FROM stock st WHERE st.item_id = i.id), 0) stock_qty,
                  (SELECT COUNT(*) FROM item_serials s WHERE s.item_id = i.id AND s.status = 'in_stock') serials
                FROM items i WHERE i.serial_tracked = 1 AND i.is_active = 1
                HAVING ABS(stock_qty - serials) > 0 ORDER BY ABS(stock_qty - serials) DESC");
    foreach ($ser as &$s) $s['why'] = $s['stock_qty'] > $s['serials']
        ? 'More pieces in stock than serials: opening stock or a stock adjustment added quantity without serial numbers, or a sale was entered without picking serials.'
        : 'More serials "in stock" than pieces: stock was reduced (adjustment, handover, sale without serial) while the serials stayed marked in stock, or the stock is negative.';
    return ['negative' => $neg, 'serials' => $ser];
}

/** Parties whose open bills add up to more than their ledger balance, each
 *  taken apart so the reason is visible rather than guessed. */
function acct_detail_parties() {
    $rows = all("SELECT p.id, p.name, p.opening_balance,
                   COALESCE((SELECT SUM(ROUND(s.total - s.paid, 2)) FROM sales s WHERE s.party_id = p.id AND s.status <> 'paid' AND s.is_cancelled = 0), 0) bills_due,
                   " . party_balance_expr('p') . " ledger,
                   COALESCE((SELECT SUM(ROUND(pu.total - pu.paid, 2)) FROM purchases pu WHERE pu.party_id = p.id AND pu.is_cancelled = 0), 0) we_owe_bills,
                   COALESCE((SELECT SUM(total) FROM sales_returns r WHERE r.party_id = p.id), 0) returns_total,
                   COALESCE((SELECT SUM(py.amount - COALESCE((SELECT SUM(pa.amount) FROM payment_allocations pa WHERE pa.payment_id = py.id), 0))
                             FROM payments py WHERE py.party_id = p.id AND py.direction = 'in' AND (py.ref_type IS NULL OR py.ref_type = '')), 0) unlinked_in
                 FROM parties p HAVING bills_due > ledger + 1 AND bills_due > 0 ORDER BY bills_due - ledger DESC");
    foreach ($rows as &$r) {
        $why = [];
        if ($r['we_owe_bills'] > 0.009) $why[] = 'you also bought from them: ₹' . money($r['we_owe_bills']) . ' of their purchase bills is unpaid, and the ledger nets the two (bills to them minus bills from them) - correct, not an error';
        if ($r['unlinked_in'] > 0.009) $why[] = '₹' . money($r['unlinked_in']) . ' they paid is not linked to any bill, so those bills still look unpaid';
        if ($r['returns_total'] > 0.009) $why[] = 'sales returns of ₹' . money($r['returns_total']) . ' reduce the ledger but are not taken off a bill';
        if ($r['opening_balance'] < -0.009) $why[] = 'an opening advance of ₹' . money(-$r['opening_balance']) . ' is in the ledger but on no bill';
        $r['why'] = $why ? ucfirst(implode('; ', $why)) . '.' : 'Not explained by purchases, unlinked payments, returns or opening - check this party\'s statement line by line.';
        $r['gap'] = round($r['bills_due'] - $r['ledger'], 2);
    }
    return $rows;
}

/** The server side: backup, unencrypted keys, cron, recent errors (grouped). */
function acct_detail_system() {
    $o = [];
    $o['backup_password'] = setting('backup_passphrase', '') !== '';
    $o['last_backup'] = val("SELECT MAX(finished_at) FROM cron_runs WHERE job = 'auto_backup' AND status = 'ok'");
    $o['backups_7d'] = (int)val("SELECT COUNT(*) FROM cron_runs WHERE job = 'auto_backup' AND status = 'ok' AND finished_at > NOW() - INTERVAL 7 DAY");
    $o['plain_keys'] = [];
    foreach (secret_setting_keys() as $k) {
        $raw = (string)val('SELECT value FROM settings WHERE name = ?', [$k]);
        if ($raw !== '' && strpos($raw, SECRET_PREFIX) !== 0) $o['plain_keys'][] = $k;   // the NAME only, never the value
    }
    $o['last_tick'] = val('SELECT MAX(started_at) FROM cron_runs');
    $o['cron_fail'] = all("SELECT job, COUNT(*) n, MAX(started_at) last, MAX(LEFT(COALESCE(detail, ''), 160)) msg FROM cron_runs WHERE status = 'fail' AND started_at > NOW() - INTERVAL 7 DAY GROUP BY job ORDER BY n DESC");
    $o['errors'] = [];
    $logf = function_exists('error_log_path') ? error_log_path() : '';
    if ($logf && is_file($logf)) {
        $cut = date('Y-m-d', strtotime('-7 days'));
        $by = [];
        foreach (@file($logf, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $ln) {
            if (substr($ln, 1, 10) < $cut) continue;
            // the same error from different requests is one problem: drop the
            // time stamp, numbers and paths' tails so they group together
            $key = preg_replace(['/^\[[^\]]*\]\s*/', '/\d+/', '/\s+/'], ['', '#', ' '], $ln);
            $key = mb_substr($key, 0, 180);
            $by[$key] = ['n' => ($by[$key]['n'] ?? 0) + 1, 'last' => substr($ln, 1, 19)];
        }
        uasort($by, fn($a, $b) => $b['n'] - $a['n']);
        foreach (array_slice($by, 0, 15, true) as $k => $x) $o['errors'][] = ['msg' => $k, 'n' => $x['n'], 'last' => $x['last']];
    }
    return $o;
}
