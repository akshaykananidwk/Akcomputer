<?php
// ONE way to write a bill. The billing screen, the counter (POS) and the phone
// app all come through here, so a bill is taxed, numbered, stocked and put in
// the books the same whichever door it came in by. The form's field names
// are the contract: the app's API fills $_POST with them before calling.

/** Refused before anything was written (period locked, no items, plan limit). */
class SaleRefused extends Exception {}

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

/** Save the bill on the form. Returns [id, invoice_no, total, paid, party_id];
 *  throws SaleRefused, or any Exception after rolling back. */
function sale_save_form(array $u, $clientUuid = null) {
    if (is_period_locked(post('sale_date', today()))) throw new SaleRefused(period_lock_message());
    // a godown/shop manager bills only from their own place - from the app too
    $lock = locked_location_id() ?: ((!empty($u['location_locked']) && !in_array('*', (array)($u['perms'] ?? []), true)) ? (int)$u['location_id'] : 0);
    $company = row('SELECT * FROM companies WHERE id = ?', [(int)post('company_id')]);
    $loc_id = stock_home_location((int)post('location_id') ?: (int)$u['location_id']);
    if ($lock) $loc_id = $lock;
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
    $costSel = post('line_cost', []); // service lines only: actual outside expense for THIS job
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
        $tr = $company['is_gst'] && !biz_on('gst_composition') ? (float)($taxes[$i] ?? 0) : 0;   // a composition dealer may not charge GST
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
                   'cost' => max(0, (float)($costSel[$i] ?? 0)),
                   'description' => trim((string)($descriptions[$i] ?? '')), 'custom_data' => sale_item_custom_data($activeCF, $i),
                   'staff' => (int)(post('line_staff', [])[$i] ?? 0)];   // salon: who did this service
    }
    if (!$rows || !$company) throw new SaleRefused('Add at least one item.');

    $subtotal = array_sum(array_column($rows, 'total'));
    $discType = post('discount_type') === 'percent' ? 'percent' : 'amount';
    $discRaw = (float)post('discount_val');
    $discount = $discType === 'percent' ? round($subtotal * $discRaw / 100, 2) : min($discRaw, $subtotal);
    $discPct = $discType === 'percent' ? $discRaw : 0;
    $tax = 0;
    foreach ($rows as $r) $tax += $r['total'] * $r['tax_rate'] / 100;
    $shipping = max(0, (float)post('shipping'));
    // Trade-in: the old part comes back across the counter and its value comes
    // OFF this bill. It rides on the bill's existing adjustment instead of
    // adding a term of its own, so every total in the software - the health
    // check, the PDF, the ledger - keeps adding up by the formula it already
    // uses. What physically came in is written to trade_ins and reaches stock.
    $tradeIns = sale_trade_in_rows();
    $tradeVal = 0.0;
    foreach ($tradeIns as $t) $tradeVal += $t['value'];
    $adjustment = (float)post('adjustment') - $tradeVal;
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

    // What the customer actually handed over, in up to two ways: ₹2,000 cash
    // and ₹3,000 UPI on one ₹5,000 bill. sale_payment_lines() caps the total
    // at the bill and drops anything blank, so the bill can never show more
    // paid than it is worth.
    $payLines = sale_payment_lines($total);
    $paid = 0.0;
    foreach ($payLines as $pl) $paid += $pl['amount'];
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

    // another shop's plan may cap its bills a month
    if (($lim = plan_limit_problem('bills')) !== '') throw new SaleRefused($lim);

    // inside someone else's transaction (a test, a bigger job): a savepoint
    $pdo = db(); $own = !$pdo->inTransaction();
    $own ? $pdo->beginTransaction() : $pdo->exec('SAVEPOINT sale_save');
    try {
        q('INSERT INTO sales (company_id, party_id, customer_name, customer_mobile, location_id, sale_date, price_type,
           credit_days, due_date, subtotal, discount, loyalty_points_used, loyalty_discount, discount_type, discount_pct, tax_amount, shipping, adjustment, round_off, total, paid, payment_mode,
           bank_account_id, payment_method_id, status, notes, created_by, share_token, delivery_address, trade_in, client_uuid)
           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
          [$company['id'], $party_id, post('customer_name'), post('customer_mobile'), $loc_id,
           post('sale_date', today()), post('price_type', 'retail'), $credit_days,
           // custom promised date wins; otherwise sale date + credit days
           preg_match('/^\d{4}-\d{2}-\d{2}$/', post('due_date')) ? post('due_date')
               : ($credit_days ? date('Y-m-d', strtotime(post('sale_date', today()) . " +$credit_days days")) : null),
           $subtotal, $discount, $loyaltyPointsUsed, $loyaltyDiscount, $discType, $discPct, $tax, $shipping, $adjustment, $roundOff, $total, $paid, post('payment_mode', 'cash'),
           $bankAccId, $pmId, payment_status($total, $paid), post('notes'), $u['id'], share_token(),
           trim((string)post('delivery_address')) ?: null, $tradeVal, $clientUuid]);
        $sale_id = insert_id();
        // a medical store: the doctor's prescription, kept with the bill
        if (biz_on('biz_prescription') && !empty($_FILES['prescription']['tmp_name']) && $_FILES['prescription']['error'] === UPLOAD_ERR_OK
            && $_FILES['prescription']['size'] <= 8 * 1024 * 1024) {
            $pext = strtolower(pathinfo($_FILES['prescription']['name'], PATHINFO_EXTENSION));
            if (in_array($pext, ['jpg', 'jpeg', 'png', 'webp', 'pdf'], true)) {
                $pname = 'rx_' . $sale_id . '_' . bin2hex(random_bytes(6)) . '.' . $pext;
                $rxDir = up_dir('prescriptions');
                if (!is_file("$rxDir/.htaccess")) @file_put_contents("$rxDir/.htaccess", "Require all denied\n");   // read only through sale_view.php
                if (move_uploaded_file($_FILES['prescription']['tmp_name'], "$rxDir/$pname")) {
                    try { q('UPDATE sales SET prescription = ? WHERE id = ?', [$pname, $sale_id]); } catch (Exception $e) { /* before v88 */ }
                }
            }
        }
        // one series per firm per financial year, counted from 1 - see
        // doc_next_no() in includes/helpers.php
        $invoice_no = doc_next_no($company['id'], post('sale_date', today()), $company['invoice_prefix']);
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
            $rloc = $lock ?: ($r['loc'] ?: $loc_id);
            if ($item['item_type'] === 'service') {
                // service: cost = ONLY the outside expense typed on this line
                // (a one-off outsourced repair must not become a standing
                // per-bill cost of this service item)
                $costPrice = $r['cost'] ?? 0;
            } else {
                $costPrice = (float)$item['purchase_price'];
                if (setting('costing_method', 'current') !== 'current') {
                    $layerCost = stock_layer_consume($r['item_id'], $rloc, $r['qty'] + $r['free']);
                    if ($layerCost !== null) $costPrice = $layerCost;
                }
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
            if (!empty($r['staff'])) { try { q('UPDATE sale_items SET staff_id = ? WHERE id = ?', [$r['staff'], insert_id()]); } catch (Exception $e) { /* before v88 */ } }

            if ($item['item_type'] === 'service') { continue; } // service: no stock effect
            // A kit is never stocked itself - selling one takes its PARTS off
            // the shelf. Checking the kit's own stock would refuse every sale
            // (it is always zero) and checking nothing would sell parts the
            // shop does not have.
            if (is_kit($r['item_id'])) {
                if (!$allowNeg) foreach (kit_parts($r['item_id']) as $kp) {
                    if ($kp['item_type'] === 'service') continue;
                    if (stock_available_qty((int)$kp['part_item_id'], $rloc) < $r['qty'] * (float)$kp['qty'])
                        throw new Exception("Not enough {$kp['name']} for the kit {$item['name']}.");
                }
                kit_move_stock($r['item_id'], $rloc, $r['qty'] + $r['free'], -1, 'sale', $sale_id, $invoice_no);
                continue;
            }
            if (!$allowNeg && stock_available_qty($r['item_id'], $rloc) < $r['qty']) {
                throw new Exception("Not enough stock of {$item['name']} at the line's location.");
            }
            adjust_stock($r['item_id'], $rloc, -($r['qty'] + $r['free']), 'sale', $sale_id, $invoice_no);

            foreach ($serials as $sn) {
                serial_sell($r['item_id'], $sn, $sale_id, post('sale_date', today()), (int)$item['warranty_months']);
            }
        }
        // post initial payment to the party ledger (and to cash/bank books -
        // always recorded, even for walk-in sales with no party, so "Cash in
        // Hand" and bank account balances stay accurate)
        // one payment row per way of paying, so the cash book and the bank
        // book each see their own share of a split payment
        foreach ($payLines as $pl) {
            q('INSERT INTO payments (party_id, direction, amount, mode, bank_account_id, payment_method_id, ref_type, ref_id, pay_date, notes, created_by)
               VALUES (?,?,?,?,?,?,?,?,?,?,?)',
              [$party_id, 'in', $pl['amount'], $pl['mode'], $pl['bank_id'], $pl['pm_id'], 'sale', $sale_id,
               post('sale_date', today()), 'With bill ' . $invoice_no, $u['id']]);
        }
        // the old part taken in exchange reaches the shelf, and is on record
        if ($tradeIns) sale_trade_in_save($sale_id, $tradeIns, $loc_id, $invoice_no);
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
        if ((int)post('park_id')) q('DELETE FROM parked_bills WHERE id = ?', [(int)post('park_id')]);
        if ((int)post('appt_id')) q("UPDATE appointments SET status = 'done', sale_id = ? WHERE id = ? AND sale_id IS NULL", [$sale_id, (int)post('appt_id')]);
        $own ? $pdo->commit() : $pdo->exec('RELEASE SAVEPOINT sale_save');
    } catch (Exception $ex) {
        $own ? $pdo->rollBack() : $pdo->exec('ROLLBACK TO SAVEPOINT sale_save');
        throw $ex;
    }
    // written: what follows is news about the bill, never part of it
    log_activity('sale_add', "$invoice_no total $total");
    fire_webhook('sale.created', ['sale_id' => $sale_id, 'invoice_no' => $invoice_no, 'total' => $total, 'paid' => $paid, 'customer_name' => post('customer_name')]);
    if ($paid > 0) big_payment_alert($paid, (string)post('customer_name'), 'Paid on bill ' . $invoice_no);
    return ['id' => (int)$sale_id, 'invoice_no' => $invoice_no, 'total' => (float)$total, 'paid' => (float)$paid, 'party_id' => $party_id];
}


