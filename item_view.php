<?php
// Item detail page (Vyapar-style): price/stock summary + Adjust Item +
// a full Sale/Purchase/Adjustment transaction history for this one item,
// built on top of the existing stock_ledger table (every adjust_stock()
// call already logs there - this page just enriches those rows with the
// invoice/party/status details from the sales/purchases tables they
// reference instead of showing the raw ledger dump stock.php's "Ledger"
// link does).
require_once __DIR__ . '/includes/init.php';
require_perm('items.view');

$id = (int)get('id');
$item = row('SELECT i.*, c.name AS cat_name FROM items i LEFT JOIN categories c ON c.id = i.category_id WHERE i.id = ?', [$id]);
if (!$item) die('Item not found.');
$isService = $item['item_type'] === 'service';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'adjust') {
    require_perm('stock.adjust');
    $loc_id = (int)post('location_id');
    $delta = (float)post('delta');
    if ($loc_id && $delta != 0) {
        adjust_stock($id, $loc_id, $delta, 'manual_adjust', null, post('reason'));
        log_activity('stock_adjust', "item=$id loc=$loc_id delta=$delta " . post('reason'));
        flash('Stock adjusted.');
    }
    redirect('item_view.php?id=' . $id);
}

$locations = all('SELECT * FROM locations WHERE is_active = 1 ORDER BY name');
$stockQty = (float)val('SELECT COALESCE(SUM(qty),0) FROM stock WHERE item_id = ?', [$id]);
$staffQty = (float)val('SELECT COALESCE(SUM(qty),0) FROM staff_stock WHERE item_id = ?', [$id]);
$totalQty = $stockQty + $staffQty;
$stockValue = $totalQty * (float)$item['purchase_price'];
$saleValue = $totalQty * (float)$item['selling_price'];

$typeLabels = [
    'sale' => 'Sale', 'sale_edit' => 'Sale (edited)', 'sale_delete' => 'Sale Deleted',
    'purchase' => 'Purchase', 'purchase_edit' => 'Purchase (edited)', 'purchase_delete' => 'Purchase Deleted',
    'purchase_return' => 'Purchase Return', 'purchase_return_delete' => 'Purchase Return Deleted',
    'sales_return' => 'Sales Return', 'sales_return_delete' => 'Sales Return Deleted',
    'manual_adjust' => 'Adjustment', 'transfer_in' => 'Transfer In', 'transfer_out' => 'Transfer Out',
    'handover_out' => 'Handover Out', 'handover_return' => 'Handover Return', 'handover_cancel' => 'Handover Cancelled',
    'import' => 'Opening / Import',
];

