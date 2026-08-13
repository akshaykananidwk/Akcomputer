<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); die('CLI only'); }
// The Data Health Check runs 24 hand-written SQL queries against live data.
// A typo or a renamed column would only surface as a broken page in front of
// the owner, so the suite executes every one of them here.

require_once dirname(__DIR__) . '/includes/health.php';

t_group('every data health check actually runs');
$checks = health_checks();
t_ok('health_checks() returns a list', count($checks) > 0, count($checks) . ' checks');

$bad = [];
foreach ($checks as $c) {
    if (!isset($c['t'], $c['gu'], $c['rows'], $c['sev'])) $bad[] = ($c['t'] ?? '?') . ' (missing key)';
    elseif (!is_array($c['rows'])) $bad[] = $c['t'] . ' (rows not an array)';
    elseif (!in_array($c['sev'], ['bad', 'warn'], true)) $bad[] = $c['t'] . ' (bad severity)';
    elseif (trim($c['gu']) === '') $bad[] = $c['t'] . ' (no Gujarati explanation)';
}
t_ok('every check has a title, a Gujarati fix-it note, rows and a severity', empty($bad), implode('; ', $bad));

// health_issue_summary() is what the weekly cron sends the owner
$sum = health_issue_summary();
t_ok('issue summary is a title => count map', is_array($sum));
foreach ($sum as $title => $n) {
    if (!is_string($title) || !is_int($n)) { t_ok('summary entries are name => number', false, $title); break; }
}

t_group('the checks catch a problem that is really there');
// a bill that claims more than the ledger — the over-claim class of bug
$p = t_party();
$b = t_sale($p, 5000);
t_payment($p, 4000); // paid, but never linked to the bill
$over = null;
foreach (health_checks() as $c) if (strpos($c['t'], 'more than the ledger') !== false) $over = $c;
t_ok('the over-claim check exists', $over !== null);
$found = false;
foreach ($over['rows'] ?? [] as $r) if ((int)$r['id'] === (int)$p) $found = true;
t_ok('an unlinked payment shows up as an over-claiming party', $found);

// and a payment allocated beyond its own value
$p2 = t_party();
$b2 = t_sale($p2, 9000);
$pay = t_payment($p2, 1000, 'in', 'cash', [['sale', $b2, 1000]]);
q('INSERT INTO payment_allocations (payment_id, ref_type, ref_id, amount) VALUES (?, ?, ?, ?)', [$pay, 'sale', $b2, 5000]);
$overAlloc = null;
foreach (health_checks() as $c) if (strpos($c['t'], 'more than it is worth') !== false) $overAlloc = $c;
$found2 = false;
foreach ($overAlloc['rows'] ?? [] as $r) if ((int)$r['id'] === (int)$pay) $found2 = true;
t_ok('a payment linked beyond its value is caught', $found2);
