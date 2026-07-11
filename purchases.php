<?php
// Purchases - stock in, serial number entry, credit terms
require_once __DIR__ . '/includes/init.php';
require_perm('purchases.view');
$u = current_user();
$action = get('action', 'list');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'save') {
    require_perm('purchases.add');
    $loc_id = (int)post('location_id') ?: $u['location_id'];
    $party_id = (int)post('party_id');
    $item_ids = post('item_id', []);
    $qtys = post('qty', []);
    $prices = post('price', []);
    $taxes = post('tax_rate', []);
    $serials_in = post('serials', []);

    $rows = [];
    $si = 0;
    foreach ($item_ids as $i => $iid) {
        $iid = (int)$iid;
        $qty = (float)($qtys[$i] ?? 0);
        if (!$iid || $qty <= 0) { continue; }
        $rows[] = ['item_id' => $iid, 'qty' => $qty, 'price' => (float)($prices[$i] ?? 0),
                   'tax_rate' => (float)($taxes[$i] ?? 0), 'total' => $qty * (float)($prices[$i] ?? 0),
                   'serials' => trim((string)($serials_in[$i] ?? ''))];
    }
    if (!$rows || !$party_id) { flash('Party and at least one item required.', 'error'); redirect('purchases.php?action=new'); }

    $subtotal = array_sum(array_column($rows, 'total'));
    $tax = 0;
    foreach ($rows as $r) $tax += $r['total'] * $r['tax_rate'] / 100;
    $discType = post('discount_type') === 'percent' ? 'percent' : 'amount';
    $discRaw = (float)post('discount_val');
    $discount = $discType === 'percent' ? round($subtotal * $discRaw / 100, 2) : min($discRaw, $subtotal);
    $discPct = $discType === 'percent' ? $discRaw : 0;
    $total = $subtotal - $discount + $tax;
    $paid = min((float)post('paid'), $total);
    $credit_days = (int)post('credit_days');
    $pdate = post('purchase_date', today());
    $bankAccId = (int)post('bank_account_id') ?: null;
    $pmId = (int)post('payment_method_id') ?: null;

    $pdo = db();
    $pdo->beginTransaction();
    try {
        q('INSERT INTO purchases (company_id, bill_no, party_id, location_id, purchase_date, credit_days, due_date,
           subtotal, discount, discount_type, discount_pct, tax_amount, total, paid, payment_method_id, bank_account_id, status, notes, created_by)
           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
          [(int)post('company_id', 1), post('bill_no'), $party_id, $loc_id, $pdate, $credit_days,
           $credit_days ? date('Y-m-d', strtotime("$pdate +$credit_days days")) : null,
           $subtotal, $discount, $discType, $discPct, $tax, $total, $paid, $pmId, $bankAccId, payment_status($total, $paid), post('notes'), $u['id']]);
        $pid = insert_id();

        foreach ($rows as $r) {
            $item = row('SELECT * FROM items WHERE id = ?', [$r['item_id']]);
            q('INSERT INTO purchase_items (purchase_id, item_id, qty, price, tax_rate, total) VALUES (?,?,?,?,?,?)',
              [$pid, $r['item_id'], $r['qty'], $r['price'], $r['tax_rate'], $r['total']]);
            adjust_stock($r['item_id'], $loc_id, $r['qty'], 'purchase', $pid, post('bill_no'));

            if ($item['serial_tracked']) {
                $sns = array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $r['serials']))));
                if (count($sns) != $r['qty']) throw new Exception("Enter {$r['qty']} serial number(s) for {$item['name']} (one per line).");
                foreach ($sns as $sn) {
                    q("INSERT INTO item_serials (item_id, serial_no, location_id, status, purchase_id, warranty_months)
                       VALUES (?,?,?,'in_stock',?,?)", [$r['item_id'], $sn, $loc_id, $pid, $item['warranty_months']]);
                }
            }
        }
        // update item purchase price to latest
        foreach ($rows as $r) {
            q('UPDATE items SET purchase_price = ? WHERE id = ?', [$r['price'], $r['item_id']]);
            q('UPDATE items SET selling_price = ROUND(purchase_price * (1 + margin_pct / 100), 2)
               WHERE id = ? AND margin_pct > 0', [$r['item_id']]);
        }

        if ($paid > 0) {
            q('INSERT INTO payments (party_id, direction, amount, mode, bank_account_id, payment_method_id, ref_type, ref_id, pay_date, notes, created_by)
               VALUES (?,?,?,?,?,?,?,?,?,?,?)',
              [$party_id, 'out', $paid, post('payment_mode', 'cash'), $bankAccId, $pmId, 'purchase', $pid, $pdate, 'Against purchase bill ' . post('bill_no'), $u['id']]);
        }
        $pdo->commit();
        log_activity('purchase_add', "#$pid total $total");
        flash('Purchase saved, stock updated.');
        redirect('purchase_view.php?id=' . $pid);
    } catch (Exception $ex) {
        $pdo->rollBack();
        flash('Error: ' . $ex->getMessage(), 'error');
        redirect('purchases.php?action=new');
    }
}

