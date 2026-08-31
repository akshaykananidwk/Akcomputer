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
        [month_start(2), today(), 1, $loc]);
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


// ---------------------------------------------------------------------------
// Reported from the shop: on bills of about a dozen lines the QR code and the
// bottom of the invoice were simply missing from the PDF.
// ---------------------------------------------------------------------------

t_group('an invoice never runs off the bottom of the paper');
require_once dirname(__DIR__) . '/includes/pdf.php';

/** How far below the sheet anything is drawn (0 = nothing is). Read out of the
 *  generated PDF itself: text is placed with "x y Td" and boxes with
 *  "x y w h re", both measured from the BOTTOM, so anything off the paper has
 *  a negative y. This measures what the customer actually receives. */
function t_pdf_overflow($bytes) {
    $worst = 0.0;
    $off = 0;
    while (($s = strpos($bytes, "stream\n", $off)) !== false) {
        $e = strpos($bytes, 'endstream', $s);
        if ($e === false) break;
        $raw = substr($bytes, $s + 7, $e - $s - 7);
        $body = @gzuncompress($raw);
        if ($body === false) $body = $raw;
        if (preg_match_all('/([\d.-]+) ([\d.-]+) Td/', $body, $m))
            foreach ($m[2] as $y) $worst = min($worst, (float)$y);
        if (preg_match_all('/([\d.-]+) ([\d.-]+) ([\d.-]+) ([\d.-]+) re/', $body, $m))
            foreach ($m[2] as $i => $y) $worst = min($worst, (float)$y, (float)$y + (float)$m[4][$i]);
        $off = $e + 9;
    }
    return -$worst;   // points past the bottom edge; 0 when nothing is
}

$hCo = row('SELECT * FROM companies LIMIT 1');
$hLoc = row('SELECT * FROM locations LIMIT 1');
$hParty = row('SELECT * FROM parties LIMIT 1');

function t_fake_invoice($co, $loc, $party, $n, $gst = 1) {
    $sale = [
        'id' => 1, 'invoice_no' => 'T-1', 'sale_date' => today(), 'due_date' => today(),
        'company_name' => $co['name'], 'gstin' => $co['gstin'] ?? '', 'is_gst' => $gst,
        'c_address' => $co['address'] ?? '', 'c_phone' => $co['phone'] ?? '', 'c_terms' => $co['terms'] ?? '',
        'loc_name' => $loc['name'], 'loc_city' => $loc['city'] ?? '',
        'party_name' => $party['name'], 'party_gstin' => $party['gstin'] ?? '',
        'customer_name' => $party['name'], 'customer_mobile' => $party['mobile'] ?? '',
        'subtotal' => 0, 'discount' => 0, 'discount_type' => 'amount', 'discount_pct' => 0,
        'tax_amount' => 0, 'shipping' => 0, 'adjustment' => 0, 'round_off' => 0,
        'total' => 0, 'paid' => 0, 'payment_mode' => 'cash', 'notes' => '',
        'share_token' => 'x', 'party_id' => $party['id'], 'loyalty_points_used' => 0, 'loyalty_discount' => 0,
    ];
    $items = []; $sub = 0;
    for ($i = 0; $i < $n; $i++) {
        $items[] = ['item_id' => 1, 'name' => 'D-Link RJ45 8P8C Cat6 UTP Modular Plug (100 Pack) NPG-5E1TRA031-100',
                    'unit' => 'PCS', 'qty' => 2, 'price' => 1250, 'total' => 2500, 'tax_rate' => 18,
                    'hsn' => '84733099', 'serials' => '', 'description' => '', 'cost_price' => 0,
                    'custom_data' => null, 'free_qty' => 0, 'line_disc' => 0];
        $sub += 2500;
    }
    $sale['subtotal'] = $sub;
    $sale['tax_amount'] = $gst ? round($sub * 0.18, 2) : 0;
    $sale['total'] = round($sub + $sale['tax_amount'], 2);
    return [$sale, $items];
}

$oldDesign = setting('invoice_design', '1');
// The exact counts that were reported broken, plus the neighbours around each
// break so a future change cannot quietly move the problem one line along.
$breakers = ['1' => [11, 12, 13, 14, 15, 29, 30, 31], '2' => [10, 11, 12, 28, 29, 30, 31, 47]];
foreach ($breakers as $design => $counts) {
    set_setting('invoice_design', $design);
    foreach ($counts as $n) {
        list($sale, $items) = t_fake_invoice($hCo, $hLoc, $hParty, $n);
        $over = t_pdf_overflow(invoice_pdf($sale, $items));
        t_ok("design $design, $n items: nothing below the page", $over <= 0.01, 'over by ' . round($over) . 'pt');
    }
}
// Without GST the totals box is shorter, which used to leave the QR card as the
// tall side and overflow by a few points instead of fifty.
set_setting('invoice_design', '2');
foreach ([12, 30] as $n) {
    list($sale, $items) = t_fake_invoice($hCo, $hLoc, $hParty, $n, 0);
    $over = t_pdf_overflow(invoice_pdf($sale, $items));
    t_ok("design 2, $n items, no GST: nothing below the page", $over <= 0.01, 'over by ' . round($over) . 'pt');
}

