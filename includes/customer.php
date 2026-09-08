<?php
// ============================================================================
//  CUSTOMER INTELLIGENCE + SMART COLLECTION
// ============================================================================
//  "Who is this customer, what do they buy, what are they worth, what do they
//   owe, when do they actually pay, and what should we do about it now?"
//
//  Everything here is DERIVED at read time from parties/sales/payments and the
//  rules in includes/money.php. Nothing is precomputed into a second table, so
//  a segment or a lifetime value can never go stale or disagree with the
//  ledger. The one thing that IS stored is what a human did about a debt -
//  rang the customer, took a promise, left a note - because that is real new
//  information (collection_events, migration v55).
//
//  Every rupee owed comes from party_balance() / money_trim_dues(), never from
//  a fresh sum over bills: an unlinked payment must reduce what we ask for.
//
//  Nothing here is predicted or scored by a model. Every threshold is a plain
//  number the owner can change in Settings, and every classification can be
//  explained in one sentence - which matters, because these decide who gets
//  chased for money.
// ============================================================================

// ------------------------------------------------------- tunable thresholds -

/** The rules behind segmentation and collection, all owner-editable.
 *  Defaults are deliberately conservative for a small computer/CCTV shop. */
function cust_rules() {
    // not cached in a static: setting() already holds them in memory, and a
    // static would hand back stale rules to a screen that just saved them
    return [
        'vip_spend'        => (float)setting('cust_vip_spend', 50000),      // 12-month spend to count as VIP
        'high_value_spend' => (float)setting('cust_high_spend', 20000),
        'regular_bills'    => (int)setting('cust_regular_bills', 3),        // bills in 12 months
        'new_days'         => (int)setting('cust_new_days', 60),
        'inactive_days'    => (int)setting('cust_inactive_days', 180),
        'at_risk_days'     => (int)setting('cust_at_risk_days', 90),        // fallback when there is no rhythm to read
        'at_risk_factor'   => (float)setting('cust_at_risk_factor', 2.0),   // gap > factor x their own usual gap
        'credit_months'    => (int)setting('cust_credit_months', 3),        // credit limit ~ this many months of buying
        'reminder_cooldown'=> (int)setting('collection_cooldown_days', 3),  // never message the same customer twice inside this
        'max_reminders'    => (int)setting('collection_max_reminders', 4),  // per customer per month
        // what counts as "a big debt" and "very late" for THIS shop - the two
        // reference points the priority score is measured against. A shop
        // dealing in lakhs should raise these or every row saturates at the top.
        'big_debt'         => (float)setting('collection_big_debt', 25000),
        'very_late_days'   => (int)setting('collection_very_late_days', 60),
    ];
}

// ------------------------------------------------------------ the 360 view --

/** Everything known about one customer, in a handful of queries.
 *
 *  Deliberately one function rather than a dozen small ones: the Customer 360
 *  page needs all of it at once, and this way the page cannot accidentally
 *  turn into twenty queries. */
