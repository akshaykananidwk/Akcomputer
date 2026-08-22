<?php
// ============================================================================
//  SCALING — will this still work when the shop is three times the size?
// ============================================================================
//  The owner's question is not "what is my p99 latency". It is: as the shop
//  grows, will this thing get slow, will it run out of room, and what do I do
//  about it. So everything here is measured on the database in front of it,
//  never estimated from a rule of thumb, and every answer comes with the
//  number it was worked out from.
//
//  The one rule that outranks everything else in this file:
//
//      BUSINESS DATA IS NEVER DELETED. Not by a cron, not by a button, not
//      "because it is old". Bills, payments, the stock ledger, parties, the
//      handover trail - all of it stays for ever. A cleanup feature that can
//      reach a bill is not a cleanup feature, it is a disaster waiting for a
//      mis-click.
//
//  What CAN be trimmed is an explicit, hand-written list of log tables, and
//  sc_trim() refuses anything not on it - not by convention, by a check. The
//  test suite asserts that refusal against the bills table by name.
// ============================================================================

function sc_rules() {
    return [
        'window'      => 90,     // days of history behind the growth rate
        'project'     => 12,     // months projected forward
        'big_mb'      => 50,     // a table this size is worth talking about
        'idx_ratio'   => 2.0,    // indexes more than twice the data is worth a look
        'frag_mb'     => 20,     // free space worth reclaiming
        'keep_days'   => max(30, (int)setting('log_keep_days', 180)),
    ];
}

/** The ONLY tables this file will ever delete from, with how long each is
 *  worth keeping and why. Anything absent from this list is business data as
 *  far as the cleanup is concerned - including tables added in future. */
function sc_log_tables() {
    return [
        'activity_log'       => ['📋 કોણે શું કર્યું', 'created_at', 365],
        'login_history'      => ['🔑 લોગિન નોંધ', 'created_at', 180],
        'login_throttle'     => ['🚧 લોગિન અટકાવ', 'window_start', 30],
        'site_visits'        => ['👣 વેબસાઇટ મુલાકાત', 'visit_date', 180],
        'webhook_deliveries' => ['🔌 વેબહૂક ડિલિવરી', 'created_at', 30],
        'cron_runs'          => ['⏱ ક્રોન ઇતિહાસ', 'started_at', 60],
        'wa_bot_log'         => ['🤖 બોટની નોંધ', 'created_at', 90],
        'api_usage'          => ['💸 AI વપરાશ', 'created_at', 730],
    ];
}

/** Tables that LOOK like logs but are not. Named explicitly so nobody adds
 *  them to the list above later by pattern-matching on the name. */
function sc_never_trim() {
    return [
        'stock_ledger'      => 'સ્ટોકનો દરેક ફેરફાર — સ્ટોક કેમ આટલો છે એનો એકમાત્ર પુરાવો',
        'wa_chats'          => 'ગ્રાહક સાથેની વાતચીત — ફરિયાદ વખતે આ જ પુરાવો છે',
        'collection_events' => 'ઉઘરાણીની નોંધ — વચન, સ્નૂઝ, ના પાડી; ઉઘરાણીના નિયમો આના પર ચાલે છે',
        'payment_allocations' => 'કયો પૈસો કયા બિલમાં ગયો — ખાતાવહીનો પાયો',
        'campaign_targets'  => 'કોને મેસેજ ગયો અને કોને કેમ નહીં — સંમતિનો પુરાવો',
    ];
}

