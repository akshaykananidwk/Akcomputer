<?php
// The software's owner, looking at every shop that runs on it: who signed
// up, which plan, paid till when, how much they use it - and the switches
// that decide how new shops are made. Owner only, on the owner's own
// address; a shop never sees this page.
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/platform.php';
platform_owner_only();
if (!is_full_admin()) { http_response_code(403); require_perm('*'); }

$tab = get('tab', 'shops');
$back = fn($t, $q = '') => 'platform.php?tab=' . $t . $q;

// ---------- actions ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = post('do');
    if ($do === 'save_settings') {
        foreach (['platform_domain', 'platform_signup_open', 'platform_signup_otp', 'platform_trial_days', 'platform_trial_plan',
                  'platform_db_mode', 'platform_db_prefix', 'cpanel_host', 'cpanel_user', 'platform_powered_by'] as $k)
            set_setting($k, trim((string)post($k)));
        if (post('cpanel_token') !== '') set_setting('cpanel_token', post('cpanel_token'));
        log_activity('platform_settings', 'saved');
        flash('Platform settings saved.');
        redirect($back('settings'));
    }
    $tid = (int)post('tid');
    $t = $tid ? prow('SELECT * FROM tenants WHERE id = ?', [$tid]) : null;
    if ($t && $do === 'extend') {
        $days = max(1, min(3650, (int)post('days')));
        $from = max(today(), (string)($t['paid_until'] ?: $t['trial_ends'] ?: today()));
        pq("UPDATE tenants SET paid_until = ?, status = IF(status IN ('suspended','trial'), 'active', status) WHERE id = ?",
           [date('Y-m-d', strtotime("$from +$days days")), $tid]);
        log_activity('platform_extend', $t['slug'] . " +$days days");
        flash($t['name'] . ": extended by $days days.");
    }
    if ($t && $do === 'plan') {
        pq('UPDATE tenants SET plan_id = ? WHERE id = ?', [(int)post('plan_id') ?: null, $tid]);
        log_activity('platform_plan', $t['slug'] . ' plan ' . (int)post('plan_id'));
        flash($t['name'] . ': plan changed.');
    }
    if ($t && in_array($do, ['suspend', 'activate'], true)) {
        pq('UPDATE tenants SET status = ? WHERE id = ?', [$do === 'suspend' ? 'suspended' : 'active', $tid]);
        log_activity('platform_' . $do, $t['slug']);
        flash($t['name'] . ($do === 'suspend' ? ' is suspended.' : ' is open again.'));
    }
    if ($t && $do === 'domain') {
        $cd = strtolower(trim(preg_replace('#^https?://#i', '', post('custom_domain')), " /"));
        if ($cd !== '' && !preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $cd)) { flash('That is not a web address.', 'error'); redirect($back('shops')); }
        if ($cd !== '' && pval('SELECT id FROM tenants WHERE (custom_domain = ? OR domain = ?) AND id <> ?', [$cd, $cd, $tid])) { flash('Another shop already uses that address.', 'error'); redirect($back('shops')); }
        pq('UPDATE tenants SET custom_domain = ? WHERE id = ?', [$cd !== '' ? $cd : null, $tid]);
        log_activity('platform_domain', $t['slug'] . ' -> ' . ($cd ?: '(none)'));
        flash($cd !== '' ? "$cd now opens {$t['name']}. Point its DNS at this server and add it to the hosting." : 'Custom address removed.');
    }
    if ($t && $do === 'install_manual' && $t['status'] === 'provisioning') {
        try {
            $pending = json_decode((string)$t['notes'], true)['pending'] ?? null;
            if (!$pending) throw new Exception('The sign-up details for this shop are missing.');
            tenant_install($tid, post('db_host') ?: 'localhost', post('db_name'), post('db_user'), (string)$_POST['db_pass'], $pending);
            flash($t['name'] . ' is set up and open.');
        } catch (Exception $e) { flash('Could not set it up: ' . plain_error($e), 'error'); }
    }
    if ($t && $do === 'reply_ticket') {
        pq("UPDATE platform_tickets SET reply = ?, status = ? WHERE id = ? AND tenant_id = ?",
           [trim(post('reply')), post('close') ? 'closed' : 'answered', (int)post('ticket_id'), $tid]);
        flash('Reply saved — the shop sees it under My plan → Help.');
        redirect($back('tickets'));
    }
    if ($do === 'save_plan') {
        $feat = array_values(array_intersect((array)post('features', []), array_keys(platform_features())));
        if (post('all_features')) $feat = ['*'];
        $vals = [trim(post('name')), max(0, (float)post('price_month')), max(0, (float)post('price_year')),
                 max(0, (int)post('max_users')), max(0, (int)post('max_bills_month')), max(0, (int)post('max_wa_month')),
                 max(0, (int)post('max_locations')), json_encode($feat), post('white_label') ? 1 : 0, post('is_active') ? 1 : 0];
        if ((int)post('plan_id')) pq('UPDATE plans SET name=?, price_month=?, price_year=?, max_users=?, max_bills_month=?, max_wa_month=?, max_locations=?, features=?, white_label=?, is_active=? WHERE id=?',
                                     array_merge($vals, [(int)post('plan_id')]));
        else {
            $code = strtolower(preg_replace('/[^a-z0-9]/i', '', post('code')));
            if ($code === '' || pval('SELECT id FROM plans WHERE code = ?', [$code])) { flash('Give the plan a new short code (letters only).', 'error'); redirect($back('plans')); }
            pq('INSERT INTO plans (name, price_month, price_year, max_users, max_bills_month, max_wa_month, max_locations, features, white_label, is_active, code, sort_order)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,99)', array_merge($vals, [$code]));
        }
        log_activity('platform_plan_save', post('name'));
        flash('Plan saved.');
        redirect($back('plans'));
    }
    if ($do === 'save_reseller') {
        $code = strtoupper(preg_replace('/[^A-Z0-9]/i', '', post('code')));
        if ($code === '') $code = strtoupper(substr(preg_replace('/[^A-Z]/i', '', post('name')), 0, 4) . random_int(100, 999));
        if ((int)post('rid')) pq('UPDATE resellers SET name=?, mobile=?, email=?, commission_pct=?, is_active=? WHERE id=?',
            [post('name'), post('mobile'), post('email'), max(0, min(90, (float)post('commission_pct'))), post('is_active') ? 1 : 0, (int)post('rid')]);
        elseif (pval('SELECT id FROM resellers WHERE code = ?', [$code])) { flash('That code is taken.', 'error'); redirect($back('resellers')); }
        else pq('INSERT INTO resellers (name, mobile, email, code, commission_pct, password) VALUES (?,?,?,?,?,?)',
                [post('name'), post('mobile'), post('email'), $code, max(0, min(90, (float)post('commission_pct'))),
                 post('password') !== '' ? password_hash(post('password'), PASSWORD_DEFAULT) : '']);
        flash('Reseller saved.');
        redirect($back('resellers'));
    }
    if ($do === 'demo') {
        try { $d = demo_make_or_reset(); flash('The demo shop is ready at ' . $d['domain'] . ' (demo / demo1234). It starts fresh every night.'); }
        catch (Exception $e) { flash('Could not make the demo shop: ' . plain_error($e), 'error'); }
        redirect($back('settings'));
    }
    if ($do === 'reseller_password' && strlen((string)post('password')) >= 8) {
        pq('UPDATE resellers SET password = ? WHERE id = ?', [password_hash(post('password'), PASSWORD_DEFAULT), (int)post('rid')]);
        flash('Password set. They log in at ' . base_url('reseller.php') . ' with their code.');
        redirect($back('resellers'));
    }
    if ($do === 'pay_commission') {
        pq("UPDATE tenant_payments SET commission = -ABS(commission) WHERE reseller_id = ? AND status = 'paid' AND commission > 0", [(int)post('rid')]);
        log_activity('platform_commission_paid', 'reseller ' . (int)post('rid'));
        flash('Marked as paid.');
        redirect($back('resellers'));
    }
    redirect($back($tab));
}

