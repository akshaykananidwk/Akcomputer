<?php
// Stock reservation - a soft hold against an item/location that never
// touches stock.qty (unlike handover, which physically deducts on
// creation). Available-to-sell = stock_qty() - active reservations
// (stock_available_qty(), includes/helpers.php). Meant for "this item is
// spoken for" scenarios: an estimate awaiting confirmation, a customer who
// asked to hold something, etc.
require_once __DIR__ . '/includes/init.php';
require_perm('reservations.view');
$u = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'save') {
    require_perm('reservations.add');
    $itemId = (int)post('item_id');
    $locId = (int)post('location_id');
    $qty = (float)post('qty');
    if (!$itemId || !$locId || $qty <= 0) { flash('Item, location and quantity are required.', 'error'); redirect('reservations.php'); }
    if (stock_available_qty($itemId, $locId) < $qty) {
        $item = row('SELECT name FROM items WHERE id = ?', [$itemId]);
        flash("Only " . stock_available_qty($itemId, $locId) . " of {$item['name']} is available to reserve at this location.", 'error');
        redirect('reservations.php');
    }
    q('INSERT INTO stock_reservations (item_id, location_id, qty, party_id, ref_type, notes, created_by) VALUES (?,?,?,?,?,?,?)',
      [$itemId, $locId, $qty, (int)post('party_id') ?: null, 'manual', post('notes'), $u['id']]);
    log_activity('stock_reserve', "item=$itemId qty=$qty");
    flash('Stock reserved.');
    redirect('reservations.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'release') {
    require_perm('reservations.edit');
    $res = row('SELECT * FROM stock_reservations WHERE id = ?', [(int)post('id')]);
    if ($res && $res['status'] === 'active') {
        q("UPDATE stock_reservations SET status = 'released', released_at = NOW() WHERE id = ?", [$res['id']]);
        flash('Reservation released - stock is available again.');
    }
    redirect('reservations.php');
}

$items = all("SELECT id, name, unit FROM items WHERE is_active = 1 AND item_type <> 'service' ORDER BY name");
$locations = all('SELECT id, name FROM locations WHERE is_active = 1 ORDER BY name');
$parties = all('SELECT id, name FROM parties WHERE is_active = 1 ORDER BY name');

$view = get('view', 'active');
$reservations = all("SELECT sr.*, i.name item_name, i.unit, l.name loc_name, p.name party_name, s.name staff_name
                      FROM stock_reservations sr JOIN items i ON i.id = sr.item_id JOIN locations l ON l.id = sr.location_id
                      LEFT JOIN parties p ON p.id = sr.party_id LEFT JOIN users s ON s.id = sr.created_by
                      WHERE sr.status " . ($view === 'active' ? "= 'active'" : "<> 'active'") . "
                      ORDER BY sr.id DESC LIMIT 300");
$page_title = 'Stock Reservations';
include __DIR__ . '/includes/header.php';
?>
<?php if (can('reservations.add')): ?>
<div class="card">
  <h2>🔒 Reserve stock</h2>
  <form method="post" class="form-row cols-3">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="save">
    <div><label>Item *</label>
      <select name="item_id" required>
        <option value="">-- select --</option>
        <?php foreach ($items as $it): ?><option value="<?= $it['id'] ?>"><?= e($it['name']) ?></option><?php endforeach; ?>
      </select></div>
    <div><label>Location *</label>
      <select name="location_id" required>
        <option value="">-- select --</option>
        <?php foreach ($locations as $l): ?><option value="<?= $l['id'] ?>" <?= $l['id'] == $u['location_id'] ? 'selected' : '' ?>><?= e($l['name']) ?></option><?php endforeach; ?>
      </select></div>
    <div><label>Qty *</label><input type="number" step="any" min="0.01" name="qty" required></div>
    <div><label>Party (optional)</label>
      <select name="party_id">
        <option value="">-- none --</option>
        <?php foreach ($parties as $p): ?><option value="<?= $p['id'] ?>"><?= e($p['name']) ?></option><?php endforeach; ?>
      </select></div>
    <div><label>Notes</label><input type="text" name="notes" placeholder="e.g. Held for estimate EST-00012"></div>
    <div style="align-self:end"><button class="btn btn-sm" type="submit">Reserve</button></div>
  </form>
</div>
<?php endif; ?>

<div class="page-actions">
  <a class="btn btn-sm btn-outline" href="reservations.php?view=active">Active</a>
  <a class="btn btn-sm btn-outline" href="reservations.php?view=history">History</a>
</div>
<div class="table-wrap">
<table>
  <thead><tr><th>Item</th><th>Location</th><th class="num">Qty</th><th>Party</th><th>Notes</th><th>By</th><th>Status</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($reservations as $r): ?>
    <tr>
      <td><?= e($r['item_name']) ?></td>
      <td><?= e($r['loc_name']) ?></td>
      <td class="num"><?= (float)$r['qty'] ?> <?= e($r['unit']) ?></td>
      <td><?= $r['party_name'] ? '<a href="parties.php?action=ledger&id=' . $r['party_id'] . '">' . e($r['party_name']) . '</a>' : '-' ?></td>
      <td><?= e($r['notes']) ?></td>
      <td><?= e($r['staff_name']) ?></td>
      <td><?= status_badge($r['status']) ?></td>
      <td><?php if ($r['status'] === 'active' && can('reservations.edit')): ?>
        <form method="post" onsubmit="return confirm('Release this reservation?')"><?= csrf_field() ?><input type="hidden" name="do" value="release"><input type="hidden" name="id" value="<?= $r['id'] ?>">
          <button class="btn btn-sm btn-outline" type="submit">Release</button></form>
      <?php endif; ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$reservations): ?><tr><td colspan="8" class="muted">Nothing here.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
