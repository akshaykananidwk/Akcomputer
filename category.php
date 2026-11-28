<?php
// Public category landing page (/c/{slug}) - server-rendered so Google can
// crawl a real "CCTV Camera in Dwarka" page with all products linked, unlike
// the JS-filtered catalog. One page per category with live products.
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/seo.php';

$app_name = setting('app_name', 'AK Computer');
$slug = seo_slug(get('s'));
$cat = null;
foreach (seo_cats() as $c) if ($slug !== '' && seo_slug($c['name']) === $slug) { $cat = $c; break; }
require_once __DIR__ . '/includes/store_ui.php';
if (!$cat) store_404('Category');

site_visit_track('category');

// same look as the rest of the store; products in the smart order
store_landing('cat', $cat['name'], array_values(array_filter(store_items(), fn($i) => $i['cat_name'] === $cat['name'])), seo_cat_url($cat['name']));
