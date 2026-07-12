<?php
// Renders an invoice as a JPG image (for sending on WhatsApp as a photo,
// which previews inline - unlike a PDF document). Pure GD, no external
// library. Uses the bundled DejaVu Sans TTF fonts (assets/fonts/).

function bi_font($bold = false) {
    return __DIR__ . '/../assets/fonts/' . ($bold ? 'DejaVuSans-Bold.ttf' : 'DejaVuSans.ttf') . '';
}

/** Wrap $text to fit $maxW pixels at the given font size; returns array of lines. */
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

function invoice_image_jpg($sale, $items) {
    $W = 720;
    $margin = 30;
    $contentW = $W - $margin * 2;

    // pre-wrap variable-height content so we can size the canvas first
    $itemLines = [];
    foreach ($items as $it) {
        $nameLines = bi_wrap($it['name'] . ($it['serials'] ? ' (SN: ' . $it['serials'] . ')' : ''), 15, false, $contentW - 160);
        $itemLines[] = $nameLines;
    }
    $itemsHeight = 0;
    foreach ($itemLines as $ls) $itemsHeight += 26 + (count($ls) - 1) * 20;

    $bank = default_bank_account();
    $qrPath = null;
    if ($bank && $bank['upi_id']) {
        $qrPath = qr_png_path(upi_uri($bank['upi_id'], $bank['account_name'], $sale['total'], $sale['invoice_no']));
    }

    $due = $sale['is_cancelled'] ? 0 : $sale['total'] - $sale['paid'];
    $wordsLines = bi_wrap('Amount in words: ' . amount_in_words($sale['total']), 14, false, $contentW);

    $H = 210 + $itemsHeight + 40 + (($sale['discount'] > 0) ? 24 : 0) + (($sale['is_gst']) ? 48 : 0)
       + (($sale['shipping'] > 0) ? 24 : 0) + 90 + (count($wordsLines) * 18) + ($qrPath ? 180 : 30) + 60;

    $img = imagecreatetruecolor($W, $H);
    $white = imagecolorallocate($img, 255, 255, 255);
    $black = imagecolorallocate($img, 20, 20, 30);
    $gray = imagecolorallocate($img, 110, 118, 130);
    $lightLine = imagecolorallocate($img, 220, 224, 230);
    $accent = imagecolorallocate($img, 26, 86, 219);
    imagefill($img, 0, 0, $white);

    $y = $margin + 10;
    imagettftext($img, 20, 0, $margin, $y, $accent, bi_font(true), $sale['company_name']);
    $y += 22;
    if (!empty($sale['c_address']) || !empty($sale['loc_name'])) {
        imagettftext($img, 12, 0, $margin, $y, $gray, bi_font(false), trim($sale['c_address'] . ' ' . $sale['loc_name'] . ', ' . $sale['loc_city']));
        $y += 18;
    }
    if (!empty($sale['c_phone'])) { imagettftext($img, 12, 0, $margin, $y, $gray, bi_font(false), 'Ph: ' . $sale['c_phone']); $y += 18; }

    $titleR = $W - $margin;
    $title = $sale['is_gst'] ? 'TAX INVOICE' : 'INVOICE';
    $tbox = imagettfbbox(15, 0, bi_font(true), $title);
    imagettftext($img, 15, 0, $titleR - ($tbox[2] - $tbox[0]), $margin + 15, $black, bi_font(true), $title);
    $ibox = imagettfbbox(13, 0, bi_font(false), $sale['invoice_no']);
    imagettftext($img, 13, 0, $titleR - ($ibox[2] - $ibox[0]), $margin + 35, $black, bi_font(false), $sale['invoice_no']);
    $dtxt = 'Date: ' . dmy($sale['sale_date']);
    $dbox = imagettfbbox(11, 0, bi_font(false), $dtxt);
    imagettftext($img, 11, 0, $titleR - ($dbox[2] - $dbox[0]), $margin + 53, $gray, bi_font(false), $dtxt);

    $y = max($y, $margin + 70) + 10;
    imageline($img, $margin, $y, $W - $margin, $y, $lightLine);
    $y += 24;

    $billTo = 'Bill To: ' . ($sale['customer_name'] ?: $sale['party_name'] ?: 'Walk-in Customer');
    imagettftext($img, 13, 0, $margin, $y, $black, bi_font(true), $billTo);
    $y += 20;
    if (!empty($sale['customer_mobile'])) { imagettftext($img, 12, 0, $margin, $y, $gray, bi_font(false), $sale['customer_mobile']); $y += 20; }
    $y += 8;
    imageline($img, $margin, $y, $W - $margin, $y, $lightLine);
    $y += 22;

    foreach ($items as $n => $it) {
        $lines = $itemLines[$n];
        imagettftext($img, 13, 0, $margin, $y, $black, bi_font(false), ($n + 1) . '. ' . $lines[0]);
        $qtyTxt = (float)$it['qty'] . ' x ' . money($it['price']);
        $amtTxt = money($it['total']);
        $abox = imagettfbbox(13, 0, bi_font(false), $amtTxt);
        imagettftext($img, 13, 0, $W - $margin - ($abox[2] - $abox[0]), $y, $black, bi_font(false), $amtTxt);
        $qbox = imagettfbbox(11, 0, bi_font(false), $qtyTxt);
        imagettftext($img, 11, 0, $W - $margin - ($abox[2] - $abox[0]) - 14 - ($qbox[2] - $qbox[0]), $y, $gray, bi_font(false), $qtyTxt);
        $y += 20;
        for ($i = 1; $i < count($lines); $i++) { imagettftext($img, 12, 0, $margin + 16, $y, $gray, bi_font(false), $lines[$i]); $y += 18; }
        $y += 6;
    }
    $y += 8;
    imageline($img, $margin, $y, $W - $margin, $y, $lightLine);
    $y += 26;

    $totalsRow = function ($label, $val, $bold = false, $color = null) use ($img, $margin, $W, &$y, $black) {
        imagettftext($img, $bold ? 15 : 13, 0, $margin, $y, $color ?: $black, bi_font($bold), $label);
        $vtxt = '₹' . $val;
        $box = imagettfbbox($bold ? 15 : 13, 0, bi_font($bold), $vtxt);
        imagettftext($img, $bold ? 15 : 13, 0, $W - $margin - ($box[2] - $box[0]), $y, $color ?: $black, bi_font($bold), $vtxt);
        $y += $bold ? 26 : 22;
    };
    $totalsRow('Subtotal', money($sale['subtotal']));
    if ($sale['discount'] > 0) $totalsRow('Discount', '-' . money($sale['discount']));
    if ($sale['is_gst']) {
        $totalsRow('CGST', money($sale['tax_amount'] / 2));
        $totalsRow('SGST', money($sale['tax_amount'] / 2));
    }
    if (!empty($sale['shipping']) && $sale['shipping'] > 0) $totalsRow('Shipping', money($sale['shipping']));
    imageline($img, $margin, $y, $W - $margin, $y, $lightLine);
    $y += 10;
    $totalsRow('TOTAL', money($sale['total']), true, $accent);
    $totalsRow('Paid', money($sale['paid']));
    if ($due > 0.009) { $bad = imagecolorallocate($img, 200, 40, 40); $totalsRow('Balance Due', money($due), true, $bad); }
    $y += 6;

    foreach ($wordsLines as $wl) { imagettftext($img, 12, 0, $margin, $y, $gray, bi_font(false), $wl); $y += 18; }
    $y += 10;

    if ($qrPath && is_file($qrPath)) {
        imageline($img, $margin, $y, $W - $margin, $y, $lightLine);
        $y += 20;
        $qr = @imagecreatefrompng($qrPath);
        if ($qr) {
            imagecopyresampled($img, $qr, $margin, $y, 0, 0, 150, 150, imagesx($qr), imagesy($qr));
            imagedestroy($qr);
            imagettftext($img, 12, 0, $margin + 165, $y + 20, $black, bi_font(true), 'Scan & Pay ₹' . money($sale['total']));
            if ($bank) {
                imagettftext($img, 11, 0, $margin + 165, $y + 42, $gray, bi_font(false), $bank['account_name'] . ' - ' . $bank['bank_name']);
                imagettftext($img, 11, 0, $margin + 165, $y + 60, $gray, bi_font(false), 'A/C: ' . $bank['account_number']);
            }
        }
        $y += 160;
    }

    imagettftext($img, 11, 0, $margin, $H - 20, $gray, bi_font(false), 'This is a computer generated invoice.');

    ob_start();
    imagejpeg($img, null, 88);
    $data = ob_get_clean();
    imagedestroy($img);
    return $data;
}
