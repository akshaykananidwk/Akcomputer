<?php
// Estimates / quotations - no stock effect; convert to bill
require_once __DIR__ . '/includes/init.php';
require_perm('estimates.view');
$u = current_user();
$action = get('action', 'list');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'save') {
    require_perm('estimates.add');
    $company = row('SELECT * FROM companies WHERE id = ?', [(int)post('company_id')]);
    $item_ids = post('item_id', []);
    $qtys = post('qty', []);
    $prices = post('price', []);
    $taxes = post('tax_rate', []);
    $rows = [];
    foreach ($item_ids as $i => $iid) {
        $iid = (int)$iid; $qty = (float)($qtys[$i] ?? 0);
        if ($iid && $qty > 0) $rows[] = ['item_id' => $iid, 'qty' => $qty, 'price' => (float)($prices[$i] ?? 0),
            'tax_rate' => $company['is_gst'] ? (float)($taxes[$i] ?? 0) : 0];
    }
    if (!$rows) { flash('Add at least one item.', 'error'); redirect('estimates.php?action=new'); }
    $subtotal = 0; $tax = 0;
    foreach ($rows as $r) { $line = $r['qty'] * $r['price']; $subtotal += $line; $tax += $line * $r['tax_rate'] / 100; }
    $discount = (float)post('discount');
    $total = $subtotal - $discount + $tax;

    q('INSERT INTO estimates (company_id, party_id, customer_name, customer_mobile, location_id, estimate_date,
       price_type, subtotal, discount, tax_amount, total, notes, created_by, share_token)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
      [$company['id'], (int)post('party_id') ?: null, post('customer_name'), post('customer_mobile'),
       $u['location_id'], post('estimate_date', today()), post('price_type', 'retail'),
       $subtotal, $discount, $tax, $total, post('notes'), $u['id'], share_token()]);
    $eid = insert_id();
    q('UPDATE estimates SET estimate_no = ? WHERE id = ?', [doc_no('EST', $eid), $eid]);
    foreach ($rows as $r) {
        q('INSERT INTO estimate_items (estimate_id, item_id, qty, price, tax_rate, total) VALUES (?,?,?,?,?,?)',
          [$eid, $r['item_id'], $r['qty'], $r['price'], $r['tax_rate'], $r['qty'] * $r['price']]);
    }
    log_activity('estimate_add', doc_no('EST', $eid));
    flash('Estimate saved.');
    redirect('estimates.php?action=view&id=' . $eid);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'whatsapp') {
    $est = row('SELECT e.*, c.name company_name FROM estimates e JOIN companies c ON c.id = e.company_id WHERE e.id = ?', [(int)post('id')]);
    $mobile = post('mobile') ?: $est['customer_mobile'];
    if ($est && $mobile) {
        $eitems = all("SELECT ei.*, COALESCE(i.name, '(deleted item)') name FROM estimate_items ei LEFT JOIN items i ON i.id = ei.item_id WHERE ei.estimate_id = ?", [$est['id']]);
        $itemsTxt = '';
        foreach ($eitems as $it) $itemsTxt .= '- ' . $it['name'] . ' x' . (float)$it['qty'] . ' = ₹' . money($it['total']) . "\n";
        $msg = wa_template('estimate', ['firm' => $est['company_name'], 'estimate_no' => $est['estimate_no'],
                                        'items' => trim($itemsTxt), 'total' => money($est['total'])]);
        send_whatsapp($mobile, $msg) ? flash('Estimate sent on WhatsApp.') : flash('WhatsApp send failed. ' . whatsapp_last_error(), 'error');
    }
    redirect('estimates.php?action=view&id=' . (int)post('id'));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'cancel' && can('estimates.edit')) {
    q("UPDATE estimates SET status='cancelled' WHERE id=? AND status='open'", [(int)post('id')]);
    flash('Estimate cancelled.');
    redirect('estimates.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'delete') {
    require_perm('estimates.delete');
    $eid = (int)post('id');
    $est = row('SELECT * FROM estimates WHERE id = ?', [$eid]);
    if ($est) {
        q('DELETE FROM estimate_items WHERE estimate_id = ?', [$eid]);
        q('DELETE FROM estimates WHERE id = ?', [$eid]);
        log_activity('estimate_delete', $est['estimate_no']);
        flash('Estimate ' . $est['estimate_no'] . ' deleted.');
    }
    redirect('estimates.php');
}

$companies = all('SELECT * FROM companies WHERE is_active = 1 ORDER BY id');

if ($action === 'new') {
    require_perm('estimates.add');
    $parties = all("SELECT id, name, mobile FROM parties WHERE is_active = 1 ORDER BY name");
    $page_title = 'New Estimate';
    include __DIR__ . '/includes/header.php';
    ?>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="save">
      <input type="hidden" name="location_id" id="location_id" value="<?= $u['location_id'] ?>">
      <div class="card">
        <div class="form-row cols-4">
          <div><label>Firm</label>
            <select name="company_id" id="company_id">
              <?php foreach ($companies as $c): ?><option value="<?= $c['id'] ?>" data-gst="<?= $c['is_gst'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?>
            </select></div>
          <div><label>Date</label><input type="date" name="estimate_date" value="<?= today() ?>"></div>
          <div><label>Price type</label><select name="price_type" id="price_type"><option value="retail">Retail</option><option value="b2b">B2B</option></select></div>
          <div><label>Party</label>
            <select name="party_id" onchange="var o=this.options[this.selectedIndex];if(this.value){document.getElementById('cn').value=o.textContent.trim();document.getElementById('cm').value=o.dataset.m||''}">
              <option value="">-- walk-in --</option>
              <?php foreach ($parties as $p): ?><option value="<?= $p['id'] ?>" data-m="<?= e($p['mobile']) ?>"><?= e($p['name']) ?></option><?php endforeach; ?>
            </select></div>
        </div>
        <div class="form-row cols-2">
          <div><label>Customer name</label><input type="text" name="customer_name" id="cn"></div>
          <div><label>Mobile</label><input type="tel" name="customer_mobile" id="cm"></div>
        </div>
      </div>
      <div class="card">
        <h3>Items</h3>
        <div class="bill-items" id="billItems"></div>
        <button type="button" class="btn btn-outline btn-sm" id="addRowBtn">+ Add item</button>
      </div>
      <div class="card">
        <div class="form-row cols-2">
          <div><label>Discount (₹)</label><input type="number" step="any" name="discount" id="discount" value="0"></div>
          <div><label>Notes</label><input type="text" name="notes"></div>
        </div>
        <div class="bill-totals">
          <div class="t-line"><span>Subtotal</span><span>₹ <span id="t_sub">0.00</span></span></div>
          <div class="t-line"><span>GST</span><span>₹ <span id="t_tax">0.00</span></span></div>
          <div class="t-line t-grand"><span>Total</span><span>₹ <span id="t_grand">0.00</span></span></div>
        </div>
        <button class="btn btn-block mt" type="submit">💾 Save Estimate</button>
      </div>
    </form>
    <script>
      Bill.init({mode: 'sale', serials: false, locSel: 'location_id', gst: document.querySelector('#company_id option:checked').dataset.gst == 1});
      document.getElementById('company_id').addEventListener('change', function () {
        Bill.cfg.gst = this.options[this.selectedIndex].dataset.gst == 1; Bill.totals();
      });
    </script>
    <?php
    include __DIR__ . '/includes/footer.php';
    exit;
}

if ($action === 'view') {
    $est = row('SELECT e.*, c.name company_name FROM estimates e JOIN companies c ON c.id = e.company_id WHERE e.id = ?', [(int)get('id')]);
    if (!$est) die('Not found');
    $eitems = all("SELECT ei.*, COALESCE(i.name, '(deleted item)') name, i.unit FROM estimate_items ei LEFT JOIN items i ON i.id = ei.item_id WHERE ei.estimate_id = ?", [$est['id']]);
    $page_title = 'Estimate ' . $est['estimate_no'];
    include __DIR__ . '/includes/header.php';
    ?>
    <div class="page-actions no-print">
      <button class="btn" onclick="window.print()">🖨️ Print</button>
      <form method="post" style="display:inline-flex;gap:6px">
        <?= csrf_field() ?><input type="hidden" name="do" value="whatsapp"><input type="hidden" name="id" value="<?= $est['id'] ?>">
        <input type="tel" name="mobile" value="<?= e($est['customer_mobile']) ?>" placeholder="WhatsApp no." style="width:150px">
        <button class="btn btn-wa" type="submit">📲 WhatsApp</button>
      </form>
      <?php if ($est['status'] === 'open' && can('sales.add')): ?>
        <a class="btn btn-success" href="sales.php?action=new&from_estimate=<?= $est['id'] ?>">→ Convert to Bill</a>
      <?php endif; ?>
      <?php if ($est['status'] === 'open' && can('estimates.edit')): ?>
      <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="do" value="cancel"><input type="hidden" name="id" value="<?= $est['id'] ?>">
        <button class="btn btn-danger" type="submit">Cancel</button></form>
      <?php endif; ?>
      <a class="btn btn-outline" href="estimates.php">← Back</a>
    </div>
    <div class="inv-paper card <?= invoice_theme_class() ?>">
      <div class="inv-head">
        <div class="inv-firm"><h1><?= e($est['company_name']) ?></h1></div>
        <div class="inv-meta"><div class="inv-title">ESTIMATE</div><div><strong><?= e($est['estimate_no']) ?></strong></div>
        <div><?= dmy($est['estimate_date']) ?> <?= status_badge($est['status']) ?></div></div>
      </div>
      <p><strong>To:</strong> <?= e($est['customer_name'] ?: 'Walk-in') ?> <?= e($est['customer_mobile']) ?></p>
      <div class="table-wrap" style="box-shadow:none">
        <table class="inv-table">
          <thead><tr><th>#</th><th>Item</th><th class="num">Qty</th><th class="num">Rate</th><th class="num">Amount</th></tr></thead>
          <tbody><?php foreach ($eitems as $n => $it): ?>
            <tr><td><?= $n + 1 ?></td><td><?= e($it['name']) ?></td><td class="num"><?= (float)$it['qty'] ?> <?= e($it['unit']) ?></td>
            <td class="num"><?= money($it['price']) ?></td><td class="num"><?= money($it['total']) ?></td></tr>
          <?php endforeach; ?></tbody>
        </table>
      </div>
      <div class="bill-totals">
        <div class="t-line"><span>Subtotal</span><span>₹<?= money($est['subtotal']) ?></span></div>
        <?php if ($est['discount'] > 0): ?><div class="t-line"><span>Discount</span><span>- ₹<?= money($est['discount']) ?></span></div><?php endif; ?>
        <?php if ($est['tax_amount'] > 0): ?><div class="t-line"><span>GST</span><span>₹<?= money($est['tax_amount']) ?></span></div><?php endif; ?>
        <div class="t-line t-grand"><span>Total</span><span>₹<?= money($est['total']) ?></span></div>
      </div>
    </div>
    <?php
    include __DIR__ . '/includes/footer.php';
    exit;
}

// list
list($scope, $params) = own_scope('estimates', 'e.created_by');
$ests = all("SELECT e.*, c.name company_name FROM estimates e JOIN companies c ON c.id = e.company_id
             WHERE 1=1 $scope ORDER BY e.id DESC LIMIT 300", $params);
$page_title = 'Estimates';
include __DIR__ . '/includes/header.php';
?>
<div class="page-actions">
  <?php if (can('estimates.add')): ?><a class="btn" href="estimates.php?action=new">+ New Estimate</a><?php endif; ?>
</div>
<div class="table-wrap">
<table>
  <thead><tr><th>No</th><th>Date</th><th>Customer</th><th>Firm</th><th class="num">Total</th><th>Status</th><th></th></tr></thead>
  <tbody><?php foreach ($ests as $es): ?>
    <tr>
      <td><strong><?= e($es['estimate_no']) ?></strong></td>
      <td><?= dmy($es['estimate_date']) ?></td>
      <td><?= e($es['customer_name'] ?: 'Walk-in') ?></td>
      <td><?= e($es['company_name']) ?></td>
      <td class="num">₹<?= money($es['total']) ?></td>
      <td><?= status_badge($es['status']) ?></td>
      <td style="white-space:nowrap">
        <a class="btn btn-sm btn-outline" href="estimates.php?action=view&id=<?= $es['id'] ?>">View</a>
        <?php if (can('estimates.delete')): ?>
        <form method="post" style="display:inline" onsubmit="return confirm('Estimate delete કરવો?')">
          <?= csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= $es['id'] ?>">
          <button class="btn btn-sm btn-danger" type="submit">✕</button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?></tbody>
</table>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
