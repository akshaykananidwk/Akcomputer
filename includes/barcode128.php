<?php
// Pure-PHP Code 128 (subset B, printable ASCII 32-126) barcode renderer
// using GD - no external libraries. Standard Code128-B width-pattern
// table (each entry = 6 bar/space widths in modules; the STOP pattern has
// 7). Verified against zbarimg during development.

function code128b_patterns() {
    return [
        '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213',
        '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132',
        '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211',
        '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
        '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331',
        '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111',
        '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214',
        '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
        '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141',
        '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141',
        '114131', '311141', '411131', '211412', '211214', '211232', '2331112',
    ];
}

/** Returns an array of alternating bar/space widths (in modules), starting with a bar. */
function code128b_widths($text) {
    $pat = code128b_patterns();
    $values = [104]; // START B
    $len = strlen($text);
    for ($i = 0; $i < $len; $i++) {
        $c = ord($text[$i]) - 32;
        if ($c < 0 || $c > 94) $c = 0; // unsupported char -> space
        $values[] = $c;
    }
    $checksum = $values[0];
    for ($i = 1; $i < count($values); $i++) $checksum += $values[$i] * $i;
    $values[] = $checksum % 103;
    $values[] = 106; // STOP

    $widths = [];
    foreach ($values as $v) {
        foreach (str_split($pat[$v]) as $w) $widths[] = (int)$w;
    }
    return $widths;
}

/** Renders a Code128-B barcode as a PNG. Returns raw PNG bytes. */
function barcode_code128_png($text, $barHeight = 60, $scale = 2, $showText = true) {
    $text = (string)$text;
    if ($text === '') $text = ' ';
    $widths = code128b_widths($text);
    $totalModules = array_sum($widths);
    $margin = 10;
    $imgW = $totalModules * $scale + $margin * 2;
    $imgH = $barHeight + $margin + ($showText ? 18 : 0);
    $img = imagecreatetruecolor($imgW, $imgH);
    $white = imagecolorallocate($img, 255, 255, 255);
    $black = imagecolorallocate($img, 0, 0, 0);
    imagefill($img, 0, 0, $white);
    $x = $margin;
    $isBar = true;
    foreach ($widths as $w) {
        $wpx = $w * $scale;
        if ($isBar) imagefilledrectangle($img, $x, $margin, $x + $wpx - 1, $margin + $barHeight - 1, $black);
        $x += $wpx;
        $isBar = !$isBar;
    }
    if ($showText) {
        $tw = imagefontwidth(3) * strlen($text);
        imagestring($img, 3, max($margin, (int)(($imgW - $tw) / 2)), $margin + $barHeight + 2, $text, $black);
    }
    ob_start();
    imagepng($img);
    $data = ob_get_clean();
    imagedestroy($img);
    return $data;
}
