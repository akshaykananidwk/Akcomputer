<?php
// Staff users: role + location + optional extra per-user permissions
require_once __DIR__ . '/includes/init.php';
require_perm('users.view');

$action = get('action', 'list');
$id = (int)get('id');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'save') {
    require_perm($id ? 'users.edit' : 'users.add');
    $extra = array_values((array)post('extra_perms', []));
    $data = [post('name'), post('username'), post('mobile'), (int)post('role_id'), (int)post('location_id'),
             json_encode($extra), post('is_active') ? 1 : 0];
    if ($id) {
        q('UPDATE users SET name=?, username=?, mobile=?, role_id=?, location_id=?, permissions=?, is_active=? WHERE id=?',
          array_merge($data, [$id]));
        if (post('password') !== '') {
            q('UPDATE users SET password=? WHERE id=?', [password_hash(post('password'), PASSWORD_DEFAULT), $id]);
        }
        flash('User updated.');
    } else {
        if (post('password') === '') { flash('Password required for new user.', 'error'); redirect('users.php?action=new'); }
        q('INSERT INTO users (name, username, mobile, role_id, location_id, permissions, is_active, password) VALUES (?,?,?,?,?,?,?,?)',
          array_merge($data, [password_hash(post('password'), PASSWORD_DEFAULT)]));
        flash('User created.');
    }
    log_activity('user_save', post('username'));
    redirect('users.php');
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
        <p class="muted mb">Role ના rights ઉપરાંત આ user ને વધારાની permission આપવી હોય તો tick કરો.</p>
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
      <td><?php if (can('users.edit')): ?><a class="btn btn-sm btn-outline" href="users.php?action=edit&id=<?= $us['id'] ?>">Edit</a><?php endif; ?></td>
    </tr>
  <?php endforeach; ?></tbody>
</table>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
