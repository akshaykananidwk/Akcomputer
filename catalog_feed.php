<?php
// Meta (Facebook/Instagram/WhatsApp) catalog feed - CSV in Commerce Manager's
// data-feed format, listing every website-enabled item. Paste this URL into
// Meta Commerce Manager > Data Sources > Scheduled Feed and the WhatsApp
// Business / FB / Instagram catalog stays in sync with the shop automatically.
// Secured by ?key= (shown in Settings > WhatsApp) since it must be reachable
// by Meta's fetcher without a login.
require_once __DIR__ . '/includes/init.php';

if (setting('catalog_feed_key', '') === '') set_setting('catalog_feed_key', bin2hex(random_bytes(10)));
if (!hash_equals(setting('catalog_feed_key'), (string)get('key'))) {
    http_response_code(403);
    die('Invalid key. Copy the feed URL from Settings.');
}

$items = all("SELECT i.*, c.name cat_name FROM items i LEFT JOIN categories c ON c.id = i.category_id
              WHERE i.is_active = 1 AND i.show_on_website = 1 AND i.selling_price > 0 ORDER BY i.id");

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: inline; filename="catalog_feed.csv"');
$out = fopen('php://output', 'w');
fputs($out, "\xEF\xBB\xBF"); // BOM so Excel/Meta read UTF-8 (Gujarati names) right
fputcsv($out, ['id', 'title', 'description', 'availability', 'condition', 'price', 'link', 'image_link', 'brand', 'product_type']);
foreach ($items as $it) {
    $stockQ = $it['item_type'] === 'service' ? 1 : (float)(val('SELECT SUM(qty) FROM stock WHERE item_id = ?', [$it['id']]) ?? 0);
    fputcsv($out, [
        'AK' . $it['id'],
        mb_substr($it['name'], 0, 150),
        mb_substr(trim(($it['brand'] ? $it['brand'] . ' ' : '') . ($it['model'] ? $it['model'] . '. ' : '') . strip_tags((string)($it['description'] ?? ''))) ?: $it['name'], 0, 4900),
        $stockQ > 0 ? 'in stock' : 'out of stock',
        'new',
        number_format((float)$it['selling_price'], 2, '.', '') . ' INR', // Meta rejects thousands separators
        base_url('product.php?id=' . $it['id']),
        $it['photo'] ? (preg_match('~^https?://~', $it['photo']) ? $it['photo'] : base_url(ltrim($it['photo'], '/'))) : '',
        $it['brand'] ?: setting('app_name', 'AK Computer'),
        $it['cat_name'] ?: 'Electronics',
    ]);
}
fclose($out);
