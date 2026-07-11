<?php
// Barcode PNG image endpoint: barcode.php?item_id=123 (or ?text=ANY-CODE)
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/barcode128.php';
require_login();

$text = get('text');
if (!$text && (int)get('item_id')) {
    $item = row('SELECT * FROM items WHERE id = ?', [(int)get('item_id')]);
    if ($item) {
        $text = $item['barcode'];
        if (!$text) {
            // auto-generate + persist a stable code for items that never had one
            $text = 'AK' . str_pad($item['id'], 6, '0', STR_PAD_LEFT);
            q('UPDATE items SET barcode = ? WHERE id = ?', [$text, $item['id']]);
        }
    }
}
if (!$text) { http_response_code(404); exit; }

header('Content-Type: image/png');
header('Cache-Control: private, max-age=86400');
echo barcode_code128_png($text, (int)get('h', 60), (float)get('s', 2));
