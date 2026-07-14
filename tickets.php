<?php
// Complaint / support tickets - separate from repair job sheets (a repair
// job IS the work order; a ticket is "the customer is unhappy about X",
// which may or may not end up creating a repair job) but linkable to one.
require_once __DIR__ . '/includes/init.php';
require_perm('tickets.view');
$u = current_user();
$action = get('action', 'list');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'save') {
    require_perm('tickets.add');
    $subject = post('subject');
    if (!$subject) { flash('Subject is required.', 'error'); redirect('tickets.php?action=new'); }
    q('INSERT INTO tickets (party_id, customer_name, customer_mobile, subject, description, priority, assigned_to, repair_id, location_id, created_by)
       VALUES (?,?,?,?,?,?,?,?,?,?)',
      [(int)post('party_id') ?: null, post('customer_name'), post('customer_mobile'), $subject, post('description'),
       post('priority', 'medium'), (int)post('assigned_to') ?: null, (int)post('repair_id') ?: null, $u['location_id'], $u['id']]);
    $tid = insert_id();
    q('UPDATE tickets SET ticket_no = ? WHERE id = ?', [doc_no('TKT', $tid), $tid]);
    log_activity('ticket_add', doc_no('TKT', $tid));
    flash('Ticket ' . doc_no('TKT', $tid) . ' opened.');
    redirect('tickets.php?action=view&id=' . $tid);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'set_status') {
    $tid = (int)post('id');
    $tk = row('SELECT * FROM tickets WHERE id = ?', [$tid]);
    if (!$tk || !($tk['assigned_to'] == $u['id'] || can('tickets.edit'))) { flash('Cannot update this ticket.', 'error'); redirect('tickets.php'); }
    $st = post('status');
    if (in_array($st, ['open', 'in_progress', 'resolved', 'closed'], true)) {
        if (in_array($st, ['resolved', 'closed'], true)) {
            q('UPDATE tickets SET status = ?, resolution = ?, resolved_at = NOW() WHERE id = ?', [$st, post('resolution', $tk['resolution']), $tid]);
        } else {
            q('UPDATE tickets SET status = ? WHERE id = ?', [$st, $tid]);
        }
        log_activity('ticket_status', $tk['ticket_no'] . ' -> ' . $st);
        flash('Ticket marked ' . str_replace('_', ' ', $st) . '.');
    }
    redirect('tickets.php?action=view&id=' . $tid);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'comment') {
    $tid = (int)post('ticket_id');
    $tk = row('SELECT * FROM tickets WHERE id = ?', [$tid]);
    if ($tk && post('comment') && ($tk['assigned_to'] == $u['id'] || $tk['created_by'] == $u['id'] || can('tickets.all'))) {
        q('INSERT INTO ticket_comments (ticket_id, comment, created_by) VALUES (?,?,?)', [$tid, post('comment'), $u['id']]);
        flash('Comment added.');
    }
    redirect('tickets.php?action=view&id=' . $tid);
}

if ($action === 'new') {
    require_perm('tickets.add');
    $parties = all('SELECT id, name, mobile FROM parties WHERE is_active = 1 ORDER BY name');
    $staffList = all('SELECT id, name FROM users WHERE is_active = 1 ORDER BY name');
    $repairId = (int)get('repair_id');
    $page_title = 'New Ticket';
    include __DIR__ . '/includes/header.php';
    ?>
    <div class="card">
      <h2>🎫 New complaint / ticket</h2>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="save">
        <input type="hidden" name="repair_id" value="<?= $repairId ?>">
        <div class="form-row cols-3">
          <div><label>Party (optional)</label>
            <select name="party_id" onchange="var o=this.options[this.selectedIndex];if(this.value){document.getElementById('tk_name').value=o.textContent.trim();document.getElementById('tk_mob').value=o.dataset.m||''}">
              <option value="">-- manual --</option>
              <?php foreach ($parties as $p): ?><option value="<?= $p['id'] ?>" data-m="<?= e($p['mobile']) ?>"><?= e($p['name']) ?></option><?php endforeach; ?>
            </select></div>
          <div><label>Customer name</label><input type="text" id="tk_name" name="customer_name"></div>
          <div><label>Mobile</label><input type="tel" id="tk_mob" name="customer_mobile"></div>
        </div>
        <div class="field"><label>Subject *</label><input type="text" name="subject" required></div>
        <div class="field"><label>Description</label><textarea name="description" rows="3"></textarea></div>
        <div class="form-row cols-2">
          <div><label>Priority</label>
            <select name="priority">
              <?php foreach (['low', 'medium', 'high', 'urgent'] as $p): ?><option value="<?= $p ?>" <?= $p === 'medium' ? 'selected' : '' ?>><?= ucfirst($p) ?></option><?php endforeach; ?>
            </select></div>
          <div><label>Assign to</label>
            <select name="assigned_to">
              <option value="">-- unassigned --</option>
              <?php foreach ($staffList as $s): ?><option value="<?= $s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?>
            </select></div>
        </div>
        <button class="btn" type="submit">Open Ticket</button>
        <a class="btn btn-muted" href="tickets.php">Back</a>
      </form>
    </div>
    <?php
    include __DIR__ . '/includes/footer.php';
    exit;
}