function cust_360($partyId) {
    $partyId = (int)$partyId;
    $p = row('SELECT * FROM parties WHERE id = ?', [$partyId]);
    if (!$p) return null;
    $t = today();

    // --- money: the ledger is the truth ---
    $bal = party_balance($partyId);
    $sales = row("SELECT COUNT(*) bills, COALESCE(SUM(total),0) total, COALESCE(SUM(paid),0) paid,
                         COALESCE(MAX(total),0) biggest, MIN(sale_date) first_sale, MAX(sale_date) last_sale,
                         COALESCE(SUM(discount + loyalty_discount),0) discount
                  FROM sales WHERE party_id = ? AND is_cancelled = 0", [$partyId]);
    $year = row("SELECT COUNT(*) bills, COALESCE(SUM(total),0) total
                 FROM sales WHERE party_id = ? AND is_cancelled = 0 AND sale_date >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)", [$partyId]);
    $lastPay = row("SELECT pay_date, amount FROM payments WHERE party_id = ? AND direction = 'in' ORDER BY pay_date DESC, id DESC LIMIT 1", [$partyId]);

    // margin contribution, not "profit": it covers the goods sold and the
    // discounts given, but not rent, staff or the time spent on this customer
    $margin = null;
    if (can('reports.profit')) {
        $m = row("SELECT COALESCE(SUM(si.total),0) revenue, COALESCE(SUM(" . profit_cost_sql() . "),0) cost
                  FROM sale_items si JOIN sales s ON s.id = si.sale_id JOIN items i ON i.id = si.item_id
                  WHERE s.party_id = ? AND s.is_cancelled = 0", [$partyId]);
        $margin = ['revenue' => (float)$m['revenue'], 'cost' => (float)$m['cost'],
                   'contribution' => round((float)$m['revenue'] - (float)$m['cost'] - (float)$sales['discount'], 2)];
    }

    $due = cust_outstanding($partyId, $bal);

    return [
        'party' => $p,
        'since' => $p['created_at'] ? substr($p['created_at'], 0, 10) : $sales['first_sale'],
        'balance' => $bal,
        'outstanding' => $due['total'],
        'overdue' => $due['overdue'],
        'overdue_days' => $due['days'],
        'bills' => (int)$sales['bills'],
        'total_purchase' => (float)$sales['total'],
        'total_paid' => (float)$sales['paid'],
        'biggest_bill' => (float)$sales['biggest'],
        'avg_bill' => $sales['bills'] ? round((float)$sales['total'] / (int)$sales['bills'], 2) : 0.0,
        'discount_given' => (float)$sales['discount'],
        'first_sale' => $sales['first_sale'],
        'last_sale' => $sales['last_sale'],
        'year_bills' => (int)$year['bills'],
        'year_value' => (float)$year['total'],
        'monthly_value' => round((float)$year['total'] / 12, 2),
        'lifetime_value' => (float)$sales['total'],
        'margin' => $margin,
        'last_payment' => $lastPay ?: null,
        'frequency' => cust_frequency($partyId),
        'segments' => cust_segments_for($partyId),
        'credit' => cust_credit($partyId, $bal),
        'opt_out' => (int)($p['collection_opt_out'] ?? 0) === 1,
    ];
}

/** What this customer really owes, capped by the ledger, plus how late the
 *  oldest unpaid bill is. Shared by the 360 page and the collection queue. */
function cust_outstanding($partyId, $bal = null) {
    // the SALE side, not max(0, netted balance): a customer the shop also buys
    // from had their own unpaid bills quietly written down by what WE owe THEM
    $cap = party_balance_side($partyId, 'in');
    $bills = money_due_bills($partyId, 'in', 'id, invoice_no, sale_date, due_date, ROUND(total - paid, 2) d');
    $adj = money_trim_dues(array_column($bills, 'd'), $cap);
    $t = today();
    $total = 0.0; $overdue = 0.0; $oldest = null; $n = 0; $lines = [];
    foreach ($bills as $i => $b) {
        $d = $adj[$i];
        if ($d <= MONEY_EPS) continue;
        $total += $d; $n++;
        $ref = $b['due_date'] ?: $b['sale_date'];
        if ($ref < $t) { $overdue += $d; if ($oldest === null || $ref < $oldest) $oldest = $ref; }
        $lines[] = ['id' => (int)$b['id'], 'invoice_no' => $b['invoice_no'], 'date' => $b['sale_date'],
                    'due_date' => $b['due_date'], 'due' => $d, 'overdue' => $ref < $t];
    }
    return ['total' => round($total, 2), 'overdue' => round($overdue, 2), 'bills' => $n,
            'days' => $oldest ? days_between_dates($oldest, $t) : 0, 'oldest' => $oldest, 'lines' => $lines];
}

/** How often this customer buys, read from their OWN history rather than a
 *  fixed rule: the median gap between consecutive bills. Median, not average,
 *  so one unusual six-month gap does not distort the picture. */
function cust_frequency($partyId, $windowDays = 730) {
    // same two-year window as cust_gap_map(), so the number on Customer 360
    // and the number behind the At Risk label are always the same number
    $dates = array_column(all("SELECT sale_date FROM sales WHERE party_id = ? AND is_cancelled = 0
                               AND sale_date >= DATE_SUB(CURDATE(), INTERVAL " . (int)$windowDays . " DAY)
                               ORDER BY sale_date", [$partyId]), 'sale_date');
    if (count($dates) < 2) return ['gap_days' => null, 'bills' => count($dates), 'per_year' => null];
    $gaps = [];
    for ($i = 1; $i < count($dates); $i++) {
        $g = days_between_dates($dates[$i - 1], $dates[$i]);
        if ($g > 0) $gaps[] = $g;
    }
    if (!$gaps) return ['gap_days' => null, 'bills' => count($dates), 'per_year' => null];
    sort($gaps);
    $mid = intdiv(count($gaps), 2);
    $median = count($gaps) % 2 ? $gaps[$mid] : (int)round(($gaps[$mid - 1] + $gaps[$mid]) / 2);
    return ['gap_days' => $median, 'bills' => count($dates),
            'per_year' => $median > 0 ? round(365 / $median, 1) : null];
}

// ------------------------------------------------------------- segmentation -

/** Which segments one customer falls into, with the REASON for each - so the
 *  owner can always see why somebody was labelled At Risk. */
function cust_segments_for($partyId) {
    $rows = cust_segment_rows("AND p.id = " . (int)$partyId);
    return $rows[$partyId]['segments'] ?? [];
}

/** Segment every customer in ONE pass.
 *
 *  This is the query the segmentation page, the dashboard cards and the
 *  at-risk list all share - running it once for everybody instead of once per
 *  customer is what keeps Phase 4 off the N+1 path. */
function cust_segment_rows($extraWhere = '') {
    $r = cust_rules();
    $t = today();
    $rows = all("SELECT p.id, p.name, p.mobile, p.city, p.created_at, p.credit_days, p.collection_opt_out,
                        " . party_balance_expr('p') . " bal,
                        " . party_balance_side_expr('p', 'in') . " recv_due,
                        COUNT(s.id) bills,
                        COALESCE(SUM(s.total),0) lifetime,
                        COALESCE(SUM(CASE WHEN s.sale_date >= DATE_SUB(CURDATE(), INTERVAL 365 DAY) THEN s.total ELSE 0 END),0) year_value,
                        COALESCE(SUM(CASE WHEN s.sale_date >= DATE_SUB(CURDATE(), INTERVAL 365 DAY) THEN 1 ELSE 0 END),0) year_bills,
                        MIN(s.sale_date) first_sale, MAX(s.sale_date) last_sale
                 FROM parties p
                 LEFT JOIN sales s ON s.party_id = p.id AND s.is_cancelled = 0
                 WHERE p.type <> 'supplier' $extraWhere
                 GROUP BY p.id");

    // each customer's own buying rhythm, in one grouped pass rather than a
    // query per customer
    $gaps = cust_gap_map($extraWhere);

    $out = [];
    foreach ($rows as $x) {
        $id = (int)$x['id'];
        $last = $x['last_sale'];
        $idle = $last ? days_between_dates($last, $t) : null;
        $bal = (float)$x['bal'];
        $owed = (float)$x['recv_due'];   // what their SALE bills still owe
        $segs = [];

        if ($x['created_at'] && days_between_dates(substr($x['created_at'], 0, 10), $t) <= $r['new_days'])
            $segs['new'] = 'ખાતું ' . $r['new_days'] . ' દિવસમાં ખૂલ્યું છે';

        if ($last !== null) {
            if ((float)$x['year_value'] >= $r['vip_spend'] && $idle <= $r['inactive_days'])
                $segs['vip'] = 'છેલ્લા વર્ષમાં ₹' . money($x['year_value']) . ' ની ખરીદી';
            elseif ((float)$x['year_value'] >= $r['high_value_spend'])
                $segs['high_value'] = 'છેલ્લા વર્ષમાં ₹' . money($x['year_value']) . ' ની ખરીદી';

            if ((int)$x['year_bills'] >= $r['regular_bills'])
                $segs['regular'] = 'છેલ્લા વર્ષમાં ' . (int)$x['year_bills'] . ' બિલ';

            if ($idle >= $r['inactive_days']) {
                $segs['inactive'] = $idle . ' દિવસથી કંઈ ખરીદ્યું નથી';
            } else {
                // At Risk is judged against the customer's OWN usual gap first,
                // and only falls back to a fixed number when they have no
                // rhythm yet. Somebody who always buys every 30 days is at risk
                // at 70; somebody who buys twice a year is not.
                $g = $gaps[$id] ?? null;
                if ($g && $idle > $g * $r['at_risk_factor'])
                    $segs['at_risk'] = 'સામાન્ય રીતે દર ' . $g . ' દિવસે ખરીદે છે, પણ ' . $idle . ' દિવસથી નથી આવ્યા';
                elseif (!$g && $idle >= $r['at_risk_days'])
                    $segs['at_risk'] = $idle . ' દિવસથી ખરીદી નથી';
            }
        }

        // The money-bearing segments carry rupee amounts in their explanation,
        // so they are attached only for someone allowed to see what customers
        // owe. Filtered HERE rather than on each screen: a segment list is a
        // tempting thing to render, and one forgotten guard would have shown a
        // sales assistant exactly how much every customer is behind on.
        if ($owed > MONEY_EPS && can('payments.view')) {
            $risk = cust_credit_risk_level($id, $owed, $x);
            if ($risk['overdue'] > MONEY_EPS) $segs['overdue'] = '₹' . money($risk['overdue']) . ' ની મુદત વીતી ગઈ છે';
            if ($risk['level'] === 'high') $segs['credit_risk'] = $risk['why'];
        }

        $out[$id] = [
            'id' => $id, 'name' => $x['name'], 'mobile' => $x['mobile'], 'city' => $x['city'],
            'balance' => $bal, 'lifetime' => (float)$x['lifetime'], 'year_value' => (float)$x['year_value'],
            'bills' => (int)$x['bills'], 'year_bills' => (int)$x['year_bills'],
            'last_sale' => $last, 'idle_days' => $idle, 'gap_days' => $gaps[$id] ?? null,
            'opt_out' => (int)$x['collection_opt_out'] === 1,
            'segments' => $segs,
        ];
    }
    return $out;
}

/** Median days between bills for every customer, in one query's worth of
 *  rows. MySQL 5.7-compatible: the medians are worked out in PHP from a flat
 *  ordered list, which is far cheaper than a window function per party.
 *
 *  Only the last two years count. That is not just for speed - a rhythm from
 *  three years ago is not this customer's rhythm today, and "usually buys
 *  every 30 days" should mean lately. It also stops the query dragging a
 *  decade of history into PHP: on a 100,000-bill shop the unbounded version
 *  took 265ms of a 1-second page.
 */
function cust_gap_map($extraWhere = '', $windowDays = 730) {
    $rows = all("SELECT s.party_id, s.sale_date FROM sales s
                 JOIN parties p ON p.id = s.party_id
                 WHERE s.is_cancelled = 0 AND s.party_id IS NOT NULL
                   AND s.sale_date >= DATE_SUB(CURDATE(), INTERVAL " . (int)$windowDays . " DAY) $extraWhere
                 ORDER BY s.party_id, s.sale_date");
    $byParty = [];
    foreach ($rows as $r) $byParty[(int)$r['party_id']][] = $r['sale_date'];
    $out = [];
    foreach ($byParty as $pid => $dates) {
        if (count($dates) < 2) continue;
        $gaps = [];
        for ($i = 1; $i < count($dates); $i++) {
            $g = days_between_dates($dates[$i - 1], $dates[$i]);
            if ($g > 0) $gaps[] = $g;
        }
        if (!$gaps) continue;
        sort($gaps);
        $mid = intdiv(count($gaps), 2);
        $out[$pid] = count($gaps) % 2 ? $gaps[$mid] : (int)round(($gaps[$mid - 1] + $gaps[$mid]) / 2);
    }
    return $out;
}

// ------------------------------------------------------ credit intelligence -

/** A suggested credit limit from what this customer actually buys, plus how
 *  reliably they pay. A SUGGESTION - the shop decides, and nothing is blocked
 *  unless the owner switches hard blocking on. */
function cust_credit($partyId, $bal = null) {
    $r = cust_rules();
    $bal = $bal === null ? party_balance($partyId) : $bal;
    $y = row("SELECT COALESCE(SUM(total),0) t, COUNT(*) c FROM sales
              WHERE party_id = ? AND is_cancelled = 0 AND sale_date >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)", [$partyId]);
    $monthly = (float)$y['t'] / 12;
    $rel = cust_reliability($partyId);
    // a reliable payer is trusted with the full window, a poor payer with half
    $factor = ['excellent' => 1.0, 'good' => 0.85, 'new' => 0.6, 'slow' => 0.5, 'poor' => 0.25][$rel['rating']] ?? 0.6;
    $suggested = round($monthly * $r['credit_months'] * $factor, -2); // to the nearest 100
    $owed = party_balance_side($partyId, 'in');   // sale side only
    return [
        'suggested' => max(0.0, (float)$suggested),
        'outstanding' => $owed,
        'over_limit' => $owed > $suggested + MONEY_EPS,
        'reliability' => $rel,
        'monthly_buy' => round($monthly, 2),
    ];
}

/** How reliably this customer pays, from real dates: what share of their
 *  settled bills were paid after the due date, and by how long on average. */
function cust_reliability($partyId) {
    $rows = all("SELECT s.due_date, s.sale_date,
                        (SELECT MAX(p2.pay_date) FROM payment_allocations pa JOIN payments p2 ON p2.id = pa.payment_id
                         WHERE pa.ref_type = 'sale' AND pa.ref_id = s.id) paid_on
                 FROM sales s
                 WHERE s.party_id = ? AND s.is_cancelled = 0 AND s.status = 'paid'
                 ORDER BY s.sale_date DESC LIMIT 50", [$partyId]);
    $n = 0; $late = 0; $delaySum = 0;
    foreach ($rows as $r) {
        if (!$r['paid_on']) continue;
        $ref = $r['due_date'] ?: $r['sale_date'];
        $n++;
        $d = days_between_dates($ref, $r['paid_on']);
        if ($d > 0) { $late++; $delaySum += $d; }
    }
    if ($n < 2) return ['rating' => 'new', 'bills' => $n, 'late' => $late, 'avg_delay' => 0, 'late_pct' => 0];
    $pct = $late / $n;
    $rating = $pct <= 0.1 ? 'excellent' : ($pct <= 0.3 ? 'good' : ($pct <= 0.6 ? 'slow' : 'poor'));
    return ['rating' => $rating, 'bills' => $n, 'late' => $late,
            'avg_delay' => $late ? (int)round($delaySum / $late) : 0, 'late_pct' => round($pct * 100)];
}

/** Credit-risk level for the segmentation pass - cheap, because the heavy
 *  per-customer reliability query is skipped unless there is money at stake. */
function cust_credit_risk_level($partyId, $bal, array $agg = []) {
    $due = cust_outstanding($partyId, $bal);
    if ($due['overdue'] <= MONEY_EPS) return ['level' => 'none', 'overdue' => 0.0, 'why' => ''];
    $days = $due['days'];
    $amt = $due['overdue'];
    if ($days >= 60 || ($days >= 30 && $amt >= 25000)) {
        return ['level' => 'high', 'overdue' => $amt,
                'why' => '₹' . money($amt) . ' ' . $days . ' દિવસથી બાકી છે'];
    }
    return ['level' => $days >= 15 ? 'medium' : 'low', 'overdue' => $amt, 'why' => ''];
}

// -------------------------------------------------------- opportunity engine -

/** What could reasonably be sold or renewed to this customer next, each with
 *  the reason it was suggested. Rules over real history - nothing generated. */
function cust_opportunities($partyId) {
    $ops = [];
    $t = today();

    // AMC about to run out
    foreach (all("SELECT * FROM amc_contracts WHERE party_id = ? AND status = 'active'
                  AND end_date IS NOT NULL AND end_date <= DATE_ADD(CURDATE(), INTERVAL 45 DAY)
                  ORDER BY end_date LIMIT 3", [$partyId]) as $a) {
        $ops[] = ['kind' => 'amc', 'icon' => '🔁', 'title' => 'AMC રિન્યુ કરાવો',
                  'why' => e($a['title']) . ' — ' . dmy($a['end_date']) . ' ના પૂરો થાય છે',
                  'link' => 'amc.php', 'cta' => 'AMC ખોલો'];
    }
    // warranty ending soon on something they bought
    foreach (all("SELECT i.name, s.sale_date, i.warranty_months
                  FROM sale_items si JOIN sales s ON s.id = si.sale_id JOIN items i ON i.id = si.item_id
                  WHERE s.party_id = ? AND s.is_cancelled = 0 AND i.warranty_months > 0
                    AND DATE_ADD(s.sale_date, INTERVAL i.warranty_months MONTH) BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 45 DAY)
                  ORDER BY s.sale_date DESC LIMIT 3", [$partyId]) as $w) {
        $ops[] = ['kind' => 'warranty', 'icon' => '🛡️', 'title' => 'વોરંટી પૂરી થવામાં છે',
                  'why' => e($w['name']) . ' ની વોરંટી પૂરી થાય છે',
                  'link' => 'warranty.php', 'cta' => 'જુઓ'];
    }
    // repeat repair customer -> maintenance
    $rep = (int)val("SELECT COUNT(*) FROM repairs WHERE party_id = ? AND received_date >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)", [$partyId]);
    if ($rep >= 2) {
        $ops[] = ['kind' => 'service', 'icon' => '🛠️', 'title' => 'મેન્ટેનન્સ પ્લાન સૂચવો',
                  'why' => 'છેલ્લા વર્ષમાં ' . $rep . ' વાર રિપેર કરાવ્યું છે',
                  'link' => 'amc.php?action=new&party_id=' . (int)$partyId, 'cta' => 'AMC આપો'];
    }
    // bought a big-ticket item, never bought accessories from the same category
    $fav = row("SELECT c.id, c.name, MAX(s.sale_date) last_buy
                FROM sale_items si JOIN sales s ON s.id = si.sale_id JOIN items i ON i.id = si.item_id
                LEFT JOIN categories c ON c.id = i.category_id
                WHERE s.party_id = ? AND s.is_cancelled = 0 AND c.id IS NOT NULL
                GROUP BY c.id ORDER BY SUM(si.total) DESC LIMIT 1", [$partyId]);
    if ($fav) {
        $ops[] = ['kind' => 'cross_sell', 'icon' => '🧩', 'title' => e($fav['name']) . ' સાથેની વસ્તુઓ બતાવો',
                  'why' => 'સૌથી વધુ ખરીદી આ કેટેગરીમાં કરી છે (છેલ્લે ' . dmy($fav['last_buy']) . ')',
                  'link' => 'items.php?category_id=' . (int)$fav['id'], 'cta' => 'આઇટમ જુઓ'];
    }
    // gone quiet
    $seg = cust_segments_for($partyId);
    if (isset($seg['at_risk']) || isset($seg['inactive'])) {
        $ops[] = ['kind' => 'reactivation', 'icon' => '📣', 'title' => 'ફરી સંપર્ક કરો',
                  'why' => $seg['at_risk'] ?? $seg['inactive'],
                  'link' => 'customer.php?id=' . (int)$partyId . '&do=draft_reactivation', 'cta' => 'મેસેજ તૈયાર કરો'];
    }
    return $ops;
}

/** A ready-to-edit reactivation message. Written from the customer's own
 *  history by plain string building - no AI, nothing invented. Staff always
 *  see it and can change it before anything is sent. */
function cust_reactivation_draft($partyId) {
    $p = row('SELECT name FROM parties WHERE id = ?', [$partyId]);
    if (!$p) return '';
    $fav = row("SELECT c.name FROM sale_items si JOIN sales s ON s.id = si.sale_id JOIN items i ON i.id = si.item_id
                JOIN categories c ON c.id = i.category_id
                WHERE s.party_id = ? AND s.is_cancelled = 0
                GROUP BY c.id ORDER BY SUM(si.total) DESC LIMIT 1", [$partyId]);
    $shop = setting('app_name', 'AK Computer');
    $last = val("SELECT MAX(sale_date) FROM sales WHERE party_id = ? AND is_cancelled = 0", [$partyId]);
    $msg = 'નમસ્તે ' . $p['name'] . ",\n\n";
    if ($last) $msg .= 'ઘણા સમયથી (' . dmy($last) . ' પછી) આપની ખરીદી થઈ નથી.' . "\n";
    if ($fav) $msg .= 'આપને ગમતી ' . $fav['name'] . ' માં નવો સ્ટોક આવેલો છે.' . "\n";
    $msg .= "\nએક વાર દુકાને આંટો મારજો.\n\n" . $shop;
    return $msg;
}

// ============================================================================
//  SMART COLLECTION
// ============================================================================

/** How urgent is chasing this customer, 0-100, and why.
 *
 *  Deliberately a transparent weighted sum rather than a learned score: the
 *  owner can read every component, and a customer can never be pushed up the
 *  queue for a reason nobody can explain. The weights are what a shopkeeper
 *  would say out loud - how much, how late, do they usually pay, did they
 *  break a promise, are they worth keeping happy.
 */
function coll_priority(array $c) {
    $why = [];
    $score = 0.0;

    // how much (0-30), measured against what this shop calls a big debt
    $rules = cust_rules();
    $amt = (float)$c['overdue'];
    $amtPts = min(30, $amt / max(1, $rules['big_debt']) * 30);
    $score += $amtPts;
    if ($amt > 0) $why[] = '₹' . money($amt) . ' બાકી';

    // how late (0-30), measured against what this shop calls very late
    $days = (int)$c['days'];
    $score += min(30, $days / max(1, $rules['very_late_days']) * 30);
    if ($days > 0) $why[] = $days . ' દિવસ મોડું';

    // do they usually pay on time (0-20)
    $rate = $c['reliability']['rating'] ?? 'new';
    $relPts = ['poor' => 20, 'slow' => 14, 'new' => 8, 'good' => 4, 'excellent' => 0][$rate] ?? 8;
    $score += $relPts;
    if ($relPts >= 14) $why[] = 'પહેલાં પણ મોડું ચૂકવે છે';

    // a broken promise is the strongest signal there is (0-15)
    if (!empty($c['broken_promises'])) {
        $score += min(15, $c['broken_promises'] * 7.5);
        $why[] = $c['broken_promises'] . ' વાર વાયદો તૂટ્યો';
    }

    // several unpaid bills piling up (0-5)
    if (($c['bill_count'] ?? 0) > 1) {
        $score += min(5, ($c['bill_count'] - 1) * 2);
        $why[] = (int)$c['bill_count'] . ' બિલ બાકી';
    }

    // a valuable customer is chased sooner, but gently - this only nudges
    if (($c['year_value'] ?? 0) >= $rules['vip_spend']) {
        $score += 5;
        $why[] = 'મોટા ગ્રાહક';
    }

    // an open promise for a future date parks the customer until then
    if (!empty($c['promise_open']) && $c['promise_open']['due_date'] >= today()) {
        $score = max(0, $score - 25);
        $why[] = dmy($c['promise_open']['due_date']) . ' નો વાયદો છે';
    }

    $score = (int)round(min(100, $score));
    $level = $score >= 70 ? 'critical' : ($score >= 50 ? 'high' : ($score >= 30 ? 'medium' : 'low'));
    return ['score' => $score, 'level' => $level, 'why' => $why];
}

/** The collection queue: every customer who owes money, ranked by how urgent
 *  chasing them is, with everything the staff needs to act on the row.
 *
 *  Built from GROUPED queries - one for the balances, one for the open bills,
 *  one for the last payment per party, one for reliability, one for the
 *  collection events - and then assembled in PHP. It never runs a query per
 *  customer, which is what would turn a 300-customer queue into 1,500 queries.
 */
function coll_queue($limit = 200, $includeSnoozed = false) {
    $t = today();
    $r = cust_rules();

    // 1. everyone who owes money.
    //
    // The whole list is scored and only THEN cut to $limit. Cutting first -
    // by balance - would have hidden the customer who owes a modest sum but
    // is 600 days late and has broken two promises, which is exactly the row
    // this screen exists to surface. The internal ceiling is a safety net for
    // an unusually large shop and is ordered by balance so the biggest debts
    // are always among those considered.
    $scan = max((int)$limit, (int)setting('collection_scan_limit', 2000));
    $parties = all("SELECT p.id, p.name, p.mobile, p.city, p.collection_opt_out, p.credit_days,
                           " . party_balance_expr('p') . " bal,
                           " . party_balance_side_expr('p', 'in') . " recv_due
                    FROM parties p
                    WHERE p.type <> 'supplier'
                    HAVING recv_due > 0.009
                    ORDER BY recv_due DESC LIMIT " . $scan);
    if (!$parties) return [];
    $ids = array_map(fn($x) => (int)$x['id'], $parties);
    $in = implode(',', $ids);

    // 2. their open bills, oldest first (one query for all of them)
    $billsBy = [];
    foreach (all("SELECT id, party_id, invoice_no, sale_date, due_date, ROUND(total - paid, 2) d, last_reminder
                  FROM sales
                  WHERE party_id IN ($in) AND is_cancelled = 0 AND status IN ('due','partial')
                  ORDER BY party_id, due_date IS NULL, due_date, id") as $b)
        $billsBy[(int)$b['party_id']][] = $b;

    // 3. last payment, 4. year value, 5. collection events - all grouped
    $lastPay = [];
    foreach (all("SELECT party_id, MAX(pay_date) d FROM payments
                  WHERE party_id IN ($in) AND direction = 'in' GROUP BY party_id") as $x)
        $lastPay[(int)$x['party_id']] = $x['d'];

    $yearVal = [];
    foreach (all("SELECT party_id, COALESCE(SUM(total),0) t FROM sales
                  WHERE party_id IN ($in) AND is_cancelled = 0 AND sale_date >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)
                  GROUP BY party_id") as $x)
        $yearVal[(int)$x['party_id']] = (float)$x['t'];

    $ev = coll_event_map($ids);
    $rel = coll_reliability_map($ids);

    $out = [];
    foreach ($parties as $p) {
        $id = (int)$p['id'];
        $bills = $billsBy[$id] ?? [];
        if (!$bills) continue;

        // the ledger cap, exactly as everywhere else
        $adj = money_trim_dues(array_column($bills, 'd'), (float)$p['recv_due']);
        $total = 0.0; $overdue = 0.0; $oldest = null; $n = 0; $lastRem = null;
        foreach ($bills as $i => $b) {
            $d = $adj[$i];
            if ($d <= MONEY_EPS) continue;
            $total += $d; $n++;
            $ref = $b['due_date'] ?: $b['sale_date'];
            if ($ref < $t) { $overdue += $d; if ($oldest === null || $ref < $oldest) $oldest = $ref; }
            if ($b['last_reminder'] && ($lastRem === null || $b['last_reminder'] > $lastRem)) $lastRem = $b['last_reminder'];
        }
        if ($total <= MONEY_EPS) continue;

        $e = $ev[$id] ?? [];
        // an active snooze hides the row unless the caller asks for everything
        $snoozed = !empty($e['snooze_until']) && $e['snooze_until'] >= $t;
        if ($snoozed && !$includeSnoozed) continue;

        $c = [
            'id' => $id, 'name' => $p['name'], 'mobile' => $p['mobile'], 'city' => $p['city'],
            'balance' => (float)$p['bal'],
            'outstanding' => round($total, 2), 'overdue' => round($overdue, 2),
            'days' => $oldest ? days_between_dates($oldest, $t) : 0,
            'oldest' => $oldest, 'bill_count' => $n,
            'last_payment' => $lastPay[$id] ?? null,
            'last_reminder' => $lastRem,
            'year_value' => $yearVal[$id] ?? 0.0,
            'reliability' => $rel[$id] ?? ['rating' => 'new'],
            'broken_promises' => $e['broken'] ?? 0,
            'promise_open' => $e['promise_open'] ?? null,
            'last_contact' => $e['last_contact'] ?? null,
            'contacts_30d' => $e['contacts_30d'] ?? 0,
            'snoozed_until' => $snoozed ? $e['snooze_until'] : null,
            'opt_out' => (int)$p['collection_opt_out'] === 1,
        ];
        $c['priority'] = coll_priority($c);
        $c['can_remind'] = coll_can_remind($c);
        $out[] = $c;
    }

    usort($out, fn($a, $b) => $b['priority']['score'] <=> $a['priority']['score']
                             ?: $b['overdue'] <=> $a['overdue']);
    return array_slice($out, 0, (int)$limit);
}

/** Collection history per party, summarised - one query for the whole queue. */
function coll_event_map(array $ids) {
    if (!$ids) return [];
    $in = implode(',', array_map('intval', $ids));
    $out = [];
    foreach (all("SELECT * FROM collection_events WHERE party_id IN ($in) ORDER BY party_id, id") as $e) {
        $pid = (int)$e['party_id'];
        if (!isset($out[$pid])) $out[$pid] = ['broken' => 0, 'promise_open' => null, 'last_contact' => null,
                                              'contacts_30d' => 0, 'snooze_until' => null];
        if ($e['event_type'] === 'broken') $out[$pid]['broken']++;
        if ($e['event_type'] === 'promise' && $e['status'] === 'open') $out[$pid]['promise_open'] = $e;
        if (in_array($e['event_type'], ['reminder', 'call'], true)) {
            $out[$pid]['last_contact'] = $e['created_at'];
            if (strtotime($e['created_at']) >= strtotime('-30 days')) $out[$pid]['contacts_30d']++;
        }
        if ($e['event_type'] === 'snooze' && $e['due_date'] && $e['status'] === 'open')
            $out[$pid]['snooze_until'] = $e['due_date'];
    }
    return $out;
}

/** Payment reliability for many parties at once. */
function coll_reliability_map(array $ids) {
    if (!$ids) return [];
    $in = implode(',', array_map('intval', $ids));
    $rows = all("SELECT s.party_id,
                        COUNT(*) n,
                        SUM(CASE WHEN pa.d > COALESCE(s.due_date, s.sale_date) THEN 1 ELSE 0 END) late,
                        AVG(GREATEST(DATEDIFF(pa.d, COALESCE(s.due_date, s.sale_date)), 0)) avg_delay
                 FROM sales s
                 JOIN (SELECT pa.ref_id sid, MAX(p2.pay_date) d
                       FROM payment_allocations pa JOIN payments p2 ON p2.id = pa.payment_id
                       WHERE pa.ref_type = 'sale' GROUP BY pa.ref_id) pa ON pa.sid = s.id
                 WHERE s.party_id IN ($in) AND s.is_cancelled = 0 AND s.status = 'paid'
                 GROUP BY s.party_id");
    $out = [];
    foreach ($rows as $r) {
        $n = (int)$r['n']; $late = (int)$r['late'];
        if ($n < 2) { $out[(int)$r['party_id']] = ['rating' => 'new', 'bills' => $n, 'late' => $late, 'avg_delay' => 0]; continue; }
        $pct = $late / $n;
        $out[(int)$r['party_id']] = [
            'rating' => $pct <= 0.1 ? 'excellent' : ($pct <= 0.3 ? 'good' : ($pct <= 0.6 ? 'slow' : 'poor')),
            'bills' => $n, 'late' => $late, 'avg_delay' => (int)round((float)$r['avg_delay']),
        ];
    }
    return $out;
}

/** May we message this customer right now? The whole point of Phase 4 is to
 *  send FEWER, better-aimed messages - so every guard here is a reason not to
 *  send, and each one explains itself. */
function coll_can_remind(array $c) {
    $r = cust_rules();
    $t = today();
    if (!empty($c['opt_out']))    return ['ok' => false, 'why' => 'આ ગ્રાહકે મેસેજ ન મોકલવાનું કહ્યું છે'];
    if (empty($c['mobile']))      return ['ok' => false, 'why' => 'મોબાઇલ નંબર નથી'];
    if (!empty($c['snoozed_until']) && $c['snoozed_until'] >= $t)
        return ['ok' => false, 'why' => dmy($c['snoozed_until']) . ' સુધી રોકી રાખ્યું છે'];
    if (!empty($c['promise_open']) && $c['promise_open']['due_date'] >= $t)
        return ['ok' => false, 'why' => dmy($c['promise_open']['due_date']) . ' નો વાયદો છે — ત્યાં સુધી રાહ જુઓ'];
    if (!empty($c['last_contact'])) {
        $since = (int)floor((time() - strtotime($c['last_contact'])) / 86400);
        if ($since < $r['reminder_cooldown'])
            return ['ok' => false, 'why' => $since . ' દિવસ પહેલાં જ સંપર્ક કર્યો છે (ઓછામાં ઓછા ' . $r['reminder_cooldown'] . ' દિવસ રાહ જુઓ)'];
    }
    if (($c['contacts_30d'] ?? 0) >= $r['max_reminders'])
        return ['ok' => false, 'why' => 'આ મહિને પહેલેથી ' . $c['contacts_30d'] . ' વાર સંપર્ક થયો છે'];
    return ['ok' => true, 'why' => ''];
}

/** Record something a human did about a debt. */
function coll_log($partyId, $type, array $opt = []) {
    q("INSERT INTO collection_events (party_id, event_type, amount, due_date, channel, note, ref_id, status, created_by)
       VALUES (?,?,?,?,?,?,?,?,?)",
      [(int)$partyId, $type, (float)($opt['amount'] ?? 0), $opt['due_date'] ?? null,
       (string)($opt['channel'] ?? ''), (string)($opt['note'] ?? ''), $opt['ref_id'] ?? null,
       (string)($opt['status'] ?? 'open'), $_SESSION['user_id'] ?? null]);
    return insert_id();
}

/** Promises whose day has come or gone.
 *  'due'    - promised for today, worth a nudge
 *  'broken' - the day passed and the money did not arrive */
function coll_promises_due() {
    $t = today();
    $rows = all("SELECT e.*, p.name, p.mobile FROM collection_events e
                 JOIN parties p ON p.id = e.party_id
                 WHERE e.event_type = 'promise' AND e.status = 'open' AND e.due_date IS NOT NULL
                 ORDER BY e.due_date");
    $due = []; $broken = [];
    foreach ($rows as $e) {
        // did the money actually arrive after the promise was made?
        $paid = (float)val("SELECT COALESCE(SUM(amount),0) FROM payments
                            WHERE party_id = ? AND direction = 'in' AND pay_date >= ?",
                           [$e['party_id'], substr($e['created_at'], 0, 10)]);
        $e['paid_since'] = $paid;
        if ($paid + MONEY_EPS >= (float)$e['amount']) { $e['kept'] = true; $due[] = $e; continue; }
        if ($e['due_date'] < $t) $broken[] = $e; else if ($e['due_date'] === $t) $due[] = $e;
    }
    return ['due' => $due, 'broken' => $broken];
}

/** Settle promises that have come good or gone bad. Run by cron; also safe to
 *  call from a screen. Returns [kept, broken]. */
function coll_settle_promises() {
    $t = today();
    $kept = 0; $broken = 0;
    foreach (all("SELECT * FROM collection_events
                  WHERE event_type = 'promise' AND status = 'open' AND due_date IS NOT NULL AND due_date <= ?", [$t]) as $e) {
        $paid = (float)val("SELECT COALESCE(SUM(amount),0) FROM payments
                            WHERE party_id = ? AND direction = 'in' AND pay_date >= ?",
                           [$e['party_id'], substr($e['created_at'], 0, 10)]);
        if ($paid + MONEY_EPS >= (float)$e['amount']) {
            q("UPDATE collection_events SET status = 'done' WHERE id = ?", [$e['id']]);
            coll_log($e['party_id'], 'kept', ['amount' => $e['amount'], 'ref_id' => $e['id'], 'status' => 'done',
                                              'note' => 'વાયદો પળાયો']);
            $kept++;
        } elseif ($e['due_date'] < $t) {
            q("UPDATE collection_events SET status = 'done' WHERE id = ?", [$e['id']]);
            coll_log($e['party_id'], 'broken', ['amount' => $e['amount'], 'ref_id' => $e['id'], 'status' => 'done',
                                                'note' => dmy($e['due_date']) . ' નો વાયદો તૂટ્યો']);
            $broken++;
        }
    }
    return [$kept, $broken];
}

/** The owner's collection summary for the dashboard. */
function coll_summary() {
    $t = today();
    $q = coll_queue(500, true);
    $out = ['outstanding' => 0.0, 'overdue' => 0.0, 'critical' => 0, 'high' => 0,
            'customers' => count($q), 'promised_today' => 0.0, 'promises_open' => 0,
            'broken' => 0, 'collected_today' => 0.0, 'contactable' => 0];
    foreach ($q as $c) {
        $out['outstanding'] += $c['outstanding'];
        $out['overdue'] += $c['overdue'];
        if ($c['priority']['level'] === 'critical') $out['critical']++;
        if ($c['priority']['level'] === 'high') $out['high']++;
        if ($c['can_remind']['ok']) $out['contactable']++;
        if (!empty($c['promise_open'])) {
            $out['promises_open']++;
            if ($c['promise_open']['due_date'] === $t) $out['promised_today'] += (float)$c['promise_open']['amount'];
        }
    }
    $out['broken'] = (int)val("SELECT COUNT(*) FROM collection_events WHERE event_type = 'broken'
                               AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
    $out['collected_today'] = (float)val("SELECT COALESCE(SUM(amount),0) FROM payments
                                          WHERE direction = 'in' AND mode NOT IN ('contra','discount') AND pay_date = ?", [$t]);
    foreach (['outstanding', 'overdue', 'promised_today', 'collected_today'] as $k) $out[$k] = round($out[$k], 2);
    return $out;
}

// ------------------------------------------------ 360 supporting aggregates -

/** What this customer likes buying: top categories, brands and products.
 *  Three grouped queries, no per-row lookups. */
function cust_favourites($partyId, $limit = 5) {
    $p = [(int)$partyId];
    return [
        'categories' => all("SELECT COALESCE(c.name,'(કોઈ કેટેગરી નહીં)') name, c.id,
                                    SUM(si.total) amount, SUM(si.qty) qty
                             FROM sale_items si JOIN sales s ON s.id = si.sale_id JOIN items i ON i.id = si.item_id
                             LEFT JOIN categories c ON c.id = i.category_id
                             WHERE s.party_id = ? AND s.is_cancelled = 0
                             GROUP BY i.category_id ORDER BY amount DESC LIMIT $limit", $p),
        'brands' => all("SELECT COALESCE(NULLIF(i.brand,''),'(કોઈ બ્રાન્ડ નહીં)') name, SUM(si.total) amount
                         FROM sale_items si JOIN sales s ON s.id = si.sale_id JOIN items i ON i.id = si.item_id
                         WHERE s.party_id = ? AND s.is_cancelled = 0
                         GROUP BY i.brand ORDER BY amount DESC LIMIT $limit", $p),
        'products' => all("SELECT i.id, i.name, SUM(si.qty) qty, SUM(si.total) amount, MAX(s.sale_date) last_buy
                           FROM sale_items si JOIN sales s ON s.id = si.sale_id JOIN items i ON i.id = si.item_id
                           WHERE s.party_id = ? AND s.is_cancelled = 0
                           GROUP BY si.item_id ORDER BY amount DESC LIMIT $limit", $p),
    ];
}

/** Service history counts, each gated by the permission that owns that module. */
function cust_service($partyId) {
    $p = [(int)$partyId];
    $out = ['repairs' => 0, 'repairs_open' => 0, 'last_repair' => null,
            'warranty' => 0, 'warranty_open' => 0, 'amc' => 0, 'amc_active' => 0, 'amc_next' => null];
    if (can('repairs.view')) {
        $r = row("SELECT COUNT(*) n, SUM(status NOT IN ('delivered','returned_unrepaired')) open, MAX(received_date) last
                  FROM repairs WHERE party_id = ?", $p);
        $out['repairs'] = (int)$r['n']; $out['repairs_open'] = (int)$r['open']; $out['last_repair'] = $r['last'];
    }
    if (can('warranty.view')) {
        $w = row("SELECT COUNT(*) n, SUM(status NOT IN ('delivered','rejected')) open FROM warranty_claims WHERE party_id = ?", $p);
        $out['warranty'] = (int)$w['n']; $out['warranty_open'] = (int)$w['open'];
    }
    if (can('amc.view')) {
        $a = row("SELECT COUNT(*) n, SUM(status = 'active') act, MIN(CASE WHEN status='active' THEN next_bill_date END) nxt
                  FROM amc_contracts WHERE party_id = ?", $p);
        $out['amc'] = (int)$a['n']; $out['amc_active'] = (int)$a['act']; $out['amc_next'] = $a['nxt'];
    }
    return $out;
}

/** How this customer engages with the shop outside of buying. */
function cust_engagement($partyId) {
    $p = row('SELECT mobile FROM parties WHERE id = ?', [(int)$partyId]);
    $mob = $p['mobile'] ?? '';
    $out = ['quotes' => 0, 'quotes_open' => 0, 'orders' => 0, 'reviews' => 0,
            'loyalty' => 0, 'wa_msgs' => 0, 'wa_last' => null];
    if (can('estimates.view')) {
        $e = row("SELECT COUNT(*) n, SUM(status='open') o FROM estimates WHERE party_id = ?", [(int)$partyId]);
        $out['quotes'] = (int)$e['n']; $out['quotes_open'] = (int)$e['o'];
    }
    if ($mob) {
        if (can('weborders.view'))
            $out['orders'] = (int)val("SELECT COUNT(*) FROM web_orders WHERE mobile = ?", [$mob]);
        $out['reviews'] = (int)val("SELECT COUNT(*) FROM product_reviews WHERE mobile = ?", [$mob]);
        $wa = row("SELECT COUNT(*) n, MAX(created_at) last FROM wa_chats WHERE mobile LIKE ?", ['%' . preg_replace('/\D/', '', $mob)]);
        $out['wa_msgs'] = (int)$wa['n']; $out['wa_last'] = $wa['last'];
    }
    try { $out['loyalty'] = (int)val("SELECT COALESCE(SUM(points),0) FROM loyalty_ledger WHERE party_id = ?", [(int)$partyId]); }
    catch (Exception $e) { /* loyalty not in use */ }
    return $out;
}

/** The collection follow-up trail for one customer, newest first - so staff
 *  can see at a glance that somebody already rang this person yesterday. */
function cust_events($partyId, $limit = 30) {
    return all("SELECT e.*, u.name staff FROM collection_events e
                LEFT JOIN users u ON u.id = e.created_by
                WHERE e.party_id = ? ORDER BY e.id DESC LIMIT " . (int)$limit, [(int)$partyId]);
}
