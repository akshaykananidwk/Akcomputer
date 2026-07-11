<?php
// Sales / Billing - list + new bill (company-wise GST/non-GST, serials, credit)
require_once __DIR__ . '/includes/init.php';
require_perm('sales.view');
$u = current_user();

$action = get('action', 'list');

// ---------- save new bill ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'save') {
    require_perm('sales.add');
    $company = row('SELECT * FROM companies WHERE id = ?', [(int)post('company_id')]);
    $loc_id = (int)post('location_id') ?: $u['location_id'];
    $item_ids = post('item_id', []);
    $qtys = post('qty', []);
    $prices = post('price', []);
    $taxes = post('tax_rate', []);
    $serialSel = post('serial_sel', []);
    $freeQtys = post('free_qty', []);

    $rows = [];
    foreach ($item_ids as $i => $iid) {
        $iid = (int)$iid;
        $qty = (float)($qtys[$i] ?? 0);
        if (!$iid || $qty <= 0) continue;
        $price = (float)($prices[$i] ?? 0);
        $tr = $company['is_gst'] ? (float)($taxes[$i] ?? 0) : 0;
        $rows[] = ['item_id' => $iid, 'qty' => $qty, 'free' => (float)($freeQtys[$i] ?? 0),
                   'price' => $price, 'tax_rate' => $tr, 'total' => $qty * $price, 'n' => $i + 1];
    }
    if (!$rows || !$company) { flash('Add at least one item.', 'error'); redirect('sales.php?action=new'); }

    $subtotal = array_sum(array_column($rows, 'total'));
    $discType = post('discount_type') === 'percent' ? 'percent' : 'amount';
    $discRaw = (float)post('discount_val');
    $discount = $discType === 'percent' ? round($subtotal * $discRaw / 100, 2) : min($discRaw, $subtotal);
    $discPct = $discType === 'percent' ? $discRaw : 0;
    $tax = 0;
    foreach ($rows as $r) $tax += $r['total'] * $r['tax_rate'] / 100;
    $shipping = max(0, (float)post('shipping'));
    $total = $subtotal - $discount + $tax + $shipping;
    $paid = post('payment_mode') === 'credit' ? 0 : min((float)post('paid'), $total);
    $credit_days = (int)post('credit_days');
    $bankAccId = (int)post('bank_account_id') ?: null;
    $pmId = (int)post('payment_method_id') ?: null;

    // Auto-link/create a Party from the mobile number (Vyapar-style) so
    // every customer becomes a trackable ledger party even when staff just
    // type name/mobile during quick billing instead of picking "Party" -
    // otherwise Payment-In has nothing to collect against later.
    $party_id = (int)post('party_id') ?: null;
    $custMobile = post('customer_mobile');
    $custName = post('customer_name');
    if (!$party_id && $custMobile !== '') {
        $existing = row('SELECT id, type FROM parties WHERE mobile = ? LIMIT 1', [$custMobile]);
        if ($existing) {
            $party_id = (int)$existing['id'];
            if ($existing['type'] === 'supplier') q("UPDATE parties SET type = 'both' WHERE id = ?", [$party_id]);
        } else {
            q("INSERT INTO parties (name, type, mobile, credit_days, opening_balance, is_active) VALUES (?, 'customer', ?, 0, 0, 1)",
              [$custName ?: $custMobile, $custMobile]);
            $party_id = insert_id();
        }
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        q('INSERT INTO sales (company_id, party_id, customer_name, customer_mobile, location_id, sale_date, price_type,
           credit_days, due_date, subtotal, discount, discount_type, discount_pct, tax_amount, shipping, total, paid, payment_mode,
           bank_account_id, payment_method_id, status, notes, created_by, share_token)
           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
          [$company['id'], $party_id, post('customer_name'), post('customer_mobile'), $loc_id,
           post('sale_date', today()), post('price_type', 'retail'), $credit_days,
           $credit_days ? date('Y-m-d', strtotime(post('sale_date', today()) . " +$credit_days days")) : null,
           $subtotal, $discount, $discType, $discPct, $tax, $shipping, $total, $paid, post('payment_mode', 'cash'),
           $bankAccId, $pmId, payment_status($total, $paid), post('notes'), $u['id'], share_token()]);
        $sale_id = insert_id();
        $invoice_no = $company['invoice_prefix'] . '-' . date('y') . '-' . str_pad($sale_id, 5, '0', STR_PAD_LEFT);
        q('UPDATE sales SET invoice_no = ? WHERE id = ?', [$invoice_no, $sale_id]);

        $allowNeg = setting('allow_negative_stock', '1') === '1';
        foreach ($rows as $r) {
            $item = row('SELECT * FROM items WHERE id = ?', [$r['item_id']]);
            $serials = array_values(array_filter(array_map('trim', (array)($serialSel[$r['n']] ?? []))));
            // serial items: serials must match qty, except when selling without
            // stock (advance billing) - then bill passes with no serials selected
            if ($item['serial_tracked'] && count($serials) > 0 && count($serials) != $r['qty']) {
                throw new Exception("Select {$r['qty']} serial number(s) for {$item['name']} (or none for advance billing).");
            }
            if ($item['serial_tracked'] && !$serials && !$allowNeg) {
                throw new Exception("Select serial number(s) for {$item['name']}.");
            }
            q('INSERT INTO sale_items (sale_id, item_id, qty, free_qty, price, cost_price, tax_rate, total, serials) VALUES (?,?,?,?,?,?,?,?,?)',
              [$sale_id, $r['item_id'], $r['qty'], $r['free'], $r['price'], (float)$item['purchase_price'], $r['tax_rate'], $r['total'], $serials ? implode(',', $serials) : null]);

            if ($item['item_type'] === 'service') { continue; } // service: no stock effect
            if (!$allowNeg && stock_qty($r['item_id'], $loc_id) < $r['qty']) {
                throw new Exception("Not enough stock of {$item['name']} at this location.");
            }
            adjust_stock($r['item_id'], $loc_id, -($r['qty'] + $r['free']), 'sale', $sale_id, $invoice_no);

            foreach ($serials as $sn) {
                $expiry = $item['warranty_months'] > 0
                    ? date('Y-m-d', strtotime(post('sale_date', today()) . ' +' . $item['warranty_months'] . ' months'))
                    : null;
                $upd = q("UPDATE item_serials SET status='sold', sale_id=?, location_id=NULL, warranty_expiry=?
                          WHERE item_id=? AND serial_no=? AND status='in_stock' AND location_id=?",
                         [$sale_id, $expiry, $r['item_id'], $sn, $loc_id]);
                if ($upd->rowCount() === 0) throw new Exception("Serial $sn is not available in stock.");
            }
        }
        // post initial payment to the party ledger (and to cash/bank books -
        // always recorded, even for walk-in sales with no party, so "Cash in
        // Hand" and bank account balances stay accurate)
        if ($paid > 0) {
            q('INSERT INTO payments (party_id, direction, amount, mode, bank_account_id, payment_method_id, ref_type, ref_id, pay_date, notes, created_by)
               VALUES (?,?,?,?,?,?,?,?,?,?,?)',
              [$party_id, 'in', $paid, post('payment_mode', 'cash'), $bankAccId, $pmId, 'sale', $sale_id,
               post('sale_date', today()), 'With bill ' . $invoice_no, $u['id']]);
        }
        if ((int)post('estimate_id')) {
            q("UPDATE estimates SET status='converted', converted_sale_id=? WHERE id=? AND status='open'",
              [$sale_id, (int)post('estimate_id')]);
        }
        if ((int)post('challan_id')) {
            q("UPDATE challans SET status='converted', converted_sale_id=? WHERE id=? AND status='open'",
              [$sale_id, (int)post('challan_id')]);
        }
        $pdo->commit();
        log_activity('sale_add', "$invoice_no total $total");
        flash("Bill $invoice_no saved.");
        redirect(post('save_new') ? 'sales.php?action=new' : 'sale_view.php?id=' . $sale_id);
    } catch (Exception $ex) {
        $pdo->rollBack();
        flash('Error: ' . $ex->getMessage(), 'error');
        redirect('sales.php?action=new');
    }
}

