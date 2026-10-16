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
            $pr = trim((string)(post('petrol')[$i] ?? ''));
            q('UPDATE users SET salary_monthly = ?, commission_pct = ?, shift_start = ?, shift_end = ?, weekly_off = ?, joined_on = ?,
                 pay_type = ?, hourly_rate = ?, raise_amount = ?, raise_from = ?, raise_max = ?, bike_allowed = ?, petrol_rate = ? WHERE id = ?',
              [max(0, (float)(post('salary')[$i] ?? 0)), max(0, min(50, (float)(post('comm')[$i] ?? 0))), $tm(post('ss')[$i] ?? ''), $tm(post('se')[$i] ?? ''),
               (post('off')[$i] ?? '') === '' ? null : (int)post('off')[$i], preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)(post('joined')[$i] ?? '')) ? post('joined')[$i] : null,
               (post('ptype')[$i] ?? '') === 'hourly' ? 'hourly' : 'monthly', max(0, (float)(post('hrate')[$i] ?? 0)), max(0, (float)(post('raise')[$i] ?? 0)),
               preg_match('/^\d{4}-\d{2}$/', (string)(post('rfrom')[$i] ?? '')) ? post('rfrom')[$i] : null, max(0, (float)(post('rmax')[$i] ?? 0)),
               in_array((string)$id, (array)post('bike', []), true) ? 1 : 0, $pr === '' ? null : max(0, (float)$pr), (int)$id]);
        }
        log_activity('team_shifts', '');
        flash('Saved.');
    }
    if ($do === 'rules') {
        // switching the penalties on starts them from today; they never reach back
        $on = post('rules_on') === '1';
        set_setting('hr_rules_from', $on ? (setting('hr_rules_from', '') ?: today()) : '');
        foreach (['hr_min_hours' => [1, 24], 'hr_late_after' => [0, 600], 'hr_late_hours' => [0, 48], 'hr_absent_hours' => [0, 72],
                  'hr_noout_hours' => [0, 24], 'hr_leave_notice' => [0, 30], 'hr_petrol_rate' => [0, 100]] as $k => [$lo, $hi])
            set_setting($k, (string)max($lo, min($hi, (float)post($k))));
        log_activity('hr_rules', $on ? 'on' : 'off');
        flash('Attendance rules saved.' . ($on ? ' Penalties count from ' . dmy(setting('hr_rules_from')) . '.' : ' Penalties are off.'));
        redirect('team.php?tab=rules');
    }
    if ($do === 'penalty' && post('user_id')) {
        // a penalty by hand, or letting one day's automatic penalty go
        $d = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)post('date')) ? post('date') : today();
        $kind = post('kind') === 'waive' ? 'waive' : 'manual';
        q('INSERT INTO hr_penalties (user_id, pen_date, kind, hours, reason, created_by) VALUES (?,?,?,?,?,?)',
          [(int)post('user_id'), $d, $kind, $kind === 'waive' ? 0 : max(0, min(100, (float)post('hours'))), mb_substr(trim((string)post('reason')), 0, 200), $u['id']]);
        log_activity('hr_penalty_' . $kind, '#' . (int)post('user_id') . " $d " . post('hours'));
        flash($kind === 'waive' ? 'The automatic penalty for ' . dmy($d) . ' is let go.' : 'Penalty of ' . +(float)post('hours') . ' hours added for ' . dmy($d) . '.');
        redirect('attendance.php?u=' . (int)post('user_id') . '&m=' . substr($d, 0, 7));
    }
    if ($do === 'penalty_del') {
        $p = row('SELECT * FROM hr_penalties WHERE id = ?', [(int)post('id')]);
        if ($p) { q('DELETE FROM hr_penalties WHERE id = ?', [$p['id']]); log_activity('hr_penalty_del', '#' . $p['id']); }
        redirect('attendance.php?u=' . (int)($p['user_id'] ?? 0) . '&m=' . substr((string)($p['pen_date'] ?? today()), 0, 7));
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
$tabs = ['shifts' => '🕒 Shifts & salary', 'rules' => '⚖️ Attendance rules', 'perf' => '📈 Performance & commission', 'docs' => '📁 Documents', 'training' => '🎓 Training', 'logins' => '⏱️ Came & went'];
$days = ['' => '—', 0 => 'Sun', 1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat'];
?>
<?= hr_tabs($tab === 'shifts' ? 'team' : ($tab === 'rules' ? 'rules' : 'more')) ?>
<?php if (!in_array($tab, ['shifts', 'rules'], true)): ?>
<nav class="hr-tabs" aria-label="More" style="padding-top:0"><?php foreach (['perf' => '📈 Performance', 'docs' => '📁 Documents', 'training' => '🎓 Training', 'logins' => '⏱️ Came & went'] as $k => $l): ?>
  <a class="hr-tab<?= $tab === $k ? ' on' : '' ?>" style="flex-direction:row;min-width:0;padding:8px 12px" href="team.php?tab=<?= $k ?>"><?= $l ?></a><?php endforeach; ?></nav>
<?php endif; ?>

<?php if ($tab === 'shifts'): $thisMonth = date('Y-m'); ?>
<form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="shifts">
<?php foreach ($staff as $s): $hourly = $s['pay_type'] === 'hourly'; ?>
  <div class="hr-staff"><input type="hidden" name="id[]" value="<?= (int)$s['id'] ?>">
    <div class="head"><div class="hr-av"><?= e(hr_initials($s['name'])) ?></div>
      <div style="min-width:0"><div class="nm"><?= e($s['name']) ?></div><div class="rt">This month ₹<?= money(hr_rate($s, $thisMonth)) ?><?= $hourly ? ' per hour' : ' per month' ?></div></div></div>
    <fieldset><legend>Pay</legend>
      <div><label>Paid</label><select name="ptype[]"><option value="monthly" <?= $hourly ? '' : 'selected' ?>>Per month</option><option value="hourly" <?= $hourly ? 'selected' : '' ?>>Per hour</option></select></div>
      <div><label>₹ per month</label><input type="number" name="salary[]" min="0" step="1" inputmode="decimal" value="<?= +$s['salary_monthly'] ?>"></div>
      <div><label>₹ per hour</label><input type="number" name="hrate[]" min="0" step="0.5" inputmode="decimal" value="<?= +$s['hourly_rate'] ?>"></div>
      <div><label>Commission %</label><input type="number" name="comm[]" min="0" max="50" step="0.1" inputmode="decimal" value="<?= +$s['commission_pct'] ?>"></div></fieldset>
    <fieldset><legend>Raise every month</legend>
      <div><label>Add ₹</label><input type="number" name="raise[]" min="0" step="0.5" inputmode="decimal" value="<?= +$s['raise_amount'] ?>"></div>
      <div><label>Starting month</label><input type="month" name="rfrom[]" value="<?= e((string)$s['raise_from']) ?>"></div>
      <div><label>Stop at ₹ (0 = no limit)</label><input type="number" name="rmax[]" min="0" step="1" inputmode="decimal" value="<?= +$s['raise_max'] ?>"></div></fieldset>
    <fieldset><legend>Shift</legend>
      <div><label>Starts</label><input type="time" name="ss[]" value="<?= e(substr((string)$s['shift_start'], 0, 5)) ?>"></div>
      <div><label>Ends</label><input type="time" name="se[]" value="<?= e(substr((string)$s['shift_end'], 0, 5)) ?>"></div>
      <div><label>Weekly off</label><select name="off[]"><?php foreach ($days as $k => $l): ?><option value="<?= $k ?>" <?= (string)$s['weekly_off'] === (string)$k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
      <div><label>Joined</label><input type="date" name="joined[]" value="<?= e((string)$s['joined_on']) ?>"></div></fieldset>
    <fieldset><legend>Bike</legend>
      <div style="display:flex;align-items:end"><label class="hr-switch" style="color:var(--text);font-size:14px;margin:0 0 8px"><input type="checkbox" name="bike[]" value="<?= (int)$s['id'] ?>" <?= $s['bike_allowed'] ? 'checked' : '' ?>> Uses a bike</label></div>
      <div><label>₹ per km (empty = shop's ₹<?= +hr_rules()['petrol_rate'] ?>)</label><input type="number" name="petrol[]" min="0" step="0.1" inputmode="decimal" value="<?= $s['petrol_rate'] === null ? '' : +$s['petrol_rate'] ?>"></div></fieldset>
  </div>
<?php endforeach; ?>
<p class="muted" style="font-size:12.5px">Raise every month: ₹32 per hour with "Add ₹1" from <?= e(date('M Y')) ?> becomes ₹33 next month and ₹34 the month after. An older month always keeps its own rate.</p>
<?php if (can('hr.edit')): ?><div class="hr-sticky-save"><button class="btn" type="submit" style="min-height:46px;padding:0 26px">💾 Save all</button></div><?php endif; ?></form>

<?php elseif ($tab === 'rules'): $r = hr_rules(); ?>
<form method="post" class="hr-card" style="display:grid;gap:14px"><?= csrf_field() ?><input type="hidden" name="do" value="rules">
  <h3 style="margin:0"><span><?= icon('sliders', 18) ?> Attendance rules</span><?= $r['from'] !== '' ? '<span class="hr-chip ok">ON since ' . dmy($r['from']) . '</span>' : '<span class="hr-chip">OFF</span>' ?></h3>
  <label class="hr-switch"><input type="checkbox" name="rules_on" value="1" <?= $r['from'] !== '' ? 'checked' : '' ?>> Penalties and leave rules are on</label>
  <div class="muted" style="font-size:12.5px;margin-top:-8px">They count from the day you switch them on — never for earlier days.</div>
  <div class="hr-form" style="grid-template-columns:repeat(auto-fit,minmax(150px,1fr))">
    <div><label>Full day (hours)</label><input type="number" name="hr_min_hours" step="0.5" inputmode="decimal" value="<?= +$r['min_hours'] ?>"></div>
    <div><label>Late after (minutes)</label><input type="number" name="hr_late_after" step="5" inputmode="numeric" value="<?= +$r['late_after'] ?>"></div>
    <div><label>Late penalty (hours)</label><input type="number" name="hr_late_hours" step="0.5" inputmode="decimal" value="<?= +$r['late_hours'] ?>"></div>
    <div><label>Absent penalty (hours)</label><input type="number" name="hr_absent_hours" step="0.5" inputmode="decimal" value="<?= +$r['absent_hours'] ?>"></div>
    <div><label>No check-out (hours)</label><input type="number" name="hr_noout_hours" step="0.5" inputmode="decimal" value="<?= +$r['noout_hours'] ?>"></div>
    <div><label>Leave notice (days)</label><input type="number" name="hr_leave_notice" step="1" inputmode="numeric" value="<?= +$r['notice_days'] ?>"></div>
    <div><label>Petrol ₹ per km</label><input type="number" name="hr_petrol_rate" step="0.1" inputmode="decimal" value="<?= +$r['petrol_rate'] ?>"></div>
  </div>
  <div class="hr-list" style="background:var(--card-alt);border-radius:14px;padding:4px 12px">
    <div class="hr-row" style="grid-template-columns:28px minmax(0,1fr)"><span><?= icon('clock', 20) ?></span><div class="hr-main"><div class="s">Shift 9:00 → not in by <?= date('H:i', strtotime('09:00 +' . (int)$r['late_after'] . ' minutes')) ?> = <b><?= +$r['late_hours'] ?> h</b> penalty</div></div></div>
    <div class="hr-row" style="grid-template-columns:28px minmax(0,1fr)"><span><?= icon('alert', 20) ?></span><div class="hr-main"><div class="s">A day away without leave or a doctor's certificate = <b><?= +$r['absent_hours'] ?> h</b></div></div></div>
    <div class="hr-row" style="grid-template-columns:28px minmax(0,1fr)"><span><?= icon('rupee', 20) ?></span><div class="hr-main"><div class="s">A penalty hour costs the hourly rate (monthly salary ÷ working days ÷ <?= +$r['min_hours'] ?>)</div></div></div>
    <div class="hr-row" style="grid-template-columns:28px minmax(0,1fr)"><span><?= icon('calendar', 20) ?></span><div class="hr-main"><div class="s">Weekly offs and approved leave carry no penalty; any day can be let go</div></div></div>
  </div>
  <button class="btn btn-block" type="submit" style="min-height:48px">💾 Save rules</button>
</form>

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
