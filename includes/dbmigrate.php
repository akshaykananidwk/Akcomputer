<?php
// Shared DB migration runner - re-runs schema.sql + every install/upgrade_v*.sql,
// treating "already exists" errors as success. Used by both install/migrate.php
// (manual link) and the GitHub auto-updater (runs automatically after a pull).

// MySQL error codes that simply mean "this was already applied"
const ALREADY_APPLIED_CODES = [1050, 1060, 1061, 1062, 1091];

function dbmigrate_split_sql($sql) {
    $stmts = preg_split('/;\s*\n/', $sql);
    return array_values(array_filter(array_map('trim', $stmts)));
}

function dbmigrate_run_file($label, $sql, &$log) {
    $pdo = db();
    $applied = 0; $already = 0; $failed = 0;
    foreach (dbmigrate_split_sql($sql) as $stmt) {
        if ($stmt === '') continue;
        try {
            $pdo->exec($stmt);
            $applied++;
        } catch (PDOException $e) {
            $code = (int)($e->errorInfo[1] ?? 0);
            if (in_array($code, ALREADY_APPLIED_CODES, true)) {
                $already++;
            } else {
                $failed++;
                $log[] = ['file' => $label, 'stmt' => mb_substr($stmt, 0, 120), 'error' => $e->getMessage()];
            }
        }
    }
    return [$applied, $already, $failed];
}

/** Re-runs install/schema.sql + every install/upgrade_v*.sql against the current DB. */
function run_all_migrations() {
    $installDir = __DIR__ . '/../install';
    $log = [];
    $totals = ['applied' => 0, 'already' => 0, 'failed' => 0];
    $files = ['schema.sql' => file_get_contents($installDir . '/schema.sql')];
    $upgradeFiles = glob($installDir . '/upgrade_v*.sql');
    natsort($upgradeFiles);
    foreach ($upgradeFiles as $f) $files[basename($f)] = file_get_contents($f);

    foreach ($files as $label => $sql) {
        list($a, $al, $f) = dbmigrate_run_file($label, $sql, $log);
        $totals['applied'] += $a; $totals['already'] += $al; $totals['failed'] += $f;
    }
    set_setting('db_last_migrated', date('Y-m-d H:i:s'));
    log_activity('db_migrate', "applied={$totals['applied']} already={$totals['already']} failed={$totals['failed']}");
    return ['totals' => $totals, 'log' => $log];
}
