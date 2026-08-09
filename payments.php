<?php
// Payments: Vyapar-style Payment-In / Payment-Out with bill linking,
// party balance display, ledger posting and WhatsApp receipt.
require_once __DIR__ . '/includes/init.php';
require_perm('payments.view');
$u = current_user();
$action = get('action', 'list');
$id = (int)get('id');

// ---------- save payment (with optional bill allocation) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'save_payment') {
    require_perm('payments.add');
    $party_id = (int)post('party_id');
    $dir = post('direction') === 'out' ? 'out' : 'in';
    $amount = (float)post('amount');
    // settlement discount ("₹7,040 ના ₹7,000 લઈ ₹40 જતા કર્યા") - settles the
    // ledger/bills WITHOUT touching cash or bank (its own 'discount' row)
    $discount = max(0, round((float)post('discount'), 2));
    $party = row('SELECT * FROM parties WHERE id = ?', [$party_id]);
    if (!$party || $amount + $discount <= 0) { flash('Party and amount required.', 'error'); redirect('payments.php?action=new&dir=' . $dir); }
    if (is_period_locked(post('pay_date', today()))) { flash(period_lock_message(), 'error'); redirect('payments.php?action=new&dir=' . $dir); }

    $allocIds = post('alloc_id', []);
    $allocAmts = post('alloc_amt', []);
    $pdo = db();
    $pdo->beginTransaction();
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
        if ($autoLeft > 0.009) {
            $tblA = $dir === 'in' ? 'sales' : 'purchases';
            foreach (all("SELECT * FROM $tblA WHERE party_id = ? AND status <> 'paid' AND is_cancelled = 0
                          ORDER BY due_date IS NULL, due_date, id", [$party_id]) as $bill) {
                if ($autoLeft <= 0.009) break;
                $due = round($bill['total'] - $bill['paid'], 2);
                if ($due <= 0.009) continue;
                $take = min($autoLeft, $due);
                q("UPDATE $tblA SET paid = paid + ?, status = ? WHERE id = ?", [$take, payment_status($bill['total'], $bill['paid'] + $take), $bill['id']]);
                $allocNotes[] = ($dir === 'in' ? $bill['invoice_no'] : ($bill['bill_no'] ?: '#' . $bill['id'])) . ': ₹' . money($take);
                $allocRows[] = ['ref_type' => $dir === 'in' ? 'sale' : 'purchase', 'ref_id' => $bill['id'], 'amount' => $take];
                $allocated += $take;
                $autoLeft -= $take;
            }
        }

        // bills settle from the CASH part first; whatever remains rides the
        // separate discount row so a later delete reverses each correctly
        $mainAlloc = []; $discAlloc = []; $left = $amount;
        foreach ($allocRows as $a) {
            $take = round(min($a['amount'], $left), 2);
            if ($take > 0.009) { $mainAlloc[] = ['ref_type' => $a['ref_type'], 'ref_id' => $a['ref_id'], 'amount' => $take]; $left -= $take; }
            if ($a['amount'] - $take > 0.009) $discAlloc[] = ['ref_type' => $a['ref_type'], 'ref_id' => $a['ref_id'], 'amount' => round($a['amount'] - $take, 2)];
        }

        $notes = trim(post('notes'));
        if ($allocNotes) $notes = trim($notes . ' [' . implode(', ', $allocNotes) . ']');
        // resolve_payment_target: only a bank-type mode may carry a bank
        // account id - the hidden bank dropdown still posts a value on cash
        // payments, and that stray id used to land cash receipts in the
        // Bank Ledger
        [$pmId, $bankAccId] = resolve_payment_target(post('mode', 'cash'), (int)post('bank_account_id'));
        $pid = null;
        if ($amount > 0.009) {
            q('INSERT INTO payments (party_id, direction, amount, mode, bank_account_id, payment_method_id, pay_date, notes, created_by) VALUES (?,?,?,?,?,?,?,?,?)',
              [$party_id, $dir, $amount, post('mode', 'cash'), $bankAccId, $pmId,
               post('pay_date', today()), $notes, $u['id']]);
            $pid = insert_id();
            foreach ($mainAlloc as $a) {
                q('INSERT INTO payment_allocations (payment_id, ref_type, ref_id, amount) VALUES (?,?,?,?)', [$pid, $a['ref_type'], $a['ref_id'], $a['amount']]);
            }
        }
        if ($discount > 0.009) {
            q("INSERT INTO payments (party_id, direction, amount, mode, bank_account_id, payment_method_id, pay_date, notes, created_by) VALUES (?,?,?,'discount',NULL,NULL,?,?,?)",
              [$party_id, $dir, $discount, post('pay_date', today()), trim('છૂટ / Settlement discount' . ($notes !== '' ? ' — ' . $notes : '')), $u['id']]);
            $dpid = insert_id();
            $pid = $pid ?: $dpid;
            foreach ($discAlloc as $a) {
                q('INSERT INTO payment_allocations (payment_id, ref_type, ref_id, amount) VALUES (?,?,?,?)', [$dpid, $a['ref_type'], $a['ref_id'], $a['amount']]);
            }
        }
        $pdo->commit();
        log_activity('payment_add', "P-$pid party={$party['name']} $dir $amount");
        fire_webhook('payment.recorded', ['payment_id' => $pid, 'party' => $party['name'], 'direction' => $dir, 'amount' => $amount]);

        // WhatsApp receipt to party (payment-in only)
        if ($dir === 'in' && post('send_wa') && $party['mobile']) {
            $bal = party_balance($party_id);
            $balTxt = $bal > 0.009 ? '₹' . money($bal) . ' (due)' : ($bal < -0.009 ? '₹' . money(-$bal) . ' (advance)' : '₹0.00 (clear)');
            $discLine = $discount > 0.009 ? 'છૂટ / Discount: ₹' . money($discount) . "\n" : '';
            wa_context(['kind' => 'receipt', 'amount' => money($amount), 'date' => dmy(post('pay_date', today())), 'balance' => $balTxt]);
            send_whatsapp($party['mobile'], wa_template('payment_receipt', [
                'amount' => money($amount), 'mode' => post('mode', 'cash'), 'date' => dmy(post('pay_date', today())),
                'alloc' => ($allocNotes ? 'Against: ' . implode(', ', $allocNotes) . "\n" : '') . $discLine,
                'balance' => $balTxt, 'party' => $party['name'],
            ]));
        }
        flash(($dir === 'in' ? 'Payment-In' : 'Payment-Out') . ' of ₹' . money($amount) . ' saved'
            . ($discount > 0.009 ? ' + છૂટ ₹' . money($discount) : '')
            . ($allocNotes ? ' & linked to ' . count($allocNotes) . ' bill(s).' : '.'));
        redirect('payments.php');
    } catch (Exception $ex) {
        $pdo->rollBack();
        flash('Error: ' . $ex->getMessage(), 'error');
        redirect('payments.php?action=new&dir=' . $dir);
    }
}

