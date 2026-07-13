<?php
// Delivery challans - goods movement document, convert to bill later
require_once __DIR__ . '/includes/init.php';
require_perm('challans.view');
$u = current_user();
$action = get('action', 'list');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'save') {
    require_perm('challans.add');
    $item_ids = post('item_id', []);
    $qtys = post('qty', []);
    $prices = post('price', []);
    $rows = [];
    foreach ($item_ids as $i => $iid) {
        $iid = (int)$iid; $qty = (float)($qtys[$i] ?? 0);
        if ($iid && $qty > 0) $rows[] = ['item_id' => $iid, 'qty' => $qty, 'price' => (float)($prices[$i] ?? 0)];
    }
    if (!$rows) { flash('Add at least one item.', 'error'); redirect('challans.php?action=new'); }
    q('INSERT INTO challans (company_id, party_id, customer_name, customer_mobile, address, location_id, challan_date, notes, created_by)
       VALUES (?,?,?,?,?,?,?,?,?)',
      [(int)post('company_id', 1), (int)post('party_id') ?: null, post('customer_name'), post('customer_mobile'),
       post('address'), $u['location_id'], post('challan_date', today()), post('notes'), $u['id']]);
    $cid = insert_id();
    q('UPDATE challans SET challan_no = ? WHERE id = ?', [doc_no('DC', $cid), $cid]);
    foreach ($rows as $r) {
        q('INSERT INTO challan_items (challan_id, item_id, qty, price, total) VALUES (?,?,?,?,?)',
          [$cid, $r['item_id'], $r['qty'], $r['price'], $r['qty'] * $r['price']]);
    }
    log_activity('challan_add', doc_no('DC', $cid));
    flash('Delivery challan saved.');
    redirect('challans.php?action=view&id=' . $cid);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'cancel' && can('challans.edit')) {
    q("UPDATE challans SET status='cancelled' WHERE id=? AND status='open'", [(int)post('id')]);
    flash('Challan cancelled.');
    redirect('challans.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'delete') {
    require_perm('challans.delete');
    $cid = (int)post('id');
    $ch = row('SELECT * FROM challans WHERE id = ?', [$cid]);
    if ($ch) {
        q('DELETE FROM challan_items WHERE challan_id = ?', [$cid]);
        q('DELETE FROM challans WHERE id = ?', [$cid]);
        log_activity('challan_delete', $ch['challan_no']);
        flash('Challan ' . $ch['challan_no'] . ' deleted.');
    }
    redirect('challans.php');
}

$companies = all('SELECT * FROM companies WHERE is_active = 1 ORDER BY id');

if ($action === 'new') {
    require_perm('challans.add');
    $parties = all("SELECT id, name, mobile, address FROM parties WHERE is_active = 1 ORDER BY name");
    $page_title = 'New Delivery Challan';
    include __DIR__ . '/includes/header.php';
    ?>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="save">
      <input type="hidden" id="location_id" value="<?= $u['location_id'] ?>">
      <div class="card">
        <div class="form-row cols-3">
          <div><label>Firm</label>
            <select name="company_id"><?php foreach ($companies as $c): ?><option value="<?= $c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
          <div><label>Date</label><input type="date" name="challan_date" value="<?= today() ?>"></div>
          <div><label>Party</label>
            <select name="party_id" onchange="var o=this.options[this.selectedIndex];if(this.value){document.getElementById('dn').value=o.textContent.trim();document.getElementById('dm').value=o.dataset.m||'';document.getElementById('da').value=o.dataset.a||''}">
              <option value="">-- manual --</option>
              <?php foreach ($parties as $p): ?><option value="<?= $p['id'] ?>" data-m="<?= e($p['mobile']) ?>" data-a="<?= e($p['address']) ?>"><?= e($p['name']) ?></option><?php endforeach; ?>
            </select></div>
        </div>
        <div class="form-row cols-3">
          <div><label>Customer name</label><input type="text" name="customer_name" id="dn"></div>
          <div><label>Mobile</label><input type="tel" name="customer_mobile" id="dm"></div>
          <div><label>Delivery address</label><input type="text" name="address" id="da"></div>
        </div>
        <div class="field"><label>Notes</label><input type="text" name="notes"></div>
      </div>
      <div class="card">
        <h3>Items</h3>
        <div class="bill-items" id="billItems"></div>
        <button type="button" class="btn btn-outline btn-sm" id="addRowBtn">+ Add item</button>
        <div class="bill-totals mt">
          <div class="t-line t-grand"><span>Value</span><span>₹ <span id="t_grand">0.00</span></span></div>
          <div style="display:none"><span id="t_sub"></span><span id="t_tax"></span></div>
        </div>
        <button class="btn btn-block mt" type="submit">💾 Save Challan</button>
      </div>
    </form>
    <script>Bill.init({mode: 'sale', serials: false, locSel: 'location_id', gst: false});</script>
    <?php
    include __DIR__ . '/includes/footer.php';
    exit;
}

if ($action === 'view') {
    $c = row('SELECT c.*, co.name company_name, co.address co_address, co.phone co_phone FROM challans c
              JOIN companies co ON co.id = c.company_id WHERE c.id = ?', [(int)get('id')]);
    if (!$c) die('Not found');
    $citems = all("SELECT ci.*, COALESCE(i.name, '(deleted item)') name, i.unit FROM challan_items ci LEFT JOIN items i ON i.id = ci.item_id WHERE ci.challan_id = ?", [$c['id']]);
    $page_title = 'Challan ' . $c['challan_no'];
    include __DIR__ . '/includes/header.php';
    ?>
    <div class="page-actions no-print">
      <button class="btn" onclick="window.print()">🖨️ Print</button>
      <?php if ($c['status'] === 'open' && can('sales.add')): ?>
        <a class="btn btn-success" href="sales.php?action=new&from_challan=<?= $c['id'] ?>">→ Convert to Bill</a>
      <?php endif; ?>
      <?php if ($c['status'] === 'open' && can('challans.edit')): ?>
      <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="do" value="cancel"><input type="hidden" name="id" value="<?= $c['id'] ?>">
        <button class="btn btn-danger" type="submit">Cancel</button></form>
      <?php endif; ?>
      <a class="btn btn-outline" href="challans.php">← Back</a>
    </div>
    <div class="inv-paper card <?= invoice_theme_class() ?>">
      <div class="inv-head">
        <div class="inv-firm"><h1><?= e($c['company_name']) ?></h1>
          <div class="muted"><?= e($c['co_address']) ?> <?= $c['co_phone'] ? '· Ph: ' . e($c['co_phone']) : '' ?></div></div>
        <div class="inv-meta"><div class="inv-title">DELIVERY CHALLAN</div>
          <div><strong><?= e($c['challan_no']) ?></strong></div>
          <div><?= dmy($c['challan_date']) ?> <?= status_badge($c['status']) ?></div></div>
      </div>
      <p><strong>Deliver to:</strong> <?= e($c['customer_name']) ?> <?= e($c['customer_mobile']) ?><br><?= e($c['address']) ?></p>
      <div class="table-wrap" style="box-shadow:none">
        <table class="inv-table">
          <thead><tr><th>#</th><th>Item</th><th class="num">Qty</th><th class="num">Value</th></tr></thead>
          <tbody><?php foreach ($citems as $n => $it): ?>
            <tr><td><?= $n + 1 ?></td><td><?= e($it['name']) ?></td><td class="num"><?= (float)$it['qty'] ?> <?= e($it['unit']) ?></td><td class="num"><?= money($it['total']) ?></td></tr>
          <?php endforeach; ?></tbody>
        </table>
      </div>
      <p class="mt">Receiver's sign: _______________</p>
      <?= $c['notes'] ? '<p class="muted">' . e($c['notes']) . '</p>' : '' ?>
    </div>
    <?php
    include __DIR__ . '/includes/footer.php';
    exit;
}

$rows = all('SELECT c.* FROM challans c ORDER BY c.id DESC LIMIT 300');
$page_title = 'Delivery Challans';
include __DIR__ . '/includes/header.php';
?>
<div class="page-actions">
  <?php if (can('challans.add')): ?><a class="btn" href="challans.php?action=new">+ New Challan</a><?php endif; ?>
</div>
<div class="table-wrap">
<table>
  <thead><tr><th>No</th><th>Date</th><th>Customer</th><th>Status</th><th></th></tr></thead>
  <tbody><?php foreach ($rows as $c): ?>
    <tr>
      <td><strong><?= e($c['challan_no']) ?></strong></td>
      <td><?= dmy($c['challan_date']) ?></td>
      <td><?= e($c['customer_name']) ?></td>
      <td><?= status_badge($c['status']) ?></td>
      <td style="white-space:nowrap">
        <a class="btn btn-sm btn-outline" href="challans.php?action=view&id=<?= $c['id'] ?>">View</a>
        <?php if (can('challans.delete')): ?>
        <form method="post" style="display:inline" onsubmit="return confirm('Delete this challan?')">
          <?= csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= $c['id'] ?>">
          <button class="btn btn-sm btn-danger" type="submit">✕</button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="5" class="muted">No challans yet.</td></tr><?php endif; ?></tbody>
</table>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
