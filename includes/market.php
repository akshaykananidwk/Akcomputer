<?php
// ============================================================================
//  MARKET INTELLIGENCE — what the shop's own books say about its market
// ============================================================================
//  Start with what this file will NOT do, because it decides everything else.
//
//  This shop has no feed of competitor prices, no industry index, no market
//  share figure, and no way to see a sale it never made. Any screen claiming
//  to know those things would be making them up, and a made-up number that
//  looks authoritative is worse than no number - the owner would act on it.
//  So there is nothing here about "the market rate", "our share" or "the
//  competition". The screen says so out loud.
//
//  What the books CAN answer honestly, and what this file computes:
//
//    · Which categories and brands are growing or shrinking - measured a year
//      apart, not against last month, so a festival does not read as growth.
//    · Whether the shop is being squeezed: what it PAYS for a thing against
//      what it actually GETS for that same thing, both moving over time.
//    · What discounting really costs, per month and per category.
//    · Which products are taking off and which have quietly died.
//    · Season: only where there are two full years to compare, and labelled
//      with how many years it rests on.
//    · Where customers come from - only when the city field is actually
//      filled in; an empty answer is reported as missing data, not as zero.
//    · Quotations that never became bills - the closest thing to a lost sale
//      that this shop's data can honestly show.
//
//  Every figure traces back to rows a person can open. Nothing is predicted,
//  nothing is scored by a model, and no external service is consulted.
// ============================================================================

function mk_rules() {
    // NOT cached in a static - the same hazard that made an AI kill switch
    // ineffective in Phase 6: a screen that saves a setting and reads it back
    // in the same request must see the new value.
    return [
        'window'      => 365,   // a year against the year before it
        'squeeze_win' => 180,   // half a year for price movement, which moves faster
        'min_amount'  => (float)setting('market_min_amount', 1000),  // ignore rounding-level groups
        'min_bills'   => 2,     // ...and one-off flukes
        'move_pct'    => 10.0,  // below this a change is called steady
        'new_days'    => 180,   // "new product" means first sold within this
        'fade_days'   => 120,   // "gone quiet" means nothing sold for this long
        'top'         => 25,
    ];
}

/** How much history there actually is, in months. Every seasonal or
 *  year-on-year claim below is gated on this. */
