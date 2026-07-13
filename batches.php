<?php
// Batch / expiry tracking (e.g. ink cartridges, cables with a shelf life).
// Informational layer on top of normal stock - does not drive FIFO
// consumption, just flags what's expiring soon.
require_once __DIR__ . '/includes/init.php';
require_perm('batches.view');
$u = current_user();
$action = get('action', 'list');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'save') {
    require_perm(post('id') ? 'batches.edit' : 'batches.add');
    $id = (int)post('id');
    $data = [(int)post('item_id'), (int)post('location_id') ?: $u['location_id'], post('batch_no'),
              post('expiry_date') ?: null, (float)post('qty'), post('notes')];
    if (!$data[0]) { flash('Item જરૂરી છે.', 'error'); redirect('batches.php?action=' . ($id ? "edit&id=$id" : 'new')); }
    if ($id) {
        q('UPDATE item_batches SET item_id=?, location_id=?, batch_no=?, expiry_date=?, qty=?, notes=? WHERE id=?', array_merge($data, [$id]));
        flash('Batch updated.');
    } else {
        q('INSERT INTO item_batches (item_id, location_id, batch_no, expiry_date, qty, notes, created_by) VALUES (?,?,?,?,?,?,?)',
          array_merge($data, [$u['id']]));
        flash('Batch added.');
    }
    log_activity('batch_save', post('batch_no'));
    redirect('batches.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'delete') {
    require_perm('batches.delete');
    q('DELETE FROM item_batches WHERE id = ?', [(int)post('id')]);
    flash('Batch deleted.');
    redirect('batches.php');
}

$items = all("SELECT id, name, unit FROM items WHERE is_active = 1 AND item_type <> 'service' ORDER BY name");
$locations = all('SELECT * FROM locations WHERE is_active = 1 ORDER BY name');

if ($action === 'new' || $action === 'edit') {
    require_perm($action === 'edit' ? 'batches.edit' : 'batches.add');
    $b = $action === 'edit' ? row('SELECT * FROM item_batches WHERE id = ?', [(int)get('id')]) : null;
    if ($action === 'edit' && !$b) die('Batch not found.');
    $page_title = $b ? 'Edit Batch' : 'New Batch';
    include __DIR__ . '/includes/header.php';
    ?>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="save">
      <?php if ($b): ?><input type="hidden" name="id" value="<?= $b['id'] ?>"><?php endif; ?>
      <div class="card">
        <div class="form-row cols-2">
          <div><label>Item *</label>
            <select name="item_id" required>
              <option value="">-- select --</option>
              <?php foreach ($items as $it): ?>
              <option value="<?= $it['id'] ?>" <?= ($b['item_id'] ?? 0) == $it['id'] ? 'selected' : '' ?>><?= e($it['name']) ?></option>
              <?php endforeach; ?>
            </select></div>
          <div><label>Location</label>
            <select name="location_id"><?php foreach ($locations as $l): ?><option value="<?= $l['id'] ?>" <?= ($b['location_id'] ?? $u['location_id']) == $l['id'] ? 'selected' : '' ?>><?= e($l['name']) ?></option><?php endforeach; ?></select></div>
        </div>
        <div class="form-row cols-3">
          <div><label>Batch / Lot No.</label><input type="text" name="batch_no" value="<?= e($b['batch_no'] ?? '') ?>"></div>
          <div><label>Expiry date</label><input type="date" name="expiry_date" value="<?= e($b['expiry_date'] ?? '') ?>"></div>
          <div><label>Quantity</label><input type="number" step="any" name="qty" value="<?= e($b['qty'] ?? '0') ?>"></div>
        </div>
        <div class="field"><label>Notes</label><input type="text" name="notes" value="<?= e($b['notes'] ?? '') ?>"></div>
        <button class="btn btn-block mt" type="submit">💾 Save Batch</button>
      </div>
    </form>
    <?php
    include __DIR__ . '/includes/footer.php';
    exit;
}

// ---------- list ----------
$batches = all("SELECT ib.*, i.name item_name, i.unit, l.name loc_name FROM item_batches ib
                JOIN items i ON i.id = ib.item_id JOIN locations l ON l.id = ib.location_id
                ORDER BY ib.expiry_date IS NULL, ib.expiry_date");
$expSoon = count(array_filter($batches, fn($b) => $b['expiry_date'] && $b['expiry_date'] <= date('Y-m-d', strtotime('+30 days'))));
$page_title = 'Batch / Expiry Tracking';
include __DIR__ . '/includes/header.php';
?>
<div class="duo-cards">
  <div class="duo-card" style="background:#e0f2fe"><div class="duo-label" style="color:#075985">Total Batches</div><div class="duo-value" style="color:#0369a1"><?= count($batches) ?></div></div>
  <div class="duo-card <?= $expSoon ? 'duo-give' : '' ?>"><div class="duo-label">Expiring ≤30 days</div><div class="duo-value"><?= $expSoon ?></div></div>
</div>
<div class="page-actions">
  <?php if (can('batches.add')): ?><a class="btn" href="batches.php?action=new">+ New Batch</a><?php endif; ?>
</div>
<div class="table-wrap">
<table>
  <thead><tr><th>Item</th><th>Batch No.</th><th>Location</th><th class="num">Qty</th><th>Expiry</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($batches as $b):
      $days = $b['expiry_date'] ? days_between(today(), $b['expiry_date']) : null; ?>
    <tr>
      <td><?= e($b['item_name']) ?></td>
      <td><?= e($b['batch_no'] ?: '-') ?></td>
      <td><?= e($b['loc_name']) ?></td>
      <td class="num"><?= (float)$b['qty'] ?> <?= e($b['unit']) ?></td>
      <td><?= $b['expiry_date'] ? dmy($b['expiry_date']) : '<span class="muted">-</span>' ?>
        <?php if ($days !== null && $days < 0): ?><span class="badge badge-bad">Expired</span>
        <?php elseif ($days !== null && $days <= 30): ?><span class="badge badge-warn"><?= $days ?> days left</span>
        <?php endif; ?>
      </td>
      <td style="white-space:nowrap">
        <?php if (can('batches.edit')): ?><a class="btn btn-sm btn-outline" href="batches.php?action=edit&id=<?= $b['id'] ?>">Edit</a><?php endif; ?>
        <?php if (can('batches.delete')): ?><form method="post" style="display:inline" onsubmit="return confirm('Batch delete કરવો?')"><?= csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= $b['id'] ?>"><button class="btn btn-sm btn-danger" type="submit">✕</button></form><?php endif; ?>
      </td>
    </tr>
  <?php endforeach; if (!$batches): ?><tr><td colspan="6" class="muted">કોઈ batch નથી. "+ New Batch" દબાવીને શરૂ કરો.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
