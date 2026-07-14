<?php
// Purchases - stock in, serial number entry, credit terms
require_once __DIR__ . '/includes/init.php';
require_perm('purchases.view');
$u = current_user();
$action = get('action', 'list');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'save') {
    require_perm('purchases.add');
    if (is_period_locked(post('purchase_date', today()))) { flash(period_lock_message(), 'error'); redirect('purchases.php?action=new'); }
    $company = row('SELECT * FROM companies WHERE id = ?', [(int)post('company_id', 1)]);
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
        $tr = ($company && $company['is_gst']) ? (float)($taxes[$i] ?? 0) : 0;
        $rows[] = ['item_id' => $iid, 'qty' => $qty, 'price' => (float)($prices[$i] ?? 0),
                   'tax_rate' => $tr, 'total' => $qty * (float)($prices[$i] ?? 0),
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
    $shipping = max(0, (float)post('shipping'));
    $total = $subtotal - $discount + $tax + $shipping;
    $paid = min((float)post('paid'), $total);
    $credit_days = (int)post('credit_days');
    $pdate = post('purchase_date', today());
    $bankAccId = (int)post('bank_account_id') ?: null;
    $pmId = (int)post('payment_method_id') ?: null;

    $pdo = db();
    $pdo->beginTransaction();
    try {
        q('INSERT INTO purchases (company_id, bill_no, party_id, location_id, purchase_date, credit_days, due_date,
           subtotal, discount, discount_type, discount_pct, tax_amount, shipping, total, paid, payment_method_id, bank_account_id, status, notes, created_by)
           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
          [(int)post('company_id', 1), post('bill_no'), $party_id, $loc_id, $pdate, $credit_days,
           $credit_days ? date('Y-m-d', strtotime("$pdate +$credit_days days")) : null,
           $subtotal, $discount, $discType, $discPct, $tax, $shipping, $total, $paid, $pmId, $bankAccId, payment_status($total, $paid), post('notes'), $u['id']]);
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
    if ($purchase && is_period_locked($purchase['purchase_date'])) { flash(period_lock_message(), 'error'); redirect('purchases.php'); }
    if ($purchase) {
        // Some serials from this purchase may already be sold/issued/
        // returned elsewhere - delete/cancel is allowed anyway (by
        // explicit request), but only the serials still sitting in stock
        // are touched; serials that already moved on keep their own
        // sold/issued/returned history intact instead of being erased.
        $pdo = db();
        $pdo->beginTransaction();
        foreach ($purchase['is_cancelled'] ? [] : all('SELECT * FROM purchase_items WHERE purchase_id = ?', [$pid]) as $pi) {
            adjust_stock($pi['item_id'], $purchase['location_id'], -(float)$pi['qty'], 'purchase_delete', $pid);
        }
        if (!$purchase['is_cancelled']) {
            q("DELETE FROM item_serials WHERE purchase_id = ? AND status = 'in_stock'", [$pid]);
        }
        q("DELETE FROM payments WHERE ref_type = 'purchase' AND ref_id = ?", [$pid]);
        if ($cancelOnly) {
            q('UPDATE purchases SET is_cancelled = 1, paid = 0, status = ? WHERE id = ?', ['due', $pid]);
            log_activity('purchase_cancel', $purchase['bill_no'] ?: "#$pid");
            flash('Purchase bill CANCELLED - stock reduced back, record kept.');
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

// ---------- edit existing purchase ----------
// Payment history untouched (paid only ever capped down to the new total).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'update') {
    require_perm('purchases.edit');
    $pid = (int)post('id');
    $purchase = row('SELECT * FROM purchases WHERE id = ?', [$pid]);
    if (!$purchase) { flash('Purchase not found.', 'error'); redirect('purchases.php'); }
    if ($purchase['is_cancelled']) { flash('A cancelled bill cannot be edited.', 'error'); redirect('purchase_view.php?id=' . $pid); }
    if (is_period_locked($purchase['purchase_date']) || is_period_locked(post('purchase_date', $purchase['purchase_date']))) {
        flash(period_lock_message(), 'error'); redirect('purchase_view.php?id=' . $pid);
    }

    $moved = (int)val("SELECT COUNT(*) FROM item_serials WHERE purchase_id = ? AND status <> 'in_stock'", [$pid]);
    if ($moved > 0) {
        flash('Some serial numbers on this bill have already been sold/issued/returned, so it cannot be edited.', 'error');
        redirect('purchase_view.php?id=' . $pid);
    }

    $company = row('SELECT * FROM companies WHERE id = ?', [(int)post('company_id', 1)]);
    $loc_id = (int)post('location_id') ?: $u['location_id'];
    $party_id = (int)post('party_id');
    $item_ids = post('item_id', []);
    $qtys = post('qty', []);
    $prices = post('price', []);
    $taxes = post('tax_rate', []);
    $serials_in = post('serials', []);

    $rows = [];
    foreach ($item_ids as $i => $iid) {
        $iid = (int)$iid;
        $qty = (float)($qtys[$i] ?? 0);
        if (!$iid || $qty <= 0) { continue; }
        $tr = ($company && $company['is_gst']) ? (float)($taxes[$i] ?? 0) : 0;
        $rows[] = ['item_id' => $iid, 'qty' => $qty, 'price' => (float)($prices[$i] ?? 0),
                   'tax_rate' => $tr, 'total' => $qty * (float)($prices[$i] ?? 0),
                   'serials' => trim((string)($serials_in[$i] ?? ''))];
    }
    if (!$rows || !$party_id) { flash('Party and at least one item required.', 'error'); redirect('purchases.php?action=edit&id=' . $pid); }

    $subtotal = array_sum(array_column($rows, 'total'));
    $tax = 0;
    foreach ($rows as $r) $tax += $r['total'] * $r['tax_rate'] / 100;
    $discType = post('discount_type') === 'percent' ? 'percent' : 'amount';
    $discRaw = (float)post('discount_val');
    $discount = $discType === 'percent' ? round($subtotal * $discRaw / 100, 2) : min($discRaw, $subtotal);
    $discPct = $discType === 'percent' ? $discRaw : 0;
    $shipping = max(0, (float)post('shipping'));
    $total = $subtotal - $discount + $tax + $shipping;
    $paid = min((float)$purchase['paid'], $total);
    $credit_days = (int)post('credit_days');
    $pdate = post('purchase_date', $purchase['purchase_date']);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        foreach (all('SELECT * FROM purchase_items WHERE purchase_id = ?', [$pid]) as $oi) {
            adjust_stock($oi['item_id'], $purchase['location_id'], -(float)$oi['qty'], 'purchase_edit', $pid);
        }
        q('DELETE FROM item_serials WHERE purchase_id = ?', [$pid]);
        q('DELETE FROM purchase_items WHERE purchase_id = ?', [$pid]);

        foreach ($rows as $r) {
            $item = row('SELECT * FROM items WHERE id = ?', [$r['item_id']]);
            q('INSERT INTO purchase_items (purchase_id, item_id, qty, price, tax_rate, total) VALUES (?,?,?,?,?,?)',
              [$pid, $r['item_id'], $r['qty'], $r['price'], $r['tax_rate'], $r['total']]);
            adjust_stock($r['item_id'], $loc_id, $r['qty'], 'purchase_edit', $pid, post('bill_no'));

            if ($item['serial_tracked']) {
                $sns = array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $r['serials']))));
                if (count($sns) != $r['qty']) throw new Exception("Enter {$r['qty']} serial number(s) for {$item['name']} (one per line).");
                foreach ($sns as $sn) {
                    q("INSERT INTO item_serials (item_id, serial_no, location_id, status, purchase_id, warranty_months)
                       VALUES (?,?,?,'in_stock',?,?)", [$r['item_id'], $sn, $loc_id, $pid, $item['warranty_months']]);
                }
            }
        }
        foreach ($rows as $r) {
            q('UPDATE items SET purchase_price = ? WHERE id = ?', [$r['price'], $r['item_id']]);
            q('UPDATE items SET selling_price = ROUND(purchase_price * (1 + margin_pct / 100), 2)
               WHERE id = ? AND margin_pct > 0', [$r['item_id']]);
        }

        q('UPDATE purchases SET company_id=?, bill_no=?, party_id=?, location_id=?, purchase_date=?, credit_days=?, due_date=?,
           subtotal=?, discount=?, discount_type=?, discount_pct=?, tax_amount=?, shipping=?, total=?, paid=?, status=?, notes=? WHERE id=?',
          [(int)post('company_id', 1), post('bill_no'), $party_id, $loc_id, $pdate, $credit_days,
           $credit_days ? date('Y-m-d', strtotime("$pdate +$credit_days days")) : null,
           $subtotal, $discount, $discType, $discPct, $tax, $shipping, $total, $paid, payment_status($total, $paid), post('notes'), $pid]);

        $pdo->commit();
        log_activity('purchase_edit', ($purchase['bill_no'] ?: "#$pid") . " total $total");
        flash('Purchase bill updated.');
        redirect('purchase_view.php?id=' . $pid);
    } catch (Exception $ex) {
        $pdo->rollBack();
        flash('Error: ' . $ex->getMessage(), 'error');
        redirect('purchases.php?action=edit&id=' . $pid);
    }
}

