<?php
// Renders an invoice as a JPG image (for sending on WhatsApp as a photo,
// which previews inline - unlike a PDF document). Pure GD, no external
// library. Uses the bundled DejaVu Sans TTF fonts (assets/fonts/).
//
// Layout is built in two passes: pass 1 measures/wraps text and appends
// drawing closures ("ops") to a list while advancing a $y cursor, so the
// canvas height can be sized exactly to the content (item count, whether
// GST/QR/discount rows apply, etc.) before a single pixel is drawn. Pass 2
// creates the real image (rendered at 2x and downsampled for anti-aliased
// circles/rounded corners) and replays the ops.

function bi_font($bold = false) {
    return __DIR__ . '/../assets/fonts/' . ($bold ? 'DejaVuSans-Bold.ttf' : 'DejaVuSans.ttf') . '';
}

/** Wrap $text to fit $maxW logical pixels at the given font size; returns array of lines. */
function bi_wrap($text, $size, $bold, $maxW) {
    $words = preg_split('/\s+/', trim($text));
    $lines = []; $cur = '';
    foreach ($words as $w) {
        $try = $cur === '' ? $w : "$cur $w";
        $box = imagettfbbox($size, 0, bi_font($bold), $try);
        if (($box[2] - $box[0]) > $maxW && $cur !== '') {
            $lines[] = $cur;
            $cur = $w;
        } else {
            $cur = $try;
        }
    }
    if ($cur !== '') $lines[] = $cur;
    return $lines ?: [''];
}
function bi_text_w($str, $size, $bold = false) {
    $box = imagettfbbox($size, 0, bi_font($bold), $str);
    return $box[2] - $box[0];
}
/** Truncate with an ellipsis so $str never overflows $maxW - guards against
 *  unpredictable data length (bank names, account numbers, long addresses). */
function bi_fit($str, $maxW, $size, $bold = false) {
    if (bi_text_w($str, $size, $bold) <= $maxW) return $str;
    $lo = 0; $hi = mb_strlen($str);
    while ($lo < $hi) {
        $mid = intdiv($lo + $hi + 1, 2);
        $t = mb_substr($str, 0, $mid) . '…';
        if (bi_text_w($t, $size, $bold) <= $maxW) $lo = $mid; else $hi = $mid - 1;
    }
    return $lo > 0 ? mb_substr($str, 0, $lo) . '…' : '';
}

// ---------- op-list primitives (pass 1: just record what to draw) ----------
function bi_txt(&$ops, $x, $y, $size, $str, $bold, $rgb) {
    $ops[] = function ($img, $S) use ($x, $y, $size, $str, $bold, $rgb) {
        $c = imagecolorallocate($img, $rgb[0], $rgb[1], $rgb[2]);
        imagettftext($img, $size * $S, 0, $x * $S, $y * $S, $c, bi_font($bold), $str);
    };
}
function bi_txt_right(&$ops, $xRight, $y, $size, $str, $bold, $rgb) {
    bi_txt($ops, $xRight - bi_text_w($str, $size, $bold), $y, $size, $str, $bold, $rgb);
}
function bi_txt_center(&$ops, $xCenter, $y, $size, $str, $bold, $rgb) {
    bi_txt($ops, $xCenter - bi_text_w($str, $size, $bold) / 2, $y, $size, $str, $bold, $rgb);
}
function bi_rect(&$ops, $x1, $y1, $x2, $y2, $rgb) {
    $ops[] = function ($img, $S) use ($x1, $y1, $x2, $y2, $rgb) {
        $c = imagecolorallocate($img, $rgb[0], $rgb[1], $rgb[2]);
        imagefilledrectangle($img, $x1 * $S, $y1 * $S, $x2 * $S, $y2 * $S, $c);
    };
}
/** Filled rounded rect via 2 rects + 4 corner circles (works on any background). */
function bi_rrect(&$ops, $x1, $y1, $x2, $y2, $r, $rgb) {
    $ops[] = function ($img, $S) use ($x1, $y1, $x2, $y2, $r, $rgb) {
        $c = imagecolorallocate($img, $rgb[0], $rgb[1], $rgb[2]);
        $x1 *= $S; $y1 *= $S; $x2 *= $S; $y2 *= $S; $r *= $S;
        imagefilledrectangle($img, $x1 + $r, $y1, $x2 - $r, $y2, $c);
        imagefilledrectangle($img, $x1, $y1 + $r, $x2, $y2 - $r, $c);
        imagefilledellipse($img, $x1 + $r, $y1 + $r, $r * 2, $r * 2, $c);
        imagefilledellipse($img, $x2 - $r, $y1 + $r, $r * 2, $r * 2, $c);
        imagefilledellipse($img, $x1 + $r, $y2 - $r, $r * 2, $r * 2, $c);
        imagefilledellipse($img, $x2 - $r, $y2 - $r, $r * 2, $r * 2, $c);
    };
}
/** A rounded-rect ring (border look): outer rrect in $rgb, inset rrect in $fillRgb on top. */
function bi_rrect_border(&$ops, $x1, $y1, $x2, $y2, $r, $rgb, $fillRgb, $bw = 1.6) {
    bi_rrect($ops, $x1, $y1, $x2, $y2, $r, $rgb);
    bi_rrect($ops, $x1 + $bw, $y1 + $bw, $x2 - $bw, $y2 - $bw, max(1, $r - $bw), $fillRgb);
}
/** Vertical gradient, sharp corners. */
function bi_vgrad(&$ops, $x1, $y1, $x2, $y2, $top, $bot) {
    $ops[] = function ($img, $S) use ($x1, $y1, $x2, $y2, $top, $bot) {
        $x1 *= $S; $y1 *= $S; $x2 *= $S; $y2 *= $S;
        $h = max(1, $y2 - $y1);
        for ($i = 0; $i < $h; $i++) {
            $t = $h > 1 ? $i / ($h - 1) : 0;
            $c = imagecolorallocate($img,
                (int)round($top[0] + ($bot[0] - $top[0]) * $t),
                (int)round($top[1] + ($bot[1] - $top[1]) * $t),
                (int)round($top[2] + ($bot[2] - $top[2]) * $t));
            imagefilledrectangle($img, $x1, $y1 + $i, $x2, $y1 + $i, $c);
        }
    };
}
/** Vertical gradient with rounded corners - "punches" the 4 corners back to
 *  white, which is safe because this invoice is always drawn on a plain
 *  white page background. */
