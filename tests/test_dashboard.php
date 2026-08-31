<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); die('CLI only'); }
// The Smart Dashboard. The point of these tests is that the owner's home
// screen can never quietly disagree with the Reports screen - so as well as
// checking each figure, several tests re-derive the same number the way the
// report does and assert the two match.

t_group('date ranges');
$t = today();
list($f, $to, $lbl) = dash_range('today');
t_eq('today is a single day', $f, $to);
t_eq('…and it is today', $f, $t);
list($f, $to) = dash_range('yesterday');
t_eq('yesterday is a single day', $f, $to);
t_eq('…one day back', $f, date('Y-m-d', strtotime('-1 day', strtotime($t))));
list($f, $to) = dash_range('month');
t_eq('this month starts on the 1st', $f, date('Y-m-01', strtotime($t)));
t_eq('…and ends today', $to, $t);
list($f, $to) = dash_range('prev_month');
t_eq('previous month starts on its 1st', $f, month_start(1, $t));
t_eq('…and ends on its last day', $to, date('Y-m-t', strtotime($f)));
list($f, $to) = dash_range('custom', '2026-03-10', '2026-03-01');
t_eq('a backwards custom range is swapped, not left broken', $f, '2026-03-01');
t_eq('…so from is never after to', $to, '2026-03-10');
list($f, $to) = dash_range('custom', 'rubbish', '');
t_eq('rubbish dates fall back to today', $f, $t);
list($f, $to, $lbl) = dash_range('no_such_range');
t_eq('an unknown range falls back to today', $f, $t);

t_group('comparison periods are weekday-aware');
list($cf, $ct, $cl) = dash_compare_range('2026-08-13', '2026-08-13', 'today');
t_eq('a single day compares with the SAME weekday a week back', $cf, '2026-08-06');
t_eq('…which is one day, not a week', $cf, $ct);
t_eq('…and says so', $cl, 'same day last week');
t_eq('the comparison day really is the same weekday',
     date('N', strtotime('2026-08-13')), date('N', strtotime($cf)));
list($cf, $ct) = dash_compare_range('2026-08-10', '2026-08-16', 'week');
t_eq('a 7-day range compares with the 7 days before it', $ct, '2026-08-09');
t_eq('…of the same length', $cf, '2026-08-03');
list($cf, $ct) = dash_compare_range('2026-08-01', '2026-08-31', 'prev_month');
t_eq('a full month compares with the whole previous month', $cf, '2026-07-01');
t_eq('…to its last day', $ct, '2026-07-31');

t_group('dash_delta() refuses to invent a percentage');
t_eq('a straight doubling is +100%', dash_delta(200, 100), 100.0);
t_eq('a halving is -50%', dash_delta(50, 100), -50.0);
t_eq('no change is 0%', dash_delta(100, 100), 0.0);
t_ok('growth from zero is not a percentage', dash_delta(500, 0) === null);
t_ok('a missing baseline is not a percentage', dash_delta(500, null) === null);

t_group('KPIs on an empty day');
$empty = dash_kpis('2019-01-01', '2019-01-01');
t_eq('no bills', $empty['bills'], 0);
t_eq('no sales', $empty['sales'], 0.0);
t_eq('no profit', $empty['profit'], 0.0);
t_eq('average bill does not divide by zero', $empty['avg_bill'], 0.0);
t_eq('margin does not divide by zero', $empty['margin_pct'], 0.0);

t_group('KPIs count what actually happened');
// The shop may already have trading today, so each figure is checked as the
// CHANGE this fixture causes rather than as an absolute - that keeps the test
// true against an empty database and against a live copy alike.
$loc = (int)val('SELECT id FROM locations ORDER BY id LIMIT 1');
$co = (int)val('SELECT id FROM companies ORDER BY id LIMIT 1');
$before = dash_kpis($t, $t);
$p = t_party();
$it = t_item(50, $loc);
q('UPDATE items SET purchase_price = 600 WHERE id = ?', [$it]);
$s = t_sale($p, 1000, 0, $t);
q('INSERT INTO sale_items (sale_id, item_id, qty, price, cost_price, total) VALUES (?,?,?,?,?,?)', [$s, $it, 1, 1000, 600, 1000]);
$k = dash_kpis($t, $t);
t_eq('the bill is counted', $k['bills'], $before['bills'] + 1);
t_eq('the sale is added', round($k['sales'] - $before['sales'], 2), 1000.0);
t_eq('gross profit rises by revenue minus the SAME cost the report uses',
     round($k['profit'] - $before['profit'], 2), 400.0);
