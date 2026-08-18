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

// Vyapar-style transaction list: ONE row per bill/document, not one per
// ledger movement. Every edit writes reverse+repost pairs to stock_ledger;
// showing them all made the list grow endlessly ("Sale (edited)" spam).
// Here all movements of one document are netted into a single row - a bill
// edited ten times still shows once, with its net quantity. Documents whose
// net effect is zero (deleted/cancelled bills, item edited off the bill)
// disappear, exactly like Vyapar. Manual adjustments/transfers/handovers
// stay as individual rows. Each bill row links straight to the bill.
$raw = all('SELECT sl.*, l.name loc_name, u2.name user_name FROM stock_ledger sl
            LEFT JOIN locations l ON l.id = sl.location_id
            LEFT JOIN users u2 ON u2.id = sl.user_id
            WHERE sl.item_id = ? ORDER BY sl.id DESC LIMIT 1000', [$id]);
$groups = [];
foreach ($raw as $le) {
    $fam = null;
    if ($le['ref_id']) {
        if (in_array($le['ref_type'], ['sale', 'sale_edit', 'sale_delete'], true)) $fam = 'sale';
        elseif (in_array($le['ref_type'], ['purchase', 'purchase_edit', 'purchase_delete'], true)) $fam = 'purchase';
        elseif (in_array($le['ref_type'], ['sales_return', 'sales_return_delete'], true)) $fam = 'sales_return';
        elseif (in_array($le['ref_type'], ['purchase_return', 'purchase_return_delete'], true)) $fam = 'purchase_return';
    }
    $key = $fam ? $fam . ':' . $le['ref_id'] : 'row:' . $le['id'];
    if (!isset($groups[$key])) {
        $groups[$key] = ['fam' => $fam, 'ref_id' => (int)$le['ref_id'], 'qty' => 0.0, 'created_at' => $le['created_at'],
                         'ref_type' => $le['ref_type'], 'loc_name' => $le['loc_name'], 'user_name' => $le['user_name'], 'note' => $le['note']];
    }
    $groups[$key]['qty'] += (float)$le['change_qty'];
    if ($le['created_at'] > $groups[$key]['created_at']) $groups[$key]['created_at'] = $le['created_at'];
}

$ledger = [];
foreach ($groups as $g) {
    if ($g['fam'] && abs($g['qty']) < 0.001) continue; // bill deleted / item edited off - nothing net happened
    $le = ['type_label' => null, 'ref_no' => null, 'party_name' => null, 'status' => null, 'price' => null,
           'link' => null, 'change_qty' => $g['qty'], 'created_at' => $g['created_at'], 'date_only' => false];
    if ($g['fam'] === 'sale') {
        $s = row('SELECT invoice_no, customer_name, sale_date, status, is_cancelled FROM sales WHERE id = ?', [$g['ref_id']]);
        if (!$s) continue; // deleted bill with stray net - nothing to open
        $le['type_label'] = 'Sale';
        $le['ref_no'] = $s['invoice_no'];
        $le['party_name'] = $s['customer_name'] ?: 'Walk-in';
        $le['status'] = $s['is_cancelled'] ? 'cancelled' : $s['status'];
        $le['created_at'] = $s['sale_date']; $le['date_only'] = true;
        $le['link'] = 'sale_view.php?id=' . $g['ref_id'];
        $si = row('SELECT price FROM sale_items WHERE sale_id = ? AND item_id = ? LIMIT 1', [$g['ref_id'], $id]);
        if ($si) $le['price'] = (float)$si['price'];
    } elseif ($g['fam'] === 'purchase') {
        $p = row('SELECT bill_no, purchase_date, status, is_cancelled, party_id FROM purchases WHERE id = ?', [$g['ref_id']]);
        if (!$p) continue;
        $le['type_label'] = 'Purchase';
        $le['ref_no'] = $p['bill_no'] ?: ('#' . $g['ref_id']);
        $le['party_name'] = val('SELECT name FROM parties WHERE id = ?', [$p['party_id']]);
        $le['status'] = $p['is_cancelled'] ? 'cancelled' : $p['status'];
        $le['created_at'] = $p['purchase_date']; $le['date_only'] = true;
        $le['link'] = 'purchase_view.php?id=' . $g['ref_id'];
        $pi = row('SELECT price FROM purchase_items WHERE purchase_id = ? AND item_id = ? LIMIT 1', [$g['ref_id'], $id]);
        if ($pi) $le['price'] = (float)$pi['price'];
    } elseif ($g['fam'] === 'sales_return') {
        $sr = row('SELECT return_no, return_date, customer_name FROM sales_returns WHERE id = ?', [$g['ref_id']]);
        $le['type_label'] = 'Sales Return';
        $le['ref_no'] = $sr['return_no'] ?? ('SR#' . $g['ref_id']);
        $le['party_name'] = $sr['customer_name'] ?? null;
        if ($sr) { $le['created_at'] = $sr['return_date']; $le['date_only'] = true; }
    } elseif ($g['fam'] === 'purchase_return') {
        $pr = row('SELECT return_no, return_date FROM purchase_returns WHERE id = ?', [$g['ref_id']]);
        $le['type_label'] = 'Purchase Return';
        $le['ref_no'] = $pr['return_no'] ?? ('PR#' . $g['ref_id']);
        if ($pr) { $le['created_at'] = $pr['return_date']; $le['date_only'] = true; }
    } else {
        $le['type_label'] = $typeLabels[$g['ref_type']] ?? ucfirst(str_replace('_', ' ', $g['ref_type']));
        $le['party_name'] = $g['loc_name'] ?: ($g['user_name'] ? 'Staff: ' . $g['user_name'] : null);
        $le['ref_no'] = $g['note'];
    }
    $ledger[] = $le;
}
usort($ledger, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));

