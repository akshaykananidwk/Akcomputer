<?php
// XML sitemap for search engines: the store front plus one URL per product.
// Submit this URL in Google Search Console (and it's referenced in robots.txt).
require_once __DIR__ . '/includes/init.php';
header('Content-Type: application/xml; charset=utf-8');
$items = all('SELECT id, created_at FROM items WHERE is_active = 1 AND show_on_website = 1 ORDER BY id');
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
echo '<url><loc>' . e(base_url('catalog.php')) . '</loc><changefreq>daily</changefreq><priority>1.0</priority></url>' . "\n";
echo '<url><loc>' . e(base_url('referral.php')) . '</loc><changefreq>monthly</changefreq><priority>0.4</priority></url>' . "\n";
foreach ($items as $it) {
    echo '<url><loc>' . e(base_url('product.php?id=' . $it['id'])) . '</loc><changefreq>weekly</changefreq><priority>0.8</priority></url>' . "\n";
}
echo '</urlset>';
