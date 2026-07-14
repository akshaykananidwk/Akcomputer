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
    /** Filled free-form path built from cubic beziers/lines. $segs is a list of
     *  ['m',x,y] | ['l',x,y] | ['c',x1,y1,x2,y2,x,y], all top-based. Used for the
     *  flowing header ribbons. */
    function fill_path($segs, $rgb) {
        $this->cur .= sprintf("%.3F %.3F %.3F rg\n", $rgb[0], $rgb[1], $rgb[2]);
        foreach ($segs as $s) {
            if ($s[0] === 'm') $this->cur .= sprintf("%.2F %.2F m\n", $s[1], self::H - $s[2]);
            elseif ($s[0] === 'l') $this->cur .= sprintf("%.2F %.2F l\n", $s[1], self::H - $s[2]);
            elseif ($s[0] === 'c') $this->cur .= sprintf("%.2F %.2F %.2F %.2F %.2F %.2F c\n", $s[1], self::H - $s[2], $s[3], self::H - $s[4], $s[5], self::H - $s[6]);
        }
        $this->cur .= "h f\n";
    }
    /** Draw the Indian Rupee sign as a small vector glyph (Helvetica base-14
     *  fonts can't encode U+20B9). Two top bars, a right stem and a diagonal
     *  leg. Returns the advance width so a number can be placed after it. $y is
     *  the text baseline (top-based). */
    function rupee($x, $y, $size, $rgb = [0, 0, 0]) {
        $S = $size;
        $t = $S * 0.08;                        // stroke thickness
        $top = $y - $S * 0.66;                 // top bar y (top-based)
        $w = $S * 0.52;                        // glyph body width
        $this->rect($x, $top, $w, $t, $rgb);                       // top horizontal bar
        $this->rect($x, $top + $S * 0.15, $w, $t, $rgb);           // second horizontal bar
        // right vertical stem joining the two bars
        $this->rect($x + $w - $t, $top, $t, $S * 0.15 + $t, $rgb);
        // diagonal leg from the right of the second bar down to the bottom-left
        $this->line($x + $w - $t / 2, $top + $S * 0.15 + $t, $x + $S * 0.05, $y, $t * 1.25, $rgb);
        return $S * 0.64;
    }
    /** Left-aligned "<Rs> <amount>" with a vector rupee glyph. */
    function money_text($x, $y, $size, $amount, $style = '', $rgb = [0, 0, 0]) {
        $adv = $this->rupee($x, $y, $size, $rgb);
        $this->text($x + $adv + $size * 0.12, $y, $size, $amount, $style, $rgb);
    }
    /** Right-aligned "<Rs> <amount>" with a vector rupee glyph, ending at $xRight. */
    function money_text_right($xRight, $y, $size, $amount, $style = '', $rgb = [0, 0, 0]) {
        $numW = pdf_text_width($amount, $size, $style === 'B');
        $adv = $size * 0.64; $gap = $size * 0.12;
        $x = $xRight - ($adv + $gap + $numW);
        $this->rupee($x, $y, $size, $rgb);
        $this->text($x + $adv + $gap, $y, $size, $amount, $style, $rgb);
    }
    /** Right-aligned column header of the form "LABEL (Rs)" with a vector rupee glyph. */
    function rupee_label_right($xRight, $y, $size, $label, $style = '', $rgb = [0, 0, 0]) {
        $pre = $label . ' ('; $post = ')';
        $wPre = pdf_text_width($pre, $size, $style === 'B');
        $wPost = pdf_text_width($post, $size, $style === 'B');
        $wRup = $size * 0.5; $g = $size * 0.05;
        $x = $xRight - ($wPre + $g + $wRup + $g + $wPost);
        $this->text($x, $y, $size, $pre, $style, $rgb);
        $this->rupee($x + $wPre + $g, $y, $size * 0.86, $rgb);
        $this->text($x + $wPre + $g + $wRup + $g, $y, $size, $post, $style, $rgb);
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
function pdf_icon_dot(&$pdf, $x, $y, $rgb) { $pdf->circle($x, $y, 2.0, $rgb); }

/** Location-pin icon (teardrop + hole), drawn around baseline point ($x,$y). */
function pdf_icon_pin(&$pdf, $x, $y, $s, $rgb) {
    $pdf->circle($x, $y - $s * 0.55, $s * 0.5, $rgb);
    $pdf->poly([[$x - $s * 0.42, $y - $s * 0.45], [$x + $s * 0.42, $y - $s * 0.45], [$x, $y + $s * 0.15]], $rgb);
    $pdf->circle($x, $y - $s * 0.58, $s * 0.18, [1, 1, 1]);
}
/** Simple phone-handset icon. */
function pdf_icon_phone(&$pdf, $x, $y, $s, $rgb) {
    $pdf->circle($x, $y - $s * 0.4, $s * 0.5, $rgb);
    $pdf->circle($x, $y - $s * 0.4, $s * 0.22, [1, 1, 1]);
    $pdf->rect($x - $s * 0.5, $y - $s * 0.62, $s * 0.22, $s * 0.22, $rgb);
}
/** "@" style email badge - a rounded square with an 'a'-ish dot. */
function pdf_icon_at(&$pdf, $x, $y, $s, $rgb) {
    $pdf->circle($x, $y - $s * 0.4, $s * 0.5, $rgb);
    $pdf->circle($x, $y - $s * 0.4, $s * 0.28, [1, 1, 1]);
    $pdf->circle($x, $y - $s * 0.4, $s * 0.12, $rgb);
}
/** Thumbs-up glyph for the "thank you" seal, centred at ($cx,$cy). */
function pdf_thumbsup(&$pdf, $cx, $cy, $s, $rgb) {
    // fist (rounded block) + separate thumb sticking up-left
    $pdf->rrect($cx - $s * 0.35, $cy - $s * 0.15, $s * 0.7, $s * 0.6, $s * 0.12, $rgb);
    $pdf->rrect($cx - $s * 0.55, $cy - $s * 0.05, $s * 0.24, $s * 0.5, $s * 0.1, $rgb);
    $pdf->rrect($cx - $s * 0.28, $cy - $s * 0.62, $s * 0.34, $s * 0.5, $s * 0.16, $rgb);
}
/** Gold "thank you for your business" seal, centred at ($cx,$cy), radius $r. */
function pdf_seal(&$pdf, $cx, $cy, $r, $C) {
    // scalloped gold edge
    $n = 20;
    for ($i = 0; $i < $n; $i++) {
        $a = 2 * M_PI * $i / $n;
        $pdf->circle($cx + cos($a) * $r, $cy + sin($a) * $r, $r * 0.16, $C['gold']);
    }
    $pdf->circle($cx, $cy, $r, $C['gold']);
    $pdf->circle($cx, $cy, $r * 0.82, $C['navy']);
    $pdf->circle($cx, $cy, $r * 0.78, $C['navy_dark']);
    pdf_thumbsup($pdf, $cx, $cy - $r * 0.12, $r * 0.55, $C['gold']);
    $pdf->text_center($cx, $cy + $r * 0.42, $r * 0.19, 'THANK YOU', 'B', $C['gold']);
    $pdf->text_center($cx, $cy + $r * 0.62, $r * 0.15, 'FOR YOUR BUSINESS', '', $C['gold']);
}
/** Small filled social circle with a letter, for the footer. */
function pdf_social(&$pdf, $cx, $cy, $r, $label, $rgb) {
    $pdf->circle($cx, $cy, $r, $rgb);
    $pdf->text_center($cx, $cy + $r * 0.35, $r * 0.95, $label, 'B', [1, 1, 1]);
}
/** Tiny generic device icon (monitor-ish box on a stand) above a footer label. */
function pdf_icon_device(&$pdf, $cx, $cy, $rgb) {
    $pdf->rrect($cx - 8, $cy - 6, 16, 11, 1.5, $rgb);
    $pdf->rrect($cx - 6, $cy - 4, 12, 7, 1, [1, 1, 1]);
    $pdf->rect($cx - 3, $cy + 5, 6, 1.6, $rgb);
    $pdf->rect($cx - 5, $cy + 6.6, 10, 1.4, $rgb);
}

/** The flowing ribbon decoration across the top of the bill (fixed branding).
 *  Layered crescents nested from outer (deep) to inner (shallow) so the top
 *  edge shows thin bands of teal/cyan/blue/orange/gold, like the reference. */
function pdf_invoice_waves(&$pdf, $C) {
    $PW = 595;
    $cyan = [0.11, 0.63, 0.72];

    // ---- top-right: teal wave filling the corner behind the invoice box ----
    $pdf->fill_path([
        ['m', 318, 0], ['l', $PW, 0], ['l', $PW, 170],
        ['c', 500, 168, 430, 120, 405, 70],
        ['c', 388, 36, 358, 8, 318, 0],
    ], $C['teal']);
    $pdf->fill_path([
        ['m', 388, 0], ['l', $PW, 0], ['l', $PW, 84],
        ['c', 495, 78, 445, 44, 425, 16], ['c', 415, 6, 402, 2, 388, 0],
    ], $cyan);
    // orange corner triangle, very top-right
    $pdf->poly([[$PW, 0], [$PW, 50], [$PW - 62, 0]], $C['orange']);

    // ---- top-left: flowing ribbons (deep on the far left, shallow over logo) ----
    $pdf->fill_path([
        ['m', 0, 0], ['l', 330, 0],
        ['c', 250, 10, 150, 20, 92, 30], ['c', 52, 38, 24, 50, 0, 60],
    ], $C['navy']);
    $pdf->fill_path([
        ['m', 0, 0], ['l', 312, 0],
        ['c', 236, 8, 142, 17, 86, 25], ['c', 48, 31, 22, 41, 0, 49],
    ], $C['teal']);
    $pdf->fill_path([
        ['m', 0, 0], ['l', 290, 0],
        ['c', 220, 7, 132, 14, 78, 20], ['c', 43, 25, 20, 33, 0, 39],
    ], $cyan);
    $pdf->fill_path([
        ['m', 0, 0], ['l', 265, 0],
        ['c', 200, 6, 120, 11, 68, 15], ['c', 37, 19, 17, 25, 0, 29],
    ], $C['blue']);
    $pdf->fill_path([
        ['m', 0, 0], ['l', 150, 0],
        ['c', 108, 5, 66, 10, 40, 14], ['c', 22, 17, 9, 20, 0, 21],
    ], $C['orange']);
    $pdf->fill_path([
        ['m', 0, 0], ['l', 120, 0],
        ['c', 86, 4, 52, 7, 30, 10], ['c', 16, 12, 6, 14, 0, 15],
    ], $C['gold']);
}

/** Build the invoice PDF bytes for a sale row + items (same data as sale_view). */
function invoice_pdf($sale, $items) {
    $pdf = new MiniPDF();
    $L = 36; $R = 559; $PW = 595;
    $C = invoice_colors();
    $bank = default_bank_account();
    $qrId = null;
    if ($bank && $bank['upi_id']) {
        $qrPath = qr_png_path(upi_uri($bank['upi_id'], $bank['account_name'], $sale['total'], $sale['invoice_no']));
        if ($qrPath) $qrId = $pdf->load_png($qrPath);
    }
    $showTime = setting('add_time_transactions', '1') === '1' && !empty($sale['created_at']);
    $due = $sale['total'] - $sale['paid'];

    // ================= HEADER =================
    pdf_invoice_waves($pdf, $C);

    // logo lockup (shifted right of the left wave flourish, larger AK)
    $logoX = 72;
    $pdf->text($logoX, 57, 31, 'AK', 'B', $C['blue']);
    $akw = pdf_text_width('AK', 31, true);
    $pdf->text($logoX + $akw + 7, 55, 24, 'COMPUTER', 'B', $C['navy']);
    $pdf->text($logoX + $akw + 9, 71, 11.5, 'Smart Solutions, Better Future', 'I', $C['navy']);

    // contact lines with icons
    $cy = 96;
    $boxX = 342; $boxW = $R - $boxX; $boxY = 16; $boxH = 108;
    $addrMaxW = $boxX - ($L + 16) - 10;
    $addrLines = array_slice(pdf_wrap(trim(($sale['c_address'] ?? '') . ' ' . $sale['loc_name'] . ', ' . $sale['loc_city']), 8.7, false, $addrMaxW), 0, 2);
    pdf_icon_pin($pdf, $L + 5, $cy, 11, $C['teal']);
    foreach ($addrLines as $al) { $pdf->text($L + 16, $cy, 8.7, $al, '', $C['gray']); $cy += 12; }
    $cy += 3;
    if ($sale['c_phone']) {
        pdf_icon_phone($pdf, $L + 5, $cy, 11, $C['teal']);
        $pdf->text($L + 16, $cy, 8.7, $sale['c_phone'], '', $C['gray']);
        $cy += 15;
    }
    $email = setting('company_email', setting('app_email', ''));
    if ($email) {
        pdf_icon_at($pdf, $L + 5, $cy, 11, $C['teal']);
        $pdf->text($L + 16, $cy, 8.7, $email, '', $C['gray']);
        $cy += 15;
    }

    // navy invoice-details box
    $pdf->rrect($boxX, $boxY, $boxW, $boxH, 12, $C['navy']);
    $bx = $boxX + 18; $by = $boxY + 30;
    $pdf->text($bx, $by, 20, $sale['is_gst'] ? 'TAX INVOICE' : 'INVOICE', 'B', [1, 1, 1]);
    $pdf->rect($bx, $by + 7, 42, 2.6, $C['orange']);
    $by += 27;
    $labelX = $bx; $valX = $bx + 78;
    $pdf->text($labelX, $by, 9.5, 'Invoice No.', '', [0.78, 0.83, 0.9]);
    $pdf->text($valX, $by, 9.5, ': ' . $sale['invoice_no'], 'B', [1, 1, 1]);
    $by += 16;
    $pdf->text($labelX, $by, 9.5, 'Date', '', [0.78, 0.83, 0.9]);
    $pdf->text($valX, $by, 9.5, ': ' . dmy($sale['sale_date']), 'B', [1, 1, 1]);
    if ($showTime) {
        $by += 16;
        $pdf->text($labelX, $by, 9.5, 'Time', '', [0.78, 0.83, 0.9]);
        $pdf->text($valX, $by, 9.5, ': ' . date('h:i A', strtotime($sale['created_at'])), 'B', [1, 1, 1]);
    }
    // seal overlapping the box bottom-right
    pdf_seal($pdf, $boxX + $boxW - 46, $boxY + $boxH + 2, 28, $C);

    // ================= BILL TO =================
    $y = max($cy, $boxY + $boxH) + 22;
    $pdf->grad_rrect($L, $y - 13, 96, 19, 9.5, $C['teal'], $C['blue'], 'h');
    $pdf->text_center($L + 48, $y, 9.5, 'BILL TO:', 'B', [1, 1, 1]);
    $y += 22;
    $pdf->text($L, $y, 13, $sale['customer_name'] ?: $sale['party_name'] ?: 'Walk-in Customer', 'B', $C['navy']);
    if ($sale['customer_mobile']) {
        $y += 16;
        pdf_icon_phone($pdf, $L + 5, $y, 10, $C['teal']);
        $pdf->text($L + 16, $y, 9.5, $sale['customer_mobile'], '', $C['gray']);
    }
    if (!empty($sale['party_gstin'])) { $y += 13; $pdf->text($L + 16, $y, 9, 'GSTIN: ' . $sale['party_gstin'], '', $C['gray']); }
    $y += 22;

    // ================= ITEM TABLE =================
    $hasGst = $sale['is_gst'];
    // column geometry
    $cNum = $L + 8; $cItem = $L + 26;
    if ($hasGst) { $cHsnR = 316; $cQtyC = 360; $cRateR = 444; $cGstR = 480; $cAmtR = $R - 8; $itemMaxW = 258; }
    else { $cQtyC = 322; $cRateR = 468; $cAmtR = $R - 8; $itemMaxW = 300; }

    $rowH = 22; $headH = 20;
    $tableTop = $y;
    // header band (teal, rounded top)
    $pdf->rrect($L, $tableTop, $R - $L, $headH, 5, $C['teal']);
    $pdf->rect($L, $tableTop + $headH - 6, $R - $L, 6, $C['teal']);
    $hy = $tableTop + 13.5;
    $pdf->text($cNum, $hy, 9, '#', 'B', [1, 1, 1]);
    $pdf->text($cItem, $hy, 9, 'ITEM DESCRIPTION', 'B', [1, 1, 1]);
    if ($hasGst) $pdf->text_right($cHsnR, $hy, 9, 'HSN', 'B', [1, 1, 1]);
    $pdf->text_center($cQtyC, $hy, 9, 'QTY', 'B', [1, 1, 1]);
    $pdf->rupee_label_right($cRateR, $hy, 8.5, 'RATE', 'B', [1, 1, 1]);
    if ($hasGst) $pdf->text_right($cGstR, $hy, 9, 'GST%', 'B', [1, 1, 1]);
    $pdf->rupee_label_right($cAmtR, $hy, 8.5, 'AMOUNT', 'B', [1, 1, 1]);

    $bodyTop = $tableTop + $headH;
    $targetBodyBottom = 498;               // designed table bottom for a "full" look
    $y = $bodyTop;
    $rowIdx = 0;
    $paginated = false;

    foreach ($items as $n => $it) {
        // page break for very long bills
        if ($y + $rowH > 706) {
            // draw side borders for current page body, then new page
            $pdf->rect($L, $y, $R - $L, 0.6, [0.88, 0.9, 0.93]);
            $pdf->new_page();
            $paginated = true;
            $y = 44;
            $pdf->rrect($L, $y, $R - $L, $headH, 5, $C['teal']);
            $pdf->rect($L, $y + $headH - 6, $R - $L, 6, $C['teal']);
            $hy = $y + 13.5;
            $pdf->text($cNum, $hy, 9, '#', 'B', [1, 1, 1]);
            $pdf->text($cItem, $hy, 9, 'ITEM DESCRIPTION', 'B', [1, 1, 1]);
            if ($hasGst) $pdf->text_right($cHsnR, $hy, 9, 'HSN', 'B', [1, 1, 1]);
            $pdf->text_center($cQtyC, $hy, 9, 'QTY', 'B', [1, 1, 1]);
            $pdf->rupee_label_right($cRateR, $hy, 8.5, 'RATE', 'B', [1, 1, 1]);
            if ($hasGst) $pdf->text_right($cGstR, $hy, 9, 'GST%', 'B', [1, 1, 1]);
            $pdf->rupee_label_right($cAmtR, $hy, 8.5, 'AMOUNT', 'B', [1, 1, 1]);
            $y += $headH;
            $bodyTop = $y;
        }
        $ty = $y + 14;
        $pdf->text($cNum, $ty, 9.5, ($n + 1) . '', '', $C['navy']);
        $pdf->text($cItem, $ty, 9.5, $pdf->fit($it['name'], $itemMaxW, 9.5), '', [0.15, 0.2, 0.28]);
        if ($hasGst) $pdf->text_right($cHsnR, $ty, 9, (string)($it['hsn'] ?? ''), '', $C['gray']);
        $pdf->text_center($cQtyC, $ty, 9.5, trim((float)$it['qty'] . ' ' . $it['unit']), '', [0.15, 0.2, 0.28]);
        $pdf->text_right($cRateR, $ty, 9.5, money($it['price']), '', [0.15, 0.2, 0.28]);
        if ($hasGst) $pdf->text_right($cGstR, $ty, 9, (float)$it['tax_rate'] . '%', '', $C['gray']);
        $pdf->text_right($cAmtR, $ty, 9.5, money($it['total']), '', [0.15, 0.2, 0.28]);
        $adv = $rowH;
        if ($it['serials']) {
            $pdf->text($cItem, $ty + 10, 7.3, $pdf->fit('SN: ' . $it['serials'], $itemMaxW, 7.3), '', $C['gray']);
            $adv = $rowH + 10;
        }
        $y += $adv;
        $pdf->line($L, $y, $R, $y, 0.5, [0.9, 0.92, 0.94]);
        $rowIdx++;
    }
    // fill remaining space with empty ruled rows so few-item bills still look
    // complete (each section lands in its designed place, not bunched up top)
    if (!$paginated) {
        while ($y + $rowH <= $targetBodyBottom) {
            $y += $rowH;
            $pdf->line($L, $y, $R, $y, 0.5, [0.93, 0.95, 0.96]);
        }
    }
    // table side borders
    $pdf->line($L, $bodyTop, $L, $y, 0.5, [0.88, 0.9, 0.93]);
    $pdf->line($R, $bodyTop, $R, $y, 0.5, [0.88, 0.9, 0.93]);
    $y += 16;

    // ================= PAY BLOCK (left) + TOTALS (right) =================
    $blockTop = $y;
    $totW = 250; $totX = $R - $totW;
    $leftW = $totX - $L - 22;

    // -- totals box (right) --
    $ry = $blockTop;
    $rowY = $ry;
    $pdf->text($totX + 12, $rowY + 15, 10, 'SUBTOTAL', '', $C['gray']);
    $pdf->money_text_right($R - 12, $rowY + 15, 10, money($sale['subtotal']), '', $C['navy']);
    $pdf->line($totX, $rowY + 24, $R, $rowY + 24, 0.5, [0.87, 0.89, 0.92]);
    $rowY += 24;
    // DISCOUNT row is always shown (as in the reference template), even at 0.00
    $dLabel = !empty($sale['discount_type']) && $sale['discount_type'] === 'percent' && $sale['discount_pct'] > 0
        ? 'DISCOUNT (' . rtrim(rtrim(number_format($sale['discount_pct'], 2), '0'), '.') . '%)' : 'DISCOUNT';
    $pdf->text($totX + 12, $rowY + 15, 10, $dLabel, '', $C['gray']);
    $pdf->money_text_right($R - 12, $rowY + 15, 10, ($sale['discount'] > 0 ? '- ' : '') . money($sale['discount']), '', $C['navy']);
    $pdf->line($totX, $rowY + 24, $R, $rowY + 24, 0.5, [0.87, 0.89, 0.92]);
    $rowY += 24;
    if ($hasGst) {
        $pdf->text($totX + 12, $rowY + 15, 10, 'CGST', '', $C['gray']);
        $pdf->money_text_right($R - 12, $rowY + 15, 10, money($sale['tax_amount'] / 2), '', $C['navy']);
        $pdf->line($totX, $rowY + 24, $R, $rowY + 24, 0.5, [0.87, 0.89, 0.92]);
        $rowY += 24;
        $pdf->text($totX + 12, $rowY + 15, 10, 'SGST', '', $C['gray']);
        $pdf->money_text_right($R - 12, $rowY + 15, 10, money($sale['tax_amount'] / 2), '', $C['navy']);
        $pdf->line($totX, $rowY + 24, $R, $rowY + 24, 0.5, [0.87, 0.89, 0.92]);
        $rowY += 24;
    }
    if (!empty($sale['shipping']) && $sale['shipping'] > 0) {
        $pdf->text($totX + 12, $rowY + 15, 10, 'SHIPPING', '', $C['gray']);
        $pdf->money_text_right($R - 12, $rowY + 15, 10, money($sale['shipping']), '', $C['navy']);
        $pdf->line($totX, $rowY + 24, $R, $rowY + 24, 0.5, [0.87, 0.89, 0.92]);
        $rowY += 24;
    }
    if (!empty($sale['adjustment']) && abs($sale['adjustment']) > 0.009) {
        $pdf->text($totX + 12, $rowY + 15, 10, 'ADJUSTMENT', '', $C['gray']);
        $pdf->money_text_right($R - 12, $rowY + 15, 10, ($sale['adjustment'] > 0 ? '' : '- ') . money(abs($sale['adjustment'])), '', $C['navy']);
        $pdf->line($totX, $rowY + 24, $R, $rowY + 24, 0.5, [0.87, 0.89, 0.92]);
        $rowY += 24;
    }
    if (!empty($sale['round_off']) && abs($sale['round_off']) > 0.004) {
        $pdf->text($totX + 12, $rowY + 15, 10, 'ROUND OFF', '', $C['gray']);
        $pdf->money_text_right($R - 12, $rowY + 15, 10, ($sale['round_off'] > 0 ? '' : '- ') . money(abs($sale['round_off'])), '', $C['navy']);
        $pdf->line($totX, $rowY + 24, $R, $rowY + 24, 0.5, [0.87, 0.89, 0.92]);
        $rowY += 24;
    }
    // TOTAL (navy bar)
    $pdf->rect($totX, $rowY, $totW, 27, $C['navy']);
    $pdf->text($totX + 12, $rowY + 18, 13, 'TOTAL', 'B', [1, 1, 1]);
    $pdf->money_text_right($R - 12, $rowY + 18, 13, money($sale['total']), 'B', [1, 1, 1]);
    $rowY += 27;
    // PAID
    $pdf->text($totX + 12, $rowY + 16, 10, 'PAID (' . strtoupper($sale['payment_mode']) . ')', '', $C['gray']);
    $pdf->money_text_right($R - 12, $rowY + 16, 10, money($sale['paid']), '', $C['navy']);
    $pdf->line($totX, $rowY + 25, $R, $rowY + 25, 0.5, [0.87, 0.89, 0.92]);
    $rowY += 25;
    // BALANCE DUE (amber) or PAID IN FULL (green)
    if ($due > 0.009) {
        $pdf->rect($totX, $rowY, $totW, 26, $C['amber_bg']);
        $pdf->text($totX + 12, $rowY + 17, 11, 'BALANCE DUE', 'B', $C['red']);
        $pdf->money_text_right($R - 12, $rowY + 17, 12, money($due), 'B', $C['red']);
    } else {
        $pdf->rect($totX, $rowY, $totW, 26, [0.85, 0.95, 0.88]);
        $pdf->text_center($totX + $totW / 2, $rowY + 17, 11.5, 'PAID IN FULL', 'B', $C['green']);
    }
    $rowY += 26;
    // outer border around totals box
    $pdf->rrect_stroke($totX, $ry, $totW, $rowY - $ry, 3, [0.83, 0.86, 0.9], 0.8);
    $totalsBottom = $rowY;

    // -- pay via bank transfer (left) --
    $ly = $blockTop;
    if ($bank) {
        $pdf->grad_rrect($L, $ly, 176, 19, 9.5, $C['purple'], $C['blue'], 'h');
        $pdf->text_center($L + 88, $ly + 13.5, 9, 'PAY VIA BANK TRANSFER:', 'B', [1, 1, 1]);
        $ly += 24;
        $bh = 66;
        $pdf->rrect($L, $ly, $leftW, $bh, 8, $C['lavender_bg']);
        $pdf->rrect_stroke($L, $ly, $leftW, $bh, 8, $C['lavender_border'], 1);
        $iy = $ly + 17;
        $rows = [['A/C Name', $bank['account_name']], ['A/C No.', $bank['account_number']], ['IFSC Code', $bank['ifsc']]];
        if ($bank['branch']) $rows[] = ['Branch', $bank['branch']];
        foreach ($rows as $rr) {
            $pdf->text($L + 12, $iy, 8.5, $rr[0], '', $C['gray']);
            $pdf->text($L + 80, $iy, 8.8, $pdf->fit($rr[1], $leftW - 130, 8.8), 'B', $C['navy']);
            $iy += 13;
        }
        // bank building icon on the right of the box (outline style, like the reference)
        $bxi = $L + $leftW - 32; $byi = $ly + $bh / 2; $bl = [0.16, 0.42, 0.86];
        $pdf->line($bxi - 18, $byi - 5, $bxi, $byi - 16, 1.3, $bl);   // pediment left
        $pdf->line($bxi, $byi - 16, $bxi + 18, $byi - 5, 1.3, $bl);   // pediment right
        $pdf->line($bxi - 18, $byi - 5, $bxi + 18, $byi - 5, 1.3, $bl); // architrave
        for ($p = -1; $p <= 1; $p++) $pdf->line($bxi + $p * 11, $byi - 3, $bxi + $p * 11, $byi + 9, 2.1, $bl); // columns
        $pdf->rect($bxi - 19, $byi + 10, 38, 2.6, $bl);               // base
        $ly += $bh + 14;
    }
    // QR + thank you
    if ($qrId) {
        $pdf->rrect_stroke($L, $ly, 78, 90, 6, [0.8, 0.82, 0.86], 0.8);
        $pdf->text_center($L + 39, $ly + 11, 7.5, 'Scan & Pay', 'B', $C['blue']);
        $pdf->image($qrId, $L + 8, $ly + 15, 62, 62);
        $pdf->text($L + 92, $ly + 26, 17, 'Thank You!', 'I', $C['pink']);
        foreach (array_slice(pdf_wrap('We truly appreciate your business and look forward to serving you again.', 8, false, $leftW - 100), 0, 3) as $i => $tl) {
            $pdf->text($L + 92, $ly + 40 + $i * 11, 8, $tl, '', $C['gray']);
        }
        $ly += 96;
    }

    $y = max($totalsBottom, $ly) + 8;

    // GST slab breakdown (optional, only if room on this page)
    if ($hasGst && $sale['tax_amount'] > 0 && $y < 640) {
        $slabs = [];
        foreach ($items as $it) { $tr = (float)$it['tax_rate']; if ($tr > 0) $slabs[(string)$tr] = ($slabs[(string)$tr] ?? 0) + (float)$it['total']; }
        ksort($slabs);
        $pdf->rect($L, $y, 320, 15, $C['row_alt']);
        $gy = $y + 10.5;
        $pdf->text($L + 8, $gy, 8.2, 'GST Slab', 'B', $C['navy']);
        $pdf->text($L + 78, $gy, 8.2, 'Taxable', 'B', $C['navy']);
        $pdf->text($L + 148, $gy, 8.2, 'CGST', 'B', $C['navy']);
        $pdf->text($L + 218, $gy, 8.2, 'SGST', 'B', $C['navy']);
        $pdf->text($L + 280, $gy, 8.2, 'Total Tax', 'B', $C['navy']);
        $y += 15;
        foreach ($slabs as $tr => $tv) {
            $tx2 = $tv * (float)$tr / 100; $gy = $y + 10;
            $pdf->text($L + 8, $gy, 8.2, $tr . '%', '', $C['gray']);
            $pdf->text($L + 78, $gy, 8.2, money($tv), '', $C['gray']);
            $pdf->text($L + 148, $gy, 8.2, money($tx2 / 2), '', $C['gray']);
            $pdf->text($L + 218, $gy, 8.2, money($tx2 / 2), '', $C['gray']);
            $pdf->text($L + 280, $gy, 8.2, money($tx2), '', $C['gray']);
            $y += 13;
        }
    }

    // ================= FOOTER (pinned near the bottom) =================
    // footer band
    $fbTop = max($y + 6, 706);
    if ($fbTop > 724) $fbTop = 724;
    $pdf->grad_rrect($L - 6, $fbTop, $PW - 2 * ($L - 6), 40, 6, [1.0, 0.86, 0.55], [1.0, 0.93, 0.72], 'h');
    // stay connected
    $pdf->text($L + 6, $fbTop + 15, 8.3, 'Stay Connected', 'B', $C['navy']);
    pdf_social($pdf, $L + 14, $fbTop + 29, 7, 'f', [0.23, 0.35, 0.6]);
    pdf_social($pdf, $L + 32, $fbTop + 29, 7, 'IG', [0.83, 0.18, 0.42]);
    pdf_social($pdf, $L + 50, $fbTop + 29, 7, 'W', [0.13, 0.7, 0.32]);
    pdf_social($pdf, $L + 68, $fbTop + 29, 7, 'YT', [0.85, 0.13, 0.13]);
    // for support (center)
    $cxc = 250;
    $pdf->text($cxc, $fbTop + 15, 8.3, 'For Support', 'B', $C['navy']);
    $pdf->text($cxc, $fbTop + 29, 10, $sale['c_phone'] ?: '', 'B', $C['navy']);
    // we deal in (right) - labels with tiny device icons
    $pdf->text($cxc + 92, $fbTop + 15, 8, 'We Deal In:', 'B', $C['navy']);
    $cats = ['Computers', 'Laptops', 'Accessories', 'CCTV', 'Networking', 'AMC'];
    $startx = $cxc + 150; $stepx = (($R) - $startx) / (count($cats) - 1);
    foreach ($cats as $i => $cat) {
        $cx = $startx + $i * $stepx;
        pdf_icon_device($pdf, $cx, $fbTop + 18, $C['teal_dark']);
        $pdf->text_center($cx, $fbTop + 34, 6.3, $cat, '', $C['navy']);
    }

    // signature + stamp
    $sy = $fbTop + 40 + 26;
    $pdf->line($L, $sy, $L + 160, $sy, 0.7, [0.55, 0.6, 0.66]);
    $pdf->text_center($L + 80, $sy + 12, 8.5, "Receiver's Signature", '', $C['gray']);
    $pdf->line($R - 200, $sy, $R, $sy, 0.7, [0.55, 0.6, 0.66]);
    $pdf->text_center($R - 100, $sy + 12, 8.5, 'For ' . $sale['company_name'] . ' - Authorised Signatory', '', $C['gray']);
    // round stamp centre
    $stcx = ($L + $R) / 2; $stcy = $sy - 2;
    $pdf->circle_stroke($stcx, $stcy, 24, $C['navy'], 1.1);
    $pdf->circle_stroke($stcx, $stcy, 20, $C['navy'], 0.6);
    $stampCity = strtoupper(trim(explode('-', $sale['loc_city'])[0]));
    $pdf->text_center($stcx, $stcy - 6, 5.2, 'AK COMPUTER', 'B', $C['navy']);
    $pdf->text_center($stcx, $stcy + 2, 5.2, 'THANK YOU', '', $C['navy']);
    $pdf->text_center($stcx, $stcy + 11, 5.2, $stampCity, 'B', $C['navy']);

    // bottom navy strip (text left-aligned, orange corner bottom-right)
    $pdf->rect(0, 824, $PW, 18, $C['navy']);
    $pdf->text($L, 836, 8, 'This is a computer generated invoice.', '', [0.75, 0.8, 0.88]);
    $pdf->poly([[$PW, 824], [$PW, 842], [$PW - 40, 842]], $C['orange']);

    return $pdf->output();
}
