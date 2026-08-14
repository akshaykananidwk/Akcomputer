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
t_ok('lost sales is called an estimate to the reader', strpos($src, 'આ આંકડો અંદાજ છે') !== false);
t_ok('delivery days are explained as owner-entered', strpos($src, 'ડિલિવરીના દિવસ તમારે ભરવાના છે') !== false);
t_ok('the cost source is shown on every reorder row', strpos($src, 'કેટલોગ ભાવ') !== false);
