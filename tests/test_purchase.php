<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); die('CLI only'); }
// Smart stock + purchase intelligence.
//
// These rules decide what the shop spends money on, so each one is checked
// against a product whose history is built by hand and whose right answer can
// be worked out on paper. Runs as the admin, because the cost figures are
// permission-gated and would otherwise be silently absent.
$_SESSION['user_id'] = (int)val("SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id
                                 WHERE r.permissions LIKE '%*%' AND u.is_active = 1 ORDER BY u.id LIMIT 1");

$loc = (int)val('SELECT id FROM locations ORDER BY id LIMIT 1');
$co  = (int)val('SELECT id FROM companies ORDER BY id LIMIT 1');

// fixed rules so the arithmetic below is predictable
set_setting('purchase_lead_days', '7');
set_setting('purchase_safety_days', '7');
set_setting('purchase_cover_days', '30');
set_setting('target_margin_pct', '10');

/** An item that sells $perDay units a day for $days, with $stock left. */
function tp_item($name, $stock, $perDay, $days = 90, $sell = 1000, $cost = 600) {
    global $loc;
    $id = t_item(0, $loc);
    q("UPDATE items SET name = ?, selling_price = ?, purchase_price = ?, min_stock = 0 WHERE id = ?", [$name, $sell, $cost, $id]);
    if ($stock > 0) q('INSERT INTO stock (item_id, location_id, qty) VALUES (?,?,?)
                       ON DUPLICATE KEY UPDATE qty = VALUES(qty)', [$id, $loc, $stock]);
    if ($perDay > 0) {
        $p = t_party('TP Buyer ' . substr($name, 0, 12));
        $total = (int)round($perDay * $days);
        // one bill carrying the whole window's quantity keeps the fixture small
        $s = t_sale($p, $sell * $total, $sell * $total, date('Y-m-d', strtotime('-' . intdiv($days, 2) . ' days')));
        q('INSERT INTO sale_items (sale_id, item_id, qty, price, cost_price, total) VALUES (?,?,?,?,?,?)',
          [$s, $id, $total, $sell, $cost, $sell * $total]);
    }
    return $id;
}

/** A purchase of one item from one supplier at one price on one date. */
function tp_purchase($itemId, $partyId, $price, $qty, $date) {
    global $co, $loc;
    q("INSERT INTO purchases (company_id, location_id, party_id, bill_no, purchase_date, subtotal, total, paid, status, created_by)
       VALUES (?,?,?,?,?,?,?,?, 'paid', 1)",
      [$co, $loc, $partyId, 'TP-' . bin2hex(random_bytes(4)), $date, $price * $qty, $price * $qty, $price * $qty]);
    $pid = insert_id();
    q('INSERT INTO purchase_items (purchase_id, item_id, qty, price, total) VALUES (?,?,?,?,?)',
      [$pid, $itemId, $qty, $price, $price * $qty]);
    return $pid;
}

function tp_find(array $rows, $itemId, $key = 'item_id') {
    foreach ($rows as $r) if ((int)$r[$key] === (int)$itemId) return $r;
    return null;
}

t_group('daily rate is measured, not guessed');
$fast = tp_item('TP Fast', 100, 2.0);      // 180 units over 90 days
$rates = pi_daily_rate(90);
t_ok('a product selling 2 a day reports about 2 a day', abs(($rates[$fast] ?? 0) - 2.0) < 0.05);
$never = tp_item('TP Never', 50, 0);
t_ok('a product that never sold has no rate', !isset($rates[$never]) || $rates[$never] == 0);

t_group('reorder triggers on running out, not on a fixed line');
// 20 in stock, sells 2/day -> 10 days of cover, delivery+safety is 14 -> order
$soon = tp_item('TP Soon', 20, 2.0);
// 20 in stock, sells 0.05/day -> 400 days of cover -> leave it alone
$slow = tp_item('TP Slow', 20, 0.05);
$re = pi_reorder(500);
$rSoon = tp_find($re, $soon);
$rSlow = tp_find($re, $slow);
t_ok('a product that runs out inside the delivery window is suggested', $rSoon !== null);
t_eq('…and its days of cover are counted right', $rSoon['days_left'], 10);
t_ok('a product with a year of cover is NOT suggested', $rSlow === null);
$idle = tp_item('TP Idle', 0, 0);
t_ok('a product nothing sells is never suggested, even at zero stock', tp_find($re, $idle) === null);

t_group('suggested quantity covers the order window plus the wait');
// 2/day, cover 30 + lead 7 + safety 7 = 44 days -> 88 units, minus 20 on hand
t_eq('quantity is demand over the whole window minus what is on hand', $rSoon['suggest_qty'], 68);
t_eq('the trigger is delivery plus safety', $rSoon['trigger_days'], 14);
t_ok('nothing is ever suggested in negative', !array_filter($re, fn($x) => $x['suggest_qty'] <= 0));

t_group('minimum stock still works as a second trigger');
$minOnly = tp_item('TP MinLine', 3, 0.01);   // 300 days of cover, but under its minimum
q('UPDATE items SET min_stock = 10 WHERE id = ?', [$minOnly]);
$re2 = pi_reorder(500);
$rMin = tp_find($re2, $minOnly);
t_ok('an item under its minimum is suggested even with plenty of cover', $rMin !== null);
t_eq('and says that is why', $rMin['reason'], 'min_stock');

t_group('out of stock sorts to the top');
$outIt = tp_item('TP Empty', 0, 1.0);
$re3 = pi_reorder(500);
t_ok('the first row is out of stock', $re3[0]['out_of_stock']);
$firstNonZero = null;
foreach ($re3 as $r) if (!$r['out_of_stock']) { $firstNonZero = $r; break; }
t_ok('every out-of-stock row comes before any in-stock row',
     $firstNonZero === null || !array_filter(array_slice($re3, array_search($firstNonZero, $re3, true)), fn($x) => $x['out_of_stock']));

t_group('cost comes from what was really paid, and says so');
$sup1 = t_party('TP Supplier A');
$costed = tp_item('TP Costed', 10, 1.0, 90, 2000, 900);
tp_purchase($costed, $sup1, 850, 5, date('Y-m-d', strtotime('-40 days')));
$re4 = pi_reorder(500);
$rc = tp_find($re4, $costed);
t_eq('the last real purchase price is used', $rc['unit_cost'], 850.0);
t_eq('and it is labelled as such', $rc['cost_source'], 'last_purchase');
t_eq('the order value follows from it', $rc['est_cost'], round($rc['suggest_qty'] * 850, 2));
$uncosted = tp_item('TP Uncosted', 5, 1.0, 90, 2000, 777);
$re5 = pi_reorder(500);
$ru = tp_find($re5, $uncosted);
t_eq('with no purchase history the catalogue price is used', $ru['unit_cost'], 777.0);
t_eq('and THAT is labelled too, so a total is never silently stale', $ru['cost_source'], 'catalogue');

t_group('the cheapest supplier is the one suggested');
$supA = t_party('TP Cheap');
$supB = t_party('TP Dear');
$shared = tp_item('TP Shared', 5, 1.0, 90, 3000, 1500);
tp_purchase($shared, $supB, 1600, 3, date('Y-m-d', strtotime('-60 days')));
tp_purchase($shared, $supA, 1200, 3, date('Y-m-d', strtotime('-30 days')));
$re6 = pi_reorder(500);
$rs = tp_find($re6, $shared);
t_ok('a supplier is suggested', $rs['supplier'] !== null);
t_eq('and it is the cheaper one', $rs['supplier']['party_id'], $supA);
t_eq('with the price they charged', $rs['supplier']['price'], 1200.0);
$list = pi_item_suppliers($shared);
t_eq('the per-item supplier list is cheapest first', (int)$list[0]['party_id'], $supA);
t_eq('and knows both of them', count($list), 2);

t_group('supplier lead time is used when the shop has entered one');
q('UPDATE parties SET lead_days = 21 WHERE id = ?', [$supA]);
$re7 = pi_reorder(500);
$rs2 = tp_find($re7, $shared);
t_eq('that supplier\'s own delivery time is used', $rs2['lead_days'], 21);
t_eq('so the trigger widens with it', $rs2['trigger_days'], 28);
q('UPDATE parties SET lead_days = 0 WHERE id = ?', [$supA]);
$re8 = pi_reorder(500);
t_eq('and falls back to the shop default when not entered', tp_find($re8, $shared)['lead_days'], 7);

t_group('price rises are caught against the same supplier');
$sup2 = t_party('TP Riser');
$rising = tp_item('TP Rising', 10, 1.0, 90, 5000, 2000);
tp_purchase($rising, $sup2, 2000, 2, date('Y-m-d', strtotime('-100 days')));
tp_purchase($rising, $sup2, 2400, 2, date('Y-m-d', strtotime('-10 days')));
$alerts = pi_price_alerts(6, 100);
$pa = tp_find($alerts, $rising);
t_ok('a 20% rise is reported', $pa !== null);
t_eq('with the old price', $pa['old_price'], 2000.0);
t_eq('the new price', $pa['new_price'], 2400.0);
t_eq('and the percentage', $pa['pct'], 20.0);
$steady = tp_item('TP Steady', 10, 1.0, 90, 5000, 2000);
tp_purchase($steady, $sup2, 2000, 2, date('Y-m-d', strtotime('-100 days')));
tp_purchase($steady, $sup2, 2010, 2, date('Y-m-d', strtotime('-10 days')));
t_ok('a half-percent wobble is not reported as a rise', tp_find(pi_price_alerts(6, 100), $steady) === null);

t_group('margin watch uses what the goods actually cost');
$thin = tp_item('TP Thin', 10, 1.0, 90, 1000, 500);   // catalogue says 50% margin
tp_purchase($thin, $sup1, 960, 2, date('Y-m-d', strtotime('-15 days')));  // reality: 4%
$mw = pi_margin_watch(500);
$tm = tp_find($mw, $thin);
t_ok('an item whose real cost destroyed the margin is caught', $tm !== null);
t_eq('the cost used is the real one, not the catalogue one', $tm['cost'], 960.0);
t_eq('the margin is computed from it', $tm['margin_pct'], 4.0);
t_ok('and a price that would hit target is suggested', $tm['suggested_price'] > 1000);
$fat = tp_item('TP Fat', 10, 1.0, 90, 1000, 400);
tp_purchase($fat, $sup1, 400, 2, date('Y-m-d', strtotime('-15 days')));
t_ok('a healthy margin is not flagged', tp_find(pi_margin_watch(500), $fat) === null);

t_group('lost sales: reconstructed, and labelled an estimate');
// sells 1/day; in stock, then zero for 20 days, then restocked
$lost = t_item(0, $loc);
q("UPDATE items SET name = 'TP Lost', selling_price = 1000, purchase_price = 600 WHERE id = ?", [$lost]);
foreach ([[60, 30], [40, -30], [20, 25]] as list($ago, $chg))
    q("INSERT INTO stock_ledger (item_id, location_id, change_qty, ref_type, note, created_by, created_at)
       VALUES (?,?,?, 'test', '', 1, DATE_SUB(NOW(), INTERVAL ? DAY))", [$lost, $loc, $chg, $ago]);
q('INSERT INTO stock (item_id, location_id, qty) VALUES (?,?,25) ON DUPLICATE KEY UPDATE qty = 25', [$lost, $loc]);
$lp = t_party('TP Lost Buyer');
$ls = t_sale($lp, 90000, 90000, date('Y-m-d', strtotime('-45 days')));
q('INSERT INTO sale_items (sale_id, item_id, qty, price, cost_price, total) VALUES (?,?,?,?,?,?)', [$ls, $lost, 90, 1000, 600, 90000]);
$est = pi_lost_sales(90, 500);
$le = tp_find($est['items'], $lost);
t_ok('an item that sat at zero is reported', $le !== null);
t_eq('the days at zero are counted exactly', $le['zero_days'], 20);
t_ok('the daily rate is about one', abs($le['per_day'] - 1.0) < 0.05);
t_eq('units missed = days x rate', $le['units'], 20.0);
t_eq('value missed = units x unit margin', $le['value'], 8000.0);
t_ok('an item that never ran out is not in the list', tp_find($est['items'], $fast) === null);
t_ok('the totals add up', $est['value'] >= $le['value']);

t_group('suppliers are summarised without a query per supplier');
$sups = pi_suppliers(24);
t_ok('the suppliers we bought from are listed', count($sups) > 0);
$sa = tp_find($sups, $supA, 'id');
t_ok('the cheap supplier is among them', $sa !== null);
t_ok('with what we spent', $sa['spend'] > 0);
t_ok('and how many bills', $sa['bills'] > 0);
t_ok('every row carries a balance figure', !array_filter($sups, fn($x) => !isset($x['we_owe'])));
$riser = tp_find($sups, $sup2, 'id');
t_ok('a supplier whose prices went up is marked', $riser && $riser['prices_up'] >= 1);

t_group('the item page and the order list cannot disagree');
$one = pi_item_reorder($soon);
$fromList = tp_find(pi_reorder(500), $soon);
t_eq('same suggested quantity', $one['suggest_qty'], $fromList['suggest_qty']);
t_eq('same days of cover', $one['days_left'], $fromList['days_left']);
t_eq('same delivery time', $one['lead_days'], $fromList['lead_days']);
t_ok('and it agrees that the item is needed', $one['needed']);
t_ok('an item with plenty of cover is not needed', !pi_item_reorder($slow)['needed']);
t_eq('a service item has no reorder picture', pi_item_reorder(
    (int)val("SELECT id FROM items WHERE item_type = 'service' ORDER BY id LIMIT 1") ?: 999999), null);

t_group('edge cases do not break anything');
t_eq('an unknown item returns nothing', pi_item_reorder(999999), null);
t_ok('the summary is always complete', count(array_diff(
    ['reorder_items', 'reorder_cost', 'out_of_stock', 'urgent', 'price_alerts', 'thin_margin'],
    array_keys(pi_summary()))) === 0);
$sum = pi_summary();
t_ok('out of stock never exceeds the reorder list', $sum['out_of_stock'] <= $sum['reorder_items']);
t_ok('the reorder cost is never negative', $sum['reorder_cost'] >= 0);
t_ok('lost sales over a zero window is safe', is_array(pi_lost_sales(1)['items']));

t_group('cost data stays behind the permissions that own it');
$src = file_get_contents(dirname(__DIR__) . '/purchase_intel.php');
t_ok('the screen needs purchases.view', strpos($src, "require_perm('purchases.view')") !== false);
t_ok('…and a cost permission on top of it',
     strpos($src, "!can('items.cost') && !can('reports.profit')") !== false);
$iv = file_get_contents(dirname(__DIR__) . '/item_view.php');
t_ok('the item page gates its reorder banner on items.cost',
     strpos($iv, "can('purchases.view') && can('items.cost')") !== false);
$idx = file_get_contents(dirname(__DIR__) . '/index.php');
t_ok('the dashboard gates its purchase card the same way',
     strpos($idx, "can('purchases.view') && (\$seeProfit || can('items.cost'))") !== false);

t_group('the honesty labels are actually in the screens');
t_ok('lost sales is called an estimate to the reader', strpos($src, 'This figure is an estimate') !== false);
t_ok('delivery days are explained as owner-entered', strpos($src, 'The delivery days are for you to fill in') !== false);
t_ok('the cost source is shown on every reorder row', strpos($src, 'Catalogue price') !== false);

$_ROOT = dirname(__DIR__);
t_group('Stock that is not moving — how long has it been sitting');

// The rule the report used to get wrong: a never-sold item is aged from when
// it ARRIVED, not from the beginning of time.
t_eq('something that sold is aged from its last sale',
     stock_sitting_days('2026-06-01', '2026-01-01', '2025-01-01', '2026-09-01'), 92);
t_eq('something never sold is aged from when it arrived',
     stock_sitting_days(null, '2026-08-25', '2024-01-01', '2026-09-01'), 7);
t_eq('...and from the day the item was created when it was never bought either',
     stock_sitting_days(null, null, '2026-08-01 10:00:00', '2026-09-01'), 31);
t_eq('nothing known at all is not treated as ancient',
     stock_sitting_days(null, null, null, '2026-09-01'), 0);
t_eq('a bill dated in the FUTURE is not negative days of sitting',
     stock_sitting_days('2027-04-10', null, '2026-01-01', '2026-09-28'), 0);
t_ok('a last sale wins over a later purchase — the money moved when it sold',
     stock_sitting_days('2026-08-30', '2026-01-01', null, '2026-09-01') === 2);

t_group('...and what to ask for it');

// Deterministic, and it must never quietly hide a loss.
$c = clearance_price(100, 200, 100);
t_eq('under 6 months: cost + 5%', $c['price'], 105.0);
t_eq('...and that is a profit', $c['per_unit'], 5.0);
$c = clearance_price(100, 200, 200);
t_eq('6 to 12 months: cost', $c['price'], 100.0);
t_eq('...breaking even exactly', $c['per_unit'], 0.0);
$c = clearance_price(100, 200, 400);
t_eq('over a year: cost − 15%', $c['price'], 85.0);
t_eq('...and the loss is stated, not hidden', $c['per_unit'], -15.0);
t_eq('the boundary at 180 days belongs to the cost bucket', clearance_price(100, 200, 180)['price'], 100.0);
t_eq('the boundary at 365 days belongs to the loss bucket', clearance_price(100, 200, 365)['price'], 85.0);

// THE one that stops it being silly: clearance never raises a price.
t_eq('it never suggests MORE than you already ask', clearance_price(100, 90, 100)['price'], 90.0);
t_eq('...and never a negative price', clearance_price(0, 0, 400)['price'], 0.0);

t_group('The clearance list is one rule, shared with the dashboard');

$pi = file_get_contents($_ROOT . '/includes/purchase_intel.php');
$rb = file_get_contents($_ROOT . '/includes/report_body.php');
$dash = file_get_contents($_ROOT . '/includes/dashboard.php');
t_ok('the rule lives in one place', strpos($pi, 'function stock_sitting_days(') !== false);
t_ok('the dashboard uses it', strpos($dash, 'stock_sitting_days(') !== false);
t_ok('...and no longer works the date out itself',
     strpos($dash, '$sittingSince = $lastSale ?:') === false);
t_ok('the report uses it too', strpos($rb, 'dead_stock_rows(') !== false);
t_ok('...and no longer has its own SQL for it',
     strpos($rb, 'last_sale_date < DATE_SUB(CURDATE()') === false);
t_ok('the cut-off is one setting, read in one place',
     strpos($pi, "setting('dead_stock_days', 90)") !== false
     && strpos($dash, "setting('dead_stock_days', 90)") === false);

// --- and now the behaviour, against real rows -----------------------------
$loc = (int)val('SELECT id FROM locations ORDER BY id LIMIT 1');
$co  = (int)val('SELECT id FROM companies ORDER BY id LIMIT 1');

// an item bought long ago, sold long ago, still on the shelf: belongs here
$old = t_item(5, $loc, 500);
q("UPDATE items SET purchase_price = 400, selling_price = 500, created_at = '2024-01-01' WHERE id = ?", [$old]);
$s1 = t_sale(t_party(), 500, 500, '2026-01-10');
q('INSERT INTO sale_items (sale_id, item_id, qty, price, total) VALUES (?,?,1,500,500)', [$s1, $old]);

// an item that arrived a week ago and has never sold: must NOT be here
$fresh = t_item(3, $loc, 900);
q("UPDATE items SET purchase_price = 700, created_at = ? WHERE id = ?", [date('Y-m-d', strtotime('-6 days')), $fresh]);

// an item sold yesterday: must NOT be here
$moving = t_item(4, $loc, 300);
$s2 = t_sale(t_party(), 300, 300, date('Y-m-d', strtotime('-1 day')));
q('INSERT INTO sale_items (sale_id, item_id, qty, price, total) VALUES (?,?,1,300,300)', [$s2, $moving]);

$rows = dead_stock_rows(90, 0);
$byId = [];
foreach ($rows as $x) $byId[$x['id']] = $x;
t_ok('stock that has not moved for months is on the list', isset($byId[$old]));
t_ok('THE OLD BUG: a never-sold item that arrived last week is NOT on it', !isset($byId[$fresh]));
t_ok('something sold yesterday is not on it either', !isset($byId[$moving]));

$row = $byId[$old] ?? null;
t_eq('...with the money actually stuck in it', $row['tied_up'], 2000.0);   // 5 x 400
t_ok('...how long it has been sitting', $row['sitting_days'] >= 200, $row['sitting_days'] ?? '');
t_eq('...a price to ask', $row['ask'], $row['sitting_days'] >= 365 ? 340.0 : 400.0);
t_eq('...and the cash that comes back at it', $row['cash_back'], round($row['ask'] * 5, 2));

// an item with stock at ANOTHER location must not show under this one
$other = (int)val('SELECT id FROM locations WHERE id <> ? ORDER BY id LIMIT 1', [$loc]);
if ($other) {
    $elsewhere = t_item(6, $other, 250);
    q("UPDATE items SET purchase_price = 200, created_at = '2024-01-01' WHERE id = ?", [$elsewhere]);
    $here = array_column(dead_stock_rows(90, $loc), 'id');
    t_ok('the location filter really filters', !in_array($elsewhere, $here)
         && in_array($elsewhere, array_column(dead_stock_rows(90, $other), 'id')));
}

// a service has no shelf, so it can never be dead stock
$svc = t_item(0, $loc, 1000);
q("UPDATE items SET item_type = 'service', created_at = '2024-01-01' WHERE id = ?", [$svc]);
t_ok('a service is never called dead stock', !in_array($svc, array_column(dead_stock_rows(90, 0), 'id')));

// something sold but with nothing left is not money on a shelf
$sold_out = t_item(0, $loc, 400);
q("UPDATE items SET created_at = '2024-01-01' WHERE id = ?", [$sold_out]);
t_ok('an item with no stock left is not on the list', !in_array($sold_out, array_column(dead_stock_rows(90, 0), 'id')));

$rows2 = dead_stock_rows(90, 0);
t_ok('the biggest money is at the top', count($rows2) < 2
     || $rows2[0]['tied_up'] >= $rows2[1]['tied_up']);
$tt = dead_stock_totals($rows2);
t_eq('the totals add up to the rows', $tt['tied_up'],
     round(array_sum(array_column($rows2, 'tied_up')), 2));
t_eq('...and so does the cash back', $tt['cash_back'],
     round(array_sum(array_column($rows2, 'cash_back')), 2));
t_ok('...and the loss/profit is the sum of the rows, not a guess',
     abs($tt['result'] - round(array_sum(array_column($rows2, 'result_total')), 2)) < 0.011);

t_group('The screen says what the number means');

t_ok('it says where the money is stuck', strpos($rb, 'Money stuck on the shelf') !== false);
t_ok('...what comes back if it is cleared', strpos($rb, 'Comes back if you clear it all') !== false);
t_ok('...and names a loss as a loss', strpos($rb, 'Loss at those prices') !== false);
t_ok('the ask is explained, not just printed', strpos($rb, 'under 6 months cost + 5%') !== false);
t_ok('never-sold rows are marked as such', strpos($rb, 'never sold') !== false);
t_ok('...and over-a-year rows too', strpos($rb, 'over a year') !== false);
t_ok('it is honest that it changes no price by itself',
     strpos($rb, 'Nothing here changes a price by itself') !== false);
t_ok('...and warns that a fresh purchase raises the price back',
     strpos($rb, 'raises its price back to the minimum margin') !== false);

t_group('The age bands beside Dead stock can all actually fill');

// They used to be fixed at 30-60 / 60-90 / 90-180 / 180+ while nothing
// entered the list before 90 days, so the first two cards on the owner's
// home screen could never show anything but zero.
$bands = dead_stock_bands();
t_ok('every band starts at or after the shop\'s own cut-off',
     !array_filter($bands, fn($b) => $b[0] < dead_stock_days()), json_encode($bands));
t_ok('the bands do not overlap and leave no gap', (function () use ($bands) {
    for ($i = 1; $i < count($bands); $i++) if ($bands[$i][0] !== $bands[$i - 1][1]) return false;
    return $bands[count($bands) - 1][1] === null;   // the last one is open-ended
})(), json_encode($bands));
t_ok('the last band is open-ended, so nothing falls out of the bottom',
     end($bands)[1] === null);

// every band is reachable: something lands in each one
$hits = [];
foreach ([dead_stock_days(), 181, 400, 5000] as $d) $hits[dead_stock_bucket($d)] = true;
t_ok('and each band can be reached by a real age',
     count($hits) === count($bands), implode(' | ', array_keys($hits)));
t_eq('a bucket key is always one the card list knows',
     array_diff_key($hits, dead_stock_buckets_init()), []);
t_eq('the cut-off day itself lands in the first band',
     dead_stock_bucket(dead_stock_days()), dead_stock_band_label($bands[0][0], $bands[0][1]));

// a shop that sets a long cut-off must not get nonsense bands
$was = setting('dead_stock_days', 90);
set_setting('dead_stock_days', '400');
$long = dead_stock_bands();
t_ok('a 400-day cut-off gives one sensible open band', count($long) === 1 && $long[0] === [400, null]);
t_eq('...and its bucket resolves', dead_stock_bucket(500), '400+ days');
set_setting('dead_stock_days', '200');
t_ok('a 200-day cut-off skips the 180 edge it has already passed',
     dead_stock_bands() === [[200, 365], [365, null]], json_encode(dead_stock_bands()));
set_setting('dead_stock_days', (string)$was);

$dash = file_get_contents($_ROOT . '/includes/dashboard.php');
t_ok('the dashboard no longer hard-codes the bands',
     strpos($dash, "'30-60' => 0.0") === false && strpos($dash, 'dead_stock_buckets_init()') !== false);
t_ok('...and puts a value in a band through the one rule', strpos($dash, 'dead_stock_bucket(') !== false);
t_ok('the buckets still add up to the dead total', (function () {
    $s = dash_stock(0, 0);
    return abs(array_sum($s['dead_buckets']) - $s['dead_value']) < 0.011;
})());

t_group('Purchase edit: units saved without a serial number');
$loc = (int)val('SELECT id FROM locations ORDER BY id LIMIT 1');
$sup = t_party('PU Serial Supplier');
$ssd = t_item(0, $loc, 1200);
q('UPDATE items SET serial_tracked = 1 WHERE id = ?', [$ssd]);
q("INSERT INTO purchases (company_id, bill_no, party_id, location_id, purchase_date, subtotal, total, paid, status, created_by)
   VALUES (1, 'T-SN-1', ?, ?, CURDATE(), 1800, 1800, 0, 'due', 1)", [$sup, $loc]);
$pu = insert_id();
q('INSERT INTO purchase_items (purchase_id, item_id, qty, price, tax_rate, total) VALUES (?,?,3,600,0,1800)', [$pu, $ssd]);
t_ok('a bill saved before serials were tracked: all 3 units have none', purchase_untracked_units($pu) === [$ssd => 3]);
q("INSERT INTO item_serials (item_id, serial_no, location_id, status, purchase_id) VALUES (?, 'SNX-1', ?, 'in_stock', ?)", [$ssd, $loc, $pu]);
t_ok('one serial added: 2 left without', purchase_untracked_units($pu) === [$ssd => 2]);
q("INSERT INTO item_serials (item_id, serial_no, location_id, status, purchase_id) VALUES (?, 'SNX-2', ?, 'sold', ?), (?, 'SNX-3', ?, 'in_stock', ?)", [$ssd, $loc, $pu, $ssd, $loc, $pu]);
t_ok('every unit has its serial: nothing is untracked', purchase_untracked_units($pu) === []);
$plain = t_item(0, $loc);
q('INSERT INTO purchase_items (purchase_id, item_id, qty, price, tax_rate, total) VALUES (?,?,5,10,0,50)', [$pu, $plain]);
t_ok('an item that is not serial tracked is never counted', !isset(purchase_untracked_units($pu)[$plain]));
$src = file_get_contents(__DIR__ . '/../purchases.php');
t_ok('the edit save allows them to stay blank', strpos($src, '$untracked = purchase_untracked_units($pid);') !== false
     && strpos($src, 'if ($short < 0 || $short > $allow)') !== false);
t_ok('and the edit screen does not shrink the qty to the serial count', strpos($src, 'if (!it.untracked) Bill.wireSerialQtySync(div);') !== false);
