<?php
// ============================================================================
//  THE MONEY RULES — single source of truth
// ============================================================================
//  Every rupee figure the shop shows comes from this file: what a party owes,
//  what a bill still claims, how a payment settles bills, and how deleting a
//  payment puts everything back.
//
//  These rules were moved here unchanged from includes/helpers.php and
//  payments.php, which had grown four near-identical copies of the
//  oldest-bill settlement loop and two copies of the ledger-capping rule.
//  The behaviour is deliberately identical - same arithmetic, same rounding,
//  same 0.009 tolerance, same ordering - so nothing a customer sees changes.
//
//  The two ideas the whole file rests on:
//
//   1. THE LEDGER IS THE TRUTH. A bill's own "total - paid" can overstate
//      reality, because a payment that was never linked to a bill, a sales
//      return or a settlement discount all reduce what the party owes without
//      touching any bill. So every customer-facing due is capped by
//      party_balance().
//
//   2. MONEY SETTLES THE OLDEST BILL FIRST. Whenever money has to be spread
//      across bills - collecting it, capping it, or re-applying it after an
//      edit - the oldest bill is served first.
// ============================================================================

/** Amounts below this are treated as zero: it absorbs the paisa-level noise
 *  of DECIMAL(12,2) arithmetic so a bill 0.4 paise short still reads "paid".
 *  Every comparison in this file uses it. */
const MONEY_EPS = 0.009;

/** The rounding used everywhere money is stored or compared. */
function money_r($n) { return round((float)$n, 2); }

// ---------------------------------------------------------------- status ---

/** Where a bill stands: due / partial / paid. */
function payment_status($total, $paid) {
    // paid-check first so a zero-total bill (e.g. 100% discount) reads
    // "paid", not "due" - nothing is owed on it
    if ($paid + MONEY_EPS >= $total) return 'paid';
    if ($paid <= MONEY_EPS) return 'due';
    return 'partial';
}

// --------------------------------------------------------- party balance ---

// Positive = party owes shop (You'll Get). Negative = shop owes
// party (You'll Give) - this also covers customer ADVANCES: a
// payment received with no bill against it simply pushes the balance
// negative, exactly like a real khata/ledger book. Used everywhere (party
// list, party ledger, Payment-In/Out, dashboard) so the numbers never
// disagree with each other.
function party_balance_expr($alias = 'p') {
    return "($alias.opening_balance
        + COALESCE((SELECT SUM(total) FROM sales WHERE party_id = $alias.id AND is_cancelled = 0), 0)
        - COALESCE((SELECT SUM(total) FROM sales_returns WHERE party_id = $alias.id), 0)
        - COALESCE((SELECT SUM(total) FROM purchases WHERE party_id = $alias.id AND is_cancelled = 0), 0)
        + COALESCE((SELECT SUM(total) FROM purchase_returns WHERE party_id = $alias.id), 0)
        - COALESCE((SELECT SUM(amount) FROM payments WHERE party_id = $alias.id AND direction = 'in'), 0)
        + COALESCE((SELECT SUM(amount) FROM payments WHERE party_id = $alias.id AND direction = 'out'), 0))";
}
function party_balance($party_id) {
    return (float)val('SELECT ' . party_balance_expr('p') . ' FROM parties p WHERE p.id = ?', [$party_id]);
}

/** The party's balance as a positive number on the side the caller cares
 *  about: 'in' = what they owe us, 'out' = what we owe them. */
function party_balance_side($party_id, $dir) {
    $bal = party_balance($party_id);
    return $dir === 'in' ? max(0.0, $bal) : max(0.0, -$bal);
}

/** Unsettled part of a party's OPENING balance for one payment direction:
 *  'in' = old receivable (opening_balance > 0), 'out' = old payable
 *  (opening_balance < 0), minus whatever payments were already linked to it
 *  (payment_allocations ref_type='opening', ref_id=party). Lets the payment
 *  allocator show "જૂનો હિસાબ" as its own linkable line. */
