<?php
// Payments ledger: receipts (in) and payments (out) + outstanding with WhatsApp reminder
require_once __DIR__ . '/includes/init.php';
require_perm('payments.view');
$u = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'save') {
    require_perm('payments.add');
    $party_id = (int)post('party_id');
    $amt = (float)post('amount');
    if ($party_id && $amt > 0) {
        q('INSERT INTO payments (party_id, direction, amount, mode, pay_date, notes, created_by) VALUES (?,?,?,?,?,?,?)',
          [$party_id, post('direction', 'in'), $amt, post('mode', 'cash'), post('pay_date', today()), post('notes'), $u['id']]);
        log_activity('payment_add', "party=$party_id " . post('direction') . " $amt");
        flash('Payment recorded.');
    }
    redirect('payments.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'remind') {
    $s = row('SELECT s.*, c.name company_name FROM sales s JOIN companies c ON c.id = s.company_id WHERE s.id = ?', [(int)post('sale_id')]);
    if ($s && $s['customer_mobile']) {
        $dueAmt = $s['total'] - $s['paid'];
        send_whatsapp($s['customer_mobile'],
            '*' . $s['company_name'] . "*\nPayment reminder 🙏\nInvoice: {$s['invoice_no']} (" . dmy($s['sale_date']) . ")\n" .
            "Balance due: *₹" . money($dueAmt) . "*" . ($s['due_date'] ? "\nDue date: " . dmy($s['due_date']) : '') .
            "\nKindly arrange the payment. Thank you!");
        flash('Reminder sent on WhatsApp.');
    } else {
        flash('No customer mobile on this bill.', 'error');
    }
    redirect('payments.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'delete') {
    require_perm('payments.delete');
    q('DELETE FROM payments WHERE id = ?', [(int)post('id')]);
    flash('Payment entry deleted.');
    redirect('payments.php');
}

$parties = all('SELECT id, name FROM parties WHERE is_active = 1 ORDER BY name');
$recent = all('SELECT p.*, pt.name party_name, u2.name by_name FROM payments p
               JOIN parties pt ON pt.id = p.party_id JOIN users u2 ON u2.id = p.created_by
               ORDER BY p.id DESC LIMIT 100');
$dueSales = all("SELECT s.*, c.name company_name FROM sales s JOIN companies c ON c.id = s.company_id
                 WHERE s.status <> 'paid' ORDER BY s.due_date IS NULL, s.due_date LIMIT 100");
$duePurchases = all("SELECT p.*, pt.name party_name FROM purchases p JOIN parties pt ON pt.id = p.party_id
                     WHERE p.status <> 'paid' ORDER BY p.due_date IS NULL, p.due_date LIMIT 100");

$page_title = 'Payments';
include __DIR__ . '/includes/header.php';
?>
<?php if (can('payments.add')): ?>
<div class="card">
  <h2>Record payment</h2>
  <form method="post" class="filterbar">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="save">
    <div><label>Party</label><select name="party_id"><?php foreach ($parties as $p): ?><option value="<?= $p['id'] ?>"><?= e($p['name']) ?></option><?php endforeach; ?></select></div>
    <div><label>Direction</label><select name="direction"><option value="in">Received (in)</option><option value="out">Paid (out)</option></select></div>
    <div><label>Amount</label><input type="number" step="any" name="amount" required></div>
    <div><label>Mode</label><select name="mode"><option>cash</option><option>upi</option><option>bank</option><option>cheque</option></select></div>
    <div><label>Date</label><input type="date" name="pay_date" value="<?= today() ?>"></div>
    <div><label>Notes</label><input type="text" name="notes"></div>
    <button class="btn btn-sm" type="submit">Save</button>
  </form>
</div>
<?php endif; ?>

<div class="card">
  <h2>💰 Receivables (customers to pay us)</h2>
  <div class="table-wrap" style="box-shadow:none">
  <table>
    <thead><tr><th>Invoice</th><th>Customer</th><th class="num">Due ₹</th><th>Due date</th><th></th></tr></thead>
    <tbody><?php foreach ($dueSales as $s): $d = $s['total'] - $s['paid']; ?>
      <tr>
        <td><a href="sale_view.php?id=<?= $s['id'] ?>"><?= e($s['invoice_no']) ?></a></td>
        <td><?= e($s['customer_name'] ?: 'Walk-in') ?></td>
        <td class="num">₹<?= money($d) ?></td>
        <td><?= dmy($s['due_date']) ?><?= $s['due_date'] && $s['due_date'] < today() ? ' <span class="badge badge-bad">overdue</span>' : '' ?></td>
        <td>
          <?php if ($s['customer_mobile']): ?>
          <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="do" value="remind"><input type="hidden" name="sale_id" value="<?= $s['id'] ?>">
            <button class="btn btn-sm btn-wa" type="submit">📲 Remind</button></form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; if (!$dueSales): ?><tr><td colspan="5" class="muted">All clear 🎉</td></tr><?php endif; ?></tbody>
  </table>
  </div>
</div>

<div class="card">
  <h2>📤 Payables (we owe suppliers)</h2>
  <div class="table-wrap" style="box-shadow:none">
  <table>
    <thead><tr><th>Bill</th><th>Supplier</th><th class="num">Due ₹</th><th>Due date</th><th></th></tr></thead>
    <tbody><?php foreach ($duePurchases as $p): ?>
      <tr>
        <td><a href="purchase_view.php?id=<?= $p['id'] ?>">#<?= $p['id'] ?> <?= e($p['bill_no']) ?></a></td>
        <td><?= e($p['party_name']) ?></td>
        <td class="num">₹<?= money($p['total'] - $p['paid']) ?></td>
        <td><?= dmy($p['due_date']) ?><?= $p['due_date'] && $p['due_date'] < today() ? ' <span class="badge badge-bad">overdue</span>' : '' ?></td>
        <td></td>
      </tr>
    <?php endforeach; if (!$duePurchases): ?><tr><td colspan="5" class="muted">All clear 🎉</td></tr><?php endif; ?></tbody>
  </table>
  </div>
</div>

<div class="card">
  <h2>Recent entries</h2>
  <div class="table-wrap" style="box-shadow:none">
  <table>
    <thead><tr><th>Date</th><th>Party</th><th>Dir</th><th class="num">Amount</th><th>Mode</th><th>Notes</th><th></th></tr></thead>
    <tbody><?php foreach ($recent as $pm): ?>
      <tr>
        <td><?= dmy($pm['pay_date']) ?></td>
        <td><?= e($pm['party_name']) ?></td>
        <td><?= $pm['direction'] === 'in' ? '<span class="badge badge-ok">IN</span>' : '<span class="badge badge-bad">OUT</span>' ?></td>
        <td class="num">₹<?= money($pm['amount']) ?></td>
        <td><?= e($pm['mode']) ?></td>
        <td><?= e($pm['notes']) ?> <span class="muted">(<?= e($pm['by_name']) ?>)</span></td>
        <td><?php if (can('payments.delete')): ?>
          <form method="post" onsubmit="return confirm('Delete entry?')" style="display:inline"><?= csrf_field() ?>
          <input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= $pm['id'] ?>">
          <button class="btn btn-sm btn-danger" type="submit">✕</button></form><?php endif; ?></td>
      </tr>
    <?php endforeach; ?></tbody>
  </table>
  </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