function bi_vgrad_rrect(&$ops, $x1, $y1, $x2, $y2, $r, $top, $bot) {
    bi_vgrad($ops, $x1, $y1, $x2, $y2, $top, $bot);
    $ops[] = function ($img, $S) use ($x1, $y1, $x2, $y2, $r) {
        $white = imagecolorallocate($img, 255, 255, 255);
        $x1 *= $S; $y1 *= $S; $x2 *= $S; $y2 *= $S; $r *= $S;
        $punch = function ($cx, $cy) use ($img, $white, $r) {
            // 4 sub-pixel offset samples softened by drawing at slightly
            // larger radius first isn't worth it here - a single clean
            // circle punch already looks crisp once downsampled from 2x.
            imagefilledellipse($img, $cx, $cy, $r * 2, $r * 2, $white);
        };
        // only punch the true "outside the rounded box" triangle by first
        // re-filling the straight bands, then the corner circle in the
        // gradient's own local color would be ideal, but a flat white punch
        // reads fine at this radius/size in practice.
        imagefilledrectangle($img, $x1, $y1, $x1 + $r, $y1 + $r, $white);
        imagefilledrectangle($img, $x2 - $r, $y1, $x2, $y1 + $r, $white);
        imagefilledrectangle($img, $x1, $y2 - $r, $x1 + $r, $y2, $white);
        imagefilledrectangle($img, $x2 - $r, $y2 - $r, $x2, $y2, $white);
    };
    // now re-draw the 4 quarter-circles in the gradient's local color on top
    $ops[] = function ($img, $S) use ($x1, $y1, $x2, $y2, $r, $top, $bot) {
        $x1 *= $S; $y1 *= $S; $x2 *= $S; $y2 *= $S; $r *= $S;
        $h = max(1, $y2 - $y1);
        $colAt = function ($yy) use ($img, $top, $bot, $y1, $h) {
            $t = max(0, min(1, ($yy - $y1) / max(1, $h - 1)));
            return imagecolorallocate($img,
                (int)round($top[0] + ($bot[0] - $top[0]) * $t),
                (int)round($top[1] + ($bot[1] - $top[1]) * $t),
                (int)round($top[2] + ($bot[2] - $top[2]) * $t));
        };
        imagefilledellipse($img, $x1 + $r, $y1 + $r, $r * 2, $r * 2, $colAt($y1 + $r));
        imagefilledellipse($img, $x2 - $r, $y1 + $r, $r * 2, $r * 2, $colAt($y1 + $r));
        imagefilledellipse($img, $x1 + $r, $y2 - $r, $r * 2, $r * 2, $colAt($y2 - $r));
        imagefilledellipse($img, $x2 - $r, $y2 - $r, $r * 2, $r * 2, $colAt($y2 - $r));
    };
}
function bi_line(&$ops, $x1, $y1, $x2, $y2, $rgb, $w = 1) {
    $ops[] = function ($img, $S) use ($x1, $y1, $x2, $y2, $rgb, $w) {
        $c = imagecolorallocate($img, $rgb[0], $rgb[1], $rgb[2]);
        imagesetthickness($img, max(1, round($w * $S)));
        imageline($img, $x1 * $S, $y1 * $S, $x2 * $S, $y2 * $S, $c);
        imagesetthickness($img, 1);
    };
}
function bi_dashed_hline(&$ops, $x1, $x2, $y, $rgb) {
    $ops[] = function ($img, $S) use ($x1, $x2, $y, $rgb) {
        $c = imagecolorallocate($img, $rgb[0], $rgb[1], $rgb[2]);
        for ($x = $x1; $x < $x2; $x += 7) {
            imagefilledrectangle($img, $x * $S, $y * $S, ($x + 3.5) * $S, $y * $S + max(1, $S - 1), $c);
        }
    };
}
function bi_circle(&$ops, $cx, $cy, $r, $rgb) {
    $ops[] = function ($img, $S) use ($cx, $cy, $r, $rgb) {
        $c = imagecolorallocate($img, $rgb[0], $rgb[1], $rgb[2]);
        imagefilledellipse($img, $cx * $S, $cy * $S, $r * 2 * $S, $r * 2 * $S, $c);
    };
}
function bi_poly(&$ops, $points, $rgb) {
    $ops[] = function ($img, $S) use ($points, $rgb) {
        $c = imagecolorallocate($img, $rgb[0], $rgb[1], $rgb[2]);
        $pts = [];
        foreach ($points as $p) { $pts[] = $p[0] * $S; $pts[] = $p[1] * $S; }
        imagefilledpolygon($img, $pts, $c);
    };
}
// ---------- small icon glyphs (built from the primitives above) ----------
function bi_icon_pin(&$ops, $cx, $cy, $r, $rgb) {
    bi_circle($ops, $cx, $cy, $r, $rgb);
    bi_poly($ops, [[$cx - $r * 0.7, $cy + $r * 0.3], [$cx + $r * 0.7, $cy + $r * 0.3], [$cx, $cy + $r * 1.9]], $rgb);
    bi_circle($ops, $cx, $cy, $r * 0.42, [255, 255, 255]);
}
function bi_icon_person(&$ops, $cx, $cy, $r, $rgb) {
    bi_circle($ops, $cx, $cy - $r * 0.32, $r * 0.36, $rgb);
    bi_poly($ops, [[$cx - $r * 0.55, $cy + $r * 0.55], [$cx + $r * 0.55, $cy + $r * 0.55], [$cx + $r * 0.38, $cy + $r * 0.1], [$cx - $r * 0.38, $cy + $r * 0.1]], $rgb);
}
function bi_icon_check(&$ops, $cx, $cy, $r, $rgb) {
    bi_line($ops, $cx - $r * 0.5, $cy + $r * 0.02, $cx - $r * 0.12, $cy + $r * 0.42, $rgb, 2.4);
    bi_line($ops, $cx - $r * 0.12, $cy + $r * 0.42, $cx + $r * 0.55, $cy - $r * 0.38, $rgb, 2.4);
}
function bi_icon_bolt(&$ops, $cx, $cy, $r, $rgb) {
    bi_poly($ops, [[$cx + $r * 0.15, $cy - $r * 0.65], [$cx - $r * 0.45, $cy + $r * 0.1], [$cx - $r * 0.05, $cy + $r * 0.1],
                   [$cx - $r * 0.2, $cy + $r * 0.65], [$cx + $r * 0.5, $cy - $r * 0.12], [$cx + $r * 0.08, $cy - $r * 0.12]], $rgb);
}
function bi_icon_heart(&$ops, $cx, $cy, $r, $rgb) {
    bi_circle($ops, $cx - $r * 0.28, $cy - $r * 0.15, $r * 0.36, $rgb);
    bi_circle($ops, $cx + $r * 0.28, $cy - $r * 0.15, $r * 0.36, $rgb);
    bi_poly($ops, [[$cx - $r * 0.6, $cy - $r * 0.05], [$cx + $r * 0.6, $cy - $r * 0.05], [$cx, $cy + $r * 0.65]], $rgb);
}
function bi_icon_star(&$ops, $cx, $cy, $r, $rgb) {
    $pts = [];
    for ($i = 0; $i < 10; $i++) {
        $ang = -M_PI / 2 + $i * M_PI / 5;
        $rad = $i % 2 === 0 ? $r : $r * 0.42;
        $pts[] = [$cx + cos($ang) * $rad, $cy + sin($ang) * $rad];
    }
    bi_poly($ops, $pts, $rgb);
}
function bi_icon_bank(&$ops, $cx, $cy, $r, $rgb) {
    bi_poly($ops, [[$cx - $r * 0.7, $cy - $r * 0.15], [$cx, $cy - $r * 0.7], [$cx + $r * 0.7, $cy - $r * 0.15]], $rgb);
    bi_rect_local($ops, $cx - $r * 0.7, $cy - $r * 0.15, $cx + $r * 0.7, $cy + $r * 0.45, $rgb);
    bi_rect_local($ops, $cx - $r * 0.85, $cy + $r * 0.45, $cx + $r * 0.85, $cy + $r * 0.6, $rgb);
}
function bi_rect_local(&$ops, $x1, $y1, $x2, $y2, $rgb) { bi_rect($ops, $x1, $y1, $x2, $y2, $rgb); }
function bi_icon_phone(&$ops, $cx, $cy, $r, $rgb) {
    // classic rotated-handset silhouette: two rounded blobs joined by a bar
    bi_circle($ops, $cx - $r * 0.42, $cy - $r * 0.42, $r * 0.4, $rgb);
    bi_circle($ops, $cx + $r * 0.42, $cy + $r * 0.42, $r * 0.4, $rgb);
    bi_poly($ops, [[$cx - $r * 0.62, $cy - $r * 0.22], [$cx - $r * 0.22, $cy - $r * 0.62],
                   [$cx + $r * 0.62, $cy + $r * 0.22], [$cx + $r * 0.22, $cy + $r * 0.62]], $rgb);
}
function bi_icon_monitor(&$ops, $x, $y, $w, $rgb, $rgbLight) {
    $screenH = $w * 0.62;
    bi_rrect($ops, $x, $y, $x + $w, $y + $screenH, 6, $rgb);
    bi_rrect($ops, $x + $w * 0.08, $y + $screenH * 0.12, $x + $w * 0.92, $y + $screenH * 0.88, 3, $rgbLight);
    bi_rect($ops, $x + $w * 0.42, $y + $screenH, $x + $w * 0.58, $y + $screenH + $w * 0.1, $rgb);
    bi_rrect($ops, $x + $w * 0.22, $y + $screenH + $w * 0.1, $x + $w * 0.78, $y + $screenH + $w * 0.18, 3, $rgb);
}