// ---------- data ----------
$plans = pall('SELECT * FROM plans ORDER BY sort_order, id');
$planName = array_column($plans, 'name', 'id');
$q = trim(get('q')); $st = get('status');
$w = '1=1'; $p = [];
if ($q !== '') { $w .= ' AND (t.name LIKE ? OR t.slug LIKE ? OR t.owner_mobile LIKE ?)'; $p = ["%$q%", "%$q%", "%$q%"]; }
if ($st !== '') { $w .= ' AND t.status = ?'; $p[] = $st; }
$shops = pall("SELECT t.*, r.name reseller FROM tenants t LEFT JOIN resellers r ON r.id = t.reseller_id WHERE $w ORDER BY t.id DESC LIMIT 300", $p);
$counts = array_column(pall('SELECT status, COUNT(*) n FROM tenants GROUP BY status'), 'n', 'status');
$mrr = (float)pval("SELECT COALESCE(SUM(p.price_month),0) FROM tenants t JOIN plans p ON p.id = t.plan_id WHERE t.status = 'active' AND t.is_demo = 0");
$month = (float)pval("SELECT COALESCE(SUM(amount),0) FROM tenant_payments WHERE status = 'paid' AND paid_at >= DATE_FORMAT(NOW(), '%Y-%m-01')");
$openTickets = (int)pval("SELECT COUNT(*) FROM platform_tickets WHERE status = 'open'");

