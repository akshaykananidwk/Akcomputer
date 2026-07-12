<?php
// Purchase detail + pay supplier
require_once __DIR__ . '/includes/init.php';
require_perm('purchases.view');

$id = (int)get('id');
$p = row('SELECT p.*, pt.name party_name, pt.mobile party_mobile, l.name loc_name
          FROM purchases p JOIN parties pt ON pt.id = p.party_id JOIN locations l ON l.id = p.location_id
          WHERE p.id = ?', [$id]);
if (!$p) die('Purchase not found.');
if (!can('purchases.all') && $p['created_by'] != current_user()['id']) die('Access denied.');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'pay' && can('payments.add')) {
    $amt = min((float)post('amount'), $p['total'] - $p['paid']);
    if ($amt > 0) {
        q('UPDATE purchases SET paid = paid + ?, status = ? WHERE id = ?',
          [$amt, payment_status($p['total'], $p['paid'] + $amt), $id]);
        q('INSERT INTO payments (party_id, direction, amount, mode, bank_account_id, ref_type, ref_id, pay_date, notes, created_by)
           VALUES (?,?,?,?,?,?,?,?,?,?)',
          [$p['party_id'], 'out', $amt, post('mode', 'cash'), (int)post('bank_account_id') ?: null, 'purchase', $id, today(), 'Against bill ' . $p['bill_no'], current_user()['id']]);
        flash('Payment recorded.');
    }
    redirect('purchase_view.php?id=' . $id);
}

$items = all("SELECT pi.*, COALESCE(i.name, '(deleted item)') name, i.unit, COALESCE(i.serial_tracked, 0) serial_tracked FROM purchase_items pi LEFT JOIN items i ON i.id = pi.item_id WHERE pi.purchase_id = ?", [$id]);
$serials = all('SELECT serial_no, status, item_id FROM item_serials WHERE purchase_id = ?', [$id]);
$due = $p['is_cancelled'] ? 0 : $p['total'] - $p['paid'];
$page_title = 'Purchase #' . $id;
include __DIR__ . '/includes/header.php';
?>
<?php if ($p['is_cancelled']): ?><div class="flash flash-error">🚫 આ PURCHASE BILL CANCELLED છે.</div><?php endif; ?>
<div class="page-actions no-print">
  <button class="btn" onclick="window.print()">🖨️ Print</button>
  <a class="btn btn-outline" href="purchases.php">← Back</a>
  <?php if (can('purchases.edit') && !$p['is_cancelled']): ?>
  <a class="btn btn-outline" href="purchases.php?action=edit&id=<?= $id ?>">✏️ Edit</a>
  <?php endif; ?>
  <?php if (can('purchases.delete')): ?>
  <?php if (!$p['is_cancelled']): ?>
  <form method="post" action="purchases.php" onsubmit="return confirm('Purchase bill CANCEL કરવું? (Record રહેશે, stock ઓછો થશે)')" style="display:inline">
    <?= csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="mode" value="cancel"><input type="hidden" name="id" value="<?= $id ?>">
    <button class="btn btn-muted" type="submit">🚫 Cancel Bill</button>
  </form>
  <?php endif; ?>
  <form method="post" action="purchases.php" onsubmit="return confirm('પૂરેપૂરું DELETE કરવું? Record પણ જતો રહેશે!')" style="display:inline">
    <?= csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="mode" value="delete"><input type="hidden" name="id" value="<?= $id ?>">
    <button class="btn btn-danger" type="submit">Delete</button>
  </form>
  <?php endif; ?>
</div>
<div class="card">
  <h2>Purchase #<?= $id ?> <?= $p['is_cancelled'] ? '<span class="badge badge-bad">CANCELLED</span>' : status_badge($p['status']) ?></h2>
  <p><strong><?= e($p['party_name']) ?></strong> · <?= e($p['party_mobile']) ?><br>
  Bill no: <?= e($p['bill_no'] ?: '-') ?> · Date: <?= dmy($p['purchase_date']) ?> · Location: <?= e($p['loc_name']) ?><br>
  Credit: <?= (int)$p['credit_days'] ?> days <?= $p['due_date'] ? '(due ' . dmy($p['due_date']) . ')' : '' ?></p>
</div>
<div class="table-wrap">
<table>
  <thead><tr><th>Item</th><th class="num">Qty</th><th class="num">Rate</th><th class="num">GST%</th><th class="num">Total</th></tr></thead>
  <tbody>
  <?php foreach ($items as $it): ?>
    <tr>
      <td><?= e($it['name']) ?>
        <?php if ($it['serial_tracked']): $sns = array_filter($serials, fn($s) => $s['item_id'] == $it['item_id']); ?>
          <br><small class="muted">SN: <?php foreach ($sns as $s) echo e($s['serial_no']) . ' (' . $s['status'] . ') '; ?></small>
        <?php endif; ?>
      </td>
      <td class="num"><?= (float)$it['qty'] ?> <?= e($it['unit']) ?></td>
      <td class="num"><?= money($it['price']) ?></td>
      <td class="num"><?= (float)$it['tax_rate'] ?>%</td>
      <td class="num"><?= money($it['total']) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<div class="card">
  <div class="bill-totals">
    <div class="t-line"><span>Subtotal</span><span>₹<?= money($p['subtotal']) ?></span></div>
    <?php if ($p['discount'] > 0): ?><div class="t-line"><span>Discount</span><span>- ₹<?= money($p['discount']) ?></span></div><?php endif; ?>
    <div class="t-line"><span>GST</span><span>₹<?= money($p['tax_amount']) ?></span></div>
    <?php if ($p['shipping'] > 0): ?><div class="t-line"><span>Shipping</span><span>₹<?= money($p['shipping']) ?></span></div><?php endif; ?>
    <div class="t-line t-grand"><span>Total</span><span>₹<?= money($p['total']) ?></span></div>
    <div class="t-line"><span>Paid</span><span>₹<?= money($p['paid']) ?></span></div>
    <?php if ($due > 0.009): ?><div class="t-line"><span><strong>Due</strong></span><span><strong>₹<?= money($due) ?></strong></span></div><?php endif; ?>
  </div>
</div>
<?php if ($due > 0.009 && can('payments.add')):
  $pms = active_payment_methods(); $banks = all('SELECT * FROM bank_accounts WHERE is_active = 1 ORDER BY is_default DESC, account_name'); ?>
<div class="card no-print">
  <h3>Pay supplier</h3>
  <form method="post" class="filterbar">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="pay">
    <div><input type="number" step="any" name="amount" value="<?= money($due) ?>"></div>
    <div><select name="mode" id="pv2_mode" onchange="document.getElementById('pv2_bank').style.display=this.selectedOptions[0].dataset.type==='bank'?'':'none'">
      <?php foreach ($pms as $pm): if ($pm['code'] === 'credit') continue; ?><option value="<?= e($pm['code']) ?>" data-type="<?= e($pm['type']) ?>"><?= e($pm['name']) ?></option><?php endforeach; ?>
    </select></div>
    <div id="pv2_bank" style="display:none"><select name="bank_account_id"><?php foreach ($banks as $b): ?><option value="<?= $b['id'] ?>"><?= e($b['account_name']) ?></option><?php endforeach; ?></select></div>
    <button class="btn btn-success btn-sm" type="submit">Pay</button>
  </form>
</div>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
