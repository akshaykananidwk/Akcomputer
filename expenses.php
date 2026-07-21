<?php
// Daily expenses (rent, tea, transport, salary...) - feeds cashbook & P/L
require_once __DIR__ . '/includes/init.php';
require_perm('expenses.view');
$u = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'save') {
    require_perm('expenses.add');
    if (is_period_locked(post('exp_date', today()))) { flash(period_lock_message(), 'error'); redirect('expenses.php'); }
    $amt = (float)post('amount');
    if ($amt > 0) {
        q('INSERT INTO expenses (exp_date, category, amount, mode, bank_account_id, payment_method_id, notes, location_id, created_by) VALUES (?,?,?,?,?,?,?,?,?)',
          [post('exp_date', today()), post('category', 'General'), $amt, post('mode', 'cash'),
           (int)post('bank_account_id') ?: null, (int)post('payment_method_id') ?: null, post('notes'), $u['location_id'], $u['id']]);
        log_activity('expense_add', post('category') . ' ' . $amt);
        flash('Expense saved.');
    }
    redirect('expenses.php');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'delete') {
    require_perm('expenses.delete');
    $exp = row('SELECT * FROM expenses WHERE id = ?', [(int)post('id')]);
    if ($exp && is_period_locked($exp['exp_date'])) { flash(period_lock_message(), 'error'); redirect('expenses.php'); }
    q('DELETE FROM expenses WHERE id = ?', [(int)post('id')]);
    flash('Expense deleted.');
    redirect('expenses.php');
}

$from = get('from', date('Y-m-01'));
$to = get('to', today());
// staff-wise view: filter to one staff member's expenses (whose pocket/wallet
// the money left), so the owner's own spend and each staff's spend stay apart
$fStaff = (int)get('staff');
$staffWhere = $fStaff ? ' AND e.created_by = ' . $fStaff : '';
$rows = all("SELECT e.*, u2.name by_name, l.name loc_name FROM expenses e
             JOIN users u2 ON u2.id = e.created_by JOIN locations l ON l.id = e.location_id
             WHERE e.exp_date BETWEEN ? AND ? $staffWhere ORDER BY e.exp_date DESC, e.id DESC", [$from, $to]);
$staffAll = all('SELECT id, name FROM users WHERE is_active = 1 ORDER BY name');
$cats = ['General', 'Rent', 'Salary', 'Electricity', 'Internet', 'Transport', 'Tea/Food', 'Stationery', 'Repair/Maintenance', 'Marketing', 'Other'];
$pms = active_payment_methods();
$banks = all('SELECT * FROM bank_accounts WHERE is_active = 1 ORDER BY is_default DESC, account_name');
$page_title = 'Expenses';
include __DIR__ . '/includes/header.php';
?>
<?php if (can('expenses.add')): ?>
<div class="card">
  <h2>Add expense</h2>
  <form method="post" class="filterbar">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="save">
    <div><label>Date</label><input type="date" name="exp_date" value="<?= today() ?>"></div>
    <div><label>Category</label><select name="category" id="exp_category"><?php foreach ($cats as $c): ?><option><?= $c ?></option><?php endforeach; ?></select></div>
    <div><label>Amount ₹</label><input type="number" step="any" name="amount" required></div>
    <div><label>Mode</label><select name="mode" id="exp_mode" onchange="document.getElementById('exp_bank').style.display=this.selectedOptions[0].dataset.type==='bank'?'':'none'">
      <?php foreach ($pms as $pm): if ($pm['code'] === 'credit') continue; ?><option value="<?= e($pm['code']) ?>" data-type="<?= e($pm['type']) ?>"><?= e($pm['name']) ?></option><?php endforeach; ?>
    </select></div>
    <div id="exp_bank" style="display:none"><label>Bank Account</label>
      <select name="bank_account_id"><?php foreach ($banks as $b): ?><option value="<?= $b['id'] ?>"><?= e($b['account_name']) ?></option><?php endforeach; ?></select></div>
    <div><label>Notes <span class="muted" style="font-weight:normal">(category auto-suggested as you type)</span></label><input type="text" name="notes" id="exp_notes"></div>
    <button class="btn btn-sm" type="submit">Save</button>
  </form>
</div>
<script>
(function () {
  var notes = document.getElementById('exp_notes'), cat = document.getElementById('exp_category');
  if (!notes || !cat) return;
  var catTouched = false, t = null;
  cat.addEventListener('change', function () { catTouched = true; });
  notes.addEventListener('input', function () {
    clearTimeout(t);
    var text = notes.value.trim();
    if (!text || catTouched) return;
    t = setTimeout(function () {
      fetch('ajax.php?a=suggest_category&text=' + encodeURIComponent(text)).then(function (r) { return r.json(); }).then(function (d) {
        if (!d.category) return;
        for (var i = 0; i < cat.options.length; i++) {
          if (cat.options[i].value === d.category) { cat.selectedIndex = i; break; }
        }
      });
    }, 400);
  });
})();
</script>
<?php endif; ?>
<form method="get" class="filterbar">
  <div><label>From</label><input type="date" name="from" value="<?= e($from) ?>"></div>
  <div><label>To</label><input type="date" name="to" value="<?= e($to) ?>"></div>
  <div><label>Staff</label>
    <select name="staff">
      <option value="0">All staff</option>
      <?php foreach ($staffAll as $s): ?><option value="<?= $s['id'] ?>" <?= $fStaff == $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?>
    </select></div>
  <button class="btn btn-sm" type="submit">Filter</button>
</form>
<?php if ($fStaff): $sn = array_values(array_filter($staffAll, fn($s) => $s['id'] == $fStaff))[0]['name'] ?? ''; ?>
<div class="mb"><span class="vyf-chip">Staff: <?= e($sn) ?> — ₹<?= money(array_sum(array_column($rows, 'amount'))) ?> spent</span></div>
<?php endif; ?>
<div class="list-count"><?= count($rows) ?> entries · Total ₹<?= money(array_sum(array_column($rows, 'amount'))) ?></div>
<div class="table-wrap">
<table>
  <thead><tr><th>Date</th><th>Category</th><th class="num">Amount</th><th>Mode</th><th>Notes</th><th>By</th><th></th></tr></thead>
  <tbody><?php foreach ($rows as $x): ?>
    <tr>
      <td><?= dmy($x['exp_date']) ?></td>
      <td><?= e($x['category']) ?></td>
      <td class="num">₹<?= money($x['amount']) ?></td>
      <td><?= e($x['mode']) ?></td>
      <td><?= e($x['notes']) ?></td>
      <td><?= e($x['by_name']) ?></td>
      <td><?php if (can('expenses.delete')): ?>
        <form method="post" style="display:inline" onsubmit="return confirm('Delete?')"><?= csrf_field() ?>
        <input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= $x['id'] ?>">
        <button class="btn btn-sm btn-danger" type="submit">✕</button></form><?php endif; ?></td>
    </tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="7" class="muted">No expenses in this period.</td></tr><?php endif; ?></tbody>
</table>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