// ---------- one-time cleanup: link OLD unlinked payments to their bills ----------
// Payments that were recorded without picking a bill settled the party's
// LEDGER but never the bill's own paid amount - so long-collected bills kept
// showing in Receivables/Payables. This walks every such payment (oldest
// first) and allocates its unused remainder to that party's oldest due
// bills, exactly like the auto-settle that now runs on new payments.
// Idempotent: a second run finds nothing left to do.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'backfill_alloc') {
    require_perm('payments.add');
    if (!is_full_admin()) { flash('Only the admin can run this.', 'error'); redirect('payments.php'); }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $fixedPays = 0; $fixedAmt = 0.0; $billsTouched = 0;
        $pays = all("SELECT p.*, COALESCE((SELECT SUM(pa.amount) FROM payment_allocations pa WHERE pa.payment_id = p.id), 0) used
                     FROM payments p
                     WHERE p.party_id IS NOT NULL AND p.mode <> 'contra' AND p.ref_type IS NULL
                     HAVING p.amount - used > 0.009
                     ORDER BY p.pay_date, p.id");
        foreach ($pays as $pm) {
            $left = round($pm['amount'] - $pm['used'], 2);
            $tblA = $pm['direction'] === 'in' ? 'sales' : 'purchases';
            $did = false;
            foreach (all("SELECT * FROM $tblA WHERE party_id = ? AND status <> 'paid' AND is_cancelled = 0
                          ORDER BY due_date IS NULL, due_date, id", [$pm['party_id']]) as $bill) {
                if ($left <= 0.009) break;
                $due = round($bill['total'] - $bill['paid'], 2);
                if ($due <= 0.009) continue;
                $take = min($left, $due);
                q("UPDATE $tblA SET paid = paid + ?, status = ? WHERE id = ?", [$take, payment_status($bill['total'], $bill['paid'] + $take), $bill['id']]);
                q('INSERT INTO payment_allocations (payment_id, ref_type, ref_id, amount) VALUES (?,?,?,?)',
                  [$pm['id'], $pm['direction'] === 'in' ? 'sale' : 'purchase', $bill['id'], $take]);
                $left -= $take;
                $fixedAmt += $take;
                $billsTouched++;
                $did = true;
            }
            if ($did) $fixedPays++;
        }
        $pdo->commit();
        log_activity('payments_backfill_alloc', "payments=$fixedPays bills=$billsTouched amount=$fixedAmt");
        flash($fixedPays
            ? "✅ $fixedPays જૂના પેમેન્ટ કુલ ₹" . money($fixedAmt) . " માટે $billsTouched બિલ સાથે જોડાઈ ગયા — હવે યાદી સાચી બાકી જ બતાવે છે."
            : 'બધું પહેલેથી બરાબર છે — કોઈ છૂટું પેમેન્ટ બાકી નથી.');
    } catch (Exception $ex) {
        $pdo->rollBack();
        flash('Error: ' . $ex->getMessage(), 'error');
    }
    redirect('payments.php');
}

// ---------- WhatsApp reminder ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'remind') {
    $s = row('SELECT s.*, c.name company_name FROM sales s JOIN companies c ON c.id = s.company_id WHERE s.id = ?', [(int)post('sale_id')]);
    $trueDue = $s ? sale_true_due($s) : 0;
    if ($s && $trueDue <= 0.009) {
        flash('આ બિલનું ખરેખર કંઈ બાકી નથી (ખાતામાં પેમેન્ટ આવી ગયેલું છે) — રિમાઇન્ડર ન મોકલ્યું. "🧹 જૂના પેમેન્ટ બિલ સાથે જોડો" દબાવશો તો બિલ પણ ચૂકતે દેખાશે.', 'error');
    } elseif ($s && $s['customer_mobile']) {
        wa_context(['kind' => 'reminder']);
        send_whatsapp($s['customer_mobile'], wa_template('reminder', [
            'firm' => $s['company_name'], 'invoice_no' => $s['invoice_no'], 'date' => dmy($s['sale_date']),
            'due' => money($trueDue),
            'due_date_line' => $s['due_date'] ? 'Due date: ' . dmy($s['due_date']) . "\n" : '',
        ]));
        flash('Reminder sent on WhatsApp.');
    } else {
        flash('No customer mobile on this bill.', 'error');
    }
    redirect('payments.php');
}

// Deleting a payment now correctly reverses whatever it paid down - both a
// direct bill link (the "paid now" amount recorded when a sale/purchase was
// created, via payments.ref_type/ref_id) and any bills it was linked to via
// the "Link to a Bill" allocator or a Contra settlement (payment_allocations
// rows). Previously the bill's own `paid` figure just stayed inflated
// forever after a delete, silently drifting from the party's real ledger.
function reverse_bill_paid($ref_type, $ref_id, $amt) {
    if ($ref_type === 'opening') return; // opening due derives from the allocation rows themselves - deleting them IS the reversal
    $table = $ref_type === 'sale' ? 'sales' : 'purchases';
    $bill = row("SELECT total, paid FROM $table WHERE id = ?", [$ref_id]);
    if (!$bill) return;
    $newPaid = max(0, round($bill['paid'] - $amt, 2));
    q("UPDATE $table SET paid = ?, status = ? WHERE id = ?", [$newPaid, payment_status($bill['total'], $newPaid), $ref_id]);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'delete') {
    require_perm('payments.delete');
    $pid = (int)post('id');
    $pay = row('SELECT * FROM payments WHERE id = ?', [$pid]);
    if ($pay && is_period_locked($pay['pay_date'])) { flash(period_lock_message(), 'error'); redirect('payments.php'); }
    if ($pay) {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            if ($pay['ref_type'] && $pay['ref_id']) reverse_bill_paid($pay['ref_type'], $pay['ref_id'], (float)$pay['amount']);
            foreach (all('SELECT * FROM payment_allocations WHERE payment_id = ?', [$pid]) as $a) {
                reverse_bill_paid($a['ref_type'], $a['ref_id'], (float)$a['amount']);
            }
            q('DELETE FROM payment_allocations WHERE payment_id = ?', [$pid]);
            q('DELETE FROM payments WHERE id = ?', [$pid]);
            $pdo->commit();
            log_activity('payment_delete', "P-$pid");
            flash('Payment entry deleted - any bill(s) it was linked to have had their paid amount reversed.');
        } catch (Exception $ex) {
            $pdo->rollBack();
            flash('Error deleting payment: ' . $ex->getMessage(), 'error');
        }
    }
    redirect('payments.php');
}

