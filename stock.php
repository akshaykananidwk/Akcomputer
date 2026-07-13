<?php
// Stock overview: location-wise, staff-held, serials, ledger, manual adjust
require_once __DIR__ . '/includes/init.php';
require_perm('stock.view');
$u = current_user();
$action = get('action', 'list');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'adjust') {
    require_perm('stock.adjust');
    $item_id = (int)post('item_id');
    $loc_id = (int)post('location_id');
    $delta = (float)post('delta');
    if ($item_id && $loc_id && $delta != 0) {
        adjust_stock($item_id, $loc_id, $delta, 'manual_adjust', null, post('reason'));
        log_activity('stock_adjust', "item=$item_id loc=$loc_id delta=$delta " . post('reason'));
        flash('Stock adjusted.');
    }
    redirect('stock.php');
}

$locations = all('SELECT * FROM locations WHERE is_active = 1 ORDER BY name');

if ($action === 'ledger') {
    $item_id = (int)get('item_id');
    $item = row('SELECT * FROM items WHERE id = ?', [$item_id]);
    $ledger = all('SELECT sl.*, l.name loc_name, u2.name user_name, cb.name by_name FROM stock_ledger sl
                   LEFT JOIN locations l ON l.id = sl.location_id
                   LEFT JOIN users u2 ON u2.id = sl.user_id
                   LEFT JOIN users cb ON cb.id = sl.created_by
                   WHERE sl.item_id = ? ORDER BY sl.id DESC LIMIT 200', [$item_id]);
    $page_title = 'Ledger: ' . ($item['name'] ?? '');
    include __DIR__ . '/includes/header.php';
    ?>
    <div class="page-actions"><a class="btn btn-outline" href="stock.php">← Back to stock</a></div>
    <div class="table-wrap">
    <table>
      <thead><tr><th>Date</th><th>Where</th><th class="num">Change</th><th>Ref</th><th>By</th><th>Note</th></tr></thead>
      <tbody><?php foreach ($ledger as $le): ?>
        <tr>
          <td><?= dmyt($le['created_at']) ?></td>
          <td><?= e($le['loc_name'] ?: ('Staff: ' . $le['user_name'])) ?></td>
          <td class="num" style="color:<?= $le['change_qty'] >= 0 ? 'var(--ok)' : 'var(--bad)' ?>"><?= $le['change_qty'] > 0 ? '+' : '' ?><?= (float)$le['change_qty'] ?></td>
          <td><?= e($le['ref_type']) ?> #<?= e($le['ref_id']) ?></td>
          <td><?= e($le['by_name']) ?></td>
          <td><?= e($le['note']) ?></td>
        </tr>
      <?php endforeach; ?></tbody>
    </table>
    </div>
    <?php
    include __DIR__ . '/includes/footer.php';
    exit;
}

// main stock matrix
$loc_filter = (int)get('loc');
$stockRows = all("SELECT i.id, i.name, i.unit, i.min_stock, i.serial_tracked FROM items i WHERE i.is_active = 1 AND i.item_type <> 'service' ORDER BY i.name");
$stockMap = [];
foreach (all('SELECT * FROM stock') as $s) $stockMap[$s['item_id']][$s['location_id']] = (float)$s['qty'];
$staffHeld = [];
foreach (all('SELECT ss.item_id, SUM(ss.qty) q FROM staff_stock ss GROUP BY ss.item_id') as $s) $staffHeld[$s['item_id']] = (float)$s['q'];
$staffDetail = all('SELECT ss.*, u2.name staff_name, i.name item_name, i.unit FROM staff_stock ss
                    JOIN users u2 ON u2.id = ss.user_id JOIN items i ON i.id = ss.item_id
                    WHERE ss.qty > 0 ORDER BY u2.name, i.name');

$page_title = 'Stock';
include __DIR__ . '/includes/header.php';
?>
<div class="searchbox"><input type="text" id="sFilter" placeholder="🔍 Search item..."></div>
<div class="table-wrap">
<table id="sTable">
  <thead><tr><th>Item</th>
  <?php foreach ($locations as $l): ?><th class="num"><?= e($l['code']) ?></th><?php endforeach; ?>
  <th class="num">Staff</th><th class="num">Total</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($stockRows as $it):
      $rowTotal = 0; ?>
    <tr>
      <td><?= e($it['name']) ?><?= $it['serial_tracked'] ? ' <span class="badge badge-info">SN</span>' : '' ?></td>
      <?php foreach ($locations as $l): $qv = $stockMap[$it['id']][$l['id']] ?? 0; $rowTotal += $qv; ?>
        <td class="num"><?= $qv ?: '·' ?></td>
      <?php endforeach; $sh = $staffHeld[$it['id']] ?? 0; $rowTotal += $sh; ?>
      <td class="num"><?= $sh ?: '·' ?></td>
      <td class="num"><strong><?= $rowTotal ?></strong>
        <?= $it['min_stock'] > 0 && $rowTotal < $it['min_stock'] ? '<span class="badge badge-bad">LOW</span>' : '' ?></td>
      <td><a class="btn btn-sm btn-outline" href="stock.php?action=ledger&item_id=<?= $it['id'] ?>">Ledger</a></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<?php if ($staffDetail): ?>
<div class="card">
  <h2>🎒 Stock held by staff</h2>
  <div class="table-wrap" style="box-shadow:none">
  <table class="table-sm">
    <thead><tr><th>Staff</th><th>Item</th><th class="num">Qty</th></tr></thead>
    <tbody><?php foreach ($staffDetail as $s): ?>
      <tr><td><?= e($s['staff_name']) ?></td><td><?= e($s['item_name']) ?></td><td class="num"><?= (float)$s['qty'] ?> <?= e($s['unit']) ?></td></tr>
    <?php endforeach; ?></tbody>
  </table>
  </div>
</div>
<?php endif; ?>

<?php if (can('stock.adjust')): ?>
<div class="card">
  <h2>Manual stock adjust</h2>
  <form method="post" class="filterbar">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="adjust">
    <div><label>Item</label>
      <select name="item_id"><?php foreach ($stockRows as $it): ?><option value="<?= $it['id'] ?>"><?= e($it['name']) ?></option><?php endforeach; ?></select></div>
    <div><label>Location</label>
      <select name="location_id"><?php foreach ($locations as $l): ?><option value="<?= $l['id'] ?>"><?= e($l['name']) ?></option><?php endforeach; ?></select></div>
    <div><label>+/- Qty</label><input type="number" step="any" name="delta" required placeholder="-2 or 5"></div>
    <div><label>Reason</label><input type="text" name="reason" required placeholder="opening / damage / count fix"></div>
    <button class="btn btn-sm" type="submit">Adjust</button>
  </form>
</div>
<?php endif; ?>
<script>tableFilter('sFilter', 'sTable');</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
