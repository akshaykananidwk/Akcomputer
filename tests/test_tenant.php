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
if (!empty($res['own_user']))
    t_ok('its own MySQL login cannot read the owner\'s database', ($res['owner_db_blocked'] ?? false) === true);
else
    t_ok('(this server would not make a MySQL login per shop; the shop shares the app\'s - noted on the shop)', true);

/** A setting read straight from the table (the request cache predates this group). */
function setting_raw($n) { return val('SELECT value FROM settings WHERE name = ?', [$n]); }

t_group('Shops: sign-up and the owner\'s panel');
$su = file_get_contents(dirname(__DIR__) . '/signup.php');
t_ok('sign-up lives only on the owner\'s address', strpos($su, "if (tenant_active()) { http_response_code(404)") !== false);
t_ok('it is closed until the owner opens it', strpos($su, "setting('platform_signup_open', '0') === '1'") !== false);
t_ok('it is rate-limited per connection', strpos($su, "api_rate_ok('signup:' . \$ip, 6, 3600)") !== false);
t_ok('the OTP is kept hashed, expires and allows 5 tries', strpos($su, "password_hash(\$code, PASSWORD_DEFAULT), 'until' => time() + 600") !== false
     && strpos($su, "++\$_SESSION['signup_otp']['tries'] > 5") !== false);
t_ok('the password follows the shop\'s password rules', strpos($su, 'password_policy_check($pass)') !== false);
$pl = file_get_contents(dirname(__DIR__) . '/platform.php');
t_ok('the owner\'s panel refuses a shop\'s address', strpos($pl, "platform_owner_only();") !== false);
t_ok('...and anyone but a full admin', strpos($pl, 'if (!is_full_admin())') !== false);
t_ok('the cPanel token is kept encrypted', is_secret_setting('cpanel_token'));
$st = file_get_contents(dirname(__DIR__) . '/settings.php');
t_eq('a shop cannot update or roll back the shared code', substr_count($st, 'platform_owner_only();'), 4);
t_ok('every plan feature names real screens', !array_filter(platform_features(), fn($f) => array_filter($f[1], fn($s) => !is_file(dirname(__DIR__) . "/$s.php"))));

t_group('Plans: what a shop may open, and how much it may use');
require_once dirname(__DIR__) . '/includes/plan.php';
t_ok('the owner\'s own shop is never held to a plan', plan_allows('accounting') && plan_limit_problem('users') === '');
t_eq('a screen belongs to its feature', script_feature('/x/purchases.php'), 'purchase');
t_eq('the dashboard belongs to every plan', script_feature('index.php'), '');
q("INSERT INTO plans (code, name, price_month, max_users, max_bills_month, features) VALUES ('zzt', 'Test plan', 100, ?, 0, '[\"billing\",\"parties\"]')",
  [(int)val('SELECT COUNT(*) FROM users WHERE is_active = 1')]);
$zp = (int)insert_id();
$fp = row('SELECT * FROM plans WHERE id = ?', [$zp]);
$GLOBALS['_tenant'] = ['id' => 0, 'slug' => 'zzplan', 'name' => 'Plan Shop', 'plan_id' => $zp, 'status' => 'trial', 'domain' => 'zzplan.example.in',
                       'trial_ends' => date('Y-m-d', strtotime('+5 days')), 'paid_until' => null, 'is_demo' => 0, '_plan' => $fp];
t_ok('the plan opens billing', plan_allows('billing'));
t_ok('...and not purchase', !plan_allows('purchase'));
t_ok('one more staff login than the plan allows is refused', plan_limit_problem('users') !== '');
t_ok('...with a reason a person can read', strpos(plan_limit_problem('users'), 'staff logins') !== false);
t_eq('no bill limit means no limit', plan_limit_problem('bills'), '');
$GLOBALS['_tenant'] = null;

t_group('Plans: when the date passes');
$base = ['is_demo' => 0, 'paid_until' => null];
q("INSERT INTO settings (name, value) VALUES ('platform_grace_days', '3') ON DUPLICATE KEY UPDATE value = '3'");
t_eq('trial with days left', tenant_expiry($base + ['trial_ends' => date('Y-m-d', strtotime('+4 days'))])['state'], 'ok');
t_eq('the last day still counts', tenant_expiry($base + ['trial_ends' => date('Y-m-d')])['state'], 'ok');
t_eq('two days after: grace, still working', tenant_expiry($base + ['trial_ends' => date('Y-m-d', strtotime('-2 days'))])['state'], 'grace');
t_eq('four days after: locked', tenant_expiry($base + ['trial_ends' => date('Y-m-d', strtotime('-4 days'))])['state'], 'locked');
t_eq('a paid date later than the trial wins', tenant_expiry(['is_demo' => 0, 'trial_ends' => date('Y-m-d', strtotime('-40 days')),
     'paid_until' => date('Y-m-d', strtotime('+20 days'))])['state'], 'ok');
