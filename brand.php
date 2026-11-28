<?php
// Public brand landing page (/b/{slug}) - "Hikvision dealer in Dwarka" type
// queries. One server-rendered page per brand with live products.
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/seo.php';

$app_name = setting('app_name', 'AK Computer');
$slug = seo_slug(get('s'));
$brand = null;
foreach (seo_brands() as $b) if ($slug !== '' && seo_slug($b['name']) === $slug) { $brand = $b['name']; break; }
require_once __DIR__ . '/includes/store_ui.php';
if ($brand === null) store_404('Brand');

site_visit_track('brand');

// same look as the rest of the store; products in the smart order
store_landing('brand', $brand, array_values(array_filter(store_items(), fn($i) => strcasecmp(trim((string)$i['brand']), $brand) === 0)), seo_brand_url($brand));
