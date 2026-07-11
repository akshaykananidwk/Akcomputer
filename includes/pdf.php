<?php
// Minimal PDF writer (no external libraries - works on any shared hosting).
// Enough for clean text invoices: Helvetica text, lines, filled rectangles.
// A4 = 595 x 842 points, origin bottom-left (we expose top-based Y helpers).

class MiniPDF {
    private $pages = [];
    private $cur = '';
    const W = 595, H = 842;

    function new_page() {
        if ($this->cur !== '') $this->pages[] = $this->cur;
        $this->cur = '';
    }
    private function esc($s) {
        // PDF strings are Latin-1; transliterate what we can, drop the rest
        $s = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', (string)$s);
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], (string)$s);
    }
    /** $y measured from TOP of page. $style: '' normal, 'B' bold */
    function text($x, $y, $size, $str, $style = '', $rgb = [0, 0, 0]) {
        $font = $style === 'B' ? '/F2' : '/F1';
        $yy = self::H - $y;
        $this->cur .= sprintf("BT %.3F %.3F %.3F rg %s %d Tf %.2F %.2F Td (%s) Tj ET\n",
            $rgb[0], $rgb[1], $rgb[2], $font, $size, $x, $yy, $this->esc($str));
    }
    function text_right($xRight, $y, $size, $str, $style = '', $rgb = [0, 0, 0]) {
        $w = strlen(@iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', (string)$str)) * $size * 0.5;
        $this->text($xRight - $w, $y, $size, $str, $style, $rgb);
    }
    function line($x1, $y1, $x2, $y2, $width = 0.7, $rgb = [0, 0, 0]) {
        $this->cur .= sprintf("%.3F %.3F %.3F RG %.2F w %.2F %.2F m %.2F %.2F l S\n",
            $rgb[0], $rgb[1], $rgb[2], $width, $x1, self::H - $y1, $x2, self::H - $y2);
    }
    function rect($x, $y, $w, $h, $rgb = [0.9, 0.9, 0.9]) {
        $this->cur .= sprintf("%.3F %.3F %.3F rg %.2F %.2F %.2F %.2F re f\n",
            $rgb[0], $rgb[1], $rgb[2], $x, self::H - $y - $h, $w, $h);
    }

    function output() {
        if ($this->cur !== '') { $this->pages[] = $this->cur; $this->cur = ''; }
        if (!$this->pages) $this->pages[] = '';
        $n = count($this->pages);
        $objs = [];
        // 1: catalog, 2: pages, 3: F1, 4: F2, then page objects + streams
        $kids = [];
        for ($i = 0; $i < $n; $i++) $kids[] = (5 + $i * 2) . ' 0 R';
        $objs[1] = "<< /Type /Catalog /Pages 2 0 R >>";
        $objs[2] = "<< /Type /Pages /Kids [" . implode(' ', $kids) . "] /Count $n >>";
        $objs[3] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>";
        $objs[4] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>";
        foreach ($this->pages as $i => $stream) {
            $pid = 5 + $i * 2;
            $sid = $pid + 1;
            $objs[$pid] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] " .
                          "/Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents $sid 0 R >>";
            $objs[$sid] = "stream:" . $stream;
        }
        $out = "%PDF-1.4\n";
        $offsets = [];
        ksort($objs);
        foreach ($objs as $num => $body) {
            $offsets[$num] = strlen($out);
            if (strpos($body, 'stream:') === 0) {
                $stream = substr($body, 7);
                $out .= "$num 0 obj\n<< /Length " . strlen($stream) . " >>\nstream\n$stream\nendstream\nendobj\n";
            } else {
                $out .= "$num 0 obj\n$body\nendobj\n";
            }
        }
        $xrefPos = strlen($out);
        $maxObj = max(array_keys($objs));
        $out .= "xref\n0 " . ($maxObj + 1) . "\n0000000000 65535 f \n";
        for ($i = 1; $i <= $maxObj; $i++) {
            $out .= isset($offsets[$i]) ? sprintf("%010d 00000 n \n", $offsets[$i]) : "0000000000 65535 f \n";
        }
        $out .= "trailer\n<< /Size " . ($maxObj + 1) . " /Root 1 0 R >>\nstartxref\n$xrefPos\n%%EOF";
        return $out;
    }
}

