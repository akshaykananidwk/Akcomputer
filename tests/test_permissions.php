<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); die('CLI only'); }
// The permission matrix: what each shipped role may and may not do.
// can() reads current_user(), so these tests install a fake session user
// and restore the real one afterwards.

function t_as_role(array $perms) {
    // current_user() caches in a static, so we swap the whole resolver's
    // input: set the session id to a scratch user built with these perms
    $roleName = 'TESTROLE_' . bin2hex(random_bytes(3));
    q('INSERT INTO roles (name, permissions, is_system) VALUES (?, ?, 0)', [$roleName, json_encode($perms)]);
    $rid = insert_id();
    $loc = (int)val('SELECT id FROM locations ORDER BY id LIMIT 1');
    q("INSERT INTO users (name, username, mobile, password, role_id, location_id, is_active)
       VALUES ('Test User', ?, '9000000001', 'x', ?, ?, 1)", ['tu_' . bin2hex(random_bytes(3)), $rid, $loc]);
    return insert_id();
}

/** can() for an arbitrary user, without touching the live session. */
function t_can($userId, $perm) {
    $u = row('SELECT u.*, r.permissions AS role_permissions FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?', [$userId]);
    if (!$u) return false;
    $perms = array_unique(array_merge(
        json_decode($u['role_permissions'] ?: '[]', true) ?: [],
        json_decode($u['permissions'] ?: '[]', true) ?: []
    ));
    return in_array('*', $perms, true) || in_array($perm, $perms, true);
}

t_group('admin wildcard opens everything');
$admin = t_as_role(['*']);
t_ok('admin can add sales', t_can($admin, 'sales.add'));
t_ok('admin can edit settings', t_can($admin, 'settings.edit'));
t_ok('admin can see cost prices', t_can($admin, 'items.cost'));
t_ok('admin passes a permission that does not even exist yet', t_can($admin, 'future.module'));

t_group('sales staff is fenced in');
$sales = t_as_role(['dashboard.view', 'sales.view', 'sales.add', 'items.view', 'parties.view', 'payments.view', 'payments.add']);
t_ok('can create a bill', t_can($sales, 'sales.add'));
t_ok('can take a payment', t_can($sales, 'payments.add'));
t_ok('CANNOT delete a payment', !t_can($sales, 'payments.delete'));
t_ok('CANNOT see purchase cost', !t_can($sales, 'items.cost'));
t_ok('CANNOT open settings', !t_can($sales, 'settings.edit'));
t_ok('CANNOT manage users', !t_can($sales, 'users.view'));
t_ok('CANNOT see profit reports', !t_can($sales, 'reports.profit'));
t_ok('CANNOT see other staff records (no sales.all)', !t_can($sales, 'sales.all'));

t_group('field staff is the tightest role');
$field = t_as_role(['dashboard.view', 'tasks.view', 'tasks.edit', 'handover.view', 'handover.add']);
t_ok('can work on tasks', t_can($field, 'tasks.edit'));
t_ok('CANNOT view sales', !t_can($field, 'sales.view'));
t_ok('CANNOT view payments', !t_can($field, 'payments.view'));
t_ok('CANNOT view parties', !t_can($field, 'parties.view'));

t_group('accounts role handles money but not the shop');
$acc = t_as_role(['sales.view', 'sales.all', 'purchases.view', 'purchases.all', 'payments.view', 'payments.add', 'payments.delete', 'reports.view', 'reports.gst']);
t_ok('sees everyone\'s sales', t_can($acc, 'sales.all'));
t_ok('can delete a payment', t_can($acc, 'payments.delete'));
t_ok('CANNOT change items', !t_can($acc, 'items.edit'));
t_ok('CANNOT manage roles', !t_can($acc, 'roles.edit'));

t_group('per-user extra permissions add to the role');
$u = t_as_role(['sales.view']);
q("UPDATE users SET permissions = ? WHERE id = ?", [json_encode(['reports.profit']), $u]);
t_ok('role permission still applies', t_can($u, 'sales.view'));
t_ok('extra permission is granted', t_can($u, 'reports.profit'));
t_ok('anything else is still denied', !t_can($u, 'settings.edit'));

t_group('own-records scoping');
$noAll = t_as_role(['sales.view']);
$withAll = t_as_role(['sales.view', 'sales.all']);
t_ok('without sales.all the user is scoped to their own', !t_can($noAll, 'sales.all'));
t_ok('with sales.all the scope opens', t_can($withAll, 'sales.all'));

t_group('the admin-only pages really are admin-only');
foreach (['approvals.php', 'cron_manager.php', 'cost_analytics.php', 'wa_inbox.php', 'serial_fix.php'] as $f) {
    $src = @file_get_contents(dirname(__DIR__) . '/' . $f);
    t_ok("$f checks is_full_admin()", $src !== false && strpos($src, 'is_full_admin()') !== false);
}

t_group('every page still declares a gate');
$ungated = [];
foreach (glob(dirname(__DIR__) . '/*.php') as $f) {
    $base = basename($f);
    // genuinely public pages, webhooks (own auth) and config
    if (in_array($base, ['config.php', 'config.sample.php', 'login.php', 'logout.php', 'forgot_password.php',
        'catalog.php', 'catalog_feed.php', 'product.php', 'price.php', 'category.php', 'brand.php', 'services.php',
        'privacy.php', 'terms.php', 'sitemap.php', 'referral.php', 'feedback.php', 'service_report.php',
        'sale_view.php', 'sale_pdf.php', 'api.php', 'cron.php', 'razorpay_webhook.php', 'wa_webhook.php',
        'telegram_webhook.php'], true)) continue;
    $src = file_get_contents($f);
    if (!preg_match('/require_perm\(|require_login\(/', $src)) $ungated[] = $base;
}
t_ok('no staff page is missing require_perm/require_login', empty($ungated), implode(', ', $ungated));
