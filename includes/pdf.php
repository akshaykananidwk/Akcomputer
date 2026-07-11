<?php
// Minimal PDF writer (no external libraries - works on any shared hosting).
// Supports Helvetica text (with real AFM character widths, not guessed),
// lines, filled rectangles, and embedded PNG images (for the payment QR).
// A4 = 595 x 842 points, origin bottom-left (we expose top-based Y helpers).

// Standard Adobe AFM widths (1/1000 em) for Helvetica / Helvetica-Bold,
// codes 32-126. Used for accurate right/center text alignment - a rough
// "0.5 * size per char" guess previously made bold headings (e.g. "TAX
// INVOICE") overflow past the page edge.
function pdf_helvetica_widths($bold = false) {
    static $normal = null, $b = null;
    if ($normal === null) {
        $normal = [278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278,
            556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 278, 278, 584, 584, 584, 556,
            1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778,
            667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 278, 278, 278, 469, 556,
            333, 556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556,
            556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500, 334, 260, 334, 584];
        $b = [278, 333, 474, 556, 556, 889, 722, 238, 333, 333, 389, 584, 278, 333, 278, 278,
            556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 333, 333, 584, 584, 584, 611,
            975, 722, 722, 722, 722, 667, 611, 778, 722, 278, 556, 722, 611, 833, 722, 778,
            667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 333, 278, 333, 584, 556,
            333, 556, 611, 556, 611, 556, 333, 611, 611, 278, 278, 556, 278, 889, 611, 611,
            611, 611, 389, 556, 333, 611, 556, 778, 556, 556, 500, 389, 280, 389, 584];
    }
    return $bold ? $b : $normal;
}
function pdf_text_width($str, $size, $bold = false) {
    $w = pdf_helvetica_widths($bold);
    $s = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', (string)$str);
    if ($s === false) $s = preg_replace('/[^\x20-\x7E]/', '?', (string)$str);
    $total = 0;
    for ($i = 0, $n = strlen($s); $i < $n; $i++) {
        $c = ord($s[$i]);
        $total += ($c >= 32 && $c <= 126) ? $w[$c - 32] : 556;
    }
    return $total / 1000 * $size;
}

class MiniPDF {
    private $pages = [];
    private $cur = '';
    private $imgs = [];
    const W = 595, H = 842;

