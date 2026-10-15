<?php
// ONE way to write a purchase bill and a payment. The website's screens and
// the phone app (api.php) both call these, so stock, serials, the cost price,
// bill links and the cash book come out the same from either door. As with
// bills (sale_save.php), the form's field names are the contract.

/** Save the purchase bill on the form: stock in, serials, latest cost, paid
 *  money out. Returns [id, total, paid]; throws SaleRefused, or rolls back. */
function purchase_save_form(array $u, $clientUuid = null) {
    if (is_period_locked(post('purchase_date', today()))) throw new SaleRefused(period_lock_message());
    $lock = locked_location_id() ?: ((!empty($u['location_locked']) && !in_array('*', (array)($u['perms'] ?? []), true)) ? (int)$u['location_id'] : 0);
    $company = row('SELECT * FROM companies WHERE id = ?', [(int)post('company_id', 1)]);
    $loc_id = stock_home_location((int)post('location_id') ?: (int)$u['location_id']);
    if ($lock) $loc_id = $lock; // godown/shop manager bills only from their own place
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
        // Serial-tracked line: the count of serial numbers typed IS the qty, so
        // 4 serials with qty left at 1 becomes qty 4 automatically (no error).
        $serialsRaw = trim((string)($serials_in[$i] ?? ''));
        $snCount = $serialsRaw !== '' ? count(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $serialsRaw)))) : 0;
        if ($snCount > 0) $qty = $snCount;
        if (!$iid || $qty <= 0) { continue; }
        $tr = ($company && $company['is_gst']) ? (float)($taxes[$i] ?? 0) : 0;
        $rows[] = ['item_id' => $iid, 'qty' => $qty, 'price' => (float)($prices[$i] ?? 0),
                   'tax_rate' => $tr, 'total' => $qty * (float)($prices[$i] ?? 0),
                   'serials' => $serialsRaw];
    }
    if (!$rows || !$party_id) throw new SaleRefused('Party and at least one item required.');

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
    $modeCode = post('payment_mode', 'cash');
    list($pmId, $bankAccId) = resolve_payment_target($modeCode, post('bank_account_id'));

    $pdo = db(); $own = !$pdo->inTransaction();
    $own ? $pdo->beginTransaction() : $pdo->exec('SAVEPOINT purchase_save');
    try {
        q('INSERT INTO purchases (company_id, bill_no, party_id, location_id, purchase_date, credit_days, due_date,
           subtotal, discount, discount_type, discount_pct, tax_amount, shipping, total, paid, payment_method_id, bank_account_id, status, notes, created_by, bill_photo, client_uuid)
           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
          [(int)post('company_id', 1), post('bill_no'), $party_id, $loc_id, $pdate, $credit_days,
           $credit_days ? date('Y-m-d', strtotime("$pdate +$credit_days days")) : null,
           $subtotal, $discount, $discType, $discPct, $tax, $shipping, $total, $paid, $pmId, $bankAccId, payment_status($total, $paid), post('notes'), $u['id'],
           preg_replace('/[^A-Za-z0-9_\-.]/', '', post('bill_photo')), $clientUuid]);
        $pid = insert_id();

        foreach ($rows as $r) {
            $item = row('SELECT * FROM items WHERE id = ?', [$r['item_id']]);
            q('INSERT INTO purchase_items (purchase_id, item_id, qty, price, tax_rate, total) VALUES (?,?,?,?,?,?)',
              [$pid, $r['item_id'], $r['qty'], $r['price'], $r['tax_rate'], $r['total']]);
            adjust_stock($r['item_id'], $loc_id, $r['qty'], 'purchase', $pid, post('bill_no'), (float)$r['price']);

            if ($item['serial_tracked']) {
                $sns = array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $r['serials']))));
                if (count($sns) != $r['qty']) throw new Exception("Enter {$r['qty']} serial number(s) for {$item['name']} (one per line).");
                if ($bad = biz_bad_imeis($sns)) throw new Exception('Not a real IMEI for ' . $item['name'] . ': ' . implode(', ', $bad) . ' — an IMEI is 15 digits (dial *#06# on the phone).');
                foreach ($sns as $sn) {
                    // Advance billing reconciliation: this serial may already be
                    // SOLD (sale entered before the vendor's bill arrived). Link
                    // it to this purchase and leave it sold - the stock +qty
                    // above cancels the sale's earlier -qty, and the unit's
                    // history is complete. A serial already sitting in stock is
                    // a genuine duplicate and is refused.
                    $srow = row('SELECT id, status FROM item_serials WHERE item_id=? AND serial_no=?', [$r['item_id'], $sn]);
                    if ($srow && $srow['status'] === 'sold') {
                        q('UPDATE item_serials SET purchase_id=? WHERE id=?', [$pid, $srow['id']]);
                    } elseif ($srow) {
                        throw new Exception("Serial $sn already exists in stock for {$item['name']} - it can't be purchased twice.");
                    } else {
                        q("INSERT INTO item_serials (item_id, serial_no, location_id, status, purchase_id, warranty_months)
                           VALUES (?,?,?,'in_stock',?,?)", [$r['item_id'], $sn, $loc_id, $pid, $item['warranty_months']]);
                    }
                }
            }
        }
        // update item purchase price to latest, then lift selling to keep
        // the minimum profit margin (item's own margin, else the default %)
        foreach ($rows as $r) {
            q('UPDATE items SET purchase_price = ? WHERE id = ?', [$r['price'], $r['item_id']]);
            enforce_min_margin($r['item_id']);
        }

        if ($paid > 0) {
            q('INSERT INTO payments (party_id, direction, amount, mode, bank_account_id, payment_method_id, ref_type, ref_id, pay_date, notes, created_by)
               VALUES (?,?,?,?,?,?,?,?,?,?,?)',
              [$party_id, 'out', $paid, $modeCode, $bankAccId, $pmId, 'purchase', $pid, $pdate, 'Against purchase bill ' . post('bill_no'), $u['id']]);
        }
        $own ? $pdo->commit() : $pdo->exec('RELEASE SAVEPOINT purchase_save');
    } catch (Exception $ex) {
        $own ? $pdo->rollBack() : $pdo->exec('ROLLBACK TO SAVEPOINT purchase_save');
        throw $ex;
    }
    log_activity('purchase_add', "#$pid total $total");
    return ['id' => (int)$pid, 'total' => (float)$total, 'paid' => (float)$paid];
}

