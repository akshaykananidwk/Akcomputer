<?php
// Follow-up reminders - optionally tied to a lead, repair job, ticket or
// party (ref_type/ref_id, mirroring the feedback table's polymorphic
// pattern), or just a standalone reminder. Surfaced as a due-today/overdue
// list here and on the technician panel (my_jobs.php).
require_once __DIR__ . '/includes/init.php';
require_perm('followups.view');
$u = current_user();
$action = get('action', 'list');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'save') {
    require_perm('followups.add');
    $title = post('title');
    $due = post('due_date', today());
    if (!$title || !$due) { flash('Title and due date are required.', 'error'); redirect('follow_ups.php?action=new'); }
    q('INSERT INTO follow_ups (ref_type, ref_id, party_id, title, due_date, assigned_to, notes, created_by) VALUES (?,?,?,?,?,?,?,?)',
      [post('ref_type') ?: '', (int)post('ref_id') ?: null, (int)post('party_id') ?: null, $title, $due,
       (int)post('assigned_to') ?: null, post('notes'), $u['id']]);
    log_activity('followup_add', $title);
    flash('Follow-up scheduled for ' . dmy($due) . '.');
    redirect('follow_ups.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'set_status') {
    $fid = (int)post('id');
    $f = row('SELECT * FROM follow_ups WHERE id = ?', [$fid]);
    if ($f && ($f['assigned_to'] == $u['id'] || $f['created_by'] == $u['id'] || can('followups.all'))) {
        $st = post('status');
        if (in_array($st, ['pending', 'done', 'cancelled'], true)) {
            q('UPDATE follow_ups SET status = ?, completed_at = IF(? = "done", NOW(), NULL) WHERE id = ?', [$st, $st, $fid]);
            flash('Follow-up marked ' . $st . '.');
        }
    }
    redirect('follow_ups.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'delete') {
    require_perm('followups.delete');
    q('DELETE FROM follow_ups WHERE id = ?', [(int)post('id')]);
    flash('Follow-up deleted.');
    redirect('follow_ups.php');
}

if ($action === 'new') {
    require_perm('followups.add');
    $staffList = all('SELECT id, name FROM users WHERE is_active = 1 ORDER BY name');
    $parties = all('SELECT id, name FROM parties WHERE is_active = 1 ORDER BY name');
    $refType = get('ref_type');
    $refId = (int)get('ref_id');
    $refLabel = '';
    if ($refType === 'lead' && $refId) { $lead = row('SELECT lead_no, name FROM leads WHERE id = ?', [$refId]); if ($lead) $refLabel = 'Lead ' . $lead['lead_no'] . ' - ' . $lead['name']; }
    if ($refType === 'ticket' && $refId) { $tk = row('SELECT ticket_no, subject FROM tickets WHERE id = ?', [$refId]); if ($tk) $refLabel = 'Ticket ' . $tk['ticket_no'] . ' - ' . $tk['subject']; }
    if ($refType === 'repair' && $refId) { $rp = row('SELECT job_no, customer_name FROM repairs WHERE id = ?', [$refId]); if ($rp) $refLabel = 'Job ' . $rp['job_no'] . ' - ' . $rp['customer_name']; }
    $page_title = 'New Follow-up';
    include __DIR__ . '/includes/header.php';
    ?>
    <div class="card">
      <h2>⏰ Schedule follow-up</h2>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="save">
        <input type="hidden" name="ref_type" value="<?= e($refType) ?>">
        <input type="hidden" name="ref_id" value="<?= $refId ?>">
        <?php if ($refLabel): ?><p class="flash flash-info">Linked to: <?= e($refLabel) ?></p><?php endif; ?>
        <div class="field"><label>Title / reason *</label><input type="text" name="title" required placeholder="e.g. Call to confirm quotation"></div>
        <div class="form-row cols-3">
          <div><label>Due date *</label><input type="date" name="due_date" value="<?= today() ?>" required></div>
          <div><label>Party (optional)</label>
            <select name="party_id">
              <option value="">-- none --</option>
              <?php foreach ($parties as $p): ?><option value="<?= $p['id'] ?>"><?= e($p['name']) ?></option><?php endforeach; ?>
            </select></div>
          <div><label>Assign to</label>
            <select name="assigned_to">
              <option value="">-- me --</option>
              <?php foreach ($staffList as $s): ?><option value="<?= $s['id'] ?>" <?= $s['id'] == $u['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?>
            </select></div>
        </div>
        <div class="field"><label>Notes</label><textarea name="notes" rows="2"></textarea></div>
        <button class="btn" type="submit">Schedule Follow-up</button>
        <a class="btn btn-muted" href="follow_ups.php">Back</a>
      </form>
    </div>
    <?php
    include __DIR__ . '/includes/footer.php';
    exit;
}

// ---------- list ----------
$where = " status = 'pending' ";
$params = [];
if (!can('followups.all')) { $where .= ' AND (assigned_to = ? OR created_by = ?) '; $params = [$u['id'], $u['id']]; }
$view = get('view', 'due');
if ($view === 'done') { $where = " status = 'done' "; if (!can('followups.all')) { $where .= ' AND (assigned_to = ? OR created_by = ?) '; $params = [$u['id'], $u['id']]; } }
$fups = all("SELECT f.*, p.name party_name, s.name staff_name FROM follow_ups f
             LEFT JOIN parties p ON p.id = f.party_id LEFT JOIN users s ON s.id = f.assigned_to
             WHERE $where ORDER BY f.due_date " . ($view === 'done' ? 'DESC' : 'ASC') . " LIMIT 300", $params);
$page_title = 'Follow-ups';
include __DIR__ . '/includes/header.php';
?>
<div class="page-actions">
  <?php if (can('followups.add')): ?><a class="btn" href="follow_ups.php?action=new">+ New Follow-up</a><?php endif; ?>
  <a class="btn btn-sm btn-outline" href="follow_ups.php?view=due">Due / Pending</a>
  <a class="btn btn-sm btn-outline" href="follow_ups.php?view=done">Completed</a>
</div>
<div class="table-wrap">
<table>
  <thead><tr><th>Due</th><th>Title</th><th>Party</th><th>Assigned</th><th>Ref</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($fups as $f):
      $overdue = $view !== 'done' && $f['due_date'] < today(); ?>
    <tr<?= $overdue ? ' style="background:rgba(220,38,38,.06)"' : '' ?>>
      <td><?= dmy($f['due_date']) ?><?= $overdue ? ' <span class="badge badge-bad">overdue</span>' : ($f['due_date'] === today() ? ' <span class="badge badge-warn">today</span>' : '') ?></td>
      <td><?= e($f['title']) ?><?= $f['notes'] ? '<br><span class="muted">' . e($f['notes']) . '</span>' : '' ?></td>
      <td><?= $f['party_name'] ? '<a href="parties.php?action=ledger&id=' . $f['party_id'] . '">' . e($f['party_name']) . '</a>' : '-' ?></td>
      <td><?= e($f['staff_name'] ?: '-') ?></td>
      <td><?php
        $refUrls = ['lead' => 'leads.php?action=view&id=', 'ticket' => 'tickets.php?action=view&id=', 'repair' => 'repairs.php?action=edit&id='];
        if ($f['ref_type'] && $f['ref_id'] && isset($refUrls[$f['ref_type']])): ?>
        <a href="<?= e($refUrls[$f['ref_type']]) ?><?= $f['ref_id'] ?>"><?= ucfirst(e($f['ref_type'])) ?></a>
      <?php else: ?>-<?php endif; ?></td>
      <td style="white-space:nowrap">
        <?php if ($view !== 'done'): ?>
        <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="do" value="set_status"><input type="hidden" name="id" value="<?= $f['id'] ?>"><input type="hidden" name="status" value="done">
          <button class="btn btn-sm btn-success" type="submit">✓ Done</button></form>
        <?php endif; ?>
        <?php if (can('followups.delete')): ?>
        <form method="post" style="display:inline" onsubmit="return confirm('Delete this follow-up?')"><?= csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= $f['id'] ?>">
          <button class="btn btn-sm btn-danger" type="submit">✕</button></form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$fups): ?><tr><td colspan="6" class="muted">Nothing here.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