t_eq('margin is exactly profit over revenue',
     $k['margin_pct'], $k['revenue'] > 0.009 ? round($k['profit'] / $k['revenue'] * 100, 1) : 0.0);
// the reconciliation that matters: the dashboard and the profit report agree
$rep = row("SELECT COALESCE(SUM(si.total),0) rev, COALESCE(SUM(" . profit_cost_sql() . "),0) cost
            FROM sale_items si JOIN sales s ON s.id = si.sale_id JOIN items i ON i.id = si.item_id
            WHERE s.is_cancelled = 0 AND s.sale_date BETWEEN ? AND ?", [$t, $t]);
t_eq('dashboard profit reconciles with the profit report formula',
     round($rep['rev'] - $rep['cost'], 2), $k['profit']);

t_group('a cancelled bill is not business');
$s2 = t_sale($p, 5000, 0, $t);
q('UPDATE sales SET is_cancelled = 1 WHERE id = ?', [$s2]);
$k2 = dash_kpis($t, $t);
t_eq('cancelling removes it from sales', $k2['sales'], $k['sales']);
t_eq('…and from the bill count', $k2['bills'], $k['bills']);

t_group('outstanding totals reconcile with the party ledger');
$b = dash_balances();
$direct = 0.0;
foreach (all('SELECT ' . party_balance_expr('p') . ' bal FROM parties p WHERE p.is_active = 1 OR ABS(' . party_balance_expr('p') . ') > 0.009') as $r)
    if ($r['bal'] > 0.009) $direct += (float)$r['bal'];
t_eq('receivable equals the Parties-list expression', $b['receivable'], round($direct, 2));
t_ok('receivable is never negative', $b['receivable'] >= 0);
t_ok('payable is never negative', $b['payable'] >= 0);

t_group('collection never claims more than the ledger');
$p2 = t_party();
$o1 = t_sale($p2, 2800, 0, date('Y-m-d', strtotime('-60 days')));
$o2 = t_sale($p2, 2041, 0, date('Y-m-d', strtotime('-10 days')));
t_payment($p2, 2800); // paid, never linked to a bill
$col = dash_collection();
$mine = null;
foreach ($col['customers'] as $c) if ((int)$c['id'] === (int)$p2) $mine = $c;
t_ok('the party appears in collection', $mine !== null);
t_eq('and is capped at the real ledger, not the bill total', $mine['amount'], 2041.0);
t_eq('which is exactly what party_balance says', $mine['amount'], party_balance($p2));
t_eq('the same figure the reminder would send',
     $mine['amount'], sale_true_due(row('SELECT * FROM sales WHERE id = ?', [$o2])));
t_ok('collection total is never more than total receivable', $col['total'] <= dash_balances()['receivable'] + 0.01);

t_group('overdue vs due-later is split by date');
$p3 = t_party();
t_sale($p3, 500, 0, date('Y-m-d', strtotime('-30 days')), date('Y-m-d', strtotime('-5 days')));  // overdue
t_sale($p3, 700, 0, $t, date('Y-m-d', strtotime('+3 days')));                                    // due this week
$col2 = dash_collection();
$m2 = null;
foreach ($col2['customers'] as $c) if ((int)$c['id'] === (int)$p3) $m2 = $c;
t_eq('the party owes both bills', $m2['amount'], 1200.0);
t_eq('only the past-due part counts as overdue', $m2['overdue'], 500.0);
t_ok('the not-yet-due part shows up in this week', $col2['due_week'] >= 700.0);

t_group('payment behaviour is a plain ratio, not a score');
t_eq('too few bills to judge', dash_payment_behaviour(1, 0), 'new');
t_eq('never late', dash_payment_behaviour(10, 0), 'excellent');
t_eq('late now and then', dash_payment_behaviour(10, 2), 'good');
t_eq('late half the time', dash_payment_behaviour(10, 5), 'slow');
t_eq('late nearly always', dash_payment_behaviour(10, 9), 'poor');

t_group('stock alerts');
// ttl 0 throughout: dash_stock() is cached for two minutes in the app, so a
// test that creates an item and immediately looks for it must read live.
$st = dash_stock(0, 0);
t_ok('out-of-stock items really have none', !array_filter($st['out'], fn($x) => $x['qty'] > 0.009));
t_ok('low-stock items all still have some', !array_filter($st['low'], fn($x) => $x['qty'] <= 0.009));
$outOfStock = t_item(0, $loc);
$st2 = dash_stock(0, 0);
t_ok('a zero-stock item is reported as out', (bool)array_filter($st2['out'], fn($x) => (int)$x['id'] === (int)$outOfStock));
// dash_stock() reports the WORST 20 of each list plus the exact totals, so a
// fixture is checked by the count moving, and by ranking it to the top when
// the row itself has to be inspected.
$lowBefore = dash_stock(0, 0)['low_count'];
$lowItem = t_item(2, $loc);
q('UPDATE items SET min_stock = 10 WHERE id = ?', [$lowItem]);
$st3 = dash_stock(0, 0);
t_eq('an item under its minimum joins the low list', $st3['low_count'], $lowBefore + 1);

t_group('dead stock is money, and the buckets add up');
$deadBefore = dash_stock(0, 0);
// priced high on purpose so it sorts to the head of the by-value list
$dead = t_item(10, $loc);
q("UPDATE items SET purchase_price = 9000000, created_at = DATE_SUB(NOW(), INTERVAL 400 DAY) WHERE id = ?", [$dead]);
$st4 = dash_stock(0, 0);
$row = null;
foreach ($st4['deadlist'] as $d) if ((int)$d['id'] === (int)$dead) $row = $d;
t_ok('stock that never sold and has sat for a year is dead', $row !== null);
t_eq('its value is quantity times cost', $row['value'], 90000000.0);
t_ok('it is flagged as never sold', $row['never_sold'] === true);
t_eq('the dead total grows by exactly that value',
     round($st4['dead_value'] - $deadBefore['dead_value'], 2), 90000000.0);
t_eq('the buckets add up to the dead total', round(array_sum($st4['dead_buckets']), 2), $st4['dead_value']);
// freshly arrived stock is NOT dead money
$fresh = t_item(5, $loc);
q('UPDATE items SET purchase_price = 500 WHERE id = ?', [$fresh]);
$st5 = dash_stock(0, 0);
t_ok('stock that arrived today is not called dead',
     !array_filter($st5['deadlist'], fn($x) => (int)$x['id'] === (int)$fresh));

t_group('the stock cache serves the same answer as a live read');
dash_cache_forget('stock_0');
$live = dash_stock(0, 0);
$c1 = dash_stock(0, 120);   // computes and stores
$c2 = dash_stock(0, 120);   // served from cache
unset($c1['cached_at'], $c2['cached_at']);
t_eq('a cached read equals a fresh one, type for type', $c1, $c2);
t_eq('the cache keeps the exact counts, not the trimmed ones', dash_n($c2, 'out'), $live['out_count']);
t_ok('the lists themselves are capped so the cache stays small', count($c2['out']) <= 20);
t_ok('…while the exact total is still reported', $c2['out_count'] >= count($c2['out']));
dash_cache_forget('stock_0');

t_group('low-margin detection uses the shop target');
set_setting('target_margin_pct', '25');
$thin = t_item(10, $loc);
q('UPDATE items SET purchase_price = 950 WHERE id = ?', [$thin]);
$s3 = t_sale($p, 1000, 0, $t);
q('INSERT INTO sale_items (sale_id, item_id, qty, price, cost_price, total) VALUES (?,?,?,?,?,?)', [$s3, $thin, 1, 1000, 950, 1000]);
$lm = dash_low_margin($t, $t);
t_ok('a 5% margin is flagged against a 25% target',
     (bool)array_filter($lm, fn($x) => (int)$x['id'] === (int)$thin));

t_group('customer segmentation is deterministic');
$seg = dash_segments();
foreach (['vip', 'high_value', 'regular', 'new', 'at_risk', 'inactive', 'overdue'] as $k) {
    t_ok("segment '$k' exists", isset($seg[$k]));
    t_ok("segment '$k' count is never negative", ($seg[$k]['count'] ?? -1) >= 0);
}
$fresh2 = t_party();
t_sale($fresh2, 100, 0, $t);
$seg2 = dash_segments();
t_ok('a party created today lands in New', $seg2['new']['count'] > 0);
$sleepy = t_party();
q('UPDATE parties SET created_at = DATE_SUB(NOW(), INTERVAL 700 DAY) WHERE id = ?', [$sleepy]);
t_sale($sleepy, 100, 100, date('Y-m-d', strtotime('-400 days')));
$seg3 = dash_segments();
t_ok('a party who last bought 400 days ago counts as Inactive', $seg3['inactive']['count'] > 0);

t_group('the Action Center only fires on real problems');
$quiet = dash_actions(['collection' => ['overdue_customers' => 0, 'overdue' => 0.0],
                       'stock' => ['out' => [], 'low' => [], 'dead_value' => 0.0]]);
t_eq('a clean shop gets no actions', count($quiet), 0);
$busy = dash_actions(['collection' => ['overdue_customers' => 3, 'overdue' => 5000.0],
                      'stock' => ['out' => [1], 'low' => [1, 2], 'dead_value' => 9000.0],
                      'repairs_ready' => 2, 'new_orders' => 1, 'health_issues' => ['x' => 1]]);
t_ok('a shop with problems gets one action per problem', count($busy) >= 6);
foreach ($busy as $a) {
    if (empty($a['link']) || empty($a['cta'])) { t_ok('every action can be acted on', false, $a['text']); break; }
}
t_ok('every action carries a link and a button', true);

t_group('alerts follow the same rules');
$al = dash_alerts([
    'kpis' => ['sales' => 800.0, 'margin_pct' => 4.0],
    'prev' => ['sales' => 2000.0],
    'compare_label' => 'ગયા અઠવાડિયે',
    'collection' => ['overdue' => 12000.0],
    'stock' => ['low' => [1], 'out' => []],
]);
$txt = implode(' | ', array_column($al, 'text'));
t_ok('a 60% sales drop is called out', strpos($txt, '60') !== false);
t_ok('overdue money is called out', strpos($txt, '12,000') !== false);
t_ok('every alert has somewhere to go', count(array_filter($al, fn($a) => !empty($a['link']))) === count($al));

t_group('the daily summary is written from the numbers');
$sum = dash_summary([
    'kpis' => ['sales' => 42500.0, 'bills' => 12, 'profit' => 7200.0, 'margin_pct' => 17.0],
    'prev' => ['sales' => 36000.0],
    'compare_label' => 'ગયા અઠવાડિયે',
    'collection' => ['overdue' => 32000.0, 'overdue_customers' => 4, 'total' => 50000.0],
    'stock' => ['out' => [1], 'low' => [2, 3], 'dead_value' => 180000.0],
]);
t_ok('it names the sales figure', strpos($sum, '42,500') !== false);
t_ok('it names the collection shortfall', strpos($sum, '32,000') !== false);
t_ok('it counts the products about to run out', strpos($sum, '3 પ્રોડક્ટ') !== false);
t_ok('it names the money stuck in dead stock', strpos($sum, '180,000') !== false);
$quietSum = dash_summary(['kpis' => ['sales' => 0.0, 'bills' => 0, 'profit' => 0.0, 'margin_pct' => 0.0], 'prev' => null]);
t_ok('a day with no sales says so plainly', strpos($quietSum, 'કોઈ વેચાણ') !== false);

t_group('the result cache returns the same answer, then refreshes');
dash_cache_forget('t_probe');
$n = 0;
$mk = function () use (&$n) { $n++; return ['v' => $n]; };
$a = dash_cache('t_probe', 300, $mk);
$b = dash_cache('t_probe', 300, $mk);
t_eq('the second read is served from cache', $a['v'], $b['v']);
t_eq('…so the work ran only once', $n, 1);
$c = dash_cache('t_probe', 0, $mk); // expired immediately
t_eq('an expired entry recomputes', $n, 2);
dash_cache_forget('t_probe');
$d = dash_cache('t_probe', 300, $mk);
t_eq('forgetting forces a fresh computation', $n, 3);

t_group('data health status');
$h = dash_health(0); // live, not cached
t_eq('it reports on every check', $h['total'], count(health_checks()));
t_ok('healthy + issues accounts for all of them', $h['healthy'] + count($h['issues']) === $h['total']);
t_ok('the state is one of the three', in_array($h['state'], ['ok', 'warn', 'bad'], true));

t_group('the dashboard respects the same fences as every other screen');
$mine = dash_kpis($t, $t, 1);        // only user 1's records
$all  = dash_kpis($t, $t, null);     // the whole shop
t_ok('a scoped user never sees more than the shop total', $mine['sales'] <= $all['sales'] + 0.01);
t_ok('…and never more bills', $mine['bills'] <= $all['bills']);
$other = dash_kpis($t, $t, 999999);  // a user who has recorded nothing
t_eq('a user with no records sees zero, not everything', $other['sales'], 0.0);
t_eq('…and no bills', $other['bills'], 0);