t_eq('a demo shop never runs out', tenant_expiry(['is_demo' => 1, 'trial_ends' => '2000-01-01', 'paid_until' => null])['state'], 'ok');

t_group('Plans: a payment comes in');
q("INSERT INTO tenants (slug, name, domain, status, plan_id, trial_ends, db_name) VALUES ('zzpay', 'Pay Shop', 'zzpay.example.in', 'suspended', NULL, ?, 'x')",
  [date('Y-m-d', strtotime('-10 days'))]);
$ptid = (int)insert_id();
q("INSERT INTO tenant_payments (tenant_id, plan_id, months, amount, ref, status) VALUES (?, ?, 1, 699, 'PLT-T-1', 'pending')", [$ptid, $zp]);
t_eq('a short amount is not accepted', platform_payment_paid('PLT-T-1', 'pay_1', 50000), 'amount short');
t_eq('the full amount is', platform_payment_paid('PLT-T-1', 'pay_1', 69900), 'ok');
$pt = row('SELECT * FROM tenants WHERE id = ?', [$ptid]);
t_eq('the shop is open again', $pt['status'], 'active');
t_eq('a month from today (the trial had already ended)', $pt['paid_until'], date('Y-m-d', strtotime('+1 month')));
t_eq('on the plan it paid for', (int)$pt['plan_id'], $zp);
t_eq('a second copy of the same payment changes nothing', platform_payment_paid('PLT-T-1', 'pay_1', 69900), 'already processed');
t_eq('...the date did not move twice', val('SELECT paid_until FROM tenants WHERE id = ?', [$ptid]), date('Y-m-d', strtotime('+1 month')));
q("INSERT INTO tenant_payments (tenant_id, plan_id, months, amount, ref, status) VALUES (?, ?, 12, 6990, 'PLT-T-2', 'pending')", [$ptid, $zp]);
platform_payment_paid('PLT-T-2', 'pay_2', 699000);
t_eq('a year paid while a month is still running starts from the end of that month',
     val('SELECT paid_until FROM tenants WHERE id = ?', [$ptid]), date('Y-m-d', strtotime(date('Y-m-d', strtotime('+1 month')) . ' +12 months')));
t_eq('an unknown reference is ignored', platform_payment_paid('PLT-NOPE', 'x', 100), 'no such payment');
$wh = file_get_contents(dirname(__DIR__) . '/razorpay_webhook.php');
t_ok('the webhook sends plan payments here, on the owner\'s address only', strpos($wh, "strncmp(\$refId, 'PLT-', 4) === 0 && !tenant_active()") !== false);
t_ok('...after the signature check', strpos($wh, 'hash_hmac') < strpos($wh, 'PLT-'));

t_group('Plans: a part the plan does not open disappears everywhere');
t_eq('purchase permissions belong to the purchase feature', perm_feature('purchases.view'), 'purchase');
t_eq('settings belong to every plan', perm_feature('settings.edit'), '');
$auth = file_get_contents(dirname(__DIR__) . '/includes/auth.php');
t_ok('can() asks the plan first, so menus, quick actions and buttons all follow it',
     strpos($auth, "if (function_exists('perm_plan_ok') && !perm_plan_ok(\$perm)) return false;") !== false);
t_ok('every permission module the code checks maps to a feature or is meant for every plan', (function () {
    $mods = [];
    foreach (glob(dirname(__DIR__) . '/*.php') as $f) if (preg_match_all("/can\\('([a-z_]+)\\./", file_get_contents($f), $m)) $mods = array_merge($mods, $m[1]);
    $everyPlan = ['settings', 'users', 'roles', 'companies', 'dashboard', 'reports'];
    return !array_filter(array_unique($mods), fn($x) => perm_feature($x . '.view') === '' && !in_array($x, $everyPlan, true));
})());
foreach (['users.php' => "plan_limit_problem('users')", 'sales.php' => "plan_limit_problem('bills')", 'api.php' => "plan_limit_problem('bills')",
          'locations.php' => "plan_limit_problem('locations')", 'includes/whatsapp.php' => "plan_limit_problem('wa')"] as $f => $needle)
    t_ok("$f holds its plan limit", strpos(file_get_contents(dirname(__DIR__) . '/' . $f), $needle) !== false);
$mp = file_get_contents(dirname(__DIR__) . '/my_plan.php');
t_ok('"My plan" is the shop admin\'s only', strpos($mp, 'if (!is_full_admin())') !== false && strpos($mp, "if (!tenant_active()) redirect('platform.php');") !== false);
t_ok('closing needs the shop\'s address typed exactly', strpos($mp, "strtolower(trim(post('confirm'))) !== strtolower(\$t['slug'])") !== false);
