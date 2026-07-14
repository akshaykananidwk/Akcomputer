<?php
// Staff users: role + location + optional extra per-user permissions
require_once __DIR__ . '/includes/init.php';

// Must be reachable BEFORE require_perm('users.view') below - while
// impersonating, the active session's permissions are the IMPERSONATED
// user's, who may well have no users.* access at all. Without this
// escape hatch working regardless of the current permission set, the
// header's "return to admin" button would itself say Access Denied and
// trap the admin in the impersonated session (only a full logout would
// get them back out).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'stop_impersonate' && !empty($_SESSION['impersonator_id'])) {
    require_login();
    $wasUser = current_user();
    session_regenerate_id(true);
    $_SESSION['user_id'] = $_SESSION['impersonator_id'];
    unset($_SESSION['impersonator_id']);
    log_activity('impersonate_stop', 'back from ' . ($wasUser['name'] ?? '?'));
    flash('Back to Admin.');
    redirect('users.php');
}

require_perm('users.view');

$action = get('action', 'list');
$id = (int)get('id');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'save') {
    require_perm($id ? 'users.edit' : 'users.add');
    $extra = array_values((array)post('extra_perms', []));
    $data = [post('name'), post('username'), post('mobile'), (int)post('role_id'), (int)post('location_id'),
             json_encode($extra), post('is_active') ? 1 : 0];
    if ($id) {
        if (post('password') !== '') {
            $pwdErr = password_policy_check(post('password'));
            if ($pwdErr) { flash($pwdErr, 'error'); redirect('users.php?action=edit&id=' . $id); }
        }
        q('UPDATE users SET name=?, username=?, mobile=?, role_id=?, location_id=?, permissions=?, is_active=? WHERE id=?',
          array_merge($data, [$id]));
        if (post('password') !== '') {
            q('UPDATE users SET password=?, password_changed_at=NOW() WHERE id=?', [password_hash(post('password'), PASSWORD_DEFAULT), $id]);
        }
        flash('User updated.');
    } else {
        if (post('password') === '') { flash('Password required for new user.', 'error'); redirect('users.php?action=new'); }
        $pwdErr = password_policy_check(post('password'));
        if ($pwdErr) { flash($pwdErr, 'error'); redirect('users.php?action=new'); }
        q('INSERT INTO users (name, username, mobile, role_id, location_id, permissions, is_active, password, password_changed_at) VALUES (?,?,?,?,?,?,?,?,NOW())',
          array_merge($data, [password_hash(post('password'), PASSWORD_DEFAULT)]));
        flash('User created.');
    }
    log_activity('user_save', post('username'));
    redirect('users.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'delete') {
    require_perm('users.delete');
    $uid = (int)post('id');
    if ($uid === current_user()['id']) {
        flash('You cannot delete/deactivate yourself.', 'error');
        redirect('users.php');
    }
    // Users are referenced everywhere (sales.created_by, tasks.assigned_to,
    // etc.) - deactivating (not hard-deleting) keeps that history intact.
    q('UPDATE users SET is_active = 0 WHERE id = ?', [$uid]);
    log_activity('user_delete', "#$uid");
    flash('User deactivated (history preserved).');
    redirect('users.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'force_logout') {
    require_perm('users.edit');
    $uid = (int)post('id');
    q('UPDATE user_sessions SET revoked = 1 WHERE user_id = ?', [$uid]);
    log_activity('user_force_logout', "#$uid");
    flash('All active sessions for this user have been signed out (takes effect on their next page load).');
    redirect('users.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'disable_2fa') {
    require_perm('users.edit');
    $uid = (int)post('id');
    q('UPDATE users SET totp_enabled = 0, totp_secret_enc = NULL WHERE id = ?', [$uid]);
    q('DELETE FROM totp_backup_codes WHERE user_id = ?', [$uid]);
    log_activity('user_2fa_disable', "#$uid (by admin)");
    flash('Two-factor authentication disabled for this user - they can set it up again from My Account.');
    redirect('users.php');
}

// ---------- login-as-user (impersonate) ----------
// Lets an admin see the app exactly as a given staff member sees it -
// same permission scoping, same "only my own bills" restrictions - to
// verify what a role can/can't do without needing that person's password.
// Fully logged (start + stop) since it's a real identity switch.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'impersonate') {
    require_perm('users.impersonate');
    $target = row('SELECT * FROM users WHERE id = ? AND is_active = 1', [(int)post('id')]);
    if (!$target) { flash('User not found or inactive.', 'error'); redirect('users.php'); }
    if ($target['id'] == current_user()['id']) { flash('You are already logged in as yourself.', 'error'); redirect('users.php'); }
    if (!empty($_SESSION['impersonator_id'])) { flash('Stop the current impersonation first.', 'error'); redirect('users.php'); }
    log_activity('impersonate_start', current_user()['name'] . ' -> ' . $target['name']);
    session_regenerate_id(true);
    $_SESSION['impersonator_id'] = current_user()['id'];
    $_SESSION['user_id'] = $target['id'];
    redirect('index.php');
}

$roles = all('SELECT * FROM roles ORDER BY name');
$locations = all('SELECT * FROM locations WHERE is_active = 1 ORDER BY name');

if ($action === 'new' || $action === 'edit') {
    require_perm($action === 'new' ? 'users.add' : 'users.edit');
    $usr = $id ? row('SELECT * FROM users WHERE id = ?', [$id]) : null;
    $extra = $usr ? (json_decode($usr['permissions'] ?: '[]', true) ?: []) : [];
    $page_title = $usr ? 'Edit User' : 'New User';
    include __DIR__ . '/includes/header.php';
    ?>
    <div class="card">
      <h2><?= $page_title ?></h2>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="save">
        <div class="form-row cols-2">
          <div><label>Name *</label><input type="text" name="name" value="<?= e($usr['name'] ?? '') ?>" required></div>
          <div><label>Username *</label><input type="text" name="username" value="<?= e($usr['username'] ?? '') ?>" required autocapitalize="none"></div>
        </div>
        <div class="form-row cols-3">
          <div><label>Mobile (WhatsApp, for OTP) *</label><input type="tel" name="mobile" value="<?= e($usr['mobile'] ?? '') ?>" required></div>
          <div><label>Role (department)</label>
            <select name="role_id">
              <?php foreach ($roles as $ro): ?><option value="<?= $ro['id'] ?>" <?= ($usr['role_id'] ?? '') == $ro['id'] ? 'selected' : '' ?>><?= e($ro['name']) ?></option><?php endforeach; ?>
            </select></div>
          <div><label>Location</label>
            <select name="location_id">
              <?php foreach ($locations as $l): ?><option value="<?= $l['id'] ?>" <?= ($usr['location_id'] ?? '') == $l['id'] ? 'selected' : '' ?>><?= e($l['name']) ?> (<?= e($l['city']) ?>)</option><?php endforeach; ?>
            </select></div>
        </div>
        <div class="form-row cols-2">
          <div><label>Password <?= $usr ? '(blank = keep same)' : '*' ?></label><input type="password" name="password"></div>
          <div><label class="check-inline mt"><input type="checkbox" name="is_active" value="1" <?= ($usr === null || $usr['is_active']) ? 'checked' : '' ?>> Active</label></div>
        </div>
        <h3 class="mt">Extra permissions (on top of role)</h3>
        <p class="muted mb">Tick to grant this user extra permissions beyond their role's rights.</p>
        <?php foreach (permission_catalog() as $mod => $acts): ?>
          <div class="field">
            <label><?= e(permission_labels()[$mod]) ?></label>
            <?php foreach ($acts as $a): $pkey = "$mod.$a"; ?>
              <label class="check-inline" style="display:inline-flex;margin-right:14px">
                <input type="checkbox" name="extra_perms[]" value="<?= $pkey ?>" <?= in_array($pkey, $extra) ? 'checked' : '' ?>> <?= $a ?>
              </label>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>
        <button class="btn" type="submit">Save User</button>
        <a class="btn btn-muted" href="users.php">Cancel</a>
      </form>
    </div>
    <?php
    include __DIR__ . '/includes/footer.php';
    exit;
}

$usersList = all('SELECT u.*, r.name role_name, l.name loc_name FROM users u
                  JOIN roles r ON r.id = u.role_id JOIN locations l ON l.id = u.location_id ORDER BY u.name');
$page_title = 'Staff Users';
include __DIR__ . '/includes/header.php';
?>
<div class="page-actions">
  <?php if (can('users.add')): ?><a class="btn" href="users.php?action=new">+ New User</a><?php endif; ?>
</div>
<div class="table-wrap">
<table>
  <thead><tr><th>Name</th><th>Username</th><th>Role</th><th>Location</th><th>Mobile</th><th>Status</th><th></th></tr></thead>
  <tbody><?php foreach ($usersList as $us): ?>
    <tr>
      <td><strong><?= e($us['name']) ?></strong></td>
      <td><?= e($us['username']) ?></td>
      <td><?= e($us['role_name']) ?></td>
      <td><?= e($us['loc_name']) ?></td>
      <td><?= e($us['mobile']) ?></td>
      <td><?= $us['is_active'] ? '<span class="badge badge-ok">active</span>' : '<span class="badge badge-bad">off</span>' ?></td>
      <td style="white-space:nowrap">
        <?php if (can('users.edit')): ?><a class="btn btn-sm btn-outline" href="users.php?action=edit&id=<?= $us['id'] ?>">Edit</a><?php endif; ?>
        <?php if (can('users.impersonate') && $us['is_active'] && $us['id'] != current_user()['id'] && empty($_SESSION['impersonator_id'])): ?>
        <form method="post" style="display:inline" onsubmit="return confirm('Log in as <?= e($us['name']) ?>? You will see the app through that user\'s eyes.')">
          <?= csrf_field() ?><input type="hidden" name="do" value="impersonate"><input type="hidden" name="id" value="<?= $us['id'] ?>">
          <button class="btn btn-sm btn-outline" type="submit">👁️ Login as</button>
        </form>
        <?php endif; ?>
        <?php if (can('users.edit') && $us['id'] != current_user()['id']): ?>
        <form method="post" style="display:inline" onsubmit="return confirm('Sign this user out of every device?')">
          <?= csrf_field() ?><input type="hidden" name="do" value="force_logout"><input type="hidden" name="id" value="<?= $us['id'] ?>">
          <button class="btn btn-sm btn-outline" type="submit">🔒 Force Logout</button>
        </form>
        <?php endif; ?>
        <?php if (can('users.edit') && $us['totp_enabled']): ?>
        <form method="post" style="display:inline" onsubmit="return confirm('Disable 2FA for this user? Use this if they lost their authenticator device.')">
          <?= csrf_field() ?><input type="hidden" name="do" value="disable_2fa"><input type="hidden" name="id" value="<?= $us['id'] ?>">
          <button class="btn btn-sm btn-outline" type="submit">Disable 2FA</button>
        </form>
        <?php endif; ?>
        <?php if (can('users.delete') && $us['is_active']): ?>
        <form method="post" style="display:inline" onsubmit="return confirm('Deactivate this user?')">
          <?= csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= $us['id'] ?>">
          <button class="btn btn-sm btn-danger" type="submit">✕</button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?></tbody>
</table>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
