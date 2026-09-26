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

/** ONE SIDE of a party's ledger, as a positive number: 'in' = what their sale
 *  bills still owe us, 'out' = what our purchase bills still owe them.
 *
 *  This used to be max(0, balance) and max(0, -balance) off the NETTED figure,
 *  which is wrong for a party the shop both sells to and buys from. Owed
 *  ₹5,000 on a sale and owing ₹3,000 on a purchase nets to ₹2,000, and the
 *  netted answer then said the customer owed ₹2,000 (reminders and pay links
 *  would have asked them for that) and the supplier was owed nothing at all.
 *  Both sides were wrong at once, and only for the parties where it matters.
 *
 *  Netting the two is a real bookkeeping act - the contra entry on the
 *  Payments screen - and until it is recorded the two debts are separate.
 *
 *  Which side a PAYMENT belongs to is its ref_type, not merely its direction:
 *  refunding a customer for returned goods is money out, but it is sale-side
 *  money out, and it puts their bill's due back rather than reducing what we
 *  owe a supplier. Same for a supplier's cash refund coming in.
 *
 *  The two sides always subtract to party_balance() exactly - there is a test
 *  that checks that identity against every party in the database. */
/** Which side of the ledger a PAYMENT row belongs to.
 *
 *  Not its direction. A payment carries the bill it was made against in
 *  ref_type, and that is what decides the side: refunding a customer for
 *  returned goods is money OUT but sale-side money out, and correcting the
 *  paid figure on a purchase bill downwards is money IN but purchase-side
 *  money in (purchases.php and sales.php both write those). Only a payment
 *  with no bill behind it - a plain receipt, an advance, a contra leg - falls
 *  back to its direction.
 *
 *  Returns the SQL condition that selects this side's payments. */
function payment_side_sql($dir, $alias = '') {
    $a = $alias ? $alias . '.' : '';
    $rt = "COALESCE({$a}ref_type,'')";
    return $dir === 'in'
        ? "($rt IN ('sale','sales_return') OR ($rt NOT IN ('purchase','purchase_return') AND {$a}direction = 'in'))"
        : "($rt IN ('purchase','purchase_return') OR ($rt NOT IN ('sale','sales_return') AND {$a}direction = 'out'))";
}

/** One side's RAW running total, which may be negative when that side has been
 *  over-settled (a customer who paid more than they bought, a supplier we
 *  advanced money to). Receivable-side terms minus payable-side terms is
 *  party_balance_expr() exactly, term for term: every payment lands on exactly
 *  one side, reducing it when it settles that side and adding to it when it
 *  gives money back. */
function party_side_terms_expr($alias = 'p', $dir = 'in') {
    $mine = payment_side_sql($dir);
    if ($dir === 'in') {
        return "(GREATEST($alias.opening_balance, 0)
            + COALESCE((SELECT SUM(total) FROM sales WHERE party_id = $alias.id AND is_cancelled = 0), 0)
            - COALESCE((SELECT SUM(total) FROM sales_returns WHERE party_id = $alias.id), 0)
            - COALESCE((SELECT SUM(IF(direction = 'in', amount, -amount)) FROM payments WHERE party_id = $alias.id AND $mine), 0))";
    }
    return "(GREATEST(-$alias.opening_balance, 0)
        + COALESCE((SELECT SUM(total) FROM purchases WHERE party_id = $alias.id AND is_cancelled = 0), 0)
        - COALESCE((SELECT SUM(total) FROM purchase_returns WHERE party_id = $alias.id), 0)
        - COALESCE((SELECT SUM(IF(direction = 'out', amount, -amount)) FROM payments WHERE party_id = $alias.id AND $mine), 0))";
}

/** ...and the side as a POSITIVE amount, which is what every caller wants.
 *
 *  An over-settled side lands on the other one, because that is what it is: a
 *  customer who has paid more than they owe is money the shop is holding, and
 *  it must keep showing up under "You'll Give". That also makes the two sides
 *  subtract to party_balance() exactly, in every case - there is a test that
 *  checks the identity against every party in the database. */