function opening_due($party_id, $dir) {
    $ob = (float)val('SELECT opening_balance FROM parties WHERE id = ?', [$party_id]);
    $base = $dir === 'in' ? max(0.0, $ob) : max(0.0, -$ob);
    if ($base <= MONEY_EPS) return 0.0;
    $paid = 0.0;
    try {
        $paid = (float)val("SELECT COALESCE(SUM(pa.amount),0) FROM payment_allocations pa
                            JOIN payments p ON p.id = pa.payment_id
                            WHERE pa.ref_type = 'opening' AND pa.ref_id = ? AND p.direction = ?", [$party_id, $dir]);
    } catch (Exception $e) { /* pre-v53 */ }
    return max(0.0, money_r($base - $paid));
}

// ------------------------------------------------- the oldest-first rule ---

/** THE capping rule, in one place.
 *
 *  Takes one party's outstanding bill dues, ALREADY ORDERED OLDEST FIRST, and
 *  the real balance they are allowed to add up to. Returns the same list with
 *  the covered-but-unlinked portion trimmed off the oldest bills.
 *
 *  Example - Pareshbhai has bills of 2,800 and 2,041 but has really only
 *  2,041 left on his khata, because 2,800 was paid without anyone linking it
 *  to a bill. Passing [2800, 2041] with a balance of 2041 returns [0, 2041]:
 *  the old bill is recognised as settled and only the newer one still claims.
 *
 *  Both sale_true_due() (one bill) and money_cap_bill_dues() (a whole list)
 *  are thin wrappers around this, so the two can never drift apart again.
 */
function money_trim_dues(array $dues, $balance) {
    $sum = 0.0;
    foreach ($dues as $d) $sum += (float)$d;
    $excess = money_r($sum - max(0.0, (float)$balance)); // covered-but-unlinked portion
    if ($excess <= MONEY_EPS) return array_map(fn($d) => money_r($d), $dues);
    $out = [];
    foreach ($dues as $k => $d) {
        $d = (float)$d;
        if ($excess > MONEY_EPS) {
            $cut = min($d, $excess);
            $excess = money_r($excess - $cut);
            $out[$k] = max(0.0, money_r($d - $cut));
        } else {
            $out[$k] = money_r($d);
        }
    }
    return $out;
}

/** A party's still-unpaid bills, oldest first — the canonical ordering every
 *  settlement and every cap uses. $dir 'in' = sales, 'out' = purchases. */
function money_due_bills($party_id, $dir, $cols = '*') {
    $tbl = $dir === 'in' ? 'sales' : 'purchases';
    return all("SELECT $cols FROM $tbl WHERE party_id = ? AND status <> 'paid' AND is_cancelled = 0
                ORDER BY due_date IS NULL, due_date, id", [$party_id]);
}

/** TRUE remaining due of one sale bill: the bill's own total-paid, capped by
 *  the party's real LEDGER balance. Unlinked payments, sales returns and
 *  settlement discounts reduce the ledger without touching bills - money
 *  settles oldest bill first, so the older bills absorb that covered-but-
 *  unlinked portion and this bill only claims what is genuinely left.
 *  Walk-in bills (no party) just use total-paid. Used by every customer-
 *  facing due figure: payment reminders (cron + manual), the WhatsApp
 *  portal's bill screens and its Razorpay pay links. */
function sale_true_due(array $s) {
    $due = money_r($s['total'] - $s['paid']);
    if ($due <= MONEY_EPS || empty($s['party_id'])) return max(0.0, $due);
    $pid = (int)$s['party_id'];
    $bills = money_due_bills($pid, 'in', 'id, ROUND(total - paid, 2) d');
    $trimmed = money_trim_dues(array_column($bills, 'd'), party_balance_side($pid, 'in'));
    foreach ($bills as $k => $b) {
        if ((int)$b['id'] === (int)$s['id']) return $trimmed[$k];
    }
    return $due; // bill is not in the party's open list (already paid/cancelled)
}

/** The same cap applied to a LIST of bills that may span many parties, as the
 *  Payments screen shows them. Each bill gains an 'adj_due' key and bills that
 *  turn out to be fully covered drop out of the list.
 *
 *  NOTE: the cap is computed from the bills PRESENT IN $bills, not from a
 *  fresh query, which is what this screen has always done - the caller's
 *  ordering and any LIMIT it applied are respected exactly. */
function money_cap_bill_dues(array $bills, $dir) {
    $byParty = [];
    foreach ($bills as $i => $b) if ($b['party_id']) $byParty[(int)$b['party_id']][] = $i;
    foreach ($byParty as $pid => $idxs) {
        $dues = [];
        foreach ($idxs as $i) $dues[$i] = $bills[$i]['total'] - $bills[$i]['paid'];
        foreach (money_trim_dues($dues, party_balance_side($pid, $dir)) as $i => $adj) {
            $bills[$i]['adj_due'] = $adj;
        }
    }
    return array_values(array_filter($bills, fn($b) => money_r($b['adj_due'] ?? ($b['total'] - $b['paid'])) > MONEY_EPS));
}

// ----------------------------------------------------------------- cost ----

/** The cost side of gross profit, as SQL. A service line carries its own
 *  cost_price; a product line uses the cost captured on the bill when there is
 *  one, and falls back to the item's purchase price otherwise. Expects
 *  sale_items aliased si and items aliased i. Used by the profit reports, the
 *  P&L and the dashboard, so all three agree. */
function profit_cost_sql() {
    return "si.qty * IF(i.item_type = 'service', si.cost_price, IF(si.cost_price > 0, si.cost_price, i.purchase_price))";
}

/**
 * Party-wise margin contribution over a date range.
 *
 * The owner's question is "which customer actually made me money this year",
 * so he knows who to look after at Diwali. Three deliberate decisions:
 *
 *  1. Cost comes from profit_cost_sql() - the same expression the product
 *     profit report, the P&L and the dashboard use. There is no second
 *     definition of what a thing cost.
 *  2. The bill-level discount is a SEPARATE column, not folded into revenue.
 *     A customer who is given 10% off every bill is a different customer from
 *     one who is not, and hiding that inside one number would make this report
 *     silently disagree with the product-wise one. Loyalty points spent as
 *     money count the same way.
 *  3. Sales returns are subtracted at their own cost and revenue, because
 *     goods that came back were never profit.
 *
 * What it is NOT: net profit. Rent, salary, electricity and every other shop
 * cost are not in here and cannot be split per customer honestly. This is what
 * the goods earned before the shop's own bills - which is why every screen
 * showing it calls it CONTRIBUTION, not profit.
 */
function party_profit($from, $to, $extraWhere = '', array $extraParams = [], $limit = 300) {
    $limit = max(1, (int)$limit);
    $cost = profit_cost_sql();
    $rows = all("SELECT COALESCE(CONCAT('p', s.party_id), s.customer_name) k, s.party_id,
                        COALESCE(p.name, CONCAT(NULLIF(s.customer_name, ''), ' (છૂટક)'), 'છૂટક ગ્રાહક') pname,
                        p.mobile, p.city,
                        COUNT(DISTINCT s.id) bills,
                        COALESCE(SUM(si.total), 0) revenue,
                        COALESCE(SUM($cost), 0) cost,
                        MAX(s.sale_date) last_buy
                 FROM sales s
                 JOIN sale_items si ON si.sale_id = s.id
                 JOIN items i ON i.id = si.item_id
                 LEFT JOIN parties p ON p.id = s.party_id
                 WHERE s.is_cancelled = 0 AND s.sale_date BETWEEN ? AND ? $extraWhere
                 GROUP BY k",
        array_merge([$from, $to], $extraParams));

    // Bill-level giveaways, counted once per BILL - joining them above would
    // multiply them by the number of lines on the bill.
    $disc = [];
    foreach (all("SELECT COALESCE(CONCAT('p', s.party_id), s.customer_name) k,
                         COALESCE(SUM(s.discount), 0) + COALESCE(SUM(s.loyalty_discount), 0) given
                  FROM sales s
                  WHERE s.is_cancelled = 0 AND s.sale_date BETWEEN ? AND ? $extraWhere
                  GROUP BY k", array_merge([$from, $to], $extraParams)) as $d)
        $disc[(string)$d['k']] = (float)$d['given'];

    // Returns: revenue that came back, and the cost that came back with it.
    $ret = [];
    try {
        foreach (all("SELECT COALESCE(CONCAT('p', sr.party_id), sr.customer_name) k,
                             COALESCE(SUM(ri.total), 0) amt,
                             COALESCE(SUM(ri.qty * IF(i.item_type = 'service', 0, i.purchase_price)), 0) cost
                      FROM sales_returns sr
                      JOIN sales_return_items ri ON ri.return_id = sr.id
                      JOIN items i ON i.id = ri.item_id
                      WHERE sr.return_date BETWEEN ? AND ?
                      GROUP BY k", [$from, $to]) as $x)
            $ret[(string)$x['k']] = ['amt' => (float)$x['amt'], 'cost' => (float)$x['cost']];
    } catch (Exception $e) { /* older installs may not have the tables */ }

    $out = [];
    foreach ($rows as $x) {
        // all three sweeps group by the SAME key expression, so they line up
        // without any name matching - a walk-in keyed by the typed name and a
        // real party keyed by "p<id>" both find their own row or nothing.
        $key = (string)$x['k'];
        $given = $disc[$key] ?? 0.0;
        $r = $ret[$key] ?? ['amt' => 0.0, 'cost' => 0.0];
        $revenue = money_r((float)$x['revenue'] - $r['amt']);
        $cogs = money_r((float)$x['cost'] - $r['cost']);
        $gross = money_r($revenue - $cogs);
        $contribution = money_r($gross - $given);
        $out[] = [
            'party_id' => $x['party_id'] === null ? 0 : (int)$x['party_id'],
            'name' => $x['pname'], 'mobile' => $x['mobile'], 'city' => $x['city'],
            'bills' => (int)$x['bills'], 'last_buy' => $x['last_buy'],
            'revenue' => $revenue, 'cost' => $cogs, 'gross' => $gross,
            'discount' => money_r($given), 'returned' => money_r($r['amt']),
            'contribution' => $contribution,
            'pct' => $revenue > 0 ? round($contribution / $revenue * 100, 1) : 0.0,
        ];
    }
    usort($out, fn($a, $b) => $b['contribution'] <=> $a['contribution']);
    return array_slice($out, 0, $limit);
}

/** A suggested Diwali/festival gift budget for one customer: a share of what
 *  they actually contributed. A SUGGESTION - the shop decides. Returns 0 when
 *  the customer made no money for the shop, because a gift is a thank-you, not
 *  an apology. */
function party_gift_budget($contribution, $pct = null, $cap = null) {
    $pct = $pct === null ? (float)setting('gift_pct', 2) : (float)$pct;
    $cap = $cap === null ? (float)setting('gift_cap', 0) : (float)$cap;
    if ($contribution <= 0 || $pct <= 0) return 0.0;
    $b = money_r($contribution * $pct / 100);
    if ($cap > 0 && $b > $cap) $b = money_r($cap);
    return $b;
}

// ------------------------------------------------------------ settlement ---

/** Applies $amount to a party's unpaid bills, OLDEST FIRST, updating each
 *  bill's paid + status as it goes. Returns what it settled:
 *
 *      [['ref_type' => 'sale', 'ref_id' => 12, 'amount' => 400.0,
 *        'label' => 'INV-0012', 'bill' => <row>], ...]
 *
 *  It deliberately does NOT write payment_allocations rows - the three
 *  callers need that at different moments (a new payment splits its takes
 *  between the cash row and the discount row before inserting; the backfill
 *  and the edit screen insert immediately), so each does its own inserting
 *  from the returned list.
 *
 *  $skipBillId lets the edit screen re-apply an amount without double-paying
 *  a bill it has already handled explicitly. */
function money_settle_oldest_first($party_id, $dir, $amount, array $skipBillIds = []) {
    $left = money_r($amount);
    if ($left <= MONEY_EPS || !$party_id) return [];
    $tbl  = $dir === 'in' ? 'sales' : 'purchases';
    $refT = $dir === 'in' ? 'sale' : 'purchase';
    $out  = [];
    foreach (money_due_bills($party_id, $dir) as $bill) {
        if ($left <= MONEY_EPS) break;
        if (in_array((int)$bill['id'], $skipBillIds, true)) continue;
        $due = money_r($bill['total'] - $bill['paid']);
        if ($due <= MONEY_EPS) continue;
        $take = min($left, $due);
        q("UPDATE $tbl SET paid = paid + ?, status = ? WHERE id = ?",
          [$take, payment_status($bill['total'], $bill['paid'] + $take), $bill['id']]);
        $out[] = [
            'ref_type' => $refT,
            'ref_id'   => (int)$bill['id'],
            'amount'   => $take,
            'label'    => $dir === 'in' ? $bill['invoice_no'] : ($bill['bill_no'] ?: '#' . $bill['id']),
            'bill'     => $bill,
        ];
        $left -= $take;
    }
    return $out;
}

/** Applies $amount to ONE named bill (the owner picked it), capped at what
 *  that bill still owes. Returns the amount actually used, 0 if none. */
function money_settle_bill($bill_id, $party_id, $dir, $amount) {
    $tbl  = $dir === 'in' ? 'sales' : 'purchases';
    $b = row("SELECT * FROM $tbl WHERE id = ? AND party_id = ? AND is_cancelled = 0", [(int)$bill_id, $party_id]);
    if (!$b) return 0.0;
    $use = min(money_r($amount), money_r($b['total'] - $b['paid']));
    if ($use <= MONEY_EPS) return 0.0;
    q("UPDATE $tbl SET paid = paid + ?, status = ? WHERE id = ?",
      [$use, payment_status($b['total'], $b['paid'] + $use), $b['id']]);
    return $use;
}

/** Undoes one allocation's effect on a bill. An 'opening' allocation has no
 *  bill to touch - opening_due() derives from the allocation rows themselves,
 *  so deleting the row IS the reversal. */
function money_reverse_bill_paid($ref_type, $ref_id, $amt) {
    if ($ref_type === 'opening') return;
    $table = $ref_type === 'sale' ? 'sales' : 'purchases';
    $bill = row("SELECT total, paid FROM $table WHERE id = ?", [$ref_id]);
    if (!$bill) return;
    $newPaid = max(0, money_r($bill['paid'] - $amt));
    q("UPDATE $table SET paid = ?, status = ? WHERE id = ?", [$newPaid, payment_status($bill['total'], $newPaid), $ref_id]);
}

/** Where a return's bill-by-bill credit is recorded, per side. */
function return_credit_table($side) { return $side === 'sale' ? 'sales_return_credits' : 'purchase_return_credits'; }

/** A return that is ADJUSTED (not refunded in cash) leaves a credit with the
 *  other party. This settles that credit against their due bills and records
 *  which bill got what, so deleting the return can put back exactly what it
 *  took.
 *
 *  $side is 'purchase' (goods back to a supplier — settles purchase bills) or
 *  'sale' (goods back from a customer — settles their sale bills).
 *
 *  If the owner names a bill, that bill is settled FIRST and the remainder
 *  spills onto the oldest others — the same two-step the payment allocator
 *  uses, so "pick a bill" never means "and lose the rest".
 *
 *  It writes NO payments row on purpose. party_balance_expr() already counts
 *  sales_returns and purchase_returns as ledger movements; adding a payment
 *  would count the credit twice. The bills move, the ledger does not.
 *
 *  Anything left once every bill is settled stays as a plain credit balance on
 *  the party, which is what it is — there is no bill to put it against yet.
 *  Returns the list of bills settled. */
function money_apply_return_credit($side, $return_id, $party_id, $amount, $preferBillId = 0) {
    $party_id = (int)$party_id;
    $left = money_r($amount);
    if (!$party_id || $left <= MONEY_EPS) return [];
    $dir  = $side === 'sale' ? 'in' : 'out';
    $tbl  = $side === 'sale' ? 'sales' : 'purchases';
    $refT = $side === 'sale' ? 'sale' : 'purchase';
    $done = [];

    $preferBillId = (int)$preferBillId;
    if ($preferBillId) {
        $use = money_settle_bill($preferBillId, $party_id, $dir, $left);
        if ($use > MONEY_EPS) {
            $b = row("SELECT * FROM $tbl WHERE id = ?", [$preferBillId]);
            $done[] = ['ref_type' => $refT, 'ref_id' => $preferBillId, 'amount' => $use, 'bill' => $b,
                       'label' => $side === 'sale' ? $b['invoice_no'] : ($b['bill_no'] ?: '#' . $preferBillId)];
            $left -= $use;
        }
    }
    foreach (money_settle_oldest_first($party_id, $dir, $left, $preferBillId ? [$preferBillId] : []) as $t) {
        $done[] = $t;
        $left -= $t['amount'];
    }

    $table = return_credit_table($side);
    foreach ($done as $t)
        q("INSERT INTO $table (return_id, bill_id, amount) VALUES (?,?,?)", [(int)$return_id, $t['ref_id'], $t['amount']]);
    return $done;
}

/** Undoes the above, bill by bill, using the amounts actually applied.
 *  Recalculating instead would guess wrong the moment a later payment has
 *  touched the same bill. */
function money_reverse_return_credit($side, $return_id) {
    $table = return_credit_table($side);
    $refT = $side === 'sale' ? 'sale' : 'purchase';
    $n = 0;
    foreach (all("SELECT bill_id, amount FROM $table WHERE return_id = ?", [(int)$return_id]) as $c) {
        money_reverse_bill_paid($refT, (int)$c['bill_id'], (float)$c['amount']);
        $n++;
    }
    q("DELETE FROM $table WHERE return_id = ?", [(int)$return_id]);
    return $n;
}

/** Splits a settled list between the CASH part of a payment and its
 *  settlement-DISCOUNT part. Bills settle from the cash first; whatever is
 *  left rides the separate discount row, so deleting either later reverses
 *  exactly the right amount. Returns [cashAllocations, discountAllocations]. */
function money_split_cash_discount(array $allocRows, $cashAmount) {
    $main = []; $disc = []; $left = $cashAmount;
    foreach ($allocRows as $a) {
        $take = money_r(min($a['amount'], $left));
        if ($take > MONEY_EPS) {
            $main[] = ['ref_type' => $a['ref_type'], 'ref_id' => $a['ref_id'], 'amount' => $take];
            $left -= $take;
        }
        if ($a['amount'] - $take > MONEY_EPS) {
            $disc[] = ['ref_type' => $a['ref_type'], 'ref_id' => $a['ref_id'], 'amount' => money_r($a['amount'] - $take)];
        }
    }
    return [$main, $disc];
}
