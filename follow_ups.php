<?php
// Follow-up reminders - optionally tied to a lead, repair job, ticket or
// party (ref_type/ref_id, mirroring the feedback table's polymorphic
// pattern), or just a standalone reminder. Surfaced as a due-today/overdue
// list here and on the technician panel (my_jobs.php).
require_once __DIR__ . '/includes/init.php';
require_perm('followups.view');
$u = current_user();
$action = get('action', 'list');
// after done / delete, back to the same filter the person was looking at -
// the form sends it along; only view, date and search are taken from it
$backTo = function () {
    parse_str((string)post('back'), $b);
    $keep = array_filter(['view' => in_array($b['view'] ?? '', ['all', 'due', 'done', 'today'], true) ? $b['view'] : '',
                          'date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($b['date'] ?? '')) ? $b['date'] : '',
                          'q' => mb_substr(trim((string)($b['q'] ?? '')), 0, 100)], fn($v) => $v !== '');
    return 'follow_ups.php' . ($keep ? '?' . http_build_query($keep) : '');
};

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
            flash($st === 'pending' ? 'Follow-up opened again.' : 'Follow-up marked ' . $st . '.');
        }
    }
    redirect($backTo());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'delete') {
    require_perm('followups.delete');
    $undo = undo_keep('follow-up', ['follow_ups' => all('SELECT * FROM follow_ups WHERE id = ?', [(int)post('id')])]);
    q('DELETE FROM follow_ups WHERE id = ?', [(int)post('id')]);
    flash('Follow-up deleted.', 'success', $undo);
    redirect($backTo());
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
// whose follow-ups this person sees: their own, or everyone's with followups.all
$mine = can('followups.all') ? '1=1' : '(f.assigned_to = ' . (int)$u['id'] . ' OR f.created_by = ' . (int)$u['id'] . ')';
$view = in_array(get('view'), ['all', 'due', 'done', 'today'], true) ? get('view') : 'due';
$date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)get('date')) ? get('date') : '';
$q = trim((string)get('q'));
$week = date('Y-m-d', strtotime('monday this week'));
$cnt = row("SELECT
    SUM(f.status = 'pending' AND f.due_date = CURDATE()) today,
    SUM(f.status = 'pending' AND f.due_date <= CURDATE()) due,
    SUM(f.status = 'done' AND f.completed_at >= ?) done_week,
    SUM(f.status = 'done') done,
    SUM(f.status <> 'cancelled') total
  FROM follow_ups f WHERE $mine", [$week]);
$where = [$mine]; $params = [];
if ($view === 'due') $where[] = "f.status = 'pending' AND f.due_date <= CURDATE()";
if ($view === 'today') $where[] = "f.status = 'pending' AND f.due_date = CURDATE()";
if ($view === 'done') $where[] = "f.status = 'done'";
if ($view === 'all') $where[] = "f.status <> 'cancelled'";
if ($date) { $where[] = 'f.due_date = ?'; $params[] = $date; }
if ($q !== '') { $where[] = '(f.title LIKE ? OR f.notes LIKE ? OR p.name LIKE ? OR p.mobile LIKE ?)'; array_push($params, "%$q%", "%$q%", "%$q%", "%$q%"); }
$fups = all("SELECT f.*, p.name party_name, p.mobile party_mobile, p.city party_city, s.name staff_name FROM follow_ups f
             LEFT JOIN parties p ON p.id = f.party_id LEFT JOIN users s ON s.id = f.assigned_to
             WHERE " . implode(' AND ', $where) . " ORDER BY " . ($view === 'done' ? 'f.completed_at DESC' : "(f.status = 'pending') DESC, f.due_date ASC, f.id") . " LIMIT 300", $params);
// the picture for a row, from what it is about
$kind = function ($t) {
    $t = mb_strtolower($t);
    foreach ([['camera|cctv|dvr|nvr', 'camera', 2], ['wifi|wi-fi|internet|router|broadband|jio|airtel|net ', 'wifi', 1], ['printer|cartridge|toner|ink', 'printer', 4],
              ['laptop|computer|monitor|hdmi|desktop|cpu|pc ', 'monitor', 0], ['office|shop|hotel|school|bank', 'building', 5], ['call|phone|mobile', 'phone', 3]] as [$re, $ic, $c])
        if (preg_match('/' . $re . '/u', $t . ' ')) return [$ic, $c];
    return ['users', 3];
};
$refUrls = ['lead' => 'leads.php?action=view&id=', 'ticket' => 'tickets.php?action=view&id=', 'repair' => 'repairs.php?action=edit&id='];
$backField = '<input type="hidden" name="back" value="' . e(http_build_query(array_filter(['view' => $view, 'date' => $date, 'q' => $q], fn($v) => $v !== ''))) . '">';
$link = function ($v) use ($date, $q) { return 'follow_ups.php?view=' . $v . ($date ? '&date=' . $date : '') . ($q !== '' ? '&q=' . urlencode($q) : ''); };
$page_title = 'Follow-ups';
include __DIR__ . '/includes/header.php';
?>
<div class="fu-hero">
  <span class="ic"><?= icon('target', 52) ?></span>
  <div class="tx"><h1>Follow-ups</h1><p>Manage your customer follow-ups, calls, visits and pending tasks</p></div>
  <?php if (can('followups.add')): ?><a class="new" href="follow_ups.php?action=new"><?= icon('plus', 20) ?> New Follow-up</a><?php endif; ?>
</div>

<div class="fu-stats">
  <a class="fu-stat red<?= $view === 'today' ? ' on' : '' ?>" href="follow_ups.php?view=today"><span class="ci"><?= icon('clock', 24) ?></span><span><span class="n"><?= (int)$cnt['today'] ?></span><span class="l" style="display:block">Today</span><span class="s"><?= dmy(today()) ?></span></span></a>
  <a class="fu-stat orange<?= $view === 'due' ? ' on' : '' ?>" href="follow_ups.php?view=due"><span class="ci"><?= icon('hourglass', 24) ?></span><span><span class="n"><?= (int)$cnt['due'] ?></span><span class="l" style="display:block">Pending</span><span class="s">Not done yet</span></span></a>
  <a class="fu-stat green<?= $view === 'done' ? ' on' : '' ?>" href="follow_ups.php?view=done"><span class="ci"><?= icon('check', 26) ?></span><span><span class="n"><?= (int)$cnt['done_week'] ?></span><span class="l" style="display:block">Completed</span><span class="s">This week</span></span></a>
  <a class="fu-stat purple<?= $view === 'all' ? ' on' : '' ?>" href="follow_ups.php?view=all"><span class="ci"><?= icon('calendar', 24) ?></span><span><span class="n"><?= (int)$cnt['total'] ?></span><span class="l" style="display:block">Total</span><span class="s">All follow-ups</span></span></a>
</div>

<div class="fu-bar">
  <a class="fu-pill all<?= $view === 'all' ? ' on' : '' ?>" href="<?= e($link('all')) ?>">All <span class="c"><?= (int)$cnt['total'] ?></span></a>
  <a class="fu-pill due<?= in_array($view, ['due', 'today'], true) ? ' on' : '' ?>" href="<?= e($link('due')) ?>">Due / Pending <span class="c"><?= (int)$cnt['due'] ?></span></a>
  <a class="fu-pill done<?= $view === 'done' ? ' on' : '' ?>" href="<?= e($link('done')) ?>">Completed <span class="c"><?= (int)$cnt['done'] ?></span></a>
  <form class="fu-date"><input type="hidden" name="view" value="<?= e($view) ?>"><?php if ($q !== ''): ?><input type="hidden" name="q" value="<?= e($q) ?>"><?php endif; ?>
    <input type="date" name="date" value="<?= e($date) ?>" onchange="this.form.submit()" aria-label="Due on">
    <?= $date ? '<a href="' . e('follow_ups.php?view=' . $view . ($q !== '' ? '&q=' . urlencode($q) : '')) . '">✕ any day</a>' : '' ?></form>
</div>
<form class="fu-search"><input type="hidden" name="view" value="<?= e($view) ?>"><?php if ($date): ?><input type="hidden" name="date" value="<?= e($date) ?>"><?php endif; ?>
  <div class="box"><?= icon('search', 18) ?><input type="search" name="q" value="<?= e($q) ?>" placeholder="Search follow-ups, party or mobile…" aria-label="Search follow-ups"></div></form>

<div class="fu-list">
  <div class="fu-head"><span>Date</span><span></span><span>Follow-up details</span><span>Contact</span><span>Assigned to</span><span>Status</span><span style="text-align:right">Action</span></div>
  <?php foreach ($fups as $f):
      [$ic, $col] = $kind($f['title'] . ' ' . $f['notes']);
      $pending = $f['status'] === 'pending';
      $over = $pending && $f['due_date'] < today();
      $tag = $f['due_date'] === today() ? ['today', 'Today'] : ($over ? ['over', 'Overdue'] : ($f['due_date'] > today() ? ['soon', date('D', strtotime($f['due_date']))] : ['past', '']));
      $mob = $f['party_mobile'] ?: ''; ?>
  <div class="fu-row<?= $over ? ' overdue' : '' ?>">
    <div class="fu-when"><b><?= dmy($f['due_date']) ?></b><?php if ($tag[1] !== '' && $pending): ?><span class="fu-tag <?= $tag[0] ?>"><?= e($tag[1]) ?></span><?php endif; ?></div>
    <div class="fu-ic c<?= $col ?>"><?= icon($ic, 22) ?></div>
    <div class="fu-what"><div class="t"><?= e($f['title']) ?></div>
      <?php if ($f['notes']): ?><div class="n"><?= e($f['notes']) ?></div><?php endif; ?>
      <div class="r"><span class="who-m"><?= icon('users', 13) ?> <?= e($f['staff_name'] ?: '—') ?></span>
        <?php if ($f['ref_type'] && $f['ref_id'] && isset($refUrls[$f['ref_type']])): ?><a href="<?= e($refUrls[$f['ref_type']] . $f['ref_id']) ?>"><?= icon('link', 13) ?> <?= e(ucfirst($f['ref_type'])) ?></a><?php endif; ?></div></div>
    <div class="fu-con"><?php if ($f['party_name']): ?><a href="parties.php?action=ledger&amp;id=<?= (int)$f['party_id'] ?>"><?= e($f['party_name']) ?></a><?php endif; ?>
      <?php if ($mob): ?><a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $mob)) ?>"><?= icon('phone', 13) ?> <?= e($mob) ?></a><?php endif; ?>
      <?php if ($f['party_city']): ?><span class="m"><?= icon('pin', 13) ?> <?= e($f['party_city']) ?></span><?php endif; ?></div>
    <div class="fu-who"><span class="av"><?= e(hr_initials($f['staff_name'] ?: '?')) ?></span><span><?= e($f['staff_name'] ?: '—') ?></span></div>
    <div class="fu-stcell"><?php if ($pending): ?><span class="fu-st <?= $over ? 'over' : 'pending' ?>"><?= icon('clock', 14) ?> <?= $over ? 'Overdue' : 'Pending' ?></span>
      <?php elseif ($f['status'] === 'done'): ?><span class="fu-st done"><?= icon('check', 14) ?> Done<?= $f['completed_at'] ? ' ' . date('d M', strtotime($f['completed_at'])) : '' ?></span>
      <?php else: ?><span class="fu-st cancelled">Cancelled</span><?php endif; ?></div>
    <div class="fu-act">
      <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="set_status"><input type="hidden" name="id" value="<?= (int)$f['id'] ?>"><?= $backField ?>
        <?php if ($pending): ?><input type="hidden" name="status" value="done"><button class="fu-btn ok" type="submit" title="Mark done" aria-label="Mark done"><?= icon('check', 20) ?></button>
        <?php else: ?><input type="hidden" name="status" value="pending"><button class="fu-btn re" type="submit" title="Open again" aria-label="Open again"><?= icon('undo', 18) ?></button><?php endif; ?></form>
      <?php if (can('followups.delete')): ?>
      <form method="post" onsubmit="return confirm('Delete this follow-up?')"><?= csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= (int)$f['id'] ?>"><?= $backField ?>
        <button class="fu-btn no" type="submit" title="Delete" aria-label="Delete"><?= icon('x', 20) ?></button></form>
      <?php endif; ?></div>
  </div>
  <?php endforeach; ?>
  <?php if (!$fups): ?><div class="fu-empty"><?= $q !== '' || $date ? 'Nothing matches this search.' : ($view === 'done' ? 'Nothing completed yet.' : 'Nothing due — all caught up. 🎉') ?></div><?php endif; ?>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