t_group('a short bill still fits on one sheet');
// The fix must not send every bill to two pages "to be safe" - that wastes
// paper and looks broken to the customer.
foreach (['1', '2'] as $design) {
    set_setting('invoice_design', $design);
    foreach ([1, 5, 8] as $n) {
        list($sale, $items) = t_fake_invoice($hCo, $hLoc, $hParty, $n);
        $bytes = invoice_pdf($sale, $items);
        t_ok("design $design, $n items is a single page", substr_count($bytes, '/Type /Page ') === 1);
    }
}
set_setting('invoice_design', $oldDesign);

t_group('the block under the items is measured, not guessed');
$pdfSrc = file_get_contents(dirname(__DIR__) . '/includes/pdf.php');
t_ok('there is one shared "does it fit" helper', strpos($pdfSrc, 'function pdf_room_for(') !== false);
t_ok('...and one shared height for the block', strpos($pdfSrc, 'function pdf_footer_height(') !== false);
t_ok('both designs use it', substr_count($pdfSrc, 'pdf_room_for($pdf, $y, pdf_footer_height(') === 2);
t_ok('the height follows the rows that will actually be drawn',
     strpos($pdfSrc, "\$hasGst ? 48 : 0") !== false);


t_group('nothing on an invoice is ever abbreviated');
// Reported with the bill: serial numbers came out as
// "SN: 202401154621,202401154645,202401154661,20240115466..." and the shop's
// own service description was chopped in half. A serial list on an invoice is
// the warranty record - it cannot carry an ellipsis.

/** Every piece of text in a generated PDF, in one string. */
function t_pdf_text($bytes) {
    $out = ''; $off = 0;
    while (($s = strpos($bytes, "stream\n", $off)) !== false) {
        $e = strpos($bytes, 'endstream', $s);
        if ($e === false) break;
        $b = @gzuncompress(substr($bytes, $s + 7, $e - $s - 7));
        if ($b === false) $b = substr($bytes, $s + 7, $e - $s - 7);
        if (preg_match_all('/\((.*?)\)\s*Tj/', $b, $m))
            foreach ($m[1] as $t) $out .= str_replace(['\(', '\)', '\\\\'], ['(', ')', '\\'], $t) . "\n";
        $off = $e + 9;
    }
    return $out;
}

$hSN   = '202401154621,202401154645,202401154661,202401154662,202401154663,202401154664';
$hDesc = 'Power Supply, Rack Box, Connectors & Hardware SMPS, NVR rack, BNC connectors, power cable and all fitting hardware';
$hName = 'Coreprix 4MP IP Dual Light Dome Network Camera (3.6mm Lens, Built-in Mic, PoE, 20m IR) (CPI-4M-DL-36)';

$hItems = [
    ['item_id' => 1, 'name' => $hName, 'unit' => 'PCS', 'qty' => 6, 'price' => 2100, 'total' => 12600,
     'tax_rate' => 18, 'hsn' => '85258900', 'serials' => $hSN, 'description' => '',
     'cost_price' => 0, 'custom_data' => null, 'free_qty' => 0, 'line_disc' => 0],
    ['item_id' => 2, 'name' => 'Service Charge', 'unit' => 'PCS', 'qty' => 1, 'price' => 4500, 'total' => 4500,
     'tax_rate' => 18, 'hsn' => '', 'serials' => '', 'description' => $hDesc,
     'cost_price' => 0, 'custom_data' => null, 'free_qty' => 0, 'line_disc' => 0],
];
list($hSale, ) = t_fake_invoice($hCo, $hLoc, $hParty, 0);
$hSale['subtotal'] = 17100; $hSale['tax_amount'] = 3078; $hSale['total'] = 20178;

$oldD = setting('invoice_design', '1');
foreach (['1', '2'] as $design) {
    set_setting('invoice_design', $design);
    $txt = t_pdf_text(invoice_pdf($hSale, $hItems));
    $flat = preg_replace('/\s+/', '', $txt);
    $has = fn($needle) => strpos($flat, preg_replace('/\s+/', '', $needle)) !== false;
    t_ok("design $design prints every serial number in full", $has($hSN));
    t_ok("design $design prints the whole service description", $has($hDesc));
    t_ok("design $design prints the whole item name", $has($hName));
    t_ok("design $design puts no ellipsis anywhere on the bill",
         strpos($txt, '...') === false && strpos($txt, "\xe2\x80\xa6") === false);
}
set_setting('invoice_design', $oldD);

