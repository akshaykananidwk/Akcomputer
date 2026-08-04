<?php
// MASTER CRON - the ONLY cron the server needs. Point your hosting cron at
// this file EVERY 1 MINUTE:
//   cPanel : * * * * *  wget -qO- "https://your-site/cron.php?key=YOUR_CRON_KEY"
//   CLI    : * * * * *  php /path/to/cron.php YOUR_CRON_KEY
// The scheduler inside (includes/cron_jobs.php) decides which registered
// jobs are actually due, runs them under a lock (never twice at once), and
// records every execution - manage it all from Admin → Cron Manager.
// Optional: &job=<id> runs one specific job immediately (used by the admin
// panel's "Run now" button, works externally too).
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/cron_jobs.php';

$key = PHP_SAPI === 'cli' ? ($argv[1] ?? '') : get('key');
$cronKey = setting('cron_key', '');
if ($cronKey === '') {
    // first run bootstraps the key so admin can copy it from Settings
    $cronKey = bin2hex(random_bytes(12));
    set_setting('cron_key', $cronKey);
}
if (!hash_equals($cronKey, (string)$key)) {
    http_response_code(403);
    die("Invalid cron key. Copy the correct cron URL from the Settings page.\n");
}

header('Content-Type: text/plain; charset=UTF-8');
$job = PHP_SAPI === 'cli' ? ($argv[2] ?? '') : get('job');
$only = $job !== '' && isset(cron_jobs()[$job]) ? [$job] : [];

$results = cron_run_all($only, (bool)$only);
foreach ($results as $r) {
    echo strtoupper($r['status']) . '  ' . $r['job'] . ' — ' . $r['detail'] . "\n";
}
echo 'Master cron tick done (' . count($results) . " job(s) ran) @ " . date('Y-m-d H:i:s') . "\n";