$locations = all('SELECT * FROM locations WHERE is_active = 1 ORDER BY name');
$terms = all('SELECT * FROM credit_terms ORDER BY days');
$companies = all('SELECT * FROM companies WHERE is_active = 1 ORDER BY id');

if ($action === 'new' || $action === 'edit') {
    $isEdit = $action === 'edit';
    require_perm($isEdit ? 'purchases.edit' : 'purchases.add');
    $editPurchase = null; $editItems = [];
    if ($isEdit) {
        $editPurchase = row('SELECT * FROM purchases WHERE id = ?', [(int)get('id')]);
        if (!$editPurchase) die('Purchase not found.');
        if (!can('purchases.all') && $editPurchase['created_by'] != $u['id']) die('Access denied.');
        if ($editPurchase['is_cancelled']) { flash('A cancelled bill cannot be edited.', 'error'); redirect('purchase_view.php?id=' . $editPurchase['id']); }
        $editItems = all('SELECT pi.*, i.name, i.serial_tracked FROM purchase_items pi JOIN items i ON i.id = pi.item_id WHERE pi.purchase_id = ?', [$editPurchase['id']]);
        foreach ($editItems as &$_ei) {
            if ($_ei['serial_tracked']) {
                $_ei['serial_list'] = implode("\n", array_column(all('SELECT serial_no FROM item_serials WHERE purchase_id = ? AND item_id = ?', [$editPurchase['id'], $_ei['item_id']]), 'serial_no'));
            }
        }
        unset($_ei);
    }
    $parties = all("SELECT id, name, credit_days FROM parties WHERE is_active = 1 ORDER BY name");
    $page_title = $isEdit ? 'Edit Purchase #' . $editPurchase['id'] : 'New Purchase';
    include __DIR__ . '/includes/header.php';
    ?>
    <?php if ($isEdit && array_filter($editItems, fn($it) => $it['serial_tracked'])): ?><div class="flash flash-info">Serial-tracked items' serial numbers are pre-filled - you can change them if needed.</div><?php endif; ?>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="<?= $isEdit ? 'update' : 'save' ?>">
      <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= $editPurchase['id'] ?>"><?php endif; ?>
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
            <select name="company_id" id="company_id"><?php foreach ($companies as $c): ?><option value="<?= $c['id'] ?>" data-gst="<?= $c['is_gst'] ?>"><?= e($c['name']) ?><?= $c['is_gst'] ? ' (GST)' : '' ?></option><?php endforeach; ?></select></div>
          <div><label>Supplier bill no.</label><input type="text" name="bill_no" id="bill_no" value="<?= $isEdit ? e($editPurchase['bill_no']) : '' ?>"></div>
          <div><label>Date</label><input type="date" name="purchase_date" id="purchase_date" value="<?= $isEdit ? e($editPurchase['purchase_date']) : today() ?>"></div>
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
        <p class="muted mt">Selecting a serial-tracked item shows a box to type serial numbers (one per line).</p>
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
          <div><label>Shipping (₹)</label><input type="number" step="any" name="shipping" id="shipping" value="<?= $isEdit ? money($editPurchase['shipping']) : '0' ?>" oninput="Bill.totals()"></div>
          <?php if ($isEdit): ?>
          <div><label>Already paid</label><input type="text" value="₹<?= money($editPurchase['paid']) ?> (unaffected by this edit)" disabled></div>
          <?php else: ?>
          <div><label>Paid now (₹)</label><input type="number" step="any" name="paid" id="paid" value="0"></div>
          <div><label>Payment mode</label>
            <select name="payment_mode" id="payment_mode" onchange="pmChange()">
              <?php foreach ($pms as $pm): if ($pm['code'] === 'credit') continue; ?><option value="<?= e($pm['code']) ?>" data-type="<?= e($pm['type']) ?>"><?= e($pm['name']) ?></option><?php endforeach; ?>
            </select></div>
          <div id="bankAccBox" style="display:none"><label>Bank Account</label>
            <select name="bank_account_id">
              <?php foreach ($banks as $b): ?><option value="<?= $b['id'] ?>"><?= e($b['account_name']) ?> - <?= e($b['bank_name']) ?></option><?php endforeach; ?>
            </select></div>
          <?php endif; ?>
        </div>
        <div class="field"><label>Notes</label><input type="text" name="notes" value="<?= $isEdit ? e($editPurchase['notes']) : '' ?>"></div>
        <div class="bill-totals">
          <div class="t-line"><span>Subtotal</span><span>₹ <span id="t_sub">0.00</span></span></div>
          <div class="t-line"><span>GST</span><span>₹ <span id="t_tax">0.00</span></span></div>
          <div class="t-line"><span>Shipping</span><span>₹ <span id="t_ship">0.00</span></span></div>
          <div class="t-line t-grand"><span>Total</span><span>₹ <span id="t_grand">0.00</span></span></div>
        </div>
        <?php if ($isEdit): ?>
        <div class="form-row cols-2 mt">
          <a class="btn btn-outline" href="purchase_view.php?id=<?= $editPurchase['id'] ?>">Cancel</a>
          <button class="btn" type="submit">💾 Update Purchase</button>
        </div>
        <?php else: ?>
        <button class="btn btn-block mt" type="submit">💾 Save Purchase</button>
        <?php endif; ?>
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
      Bill.init({mode: 'purchase', serials: true, locSel: 'location_id', gst: document.querySelector('#company_id option:checked').dataset.gst == 1});
      document.getElementById('company_id').addEventListener('change', function () {
        Bill.cfg.gst = this.options[this.selectedIndex].dataset.gst == 1;
        Bill.totals();
      });
      <?php if (!$isEdit && get('reorder')): ?>
      // prefill rows from a "🛒 Create Purchase for Selected" reorder suggestion (Reports > Low Stock)
      (function () {
        var raw = sessionStorage.getItem('reorderItems');
        if (!raw) return;
        sessionStorage.removeItem('reorderItems');
        var items = JSON.parse(raw);
        items.forEach(function (it, idx) {
          if (idx > 0) Bill.addRow();
          var rows = document.querySelectorAll('#billItems .bill-row');
          var div = rows[rows.length - 1];
          div.querySelector('.i-search').value = it.name;
          div.querySelector('.i-id').value = it.id;
          div.querySelector('.i-tax').value = it.tax;
          div.querySelector('.i-qty').value = it.qty;
          div.querySelector('.i-price').value = it.price;
          div.querySelector('.i-extra').innerHTML = '<input type="hidden" name="serials[]" value="">';
          Bill.rowTotal(div);
        });
        Bill.totals();
      })();
      <?php endif; ?>
      <?php if ($isEdit): ?>
      // prefill rows from the existing purchase being edited
      (function () {
        var pre = <?= json_encode(array_map(fn($x) => [
            'id' => (int)$x['item_id'], 'name' => $x['name'], 'qty' => (float)$x['qty'],
            'price' => (float)$x['price'], 'tax' => (float)$x['tax_rate'],
            'serialTracked' => (int)$x['serial_tracked'], 'serials' => $x['serial_list'] ?? '',
        ], $editItems)) ?>;
        document.getElementById('company_id').value = '<?= (int)$editPurchase['company_id'] ?>';
        document.getElementById('party_id').value = '<?= (int)$editPurchase['party_id'] ?>';
        document.getElementById('credit_days').value = '<?= (int)$editPurchase['credit_days'] ?>';
        document.getElementById('discount_type').value = <?= json_encode($editPurchase['discount_type']) ?>;
        document.getElementById('discount_val').value = '<?= $editPurchase['discount_type'] === 'percent' ? (float)$editPurchase['discount_pct'] : (float)$editPurchase['discount'] ?>';
        document.getElementById('discAmt').className = <?= json_encode($editPurchase['discount_type']) ?> === 'amount' ? 'on-cash' : '';
        document.getElementById('discPct').className = <?= json_encode($editPurchase['discount_type']) ?> === 'percent' ? 'on-cash' : '';
        Bill.cfg.gst = document.querySelector('#company_id option:checked').dataset.gst == 1;
        pre.forEach(function (it, idx) {
          if (idx > 0) Bill.addRow();
          var rows = document.querySelectorAll('#billItems .bill-row');
          var div = rows[rows.length - 1];
          div.querySelector('.i-search').value = it.name;
          div.querySelector('.i-id').value = it.id;
          div.querySelector('.i-tax').value = it.tax;
          div.querySelector('.i-qty').value = it.qty;
          div.querySelector('.i-price').value = it.price;
          var extra = div.querySelector('.i-extra');
          if (it.serialTracked) {
            extra.innerHTML = '<label class="mt">Serial numbers (one per line, count = qty)</label><textarea name="serials[]" rows="2"></textarea>';
            extra.querySelector('textarea').value = it.serials;
          } else {
            extra.innerHTML = '<input type="hidden" name="serials[]" value="">';
          }
          Bill.rowTotal(div);
        });
        Bill.totals();
      })();
      <?php endif; ?>
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
      <td><a href="parties.php?action=ledger&id=<?= $p['party_id'] ?>"><?= e($p['party_name']) ?></a></td>
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