/** Build the invoice PDF bytes for a sale row + items (same data as sale_view). */
function invoice_pdf($sale, $items) {
    $pdf = new MiniPDF();
    $L = 40; $R = 555;
    $y = 50;

    // header band
    $pdf->rect($L - 10, $y - 20, $R - $L + 20, 58, [0.10, 0.34, 0.86]);
    $pdf->text($L, $y, 20, $sale['company_name'], 'B', [1, 1, 1]);
    $pdf->text($L, $y + 16, 9, trim(($sale['c_address'] ?? '') . ' ' . $sale['loc_name'] . ', ' . $sale['loc_city']), '', [1, 1, 1]);
    $pdf->text($L, $y + 27, 9, trim(($sale['c_phone'] ? 'Ph: ' . $sale['c_phone'] . '  ' : '') . ($sale['is_gst'] && $sale['gstin'] ? 'GSTIN: ' . $sale['gstin'] : '')), '', [1, 1, 1]);
    $pdf->text_right($R, $y, 15, $sale['is_gst'] ? 'TAX INVOICE' : 'INVOICE', 'B', [1, 1, 1]);
    $pdf->text_right($R, $y + 16, 10, $sale['invoice_no'], 'B', [1, 1, 1]);
    $pdf->text_right($R, $y + 27, 9, 'Date: ' . dmy($sale['sale_date']), '', [1, 1, 1]);

    $y += 60;
    $pdf->text($L, $y, 10, 'Bill To: ' . ($sale['customer_name'] ?: $sale['party_name'] ?: 'Walk-in Customer'), 'B');
    if ($sale['customer_mobile']) $pdf->text($L, $y + 13, 9, 'Mobile: ' . $sale['customer_mobile']);
    if (!empty($sale['party_gstin'])) $pdf->text($L + 200, $y + 13, 9, 'GSTIN: ' . $sale['party_gstin']);
    $y += 30;

    // table head
    $pdf->rect($L - 4, $y - 11, $R - $L + 8, 16, [0.93, 0.95, 0.98]);
    $pdf->text($L, $y, 9, '#', 'B');
    $pdf->text($L + 18, $y, 9, 'Item', 'B');
    $pdf->text_right($R - 200, $y, 9, 'Qty', 'B');
    $pdf->text_right($R - 130, $y, 9, 'Rate', 'B');
    if ($sale['is_gst']) $pdf->text_right($R - 75, $y, 9, 'GST%', 'B');
    $pdf->text_right($R, $y, 9, 'Amount', 'B');
    $y += 8;
    $pdf->line($L - 4, $y, $R + 4, $y);
    $y += 14;

    foreach ($items as $n => $it) {
        if ($y > 700) { $pdf->new_page(); $y = 50; }
        $pdf->text($L, $y, 9, ($n + 1) . '');
        $name = mb_substr($it['name'], 0, 52);
        $pdf->text($L + 18, $y, 9, $name);
        $pdf->text_right($R - 200, $y, 9, (float)$it['qty'] . ' ' . $it['unit']);
        $pdf->text_right($R - 130, $y, 9, money($it['price']));
        if ($sale['is_gst']) $pdf->text_right($R - 75, $y, 9, (float)$it['tax_rate'] . '%');
        $pdf->text_right($R, $y, 9, money($it['total']));
        $y += 13;
        if ($it['serials']) {
            $pdf->text($L + 18, $y, 7.5, 'SN: ' . mb_substr($it['serials'], 0, 80), '', [0.4, 0.45, 0.5]);
            $y += 11;
        }
    }
    $y += 4;
    $pdf->line($L - 4, $y, $R + 4, $y);
    $y += 16;

    // totals
    $tx = $R - 190;
    $pdf->text($tx, $y, 10, 'Subtotal');
    $pdf->text_right($R, $y, 10, 'Rs ' . money($sale['subtotal']));
    $y += 14;
    if ($sale['discount'] > 0) {
        $pdf->text($tx, $y, 10, 'Discount');
        $pdf->text_right($R, $y, 10, '- Rs ' . money($sale['discount']));
        $y += 14;
    }
    if ($sale['is_gst']) {
        $pdf->text($tx, $y, 10, 'CGST');
        $pdf->text_right($R, $y, 10, 'Rs ' . money($sale['tax_amount'] / 2));
        $y += 14;
        $pdf->text($tx, $y, 10, 'SGST');
        $pdf->text_right($R, $y, 10, 'Rs ' . money($sale['tax_amount'] / 2));
        $y += 14;
    }
    $pdf->line($tx, $y - 8, $R, $y - 8, 1.1);
    $pdf->text($tx, $y + 4, 12, 'TOTAL', 'B');
    $pdf->text_right($R, $y + 4, 12, 'Rs ' . money($sale['total']), 'B');
    $y += 18;
    $pdf->text($tx, $y, 10, 'Paid (' . $sale['payment_mode'] . ')');
    $pdf->text_right($R, $y, 10, 'Rs ' . money($sale['paid']));
    $y += 14;
    $due = $sale['total'] - $sale['paid'];
    if ($due > 0.009) {
        $pdf->text($tx, $y, 11, 'Balance Due', 'B', [0.8, 0.15, 0.15]);
        $pdf->text_right($R, $y, 11, 'Rs ' . money($due), 'B', [0.8, 0.15, 0.15]);
        $y += 14;
    }
    $y += 14;
    if (!empty($sale['c_terms'])) {
        foreach (array_slice(explode("\n", $sale['c_terms']), 0, 3) as $tl) {
            $pdf->text($L, $y, 8, $tl, '', [0.45, 0.5, 0.55]);
            $y += 10;
        }
    }
    $pdf->text($L, 790, 8, 'This is a computer generated invoice.', '', [0.55, 0.58, 0.62]);
    return $pdf->output();
}
