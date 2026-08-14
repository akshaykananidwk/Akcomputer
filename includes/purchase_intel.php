<?php
// ============================================================================
//  SMART STOCK + PURCHASE INTELLIGENCE
// ============================================================================
//  "What is about to run out, how much should I order, who should I buy it
//   from, what should it cost, and what is the money doing that it shouldn't?"
//
//  Built ON TOP of what already works rather than beside it: demand_prediction()
//  and weighted_forecast() in ai_insights.php already turn six months of sales
//  into a next-month forecast and are already used by the Purchase
//  Recommendations report. This file keeps that forecast and adds the parts it
//  was missing - lead time, safety stock, what is already on order, which
//  supplier to ask, and what they last charged.
//
//  Costs come from real purchase history where there is any, and fall back to
//  items.purchase_price only when there is none - and say which was used, so a
//  suggested order value is never quietly built on a stale catalogue price.
//
//  Two honesty rules run through the whole file:
//
//   1. Lead time is NOT measured. purchases records a bill date and no
//      delivery date, so how long a supplier takes cannot be derived. It is
//      entered by the shop (parties.lead_days, v56) with a global default.
//
//   2. Lost sales are an ESTIMATE and are labelled as one everywhere. Nobody
//      records the customer who asked for something that was not there, so it
//      is reconstructed from how long an item sat at zero stock multiplied by
//      how fast it normally sells. That is a reasonable indication of what
//      stocking out costs, not a measurement of it.
// ============================================================================

/** Reorder settings, all owner-editable. */
function pi_rules() {
    static $r = null;
    if ($r !== null) return $r;
    return $r = [
        'lead_days'     => max(0, (int)setting('purchase_lead_days', 7)),    // default supplier delivery time
        'safety_days'   => max(0, (int)setting('purchase_safety_days', 7)),  // buffer on top of lead time
        'cover_days'    => max(7, (int)setting('purchase_cover_days', 30)),  // how long one order should last
        'target_margin' => (float)setting('target_margin_pct', 10),
        'history_months' => max(2, (int)setting('purchase_history_months', 6)),
    ];
}

// ------------------------------------------------------------ daily demand -

/** How many units a day each item sells, from the last N days of real bills.
 *  One grouped query for every item. Used for "days until it runs out" and
 *  for the lost-sales estimate. */
