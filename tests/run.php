<?php
// Regression suite for the money, stock and permission rules.
// Run from the project root:  php tests/run.php
//
// Everything runs inside ONE transaction that is rolled back at the end,
// so the suite is safe to run against a copy of live data and leaves the
// database exactly as it found it.

if (PHP_SAPI !== 'cli') { http_response_code(403); die('CLI only'); }

require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/lib.php';

echo "\n\033[1mAK Computer — regression suite\033[0m\n";
echo str_repeat('─', 58) . "\n";

$pdo = db();
$pdo->beginTransaction();

try {
    foreach (['money', 'payments', 'stock', 'permissions', 'health', 'settlement', 'dashboard', 'customer', 'purchase', 'ai', 'market', 'campaign', 'forecast', 'scaling', 'reports', 'api'] as $suite) {
        require __DIR__ . '/test_' . $suite . '.php';
    }
} catch (Throwable $e) {
    echo "\n\033[31mSUITE CRASHED:\033[0m " . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
    $GLOBALS['_t']['fail']++;
    $GLOBALS['_t']['fails'][] = 'suite crashed: ' . $e->getMessage();
}

$pdo->rollBack(); // nothing the tests did is kept

// ...but the dashboard's cache is a FILE, and a file does not roll back.
// A suite run renders those summaries from its own fixtures, and the JSON
// left behind would then be served to a live owner for up to its TTL - real
// screens showing numbers that no longer exist in the database. Dropping it
// here costs one cache miss and closes that window.
foreach (glob(__DIR__ . '/../uploads/cache/*.json') as $stale) @unlink($stale);
echo "\n\033[90m(all test data rolled back)\033[0m\n";
exit(t_summary());