// ---------- Edit a payment ----------
// The amount/date/mode/notes can all be changed. To keep the books exactly
// right, the payment's old effect on bills is fully reversed first, then the
// NEW amount is re-applied to the party's outstanding bills oldest-first (the
// same predictable rule as "Auto"). The party's ledger balance always tracks
// the payments table directly, so it's correct no matter what.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'update') {
    require_perm('payments.edit');
    $pid = (int)post('id');
    $pay = row('SELECT * FROM payments WHERE id = ?', [$pid]);
    if (!$pay) { flash('Payment not found.', 'error'); redirect('payments.php'); }
    if ($pay['mode'] === 'contra' || $pay['mode'] === 'discount') { flash("A " . ($pay['mode'] === 'contra' ? 'contra settlement' : 'settlement discount') . " can't be edited - delete it and record a fresh one.", 'error'); redirect('payments.php?action=view&id=' . $pid); }
    if (is_period_locked($pay['pay_date']) || is_period_locked(post('pay_date', $pay['pay_date']))) { flash(period_lock_message(), 'error'); redirect('payments.php?action=view&id=' . $pid); }
    $amount = round((float)post('amount'), 2);
    if ($amount <= 0) { flash('Amount must be more than zero.', 'error'); redirect('payments.php?action=edit&id=' . $pid); }
    $mode = post('mode', $pay['mode']);
    // same guard as everywhere: a cash-type mode never keeps a bank id
    [$pmEditId, $bankAccId] = resolve_payment_target($mode, (int)post('bank_account_id'));
    $payDate = post('pay_date', $pay['pay_date']);
    $notes = post('notes', $pay['notes']);
    $dir = $pay['direction'];
    $party_id = (int)$pay['party_id'];

    // The bills this payment settled, in order (direct link first, then any
    // structured allocations). The edited amount is re-applied to these SAME
    // bills so the payment keeps settling what it always did - only the
    // amount scales. Any leftover just sits on the party's account.
    $targets = [];
    if ($pay['ref_type'] && $pay['ref_id']) $targets[] = ['type' => $pay['ref_type'], 'id' => (int)$pay['ref_id']];
    foreach (all('SELECT ref_type, ref_id FROM payment_allocations WHERE payment_id = ? ORDER BY id', [$pid]) as $a)
        $targets[] = ['type' => $a['ref_type'], 'id' => (int)$a['ref_id']];

    $pdo = db();
    $pdo->beginTransaction();
    try {
        // 1) reverse the old effect on any bill(s) it had touched
        if ($pay['ref_type'] && $pay['ref_id']) reverse_bill_paid($pay['ref_type'], $pay['ref_id'], (float)$pay['amount']);
        foreach (all('SELECT * FROM payment_allocations WHERE payment_id = ?', [$pid]) as $a) reverse_bill_paid($a['ref_type'], $a['ref_id'], (float)$a['amount']);
        q('DELETE FROM payment_allocations WHERE payment_id = ?', [$pid]);
        // 2) save the new values (link now tracked purely via allocations below)
        q('UPDATE payments SET amount=?, mode=?, bank_account_id=?, payment_method_id=?, pay_date=?, notes=?, ref_type=NULL, ref_id=NULL WHERE id=?',
          [$amount, $mode, $bankAccId, $pmEditId, $payDate, $notes, $pid]);
        // 3) re-apply the new amount to the same bills, capped to each one's due
        $left = $amount;
        foreach ($targets as $t) {
            if ($left <= 0.009) break;
            if ($t['type'] === 'opening') {
                $use = min($left, opening_due($party_id, $dir));
                if ($use <= 0.009) continue;
                q('INSERT INTO payment_allocations (payment_id, ref_type, ref_id, amount) VALUES (?,?,?,?)', [$pid, 'opening', $party_id, $use]);
                $left -= $use;
                continue;
            }
            $tbl = $t['type'] === 'sale' ? 'sales' : 'purchases';
            $b = row("SELECT total, paid, is_cancelled FROM $tbl WHERE id = ?", [$t['id']]);
            if (!$b || $b['is_cancelled']) continue;
            $use = min($left, round($b['total'] - $b['paid'], 2));
            if ($use <= 0.009) continue;
            q("UPDATE $tbl SET paid = paid + ?, status = ? WHERE id = ?", [$use, payment_status($b['total'], $b['paid'] + $use), $t['id']]);
            q('INSERT INTO payment_allocations (payment_id, ref_type, ref_id, amount) VALUES (?,?,?,?)', [$pid, $t['type'], $t['id'], $use]);
            $left -= $use;
        }
        $pdo->commit();
        log_activity('payment_edit', "P-$pid amount $amount");
        flash('Payment updated — the linked bills and the party ledger have been recalculated.');
        redirect('payments.php?action=view&id=' . $pid);
    } catch (Exception $ex) {
        $pdo->rollBack();
        flash('Error updating payment: ' . $ex->getMessage(), 'error');
        redirect('payments.php?action=edit&id=' . $pid);
    }
}

// ---------- Contra / Settle: net a sale due against a purchase due for the
// same party (they're both a customer and a supplier) with no real cash or
// bank movement - the standard "contra entry" from double-entry bookkeeping.
// Recorded as a matched pair of payments (mode='contra', no bank_account_id)
// so the party's ledger balance updates correctly but cashbook/bank ledger
// reports (which only count real money movement) exclude them.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'save_contra') {
    require_perm('payments.add');
    $party_id = (int)post('party_id');
    $party = row('SELECT * FROM parties WHERE id = ?', [$party_id]);
    if (!$party) { flash('Party required.', 'error'); redirect('payments.php?action=contra'); }

    $saleAllocIds = post('sale_alloc_id', []);
    $saleAllocAmts = post('sale_alloc_amt', []);
    $purchAllocIds = post('purch_alloc_id', []);
    $purchAllocAmts = post('purch_alloc_amt', []);
    $pay_date = post('pay_date', today());
    if (is_period_locked($pay_date)) { flash(period_lock_message(), 'error'); redirect('payments.php?action=contra&party=' . $party_id); }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $saleTotal = 0; $saleNotes = []; $saleAllocRows = [];
        foreach ($saleAllocIds as $i => $bid) {
            $bid = (int)$bid;
            $amt = round((float)($saleAllocAmts[$i] ?? 0), 2);
            if (!$bid || $amt <= 0) continue;
            $bill = row('SELECT * FROM sales WHERE id = ? AND party_id = ? AND is_cancelled = 0', [$bid, $party_id]);
            if (!$bill) continue;
            $amt = min($amt, $bill['total'] - $bill['paid']);
            if ($amt <= 0) continue;
            q('UPDATE sales SET paid = paid + ?, status = ? WHERE id = ?',
              [$amt, payment_status($bill['total'], $bill['paid'] + $amt), $bid]);
            $saleNotes[] = $bill['invoice_no'] . ': ₹' . money($amt);
            $saleAllocRows[] = ['ref_id' => $bid, 'amount' => $amt];
            $saleTotal += $amt;
        }
        $purchTotal = 0; $purchNotes = []; $purchAllocRows = [];
        foreach ($purchAllocIds as $i => $bid) {
            $bid = (int)$bid;
            $amt = round((float)($purchAllocAmts[$i] ?? 0), 2);
            if (!$bid || $amt <= 0) continue;
            $bill = row('SELECT * FROM purchases WHERE id = ? AND party_id = ? AND is_cancelled = 0', [$bid, $party_id]);
            if (!$bill) continue;
            $amt = min($amt, $bill['total'] - $bill['paid']);
            if ($amt <= 0) continue;
            q('UPDATE purchases SET paid = paid + ?, status = ? WHERE id = ?',
              [$amt, payment_status($bill['total'], $bill['paid'] + $amt), $bid]);
            $purchNotes[] = ($bill['bill_no'] ?: '#' . $bid) . ': ₹' . money($amt);
            $purchAllocRows[] = ['ref_id' => $bid, 'amount' => $amt];
            $purchTotal += $amt;
        }
        if ($saleTotal <= 0 || $purchTotal <= 0) throw new Exception('Select at least one bill on each side to settle.');
        if (abs($saleTotal - $purchTotal) > 0.009) {
            throw new Exception('Sales-side (₹' . money($saleTotal) . ') and purchase-side (₹' . money($purchTotal) . ') amounts must match exactly - a contra settlement has no leftover cash. Use "Auto-balance" to fix this.');
        }

        $amount = $saleTotal;
        $note = 'Contra settlement — Sales [' . implode(', ', $saleNotes) . '] = Purchases [' . implode(', ', $purchNotes) . ']';
        q("INSERT INTO payments (party_id, direction, amount, mode, pay_date, notes, created_by) VALUES (?, 'in', ?, 'contra', ?, ?, ?)",
          [$party_id, $amount, $pay_date, $note, $u['id']]);
        $inPid = insert_id();
        foreach ($saleAllocRows as $a) q('INSERT INTO payment_allocations (payment_id, ref_type, ref_id, amount) VALUES (?, ?, ?, ?)', [$inPid, 'sale', $a['ref_id'], $a['amount']]);
        q("INSERT INTO payments (party_id, direction, amount, mode, pay_date, notes, created_by) VALUES (?, 'out', ?, 'contra', ?, ?, ?)",
          [$party_id, $amount, $pay_date, $note, $u['id']]);
        $outPid = insert_id();
        foreach ($purchAllocRows as $a) q('INSERT INTO payment_allocations (payment_id, ref_type, ref_id, amount) VALUES (?, ?, ?, ?)', [$outPid, 'purchase', $a['ref_id'], $a['amount']]);
        $pdo->commit();
        log_activity('contra_add', "party={$party['name']} amount=$amount");
        flash('Contra settlement of ₹' . money($amount) . ' recorded for ' . $party['name'] . ' — both sides\' dues reduced, no cash moved.');
        redirect('parties.php?action=ledger&id=' . $party_id);
    } catch (Exception $ex) {
        $pdo->rollBack();
        flash('Error: ' . $ex->getMessage(), 'error');
        redirect('payments.php?action=contra&party=' . $party_id);
    }
}

