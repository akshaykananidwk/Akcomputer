<?php
// Called by test_tenant.php in its own process: make a real shop, look at
// it from the inside, then remove every trace of it. Prints one JSON line.
if (PHP_SAPI !== 'cli') { http_response_code(403); die('CLI only'); }
$slug = preg_replace('/[^a-z0-9]/', '', (string)($argv[1] ?? ''));
if ($slug === '') die('{}');
require_once dirname(__DIR__) . '/includes/init.php';
require_once dirname(__DIR__) . '/includes/platform.php';

$out = [];
$oldDom = val("SELECT value FROM settings WHERE name = 'platform_domain'");
$oldMode = val("SELECT value FROM settings WHERE name = 'platform_db_mode'");
set_setting('platform_domain', 'e2e.test');
set_setting('platform_db_mode', 'mysql');
try {
    $t = provision_tenant(['slug' => $slug, 'name' => 'Shree Test Mobile', 'owner_name' => 'Test Owner', 'owner_mobile' => '9800000009',
        'username' => 'owner', 'pass_hash' => password_hash('x', PASSWORD_DEFAULT), 'business_type' => 'mobile', 'with_samples' => 1]);
    $out['status'] = $t['status'];
    $pdo = tenant_pdo_for($t);
    $out['tables'] = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()")->fetchColumn();
    $out['admin'] = (string)$pdo->query("SELECT username FROM users LIMIT 1")->fetchColumn();
    $out['admin_perms'] = (string)$pdo->query("SELECT r.permissions FROM users u JOIN roles r ON r.id = u.role_id LIMIT 1")->fetchColumn();
    $out['items'] = (int)$pdo->query("SELECT COUNT(*) FROM items")->fetchColumn();
    $out['serial_label'] = (string)$pdo->query("SELECT value FROM settings WHERE name = 'biz_serial_label'")->fetchColumn();
    // the shop seen through its own address, in a fresh process
    $php = escapeshellarg(PHP_BINARY);
    $code = escapeshellarg('require "' . dirname(__DIR__) . '/includes/init.php"; echo setting("app_name", "(default name)");');
    $out['login_title'] = trim((string)shell_exec('TENANT_HOST=' . escapeshellarg($slug . '.e2e.test') . " $php -r $code 2>&1"));
    $out['main_title'] = trim((string)shell_exec("$php -r $code 2>&1"));
    $unk = tenant_find_by_host('nosuchshop.e2e.test');
    $out['unknown_code'] = ($unk['status'] ?? '') === 'closed' ? 404 : 200;
    // clean up
    db()->exec('DROP DATABASE IF EXISTS `' . $t['db_name'] . '`');
    q('DELETE FROM tenants WHERE id = ?', [$t['id']]);
    $out['cleaned'] = !val('SELECT id FROM tenants WHERE slug = ?', [$slug])
        && !val('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [$t['db_name']]);
} catch (Throwable $e) {
    $out['error'] = $e->getMessage();
}
set_setting('platform_domain', (string)$oldDom);
set_setting('platform_db_mode', (string)($oldMode ?? 'mysql'));
echo json_encode($out);
