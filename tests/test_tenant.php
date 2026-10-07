<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); die('CLI only'); }
// Many shops on one code base.
//
// What must never happen: one shop's request reading another shop's
// database, files or session. What must keep happening: the owner's own
// shop working exactly as before, with nothing looked up and nothing moved.
require_once dirname(__DIR__) . '/includes/platform.php';

t_group('Shops: the owner\'s own shop is untouched');
t_ok('no other shop is active on the owner\'s address', !tenant_active() && tenant_key() === '');
t_eq('its database is config.php\'s', db_name(), DB_NAME);
t_eq('its files stay in uploads/', up_rel('invoices'), 'uploads/invoices');
t_ok('...on disk too', substr(up_dir('cache'), -strlen('/uploads/cache')) === '/uploads/cache');
t_ok('platform tables exist', (bool)val("SHOW TABLES LIKE 'tenants'") && (bool)val("SHOW TABLES LIKE 'plans'"));
t_ok('three plans ship by default', (int)val("SELECT COUNT(*) FROM plans WHERE code IN ('basic','pro','premium')") === 3);

t_group('Shops: another shop\'s request uses only its own things');
$GLOBALS['_tenant'] = ['slug' => 'zzunit', 'name' => 'Unit Shop', 'domain' => 'zzunit.example.in', 'db_host' => 'localhost',
                       'db_name' => 'shop_zzunit', 'db_user' => 'u_zz', '_db_pass' => 'p_zz'];
t_eq('its database', db_conf(), ['localhost', 'shop_zzunit', 'u_zz', 'p_zz']);
t_eq('its files', up_rel('invoices'), 'uploads/t/zzunit/invoices');
t_ok('...on disk', strpos(up_dir('cache'), '/uploads/t/zzunit/cache') !== false);
$_SERVER['HTTP_HOST'] = 'zzunit.example.in';
t_ok('its links point back at its own address', strpos(base_url('pay.php'), '://zzunit.example.in/pay.php') !== false);
@rmdir(up_dir('cache')); @rmdir(up_dir('invoices')); @rmdir(dirname(up_dir()) . '/zzunit');
$GLOBALS['_tenant'] = null;
unset($_SERVER['HTTP_HOST']);

t_group('Shops: a login belongs to one shop');
$saved = $_SESSION ?? [];
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
$_SESSION = ['_shop' => 'shopa', 'user_id' => 1];
tenant_bind_session('shopb');
t_ok('a session from shop A arriving at shop B is emptied', !isset($_SESSION['user_id']) && $_SESSION['_shop'] === 'shopb');
$_SESSION = ['_shop' => 'shopa', 'user_id' => 1];
tenant_bind_session('');
t_ok('...and at the owner\'s own shop too', !isset($_SESSION['user_id']));
$_SESSION = ['_shop' => 'shopa', 'user_id' => 7];
tenant_bind_session('shopa');
t_ok('the same shop keeps its login', ($_SESSION['user_id'] ?? 0) === 7);
$_SESSION = $saved;

t_group('Shops: picking a web address');
t_eq('a name becomes an address', tenant_slug_from('Shree Ram Mobile!'), 'shreerammobile');
t_ok('too short is refused', tenant_slug_problem('ab') !== '');
t_ok('spaces and capitals are refused', tenant_slug_problem('My Shop') !== '');
t_ok('reserved names are refused', tenant_slug_problem('admin') !== '' && tenant_slug_problem('www') !== '');
t_eq('a good one passes', tenant_slug_problem('zzfreshshop' . random_int(100, 999)), '');

t_group('Shops: business packs');
t_ok('eleven kinds of business plus general', count(business_packs()) === 12);
foreach (business_packs() as $k => $p)
    t_ok("$k: categories and items are consistent", !array_filter($p[4], fn($i) => !in_array($i[1], $p[3], true)));
$r = business_pack_apply(db(), 'kirana', true);
t_ok('applying kirana adds its categories and items', $r['categories'] === 5 && $r['items'] >= 1);
t_eq('it sells by weight', setting_raw('biz_weight'), '1');
t_eq('loose rice is in kg', val("SELECT unit FROM items WHERE name = 'Rice (loose)'"), 'kg');
$again = business_pack_apply(db(), 'kirana', true);
t_eq('running it twice adds nothing twice', $again['items'], 0);

t_group('Shops: a real new shop, start to finish (its own process)');
// Making a database commits any open transaction in MySQL, so this runs in
// a separate PHP process against its own connection, and cleans up after.
$slug = 'zze2e' . random_int(1000, 9999);
$out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/tenant_e2e.php') . ' ' . escapeshellarg($slug) . ' 2>&1');
$res = json_decode((string)$out, true) ?: [];
t_ok('the shop is made', ($res['status'] ?? '') === 'trial', mb_substr((string)$out, 0, 300));
t_ok('in its own database, with every table', ($res['tables'] ?? 0) > 100);
t_ok('with the owner\'s login', ($res['admin'] ?? '') === 'owner');
t_ok('the admin role can do everything', ($res['admin_perms'] ?? '') === '["*"]');
t_ok('its mobile-shop items and IMEI label', ($res['items'] ?? 0) === 4 && ($res['serial_label'] ?? '') === 'IMEI');
t_ok('its address answers with its own name', ($res['login_title'] ?? '') === 'Shree Test Mobile');
t_ok('the owner\'s address still answers as the owner\'s shop', ($res['main_title'] ?? '') !== 'Shree Test Mobile' && ($res['main_title'] ?? '') !== '');
t_ok('an address no shop has says so', ($res['unknown_code'] ?? 0) === 404);
t_ok('and everything was cleaned up', ($res['cleaned'] ?? false) === true);

/** A setting read straight from the table (the request cache predates this group). */
function setting_raw($n) { return val('SELECT value FROM settings WHERE name = ?', [$n]); }