// ---------- Payment-In / Payment-Out form ----------
if ($action === 'new') {
    require_perm('payments.add');
    $dir = get('dir') === 'out' ? 'out' : 'in';
    $presetParty = (int)get('party');
    // ALL active parties are selectable, either direction (every party works
    // both sides - no customer/supplier split) - not just those with a due,
    // because a party can be receiving/paying an ADVANCE with no bill
    // against it yet. Parties with a pending balance in this direction are
    // just sorted to the top so the common case stays fast. Inactive
    // parties are included too when they still carry a balance - a party
    // deactivated with money outstanding must stay collectible.
    $balExpr = party_balance_expr('p');
    $pendingFirst = $dir === 'in' ? "($balExpr > 0.009) DESC, $balExpr DESC" : "($balExpr < -0.009) DESC, $balExpr ASC";
    $parties = all("SELECT p.id, p.name, p.mobile, $balExpr AS balance FROM parties p
                    WHERE (p.is_active = 1 OR ABS($balExpr) > 0.009)
                    ORDER BY $pendingFirst, p.name");
    $pendingCount = count(array_filter($parties, fn($p) => $dir === 'in' ? $p['balance'] > 0.009 : $p['balance'] < -0.009));
    $wDue = $dir === 'in' ? walkin_due() : 0;
    $pms = active_payment_methods();
    $banks = all('SELECT * FROM bank_accounts WHERE is_active = 1 ORDER BY is_default DESC, account_name');
    $page_title = $dir === 'in' ? 'Payment-In' : 'Payment-Out';
    include __DIR__ . '/includes/header.php';
    ?>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="save_payment">
      <input type="hidden" name="direction" value="<?= $dir ?>">
      <div class="card">
        <?php if ($pendingCount === 0): ?>
        <div class="flash flash-info">ℹ️ No party has anything <?= $dir === 'in' ? 'receivable' : 'payable' ?> right now — but you can still pick any party below and record an <strong>advance payment</strong>.</div>
        <?php endif; ?>
        <?php if ($wDue > 0.009): ?>
        <div class="flash flash-info">ℹ️ Walk-in (no party) bills have ₹<?= money($wDue) ?> due — collect that directly from the bill itself (via Sale List), not here.</div>
        <?php endif; ?>
        <div class="form-row cols-3">
          <div><label><?= $dir === 'in' ? 'Customer / Party *' : 'Supplier / Party *' ?></label>
            <select name="party_id" id="party_id" required>
              <option value="">-- select party --</option>
              <?php if ($pendingCount): ?><optgroup label="<?= $dir === 'in' ? 'Receivable' : 'Payable' ?>"><?php endif; ?>
              <?php $inGroup = true; foreach ($parties as $p):
                $pending = $dir === 'in' ? $p['balance'] > 0.009 : $p['balance'] < -0.009;
                if ($inGroup && $pendingCount && !$pending) { echo '</optgroup><optgroup label="All other parties">'; $inGroup = false; } ?>
              <option value="<?= $p['id'] ?>" <?= $presetParty === (int)$p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?><?= abs($p['balance']) > 0.009 ? ' (₹' . money(abs($p['balance'])) . ($p['balance'] > 0 ? ' receivable' : ' payable') . ')' : '' ?></option>
              <?php endforeach; ?>
              <?php if ($pendingCount): ?></optgroup><?php endif; ?>
            </select>
            <p class="muted mt" id="balInfo"></p>
          </div>
          <div><label>Date</label><input type="date" name="pay_date" value="<?= today() ?>"></div>
          <div><label>Mode</label>
            <select name="mode" id="pay_mode" onchange="pmChange()">
              <?php foreach ($pms as $pm): if ($pm['code'] === 'credit') continue; ?><option value="<?= e($pm['code']) ?>" data-type="<?= e($pm['type']) ?>"><?= e($pm['name']) ?></option><?php endforeach; ?>
            </select></div>
        </div>
        <div class="form-row cols-3">
          <div><label><?= $dir === 'in' ? 'Received amount (₹) *' : 'Paid amount (₹) *' ?></label>
            <input type="number" step="any" min="0" name="amount" id="pay_amount" required></div>
          <div><label>છૂટ / Discount (₹)</label>
            <input type="number" step="any" min="0" name="discount" id="pay_discount" value="0">
            <p class="muted mt" style="font-size:.8em">બાકી માંડી વાળવા — કેશ/બેંકમાં નહીં ગણાય. દા.ત. બિલ ₹7,040, મળ્યા ₹7,000 → છૂટ ₹40</p></div>
          <div id="bankAccBox" style="display:none"><label>Bank Account</label>
            <select name="bank_account_id"><?php foreach ($banks as $b): ?><option value="<?= $b['id'] ?>"><?= e($b['account_name']) ?> - <?= e($b['bank_name']) ?></option><?php endforeach; ?></select></div>
          <div><label>Notes</label><input type="text" name="notes"></div>
        </div>
        <?php if ($dir === 'in'): ?>
        <label class="check-inline"><input type="checkbox" name="send_wa" value="1" checked> Send WhatsApp receipt to party</label>
        <?php endif; ?>
      </div>

      <div class="card">
        <h3>🔗 Link to a Bill (optional)</h3>
        <p class="muted mb">Select a party to see their due bills. Press "Auto" to allocate automatically starting from the oldest bill. Even without linking, the payment still gets recorded in the ledger.</p>
        <div id="billList" class="muted">Select a party first.</div>
        <button type="button" class="btn btn-sm btn-outline mt" id="autoAlloc" style="display:none">⚡ Auto-link (oldest first)</button>
      </div>
      <button class="btn btn-block <?= $dir === 'in' ? 'btn-success' : '' ?>" type="submit">💾 Save <?= $dir === 'in' ? 'Payment-In' : 'Payment-Out' ?></button>
    </form>
    <script>
    var DIR = '<?= $dir ?>';
    function pmChange() {
      var sel = document.getElementById('pay_mode');
      document.getElementById('bankAccBox').style.display = sel.options[sel.selectedIndex].dataset.type === 'bank' ? '' : 'none';
    }
    window.pmChange = pmChange;
    function loadBills() {
      var pid = document.getElementById('party_id').value;
      var box = document.getElementById('billList');
      document.getElementById('balInfo').textContent = '';
      document.getElementById('autoAlloc').style.display = 'none';
      if (!pid) { box.textContent = 'Select a party first.'; return; }
      fetch('ajax.php?a=party_bills&dir=' + DIR + '&party_id=' + pid)
        .then(function (r) { return r.json(); })
        .then(function (d) {
          var b = d.balance;
          document.getElementById('balInfo').innerHTML = 'Party Balance: <strong style="color:' +
            (b > 0 ? 'var(--ok)' : (b < 0 ? 'var(--bad)' : 'inherit')) + '">₹' +
            Math.abs(b).toFixed(2) + (b > 0 ? ' receivable' : (b < 0 ? ' payable' : '')) + '</strong>';
          if (!d.bills.length) { box.innerHTML = '<span class="muted">No due bills - the payment will just be recorded in the ledger.</span>'; return; }
          var h = '<div class="table-wrap" style="box-shadow:none"><table class="table-sm"><thead><tr><th>Bill</th><th>Date</th><th class="num">Due ₹</th><th style="width:130px">Link ₹</th></tr></thead><tbody>';
          d.bills.forEach(function (bl) {
            h += '<tr><td>' + bl.no + '<input type="hidden" name="alloc_id[]" value="' + bl.id + '"></td>' +
                 '<td>' + bl.date + '</td><td class="num">' + bl.due.toFixed(2) + '</td>' +
                 '<td><input type="number" step="any" min="0" max="' + bl.due + '" name="alloc_amt[]" value="0" class="alloc-inp" data-due="' + bl.due + '"></td></tr>';
          });
          box.innerHTML = h + '</tbody></table></div>';
          document.getElementById('autoAlloc').style.display = '';
        });
    }
    document.getElementById('party_id').addEventListener('change', loadBills);
    document.getElementById('autoAlloc').addEventListener('click', function () {
      var left = (parseFloat(document.getElementById('pay_amount').value) || 0) +
                 (parseFloat(document.getElementById('pay_discount').value) || 0);
      document.querySelectorAll('.alloc-inp').forEach(function (inp) {
        var due = parseFloat(inp.dataset.due) || 0;
        var use = Math.min(left, due);
        inp.value = use > 0 ? use.toFixed(2) : 0;
        left -= use;
      });
    });
    if (document.getElementById('party_id').value) loadBills();
    </script>
    <?php
    include __DIR__ . '/includes/footer.php';
    exit;
}

// ---------- Contra / Settle form ----------
// For a party that is BOTH a customer and a supplier: net their sales due
// against their purchase due directly, no cash/bank involved. Only parties
// carrying a due on both sides at once are useful here, so those are
// listed first (same "pending first" pattern as the Payment-In/Out form).
if ($action === 'contra') {
    require_perm('payments.add');
    $presetParty = (int)get('party');
    $parties = all("SELECT p.id, p.name,
                     COALESCE((SELECT SUM(total - paid) FROM sales WHERE party_id = p.id AND status <> 'paid' AND is_cancelled = 0), 0) sale_due,
                     COALESCE((SELECT SUM(total - paid) FROM purchases WHERE party_id = p.id AND status <> 'paid'), 0) purch_due
                     FROM parties p WHERE p.is_active = 1
                     ORDER BY (sale_due > 0.009 AND purch_due > 0.009) DESC, p.name");
    $bothCount = count(array_filter($parties, fn($p) => $p['sale_due'] > 0.009 && $p['purch_due'] > 0.009));
    $page_title = 'Contra / Settle';
    include __DIR__ . '/includes/header.php';
    ?>
    <div class="card">
      <p class="muted mb">For a party you both <strong>buy from and sell to</strong>: settle what they owe you against what you owe them directly - no cash or bank account is touched, only both sides' outstanding bills go down.</p>
      <?php if ($bothCount === 0): ?>
      <div class="flash flash-info">ℹ️ No party currently has a due on both sides - but you can still pick any party below if that changes.</div>
      <?php endif; ?>
    </div>
    <form method="post" id="contraForm">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="save_contra">
      <div class="card">
        <div class="form-row cols-2">
          <div><label>Party *</label>
            <select name="party_id" id="party_id" required>
              <option value="">-- select party --</option>
              <?php if ($bothCount): ?><optgroup label="Due on both sides"><?php endif; ?>
              <?php $inGroup = true; foreach ($parties as $p):
                $both = $p['sale_due'] > 0.009 && $p['purch_due'] > 0.009;
                if ($inGroup && $bothCount && !$both) { echo '</optgroup><optgroup label="All other parties">'; $inGroup = false; } ?>
              <option value="<?= $p['id'] ?>" <?= $presetParty === (int)$p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?><?= $both ? ' (Sales due ₹' . money($p['sale_due']) . ' · Purchase due ₹' . money($p['purch_due']) . ')' : '' ?></option>
              <?php endforeach; ?>
              <?php if ($bothCount): ?></optgroup><?php endif; ?>
            </select></div>
          <div><label>Date</label><input type="date" name="pay_date" value="<?= today() ?>"></div>
        </div>
      </div>
      <div class="card">
        <h3>They owe us (Sales)</h3>
        <div id="saleBillList" class="muted">Select a party first.</div>
      </div>
      <div class="card">
        <h3>We owe them (Purchases)</h3>
        <div id="purchBillList" class="muted">Select a party first.</div>
      </div>
      <div class="card">
        <button type="button" class="btn btn-sm btn-outline" id="autoBalance" style="display:none">⚡ Auto-balance</button>
        <p class="mt" id="settleSummary"></p>
        <button class="btn btn-block" type="submit">💾 Save Contra Settlement</button>
      </div>
    </form>
    <script>
    function renderBills(box, bills, idName, amtName) {
      if (!bills.length) { box.innerHTML = '<span class="muted">No due bills on this side.</span>'; return; }
      var h = '<div class="table-wrap" style="box-shadow:none"><table class="table-sm"><thead><tr><th>Bill</th><th>Date</th><th class="num">Due ₹</th><th style="width:130px">Settle ₹</th></tr></thead><tbody>';
      bills.forEach(function (bl) {
        h += '<tr><td>' + bl.no + '<input type="hidden" name="' + idName + '" value="' + bl.id + '"></td>' +
             '<td>' + bl.date + '</td><td class="num">' + bl.due.toFixed(2) + '</td>' +
             '<td><input type="number" step="any" min="0" max="' + bl.due + '" name="' + amtName + '" value="0" class="alloc-inp" data-due="' + bl.due + '"></td></tr>';
      });
      box.innerHTML = h + '</tbody></table></div>';
    }
    function updateSummary() {
      var saleTot = 0, purchTot = 0;
      document.querySelectorAll('#saleBillList .alloc-inp').forEach(function (i) { saleTot += parseFloat(i.value) || 0; });
      document.querySelectorAll('#purchBillList .alloc-inp').forEach(function (i) { purchTot += parseFloat(i.value) || 0; });
      var el = document.getElementById('settleSummary');
      var match = Math.abs(saleTot - purchTot) < 0.01;
      el.innerHTML = 'Sales side: <strong>₹' + saleTot.toFixed(2) + '</strong> &nbsp; Purchase side: <strong>₹' + purchTot.toFixed(2) + '</strong> &nbsp; ' +
        (match && saleTot > 0 ? '<span class="badge badge-ok">✓ Balanced - settling ₹' + saleTot.toFixed(2) + '</span>' : '<span class="badge badge-warn">Not balanced yet</span>');
    }
    function loadContraBills() {
      var pid = document.getElementById('party_id').value;
      var saleBox = document.getElementById('saleBillList'), purchBox = document.getElementById('purchBillList');
      document.getElementById('autoBalance').style.display = 'none';
      document.getElementById('settleSummary').innerHTML = '';
      if (!pid) { saleBox.textContent = 'Select a party first.'; purchBox.textContent = 'Select a party first.'; return; }
      Promise.all([
        fetch('ajax.php?a=party_bills&dir=in&party_id=' + pid).then(function (r) { return r.json(); }),
        fetch('ajax.php?a=party_bills&dir=out&party_id=' + pid).then(function (r) { return r.json(); })
      ]).then(function (res) {
        renderBills(saleBox, res[0].bills, 'sale_alloc_id[]', 'sale_alloc_amt[]');
        renderBills(purchBox, res[1].bills, 'purch_alloc_id[]', 'purch_alloc_amt[]');
        if (res[0].bills.length && res[1].bills.length) document.getElementById('autoBalance').style.display = '';
        document.querySelectorAll('.alloc-inp').forEach(function (inp) { inp.addEventListener('input', updateSummary); });
        updateSummary();
      });
    }
    document.getElementById('party_id').addEventListener('change', loadContraBills);
    document.getElementById('autoBalance').addEventListener('click', function () {
      var saleInps = Array.prototype.slice.call(document.querySelectorAll('#saleBillList .alloc-inp'));
      var purchInps = Array.prototype.slice.call(document.querySelectorAll('#purchBillList .alloc-inp'));
      var saleDue = saleInps.reduce(function (s, i) { return s + (parseFloat(i.dataset.due) || 0); }, 0);
      var purchDue = purchInps.reduce(function (s, i) { return s + (parseFloat(i.dataset.due) || 0); }, 0);
      var settle = Math.min(saleDue, purchDue);
      [saleInps, purchInps].forEach(function (inps) {
        var left = settle;
        inps.forEach(function (inp) {
          var due = parseFloat(inp.dataset.due) || 0;
          var use = Math.min(left, due);
          inp.value = use > 0 ? use.toFixed(2) : 0;
          left -= use;
        });
      });
      updateSummary();
    });
    if (document.getElementById('party_id').value) loadContraBills();
    </script>
    <?php
    include __DIR__ . '/includes/footer.php';
    exit;
}

// ---------- view a single payment (detail + Edit / Delete) ----------
if ($action === 'view' && $id) {
    $pay = row('SELECT p.*, pt.name party_name, pt.id party_ref, u2.name by_name, ba.account_name bank_name
                FROM payments p LEFT JOIN parties pt ON pt.id = p.party_id
                JOIN users u2 ON u2.id = p.created_by
                LEFT JOIN bank_accounts ba ON ba.id = p.bank_account_id
                WHERE p.id = ?', [$id]);
    if (!$pay) { flash('Payment not found.', 'error'); redirect('payments.php'); }
    // bills this payment settled (structured allocations + any direct bill link)
    $links = [];
    foreach (all('SELECT * FROM payment_allocations WHERE payment_id = ?', [$id]) as $a) {
        if ($a['ref_type'] === 'opening') {
            $links[] = ['label' => '📜 Opening Balance / જૂનો હિસાબ', 'amt' => $a['amount'],
                        'link' => 'parties.php?action=ledger&id=' . $a['ref_id']];
            continue;
        }
        $t = $a['ref_type'] === 'sale' ? 'sales' : 'purchases';
        $noCol = $a['ref_type'] === 'sale' ? 'invoice_no' : 'bill_no';
        $b = row("SELECT $noCol no FROM $t WHERE id = ?", [$a['ref_id']]);
        $links[] = ['label' => ucfirst($a['ref_type']) . ' ' . ($b['no'] ?? '#' . $a['ref_id']), 'amt' => $a['amount'],
                    'link' => ($a['ref_type'] === 'sale' ? 'sale_view.php?id=' : 'purchase_view.php?id=') . $a['ref_id']];
    }
    if ($pay['ref_type'] && $pay['ref_id'] && !$links) {
        $t = $pay['ref_type'] === 'sale' ? 'sales' : 'purchases';
        $noCol = $pay['ref_type'] === 'sale' ? 'invoice_no' : 'bill_no';
        $b = row("SELECT $noCol no FROM $t WHERE id = ?", [$pay['ref_id']]);
        $links[] = ['label' => ucfirst($pay['ref_type']) . ' ' . ($b['no'] ?? '#' . $pay['ref_id']), 'amt' => $pay['amount'],
                    'link' => ($pay['ref_type'] === 'sale' ? 'sale_view.php?id=' : 'purchase_view.php?id=') . $pay['ref_id']];
    }
    $page_title = 'Payment P-' . str_pad($id, 5, '0', STR_PAD_LEFT);
    include __DIR__ . '/includes/header.php';
    ?>
    <div class="page-actions no-print">
      <a class="btn btn-outline" href="<?= $pay['party_ref'] ? 'parties.php?action=ledger&id=' . $pay['party_ref'] : 'payments.php' ?>">← Back</a>
      <?php if ($pay['mode'] !== 'contra' && $pay['mode'] !== 'discount' && can('payments.edit')): ?>
      <a class="btn" href="payments.php?action=edit&id=<?= $id ?>">✏️ Edit</a>
      <?php endif; ?>
      <?php if (can('payments.delete')): ?>
      <form method="post" onsubmit="return confirm('Delete this payment? Any bill it was linked to will have its paid amount reversed.')" style="display:inline">
        <?= csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= $id ?>">
        <button class="btn btn-danger" type="submit">🗑️ Delete</button></form>
      <?php endif; ?>
    </div>
    <div class="card">
      <h2><?= $pay['direction'] === 'in' ? '⬇ Payment-In (Received)' : '⬆ Payment-Out (Paid)' ?></h2>
      <div style="font-size:30px;font-weight:800;color:<?= $pay['direction'] === 'in' ? 'var(--ok)' : 'var(--bad)' ?>">₹<?= money($pay['amount']) ?></div>
      <p class="muted">Receipt P-<?= str_pad($id, 5, '0', STR_PAD_LEFT) ?> · <?= dmy($pay['pay_date']) ?></p>
      <table class="table-sm mt">
        <tr><td class="muted">Party</td><td><?= $pay['party_ref'] ? '<a href="parties.php?action=ledger&id=' . $pay['party_ref'] . '">' . e($pay['party_name']) . '</a>' : '<span class="muted">Walk-in / none</span>' ?></td></tr>
        <tr><td class="muted">Mode</td><td><?= e(strtoupper($pay['mode'])) ?><?= $pay['bank_name'] ? ' · ' . e($pay['bank_name']) : '' ?></td></tr>
        <tr><td class="muted">Notes</td><td><?= e($pay['notes']) ?: '<span class="muted">—</span>' ?></td></tr>
        <tr><td class="muted">Recorded by</td><td><?= e($pay['by_name']) ?></td></tr>
      </table>
      <?php if ($pay['mode'] === 'contra'): ?><p class="muted mt">🔄 This is a contra settlement (no cash moved). To change it, delete it and record a fresh one.</p><?php endif; ?>
      <?php if ($pay['mode'] === 'discount'): ?><p class="muted mt">🏷️ છૂટ / Settlement discount (no cash moved) — it only clears the party's balance. To change it, delete it and record a fresh one.</p><?php endif; ?>
    </div>
    <?php if ($links): ?>
    <div class="card list-card">
      <div class="list-row" style="cursor:default"><div class="list-row-main"><strong>Settles these bills</strong></div></div>
      <?php foreach ($links as $lk): ?>
      <a class="list-row" href="<?= e($lk['link']) ?>">
        <div class="list-row-main"><?= e($lk['label']) ?></div>
        <div class="list-row-val">₹<?= money($lk['amt']) ?> <span class="muted" style="font-size:15px">›</span></div>
      </a>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="card"><p class="muted">Not linked to a specific bill — it sits on the party's account as an advance / on-account amount.</p></div>
    <?php endif; ?>
    <?php include __DIR__ . '/includes/footer.php'; exit;
}

// ---------- edit a payment ----------
if ($action === 'edit' && $id) {
    require_perm('payments.edit');
    $pay = row('SELECT p.*, pt.name party_name FROM payments p LEFT JOIN parties pt ON pt.id = p.party_id WHERE p.id = ?', [$id]);
    if (!$pay) { flash('Payment not found.', 'error'); redirect('payments.php'); }
    if ($pay['mode'] === 'contra') { flash("A contra settlement can't be edited - delete it and record a fresh one.", 'error'); redirect('payments.php?action=view&id=' . $id); }
    $pms = active_payment_methods();
    $banks = all('SELECT * FROM bank_accounts WHERE is_active = 1 ORDER BY is_default DESC, account_name');
    $page_title = 'Edit Payment P-' . str_pad($id, 5, '0', STR_PAD_LEFT);
    include __DIR__ . '/includes/header.php';
    ?>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="update">
      <input type="hidden" name="id" value="<?= $id ?>">
      <div class="card">
        <h2>Edit <?= $pay['direction'] === 'in' ? 'Payment-In' : 'Payment-Out' ?> — <?= e($pay['party_name'] ?: 'Walk-in') ?></h2>
        <p class="muted mb">Changing the amount recalculates the linked bills and the party's balance automatically. The updated amount is re-applied to the oldest unpaid bill(s) first.</p>
        <div class="form-row cols-2">
          <div><label>Amount (₹)</label><input type="number" step="any" name="amount" value="<?= 0 + $pay['amount'] ?>" required></div>
          <div><label>Date</label><input type="date" name="pay_date" value="<?= e($pay['pay_date']) ?>"></div>
        </div>
        <div class="form-row cols-2">
          <div><label>Mode</label>
            <select name="mode" id="ep_mode" onchange="document.getElementById('ep_bank').style.display=this.selectedOptions[0].dataset.type==='bank'?'':'none'">
              <?php foreach ($pms as $pm): if ($pm['code'] === 'credit') continue; ?>
              <option value="<?= e($pm['code']) ?>" data-type="<?= e($pm['type']) ?>" <?= $pm['code'] === $pay['mode'] ? 'selected' : '' ?>><?= e($pm['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div id="ep_bank" style="<?= ($pay['bank_account_id']) ? '' : 'display:none' ?>"><label>Bank account</label>
            <select name="bank_account_id"><?php foreach ($banks as $b): ?><option value="<?= $b['id'] ?>" <?= $b['id'] == $pay['bank_account_id'] ? 'selected' : '' ?>><?= e($b['account_name']) ?></option><?php endforeach; ?></select>
          </div>
        </div>
        <div class="field"><label>Notes</label><input type="text" name="notes" value="<?= e($pay['notes']) ?>"></div>
        <div class="page-actions" style="margin-top:10px">
          <button class="btn" type="submit">💾 Update Payment</button>
          <a class="btn btn-outline" href="payments.php?action=view&id=<?= $id ?>">Cancel</a>
        </div>
      </div>
    </form>
    <?php include __DIR__ . '/includes/footer.php'; exit;
}

// ---------- list ----------
$parties = all('SELECT id, name FROM parties WHERE is_active = 1 ORDER BY name');
$recent = all('SELECT p.*, pt.name party_name, u2.name by_name FROM payments p
               LEFT JOIN parties pt ON pt.id = p.party_id JOIN users u2 ON u2.id = p.created_by
               ORDER BY p.id DESC LIMIT 100');
$dueSales = all("SELECT s.*, c.name company_name FROM sales s JOIN companies c ON c.id = s.company_id
                 WHERE s.status <> 'paid' AND s.is_cancelled = 0 ORDER BY s.due_date IS NULL, s.due_date, s.id LIMIT 100");
$duePurchases = all("SELECT p.*, pt.name party_name FROM purchases p JOIN parties pt ON pt.id = p.party_id
                     WHERE p.status <> 'paid' ORDER BY p.due_date IS NULL, p.due_date, p.id LIMIT 100");

// The party LEDGER is the truth. A bill's own due can overstate reality when
// something reduced the ledger without touching the bill (an unlinked
// payment, a sales return, a settlement discount) - so per party, the dues
// shown here are capped at the party's real receivable/payable, trimming
// the OLDEST bills first (money always settles oldest-first). Pareshbhai
// with bills 2,041 + 2,800 but a real balance of 2,041 shows exactly 2,041.
function cap_bill_dues(array $bills, $dir) {
    $byParty = [];
    foreach ($bills as $i => $b) if ($b['party_id']) $byParty[(int)$b['party_id']][] = $i;
    foreach ($byParty as $pid => $idxs) {
        $bal = party_balance($pid);
        $bal = $dir === 'in' ? max(0.0, $bal) : max(0.0, -$bal);
        $sum = 0.0;
        foreach ($idxs as $i) $sum += $bills[$i]['total'] - $bills[$i]['paid'];
        $excess = round($sum - $bal, 2); // covered-but-unlinked portion
        foreach ($idxs as $i) {
            if ($excess <= 0.009) break;
            $d = round($bills[$i]['total'] - $bills[$i]['paid'], 2);
            $cut = min($d, $excess);
            $bills[$i]['adj_due'] = round($d - $cut, 2);
            $excess = round($excess - $cut, 2);
        }
    }
    return array_values(array_filter($bills, fn($b) => round($b['adj_due'] ?? ($b['total'] - $b['paid']), 2) > 0.009));
}
$dueSales = cap_bill_dues($dueSales, 'in');
$duePurchases = cap_bill_dues($duePurchases, 'out');

$page_title = 'Payments';
include __DIR__ . '/includes/header.php';
?>
<div class="page-actions">
  <?php if (can('payments.add')): ?>
  <a class="btn btn-success" href="payments.php?action=new&dir=in">⬇ Payment-In</a>
  <a class="btn btn-danger" href="payments.php?action=new&dir=out">⬆ Payment-Out</a>
  <a class="btn btn-outline" href="payments.php?action=contra">🔄 Contra / Settle</a>
  <?php endif; ?>
  <?php if (is_full_admin()): ?>
  <form method="post" style="display:inline" onsubmit="return confirm('જૂના (બિલ સાથે ન જોડાયેલા) બધા પેમેન્ટ એમની પાર્ટીના જૂનામાં જૂના બાકી બિલ સાથે આપોઆપ જોડી દેવા છે? હિસાબ બદલાતો નથી — ફક્ત બિલની બાકી સાચી થાય છે.')">
    <?= csrf_field() ?><input type="hidden" name="do" value="backfill_alloc">
    <button class="btn btn-outline" type="submit">🧹 જૂના પેમેન્ટ બિલ સાથે જોડો</button>
  </form>
  <?php endif; ?>
</div>

<div class="card">
  <h2>💰 Receivables</h2>
  <div class="table-wrap" style="box-shadow:none">
  <table>
    <thead><tr><th>Invoice</th><th>Customer</th><th class="num">Due ₹</th><th>Due date</th><th></th></tr></thead>
    <tbody><?php foreach ($dueSales as $s): $d = $s['adj_due'] ?? ($s['total'] - $s['paid']); ?>
      <tr>
        <td><a href="sale_view.php?id=<?= $s['id'] ?>"><?= e($s['invoice_no']) ?></a></td>
        <td><?= $s['party_id'] ? '<a href="parties.php?action=ledger&id=' . $s['party_id'] . '">' . e($s['customer_name'] ?: 'Walk-in') . '</a>' : e($s['customer_name'] ?: 'Walk-in') ?></td>
        <td class="num">₹<?= money($d) ?></td>
        <td><?= dmy($s['due_date']) ?><?= $s['due_date'] && $s['due_date'] < today() ? ' <span class="badge badge-bad">overdue</span>' : '' ?></td>
        <td style="white-space:nowrap">
          <?php if (can('payments.add') && $s['party_id']): ?>
            <a class="btn btn-sm btn-success" href="payments.php?action=new&dir=in&party=<?= $s['party_id'] ?>">Receive</a>
          <?php endif; ?>
          <?php if ($s['customer_mobile']): ?>
          <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="do" value="remind"><input type="hidden" name="sale_id" value="<?= $s['id'] ?>">
            <button class="btn btn-sm btn-wa" type="submit">📲</button></form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; if (!$dueSales): ?><tr><td colspan="5" class="muted">All clear 🎉</td></tr><?php endif; ?></tbody>
  </table>
  </div>
</div>

<div class="card">
  <h2>📤 Payables</h2>
  <div class="table-wrap" style="box-shadow:none">
  <table>
    <thead><tr><th>Bill</th><th>Supplier</th><th class="num">Due ₹</th><th>Due date</th><th></th></tr></thead>
    <tbody><?php foreach ($duePurchases as $p): ?>
      <tr>
        <td><a href="purchase_view.php?id=<?= $p['id'] ?>">#<?= $p['id'] ?> <?= e($p['bill_no']) ?></a></td>
        <td><a href="parties.php?action=ledger&id=<?= $p['party_id'] ?>"><?= e($p['party_name']) ?></a></td>
        <td class="num">₹<?= money($p['adj_due'] ?? ($p['total'] - $p['paid'])) ?></td>
        <td><?= dmy($p['due_date']) ?><?= $p['due_date'] && $p['due_date'] < today() ? ' <span class="badge badge-bad">overdue</span>' : '' ?></td>
        <td><?php if (can('payments.add')): ?><a class="btn btn-sm btn-danger" href="payments.php?action=new&dir=out&party=<?= $p['party_id'] ?>">Pay</a><?php endif; ?></td>
      </tr>
    <?php endforeach; if (!$duePurchases): ?><tr><td colspan="5" class="muted">All clear 🎉</td></tr><?php endif; ?></tbody>
  </table>
  </div>
</div>

<div class="card">
  <h2>Recent entries</h2>
  <div class="table-wrap" style="box-shadow:none">
  <table>
    <thead><tr><th>#</th><th>Date</th><th>Party</th><th>Dir</th><th class="num">Amount</th><th>Mode</th><th>Notes</th><th></th></tr></thead>
    <tbody><?php foreach ($recent as $pm): ?>
      <tr>
        <td><a href="payments.php?action=view&id=<?= $pm['id'] ?>">P-<?= str_pad($pm['id'], 5, '0', STR_PAD_LEFT) ?></a></td>
        <td><?= dmy($pm['pay_date']) ?></td>
        <td><?= $pm['party_id'] ? '<a href="parties.php?action=ledger&id=' . $pm['party_id'] . '">' . e($pm['party_name']) . '</a>' : '<span class="muted">Walk-in</span>' ?></td>
        <td><?= $pm['direction'] === 'in' ? '<span class="badge badge-ok">IN</span>' : '<span class="badge badge-bad">OUT</span>' ?></td>
        <td class="num">₹<?= money($pm['amount']) ?></td>
        <td><?= e($pm['mode']) ?></td>
        <td><?= e($pm['notes']) ?> <span class="muted">(<?= e($pm['by_name']) ?>)</span></td>
        <td style="white-space:nowrap">
          <a class="btn btn-sm btn-outline" href="payments.php?action=view&id=<?= $pm['id'] ?>">Open</a>
          <?php if ($pm['mode'] !== 'contra' && can('payments.edit')): ?><a class="btn btn-sm btn-outline" href="payments.php?action=edit&id=<?= $pm['id'] ?>">✏️</a><?php endif; ?>
          <?php if (can('payments.delete')): ?>
          <form method="post" onsubmit="return confirm('Delete entry?')" style="display:inline"><?= csrf_field() ?>
          <input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= $pm['id'] ?>">
          <button class="btn btn-sm btn-danger" type="submit">✕</button></form><?php endif; ?></td>
      </tr>
    <?php endforeach; ?></tbody>
  </table>
  </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
