<?php
// XML sitemaps for search engines. /sitemap.xml is a sitemap INDEX pointing
// at 5 small section sitemaps - each one can also be submitted separately in
// Google Search Console, so if one section ever has a problem the rest still
// index fine:
//   /sitemap-main.xml        home, services, static pages
//   /sitemap-categories.xml  category pages
//   /sitemap-brands.xml      brand pages
//   /sitemap-products.xml    one URL per product
//   /sitemap-prices.xml      the "price" SEO page per product
// (all rewritten to sitemap.php?part=... in .htaccess)
//
// Hardened for finicky crawlers: any buffered output (a stray BOM, a PHP
// notice from some other include on the host) is discarded before the XML
// declaration - Google rejects the whole file over a single leading byte.
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/seo.php';

ini_set('display_errors', '0');
while (ob_get_level()) ob_end_clean();
header('Content-Type: application/xml; charset=UTF-8');
header('X-Robots-Tag: noindex'); // the sitemap file itself shouldn't be indexed as a page

function sm_url($loc, $freq, $prio, $lastmod = '') {
    echo '<url><loc>' . e($loc) . '</loc>'
       . ($lastmod ? '<lastmod>' . e(date('Y-m-d', strtotime($lastmod))) . '</lastmod>' : '')
       . '<changefreq>' . $freq . '</changefreq><priority>' . $prio . '</priority></url>' . "\n";
}

$part = get('part');
$parts = ['main', 'categories', 'brands', 'products', 'prices'];

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";

// ---------- /sitemap.xml = the index of all five ----------
if ($part === '' || !in_array($part, $parts, true)) {
    echo '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    foreach ($parts as $p) {
        echo '<sitemap><loc>' . e(base_url('sitemap-' . $p . '.xml')) . '</loc><lastmod>' . date('Y-m-d') . '</lastmod></sitemap>' . "\n";
    }
    echo '</sitemapindex>';
    exit;
}

echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

if ($part === 'main') {
    sm_url(base_url('') . '/', 'daily', '1.0', today());
    sm_url(base_url('services.php'), 'weekly', '0.8');
    foreach (seo_services() as $slug => $sv) sm_url(seo_service_url($slug), 'monthly', '0.8');
    sm_url(base_url('privacy.php'), 'monthly', '0.3');
    sm_url(base_url('terms.php'), 'monthly', '0.3');
    sm_url(base_url('referral.php'), 'monthly', '0.3');
}

if ($part === 'categories') {
    foreach (seo_cats() as $c) if (seo_slug($c['name']) !== '') sm_url(seo_cat_url($c['name']), 'daily', '0.9', today());
}

if ($part === 'brands') {
    foreach (seo_brands() as $b) if (seo_slug($b['name']) !== '') sm_url(seo_brand_url($b['name']), 'weekly', '0.8');
}

if ($part === 'products' || $part === 'prices') {
    $items = all('SELECT id, name, brand, model, created_at FROM items WHERE is_active = 1 AND show_on_website = 1 ORDER BY id');
    foreach ($items as $it) {
        if ($part === 'products') sm_url(seo_product_url($it), 'weekly', '0.8', $it['created_at']);
        else sm_url(seo_price_url($it), 'weekly', '0.7', $it['created_at']);
    }
}

echo '</urlset>';
