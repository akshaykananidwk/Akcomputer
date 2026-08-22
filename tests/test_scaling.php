<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); die('CLI only'); }
// Scaling.
//
// One test here matters more than all the others: that the cleanup cannot
// touch a bill. Everything else is measurement, and measurement being wrong is
// embarrassing; a cleanup reaching business data is unrecoverable.
$_SESSION['user_id'] = (int)val("SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id
                                 WHERE r.permissions LIKE '%*%' AND u.is_active = 1 ORDER BY u.id LIMIT 1");
$_ROOT = dirname(__DIR__);

// ------------------------------------------------------ the deletion guard --
t_group('the cleanup cannot reach business data');
// Named one by one, because "it is not on the list" is only reassuring if the
// list is actually checked. Each of these is a table someone might one day
// think of as "old records".
foreach (['sales', 'sale_items', 'payments', 'payment_allocations', 'purchases', 'purchase_items',
          'parties', 'items', 'stock', 'stock_ledger', 'handovers', 'handover_items',
          'expenses', 'wa_chats', 'collection_events', 'campaign_targets', 'campaigns',
          'settings', 'users', 'roles'] as $t) {
    $r = sc_trim($t, 1);
    t_ok("sc_trim() refuses $t", !$r['ok'] && $r['deleted'] === 0);
}
$r = sc_trim('sales', 1);
t_ok('...and the refusal says why, in the owner\'s language',
     strpos($r['why'], 'ધંધાનો ડેટા ક્યારેય ડિલીટ થતો નથી') !== false);
t_ok('a table that does not exist is refused too', !sc_trim('no_such_table_at_all')['ok']);
t_ok('an empty table name is refused', !sc_trim('')['ok']);

t_group('the guard lives in the function that deletes, not in the caller');
$src = file_get_contents($_ROOT . '/includes/scaling.php');
t_ok('sc_trim checks the allow-list itself', preg_match('/function sc_trim.*?if \(!isset\(\$logs\[\$table\]\)\)/s', $src) === 1);
$cron = file_get_contents($_ROOT . '/includes/cron_jobs.php');
t_ok('the cron deletes only through sc_trim()', strpos($cron, 'sc_trim($t)') !== false);
t_ok('...and no longer writes its own DELETE for a log table',
     !preg_match('/function cron_job_housekeeping.*?DELETE FROM/s', $cron));
$page = file_get_contents($_ROOT . '/scaling.php');
t_ok('the screen also goes through sc_trim()', strpos($page, 'sc_trim(post(') !== false);
t_ok('...and never builds a DELETE of its own', !preg_match('/\bDELETE FROM\b/i', $page));

t_group('the two lists do not overlap');
$logs = array_keys(sc_log_tables());
$never = array_keys(sc_never_trim());
t_ok('nothing is on both lists', array_intersect($logs, $never) === []);
t_ok('the never-list is not empty', count($never) >= 5);
foreach ($never as $t) t_ok("$t is refused by name", !sc_trim($t, 1)['ok']);
foreach (sc_log_tables() as $t => $def) {
    t_ok("$t declares a label, a date column and a keep-for period", count($def) === 3 && $def[2] >= 30);
}

t_group('a log table really is trimmed, and only past the keep-for line');
$before = (int)val('SELECT COUNT(*) FROM activity_log');
q("INSERT INTO activity_log (user_id, action, details, created_at) VALUES (1, 'TF_OLD', 'x', ?)",
  [date('Y-m-d H:i:s', strtotime('-800 days'))]);
q("INSERT INTO activity_log (user_id, action, details, created_at) VALUES (1, 'TF_NEW', 'x', ?)",
  [date('Y-m-d H:i:s', strtotime('-2 days'))]);
$trimmed = sc_trim('activity_log');            // keeps 365 days
t_ok('the trim runs', $trimmed['ok']);
t_ok('the 800-day-old row is gone', (int)val("SELECT COUNT(*) FROM activity_log WHERE action = 'TF_OLD'") === 0);
t_ok('the two-day-old row is untouched', (int)val("SELECT COUNT(*) FROM activity_log WHERE action = 'TF_NEW'") === 1);

t_group('a trim can never mean "delete everything"');
q("INSERT INTO activity_log (user_id, action, details, created_at) VALUES (1, 'TF_TODAY', 'x', NOW())");
foreach ([0, 1, -50] as $days) {
    sc_trim('activity_log', $days);
    t_ok("asking for $days days still keeps today's row",
         (int)val("SELECT COUNT(*) FROM activity_log WHERE action = 'TF_TODAY'") === 1);
}
t_ok('the floor is in the code', strpos($src, "max(7, (int)(\$days ?: \$keep))") !== false);

// ------------------------------------------------------------- the audit ---
t_group('sizes are read from the database, not guessed');
$size = sc_db_size();
t_ok('the database reports a size', $size['total_mb'] > 0);
t_ok('it counts its tables', $size['tables'] > 20);
t_eq('total is data plus indexes', $size['total_mb'], round($size['data_mb'] + $size['idx_mb'], 2));
$tables = sc_tables(5);
t_ok('the biggest tables come back', count($tables) === 5);
t_ok('...largest first', (float)$tables[0]['total_mb'] + (float)$tables[0]['free_mb']
                       >= (float)$tables[4]['total_mb'] + (float)$tables[4]['free_mb']);

t_group('growth is measured from recent rows, and refused where it cannot be');
$g = sc_growth(30);
t_ok('some tables are measured', count($g) > 0);
foreach ($g as $x) {
    if ($x['per_month'] === null) {
        t_ok($x['name'] . ' says why it cannot be measured', $x['why'] !== '');
        continue;
    }
    t_ok($x['name'] . ': a year from now is at least today', $x['in_a_year'] >= $x['rows']);
}
$sales = null;
foreach ($g as $x) if ($x['name'] === 'sales') $sales = $x;
if ($sales) {
    t_ok('the sales table is growing', $sales['per_month'] > 0);
    t_eq('the projection is rows plus twelve months of growth',
         $sales['in_a_year'], (int)round($sales['rows'] + $sales['per_month'] * 12));
}
// created_at is preferred over a business date on purpose: growth means "when
// was this ROW added", and sale_date can be backdated by whoever typed the bill.
t_ok('created_at is preferred where a table has one', sc_date_column('sales') === 'created_at');
t_ok('...on log tables too', sc_date_column('activity_log') === 'created_at');
t_ok('a table with no date column at all reports none', sc_date_column('stock') === '');
t_ok('an unknown table reports none rather than crashing', sc_date_column('no_such_table') === '');

// ---------------------------------------------------------------- indexes --
t_group('a redundant index is one whose columns start another');
$ri = sc_redundant_indexes();
// v59 dropped the three this found on the live schema; the detector itself is
// checked against known-good pairs on tables that are still here.
foreach ($ri as $x) {
    t_ok($x['table'] . '.' . $x['index'] . ' really is a prefix of ' . $x['covered_by'],
         $x['cols'] === $x['covered_cols'] || strpos($x['covered_cols'], $x['cols'] . ',') === 0);
    t_ok('...and they are different indexes', $x['index'] !== $x['covered_by']);
}
t_ok('a unique index is never called redundant', (function () {
    foreach (sc_redundant_indexes() as $x) {
        $nu = (int)val("SELECT non_unique FROM information_schema.statistics
                        WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? LIMIT 1",
                       [$x['table'], $x['index']]);
        if ($nu === 0) return false;
    }
    return true;
})());
t_ok('an exact duplicate pair is reported once, not twice', (function () {
    $seen = [];
    foreach (sc_redundant_indexes() as $x) {
        $key = $x['table'] . '|' . $x['index'] . '|' . $x['covered_by'];
        $rev = $x['table'] . '|' . $x['covered_by'] . '|' . $x['index'];
        if (isset($seen[$rev])) return false;
        $seen[$key] = 1;
    }
    return true;
})());
// the three v59 dropped must stay gone
foreach ([['sales', 'idx_sale_party'], ['stock_ledger', 'idx_ledger_item'], ['api_usage', 'idx_usage_service_time']] as $p) {
    t_ok($p[0] . '.' . $p[1] . ' is gone (v59)',
         (int)val("SELECT COUNT(*) FROM information_schema.statistics
                   WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?", $p) === 0);
}
t_ok('...and the query it served still has an index',
     (string)(row("EXPLAIN SELECT COUNT(*) FROM sales WHERE party_id = 1")['key'] ?? '') !== '');

// ------------------------------------------------------------ the summary --
t_group('the summary reads back exactly as written');
dash_cache_forget('scaling_summary');
$cold = sc_summary();
$warm = sc_summary();
unset($cold['cached_at'], $warm['cached_at']);
t_ok('cold and warm are identical, types included', $cold === $warm);
t_ok('a year from now is at least today', $cold['in_a_year_mb'] >= $cold['total_mb']);
t_ok('the freeable figure is never negative', $cold['freeable_mb'] >= 0);
t_eq('the redundant count matches the list', $cold['redundant'], count(sc_redundant_indexes()));

// ------------------------------------------------------------ guard rails --
t_group('the screen is for the owner and explains itself');
t_ok('it is admin only', strpos($page, 'is_full_admin()') !== false);
t_ok('changing anything needs settings.edit', substr_count($page, "require_perm('settings.edit')") >= 3);
t_ok('it says the numbers are measured, not assumed', strpos($page, 'માપેલા') !== false);
t_ok('it has a page listing what can never be deleted', strpos($page, 'જે કદી ડિલીટ ન થાય') !== false);
t_ok('...and says the guard is inside the deleting function', strpos($page, 'ડિલીટ કરનારા ફંક્શનની અંદર') !== false);
t_ok('it tells the reader an index is not free', strpos($page, 'ઇન્ડેક્સ મફત નથી') !== false);
t_ok('it warns that rebuilding a table locks it', strpos($page, 'દુકાન બંધ હોય ત્યારે કરવું') !== false);
t_ok('it is honest that test runs create dead space too', strpos($page, 'ટેસ્ટ ચલાવવાથી') !== false);
t_ok('it does not offer to drop an index by itself', strpos($page, 'આ સ્ક્રીન જાતે ઇન્ડેક્સ કાઢતી નથી') !== false);
t_ok('no AI anywhere in it', strpos($src, 'ai_ask') === false && strpos($page, 'ai_ask') === false);
