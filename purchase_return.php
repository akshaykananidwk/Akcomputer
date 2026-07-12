<?php
// Purchase return - stock out back to supplier
require_once __DIR__ . '/includes/init.php';
require_perm('purchase_return.view');
$u = current_user();
$action = get('action', 'list');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'save') {
    require_perm('purchase_return.add');
    $party_id = (int)post('party_id');
    $loc_id = (int)post('location_id') ?: $u['location_id'];
    $item_ids = post('item_id', []);
    $qtys = post('qty', []);
    $prices = post('price', []);
    $rows = [];
    foreach ($item_ids as $i => $iid) {
        $iid = (int)$iid; $qty = (float)($qtys[$i] ?? 0);
        if ($iid && $qty > 0) $rows[] = ['item_id' => $iid, 'qty' => $qty, 'price' => (float)($prices[$i] ?? 0)];
    }
    if (!$rows || !$party_id) { flash('Supplier and items required.', 'error'); redirect('purchase_return.php?action=new'); }
    $total = 0;
    foreach ($rows as $r) $total += $r['qty'] * $r['price'];

    $pdo = db();
    $pdo->beginTransaction();
    try {
        q('INSERT INTO purchase_returns (party_id, location_id, return_date, total, notes, created_by) VALUES (?,?,?,?,?,?)',
          [$party_id, $loc_id, post('return_date', today()), $total, post('notes'), $u['id']]);
        $rid = insert_id();
        q('UPDATE purchase_returns SET return_no = ? WHERE id = ?', [doc_no('PR', $rid), $rid]);
        foreach ($rows as $r) {
            $item = row('SELECT name FROM items WHERE id = ?', [$r['item_id']]);
            if (stock_qty($r['item_id'], $loc_id) < $r['qty']) throw new Exception("Not enough stock of {$item['name']} to return.");
            q('INSERT INTO purchase_return_items (return_id, item_id, qty, price, total) VALUES (?,?,?,?,?)',
              [$rid, $r['item_id'], $r['qty'], $r['price'], $r['qty'] * $r['price']]);
            adjust_stock($r['item_id'], $loc_id, -$r['qty'], 'purchase_return', $rid);
        }
        // serial-tracked units returned to supplier are updated on the serial itself
        foreach (array_filter(array_map('trim', preg_split('/[\r\n,]+/', post('serials')))) as $sn) {
            q("UPDATE item_serials SET status='returned_supplier', location_id=NULL WHERE serial_no=? AND status='in_stock'", [$sn]);
        }
        $pdo->commit();
        log_activity('purchase_return', doc_no('PR', $rid));
        flash('Purchase return saved, stock deducted.');
        redirect('purchase_return.php');
    } catch (Exception $ex) {
        $pdo->rollBack();
        flash('Error: ' . $ex->getMessage(), 'error');
        redirect('purchase_return.php?action=new');
    }
}

