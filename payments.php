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
        foreach (money_settle_oldest_first($party_id, $dir, $autoLeft) as $t) {
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
        $pdo->commit();
        log_activity('payment_add', "P-$pid party={$party['name']} $dir $amount");
        fire_webhook('payment.recorded', ['payment_id' => $pid, 'party' => $party['name'], 'direction' => $dir, 'amount' => $amount]);

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
            ]));
        }
        flash(($dir === 'in' ? 'Payment-In' : 'Payment-Out') . ' of ₹' . money($amount) . ' saved'
            . ($discount > 0.009 ? ' + discount Rs ' . money($discount) : '')
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
            $takes = money_settle_oldest_first($pm['party_id'], $pm['direction'], round($pm['amount'] - $pm['used'], 2));
            foreach ($takes as $t) {
                q('INSERT INTO payment_allocations (payment_id, ref_type, ref_id, amount) VALUES (?,?,?,?)',
                  [$pm['id'], $t['ref_type'], $t['ref_id'], $t['amount']]);
                $fixedAmt += $t['amount'];
                $billsTouched++;
            }
            if ($takes) $fixedPays++;
        }
        $pdo->commit();
        log_activity('payments_backfill_alloc', "payments=$fixedPays bills=$billsTouched amount=$fixedAmt");
        flash($fixedPays
            ? "✅ $fixedPays old payments totalling Rs " . money($fixedAmt) . " were linked to $billsTouched bills — the list now shows the true dues."
            : 'Everything is already in order — no unlinked payment is left.');
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
        flash('Nothing is really outstanding on this bill (the payment is already in the account) — no reminder sent. "🧹 Link old payments to bills" pressing it also marks the bill settled.', 'error');
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
// The reversal rule lives in includes/money.php; this keeps the old name so
// every call site on this page reads the same as it always did.
function reverse_bill_paid($ref_type, $ref_id, $amt) { money_reverse_bill_paid($ref_type, $ref_id, $amt); }
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'delete') {
    require_perm('payments.delete');
    $pid = (int)post('id');
    $pay = row('SELECT * FROM payments WHERE id = ?', [$pid]);
    if ($pay && is_period_locked($pay['pay_date'])) { flash(period_lock_message(), 'error'); redirect('payments.php'); }
    if ($pay) {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            // one undo rule, shared with a cheque bouncing - see
            // payment_reverse() in includes/money.php
            payment_reverse($pid);
            q('UPDATE cheques SET status = ? WHERE payment_id = ?', ['cancelled', $pid]);
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
        // 3) re-apply the new amount. When the edit screen's allocator was
        // used (alloc_ui=1), the OWNER's picks replace the old links - this
        // is how an unlinked payment gets linked to bills any time later.
        // Otherwise the amount goes back to the same bills it settled before.
        $left = $amount;
        if (post('alloc_ui') === '1' && $party_id) {
            $aIds = post('alloc_id', []); $aAmts = post('alloc_amt', []);
            foreach ($aIds as $i => $bid) {
                if ($left <= 0.009) break;
                $amt2 = min(round((float)($aAmts[$i] ?? 0), 2), $left);
                if ($amt2 <= 0.009) continue;
                if ($bid === 'op') {
                    $use = min($amt2, opening_due($party_id, $dir));
                    if ($use <= 0.009) continue;
                    q('INSERT INTO payment_allocations (payment_id, ref_type, ref_id, amount) VALUES (?,?,?,?)', [$pid, 'opening', $party_id, $use]);
                    $left -= $use;
                    continue;
                }
                $use = money_settle_bill((int)$bid, $party_id, $dir, $amt2);
                if ($use <= 0.009) continue;
                q('INSERT INTO payment_allocations (payment_id, ref_type, ref_id, amount) VALUES (?,?,?,?)', [$pid, $dir === 'in' ? 'sale' : 'purchase', (int)$bid, $use]);
                $left -= $use;
            }
            // whatever the owner left unassigned auto-settles the oldest due
            // bills, same rule as a brand-new payment
            foreach (money_settle_oldest_first($party_id, $dir, $left) as $t) {
                q('INSERT INTO payment_allocations (payment_id, ref_type, ref_id, amount) VALUES (?,?,?,?)', [$pid, $t['ref_type'], $t['ref_id'], $t['amount']]);
                $left -= $t['amount'];
            }
        } else {
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
    // "pending" is decided on THIS side only. On the netted balance a party we
    // both sell to and buy from never looked pending on the smaller side - a
    // supplier we owe 3,000 who also owes us 5,000 was simply absent from
    // Payment-Out's pending list.
    $sideExpr = party_balance_side_expr('p', $dir);
    $pendingFirst = "($sideExpr > 0.009) DESC, $sideExpr DESC";
    $parties = all("SELECT p.id, p.name, p.mobile, $balExpr AS balance, $sideExpr AS side_due FROM parties p
                    WHERE (p.is_active = 1 OR ABS($balExpr) > 0.009)
                    ORDER BY $pendingFirst, p.name");
    $pendingCount = count(array_filter($parties, fn($p) => $p['side_due'] > 0.009));
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
                $pending = $p['side_due'] > 0.009;
                if ($inGroup && $pendingCount && !$pending) { echo '</optgroup><optgroup label="All other parties">'; $inGroup = false; }
                // This side's own figure, not the netted one: on Payment-Out a
                // party who also owes US money must still show what WE owe THEM.
                //
                // But when BOTH sides are live, saying "₹16,504 receivable" and
                // then "Party Balance: ₹0.00" two lines below reads as a
                // contradiction - and it was the thing that made the owner
                // distrust the figures. So the net is said out loud, in the
                // same label, along with the fact that a contra settles it
                // without any cash changing hands.
                $lbl = $pending ? ' (₹' . money($p['side_due']) . ($dir === 'in' ? ' receivable' : ' payable') . ')' : '';
                if ($pending && abs($p['balance']) < $p['side_due'] - 0.009) {
                    $netTxt = abs((float)$p['balance']) < 0.009
                        ? 'net ₹0 — settles by Contra'
                        : 'net ₹' . money(abs($p['balance'])) . ($p['balance'] > 0 ? ' receivable' : ' payable');
                    $lbl .= ' ↔ ' . ($dir === 'in' ? 'also payable' : 'also receivable') . ' · ' . $netTxt;
                } ?>
              <option value="<?= $p['id'] ?>" <?= $presetParty === (int)$p['id'] ? 'selected' : '' ?>><?= e($p['name']) . $lbl ?></option>
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
          <div><label>Discount (Rs)</label>
            <input type="number" step="any" min="0" name="discount" id="pay_discount" value="0">
            <p class="muted mt" style="font-size:.8em">To write off the remainder — not counted in cash or bank. E.g. bill Rs 7,040, received Rs 7,000 → discount Rs 40</p></div>
          <div id="bankAccBox" style="display:none"><label>Bank Account</label>
            <select name="bank_account_id"><?php foreach ($banks as $b): ?><option value="<?= $b['id'] ?>"><?= e($b['account_name']) ?> - <?= e($b['bank_name']) ?></option><?php endforeach; ?></select></div>
          <div><label>Notes</label><input type="text" name="notes"></div>
        </div>
        <!-- what the bank / UPI took off this payment: a real cost, recorded
             as an expense so the year's total is visible -->
        <div class="form-row cols-3" id="chargeBox" style="display:none">
          <div><label>Bank / UPI charges (Rs)</label>
            <input type="number" step="any" min="0" name="bank_charge" id="bank_charge" value="0">
            <p class="muted mt" style="font-size:.8em">What the bank deducted. In expenses "Bank Charges" will be recorded as.</p></div>
        </div>
        <!-- the cheque itself, when one is handed over -->
        <div class="form-row cols-3" id="chqBox" style="display:none">
          <div><label>Cheque number</label><input type="text" name="cheque_no" maxlength="40"></div>
          <div><label>Which bank</label><input type="text" name="cheque_bank" maxlength="120"></div>
          <div><label>Cheque date</label><input type="date" name="cheque_date" value="<?= today() ?>"></div>
          <p class="muted" style="grid-column:1/-1;margin:0">It goes into the cheque register automatically. If it bounces, mark it there — the payment is reversed.</p>
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
      var o = sel.options[sel.selectedIndex];
      var isBank = o.dataset.type === 'bank';
      document.getElementById('bankAccBox').style.display = isBank ? '' : 'none';
      // a charge only exists where a bank or a gateway is in the middle
      document.getElementById('chargeBox').style.display = isBank || o.value === 'upi' || o.value === 'card' ? '' : 'none';
      document.getElementById('chqBox').style.display = o.value === 'cheque' ? '' : 'none';
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
            $links[] = ['label' => '📜 Opening balance / old account', 'amt' => $a['amount'],
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
      <?php if ($pay['mode'] === 'discount'): ?><p class="muted mt">🏷️ Settlement discount (no cash moved) — it only clears the party's balance. To change it, delete it and record a fresh one.</p><?php endif; ?>
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

    // Bill allocator on the EDIT screen too: staff often save a payment
    // without picking bills - the owner can open it any time later and link
    // it properly. Each open bill's due gets THIS payment's own share added
    // back (updating reverses first), and current links come pre-filled.
    $curAlloc = [];
    foreach (all('SELECT ref_type, ref_id, SUM(amount) a FROM payment_allocations WHERE payment_id = ? GROUP BY ref_type, ref_id', [$id]) as $x) {
        $curAlloc[$x['ref_type'] . ':' . $x['ref_id']] = (float)$x['a'];
    }
    $editBills = [];
    if ($pay['party_id']) {
        $refT = $pay['direction'] === 'in' ? 'sale' : 'purchase';
        $opMine = $curAlloc['opening:' . $pay['party_id']] ?? 0.0;
        $opDue = round(opening_due((int)$pay['party_id'], $pay['direction']) + $opMine, 2);
        if ($opDue > 0.009) $editBills[] = ['id' => 'op', 'no' => '📜 Opening balance / old account', 'date' => '—', 'due' => $opDue, 'mine' => $opMine];
        $tblE = $pay['direction'] === 'in' ? 'sales' : 'purchases';
        foreach (all("SELECT * FROM $tblE WHERE party_id = ? AND is_cancelled = 0 ORDER BY due_date IS NULL, due_date, id", [$pay['party_id']]) as $b) {
            $mine = $curAlloc[$refT . ':' . $b['id']] ?? 0.0;
            $due = round($b['total'] - $b['paid'] + $mine, 2);
            if ($due <= 0.009) continue;
            $editBills[] = ['id' => $b['id'], 'no' => $pay['direction'] === 'in' ? $b['invoice_no'] : ($b['bill_no'] ?: '#' . $b['id']),
                            'date' => dmy($pay['direction'] === 'in' ? $b['sale_date'] : $b['purchase_date']), 'due' => $due, 'mine' => $mine];
        }
    }
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
      </div>
      <?php if ($pay['party_id']): ?>
      <div class="card">
        <h3>🔗 Link to a Bill</h3>
        <p class="muted mb">Change which bills this payment counts against, any time — even if staff saved it without linking. The current links are filled in; change an amount, set it to 0, or move it to another bill. Anything not put against a bill goes automatically to the oldest outstanding bill.</p>
        <input type="hidden" name="alloc_ui" value="1">
        <?php if (!$editBills): ?>
        <p class="muted">This party has no outstanding bill — the payment simply stays in the account.</p>
        <?php else: ?>
        <div class="table-wrap" style="box-shadow:none"><table class="table-sm">
          <thead><tr><th>Bill</th><th>Date</th><th class="num">Due ₹</th><th style="width:130px">Link ₹</th></tr></thead>
          <tbody>
          <?php foreach ($editBills as $eb): ?>
          <tr>
            <td><?= e($eb['no']) ?><input type="hidden" name="alloc_id[]" value="<?= e($eb['id']) ?>"></td>
            <td><?= e($eb['date']) ?></td>
            <td class="num"><?= money($eb['due']) ?></td>
            <td><input type="number" step="any" min="0" max="<?= $eb['due'] ?>" name="alloc_amt[]" value="<?= 0 + $eb['mine'] ?>" class="alloc-inp" data-due="<?= $eb['due'] ?>"></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
        <button type="button" class="btn btn-sm btn-outline mt" onclick="var l=parseFloat(document.querySelector('input[name=amount]').value)||0;document.querySelectorAll('.alloc-inp').forEach(function(i){var d=parseFloat(i.dataset.due)||0;var u=Math.min(l,d);i.value=u>0?u.toFixed(2):0;l-=u;})">⚡ Auto-link (oldest first)</button>
        <?php endif; ?>
      </div>
      <?php endif; ?>
      <div class="page-actions" style="margin-top:10px">
        <button class="btn" type="submit">💾 Update Payment</button>
        <a class="btn btn-outline" href="payments.php?action=view&id=<?= $id ?>">Cancel</a>
      </div>
    </form>
    <?php include __DIR__ . '/includes/footer.php'; exit;
}

// ---------- list ----------
//
// Every figure on this screen comes from a rule that already exists
// somewhere else - dash_balances() for what is owed either way,
// parties_with_advance() for money held, coll_queue() for who to chase,
// money_cap_bill_dues() for a bill's real due. Not one of them is worked out
// again here. A second copy of a money rule is how two screens in the same
// software come to show a customer two different totals.
require_once __DIR__ . '/includes/dashboard.php';   // dash_balances()
require_once __DIR__ . '/includes/customer.php';    // coll_queue()
require_once __DIR__ . '/includes/voice.php';       // voice_call_button()

$parties = all('SELECT id, name FROM parties WHERE is_active = 1 ORDER BY name');

// ---- the filters, all read from the query string ----
$fRecv = in_array(get('r'), ['overdue', 'soon', 'nodue'], true) ? get('r') : 'all';
$fPay  = in_array(get('p'), ['overdue', 'soon'], true) ? get('p') : 'all';
$fRec  = in_array(get('f'), ['in', 'out', 'cash', 'bank', 'upi', 'contra', 'discount'], true) ? get('f') : 'all';
$pg    = max(1, (int)get('pg'));
$per   = 25;

// Recent payments: filtered and paged in SQL, not in PHP. A shop with thirty
// thousand payments must not load thirty thousand rows to show twenty-five.
$rw = ['1=1']; $ra = [];
if ($fRec === 'in' || $fRec === 'out') { $rw[] = 'p.direction = ?'; $ra[] = $fRec; }
elseif ($fRec === 'cash')     { $rw[] = "p.mode = 'cash'"; }
elseif ($fRec === 'bank')     { $rw[] = "p.mode IN ('bank','cheque','neft','rtgs','imps')"; }
elseif ($fRec === 'upi')      { $rw[] = "p.mode IN ('upi','gpay','phonepe','paytm')"; }
elseif ($fRec === 'contra')   { $rw[] = "p.mode = 'contra'"; }
elseif ($fRec === 'discount') { $rw[] = "p.mode = 'discount'"; }
$rwSql = implode(' AND ', $rw);
$recTotal = (int)val("SELECT COUNT(*) FROM payments p WHERE $rwSql", $ra);
$pgMax = max(1, (int)ceil($recTotal / $per));
if ($pg > $pgMax) $pg = $pgMax;
$recent = all("SELECT p.*, pt.name party_name, u2.name by_name FROM payments p
               LEFT JOIN parties pt ON pt.id = p.party_id JOIN users u2 ON u2.id = p.created_by
               WHERE $rwSql ORDER BY p.id DESC LIMIT $per OFFSET " . (($pg - 1) * $per), $ra);
// status IN ('due','partial') rather than <> 'paid': the column is a NOT NULL
// enum of exactly those three values, so the two are identical - but only the
// IN form can use idx_sale_status_due, which is what makes this list fast
// (measured 64ms -> 15ms on 25,000 bills).
$dueSales = all("SELECT s.*, c.name company_name FROM sales s JOIN companies c ON c.id = s.company_id
                 WHERE s.status IN ('due','partial') AND s.is_cancelled = 0 ORDER BY s.due_date IS NULL, s.due_date, s.id LIMIT 100");
$duePurchases = all("SELECT p.*, pt.name party_name FROM purchases p JOIN parties pt ON pt.id = p.party_id
                     WHERE p.status IN ('due','partial') ORDER BY p.due_date IS NULL, p.due_date, p.id LIMIT 100");

// The party LEDGER is the truth: a bill's own due can overstate reality when
// something reduced the ledger without touching the bill. The capping rule
// itself lives in includes/money.php, shared with sale_true_due().
$dueSales = money_cap_bill_dues($dueSales, 'in');
$duePurchases = money_cap_bill_dues($duePurchases, 'out');

// ---- the figures at the top ----
$bal     = dash_balances();
$advList = parties_with_advance(20);
$advTot  = array_sum(array_map(fn($x) => (float)$x['adv'], $advList));
$advAll  = (float)val('SELECT COALESCE(SUM(adv),0) FROM (SELECT ' . party_balance_side_expr('p', 'out')
                      . ' adv FROM parties p WHERE p.is_active = 1 HAVING adv > 0.009) x');

// Overdue and due-this-week are counted off the bills already loaded and
// already capped above - a date comparison, not a second money rule.
$today = today();
$weekEnd = date('Y-m-d', strtotime('+7 days'));
$sumDue = function (array $bills, $from, $to) {
    $amt = 0.0; $n = 0;
    foreach ($bills as $b) {
        $d = $b['due_date'] ?: null;
        if ($from !== null && (!$d || $d >= $from)) continue;   // "before $from" = overdue
        if ($to !== null && (!$d || $d < today() || $d > $to)) continue;
        $amt += (float)($b['adj_due'] ?? ($b['total'] - $b['paid'])); $n++;
    }
    return [round($amt, 2), $n];
};

$today2 = row("SELECT
    COALESCE(SUM(CASE WHEN direction = 'in'  THEN amount END), 0) got,
    COALESCE(SUM(CASE WHEN direction = 'out' THEN amount END), 0) gave,
    SUM(direction = 'in')  n_in,
    SUM(direction = 'out') n_out
  FROM payments WHERE pay_date = ? AND mode NOT IN ('contra','discount')", [$today]);

$page_title = 'Payments';
include __DIR__ . '/includes/header.php';
?>
<?php
// ---------- 1. the figures, and where each one goes ----------
//
// Every card is a link. A number you cannot open is a number you have to go
// and look for somewhere else, which is how a summary stops being used.
[$odAmt, $odN]   = $sumDue($dueSales, $today, null);
[$wkAmt, $wkN]   = $sumDue($dueSales, null, $weekEnd);
[$opAmt, $opN]   = $sumDue($duePurchases, $today, null);
[$wpAmt, $wpN]   = $sumDue($duePurchases, null, $weekEnd);
?>
<div class="kpi-row">
  <a class="kpi k-ok" href="payments.php?r=all#receivables">
    <div class="kpi-top">👥 Total Receivable</div>
    <div class="kpi-val">₹<?= money($bal['receivable']) ?></div>
    <div class="kpi-sub"><?= (int)$bal['receivable_parties'] ?> parties</div></a>

  <a class="kpi k-warn" href="#advance">
    <div class="kpi-top">🪙 Advance / Money Held</div>
    <div class="kpi-val">₹<?= money($advAll) ?></div>
    <div class="kpi-sub"><?= count($advList) ?> parties</div></a>

  <a class="kpi k-bad" href="payments.php?p=all#payables">
    <div class="kpi-top">🏦 Total Payable</div>
    <div class="kpi-val">₹<?= money($bal['payable']) ?></div>
    <div class="kpi-sub"><?= (int)$bal['payable_parties'] ?> parties</div></a>

  <a class="kpi k-bad" href="payments.php?r=overdue#receivables">
    <div class="kpi-top">❗ Overdue</div>
    <div class="kpi-val">₹<?= money($odAmt + $opAmt) ?></div>
    <div class="kpi-sub"><?= $odN ?> invoices · <?= $opN ?> bills</div></a>

  <a class="kpi k-info" href="payments.php?r=soon#receivables">
    <div class="kpi-top">📅 Due This Week</div>
    <div class="kpi-val">₹<?= money($wkAmt + $wpAmt) ?></div>
    <div class="kpi-sub"><?= $wkN + $wpN ?> invoices / bills</div></a>
</div>

<?php // ---------- 2. what to do ---------- ?>
<div class="page-actions no-print">
  <?php if (can('payments.add')): ?>
  <a class="btn btn-success" href="payments.php?action=new&dir=in">+ Payment In</a>
  <a class="btn btn-danger" href="payments.php?action=new&dir=out">− Payment Out</a>
  <a class="btn btn-outline" href="payments.php?action=contra">🔄 Contra / Settle</a>
  <?php endif; ?>
  <a class="btn btn-outline" href="collection.php">📮 Collection Queue</a>
  <?php if (is_full_admin()): ?>
  <form method="post" style="display:inline" onsubmit="return confirm('Link every old unlinked payment to its party oldest outstanding bills automatically? No balance changes — only the per-bill dues become correct.')">
    <?= csrf_field() ?><input type="hidden" name="do" value="backfill_alloc">
    <button class="btn btn-outline" type="submit">🧹 Link old payments to bills</button>
  </form>
  <?php endif; ?>
</div>

<div class="row-2">
<?php // ---------- 3. today ---------- ?>
<div class="pane">
  <div class="pane-head"><h3>📅 Today's Money</h3>
    <span class="mini"><?= e(dmy($today)) ?></span>
    <a class="btn btn-sm btn-outline" href="reports.php?r=cashbook">View all today →</a></div>
  <div class="pane-body">
    <div class="grid-stats" style="margin:0">
      <div class="stat s-ok"><div class="stat-label">Received</div>
        <div class="stat-value">₹<?= money($today2['got'] ?? 0) ?></div>
        <div class="mini"><?= (int)($today2['n_in'] ?? 0) ?> entries</div></div>
      <div class="stat s-bad"><div class="stat-label">Paid</div>
        <div class="stat-value">₹<?= money($today2['gave'] ?? 0) ?></div>
        <div class="mini"><?= (int)($today2['n_out'] ?? 0) ?> entries</div></div>
      <div class="stat"><div class="stat-label">Net</div>
        <div class="stat-value">₹<?= money(($today2['got'] ?? 0) - ($today2['gave'] ?? 0)) ?></div></div>
      <div class="stat"><div class="stat-label">Entries</div>
        <div class="stat-value"><?= (int)($today2['n_in'] ?? 0) + (int)($today2['n_out'] ?? 0) ?></div></div>
    </div>
    <p class="mini" style="margin-top:8px">Contra and discount entries are left out — they move money between
      accounts rather than in or out of the shop.</p>
  </div>
</div>

<?php // ---------- 4. take money, without leaving the page ---------- ?>
<?php if (can('payments.add')): ?>
<div class="pane">
  <div class="pane-head"><h3>⚡ Quick Collection</h3></div>
  <div class="pane-body">
    <p class="mini" style="margin-top:0">Pick the customer and the rest of the form opens with their
      outstanding already filled in — the same screen as Payment In, so the same rules apply.</p>
    <form method="get" action="payments.php" class="filterbar" style="margin:0">
      <input type="hidden" name="action" value="new">
      <input type="hidden" name="dir" value="in">
      <div style="flex:1;min-width:200px"><label>Customer</label>
        <select name="party" required>
          <option value="">Search name…</option>
          <?php foreach ($parties as $pp): ?>
            <option value="<?= (int)$pp['id'] ?>"><?= e($pp['name']) ?></option>
          <?php endforeach; ?>
        </select></div>
      <button class="btn btn-success" type="submit">Receive Payment →</button>
    </form>
  </div>
</div>
<?php endif; ?>
</div>

<?php // ---------- 5. who to chase first ---------- ?>
<?php
$cq = [];
try { $cq = array_slice(coll_queue(60, false), 0, 6); } catch (Exception $e) { $cq = []; }
if ($cq): ?>
<div class="pane">
  <div class="pane-head"><h3>🔴 Collection Priority <span class="mini">(most overdue first)</span></h3>
    <a class="btn btn-sm btn-outline" href="collection.php">View all →</a></div>
  <div class="pane-body tight">
    <table class="rowlist">
      <thead><tr><th>Customer</th><th class="num">Overdue ₹</th><th class="num">Days</th><th class="act"></th></tr></thead>
      <tbody>
      <?php foreach ($cq as $c): ?>
        <tr>
          <td data-l="Customer"><a href="parties.php?action=ledger&id=<?= (int)$c['id'] ?>"><?= e($c['name']) ?></a>
            <?php if (!empty($c['mobile'])): ?><br><span class="mini"><?= e($c['mobile']) ?></span><?php endif; ?></td>
          <td class="num money-out" data-l="Overdue">₹<?= money($c['overdue']) ?></td>
          <td class="num" data-l="Days"><span class="badge badge-bad"><?= (int)($c['days'] ?? 0) ?>d</span></td>
          <td class="act">
            <?= voice_call_button((int)$c['id'], 'payments.php') ?>
            <?php if (!empty($c['mobile']) && $c['can_remind']['ok']): ?>
            <form method="post" action="collection.php" style="display:inline">
              <?= csrf_field() ?><input type="hidden" name="do" value="preview_bulk">
              <input type="hidden" name="pick[]" value="<?= (int)$c['id'] ?>">
              <button class="btn btn-sm btn-wa" type="submit" title="Send a WhatsApp reminder">📲</button>
            </form>
            <?php endif; ?>
            <?php if (can('payments.add')): ?>
            <a class="btn btn-sm btn-success" href="payments.php?action=new&dir=in&party=<?= (int)$c['id'] ?>">Receive</a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php // ---------- 6. money that is not ours ---------- ?>
<?php if ($advList): ?>
<div class="pane" id="advance">
  <div class="pane-head"><h3>🪙 Advance / Money Held — ₹<?= money($advAll) ?></h3>
    <a class="btn btn-sm btn-outline" href="parties.php?bal=give">View all →</a></div>
  <p class="pane-note">We are holding this money for them. It goes against their next bill automatically —
    and it is the easiest thing in a shop to forget.</p>
  <div class="pane-body tight">
    <table class="rowlist">
      <thead><tr><th>Party</th><th>Mobile</th><th class="num">Credit ₹</th></tr></thead>
      <tbody>
      <?php foreach ($advList as $a): ?>
        <tr>
          <td data-l="Party"><a href="parties.php?action=ledger&id=<?= (int)$a['id'] ?>"><?= e($a['name']) ?></a></td>
          <td class="mini" data-l="Mobile"><?= e($a['mobile'] ?: '—') ?></td>
          <td class="num money-in" data-l="Credit">₹<?= money($a['adv']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php // ---------- 7. receivables ---------- ?>
<?php
// Filtered here, on rows already loaded and already capped. The list is
// bounded at 100 bills by the query above, so this costs nothing.
$recvRows = array_values(array_filter($dueSales, function ($x) use ($fRecv, $today, $weekEnd) {
    $d = $x['due_date'] ?: null;
    if ($fRecv === 'overdue') return $d && $d < $today;
    if ($fRecv === 'soon')    return $d && $d >= $today && $d <= $weekEnd;
    if ($fRecv === 'nodue')   return !$d || $d > $weekEnd;
    return true;
}));
$cnt = fn($k) => count(array_filter($dueSales, function ($x) use ($k, $today, $weekEnd) {
    $d = $x['due_date'] ?: null;
    if ($k === 'overdue') return $d && $d < $today;
    if ($k === 'soon')    return $d && $d >= $today && $d <= $weekEnd;
    if ($k === 'nodue')   return !$d || $d > $weekEnd;
    return true;
}));
?>
<div class="pane" id="receivables">
  <div class="pane-head"><h3>💰 Receivables <span class="mini">(customer dues)</span></h3>
    <div class="seg">
      <a class="<?= $fRecv === 'all' ? 'on' : '' ?>" href="payments.php?r=all#receivables">All (<?= $cnt('all') ?>)</a>
      <a class="<?= $fRecv === 'overdue' ? 'on-bad' : '' ?>" href="payments.php?r=overdue#receivables">Overdue (<?= $cnt('overdue') ?>)</a>
      <a class="<?= $fRecv === 'soon' ? 'on-warn' : '' ?>" href="payments.php?r=soon#receivables">This week (<?= $cnt('soon') ?>)</a>
      <a class="<?= $fRecv === 'nodue' ? 'on-ok' : '' ?>" href="payments.php?r=nodue#receivables">Later (<?= $cnt('nodue') ?>)</a>
    </div>
  </div>
  <div class="pane-body tight">
    <table class="rowlist">
      <thead><tr><th>Invoice</th><th>Customer</th><th class="num">Due ₹</th><th>Due date</th><th class="act"></th></tr></thead>
      <tbody>
      <?php foreach ($recvRows as $sl): $d = $sl['adj_due'] ?? ($sl['total'] - $sl['paid']);
            $late = $sl['due_date'] && $sl['due_date'] < $today; ?>
        <tr>
          <td data-l="Invoice"><a href="sale_view.php?id=<?= (int)$sl['id'] ?>"><?= e($sl['invoice_no']) ?></a></td>
          <td data-l="Customer"><?= $sl['party_id']
              ? '<a href="parties.php?action=ledger&id=' . (int)$sl['party_id'] . '">' . e($sl['customer_name'] ?: 'Walk-in') . '</a>'
              : e($sl['customer_name'] ?: 'Walk-in') ?></td>
          <td class="num money-out" data-l="Due">₹<?= money($d) ?></td>
          <td data-l="Due date"><?= $sl['due_date'] ? e(dmy($sl['due_date'])) : '<span class="mini">no date</span>' ?>
            <?= $late ? ' <span class="badge badge-bad">' . (int)days_between($sl['due_date']) . 'd</span>' : '' ?></td>
          <td class="act">
            <?php if (can('payments.add') && $sl['party_id']): ?>
              <a class="btn btn-sm btn-success" href="payments.php?action=new&dir=in&party=<?= (int)$sl['party_id'] ?>">Receive</a>
            <?php endif; ?>
            <?php if ($sl['customer_mobile']): ?>
            <form method="post" style="display:inline"><?= csrf_field() ?>
              <input type="hidden" name="do" value="remind"><input type="hidden" name="sale_id" value="<?= (int)$sl['id'] ?>">
              <button class="btn btn-sm btn-wa" type="submit" title="WhatsApp this bill">📲</button></form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$recvRows): ?><tr><td colspan="5" class="mini" style="padding:16px">Nothing here 🎉</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php // ---------- 8. payables ---------- ?>
<?php
$payRows = array_values(array_filter($duePurchases, function ($x) use ($fPay, $today, $weekEnd) {
    $d = $x['due_date'] ?: null;
    if ($fPay === 'overdue') return $d && $d < $today;
    if ($fPay === 'soon')    return $d && $d >= $today && $d <= $weekEnd;
    return true;
}));
$cntP = fn($k) => count(array_filter($duePurchases, function ($x) use ($k, $today, $weekEnd) {
    $d = $x['due_date'] ?: null;
    if ($k === 'overdue') return $d && $d < $today;
    if ($k === 'soon')    return $d && $d >= $today && $d <= $weekEnd;
    return true;
}));
?>
<div class="pane" id="payables">
  <div class="pane-head"><h3>📤 Payables <span class="mini">(supplier dues)</span></h3>
    <div class="seg">
      <a class="<?= $fPay === 'all' ? 'on' : '' ?>" href="payments.php?p=all#payables">All (<?= $cntP('all') ?>)</a>
      <a class="<?= $fPay === 'overdue' ? 'on-bad' : '' ?>" href="payments.php?p=overdue#payables">Overdue (<?= $cntP('overdue') ?>)</a>
      <a class="<?= $fPay === 'soon' ? 'on-warn' : '' ?>" href="payments.php?p=soon#payables">This week (<?= $cntP('soon') ?>)</a>
    </div>
  </div>
  <div class="pane-body tight">
    <table class="rowlist">
      <thead><tr><th>Bill</th><th>Supplier</th><th class="num">Due ₹</th><th>Due date</th><th class="act"></th></tr></thead>
      <tbody>
      <?php foreach ($payRows as $pu): $late = $pu['due_date'] && $pu['due_date'] < $today; ?>
        <tr>
          <td data-l="Bill"><a href="purchase_view.php?id=<?= (int)$pu['id'] ?>">#<?= (int)$pu['id'] ?> <?= e($pu['bill_no']) ?></a></td>
          <td data-l="Supplier"><a href="parties.php?action=ledger&id=<?= (int)$pu['party_id'] ?>"><?= e($pu['party_name']) ?></a></td>
          <td class="num money-out" data-l="Due">₹<?= money($pu['adj_due'] ?? ($pu['total'] - $pu['paid'])) ?></td>
          <td data-l="Due date"><?= $pu['due_date'] ? e(dmy($pu['due_date'])) : '<span class="mini">no date</span>' ?>
            <?= $late ? ' <span class="badge badge-bad">overdue</span>' : '' ?></td>
          <td class="act">
            <?php if (can('payments.add')): ?>
            <a class="btn btn-sm btn-danger" href="payments.php?action=new&dir=out&party=<?= (int)$pu['party_id'] ?>">Pay</a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$payRows): ?><tr><td colspan="5" class="mini" style="padding:16px">Nothing here 🎉</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php // ---------- 9. what has been entered ---------- ?>
<div class="pane" id="recent">
  <div class="pane-head"><h3>🕘 Recent Payments</h3>
    <div class="seg">
      <?php foreach (['all' => 'All', 'in' => 'IN', 'out' => 'OUT', 'cash' => 'Cash', 'bank' => 'Bank',
                      'upi' => 'UPI', 'contra' => 'Contra', 'discount' => 'Discount'] as $k => $lbl): ?>
        <a class="<?= $fRec === $k ? 'on' : '' ?>" href="payments.php?f=<?= $k ?>#recent"><?= e($lbl) ?></a>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="pane-body tight">
    <table class="rowlist">
      <thead><tr><th>#</th><th>Date</th><th>Party</th><th>Dir</th><th class="num">Amount</th>
                 <th>Mode</th><th>Notes</th><th class="act"></th></tr></thead>
      <tbody>
      <?php foreach ($recent as $pm): $in = $pm['direction'] === 'in'; ?>
        <tr>
          <td data-l="#"><a href="payments.php?action=view&id=<?= (int)$pm['id'] ?>">P-<?= str_pad($pm['id'], 5, '0', STR_PAD_LEFT) ?></a></td>
          <td data-l="Date"><?= e(dmy($pm['pay_date'])) ?></td>
          <td data-l="Party"><?= $pm['party_id']
              ? '<a href="parties.php?action=ledger&id=' . (int)$pm['party_id'] . '">' . e($pm['party_name']) . '</a>'
              : '<span class="mini">Walk-in</span>' ?></td>
          <td data-l="Direction"><span class="badge <?= $in ? 'pill-in' : 'pill-out' ?>"><?= $in ? 'IN' : 'OUT' ?></span></td>
          <td class="num <?= $in ? 'money-in' : 'money-out' ?>" data-l="Amount">₹<?= money($pm['amount']) ?></td>
          <td data-l="Mode"><?= e($pm['mode']) ?></td>
          <td data-l="Notes"><span class="mini"><?= e(mb_substr((string)$pm['notes'], 0, 90)) ?>
            <?= $pm['by_name'] ? '(' . e($pm['by_name']) . ')' : '' ?></span></td>
          <td class="act">
            <a class="btn btn-sm btn-outline" href="payments.php?action=view&id=<?= (int)$pm['id'] ?>">View</a>
            <?php if ($pm['mode'] !== 'contra' && can('payments.edit')): ?>
              <a class="btn btn-sm btn-outline" href="payments.php?action=edit&id=<?= (int)$pm['id'] ?>">✏️</a>
            <?php endif; ?>
            <?php if (can('payments.delete')): ?>
            <form method="post" onsubmit="return confirm('Delete entry?')" style="display:inline"><?= csrf_field() ?>
              <input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= (int)$pm['id'] ?>">
              <button class="btn btn-sm btn-danger" type="submit">✕</button></form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$recent): ?><tr><td colspan="8" class="mini" style="padding:16px">Nothing matches that filter.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php if ($pgMax > 1): ?>
  <div class="pane-note" style="display:flex;align-items:center;gap:10px;padding:12px 14px">
    <span>Page <?= $pg ?> of <?= $pgMax ?> · <?= $recTotal ?> entries</span>
    <span style="flex:1"></span>
    <?php if ($pg > 1): ?><a class="btn btn-sm btn-outline" href="payments.php?f=<?= e($fRec) ?>&pg=<?= $pg - 1 ?>#recent">← Newer</a><?php endif; ?>
    <?php if ($pg < $pgMax): ?><a class="btn btn-sm btn-outline" href="payments.php?f=<?= e($fRec) ?>&pg=<?= $pg + 1 ?>#recent">Older →</a><?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
