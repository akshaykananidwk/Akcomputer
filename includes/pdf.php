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
/** Word-wrap $text to fit $maxWidth points at $size/$bold. Returns an array of lines. */
function pdf_wrap($text, $size, $bold, $maxWidth) {
    $words = preg_split('/\s+/', trim((string)$text));
    $lines = []; $cur = '';
    foreach ($words as $w) {
        $try = $cur === '' ? $w : "$cur $w";
        if (pdf_text_width($try, $size, $bold) > $maxWidth && $cur !== '') { $lines[] = $cur; $cur = $w; } else { $cur = $try; }
    }
    if ($cur !== '') $lines[] = $cur;
    return $lines ?: [''];
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
    private $pats = [];
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
    /** $y measured from TOP of page. $style: '' normal, 'B' bold, 'I' italic */
    function text($x, $y, $size, $str, $style = '', $rgb = [0, 0, 0]) {
        $font = $style === 'B' ? '/F2' : ($style === 'I' ? '/F3' : '/F1');
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
    /** Filled circle (4-bezier-arc approximation, kappa = 0.5523). $cy is top-based. */
    function circle($cx, $cy, $r, $rgb) {
        $y = self::H - $cy;
        $k = 0.5523 * $r;
        $this->cur .= sprintf("%.3F %.3F %.3F rg\n", $rgb[0], $rgb[1], $rgb[2]);
        $this->cur .= sprintf("%.2F %.2F m\n", $cx + $r, $y);
        $this->cur .= sprintf("%.2F %.2F %.2F %.2F %.2F %.2F c\n", $cx + $r, $y + $k, $cx + $k, $y + $r, $cx, $y + $r);
        $this->cur .= sprintf("%.2F %.2F %.2F %.2F %.2F %.2F c\n", $cx - $k, $y + $r, $cx - $r, $y + $k, $cx - $r, $y);
        $this->cur .= sprintf("%.2F %.2F %.2F %.2F %.2F %.2F c\n", $cx - $r, $y - $k, $cx - $k, $y - $r, $cx, $y - $r);
        $this->cur .= sprintf("%.2F %.2F %.2F %.2F %.2F %.2F c\n", $cx + $k, $y - $r, $cx + $r, $y - $k, $cx + $r, $y);
        $this->cur .= "f\n";
    }
    /** Circle outline only (ring), stroked at $w points wide. */
    function circle_stroke($cx, $cy, $r, $rgb, $w = 1) {
        $y = self::H - $cy;
        $k = 0.5523 * $r;
        $this->cur .= sprintf("%.3F %.3F %.3F RG %.2F w\n", $rgb[0], $rgb[1], $rgb[2], $w);
        $this->cur .= sprintf("%.2F %.2F m\n", $cx + $r, $y);
        $this->cur .= sprintf("%.2F %.2F %.2F %.2F %.2F %.2F c\n", $cx + $r, $y + $k, $cx + $k, $y + $r, $cx, $y + $r);
        $this->cur .= sprintf("%.2F %.2F %.2F %.2F %.2F %.2F c\n", $cx - $k, $y + $r, $cx - $r, $y + $k, $cx - $r, $y);
        $this->cur .= sprintf("%.2F %.2F %.2F %.2F %.2F %.2F c\n", $cx - $r, $y - $k, $cx - $k, $y - $r, $cx, $y - $r);
        $this->cur .= sprintf("%.2F %.2F %.2F %.2F %.2F %.2F c\n", $cx + $k, $y - $r, $cx + $r, $y - $k, $cx + $r, $y);
        $this->cur .= "h S\n";
    }
    /** Filled closed polygon. $pts = [[x,y], ...], $y top-based. */
    function poly($pts, $rgb) {
        if (count($pts) < 3) return;
        $this->cur .= sprintf("%.3F %.3F %.3F rg\n", $rgb[0], $rgb[1], $rgb[2]);
        foreach ($pts as $i => $p) {
            $this->cur .= sprintf("%.2F %.2F %s\n", $p[0], self::H - $p[1], $i === 0 ? 'm' : 'l');
        }
        $this->cur .= "h f\n";
    }
    private function rrect_path($x, $y, $w, $h, $r) {
        $x0 = $x; $y0 = self::H - $y - $h;
        $x1 = $x + $w; $y1 = self::H - $y;
        $r = min($r, $w / 2, $h / 2);
        $k = 0.5523 * $r;
        $p = sprintf("%.2F %.2F m\n", $x0 + $r, $y0);
        $p .= sprintf("%.2F %.2F l\n", $x1 - $r, $y0);
        $p .= sprintf("%.2F %.2F %.2F %.2F %.2F %.2F c\n", $x1 - $r + $k, $y0, $x1, $y0 + $r - $k, $x1, $y0 + $r);
        $p .= sprintf("%.2F %.2F l\n", $x1, $y1 - $r);
        $p .= sprintf("%.2F %.2F %.2F %.2F %.2F %.2F c\n", $x1, $y1 - $r + $k, $x1 - $r + $k, $y1, $x1 - $r, $y1);
        $p .= sprintf("%.2F %.2F l\n", $x0 + $r, $y1);
        $p .= sprintf("%.2F %.2F %.2F %.2F %.2F %.2F c\n", $x0 + $r - $k, $y1, $x0, $y1 - $r + $k, $x0, $y1 - $r);
        $p .= sprintf("%.2F %.2F l\n", $x0, $y0 + $r);
        $p .= sprintf("%.2F %.2F %.2F %.2F %.2F %.2F c\n", $x0, $y0 + $r - $k, $x0 + $r - $k, $y0, $x0 + $r, $y0);
        return $p;
    }
    /** Filled rounded rectangle. $y measured from TOP of page. */
    function rrect($x, $y, $w, $h, $r, $rgb) {
        $this->cur .= sprintf("%.3F %.3F %.3F rg\n", $rgb[0], $rgb[1], $rgb[2]) . $this->rrect_path($x, $y, $w, $h, $r) . "h f\n";
    }
    /** Rounded rectangle border/outline only. */
    function rrect_stroke($x, $y, $w, $h, $r, $rgb, $bw = 1) {
        $this->cur .= sprintf("%.3F %.3F %.3F RG %.2F w\n", $rgb[0], $rgb[1], $rgb[2], $bw) . $this->rrect_path($x, $y, $w, $h, $r) . "h S\n";
    }
    /** Rect filled with a real 2-colour axial gradient (PDF shading pattern) - not a
     *  banded approximation. $dir 'h' = rgb1 left -> rgb2 right, 'v' = rgb1 top -> rgb2 bottom. */
    function grad_rect($x, $y, $w, $h, $rgb1, $rgb2, $dir = 'h') {
        $id = 'Pt' . (count($this->pats) + 1);
        $y0 = self::H - $y - $h;
        $y1 = self::H - $y;
        $coords = $dir === 'v' ? [$x + $w / 2, $y1, $x + $w / 2, $y0] : [$x, $y0, $x + $w, $y0];
        $this->pats[$id] = ['c0' => $rgb1, 'c1' => $rgb2, 'coords' => $coords];
        $this->cur .= sprintf("q /Pattern cs /%s scn %.2F %.2F %.2F %.2F re f Q\n", $id, $x, $y0, $w, $h);
    }
    /** Rounded rect filled with a real axial gradient. */
    function grad_rrect($x, $y, $w, $h, $r, $rgb1, $rgb2, $dir = 'h') {
        $id = 'Pt' . (count($this->pats) + 1);
        $y0 = self::H - $y - $h;
        $y1 = self::H - $y;
        $coords = $dir === 'v' ? [$x + $w / 2, $y1, $x + $w / 2, $y0] : [$x, $y0, $x + $w, $y0];
        $this->pats[$id] = ['c0' => $rgb1, 'c1' => $rgb2, 'coords' => $coords];
        $this->cur .= sprintf("q /Pattern cs /%s scn\n", $id) . $this->rrect_path($x, $y, $w, $h, $r) . "h f Q\n";
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
        // 1 catalog, 2 pages, 3/4/5 fonts, then images, then 3 objs per gradient
        // pattern (function/shading/pattern), then page/content pairs
        $imgIds = array_keys($this->imgs);
        $imgObjNum = [];
        $next = 6;
        foreach ($imgIds as $iid) { $imgObjNum[$iid] = $next; $next++; }
        $patIds = array_keys($this->pats);
        $patObjNum = [];
        foreach ($patIds as $patId) { $patObjNum[$patId] = ['fn' => $next, 'sh' => $next + 1, 'pt' => $next + 2]; $next += 3; }
        $pageStart = $next;

        $kids = [];
        for ($i = 0; $i < $n; $i++) $kids[] = ($pageStart + $i * 2) . ' 0 R';
        $objs[1] = "<< /Type /Catalog /Pages 2 0 R >>";
        $objs[2] = "<< /Type /Pages /Kids [" . implode(' ', $kids) . "] /Count $n >>";
        $objs[3] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>";
        $objs[4] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>";
        $objs[5] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Oblique >>";
        foreach ($this->imgs as $iid => $img) {
            $objs[$imgObjNum[$iid]] = "imgstream:" . json_encode(['w' => $img['w'], 'h' => $img['h']]) . '|' . $img['data'];
        }
        foreach ($this->pats as $patId => $p) {
            $numFn = $patObjNum[$patId]['fn']; $numSh = $patObjNum[$patId]['sh']; $numPt = $patObjNum[$patId]['pt'];
            $objs[$numFn] = sprintf("<< /FunctionType 2 /Domain [0 1] /C0 [%.3F %.3F %.3F] /C1 [%.3F %.3F %.3F] /N 1 >>",
                $p['c0'][0], $p['c0'][1], $p['c0'][2], $p['c1'][0], $p['c1'][1], $p['c1'][2]);
            $objs[$numSh] = sprintf("<< /ShadingType 2 /ColorSpace /DeviceRGB /Coords [%.2F %.2F %.2F %.2F] /Function %d 0 R /Extend [true true] >>",
                $p['coords'][0], $p['coords'][1], $p['coords'][2], $p['coords'][3], $numFn);
            $objs[$numPt] = "<< /Type /Pattern /PatternType 2 /Shading $numSh 0 R >>";
        }
        $xobjDict = '';
        foreach ($imgIds as $iid) $xobjDict .= "/$iid " . $imgObjNum[$iid] . " 0 R ";
        $patDict = '';
        foreach ($patIds as $patId) $patDict .= "/$patId " . $patObjNum[$patId]['pt'] . " 0 R ";
        foreach ($this->pages as $i => $stream) {
            $pid = $pageStart + $i * 2;
            $sid = $pid + 1;
            $res = "/Font << /F1 3 0 R /F2 4 0 R /F3 5 0 R >>" . ($xobjDict ? " /XObject << $xobjDict >>" : '') . ($patDict ? " /Pattern << $patDict >>" : '');
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

/** The one fixed AK Computer bill design - colours used by both the PDF
 *  (this file) and the on-screen/print HTML view (assets/style.css). There
 *  used to be a 20-theme picker; it's gone, replaced by this single design. */
function invoice_colors() {
    return [
        'navy' => [0.059, 0.137, 0.255], 'navy_dark' => [0.035, 0.086, 0.169],
        'teal' => [0.043, 0.431, 0.580], 'teal_dark' => [0.031, 0.329, 0.447],
        'orange' => [1.0, 0.463, 0.247], 'gold' => [0.831, 0.686, 0.216],
        'purple' => [0.486, 0.227, 0.929], 'blue' => [0.145, 0.388, 0.922],
        'pink' => [0.816, 0.204, 0.518], 'red' => [0.769, 0.118, 0.118],
        'green' => [0.02, 0.5, 0.32],
        'row_alt' => [0.933, 0.965, 0.988], 'amber_bg' => [1.0, 0.925, 0.8],
        'gray' => [0.42, 0.47, 0.52], 'lavender_bg' => [0.965, 0.96, 0.99], 'lavender_border' => [0.82, 0.78, 0.95],
    ];
}
function pdf_icon_dot(&$pdf, $x, $y, $rgb) { $pdf->circle($x, $y, 2.2, $rgb); }

/** Build the invoice PDF bytes for a sale row + items (same data as sale_view). */
function invoice_pdf($sale, $items) {
    $pdf = new MiniPDF();
    $L = 36; $R = 559;
    $C = invoice_colors();
    $bank = default_bank_account();
    $qrId = null;
    if ($bank && $bank['upi_id']) {
        $qrPath = qr_png_path(upi_uri($bank['upi_id'], $bank['account_name'], $sale['total'], $sale['invoice_no']));
        if ($qrPath) $qrId = $pdf->load_png($qrPath);
    }
    $showTime = setting('add_time_transactions', '1') === '1' && !empty($sale['created_at']);
    $boxX = 345; $boxW = ($R - $boxX); $boxY = 18; $boxH = 108;

    // ---------- header: white paper with corner accents + logo (left), navy INVOICE box + seal (right) ----------
    $pdf->grad_rect(0, 0, $R + 36, 7, $C['teal'], $C['orange'], 'h');
    $pdf->poly([[0, 7], [64, 7], [0, 46]], $C['teal']);
    $pdf->poly([[$R + 36, 7], [$R + 36 - 58, 7], [$R + 36, 44]], $C['orange']);

    $y = 34;
    $pdf->text($L, $y, 25, 'AK', 'B', $C['blue']);
    $pdf->text($L + 26, $y, 25, 'COMPUTER', 'B', $C['navy']);
    $y += 15;
    $pdf->text($L, $y, 10, 'Smart Solutions, Better Future', 'I', $C['teal_dark']);
    $y += 16;
    $addrMaxW = $boxX - ($L + 10) - 8;
    $addrLines = array_slice(pdf_wrap(trim(($sale['c_address'] ?? '') . ' ' . $sale['loc_name'] . ', ' . $sale['loc_city']), 8.7, false, $addrMaxW), 0, 2);
    pdf_icon_dot($pdf, $L + 2, $y - 3, $C['teal']);
    foreach ($addrLines as $al) { $pdf->text($L + 10, $y, 8.7, $al, '', $C['gray']); $y += 11; }
    $y += 2;
    if ($sale['c_phone']) {
        pdf_icon_dot($pdf, $L + 2, $y - 3, $C['teal']);
        $pdf->text($L + 10, $y, 8.7, $sale['c_phone'], '', $C['gray']);
        $y += 13;
    }
    if ($sale['is_gst'] && $sale['gstin']) {
        pdf_icon_dot($pdf, $L + 2, $y - 3, $C['teal']);
        $pdf->text($L + 10, $y, 8.7, 'GSTIN: ' . $sale['gstin'], '', $C['gray']);
        $y += 13;
    }

    $pdf->rrect($boxX, $boxY, $boxW, $boxH, 10, $C['navy']);
    $by = $boxY + 24;
    $pdf->text($boxX + 16, $by, 19, $sale['is_gst'] ? 'TAX INVOICE' : 'INVOICE', 'B', [1, 1, 1]);
    $pdf->rect($boxX + 16, $by + 6, 36, 2.4, $C['orange']);
    $by += 24;
    $pdf->text($boxX + 16, $by, 9, 'Invoice No.', '', [0.8, 0.85, 0.92]);
    $pdf->text($boxX + 78, $by, 9, ': ' . $sale['invoice_no'], 'B', [1, 1, 1]);
    $by += 15;
    $pdf->text($boxX + 16, $by, 9, 'Date', '', [0.8, 0.85, 0.92]);
    $pdf->text($boxX + 78, $by, 9, ': ' . dmy($sale['sale_date']), 'B', [1, 1, 1]);
    if ($showTime) {
        $by += 15;
        $pdf->text($boxX + 16, $by, 9, 'Time', '', [0.8, 0.85, 0.92]);
        $pdf->text($boxX + 78, $by, 9, ': ' . date('h:i A', strtotime($sale['created_at'])), 'B', [1, 1, 1]);
    }
    // gold "thank you" seal overlapping the box's bottom edge
    $sealCx = $boxX + $boxW - 45; $sealCy = $boxY + $boxH;
    $pdf->circle($sealCx, $sealCy, 26, $C['gold']);
    $pdf->circle_stroke($sealCx, $sealCy, 26, $C['navy'], 1.6);
    $pdf->text_center($sealCx, $sealCy - 5, 6.2, 'THANK YOU', 'B', $C['navy_dark']);
    $pdf->text_center($sealCx, $sealCy + 5, 5.3, 'FOR YOUR', '', $C['navy_dark']);
    $pdf->text_center($sealCx, $sealCy + 13, 5.3, 'BUSINESS', '', $C['navy_dark']);

    $y = max($y, $boxY + $boxH) + 30;

    // ---------- BILL TO ----------
    $pdf->grad_rrect($L, $y - 13, 92, 19, 9.5, $C['teal'], $C['blue'], 'h');
    $pdf->text_center($L + 46, $y, 9, 'BILL TO:', 'B', [1, 1, 1]);
    $y += 22;
    $pdf->text($L, $y, 11.5, $sale['customer_name'] ?: $sale['party_name'] ?: 'Walk-in Customer', 'B', $C['navy']);
    if ($sale['customer_mobile']) {
        pdf_icon_dot($pdf, $L + 2, $y + 11, $C['teal']);
        $pdf->text($L + 10, $y + 14, 9, $sale['customer_mobile'], '', $C['gray']);
        $y += 14;
    }
    if (!empty($sale['party_gstin'])) { $pdf->text($L + 220, $y, 9, 'GSTIN: ' . $sale['party_gstin'], '', $C['gray']); }
    $y += 20;

    // ---------- item table ----------
    $hasHsn = $sale['is_gst'];
    $colQty = $R - ($hasHsn ? 220 : 195); $colRate = $R - ($hasHsn ? 150 : 130); $colGst = $R - 75;
    $pdf->rect($L, $y - 12, $R - $L, 18, $C['teal']);
    $pdf->text($L + 6, $y, 8.7, '#', 'B', [1, 1, 1]);
    $pdf->text($L + 22, $y, 8.7, 'ITEM DESCRIPTION', 'B', [1, 1, 1]);
    $pdf->text_right($colQty, $y, 8.7, 'QTY', 'B', [1, 1, 1]);
    $pdf->text_right($colRate, $y, 8.7, 'RATE (Rs)', 'B', [1, 1, 1]);
    if ($hasHsn) $pdf->text_right($colGst, $y, 8.7, 'GST%', 'B', [1, 1, 1]);
    $pdf->text_right($R - 6, $y, 8.7, 'AMOUNT (Rs)', 'B', [1, 1, 1]);
    $y += 12;

    foreach ($items as $n => $it) {
        if ($y > 690) {
            $pdf->new_page();
            $y = 40;
            $pdf->rect($L, $y - 12, $R - $L, 18, $C['teal']);
            $pdf->text($L + 6, $y, 8.7, '#', 'B', [1, 1, 1]);
            $pdf->text($L + 22, $y, 8.7, 'ITEM DESCRIPTION', 'B', [1, 1, 1]);
            $pdf->text_right($colQty, $y, 8.7, 'QTY', 'B', [1, 1, 1]);
            $pdf->text_right($colRate, $y, 8.7, 'RATE (Rs)', 'B', [1, 1, 1]);
            if ($hasHsn) $pdf->text_right($colGst, $y, 8.7, 'GST%', 'B', [1, 1, 1]);
            $pdf->text_right($R - 6, $y, 8.7, 'AMOUNT (Rs)', 'B', [1, 1, 1]);
            $y += 12;
        }
        $rowLines = 1 + ($it['serials'] ? 1 : 0);
        $rowH = $rowLines * 13 + 4;
        if ($n % 2 === 1) $pdf->rect($L, $y - 9, $R - $L, $rowH, $C['row_alt']);
        $pdf->text($L + 6, $y, 9, ($n + 1) . '');
        $pdf->text($L + 22, $y, 9, $pdf->fit($it['name'], $colQty - 55 - $L, 9));
        $pdf->text_right($colQty, $y, 9, (float)$it['qty'] . ' ' . $it['unit']);
        $pdf->text_right($colRate, $y, 9, money($it['price']));
        if ($hasHsn) $pdf->text_right($colGst, $y, 9, (float)$it['tax_rate'] . '%');
        $pdf->text_right($R - 6, $y, 9, money($it['total']));
        $y += 13;
        if ($it['serials']) {
            $pdf->text($L + 22, $y, 7.3, $pdf->fit('SN: ' . $it['serials'], 320, 7.3), '', $C['gray']);
            $y += 11;
        }
    }
    $y += 10;

    // ---------- payment block (left) + totals box (right) ----------
    $blockTop = $y;
    $tw = 210; $tx = $R - $tw;
    $lw = $tx - $L - 20;

    // left: pay via bank transfer
    $ly = $blockTop;
    if ($bank) {
        $pdf->grad_rrect($L, $ly - 13, 172, 19, 9.5, $C['purple'], $C['blue'], 'h');
        $pdf->text_center($L + 86, $ly, 9, 'PAY VIA BANK TRANSFER:', 'B', [1, 1, 1]);
        $ly += 16;
        $boxTop = $ly;
        $bh = 58;
        $pdf->rrect($L, $boxTop, $lw, $bh, 8, $C['lavender_bg']);
        $pdf->rrect_stroke($L, $boxTop, $lw, $bh, 8, $C['lavender_border'], 1);
        $iy = $boxTop + 15;
        $pdf->text($L + 10, $iy, 8, 'A/C Name', '', $C['gray']); $pdf->text($L + 70, $iy, 8.3, $pdf->fit($bank['account_name'], $lw - 80, 8.3), 'B', $C['navy']); $iy += 13;
        $pdf->text($L + 10, $iy, 8, 'A/C No.', '', $C['gray']); $pdf->text($L + 70, $iy, 8.3, $bank['account_number'], 'B', $C['navy']); $iy += 13;
        $pdf->text($L + 10, $iy, 8, 'IFSC Code', '', $C['gray']); $pdf->text($L + 70, $iy, 8.3, $bank['ifsc'], 'B', $C['navy']); $iy += 13;
        if ($bank['branch']) { $pdf->text($L + 10, $iy, 8, 'Branch', '', $C['gray']); $pdf->text($L + 70, $iy, 8.3, $bank['branch'], 'B', $C['navy']); }
        $ly = $boxTop + $bh + 14;
    }
    if ($qrId) {
        $pdf->text($L, $ly, 8, 'Scan & Pay', 'B', $C['blue']);
        $pdf->image($qrId, $L, $ly + 5, 70, 70);
        $qy = $ly + 5;
        $pdf->text($L + 78, $qy + 20, 15, 'Thank You!', 'I', $C['pink']);
        $note = $pdf->fit('We truly appreciate your business and look forward to serving you again.', $lw - 82, 7.6);
        $pdf->text($L + 78, $qy + 36, 7.6, $note, '', $C['gray']);
        for ($d = 0; $d < 3; $d++) pdf_icon_dot($pdf, $L + 78 + $d * 9, $qy + 46, $C['teal']);
        $ly += 82;
    }

    // right: totals box
    $ry = $blockTop;
    $pdf->text($tx, $ry, 9.5, 'SUBTOTAL', '', $C['gray']);
    $pdf->text_right($R, $ry, 9.5, 'Rs ' . money($sale['subtotal']));
    $ry += 15;
    if ($sale['discount'] > 0) {
        $dLabel = !empty($sale['discount_type']) && $sale['discount_type'] === 'percent' && $sale['discount_pct'] > 0
            ? 'DISCOUNT (' . rtrim(rtrim(number_format($sale['discount_pct'], 2), '0'), '.') . '%)' : 'DISCOUNT';
        $pdf->text($tx, $ry, 9.5, $dLabel, '', $C['gray']);
        $pdf->text_right($R, $ry, 9.5, '- Rs ' . money($sale['discount']));
        $ry += 15;
    }
    if ($sale['is_gst']) {
        $pdf->text($tx, $ry, 9.5, 'CGST', '', $C['gray']);
        $pdf->text_right($R, $ry, 9.5, 'Rs ' . money($sale['tax_amount'] / 2));
        $ry += 15;
        $pdf->text($tx, $ry, 9.5, 'SGST', '', $C['gray']);
        $pdf->text_right($R, $ry, 9.5, 'Rs ' . money($sale['tax_amount'] / 2));
        $ry += 15;
    }
    if (!empty($sale['shipping']) && $sale['shipping'] > 0) {
        $pdf->text($tx, $ry, 9.5, 'SHIPPING', '', $C['gray']);
        $pdf->text_right($R, $ry, 9.5, 'Rs ' . money($sale['shipping']));
        $ry += 15;
    }
    if (!empty($sale['loyalty_discount']) && $sale['loyalty_discount'] > 0) {
        $pdf->text($tx, $ry, 9.5, 'POINTS DISCOUNT', '', $C['gray']);
        $pdf->text_right($R, $ry, 9.5, '- Rs ' . money($sale['loyalty_discount']));
        $ry += 15;
    }
    if (!empty($sale['adjustment']) && abs($sale['adjustment']) > 0.009) {
        $pdf->text($tx, $ry, 9.5, 'ADJUSTMENT', '', $C['gray']);
        $pdf->text_right($R, $ry, 9.5, ($sale['adjustment'] > 0 ? '' : '- ') . 'Rs ' . money(abs($sale['adjustment'])));
        $ry += 15;
    }
    if (!empty($sale['round_off']) && abs($sale['round_off']) > 0.004) {
        $pdf->text($tx, $ry, 9.5, 'ROUND OFF', '', $C['gray']);
        $pdf->text_right($R, $ry, 9.5, ($sale['round_off'] > 0 ? '' : '- ') . 'Rs ' . money(abs($sale['round_off'])));
        $ry += 15;
    }
    $ry += 3;
    $pdf->rect($tx, $ry, $tw, 24, $C['navy']);
    $pdf->text($tx + 10, $ry + 16, 12.5, 'TOTAL', 'B', [1, 1, 1]);
    $pdf->text_right($R - 10, $ry + 16, 12.5, 'Rs ' . money($sale['total']), 'B', [1, 1, 1]);
    $ry += 34;
    $pdf->text($tx, $ry, 9.5, 'PAID (' . strtoupper($sale['payment_mode']) . ')', '', $C['gray']);
    $pdf->text_right($R, $ry, 9.5, 'Rs ' . money($sale['paid']));
    $ry += 18;
    $due = $sale['total'] - $sale['paid'];
    if ($due > 0.009) {
        $pdf->rect($tx, $ry - 14, $tw, 22, $C['amber_bg']);
        $pdf->text($tx + 10, $ry, 10, 'BALANCE DUE', 'B', $C['red']);
        $pdf->text_right($R - 10, $ry, 11.5, 'Rs ' . money($due), 'B', $C['red']);
        $ry += 22;
    } else {
        $pdf->rect($tx, $ry - 14, $tw, 22, [0.85, 0.95, 0.88]);
        $pdf->text_center($tx + $tw / 2, $ry, 10.5, 'PAID IN FULL', 'B', $C['green']);
        $ry += 22;
    }
    $y = max($ly, $ry) + 12;

    // GST slab breakdown
    if ($sale['is_gst'] && $sale['tax_amount'] > 0) {
        if ($y > 660) { $pdf->new_page(); $y = 40; }
        $slabs = [];
        foreach ($items as $it) {
            $tr = (float)$it['tax_rate'];
            if ($tr > 0) $slabs[(string)$tr] = ($slabs[(string)$tr] ?? 0) + (float)$it['total'];
        }
        ksort($slabs);
        $pdf->rect($L, $y - 10, 330, 14, $C['row_alt']);
        $pdf->text($L + 6, $y, 8.2, 'GST Slab', 'B', $C['navy']);
        $pdf->text($L + 76, $y, 8.2, 'Taxable', 'B', $C['navy']);
        $pdf->text($L + 146, $y, 8.2, 'CGST', 'B', $C['navy']);
        $pdf->text($L + 216, $y, 8.2, 'SGST', 'B', $C['navy']);
        $pdf->text($L + 286, $y, 8.2, 'Total Tax', 'B', $C['navy']);
        $y += 13;
        foreach ($slabs as $tr => $tv) {
            $tx2 = $tv * (float)$tr / 100;
            $pdf->text($L + 6, $y, 8.2, $tr . '%');
            $pdf->text($L + 76, $y, 8.2, money($tv));
            $pdf->text($L + 146, $y, 8.2, money($tx2 / 2));
            $pdf->text($L + 216, $y, 8.2, money($tx2 / 2));
            $pdf->text($L + 286, $y, 8.2, money($tx2));
            $y += 12;
        }
        $y += 8;
    }

    if (!empty($sale['c_terms'])) {
        if ($y > 700) { $pdf->new_page(); $y = 40; }
        $pdf->text($L, $y, 8, 'Terms & Conditions:', 'B', $C['navy']);
        $y += 11;
        foreach (array_slice(explode("\n", $sale['c_terms']), 0, 3) as $tl) {
            $pdf->text($L, $y, 7.8, $pdf->fit($tl, $R - $L, 7.8), '', $C['gray']);
            $y += 10;
        }
    }

    // ---------- signature block + centre stamp ----------
    $sy = max($y + 46, 745);
    if ($sy > 800) { $pdf->new_page(); $sy = 745; }
    $pdf->line($L, $sy, $L + 150, $sy, 0.8, $C['gray']);
    $pdf->text_center($L + 75, $sy + 13, 8.5, "Receiver's Signature", '', $C['gray']);

    $stampCx = ($L + $R) / 2; $stampCy = $sy - 8;
    $pdf->circle_stroke($stampCx, $stampCy, 30, $C['navy'], 1.1);
    $pdf->circle_stroke($stampCx, $stampCy, 25, $C['navy'], 0.6);
    $pdf->text_center($stampCx, $stampCy - 6, 6.5, 'AK COMPUTER', 'B', $C['navy']);
    $pdf->text_center($stampCx, $stampCy + 3, 6, '* THANK YOU *', '', $C['navy']);
    $pdf->text_center($stampCx, $stampCy + 12, 6, strtoupper($sale['loc_city']), '', $C['navy']);

    $pdf->line($R - 190, $sy, $R, $sy, 0.8, $C['gray']);
    $pdf->text_center($R - 95, $sy + 13, 8.5, 'For ' . $sale['company_name'], '', $C['gray']);
    $pdf->text_center($R - 95, $sy + 24, 8.5, 'Authorised Signatory', '', $C['gray']);

    // ---------- footer band + bottom strip ----------
    $fy = 800;
    $pdf->grad_rect(0, $fy, $R + 36, 26, $C['orange'], $C['teal'], 'h');
    $pdf->text($L, $fy + 17, 8.3, 'Stay Connected', 'B', [1, 1, 1]);
    $sxBase = $L + 78;
    foreach (['f', 'IG', 'W', 'YT'] as $i => $lbl) {
        $cx = $sxBase + $i * 22;
        $pdf->circle($cx, $fy + 13, 8, [1, 1, 1]);
        $pdf->text_center($cx, $fy + 16, 6.5, $lbl, 'B', $C['teal_dark']);
    }
    $pdf->text_center(($R + 36) / 2, $fy + 11, 8, 'For Support', 'B', [1, 1, 1]);
    $pdf->text_center(($R + 36) / 2, $fy + 22, 8.3, $sale['c_phone'] ?: '', '', [1, 1, 1]);
    $pdf->text_right($R + 26, $fy + 11, 8, 'We Deal In:', 'B', [1, 1, 1]);
    $pdf->text_right($R + 26, $fy + 22, 7.3, 'Computers . Laptops . CCTV . Networking . AMC', '', [0.95, 0.98, 0.97]);

    $pdf->rect(0, $fy + 26, $R + 36, 16, $C['navy']);
    $pdf->text_center(($R + 36) / 2, $fy + 37, 7.6, 'This is a computer generated invoice.', '', [0.75, 0.8, 0.88]);
    $pdf->poly([[$R + 36, $fy + 26], [$R + 36, $fy + 42], [$R + 36 - 30, $fy + 42]], $C['orange']);

    return $pdf->output();
}
