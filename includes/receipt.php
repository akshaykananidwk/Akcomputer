<?php
// A bill as a small till receipt: plain lines of fixed width, so the very
// same text prints on a USB/thermal printer through the browser and on a
// Bluetooth printer sent straight from the phone. 32 characters = 58 mm
// paper, 48 = 80 mm (Settings → Invoice).

function receipt_width() { return setting('receipt_paper', '58') === '80' ? 48 : 32; }

/** The receipt as an array of lines (ASCII only - small printers have no ₹ or Gujarati font). */
function receipt_lines($saleId, $w = null) {
    $w = $w ?: receipt_width();
    $s = row('SELECT s.*, c.name company_name, c.gstin c_gstin, c.address c_address, c.phone c_phone FROM sales s JOIN companies c ON c.id = s.company_id WHERE s.id = ?', [(int)$saleId]);
    if (!$s) return [];
    $items = all("SELECT si.*, COALESCE(i.name, 'Item') name FROM sale_items si LEFT JOIN items i ON i.id = si.item_id WHERE si.sale_id = ? AND si.qty > 0", [$s['id']]);
    $a = fn($t) => trim(preg_replace('/[^\x20-\x7E]/', '', str_replace('₹', 'Rs', (string)$t)));
    $c = fn($t) => str_pad(mb_substr($a($t), 0, $w), $w, ' ', STR_PAD_BOTH);
    $lr = fn($l, $r) => str_pad(mb_substr($a($l), 0, max(1, $w - strlen($a($r)) - 1)), $w - strlen($a($r))) . $a($r);
    $hr = str_repeat('-', $w);
    $out = [$c(mb_strtoupper($s['company_name']))];
    foreach (array_filter([$s['c_address'], $s['c_phone'] ? 'Ph: ' . $s['c_phone'] : '', $s['c_gstin'] ? 'GSTIN: ' . $s['c_gstin'] : '']) as $l)
        foreach (explode("\n", wordwrap($a($l), $w, "\n", true)) as $part) $out[] = $c($part);
    $out[] = $hr;
    $out[] = $lr('Bill: ' . $s['invoice_no'], dmy($s['sale_date']));
    if (trim((string)$s['customer_name']) !== '') $out[] = $a('To: ' . $s['customer_name']);
    $out[] = $hr;
    foreach ($items as $it) {
        foreach (explode("\n", wordwrap($a($it['name']) ?: 'Item', $w, "\n", true)) as $part) $out[] = $part;
        $out[] = $lr('  ' . (+$it['qty']) . ' x ' . money($it['price']), money($it['total']));
    }
    $out[] = $hr;
    if ((float)$s['discount'] > 0) $out[] = $lr('Discount', '-' . money($s['discount']));
    if ((float)$s['tax_amount'] > 0) $out[] = $lr('GST', money($s['tax_amount']));
    if (abs((float)$s['round_off']) > 0.004) $out[] = $lr('Round off', money($s['round_off']));
    $out[] = $lr('TOTAL', 'Rs ' . money($s['total']));
    $out[] = $lr('Paid', money($s['paid']));
    if ((float)$s['total'] - (float)$s['paid'] > 0.009) $out[] = $lr('Balance due', money($s['total'] - $s['paid']));
    foreach (explode("\n", wordwrap($a(amount_in_words($s['total'])), $w, "\n", true)) as $part) $out[] = $part;
    $out[] = $hr;
    $out[] = $c('Thank you! Visit again');
    return $out;
}
