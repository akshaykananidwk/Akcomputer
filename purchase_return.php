<?php
// Purchase return - stock out back to supplier
require_once __DIR__ . '/includes/init.php';
require_perm('purchase_return.view');
$u = current_user();
$action = get('action', 'list');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'save') {
    require_perm('purchase_return.add');
    if (is_period_locked(post('return_date', today()))) { flash(period_lock_message(), 'error'); redirect('purchase_return.php?action=new'); }
    $party_id = (int)post('party_id');
    $loc_id = (int)post('location_id') ?: $u['location_id'];
    $item_ids = post('item_id', []);
    $qtys = post('qty', []);
    $prices = post('price', []);
    // WHICH pieces are going back. Sending goods to a supplier takes them off
    // our own shelf, so the list to pick from is the serials in stock at this
    // location - the same tick-boxes as selling one. serial_sel is keyed by the
    // row's own id, so deleting a row cannot hand one item another's serials.
    $serialSel = post('serial_sel', []);
    $rowNs = post('row_n', []);
    $rows = [];
    foreach ($item_ids as $i => $iid) {
        $iid = (int)$iid; $qty = (float)($qtys[$i] ?? 0);
        if (!$iid || $qty <= 0) continue;
        $n = $rowNs[$i] ?? null;
        $sns = ($n !== null && isset($serialSel[$n])) ? array_values(array_filter(array_map('trim', (array)$serialSel[$n]))) : [];
        $rows[] = ['item_id' => $iid, 'qty' => $qty, 'price' => (float)($prices[$i] ?? 0), 'sns' => $sns];
    }
    if (!$rows || !$party_id) { flash('Supplier and items required.', 'error'); redirect('purchase_return.php?action=new'); }

    // Check every serial BEFORE anything is written. A piece can only go back
    // to the supplier if it is actually on our shelf - sending one that is
    // already sold, or already gone, would take a unit off the count twice.
    foreach ($rows as $r) {
        $item = row('SELECT name, serial_tracked FROM items WHERE id = ?', [$r['item_id']]);
        if ($r['sns'] && count($r['sns']) != (int)$r['qty']) {
            flash(($item['name'] ?? '#' . $r['item_id']) . ': ' . count($r['sns']) . ' સિરિયલ પસંદ કર્યા છે પણ જથ્થો '
                . (0 + $r['qty']) . ' છે — બંને સરખા હોવા જોઈએ.', 'error');
            redirect('purchase_return.php?action=new');
        }
        if (!empty($item['serial_tracked']) && !$r['sns']) {
            flash(($item['name'] ?? '#' . $r['item_id']) . ': આ આઇટમ સિરિયલવાળી છે — કયો સિરિયલ પાછો મોકલવાનો છે એ પસંદ કરો.', 'error');
            redirect('purchase_return.php?action=new');
        }
        foreach ($r['sns'] as $sn) {
            $srow = row('SELECT status FROM item_serials WHERE item_id = ? AND serial_no = ?', [$r['item_id'], $sn]);
            if (!$srow) {
                flash('સિરિયલ ' . $sn . ' આ આઇટમનો નથી (નોંધાયેલો જ નથી).', 'error');
                redirect('purchase_return.php?action=new');
            }
            if ($srow['status'] !== 'in_stock') {
                flash('સિરિયલ ' . $sn . ' સ્ટોકમાં નથી (અત્યારે: ' . $srow['status'] . ') — એટલે સપ્લાયરને પાછો મોકલી શકાય નહીં.', 'error');
                redirect('purchase_return.php?action=new');
            }
        }
    }
    $total = 0;
    foreach ($rows as $r) $total += $r['qty'] * $r['price'];

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $refundMode = in_array(post('refund_mode'), ['cash', 'bank', 'adjust'], true) ? post('refund_mode') : 'adjust';
        q('INSERT INTO purchase_returns (party_id, location_id, return_date, total, refund_mode, notes, created_by) VALUES (?,?,?,?,?,?,?)',
          [$party_id, $loc_id, post('return_date', today()), $total, $refundMode, post('notes'), $u['id']]);
        $rid = insert_id();
        q('UPDATE purchase_returns SET return_no = ? WHERE id = ?', [doc_no('PR', $rid), $rid]);
        $sentSns = [];
        foreach ($rows as $r) {
            $item = row('SELECT name FROM items WHERE id = ?', [$r['item_id']]);
            if (stock_qty($r['item_id'], $loc_id) < $r['qty']) throw new Exception("Not enough stock of {$item['name']} to return.");
            q('INSERT INTO purchase_return_items (return_id, item_id, qty, price, total, serials) VALUES (?,?,?,?,?,?)',
              [$rid, $r['item_id'], $r['qty'], $r['price'], $r['qty'] * $r['price'], $r['sns'] ? implode(',', $r['sns']) : null]);
            adjust_stock($r['item_id'], $loc_id, -$r['qty'], 'purchase_return', $rid);
            // the piece itself goes back to the supplier. Recorded on the line,
            // so deleting the return can put exactly these serials back.
            foreach ($r['sns'] as $sn) {
                q("UPDATE item_serials SET status='returned_supplier', location_id=NULL
                   WHERE item_id=? AND serial_no=? AND status='in_stock'", [$r['item_id'], $sn]);
                $sentSns[] = $sn;
            }
        }
        // the box at the top is the fallback for an item that is NOT serial-
        // tracked but still came with a number on it; scoped the same way
        foreach (array_filter(array_map('trim', preg_split('/[\r\n,]+/', (string)post('return_serials')))) as $sn) {
            q("UPDATE item_serials SET status='returned_supplier', location_id=NULL WHERE serial_no=? AND status='in_stock'", [$sn]);
            $sentSns[] = $sn;
        }
        // Money side: when the supplier actually hands cash/UPI back, post it
        // to the payments ledger (money IN) so the party balance and cashbook
        // stay right.
        //
        // 'adjust' means the supplier keeps the money and we hold a credit.
        // The ledger has always counted that credit; what it did not do was
        // put it against anything, so every purchase bill still read as fully
        // outstanding. Now it settles the OLDEST due bill first - the rule
        // every payment follows - and no payments row is written, because
        // party_balance_expr() already counts the return itself and a payment
        // would count it twice.
        $creditedTo = [];
        if ($refundMode === 'adjust')
            $creditedTo = money_apply_return_credit('purchase', $rid, $party_id, $total, (int)post('credit_bill_id'));
        if (in_array($refundMode, ['cash', 'bank'], true)) {
            // the owner picks the account the money landed in; the default one
            // is only the fallback when they did not (resolve_payment_target)
            list(, $refBank) = resolve_payment_target($refundMode, (int)post('bank_account_id'));
            q("INSERT INTO payments (party_id, direction, amount, mode, bank_account_id, ref_type, ref_id, pay_date, notes, created_by)
               VALUES (?,?,?,?,?,?,?,?,?,?)",
              [$party_id, 'in', $total, $refundMode, $refBank, 'purchase_return', $rid,
               post('return_date', today()), 'Refund received for return ' . doc_no('PR', $rid), $u['id']]);
        }
        $pdo->commit();
        log_activity('purchase_return', doc_no('PR', $rid));
        $msg = 'Purchase return saved, stock deducted.';
        if ($sentSns) $msg .= ' સપ્લાયરને પાછા મોકલેલા સિરિયલ: ' . implode(', ', array_unique($sentSns)) . '.';
        if ($creditedTo) {
            $bits = [];
            foreach ($creditedTo as $t) $bits[] = $t['label'] . ' ₹' . money($t['amount']);
            $msg .= ' Credit applied to the oldest bill(s): ' . implode(', ', $bits) . '.';
        } elseif ($refundMode === 'adjust') {
            $msg .= ' Held as credit on the supplier — they have no bill due right now.';
        }
        flash($msg);
        redirect('purchase_return.php');
    } catch (Exception $ex) {
        $pdo->rollBack();
        flash('Error: ' . $ex->getMessage(), 'error');
        redirect('purchase_return.php?action=new');
    }
}

