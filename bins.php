<?php
// Bin / Rack locations: a lightweight sub-location layer on top of the
// existing location-level stock table (which stays authoritative and
// unchanged - same "informational layer" precedent already established by
// item_batches). Lets a shop record which shelf/rack/bin an item sits on
// within a location, without touching the core stock/adjust_stock() logic.
require_once __DIR__ . '/includes/init.php';
require_perm('bins.view');
$u = current_user();
$action = get('action', 'list');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'save_bin') {
    require_perm('bins.add');
    $locId = (int)post('location_id');
    $code = trim(post('code'));
    if (!$locId || !$code) { flash('Location and bin code are required.', 'error'); redirect('bins.php'); }
    q('INSERT INTO location_bins (location_id, code, name) VALUES (?,?,?) ON DUPLICATE KEY UPDATE name = VALUES(name), is_active = 1',
      [$locId, $code, post('name')]);
    flash('Bin ' . e($code) . ' saved.');
    redirect('bins.php?location_id=' . $locId);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'toggle_bin') {
    require_perm('bins.edit');
    $b = row('SELECT * FROM location_bins WHERE id = ?', [(int)post('id')]);
    if ($b) {
        q('UPDATE location_bins SET is_active = ? WHERE id = ?', [$b['is_active'] ? 0 : 1, $b['id']]);
        flash($b['is_active'] ? 'Bin deactivated.' : 'Bin reactivated.');
    }
    redirect('bins.php?location_id=' . ($b['location_id'] ?? 0));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'assign') {
    require_perm('bins.edit');
    $binId = (int)post('bin_id');
    $itemId = (int)post('item_id');
    $qty = (float)post('qty');
    $bin = row('SELECT * FROM location_bins WHERE id = ?', [$binId]);
    if (!$bin || !$itemId) { flash('Pick a bin and item.', 'error'); redirect('bins.php'); }
    q('INSERT INTO stock_bins (item_id, location_id, bin_id, qty) VALUES (?,?,?,?)
       ON DUPLICATE KEY UPDATE qty = VALUES(qty)', [$itemId, $bin['location_id'], $binId, $qty]);
    flash('Bin contents updated.');
    redirect('bins.php?location_id=' . $bin['location_id'] . '&action=bin&id=' . $binId);
}

$locations = all('SELECT * FROM locations WHERE is_active = 1 ORDER BY name');
$locId = (int)get('location_id') ?: (int)($locations[0]['id'] ?? 0);

if ($action === 'bin') {
    $binId = (int)get('id');
    $bin = row('SELECT lb.*, l.name loc_name FROM location_bins lb JOIN locations l ON l.id = lb.location_id WHERE lb.id = ?', [$binId]);
    if (!$bin) { flash('Bin not found.', 'error'); redirect('bins.php'); }
    $contents = all('SELECT sb.*, i.name, i.unit FROM stock_bins sb JOIN items i ON i.id = sb.item_id WHERE sb.bin_id = ? AND sb.qty > 0 ORDER BY i.name', [$binId]);
    $items = all("SELECT id, name, unit FROM items WHERE is_active = 1 AND item_type <> 'service' ORDER BY name");
    $page_title = 'Bin ' . $bin['code'];
    include __DIR__ . '/includes/header.php';
    ?>
    <div class="card">
      <h2>📦 Bin <?= e($bin['code']) ?> <?= $bin['name'] ? '- ' . e($bin['name']) : '' ?></h2>
      <p class="muted"><?= e($bin['loc_name']) ?></p>
    </div>
    <div class="card">
      <h3>Contents</h3>
      <table class="table-sm">
        <?php foreach ($contents as $c): ?>
        <tr><td><?= e($c['name']) ?></td><td class="num"><?= (float)$c['qty'] ?> <?= e($c['unit']) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$contents): ?><tr><td class="muted">Nothing assigned to this bin yet.</td></tr><?php endif; ?>
      </table>
      <?php if (can('bins.edit')): ?>
      <form method="post" class="form-row cols-3 mt">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="assign">
        <input type="hidden" name="bin_id" value="<?= $binId ?>">
        <div><label>Item</label>
          <select name="item_id" required>
            <option value="">-- select --</option>
            <?php foreach ($items as $it): ?><option value="<?= $it['id'] ?>"><?= e($it['name']) ?></option><?php endforeach; ?>
          </select></div>
        <div><label>Qty in this bin</label><input type="number" step="any" min="0" name="qty" value="0" required></div>
        <div style="align-self:end"><button class="btn btn-sm" type="submit">Set Qty</button></div>
      </form>
      <p class="muted mt">This is a locate-it tool (which shelf an item sits on) - it doesn't drive stock totals or purchases/sales, so it's fine if it drifts slightly from the real count. Use Stock Audit for the authoritative quantity.</p>
      <?php endif; ?>
    </div>
    <?php
    include __DIR__ . '/includes/footer.php';
    exit;
}

// ---------- list ----------
$bins = $locId ? all('SELECT lb.*, (SELECT COUNT(*) FROM stock_bins sb WHERE sb.bin_id = lb.id AND sb.qty > 0) item_count
                       FROM location_bins lb WHERE lb.location_id = ? ORDER BY lb.code', [$locId]) : [];
$page_title = 'Bin / Rack Locations';
include __DIR__ . '/includes/header.php';
?>
<form method="get" class="filterbar">
  <div><label>Location</label>
    <select name="location_id" onchange="this.form.submit()">
      <?php foreach ($locations as $l): ?><option value="<?= $l['id'] ?>" <?= $l['id'] == $locId ? 'selected' : '' ?>><?= e($l['name']) ?></option><?php endforeach; ?>
    </select></div>
</form>

<?php if (can('bins.add')): ?>
<div class="card">
  <h3>+ New bin / rack</h3>
  <form method="post" class="form-row cols-3">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="save_bin">
    <input type="hidden" name="location_id" value="<?= $locId ?>">
    <div><label>Bin code *</label><input type="text" name="code" required placeholder="e.g. A1, Rack-3"></div>
    <div><label>Name / description</label><input type="text" name="name" placeholder="e.g. Top shelf, near counter"></div>
    <div style="align-self:end"><button class="btn btn-sm" type="submit">+ Add Bin</button></div>
  </form>
</div>
<?php endif; ?>

<div class="table-wrap">
<table>
  <thead><tr><th>Code</th><th>Name</th><th class="num">Items</th><th>Status</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($bins as $b): ?>
    <tr>
      <td><strong><?= e($b['code']) ?></strong></td>
      <td><?= e($b['name']) ?></td>
      <td class="num"><?= (int)$b['item_count'] ?></td>
      <td><?= $b['is_active'] ? '<span class="badge badge-ok">active</span>' : '<span class="badge badge-bad">off</span>' ?></td>
      <td style="white-space:nowrap">
        <a class="btn btn-sm btn-outline" href="bins.php?action=bin&id=<?= $b['id'] ?>&location_id=<?= $locId ?>">Open</a>
        <?php if (can('bins.edit')): ?>
        <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="do" value="toggle_bin"><input type="hidden" name="id" value="<?= $b['id'] ?>">
          <button class="btn btn-sm btn-outline" type="submit"><?= $b['is_active'] ? 'Deactivate' : 'Activate' ?></button></form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$bins): ?><tr><td colspan="5" class="muted">No bins yet at this location.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