// ---------- delete / cancel ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'delete') {
    require_perm('purchases.delete');
    $pid = (int)post('id');
    $purchase = row('SELECT * FROM purchases WHERE id = ?', [$pid]);
    $cancelOnly = post('mode') === 'cancel';
    if ($purchase) {
        $moved = (int)val("SELECT COUNT(*) FROM item_serials WHERE purchase_id = ? AND status <> 'in_stock'", [$pid]);
        if (!$purchase['is_cancelled'] && $moved > 0) {
            flash('આ bill ના કેટલાક serial numbers પહેલેથી sold/issued/returned થઈ ગયા છે, એટલે ' . ($cancelOnly ? 'cancel' : 'delete') . ' કરી શકાય એમ નથી.', 'error');
            redirect('purchase_view.php?id=' . $pid);
        }
        $pdo = db();
        $pdo->beginTransaction();
        foreach ($purchase['is_cancelled'] ? [] : all('SELECT * FROM purchase_items WHERE purchase_id = ?', [$pid]) as $pi) {
            adjust_stock($pi['item_id'], $purchase['location_id'], -(float)$pi['qty'], 'purchase_delete', $pid);
        }
        if (!$purchase['is_cancelled']) {
            q('DELETE FROM item_serials WHERE purchase_id = ?', [$pid]);
        }
        q("DELETE FROM payments WHERE ref_type = 'purchase' AND ref_id = ?", [$pid]);
        if ($cancelOnly) {
            q('UPDATE purchases SET is_cancelled = 1, paid = 0, status = ? WHERE id = ?', ['due', $pid]);
            log_activity('purchase_cancel', $purchase['bill_no'] ?: "#$pid");
            flash('Purchase bill CANCELLED - stock પાછો ઓછો થયો, record સચવાયો.');
        } else {
            q('DELETE FROM purchase_items WHERE purchase_id = ?', [$pid]);
            q('DELETE FROM purchases WHERE id = ?', [$pid]);
            log_activity('purchase_delete', $purchase['bill_no'] ?: "#$pid");
            flash('Purchase bill deleted and stock reversed.');
        }
        $pdo->commit();
    }
    redirect('purchases.php');
}

$locations = all('SELECT * FROM locations WHERE is_active = 1 ORDER BY name');
$terms = all('SELECT * FROM credit_terms ORDER BY days');
$companies = all('SELECT * FROM companies WHERE is_active = 1 ORDER BY id');