function mk_history() {
    $r = row("SELECT MIN(sale_date) first_sale, MAX(sale_date) last_sale, COUNT(*) bills
              FROM sales WHERE is_cancelled = 0");
    $first = $r['first_sale'] ?? null;
    $months = $first ? max(0, (int)floor((strtotime(today()) - strtotime($first)) / 86400 / 30.44)) : 0;
    return [
        'first_sale' => $first, 'last_sale' => $r['last_sale'] ?? null,
        'bills' => (int)($r['bills'] ?? 0), 'months' => $months,
        'years' => round($months / 12, 1),
        'can_compare_year' => $months >= 24,   // a year against a full year before it
        'can_season' => $months >= 24,         // two of each month to compare
    ];
}

/** The four dates that bound "this year" and "the year before".
 *
 *  Both windows are the SAME number of days, and they touch without
 *  overlapping. The obvious version (-365 vs -730) is wrong by a day: counting
 *  inclusively, today back to -365 is 366 days while -730 back to -366 is 365,
 *  so the current period got a free day and every comparison leaned very
 *  slightly towards "growing". */
function mk_windows($days = null) {
    $days = (int)($days ?: mk_rules()['window']);
    return [
        'now_from'  => date('Y-m-d', strtotime("-{$days} days")),
        'now_to'    => today(),
        'prev_from' => date('Y-m-d', strtotime('-' . ($days * 2 + 1) . ' days')),
        'prev_to'   => date('Y-m-d', strtotime('-' . ($days + 1) . ' days')),
    ];
}

/** Percentage change, with the honest answers for the awkward cases. */
function mk_change($now, $prev) {
    $now = (float)$now; $prev = (float)$prev;
    if ($prev <= MONEY_EPS && $now <= MONEY_EPS) return ['pct' => 0.0, 'dir' => 'flat', 'label' => 'કંઈ નહીં'];
    if ($prev <= MONEY_EPS) return ['pct' => null, 'dir' => 'up', 'label' => 'નવું'];
    if ($now <= MONEY_EPS) return ['pct' => -100.0, 'dir' => 'down', 'label' => 'બંધ થઈ ગયું'];
    $pct = round(($now - $prev) / $prev * 100, 1);
    $move = mk_rules()['move_pct'];
    $dir = $pct >= $move ? 'up' : ($pct <= -$move ? 'down' : 'flat');
    return ['pct' => $pct, 'dir' => $dir, 'label' => ($pct > 0 ? '+' : '') . $pct . '%'];
}

/** Which categories (or brands) are growing and which are shrinking, this year
 *  against the year before. Year-on-year on purpose: comparing with last
 *  quarter would call every Diwali a boom and every monsoon a collapse. */
function mk_momentum($by = 'category') {
    $r = mk_rules();
    $w = mk_windows();
    $grp = $by === 'brand'
        ? ["COALESCE(NULLIF(i.brand,''),'(બ્રાન્ડ નથી)')", 'i.brand', 'NULL']
        : ["COALESCE(c.name,'(કેટેગરી નથી)')", 'i.category_id', 'c.id'];
    $rows = all("SELECT {$grp[0]} g, {$grp[2]} gid,
                        SUM(IF(s.sale_date >= ?, si.total, 0)) now_amt,
                        SUM(IF(s.sale_date <= ?, si.total, 0)) prev_amt,
                        SUM(IF(s.sale_date >= ?, si.qty, 0)) now_qty,
                        SUM(IF(s.sale_date <= ?, si.qty, 0)) prev_qty,
                        COUNT(DISTINCT IF(s.sale_date >= ?, s.id, NULL)) now_bills,
                        COUNT(DISTINCT IF(s.sale_date <= ?, s.id, NULL)) prev_bills
                 FROM sale_items si
                 JOIN sales s ON s.id = si.sale_id AND s.is_cancelled = 0
                 JOIN items i ON i.id = si.item_id
                 LEFT JOIN categories c ON c.id = i.category_id
                 WHERE s.sale_date >= ? AND s.sale_date <= ?
                 GROUP BY {$grp[1]}",
        [$w['now_from'], $w['prev_to'], $w['now_from'], $w['prev_to'],
         $w['now_from'], $w['prev_to'], $w['prev_from'], $w['now_to']]);

    $out = [];
    foreach ($rows as $x) {
        $now = money_r($x['now_amt']); $prev = money_r($x['prev_amt']);
        // a group too small to matter is noise, not a trend
        if (max($now, $prev) < $r['min_amount'] && ((int)$x['now_bills'] + (int)$x['prev_bills']) < $r['min_bills']) continue;
        $ch = mk_change($now, $prev);
        $out[] = [
            'name' => $x['g'], 'id' => $x['gid'] === null ? 0 : (int)$x['gid'],
            'now' => $now, 'prev' => $prev, 'diff' => money_r($now - $prev),
            'now_qty' => (float)$x['now_qty'], 'prev_qty' => (float)$x['prev_qty'],
            'now_bills' => (int)$x['now_bills'], 'prev_bills' => (int)$x['prev_bills'],
        ] + $ch;
    }
    usort($out, fn($a, $b) => $b['diff'] <=> $a['diff']);
    return $out;
}

/** The squeeze: what the shop pays for a thing against what it gets for it.
 *
 *  Both halves are real money out of real documents - the purchase rate the
 *  shop was actually charged, and the price actually realised on the bill
 *  (which is the line total after any line discount, not the list price). An
 *  item only appears when it was BOTH bought and sold in both windows; there
 *  is no way to compare otherwise, and the count of skipped items is reported
 *  rather than hidden. */
function mk_squeeze($limit = null) {
    $r = mk_rules();
    $limit = (int)($limit ?: $r['top']);
    $w = mk_windows($r['squeeze_win']);

    $sold = all("SELECT si.item_id,
                        SUM(IF(s.sale_date >= ?, si.total, 0)) n_amt, SUM(IF(s.sale_date >= ?, si.qty, 0)) n_qty,
                        SUM(IF(s.sale_date <= ?, si.total, 0)) p_amt, SUM(IF(s.sale_date <= ?, si.qty, 0)) p_qty
                 FROM sale_items si JOIN sales s ON s.id = si.sale_id AND s.is_cancelled = 0
                 WHERE s.sale_date >= ? AND s.sale_date <= ? AND si.qty > 0
                 GROUP BY si.item_id",
        [$w['now_from'], $w['now_from'], $w['prev_to'], $w['prev_to'], $w['prev_from'], $w['now_to']]);

    $bought = all("SELECT pi.item_id,
                          SUM(IF(p.purchase_date >= ?, pi.total, 0)) n_amt, SUM(IF(p.purchase_date >= ?, pi.qty, 0)) n_qty,
                          SUM(IF(p.purchase_date <= ?, pi.total, 0)) p_amt, SUM(IF(p.purchase_date <= ?, pi.qty, 0)) p_qty
                   FROM purchase_items pi JOIN purchases p ON p.id = pi.purchase_id AND p.is_cancelled = 0
                   WHERE p.purchase_date >= ? AND p.purchase_date <= ? AND pi.qty > 0
                   GROUP BY pi.item_id",
        [$w['now_from'], $w['now_from'], $w['prev_to'], $w['prev_to'], $w['prev_from'], $w['now_to']]);
    $buy = [];
    foreach ($bought as $b) $buy[(int)$b['item_id']] = $b;

    $rows = []; $skipped = 0;
    foreach ($sold as $s) {
        $id = (int)$s['item_id'];
        $b = $buy[$id] ?? null;
        if (!$b || $s['n_qty'] <= 0 || $s['p_qty'] <= 0 || $b['n_qty'] <= 0 || $b['p_qty'] <= 0) { $skipped++; continue; }
        $gotNow = money_r($s['n_amt'] / $s['n_qty']);   $gotPrev = money_r($s['p_amt'] / $s['p_qty']);
        $payNow = money_r($b['n_amt'] / $b['n_qty']);   $payPrev = money_r($b['p_amt'] / $b['p_qty']);
        if ($gotPrev <= 0 || $payPrev <= 0) { $skipped++; continue; }
        $gotCh = round(($gotNow - $gotPrev) / $gotPrev * 100, 1);
        $payCh = round(($payNow - $payPrev) / $payPrev * 100, 1);
        $rows[] = [
            'item_id' => $id, 'got_now' => $gotNow, 'got_prev' => $gotPrev, 'got_pct' => $gotCh,
            'pay_now' => $payNow, 'pay_prev' => $payPrev, 'pay_pct' => $payCh,
            'squeeze' => round($gotCh - $payCh, 1),      // negative = costs rose faster than prices
            'margin_now' => money_r($gotNow - $payNow), 'margin_prev' => money_r($gotPrev - $payPrev),
            'qty' => (float)$s['n_qty'],
        ];
    }
    usort($rows, fn($a, $b) => $a['squeeze'] <=> $b['squeeze']);   // worst squeeze first
    $rows = array_slice($rows, 0, $limit);
    if ($rows) {
        $ids = implode(',', array_map(fn($x) => (int)$x['item_id'], $rows));
        $names = [];
        foreach (all("SELECT id, name, brand FROM items WHERE id IN ($ids)") as $i) $names[(int)$i['id']] = $i;
        foreach ($rows as &$x) {
            $x['name'] = $names[$x['item_id']]['name'] ?? '?';
            $x['brand'] = $names[$x['item_id']]['brand'] ?? '';
        }
        unset($x);
    }
    return ['rows' => $rows, 'skipped' => $skipped, 'window' => $r['squeeze_win'], 'dates' => $w];
}

/** What discounting actually costs, month by month.
 *
 *  Everything given away is counted, in the same places the bill screen puts
 *  it: the per-line discount, the bill-level discount and loyalty points spent
 *  as money. "Gross" is what the bill would have been at list. */
function mk_discount($months = 13) {
    $months = max(1, (int)$months);
    $from = date('Y-m-01', strtotime('-' . ($months - 1) . ' months'));
    $lines = all("SELECT DATE_FORMAT(s.sale_date, '%Y-%m') m,
                         COALESCE(SUM(si.line_disc),0) line_disc, COALESCE(SUM(si.total),0) net
                  FROM sale_items si JOIN sales s ON s.id = si.sale_id AND s.is_cancelled = 0
                  WHERE s.sale_date >= ? GROUP BY m", [$from]);
    $bills = all("SELECT DATE_FORMAT(sale_date, '%Y-%m') m, COUNT(*) bills,
                         COALESCE(SUM(discount),0) bill_disc, COALESCE(SUM(loyalty_discount),0) loy,
                         COALESCE(SUM(subtotal),0) subtotal
                  FROM sales WHERE is_cancelled = 0 AND sale_date >= ? GROUP BY m", [$from]);
    $byM = [];
    foreach ($lines as $l) $byM[$l['m']] = ['line' => (float)$l['line_disc'], 'net' => (float)$l['net']];
    $out = [];
    foreach ($bills as $b) {
        $m = $b['m'];
        $line = $byM[$m]['line'] ?? 0.0;
        $given = money_r($line + (float)$b['bill_disc'] + (float)$b['loy']);
        $gross = money_r((float)$b['subtotal'] + $line);
        $out[] = [
            'month' => $m, 'bills' => (int)$b['bills'], 'gross' => $gross, 'given' => $given,
            'line_disc' => money_r($line), 'bill_disc' => money_r($b['bill_disc']), 'loyalty' => money_r($b['loy']),
            'pct' => $gross > 0 ? round($given / $gross * 100, 2) : 0.0,
        ];
    }
    usort($out, fn($a, $b) => strcmp($a['month'], $b['month']));
    return $out;
}

/** Which categories the discounting is concentrated in, over the last year. */
function mk_discount_by_category($limit = null) {
    $limit = (int)($limit ?: mk_rules()['top']);
    $from = date('Y-m-d', strtotime('-365 days'));
    // Only the LINE discount can be attributed to a category honestly; a
    // bill-level discount belongs to the whole bill, and splitting it across
    // categories would be an invented number.
    return all("SELECT COALESCE(c.name,'(કેટેગરી નથી)') name, c.id,
                       COALESCE(SUM(si.line_disc),0) given, COALESCE(SUM(si.total),0) net,
                       COUNT(DISTINCT s.id) bills
                FROM sale_items si
                JOIN sales s ON s.id = si.sale_id AND s.is_cancelled = 0
                JOIN items i ON i.id = si.item_id
                LEFT JOIN categories c ON c.id = i.category_id
                WHERE s.sale_date >= ? AND si.line_disc > 0
                GROUP BY c.id ORDER BY given DESC LIMIT $limit", [$from]);
}

/** Products taking off, and products that have quietly stopped selling.
 *  Both are plain facts about dates, not predictions. */
function mk_lifecycle() {
    $r = mk_rules();
    $newFrom = date('Y-m-d', strtotime('-' . $r['new_days'] . ' days'));
    $fadeBefore = date('Y-m-d', strtotime('-' . $r['fade_days'] . ' days'));
    $top = $r['top'];

    $rising = all("SELECT i.id, i.name, i.brand, MIN(s.sale_date) first_sold, MAX(s.sale_date) last_sold,
                          SUM(si.qty) qty, SUM(si.total) amt, COUNT(DISTINCT s.id) bills
                   FROM sale_items si JOIN sales s ON s.id = si.sale_id AND s.is_cancelled = 0
                   JOIN items i ON i.id = si.item_id
                   GROUP BY si.item_id
                   HAVING first_sold >= ? AND bills >= ?
                   ORDER BY amt DESC LIMIT $top", [$newFrom, $r['min_bills']]);

    $fading = all("SELECT i.id, i.name, i.brand, MAX(s.sale_date) last_sold, SUM(si.total) amt,
                          COUNT(DISTINCT s.id) bills,
                          DATEDIFF(CURDATE(), MAX(s.sale_date)) quiet_days,
                          COALESCE((SELECT SUM(st.qty) FROM stock st WHERE st.item_id = i.id),0) stock
                   FROM sale_items si JOIN sales s ON s.id = si.sale_id AND s.is_cancelled = 0
                   JOIN items i ON i.id = si.item_id
                   WHERE i.is_active = 1
                   GROUP BY si.item_id
                   HAVING last_sold < ? AND bills >= ?
                   ORDER BY amt DESC LIMIT $top", [$fadeBefore, $r['min_bills']]);

    return ['rising' => $rising, 'fading' => $fading, 'new_days' => $r['new_days'], 'fade_days' => $r['fade_days']];
}

/** Which months of the year are strong and which are weak.
 *
 *  Refused outright with less than two years of history: one December is an
 *  anecdote, not a season. The answer carries the number of years it rests on
 *  so the reader can weigh it. */
function mk_season() {
    $h = mk_history();
    if (!$h['can_season']) return ['ok' => false, 'months' => [], 'years' => $h['years'],
                                   'why' => 'સીઝન કહેવા માટે ઓછામાં ઓછા બે વર્ષનો ઇતિહાસ જોઈએ. અત્યારે ' . $h['years'] . ' વર્ષનો છે.'];
    // Only WHOLE months count. The shop's first month and its current month are
    // both partial, and dividing a half-month's takings by a full year made
    // that month look like a slump - August read as index 68 purely because
    // trading began on the 13th. So the window starts on the 1st of the month
    // after the first sale and ends on the last day of the month before this one.
    $from = month_start(-1, $h['first_sale']);   // 1st of the month AFTER the first sale
    $to = month_end(1);   // last whole month; month_end() survives the 31st
    if ($from > $to) return ['ok' => false, 'months' => [], 'years' => $h['years'],
                             'why' => 'આખા મહિનાનો પૂરતો ઇતિહાસ નથી.'];
    $rows = all("SELECT MONTH(sale_date) mno, COUNT(DISTINCT YEAR(sale_date)) years,
                        SUM(total) amt, COUNT(*) bills
                 FROM sales WHERE is_cancelled = 0 AND sale_date >= ? AND sale_date <= ?
                 GROUP BY mno ORDER BY mno", [$from, $to]);
    $perYear = []; $totalAvg = 0.0;
    foreach ($rows as $x) {
        $y = max(1, (int)$x['years']);
        $avg = money_r((float)$x['amt'] / $y);
        $perYear[(int)$x['mno']] = ['avg' => $avg, 'years' => $y, 'bills' => (int)$x['bills']];
        $totalAvg += $avg;
    }
    $mean = count($perYear) ? $totalAvg / count($perYear) : 0.0;
    $names = ['', 'જાન્યુ', 'ફેબ્રુ', 'માર્ચ', 'એપ્રિલ', 'મે', 'જૂન', 'જુલાઈ', 'ઓગસ્ટ', 'સપ્ટે', 'ઓક્ટો', 'નવે', 'ડિસે'];
    $out = [];
    for ($m = 1; $m <= 12; $m++) {
        $d = $perYear[$m] ?? ['avg' => 0.0, 'years' => 0, 'bills' => 0];
        $out[] = [
            'month' => $m, 'name' => $names[$m], 'avg' => $d['avg'], 'years' => $d['years'], 'bills' => $d['bills'],
            'index' => $mean > 0 ? round($d['avg'] / $mean * 100) : 0,
        ];
    }
    return ['ok' => true, 'months' => $out, 'years' => $h['years'], 'mean' => money_r($mean),
            'from' => $from, 'to' => $to, 'why' => ''];
}

/** Where the customers are. Reported as MISSING when the city field is empty,
 *  never as a shop with no geography. */
function mk_geography($limit = null) {
    $limit = (int)($limit ?: mk_rules()['top']);
    $from = date('Y-m-d', strtotime('-365 days'));
    $filled = (int)val("SELECT COUNT(*) FROM parties WHERE city IS NOT NULL AND city <> ''");
    $total = (int)val('SELECT COUNT(*) FROM parties');
    if ($filled === 0) return ['ok' => false, 'rows' => [], 'filled' => 0, 'total' => $total,
                               'why' => 'ગ્રાહકોના શહેરનું ખાનું ભરેલું નથી, એટલે વિસ્તાર પ્રમાણે કંઈ કહી શકાય એમ નથી.'];
    $rows = all("SELECT p.city, COUNT(DISTINCT p.id) customers, COUNT(DISTINCT s.id) bills,
                        COALESCE(SUM(s.total),0) amt
                 FROM parties p JOIN sales s ON s.party_id = p.id AND s.is_cancelled = 0 AND s.sale_date >= ?
                 WHERE p.city IS NOT NULL AND p.city <> ''
                 GROUP BY p.city ORDER BY amt DESC LIMIT $limit", [$from]);
    return ['ok' => true, 'rows' => $rows, 'filled' => $filled, 'total' => $total, 'why' => ''];
}

/** Quotations that never became bills - the only "lost sale" this shop's data
 *  can honestly show. It does not say WHY one was lost; the books do not know,
 *  and guessing would be fiction. */
function mk_lost_quotes($limit = null) {
    $limit = (int)($limit ?: mk_rules()['top']);
    $total = (int)val('SELECT COUNT(*) FROM estimates');
    if ($total === 0) return ['ok' => false, 'rows' => [], 'stats' => [],
                              'why' => 'હજી કોઈ ક્વોટેશન બનાવ્યું નથી, એટલે ગુમાવેલા સોદા ગણી શકાય એમ નથી.'];
    $stats = row("SELECT COUNT(*) total,
                         SUM(status = 'converted') won, SUM(status IN ('rejected','cancelled')) lost,
                         SUM(status IN ('open','accepted')) pending,
                         COALESCE(SUM(IF(status = 'converted', total, 0)),0) won_amt,
                         COALESCE(SUM(IF(status IN ('rejected','cancelled'), total, 0)),0) lost_amt,
                         COALESCE(SUM(IF(status IN ('open','accepted'), total, 0)),0) pending_amt
                  FROM estimates WHERE estimate_date >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)");
    $closed = (int)$stats['won'] + (int)$stats['lost'];
    $stats['win_pct'] = $closed > 0 ? round((int)$stats['won'] / $closed * 100, 1) : null;
    $rows = all("SELECT e.id, e.estimate_no, e.estimate_date, e.customer_name, e.total, e.status, e.party_id,
                        DATEDIFF(CURDATE(), e.estimate_date) age
                 FROM estimates e
                 WHERE e.status IN ('open','accepted','rejected')
                   AND e.estimate_date >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)
                 ORDER BY e.total DESC LIMIT $limit");
    return ['ok' => true, 'rows' => $rows, 'stats' => $stats, 'why' => ''];
}

/** Which items sit on lost quotations most often - "what do we keep quoting
 *  and not winning". */
function mk_lost_items($limit = 15) {
    $limit = (int)$limit;
    if ((int)val('SELECT COUNT(*) FROM estimates') === 0) return [];
    return all("SELECT i.id, i.name, COUNT(DISTINCT e.id) quotes, SUM(ei.total) amt
                FROM estimate_items ei
                JOIN estimates e ON e.id = ei.estimate_id
                JOIN items i ON i.id = ei.item_id
                WHERE e.status IN ('open','rejected','cancelled')
                  AND e.estimate_date >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)
                GROUP BY ei.item_id ORDER BY amt DESC LIMIT $limit");
}

/** The headline numbers, cached because the screen and the dashboard both want
 *  them and neither should pay for the other's visit. */
function mk_summary() {
    // Every number that goes through the cache is forced to float. A JSON
    // round trip turns 2.0 into 2 and 0.0 into 0, so the cached read came back
    // a different TYPE from the fresh one - the same trap the dashboard cache
    // fell into in Phase 3. The test asserts cold === warm, which only holds
    // if the types are pinned here.
    $val = dash_cache('market_summary', 900, function () {
        $r = mk_rules();
        $h = mk_history();
        $cats = mk_momentum('category');
        $up = array_values(array_filter($cats, fn($x) => $x['dir'] === 'up'));
        $down = array_values(array_filter($cats, fn($x) => $x['dir'] === 'down'));
        $disc = mk_discount(13);
        $lastFull = count($disc) >= 2 ? $disc[count($disc) - 2] : ($disc[0] ?? null);
        // the same two windows the momentum table uses - one definition, so
        // the headline and the detail can never disagree
        $w = mk_windows();
        $year = row("SELECT COALESCE(SUM(total),0) amt FROM sales
                     WHERE is_cancelled = 0 AND sale_date >= ? AND sale_date <= ?", [$w['now_from'], $w['now_to']]);
        $prevYear = row("SELECT COALESCE(SUM(total),0) amt FROM sales
                         WHERE is_cancelled = 0 AND sale_date >= ? AND sale_date <= ?", [$w['prev_from'], $w['prev_to']]);
        return [
            'months' => $h['months'], 'years' => $h['years'], 'can_compare_year' => $h['can_compare_year'],
            'sales_year' => money_r($year['amt']), 'sales_prev_year' => money_r($prevYear['amt']),
            'change' => mk_change($year['amt'], $prevYear['amt']),
            'growing' => count($up), 'shrinking' => count($down), 'groups' => count($cats),
            'top_up' => $up[0]['name'] ?? '', 'top_down' => $down ? end($down)['name'] : '',
            'discount_pct' => $lastFull['pct'] ?? 0.0,
            'discount_given' => $lastFull['given'] ?? 0.0,
            'discount_month' => $lastFull['month'] ?? '',
        ];
    });
    foreach (['years', 'sales_year', 'sales_prev_year', 'discount_pct', 'discount_given'] as $k) $val[$k] = (float)$val[$k];
    // the nested percentage too - round() returns a float, and JSON writes
    // 100.0 as 100, which reads back as an int
    if ($val['change']['pct'] !== null) $val['change']['pct'] = (float)$val['change']['pct'];
    return $val;
}
