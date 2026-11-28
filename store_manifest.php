<?php
// The online store as an installable app (customers' phones) - separate from
// manifest.php, which installs the shop's own billing app for staff.
require_once __DIR__ . '/includes/init.php';
header('Content-Type: application/manifest+json');
header('Cache-Control: public, max-age=86400');
$name = setting('app_name', 'AK Computer');
echo json_encode(['name' => $name . ' — Online Store', 'short_name' => mb_substr($name, 0, 12), 'description' => 'Computers, CCTV, networking, printers and accessories' . (shop_city() !== '' ? ' — ' . shop_city() : ''),
    'id' => 'catalog.php', 'start_url' => 'catalog.php?source=app', 'scope' => './', 'display' => 'standalone', 'orientation' => 'portrait',
    'background_color' => '#ffffff', 'theme_color' => '#e11d2e', 'categories' => ['shopping'],
    'icons' => [['src' => 'assets/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any maskable'], ['src' => 'assets/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable']],
    'shortcuts' => [['name' => 'Search products', 'url' => 'catalog.php?view=all'], ['name' => 'Categories', 'url' => 'catalog.php?view=cats'], ['name' => 'Track order', 'url' => 'catalog.php?view=account']]],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