$page_title = 'Shops on this software';
include __DIR__ . '/includes/header.php';
?>
<div class="page-head"><h1>🌐 Shops on this software</h1></div>
<div class="kpi-row">
  <div class="kpi k-ok"><div class="kpi-top">✅ Paying shops</div><div class="kpi-val"><?= (int)($counts['active'] ?? 0) ?></div><div class="kpi-sub">≈ ₹<?= money($mrr) ?> a month</div></div>
  <div class="kpi k-info"><div class="kpi-top">🆓 On trial</div><div class="kpi-val"><?= (int)($counts['trial'] ?? 0) ?></div><div class="kpi-sub">free days running</div></div>
  <div class="kpi k-warn"><div class="kpi-top">⏸ Suspended</div><div class="kpi-val"><?= (int)($counts['suspended'] ?? 0) ?></div><div class="kpi-sub">plan ran out</div></div>
  <div class="kpi"><div class="kpi-top">💰 Collected this month</div><div class="kpi-val">₹<?= money($month) ?></div><div class="kpi-sub"><?= $openTickets ?> open question<?= $openTickets === 1 ? '' : 's' ?></div></div>
</div>
<div class="tabs" style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px">
<?php foreach (['shops' => '🏪 Shops', 'plans' => '💳 Plans', 'resellers' => '🤝 Resellers', 'payments' => '💰 Payments', 'tickets' => '❓ Questions' . ($openTickets ? " ($openTickets)" : ''), 'settings' => '⚙️ Settings'] as $k => $l): ?>
  <a class="btn btn-sm <?= $tab === $k ? '' : 'btn-outline' ?>" href="platform.php?tab=<?= $k ?>"><?= $l ?></a>
<?php endforeach; ?>
</div>

<?php if ($tab === 'shops'): ?>
<form class="filterbar" method="get"><input type="hidden" name="tab" value="shops">
  <div><label>Find</label><input type="text" name="q" value="<?= e($q) ?>" placeholder="name, address, mobile"></div>
  <div><label>Status</label><select name="status"><option value="">All</option>
    <?php foreach (['trial', 'active', 'suspended', 'provisioning', 'closed'] as $s): ?><option <?= $st === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?></select></div>
  <button class="btn btn-sm" type="submit">Show</button>
</form>
<?php if (!$shops): ?><div class="card"><p class="muted">No shops yet. <?= setting('platform_signup_open', '0') === '1' ? 'Share ' . e(base_url('signup.php')) . ' with shop owners.' : 'Open sign-ups under ⚙️ Settings.' ?></p></div><?php endif; ?>
<?php foreach ($shops as $s):
    $until = $s['paid_until'] ?: $s['trial_ends'];
    $left = $until ? (int)floor((strtotime($until) - strtotime(today())) / 86400) : null; ?>
