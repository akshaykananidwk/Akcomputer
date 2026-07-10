<?php
// Roles: permission checkboxes per module.action
require_once __DIR__ . '/includes/init.php';
require_perm('roles.view');

$action = get('action', 'list');
$id = (int)get('id');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'save') {
    require_perm($id ? 'roles.edit' : 'roles.add');
    $perms = array_values((array)post('perms', []));
    if (post('super') === '1') $perms = ['*'];
    if ($id) {
        q('UPDATE roles SET name=?, permissions=? WHERE id=?', [post('name'), json_encode($perms), $id]);
        flash('Role updated.');
    } else {
        q('INSERT INTO roles (name, permissions) VALUES (?,?)', [post('name'), json_encode($perms)]);
        flash('Role created.');
    }
    log_activity('role_save', post('name'));
    redirect('roles.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'delete') {
    require_perm('roles.delete');
    $rid = (int)post('id');
    if (val('SELECT COUNT(*) FROM users WHERE role_id = ?', [$rid]) > 0) {
        flash('Role is assigned to users - move them first.', 'error');
    } elseif (val('SELECT is_system FROM roles WHERE id = ?', [$rid])) {
        flash('System role cannot be deleted.', 'error');
    } else {
        q('DELETE FROM roles WHERE id = ?', [$rid]);
        flash('Role deleted.');
    }
    redirect('roles.php');
}

if ($action === 'new' || $action === 'edit') {
    require_perm($action === 'new' ? 'roles.add' : 'roles.edit');
    $role = $id ? row('SELECT * FROM roles WHERE id = ?', [$id]) : null;
    $perms = $role ? (json_decode($role['permissions'], true) ?: []) : [];
    $isSuper = in_array('*', $perms);
    $page_title = $role ? 'Edit Role' : 'New Role';
    include __DIR__ . '/includes/header.php';
    ?>
    <div class="card">
      <h2><?= $page_title ?></h2>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="save">
        <div class="field"><label>Role name (department)</label><input type="text" name="name" value="<?= e($role['name'] ?? '') ?>" required placeholder="e.g. Accounts, Sales, Purchase"></div>
        <label class="check-inline mb"><input type="checkbox" name="super" value="1" <?= $isSuper ? 'checked' : '' ?> onchange="document.getElementById('permGrid').style.display=this.checked?'none':''"> Super admin (everything)</label>
        <div id="permGrid" style="<?= $isSuper ? 'display:none' : '' ?>">
        <?php foreach (permission_catalog() as $mod => $acts): ?>
          <div class="field">
            <label><?= e(permission_labels()[$mod]) ?></label>
            <?php foreach ($acts as $a): $pkey = "$mod.$a"; ?>
              <label class="check-inline" style="display:inline-flex;margin-right:14px">
                <input type="checkbox" name="perms[]" value="<?= $pkey ?>" <?= in_array($pkey, $perms) ? 'checked' : '' ?>> <?= $a ?>
              </label>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>
        <p class="muted">"all" = બીજા staff ના records પણ જુએ. "profit" = profit આંકડા દેખાય. "gst" = GST report access.</p>
        </div>
        <button class="btn" type="submit">Save Role</button>
        <a class="btn btn-muted" href="roles.php">Cancel</a>
      </form>
    </div>
    <?php
    include __DIR__ . '/includes/footer.php';
    exit;
}

$rolesList = all('SELECT r.*, (SELECT COUNT(*) FROM users WHERE role_id = r.id) cnt FROM roles r ORDER BY r.name');
$page_title = 'Roles';
include __DIR__ . '/includes/header.php';
?>
<div class="page-actions">
  <?php if (can('roles.add')): ?><a class="btn" href="roles.php?action=new">+ New Role</a><?php endif; ?>
</div>
<div class="table-wrap">
<table>
  <thead><tr><th>Role</th><th class="num">Users</th><th>Permissions</th><th></th></tr></thead>
  <tbody><?php foreach ($rolesList as $ro): $p = json_decode($ro['permissions'], true) ?: []; ?>
    <tr>
      <td><strong><?= e($ro['name']) ?></strong></td>
      <td class="num"><?= $ro['cnt'] ?></td>
      <td><?= in_array('*', $p) ? '<span class="badge badge-ok">ALL</span>' : count($p) . ' rights' ?></td>
      <td>
        <?php if (can('roles.edit')): ?><a class="btn btn-sm btn-outline" href="roles.php?action=edit&id=<?= $ro['id'] ?>">Edit</a><?php endif; ?>
        <?php if (can('roles.delete') && !$ro['is_system'] && !$ro['cnt']): ?>
        <form method="post" style="display:inline" onsubmit="return confirm('Delete role?')"><?= csrf_field() ?>
        <input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= $ro['id'] ?>">
        <button class="btn btn-sm btn-danger" type="submit">✕</button></form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?></tbody>
</table>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
