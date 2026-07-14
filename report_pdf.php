<?php
// PDF export for any Reports tab. Renders the exact same includes/report_body.php
// used by reports.php (captured via output buffering) so the PDF can never
// drift from what's shown on screen, then converts the HTML <table>s found
// in that output into pages via the existing MiniPDF writer (same one
// invoices use) - no external PDF library needed.
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/pdf.php';
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

// Optional column picker from the "What to display?" export dialog: a
// comma-separated list of 0-based column indices (matching the source
// table's <th>/<td> position) to include. Absent/empty means "all columns",
// so plain report_pdf.php links (e.g. from elsewhere in the app) keep working.
$selectedCols = null;
if (get('cols') !== '') {
    $selectedCols = array_values(array_unique(array_map('intval', explode(',', get('cols')))));
    sort($selectedCols);
}

ob_start();
include __DIR__ . '/includes/report_body.php';
$html = ob_get_clean();

$tabLabels = [
    'business' => 'Business Report', 'daily' => 'Daily Sales', 'sales' => 'Item-wise Sales',
    'party_sales' => 'Party-wise Sales', 'aging' => 'Aging / Collection', 'purchase' => 'Purchase',
    'vendor_perf' => 'Vendor Performance', 'stockval' => 'Stock Report', 'cashbook' => 'Cashbook',
    'bank_ledger' => 'Bank Ledger',
    'expense' => 'Expenses', 'gst' => 'GST', 'profit' => 'Profit', 'bill_profit' => 'Bill Profit',
    'staff' => 'Staff Stock', 'repair_tat' => 'Repair TAT', 'warranty_tat' => 'Warranty TAT',
    'tech_sla' => 'Technician SLA', 'forecast' => 'AI Sales Forecast', 'low' => 'Low Stock', 'dead_stock' => 'Dead / Slow-moving Stock',
    'general_ledger' => 'General Ledger', 'trial_balance' => 'Trial Balance',
    'balance_sheet' => 'Balance Sheet', 'profit_loss' => 'Profit & Loss',
    'activity' => 'Activity Log', 'login_history' => 'Login History',
];
$title = $tabLabels[$r] ?? 'Report';

$doc = new DOMDocument();
libxml_use_internal_errors(true);
$doc->loadHTML('<?xml encoding="UTF-8">' . $html);
libxml_clear_errors();
$tables = $doc->getElementsByTagName('table');

function pdf_cell_text($node) {
    return trim(preg_replace('/\s+/u', ' ', $node->textContent));
}

$pdf = new MiniPDF();
$pdf->new_page();
$M = 36; $pageW = MiniPDF::W - $M * 2;
$y = $M;

$pdf->text($M, $y, 16, $title, 'B'); $y += 20;
$pdf->text($M, $y, 10, dmy($from) . ' to ' . dmy($to), '', [0.4, 0.4, 0.4]); $y += 14;
$filterBits = [];
if ($fCompany) { $c = row('SELECT name FROM companies WHERE id=?', [$fCompany]); if ($c) $filterBits[] = 'Firm: ' . $c['name']; }
if ($fParty) { $p = row('SELECT name FROM parties WHERE id=?', [$fParty]); if ($p) $filterBits[] = 'Party: ' . $p['name']; }
if ($fStatus) $filterBits[] = 'Status: ' . ucfirst($fStatus);
if ($r === 'bank_ledger' && $bankId) { $b = row('SELECT account_name, bank_name FROM bank_accounts WHERE id=?', [$bankId]); if ($b) $filterBits[] = 'Bank: ' . $b['account_name'] . ' - ' . $b['bank_name']; }
if ($filterBits) { $pdf->text($M, $y, 9, 'Filters: ' . implode(' | ', $filterBits), '', [0.4, 0.4, 0.4]); $y += 14; }
$y += 6;

