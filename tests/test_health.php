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
// A bill that claims more than the ledger — the over-claim class of bug.
// The check reports the ten WORST offenders, so the fixture is deliberately
// large: that keeps the test deterministic on a shop that already has real
// over-claiming parties in its data.
$p = t_party();
$b = t_sale($p, 9000000);
t_payment($p, 8000000); // paid, but never linked to the bill
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

// ---------------------------------------------------------------------------
// Regressions reported from the live site on 2026-08-14.
// ---------------------------------------------------------------------------

t_group('a scope that lands in a JOIN is pointed at its own table');
// The live dashboard threw "Column 'location_id' in WHERE is ambiguous" and a
// 500 for every ?loc=N. A scope fragment carries no table name, which is fine
// until the query JOINs - created_by only exists on sales so it never
// complained, but location_id sits on sale_items too.
t_ok('an empty scope is left alone', scope_for('', 's') === '');
t_ok('created_by is pointed at the alias', scope_for(' AND created_by = ? ', 's') === ' AND s.created_by = ? ');
t_ok('location_id is too', scope_for(' AND location_id = ?', 's') === ' AND s.location_id = ?');
t_ok('both at once', scope_for(' AND created_by = ?  AND location_id = ?', 's')
     === ' AND s.created_by = ?  AND s.location_id = ?');
t_ok('an already-qualified column is not double-prefixed',
     strpos(scope_for(' AND s.location_id = ?', 's'), 's.s.') === false);

// the actual query that crashed, run for real
$loc = (int)val('SELECT id FROM locations ORDER BY id LIMIT 1');
$crashed = false;
try {
    $scope = scope_for(' AND created_by = ? AND location_id = ?', 's');
    all("SELECT DATE_FORMAT(s.sale_date, '%Y-%m') ym, COALESCE(SUM(si.qty * i.purchase_price),0) c
         FROM sale_items si JOIN sales s ON s.id = si.sale_id JOIN items i ON i.id = si.item_id
         WHERE s.is_cancelled = 0 AND s.sale_date BETWEEN ? AND ? $scope GROUP BY ym",
        [date('Y-m-01', strtotime('-2 months')), today(), 1, $loc]);
} catch (Throwable $e) { $crashed = true; }
t_ok('the joined dashboard cost query runs with a location filter', !$crashed);

$crashed2 = false;
try { ai_dashboard_insights(' AND created_by = ? AND location_id = ?', [1, $loc]); }
catch (Throwable $e) { $crashed2 = true; }
t_ok('and so do the dashboard insights, which is what actually 500d', !$crashed2);

t_group('a non-GST invoice prints without warnings');
// pdf.php wrote two "undefined variable" warnings into the live error log on
// every non-GST invoice: the GST column positions are only set inside
// if ($hasGst), but a closure's use() list captures them either way.
require_once dirname(__DIR__) . '/includes/pdf.php';
$noGst = row("SELECT s.*, c.name company_name, c.gstin, c.is_gst, c.address c_address, c.phone c_phone,
                     c.terms c_terms, l.name loc_name, l.city loc_city, p.name party_name, p.gstin party_gstin
              FROM sales s JOIN companies c ON c.id = s.company_id JOIN locations l ON l.id = s.location_id
              LEFT JOIN parties p ON p.id = s.party_id
              WHERE c.is_gst = 0 AND s.is_cancelled = 0 ORDER BY s.id DESC LIMIT 1");
if (!$noGst) {
    t_ok('(no non-GST bill in this database to print)', true);
} else {
    $pdfItems = all("SELECT si.*, COALESCE(i.name, '(deleted item)') name, i.unit
                     FROM sale_items si LEFT JOIN items i ON i.id = si.item_id
                     WHERE si.sale_id = ? AND si.qty > 0", [$noGst['id']]);
    foreach (['1', '2'] as $design) {
        set_setting('invoice_design', $design);
        $warnings = [];
        set_error_handler(function ($n, $m) use (&$warnings) { $warnings[] = $m; return true; });
        $bytes = invoice_pdf($noGst, $pdfItems);
        restore_error_handler();
        t_ok("design $design still produces a PDF", strlen($bytes) > 1000);
        t_ok("design $design prints no warnings", $warnings === [], implode(' | ', array_unique($warnings)));
    }
    set_setting('invoice_design', '1');
}
