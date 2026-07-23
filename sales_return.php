<?php
// Sales return - stock back in, serials restored
require_once __DIR__ . '/includes/init.php';
require_perm('sales_return.view');
$u = current_user();
$action = get('action', 'list');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'save') {
    require_perm('sales_return.add');
    if (is_period_locked(post('return_date', today()))) { flash(period_lock_message(), 'error'); redirect('sales_return.php?action=new'); }
    $sale = row('SELECT * FROM sales WHERE invoice_no = ? OR id = ?', [post('invoice_ref'), (int)post('invoice_ref')]);
    $loc_id = $sale ? (int)$sale['location_id'] : (int)$u['location_id'];
    $item_ids = post('item_id', []);
    $qtys = post('qty', []);
    $prices = post('price', []);
    $serials_in = post('serials_txt', []);

    $rows = [];
    foreach ($item_ids as $i => $iid) {
        $iid = (int)$iid; $qty = (float)($qtys[$i] ?? 0);
        if ($iid && $qty > 0) $rows[] = ['item_id' => $iid, 'qty' => $qty, 'price' => (float)($prices[$i] ?? 0),
            'serials' => trim((string)($serials_in[$i] ?? ''))];
    }
    if (!$rows) { flash('Add items to return.', 'error'); redirect('sales_return.php?action=new'); }
    $total = 0;
    foreach ($rows as $r) $total += $r['qty'] * $r['price'];

    $pdo = db();
    $pdo->beginTransaction();
    try {
        q('INSERT INTO sales_returns (sale_id, party_id, customer_name, customer_mobile, location_id, return_date, total, refund_mode, notes, created_by)
           VALUES (?,?,?,?,?,?,?,?,?,?)',
          [$sale['id'] ?? null, $sale['party_id'] ?? null, post('customer_name') ?: ($sale['customer_name'] ?? ''),
           post('customer_mobile'), $loc_id, post('return_date', today()), $total, post('refund_mode', 'cash'), post('notes'), $u['id']]);
        $rid = insert_id();
        q('UPDATE sales_returns SET return_no = ? WHERE id = ?', [doc_no('SR', $rid), $rid]);
        foreach ($rows as $r) {
            $sns = array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $r['serials']))));
            q('INSERT INTO sales_return_items (return_id, item_id, qty, price, total, serials) VALUES (?,?,?,?,?,?)',
              [$rid, $r['item_id'], $r['qty'], $r['price'], $r['qty'] * $r['price'], $sns ? implode(',', $sns) : null]);
            adjust_stock($r['item_id'], $loc_id, $r['qty'], 'sales_return', $rid);
            foreach ($sns as $sn) {
                q("UPDATE item_serials SET status='in_stock', location_id=?, sale_id=NULL WHERE item_id=? AND serial_no=?",
                  [$loc_id, $r['item_id'], $sn]);
            }
        }
        // Money side of the return - without this the books drifted:
        // - cash/upi refund: the money handed back is posted to the payments
        //   ledger (direction 'out'), so the party balance stays right (a paid
        //   bill + cash refund used to show a fake "advance") and the cashbook
        //   shows the cash leaving.
        // - adjust: the return credit knocks down the ORIGINAL bill's
        //   outstanding, so bill-level dues (Aging etc.) agree with the party
        //   ledger. The applied amount is remembered for a clean delete.
        $refundMode = post('refund_mode', 'cash');
        if (in_array($refundMode, ['cash', 'bank', 'upi'], true)) {
            // bank refunds carry the default bank account so the bank ledger
            // knows exactly which account the money left ('upi' only lingers
            // from very old forms and is treated as bank)
            $refBank = $refundMode === 'cash' ? null : ((int)val('SELECT id FROM bank_accounts WHERE is_active = 1 ORDER BY is_default DESC, id LIMIT 1') ?: null);
            q("INSERT INTO payments (party_id, direction, amount, mode, bank_account_id, ref_type, ref_id, pay_date, notes, created_by)
               VALUES (?,?,?,?,?,?,?,?,?,?)",
              [$sale['party_id'] ?? null, 'out', $total, $refundMode === 'cash' ? 'cash' : 'bank', $refBank, 'sales_return', $rid,
               post('return_date', today()), 'Refund for return ' . doc_no('SR', $rid) . ($sale ? ' (bill ' . $sale['invoice_no'] . ')' : ''), $u['id']]);
        } elseif ($refundMode === 'adjust' && $sale) {
            $due = round((float)$sale['total'] - (float)$sale['paid'], 2);
            $apply = min($total, max(0, $due));
            if ($apply > 0.009) {
                q('UPDATE sales SET paid = paid + ?, status = ? WHERE id = ?',
                  [$apply, payment_status($sale['total'], $sale['paid'] + $apply), $sale['id']]);
                q('UPDATE sales_returns SET adjusted_amount = ? WHERE id = ?', [$apply, $rid]);
            }
        }
        $pdo->commit();
        log_activity('sales_return', doc_no('SR', $rid));
        flash('Sales return saved, stock restored.');
        redirect('sales_return.php');
    } catch (Exception $ex) {
        $pdo->rollBack();
        flash('Error: ' . $ex->getMessage(), 'error');
        redirect('sales_return.php?action=new');
    }
}