// Supplier price comparison: what each supplier charged for THIS item -
// last price (cheapest first), lowest ever, how much bought. Cost data,
// so only staff holding items.cost see it.
$suppHist = (!$isService && can('items.cost')) ? all(
    "SELECT pt.name supplier, COUNT(*) bills, SUM(pi.qty) qty, MIN(pi.price) min_price, MAX(pu.purchase_date) last_date,
        (SELECT pi2.price FROM purchase_items pi2 JOIN purchases pu2 ON pu2.id = pi2.purchase_id
          WHERE pi2.item_id = pi.item_id AND pu2.party_id = pu.party_id AND pu2.is_cancelled = 0
          ORDER BY pu2.purchase_date DESC, pi2.id DESC LIMIT 1) last_price
     FROM purchase_items pi JOIN purchases pu ON pu.id = pi.purchase_id JOIN parties pt ON pt.id = pu.party_id
     WHERE pi.item_id = ? AND pu.is_cancelled = 0
     GROUP BY pu.party_id, pt.name, pi.item_id ORDER BY last_price ASC", [$id]) : [];

// Should this item be reordered? Same arithmetic the Purchase Intelligence
// screen uses, for this one product - so the answer on the item page and the
// answer on the order list can never disagree.
$reorder = (!$isService && can('purchases.view') && can('items.cost')) ? pi_item_reorder($id) : null;

$page_title = $item['name'];
include __DIR__ . '/includes/header.php';
?>
<?php if ($reorder && $reorder['needed']): ?>
<div class="card" style="border-left:4px solid var(--warn)">
  <h3 style="margin:0 0 6px">🛒 આ વસ્તુ મંગાવવા જેવી છે</h3>
  <p class="muted" style="margin:0 0 8px;font-size:13px">
    અત્યારે <strong><?= (float)$reorder['stock'] ?> <?= e($reorder['unit']) ?></strong> છે,
    રોજ આશરે <strong><?= $reorder['per_day'] ?></strong> વેચાય છે
    <?php if ($reorder['days_left'] !== null): ?>— એટલે લગભગ <strong><?= (int)$reorder['days_left'] ?> દિવસ</strong> ચાલશે<?php endif; ?>,
    અને ડિલિવરીમાં <strong><?= (int)$reorder['lead_days'] ?> દિવસ</strong> લાગે છે.
    સૂચવેલો ઓર્ડર: <strong><?= (int)$reorder['suggest_qty'] ?> <?= e($reorder['unit']) ?></strong>.
    <?php if ($reorder['best_supplier']): ?>
      સૌથી સસ્તું <a href="parties.php?action=ledger&id=<?= (int)$reorder['best_supplier']['party_id'] ?>"><?= e($reorder['best_supplier']['name']) ?></a>
      પાસે ₹<?= money($reorder['best_supplier']['best_price']) ?> માં મળ્યું હતું.
    <?php endif; ?>
  </p>
  <a class="btn btn-sm" href="purchase_intel.php">🛒 મંગાવવાની આખી યાદી</a>
</div>
<?php endif; ?>

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
  <?php if (can('items.cost')): ?><div class="stat"><div class="stat-label">Purchase Price</div><div class="stat-value">₹<?= money($item['purchase_price']) ?></div></div><?php endif; ?>
  <?php if (!$isService): ?>
  <div class="stat <?= $item['min_stock'] > 0 && $totalQty < $item['min_stock'] ? 's-bad' : '' ?>">
    <div class="stat-label">Stock Quantity <?= $item['min_stock'] > 0 && $totalQty < $item['min_stock'] ? '<span class="badge badge-bad">LOW</span>' : '' ?></div>
    <div class="stat-value"><?= $totalQty ?> <?= e($item['unit']) ?></div>
  </div>
  <div class="stat s-ok"><div class="stat-label">Stock Value (at purchase price)</div><div class="stat-value">₹<?= money($stockValue) ?></div></div>
  <?php $transit = stock_in_transit($item['id']); $transitQty = 0;
        foreach ($transit as $t) $transitQty += (float)$t['qty'];
        if ($transitQty > 0): ?>
  <div class="stat s-warn"><div class="stat-label">રસ્તામાં (સ્વીકારવાનું બાકી)</div>
    <div class="stat-value"><?= rtrim(rtrim(number_format($transitQty, 2), '0'), '.') ?> <?= e($item['unit']) ?></div></div>
  <?php endif; ?>
  <?php endif; ?>
</div>

<?php if (!$isService && !empty($transit)): ?>
<div class="card">
  <h3>⏳ રસ્તામાં પડેલો માલ</h3>
  <p class="muted mb" style="font-size:13px">
    હેન્ડઓવર બનતાં જ માલ મોકલનારી જગ્યામાંથી <b>ઓછો થઈ જાય છે</b>, અને સામેવાળો OTP થી સ્વીકારે
    ત્યારે જ એની જગ્યાએ ઉમેરાય છે. વચ્ચેના સમયમાં એ <b>કોઈ પણ જગ્યાના સ્ટોકમાં ગણાતો નથી</b> —
    એટલે એ ખોવાયો નથી, અહીં નીચે જ છે.
  </p>
  <div class="table-wrap" style="box-shadow:none"><table class="table-sm">
    <thead><tr><th>હેન્ડઓવર</th><th class="num">નંગ</th><th>ક્યાંથી</th><th>કોની પાસે જવાનું</th><th>ક્યારથી</th></tr></thead>
    <tbody>
    <?php foreach ($transit as $t): ?>
      <tr>
        <td><a href="handover.php?action=view&id=<?= (int)$t['id'] ?>"><?= e($t['handover_no']) ?></a></td>
        <td class="num"><?= rtrim(rtrim(number_format((float)$t['qty'], 2), '0'), '.') ?></td>
        <td class="muted"><?= e($t['from_loc']) ?></td>
        <td><?= e(transit_destination($t)) ?></td>
        <td class="muted"><?= dmy($t['created_at']) ?> (<?= (int)days_between(date('Y-m-d', strtotime($t['created_at']))) ?> દિવસ)</td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>

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

<?php if ($suppHist): ?>
<div class="card">
  <h3>🏷️ Supplier Price Comparison <span class="muted" style="font-weight:normal;font-size:12px">— આ આઇટમ કોણ કેટલામાં આપે છે</span></h3>
  <div class="table-wrap" style="box-shadow:none">
  <table>
    <thead><tr><th>Supplier</th><th class="num">Last Price</th><th class="num">Lowest Ever</th><th class="num">Bought</th><th>Last Purchase</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($suppHist as $i2 => $sh): ?>
    <tr>
      <td><strong><?= e($sh['supplier']) ?></strong></td>
      <td class="num"><strong>₹<?= money($sh['last_price']) ?></strong></td>
      <td class="num">₹<?= money($sh['min_price']) ?></td>
      <td class="num"><?= (float)$sh['qty'] ?> (<?= (int)$sh['bills'] ?> bill<?= $sh['bills'] > 1 ? 's' : '' ?>)</td>
      <td><?= dmy($sh['last_date']) ?></td>
      <td><?= $i2 === 0 && count($suppHist) > 1 ? '<span class="badge badge-ok">💰 સૌથી સસ્તું</span>' : '' ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endif; ?>

<?php if ($item['serial_tracked']): ?>
<div class="card">
  <h3>🔢 Serial Numbers — warranty at a glance</h3>
  <?php $serials = all('SELECT s.*, p.purchase_date, p.bill_no, p.id pid, sa.sale_date, sa.invoice_no, sa.id sid
                        FROM item_serials s
                        LEFT JOIN purchases p ON p.id = s.purchase_id
                        LEFT JOIN sales sa ON sa.id = s.sale_id
                        WHERE s.item_id = ? ORDER BY s.id DESC LIMIT 300', [$item['id']]); ?>
  <div class="table-wrap" style="box-shadow:none">
  <table class="table-sm">
    <thead><tr><th>Serial</th><th>Status</th><th>Purchased</th><th>Sold</th><th>Warranty till</th></tr></thead>
    <tbody>
    <?php foreach ($serials as $sn):
        $wm = (int)($sn['warranty_months'] ?: $item['warranty_months']);
        $wEnd = ($sn['sale_date'] && $wm > 0) ? date('Y-m-d', strtotime($sn['sale_date'] . " +$wm months")) : null; ?>
      <tr>
        <td><strong><?= e($sn['serial_no']) ?></strong></td>
        <td><span class="badge <?= $sn['status'] === 'in_stock' ? 'badge-ok' : ($sn['status'] === 'sold' ? 'badge-info' : 'badge-bad') ?>"><?= e($sn['status']) ?></span></td>
        <td><?= $sn['purchase_date'] ? dmy($sn['purchase_date']) . ($sn['pid'] ? ' · <a href="purchase_view.php?id=' . $sn['pid'] . '">' . e($sn['bill_no'] ?: '#' . $sn['pid']) . '</a>' : '') : '<span class="muted">-</span>' ?></td>
        <td><?= $sn['sale_date'] ? dmy($sn['sale_date']) . ($sn['sid'] ? ' · <a href="sale_view.php?id=' . $sn['sid'] . '">' . e($sn['invoice_no']) . '</a>' : '') : '<span class="muted">-</span>' ?></td>
        <td><?php if ($wEnd): ?><span style="font-weight:700;color:<?= $wEnd >= today() ? 'var(--ok)' : 'var(--bad)' ?>"><?= dmy($wEnd) ?> <?= $wEnd >= today() ? '✅' : '(expired)' ?></span><?php else: ?><span class="muted">-</span><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$serials): ?><tr><td colspan="5" class="muted">No serial numbers recorded yet.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <h3>Transactions</h3>
  <div class="table-wrap" style="box-shadow:none">
  <table>
    <thead><tr><th>Type</th><th>Invoice/Ref No.</th><th>Name</th><th>Date</th><th class="num">Quantity</th><th class="num">Price/Unit</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($ledger as $le): ?>
    <tr<?= $le['link'] ? ' style="cursor:pointer" onclick="location.href=\'' . e($le['link']) . '\'"' : '' ?>>
      <td><?= e($le['type_label']) ?></td>
      <td><?= $le['link'] ? '<a href="' . e($le['link']) . '">' . e($le['ref_no'] ?: '-') . '</a>' : e($le['ref_no'] ?: '-') ?></td>
      <td><?= e($le['party_name'] ?: '-') ?></td>
      <td><?= $le['date_only'] ? dmy($le['created_at']) : dmyt($le['created_at']) ?></td>
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
