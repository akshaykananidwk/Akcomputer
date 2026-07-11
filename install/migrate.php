<?php
// Self-healing database updater.
//
// Problem this solves: every code update after v1 needed a matching SQL
// file (upgrade_v2.sql, v3.sql, ...) run by hand in phpMyAdmin. If that
// step is missed, the uploaded PHP code references tables/columns that
// don't exist yet and pages break or silently misbehave (exactly what
// happened when files were uploaded without running the SQL).
//
// This page re-runs schema.sql (fully idempotent - every statement is
// CREATE TABLE IF NOT EXISTS) plus every install/upgrade_v*.sql in order,
// and treats "already exists" errors (duplicate column/table/key) as
// success instead of failure, so it is always safe to click "Update
// Database Now" after uploading new files - whether it's the first time
// or the tenth time. No SQL file needs to be picked by hand ever again.
require_once __DIR__ . '/../includes/init.php';
require_perm('settings.edit');

// MySQL error codes that simply mean "this was already applied"
const ALREADY_APPLIED_CODES = [1050, 1060, 1061, 1062, 1091];

function split_sql($sql) {
    $stmts = preg_split('/;\s*\n/', $sql);
    return array_values(array_filter(array_map('trim', $stmts)));
}

function run_migration_file($label, $sql, &$log) {
    $pdo = db();
    $applied = 0; $already = 0; $failed = 0;
    foreach (split_sql($sql) as $stmt) {
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

$result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'run') {
    $log = [];
    $totals = ['applied' => 0, 'already' => 0, 'failed' => 0];
    $files = ['schema.sql' => file_get_contents(__DIR__ . '/schema.sql')];
    $upgradeFiles = glob(__DIR__ . '/upgrade_v*.sql');
    natsort($upgradeFiles);
    foreach ($upgradeFiles as $f) $files[basename($f)] = file_get_contents($f);

    foreach ($files as $label => $sql) {
        list($a, $al, $f) = run_migration_file($label, $sql, $log);
        $totals['applied'] += $a; $totals['already'] += $al; $totals['failed'] += $f;
    }
    set_setting('db_last_migrated', date('Y-m-d H:i:s'));
    log_activity('db_migrate', "applied={$totals['applied']} already={$totals['already']} failed={$totals['failed']}");
    $result = ['totals' => $totals, 'log' => $log];
}

// live status: which tables/columns from recent versions actually exist
function schema_check() {
    $checks = [
        'bank_accounts table' => "SHOW TABLES LIKE 'bank_accounts'",
        'payment_methods table' => "SHOW TABLES LIKE 'payment_methods'",
        'web_orders table' => "SHOW TABLES LIKE 'web_orders'",
        'sales.is_cancelled' => "SHOW COLUMNS FROM sales LIKE 'is_cancelled'",
        'sales.discount_type' => "SHOW COLUMNS FROM sales LIKE 'discount_type'",
        'sales.bank_account_id' => "SHOW COLUMNS FROM sales LIKE 'bank_account_id'",
        'sales.last_reminder' => "SHOW COLUMNS FROM sales LIKE 'last_reminder'",
        'purchases.discount_type' => "SHOW COLUMNS FROM purchases LIKE 'discount_type'",
        'purchases.is_cancelled' => "SHOW COLUMNS FROM purchases LIKE 'is_cancelled'",
        'payments.party_id nullable' => "SHOW COLUMNS FROM payments LIKE 'party_id'",
        'payments.bank_account_id' => "SHOW COLUMNS FROM payments LIKE 'bank_account_id'",
        'expenses.bank_account_id' => "SHOW COLUMNS FROM expenses LIKE 'bank_account_id'",
        'items.margin_pct' => "SHOW COLUMNS FROM items LIKE 'margin_pct'",
        'items.item_type' => "SHOW COLUMNS FROM items LIKE 'item_type'",
        'parties.service_center type' => "SHOW COLUMNS FROM parties LIKE 'type'",
    ];
    $out = [];
    foreach ($checks as $label => $sql) {
        try {
            $r = row($sql);
            $ok = (bool)$r;
            if ($label === 'payments.party_id nullable' && $r) $ok = ($r['Null'] === 'YES');
            if ($label === 'parties.service_center type' && $r) $ok = (strpos($r['Type'], 'service_center') !== false);
            $out[$label] = $ok;
        } catch (Exception $e) {
            $out[$label] = false;
        }
    }
    return $out;
}
$status = schema_check();
$allOk = !in_array(false, $status, true);

$page_title = 'Database Update';
include __DIR__ . '/../includes/header.php';
?>
<div class="card">
  <h2>🔄 Database Update</h2>
  <p class="muted mb">Server પર નવી files upload કરો પછી અહીં "Update Database Now" દબાવવાનું — code ને જોઈતા બધા tables/columns આપોઆપ ઉમેરાઈ જશે. પહેલેથી હોય એ છોડી દેશે, ફરીથી ક્લિક કરવામાં પણ કંઈ નુકસાન નથી (safe to click multiple times).</p>
  <?php if (setting('db_last_migrated')): ?><p class="muted mb">Last run: <?= e(setting('db_last_migrated')) ?></p><?php endif; ?>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="run">
    <button class="btn btn-block" type="submit">⚡ Update Database Now</button>
  </form>
</div>

<?php if ($result): ?>
<div class="card">
  <h2><?= $result['totals']['failed'] ? '⚠️ થોડું ધ્યાન આપવા જેવું' : '✅ Database Up To Date' ?></h2>
  <div class="grid-stats" style="margin-bottom:0">
    <div class="stat s-ok"><div class="stat-label">નવું લાગુ થયું</div><div class="stat-value"><?= $result['totals']['applied'] ?></div></div>
    <div class="stat"><div class="stat-label">પહેલેથી હતું</div><div class="stat-value"><?= $result['totals']['already'] ?></div></div>
    <div class="stat <?= $result['totals']['failed'] ? 's-bad' : '' ?>"><div class="stat-label">Failed</div><div class="stat-value"><?= $result['totals']['failed'] ?></div></div>
  </div>
  <?php if ($result['log']): ?>
  <table class="table-sm mt">
    <thead><tr><th>File</th><th>Statement</th><th>Error</th></tr></thead>
    <tbody><?php foreach ($result['log'] as $l): ?>
      <tr><td><?= e($l['file']) ?></td><td><code style="font-size:11px"><?= e($l['stmt']) ?></code></td><td class="muted"><?= e($l['error']) ?></td></tr>
    <?php endforeach; ?></tbody>
  </table>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="card">
  <h2><?= $allOk ? '✅' : '⚠️' ?> Schema Status (live check)</h2>
  <p class="muted mb">આ list જોઈને ખબર પડે કે server ના database માં ખરેખર શું છે અત્યારે.</p>
  <table class="table-sm">
    <?php foreach ($status as $label => $ok): ?>
    <tr><td><?= e($label) ?></td><td class="right"><?= $ok ? '<span class="badge badge-ok">✓ છે</span>' : '<span class="badge badge-bad">✗ ખૂટે છે</span>' ?></td></tr>
    <?php endforeach; ?>
  </table>
  <?php if (!$allOk): ?><p class="flash flash-error mt">ઉપર "Update Database Now" દબાવો — ખૂટતી વસ્તુ આપોઆપ ઉમેરાઈ જશે.</p><?php endif; ?>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