if ($action === 'new') {
    require_perm('purchase_return.add');
    $suppliers = all("SELECT id, name, mobile FROM parties WHERE is_active = 1 ORDER BY name");
    $banks = all('SELECT id, account_name, bank_name, is_default FROM bank_accounts WHERE is_active = 1 ORDER BY is_default DESC, account_name');
    $locations = all('SELECT * FROM locations WHERE is_active = 1 ORDER BY name');
    $page_title = 'New Purchase Return';
    include __DIR__ . '/includes/header.php';
    ?>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="save">
      <div class="card">
        <div class="form-row cols-3">
          <div><label>Supplier *</label>
            <select name="party_id" id="party_id" required><option value="">-- select --</option>
            <?php foreach ($suppliers as $s): ?><option value="<?= $s['id'] ?>" data-mobile="<?= e($s['mobile']) ?>"><?= e($s['name']) ?></option><?php endforeach; ?>
            </select></div>
          <div><label>From location</label>
            <select name="location_id" id="location_id">
              <?php foreach ($locations as $l): ?><option value="<?= $l['id'] ?>" <?= $l['id'] == $u['location_id'] ? 'selected' : '' ?>><?= e($l['name']) ?></option><?php endforeach; ?>
            </select></div>
          <div><label>Date</label><input type="date" name="return_date" value="<?= today() ?>"></div>
        </div>
        <div class="form-row cols-2">
          <div><label>Refund</label>
            <select name="refund_mode" id="refundMode">
              <option value="adjust">Adjust against supplier account (credit)</option>
              <option value="cash">Supplier refunded CASH</option>
              <option value="bank">Supplier refunded to BANK</option>
            </select></div>
          <div id="bankWrap" style="display:none"><label>Which bank account?</label>
            <select name="bank_account_id">
              <?php foreach ($banks as $b): ?><option value="<?= $b['id'] ?>"<?= $b['is_default'] ? ' selected' : '' ?>><?= e($b['account_name']) ?> - <?= e($b['bank_name']) ?></option><?php endforeach; ?>
            </select></div>
          <div id="creditWrap"><label>Credit it to which bill?</label>
            <select name="credit_bill_id" id="creditBill"><option value="0">Automatic - oldest bill first</option></select>
            <small class="muted" id="creditHint">Pick a supplier to see their unpaid bills.</small></div>
        </div>
        <div class="field"><label>સિરિયલ નંબર — ફક્ત સિરિયલ યાદી વગરની આઇટમ માટે (કોમા / નવી લાઇન, મરજી પ્રમાણે)</label>
          <textarea name="return_serials" rows="2"></textarea>
          <small class="muted">સિરિયલવાળી આઇટમ નીચે ઉમેરશો એટલે એની સ્ટોકમાં પડેલી સિરિયલ યાદી ત્યાં જ આવશે — ટિક કરી લેજો.</small></div>
        <div class="field"><label>Notes / reason</label><input type="text" name="notes"></div>
      </div>
      <div class="card">
        <h3>Items</h3>
        <div class="bill-items" id="billItems"></div>
        <button type="button" class="btn btn-outline btn-sm" id="addRowBtn">+ Add item</button>
        <div class="bill-totals mt">
          <div class="t-line t-grand"><span>Return total</span><span>₹ <span id="t_grand">0.00</span></span></div>
          <div style="display:none"><span id="t_sub"></span><span id="t_tax"></span></div>
        </div>
        <button class="btn btn-block mt" type="submit">Save Return</button>
      </div>
    </form>
    <script>Bill.init({mode: 'purchase', serials: true, pickStock: true, locSel: 'location_id', gst: false});
    ReturnMoney.init({dir: 'out', partySel: 'select[name=party_id]'});
    PartyPick.init('party_id', 'સપ્લાયરનું નામ કે મોબાઇલ ટાઇપ કરો…');</script>
    <?php
    include __DIR__ . '/includes/footer.php';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'delete') {
    require_perm('purchase_return.delete');
    $rid = (int)post('id');
    $ret = row('SELECT * FROM purchase_returns WHERE id = ?', [$rid]);
    if ($ret && is_period_locked($ret['return_date'])) { flash(period_lock_message(), 'error'); redirect('purchase_return.php'); }
    if ($ret) {
        $ritems = all('SELECT * FROM purchase_return_items WHERE return_id = ?', [$rid]);
        $allowNeg = setting('allow_negative_stock', '1') === '1';
        if (!$allowNeg) {
            foreach ($ritems as $ri) {
                $item = row('SELECT name FROM items WHERE id = ?', [$ri['item_id']]);
                if (stock_qty($ri['item_id'], $ret['location_id']) + (float)$ri['qty'] < 0) {
                    flash("Deleting would make {$item['name']}'s stock negative, so it was blocked.", 'error');
                    redirect('purchase_return.php');
                }
            }
        }
        $pdo = db();
        $pdo->beginTransaction();
        $backSns = [];
        foreach ($ritems as $ri) {
            adjust_stock($ri['item_id'], $ret['location_id'], (float)$ri['qty'], 'purchase_return_delete', $rid);
            // ...and the pieces themselves come back off the supplier's books.
            // This used to be left to the owner ("please manually verify").
            foreach (array_filter(array_map('trim', explode(',', (string)$ri['serials']))) as $sn) {
                serial_put_in_stock((int)$ri['item_id'], $sn, (int)$ret['location_id']);
                $backSns[] = $sn;
            }
        }
        // reverse any refund this return posted to the ledger
        q("DELETE FROM payments WHERE ref_type = 'purchase_return' AND ref_id = ?", [$rid]);
        // ...and put back exactly what its credit took off each purchase bill
        money_reverse_return_credit('purchase', $rid);
        q('DELETE FROM purchase_return_items WHERE return_id = ?', [$rid]);
        q('DELETE FROM purchase_returns WHERE id = ?', [$rid]);
        $pdo->commit();
        log_activity('purchase_return_delete', $ret['return_no']);
        flash('Return ' . $ret['return_no'] . ' deleted, stock reversed.'
            . ($backSns ? ' સિરિયલ પાછા સ્ટોકમાં: ' . implode(', ', $backSns) . '.' : ''));
    }
    redirect('purchase_return.php');
}

