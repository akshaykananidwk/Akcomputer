<?php
// 🗑️ Recycle bin: what was deleted in the last 30 days (records that carry no
// money - items never billed, parked bills, follow-ups, reminders), and a
// button to bring each one back exactly as it was. Older ones are emptied.
require_once __DIR__ . '/includes/init.php';
require_perm('settings.edit');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'restore') {
    $r = row('SELECT * FROM recycle_bin WHERE id = ? AND restored_at IS NULL AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)', [(int)post('id')]);
    if (!$r) { flash('Not found any more.', 'error'); redirect('recycle.php'); }
    $why = recycle_restore(json_decode($r['sets'], true) ?: []);
    if ($why === '') { q('UPDATE recycle_bin SET restored_at = NOW() WHERE id = ?', [$r['id']]); log_activity('recycle_restore', $r['label']); flash('Brought back ✔'); }
    else flash($why, 'error');
    redirect('recycle.php');
}
$rows = all('SELECT r.*, us.name FROM recycle_bin r LEFT JOIN users us ON us.id = r.user_id WHERE r.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) ORDER BY r.id DESC LIMIT 300');
$page_title = 'Recycle bin';
include __DIR__ . '/includes/header.php';
?>
<div class="page-head"><h1>🗑️ Recycle bin</h1><span class="muted">last 30 days</span></div>
<div class="pane"><div class="pane-body tight"><table class="rowlist"><thead><tr><th>What</th><th>Deleted</th><th>By</th><th class="act"></th></tr></thead><tbody>
<?php foreach ($rows as $r): $sets = json_decode($r['sets'], true) ?: []; $first = reset($sets)[0] ?? []; ?>
  <tr><td data-l="What"><b><?= e(ucfirst($r['label'])) ?></b> <span class="muted"><?= e($first['name'] ?? $first['title'] ?? $first['label'] ?? '') ?></span></td>
    <td data-l="Deleted"><?= dmyt($r['created_at']) ?></td><td data-l="By"><?= e($r['name'] ?? '') ?></td>
    <td class="act"><?php if ($r['restored_at']): ?><span class="badge badge-ok">brought back</span><?php else: ?>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="restore"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-sm">↩ Bring back</button></form><?php endif; ?></td></tr>
<?php endforeach; if (!$rows): ?><tr><td class="muted">Nothing deleted in the last 30 days.</td></tr><?php endif; ?></tbody></table></div></div>
<?php include __DIR__ . '/includes/footer.php'; ?>