$ledger = all('SELECT sl.*, l.name loc_name, u2.name user_name FROM stock_ledger sl
               LEFT JOIN locations l ON l.id = sl.location_id
               LEFT JOIN users u2 ON u2.id = sl.user_id
               WHERE sl.item_id = ? ORDER BY sl.id DESC LIMIT 200', [$id]);
foreach ($ledger as &$le) {
    $le['type_label'] = $typeLabels[$le['ref_type']] ?? ucfirst(str_replace('_', ' ', $le['ref_type']));
    $le['ref_no'] = null; $le['party_name'] = null; $le['status'] = null; $le['price'] = null;
    if (in_array($le['ref_type'], ['sale', 'sale_edit', 'sale_delete'], true) && $le['ref_id']) {
        $s = row('SELECT invoice_no, customer_name, status, is_cancelled FROM sales WHERE id = ?', [$le['ref_id']]);
        if ($s) {
            $le['ref_no'] = $s['invoice_no'];
            $le['party_name'] = $s['customer_name'] ?: 'Walk-in';
            $le['status'] = $s['is_cancelled'] ? 'cancelled' : $s['status'];
        }
        $si = row('SELECT price FROM sale_items WHERE sale_id = ? AND item_id = ? LIMIT 1', [$le['ref_id'], $id]);
        if ($si) $le['price'] = (float)$si['price'];
    } elseif (in_array($le['ref_type'], ['purchase', 'purchase_edit', 'purchase_delete'], true) && $le['ref_id']) {
        $p = row('SELECT bill_no, status, is_cancelled, party_id FROM purchases WHERE id = ?', [$le['ref_id']]);
        if ($p) {
            $le['ref_no'] = $p['bill_no'] ?: ('#' . $le['ref_id']);
            $le['party_name'] = val('SELECT name FROM parties WHERE id = ?', [$p['party_id']]);
            $le['status'] = $p['is_cancelled'] ? 'cancelled' : $p['status'];
        }
        $pi = row('SELECT price FROM purchase_items WHERE purchase_id = ? AND item_id = ? LIMIT 1', [$le['ref_id'], $id]);
        if ($pi) $le['price'] = (float)$pi['price'];
    } else {
        $le['party_name'] = $le['loc_name'] ?: ($le['user_name'] ? 'Staff: ' . $le['user_name'] : null);
        $le['ref_no'] = $le['note'];
    }
}
unset($le);

$page_title = $item['name'];
include __DIR__ . '/includes/header.php';
?>
<div class="page-actions">
  <a class="btn btn-outline" href="items.php">← Back to Items</a>
  <?php if (can('items.edit')): ?><a class="btn btn-outline" href="items.php?action=edit&id=<?= $id ?>">✏️ Edit Item</a><?php endif; ?>
  <?php if (!$isService && can('stock.adjust')): ?>
  <button class="btn" type="button" onclick="var p=document.getElementById('adjustPanel'); p.style.display = p.style.display === 'none' ? '' : 'none'">📐 Adjust Item</button>
  <?php endif; ?>
</div>

<div class="card">
  <h2><?= e($item['name']) ?> <?= $item['serial_tracked'] ? '<span class="badge badge-info">SN</span>' : '' ?> <?= $isService ? '<span class="badge badge-info">Service</span>' : '' ?></h2>
  <p class="muted"><?= e(trim($item['brand'] . ' ' . $item['model'])) ?: '&nbsp;' ?> <?= $item['cat_name'] ? '· ' . e($item['cat_name']) : '' ?></p>
</div>

<div class="grid-stats">
  <div class="stat"><div class="stat-label">Sale Price</div><div class="stat-value">₹<?= money($item['selling_price']) ?></div></div>
  <div class="stat"><div class="stat-label">Purchase Price</div><div class="stat-value">₹<?= money($item['purchase_price']) ?></div></div>
  <?php if (!$isService): ?>
  <div class="stat <?= $item['min_stock'] > 0 && $totalQty < $item['min_stock'] ? 's-bad' : '' ?>">
    <div class="stat-label">Stock Quantity <?= $item['min_stock'] > 0 && $totalQty < $item['min_stock'] ? '<span class="badge badge-bad">LOW</span>' : '' ?></div>
    <div class="stat-value"><?= $totalQty ?> <?= e($item['unit']) ?></div>
  </div>
  <div class="stat s-ok"><div class="stat-label">Stock Value (at purchase price)</div><div class="stat-value">₹<?= money($stockValue) ?></div></div>
  <?php endif; ?>
</div>

<?php if (!$isService && can('stock.adjust')): ?>
<div class="card" id="adjustPanel" style="display:none">
  <h3>Adjust Item Stock</h3>
  <form method="post" class="filterbar">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="adjust">
    <div><label>Location</label>
      <select name="location_id"><?php foreach ($locations as $l): ?><option value="<?= $l['id'] ?>"><?= e($l['name']) ?></option><?php endforeach; ?></select></div>
    <div><label>+/- Qty</label><input type="number" step="any" name="delta" required placeholder="-2 or 5"></div>
    <div><label>Reason</label><input type="text" name="reason" required placeholder="opening / damage / count fix"></div>
    <button class="btn btn-sm" type="submit">Adjust</button>
  </form>
</div>
<?php endif; ?>

<div class="card">
  <h3>Transactions</h3>
  <div class="table-wrap" style="box-shadow:none">
  <table>
    <thead><tr><th>Type</th><th>Invoice/Ref No.</th><th>Name</th><th>Date</th><th class="num">Quantity</th><th class="num">Price/Unit</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($ledger as $le): ?>
    <tr>
      <td><?= e($le['type_label']) ?></td>
      <td><?= e($le['ref_no'] ?: '-') ?></td>
      <td><?= e($le['party_name'] ?: '-') ?></td>
      <td><?= dmyt($le['created_at']) ?></td>
      <td class="num" style="color:<?= $le['change_qty'] >= 0 ? 'var(--ok)' : 'var(--bad)' ?>"><?= $le['change_qty'] > 0 ? '+' : '' ?><?= (float)$le['change_qty'] ?> <?= e($item['unit']) ?></td>
      <td class="num"><?= $le['price'] !== null ? '₹' . money($le['price']) : '-' ?></td>
      <td><?= $le['status'] ? status_badge($le['status']) : '-' ?></td>
    </tr>
    <?php endforeach; if (!$ledger): ?><tr><td colspan="7" class="muted">No transactions.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
