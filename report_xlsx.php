<?php
// Server-side Excel (.xlsx) export for any Reports tab - the "Excel" export
// button was CSV-only before (client-side, reading the rendered HTML
// table). This renders the exact same includes/report_body.php used by
// reports.php/report_pdf.php (same output-buffering + <table> parsing
// approach as report_pdf.php) and writes real .xlsx bytes via the
// hand-rolled includes/xlsx_writer.php - no PhpSpreadsheet/composer needed.
require_once __DIR__ . '/includes/init.php';
require_perm('reports.view');

$r = get('r', 'daily');
$from = get('from', date('Y-m-01'));
$to = get('to', today());
$fCompany = (int)get('f_company');
$fParty = (int)get('f_party');
$fStatus = get('f_status');
$bankId = (int)get('bank_id');
$glAccount = (int)get('gl_account');
$stockLoc = (int)get('stock_loc');
$fname = get('fname');

$selectedCols = null;
if (get('cols') !== '') {
    $selectedCols = array_values(array_unique(array_map('intval', explode(',', get('cols')))));
    sort($selectedCols);
}

ob_start();
include __DIR__ . '/includes/report_body.php';
$html = ob_get_clean();

$doc = new DOMDocument();
libxml_use_internal_errors(true);
$doc->loadHTML('<?xml encoding="UTF-8">' . $html);
libxml_clear_errors();
$tables = $doc->getElementsByTagName('table');

$rows = [];
foreach ($tables as $table) {
    foreach ($table->getElementsByTagName('tr') as $tr) {
        $cellNodes = [];
        foreach ($tr->childNodes as $c) if (in_array($c->nodeName, ['th', 'td'], true)) $cellNodes[] = $c;
        if ($selectedCols !== null) {
            $cellNodes = array_values(array_filter($cellNodes, fn($c, $i) => in_array($i, $selectedCols, true), ARRAY_FILTER_USE_BOTH));
        }
        if (!$cellNodes) continue;
        $rows[] = array_map(fn($c) => trim(preg_replace('/\s+/u', ' ', $c->textContent)), $cellNodes);
    }
    $rows[] = []; // blank row between multiple tables (e.g. GST tab has one per firm)
}

$bytes = xlsx_build($rows);
$outName = $fname !== '' ? preg_replace('/[^A-Za-z0-9 _\-]/', '', $fname) : 'report_' . $r . '_' . $from . '_to_' . $to;
if ($outName === '') $outName = 'report';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $outName . '.xlsx"');
header('Content-Length: ' . strlen($bytes));
echo $bytes;
log_activity('report_xlsx_export', "$r $from to $to");