if ($action === 'new') {
    require_perm('purchases.add');
    $parties = all("SELECT id, name, credit_days FROM parties WHERE is_active = 1 AND type IN ('supplier','both') ORDER BY name");
    $page_title = 'New Purchase';
    include __DIR__ . '/includes/header.php';
    ?>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="save">
      <div class="card">
        <div class="form-row cols-4">
          <div><label>Supplier party *</label>
            <select name="party_id" id="party_id" required>
              <option value="">-- select --</option>
              <?php foreach ($parties as $p): ?>
              <option value="<?= $p['id'] ?>" data-credit="<?= $p['credit_days'] ?>"><?= e($p['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <p class="muted mt"><a href="#" onclick="document.getElementById('qpModal').style.display='block';return false">+ Add new party</a></p>
          </div>
          <div><label>Firm / Company</label>
            <select name="company_id"><?php foreach ($companies as $c): ?><option value="<?= $c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
          <div><label>Supplier bill no.</label><input type="text" name="bill_no"></div>
          <div><label>Date</label><input type="date" name="purchase_date" value="<?= today() ?>"></div>
        </div>
        <div class="form-row cols-2">
          <div><label>Stock into location</label>
            <select name="location_id" id="location_id">
              <?php foreach ($locations as $l): ?>
              <option value="<?= $l['id'] ?>" <?= $l['id'] == $u['location_id'] ? 'selected' : '' ?>><?= e($l['name']) ?> (<?= e($l['city']) ?>)</option>
              <?php endforeach; ?>
            </select></div>
          <div><label>Credit term</label>
            <select name="credit_days" id="credit_days">
              <?php foreach ($terms as $t): ?><option value="<?= $t['days'] ?>"><?= e($t['label']) ?></option><?php endforeach; ?>
            </select></div>
        </div>
      </div>

      <div class="card">
        <h3>Items</h3>
        <div class="bill-items" id="billItems"></div>
        <button type="button" class="btn btn-outline btn-sm" id="addRowBtn">+ Add item</button>
        <p class="muted mt">Serial-tracked item select કરો એટલે serial numbers લખવાનું box આવશે (દરેક line પર એક).</p>
      </div>

      <div class="card">
        <?php $pms = active_payment_methods(); $banks = all('SELECT * FROM bank_accounts WHERE is_active = 1 ORDER BY is_default DESC, account_name'); ?>
        <div class="form-row cols-4">
          <div><label>Discount</label>
            <div style="display:flex;gap:6px">
              <input type="number" step="any" id="discount_val" name="discount_val" value="0" oninput="Bill.totals()" style="flex:1">
              <div class="cc-toggle" style="flex-shrink:0">
                <button type="button" id="discAmt" class="on-cash" onclick="setDiscType('amount')">₹</button>
                <button type="button" id="discPct" onclick="setDiscType('percent')">%</button>
              </div>
            </div>
            <input type="hidden" name="discount" id="discount" value="0">
            <input type="hidden" name="discount_type" id="discount_type" value="amount">
          </div>
          <div><label>Paid now (₹)</label><input type="number" step="any" name="paid" id="paid" value="0"></div>
          <div><label>Payment mode</label>
            <select name="payment_mode" id="payment_mode" onchange="pmChange()">
              <?php foreach ($pms as $pm): if ($pm['code'] === 'credit') continue; ?><option value="<?= e($pm['code']) ?>" data-type="<?= e($pm['type']) ?>"><?= e($pm['name']) ?></option><?php endforeach; ?>
            </select></div>
          <div id="bankAccBox" style="display:none"><label>Bank Account</label>
            <select name="bank_account_id">
              <?php foreach ($banks as $b): ?><option value="<?= $b['id'] ?>"><?= e($b['account_name']) ?> - <?= e($b['bank_name']) ?></option><?php endforeach; ?>
            </select></div>
        </div>
        <div class="field"><label>Notes</label><input type="text" name="notes"></div>
        <div class="bill-totals">
          <div class="t-line"><span>Subtotal</span><span>₹ <span id="t_sub">0.00</span></span></div>
          <div class="t-line"><span>GST</span><span>₹ <span id="t_tax">0.00</span></span></div>
          <div class="t-line t-grand"><span>Total</span><span>₹ <span id="t_grand">0.00</span></span></div>
        </div>
        <button class="btn btn-block mt" type="submit">💾 Save Purchase</button>
      </div>
    </form>

    <!-- quick party modal -->
    <div id="qpModal" style="display:none" class="card">
      <h3>Quick add supplier</h3>
      <div class="form-row cols-3">
        <div><input type="text" id="qp_name" placeholder="Party name"></div>
        <div><input type="tel" id="qp_mobile" placeholder="Mobile"></div>
        <div><input type="text" id="qp_gstin" placeholder="GSTIN (optional)"></div>
      </div>
      <button type="button" class="btn btn-sm" onclick="quickParty()">Add</button>
    </div>
    <script>
      Bill.init({mode: 'purchase', serials: true, locSel: 'location_id', gst: true});
      function pmChange() {
        var sel = document.getElementById('payment_mode');
        var opt = sel.options[sel.selectedIndex];
        document.getElementById('bankAccBox').style.display = opt.dataset.type === 'bank' ? '' : 'none';
      }
      window.pmChange = pmChange;
      function setDiscType(t) {
        document.getElementById('discount_type').value = t;
        document.getElementById('discAmt').className = t === 'amount' ? 'on-cash' : '';
        document.getElementById('discPct').className = t === 'percent' ? 'on-cash' : '';
        Bill.totals();
      }
      window.setDiscType = setDiscType;
      function quickParty() {
        var fd = new FormData();
        fd.append('csrf', document.querySelector('input[name=csrf]').value);
        fd.append('name', document.getElementById('qp_name').value);
        fd.append('mobile', document.getElementById('qp_mobile').value);
        fd.append('gstin', document.getElementById('qp_gstin').value);
        fd.append('type', 'supplier');
        fetch('ajax.php?a=party_add', {method: 'POST', body: fd})
          .then(r => r.json()).then(function (d) {
            if (d.error) { alert(d.error); return; }
            var sel = document.getElementById('party_id');
            var o = document.createElement('option');
            o.value = d.id; o.textContent = d.name; o.selected = true;
            sel.appendChild(o);
            document.getElementById('qpModal').style.display = 'none';
          });
      }
    </script>
    <?php
    include __DIR__ . '/includes/footer.php';
    exit;
}

// ---------- list ----------
list($scope, $params) = own_scope('purchases', 'p.created_by');
$from = get('from', date('Y-m-01'));
$to = get('to', today());
$purchases = all("SELECT p.*, pt.name party_name FROM purchases p
                  JOIN parties pt ON pt.id = p.party_id
                  WHERE p.purchase_date BETWEEN ? AND ? $scope
                  ORDER BY p.id DESC LIMIT 500", array_merge([$from, $to], $params));
$sumDueP = (float)val("SELECT COALESCE(SUM(total - paid),0) FROM purchases p WHERE p.status <> 'paid' AND p.is_cancelled = 0 " . $scope, $params);
$page_title = 'Purchases';
include __DIR__ . '/includes/header.php';
?>
<div class="duo-cards">
  <div class="duo-card" style="background:#e0f2fe"><div class="duo-label" style="color:#075985">Total Purchase (period)</div><div class="duo-value" style="color:#0369a1">₹ <?= money(array_sum(array_column($purchases, 'total'))) ?></div></div>
  <div class="duo-card duo-give"><div class="duo-label">Balance Due</div><div class="duo-value">₹ <?= money($sumDueP) ?></div></div>
</div>
<div class="page-actions">
  <?php if (can('purchases.add')): ?><a class="btn" href="purchases.php?action=new">+ New Purchase</a><?php endif; ?>
</div>
<form method="get" class="filterbar">
  <div><label>From</label><input type="date" name="from" value="<?= e($from) ?>"></div>
  <div><label>To</label><input type="date" name="to" value="<?= e($to) ?>"></div>
  <button class="btn btn-sm" type="submit">Filter</button>
</form>
<div class="list-count"><?= count($purchases) ?> purchases · Total ₹<?= money(array_sum(array_column($purchases, 'total'))) ?></div>
<div class="table-wrap">
<table>
  <thead><tr><th>#</th><th>Date</th><th>Supplier</th><th>Bill no</th><th class="num">Total</th><th>Due date</th><th>Status</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($purchases as $p): ?>
    <tr>
      <td><?= $p['id'] ?></td>
      <td><?= dmy($p['purchase_date']) ?></td>
      <td><?= e($p['party_name']) ?></td>
      <td><?= e($p['bill_no']) ?></td>
      <td class="num">₹<?= money($p['total']) ?></td>
      <td><?= dmy($p['due_date']) ?><?= $p['status'] !== 'paid' && $p['due_date'] && $p['due_date'] < today() ? ' <span class="badge badge-bad">overdue</span>' : '' ?></td>
      <td><?= $p['is_cancelled'] ? '<span class="badge badge-bad">CANCELLED</span>' : status_badge($p['status']) ?></td>
      <td><a class="btn btn-sm btn-outline" href="purchase_view.php?id=<?= $p['id'] ?>">View</a></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
