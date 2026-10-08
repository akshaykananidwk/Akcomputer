<?php
// 👥 Team: shift, salary and commission per person; how each one is doing;
// their papers; what they have learnt; and when they came and went.
require_once __DIR__ . '/includes/init.php';
require_perm('hr.view');
$u = current_user();
$tab = get('tab', 'shifts');
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)get('from')) ? get('from') : date('Y-m-01');
$to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)get('to')) ? get('to') : today();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_perm('hr.edit');
    $do = post('do');
    if ($do === 'shifts') {
        foreach ((array)post('id', []) as $i => $id) {
            $tm = fn($v) => preg_match('/^\d{2}:\d{2}$/', (string)$v) ? "$v:00" : null;
            q('UPDATE users SET salary_monthly = ?, commission_pct = ?, shift_start = ?, shift_end = ?, weekly_off = ?, joined_on = ? WHERE id = ?',
              [max(0, (float)(post('salary')[$i] ?? 0)), max(0, min(50, (float)(post('comm')[$i] ?? 0))), $tm(post('ss')[$i] ?? ''), $tm(post('se')[$i] ?? ''),
               (post('off')[$i] ?? '') === '' ? null : (int)post('off')[$i], preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)(post('joined')[$i] ?? '')) ? post('joined')[$i] : null, (int)$id]);
        }
        log_activity('team_shifts', '');
        flash('Saved.');
    }
    if ($do === 'doc' && !empty($_FILES['file']['tmp_name']) && $_FILES['file']['error'] === UPLOAD_ERR_OK && $_FILES['file']['size'] <= 8 * 1024 * 1024) {
        $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'pdf'], true)) {
            $dir = up_dir('staff_docs');
            if (!is_file("$dir/.htaccess")) @file_put_contents("$dir/.htaccess", "Require all denied\n");   // Aadhaar etc. are never public
            $name = 'doc_' . (int)post('user_id') . '_' . bin2hex(random_bytes(6)) . ".$ext";
            if (move_uploaded_file($_FILES['file']['tmp_name'], "$dir/$name"))
                q('INSERT INTO staff_docs (user_id, kind, file, created_by) VALUES (?,?,?,?)', [(int)post('user_id'), mb_substr(post('kind', 'Document'), 0, 40), $name, $u['id']]);
            log_activity('staff_doc_add', '#' . (int)post('user_id') . ' ' . post('kind'));
            flash('Document kept.');
        }
    }
    if ($do === 'doc_del') {
        $d = row('SELECT * FROM staff_docs WHERE id = ?', [(int)post('id')]);
        if ($d) { @unlink(up_dir('staff_docs') . '/' . basename($d['file'])); q('DELETE FROM staff_docs WHERE id = ?', [$d['id']]); log_activity('staff_doc_del', '#' . $d['id']); }
    }
    if ($do === 'tasks') set_setting('training_tasks', mb_substr((string)post('training_tasks'), 0, 3000));
    if ($do === 'train') {
        $uid = (int)post('user_id'); $task = mb_substr((string)post('task'), 0, 150);
        if (post('done')) q('INSERT INTO staff_training (user_id, task, done_at, done_by) VALUES (?,?,NOW(),?) ON DUPLICATE KEY UPDATE done_at = NOW(), done_by = VALUES(done_by)', [$uid, $task, $u['id']]);
        else q('DELETE FROM staff_training WHERE user_id = ? AND task = ?', [$uid, $task]);
    }
    redirect('team.php?tab=' . urlencode($tab));
}
if ((int)get('doc')) {
    $d = row('SELECT * FROM staff_docs WHERE id = ?', [(int)get('doc')]);
    if (!$d) { http_response_code(404); exit; }
    $ext = strtolower(pathinfo($d['file'], PATHINFO_EXTENSION));
    header('Content-Type: ' . ($ext === 'pdf' ? 'application/pdf' : 'image/' . ($ext === 'jpg' ? 'jpeg' : $ext)));
    header('Cache-Control: private, no-store'); header('X-Content-Type-Options: nosniff');
    readfile(up_dir('staff_docs') . '/' . basename($d['file'])); exit;
}