// ---------- delete ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'delete') {
    require_perm('sales.delete');
    $sid = (int)post('id');
    $sale = row('SELECT * FROM sales WHERE id = ?', [$sid]);
    $cancelOnly = post('mode') === 'cancel';
    if ($sale) {
        $pdo = db();
        $pdo->beginTransaction();
        foreach ($sale['is_cancelled'] ? [] : all('SELECT * FROM sale_items WHERE sale_id = ?', [$sid]) as $si) {
            adjust_stock($si['item_id'], $sale['location_id'], (float)$si['qty'] + (float)($si['free_qty'] ?? 0), 'sale_delete', $sid);
            if ($si['serials']) {
                foreach (explode(',', $si['serials']) as $sn) {
                    q("UPDATE item_serials SET status='in_stock', sale_id=NULL, location_id=?, warranty_expiry=NULL
                       WHERE item_id=? AND serial_no=?", [$sale['location_id'], $si['item_id'], trim($sn)]);
                }
            }
        }
        q("DELETE FROM payments WHERE ref_type = 'sale' AND ref_id = ?", [$sid]);
        if ($cancelOnly) {
            q('UPDATE sales SET is_cancelled = 1, paid = 0, status = ? WHERE id = ?', ['due', $sid]);
            log_activity('sale_cancel', $sale['invoice_no']);
            flash('Invoice ' . $sale['invoice_no'] . ' CANCELLED - stock restored, record સચવાયો.');
        } else {
            q('DELETE FROM sale_items WHERE sale_id = ?', [$sid]);
            q('DELETE FROM sales WHERE id = ?', [$sid]);
            log_activity('sale_delete', $sale['invoice_no']);
            flash('Bill deleted and stock restored.');
        }
        $pdo->commit();
    }
    redirect('sales.php');
}