<div class="pane">
  <div class="pane-head"><h3><?= e($s['name']) ?> <span class="badge badge-<?= ['active' => 'ok', 'trial' => 'info', 'suspended' => 'warn', 'closed' => 'bad'][$s['status']] ?? 'info' ?>"><?= e($s['status']) ?></span><?= $s['is_demo'] ? ' <span class="badge badge-info">demo</span>' : '' ?></h3>
    <a class="btn btn-sm btn-outline" target="_blank" href="<?= e((parse_url(base_url(), PHP_URL_SCHEME) ?: 'https') . '://' . ($s['custom_domain'] ?: $s['domain']) . '/login.php') ?>">Open ↗</a></div>
  <div class="pane-body">
    <p class="muted" style="margin:0 0 8px"><?= e($s['domain']) ?><?= $s['custom_domain'] ? ' · ' . e($s['custom_domain']) : '' ?> · <?= e(business_label($s['business_type'])) ?>
      · <?= e($s['owner_name']) ?> <?= e($s['owner_mobile']) ?> · plan <strong><?= e($planName[$s['plan_id']] ?? '—') ?></strong>
      <?php if ($until): ?> · <?= $s['paid_until'] ? 'paid' : 'trial' ?> till <strong><?= dmy($until) ?></strong> (<?= $left >= 0 ? $left . ' days left' : abs($left) . ' days ago' ?>)<?php endif; ?>
      <?= $s['reseller'] ? ' · via ' . e($s['reseller']) : '' ?> · since <?= dmy($s['created_at']) ?></p>
    <?php if ($s['notes'] && $s['status'] !== 'provisioning'): ?><p class="muted" style="font-size:12px;margin:0 0 8px">📝 <?= e(mb_substr($s['notes'], 0, 200)) ?></p><?php endif; ?>
    <?php if ($s['status'] === 'provisioning'): ?>
      <form method="post" class="filterbar"><?= csrf_field() ?><input type="hidden" name="do" value="install_manual"><input type="hidden" name="tid" value="<?= $s['id'] ?>">
        <div><label>DB host</label><input name="db_host" value="localhost"></div><div><label>DB name</label><input name="db_name" required></div>
        <div><label>DB user</label><input name="db_user" required></div><div><label>DB password</label><input name="db_pass" type="password" required></div>
        <button class="btn btn-sm" type="submit">Set it up</button></form>
      <p class="muted" style="font-size:12px">Make an empty database and a user for it in cPanel, give the user all rights on it, then fill these in.</p>
    <?php else: ?>
    <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:flex-end">
      <form method="post" style="display:flex;gap:4px;align-items:flex-end"><?= csrf_field() ?><input type="hidden" name="do" value="extend"><input type="hidden" name="tid" value="<?= $s['id'] ?>">
        <select name="days"><option value="30">+1 month</option><option value="90">+3 months</option><option value="365">+1 year</option><option value="7">+7 days</option></select>
        <button class="btn btn-sm btn-outline" type="submit">Extend</button></form>
      <form method="post" style="display:flex;gap:4px;align-items:flex-end"><?= csrf_field() ?><input type="hidden" name="do" value="plan"><input type="hidden" name="tid" value="<?= $s['id'] ?>">
        <select name="plan_id"><?php foreach ($plans as $pl): ?><option value="<?= $pl['id'] ?>" <?= $pl['id'] == $s['plan_id'] ? 'selected' : '' ?>><?= e($pl['name']) ?></option><?php endforeach; ?></select>
        <button class="btn btn-sm btn-outline" type="submit">Change plan</button></form>
      <form method="post" style="display:flex;gap:4px;align-items:flex-end"><?= csrf_field() ?><input type="hidden" name="do" value="domain"><input type="hidden" name="tid" value="<?= $s['id'] ?>">
        <input name="custom_domain" placeholder="own domain, e.g. billing.shop.in" value="<?= e((string)$s['custom_domain']) ?>" style="max-width:200px">
        <button class="btn btn-sm btn-outline" type="submit">Save address</button></form>
      <form method="post" onsubmit="return confirm('<?= $s['status'] === 'suspended' ? 'Open this shop again?' : 'Suspend this shop? Its staff will see a renew message.' ?>')"><?= csrf_field() ?>
        <input type="hidden" name="do" value="<?= $s['status'] === 'suspended' ? 'activate' : 'suspend' ?>"><input type="hidden" name="tid" value="<?= $s['id'] ?>">
        <button class="btn btn-sm <?= $s['status'] === 'suspended' ? '' : 'btn-danger' ?>" type="submit"><?= $s['status'] === 'suspended' ? 'Open again' : 'Suspend' ?></button></form>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php endforeach; ?>

<?php elseif ($tab === 'plans'): ?>
<p class="muted">Limits: 0 means no limit. A shop on a plan sees only the parts its plan opens; the rest shows an "upgrade" note.</p>
<?php foreach (array_merge($plans, [['id' => 0, 'code' => '', 'name' => '', 'price_month' => 0, 'price_year' => 0, 'max_users' => 0, 'max_bills_month' => 0, 'max_wa_month' => 0, 'max_locations' => 0, 'features' => '[]', 'white_label' => 0, 'is_active' => 1]]) as $pl):
    $f = json_decode((string)$pl['features'], true) ?: []; ?>
