<?php
// XML sitemap for search engines - reachable as /sitemap.xml (rewritten in
// .htaccess). Lists EVERY public page: store, categories, brands, services
// and both SEO pages per product (product + price). Submit /sitemap.xml in
// Google Search Console.
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/seo.php';
header('Content-Type: application/xml; charset=utf-8');

function sm_url($loc, $freq, $prio, $lastmod = '') {
    echo '<url><loc>' . e($loc) . '</loc>'
       . ($lastmod ? '<lastmod>' . e($lastmod) . '</lastmod>' : '')
       . '<changefreq>' . $freq . '</changefreq><priority>' . $prio . '</priority></url>' . "\n";
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

sm_url(base_url('') . '/', 'daily', '1.0', date('Y-m-d'));
sm_url(base_url('services.php'), 'weekly', '0.8');
foreach (seo_services() as $slug => $sv) sm_url(seo_service_url($slug), 'monthly', '0.8');
foreach (seo_cats() as $c) if (seo_slug($c['name']) !== '') sm_url(seo_cat_url($c['name']), 'daily', '0.9', date('Y-m-d'));
foreach (seo_brands() as $b) if (seo_slug($b['name']) !== '') sm_url(seo_brand_url($b['name']), 'weekly', '0.8');

$items = all('SELECT id, name, brand, model, created_at FROM items WHERE is_active = 1 AND show_on_website = 1 ORDER BY id');
foreach ($items as $it) {
    sm_url(seo_product_url($it), 'weekly', '0.8');
    sm_url(seo_price_url($it), 'weekly', '0.7');
}
sm_url(base_url('privacy.php'), 'monthly', '0.3');
sm_url(base_url('terms.php'), 'monthly', '0.3');
sm_url(base_url('referral.php'), 'monthly', '0.3');
echo '</urlset>';
