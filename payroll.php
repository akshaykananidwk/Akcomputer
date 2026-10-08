<?php
// 💵 Payroll: the month's salary for each person, from attendance and leave.
// Saving keeps the slip; paying writes one "Salary & Wages" expense, so cash,
// bank and profit follow the usual expense rules. A paid slip is not changed.
require_once __DIR__ . '/includes/init.php';
require_login();
$u = current_user();
$month = preg_match('/^\d{4}-\d{2}$/', (string)get('m', post('m'))) ? get('m', post('m')) : date('Y-m', strtotime('first day of last month'));

// a slip: its owner may always see their own
if ((int)get('slip')) {
    $s = row('SELECT p.*, us.name, us.username FROM payslips p JOIN users us ON us.id = p.user_id WHERE p.id = ?', [(int)get('slip')]);
    if (!$s || ((int)$s['user_id'] !== (int)$u['id'] && !can('hr.payroll'))) die('Not found.');
    $co = row('SELECT * FROM companies WHERE is_active = 1 ORDER BY id LIMIT 1'); ?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Salary slip <?= e($s['month']) ?></title>
<style>body{font:14px/1.5 sans-serif;max-width:560px;margin:20px auto;padding:0 14px;color:#111;background:#fff}h2{margin:0}table{width:100%;border-collapse:collapse;margin:12px 0}td{padding:6px;border-bottom:1px solid #ddd}td:last-child{text-align:right}.t td{font-weight:700;border-top:2px solid #111}@media print{.np{display:none}}</style></head><body>
<h2><?= e($co['name'] ?? setting('app_name')) ?></h2><div><?= e($co['address'] ?? '') ?></div>
<h3>Salary slip — <?= e(date('F Y', strtotime($s['month'] . '-01'))) ?></h3>
<p><b><?= e($s['name']) ?></b> (<?= e($s['username']) ?>)</p>
<table>
  <tr><td>Monthly salary</td><td>₹<?= money($s['salary']) ?></td></tr>
  <tr><td>Days paid</td><td><?= +$s['paid_days'] ?> of <?= (int)$s['days_in_month'] ?></td></tr>
  <tr><td>Earned</td><td>₹<?= money($s['earned']) ?></td></tr>
  <?php if ($s['commission'] > 0): ?><tr><td>Commission on sales</td><td>₹<?= money($s['commission']) ?></td></tr><?php endif; ?>
  <?php if ($s['advance_deduct'] > 0): ?><tr><td>Advance taken (less)</td><td>− ₹<?= money($s['advance_deduct']) ?></td></tr><?php endif; ?>
  <?php if ($s['other_deduct'] > 0): ?><tr><td>Other deduction (less)</td><td>− ₹<?= money($s['other_deduct']) ?></td></tr><?php endif; ?>
  <tr class="t"><td>Net pay</td><td>₹<?= money($s['net']) ?></td></tr>
</table>
<p><?= e(amount_in_words($s['net'])) ?></p>
<p><?= $s['paid_at'] ? 'Paid on ' . dmy($s['paid_at']) : 'Not paid yet' ?></p>
<p class="np"><button onclick="print()">🖨️ Print</button> <a href="<?= can('hr.payroll') ? 'payroll.php?m=' . e($s['month']) : 'attendance.php' ?>">← Back</a></p>
</body></html>
<?php exit; }

require_perm('hr.payroll');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'save') {
    foreach ((array)post('uid', []) as $i => $uid) {
        $st = row('SELECT * FROM users WHERE id = ?', [(int)$uid]);
        if (!$st || val('SELECT paid_at FROM payslips WHERE user_id = ? AND month = ?', [$st['id'], $month])) continue;   // a paid slip stays as it was paid
        $c = hr_payslip_calc($st, $month, post('advance', [])[$i] ?? 0, post('other', [])[$i] ?? 0);
        q('INSERT INTO payslips (user_id, month, days_in_month, paid_days, salary, earned, commission, advance_deduct, other_deduct, net, created_by)
           VALUES (?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE days_in_month = VALUES(days_in_month), paid_days = VALUES(paid_days), salary = VALUES(salary),
           earned = VALUES(earned), commission = VALUES(commission), advance_deduct = VALUES(advance_deduct), other_deduct = VALUES(other_deduct), net = VALUES(net)',
          [$st['id'], $month, $c['days_in_month'], $c['paid_days'], $c['salary'], $c['earned'], $c['commission'], $c['advance_deduct'], $c['other_deduct'], $c['net'], $u['id']]);
    }
    log_activity('payroll_save', $month);
    flash('Slips saved for ' . date('F Y', strtotime("$month-01")) . '.');
    redirect("payroll.php?m=$month");
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'pay') {
    $s = row('SELECT p.*, us.name FROM payslips p JOIN users us ON us.id = p.user_id WHERE p.id = ? AND p.paid_at IS NULL', [(int)post('id')]);
    if (!$s || $s['net'] <= 0) { flash('Nothing to pay on this slip.', 'error'); redirect("payroll.php?m=$month"); }
    if (is_period_locked(today())) { flash(period_lock_message(), 'error'); redirect("payroll.php?m=$month"); }
    $mode = post('mode', 'cash');
    [$pmId, $bankId] = resolve_payment_target($mode, post('bank_account_id'));
    $pdo = db(); $pdo->beginTransaction();
    try {
        q('INSERT INTO expenses (exp_date, category, amount, mode, bank_account_id, payment_method_id, notes, location_id, created_by) VALUES (?,?,?,?,?,?,?,?,?)',
          [today(), 'Salary & Wages', $s['net'], $mode, $bankId, $pmId, 'Salary ' . $s['month'] . ' — ' . $s['name'], $u['location_id'], $u['id']]);
        $eid = insert_id();
        q('UPDATE payslips SET expense_id = ?, paid_at = NOW() WHERE id = ? AND paid_at IS NULL', [$eid, $s['id']]);
        $pdo->commit();
    } catch (Exception $e) { $pdo->rollBack(); flash(plain_error($e), 'error'); redirect("payroll.php?m=$month"); }
    log_activity('payroll_pay', $s['name'] . ' ' . $s['month'] . ' ' . $s['net']);
    flash('₹' . money($s['net']) . ' paid to ' . $s['name'] . ' — written as a Salary expense.');
    redirect("payroll.php?m=$month");
}

$staff = all('SELECT * FROM users WHERE is_active = 1 AND salary_monthly > 0 ORDER BY name');
$slips = [];
foreach (all('SELECT * FROM payslips WHERE month = ?', [$month]) as $s) $slips[$s['user_id']] = $s;
$pms = active_payment_methods();
$page_title = 'Payroll';
include __DIR__ . '/includes/header.php';
?>
<div class="page-head"><h1>💵 Payroll</h1>
  <form><input type="month" name="m" value="<?= e($month) ?>" onchange="this.form.submit()"></form></div>
<?php if (!$staff): ?><div class="card">No one has a monthly salary yet. Set it in <a href="team.php?tab=shifts">Team → Shifts &amp; salary</a>.</div><?php else: ?>
<form method="post" class="pane"><?= csrf_field() ?><input type="hidden" name="do" value="save"><input type="hidden" name="m" value="<?= e($month) ?>">
<div class="pane-body tight"><table class="rowlist"><thead><tr><th>Staff</th><th class="num">Days paid</th><th class="num">Earned</th><th class="num">Commission</th><th class="num">Advance (less)</th><th class="num">Other (less)</th><th class="num">Net</th><th class="act"></th></tr></thead><tbody>
<?php foreach ($staff as $i => $st): $s = $slips[$st['id']] ?? null; $c = hr_payslip_calc($st, $month, $s['advance_deduct'] ?? 0, $s['other_deduct'] ?? 0); $paid = $s && $s['paid_at']; ?>
  <tr><td data-l="Staff"><b><?= e($st['name']) ?></b><br><small class="muted">₹<?= money($st['salary_monthly']) ?>/month · P<?= $c['present'] ?> H<?= $c['half'] ?> L<?= $c['leave'] ?> Off<?= $c['off'] ?> A<?= $c['absent'] ?></small>
      <input type="hidden" name="uid[]" value="<?= (int)$st['id'] ?>"></td>
    <td class="num" data-l="Days paid"><?= +($paid ? $s['paid_days'] : $c['paid_days']) ?>/<?= $c['days_in_month'] ?></td>
    <td class="num" data-l="Earned">₹<?= money($paid ? $s['earned'] : $c['earned']) ?></td>
    <td class="num" data-l="Commission">₹<?= money($paid ? $s['commission'] : $c['commission']) ?></td>
    <td class="num" data-l="Advance"><input type="number" name="advance[]" min="0" step="0.01" value="<?= +($s['advance_deduct'] ?? 0) ?>" style="width:90px" <?= $paid ? 'readonly' : '' ?>></td>
    <td class="num" data-l="Other"><input type="number" name="other[]" min="0" step="0.01" value="<?= +($s['other_deduct'] ?? 0) ?>" style="width:90px" <?= $paid ? 'readonly' : '' ?>></td>
    <td class="num" data-l="Net"><b>₹<?= money($paid ? $s['net'] : $c['net']) ?></b></td>
    <td class="act"><?php if ($s): ?><a class="btn btn-sm btn-outline" href="payroll.php?slip=<?= (int)$s['id'] ?>" target="_blank">🧾 Slip</a><?php endif; ?>
      <?= $paid ? '<span class="badge badge-ok">paid ' . dmy($s['paid_at']) . '</span>' : '' ?></td></tr>
<?php endforeach; ?></tbody></table></div>
<div style="padding:10px"><button class="btn" type="submit">💾 Save slips</button> <span class="muted">Advance: money given to the person during the month as "Staff Advance".</span></div>
</form>
<?php $unpaid = array_filter($slips, fn($s) => !$s['paid_at'] && $s['net'] > 0); if ($unpaid): ?>
<div class="card"><h3 style="margin-top:0">Pay saved slips</h3>
<?php foreach ($unpaid as $s): $nm = val('SELECT name FROM users WHERE id = ?', [$s['user_id']]); ?>
  <form method="post" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin:6px 0"><?= csrf_field() ?><input type="hidden" name="do" value="pay"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><input type="hidden" name="m" value="<?= e($month) ?>">
    <b style="min-width:140px"><?= e($nm) ?></b> ₹<?= money($s['net']) ?>
    <select name="mode"><?php foreach ($pms as $p): if ($p['code'] === 'credit') continue; ?><option value="<?= e($p['code']) ?>"><?= e($p['name']) ?></option><?php endforeach; ?></select>
    <button class="btn btn-sm" type="submit" onclick="return confirm('Pay ₹<?= money($s['net']) ?> to <?= e($nm) ?>?')">Pay</button></form>
<?php endforeach; ?></div>
<?php endif; endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
