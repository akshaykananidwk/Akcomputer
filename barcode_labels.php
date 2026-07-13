<?php
// Barcode label sheet - pick items + qty of labels, print on sticker sheets.
require_once __DIR__ . '/includes/init.php';
require_perm('items.view');

$action = get('action', 'pick');

if ($action === 'print') {
    $qtys = post('qty', []);
    $ids = array_keys(array_filter($qtys, fn($q) => (int)$q > 0));
    if (!$ids) { flash('Give a qty to at least one item.', 'error'); redirect('barcode_labels.php'); }
    $items = all('SELECT * FROM items WHERE id IN (' . implode(',', array_map('intval', $ids)) . ')');
    $byId = [];
    foreach ($items as $it) $byId[$it['id']] = $it;
    $labels = [];
    foreach ($ids as $id) {
        $n = (int)$qtys[$id];
        for ($i = 0; $i < $n; $i++) $labels[] = $byId[$id];
    }
    ?>
    <!doctype html><html><head><meta charset="utf-8"><title>Barcode Labels</title>
    <style>
      body { font-family: Arial, sans-serif; margin: 0; }
      .sheet { display: flex; flex-wrap: wrap; gap: 4mm; padding: 5mm; }
      .label { width: 45mm; height: 25mm; border: 1px dotted #ccc; padding: 2mm; box-sizing: border-box;
               display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; page-break-inside: avoid; }
      .label .name { font-size: 9px; font-weight: bold; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 100%; }
      .label .price { font-size: 10px; font-weight: bold; }
      .label img { max-width: 100%; height: 14mm; }
      .no-print { padding: 10px; }
      @media print { .no-print { display: none; } .label { border: none; } }
    </style></head><body>
    <div class="no-print"><button onclick="window.print()">🖨️ Print</button> <a href="barcode_labels.php">← Back</a></div>
    <div class="sheet">
      <?php foreach ($labels as $it): ?>
      <div class="label">
        <div class="name"><?= e($it['name']) ?></div>
        <img src="barcode.php?item_id=<?= $it['id'] ?>&h=40&s=1.6" alt="">
        <div class="price">₹<?= money($it['selling_price']) ?></div>
      </div>
      <?php endforeach; ?>
    </div>
    </body></html>
    <?php
    exit;
}

$q = get('q');
$items = all('SELECT * FROM items WHERE is_active = 1' . ($q ? ' AND (name LIKE ? OR barcode LIKE ?)' : '') . ' ORDER BY name LIMIT 200',
    $q ? ["%$q%", "%$q%"] : []);
$page_title = 'Barcode Label Printing';
include __DIR__ . '/includes/header.php';
?>
<form method="get" class="filterbar">
  <div><label>Search item</label><input type="text" name="q" value="<?= e($q) ?>" placeholder="Item name or barcode"></div>
  <button class="btn btn-sm" type="submit">Search</button>
</form>
<form method="post" action="barcode_labels.php?action=print" target="_blank">
  <?= csrf_field() ?>
  <div class="table-wrap">
  <table>
    <thead><tr><th>Item</th><th>Barcode</th><th class="num">Price</th><th class="num" style="width:100px">Labels needed</th></tr></thead>
    <tbody>
    <?php foreach ($items as $it): ?>
      <tr>
        <td><?= e($it['name']) ?></td>
        <td><?= $it['barcode'] ? '<code>' . e($it['barcode']) . '</code>' : '<span class="muted">(auto-generated on print)</span>' ?></td>
        <td class="num">₹<?= money($it['selling_price']) ?></td>
        <td class="num"><input type="number" min="0" step="1" name="qty[<?= $it['id'] ?>]" value="0" style="width:80px"></td>
      </tr>
    <?php endforeach; if (!$items): ?><tr><td colspan="4" class="muted">No item found.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
  <button class="btn btn-block mt" type="submit">🖨️ Generate Label Sheet</button>
</form>
<?php include __DIR__ . '/includes/footer.php'; ?>