$staff = all('SELECT * FROM users WHERE is_active = 1 ORDER BY name');
$page_title = 'Team';
include __DIR__ . '/includes/header.php';
$tabs = ['shifts' => '🕒 Shifts & salary', 'perf' => '📈 Performance & commission', 'docs' => '📁 Documents', 'training' => '🎓 Training', 'logins' => '⏱️ Came & went'];
$days = ['' => '—', 0 => 'Sun', 1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat'];
?>
<div class="page-head"><h1>👥 Team</h1><div style="display:flex;gap:6px"><a class="btn btn-sm btn-outline" href="attendance.php">🕘 Attendance</a><a class="btn btn-sm btn-outline" href="leaves.php">🏖️ Leave</a></div></div>
<div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px"><?php foreach ($tabs as $k => $l): ?><a class="btn btn-sm <?= $tab === $k ? '' : 'btn-outline' ?>" href="team.php?tab=<?= $k ?>"><?= $l ?></a><?php endforeach; ?></div>

<?php if ($tab === 'shifts'): ?>
<form method="post" class="pane"><?= csrf_field() ?><input type="hidden" name="do" value="shifts">
<div class="pane-body tight"><table class="rowlist"><thead><tr><th>Staff</th><th>Salary ₹/month</th><th>Commission %</th><th>Shift</th><th>Weekly off</th><th>Joined</th></tr></thead><tbody>
<?php foreach ($staff as $s): ?>
  <tr><td data-l="Staff"><b><?= e($s['name']) ?></b><input type="hidden" name="id[]" value="<?= (int)$s['id'] ?>"></td>
    <td data-l="Salary"><input type="number" name="salary[]" min="0" step="1" value="<?= +$s['salary_monthly'] ?>" style="width:100px"></td>
    <td data-l="Commission"><input type="number" name="comm[]" min="0" max="50" step="0.1" value="<?= +$s['commission_pct'] ?>" style="width:70px"></td>
    <td data-l="Shift"><input type="time" name="ss[]" value="<?= e(substr((string)$s['shift_start'], 0, 5)) ?>"> – <input type="time" name="se[]" value="<?= e(substr((string)$s['shift_end'], 0, 5)) ?>"></td>
    <td data-l="Weekly off"><select name="off[]"><?php foreach ($days as $k => $l): ?><option value="<?= $k ?>" <?= (string)$s['weekly_off'] === (string)$k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></td>
    <td data-l="Joined"><input type="date" name="joined[]" value="<?= e((string)$s['joined_on']) ?>"></td></tr>
<?php endforeach; ?></tbody></table></div>
<?php if (can('hr.edit')): ?><div style="padding:10px"><button class="btn" type="submit">💾 Save</button></div><?php endif; ?></form>

<?php elseif ($tab === 'perf'):
  $rows = [];
  foreach ($staff as $s) {
      $sale = row('SELECT COUNT(*) n, COALESCE(SUM(total - tax_amount), 0) amt FROM sales WHERE created_by = ? AND is_cancelled = 0 AND sale_date BETWEEN ? AND ?', [$s['id'], $from, $to]);
      $rep = (int)val("SELECT COUNT(*) FROM repairs WHERE assigned_to = ? AND delivered_date BETWEEN ? AND ?", [$s['id'], $from, $to]);
      $rate = row("SELECT AVG(f.rating) r, COUNT(*) n FROM feedback f JOIN repairs rp ON f.ref_type = 'repair' AND rp.id = f.ref_id WHERE rp.assigned_to = ? AND f.rating > 0 AND f.submitted_at BETWEEN ? AND ?", [$s['id'], "$from 00:00:00", "$to 23:59:59"]);
      $rows[] = [$s, $sale, $rep, $rate, round($sale['amt'] * $s['commission_pct'] / 100, 2)];
  }
  usort($rows, fn($a, $b) => $b[1]['amt'] <=> $a[1]['amt']); ?>
<form class="card" style="display:flex;gap:8px;align-items:end;flex-wrap:wrap"><input type="hidden" name="tab" value="perf">
  <div class="field"><label>From</label><input type="date" name="from" value="<?= e($from) ?>"></div><div class="field"><label>To</label><input type="date" name="to" value="<?= e($to) ?>"></div>
  <button class="btn btn-outline" type="submit">Show</button></form>
<div class="pane"><div class="pane-body tight"><table class="rowlist"><thead><tr><th>Staff</th><th class="num">Bills</th><th class="num">Sales (before GST)</th><th class="num">Repairs done</th><th class="num">Customer rating</th><th class="num">Commission</th></tr></thead><tbody>
<?php foreach ($rows as [$s, $sale, $rep, $rate, $comm]): ?>
  <tr><td data-l="Staff"><b><?= e($s['name']) ?></b></td><td class="num" data-l="Bills"><?= (int)$sale['n'] ?></td><td class="num" data-l="Sales">₹<?= money($sale['amt']) ?></td>
    <td class="num" data-l="Repairs"><?= $rep ?></td><td class="num" data-l="Rating"><?= $rate['n'] ? '⭐ ' . round($rate['r'], 1) . ' <small class="muted">(' . (int)$rate['n'] . ')</small>' : '—' ?></td>
    <td class="num" data-l="Commission"><?= $s['commission_pct'] > 0 ? '₹' . money($comm) . ' <small class="muted">' . +$s['commission_pct'] . '%</small>' : '—' ?></td></tr>
<?php endforeach; ?></tbody></table></div></div>

<?php elseif ($tab === 'docs'): $docs = all('SELECT d.*, us.name FROM staff_docs d JOIN users us ON us.id = d.user_id ORDER BY us.name, d.id DESC'); ?>
<?php if (can('hr.edit')): ?>
<form method="post" enctype="multipart/form-data" class="card" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end"><?= csrf_field() ?><input type="hidden" name="do" value="doc">
  <div class="field"><label>Staff</label><select name="user_id"><?php foreach ($staff as $s): ?><option value="<?= (int)$s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
  <div class="field"><label>What</label><select name="kind"><option>Aadhaar</option><option>PAN</option><option>Photo</option><option>Bank passbook</option><option>Address proof</option><option>Agreement</option><option>Document</option></select></div>
  <div class="field"><label>File</label><input type="file" name="file" accept="image/*,application/pdf" required></div>
  <button class="btn" type="submit">Keep</button></form>
<?php endif; ?>
<div class="pane"><div class="pane-body tight"><table class="rowlist"><tbody>
<?php foreach ($docs as $d): ?><tr><td data-l="Staff"><b><?= e($d['name']) ?></b></td><td data-l="What"><?= e($d['kind']) ?></td><td data-l="When" class="muted"><?= dmy($d['created_at']) ?></td>
  <td class="act"><a class="btn btn-sm btn-outline" target="_blank" href="team.php?doc=<?= (int)$d['id'] ?>">Open</a>
  <?php if (can('hr.edit')): ?><form method="post" style="display:inline" onsubmit="return confirm('Remove this document?')"><?= csrf_field() ?><input type="hidden" name="do" value="doc_del"><input type="hidden" name="id" value="<?= (int)$d['id'] ?>"><button class="btn btn-sm btn-danger">✕</button></form><?php endif; ?></td></tr>
<?php endforeach; if (!$docs): ?><tr><td class="muted">No documents kept yet.</td></tr><?php endif; ?></tbody></table></div></div>

<?php elseif ($tab === 'training'):
  $tasks = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', setting('training_tasks', "Make a cash bill\nMake a credit (udhar) bill\nTake a payment\nAdd a new item\nPark and reopen a bill\nCancel a wrong bill\nWrite an expense\nClose the day (cash count)")))));
  $done = [];
  foreach (all('SELECT user_id, task FROM staff_training WHERE done_at IS NOT NULL') as $t) $done[$t['user_id']][$t['task']] = 1; ?>
<div class="pane"><div class="pane-body tight" style="overflow-x:auto"><table class="rowlist"><thead><tr><th>Task</th><?php foreach ($staff as $s): ?><th><?= e($s['name']) ?></th><?php endforeach; ?></tr></thead><tbody>
<?php foreach ($tasks as $t): ?><tr><td><?= e($t) ?></td><?php foreach ($staff as $s): $ok = !empty($done[$s['id']][$t]); ?>
  <td style="text-align:center"><form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="train"><input type="hidden" name="user_id" value="<?= (int)$s['id'] ?>"><input type="hidden" name="task" value="<?= e($t) ?>">
    <input type="checkbox" name="done" value="1" <?= $ok ? 'checked' : '' ?> <?= can('hr.edit') ? 'onchange="this.form.submit()"' : 'disabled' ?> aria-label="<?= e($s['name'] . ': ' . $t) ?>"></form></td>
<?php endforeach; ?></tr><?php endforeach; ?></tbody></table></div></div>
<?php if (can('hr.edit')): ?><form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="do" value="tasks"><label>What a new person must learn — one per line</label>
  <textarea name="training_tasks" rows="6"><?= e(implode("\n", $tasks)) ?></textarea><button class="btn btn-sm" type="submit">Save list</button></form><?php endif; ?>

<?php else:
  $rows = all("SELECT us.name, DATE(s.created_at) d, MIN(s.created_at) first_in, MAX(COALESCE(s.last_seen_at, s.created_at)) last_seen, COUNT(*) n
               FROM user_sessions s JOIN users us ON us.id = s.user_id WHERE s.created_at BETWEEN ? AND ? GROUP BY us.name, DATE(s.created_at) ORDER BY d DESC, us.name LIMIT 300", ["$from 00:00:00", "$to 23:59:59"]); ?>
<form class="card" style="display:flex;gap:8px;align-items:end;flex-wrap:wrap"><input type="hidden" name="tab" value="logins">
  <div class="field"><label>From</label><input type="date" name="from" value="<?= e($from) ?>"></div><div class="field"><label>To</label><input type="date" name="to" value="<?= e($to) ?>"></div>
  <button class="btn btn-outline" type="submit">Show</button></form>
<div class="pane"><div class="pane-body tight"><table class="rowlist"><thead><tr><th>Date</th><th>Staff</th><th>First login</th><th>Last seen</th><th class="num">Logins</th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr><td data-l="Date"><?= dmy($r['d']) ?></td><td data-l="Staff"><b><?= e($r['name']) ?></b></td><td data-l="First"><?= date('h:i A', strtotime($r['first_in'])) ?></td><td data-l="Last"><?= date('h:i A', strtotime($r['last_seen'])) ?></td><td class="num" data-l="Logins"><?= (int)$r['n'] ?></td></tr>
<?php endforeach; if (!$rows): ?><tr><td class="muted">No logins in these dates.</td></tr><?php endif; ?></tbody></table></div></div>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
