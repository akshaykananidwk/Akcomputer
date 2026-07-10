<?php
// Sales return - stock back in, serials restored
require_once __DIR__ . '/includes/init.php';
require_perm('sales_return.view');
$u = current_user();
$action = get('action', 'list');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'save') {
    require_perm('sales_return.add');
    $sale = row('SELECT * FROM sales WHERE invoice_no = ? OR id = ?', [post('invoice_ref'), (int)post('invoice_ref')]);
    $loc_id = $sale ? (int)$sale['location_id'] : (int)$u['location_id'];
    $item_ids = post('item_id', []);
    $qtys = post('qty', []);
    $prices = post('price', []);
    $serials_in = post('serials_txt', []);

    $rows = [];
    foreach ($item_ids as $i => $iid) {
        $iid = (int)$iid; $qty = (float)($qtys[$i] ?? 0);
        if ($iid && $qty > 0) $rows[] = ['item_id' => $iid, 'qty' => $qty, 'price' => (float)($prices[$i] ?? 0),
            'serials' => trim((string)($serials_in[$i] ?? ''))];
    }
    if (!$rows) { flash('Add items to return.', 'error'); redirect('sales_return.php?action=new'); }
    $total = 0;
    foreach ($rows as $r) $total += $r['qty'] * $r['price'];

    $pdo = db();
    $pdo->beginTransaction();
    try {
        q('INSERT INTO sales_returns (sale_id, party_id, customer_name, customer_mobile, location_id, return_date, total, refund_mode, notes, created_by)
           VALUES (?,?,?,?,?,?,?,?,?,?)',
          [$sale['id'] ?? null, $sale['party_id'] ?? null, post('customer_name') ?: ($sale['customer_name'] ?? ''),
           post('customer_mobile'), $loc_id, post('return_date', today()), $total, post('refund_mode', 'cash'), post('notes'), $u['id']]);
        $rid = insert_id();
        q('UPDATE sales_returns SET return_no = ? WHERE id = ?', [doc_no('SR', $rid), $rid]);
        foreach ($rows as $r) {
            $sns = array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $r['serials']))));
            q('INSERT INTO sales_return_items (return_id, item_id, qty, price, total, serials) VALUES (?,?,?,?,?,?)',
              [$rid, $r['item_id'], $r['qty'], $r['price'], $r['qty'] * $r['price'], $sns ? implode(',', $sns) : null]);
            adjust_stock($r['item_id'], $loc_id, $r['qty'], 'sales_return', $rid);
            foreach ($sns as $sn) {
                q("UPDATE item_serials SET status='in_stock', location_id=?, sale_id=NULL WHERE item_id=? AND serial_no=?",
                  [$loc_id, $r['item_id'], $sn]);
            }
        }
        $pdo->commit();
        log_activity('sales_return', doc_no('SR', $rid));
        flash('Sales return saved, stock restored.');
        redirect('sales_return.php');
    } catch (Exception $ex) {
        $pdo->rollBack();
        flash('Error: ' . $ex->getMessage(), 'error');
        redirect('sales_return.php?action=new');
    }
}

if ($action === 'new') {
    require_perm('sales_return.add');
    $page_title = 'New Sales Return';
    include __DIR__ . '/includes/header.php';
    ?>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="save">
      <input type="hidden" id="location_id" value="<?= $u['location_id'] ?>">
      <div class="card">
        <div class="form-row cols-4">
          <div><label>Original invoice no (optional)</label><input type="text" name="invoice_ref" placeholder="INV-26-00012"></div>
          <div><label>Customer name</label><input type="text" name="customer_name"></div>
          <div><label>Mobile</label><input type="tel" name="customer_mobile"></div>
          <div><label>Date</label><input type="date" name="return_date" value="<?= today() ?>"></div>
        </div>
        <div class="form-row cols-2">
          <div><label>Refund mode</label><select name="refund_mode"><option>cash</option><option>upi</option><option>adjust</option></select></div>
          <div><label>Notes / reason</label><input type="text" name="notes"></div>
        </div>
      </div>
      <div class="card">
        <h3>Returned items</h3>
        <div class="bill-items" id="billItems"></div>
        <button type="button" class="btn btn-outline btn-sm" id="addRowBtn">+ Add item</button>
        <p class="muted mt">Serial-tracked item પાછી આવે તો serial number "Notes" માં લખો — warranty page પરથી serial status handle થાય છે.</p>
      </div>
      <div class="card">
        <div class="bill-totals">
          <div class="t-line t-grand"><span>Refund total</span><span>₹ <span id="t_grand">0.00</span></span></div>
          <div style="display:none"><span id="t_sub"></span><span id="t_tax"></span></div>
        </div>
        <button class="btn btn-block mt" type="submit">Save Return</button>
      </div>
    </form>
    <script>Bill.init({mode: 'sale', serials: false, locSel: 'location_id', gst: false});</script>
    <?php
    include __DIR__ . '/includes/footer.php';
    exit;
}

$rets = all('SELECT sr.*, u2.name staff_name FROM sales_returns sr JOIN users u2 ON u2.id = sr.created_by ORDER BY sr.id DESC LIMIT 300');
$page_title = 'Sales Returns';
include __DIR__ . '/includes/header.php';
?>
<div class="page-actions">
  <?php if (can('sales_return.add')): ?><a class="btn" href="sales_return.php?action=new">+ New Return</a><?php endif; ?>
</div>
<div class="table-wrap">
<table>
  <thead><tr><th>No</th><th>Date</th><th>Customer</th><th class="num">Refund</th><th>Mode</th><th>By</th></tr></thead>
  <tbody><?php foreach ($rets as $r): ?>
    <tr><td><strong><?= e($r['return_no']) ?></strong></td><td><?= dmy($r['return_date']) ?></td>
    <td><?= e($r['customer_name']) ?></td><td class="num">₹<?= money($r['total']) ?></td>
    <td><?= e($r['refund_mode']) ?></td><td><?= e($r['staff_name']) ?></td></tr>
  <?php endforeach; ?></tbody>
</table>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