// ---------- edit existing bill ----------
// Payment history is left untouched on edit (paid is only ever capped down
// to the new total) - editing only replaces the item lines and recomputes
// subtotal/discount/tax/shipping/total, same as a fresh save but updating
// the existing row instead of inserting a new one.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'update') {
    require_perm('sales.edit');
    $sid = (int)post('id');
    $sale = row('SELECT * FROM sales WHERE id = ?', [$sid]);
    if (!$sale) { flash('Bill not found.', 'error'); redirect('sales.php'); }
    if ($sale['is_cancelled']) { flash('Cancelled bill ને edit કરી શકાય નહીં.', 'error'); redirect('sale_view.php?id=' . $sid); }

    $oldItems = all('SELECT * FROM sale_items WHERE sale_id = ?', [$sid]);
    foreach ($oldItems as $oi) {
        if (!$oi['serials']) continue;
        foreach (explode(',', $oi['serials']) as $sn) {
            $srow = row('SELECT status FROM item_serials WHERE item_id = ? AND serial_no = ?', [$oi['item_id'], trim($sn)]);
            if ($srow && $srow['status'] !== 'sold') {
                flash('આ bill ના serial ' . trim($sn) . ' પર warranty/return જેવું કંઈક થઈ ગયું છે, એટલે edit કરી શકાય નહીં. Cancel કરીને નવું બિલ બનાવો.', 'error');
                redirect('sale_view.php?id=' . $sid);
            }
        }
    }

    $company = row('SELECT * FROM companies WHERE id = ?', [(int)post('company_id')]);
    $loc_id = (int)post('location_id') ?: $u['location_id'];
    $item_ids = post('item_id', []);
    $qtys = post('qty', []);
    $prices = post('price', []);
    $taxes = post('tax_rate', []);
    $serialSel = post('serial_sel', []);
    $freeQtys = post('free_qty', []);

    $rows = [];
    foreach ($item_ids as $i => $iid) {
        $iid = (int)$iid;
        $qty = (float)($qtys[$i] ?? 0);
        if (!$iid || $qty <= 0) continue;
        $price = (float)($prices[$i] ?? 0);
        $tr = $company['is_gst'] ? (float)($taxes[$i] ?? 0) : 0;
        $rows[] = ['item_id' => $iid, 'qty' => $qty, 'free' => (float)($freeQtys[$i] ?? 0),
                   'price' => $price, 'tax_rate' => $tr, 'total' => $qty * $price, 'n' => $i + 1];
    }
    if (!$rows || !$company) { flash('Add at least one item.', 'error'); redirect('sales.php?action=edit&id=' . $sid); }

    $subtotal = array_sum(array_column($rows, 'total'));
    $discType = post('discount_type') === 'percent' ? 'percent' : 'amount';
    $discRaw = (float)post('discount_val');
    $discount = $discType === 'percent' ? round($subtotal * $discRaw / 100, 2) : min($discRaw, $subtotal);
    $discPct = $discType === 'percent' ? $discRaw : 0;
    $tax = 0;
    foreach ($rows as $r) $tax += $r['total'] * $r['tax_rate'] / 100;
    $shipping = max(0, (float)post('shipping'));
    $total = $subtotal - $discount + $tax + $shipping;
    $paid = min((float)$sale['paid'], $total);
    $credit_days = (int)post('credit_days');
    $sale_date = post('sale_date', $sale['sale_date']);

    $party_id = (int)post('party_id') ?: null;
    $custMobile = post('customer_mobile');
    $custName = post('customer_name');
    if (!$party_id && $custMobile !== '') {
        $existing = row('SELECT id, type FROM parties WHERE mobile = ? LIMIT 1', [$custMobile]);
        if ($existing) {
            $party_id = (int)$existing['id'];
            if ($existing['type'] === 'supplier') q("UPDATE parties SET type = 'both' WHERE id = ?", [$party_id]);
        } else {
            q("INSERT INTO parties (name, type, mobile, credit_days, opening_balance, is_active) VALUES (?, 'customer', ?, 0, 0, 1)",
              [$custName ?: $custMobile, $custMobile]);
            $party_id = insert_id();
        }
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        foreach ($oldItems as $oi) {
            adjust_stock($oi['item_id'], $sale['location_id'], (float)$oi['qty'] + (float)($oi['free_qty'] ?? 0), 'sale_edit', $sid);
            if ($oi['serials']) {
                foreach (explode(',', $oi['serials']) as $sn) {
                    q("UPDATE item_serials SET status='in_stock', sale_id=NULL, location_id=?, warranty_expiry=NULL
                       WHERE item_id=? AND serial_no=?", [$sale['location_id'], $oi['item_id'], trim($sn)]);
                }
            }
        }
        q('DELETE FROM sale_items WHERE sale_id = ?', [$sid]);

        $allowNeg = setting('allow_negative_stock', '1') === '1';
        foreach ($rows as $r) {
            $item = row('SELECT * FROM items WHERE id = ?', [$r['item_id']]);
            $serials = array_values(array_filter(array_map('trim', (array)($serialSel[$r['n']] ?? []))));
            if ($item['serial_tracked'] && count($serials) > 0 && count($serials) != $r['qty']) {
                throw new Exception("Select {$r['qty']} serial number(s) for {$item['name']} (or none for advance billing).");
            }
            if ($item['serial_tracked'] && !$serials && !$allowNeg) {
                throw new Exception("Select serial number(s) for {$item['name']}.");
            }
            q('INSERT INTO sale_items (sale_id, item_id, qty, free_qty, price, cost_price, tax_rate, total, serials) VALUES (?,?,?,?,?,?,?,?,?)',
              [$sid, $r['item_id'], $r['qty'], $r['free'], $r['price'], (float)$item['purchase_price'], $r['tax_rate'], $r['total'], $serials ? implode(',', $serials) : null]);

            if ($item['item_type'] === 'service') { continue; }
            if (!$allowNeg && stock_qty($r['item_id'], $loc_id) < $r['qty']) {
                throw new Exception("Not enough stock of {$item['name']} at this location.");
            }
            adjust_stock($r['item_id'], $loc_id, -($r['qty'] + $r['free']), 'sale_edit', $sid, $sale['invoice_no']);

            foreach ($serials as $sn) {
                $expiry = $item['warranty_months'] > 0
                    ? date('Y-m-d', strtotime($sale_date . ' +' . $item['warranty_months'] . ' months'))
                    : null;
                $upd = q("UPDATE item_serials SET status='sold', sale_id=?, location_id=NULL, warranty_expiry=?
                          WHERE item_id=? AND serial_no=? AND status='in_stock' AND location_id=?",
                         [$sid, $expiry, $r['item_id'], $sn, $loc_id]);
                if ($upd->rowCount() === 0) throw new Exception("Serial $sn is not available in stock.");
            }
        }

        q('UPDATE sales SET company_id=?, party_id=?, customer_name=?, customer_mobile=?, location_id=?, sale_date=?, price_type=?,
           credit_days=?, due_date=?, subtotal=?, discount=?, discount_type=?, discount_pct=?, tax_amount=?, shipping=?, total=?, paid=?,
           status=?, notes=? WHERE id=?',
          [$company['id'], $party_id, post('customer_name'), post('customer_mobile'), $loc_id,
           $sale_date, post('price_type', 'retail'), $credit_days,
           $credit_days ? date('Y-m-d', strtotime("$sale_date +$credit_days days")) : null,
           $subtotal, $discount, $discType, $discPct, $tax, $shipping, $total, $paid,
           payment_status($total, $paid), post('notes'), $sid]);

        $pdo->commit();
        log_activity('sale_edit', "{$sale['invoice_no']} total $total");
        flash("Bill {$sale['invoice_no']} update થઈ ગયું.");
        redirect('sale_view.php?id=' . $sid);
    } catch (Exception $ex) {
        $pdo->rollBack();
        flash('Error: ' . $ex->getMessage(), 'error');
        redirect('sales.php?action=edit&id=' . $sid);
    }
}