if ($tables->length === 0) {
    $pdf->text($M, $y, 11, 'No data for this report / filter combination.');
} else {
    foreach ($tables as $table) {
        $rowsNodes = $table->getElementsByTagName('tr');
        if ($rowsNodes->length === 0) continue;
        // column count from the widest row
        $colCount = 0;
        foreach ($rowsNodes as $tr) $colCount = max($colCount, $tr->getElementsByTagName('th')->length + $tr->getElementsByTagName('td')->length);
        if ($colCount === 0) continue;
        $effCount = $selectedCols !== null ? count(array_filter($selectedCols, fn($i) => $i < $colCount)) : $colCount;
        if ($effCount === 0) continue;

        // Column width hints: a <th data-w="55"> means this column gets 55%
        // of the table width instead of an equal share - used by reports
        // like Bank Ledger where the Description column carries much longer
        // text than the Amount/Balance columns. Falls back to an equal split
        // (the old behaviour) when a table doesn't define any hints.
        $widthHints = [];
        foreach ($rowsNodes as $tr) {
            if ($tr->getElementsByTagName('th')->length > 0) {
                $i = 0;
                foreach ($tr->childNodes as $c) {
                    if ($c->nodeName === 'th') { $hint = $c->getAttribute('data-w'); if ($hint !== '') $widthHints[$i] = (float)$hint; $i++; }
                }
                break;
            }
        }
        $colIdx = $selectedCols !== null ? array_values(array_filter(range(0, $colCount - 1), fn($i) => in_array($i, $selectedCols, true))) : range(0, $colCount - 1);
        if (count($widthHints) === $colCount && array_sum($widthHints) > 0) {
            $hintTotal = array_sum(array_map(fn($i) => $widthHints[$i] ?? 0, $colIdx));
            $colWidths = array_map(fn($i) => $pageW * ($widthHints[$i] ?? 0) / $hintTotal, $colIdx);
        } else {
            $colWidths = array_fill(0, $effCount, $pageW / $effCount);
        }

        // Draws one row at the CURRENT $y (top of the row) and advances $y past
        // it. Header rows get a shaded background band and an underline drawn
        // at the row's own bottom edge (not the following row's), and are
        // reprinted at the top of every new page so a table that spans pages
        // never loses its column labels.
        $drawRow = function ($cells, $isHeader) use ($pdf, &$y, $M, $pageW, $colWidths) {
            if ($isHeader) $pdf->rect($M, $y - 10, $pageW, 14, [0.90, 0.92, 0.96]);
            $x = $M;
            foreach ($cells as $i => $c) {
                [$txt, $isNum] = $c;
                $w = $colWidths[$i] ?? ($pageW / max(count($cells), 1));
                if ($txt !== '') {
                    $fit = $pdf->fit($txt, $w - 4, 8, $isHeader ? 'B' : '');
                    if ($isNum) $pdf->text_right($x + $w - 2, $y, 8, $fit, $isHeader ? 'B' : '');
                    else $pdf->text($x + 2, $y, 8, $fit, $isHeader ? 'B' : '');
                }
                $x += $w;
            }
            if ($isHeader) {
                // Underline sits just below the header's own baseline (clear
                // of its descenders) rather than at the row-height boundary,
                // which used to land right at the next row's cap-height and
                // strike through its text.
                $pdf->line($M, $y + 4, $M + $pageW, $y + 4);
                $y += 18;
            } else {
                $y += 14;
            }
        };

        $headerCells = null;
        foreach ($rowsNodes as $ri => $tr) {
            $cellNodes = [];
            foreach ($tr->childNodes as $c) if (in_array($c->nodeName, ['th', 'td'], true)) $cellNodes[] = $c;
            if ($selectedCols !== null) {
                $cellNodes = array_values(array_filter($cellNodes, fn($c, $i) => in_array($i, $selectedCols, true), ARRAY_FILTER_USE_BOTH));
            }
            $isHeader = $tr->getElementsByTagName('th')->length > 0;
            $cells = array_map(fn($c) => [pdf_cell_text($c), strpos((string)$c->getAttribute('class'), 'num') !== false], $cellNodes);
            if ($isHeader) $headerCells = $cells;

            if ($y > MiniPDF::H - $M - 20) {
                $pdf->new_page(); $y = $M;
                if (!$isHeader && $headerCells) $drawRow($headerCells, true);
            }
            $drawRow($cells, $isHeader);
        }
        $y += 16; // gap between multiple tables (e.g. GST tab has one per firm)
        if ($y > MiniPDF::H - $M - 20) { $pdf->new_page(); $y = $M; }
    }
}

$bytes = $pdf->output();
$outName = $fname !== '' ? preg_replace('/[^A-Za-z0-9 _\-]/', '', $fname) : 'report_' . $r . '_' . $from . '_to_' . $to;
if ($outName === '') $outName = 'report';
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $outName . '.pdf"');
header('Content-Length: ' . strlen($bytes));
echo $bytes;
log_activity('report_pdf_export', "$r $from to $to");