if ($action === 'view') {
    $id = (int)get('id');
    $tk = row('SELECT t.*, p.name party_name, s.name staff_name, r.job_no FROM tickets t
               LEFT JOIN parties p ON p.id = t.party_id LEFT JOIN users s ON s.id = t.assigned_to
               LEFT JOIN repairs r ON r.id = t.repair_id WHERE t.id = ?', [$id]);
    if (!$tk) { flash('Ticket not found.', 'error'); redirect('tickets.php'); }
    $comments = all('SELECT c.*, s.name staff_name FROM ticket_comments c JOIN users s ON s.id = c.created_by WHERE c.ticket_id = ? ORDER BY c.id', [$id]);
    $canAct = $tk['assigned_to'] == $u['id'] || can('tickets.edit');
    $page_title = $tk['ticket_no'];
    include __DIR__ . '/includes/header.php';
    ?>
    <div class="card">
      <h2><?= e($tk['ticket_no']) ?> <?= status_badge($tk['status']) ?> <?= status_badge($tk['priority']) ?></h2>
      <p><strong><?= e($tk['subject']) ?></strong></p>
      <p class="muted"><?= e($tk['party_name'] ?: $tk['customer_name'] ?: 'Walk-in') ?> · <?= e($tk['customer_mobile']) ?> · Assigned: <?= e($tk['staff_name'] ?: 'Unassigned') ?></p>
      <?php if ($tk['job_no']): ?><p class="mt">🔧 Linked repair job: <a href="repairs.php?action=edit&id=<?= $tk['repair_id'] ?>"><?= e($tk['job_no']) ?></a></p><?php endif; ?>
      <?php if ($tk['description']): ?><p class="mt"><?= nl2br(e($tk['description'])) ?></p><?php endif; ?>
      <?php if ($tk['resolution']): ?><div class="flash flash-info mt"><strong>Resolution:</strong> <?= nl2br(e($tk['resolution'])) ?></div><?php endif; ?>
    </div>

    <?php if ($canAct && !in_array($tk['status'], ['resolved', 'closed'], true)): ?>
    <div class="card">
      <h3>Update status</h3>
      <div class="page-actions" style="margin:0 0 10px">
        <?php foreach (['open', 'in_progress'] as $s): if ($s === $tk['status']) continue; ?>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="set_status"><input type="hidden" name="id" value="<?= $tk['id'] ?>"><input type="hidden" name="status" value="<?= $s ?>">
          <button class="btn btn-sm btn-outline" type="submit">Mark <?= ucwords(str_replace('_', ' ', $s)) ?></button></form>
        <?php endforeach; ?>
      </div>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="set_status">
        <input type="hidden" name="id" value="<?= $tk['id'] ?>">
        <div class="field"><label>Resolution notes</label><textarea name="resolution" rows="2"></textarea></div>
        <button class="btn btn-success btn-sm" type="submit" name="status" value="resolved">✅ Resolve Ticket</button>
        <button class="btn btn-outline btn-sm" type="submit" name="status" value="closed">Close (no resolution)</button>
      </form>
    </div>
    <?php endif; ?>

    <div class="card">
      <h3>💬 Notes / conversation</h3>
      <?php foreach ($comments as $c): ?>
      <div class="list-row" style="cursor:default">
        <div class="list-row-main"><?= nl2br(e($c['comment'])) ?><div class="muted list-row-sub"><?= e($c['staff_name']) ?> · <?= dmyt($c['created_at']) ?></div></div>
      </div>
      <?php endforeach; ?>
      <?php if (!$comments): ?><p class="muted">No notes yet.</p><?php endif; ?>
      <form method="post" class="mt">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="comment">
        <input type="hidden" name="ticket_id" value="<?= $tk['id'] ?>">
        <div class="field"><textarea name="comment" rows="2" placeholder="Add a note..." required></textarea></div>
        <button class="btn btn-sm" type="submit">Add Note</button>
      </form>
    </div>
    <?php
    include __DIR__ . '/includes/footer.php';
    exit;
}

// ---------- list ----------
$where = ' 1=1 ';
$params = [];
if (!can('tickets.all')) { $where = ' (t.assigned_to = ? OR t.created_by = ?) '; $params = [$u['id'], $u['id']]; }
$st = get('st');
if ($st) { $where .= ' AND t.status = ? '; $params[] = $st; }
$tickets = all("SELECT t.*, s.name staff_name FROM tickets t LEFT JOIN users s ON s.id = t.assigned_to
                WHERE $where ORDER BY t.id DESC LIMIT 300", $params);
$page_title = 'Complaints / Tickets';
include __DIR__ . '/includes/header.php';
?>
<div class="page-actions">
  <?php if (can('tickets.add')): ?><a class="btn" href="tickets.php?action=new">+ New Ticket</a><?php endif; ?>
  <select onchange="location='tickets.php?st='+this.value" style="max-width:170px">
    <option value="">All statuses</option>
    <?php foreach (['open', 'in_progress', 'resolved', 'closed'] as $s): ?>
    <option value="<?= $s ?>" <?= $st === $s ? 'selected' : '' ?>><?= ucwords(str_replace('_', ' ', $s)) ?></option>
    <?php endforeach; ?>
  </select>
</div>
<div class="table-wrap">
<table>
  <thead><tr><th>No</th><th>Subject</th><th>Customer</th><th>Priority</th><th>Status</th><th>Assigned</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($tickets as $t): ?>
    <tr>
      <td><strong><?= e($t['ticket_no']) ?></strong><br><span class="muted"><?= dmy($t['created_at']) ?></span></td>
      <td><?= e($t['subject']) ?></td>
      <td><?= e($t['customer_name']) ?><br><span class="muted"><?= e($t['customer_mobile']) ?></span></td>
      <td><?= status_badge($t['priority']) ?></td>
      <td><?= status_badge($t['status']) ?></td>
      <td><?= e($t['staff_name'] ?: '-') ?></td>
      <td><a class="btn btn-sm btn-outline" href="tickets.php?action=view&id=<?= $t['id'] ?>">Open</a></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$tickets): ?><tr><td colspan="7" class="muted">No tickets yet.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