/** Save Payment-In / Payment-Out from the form: bill links, oldest bills
 *  first, settlement discount, cheque, bank charge, WhatsApp receipt.
 *  Returns [id, amount, dir, party, alloc, discount]; throws SaleRefused, or rolls back. */
function payment_save_form(array $u, $clientUuid = null) {
    $party_id = (int)post('party_id');
    $dir = post('direction') === 'out' ? 'out' : 'in';
    $amount = (float)post('amount');
    // settlement discount ("₹7,040 ના ₹7,000 લઈ ₹40 જતા કર્યા") - settles the
    // ledger/bills WITHOUT touching cash or bank (its own 'discount' row)
    $discount = max(0, round((float)post('discount'), 2));
    $party = row('SELECT * FROM parties WHERE id = ?', [$party_id]);
    if (!$party || $amount + $discount <= 0) throw new SaleRefused('Party and amount required.');
    if (is_period_locked(post('pay_date', today()))) throw new SaleRefused(period_lock_message());

    $allocIds = post('alloc_id', []);
    $allocAmts = post('alloc_amt', []);
    $pdo = db(); $own = !$pdo->inTransaction();
    $own ? $pdo->beginTransaction() : $pdo->exec('SAVEPOINT payment_save');
    try {
        $allocated = 0;
        $allocNotes = [];
        $allocRows = []; // structured record of which bills this payment settles, so a later delete can be reversed correctly
        foreach ($allocIds as $i => $bid) {
            $amt = round((float)($allocAmts[$i] ?? 0), 2);
            // 'op' = the party's OPENING balance line (જૂનો હિસાબ) - nothing
            // to update on any bill; the allocation row itself is the record
            if ($bid === 'op') {
                $amt = min($amt, opening_due($party_id, $dir));
                if ($amt <= 0.009) continue;
                $allocNotes[] = 'Opening Bal: ₹' . money($amt);
                $allocRows[] = ['ref_type' => 'opening', 'ref_id' => $party_id, 'amount' => $amt];
                $allocated += $amt;
                continue;
            }
            $bid = (int)$bid;
            if (!$bid || $amt <= 0) continue;
            if ($dir === 'in') {
                $bill = row('SELECT * FROM sales WHERE id = ? AND party_id = ? AND is_cancelled = 0', [$bid, $party_id]);
                if (!$bill) continue;
                $amt = min($amt, $bill['total'] - $bill['paid']);
                if ($amt <= 0) continue;
                q('UPDATE sales SET paid = paid + ?, status = ? WHERE id = ?',
                  [$amt, payment_status($bill['total'], $bill['paid'] + $amt), $bid]);
                $allocNotes[] = $bill['invoice_no'] . ': ₹' . money($amt);
                $allocRows[] = ['ref_type' => 'sale', 'ref_id' => $bid, 'amount' => $amt];
            } else {
                $bill = row('SELECT * FROM purchases WHERE id = ? AND party_id = ? AND is_cancelled = 0', [$bid, $party_id]);
                if (!$bill) continue;
                $amt = min($amt, $bill['total'] - $bill['paid']);
                if ($amt <= 0) continue;
                q('UPDATE purchases SET paid = paid + ?, status = ? WHERE id = ?',
                  [$amt, payment_status($bill['total'], $bill['paid'] + $amt), $bid]);
                $allocNotes[] = ($bill['bill_no'] ?: '#' . $bid) . ': ₹' . money($amt);
                $allocRows[] = ['ref_type' => 'purchase', 'ref_id' => $bid, 'amount' => $amt];
            }
            $allocated += $amt;
        }
        if ($allocated > $amount + $discount + 0.009) throw new Exception('Linked amount is more than payment + discount.');

        // whatever is NOT explicitly linked settles the party's OLDEST due
        // bills automatically (Vyapar-style): a plain "received ₹400" also
        // clears the bill it obviously pays, so the Receivables list stays
        // truthful instead of showing already-collected bills forever
        $autoLeft = round($amount + $discount - $allocated, 2);
        // "Keep as advance": the owner will put it on a bill later, so the
        // part not linked by hand is NOT spread over the oldest bills
        $keepAdvance = post('on_account') === '1';
        foreach ($keepAdvance ? [] : money_settle_oldest_first($party_id, $dir, $autoLeft) as $t) {
            $allocNotes[] = $t['label'] . ': ₹' . money($t['amount']);
            $allocRows[] = ['ref_type' => $t['ref_type'], 'ref_id' => $t['ref_id'], 'amount' => $t['amount']];
            $allocated += $t['amount'];
        }

        // bills settle from the CASH part first; whatever remains rides the
        // separate discount row so a later delete reverses each correctly
        list($mainAlloc, $discAlloc) = money_split_cash_discount($allocRows, $amount);

        $notes = trim(post('notes'));
        if ($allocNotes) $notes = trim($notes . ' [' . implode(', ', $allocNotes) . ']');
        // resolve_payment_target: only a bank-type mode may carry a bank
        // account id - the hidden bank dropdown still posts a value on cash
        // payments, and that stray id used to land cash receipts in the
        // Bank Ledger
        [$pmId, $bankAccId] = resolve_payment_target(post('mode', 'cash'), (int)post('bank_account_id'));
        $pid = null;
        if ($amount > 0.009) {
            q('INSERT INTO payments (party_id, direction, amount, mode, bank_account_id, payment_method_id, pay_date, notes, created_by, client_uuid) VALUES (?,?,?,?,?,?,?,?,?,?)',
              [$party_id, $dir, $amount, post('mode', 'cash'), $bankAccId, $pmId,
               post('pay_date', today()), $notes, $u['id'], $clientUuid]);
            $pid = insert_id();
            if ($keepAdvance) q('UPDATE payments SET on_account = 1 WHERE id = ?', [$pid]);
            foreach ($mainAlloc as $a) {
                q('INSERT INTO payment_allocations (payment_id, ref_type, ref_id, amount) VALUES (?,?,?,?)', [$pid, $a['ref_type'], $a['ref_id'], $a['amount']]);
            }
        }
        if ($discount > 0.009) {
            q("INSERT INTO payments (party_id, direction, amount, mode, bank_account_id, payment_method_id, pay_date, notes, created_by) VALUES (?,?,?,'discount',NULL,NULL,?,?,?)",
              [$party_id, $dir, $discount, post('pay_date', today()), trim('Settlement discount' . ($notes !== '' ? ' — ' . $notes : '')), $u['id']]);
            $dpid = insert_id();
            $pid = $pid ?: $dpid;
            foreach ($discAlloc as $a) {
                q('INSERT INTO payment_allocations (payment_id, ref_type, ref_id, amount) VALUES (?,?,?,?)', [$dpid, $a['ref_type'], $a['ref_id'], $a['amount']]);
            }
        }
        // The cheque itself. The payment above is the money; this is the
        // piece of paper, which still has to reach the bank and can still
        // bounce - and if it does, cheques.php reverses that payment.
        if ($pid && post('mode') === 'cheque' && trim((string)post('cheque_no')) !== '') {
            cheque_add(['direction' => $dir, 'party_id' => $party_id, 'payment_id' => $pid,
                        'cheque_no' => post('cheque_no'), 'bank_name' => post('cheque_bank'),
                        'cheque_date' => post('cheque_date', post('pay_date', today())),
                        'amount' => $amount, 'notes' => 'Payment P-' . $pid . ' with']);
        }
        // What the bank or the gateway quietly took off this payment. It is a
        // real cost, so it becomes an ordinary expense in the category the
        // reports already group under - never hidden inside the amount.
        $charge = round((float)post('bank_charge'), 2);
        if ($charge > 0.009) {
            bank_charge_post($charge, post('pay_date', today()), $bankAccId, post('mode', 'cash'),
                             '— ' . $party['name'] . ' (P-' . $pid . ')');
        }
        $own ? $pdo->commit() : $pdo->exec('RELEASE SAVEPOINT payment_save');
    } catch (Exception $ex) {
        $own ? $pdo->rollBack() : $pdo->exec('ROLLBACK TO SAVEPOINT payment_save');
        throw $ex;
    }
    // written: what follows is news about the payment, never part of it
    log_activity('payment_add', "P-$pid party={$party['name']} $dir $amount");
    fire_webhook('payment.recorded', ['payment_id' => $pid, 'party' => $party['name'], 'direction' => $dir, 'amount' => $amount]);
    if ($dir === 'in') big_payment_alert($amount, $party['name']);
    // a UPI credit from the inbox (upi.php) is now in the books
    if ((int)post('upi_id')) { try { q("UPDATE upi_inbox SET status = 'recorded', payment_id = ?, party_id = ? WHERE id = ? AND status = 'new'", [$pid, $party['id'], (int)post('upi_id')]); } catch (Exception $e) {} }

    // WhatsApp receipt to party (payment-in only)
    if ($dir === 'in' && post('send_wa') && $party['mobile']) {
        $bal = party_balance($party_id);
        $balTxt = $bal > 0.009 ? '₹' . money($bal) . ' (due)' : ($bal < -0.009 ? '₹' . money(-$bal) . ' (advance)' : '₹0.00 (clear)');
        $discLine = $discount > 0.009 ? 'Discount: Rs ' . money($discount) . "\n" : '';
        wa_context(['kind' => 'receipt', 'amount' => money($amount), 'date' => dmy(post('pay_date', today())), 'balance' => $balTxt]);
        send_whatsapp($party['mobile'], wa_template('payment_receipt', [
            'amount' => money($amount), 'mode' => post('mode', 'cash'), 'date' => dmy(post('pay_date', today())),
            'alloc' => ($allocNotes ? 'Against: ' . implode(', ', $allocNotes) . "\n" : '') . $discLine,
            'balance' => $balTxt, 'party' => $party['name'],
        ]), '', wa_customer_buttons($party['mobile'], 'receipt'));
    }
    return ['id' => (int)$pid, 'amount' => (float)$amount, 'dir' => $dir, 'party' => $party, 'alloc' => $allocNotes, 'discount' => $discount];
}


