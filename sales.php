<?php
// Sales / Billing - list + new bill (company-wise GST/non-GST, serials, credit)
require_once __DIR__ . '/includes/init.php';
require_perm('sales.view');
$u = current_user();

$action = get('action', 'list');

// Per-item custom fields (Settings > Transaction > Item Custom Fields) are
// posted as parallel arrays cf_<field id>[], indexed the same way as
// item_id[]/qty[]/price[] - collapsed here into one {label: value} JSON
// blob per row (only non-blank values, keyed by label text since that's
// what the invoice/edit form displays regardless of field id).
function sale_item_custom_data($activeCF, $i) {
    $d = [];
    foreach ($activeCF as $cf) {
        $v = trim((string)(post('cf_' . $cf['id'], [])[$i] ?? ''));
        if ($v !== '') $d[$cf['label']] = $v;
    }
    return $d ? json_encode($d) : null;
}

// ---------- save new bill ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'save') {
    require_perm('sales.add');
    if (is_period_locked(post('sale_date', today()))) { flash(period_lock_message(), 'error'); redirect('sales.php?action=new'); }
    $company = row('SELECT * FROM companies WHERE id = ?', [(int)post('company_id')]);
    $loc_id = (int)post('location_id') ?: $u['location_id'];
    if (locked_location_id()) $loc_id = locked_location_id(); // godown/shop manager bills only from their own place
    $item_ids = post('item_id', []);
    $qtys = post('qty', []);
    $prices = post('price', []);
    $taxes = post('tax_rate', []);
    $serialSel = post('serial_sel', []);
    $freeQtys = post('free_qty', []);
    $descriptions = post('description', []);
    $activeCF = all('SELECT id, label FROM item_custom_fields WHERE is_active = 1');

    $rowNs = post('row_n', []);
    $ldiscs = post('ldisc', []);
    $ldiscTs = post('ldisc_t', []);
    $locSel = post('line_loc', []); // optional per-line stock location (godown vs shop), 0 = bill's location
    $rows = [];
    foreach ($item_ids as $i => $iid) {
        $iid = (int)$iid;
        // Pair this item with ITS OWN serials by the row's stable id (row_n),
        // not by array position - positions shift when a row is deleted, which
        // used to hand one item another item's serial numbers.
        $n = (int)($rowNs[$i] ?? ($i + 1));
        $qty = (float)($qtys[$i] ?? 0);
        // Serial-tracked line: the number of serial numbers entered IS the
        // quantity (each serial = one physical unit). So 4 serials with qty
        // left at 1 is treated as qty 4 automatically instead of erroring.
        $rowSerials = array_values(array_filter(array_map('trim', (array)($serialSel[$n] ?? []))));
        if ($rowSerials) $qty = count($rowSerials);
        if (!$iid || $qty <= 0) continue;
        $price = (float)($prices[$i] ?? 0);
        $tr = $company['is_gst'] ? (float)($taxes[$i] ?? 0) : 0;
        // Per-item discount: a fixed rupee amount for the whole line, or a
        // percent of qty x rate - clamped so the line never goes negative.
        // The stored line total is NET of this discount (GST applies after).
        $ldType = ($ldiscTs[$i] ?? 'amount') === 'percent' ? 'percent' : 'amount';
        $ldVal = max(0, (float)($ldiscs[$i] ?? 0));
        $gross = $qty * $price;
        $ld = round(min($gross, $ldType === 'percent' ? $gross * $ldVal / 100 : $ldVal), 2);
        $rows[] = ['item_id' => $iid, 'qty' => $qty, 'free' => (float)($freeQtys[$i] ?? 0),
                   'price' => $price, 'tax_rate' => $tr, 'total' => $gross - $ld, 'n' => $n,
                   'ld_type' => $ldType, 'ld_val' => $ldVal, 'ld' => $ld, 'loc' => (int)($locSel[$i] ?? 0),
                   'description' => trim((string)($descriptions[$i] ?? '')), 'custom_data' => sale_item_custom_data($activeCF, $i)];
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
    $adjustment = (float)post('adjustment');
    $total = $subtotal - $discount + $tax + $shipping + $adjustment;
    // Round Off Total is computed server-side from the checkbox flag only
    // (never trusting a client-posted round-off amount) so it always
    // matches "nearest rupee" exactly, regardless of what the browser sent.
    $roundOff = 0;
    if (post('round_off_on') === '1') {
        $rounded = round($total);
        $roundOff = round($rounded - $total, 2);
        $total = $rounded;
    }

    // Loyalty points redemption - only for an explicitly picked party (one
    // auto-created below from a typed mobile number has 0 points anyway,
    // nothing to redeem). Re-checked against the party's real current
    // balance here - the client-side cap in the bill form is just UX and
    // is never trusted for the actual deduction.
    $loyaltyPointsUsed = 0; $loyaltyDiscount = 0;
    $redeemPartyId = (int)post('party_id') ?: null;
    if ($redeemPartyId && setting('loyalty_enabled') === '1') {
        $avail = (int)val('SELECT loyalty_points FROM parties WHERE id = ?', [$redeemPartyId]);
        $redeemValue = (float)setting('loyalty_redeem_value', '1');
        $maxByTotal = $redeemValue > 0 ? (int)floor($total / $redeemValue) : 0;
        $loyaltyPointsUsed = max(0, min((int)post('redeem_points'), $avail, $maxByTotal));
        $loyaltyDiscount = round($loyaltyPointsUsed * $redeemValue, 2);
        $total -= $loyaltyDiscount;
    }

    $paid = post('payment_mode') === 'credit' ? 0 : min((float)post('paid'), $total);
    $credit_days = (int)post('credit_days');
    list($pmId, $bankAccId) = resolve_payment_target(post('payment_mode', 'cash'), post('bank_account_id'));

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
           credit_days, due_date, subtotal, discount, loyalty_points_used, loyalty_discount, discount_type, discount_pct, tax_amount, shipping, adjustment, round_off, total, paid, payment_mode,
           bank_account_id, payment_method_id, status, notes, created_by, share_token)
           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
          [$company['id'], $party_id, post('customer_name'), post('customer_mobile'), $loc_id,
           post('sale_date', today()), post('price_type', 'retail'), $credit_days,
           // custom promised date wins; otherwise sale date + credit days
           preg_match('/^\d{4}-\d{2}-\d{2}$/', post('due_date')) ? post('due_date')
               : ($credit_days ? date('Y-m-d', strtotime(post('sale_date', today()) . " +$credit_days days")) : null),
           $subtotal, $discount, $loyaltyPointsUsed, $loyaltyDiscount, $discType, $discPct, $tax, $shipping, $adjustment, $roundOff, $total, $paid, post('payment_mode', 'cash'),
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
            // FIFO/weighted-average costing (Settings > Inventory) is
            // opt-in and additive - stock_layer_consume() returns null
            // (falling back to today's purchase price, same as before this
            // feature existed) whenever no cost layers have built up yet
            // for this item/location.
            // this line's stock comes from its own location (godown vs shop) -
            // a locked manager can never point a line at another location
            $rloc = locked_location_id() ?: ($r['loc'] ?: $loc_id);
            $costPrice = (float)$item['purchase_price'];
            if ($item['item_type'] !== 'service' && setting('costing_method', 'current') !== 'current') {
                $layerCost = stock_layer_consume($r['item_id'], $rloc, $r['qty'] + $r['free']);
                if ($layerCost !== null) $costPrice = $layerCost;
            }
            if (sale_line_loc_ready()) {
                q('INSERT INTO sale_items (sale_id, item_id, qty, free_qty, price, cost_price, tax_rate, total, line_disc_type, line_disc_val, line_disc, serials, description, custom_data, location_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                  [$sale_id, $r['item_id'], $r['qty'], $r['free'], $r['price'], $costPrice, $r['tax_rate'], $r['total'], $r['ld_type'], $r['ld_val'], $r['ld'], $serials ? implode(',', $serials) : null,
                   $r['description'], $r['custom_data'], $rloc]);
            } else {
                q('INSERT INTO sale_items (sale_id, item_id, qty, free_qty, price, cost_price, tax_rate, total, line_disc_type, line_disc_val, line_disc, serials, description, custom_data) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                  [$sale_id, $r['item_id'], $r['qty'], $r['free'], $r['price'], $costPrice, $r['tax_rate'], $r['total'], $r['ld_type'], $r['ld_val'], $r['ld'], $serials ? implode(',', $serials) : null,
                   $r['description'], $r['custom_data']]);
            }

            if ($item['item_type'] === 'service') { continue; } // service: no stock effect
            if (!$allowNeg && stock_available_qty($r['item_id'], $rloc) < $r['qty']) {
                throw new Exception("Not enough stock of {$item['name']} at the line's location.");
            }
            adjust_stock($r['item_id'], $rloc, -($r['qty'] + $r['free']), 'sale', $sale_id, $invoice_no);

            foreach ($serials as $sn) {
                $expiry = $item['warranty_months'] > 0
                    ? date('Y-m-d', strtotime(post('sale_date', today()) . ' +' . $item['warranty_months'] . ' months'))
                    : null;
                // Same tolerant rule as bill-edit: an in-stock serial gets sold;
                // an unknown serial is CREATED as sold (advance billing - the
                // vendor's bill arrives days later, and the purchase entry will
                // reconcile it); only a serial already sold on ANOTHER bill is
                // refused.
                $srow = row('SELECT id, status, sale_id FROM item_serials WHERE item_id=? AND serial_no=?', [$r['item_id'], $sn]);
                if ($srow) {
                    if ($srow['status'] !== 'in_stock') {
                        throw new Exception("Serial $sn has already been sold/used on another bill.");
                    }
                    q("UPDATE item_serials SET status='sold', sale_id=?, location_id=NULL, warranty_expiry=? WHERE id=?",
                      [$sale_id, $expiry, $srow['id']]);
                } else {
                    q("INSERT INTO item_serials (item_id, serial_no, status, sale_id, location_id, warranty_expiry, warranty_months)
                       VALUES (?,?,'sold',?,NULL,?,?)", [$r['item_id'], $sn, $sale_id, $expiry, (int)$item['warranty_months']]);
                }
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
        if ($loyaltyPointsUsed > 0) {
            loyalty_add($party_id, -$loyaltyPointsUsed, 'Redeemed on bill ' . $invoice_no, 'sale', $sale_id);
        }
        if ($party_id && setting('loyalty_enabled') === '1') {
            $earned = (int)floor($total * (float)setting('loyalty_earn_rate', '1') / 100);
            if ($earned > 0) loyalty_add($party_id, $earned, 'Earned on bill ' . $invoice_no, 'sale', $sale_id);
        }
        $pdo->commit();
        log_activity('sale_add', "$invoice_no total $total");
        fire_webhook('sale.created', ['sale_id' => $sale_id, 'invoice_no' => $invoice_no, 'total' => $total, 'paid' => $paid, 'customer_name' => post('customer_name')]);
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
    if ($sale && is_period_locked($sale['sale_date'])) { flash(period_lock_message(), 'error'); redirect('sales.php'); }
    if ($sale) {
        $pdo = db();
        $pdo->beginTransaction();
        foreach ($sale['is_cancelled'] ? [] : all('SELECT * FROM sale_items WHERE sale_id = ?', [$sid]) as $si) {
            $siLoc = (int)($si['location_id'] ?? 0) ?: (int)$sale['location_id'];
            adjust_stock($si['item_id'], $siLoc, (float)$si['qty'] + (float)($si['free_qty'] ?? 0), 'sale_delete', $sid);
            if ($si['serials']) {
                foreach (explode(',', $si['serials']) as $sn) {
                    q("UPDATE item_serials SET status='in_stock', sale_id=NULL, location_id=?, warranty_expiry=NULL
                       WHERE item_id=? AND serial_no=?", [$siLoc, $si['item_id'], trim($sn)]);
                }
            }
        }
        q("DELETE FROM payments WHERE ref_type = 'sale' AND ref_id = ?", [$sid]);
        if ($cancelOnly) {
            q('UPDATE sales SET is_cancelled = 1, paid = 0, status = ? WHERE id = ?', ['due', $sid]);
            log_activity('sale_cancel', $sale['invoice_no']);
            flash('Invoice ' . $sale['invoice_no'] . ' CANCELLED - stock restored, record kept.');
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
    if ($sale['is_cancelled']) { flash('A cancelled bill cannot be edited.', 'error'); redirect('sale_view.php?id=' . $sid); }
    if (is_period_locked($sale['sale_date']) || is_period_locked(post('sale_date', $sale['sale_date']))) {
        flash(period_lock_message(), 'error'); redirect('sale_view.php?id=' . $sid);
    }
    // 24-hour rule: staff edit a bill freely for 24h after it was created;
    // after that the submitted edit is parked for the admin, who approves it
    // on approvals.php (the exact form data is replayed there). The admin
    // (is_full_admin) always edits directly - including that replay.
    if (!is_full_admin() && strtotime($sale['created_at']) < time() - 86400) {
        try {
            q('INSERT INTO edit_requests (doc_type, doc_id, payload, requested_by) VALUES (?,?,?,?)',
              ['sale', $sid, json_encode($_POST, JSON_UNESCAPED_UNICODE), $u['id']]);
            log_activity('edit_request', 'sale ' . $sale['invoice_no']);
            try { tg_notify_admins('✏️ Bill edit approval\n' . $u['name'] . ' wants to change ' . $sale['invoice_no'] . "\n" . base_url('approvals.php')); } catch (Exception $e) { /* optional */ }
            flash('બિલ 24 કલાકથી જૂનું છે, એટલે તમારો ફેરફાર એડમિનની મંજૂરી માટે મોકલાયો છે. મંજૂર થાય એટલે આપોઆપ લાગુ થઈ જશે.', 'info');
        } catch (Exception $e) {
            flash('Edit request could not be saved - run Settings → Migrate first.', 'error');
        }
        redirect('sale_view.php?id=' . $sid);
    }

    $oldItems = all('SELECT * FROM sale_items WHERE sale_id = ?', [$sid]);
    foreach ($oldItems as $oi) {
        if (!$oi['serials']) continue;
        foreach (explode(',', $oi['serials']) as $sn) {
            $srow = row('SELECT status FROM item_serials WHERE item_id = ? AND serial_no = ?', [$oi['item_id'], trim($sn)]);
            if ($srow && $srow['status'] !== 'sold') {
                flash('Serial ' . trim($sn) . ' on this bill has already gone through a warranty/return, so it cannot be edited. Cancel and create a new bill instead.', 'error');
                redirect('sale_view.php?id=' . $sid);
            }
        }
    }

    $company = row('SELECT * FROM companies WHERE id = ?', [(int)post('company_id')]);
    $loc_id = (int)post('location_id') ?: $u['location_id'];
    if (locked_location_id()) $loc_id = locked_location_id(); // godown/shop manager bills only from their own place
    $item_ids = post('item_id', []);
    $qtys = post('qty', []);
    $prices = post('price', []);
    $taxes = post('tax_rate', []);
    $serialSel = post('serial_sel', []);
    $freeQtys = post('free_qty', []);
    $descriptions = post('description', []);
    $activeCF = all('SELECT id, label FROM item_custom_fields WHERE is_active = 1');

    $rowNs = post('row_n', []);
    $ldiscs = post('ldisc', []);
    $ldiscTs = post('ldisc_t', []);
    $locSel = post('line_loc', []); // optional per-line stock location (godown vs shop), 0 = bill's location
    $rows = [];
    foreach ($item_ids as $i => $iid) {
        $iid = (int)$iid;
        // Pair this item with ITS OWN serials by the row's stable id (row_n),
        // not by array position - positions shift when a row is deleted, which
        // used to hand one item another item's serial numbers.
        $n = (int)($rowNs[$i] ?? ($i + 1));
        $qty = (float)($qtys[$i] ?? 0);
        // Serial-tracked line: the number of serial numbers entered IS the
        // quantity (each serial = one physical unit). So 4 serials with qty
        // left at 1 is treated as qty 4 automatically instead of erroring.
        $rowSerials = array_values(array_filter(array_map('trim', (array)($serialSel[$n] ?? []))));
        if ($rowSerials) $qty = count($rowSerials);
        if (!$iid || $qty <= 0) continue;
        $price = (float)($prices[$i] ?? 0);
        $tr = $company['is_gst'] ? (float)($taxes[$i] ?? 0) : 0;
        // Per-item discount: a fixed rupee amount for the whole line, or a
        // percent of qty x rate - clamped so the line never goes negative.
        // The stored line total is NET of this discount (GST applies after).
        $ldType = ($ldiscTs[$i] ?? 'amount') === 'percent' ? 'percent' : 'amount';
        $ldVal = max(0, (float)($ldiscs[$i] ?? 0));
        $gross = $qty * $price;
        $ld = round(min($gross, $ldType === 'percent' ? $gross * $ldVal / 100 : $ldVal), 2);
        $rows[] = ['item_id' => $iid, 'qty' => $qty, 'free' => (float)($freeQtys[$i] ?? 0),
                   'price' => $price, 'tax_rate' => $tr, 'total' => $gross - $ld, 'n' => $n,
                   'ld_type' => $ldType, 'ld_val' => $ldVal, 'ld' => $ld, 'loc' => (int)($locSel[$i] ?? 0),
                   'description' => trim((string)($descriptions[$i] ?? '')), 'custom_data' => sale_item_custom_data($activeCF, $i)];
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
    $adjustment = (float)post('adjustment');
    $total = $subtotal - $discount + $tax + $shipping + $adjustment;
    $roundOff = 0;
    if (post('round_off_on') === '1') {
        $rounded = round($total);
        $roundOff = round($rounded - $total, 2);
        $total = $rounded;
    }
    // Paid amount is now editable (staff often need to correct a mistyped
    // amount) - clamped to [0, total]. The DIFFERENCE from the old paid
    // value is posted as its own payments-ledger entry (direction 'in' for
    // an increase, 'out' for a decrease) so the party balance and cash/bank
    // books - which are computed from the payments table, not sales.paid -
    // stay in sync instead of silently drifting from the invoice.
    $paid = max(0, min((float)post('paid', $sale['paid']), $total));
    $paidDelta = round($paid - (float)$sale['paid'], 2);
    $modeCode = post('payment_mode', $sale['payment_mode'] ?: 'cash');
    list($pmId, $bankAccId) = resolve_payment_target($modeCode, post('bank_account_id'));
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
        $advanceRestored = []; // serials born ON this bill (advance billing, no purchase behind them) that the restore put back "in stock"
        foreach ($oldItems as $oi) {
            // restore to the location the line actually came from (per-line
            // godown/shop), falling back to the bill's location for old rows
            $oiLoc = (int)($oi['location_id'] ?? 0) ?: (int)$sale['location_id'];
            adjust_stock($oi['item_id'], $oiLoc, (float)$oi['qty'] + (float)($oi['free_qty'] ?? 0), 'sale_edit', $sid);
            if ($oi['serials']) {
                foreach (explode(',', $oi['serials']) as $sn) {
                    $sn = trim($sn);
                    $srowOld = row('SELECT purchase_id FROM item_serials WHERE item_id=? AND serial_no=? AND sale_id=?', [$oi['item_id'], $sn, $sid]);
                    if ($srowOld && $srowOld['purchase_id'] === null) $advanceRestored[] = [$oi['item_id'], $sn];
                    q("UPDATE item_serials SET status='in_stock', sale_id=NULL, location_id=?, warranty_expiry=NULL
                       WHERE item_id=? AND serial_no=?", [$oiLoc, $oi['item_id'], $sn]);
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
            $rloc = locked_location_id() ?: ($r['loc'] ?: $loc_id);
            if (sale_line_loc_ready()) {
                q('INSERT INTO sale_items (sale_id, item_id, qty, free_qty, price, cost_price, tax_rate, total, line_disc_type, line_disc_val, line_disc, serials, description, custom_data, location_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                  [$sid, $r['item_id'], $r['qty'], $r['free'], $r['price'], (float)$item['purchase_price'], $r['tax_rate'], $r['total'], $r['ld_type'], $r['ld_val'], $r['ld'], $serials ? implode(',', $serials) : null,
                   $r['description'], $r['custom_data'], $rloc]);
            } else {
                q('INSERT INTO sale_items (sale_id, item_id, qty, free_qty, price, cost_price, tax_rate, total, line_disc_type, line_disc_val, line_disc, serials, description, custom_data) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                  [$sid, $r['item_id'], $r['qty'], $r['free'], $r['price'], (float)$item['purchase_price'], $r['tax_rate'], $r['total'], $r['ld_type'], $r['ld_val'], $r['ld'], $serials ? implode(',', $serials) : null,
                   $r['description'], $r['custom_data']]);
            }

            if ($item['item_type'] === 'service') { continue; }
            if (!$allowNeg && stock_available_qty($r['item_id'], $rloc) < $r['qty']) {
                throw new Exception("Not enough stock of {$item['name']} at the line's location.");
            }
            adjust_stock($r['item_id'], $rloc, -($r['qty'] + $r['free']), 'sale_edit', $sid, $sale['invoice_no']);

            foreach ($serials as $sn) {
                $expiry = $item['warranty_months'] > 0
                    ? date('Y-m-d', strtotime($sale_date . ' +' . $item['warranty_months'] . ' months'))
                    : null;
                // Re-attach the serial to this bill. We already reversed this
                // bill's own serials to in_stock above, so normally it's back
                // in stock now. Be tolerant so a price-only edit never fails on
                // serials: accept a serial that's in stock OR already sold to
                // THIS same bill (regardless of which godown), and only reject
                // one that has genuinely moved on (sold on another bill /
                // returned / warranty). The old code additionally required an
                // exact location match, which wrongly errored ("Serial X is not
                // available in stock") whenever the posted godown didn't line up
                // with where the serial was reversed to.
                $srow = row("SELECT id, status, sale_id FROM item_serials WHERE item_id=? AND serial_no=?", [$r['item_id'], $sn]);
                if ($srow) {
                    $onThisBill = ($srow['status'] === 'sold' && (int)$srow['sale_id'] === $sid);
                    if ($srow['status'] !== 'in_stock' && !$onThisBill) {
                        throw new Exception("Serial $sn has already been sold or returned on another bill, so it can't be added here.");
                    }
                    q("UPDATE item_serials SET status='sold', sale_id=?, location_id=NULL, warranty_expiry=? WHERE id=?",
                      [$sid, $expiry, $srow['id']]);
                } else {
                    // The bill references this serial but its stock row is gone
                    // (e.g. the purchase that created it was later deleted/edited).
                    // Reconcile by recreating the record, marked sold to this
                    // bill, so editing the bill never dead-ends on missing serial
                    // bookkeeping - the physical unit was, after all, sold here.
                    q("INSERT INTO item_serials (item_id, serial_no, status, sale_id, location_id, warranty_expiry, warranty_months)
                       VALUES (?,?,'sold',?,NULL,?,?)",
                      [$r['item_id'], $sn, $sid, $expiry, (int)$item['warranty_months']]);
                }
            }
        }

        // Phantom-serial cleanup: an advance-billing serial was CREATED on
        // this bill (typed/scanned at sale time, no purchase behind it). If
        // the edit swapped it for a different serial, the restore above left
        // it lying "in stock" even though no stock quantity backs it - that's
        // how in-stock serial counts drift above the stock figure. Delete
        // such a dropped serial, but only while the item's in-stock serial
        // count actually exceeds its stock quantity (if real stock backs it,
        // e.g. the qty came back too, it stays).
        foreach ($advanceRestored as [$aiId, $aSn]) {
            $stillOnBill = row('SELECT id FROM item_serials WHERE item_id=? AND serial_no=? AND sale_id=?', [$aiId, $aSn, $sid]);
            if ($stillOnBill) continue;
            $stockQ = (float)(val('SELECT SUM(qty) FROM stock WHERE item_id=?', [$aiId]) ?? 0);
            $snCount = (int)val("SELECT COUNT(*) FROM item_serials WHERE item_id=? AND status='in_stock'", [$aiId]);
            if ($snCount > $stockQ) {
                q("DELETE FROM item_serials WHERE item_id=? AND serial_no=? AND status='in_stock' AND purchase_id IS NULL", [$aiId, $aSn]);
            }
        }

        q('UPDATE sales SET company_id=?, party_id=?, customer_name=?, customer_mobile=?, location_id=?, sale_date=?, price_type=?,
           credit_days=?, due_date=?, subtotal=?, discount=?, discount_type=?, discount_pct=?, tax_amount=?, shipping=?, adjustment=?, round_off=?, total=?, paid=?,
           payment_mode=?, payment_method_id=?, bank_account_id=?, status=?, notes=? WHERE id=?',
          [$company['id'], $party_id, post('customer_name'), post('customer_mobile'), $loc_id,
           $sale_date, post('price_type', 'retail'), $credit_days,
           preg_match('/^\d{4}-\d{2}-\d{2}$/', post('due_date')) ? post('due_date')
               : ($credit_days ? date('Y-m-d', strtotime("$sale_date +$credit_days days")) : null),
           $subtotal, $discount, $discType, $discPct, $tax, $shipping, $adjustment, $roundOff, $total, $paid,
           $modeCode, $pmId, $bankAccId, payment_status($total, $paid), post('notes'), $sid]);

        // Mode/bank changed on the bill itself → move this bill's existing
        // ledger entries with it, so the money shows in the right cashbook/
        // bank. Switching TO credit doesn't rewrite anything: money already
        // received stays in whichever book it actually arrived in.
        if ($modeCode !== 'credit' && ($modeCode !== $sale['payment_mode'] || (int)$bankAccId != (int)$sale['bank_account_id'])) {
            q("UPDATE payments SET mode = ?, bank_account_id = ?, payment_method_id = ? WHERE ref_type = 'sale' AND ref_id = ?",
              [$modeCode, $bankAccId, $pmId, $sid]);
        }

        if (abs($paidDelta) > 0.009) {
            // 'credit' is not a money book - a paid correction on a credit
            // bill still moved real money, count it as cash
            $deltaMode = $modeCode === 'credit' ? 'cash' : $modeCode;
            q('INSERT INTO payments (party_id, direction, amount, mode, bank_account_id, payment_method_id, ref_type, ref_id, pay_date, notes, created_by)
               VALUES (?,?,?,?,?,?,?,?,?,?,?)',
              [$party_id, $paidDelta > 0 ? 'in' : 'out', abs($paidDelta), $deltaMode,
               $deltaMode === $modeCode ? $bankAccId : null, $deltaMode === $modeCode ? $pmId : null,
               'sale', $sid, today(), 'Paid amount adjusted on edit of ' . $sale['invoice_no'], $u['id']]);
        }

        $pdo->commit();
        log_activity('sale_edit', "{$sale['invoice_no']} total $total");
        flash("Bill {$sale['invoice_no']} updated.");
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
        if ($editSale['is_cancelled']) { flash('A cancelled bill cannot be edited.', 'error'); redirect('sale_view.php?id=' . $editSale['id']); }
        $editItems = all('SELECT si.*, i.name, i.serial_tracked FROM sale_items si JOIN items i ON i.id = si.item_id WHERE si.sale_id = ?', [$editSale['id']]);
    }
    $parties = all("SELECT id, name, mobile, credit_days, loyalty_points FROM parties WHERE is_active = 1 ORDER BY name");
    $customFields = all('SELECT id, label FROM item_custom_fields WHERE is_active = 1 ORDER BY sort_order, id');
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
    } elseif (!$isEdit && (int)get('copy')) {
        // Duplicate bill: same party + items prefilled as a FRESH bill
        // (today's date, serials re-picked) - "same as last time" in one tap
        $srcCopy = row('SELECT * FROM sales WHERE id = ?', [(int)get('copy')]);
        if ($srcCopy && (can('sales.all') || $srcCopy['created_by'] == $u['id'])) {
            $estItems = all('SELECT si.item_id, si.qty, si.price, si.tax_rate, i.name FROM sale_items si JOIN items i ON i.id = si.item_id WHERE si.sale_id = ? AND si.qty > 0', [$srcCopy['id']]);
            $est = ['id' => 0, 'estimate_no' => 'copy of ' . $srcCopy['invoice_no'], 'customer_name' => $srcCopy['customer_name'],
                    'customer_mobile' => $srcCopy['customer_mobile'], 'party_id' => $srcCopy['party_id'], 'discount' => (float)$srcCopy['discount']];
        }
    }
    $page_title = $isEdit ? 'Edit Bill ' . $editSale['invoice_no'] : 'New Bill';
    include __DIR__ . '/includes/header.php';
    ?>
    <?php if ($est): ?><div class="flash flash-info">Converting <?= e($est['estimate_no']) ?> — serial-tracked items will need their serial re-selected.</div><?php endif; ?>
    <?php if ($isEdit && array_filter($editItems, fn($it) => $it['serials'])): ?><div class="flash flash-info">Serial-tracked items' serial numbers will need to be re-selected.</div><?php endif; ?>
    <?php if ($isEdit && !is_full_admin() && strtotime($editSale['created_at']) < time() - 86400): ?><div class="flash flash-info">⏳ આ બિલ 24 કલાકથી જૂનું છે — સેવ કરશો એટલે ફેરફાર સીધો લાગુ નહીં થાય, એડમિનની મંજૂરી માટે જશે.</div><?php endif; ?>
    <form method="post" id="billForm">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="<?= $isEdit ? 'update' : 'save' ?>">
      <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= $editSale['id'] ?>"><?php endif; ?>
      <?php if (!$isEdit): ?>
      <div class="page-actions" style="justify-content:center">
        <div class="cc-toggle">
          <button type="button" id="ccCredit" class="<?= setting('cash_sale_default', '1') === '1' ? '' : 'on-credit' ?>" onclick="setCC('credit')">Credit</button>
          <button type="button" id="ccCash" class="<?= setting('cash_sale_default', '1') === '1' ? 'on-cash' : '' ?>" onclick="setCC('cash')">Cash</button>
        </div>
      </div>
      <?php endif; ?>
      <?php if ($est && $est['id']): ?><input type="hidden" name="estimate_id" value="<?= $est['id'] ?>"><?php endif; ?>
      <?php if ($chal): ?><input type="hidden" name="challan_id" value="<?= $chal['id'] ?>"><?php endif; ?>
      <div class="card">
        <div class="form-row cols-3">
          <div><label>Invoice No.</label><input type="text" value="<?= $isEdit ? e($editSale['invoice_no']) : 'Auto' ?>" disabled></div>
          <div><label>Date</label><input type="date" name="sale_date" value="<?= today() ?>"></div>
          <div><label>Time</label><input type="text" id="saleTimeDisplay" value="<?= date('h:i A') ?>" disabled></div>
        </div>
        <div class="form-row cols-2">
          <div><label>Firm Name</label>
            <select name="company_id" id="company_id">
              <?php foreach ($companies as $c): ?>
              <option value="<?= $c['id'] ?>" data-gst="<?= $c['is_gst'] ?>"><?= e($c['name']) ?><?= $c['is_gst'] ? ' (GST)' : '' ?></option>
              <?php endforeach; ?>
            </select></div>
          <div><label>Godown Name</label>
            <select name="location_id" id="location_id">
              <?php foreach ($locations as $l): ?>
              <option value="<?= $l['id'] ?>" <?= $l['id'] == $u['location_id'] ? 'selected' : '' ?>><?= e($l['name']) ?></option>
              <?php endforeach; ?>
            </select></div>
        </div>
        <div class="form-row cols-2">
          <div><label>Pmt. Terms</label>
            <select name="credit_days" id="credit_days">
              <?php foreach ($terms as $t): ?><option value="<?= $t['days'] ?>"><?= e($t['label']) ?></option><?php endforeach; ?>
            </select></div>
          <div><label>Due On <span class="muted" style="font-weight:normal">(તારીખ જાતે પણ નાખી શકો)</span></label>
            <input type="date" name="due_date" id="due_date"></div>
        </div>
        <div class="field"><label>Price type</label>
          <select name="price_type" id="price_type"><option value="retail">Retail</option><option value="b2b">B2B</option></select></div>
        <div class="field"><label>Party
            <span class="muted" id="partyReqHint" style="font-weight:normal;color:var(--bad);display:none">— required for a Credit bill</span>
            <?php if (can('parties.add')): ?><a href="#" onclick="document.getElementById('qpModal').style.display='block';document.getElementById('qp_name').focus();return false" style="float:right;font-weight:normal">+ New party</a><?php endif; ?></label>
          <select name="party_id" id="party_id">
            <option value="">-- Walk-in customer --</option>
            <?php foreach ($parties as $p): ?>
            <option value="<?= $p['id'] ?>" data-mobile="<?= e($p['mobile']) ?>" data-credit="<?= $p['credit_days'] ?>" data-points="<?= (int)$p['loyalty_points'] ?>"><?= e($p['name']) ?></option>
            <?php endforeach; ?>
          </select></div>
        <?php if (can('parties.add')): ?>
        <div id="qpModal" style="display:none;border:1px solid var(--border);border-radius:10px;padding:12px;margin-bottom:10px;background:var(--bg)">
          <strong>Quick add new party</strong>
          <div class="form-row cols-3" style="margin-top:8px">
            <div><input type="text" id="qp_name" placeholder="Party name *"></div>
            <div><input type="tel" id="qp_mobile" placeholder="Mobile"></div>
            <div><input type="text" id="qp_gstin" placeholder="GSTIN (optional)"></div>
          </div>
          <div class="page-actions" style="margin-top:8px">
            <button type="button" class="btn btn-sm" onclick="quickParty()">Add &amp; select</button>
            <button type="button" class="btn btn-sm btn-outline" onclick="document.getElementById('qpModal').style.display='none'">Cancel</button>
          </div>
        </div>
        <?php endif; ?>
        <div class="field"><label>Customer</label><input type="text" name="customer_name" id="customer_name" placeholder="Customer name (leave blank for walk-in)"></div>
        <div class="field"><label>Phone Number</label><input type="tel" name="customer_mobile" id="customer_mobile" placeholder="WhatsApp number"></div>
      </div>

      <div class="card">
        <h3>Items</h3>
        <div class="bill-summary-list" id="billSummaryList"></div>
        <div class="page-actions">
          <button type="button" class="btn btn-outline btn-sm" id="addItemsBtn">+ Add Items <span class="muted" style="font-weight:normal">(optional)</span></button>
          <button type="button" class="btn btn-outline btn-sm" id="scanItemBtn" style="display:none">📷 Scan</button>
        </div>
        <div class="bill-items" id="billItems" style="display:none"></div>
      </div>

      <!-- "second page" - the Vyapar-style full-screen Add Item panel -->
      <div class="add-item-panel" id="addItemPanel">
        <div class="aip-header">
          <button type="button" id="aipClose">←</button>
          <h2>Add Items to Sale</h2>
        </div>
        <div class="aip-body" id="aipBody">
          <div class="aip-totals" id="aipTotalsBox">
            <h4>Totals &amp; Taxes</h4>
            <div class="t-line"><span>Subtotal (Rate x Qty)</span><span>₹ <span class="aip-sub">0.00</span></span></div>
          </div>
        </div>
        <div class="aip-footer">
          <button type="button" class="aip-savenew" id="aipSaveNew">Save &amp; New</button>
          <button type="button" class="aip-save" id="aipSave">Save</button>
        </div>
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
          <div><label>Shipping (₹)</label><input type="number" step="any" name="shipping" id="shipping" value="<?= $isEdit ? 0 + $editSale['shipping'] : '0' ?>" oninput="Bill.totals()"></div>
          <div><label>Adjustment (₹, +/-)</label><input type="number" step="any" name="adjustment" id="adjustment" value="<?= $isEdit ? 0 + $editSale['adjustment'] : '0' ?>" oninput="Bill.totals()"></div>
          <?php if (!$isEdit && setting('loyalty_enabled') === '1'): ?>
          <div><label>⭐ Redeem Points <span class="muted" id="pointsAvail" style="font-weight:normal"></span></label>
            <input type="number" step="1" min="0" name="redeem_points" id="redeem_points" value="0" oninput="Bill.totals()"></div>
          <?php endif; ?>
          <?php if ($isEdit): ?>
          <div><label>Paid (₹)</label><input type="number" step="any" name="paid" id="paid" value="<?= 0 + $editSale['paid'] ?>" max="<?= 0 + $editSale['total'] ?>"></div>
          <div><label>Payment mode</label>
            <select name="payment_mode" id="payment_mode" onchange="pmChange()">
              <?php foreach ($pms as $pm): ?><option value="<?= e($pm['code']) ?>" data-type="<?= e($pm['type']) ?>" <?= $pm['code'] === $editSale['payment_mode'] ? 'selected' : '' ?>><?= e($pm['name']) ?></option><?php endforeach; ?>
              <?php if (!in_array('credit', array_column($pms, 'code'), true)): ?><option value="credit" <?= $editSale['payment_mode'] === 'credit' ? 'selected' : '' ?>>Credit / Udhar</option><?php endif; ?>
            </select></div>
          <div id="bankAccBox" style="display:none"><label>Bank Account</label>
            <select name="bank_account_id">
              <?php foreach ($banks as $b): ?><option value="<?= $b['id'] ?>" <?= (int)$editSale['bank_account_id'] === (int)$b['id'] ? 'selected' : '' ?>><?= e($b['account_name']) ?> - <?= e($b['bank_name']) ?></option><?php endforeach; ?>
            </select></div>
          <?php else: ?>
          <div><label>Paid now (₹) <a href="javascript:payFull()" style="font-weight:normal">[full]</a></label><input type="number" step="any" name="paid" id="paid" value="0"></div>
          <div><label>Payment mode</label>
            <select name="payment_mode" id="payment_mode" onchange="pmChange()">
              <?php foreach ($pms as $pm): ?><option value="<?= e($pm['code']) ?>" data-type="<?= e($pm['type']) ?>"><?= e($pm['name']) ?></option><?php endforeach; ?>
              <option value="credit" <?= setting('cash_sale_default', '1') === '1' ? '' : 'selected' ?>>Credit / Udhar</option>
            </select></div>
          <div id="bankAccBox" style="display:none"><label>Bank Account</label>
            <select name="bank_account_id">
              <?php foreach ($banks as $b): ?><option value="<?= $b['id'] ?>"><?= e($b['account_name']) ?> - <?= e($b['bank_name']) ?></option><?php endforeach; ?>
            </select></div>
          <?php endif; ?>
        </div>
        <div class="field"><label>Notes</label><input type="text" name="notes" <?= $isEdit ? 'value="' . e($editSale['notes']) . '"' : '' ?>></div>
        <div class="bill-totals">
          <div class="t-line"><span>Items</span><span id="t_items">0 items · 0 qty</span></div>
          <div class="t-line" id="ldiscRow" style="display:none"><span>Item Discounts</span><span>- ₹ <span id="t_ldisc">0.00</span></span></div>
          <div class="t-line"><span>Subtotal</span><span>₹ <span id="t_sub">0.00</span></span></div>
          <div class="t-line"><span>GST</span><span>₹ <span id="t_tax">0.00</span></span></div>
          <div class="t-line"><span>Shipping</span><span>₹ <span id="t_ship">0.00</span></span></div>
          <div class="t-line" id="adjRow" style="display:none"><span>Adjustment</span><span>₹ <span id="t_adj">0.00</span></span></div>
          <?php if (!$isEdit && setting('loyalty_enabled') === '1'): ?>
          <div class="t-line" id="loyaltyRow" style="display:none"><span>⭐ Points Discount</span><span>- ₹ <span id="t_loyalty">0.00</span></span></div>
          <?php endif; ?>
          <div class="t-line" id="roundRow" style="display:none"><span>Round Off</span><span>₹ <span id="t_round">0.00</span></span></div>
          <div class="t-line t-grand"><span>Total</span><span>₹ <span id="t_grand">0.00</span></span></div>
          <?php if (!$isEdit && setting('show_profit_billing') === '1' && can('items.cost')): ?>
          <div class="t-line" id="profitRow"><span>Estimated Profit</span><span>₹ <span id="t_profit">0.00</span></span></div>
          <?php endif; ?>
          <label class="check-inline"><input type="checkbox" name="round_off_on" id="round_off_chk" value="1" <?= (!$isEdit && setting('round_off_default', '1') === '1') || ($isEdit && abs((float)$editSale['round_off']) > 0.004) ? 'checked' : '' ?> onchange="Bill.totals()"> Round Off Total</label>
          <input type="hidden" name="round_off" id="round_off" value="0">
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
      Bill.init({mode: 'sale', serials: true, freeQty: true, lineDisc: true, locSel: 'location_id', gst: document.querySelector('#company_id option:checked').dataset.gst == 1,
        locations: <?= json_encode(locked_location_id() || count($locations) < 2 ? [] : array_map(fn($l) => ['id' => (int)$l['id'], 'name' => $l['name']], $locations)) ?>,
        showPurchasePrice: <?= json_encode(setting('show_purchase_price_billing') === '1' && can('items.cost')) ?>,
        customFields: <?= json_encode(array_map(fn($f) => ['id' => $f['id'], 'label' => $f['label']], $customFields)) ?><?= $isEdit ? ', editSaleId: ' . (int)$editSale['id'] : '' ?>});
      // barcode scan shortcut next to "+ Add Items" - opens a fresh item
      // row already in the panel and starts the camera scan immediately
      var scanItemBtn = document.getElementById('scanItemBtn');
      if (scanItemBtn && 'BarcodeDetector' in window) {
        scanItemBtn.style.display = '';
        scanItemBtn.addEventListener('click', function () {
          var div = Bill.openAddPanel();
          Bill.scanBarcode(div.querySelector('.i-search'));
        });
      }
      // "Due On" auto-fills from Date + Pmt. Terms, but it's a real date
      // field: if the customer promises a specific day ("15 તારીખે આપીશ"),
      // type that date and it becomes the bill's due date for the auto
      // overdue reminders. Changing the terms recomputes it again.
      function updateDueOn() {
        var days = parseInt(document.getElementById('credit_days').value, 10) || 0;
        var dateInp = document.querySelector('input[name=sale_date]');
        var due = document.getElementById('due_date');
        if (!dateInp.value || !days) { due.value = ''; return; }
        var d = new Date(dateInp.value + 'T00:00:00');
        d.setDate(d.getDate() + days);
        due.value = d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2);
      }
      document.getElementById('credit_days').addEventListener('change', updateDueOn);
      document.querySelector('input[name=sale_date]').addEventListener('change', updateDueOn);
      <?php if (!$editSale): ?>updateDueOn();<?php endif; ?>
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
        pre.forEach(function (it) {
          var div = Bill.addRow();
          div.querySelector('.i-search').value = it.name;
          div.querySelector('.i-id').value = it.id;
          div.querySelector('.i-tax').value = it.tax;
          div.querySelector('.i-qty').value = it.qty;
          div.querySelector('.i-price').value = it.price;
          Bill.rowTotal(div);
        });
        Bill.renderSummary();
      })();
      <?php endif; ?>
      <?php if ($isEdit): ?>
      // prefill rows from the existing bill being edited
      (function () {
        var pre = <?= json_encode(array_map(fn($x) => [
            'id' => (int)$x['item_id'], 'name' => $x['name'], 'qty' => (float)$x['qty'],
            'free' => (float)($x['free_qty'] ?? 0), 'price' => (float)$x['price'], 'tax' => (float)$x['tax_rate'],
            'ldiscVal' => (float)($x['line_disc_val'] ?? 0), 'ldiscType' => $x['line_disc_type'] ?? 'amount',
            'serialTracked' => (int)$x['serial_tracked'],
            'serials' => $x['serials'] ? array_values(array_filter(array_map('trim', explode(',', $x['serials'])))) : [],
            'description' => $x['description'] ?? '',
            'loc' => (int)($x['location_id'] ?? 0),
            'customData' => $x['custom_data'] ? json_decode($x['custom_data'], true) : [],
        ], $editItems)) ?>;
        document.getElementById('company_id').value = '<?= (int)$editSale['company_id'] ?>';
        document.getElementById('location_id').value = '<?= (int)$editSale['location_id'] ?>';
        document.getElementById('price_type').value = <?= json_encode($editSale['price_type']) ?>;
        document.getElementById('credit_days').value = '<?= (int)$editSale['credit_days'] ?>';
        document.getElementById('due_date').value = <?= json_encode($editSale['due_date'] ?: '') ?>;
        document.getElementById('party_id').value = '<?= (int)$editSale['party_id'] ?>';
        document.getElementById('customer_name').value = <?= json_encode($editSale['customer_name']) ?>;
        document.getElementById('customer_mobile').value = <?= json_encode($editSale['customer_mobile']) ?>;
        document.querySelector('input[name=sale_date]').value = <?= json_encode($editSale['sale_date']) ?>;
        document.getElementById('discount_type').value = <?= json_encode($editSale['discount_type']) ?>;
        document.getElementById('discount_val').value = '<?= $editSale['discount_type'] === 'percent' ? (float)$editSale['discount_pct'] : (float)$editSale['discount'] ?>';
        document.getElementById('discAmt').className = <?= json_encode($editSale['discount_type']) ?> === 'amount' ? 'on-cash' : '';
        document.getElementById('discPct').className = <?= json_encode($editSale['discount_type']) ?> === 'percent' ? 'on-cash' : '';
        Bill.cfg.gst = document.querySelector('#company_id option:checked').dataset.gst == 1;
        pre.forEach(function (it) {
          var div = Bill.addRow();
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
          var descInp = div.querySelector('.i-desc'); if (descInp) descInp.value = it.description;
          var lsel = div.querySelector('.i-loc'); if (lsel && it.loc) lsel.value = it.loc;
          var ldi = div.querySelector('.i-ldisc'); if (ldi) ldi.value = it.ldiscVal || 0;
          var ldts = div.querySelector('.i-ldisct'); if (ldts) ldts.value = it.ldiscType || 'amount';
          div.querySelectorAll('.i-cf').forEach(function (cf) {
            if (it.customData && it.customData[cf.dataset.label] !== undefined) cf.value = it.customData[cf.dataset.label];
          });
          Bill.rowTotal(div);
        });
        Bill.renderSummary();
        Bill.totals();
      })();
      <?php endif; ?>
      document.getElementById('company_id').addEventListener('change', function () {
        Bill.cfg.gst = this.options[this.selectedIndex].dataset.gst == 1;
        Bill.totals();
      });
      var ccMode = <?= json_encode(!$isEdit && setting('cash_sale_default', '1') !== '1' ? 'credit' : 'cash') ?>;
      // When editing an existing bill the paid amount must NOT auto-follow the
      // total - the user types/spreads it manually (partial payments, udhar).
      var SALE_EDIT = <?= $isEdit ? 'true' : 'false' ?>;
      function setCC(m) {
        ccMode = m;
        document.getElementById('ccCash').className = m === 'cash' ? 'on-cash' : '';
        document.getElementById('ccCredit').className = m === 'credit' ? 'on-credit' : '';
        var sel = document.getElementById('payment_mode');
        if (m === 'cash') { payFull(); if (sel.value === 'credit') sel.value = 'cash'; }
        else { document.getElementById('paid').value = 0; sel.value = 'credit'; Bill.totals(); }
        var hint = document.getElementById('partyReqHint'); if (hint) hint.style.display = m === 'credit' ? '' : 'none';
        pmChange();
      }
      window.setCC = setCC;
      // Quick-add a new customer right from the bill (Credit needs a party).
      function quickParty() {
        var name = (document.getElementById('qp_name').value || '').trim();
        if (!name) { alert('Enter the party name.'); return; }
        var fd = new FormData();
        fd.append('csrf', document.querySelector('input[name=csrf]').value);
        fd.append('name', name);
        fd.append('mobile', document.getElementById('qp_mobile').value);
        fd.append('gstin', document.getElementById('qp_gstin').value);
        fd.append('type', 'customer');
        fetch('ajax.php?a=party_add', { method: 'POST', body: fd })
          .then(function (r) { return r.json(); })
          .then(function (d) {
            if (d.error) { alert(d.error); return; }
            var sel = document.getElementById('party_id');
            var o = document.createElement('option');
            o.value = d.id; o.textContent = d.name; o.dataset.mobile = document.getElementById('qp_mobile').value; o.dataset.credit = 0; o.dataset.points = 0;
            o.selected = true; sel.appendChild(o);
            sel.dispatchEvent(new Event('change'));
            document.getElementById('qpModal').style.display = 'none';
            document.getElementById('qp_name').value = document.getElementById('qp_mobile').value = document.getElementById('qp_gstin').value = '';
          }).catch(function () { alert('Could not add the party. Try again.'); });
      }
      window.quickParty = quickParty;
      (function () { var h = document.getElementById('partyReqHint'); if (h) h.style.display = ccMode === 'credit' ? '' : 'none'; })();
      // On a Credit bill a party (or at least a customer name) is required, so
      // the due always lands on someone's ledger instead of a walk-in.
      document.getElementById('billForm').addEventListener('submit', function (e) {
        if (ccMode === 'credit') {
          var hasParty = document.getElementById('party_id').value;
          var hasName = (document.getElementById('customer_name').value || '').trim();
          if (!hasParty && !hasName) {
            e.preventDefault();
            alert('Select a party (or add a new one) for a Credit bill — otherwise the pending amount can\'t be tracked. Use Cash for a walk-in sale.');
            document.getElementById('qpModal').style.display = 'block';
            document.getElementById('qp_name').focus();
          }
        }
      });
      function pmChange() {
        var sel = document.getElementById('payment_mode');
        var opt = sel.options[sel.selectedIndex];
        document.getElementById('bankAccBox').style.display = opt.dataset.type === 'bank' ? '' : 'none';
        if (!document.getElementById('ccCash')) return; // edit form has no Cash/Credit toggle
        if (sel.value === 'credit' && ccMode !== 'credit') setCC('credit');
        else if (sel.value !== 'credit' && ccMode === 'credit') setCC('cash');
      }
      pmChange(); // on load: show the bank picker if the saved/default mode is a bank one
      window.pmChange = pmChange;
      function setDiscType(t) {
        document.getElementById('discount_type').value = t;
        document.getElementById('discAmt').className = t === 'amount' ? 'on-cash' : '';
        document.getElementById('discPct').className = t === 'percent' ? 'on-cash' : '';
        Bill.totals();
      }
      window.setDiscType = setDiscType;
      // cash mode: paid follows total automatically; loyalty points redemption
      // further reduces the total shown before cash-mode auto-fill runs
      var LOYALTY_REDEEM_VALUE = <?= json_encode((float)setting('loyalty_redeem_value', '1')) ?>;
      var _origTotals = Bill.totals.bind(Bill);
      Bill.totals = function () {
        _origTotals();
        var g = document.getElementById('t_grand');
        var adjInp = document.getElementById('adjustment');
        var adj = adjInp ? (parseFloat(adjInp.value) || 0) : 0;
        var grand = (parseFloat(g.textContent) || 0) + adj;
        var adjRow = document.getElementById('adjRow'); if (adjRow) adjRow.style.display = Math.abs(adj) > 0.004 ? '' : 'none';
        var adjT = document.getElementById('t_adj'); if (adjT) adjT.textContent = (adj >= 0 ? '' : '- ') + Math.abs(adj).toFixed(2);

        var profitEl = document.getElementById('t_profit');
        var billCost = 0;
        if (profitEl) {
          document.querySelectorAll('#billItems .bill-row').forEach(function (div) {
            var qty = parseFloat(div.querySelector('.i-qty').value) || 0;
            billCost += qty * (parseFloat(div.dataset.cost) || 0);
          });
        }

        var loyaltyDisc = 0;
        var redeemInp = document.getElementById('redeem_points');
        if (redeemInp) {
          var avail = parseInt(document.getElementById('party_id').selectedOptions[0].dataset.points || 0, 10);
          var pts = Math.max(0, Math.min(parseInt(redeemInp.value, 10) || 0, avail, Math.floor(grand / LOYALTY_REDEEM_VALUE)));
          redeemInp.value = pts;
          loyaltyDisc = pts * LOYALTY_REDEEM_VALUE;
          var row = document.getElementById('loyaltyRow');
          if (row) row.style.display = loyaltyDisc > 0 ? '' : 'none';
          var ld = document.getElementById('t_loyalty'); if (ld) ld.textContent = loyaltyDisc.toFixed(2);
          grand -= loyaltyDisc;
        }

        var roundChk = document.getElementById('round_off_chk');
        var roundOffVal = 0;
        if (roundChk && roundChk.checked) {
          var rounded = Math.round(grand);
          roundOffVal = rounded - grand;
          grand = rounded;
        }
        var roInp = document.getElementById('round_off'); if (roInp) roInp.value = roundOffVal.toFixed(2);
        var roRow = document.getElementById('roundRow'); if (roRow) roRow.style.display = Math.abs(roundOffVal) > 0.004 ? '' : 'none';
        var roT = document.getElementById('t_round'); if (roT) roT.textContent = (roundOffVal >= 0 ? '' : '- ') + Math.abs(roundOffVal).toFixed(2);

        // real profit = what actually lands in the pocket: after the bill
        // discount, loyalty points, +/- adjustment and round-off (GST is the
        // government's money, so it's already excluded from both sides)
        if (profitEl) {
          var subP = parseFloat(document.getElementById('t_sub').textContent) || 0;
          var discP = parseFloat((document.getElementById('discount') || {}).value) || 0;
          profitEl.textContent = (subP - discP - loyaltyDisc + adj + roundOffVal - billCost).toFixed(2);
        }

        g.textContent = grand.toFixed(2);
        var due = document.getElementById('t_due');
        if (due) { var paid = parseFloat((document.getElementById('paid') || {}).value) || 0; due.textContent = (grand - paid).toFixed(2); }

        if (ccMode === 'cash' && !SALE_EDIT) {
          var p = document.getElementById('paid');
          if (p) { p.value = grand.toFixed(2); if (due) due.textContent = '0.00'; }
        }
      };
      document.getElementById('party_id').addEventListener('change', function () {
        var o = this.options[this.selectedIndex];
        if (this.value) {
          document.getElementById('customer_name').value = o.textContent.trim();
          document.getElementById('customer_mobile').value = o.dataset.mobile || '';
          var pa = document.getElementById('pointsAvail'); if (pa) pa.textContent = '(Available: ' + (o.dataset.points || 0) + ')';
          document.getElementById('credit_days').value = o.dataset.credit || 0;
        } else {
          var pa2 = document.getElementById('pointsAvail'); if (pa2) pa2.textContent = '';
        }
        Bill.totals();
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
<?= render_saved_filters($u['id'], 'sales') ?>
<div class="list-count"><?= count($sales) ?> bills · Total ₹<?= money($sumTotal) ?></div>
<div class="table-wrap">
<table>
  <thead><tr><th>Invoice</th><th>Date</th><th>Customer</th><th>Firm</th><th class="num">Total</th><th>Status</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($sales as $s): ?>
    <tr>
      <td><a href="sale_view.php?id=<?= $s['id'] ?>"><strong><?= e($s['invoice_no']) ?></strong></a><br><span class="muted"><?= e($s['staff_name']) ?></span></td>
      <td><?= dmy($s['sale_date']) ?></td>
      <td><?= $s['party_id'] ? '<a href="parties.php?action=ledger&id=' . $s['party_id'] . '">' . e($s['customer_name'] ?: 'Walk-in') . '</a>' : e($s['customer_name'] ?: 'Walk-in') ?><br><span class="muted"><?= e($s['customer_mobile']) ?></span></td>
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
