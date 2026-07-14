<?php
// Technician mobile panel - "my jobs today": field tasks + repair jobs
// assigned to me, with one-tap status updates. No separate technician
// role/flag exists in this app (see includes/auth.php) - this page just
// scopes everything to assigned_to = current_user()['id'], the same way
// tasks.php already does for its own list/view.
require_once __DIR__ . '/includes/init.php';
require_login();
$u = current_user();

$myTasks = all("SELECT * FROM tasks WHERE assigned_to = ? AND status IN ('assigned','started') ORDER BY scheduled_date, id", [$u['id']]);
$myRepairs = all("SELECT * FROM repairs WHERE assigned_to = ? AND status NOT IN ('delivered','returned_unrepaired') ORDER BY received_date, id", [$u['id']]);
$myFollowUps = all("SELECT f.*, p.name party_name FROM follow_ups f LEFT JOIN parties p ON p.id = f.party_id
                     WHERE f.assigned_to = ? AND f.status = 'pending' AND f.due_date <= ? ORDER BY f.due_date", [$u['id'], today()]);

$repairNext = ['received' => 'in_progress', 'in_progress' => 'ready', 'outsourced' => 'ready', 'ready' => 'delivered'];
$repairNextLabel = ['in_progress' => 'Start Repair', 'ready' => 'Mark Ready', 'delivered' => 'Mark Delivered'];

$page_title = 'My Jobs';
include __DIR__ . '/includes/header.php';
?>
<div class="card">
  <h2>👷 My Jobs Today</h2>
  <p class="muted">Everything assigned to you - tap a button to update status on the spot.</p>
</div>

<?php if ($myFollowUps): ?>
<div class="card">
  <h3>⏰ Follow-ups due</h3>
  <?php foreach ($myFollowUps as $f): ?>
  <div class="list-row" style="cursor:default">
    <div class="list-row-main"><strong><?= e($f['title']) ?></strong><div class="muted list-row-sub"><?= e($f['party_name'] ?: '') ?> · due <?= dmy($f['due_date']) ?></div></div>
    <div class="list-row-val"><a class="btn btn-sm btn-outline" href="follow_ups.php">Open</a></div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="card">
  <h3>🛠️ Field Tasks (<?= count($myTasks) ?>)</h3>
  <?php if (!$myTasks): ?><p class="muted">No open tasks assigned to you.</p><?php endif; ?>
  <?php foreach ($myTasks as $t): ?>
  <div class="list-row" style="cursor:default;flex-wrap:wrap;gap:8px">
    <div class="list-row-main">
      <strong><?= e($t['task_no']) ?></strong> <?= status_badge($t['status']) ?>
      <div class="muted list-row-sub"><?= e($t['customer_name']) ?> · 📍<?= e($t['address']) ?></div>
      <div class="muted list-row-sub"><?= e(mb_substr($t['description'], 0, 80)) ?></div>
    </div>
    <div class="list-row-val" style="display:flex;gap:6px">
      <?php if ($t['status'] === 'assigned'): ?>
      <form method="post" action="tasks.php"><?= csrf_field() ?><input type="hidden" name="do" value="start"><input type="hidden" name="id" value="<?= $t['id'] ?>">
        <button class="btn btn-sm btn-success" type="submit">▶ Start</button></form>
      <?php endif; ?>
      <a class="btn btn-sm btn-outline" href="tasks.php?action=view&id=<?= $t['id'] ?>">Open</a>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<div class="card">
  <h3>💻 Repair Jobs (<?= count($myRepairs) ?>)</h3>
  <?php if (!$myRepairs): ?><p class="muted">No open repair jobs assigned to you.</p><?php endif; ?>
  <?php foreach ($myRepairs as $j): $next = $repairNext[$j['status']] ?? null; ?>
  <div class="list-row" style="cursor:default;flex-wrap:wrap;gap:8px">
    <div class="list-row-main">
      <strong><?= e($j['job_no']) ?></strong> <?= status_badge($j['status']) ?>
      <div class="muted list-row-sub"><?= e($j['customer_name']) ?> · <?= e($j['device_type']) ?> <?= e($j['brand_model']) ?></div>
      <div class="muted list-row-sub"><?= e(mb_substr($j['problem'], 0, 80)) ?></div>
    </div>
    <div class="list-row-val" style="display:flex;gap:6px;flex-wrap:wrap">
      <?php if ($next): ?>
      <form method="post" action="repairs.php"><?= csrf_field() ?>
        <input type="hidden" name="do" value="quickstatus"><input type="hidden" name="id" value="<?= $j['id'] ?>">
        <input type="hidden" name="status" value="<?= $next ?>">
        <button class="btn btn-sm btn-success" type="submit"><?= e($repairNextLabel[$next] ?? ucfirst($next)) ?></button></form>
      <?php endif; ?>
      <a class="btn btn-sm btn-outline" href="repairs.php?action=edit&id=<?= $j['id'] ?>">Open</a>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
