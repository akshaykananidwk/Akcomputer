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
        q('INSERT INTO payments (party_id, direction, amount, mode, ref_type, ref_id, pay_date, notes, created_by)
           VALUES (?,?,?,?,?,?,?,?,?)',
          [$p['party_id'], 'out', $amt, post('mode', 'cash'), 'purchase', $id, today(), 'Against bill ' . $p['bill_no'], current_user()['id']]);
        flash('Payment recorded.');
    }
    redirect('purchase_view.php?id=' . $id);
}

$items = all('SELECT pi.*, i.name, i.unit, i.serial_tracked FROM purchase_items pi JOIN items i ON i.id = pi.item_id WHERE pi.purchase_id = ?', [$id]);
$serials = all('SELECT serial_no, status, item_id FROM item_serials WHERE purchase_id = ?', [$id]);
$due = $p['total'] - $p['paid'];
$page_title = 'Purchase #' . $id;
include __DIR__ . '/includes/header.php';
?>
<div class="page-actions no-print">
  <button class="btn" onclick="window.print()">🖨️ Print</button>
  <a class="btn btn-outline" href="purchases.php">← Back</a>
</div>
<div class="card">
  <h2>Purchase #<?= $id ?> <?= status_badge($p['status']) ?></h2>
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
    <div class="t-line t-grand"><span>Total</span><span>₹<?= money($p['total']) ?></span></div>
    <div class="t-line"><span>Paid</span><span>₹<?= money($p['paid']) ?></span></div>
    <?php if ($due > 0.009): ?><div class="t-line"><span><strong>Due</strong></span><span><strong>₹<?= money($due) ?></strong></span></div><?php endif; ?>
  </div>
</div>
<?php if ($due > 0.009 && can('payments.add')): ?>
<div class="card no-print">
  <h3>Pay supplier</h3>
  <form method="post" class="filterbar">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="pay">
    <div><input type="number" step="any" name="amount" value="<?= money($due) ?>"></div>
    <div><select name="mode"><option>cash</option><option>upi</option><option>bank</option><option>cheque</option></select></div>
    <button class="btn btn-success btn-sm" type="submit">Pay</button>
  </form>
</div>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
