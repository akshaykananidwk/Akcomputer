<?php
// Accounting engine: Chart of Accounts + double-entry Journal, layered on
// top of the existing sales/purchases/payments/expenses tables rather than
// replacing them. Every system account's balance is COMPUTED live from the
// same tables the rest of the app already trusts (party ledgers, cashbook,
// stock valuation); a manual Journal/Adjustment entry only ever affects
// the accounts it's explicitly posted against. General Ledger, Trial
// Balance, Balance Sheet and P&L (includes/report_body.php) all read
// through the functions below so the numbers can never drift apart.

function coa_all($activeOnly = true) {
    return all('SELECT * FROM chart_of_accounts' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY sort_order, code');
}
function coa_get($id) {
    return row('SELECT * FROM chart_of_accounts WHERE id = ?', [$id]);
}
function coa_by_code($code) {
    return row('SELECT * FROM chart_of_accounts WHERE code = ?', [$code]);
}
/** Normal balance side for a given account type - determines whether a
 *  positive computed/journal balance is shown as a debit or a credit. */
function coa_normal_side($type) {
    return in_array($type, ['asset', 'expense'], true) ? 'debit' : 'credit';
}

function next_journal_ref() {
    $n = (int)val("SELECT COALESCE(MAX(id),0) FROM journal_entries") + 1;
    return 'JV-' . date('y') . '-' . str_pad($n, 5, '0', STR_PAD_LEFT);
}

/** Net debit-credit posted via manual Journal/Adjustment entries against
 *  one account in a date range (positive = net debit). */
function journal_balance($account_id, $from, $to) {
    $row = row("SELECT COALESCE(SUM(debit),0) d, COALESCE(SUM(credit),0) c FROM journal_lines jl
                JOIN journal_entries je ON je.id = jl.entry_id
                WHERE jl.account_id = ? AND je.entry_date BETWEEN ? AND ?", [$account_id, $from, $to]);
    return (float)$row['d'] - (float)$row['c'];
}

/** Cash-in-hand balance, cumulative from inception through $to (inclusive). */
function coa_cash_balance($to) {
    // SAME formula as the Cash & Bank page's total_cash_in_hand(), just
    // date-limited - it was missing cash<->bank transfers and cash
    // adjustments (money_transfers), so the Balance Sheet showed a cash
    // figure that disagreed with the actual cash drawer (even negative).
    return (float)val("SELECT
        COALESCE((SELECT SUM(amount) FROM payments WHERE mode='cash' AND direction='in' AND pay_date <= ?),0)
      - COALESCE((SELECT SUM(amount) FROM payments WHERE mode='cash' AND direction='out' AND pay_date <= ?),0)
      - COALESCE((SELECT SUM(amount) FROM expenses WHERE mode='cash' AND exp_date <= ?),0)
      - COALESCE((SELECT SUM(amount) FROM money_transfers WHERE status='done' AND txn_type='cash_to_bank' AND txn_date <= ?),0)
      + COALESCE((SELECT SUM(amount) FROM money_transfers WHERE status='done' AND txn_type='bank_to_cash' AND txn_date <= ?),0)
      + COALESCE((SELECT SUM(amount) FROM money_transfers WHERE status='done' AND txn_type='cash_adjust' AND adjust_dir='add' AND txn_date <= ?),0)
      - COALESCE((SELECT SUM(amount) FROM money_transfers WHERE status='done' AND txn_type='cash_adjust' AND adjust_dir='reduce' AND txn_date <= ?),0)",
      [$to, $to, $to, $to, $to, $to, $to]);
}
/** Total bank balance across every bank account, cumulative through $to -
 *  mirrors bank_account_balance() summed over all accounts (bank-to-bank
 *  moves cancel out inside the total, so they are skipped). */
function coa_bank_balance($to) {
    return (float)val("SELECT COALESCE(SUM(opening_balance),0) FROM bank_accounts")
         + (float)val("SELECT COALESCE(SUM(amount),0) FROM payments WHERE bank_account_id IS NOT NULL AND direction='in' AND pay_date <= ?", [$to])
         - (float)val("SELECT COALESCE(SUM(amount),0) FROM payments WHERE bank_account_id IS NOT NULL AND direction='out' AND pay_date <= ?", [$to])
         - (float)val("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE bank_account_id IS NOT NULL AND exp_date <= ?", [$to])
         + (float)val("SELECT COALESCE(SUM(amount),0) FROM money_transfers WHERE status='done' AND txn_type='cash_to_bank' AND txn_date <= ?", [$to])
         - (float)val("SELECT COALESCE(SUM(amount),0) FROM money_transfers WHERE status='done' AND txn_type='bank_to_cash' AND txn_date <= ?", [$to])
         + (float)val("SELECT COALESCE(SUM(amount),0) FROM money_transfers WHERE status='done' AND txn_type='bank_adjust' AND adjust_dir='add' AND txn_date <= ?", [$to])
         - (float)val("SELECT COALESCE(SUM(amount),0) FROM money_transfers WHERE status='done' AND txn_type='bank_adjust' AND adjust_dir='reduce' AND txn_date <= ?", [$to]);
}
/** Accounts Receivable / Accounts Payable through $to, on a net-per-party
 *  basis (a party who is both customer and supplier nets to one balance -
 *  consistent with how Parties/Contra-Settle already treat every party as
 *  a single combined account, rather than separate AR and AP ledgers). */
function coa_ar_ap($to) {
    $row = row("SELECT
        SUM(GREATEST(bal,0)) ar, SUM(GREATEST(-bal,0)) ap
        FROM (
          SELECT p.opening_balance
            + COALESCE((SELECT SUM(total) FROM sales WHERE party_id=p.id AND is_cancelled=0 AND sale_date<=?),0)
            - COALESCE((SELECT SUM(total) FROM sales_returns WHERE party_id=p.id AND return_date<=?),0)
            - COALESCE((SELECT SUM(total) FROM purchases WHERE party_id=p.id AND is_cancelled=0 AND purchase_date<=?),0)
            + COALESCE((SELECT SUM(total) FROM purchase_returns WHERE party_id=p.id AND return_date<=?),0)
            - COALESCE((SELECT SUM(amount) FROM payments WHERE party_id=p.id AND direction='in' AND pay_date<=?),0)
            + COALESCE((SELECT SUM(amount) FROM payments WHERE party_id=p.id AND direction='out' AND pay_date<=?),0)
            AS bal
          FROM parties p
        ) x", [$to, $to, $to, $to, $to, $to]);
    return ['ar' => (float)($row['ar'] ?? 0), 'ap' => (float)($row['ap'] ?? 0)];
}
/** Current stock valuation (qty x current purchase price) across every
 *  location - always "as of today"; a fully historical, point-in-time
 *  stock ledger isn't tracked, so an as-of date in the past still shows
 *  today's valuation (report_body.php footnotes this when relevant). */
function coa_stock_value() {
    return (float)val("SELECT COALESCE(SUM(s.qty * i.purchase_price),0) FROM stock s JOIN items i ON i.id = s.item_id WHERE i.item_type <> 'service'");
}
/** Net sales revenue (gross sales less sales returns) in a date range. */
function coa_sales_revenue($from, $to) {
    $gross = (float)val("SELECT COALESCE(SUM(total),0) FROM sales WHERE is_cancelled = 0 AND sale_date BETWEEN ? AND ?", [$from, $to]);
    $ret = (float)val("SELECT COALESCE(SUM(total),0) FROM sales_returns WHERE return_date BETWEEN ? AND ?", [$from, $to]);
    return $gross - $ret;
}
/** Cost of goods sold in a date range.
 *
 *  This used to be "qty x the item's CURRENT purchase price", which was wrong
 *  in two ways and made the Profit & Loss disagree with every other profit
 *  screen in the app - reported from the shop, where the two reports showed
 *  the same month as a ₹57,000 loss and a ₹14,000 profit at the same time.
 *
 *    · A SERVICE line consumes no stock, but its item row still carries a
 *      purchase_price, so every service sold was charged a cost it never had.
 *    · A product's purchase price changes over time. Using today's price
 *      retroactively rewrote the profit on bills raised months ago - last
 *      year's profit moved every time a supplier put their rate up.
 *
 *  profit_cost_sql() answers both: it uses the cost CAPTURED ON THE BILL when
 *  there is one, falls back to the item's purchase price when there is not,
 *  and gives a service line its own cost. It is the same expression the
 *  Business Report, Product-wise Profit, Party-wise Profit and the dashboard
 *  already used - so now all of them genuinely agree, which is what the
 *  comment on profit_cost_sql() always claimed. */
function coa_cogs($from, $to) {
    return (float)val("SELECT COALESCE(SUM(" . profit_cost_sql() . "),0)
                        FROM sale_items si JOIN sales s ON s.id = si.sale_id JOIN items i ON i.id = si.item_id
                        WHERE s.is_cancelled = 0 AND s.sale_date BETWEEN ? AND ?", [$from, $to]);
}
/** Operating expenses grouped by category in a date range (same grouping
 *  as the existing Expense report). */
function coa_expenses_by_category($from, $to) {
    return all("SELECT category, COALESCE(SUM(amount),0) total FROM expenses WHERE exp_date BETWEEN ? AND ? GROUP BY category ORDER BY total DESC", [$from, $to]);
}
/** Net profit (Revenue - COGS - Expenses) for a date range, before any
 *  manual income/expense-type journal adjustments - the shared building
 *  block behind both the P&L statement and the Balance Sheet's Retained
 *  Earnings line. */
function coa_net_profit($from, $to) {
    $rev = coa_sales_revenue($from, $to);
    $cogs = coa_cogs($from, $to);
    $exp = array_sum(array_column(coa_expenses_by_category($from, $to), 'total'));
    return $rev - $cogs - $exp;
}

/** Net debit balance of one account (system-computed + any manual journal
 *  postings), cumulative from inception through $to - the shared figure
 *  behind Trial Balance and Balance Sheet. Income/liability/equity
 *  accounts come back negative here when they carry their normal credit
 *  balance; callers flip the sign using coa_normal_side() for display. */
function coa_balance_asof($account, $to) {
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
/** Transaction-level rows for one account's General Ledger, in a date
 *  range, each as ['date','desc','debit','credit','link']. System accounts
 *  are reconstructed from the underlying sales/purchases/payments/expenses
 *  tables; custom accounts read straight from journal_lines. */
function coa_gl_rows($account, $from, $to) {
    $rows = [];
    if (!$account['is_system']) {
        foreach (all("SELECT jl.*, je.entry_date, je.narration, je.ref_no FROM journal_lines jl
                       JOIN journal_entries je ON je.id = jl.entry_id
                       WHERE jl.account_id = ? AND je.entry_date BETWEEN ? AND ? ORDER BY je.entry_date, je.id", [$account['id'], $from, $to]) as $l) {
            $rows[] = ['date' => $l['entry_date'], 'desc' => $l['ref_no'] . ' - ' . ($l['narration'] ?: $l['notes']),
                       'debit' => (float)$l['debit'], 'credit' => (float)$l['credit'], 'link' => 'journal.php?action=view&id=' . $l['entry_id']];
        }
        return $rows;
    }
    switch ($account['code']) {
        case '1000': // Cash in Hand
            foreach (all("SELECT p.*, pt.name party_name FROM payments p LEFT JOIN parties pt ON pt.id = p.party_id
                          WHERE p.mode = 'cash' AND p.pay_date BETWEEN ? AND ? ORDER BY p.pay_date, p.id", [$from, $to]) as $p) {
                $rows[] = ['date' => $p['pay_date'], 'desc' => ($p['direction'] === 'in' ? 'Receipt' : 'Payment') . ' - ' . ($p['party_name'] ?: 'Walk-in'),
                           'debit' => $p['direction'] === 'in' ? (float)$p['amount'] : 0, 'credit' => $p['direction'] === 'out' ? (float)$p['amount'] : 0, 'link' => null];
            }
            foreach (all("SELECT * FROM expenses WHERE mode = 'cash' AND exp_date BETWEEN ? AND ? ORDER BY exp_date, id", [$from, $to]) as $x) {
                $rows[] = ['date' => $x['exp_date'], 'desc' => 'Expense - ' . $x['category'], 'debit' => 0, 'credit' => (float)$x['amount'], 'link' => null];
            }
            foreach (all("SELECT * FROM money_transfers WHERE status='done' AND txn_type IN ('cash_to_bank','bank_to_cash','cash_adjust') AND txn_date BETWEEN ? AND ? ORDER BY txn_date, id", [$from, $to]) as $t) {
                $in = $t['txn_type'] === 'bank_to_cash' || ($t['txn_type'] === 'cash_adjust' && $t['adjust_dir'] === 'add');
                $desc = ['cash_to_bank' => 'Deposit to bank', 'bank_to_cash' => 'Withdrawal from bank', 'cash_adjust' => 'Cash adjustment'][$t['txn_type']];
                $rows[] = ['date' => $t['txn_date'], 'desc' => $desc . ($t['notes'] ? ' - ' . $t['notes'] : ''),
                           'debit' => $in ? (float)$t['amount'] : 0, 'credit' => $in ? 0 : (float)$t['amount'], 'link' => null];
            }
            break;
        case '1100': // Bank Accounts (aggregate across every account)
            foreach (all("SELECT p.*, pt.name party_name, b.account_name FROM payments p LEFT JOIN parties pt ON pt.id = p.party_id
                          JOIN bank_accounts b ON b.id = p.bank_account_id
                          WHERE p.bank_account_id IS NOT NULL AND p.pay_date BETWEEN ? AND ? ORDER BY p.pay_date, p.id", [$from, $to]) as $p) {
                $rows[] = ['date' => $p['pay_date'], 'desc' => ($p['direction'] === 'in' ? 'Receipt' : 'Payment') . ' - ' . ($p['party_name'] ?: 'Walk-in') . ' (' . $p['account_name'] . ')',
                           'debit' => $p['direction'] === 'in' ? (float)$p['amount'] : 0, 'credit' => $p['direction'] === 'out' ? (float)$p['amount'] : 0, 'link' => null];
            }
            foreach (all("SELECT x.*, b.account_name FROM expenses x JOIN bank_accounts b ON b.id = x.bank_account_id
                          WHERE x.bank_account_id IS NOT NULL AND x.exp_date BETWEEN ? AND ? ORDER BY x.exp_date, x.id", [$from, $to]) as $x) {
                $rows[] = ['date' => $x['exp_date'], 'desc' => 'Expense - ' . $x['category'] . ' (' . $x['account_name'] . ')', 'debit' => 0, 'credit' => (float)$x['amount'], 'link' => null];
            }
            // cash deposits/withdrawals + bank adjustments (bank-to-bank is
            // internal to this aggregate account, so it is skipped)
            foreach (all("SELECT * FROM money_transfers WHERE status='done' AND txn_type IN ('cash_to_bank','bank_to_cash','bank_adjust') AND txn_date BETWEEN ? AND ? ORDER BY txn_date, id", [$from, $to]) as $t) {
                $in = $t['txn_type'] === 'cash_to_bank' || ($t['txn_type'] === 'bank_adjust' && $t['adjust_dir'] === 'add');
                $desc = ['cash_to_bank' => 'Cash deposit', 'bank_to_cash' => 'Cash withdrawal', 'bank_adjust' => 'Bank adjustment'][$t['txn_type']];
                $rows[] = ['date' => $t['txn_date'], 'desc' => $desc . ($t['notes'] ? ' - ' . $t['notes'] : ''),
                           'debit' => $in ? (float)$t['amount'] : 0, 'credit' => $in ? 0 : (float)$t['amount'], 'link' => null];
            }
            break;
        case '1200': // Accounts Receivable (approximate: every payment direction is netted per-party, same basis as the Balance Sheet)
            foreach (all("SELECT s.*, COALESCE(p.name, s.customer_name, 'Walk-in') pname FROM sales s LEFT JOIN parties p ON p.id = s.party_id
                          WHERE s.is_cancelled = 0 AND s.sale_date BETWEEN ? AND ? ORDER BY s.sale_date, s.id", [$from, $to]) as $s) {
                $rows[] = ['date' => $s['sale_date'], 'desc' => 'Sale ' . $s['invoice_no'] . ' - ' . $s['pname'], 'debit' => (float)$s['total'], 'credit' => 0, 'link' => 'sale_view.php?id=' . $s['id']];
            }
            foreach (all('SELECT * FROM sales_returns WHERE return_date BETWEEN ? AND ? ORDER BY return_date, id', [$from, $to]) as $sr) {
                $rows[] = ['date' => $sr['return_date'], 'desc' => 'Sales Return ' . $sr['return_no'], 'debit' => 0, 'credit' => (float)$sr['total'], 'link' => null];
            }
            foreach (all("SELECT p.*, pt.name party_name FROM payments p JOIN parties pt ON pt.id = p.party_id
                          WHERE p.pay_date BETWEEN ? AND ? ORDER BY p.pay_date, p.id", [$from, $to]) as $p) {
                $rows[] = ['date' => $p['pay_date'], 'desc' => ($p['direction'] === 'in' ? 'Received from' : 'Paid to') . ' ' . $p['party_name'],
                           'debit' => $p['direction'] === 'out' ? (float)$p['amount'] : 0, 'credit' => $p['direction'] === 'in' ? (float)$p['amount'] : 0, 'link' => null];
            }
            break;
        case '2000': // Accounts Payable (approximate, same basis as AR above)
            foreach (all("SELECT pu.*, pt.name party_name FROM purchases pu JOIN parties pt ON pt.id = pu.party_id
                          WHERE pu.is_cancelled = 0 AND pu.purchase_date BETWEEN ? AND ? ORDER BY pu.purchase_date, pu.id", [$from, $to]) as $pu) {
                $rows[] = ['date' => $pu['purchase_date'], 'desc' => 'Purchase ' . ($pu['bill_no'] ?: '#' . $pu['id']) . ' - ' . $pu['party_name'], 'debit' => 0, 'credit' => (float)$pu['total'], 'link' => 'purchase_view.php?id=' . $pu['id']];
            }
            foreach (all('SELECT * FROM purchase_returns WHERE return_date BETWEEN ? AND ? ORDER BY return_date, id', [$from, $to]) as $pr) {
                $rows[] = ['date' => $pr['return_date'], 'desc' => 'Purchase Return ' . $pr['return_no'], 'debit' => (float)$pr['total'], 'credit' => 0, 'link' => null];
            }
            break;
        case '4000': // Sales Revenue
            foreach (all("SELECT s.*, COALESCE(p.name, s.customer_name, 'Walk-in') pname FROM sales s LEFT JOIN parties p ON p.id = s.party_id
                          WHERE s.is_cancelled = 0 AND s.sale_date BETWEEN ? AND ? ORDER BY s.sale_date, s.id", [$from, $to]) as $s) {
                $rows[] = ['date' => $s['sale_date'], 'desc' => 'Sale ' . $s['invoice_no'] . ' - ' . $s['pname'], 'debit' => 0, 'credit' => (float)$s['total'], 'link' => 'sale_view.php?id=' . $s['id']];
            }
            break;
        case '4100': // Sales Returns
            foreach (all('SELECT * FROM sales_returns WHERE return_date BETWEEN ? AND ? ORDER BY return_date, id', [$from, $to]) as $sr) {
                $rows[] = ['date' => $sr['return_date'], 'desc' => 'Sales Return ' . $sr['return_no'], 'debit' => (float)$sr['total'], 'credit' => 0, 'link' => null];
            }
            break;
        case '5000': // Cost of Goods Sold - one row per sale, on the shared rule
            // so this ledger account adds up to exactly the COGS line the
            // Profit & Loss shows for the same period
            foreach (all("SELECT s.id, s.invoice_no, s.sale_date, SUM(" . profit_cost_sql() . ") cost
                          FROM sales s JOIN sale_items si ON si.sale_id = s.id JOIN items i ON i.id = si.item_id
                          WHERE s.is_cancelled = 0 AND s.sale_date BETWEEN ? AND ? GROUP BY s.id ORDER BY s.sale_date, s.id", [$from, $to]) as $s) {
                if ((float)$s['cost'] <= 0) continue;
                $rows[] = ['date' => $s['sale_date'], 'desc' => 'Cost of goods - Sale ' . $s['invoice_no'], 'debit' => (float)$s['cost'], 'credit' => 0, 'link' => 'sale_view.php?id=' . $s['id']];
            }
            break;
        case '1300': // Inventory / Stock - approximate movement feed (purchases in, cost-of-sales out)
            foreach (all("SELECT pu.*, pt.name party_name FROM purchases pu JOIN parties pt ON pt.id = pu.party_id
                          WHERE pu.is_cancelled = 0 AND pu.purchase_date BETWEEN ? AND ? ORDER BY pu.purchase_date, pu.id", [$from, $to]) as $pu) {
                $rows[] = ['date' => $pu['purchase_date'], 'desc' => 'Purchase ' . ($pu['bill_no'] ?: '#' . $pu['id']) . ' - ' . $pu['party_name'], 'debit' => (float)$pu['total'], 'credit' => 0, 'link' => 'purchase_view.php?id=' . $pu['id']];
            }
            foreach (all("SELECT s.id, s.invoice_no, s.sale_date, SUM(" . profit_cost_sql() . ") cost
                          FROM sales s JOIN sale_items si ON si.sale_id = s.id JOIN items i ON i.id = si.item_id
                          WHERE s.is_cancelled = 0 AND s.sale_date BETWEEN ? AND ? GROUP BY s.id ORDER BY s.sale_date, s.id", [$from, $to]) as $s) {
                if ((float)$s['cost'] <= 0) continue;
                $rows[] = ['date' => $s['sale_date'], 'desc' => 'Sold - Sale ' . $s['invoice_no'], 'debit' => 0, 'credit' => (float)$s['cost'], 'link' => 'sale_view.php?id=' . $s['id']];
            }
            break;
    }
    usort($rows, fn($a, $b) => strcmp($a['date'], $b['date']));
    return $rows;
}

/** Period Lock: transactions dated on/before the configured lock date can
 *  no longer be added, edited, cancelled or deleted - protects a closed
 *  month/year from accidental changes once books are finalized. */
function is_period_locked($date) {
    $lock = setting('period_lock_date', '');
    return $lock !== '' && $date !== '' && $date <= $lock;
}
function period_lock_message() {
    return 'This date falls in a locked accounting period (on or before ' . dmy(setting('period_lock_date')) . '). Ask an admin to move the lock date in Settings if this needs to change.';
}
