<?php
// The installable app's name and icon - each shop's own (its name, its logo).
require_once __DIR__ . '/includes/init.php';
header('Content-Type: application/manifest+json');
$name = setting('app_name', 'AK Computer');
$logo = (string)val("SELECT logo FROM companies WHERE is_active = 1 AND logo <> '' ORDER BY id LIMIT 1");
$icons = [['src' => 'assets/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png'], ['src' => 'assets/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png']];
if ($logo !== '' && is_file(__DIR__ . '/' . $logo)) {
    [$w, $h] = @getimagesize(__DIR__ . '/' . $logo) ?: [0, 0];
    if ($w >= 192 && abs($w - $h) < 4) array_unshift($icons, ['src' => $logo, 'sizes' => "{$w}x{$h}", 'type' => mime_content_type(__DIR__ . '/' . $logo), 'purpose' => 'any']);
}
echo json_encode(['name' => $name . (tenant_active() ? '' : ' Manager'), 'short_name' => mb_substr($name, 0, 12), 'description' => 'Billing, stock, payments',
    'start_url' => 'index.php', 'scope' => './', 'display' => 'standalone', 'background_color' => '#f1f5f9', 'theme_color' => '#1a56db', 'orientation' => 'portrait', 'icons' => $icons],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