<div class="pane"><div class="pane-head"><h3><?= $pl['id'] ? e($pl['name']) : '＋ New plan' ?></h3></div><div class="pane-body">
<form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="save_plan"><input type="hidden" name="plan_id" value="<?= (int)$pl['id'] ?>">
  <div class="form-row cols-3">
    <div><label>Name</label><input name="name" required value="<?= e($pl['name']) ?>"></div>
    <?php if (!$pl['id']): ?><div><label>Short code</label><input name="code" required placeholder="gold"></div><?php endif; ?>
    <div><label>₹ / month</label><input type="number" step="1" min="0" name="price_month" value="<?= (float)$pl['price_month'] ?>"></div>
    <div><label>₹ / year</label><input type="number" step="1" min="0" name="price_year" value="<?= (float)$pl['price_year'] ?>"></div>
    <div><label>Users</label><input type="number" min="0" name="max_users" value="<?= (int)$pl['max_users'] ?>"></div>
    <div><label>Bills / month</label><input type="number" min="0" name="max_bills_month" value="<?= (int)$pl['max_bills_month'] ?>"></div>
    <div><label>WhatsApp / month</label><input type="number" min="0" name="max_wa_month" value="<?= (int)$pl['max_wa_month'] ?>"></div>
    <div><label>Locations</label><input type="number" min="0" name="max_locations" value="<?= (int)$pl['max_locations'] ?>"></div>
  </div>
  <label class="check-inline mt"><input type="checkbox" name="all_features" value="1" <?= in_array('*', $f, true) ? 'checked' : '' ?>> <b>Everything</b></label>
  <div style="display:flex;flex-wrap:wrap;gap:4px 14px;margin:6px 0">
  <?php foreach (platform_features() as $code => [$label]): ?>
    <label class="check-inline"><input type="checkbox" name="features[]" value="<?= $code ?>" <?= in_array($code, $f, true) || in_array('*', $f, true) ? 'checked' : '' ?>> <?= e($label) ?></label>
  <?php endforeach; ?></div>
  <label class="check-inline"><input type="checkbox" name="white_label" value="1" <?= $pl['white_label'] ? 'checked' : '' ?>> May hide "Powered by"</label>
  <label class="check-inline"><input type="checkbox" name="is_active" value="1" <?= $pl['is_active'] ? 'checked' : '' ?>> Offered</label>
  <button class="btn btn-sm mt" type="submit">Save</button>
</form></div></div>
<?php endforeach; ?>

