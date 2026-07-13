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
// success instead of failure. It runs automatically the moment this page
// is opened (no button to click) - just bookmark this link and open it
// after every file upload. Existing data is never touched; it only adds
// whatever tables/columns are missing. Safe to open any number of times.
// OPcache must be cleared BEFORE anything below requires helpers.php /
// any other app file - otherwise this very request compiles/executes
// whatever stale bytecode OPcache already had cached, and only takes
// effect for requests AFTER this one. That ordering bug is why the
// Code Self-Test below could show FAIL on the very run that fixes it.
$opcacheCleared = false;
if (function_exists('opcache_reset')) { $opcacheCleared = opcache_reset(); }

require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/dbmigrate.php';
require_perm('settings.edit');

// Runs automatically on every visit to this page (GET or POST) - opening
// the link IS the action, nothing to click.
$result = run_all_migrations();

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
        'sales.shipping' => "SHOW COLUMNS FROM sales LIKE 'shipping'",
        'purchases.shipping' => "SHOW COLUMNS FROM purchases LIKE 'shipping'",
        'amc_contracts table' => "SHOW TABLES LIKE 'amc_contracts'",
        'sales.amc_contract_id' => "SHOW COLUMNS FROM sales LIKE 'amc_contract_id'",
        'tasks.signature' => "SHOW COLUMNS FROM tasks LIKE 'signature'",
        'sites table' => "SHOW TABLES LIKE 'sites'",
        'item_batches table' => "SHOW TABLES LIKE 'item_batches'",
        'payments.party_id nullable' => "SHOW COLUMNS FROM payments LIKE 'party_id'",
        'payments.bank_account_id' => "SHOW COLUMNS FROM payments LIKE 'bank_account_id'",
        'expenses.bank_account_id' => "SHOW COLUMNS FROM expenses LIKE 'bank_account_id'",
        'items.margin_pct' => "SHOW COLUMNS FROM items LIKE 'margin_pct'",
        'items.item_type' => "SHOW COLUMNS FROM items LIKE 'item_type'",
        'parties.service_center type' => "SHOW COLUMNS FROM parties LIKE 'type'",
        'parties.loyalty_points' => "SHOW COLUMNS FROM parties LIKE 'loyalty_points'",
        'loyalty_ledger table' => "SHOW TABLES LIKE 'loyalty_ledger'",
        'feedback table' => "SHOW TABLES LIKE 'feedback'",
        'sites.next_visit_date' => "SHOW COLUMNS FROM sites LIKE 'next_visit_date'",
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

// Self-test: proves whether this SERVER is actually running the code in
// this file's own folder, or old cached code (the classic symptom is a
// bug that keeps "coming back" after every fix - amount_in_words() has
// been reported wrong 3 times and is provably correct in the current
// source every time it's checked, which points at stale code, not a bug).
// If this ever shows FAIL, the server is NOT running the uploaded files.
$selfTests = [
    ['label' => 'amount_in_words(2868.58)', 'got' => amount_in_words(2868.58),
     'want' => 'Two Thousand Eight Hundred Sixty Eight Rupees and Fifty Eight Paise Only'],
    ['label' => 'amount_in_words(3374.80)', 'got' => amount_in_words(3374.80),
     'want' => 'Three Thousand Three Hundred Seventy Four Rupees and Eighty Paise Only'],
];
$selfTestOk = true;
foreach ($selfTests as $t) if ($t['got'] !== $t['want']) $selfTestOk = false;

$page_title = 'Database Update';
include __DIR__ . '/../includes/header.php';
?>
<div class="card">
  <h2><?= $result['totals']['failed'] ? '⚠️ A few things need attention' : '✅ Database Update Done' ?></h2>
  <p class="muted mb">Opening this link auto-updates the database — nothing to click. Old data (bills, parties, stock — everything) stays exactly as it is; only whatever new tables/columns the code needs get added. Re-open this link after uploading files to the server — opening it any number of times causes no harm.</p>
  <p class="muted mb">Last run: <?= e(setting('db_last_migrated')) ?></p>
  <p class="mb"><?= $opcacheCleared ? '✅ Also cleared the PHP code cache - the server will use the new files immediately.' : '<span class="muted">PHP code cache (OPcache) is not enabled on this server - uploaded files take effect immediately, nothing needed here.</span>' ?></p>
  <div class="grid-stats" style="margin-bottom:0">
    <div class="stat s-ok"><div class="stat-label">Applied</div><div class="stat-value"><?= $result['totals']['applied'] ?></div></div>
    <div class="stat"><div class="stat-label">Already existed</div><div class="stat-value"><?= $result['totals']['already'] ?></div></div>
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
  <a class="btn btn-outline mt" href="<?= e($_SERVER['REQUEST_URI']) ?>">🔄 Check Again</a>
</div>

<div class="card">
  <h2><?= $allOk ? '✅' : '⚠️' ?> Schema Status (live check)</h2>
  <p class="muted mb">This list shows what's actually in the server's database right now.</p>
  <table class="table-sm">
    <?php foreach ($status as $label => $ok): ?>
    <tr><td><?= e($label) ?></td><td class="right"><?= $ok ? '<span class="badge badge-ok">✓ present</span>' : '<span class="badge badge-bad">✗ missing</span>' ?></td></tr>
    <?php endforeach; ?>
  </table>
  <?php if (!$allOk): ?><p class="flash flash-error mt">This page just ran the auto-update, yet something ✗ missing shows above — check the "Failed" table above, or press "🔄 Check Again".</p><?php endif; ?>
</div>

<div class="card">
  <h2><?= $selfTestOk ? '✅' : '🚨' ?> Code Self-Test (is the server actually running the new code?)</h2>
  <p class="muted mb">This test proves whether the server is running this folder's current files, not old cached code. The "Amount in words" complaint keeps coming back even though no bug is found in the code - if ❌ FAIL shows below, that confirms the server is running old code (re-upload the files + ask hosting support to "restart OPcache/PHP cache"). If everything shows ✅ PASS, the code is correct.</p>
  <table class="table-sm">
    <thead><tr><th>Test</th><th>Server computed</th><th>Should be</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($selfTests as $t): $pass = $t['got'] === $t['want']; ?>
    <tr>
      <td><?= e($t['label']) ?></td>
      <td><?= e($t['got']) ?></td>
      <td><?= e($t['want']) ?></td>
      <td><?= $pass ? '<span class="badge badge-ok">✓ PASS</span>' : '<span class="badge badge-bad">✗ FAIL</span>' ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php if (!$selfTestOk): ?>
  <p class="flash flash-error mt">🚨 The server is running old code! Re-upload the files, and ask the hosting company whether they can restart PHP OPcache/cache.</p>
  <?php else: ?>
  <p class="flash flash-success mt">✅ The server is using today's files. If "Amount in words" still looks wrong, send the exact invoice number so it can be checked against the data.</p>
  <?php endif; ?>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
