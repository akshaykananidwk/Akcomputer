<?php
// 🏖️ Leave: a person asks, the owner says yes or no. An approved paid leave
// counts as a paid day in payroll; unpaid leave does not.
require_once __DIR__ . '/includes/init.php';
require_login();
$u = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'ask') {
    $f = post('from_date'); $t = post('to_date') ?: $f;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $f) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $t) || $t < $f) { flash('Pick the dates again.', 'error'); redirect('leaves.php'); }
    q('INSERT INTO leave_requests (user_id, from_date, to_date, paid, reason) VALUES (?,?,?,?,?)',
      [$u['id'], $f, $t, post('paid') === '0' ? 0 : 1, mb_substr(trim((string)post('reason')), 0, 255)]);
    owner_alert('🏖️ Leave asked: ' . $u['name'] . ' · ' . dmy($f) . ($t !== $f ? ' to ' . dmy($t) : '') . (post('reason') ? ' — ' . mb_substr(post('reason'), 0, 80) : ''));
    flash('Sent to the owner.');
    redirect('leaves.php');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(post('do'), ['approved', 'rejected'], true)) {
    require_perm('hr.edit');
    q("UPDATE leave_requests SET status = ?, decided_by = ?, decided_at = NOW() WHERE id = ? AND status = 'pending'", [post('do'), $u['id'], (int)post('id')]);
    log_activity('leave_' . post('do'), '#' . (int)post('id'));
    redirect('leaves.php');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'withdraw') {
    q("DELETE FROM leave_requests WHERE id = ? AND user_id = ? AND status = 'pending'", [(int)post('id'), $u['id']]);
    redirect('leaves.php');
}

$all = can('hr.view');
$rows = all('SELECT l.*, us.name FROM leave_requests l JOIN users us ON us.id = l.user_id ' . ($all ? '' : 'WHERE l.user_id = ' . (int)$u['id']) .
            " ORDER BY (l.status = 'pending') DESC, l.from_date DESC LIMIT 200");
$page_title = 'Leave';
include __DIR__ . '/includes/header.php';
?>
<div class="page-head"><h1>🏖️ Leave</h1><a class="btn btn-sm btn-outline" href="attendance.php">🕘 Attendance</a></div>
<form method="post" class="card" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:8px;align-items:end"><?= csrf_field() ?><input type="hidden" name="do" value="ask">
  <div class="field"><label>From</label><input type="date" name="from_date" required></div>
  <div class="field"><label>To</label><input type="date" name="to_date"></div>
  <div class="field"><label>Type</label><select name="paid"><option value="1">Paid leave</option><option value="0">Without pay</option></select></div>
  <div class="field"><label>Reason</label><input type="text" name="reason" maxlength="255"></div>
  <button class="btn" type="submit">Ask for leave</button>
</form>
<div class="pane"><div class="pane-body tight"><table class="rowlist"><thead><tr><?php if ($all): ?><th>Staff</th><?php endif; ?><th>Dates</th><th>Type</th><th>Reason</th><th>Status</th><th class="act"></th></tr></thead><tbody>
<?php foreach ($rows as $l): ?>
  <tr><?php if ($all): ?><td data-l="Staff"><b><?= e($l['name']) ?></b></td><?php endif; ?>
    <td data-l="Dates"><?= dmy($l['from_date']) ?><?= $l['to_date'] !== $l['from_date'] ? ' → ' . dmy($l['to_date']) : '' ?></td>
    <td data-l="Type"><?= $l['paid'] ? 'Paid' : 'Without pay' ?></td><td data-l="Reason"><?= e($l['reason']) ?></td>
    <td data-l="Status"><span class="badge badge-<?= ['pending' => 'warn', 'approved' => 'ok', 'rejected' => 'bad'][$l['status']] ?>"><?= e($l['status']) ?></span></td>
    <td class="act"><?php if ($l['status'] === 'pending'): ?>
      <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
      <?php if (can('hr.edit')): ?><button class="btn btn-sm" name="do" value="approved">✔ Yes</button><button class="btn btn-sm btn-outline" name="do" value="rejected">✕ No</button>
      <?php elseif ((int)$l['user_id'] === (int)$u['id']): ?><button class="btn btn-sm btn-outline" name="do" value="withdraw">Withdraw</button><?php endif; ?></form>
    <?php endif; ?></td></tr>
<?php endforeach; if (!$rows): ?><tr><td class="muted">No leave asked yet.</td></tr><?php endif; ?>
</tbody></table></div></div>
<?php include __DIR__ . '/includes/footer.php'; ?>