<?php elseif ($tab === 'resellers'):
$rs = pall("SELECT r.*, (SELECT COUNT(*) FROM tenants t WHERE t.reseller_id = r.id) shops,
            (SELECT COALESCE(SUM(commission),0) FROM tenant_payments p WHERE p.reseller_id = r.id AND p.status = 'paid' AND p.commission > 0) due
            FROM resellers r ORDER BY r.id DESC"); ?>
<p class="muted">A reseller shares <code><?= e(base_url('signup.php')) ?>?ref=CODE</code> and sees their shops at <code><?= e(base_url('reseller.php')) ?></code>. Shops that sign up with the code are theirs, and every payment those shops make earns the commission.</p>
<div class="pane"><div class="pane-body tight"><table class="rowlist rl-scan">
  <thead><tr><th>Reseller</th><th class="num">Commission due</th><th>Code</th><th>Shops</th><th>%</th><th>Their page</th><th></th></tr></thead><tbody>
  <?php foreach ($rs as $r): ?><tr>
    <td class="rl-main"><?= e($r['name']) ?> <?= $r['is_active'] ? '' : '<span class="badge badge-bad">off</span>' ?></td>
    <td class="num">₹<?= money($r['due']) ?></td>
    <td><?= e($r['code']) ?> · <?= e($r['mobile']) ?></td><td><?= (int)$r['shops'] ?> shops</td><td><?= (float)$r['commission_pct'] ?>%</td>
    <td class="rl-note"><form method="post" style="display:inline-flex;gap:4px"><?= csrf_field() ?><input type="hidden" name="do" value="reseller_password"><input type="hidden" name="rid" value="<?= $r['id'] ?>"><input type="text" name="password" minlength="8" placeholder="new password" style="max-width:130px" autocomplete="off"><button class="btn btn-sm btn-outline" type="submit">Set</button></form></td>
    <td class="rl-act"><?php if ($r['due'] > 0.009): ?><form method="post" onsubmit="return confirm('Mark ₹<?= money($r['due']) ?> as paid to <?= e($r['name']) ?>?')"><?= csrf_field() ?><input type="hidden" name="do" value="pay_commission"><input type="hidden" name="rid" value="<?= $r['id'] ?>"><button class="btn btn-sm btn-outline" type="submit">Paid</button></form><?php endif; ?></td>
  </tr><?php endforeach; ?>
  <?php if (!$rs): ?><tr><td class="rl-main muted">No resellers yet.</td></tr><?php endif; ?>
</tbody></table></div></div>
<div class="pane"><div class="pane-head"><h3>＋ Add a reseller</h3></div><div class="pane-body">
<form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="save_reseller">
  <div class="form-row cols-3"><div><label>Name</label><input name="name" required></div><div><label>Mobile</label><input name="mobile"></div>
  <div><label>Email</label><input name="email" type="email"></div><div><label>Code (blank = make one)</label><input name="code" maxlength="20"></div>
  <div><label>Commission %</label><input type="number" name="commission_pct" value="20" min="0" max="90" step="0.5"></div>
  <div><label>Password for their page</label><input type="text" name="password" minlength="8" autocomplete="off"></div></div>
  <input type="hidden" name="is_active" value="1"><button class="btn btn-sm mt" type="submit">Add</button></form></div></div>

<?php elseif ($tab === 'payments'):
$pays = pall('SELECT p.*, t.name shop, pl.name plan FROM tenant_payments p JOIN tenants t ON t.id = p.tenant_id LEFT JOIN plans pl ON pl.id = p.plan_id ORDER BY p.id DESC LIMIT 200'); ?>
<div class="pane"><div class="pane-body tight"><table class="rowlist rl-scan">
  <thead><tr><th>Shop</th><th class="num">Amount</th><th>Plan</th><th>Status</th><th>Date</th><th>Ref</th></tr></thead><tbody>
  <?php foreach ($pays as $x): ?><tr>
    <td class="rl-main"><?= e($x['shop']) ?></td><td class="num">₹<?= money($x['amount']) ?></td>
    <td><?= e($x['plan'] ?? '') ?> · <?= (int)$x['months'] ?> mo</td>
    <td><span class="badge badge-<?= $x['status'] === 'paid' ? 'ok' : ($x['status'] === 'failed' ? 'bad' : 'info') ?>"><?= e($x['status']) ?></span></td>
    <td><?= dmyt($x['paid_at'] ?: $x['created_at']) ?></td><td><?= e($x['ref']) ?></td></tr>
  <?php endforeach; if (!$pays): ?><tr><td class="rl-main muted">No payments yet.</td></tr><?php endif; ?>
</tbody></table></div></div>

<?php elseif ($tab === 'tickets'):
$tks = pall("SELECT k.*, t.name shop, t.owner_mobile FROM platform_tickets k JOIN tenants t ON t.id = k.tenant_id ORDER BY k.status = 'open' DESC, k.id DESC LIMIT 100"); ?>
<?php foreach ($tks as $k): ?>
<div class="pane"><div class="pane-head"><h3><?= e($k['subject']) ?> <span class="badge badge-<?= $k['status'] === 'open' ? 'warn' : 'ok' ?>"><?= e($k['status']) ?></span></h3></div><div class="pane-body">
  <p class="muted" style="margin:0 0 6px"><?= e($k['shop']) ?> · <?= e($k['raised_by']) ?> · <?= dmyt($k['created_at']) ?></p>
  <p style="white-space:pre-wrap;margin:0 0 8px"><?= e($k['body']) ?></p>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="reply_ticket"><input type="hidden" name="tid" value="<?= $k['tenant_id'] ?>"><input type="hidden" name="ticket_id" value="<?= $k['id'] ?>">
    <textarea name="reply" rows="2" placeholder="Your answer"><?= e((string)$k['reply']) ?></textarea>
    <label class="check-inline"><input type="checkbox" name="close" value="1"> Close it</label>
    <button class="btn btn-sm" type="submit">Send answer</button></form>
</div></div>
<?php endforeach; if (!$tks): ?><div class="card"><p class="muted">No questions from shops.</p></div><?php endif; ?>

<?php else: ?>
<div class="pane"><div class="pane-body">
<form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="save_settings">
  <div class="form-row cols-2">
    <div><label>Shops' web address</label><input name="platform_domain" value="<?= e(setting('platform_domain', '')) ?>" placeholder="akdwk.in">
      <div class="muted" style="font-size:12px">Each shop opens on <code>&lt;name&gt;.<?= e(setting('platform_domain', 'akdwk.in') ?: 'akdwk.in') ?></code>. In the hosting, point a wildcard sub-domain <code>*.<?= e(setting('platform_domain', 'akdwk.in') ?: 'akdwk.in') ?></code> at this same folder.</div></div>
    <div><label>Free trial (days)</label><input type="number" min="0" max="90" name="platform_trial_days" value="<?= (int)setting('platform_trial_days', '15') ?>"></div>
    <div><label>Plan during the trial</label><select name="platform_trial_plan"><?php foreach ($plans as $pl): ?><option value="<?= e($pl['code']) ?>" <?= setting('platform_trial_plan', 'pro') === $pl['code'] ? 'selected' : '' ?>><?= e($pl['name']) ?></option><?php endforeach; ?></select></div>
    <div><label>"Powered by" line on shops' bills and pages</label><input name="platform_powered_by" value="<?= e(setting('platform_powered_by', 'Powered by AK Computer')) ?>"></div>
  </div>
  <label class="check-inline mt"><input type="hidden" name="platform_signup_open" value="0"><input type="checkbox" name="platform_signup_open" value="1" <?= setting('platform_signup_open', '0') === '1' ? 'checked' : '' ?>> <b>Anyone may sign up</b> at <code><?= e(base_url('signup.php')) ?></code></label>
  <label class="check-inline"><input type="hidden" name="platform_signup_otp" value="0"><input type="checkbox" name="platform_signup_otp" value="1" <?= setting('platform_signup_otp', '1') === '1' ? 'checked' : '' ?>> Check the mobile number with a WhatsApp OTP</label>
  <h3 class="mt">How a new shop's database is made</h3>
  <div class="form-row cols-2">
    <div><label>Method</label><select name="platform_db_mode">
      <option value="mysql" <?= setting('platform_db_mode', 'mysql') === 'mysql' ? 'selected' : '' ?>>Automatic (server allows CREATE DATABASE - VPS)</option>
      <option value="cpanel" <?= setting('platform_db_mode') === 'cpanel' ? 'selected' : '' ?>>Automatic through cPanel (shared hosting)</option>
      <option value="manual" <?= setting('platform_db_mode') === 'manual' ? 'selected' : '' ?>>By hand - I make each database in cPanel</option></select></div>
    <div><label>Database name prefix <span class="muted" style="font-weight:normal">(cPanel adds "user_")</span></label><input name="platform_db_prefix" value="<?= e(setting('platform_db_prefix', '')) ?>" placeholder="akdwk_"></div>
    <div><label>cPanel host</label><input name="cpanel_host" value="<?= e(setting('cpanel_host', '')) ?>" placeholder="server123.hosting.com"></div>
    <div><label>cPanel user</label><input name="cpanel_user" value="<?= e(setting('cpanel_user', '')) ?>"></div>
    <div><label>cPanel API token <span class="muted" style="font-weight:normal">(<?= setting('cpanel_token', '') !== '' ? 'saved — blank keeps it' : 'not set' ?>)</span></label><input name="cpanel_token" type="password" autocomplete="new-password"></div>
  </div>
  <button class="btn mt" type="submit">Save settings</button>
</form></div></div>
<div class="pane"><div class="pane-head"><h3>🎮 Demo shop</h3></div><div class="pane-body">
  <?php $dm = demo_shop(); ?>
  <p class="muted" style="margin-top:0"><?= $dm ? 'Live at <strong>' . e($dm['domain']) . '</strong> — log in as <strong>demo / demo1234</strong>. It is wiped and made fresh every night; sign-up visitors reach it from "Try the demo".'
      : 'A sample computer shop anyone can try, without signing up. Reset every night.' ?></p>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="demo"><button class="btn btn-sm btn-outline" type="submit"><?= $dm ? 'Reset it now' : 'Make the demo shop' ?></button></form>
</div></div>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