if ($action === 'new') {
    require_perm('purchase_return.add');
    $suppliers = all("SELECT id, name FROM parties WHERE is_active = 1 AND type IN ('supplier','both') ORDER BY name");
    $locations = all('SELECT * FROM locations WHERE is_active = 1 ORDER BY name');
    $page_title = 'New Purchase Return';
    include __DIR__ . '/includes/header.php';
    ?>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="save">
      <div class="card">
        <div class="form-row cols-3">
          <div><label>Supplier *</label>
            <select name="party_id" required><option value="">-- select --</option>
            <?php foreach ($suppliers as $s): ?><option value="<?= $s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?>
            </select></div>
          <div><label>From location</label>
            <select name="location_id" id="location_id">
              <?php foreach ($locations as $l): ?><option value="<?= $l['id'] ?>" <?= $l['id'] == $u['location_id'] ? 'selected' : '' ?>><?= e($l['name']) ?></option><?php endforeach; ?>
            </select></div>
          <div><label>Date</label><input type="date" name="return_date" value="<?= today() ?>"></div>
        </div>
        <div class="field"><label>Serial numbers being returned (comma / new line, optional)</label>
          <textarea name="serials" rows="2"></textarea></div>
        <div class="field"><label>Notes / reason</label><input type="text" name="notes"></div>
      </div>
      <div class="card">
        <h3>Items</h3>
        <div class="bill-items" id="billItems"></div>
        <button type="button" class="btn btn-outline btn-sm" id="addRowBtn">+ Add item</button>
        <div class="bill-totals mt">
          <div class="t-line t-grand"><span>Return total</span><span>₹ <span id="t_grand">0.00</span></span></div>
          <div style="display:none"><span id="t_sub"></span><span id="t_tax"></span></div>
        </div>
        <button class="btn btn-block mt" type="submit">Save Return</button>
      </div>
    </form>
    <script>Bill.init({mode: 'purchase', serials: false, locSel: 'location_id', gst: false});</script>
    <?php
    include __DIR__ . '/includes/footer.php';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'delete') {
    require_perm('purchase_return.delete');
    $rid = (int)post('id');
    $ret = row('SELECT * FROM purchase_returns WHERE id = ?', [$rid]);
    if ($ret) {
        $ritems = all('SELECT * FROM purchase_return_items WHERE return_id = ?', [$rid]);
        $allowNeg = setting('allow_negative_stock', '1') === '1';
        if (!$allowNeg) {
            foreach ($ritems as $ri) {
                $item = row('SELECT name FROM items WHERE id = ?', [$ri['item_id']]);
                if (stock_qty($ri['item_id'], $ret['location_id']) + (float)$ri['qty'] < 0) {
                    flash("Delete કરવાથી {$item['name']} નો stock negative થાય છે, અટકાવ્યું.", 'error');
                    redirect('purchase_return.php');
                }
            }
        }
        $pdo = db();
        $pdo->beginTransaction();
        foreach ($ritems as $ri) adjust_stock($ri['item_id'], $ret['location_id'], (float)$ri['qty'], 'purchase_return_delete', $rid);
        q('DELETE FROM purchase_return_items WHERE return_id = ?', [$rid]);
        q('DELETE FROM purchase_returns WHERE id = ?', [$rid]);
        $pdo->commit();
        log_activity('purchase_return_delete', $ret['return_no']);
        flash('Return ' . $ret['return_no'] . ' deleted, stock reversed. (Serial number status manually ચકાસી લેજો.)');
    }
    redirect('purchase_return.php');
}

$rets = all('SELECT pr.*, p.name party_name FROM purchase_returns pr JOIN parties p ON p.id = pr.party_id ORDER BY pr.id DESC LIMIT 300');
$page_title = 'Purchase Returns';
include __DIR__ . '/includes/header.php';
?>
<div class="page-actions">
  <?php if (can('purchase_return.add')): ?><a class="btn" href="purchase_return.php?action=new">+ New Return</a><?php endif; ?>
</div>
<div class="table-wrap">
<table>
  <thead><tr><th>No</th><th>Date</th><th>Supplier</th><th class="num">Total</th><th>Notes</th><th></th></tr></thead>
  <tbody><?php foreach ($rets as $r): ?>
    <tr><td><strong><?= e($r['return_no']) ?></strong></td><td><?= dmy($r['return_date']) ?></td>
    <td><?= e($r['party_name']) ?></td><td class="num">₹<?= money($r['total']) ?></td><td><?= e($r['notes']) ?></td>
    <td><?php if (can('purchase_return.delete')): ?>
      <form method="post" onsubmit="return confirm('Return delete કરવો? Stock પાછો adjust થશે.')"><?= csrf_field() ?>
      <input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= $r['id'] ?>">
      <button class="btn btn-sm btn-danger" type="submit">✕</button></form>
    <?php endif; ?></td></tr>
  <?php endforeach; ?></tbody>
</table>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
