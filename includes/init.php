<?php
// Bootstrap - include this at the top of every page
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '0');

$configFile = dirname(__DIR__) . '/config.php';
if (!file_exists($configFile)) {
    header('Location: install/');
    exit;
}
require_once $configFile;

date_default_timezone_set(defined('APP_TZ') ? APP_TZ : 'Asia/Kolkata');

session_name('akcsess');
// 'secure' is set only when the request actually arrived over HTTPS, so a
// local/http install still logs in while the live site never leaks its
// session cookie over a plain-HTTP request. Proxy/CDN setups terminate TLS
// upstream and tell us through X-Forwarded-Proto.
$_isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
    || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => $_isHttps]);
session_start();

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/money.php'; // the money rules, before anything that uses them
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/errors.php'; // capture + alert (needs setting()/tg from helpers)
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/whatsapp.php';
require_once __DIR__ . '/icons.php';
require_once __DIR__ . '/accounting.php';
require_once __DIR__ . '/custom_reports.php';
require_once __DIR__ . '/api_auth.php';
require_once __DIR__ . '/webhooks.php';
require_once __DIR__ . '/xlsx_writer.php';
require_once __DIR__ . '/ai_insights.php';
require_once __DIR__ . '/dashboard.php'; // needs money.php + ai_insights' trend_direction()
require_once __DIR__ . '/customer.php';  // customer intelligence + collection
require_once __DIR__ . '/purchase_intel.php'; // smart stock + purchasing
require_once __DIR__ . '/ocr.php';
require_once __DIR__ . '/ai.php';
require_once __DIR__ . '/ai_guard.php'; // budget, switches and the fallback contract

csrf_check();

function base_url($path = '') {
    $base = defined('BASE_URL') && BASE_URL ? BASE_URL : (
        (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' .
        ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/\\')
    );
    // config.php's BASE_URL is easy to fill in as a bare domain (e.g.
    // "shop.akdwk.in") instead of a full URL - that produced links with no
    // scheme at all, which external services (WhatsApp media fetch, QR
    // codes, share links) can't load. Default a missing scheme to https.
    if (!preg_match('#^https?://#i', $base)) $base = 'https://' . $base;
    return rtrim($base, '/') . ($path ? '/' . ltrim($path, '/') : '');
}