/** Every table with its real size, split into data, indexes and dead space. */
function sc_tables($limit = 40) {
    $limit = (int)$limit;
    $db = defined('DB_NAME') ? DB_NAME : (string)val('SELECT DATABASE()');
    return all("SELECT table_name name, COALESCE(table_rows,0) rows_est,
                       ROUND(data_length/1048576, 2) data_mb,
                       ROUND(index_length/1048576, 2) idx_mb,
                       ROUND(data_free/1048576, 2) free_mb,
                       ROUND((data_length + index_length)/1048576, 2) total_mb
                FROM information_schema.tables
                WHERE table_schema = ? AND table_type = 'BASE TABLE'
                ORDER BY (data_length + index_length + data_free) DESC LIMIT $limit", [$db]);
}

function sc_db_size() {
    $db = defined('DB_NAME') ? DB_NAME : (string)val('SELECT DATABASE()');
    $r = row("SELECT ROUND(SUM(data_length)/1048576,2) data_mb, ROUND(SUM(index_length)/1048576,2) idx_mb,
                     ROUND(SUM(data_free)/1048576,2) free_mb, COUNT(*) tables
              FROM information_schema.tables WHERE table_schema = ? AND table_type = 'BASE TABLE'", [$db]);
    return ['data_mb' => (float)$r['data_mb'], 'idx_mb' => (float)$r['idx_mb'],
            'free_mb' => (float)$r['free_mb'], 'tables' => (int)$r['tables'],
            'total_mb' => round((float)$r['data_mb'] + (float)$r['idx_mb'], 2)];
}

/** The date column a table actually records time on, or '' if it has none. */
function sc_date_column($table) {
    $db = defined('DB_NAME') ? DB_NAME : (string)val('SELECT DATABASE()');
    $cols = array_column(all("SELECT column_name c FROM information_schema.columns
                              WHERE table_schema = ? AND table_name = ?
                                AND data_type IN ('datetime','timestamp','date')", [$db, $table]), 'c');
    foreach (['created_at', 'started_at', 'sale_date', 'purchase_date', 'pay_date', 'exp_date', 'estimate_date'] as $pref)
        if (in_array($pref, $cols, true)) return $pref;
    return $cols[0] ?? '';
}

/** How fast each table is actually growing, and where that lands in a year.
 *
 *  The rate is rows genuinely added in the last 90 days - not the table's
 *  lifetime average, which for a shop that started small would understate
 *  today's pace. A table with no date column is reported as unmeasurable
 *  rather than guessed at. */
function sc_growth($limit = 15) {
    $r = sc_rules();
    $out = [];
    foreach (sc_tables(60) as $t) {
        if ((float)$t['total_mb'] < 0.05 && (int)$t['rows_est'] < 500) continue;
        $col = sc_date_column($t['name']);
        $rows = (int)val('SELECT COUNT(*) FROM `' . $t['name'] . '`');
        if ($col === '') { $out[] = ['name' => $t['name'], 'rows' => $rows, 'total_mb' => (float)$t['total_mb'],
                                     'per_month' => null, 'why' => 'તારીખનું ખાનું નથી, એટલે વધારો માપી શકાય એમ નથી']; continue; }
        $recent = (int)val("SELECT COUNT(*) FROM `{$t['name']}` WHERE `$col` >= DATE_SUB(NOW(), INTERVAL ? DAY)", [$r['window']]);
        $perMonth = round($recent / ($r['window'] / 30.44), 1);
        $bytesPerRow = $rows > 0 ? ((float)$t['data_mb'] + (float)$t['idx_mb']) * 1048576 / $rows : 0;
        $out[] = [
            'name' => $t['name'], 'rows' => $rows, 'total_mb' => (float)$t['total_mb'],
            'recent' => $recent, 'per_month' => $perMonth,
            'in_a_year' => (int)round($rows + $perMonth * $r['project']),
            'mb_in_a_year' => round(($rows + $perMonth * $r['project']) * $bytesPerRow / 1048576, 2),
            'why' => '',
        ];
    }
    usort($out, fn($a, $b) => ($b['per_month'] ?? -1) <=> ($a['per_month'] ?? -1));
    return array_slice($out, 0, (int)$limit);
}

/** Indexes that are a leading prefix of another index on the same table.
 *
 *  These cost a write on every INSERT and UPDATE and buy nothing: any query
 *  the narrow one serves, the wider one serves too. A UNIQUE index is never
 *  reported, because it is enforcing a rule, not speeding up a read. */
function sc_redundant_indexes() {
    $db = defined('DB_NAME') ? DB_NAME : (string)val('SELECT DATABASE()');
    $rows = all("SELECT table_name t, index_name i, non_unique nu,
                        GROUP_CONCAT(column_name ORDER BY seq_in_index) cols
                 FROM information_schema.statistics WHERE table_schema = ?
                 GROUP BY table_name, index_name, non_unique", [$db]);
    $byTable = [];
    foreach ($rows as $x) $byTable[$x['t']][] = $x;

    $out = [];
    foreach ($byTable as $table => $idxs) {
        foreach ($idxs as $a) {
            if ((int)$a['nu'] === 0) continue;              // unique / primary enforces a rule
            foreach ($idxs as $b) {
                if ($a['i'] === $b['i']) continue;
                $same = $b['cols'] === $a['cols'];
                // Two indexes on the SAME columns cover each other, so a naive
                // check reports the pair twice and leaves the reader to work
                // out which one to drop. Name order decides it: the later name
                // is the spare, reported once.
                if ($same && strcmp($a['i'], $b['i']) < 0) continue;
                if ($same || strpos($b['cols'], $a['cols'] . ',') === 0) {
                    $out[] = ['table' => $table, 'index' => $a['i'], 'cols' => $a['cols'],
                              'covered_by' => $b['i'], 'covered_cols' => $b['cols'],
                              'exact' => $same];
                    break;
                }
            }
        }
    }
    usort($out, fn($x, $y) => [$x['table'], $x['index']] <=> [$y['table'], $y['index']]);
    return $out;
}

/** How much of each log table is older than its keep-for period, and what
 *  clearing it would free. Nothing is deleted here - this only reports. */
function sc_trimmable() {
    $sizes = [];
    foreach (sc_tables(90) as $t) $sizes[$t['name']] = $t;
    $out = [];
    foreach (sc_log_tables() as $table => list($label, $col, $keep)) {
        try {
            $total = (int)val("SELECT COUNT(*) FROM `$table`");
            $old = (int)val("SELECT COUNT(*) FROM `$table` WHERE `$col` < DATE_SUB(NOW(), INTERVAL ? DAY)", [$keep]);
        } catch (Exception $e) { continue; }   // table not in this install yet
        $mb = (float)($sizes[$table]['total_mb'] ?? 0);
        $out[] = ['table' => $table, 'label' => $label, 'keep' => $keep, 'date_col' => $col,
                  'rows' => $total, 'old' => $old, 'total_mb' => $mb,
                  'free_mb' => $total > 0 ? round($mb * $old / $total, 2) : 0.0];
    }
    usort($out, fn($a, $b) => $b['old'] <=> $a['old']);
    return $out;
}

/** Delete old rows from ONE log table.
 *
 *  The allow-list is checked here, in the function that does the deleting, not
 *  in the screen that calls it - a guard on the caller is a guard someone can
 *  forget to copy into the next caller. Anything not on the list is refused by
 *  name, including every business table. */
function sc_trim($table, $days = null) {
    $logs = sc_log_tables();
    if (!isset($logs[$table])) {
        return ['ok' => false, 'deleted' => 0,
                'why' => '"' . $table . '" સાફ કરી શકાય એવું ટેબલ નથી. ધંધાનો ડેટા ક્યારેય ડિલીટ થતો નથી.'];
    }
    list($label, $col, $keep) = $logs[$table];
    $days = max(7, (int)($days ?: $keep));   // never allow "delete everything from today"
    // An install that has not reached the migration adding this table - or a
    // column renamed since - must not take the whole cron down with it.
    try {
        $n = q("DELETE FROM `$table` WHERE `$col` < DATE_SUB(NOW(), INTERVAL ? DAY)", [$days])->rowCount();
    } catch (Exception $e) {
        return ['ok' => false, 'deleted' => 0, 'why' => $table . ': ' . $e->getMessage()];
    }
    log_activity('scaling_trim', "$table older than $days days: $n rows");
    return ['ok' => true, 'deleted' => $n, 'table' => $table, 'days' => $days, 'why' => ''];
}

/** Dead space left behind by deleted rows. InnoDB does not hand it back to the
 *  disk on its own; OPTIMIZE TABLE rebuilds the table and does.
 *
 *  Worth being honest about on a development copy: a test suite that inserts
 *  and rolls back all day produces plenty of this, so a big number here does
 *  not automatically mean a big number on the live site. */
function sc_fragmentation() {
    $out = [];
    foreach (sc_tables(60) as $t) {
        if ((float)$t['free_mb'] < 1) continue;
        $out[] = ['name' => $t['name'], 'free_mb' => (float)$t['free_mb'], 'total_mb' => (float)$t['total_mb']];
    }
    usort($out, fn($a, $b) => $b['free_mb'] <=> $a['free_mb']);
    return $out;
}

/** Rebuild one table to hand its dead space back. Allowed on any table -
 *  unlike a delete this changes no data at all - but it locks the table while
 *  it runs, so the screen warns about doing it in shop hours. */
function sc_optimize($table) {
    $db = defined('DB_NAME') ? DB_NAME : (string)val('SELECT DATABASE()');
    $exists = (int)val("SELECT COUNT(*) FROM information_schema.tables
                        WHERE table_schema = ? AND table_name = ? AND table_type = 'BASE TABLE'", [$db, $table]);
    if (!$exists) return ['ok' => false, 'why' => 'એવું ટેબલ નથી.'];
    $before = (float)val("SELECT ROUND(data_free/1048576,2) FROM information_schema.tables
                          WHERE table_schema = ? AND table_name = ?", [$db, $table]);
    q('OPTIMIZE TABLE `' . str_replace('`', '', $table) . '`');
    log_activity('scaling_optimize', $table . ' (' . $before . ' MB free)');
    return ['ok' => true, 'table' => $table, 'freed_mb' => $before, 'why' => ''];
}

/** The headline, in the terms the owner actually asked in. */
function sc_summary() {
    $val = dash_cache('scaling_summary', 3600, function () {
        $r = sc_rules();
        $size = sc_db_size();
        $growth = sc_growth(40);
        $trim = sc_trimmable();
        $redundant = sc_redundant_indexes();
        $frag = sc_fragmentation();
        $mbYear = 0.0;
        foreach ($growth as $g) if ($g['per_month'] !== null) $mbYear += max(0, $g['mb_in_a_year'] - $g['total_mb']);
        $oldRows = 0; $freeable = 0.0;
        foreach ($trim as $t) { $oldRows += $t['old']; $freeable += $t['free_mb']; }
        return [
            'total_mb' => $size['total_mb'], 'free_mb' => $size['free_mb'], 'tables' => $size['tables'],
            'grow_mb_year' => round($mbYear, 2),
            'in_a_year_mb' => round($size['total_mb'] + $mbYear, 2),
            'old_log_rows' => $oldRows, 'freeable_mb' => round($freeable, 2),
            'redundant' => count($redundant),
            'frag_mb' => round(array_sum(array_column($frag, 'free_mb')), 2),
        ];
    });
    foreach (['total_mb', 'free_mb', 'grow_mb_year', 'in_a_year_mb', 'freeable_mb', 'frag_mb'] as $k) $val[$k] = (float)$val[$k];
    return $val;
}