    function new_page() {
        if ($this->cur !== '') $this->pages[] = $this->cur;
        $this->cur = '';
    }
    private function esc($s) {
        $s = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', (string)$s);
        if ($s === false) $s = preg_replace('/[^\x20-\x7E]/', '?', (string)$s);
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $s);
    }
    /** $y measured from TOP of page. $style: '' normal, 'B' bold */
    function text($x, $y, $size, $str, $style = '', $rgb = [0, 0, 0]) {
        $font = $style === 'B' ? '/F2' : '/F1';
        $yy = self::H - $y;
        $this->cur .= sprintf("BT %.3F %.3F %.3F rg %s %d Tf %.2F %.2F Td (%s) Tj ET\n",
            $rgb[0], $rgb[1], $rgb[2], $font, $size, $x, $yy, $this->esc($str));
    }
    function text_right($xRight, $y, $size, $str, $style = '', $rgb = [0, 0, 0]) {
        $w = pdf_text_width($str, $size, $style === 'B');
        $this->text($xRight - $w, $y, $size, $str, $style, $rgb);
    }
    function text_center($xCenter, $y, $size, $str, $style = '', $rgb = [0, 0, 0]) {
        $w = pdf_text_width($str, $size, $style === 'B');
        $this->text($xCenter - $w / 2, $y, $size, $str, $style, $rgb);
    }
    /** Truncate $str so it fits within $maxWidth points at $size/$style. */
    function fit($str, $maxWidth, $size, $style = '') {
        if (pdf_text_width($str, $size, $style === 'B') <= $maxWidth) return $str;
        $lo = 0; $hi = mb_strlen($str);
        while ($lo < $hi) {
            $mid = intdiv($lo + $hi + 1, 2);
            $t = mb_substr($str, 0, $mid) . '…';
            if (pdf_text_width($t, $size, $style === 'B') <= $maxWidth) $lo = $mid; else $hi = $mid - 1;
        }
        return $lo > 0 ? mb_substr($str, 0, $lo) . '…' : '';
    }
    function line($x1, $y1, $x2, $y2, $width = 0.7, $rgb = [0, 0, 0]) {
        $this->cur .= sprintf("%.3F %.3F %.3F RG %.2F w %.2F %.2F m %.2F %.2F l S\n",
            $rgb[0], $rgb[1], $rgb[2], $width, $x1, self::H - $y1, $x2, self::H - $y2);
    }
    function rect($x, $y, $w, $h, $rgb = [0.9, 0.9, 0.9]) {
        $this->cur .= sprintf("%.3F %.3F %.3F rg %.2F %.2F %.2F %.2F re f\n",
            $rgb[0], $rgb[1], $rgb[2], $x, self::H - $y - $h, $w, $h);
    }

    /** Load a PNG and register it as an embeddable image. Returns an image id or null. */
    function load_png($path) {
        if (!$path || !is_file($path) || !function_exists('imagecreatefrompng')) return null;
        $im = @imagecreatefrompng($path);
        if (!$im) return null;
        $w = imagesx($im); $h = imagesy($im);
        // flatten onto white (QR codes have no meaningful transparency for print)
        $bg = imagecreatetruecolor($w, $h);
        imagefill($bg, 0, 0, imagecolorallocate($bg, 255, 255, 255));
        imagecopy($bg, $im, 0, 0, 0, 0, $w, $h);
        imagedestroy($im);
        $raw = '';
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $rgb = imagecolorat($bg, $x, $y);
                $raw .= chr(($rgb >> 16) & 0xFF) . chr(($rgb >> 8) & 0xFF) . chr($rgb & 0xFF);
            }
        }
        imagedestroy($bg);
        $id = 'Im' . (count($this->imgs) + 1);
        $this->imgs[$id] = ['w' => $w, 'h' => $h, 'data' => gzcompress($raw, 6)];
        return $id;
    }
    function image($id, $x, $y, $w, $h) {
        if (!$id || !isset($this->imgs[$id])) return;
        $yy = self::H - $y - $h;
        $this->cur .= sprintf("q %.2F 0 0 %.2F %.2F %.2F cm /%s Do Q\n", $w, $h, $x, $yy, $id);
    }

    function output() {
        if ($this->cur !== '') { $this->pages[] = $this->cur; $this->cur = ''; }
        if (!$this->pages) $this->pages[] = '';
        $n = count($this->pages);
        $objs = [];
        // 1 catalog, 2 pages, 3/4 fonts, 5..(5+imgN-1) images, then page/content pairs
        $imgIds = array_keys($this->imgs);
        $imgObjNum = [];
        $next = 5;
        foreach ($imgIds as $iid) { $imgObjNum[$iid] = $next; $next++; }
        $pageStart = $next;

        $kids = [];
        for ($i = 0; $i < $n; $i++) $kids[] = ($pageStart + $i * 2) . ' 0 R';
        $objs[1] = "<< /Type /Catalog /Pages 2 0 R >>";
        $objs[2] = "<< /Type /Pages /Kids [" . implode(' ', $kids) . "] /Count $n >>";
        $objs[3] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>";
        $objs[4] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>";
        foreach ($this->imgs as $iid => $img) {
            $objs[$imgObjNum[$iid]] = "imgstream:" . json_encode(['w' => $img['w'], 'h' => $img['h']]) . '|' . $img['data'];
        }
        $xobjDict = '';
        foreach ($imgIds as $iid) $xobjDict .= "/$iid " . $imgObjNum[$iid] . " 0 R ";
        foreach ($this->pages as $i => $stream) {
            $pid = $pageStart + $i * 2;
            $sid = $pid + 1;
            $res = "/Font << /F1 3 0 R /F2 4 0 R >>" . ($xobjDict ? " /XObject << $xobjDict >>" : '');
            $objs[$pid] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << $res >> /Contents $sid 0 R >>";
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
            } elseif (strpos($body, 'imgstream:') === 0) {
                list($meta, $data) = explode('|', substr($body, 10), 2);
                $meta = json_decode($meta, true);
                $out .= "$num 0 obj\n<< /Type /XObject /Subtype /Image /Width {$meta['w']} /Height {$meta['h']} " .
                        "/ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /FlateDecode /Length " . strlen($data) . " >>\nstream\n$data\nendstream\nendobj\n";
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
    $accent = invoice_theme_accent_rgb();
    $bank = default_bank_account();
    $qrId = null;
    if ($bank && $bank['upi_id']) {
        $qrPath = qr_png_path(upi_uri($bank['upi_id'], $bank['account_name'], $sale['total'], $sale['invoice_no']));
        if ($qrPath) $qrId = $pdf->load_png($qrPath);
    }

    // header band
    $pdf->rect($L - 10, $y - 20, $R - $L + 20, 58, $accent);
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
        $name = $pdf->fit($it['name'], 210, 9);
        $pdf->text($L + 18, $y, 9, $name);
        $pdf->text_right($R - 200, $y, 9, (float)$it['qty'] . ' ' . $it['unit']);
        $pdf->text_right($R - 130, $y, 9, money($it['price']));
        if ($sale['is_gst']) $pdf->text_right($R - 75, $y, 9, (float)$it['tax_rate'] . '%');
        $pdf->text_right($R, $y, 9, money($it['total']));
        $y += 13;
        if ($it['serials']) {
            $pdf->text($L + 18, $y, 7.5, $pdf->fit('SN: ' . $it['serials'], 320, 7.5), '', [0.4, 0.45, 0.5]);
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
        $dLabel = !empty($sale['discount_type']) && $sale['discount_type'] === 'percent' && $sale['discount_pct'] > 0
            ? 'Discount (' . rtrim(rtrim(number_format($sale['discount_pct'], 2), '0'), '.') . '%)' : 'Discount';
        $pdf->text($tx, $y, 10, $dLabel);
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
    $y += 4;
    $pdf->text($L, $y, 9, $pdf->fit('Amount in words: ' . amount_in_words($sale['total']), $R - $L, 9), 'B');
    $y += 16;

    // GST slab breakdown
    if ($sale['is_gst'] && $sale['tax_amount'] > 0) {
        $slabs = [];
        foreach ($items as $it) {
            $tr = (float)$it['tax_rate'];
            if ($tr > 0) $slabs[(string)$tr] = ($slabs[(string)$tr] ?? 0) + (float)$it['total'];
        }
        ksort($slabs);
        $pdf->rect($L - 4, $y - 10, 330, 14, [0.93, 0.95, 0.98]);
        $pdf->text($L, $y, 8.5, 'GST Slab', 'B');
        $pdf->text($L + 70, $y, 8.5, 'Taxable', 'B');
        $pdf->text($L + 140, $y, 8.5, 'CGST', 'B');
        $pdf->text($L + 210, $y, 8.5, 'SGST', 'B');
        $pdf->text($L + 280, $y, 8.5, 'Total Tax', 'B');
        $y += 13;
        foreach ($slabs as $tr => $tv) {
            $tx2 = $tv * (float)$tr / 100;
            $pdf->text($L, $y, 8.5, $tr . '%');
            $pdf->text($L + 70, $y, 8.5, money($tv));
            $pdf->text($L + 140, $y, 8.5, money($tx2 / 2));
            $pdf->text($L + 210, $y, 8.5, money($tx2 / 2));
            $pdf->text($L + 280, $y, 8.5, money($tx2));
            $y += 12;
        }
        $y += 6;
    }

    // bank details + QR (payment info block)
    if ($bank || $qrId) {
        $blockTop = $y;
        if ($bank) {
            $pdf->text($L, $y, 9, 'Pay via Bank Transfer:', 'B');
            $y += 13;
            $pdf->text($L, $y, 8.5, $bank['account_name'] . ' - ' . $bank['bank_name']);
            $y += 11;
            $pdf->text($L, $y, 8.5, 'A/C No: ' . $bank['account_number'] . '   IFSC: ' . $bank['ifsc']);
            $y += 11;
            if ($bank['branch']) { $pdf->text($L, $y, 8.5, 'Branch: ' . $bank['branch']); $y += 11; }
        }
        if ($qrId) {
            $pdf->image($qrId, $R - 85, $blockTop - 4, 78, 78);
            $pdf->text_center($R - 46, $blockTop + 80, 7.5, 'Scan & Pay', '', [0.4, 0.45, 0.5]);
            $y = max($y, $blockTop + 92);
        }
        $y += 6;
    }

    if (!empty($sale['c_terms'])) {
        foreach (array_slice(explode("\n", $sale['c_terms']), 0, 3) as $tl) {
            $pdf->text($L, $y, 8, $pdf->fit($tl, $R - $L, 8), '', [0.45, 0.5, 0.55]);
            $y += 10;
        }
    }
    // signature block
    $sy = max($y + 44, 740);
    $pdf->line($L, $sy, $L + 150, $sy, 0.8);
    $pdf->text($L + 20, $sy + 12, 8.5, "Receiver's Signature");
    $pdf->line($R - 190, $sy, $R, $sy, 0.8);
    $pdf->text_center($R - 95, $sy + 12, 8.5, 'For ' . $sale['company_name'] . ' - Authorised Signatory');
    $pdf->text($L, 800, 8, 'This is a computer generated invoice.', '', [0.55, 0.58, 0.62]);
    return $pdf->output();
}
