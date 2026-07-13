<?php
// Locations: shops, branches, godowns - city wise
require_once __DIR__ . '/includes/init.php';
require_perm('locations.view');

$id = (int)get('id');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'save') {
    require_perm($id ? 'locations.edit' : 'locations.add');
    $data = [post('name'), strtoupper(post('code')), post('city'), post('type', 'shop'),
             post('address'), post('phone'), post('is_active') ? 1 : 0];
    if ($id) {
        q('UPDATE locations SET name=?, code=?, city=?, type=?, address=?, phone=?, is_active=? WHERE id=?', array_merge($data, [$id]));
        flash('Location updated.');
    } else {
        q('INSERT INTO locations (name, code, city, type, address, phone, is_active) VALUES (?,?,?,?,?,?,?)', $data);
        flash('Location added.');
    }
    log_activity('location_save', post('name'));
    redirect('locations.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'delete') {
    require_perm('locations.delete');
    $lid = (int)post('id');
    $activeCount = (int)val('SELECT COUNT(*) FROM locations WHERE is_active = 1');
    if ($activeCount <= 1) {
        flash('Cannot deactivate the last active location - staff need a location.', 'error');
        redirect('locations.php');
    }
    // stock/sales already reference this location - deactivate (not hard
    // delete) so that history and stock ledger stay intact.
    q('UPDATE locations SET is_active = 0 WHERE id = ?', [$lid]);
    log_activity('location_delete', "#$lid");
    flash('Location deactivated.');
    redirect('locations.php');
}

$loc = $id ? row('SELECT * FROM locations WHERE id = ?', [$id]) : null;
$locs = all('SELECT * FROM locations ORDER BY city, name');
$page_title = 'Locations';
include __DIR__ . '/includes/header.php';
?>
<div class="card">
  <h2><?= $loc ? 'Edit Location' : 'Add Location' ?></h2>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="save">
    <div class="form-row cols-4">
      <div><label>Name *</label><input type="text" name="name" value="<?= e($loc['name'] ?? '') ?>" required placeholder="Dwarka Main Shop"></div>
      <div><label>Short code *</label><input type="text" name="code" value="<?= e($loc['code'] ?? '') ?>" required placeholder="DWK1" maxlength="10"></div>
      <div><label>City *</label><input type="text" name="city" value="<?= e($loc['city'] ?? '') ?>" required placeholder="Dwarka"></div>
      <div><label>Type</label>
        <select name="type">
          <?php foreach (['shop', 'branch', 'godown'] as $t): ?>
          <option value="<?= $t ?>" <?= ($loc['type'] ?? 'shop') === $t ? 'selected' : '' ?>><?= ucfirst($t) ?></option>
          <?php endforeach; ?>
        </select></div>
    </div>
    <div class="form-row cols-3">
      <div><label>Address</label><input type="text" name="address" value="<?= e($loc['address'] ?? '') ?>"></div>
      <div><label>Phone</label><input type="tel" name="phone" value="<?= e($loc['phone'] ?? '') ?>"></div>
      <div><label class="check-inline mt"><input type="checkbox" name="is_active" value="1" <?= ($loc === null || $loc['is_active']) ? 'checked' : '' ?>> Active</label></div>
    </div>
    <button class="btn" type="submit">Save</button>
    <?php if ($loc): ?><a class="btn btn-muted" href="locations.php">Cancel edit</a><?php endif; ?>
  </form>
</div>
<div class="table-wrap">
<table>
  <thead><tr><th>Name</th><th>Code</th><th>City</th><th>Type</th><th>Status</th><th></th></tr></thead>
  <tbody><?php foreach ($locs as $l): ?>
    <tr>
      <td><strong><?= e($l['name']) ?></strong></td>
      <td><?= e($l['code']) ?></td>
      <td><?= e($l['city']) ?></td>
      <td><?= e($l['type']) ?></td>
      <td><?= $l['is_active'] ? '<span class="badge badge-ok">active</span>' : '<span class="badge badge-bad">off</span>' ?></td>
      <td style="white-space:nowrap">
        <?php if (can('locations.edit')): ?><a class="btn btn-sm btn-outline" href="locations.php?id=<?= $l['id'] ?>">Edit</a><?php endif; ?>
        <?php if (can('locations.delete') && $l['is_active']): ?>
        <form method="post" style="display:inline" onsubmit="return confirm('Deactivate this location?')">
          <?= csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= $l['id'] ?>">
          <button class="btn btn-sm btn-danger" type="submit">✕</button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?></tbody>
</table>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
