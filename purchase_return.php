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

// ---------------------------------------------------------------------------
// One return, in full: what went back to the supplier, what is still on our
// shelf, and where the money went. The list only ever showed a total, so
// "which pieces did we actually send on this one" had no answer anywhere.
// ---------------------------------------------------------------------------
if ($action === 'view') {
    $rid = (int)get('id');
    $ret = row('SELECT pr.*, p.name party_name, p.mobile party_mobile, l.name loc_name, u2.name staff_name
                FROM purchase_returns pr
                JOIN parties p ON p.id = pr.party_id
                LEFT JOIN locations l ON l.id = pr.location_id
                LEFT JOIN users u2 ON u2.id = pr.created_by
                WHERE pr.id = ?', [$rid]);
    if (!$ret) die('Return not found.');
    $lines = all('SELECT pri.*, i.name item_name, i.unit, i.serial_tracked
                  FROM purchase_return_items pri JOIN items i ON i.id = pri.item_id
                  WHERE pri.return_id = ? ORDER BY pri.id', [$rid]);

    // the money side: an adjusted return credits real bills, a cash/bank one
    // posts a payment - show whichever actually happened
    $credits = [];
    foreach (all('SELECT c.*, pu.bill_no, pu.purchase_date, pu.total, pu.paid, pu.status
                  FROM purchase_return_credits c LEFT JOIN purchases pu ON pu.id = c.bill_id
                  WHERE c.return_id = ? ORDER BY c.id', [$rid]) as $c) $credits[] = $c;
    $refundPay = row("SELECT p.*, ba.account_name, ba.bank_name FROM payments p
                      LEFT JOIN bank_accounts ba ON ba.id = p.bank_account_id
                      WHERE p.ref_type = 'purchase_return' AND p.ref_id = ?", [$rid]);

    $page_title = 'Purchase Return ' . $ret['return_no'];
    include __DIR__ . '/includes/header.php';
    ?>
    <div class="page-actions">
      <a class="btn btn-outline btn-sm" href="purchase_return.php">← બધા રિટર્ન</a>
      <a class="btn btn-outline btn-sm" href="parties.php?action=ledger&id=<?= $ret['party_id'] ?>">સપ્લાયરનું ખાતું</a>
    </div>

    <div class="card">
      <h2><?= e($ret['return_no']) ?> <span class="badge badge-info"><?= e($ret['refund_mode']) ?></span></h2>
      <table>
        <tr><th>સપ્લાયર</th><td><a href="parties.php?action=ledger&id=<?= $ret['party_id'] ?>"><?= e($ret['party_name']) ?></a>
            <?= $ret['party_mobile'] ? ' · ' . e($ret['party_mobile']) : '' ?></td></tr>
        <tr><th>તારીખ</th><td><?= dmy($ret['return_date']) ?></td></tr>
        <tr><th>લોકેશન</th><td><?= e($ret['loc_name'] ?: '-') ?></td></tr>
        <tr><th>કુલ રકમ</th><td><strong>₹<?= money($ret['total']) ?></strong></td></tr>
        <?php if ($ret['notes']): ?><tr><th>નોંધ</th><td><?= e($ret['notes']) ?></td></tr><?php endif; ?>
        <tr><th>બનાવ્યું</th><td><?= e($ret['staff_name'] ?: '-') ?> · <?= dmyt($ret["created_at"]) ?></td></tr>
      </table>
    </div>

    <div class="card">
      <h3>📦 શું મોકલ્યું</h3>
      <div class="table-wrap">
      <table>
        <thead><tr><th>આઇટમ</th><th class="num">નંગ</th><th class="num">ભાવ</th><th class="num">કુલ</th><th>સિરિયલ નંબર</th></tr></thead>
        <tbody>
        <?php foreach ($lines as $ln):
            $sent = array_filter(array_map('trim', explode(',', (string)$ln['serials']))); ?>
          <tr>
            <td><a href="item_view.php?id=<?= $ln['item_id'] ?>"><?= e($ln['item_name']) ?></a>
                <?= $ln['serial_tracked'] ? ' <span class="badge badge-info">SN</span>' : '' ?></td>
            <td class="num"><?= 0 + $ln['qty'] ?> <?= e($ln['unit']) ?></td>
            <td class="num">₹<?= money($ln['price']) ?></td>
            <td class="num">₹<?= money($ln['total']) ?></td>
            <td>
              <?php if (!$sent): ?><span class="muted">—</span>
              <?php else: foreach ($sent as $sn):
                  // where that piece is NOW: still with the supplier, or back
                  $st = val('SELECT status FROM item_serials WHERE item_id = ? AND serial_no = ?', [$ln['item_id'], $sn]); ?>
                <div><strong><?= e($sn) ?></strong>
                  <?php if ($st === 'returned_supplier'): ?><span class="badge badge-warn">સપ્લાયર પાસે</span>
                  <?php elseif ($st === 'in_stock'): ?><span class="badge badge-ok">પાછો આવી ગયો</span>
                  <?php elseif ($st): ?><span class="badge"><?= e($st) ?></span>
                  <?php else: ?><span class="badge badge-bad">રેકોર્ડ નથી</span><?php endif; ?>
                </div>
              <?php endforeach; endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <?php if (!$lines): ?><p class="muted">આ રિટર્નમાં કોઈ આઇટમ નથી.</p><?php endif; ?>
    </div>

    <?php
    // The other half of the question: of the same items, which pieces are
    // STILL with us. Only for serial-tracked lines - for anything else there
    // is no serial list to compare against, so the quantity is the answer.
    $trackedLines = array_filter($lines, fn($l) => !empty($l['serial_tracked']));
    if ($trackedLines): ?>
    <div class="card">
      <h3>🏠 શું નથી મોકલ્યું <span class="muted" style="font-weight:normal">— આ જ આઇટમના જે પીસ હજી આપણી પાસે છે</span></h3>
      <?php foreach ($trackedLines as $ln):
          $left = all("SELECT serial_no FROM item_serials
                       WHERE item_id = ? AND status = 'in_stock'" . ($ret['location_id'] ? ' AND location_id = ?' : '') . "
                       ORDER BY serial_no",
                      $ret['location_id'] ? [$ln['item_id'], $ret['location_id']] : [$ln['item_id']]); ?>
        <div style="margin-bottom:10px">
          <strong><?= e($ln['item_name']) ?></strong>
          <span class="muted">— સ્ટોકમાં <?= count($left) ?> પીસ</span>
          <?php if ($left): ?>
            <div class="sp-list" style="max-height:150px;overflow:auto;margin-top:4px">
              <?php foreach ($left as $l2): ?><span class="badge badge-ok" style="margin:2px"><?= e($l2['serial_no']) ?></span><?php endforeach; ?>
            </div>
          <?php else: ?>
            <div class="muted">આ લોકેશનમાં આ આઇટમનો એકેય સિરિયલ સ્ટોકમાં નથી.</div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="card">
      <h3>💰 પૈસાનું શું થયું</h3>
      <?php if ($ret['refund_mode'] === 'adjust'): ?>
        <?php if ($credits): ?>
          <p class="muted">આ રકમ સપ્લાયરનાં જૂનાં બિલમાં જમા થઈ (સૌથી જૂનું પહેલાં):</p>
          <div class="table-wrap">
          <table>
            <thead><tr><th>બિલ</th><th>તારીખ</th><th class="num">બિલની રકમ</th><th class="num">આમાં જમા</th><th>હાલત</th></tr></thead>
            <tbody><?php $sumC = 0; foreach ($credits as $c): $sumC += (float)$c['amount']; ?>
              <tr><td><?= e($c['bill_no'] ?: '#' . $c['bill_id']) ?></td>
                  <td><?= $c['purchase_date'] ? dmy($c['purchase_date']) : '-' ?></td>
                  <td class="num">₹<?= money($c['total'] ?? 0) ?></td>
                  <td class="num"><strong>₹<?= money($c['amount']) ?></strong></td>
                  <td><?= $c['status'] ? status_badge($c['status']) : '-' ?></td></tr>
            <?php endforeach; ?></tbody>
          </table>
          </div>
          <?php $leftOver = money_r((float)$ret['total'] - $sumC); if ($leftOver > MONEY_EPS): ?>
            <p class="muted">બાકીના ₹<?= money($leftOver) ?> સપ્લાયરના ખાતામાં જમા પડ્યા છે — હજી કોઈ બિલ સામે લાગ્યા નથી.</p>
          <?php endif; ?>
        <?php else: ?>
          <p class="muted">આખી રકમ ₹<?= money($ret['total']) ?> સપ્લાયરના ખાતામાં જમા છે — એ વખતે કોઈ બિલ બાકી નહોતું.</p>
        <?php endif; ?>
      <?php elseif ($refundPay): ?>
        <p>સપ્લાયરે <strong>₹<?= money($refundPay['amount']) ?></strong> પાછા આપ્યા
          (<?= e($refundPay['mode']) ?><?= $refundPay['account_name'] ? ' — ' . e($refundPay['account_name']) . ' / ' . e($refundPay['bank_name']) : '' ?>)
          · <?= dmy($refundPay['pay_date']) ?></p>
      <?php else: ?>
        <p class="muted">આ રિટર્નનું કોઈ પેમેન્ટ નોંધાયેલું નથી.</p>
      <?php endif; ?>
    </div>
    <?php
    include __DIR__ . '/includes/footer.php';
    exit;
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
    <tr><td><a href="purchase_return.php?action=view&id=<?= $r['id'] ?>"><strong><?= e($r['return_no']) ?></strong></a></td><td><?= dmy($r['return_date']) ?></td>
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