function party_balance_side_expr($alias = 'p', $dir = 'in') {
    $in = party_side_terms_expr($alias, 'in');
    $out = party_side_terms_expr($alias, 'out');
    return $dir === 'in'
        ? "(GREATEST($in, 0) + GREATEST(-$out, 0))"
        : "(GREATEST($out, 0) + GREATEST(-$in, 0))";
}

/** The same rule as party_balance_side_expr(), applied in PHP to the two raw
 *  term totals. A list screen selects the two term expressions ONCE and calls
 *  this per row, instead of asking MySQL for both sides (which evaluates the
 *  terms four times) plus the net (a third full expression). Same numbers - a
 *  test compares the two paths row for row. */
function money_sides_from_terms($inTerms, $outTerms) {
    $R = (float)$inTerms; $P = (float)$outTerms;
    return [
        'recv_due' => money_r(max(0.0, $R) + max(0.0, -$P)),
        'pay_due'  => money_r(max(0.0, $P) + max(0.0, -$R)),
        'bal'      => money_r($R - $P),
    ];
}

function party_balance_side($party_id, $dir) {
    return (float)val('SELECT ' . party_balance_side_expr('p', $dir) . ' FROM parties p WHERE p.id = ?', [$party_id]);
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

/** WHO OWES WHAT, AND HOW OLD IT IS - the Aging / Collection report's one rule.
 *
 *  Returns one row per party, newest-owing first, with the four age buckets
 *  (0-30 / 31-60 / 61-90 / 90+ days) and the total. The report screen only
 *  draws what comes back, so the numbers can be checked without a browser.
 *
 *  Two things decide every figure here:
 *
 *  1. AGE IS COUNTED FROM THE BILL DATE, never the due date. Credit days are
 *     a promise about when the money will come, not about how old the debt
 *     is. A bill written 45 days ago with 30 days' credit is 45 days old, not
 *     15 - counting from the due date pushed real old money into the "0-30"
 *     column and hid exactly what needed chasing.
 *
 *  2. THE OPENING BALANCE COUNTS TOO, from the day the party was created -
 *     the day the shop took that old money onto its books. It is the oldest
 *     thing a party owes, so it sits at the head of the list, and the
 *     oldest-first trim below takes any already-received money off it first.
 */
function aging_rows($fCompany = 0, $fParty = 0) {
    $ewA = ''; $epA = [];
    if ($fCompany) { $ewA .= ' AND s.company_id = ?'; $epA[] = $fCompany; }
    if ($fParty) { $ewA .= ' AND s.party_id = ?'; $epA[] = $fParty; }
    $rows = all("SELECT s.party_id, COALESCE(p.name, NULLIF(s.customer_name, ''), 'Walk-in') pname, s.customer_mobile,
                 p.mobile party_mobile, TRIM(CONCAT_WS(', ', NULLIF(p.address, ''), NULLIF(p.city, ''))) addr,
                 s.due_date, s.sale_date, (s.total - s.paid) due
                 FROM sales s LEFT JOIN parties p ON p.id = s.party_id
                 WHERE s.is_cancelled = 0 AND s.status <> 'paid' AND s.total - s.paid > 0.009 $ewA
                 ORDER BY COALESCE(s.due_date, s.sale_date), s.id", $epA);
    // Everything a party still owes, oldest first, as ONE list per party:
    // the opening balance they came in with, then each unpaid bill.
    $byParty = [];
    $openItem = function ($key, $pid, $pname, $mobile, $addr) use (&$byParty) {
        if (!isset($byParty[$key])) $byParty[$key] = ['pid' => (int)$pid, 'pname' => $pname,
            'mobile' => $mobile, 'addr' => $addr, 'items' => []];
    };

    // The opening balance is old money the shop was already owed on the day
    // the party was put into the software. It is a debt like any other and
    // belongs on this report - it was missing entirely, so a party whose
    // whole balance was opening money showed up owing nothing. It is also the
    // OLDEST thing they owe, so it goes at the head of the list: unlinked
    // payments settle the oldest debt first, and the trim below has to know
    // that. opening_due() is the same rule the payment allocator uses, so a
    // part of it already settled against a payment is not asked for twice.
    //
    // Its age is counted from the day the party was created - the day the
    // shop took that balance onto its books.
    //
    // A company filter narrows the report to one firm's bills; an opening
    // balance belongs to no firm, so it is left out of that view. A party
    // switched OFF is still chased: the bills side never filtered them out
    // either, and money owed does not stop being owed.
    if (!$fCompany) {
        $ow = ''; $op = [];
        if ($fParty) { $ow = ' AND p.id = ?'; $op[] = $fParty; }
        foreach (all("SELECT p.id, p.name, p.mobile, p.created_at,
                             TRIM(CONCAT_WS(', ', NULLIF(p.address, ''), NULLIF(p.city, ''))) addr
                      FROM parties p WHERE p.opening_balance > 0.009 $ow", $op) as $pp) {
            $odue = opening_due((int)$pp['id'], 'in');
            if ($odue <= 0.009) continue;
            $key = $pp['id'] . '|' . $pp['name'];
            $openItem($key, $pp['id'], $pp['name'], $pp['mobile'], $pp['addr']);
            $byParty[$key]['items'][] = ['date' => substr((string)$pp['created_at'], 0, 10), 'due' => $odue];
        }
    }

    foreach ($rows as $x) {
        $key = ($x['party_id'] ?: 'w') . '|' . $x['pname'];
        $openItem($key, $x['party_id'], $x['pname'], $x['party_mobile'] ?: $x['customer_mobile'], $x['addr']);
        // AGED FROM THE BILL DATE, not the due date. Credit days are a promise
        // about when money will come, not about how old the debt is: a bill
        // given 30 days' credit is 45 days old on day 45, and putting it in
        // the "0-30" column because it only went overdue a fortnight ago hid
        // exactly the money that needed chasing.
        $byParty[$key]['items'][] = ['date' => $x['sale_date'], 'due' => (float)$x['due']];
    }

    // A payment saved in the party LEDGER (Payment In) without being linked
    // to its bill leaves sales.paid untouched - the customer HAS paid and the
    // ledger says so, but the bill alone still reads "due". So each party is
    // capped at their REAL ledger balance, knocking the already-received
    // difference off the oldest debt first (FIFO), the way Vyapar/Tally do.
    // A fully settled party disappears from this report entirely.
    $agg = [];
    foreach ($byParty as $key => $P) {
        $items = $P['items'];
        if ($P['pid']) {
            // same oldest-first ledger cap the Payments list and every reminder
            // use - money_trim_dues() in includes/money.php is the one copy
            try {
                foreach (money_trim_dues(array_column($items, 'due'), party_balance_side($P['pid'], 'in')) as $i => $adj)
                    $items[$i]['due'] = $adj;
            } catch (Exception $e) { /* no ledger reading - leave the dues as billed */ }
        }
        foreach ($items as $it) {
            if ((float)$it['due'] <= 0.009) continue;
            $days = (int)days_between($it['date']);
            $bucket = $days <= 30 ? 'b1' : ($days <= 60 ? 'b2' : ($days <= 90 ? 'b3' : 'b4'));
            if (!isset($agg[$key])) $agg[$key] = ['pname' => $P['pname'], 'party_id' => $P['pid'],
                'mobile' => $P['mobile'], 'addr' => $P['addr'],
                'b1' => 0, 'b2' => 0, 'b3' => 0, 'b4' => 0, 'total' => 0];
            $agg[$key][$bucket] += $it['due'];
            $agg[$key]['total'] += $it['due'];
        }
    }
    usort($agg, fn($a, $b) => $b['total'] <=> $a['total']);
    return $agg;
}

/** Undo one payment completely: every bill it settled goes back to unpaid by
 *  exactly what this payment put on it, its allocations go, and the payment
 *  row itself goes.
 *
 *  Written once because it happens two ways now - the owner deleting a
 *  payment, and a cheque bouncing. A bounced cheque is not a correction, it
 *  is money that never arrived, and the bills it "paid" have to open again.
 *  MUST be called inside a transaction. */
function payment_reverse($paymentId) {
    $pid = (int)$paymentId;
    $pay = row('SELECT * FROM payments WHERE id = ?', [$pid]);
    if (!$pay) return false;
    // money_reverse_bill_paid() directly, not payments.php's one-line wrapper
    // around it: this runs from the cheque register too, where that file is
    // not loaded at all and the wrapper does not exist.
    if ($pay['ref_type'] && $pay['ref_id']) money_reverse_bill_paid($pay['ref_type'], $pay['ref_id'], (float)$pay['amount']);
    foreach (all('SELECT * FROM payment_allocations WHERE payment_id = ?', [$pid]) as $a) {
        money_reverse_bill_paid($a['ref_type'], $a['ref_id'], (float)$a['amount']);
    }
    q('DELETE FROM payment_allocations WHERE payment_id = ?', [$pid]);
    q('DELETE FROM payments WHERE id = ?', [$pid]);
    return true;
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

/** Payment modes where NO real money moved.
 *
 *  A contra nets two sides of one party, a settlement discount waives a
 *  balance, and a warranty credit note is the company knocking money off what
 *  we owe them instead of sending the part back. All three change what a party
 *  owes; none of them is cash leaving a drawer or a bank.
 *
 *  Every cashbook, bank-ledger and collection figure has to exclude the same
 *  three, so the list is written once. It used to be spelled out inline in
 *  half a dozen queries, which is how the fourth one gets forgotten. */
function money_noncash_modes() { return ['contra', 'discount', 'credit_note']; }

/** ...as a SQL condition. $col is the mode column, already qualified. */
function money_cash_only_sql($col = 'mode') {
    return $col . " NOT IN ('" . implode("','", money_noncash_modes()) . "')";
}

/** THE stock valuation rule, in one place - what the shop's goods are worth.
 *
 *  Four screens were each asking this their own way and getting four different
 *  answers off the same data: the dashboard said 7,020, the Balance Sheet
 *  5,620, the Business Report 7,230 and the Stock Report 6,820. The owner
 *  noticed because two of them sat 826 rupees apart on his phone.
 *
 *  Three decisions, made once:
 *
 *  - STOCK WITH STAFF COUNTS. A part out with a technician for a job has not
 *    been sold; it is still the shop's goods. The Balance Sheet was leaving it
 *    out, which understated the asset side by exactly that amount and let the
 *    difference disappear into the "opening equity" note.
 *
 *  - SERVICES DO NOT. A service has nothing on a shelf. If a stray stock row
 *    exists against one it is a data fault, not inventory, and the Business
 *    Report was pricing it as if it were.
 *
 *  - A SWITCHED-OFF ITEM STILL COUNTS WHILE IT HAS STOCK. Deactivating an item
 *    hides it from the pick lists; it does not give the goods away. The Stock
 *    Report was dropping those rows, so real stock on a real shelf was missing
 *    from the total. (The same call is already made for parties: one
 *    deactivated with money outstanding stays collectible.)
 *
 *  Valued at the item's purchase price. FIFO / weighted-average valuation is a
 *  separate, clearly-labelled table on the Stock Report and is left alone. */
function stock_qty_sql() {
    return "(SELECT item_id, SUM(qty) q FROM
                (SELECT item_id, qty FROM stock UNION ALL SELECT item_id, qty FROM staff_stock) z
             GROUP BY item_id)";
}

/** The valuation as SQL, so a report can join more onto it. $priceCol is the
 *  column to value at - purchase price by default, selling price for the
 *  "what is it worth on the shelf" figure. */
function stock_value_sql($priceCol = 'i.purchase_price') {
    return "SELECT COALESCE(SUM(sq.q * " . $priceCol . "), 0)
            FROM " . stock_qty_sql() . " sq JOIN items i ON i.id = sq.item_id
            WHERE i.item_type <> 'service'";
}

/** ...and the number itself. */
function stock_value($priceCol = 'i.purchase_price') {
    return (float)val(stock_value_sql($priceCol));
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