function pi_daily_rate($days = 90) {
    $out = [];
    foreach (all("SELECT si.item_id, SUM(si.qty) q
                  FROM sale_items si JOIN sales s ON s.id = si.sale_id
                  WHERE s.is_cancelled = 0 AND s.sale_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
                  GROUP BY si.item_id", [$days]) as $r)
        $out[(int)$r['item_id']] = (float)$r['q'] / $days;
    return $out;
}

/** Units already ordered but not yet received. The shop books a purchase when
 *  the goods arrive, so there is no "on order" state to read - this returns an
 *  empty map and exists as the single place to change if ordering is ever
 *  recorded separately from receiving. Keeping the concept visible is worth
 *  more than pretending the number is always zero by leaving it out. */
function pi_on_order() { return []; }

// ---------------------------------------------------------- reorder engine -

/** What to buy, how much, and how urgently.
 *
 *  quantity  = cover_days of demand + safety stock, minus what is on hand
 *  urgency   = how many days of stock are left against how long delivery takes
 *
 *  An item is only suggested if it will actually run out inside the window it
 *  takes to restock it - which is the difference between this and a plain
 *  minimum-stock line. Something with 3 units left that sells one a month is
 *  not urgent; something with 20 left that sells five a day is.
 */
function pi_reorder($limit = 100) {
    $r = pi_rules();
    $rate = pi_daily_rate(90);
    $onOrder = pi_on_order();

    // forecast from the existing, already-trusted engine
    $forecast = [];
    foreach (demand_prediction($r['history_months']) as $d) $forecast[(int)$d['item_id']] = $d;

    $items = all("SELECT i.id, i.name, i.unit, i.min_stock, i.purchase_price, i.selling_price,
                         i.tax_rate, i.brand, COALESCE(SUM(st.qty),0) stock
                  FROM items i LEFT JOIN stock st ON st.item_id = i.id
                  WHERE i.is_active = 1 AND i.item_type <> 'service'
                  GROUP BY i.id");
    $lastCost = pi_last_costs();
    $bestSup = pi_best_supplier_map();

    $out = [];
    foreach ($items as $it) {
        $id = (int)$it['id'];
        $stock = (float)$it['stock'];
        $perDay = $rate[$id] ?? 0.0;
        $fc = $forecast[$id] ?? null;
        $onOrd = (float)($onOrder[$id] ?? 0);

        // days of cover left at the current rate
        $daysLeft = $perDay > 0.0001 ? (int)floor(($stock + $onOrd) / $perDay) : null;

        // the trigger: will it run out before a new order could arrive?
        $leadFor = $bestSup[$id]['lead_days'] ?? $r['lead_days'];
        $trigger = $leadFor + $r['safety_days'];
        $needByDemand = $daysLeft !== null && $daysLeft <= $trigger;
        $needByMin = $it['min_stock'] > 0 && $stock < (float)$it['min_stock'];
        if (!$needByDemand && !$needByMin) continue;
        if ($perDay <= 0.0001 && !$needByMin) continue; // nothing sells it, do not push stock in

        // How much: enough to cover the order window plus the buffer - but
        // never less than the minimum the owner set by hand. Without that
        // floor, a slow-moving item sitting below its minimum computed a
        // demand target smaller than the stock already present, came out at
        // zero and was dropped from the list entirely - so min_stock silently
        // stopped working as a trigger at all.
        $target = $perDay > 0.0001 ? ceil($perDay * ($r['cover_days'] + $trigger)) : 0;
        $target = max($target, (float)$it['min_stock']);
        if ($target <= 0) $target = 1;
        $qty = max(0, (int)ceil($target - $stock - $onOrd));
        if ($qty <= 0) continue;

        $cost = $lastCost[$id] ?? null;
        $unitCost = $cost['price'] ?? (float)$it['purchase_price'];
        $out[] = [
            'item_id' => $id, 'name' => $it['name'], 'unit' => $it['unit'], 'brand' => $it['brand'],
            'stock' => $stock, 'per_day' => round($perDay, 3), 'days_left' => $daysLeft,
            'lead_days' => $leadFor, 'trigger_days' => $trigger,
            'forecast' => $fc ? round((float)$fc['forecast'], 1) : null,
            'trend' => $fc['trend'] ?? 'flat',
            'suggest_qty' => $qty,
            'unit_cost' => round($unitCost, 2),
            'cost_source' => $cost ? 'last_purchase' : 'catalogue',
            'cost_date' => $cost['date'] ?? null,
            'est_cost' => round($qty * $unitCost, 2),
            'tax_rate' => (float)$it['tax_rate'],
            'purchase_price' => (float)$it['purchase_price'],
            'supplier' => $bestSup[$id] ?? null,
            'reason' => $needByMin && !$needByDemand ? 'min_stock' : 'demand',
            'out_of_stock' => $stock <= 0.009,
        ];
    }

    // most urgent first: out of stock, then fewest days of cover left
    usort($out, function ($a, $b) {
        if ($a['out_of_stock'] !== $b['out_of_stock']) return $a['out_of_stock'] ? -1 : 1;
        $ad = $a['days_left'] ?? 9999; $bd = $b['days_left'] ?? 9999;
        return $ad <=> $bd ?: $b['est_cost'] <=> $a['est_cost'];
    });
    return array_slice($out, 0, (int)$limit);
}

/** The most recent purchase price for every item, with its date. */
function pi_last_costs() {
    $out = [];
    foreach (all("SELECT pi.item_id, pi.price, p.purchase_date, p.party_id
                  FROM purchase_items pi JOIN purchases p ON p.id = pi.purchase_id
                  WHERE p.is_cancelled = 0
                  ORDER BY pi.item_id, p.purchase_date DESC, pi.id DESC") as $r) {
        $id = (int)$r['item_id'];
        if (isset($out[$id])) continue; // first row per item is the newest
        $out[$id] = ['price' => (float)$r['price'], 'date' => $r['purchase_date'], 'party_id' => (int)$r['party_id']];
    }
    return $out;
}

/** For every item, the supplier worth asking: the one with the lowest recent
 *  price, carrying their name, that price and their stated delivery time.
 *  One query for the whole catalogue. */
function pi_best_supplier_map($months = 12) {
    $rows = all("SELECT pi.item_id, p.party_id, pt.name, pt.lead_days,
                        MIN(pi.price) best_price, MAX(p.purchase_date) last_date, COUNT(*) times
                 FROM purchase_items pi
                 JOIN purchases p ON p.id = pi.purchase_id
                 JOIN parties pt ON pt.id = p.party_id
                 WHERE p.is_cancelled = 0 AND pi.price > 0
                   AND p.purchase_date >= DATE_SUB(CURDATE(), INTERVAL ? MONTH)
                 GROUP BY pi.item_id, p.party_id", [$months]);
    $best = [];
    foreach ($rows as $r) {
        $id = (int)$r['item_id'];
        if (isset($best[$id]) && (float)$r['best_price'] >= $best[$id]['price']) continue;
        $best[$id] = ['party_id' => (int)$r['party_id'], 'name' => $r['name'],
                      'price' => (float)$r['best_price'], 'last_date' => $r['last_date'],
                      'times' => (int)$r['times'],
                      'lead_days' => (int)$r['lead_days'] ?: pi_rules()['lead_days']];
    }
    return $best;
}

// ------------------------------------------------------- supplier analysis -

/** How each supplier is doing: what we spend, how often, whether their prices
 *  are drifting up, and how much they still owe / we still owe them. */
function pi_suppliers($months = 12) {
    $rows = all("SELECT p.party_id, pt.name, pt.mobile, pt.lead_days,
                        COUNT(DISTINCT p.id) bills,
                        COALESCE(SUM(p.total),0) spend,
                        MIN(p.purchase_date) first_buy, MAX(p.purchase_date) last_buy,
                        COUNT(DISTINCT pi.item_id) items
                 FROM purchases p
                 JOIN parties pt ON pt.id = p.party_id
                 LEFT JOIN purchase_items pi ON pi.purchase_id = p.id
                 WHERE p.is_cancelled = 0 AND p.purchase_date >= DATE_SUB(CURDATE(), INTERVAL ? MONTH)
                 GROUP BY p.party_id ORDER BY spend DESC", [$months]);
    if (!$rows) return [];

    // price direction per supplier: compare their newest price for each item
    // against their own older price for that same item
    $drift = [];
    foreach (all("SELECT p.party_id, pi.item_id, pi.price, p.purchase_date
                  FROM purchase_items pi JOIN purchases p ON p.id = pi.purchase_id
                  WHERE p.is_cancelled = 0 AND pi.price > 0
                    AND p.purchase_date >= DATE_SUB(CURDATE(), INTERVAL ? MONTH)
                  ORDER BY p.party_id, pi.item_id, p.purchase_date", [$months]) as $r) {
        $k = (int)$r['party_id'] . ':' . (int)$r['item_id'];
        if (!isset($drift[$k])) $drift[$k] = ['first' => (float)$r['price'], 'last' => (float)$r['price']];
        else $drift[$k]['last'] = (float)$r['price'];
    }
    $up = []; $down = [];
    foreach ($drift as $k => $d) {
        $pid = (int)explode(':', $k)[0];
        if ($d['last'] > $d['first'] * 1.02) $up[$pid] = ($up[$pid] ?? 0) + 1;
        elseif ($d['last'] < $d['first'] * 0.98) $down[$pid] = ($down[$pid] ?? 0) + 1;
    }

    // one ledger sweep for every supplier in the list, not party_balance() per
    // row - that loop was 50 extra queries on this screen alone
    $ids = implode(',', array_map(fn($x) => (int)$x['party_id'], $rows));
    $bals = array_column(all("SELECT p.id, " . party_balance_expr('p') . " bal
                              FROM parties p WHERE p.id IN ($ids)"), 'bal', 'id');

    $t = today();
    $out = [];
    foreach ($rows as $r) {
        $pid = (int)$r['party_id'];
        $bal = (float)($bals[$pid] ?? 0);
        $out[] = [
            'id' => $pid, 'name' => $r['name'], 'mobile' => $r['mobile'],
            'lead_days' => (int)$r['lead_days'],
            'bills' => (int)$r['bills'], 'spend' => (float)$r['spend'], 'items' => (int)$r['items'],
            'first_buy' => $r['first_buy'], 'last_buy' => $r['last_buy'],
            'idle_days' => $r['last_buy'] ? days_between_dates($r['last_buy'], $t) : null,
            'avg_bill' => $r['bills'] ? round((float)$r['spend'] / (int)$r['bills'], 2) : 0.0,
            'prices_up' => $up[$pid] ?? 0,
            'prices_down' => $down[$pid] ?? 0,
            'we_owe' => $bal < -0.009 ? round(-$bal, 2) : 0.0,
        ];
    }
    return $out;
}

/** Every supplier we have bought ONE item from, cheapest first - the answer to
 *  "who should I ring for this?". */
function pi_item_suppliers($itemId, $limit = 10) {
    return all("SELECT p.party_id, pt.name, pt.mobile, pt.lead_days,
                       MIN(pi.price) best_price, MAX(p.purchase_date) last_date,
                       SUM(pi.qty) qty, COUNT(*) times,
                       SUBSTRING_INDEX(GROUP_CONCAT(pi.price ORDER BY p.purchase_date DESC), ',', 1) last_price
                FROM purchase_items pi
                JOIN purchases p ON p.id = pi.purchase_id
                JOIN parties pt ON pt.id = p.party_id
                WHERE pi.item_id = ? AND p.is_cancelled = 0
                GROUP BY p.party_id
                ORDER BY best_price ASC LIMIT " . (int)$limit, [(int)$itemId]);
}

// --------------------------------------------------------- price + margin --

/** Purchase prices that moved UP against the previous price for the same item
 *  from the same supplier - the thing worth arguing about before the next
 *  order goes in. */
function pi_price_alerts($months = 6, $limit = 30) {
    $rows = all("SELECT pi.item_id, i.name, p.party_id, pt.name supplier, pi.price, p.purchase_date
                 FROM purchase_items pi
                 JOIN purchases p ON p.id = pi.purchase_id
                 JOIN items i ON i.id = pi.item_id
                 JOIN parties pt ON pt.id = p.party_id
                 WHERE p.is_cancelled = 0 AND pi.price > 0
                   AND p.purchase_date >= DATE_SUB(CURDATE(), INTERVAL ? MONTH)
                 ORDER BY pi.item_id, p.party_id, p.purchase_date, pi.id", [$months]);
    $seen = [];
    foreach ($rows as $r) {
        $k = (int)$r['item_id'] . ':' . (int)$r['party_id'];
        if (!isset($seen[$k])) { $seen[$k] = ['first' => $r, 'last' => $r]; continue; }
        $seen[$k]['last'] = $r;
    }
    $out = [];
    foreach ($seen as $s) {
        $old = (float)$s['first']['price'];
        $new = (float)$s['last']['price'];
        if ($old <= 0 || $new <= $old * 1.02) continue;
        $out[] = [
            'item_id' => (int)$s['last']['item_id'], 'name' => $s['last']['name'],
            'supplier' => $s['last']['supplier'], 'party_id' => (int)$s['last']['party_id'],
            'old_price' => $old, 'new_price' => $new,
            'pct' => round(($new - $old) / $old * 100, 1),
            'date' => $s['last']['purchase_date'],
        ];
    }
    usort($out, fn($a, $b) => $b['pct'] <=> $a['pct']);
    return array_slice($out, 0, (int)$limit);
}

/** Items whose margin is thin RIGHT NOW - selling price against what the goods
 *  actually cost last time, not against a catalogue figure that may be years
 *  old. This is what tells the owner a price needs raising before the next
 *  sale, rather than after the month's profit report. */
function pi_margin_watch($limit = 30) {
    $r = pi_rules();
    $lastCost = pi_last_costs();
    $items = all("SELECT i.id, i.name, i.unit, i.selling_price, i.purchase_price, i.brand,
                         COALESCE(SUM(st.qty),0) stock
                  FROM items i LEFT JOIN stock st ON st.item_id = i.id
                  WHERE i.is_active = 1 AND i.item_type <> 'service' AND i.selling_price > 0
                  GROUP BY i.id");
    $sold90 = pi_daily_rate(90);
    $out = [];
    foreach ($items as $it) {
        $id = (int)$it['id'];
        $c = $lastCost[$id] ?? null;
        $cost = $c['price'] ?? (float)$it['purchase_price'];
        if ($cost <= 0) continue;
        $sell = (float)$it['selling_price'];
        $margin = ($sell - $cost) / $sell * 100;
        if ($margin >= $r['target_margin']) continue;
        $out[] = [
            'item_id' => $id, 'name' => $it['name'], 'brand' => $it['brand'],
            'sell' => $sell, 'cost' => round($cost, 2),
            'cost_source' => $c ? 'last_purchase' : 'catalogue', 'cost_date' => $c['date'] ?? null,
            'margin_pct' => round($margin, 1),
            'target' => $r['target_margin'],
            'stock' => (float)$it['stock'],
            'moves' => ($sold90[$id] ?? 0) > 0,
            // what the price would have to be to hit target
            'suggested_price' => round($cost / max(0.01, 1 - $r['target_margin'] / 100), 0),
        ];
    }
    // the ones that both sell and lose money matter most
    usort($out, function ($a, $b) {
        if ($a['moves'] !== $b['moves']) return $a['moves'] ? -1 : 1;
        return $a['margin_pct'] <=> $b['margin_pct'];
    });
    return array_slice($out, 0, (int)$limit);
}

// ---------------------------------------------------------- lost sales -----

/** An ESTIMATE of what running out has cost, reconstructed from stock_ledger.
 *
 *  Nobody writes down the customer who asked for something that was not on the
 *  shelf, so this cannot be measured. What CAN be reconstructed is how long
 *  each item sat at zero, by replaying its movements in order; multiplying
 *  those days by how fast the item normally sells gives the units probably
 *  missed, and the margin on those units is the money probably missed.
 *
 *  Everything that displays this must call it an estimate. It is meant to
 *  answer "is stocking out costing me anything worth caring about", not to be
 *  booked as a number.
 */
function pi_lost_sales($days = 90, $limit = 30) {
    $from = date('Y-m-d', strtotime("-$days days"));
    $rate = pi_daily_rate($days);
    if (!$rate) return ['items' => [], 'units' => 0.0, 'value' => 0.0, 'days' => $days];

    $ids = implode(',', array_map('intval', array_keys($rate)));
    // running balance per item, oldest first
    $moves = all("SELECT item_id, DATE(created_at) d, SUM(change_qty) chg
                  FROM stock_ledger WHERE item_id IN ($ids)
                  GROUP BY item_id, DATE(created_at) ORDER BY item_id, d");
    $nowStock = array_column(all("SELECT item_id, SUM(qty) q FROM stock WHERE item_id IN ($ids) GROUP BY item_id"), 'q', 'item_id');

    // walk each item's history BACKWARDS from today's known stock, so the
    // reconstruction is anchored to a figure that is certainly right
    $byItem = [];
    foreach ($moves as $m) $byItem[(int)$m['item_id']][] = $m;

    $meta = array_column(all("SELECT id, name, unit, selling_price, purchase_price FROM items WHERE id IN ($ids)"), null, 'id');
    $t = today();
    $out = []; $totUnits = 0.0; $totValue = 0.0;

    foreach ($byItem as $id => $rows) {
        $stock = (float)($nowStock[$id] ?? 0);
        // rebuild the daily closing balance by unwinding today's stock
        $closing = [];
        for ($i = count($rows) - 1; $i >= 0; $i--) {
            $closing[$rows[$i]['d']] = $stock;
            $stock -= (float)$rows[$i]['chg'];
        }
        ksort($closing);

        // count days at (or below) zero inside the window
        $zeroDays = 0;
        $prev = null;
        $prevDate = null;
        foreach ($closing as $d => $bal) {
            if ($prevDate !== null && $prev <= 0.009 && $d > $from) {
                $span = days_between_dates(max($prevDate, $from), $d);
                if ($span > 0) $zeroDays += $span;
            }
            $prev = $bal; $prevDate = $d;
        }
        // still out of stock right now
        if ($prev !== null && $prev <= 0.009 && $prevDate !== null)
            $zeroDays += max(0, days_between_dates(max($prevDate, $from), $t));

        if ($zeroDays <= 0) continue;
        $perDay = $rate[$id] ?? 0.0;
        if ($perDay <= 0.0001) continue;
        $units = round($zeroDays * $perDay, 1);
        $m = $meta[$id] ?? null;
        if (!$m) continue;
        $unitMargin = max(0.0, (float)$m['selling_price'] - (float)$m['purchase_price']);
        $value = round($units * $unitMargin, 2);
        $totUnits += $units; $totValue += $value;
        $out[] = ['item_id' => $id, 'name' => $m['name'], 'unit' => $m['unit'],
                  'zero_days' => $zeroDays, 'per_day' => round($perDay, 3),
                  'units' => $units, 'value' => $value];
    }
    usort($out, fn($a, $b) => $b['value'] <=> $a['value']);
    return ['items' => array_slice($out, 0, (int)$limit),
            'units' => round($totUnits, 1), 'value' => round($totValue, 2), 'days' => $days];
}

// -------------------------------------------------------------- summary ----

/** The headline numbers for the purchase screen and the dashboard card. */
function pi_summary() {
    $reorder = pi_reorder(500);
    $out = [
        'reorder_items' => count($reorder),
        'reorder_cost' => round(array_sum(array_column($reorder, 'est_cost')), 2),
        'out_of_stock' => count(array_filter($reorder, fn($x) => $x['out_of_stock'])),
        'urgent' => count(array_filter($reorder, fn($x) => $x['days_left'] !== null && $x['days_left'] <= $x['lead_days'])),
        'price_alerts' => count(pi_price_alerts()),
        'thin_margin' => count(pi_margin_watch(500)),
    ];
    return $out;
}

/** The reorder picture for ONE item, for the item page. Same arithmetic as
 *  pi_reorder() but without sweeping the whole catalogue. */
function pi_item_reorder($itemId, $days = 90) {
    $id = (int)$itemId;
    $it = row("SELECT i.id, i.unit, i.min_stock, i.purchase_price, COALESCE(SUM(st.qty),0) stock
               FROM items i LEFT JOIN stock st ON st.item_id = i.id
               WHERE i.id = ? AND i.item_type <> 'service' GROUP BY i.id", [$id]);
    if (!$it) return null;
    $r = pi_rules();
    $sold = (float)val("SELECT COALESCE(SUM(si.qty),0) FROM sale_items si JOIN sales s ON s.id = si.sale_id
                        WHERE si.item_id = ? AND s.is_cancelled = 0
                          AND s.sale_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)", [$id, $days]);
    $perDay = $sold / $days;
    $stock = (float)$it['stock'];
    $sup = pi_item_suppliers($id, 1);
    $lead = $sup && (int)$sup[0]['lead_days'] ? (int)$sup[0]['lead_days'] : $r['lead_days'];
    $trigger = $lead + $r['safety_days'];
    $daysLeft = $perDay > 0.0001 ? (int)floor($stock / $perDay) : null;
    $target = $perDay > 0.0001 ? ceil($perDay * ($r['cover_days'] + $trigger)) : max((float)$it['min_stock'], 0);
    $qty = max(0, (int)ceil($target - $stock));
    return [
        'stock' => $stock, 'unit' => $it['unit'], 'per_day' => round($perDay, 3),
        'sold' => $sold, 'window' => $days,
        'days_left' => $daysLeft, 'lead_days' => $lead, 'trigger_days' => $trigger,
        'suggest_qty' => $qty,
        'needed' => ($daysLeft !== null && $daysLeft <= $trigger) || ($it['min_stock'] > 0 && $stock < (float)$it['min_stock']),
        'best_supplier' => $sup[0] ?? null,
    ];
}