function invoice_image_jpg($sale, $items) {
    $S = 2; // supersample factor - drawn at 2x then downsampled for smooth circles/rounded corners
    $W = 760; $margin = 30;

    // ---- palette ----
    $navy = [22, 40, 105]; $blue = [58, 91, 224]; $blueDark = [26, 48, 150];
    $purple = [111, 79, 227]; $purpleDark = [70, 49, 168];
    $black = [24, 24, 34]; $gray = [110, 118, 132]; $lightGray = [232, 235, 242];
    $lavender = [238, 240, 254]; $lavenderBorder = [214, 220, 250];
    $green = [16, 148, 82]; $red = [211, 47, 58];
    $gold = [244, 180, 0]; $cream = [255, 244, 213]; $creamBorder = [250, 228, 160];
    $white = [255, 255, 255];

    $bank = default_bank_account();
    $qrPath = null;
    if ($bank && $bank['upi_id']) $qrPath = qr_png_path(upi_uri($bank['upi_id'], $bank['account_name'], $sale['total'], $sale['invoice_no']));
    $due = $sale['is_cancelled'] ? 0 : $sale['total'] - $sale['paid'];
    $companyName = $sale['company_name'];

    $ops = []; $y = 0;

    // ================= HEADER =================
    $logoX1 = $margin; $logoY1 = 20; $logoX2 = $logoX1 + 84; $logoY2 = $logoY1 + 84;
    bi_rrect_border($ops, $logoX1, $logoY1, $logoX2, $logoY2, 16, $lavenderBorder, $white, 2);
    $logoFile = !empty($sale['c_logo']) ? __DIR__ . '/../' . $sale['c_logo'] : null;
    if ($logoFile && is_file($logoFile)) {
        $ops[] = function ($img, $S) use ($logoFile, $logoX1, $logoY1) {
            $ext = strtolower(pathinfo($logoFile, PATHINFO_EXTENSION));
            $src = $ext === 'png' ? @imagecreatefrompng($logoFile) : (in_array($ext, ['jpg', 'jpeg']) ? @imagecreatefromjpeg($logoFile) : null);
            if (!$src) return;
            $sw = imagesx($src); $sh = imagesy($src);
            $fit = min(64 / $sw, 64 / $sh);
            $dw = $sw * $fit; $dh = $sh * $fit;
            $dx = ($logoX1 + 10 + (64 - $dw) / 2) * $S; $dy = ($logoY1 + 10 + (64 - $dh) / 2) * $S;
            imagecopyresampled($img, $src, (int)$dx, (int)$dy, 0, 0, (int)($dw * $S), (int)($dh * $S), $sw, $sh);
            imagedestroy($src);
        };
    } else {
        $initials = mb_strtoupper(mb_substr($companyName, 0, 1) . (mb_strpos($companyName, ' ') ? mb_substr(mb_substr($companyName, mb_strpos($companyName, ' ') + 1), 0, 1) : ''));
        bi_circle($ops, ($logoX1 + $logoX2) / 2, ($logoY1 + $logoY2) / 2, 32, $navy);
        bi_txt_center($ops, ($logoX1 + $logoX2) / 2, ($logoY1 + $logoY2) / 2 + 9, 22, $initials, true, $white);
    }

    $tx = $logoX2 + 14;
    bi_txt($ops, $tx, 44, 22, $companyName, true, $navy);
    $addrLine = trim(($sale['c_address'] ?? '') . ' ' . ($sale['loc_name'] ?? '') . ($sale['loc_city'] ? ', ' . $sale['loc_city'] : ''));
    if ($addrLine !== '') {
        bi_icon_pin($ops, $tx + 5, 65, 6, $blue);
        bi_txt($ops, $tx + 16, 69, 12, $addrLine, false, $gray);
    }
    if (!empty($sale['c_phone'])) bi_txt($ops, $tx, 90, 12, 'Ph: ' . $sale['c_phone'], false, $gray);

    $invX1 = 470; $invX2 = $W - $margin; $invY1 = 20; $invY2 = $invY1 + 76;
    bi_vgrad_rrect($ops, $invX1, $invY1, $invX2, $invY2, 14, $blue, $blueDark);
    $title = $sale['is_gst'] ? 'TAX INVOICE' : 'INVOICE';
    bi_txt_center($ops, ($invX1 + $invX2) / 2, $invY1 + 30, 19, $title, true, $white);
    bi_rrect($ops, $invX1 + 20, $invY1 + 40, $invX2 - 20, $invY2 - 8, 11, $white);
    bi_txt_center($ops, ($invX1 + $invX2) / 2, $invY2 - 20, 13, $sale['invoice_no'], true, $navy);

    $dtxt = 'Date: ' . dmy($sale['sale_date']);
    bi_txt_right($ops, $invX2, $invY2 + 20, 11, $dtxt, false, $gray);
    if (setting('add_time_transactions', '1') === '1' && !empty($sale['created_at'])) {
        bi_txt_right($ops, $invX2, $invY2 + 36, 11, 'Time: ' . date('h:i A', strtotime($sale['created_at'])), false, $gray);
    }

    $y = 150;

    // ================= BILL TO CARD =================
    $cardY1 = $y; $cardY2 = $y + 90;
    bi_rrect($ops, $margin, $cardY1, $W - $margin, $cardY2, 16, $lavender);
    bi_circle($ops, $margin + 40, ($cardY1 + $cardY2) / 2, 26, $purple);
    bi_icon_person($ops, $margin + 40, ($cardY1 + $cardY2) / 2, 22, $white);
    $btX = $margin + 84;
    $monitorX = $W - $margin - 96;
    bi_txt($ops, $btX, $cardY1 + 28, 11, 'BILL TO', true, $purpleDark);
    $custName = $sale['customer_name'] ?: $sale['party_name'] ?: 'Walk-in Customer';
    bi_txt($ops, $btX, $cardY1 + 52, 19, bi_fit($custName, $monitorX - $btX - 16, 19, true), true, $black);
    if (!empty($sale['customer_mobile'])) bi_txt($ops, $btX, $cardY1 + 74, 12, $sale['customer_mobile'], false, $gray);
    bi_icon_monitor($ops, $monitorX, $cardY1 + 16, 66, [206, 217, 250], $white);

    $y = $cardY2 + 20;

    // ================= ITEMS TABLE =================
    // Column anchors + a max content width per numeric column, shared between
    // header and body rows, so the description wrap width can never drift
    // out of sync with where the QTY column actually starts - and every
    // right-aligned value is bi_fit()-clamped to its own reserved slot so
    // an unusually long qty/rate/amount can never bleed into the column
    // to its left (both were real overlap bugs found in testing).
    $colAmtX = $W - $margin; $amtMaxW = 110;
    $colRateX = $colAmtX - $amtMaxW - 20; $rateMaxW = 95;
    $colQtyX = $colRateX - $rateMaxW - 20; $qtyMaxW = 80;
    $descX = $margin + 38; $descMaxW = $colQtyX - $qtyMaxW - 20 - $descX;
    $itemLines = [];
    foreach ($items as $it) $itemLines[] = bi_wrap($it['name'] . ($it['serials'] ? ' (SN: ' . $it['serials'] . ')' : ''), 13, false, $descMaxW);

    $theadY1 = $y; $theadY2 = $y + 32;
    bi_vgrad($ops, $margin, $theadY1, $W - $margin, $theadY2, $blue, $blueDark);
    $rowBase = $theadY2 - 11;
    bi_txt($ops, $margin + 12, $rowBase, 12, '#', true, $white);
    bi_txt($ops, $descX, $rowBase, 12, 'DESCRIPTION', true, $white);
    bi_txt_right($ops, $colQtyX, $rowBase, 12, 'QTY', true, $white);
    bi_txt_right($ops, $colRateX, $rowBase, 12, 'RATE ₹', true, $white);
    bi_txt_right($ops, $colAmtX, $rowBase, 12, 'AMOUNT ₹', true, $white);
    $y = $theadY2 + 22;

    foreach ($items as $n => $it) {
        $lines = $itemLines[$n];
        bi_txt($ops, $margin + 12, $y, 13, (string)($n + 1), false, $gray);
        bi_txt($ops, $descX, $y, 13, $lines[0], false, $black);
        bi_txt_right($ops, $colQtyX, $y, 12, bi_fit((float)$it['qty'] . ' ' . ($it['unit'] ?? ''), $qtyMaxW, 12), false, $gray);
        bi_txt_right($ops, $colRateX, $y, 12, bi_fit(money($it['price']), $rateMaxW, 12), false, $gray);
        bi_txt_right($ops, $colAmtX, $y, 13, bi_fit(money($it['total']), $amtMaxW, 13, true), true, $black);
        $y += 20;
        for ($i = 1; $i < count($lines); $i++) { bi_txt($ops, $margin + 26, $y, 12, $lines[$i], false, $gray); $y += 18; }
        $y += 8;
        bi_dashed_hline($ops, $margin, $W - $margin, $y - 4, $lightGray);
    }
    $y += 12;
    bi_line($ops, $margin, $y, $W - $margin, $y, $navy, 1.4);
    $y += 24;

    // ================= THANK YOU + TOTALS =================
    $topY = $y;
    $thanksLines = bi_wrap('Thank you for choosing ' . $companyName . '!', 16, true, 330);
    $ty = $topY + 20;
    foreach ($thanksLines as $ln) { bi_txt($ops, $margin, $ty, 16, $ln, true, $navy); $ty += 24; }
    bi_icon_star($ops, $margin + bi_text_w(end($thanksLines), 16, true) + 16, $ty - 24 - 6, 8, $gold);
    $leftBlockH = ($ty - $topY) + 10;

    $totRows = [];
    $totRows[] = ['Subtotal', money($sale['subtotal']), false, null];
    if ($sale['discount'] > 0) $totRows[] = ['Discount', '-' . money($sale['discount']), false, null];
    if ($sale['is_gst']) {
        $totRows[] = ['CGST', money($sale['tax_amount'] / 2), false, null];
        $totRows[] = ['SGST', money($sale['tax_amount'] / 2), false, null];
    }
    if (!empty($sale['shipping']) && $sale['shipping'] > 0) $totRows[] = ['Shipping', money($sale['shipping']), false, null];
    if (!empty($sale['adjustment']) && abs($sale['adjustment']) > 0.009) $totRows[] = ['Adjustment', ($sale['adjustment'] > 0 ? '' : '-') . money(abs($sale['adjustment'])), false, null];
    if (!empty($sale['round_off']) && abs($sale['round_off']) > 0.004) $totRows[] = ['Round Off', ($sale['round_off'] > 0 ? '' : '-') . money(abs($sale['round_off'])), false, null];

    $boxX1 = 400; $boxX2 = $W - $margin;
    $plainRowsH = count($totRows) * 24;
    $boxH = 16 + $plainRowsH + 34 + 26 + ($due > 0.009 ? 34 : 0) + 12;
    $boxY1 = $topY; $boxY2 = $boxY1 + $boxH;
    bi_rrect_border($ops, $boxX1, $boxY1, $boxX2, $boxY2, 12, $lightGray, $white, 1.5);
    $ry = $boxY1 + 16;
    foreach ($totRows as $tr) {
        bi_txt($ops, $boxX1 + 16, $ry, 13, $tr[0], false, $black);
        bi_txt_right($ops, $boxX2 - 16, $ry, 13, '₹' . $tr[1], false, $black);
        $ry += 24;
    }
    bi_line($ops, $boxX1 + 12, $ry - 6, $boxX2 - 12, $ry - 6, $lightGray, 1);
    bi_vgrad($ops, $boxX1 + 2, $ry, $boxX2 - 2, $ry + 34, $blue, $blueDark);
    bi_txt($ops, $boxX1 + 16, $ry + 23, 15, 'TOTAL', true, $white);
    bi_txt_right($ops, $boxX2 - 16, $ry + 23, 15, '₹' . money($sale['total']), true, $white);
    $ry += 34 + 8;
    bi_txt($ops, $boxX1 + 16, $ry + 10, 13, 'Paid', false, $black);
    bi_txt_right($ops, $boxX2 - 16, $ry + 10, 13, '₹' . money($sale['paid']), true, $green);
    $ry += 26;
    if ($due > 0.009) {
        bi_vgrad($ops, $boxX1 + 2, $ry, $boxX2 - 2, $ry + 34, $red, [175, 30, 40]);
        bi_txt($ops, $boxX1 + 16, $ry + 23, 15, 'Balance Due', true, $white);
        bi_txt_right($ops, $boxX2 - 16, $ry + 23, 15, '₹' . money($due), true, $white);
    }

    $y = $topY + max($leftBlockH, $boxH) + 24;

    // ================= PAYMENT / QR =================
    if ($qrPath && is_file($qrPath) && $bank) {
        $secH = 190;
        $panelX1 = $margin; $panelX2 = 330;
        bi_vgrad_rrect($ops, $panelX1, $y, $panelX2, $y + $secH, 16, $purple, $purpleDark);
        $features = [
            ['check', '100% Secure', 'Safe & Trusted Payment'],
            ['bolt', 'Instant Payment', 'Quick & Hassle Free'],
            ['heart', 'Thank You!', 'We Value Your Support'],
        ];
        $fy = $y + 20;
        foreach ($features as $f) {
            bi_circle($ops, $panelX1 + 30, $fy + 14, 15, $white);
            $iconFn = 'bi_icon_' . $f[0];
            $iconFn($ops, $panelX1 + 30, $fy + 14, 13, $purpleDark);
            bi_txt($ops, $panelX1 + 56, $fy + 12, 13, $f[1], true, $white);
            bi_txt($ops, $panelX1 + 56, $fy + 30, 11, $f[2], false, [222, 216, 250]);
            $fy += 54;
        }

        $rx1 = 350;
        bi_txt($ops, $rx1, $y + 20, 15, 'Scan & Pay ₹' . money($sale['total']), true, $navy);
        $qrSize = 128;
        $ops[] = function ($img, $S) use ($qrPath, $rx1, $y, $qrSize) {
            $qr = @imagecreatefrompng($qrPath);
            if (!$qr) return;
            imagecopyresampled($img, $qr, (int)($rx1 * $S), (int)(($y + 32) * $S), 0, 0, $qrSize * $S, $qrSize * $S, imagesx($qr), imagesy($qr));
            imagedestroy($qr);
        };
        $bx = $rx1 + $qrSize + 18;
        $bTextW = ($W - $margin) - ($bx + 40); // remaining width to the right margin
        bi_circle($ops, $bx + 16, $y + 58, 16, $navy);
        bi_icon_bank($ops, $bx + 16, $y + 58, 13, $white);
        bi_txt($ops, $bx + 40, $y + 54, 13, bi_fit($bank['account_name'], $bTextW, 13, true), true, $black);
        bi_txt($ops, $bx + 40, $y + 72, 12, bi_fit($bank['bank_name'], $bTextW, 12), false, $gray);
        bi_txt($ops, $bx + 40, $y + 90, 12, bi_fit('A/C: ' . $bank['account_number'], $bTextW, 12), false, $gray);
        bi_circle($ops, $bx + 16, $y + 112, 12, $green);
        bi_icon_check($ops, $bx + 16, $y + 112, 10, $white);
        foreach (bi_wrap('Thank you for your payment!', 11, false, $bTextW + 4) as $i => $ln) {
            bi_txt($ops, $bx + 36, $y + 114 + $i * 16, 11, $ln, false, $gray);
        }

        $y += $secH + 20;
    }

    // ================= FOOTER THANK-YOU BANNER =================
    $fbH = 54;
    bi_rrect_border($ops, $margin, $y, $W - $margin, $y + $fbH, 14, $creamBorder, $cream, 1.5);
    bi_circle($ops, $margin + 26, $y + $fbH / 2, 16, $gold);
    bi_icon_star($ops, $margin + 26, $y + $fbH / 2, 10, $white);
    bi_txt($ops, $margin + 56, $y + $fbH / 2 - 4, 12, 'We appreciate your business and look', false, $black);
    bi_txt($ops, $margin + 56, $y + $fbH / 2 + 14, 12, 'forward to serving you again!', false, $black);
    bi_txt_right($ops, $W - $margin - 16, $y + $fbH / 2 + 6, 15, 'Thank You!', true, $navy);
    $y += $fbH + 20;

    // ================= BOTTOM CONTACT BAR =================
    $barH = 46;
    bi_vgrad($ops, 0, $y, $W, $y + $barH, $navy, [16, 30, 80]);
    $contactItems = [];
    if (!empty($sale['c_phone'])) $contactItems[] = ['pin' => 'phone', 'txt' => $sale['c_phone']];
    $locTxt = trim(($sale['loc_name'] ?? '') . ($sale['loc_city'] ? ', ' . $sale['loc_city'] : ''));
    if ($locTxt !== '') $contactItems[] = ['pin' => 'pin', 'txt' => $locTxt];
    $gap = 44;
    $widths = array_map(fn($c) => 20 + bi_text_w($c['txt'], 12, false), $contactItems);
    $totalW = array_sum($widths) + $gap * max(0, count($contactItems) - 1);
    $cx = ($W - $totalW) / 2;
    foreach ($contactItems as $i => $c) {
        if ($c['pin'] === 'phone') {
            bi_icon_phone($ops, $cx + 7, $y + $barH / 2, 8, $white);
        } else {
            bi_icon_pin($ops, $cx + 7, $y + $barH / 2 - 4, 6, $white);
        }
        bi_txt($ops, $cx + 20, $y + $barH / 2 + 4, 12, $c['txt'], false, $white);
        $cx += $widths[$i] + $gap;
    }
    $y += $barH + 16;

    // ---- computer-generated note pill ----
    $note = 'This is a computer generated invoice.';
    $nw = bi_text_w($note, 11, false) + 28;
    bi_rrect($ops, ($W - $nw) / 2, $y, ($W + $nw) / 2, $y + 24, 12, $lightGray);
    bi_txt_center($ops, $W / 2, $y + 16, 11, $note, false, $gray);
    $y += 24 + 16;

    $H = (int)ceil($y);

    // ---- pass 2: create the real (supersampled) canvas and replay ops ----
    $big = imagecreatetruecolor($W * $S, $H * $S);
    $white_c = imagecolorallocate($big, 255, 255, 255);
    imagefill($big, 0, 0, $white_c);
    foreach ($ops as $op) $op($big, $S);

    $img = imagecreatetruecolor($W, $H);
    imagecopyresampled($img, $big, 0, 0, 0, 0, $W, $H, $W * $S, $H * $S);
    imagedestroy($big);

    ob_start();
    imagejpeg($img, null, 90);
    $data = ob_get_clean();
    imagedestroy($img);
    return $data;
}