/** A NEW expense from the form (exp_date, category, amount, mode,
 *  bank_account_id, notes). A big one waits for the owner instead - it is held
 *  in its own table, not written as a "pending" expense row: forty different
 *  queries add expenses up, and one of them forgetting to skip pending rows
 *  would quietly change the profit. Nothing exists in the books until it is
 *  approved. Returns [id, pending, over (budget messages)]. */
function expense_save_form(array $u, $clientUuid = null) {
    $amt = round((float)post('amount'), 2);
    if ($amt <= 0) throw new SaleRefused('Enter the amount.');
    if (is_period_locked(post('exp_date', today()))) throw new SaleRefused(period_lock_message());
    // the bank dropdown is hidden for cash-type modes but still posts a
    // value - resolve_payment_target() keeps a cash expense from carrying
    // a stray bank id into the bank ledger (same guard as on bills)
    [$pmId, $bankId] = resolve_payment_target(post('mode', 'cash'), (int)post('bank_account_id'));
    $limit = (float)setting('expense_approval_above', '0');
    $isAdmin = in_array('*', (array)($u['perms'] ?? []), true);   // the admin is not stopped by it
    if ($limit > 0.009 && $amt > $limit && !$isAdmin) {
        q('INSERT INTO expense_requests (amount, category, exp_date, payload, reason, requested_by, client_uuid)
           VALUES (?,?,?,?,?,?,?)',
          [$amt, post('category', 'General'), post('exp_date', today()),
           json_encode(['exp_date' => post('exp_date', today()), 'category' => post('category', 'General'),
                        'amount' => $amt, 'mode' => post('mode', 'cash'), 'bank_account_id' => $bankId,
                        'payment_method_id' => $pmId, 'notes' => post('notes'),
                        'location_id' => $u['location_id']], JSON_UNESCAPED_UNICODE),
           trim((string)post('notes')), $u['id'], $clientUuid]);
        $id = insert_id();
        log_activity('expense_request', post('category') . ' ' . $amt);
        try { tg_notify_admins("🧾 For expense approval\n" . $u['name'] . " — ₹" . money($amt) . ' (' . post('category') . ')'); }
        catch (Throwable $e) { /* telling the owner must never block the request */ }
        return ['id' => (int)$id, 'pending' => true, 'over' => []];
    }
    q('INSERT INTO expenses (exp_date, category, amount, mode, bank_account_id, payment_method_id, notes, location_id, created_by, client_uuid) VALUES (?,?,?,?,?,?,?,?,?,?)',
      [post('exp_date', today()), post('category', 'General'), $amt, post('mode', 'cash'),
       $bankId, $pmId, post('notes'), $u['location_id'], $u['id'], $clientUuid]);
    $id = insert_id();
    log_activity('expense_add', post('category') . ' ' . $amt);
    // 🎯 the month's budget for this kind of spending (Books → Budget)
    require_once __DIR__ . '/books.php';
    $over = [];
    foreach (budget_status(substr(post('exp_date', today()), 0, 7)) as $b) {
        if ($b['category'] !== post('category') || $b['spent'] <= $b['budget']) continue;
        $over[] = '🎯 ' . $b['category'] . ' is now ₹' . money($b['spent'] - $b['budget']) . ' over this month\'s budget of ₹' . money($b['budget']) . '.';
        if ($b['spent'] - $amt <= $b['budget']) owner_alert('🎯 ' . $b['category'] . ' crossed its monthly budget: ₹' . money($b['spent']) . ' of ₹' . money($b['budget']));
    }
    return ['id' => (int)$id, 'pending' => false, 'over' => $over];
}
