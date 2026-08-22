<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); die('CLI only'); }
// ============================================================================
//  BENCHMARK — does this get slow when the shop gets bigger?
// ============================================================================
//  Run:  php tests/bench.php            measure at today's size
//        php tests/bench.php 40000      measure again with 40,000 extra bills
//
//  The second form is the one that answers the scaling question. It inserts
//  the extra bills INSIDE A TRANSACTION and rolls the whole thing back at the
//  end, so nothing is kept - but while it is running the tables really are
//  that big, the indexes really are that deep, and the timings are real.
//
//  Every heavy screen's DATA LAYER is timed, not the HTML, because the HTML is
//  the same however much data there is. A number that grows in step with the
//  row count is a query that will one day be a problem; a number that barely
//  moves is one that will not.
// ============================================================================

require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/lib.php';

$extra = (int)($argv[1] ?? 0);
$_SESSION['user_id'] = (int)val("SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id
                                 WHERE r.permissions LIKE '%*%' AND u.is_active = 1 ORDER BY u.id LIMIT 1");

function bm($label, callable $fn, $runs = 3) {
    $fn();                                  // warm the buffer pool
    $best = INF; $q0 = (int)row("SHOW GLOBAL STATUS LIKE 'Questions'")['Value'];
    for ($i = 0; $i < $runs; $i++) {
        $t = microtime(true);
        $fn();
        $best = min($best, (microtime(true) - $t) * 1000);
    }
    $q1 = (int)row("SHOW GLOBAL STATUS LIKE 'Questions'")['Value'];
    printf("  %-34s %8.1f ms   %4d queries\n", $label, $best, (int)round(($q1 - $q0 - 1) / $runs));
    return $best;
}

/** Bulk sales + lines, as fast as MySQL will take them. */
function seed_sales($n) {
    $co = (int)val('SELECT id FROM companies LIMIT 1');
    $loc = (int)val('SELECT id FROM locations LIMIT 1');
    $parties = array_column(all('SELECT id FROM parties WHERE type IN ("customer","both") LIMIT 200'), 'id');
    $items = array_column(all('SELECT id FROM items WHERE is_active = 1 LIMIT 200'), 'id');
    if (!$parties || !$items) { echo "  (no parties/items to seed against)\n"; return; }
    $batch = 500;
    for ($done = 0; $done < $n; $done += $batch) {
        $chunk = min($batch, $n - $done);
        $vals = []; $args = [];
        for ($i = 0; $i < $chunk; $i++) {
            $d = date('Y-m-d', strtotime('-' . random_int(0, 900) . ' days'));
            $tot = random_int(200, 40000);
            $vals[] = '(?,?,?,?,?,?,?,?,?,?,?,?,1)';
            array_push($args, $co, $loc, $parties[array_rand($parties)], 'Bench',
                'BN-' . $done . '-' . $i . '-' . bin2hex(random_bytes(3)), $d, $d,
                $tot, $tot, random_int(0, 1) ? $tot : 0, 'credit', random_int(0, 1) ? 'paid' : 'due');
        }
        q("INSERT INTO sales (company_id, location_id, party_id, customer_name, invoice_no, sale_date, due_date,
            subtotal, total, paid, payment_mode, status, created_by) VALUES " . implode(',', $vals), $args);
        $first = (int)db()->lastInsertId();
        $lv = []; $la = [];
        for ($i = 0; $i < $chunk; $i++) {
            $it = $items[array_rand($items)];
            $lv[] = '(?,?,1,?,?,?)';
            $p = random_int(200, 9000);
            array_push($la, $first + $i, $it, $p, round($p * 0.6), $p);
        }
        q('INSERT INTO sale_items (sale_id, item_id, qty, price, cost_price, total) VALUES ' . implode(',', $lv), $la);
    }
}

$pdo = db();
$pdo->beginTransaction();

$before = (int)val('SELECT COUNT(*) FROM sales');
if ($extra > 0) {
    echo "\nseeding $extra extra bills (rolled back at the end)...\n";
    $t = microtime(true);
    seed_sales($extra);
    printf("seeded in %.1f s\n", microtime(true) - $t);
}
$after = (int)val('SELECT COUNT(*) FROM sales');

echo "\n\033[1mAK Computer — benchmark\033[0m   bills: " . number_format($before)
   . ($extra ? " → " . number_format($after) . ' (×' . round($after / max(1, $before), 1) . ')' : '') . "\n";
echo str_repeat('─', 62) . "\n";

echo "\n\033[1mthe dashboard\033[0m\n";
$from = date('Y-m-01'); $to = today();
bm('dash_kpis (this month)', fn() => dash_kpis($from, $to, null, 0));
bm('dash_balances', fn() => dash_balances());
bm('dash_sales_trend', fn() => dash_sales_trend());
// the cache would otherwise time itself, not the query behind it
bm('dash_stock (cache cleared each run)', function () { dash_cache_forget('stock'); dash_stock(0); }, 2);

echo "\n\033[1mmoney\033[0m\n";
$party = (int)val('SELECT party_id FROM sales WHERE party_id IS NOT NULL LIMIT 1');
bm('party_balance (one customer)', fn() => party_balance($party));
bm('money_due_bills (one customer)', fn() => money_due_bills($party, 'in'));
$open = all("SELECT id, party_id, total, paid FROM sales WHERE status <> 'paid' AND is_cancelled = 0 LIMIT 3000");
bm('cap ' . count($open) . ' open bills (batched)', fn() => fc_cap_dues($open, 'in'));

echo "\n\033[1mthe intelligence screens\033[0m\n";
bm('collection queue', fn() => coll_queue(200));
bm('purchase reorder list', fn() => pi_reorder(150));
bm('market momentum (category)', fn() => mk_momentum('category'));
bm('market squeeze', fn() => mk_squeeze());
bm('cash projection (12 weeks)', fn() => fc_cashflow(12));
bm('sales forecast + backtest', fn() => fc_sales_ahead(3));

echo "\n\033[1mwriting\033[0m\n";
$co = (int)val('SELECT id FROM companies LIMIT 1');
$loc = (int)val('SELECT id FROM locations LIMIT 1');
bm('insert 200 bills', function () use ($co, $loc, $party) {
    for ($i = 0; $i < 200; $i++)
        q("INSERT INTO sales (company_id, location_id, party_id, customer_name, invoice_no, sale_date, due_date,
            subtotal, total, paid, payment_mode, status, created_by)
           VALUES (?,?,?,'W',?,CURDATE(),CURDATE(),100,100,0,'credit','due',1)",
          [$co, $loc, $party, 'W-' . bin2hex(random_bytes(6))]);
}, 1);

$pdo->rollBack();
echo "\n\033[90m(everything rolled back — the database is exactly as it was)\033[0m\n";