$companies = all('SELECT * FROM companies WHERE is_active = 1 ORDER BY id');
$locations = all('SELECT * FROM locations WHERE is_active = 1 ORDER BY name');
$terms = all('SELECT * FROM credit_terms ORDER BY days');

// ---------- new / edit bill form ----------
if ($action === 'new' || $action === 'edit') {
    $isEdit = $action === 'edit';
    require_perm($isEdit ? 'sales.edit' : 'sales.add');
    $editSale = null; $editItems = [];
    if ($isEdit) {
        $editSale = row('SELECT * FROM sales WHERE id = ?', [(int)get('id')]);
        if (!$editSale) die('Bill not found.');
        if (!can('sales.all') && $editSale['created_by'] != $u['id']) die('Access denied.');
        if ($editSale['is_cancelled']) { flash('Cancelled bill ને edit કરી શકાય નહીં.', 'error'); redirect('sale_view.php?id=' . $editSale['id']); }
        $editItems = all('SELECT si.*, i.name, i.serial_tracked FROM sale_items si JOIN items i ON i.id = si.item_id WHERE si.sale_id = ?', [$editSale['id']]);
    }
    $parties = all("SELECT id, name, mobile, credit_days FROM parties WHERE is_active = 1 AND type IN ('customer','both') ORDER BY name");
    // prefill from estimate or delivery challan (convert to bill)
    $est = null; $estItems = []; $chal = null;
    if (!$isEdit && (int)get('from_estimate')) {
        $est = row("SELECT * FROM estimates WHERE id = ? AND status = 'open'", [(int)get('from_estimate')]);
        if ($est) $estItems = all('SELECT ei.*, i.name, i.serial_tracked FROM estimate_items ei JOIN items i ON i.id = ei.item_id WHERE ei.estimate_id = ?', [$est['id']]);
    } elseif (!$isEdit && (int)get('from_challan')) {
        $chal = row("SELECT * FROM challans WHERE id = ? AND status = 'open'", [(int)get('from_challan')]);
        if ($chal) {
            $estItems = all('SELECT ci.item_id, ci.qty, ci.price, i.tax_rate, i.name FROM challan_items ci JOIN items i ON i.id = ci.item_id WHERE ci.challan_id = ?', [$chal['id']]);
            // reuse estimate prefill vars
            $est = ['id' => 0, 'estimate_no' => $chal['challan_no'], 'customer_name' => $chal['customer_name'],
                    'customer_mobile' => $chal['customer_mobile'], 'party_id' => $chal['party_id'], 'discount' => 0];
        }
    }
    $page_title = $isEdit ? 'Edit Bill ' . $editSale['invoice_no'] : 'New Bill';
    include __DIR__ . '/includes/header.php';
    ?>
    <?php if ($est): ?><div class="flash flash-info">Converting <?= e($est['estimate_no']) ?> — serial-tracked items માટે serial ફરી select કરવા પડશે.</div><?php endif; ?>
    <?php if ($isEdit && array_filter($editItems, fn($it) => $it['serials'])): ?><div class="flash flash-info">Serial-tracked items ના serial numbers ફરી select કરવા પડશે.</div><?php endif; ?>
    <form method="post" id="billForm">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="<?= $isEdit ? 'update' : 'save' ?>">
      <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= $editSale['id'] ?>"><?php endif; ?>
      <?php if (!$isEdit): ?>
      <div class="page-actions" style="justify-content:center">
        <div class="cc-toggle">
          <button type="button" id="ccCredit" onclick="setCC('credit')">Credit</button>
          <button type="button" id="ccCash" class="on-cash" onclick="setCC('cash')">Cash</button>
        </div>
      </div>
      <?php endif; ?>
      <?php if ($est && $est['id']): ?><input type="hidden" name="estimate_id" value="<?= $est['id'] ?>"><?php endif; ?>
      <?php if ($chal): ?><input type="hidden" name="challan_id" value="<?= $chal['id'] ?>"><?php endif; ?>
      <div class="card">
        <div class="form-row cols-4">
          <div><label>Firm / Company</label>
            <select name="company_id" id="company_id">
              <?php foreach ($companies as $c): ?>
              <option value="<?= $c['id'] ?>" data-gst="<?= $c['is_gst'] ?>"><?= e($c['name']) ?><?= $c['is_gst'] ? ' (GST)' : '' ?></option>
              <?php endforeach; ?>
            </select></div>
          <div><label>Location</label>
            <select name="location_id" id="location_id">
              <?php foreach ($locations as $l): ?>
              <option value="<?= $l['id'] ?>" <?= $l['id'] == $u['location_id'] ? 'selected' : '' ?>><?= e($l['name']) ?></option>
              <?php endforeach; ?>
            </select></div>
          <div><label>Date</label><input type="date" name="sale_date" value="<?= today() ?>"></div>
          <div><label>Price type</label>
            <select name="price_type" id="price_type"><option value="retail">Retail</option><option value="b2b">B2B</option></select></div>
        </div>
        <div class="form-row cols-4">
          <div><label>Party (optional)</label>
            <select name="party_id" id="party_id">
              <option value="">-- Walk-in customer --</option>
              <?php foreach ($parties as $p): ?>
              <option value="<?= $p['id'] ?>" data-mobile="<?= e($p['mobile']) ?>" data-credit="<?= $p['credit_days'] ?>"><?= e($p['name']) ?></option>
              <?php endforeach; ?>
            </select></div>
          <div><label>Customer name</label><input type="text" name="customer_name" id="customer_name"></div>
          <div><label>Customer mobile (WhatsApp)</label><input type="tel" name="customer_mobile" id="customer_mobile"></div>
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
          <div><label>Shipping (₹)</label><input type="number" step="any" name="shipping" id="shipping" value="<?= $isEdit ? money($editSale['shipping']) : '0' ?>" oninput="Bill.totals()"></div>
          <?php if ($isEdit): ?>
          <div><label>Already paid</label><input type="text" value="₹<?= money($editSale['paid']) ?> (edit થી બદલાશે નહીં)" disabled></div>
          <?php else: ?>
          <div><label>Paid now (₹) <a href="javascript:payFull()" style="font-weight:normal">[full]</a></label><input type="number" step="any" name="paid" id="paid" value="0"></div>
          <div><label>Payment mode</label>
            <select name="payment_mode" id="payment_mode" onchange="pmChange()">
              <?php foreach ($pms as $pm): ?><option value="<?= e($pm['code']) ?>" data-type="<?= e($pm['type']) ?>"><?= e($pm['name']) ?></option><?php endforeach; ?>
              <option value="credit">Credit / Udhar</option>
            </select></div>
          <div id="bankAccBox" style="display:none"><label>Bank Account</label>
            <select name="bank_account_id">
              <?php foreach ($banks as $b): ?><option value="<?= $b['id'] ?>"><?= e($b['account_name']) ?> - <?= e($b['bank_name']) ?></option><?php endforeach; ?>
            </select></div>
          <?php endif; ?>
        </div>
        <div class="field"><label>Notes</label><input type="text" name="notes" <?= $isEdit ? 'value="' . e($editSale['notes']) . '"' : '' ?>></div>
        <div class="bill-totals">
          <div class="t-line"><span>Subtotal</span><span>₹ <span id="t_sub">0.00</span></span></div>
          <div class="t-line"><span>GST</span><span>₹ <span id="t_tax">0.00</span></span></div>
          <div class="t-line"><span>Shipping</span><span>₹ <span id="t_ship">0.00</span></span></div>
          <div class="t-line t-grand"><span>Total</span><span>₹ <span id="t_grand">0.00</span></span></div>
          <?php if (!$isEdit): ?><div class="t-line"><span>Balance due</span><span>₹ <span id="t_due">0.00</span></span></div><?php endif; ?>
        </div>
        <div class="form-row cols-2 mt">
          <?php if ($isEdit): ?>
          <a class="btn btn-outline" href="sale_view.php?id=<?= $editSale['id'] ?>">Cancel</a>
          <button class="btn" type="submit">💾 Update Bill</button>
          <?php else: ?>
          <button class="btn btn-outline" type="submit" name="save_new" value="1">Save & New</button>
          <button class="btn" type="submit">💾 Save</button>
          <?php endif; ?>
        </div>
      </div>
    </form>
    <script>
      Bill.init({mode: 'sale', serials: true, freeQty: true, locSel: 'location_id', gst: document.querySelector('#company_id option:checked').dataset.gst == 1<?= $isEdit ? ', editSaleId: ' . (int)$editSale['id'] : '' ?>});
      <?php if ($est && $estItems): ?>
      // prefill rows from estimate
      (function () {
        var pre = <?= json_encode(array_map(fn($x) => [
            'id' => (int)$x['item_id'], 'name' => $x['name'], 'qty' => (float)$x['qty'],
            'price' => (float)$x['price'], 'tax' => (float)$x['tax_rate'],
        ], $estItems)) ?>;
        document.getElementById('customer_name').value = <?= json_encode($est['customer_name']) ?>;
        document.getElementById('customer_mobile').value = <?= json_encode($est['customer_mobile']) ?>;
        <?php if ($est['party_id']): ?>document.getElementById('party_id').value = '<?= (int)$est['party_id'] ?>';<?php endif; ?>
        document.getElementById('discount_val').value = '<?= (float)$est['discount'] ?>';
        pre.forEach(function (it, idx) {
          if (idx > 0) Bill.addRow();
          var rows = document.querySelectorAll('#billItems .bill-row');
          var div = rows[rows.length - 1];
          div.querySelector('.i-search').value = it.name;
          div.querySelector('.i-id').value = it.id;
          div.querySelector('.i-tax').value = it.tax;
          div.querySelector('.i-qty').value = it.qty;
          div.querySelector('.i-price').value = it.price;
          Bill.rowTotal(div);
        });
      })();
      <?php endif; ?>
      <?php if ($isEdit): ?>
      // prefill rows from the existing bill being edited
      (function () {
        var pre = <?= json_encode(array_map(fn($x) => [
            'id' => (int)$x['item_id'], 'name' => $x['name'], 'qty' => (float)$x['qty'],
            'free' => (float)($x['free_qty'] ?? 0), 'price' => (float)$x['price'], 'tax' => (float)$x['tax_rate'],
            'serialTracked' => (int)$x['serial_tracked'],
            'serials' => $x['serials'] ? array_values(array_filter(array_map('trim', explode(',', $x['serials'])))) : [],
        ], $editItems)) ?>;
        document.getElementById('company_id').value = '<?= (int)$editSale['company_id'] ?>';
        document.getElementById('location_id').value = '<?= (int)$editSale['location_id'] ?>';
        document.getElementById('price_type').value = <?= json_encode($editSale['price_type']) ?>;
        document.getElementById('credit_days').value = '<?= (int)$editSale['credit_days'] ?>';
        document.getElementById('party_id').value = '<?= (int)$editSale['party_id'] ?>';
        document.getElementById('customer_name').value = <?= json_encode($editSale['customer_name']) ?>;
        document.getElementById('customer_mobile').value = <?= json_encode($editSale['customer_mobile']) ?>;
        document.querySelector('input[name=sale_date]').value = <?= json_encode($editSale['sale_date']) ?>;
        document.getElementById('discount_type').value = <?= json_encode($editSale['discount_type']) ?>;
        document.getElementById('discount_val').value = '<?= $editSale['discount_type'] === 'percent' ? (float)$editSale['discount_pct'] : (float)$editSale['discount'] ?>';
        document.getElementById('discAmt').className = <?= json_encode($editSale['discount_type']) ?> === 'amount' ? 'on-cash' : '';
        document.getElementById('discPct').className = <?= json_encode($editSale['discount_type']) ?> === 'percent' ? 'on-cash' : '';
        Bill.cfg.gst = document.querySelector('#company_id option:checked').dataset.gst == 1;
        pre.forEach(function (it, idx) {
          if (idx > 0) Bill.addRow();
          var rows = document.querySelectorAll('#billItems .bill-row');
          var div = rows[rows.length - 1];
          div.querySelector('.i-qty').value = it.qty;
          var fq = div.querySelector('.i-freeq'); if (fq) fq.value = it.free;
          if (it.serialTracked) {
            // route through pickItem so the Vyapar-style serial checkbox
            // picker renders (fetches this item's available + already-on-
            // this-bill serials) with the bill's current serials pre-checked
            div.dataset.preSerials = JSON.stringify(it.serials);
            Bill.pickItem(div, {id: it.id, name: it.name, tax_rate: it.tax, serial_tracked: 1,
              selling_price: it.price, b2b_price: it.price, purchase_price: it.price, stock: 0, unit: '', barcode: ''});
          } else {
            div.querySelector('.i-search').value = it.name;
            div.querySelector('.i-id').value = it.id;
            div.querySelector('.i-tax').value = it.tax;
            div.querySelector('.i-price').value = it.price;
          }
          Bill.rowTotal(div);
        });
        Bill.totals();
      })();
      <?php endif; ?>
      document.getElementById('company_id').addEventListener('change', function () {
        Bill.cfg.gst = this.options[this.selectedIndex].dataset.gst == 1;
        Bill.totals();
      });
      var ccMode = 'cash';
      function setCC(m) {
        ccMode = m;
        document.getElementById('ccCash').className = m === 'cash' ? 'on-cash' : '';
        document.getElementById('ccCredit').className = m === 'credit' ? 'on-credit' : '';
        var sel = document.getElementById('payment_mode');
        if (m === 'cash') { payFull(); if (sel.value === 'credit') sel.value = 'cash'; }
        else { document.getElementById('paid').value = 0; sel.value = 'credit'; Bill.totals(); }
        pmChange();
      }
      window.setCC = setCC;
      function pmChange() {
        var sel = document.getElementById('payment_mode');
        var opt = sel.options[sel.selectedIndex];
        document.getElementById('bankAccBox').style.display = opt.dataset.type === 'bank' ? '' : 'none';
        if (sel.value === 'credit' && ccMode !== 'credit') setCC('credit');
        else if (sel.value !== 'credit' && ccMode === 'credit') setCC('cash');
      }
      window.pmChange = pmChange;
      function setDiscType(t) {
        document.getElementById('discount_type').value = t;
        document.getElementById('discAmt').className = t === 'amount' ? 'on-cash' : '';
        document.getElementById('discPct').className = t === 'percent' ? 'on-cash' : '';
        Bill.totals();
      }
      window.setDiscType = setDiscType;
      // cash mode: paid follows total automatically
      var _origTotals = Bill.totals.bind(Bill);
      Bill.totals = function () {
        _origTotals();
        if (ccMode === 'cash') {
          var g = document.getElementById('t_grand'), p = document.getElementById('paid');
          if (g && p) { p.value = g.textContent; var d = document.getElementById('t_due'); if (d) d.textContent = '0.00'; }
        }
      };
      document.getElementById('party_id').addEventListener('change', function () {
        var o = this.options[this.selectedIndex];
        if (this.value) {
          document.getElementById('customer_name').value = o.textContent.trim();
          document.getElementById('customer_mobile').value = o.dataset.mobile || '';
          document.getElementById('credit_days').value = o.dataset.credit || 0;
        }
      });
    </script>
    <?php
    include __DIR__ . '/includes/footer.php';
    exit;
}