if ($action === 'new') {
    require_perm('sales_return.add');
    $page_title = 'New Sales Return';
    include __DIR__ . '/includes/header.php';
    ?>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="save">
      <input type="hidden" id="location_id" value="<?= $u['location_id'] ?>">
      <div class="card">
        <div class="form-row cols-4">
          <div><label>Original invoice no (optional)</label><input type="text" name="invoice_ref" placeholder="INV-26-00012"></div>
          <div><label>Customer name</label><input type="text" name="customer_name"></div>
          <div><label>Mobile</label><input type="tel" name="customer_mobile"></div>
          <div><label>Date</label><input type="date" name="return_date" value="<?= today() ?>"></div>
        </div>
        <div class="form-row cols-2">
          <div><label>Refund mode</label><select name="refund_mode"><option value="cash">cash</option><option value="bank">bank</option><option value="adjust">adjust</option></select></div>
          <div><label>Notes / reason</label><input type="text" name="notes"></div>
        </div>
      </div>
      <div class="card">
        <h3>Returned items</h3>
        <div class="bill-items" id="billItems"></div>
        <button type="button" class="btn btn-outline btn-sm" id="addRowBtn">+ Add item</button>
        <p class="muted mt">If a serial-tracked item is returned, write the serial number in "Notes" — serial status is handled from the warranty page.</p>
      </div>
      <div class="card">
        <div class="bill-totals">
          <div class="t-line t-grand"><span>Refund total</span><span>₹ <span id="t_grand">0.00</span></span></div>
          <div style="display:none"><span id="t_sub"></span><span id="t_tax"></span></div>
        </div>
        <button class="btn btn-block mt" type="submit">Save Return</button>
      </div>
    </form>
    <script>Bill.init({mode: 'sale', serials: false, locSel: 'location_id', gst: false});</script>
    <?php
    include __DIR__ . '/includes/footer.php';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'delete') {
    require_perm('sales_return.delete');
    $rid = (int)post('id');
    $ret = row('SELECT * FROM sales_returns WHERE id = ?', [$rid]);
    if ($ret && is_period_locked($ret['return_date'])) { flash(period_lock_message(), 'error'); redirect('sales_return.php'); }
    if ($ret) {
        $ritems = all('SELECT * FROM sales_return_items WHERE return_id = ?', [$rid]);
        foreach ($ritems as $ri) {
            if (!$ri['serials']) continue;
            foreach (explode(',', $ri['serials']) as $sn) {
                $srow = row('SELECT status FROM item_serials WHERE item_id = ? AND serial_no = ?', [$ri['item_id'], trim($sn)]);
                if ($srow && $srow['status'] !== 'in_stock') {
                    flash('Something has happened to serial ' . trim($sn) . ' on this return since, so it cannot be deleted.', 'error');
                    redirect('sales_return.php');
                }
            }
        }
        $pdo = db();
        $pdo->beginTransaction();
        foreach ($ritems as $ri) {
            adjust_stock($ri['item_id'], $ret['location_id'], -(float)$ri['qty'], 'sales_return_delete', $rid);
            if ($ri['serials']) {
                foreach (explode(',', $ri['serials']) as $sn) {
                    q("UPDATE item_serials SET status='sold', sale_id=?, location_id=NULL WHERE item_id=? AND serial_no=?",
                      [$ret['sale_id'], $ri['item_id'], trim($sn)]);
                }
            }
        }
        // reverse the money side too: drop any refund payment this return
        // posted, and un-apply an 'adjust' credit from the original bill
        q("DELETE FROM payments WHERE ref_type = 'sales_return' AND ref_id = ?", [$rid]);
        if ((float)($ret['adjusted_amount'] ?? 0) > 0.009 && $ret['sale_id']) {
            $bill = row('SELECT total, paid FROM sales WHERE id = ?', [$ret['sale_id']]);
            if ($bill) {
                $newPaid = max(0, round($bill['paid'] - (float)$ret['adjusted_amount'], 2));
                q('UPDATE sales SET paid = ?, status = ? WHERE id = ?', [$newPaid, payment_status($bill['total'], $newPaid), $ret['sale_id']]);
            }
        }
        q('DELETE FROM sales_return_items WHERE return_id = ?', [$rid]);
        q('DELETE FROM sales_returns WHERE id = ?', [$rid]);
        $pdo->commit();
        log_activity('sales_return_delete', $ret['return_no']);
        flash('Return ' . $ret['return_no'] . ' deleted, stock reversed.');
    }
    redirect('sales_return.php');
}

$rets = all('SELECT sr.*, u2.name staff_name FROM sales_returns sr JOIN users u2 ON u2.id = sr.created_by ORDER BY sr.id DESC LIMIT 300');
$page_title = 'Sales Returns';
include __DIR__ . '/includes/header.php';
?>
<div class="page-actions">
  <?php if (can('sales_return.add')): ?><a class="btn" href="sales_return.php?action=new">+ New Return</a><?php endif; ?>
</div>
<div class="table-wrap">
<table>
  <thead><tr><th>No</th><th>Date</th><th>Customer</th><th class="num">Refund</th><th>Mode</th><th>By</th><th></th></tr></thead>
  <tbody><?php foreach ($rets as $r): ?>
    <tr><td><strong><?= e($r['return_no']) ?></strong></td><td><?= dmy($r['return_date']) ?></td>
    <td><?= $r['party_id'] ? '<a href="parties.php?action=ledger&id=' . $r['party_id'] . '">' . e($r['customer_name']) . '</a>' : e($r['customer_name'] ?: 'Walk-in') ?></td><td class="num">₹<?= money($r['total']) ?></td>
    <td><?= e($r['refund_mode']) ?></td><td><?= e($r['staff_name']) ?></td>
    <td><?php if (can('sales_return.delete')): ?>
      <form method="post" onsubmit="return confirm('Delete this return? Stock will be adjusted back.')"><?= csrf_field() ?>
      <input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= $r['id'] ?>">
      <button class="btn btn-sm btn-danger" type="submit">✕</button></form>
    <?php endif; ?></td></tr>
  <?php endforeach; ?></tbody>
</table>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