/** The phone app's bill ({party_id, customer_name, customer_mobile, sale_date,
 *  paid, payment_mode, discount, round_off, company_id, location_id,
 *  items: [{item_id, qty, price, discount, serials}]}) laid out as the billing
 *  screen's form, for sale_save_form(). */
function sale_form_from_app(array $b, array $u) {
    $items = (array)($b['items'] ?? []);
    // THE SAME BILL AS THE BILLING SCREEN WRITES. The phone's lines are
    // laid out as the screen's form fields and handed to the one function
    // both use, so GST, the invoice number, party-from-mobile, kits,
    // services, serials, stock checks and the cash book come out the same
    // whichever door the bill came in by. Tax is the item master's, never
    // the phone's.
    $company = row('SELECT * FROM companies WHERE id = ? AND is_active = 1', [(int)($b['company_id'] ?? 0)])
            ?: row('SELECT * FROM companies WHERE is_active = 1 ORDER BY id LIMIT 1');
    $f = ['company_id' => $company['id'] ?? 0, 'location_id' => (int)($b['location_id'] ?? $u['location_id']),
          'party_id' => (int)($b['party_id'] ?? 0) ?: '', 'customer_name' => trim((string)($b['customer_name'] ?? '')),
          'customer_mobile' => preg_replace('/\D/', '', (string)($b['customer_mobile'] ?? '')),
          'sale_date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($b['sale_date'] ?? '')) ? $b['sale_date'] : today(),
          'price_type' => 'retail', 'notes' => (string)($b['notes'] ?? ''),
          'discount_type' => 'amount', 'discount_val' => max(0, (float)($b['discount'] ?? 0)),
          'round_off_on' => !empty($b['round_off']) ? '1' : '',
          'payment_mode' => (string)($b['payment_mode'] ?? ''), 'paid' => (float)($b['paid'] ?? 0),
          'credit_days' => 0];
    if ($f['payment_mode'] === '') $f['payment_mode'] = $f['paid'] > 0.009 ? 'cash' : 'credit';
    foreach (array_values($items) as $k => $it) {
        $item = row('SELECT id, tax_rate, selling_price FROM items WHERE id = ? AND is_active = 1', [(int)($it['item_id'] ?? 0)]);
        if (!$item) throw new SaleRefused('Invalid item_id ' . ($it['item_id'] ?? ''));
        if ((float)($it['qty'] ?? 0) <= 0) throw new SaleRefused('qty must be > 0');
        $n = $k + 1;
        $f['row_n'][] = $n;
        $f['item_id'][] = $item['id'];
        $f['qty'][] = (float)$it['qty'];
        $f['price'][] = isset($it['price']) ? (float)$it['price'] : (float)$item['selling_price'];
        $f['tax_rate'][] = (float)$item['tax_rate'];
        $f['ldisc'][] = max(0, (float)($it['discount'] ?? 0));
        $f['ldisc_t'][] = 'amount';
        $sn = array_values(array_filter(array_map(fn($s) => trim((string)$s), (array)($it['serials'] ?? [])), fn($s) => $s !== ''));
        if ($sn) $f['serial_sel'][$n] = $sn;
    }
    return $f;
}