t_group('a long serial list can be broken at all');
// The list is one enormous "word" with no spaces, so plain word wrapping could
// never split it - which is exactly why it used to be truncated instead.
require_once dirname(__DIR__) . '/includes/pdf.php';
$wrapped = pdf_wrap('SN: ' . $hSN, 7.3, false, 230);
t_ok('it wraps onto more than one line', count($wrapped) > 1);
t_eq('every character survives the wrap',
     preg_replace('/\s+/', '', implode('', $wrapped)), preg_replace('/\s+/', '', 'SN: ' . $hSN));
foreach ($wrapped as $i => $l)
    t_ok('line ' . ($i + 1) . ' fits the column', pdf_text_width($l, 7.3, false) <= 231);

$desc = pdf_wrap($hDesc, 7.3, false, 230);
t_eq('a wrapped description keeps its words and spacing',
     preg_replace('/\s+/', ' ', trim(implode(' ', $desc))), preg_replace('/\s+/', ' ', $hDesc));
t_ok('a short line is left alone', pdf_wrap('SN: KI9ZI78XJ480IT1D', 7.3, false, 230) === ['SN: KI9ZI78XJ480IT1D']);
t_ok('empty text is safe', pdf_wrap('', 7.3, false, 230) === ['']);
// A single unbroken run with no commas at all must still be split rather than lost.
$runOn = str_repeat('A', 400);
$hard = pdf_wrap($runOn, 7.3, false, 230);
t_ok('an unbroken run is broken by character rather than dropped', count($hard) > 1);
t_eq('...and still keeps every character', implode('', $hard), $runOn);

// -------------------------------------------------------- month arithmetic --
// This is the bug that made the dashboard's six-month trend show "Mar May May
// Jul Jul Aug" on 31 August, and made "last month vs the month before" compare
// July with July. Every assertion below is pinned to a fixed month-end date so
// the test does not quietly pass on the other 27 days of the month.
t_group('month arithmetic survives the 29th, 30th and 31st');
foreach (['2026-08-31', '2026-05-31', '2026-03-30', '2026-01-29', '2024-03-31'] as $anchor) {
    $six = [];
    for ($i = 5; $i >= 0; $i--) $six[] = month_key($i, $anchor);
    t_eq('six months from ' . $anchor . ' are six DIFFERENT months', count(array_unique($six)), 6);
    $consec = true;
    for ($k = 1; $k < 6; $k++)
        if ($six[$k] !== date('Y-m', strtotime($six[$k - 1] . '-01 +1 month'))) $consec = false;
    t_ok('...and they run consecutively (' . implode(' ', $six) . ')', $consec);
    t_eq('...ending on the anchor\'s own month', $six[5], date('Y-m', strtotime($anchor)));
}
t_eq('last month, from 31 Aug 2026', month_key(1, '2026-08-31'), '2026-07');
t_eq('the month before that', month_key(2, '2026-08-31'), '2026-06');
t_eq('a month start is always the 1st', month_start(2, '2026-08-31'), '2026-06-01');
t_eq('a month end is that month\'s own last day', month_end(1, '2026-08-31'), '2026-07-31');
t_eq('February is not given 31 days', month_end(6, '2026-08-31'), '2026-02-28');
t_eq('a leap February is', month_end(1, '2024-03-31'), '2024-02-29');
t_eq('a negative step goes forward', month_start(-1, '2026-01-31'), '2026-02-01');

// month_add() is the money one: an AMC billed monthly from the 31st used to
// jump to 3 March and never bill February at all.
$d = '2026-01-31'; $seen = [];
for ($k = 0; $k < 14; $k++) { $d = amc_advance_date($d, 'monthly'); $seen[] = date('Y-m', strtotime($d)); }
t_eq('14 monthly cycles from the 31st are 14 different months', count(array_unique($seen)), 14);
t_ok('...with no month skipped (' . implode(' ', array_slice($seen, 0, 4)) . ')',
     $seen[0] === '2026-02' && $seen[1] === '2026-03' && $seen[2] === '2026-04');
t_eq('...and February is clamped to its last day', amc_advance_date('2026-01-31', 'monthly'), '2026-02-28');
t_eq('a quarterly cycle from the 31st lands on a real date', amc_advance_date('2026-01-31', 'quarterly'), '2026-04-30');
t_eq('29 February advances a year to 28 February', amc_advance_date('2024-02-29', 'yearly'), '2025-02-28');
t_eq('an ordinary date is untouched', amc_advance_date('2026-03-15', 'monthly'), '2026-04-15');
t_eq('a monthly reminder from the 31st keeps its time and skips no month',
     reminder_next_at('2026-01-31 09:30:00', 'monthly'), '2026-02-28 09:30:00');