// ---------- list ----------
list($scope, $params) = own_scope('sales', 's.created_by');
$from = get('from', date('Y-m-01'));
$to = get('to', today());
$sales = all("SELECT s.*, c.name AS company_name, u2.name AS staff_name
              FROM sales s
              JOIN companies c ON c.id = s.company_id
              JOIN users u2 ON u2.id = s.created_by
              WHERE s.sale_date BETWEEN ? AND ? $scope
              ORDER BY s.id DESC LIMIT 500", array_merge([$from, $to], $params));
$sumTotal = array_sum(array_column($sales, 'total'));
$sumDue = (float)val("SELECT COALESCE(SUM(total - paid),0) FROM sales s WHERE s.status <> 'paid' AND s.is_cancelled = 0 " . str_replace('s.created_by', 'created_by', $scope), $params);
$page_title = 'Sales / Billing';
include __DIR__ . '/includes/header.php';
?>
<div class="duo-cards">
  <div class="duo-card" style="background:#e0f2fe"><div class="duo-label" style="color:#075985">Total Sale (period)</div><div class="duo-value" style="color:#0369a1">₹ <?= money($sumTotal) ?></div></div>
  <div class="duo-card duo-give"><div class="duo-label">Balance Due</div><div class="duo-value">₹ <?= money($sumDue) ?></div></div>
</div>
<div class="page-actions">
  <?php if (can('sales.add')): ?><a class="btn" href="sales.php?action=new">+ New Bill</a><?php endif; ?>
</div>
<form method="get" class="filterbar">
  <div><label>From</label><input type="date" name="from" value="<?= e($from) ?>"></div>
  <div><label>To</label><input type="date" name="to" value="<?= e($to) ?>"></div>
  <button class="btn btn-sm" type="submit">Filter</button>
</form>
<div class="list-count"><?= count($sales) ?> bills · Total ₹<?= money($sumTotal) ?></div>
<div class="table-wrap">
<table>
  <thead><tr><th>Invoice</th><th>Date</th><th>Customer</th><th>Firm</th><th class="num">Total</th><th>Status</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($sales as $s): ?>
    <tr>
      <td><a href="sale_view.php?id=<?= $s['id'] ?>"><strong><?= e($s['invoice_no']) ?></strong></a><br><span class="muted"><?= e($s['staff_name']) ?></span></td>
      <td><?= dmy($s['sale_date']) ?></td>
      <td><?= e($s['customer_name'] ?: 'Walk-in') ?><br><span class="muted"><?= e($s['customer_mobile']) ?></span></td>
      <td><?= e($s['company_name']) ?></td>
      <td class="num">₹<?= money($s['total']) ?></td>
      <td><?= $s['is_cancelled'] ? '<span class="badge badge-bad">CANCELLED</span>' : status_badge($s['status']) ?></td>
      <td><a class="btn btn-sm btn-outline" href="sale_view.php?id=<?= $s['id'] ?>">View</a></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