$rets = all('SELECT pr.*, p.name party_name FROM purchase_returns pr JOIN parties p ON p.id = pr.party_id ORDER BY pr.id DESC LIMIT 300');
$page_title = 'Purchase Returns';
include __DIR__ . '/includes/header.php';
?>
<div class="page-actions">
  <?php if (can('purchase_return.add')): ?><a class="btn" href="purchase_return.php?action=new">+ New Return</a><?php endif; ?>
</div>
<div class="table-wrap">
<table>
  <thead><tr><th>No</th><th>Date</th><th>Supplier</th><th class="num">Total</th><th>Notes</th><th></th></tr></thead>
  <tbody><?php foreach ($rets as $r): ?>
    <tr><td><strong><?= e($r['return_no']) ?></strong></td><td><?= dmy($r['return_date']) ?></td>
    <td><a href="parties.php?action=ledger&id=<?= $r['party_id'] ?>"><?= e($r['party_name']) ?></a></td><td class="num">₹<?= money($r['total']) ?></td><td><?= e($r['notes']) ?></td>
    <td><?php if (can('purchase_return.delete')): ?>
      <form method="post" onsubmit="return confirm('Delete this return? Stock will be adjusted back.')"><?= csrf_field() ?>
      <input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= $r['id'] ?>">
      <button class="btn btn-sm btn-danger" type="submit">✕</button></form>
    <?php endif; ?></td></tr>
  <?php endforeach; ?></tbody>
</table>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
