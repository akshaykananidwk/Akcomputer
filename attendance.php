<?php
// 🕘 Attendance: each person checks in and out from their phone with a
// selfie and where they are. The owner sees everyone, can mark a day
// present / half / absent / leave, and the month feeds payroll.
require_once __DIR__ . '/includes/init.php';
require_login();
$u = current_user();
$seeAll = can('hr.view');
$month = preg_match('/^\d{4}-\d{2}$/', (string)get('m')) ? get('m') : date('Y-m');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(post('do'), ['in', 'out'], true)) {
    if (!api_rate_ok('att:' . $u['id'], 10, 3600)) { flash('Too many tries — please wait a little.', 'error'); redirect('attendance.php'); }
    $photo = null;
    if (!empty($_FILES['selfie']['tmp_name']) && $_FILES['selfie']['error'] === UPLOAD_ERR_OK && $_FILES['selfie']['size'] <= 6 * 1024 * 1024
        && @getimagesize($_FILES['selfie']['tmp_name'])) {
        $dir = up_dir('attendance');
        if (!is_file("$dir/.htaccess")) @file_put_contents("$dir/.htaccess", "Require all denied\n");   // faces are private: shown through this page only
        $photo = 'att_' . $u['id'] . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.jpg';
        if (!move_uploaded_file($_FILES['selfie']['tmp_name'], "$dir/$photo")) $photo = null;
    }
    if (setting('att_need_selfie', '1') === '1' && !$photo) { flash('Please take a selfie to check ' . post('do') . '.', 'error'); redirect('attendance.php'); }
    $lat = is_numeric(post('lat')) ? (float)post('lat') : null; $lng = is_numeric(post('lng')) ? (float)post('lng') : null;
    if (post('do') === 'in') {
        q("INSERT INTO attendance (user_id, att_date, in_at, in_lat, in_lng, in_photo) VALUES (?,?,NOW(),?,?,?)
           ON DUPLICATE KEY UPDATE in_at = COALESCE(in_at, NOW()), in_lat = COALESCE(in_lat, VALUES(in_lat)), in_lng = COALESCE(in_lng, VALUES(in_lng)), in_photo = COALESCE(in_photo, VALUES(in_photo))",
          [$u['id'], today(), $lat, $lng, $photo]);
        flash('Checked in at ' . date('h:i A') . '. Have a good day!');
    } else {
        q('UPDATE attendance SET out_at = NOW(), out_lat = ?, out_lng = ?, out_photo = ? WHERE user_id = ? AND att_date = ?', [$lat, $lng, $photo, $u['id'], today()]);
        flash('Checked out at ' . date('h:i A') . '.');
    }
    redirect('attendance.php');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'mark') {
    require_perm('hr.edit');
    $st = in_array(post('status'), ['present', 'half', 'absent', 'leave'], true) ? post('status') : 'present';
    $d = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)post('date')) ? post('date') : today();
    q('INSERT INTO attendance (user_id, att_date, status, note) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE status = VALUES(status), note = VALUES(note)',
      [(int)post('user_id'), $d, $st, mb_substr((string)post('note'), 0, 200)]);
    log_activity('attendance_mark', '#' . (int)post('user_id') . " $d $st");
    redirect('attendance.php?m=' . substr($d, 0, 7) . '&u=' . (int)post('user_id'));
}
// a selfie is shown only to its owner and to whoever may see everyone
if (get('photo')) {
    $a = row('SELECT user_id, in_photo, out_photo FROM attendance WHERE id = ?', [(int)get('photo')]);
    $f = $a ? (get('w') === 'out' ? $a['out_photo'] : $a['in_photo']) : null;
    if (!$f || ((int)$a['user_id'] !== (int)$u['id'] && !$seeAll)) { http_response_code(404); exit; }
    header('Content-Type: image/jpeg'); header('Cache-Control: private, no-store');
    readfile(up_dir('attendance') . '/' . basename($f)); exit;
}

$who = $seeAll && (int)get('u') ? row('SELECT * FROM users WHERE id = ?', [(int)get('u')]) : $u;
$today = hr_today($u);
$page_title = 'Attendance';
include __DIR__ . '/includes/header.php';
$colors = ['present' => '#16a34a', 'half' => '#f59e0b', 'absent' => '#dc2626', 'leave' => '#2563eb', 'unpaid' => '#9ca3af', 'off' => '#64748b', 'future' => 'transparent'];
?>
<div class="page-head"><h1>🕘 Attendance</h1>
  <div style="display:flex;gap:6px"><a class="btn btn-sm btn-outline" href="leaves.php">🏖️ Leave</a><?php if (can('hr.payroll')): ?><a class="btn btn-sm btn-outline" href="payroll.php">💵 Payroll</a><?php endif; ?></div></div>

<div class="card">
  <?php $r = $today['row']; ?>
  <p style="margin-top:0"><b><?= e($u['name']) ?></b> — <?= dmy(today()) ?>
    <?= $r && $r['in_at'] ? ' · in ' . date('h:i A', strtotime($r['in_at'])) . ($today['late'] ? ' <span class="badge badge-warn">late</span>' : '') : '' ?>
    <?= $r && $r['out_at'] ? ' · out ' . date('h:i A', strtotime($r['out_at'])) : '' ?></p>
  <?php if (!$r || !$r['in_at'] || !$r['out_at']): $mode = !$r || !$r['in_at'] ? 'in' : 'out'; ?>
  <form method="post" enctype="multipart/form-data" id="attForm"><?= csrf_field() ?>
    <input type="hidden" name="do" value="<?= $mode ?>"><input type="hidden" name="lat" id="attLat"><input type="hidden" name="lng" id="attLng">
    <label class="btn btn-block" style="font-size:18px;padding:16px;cursor:pointer">📸 <?= $mode === 'in' ? 'Check in' : 'Check out' ?> — take a selfie
      <input type="file" name="selfie" accept="image/*" capture="user" style="display:none" onchange="this.form.submit()"></label>
    <small class="muted" id="attWhere">Finding where you are…</small>
  </form>
  <script>
    if (navigator.geolocation) navigator.geolocation.getCurrentPosition(function (p) {
      document.getElementById('attLat').value = p.coords.latitude; document.getElementById('attLng').value = p.coords.longitude;
      document.getElementById('attWhere').textContent = '📍 Location found (±' + Math.round(p.coords.accuracy) + ' m)';
    }, function () { document.getElementById('attWhere').textContent = '📍 Location not shared — it will be saved without it.'; }, { enableHighAccuracy: true, timeout: 8000 });
  </script>
  <?php else: ?><p class="muted">Done for today ✔</p><?php endif; ?>
</div>

<?php if ($seeAll): $team = all('SELECT u.*, a.id aid, a.in_at, a.out_at, a.in_lat, a.in_lng, a.in_photo FROM users u LEFT JOIN attendance a ON a.user_id = u.id AND a.att_date = ? WHERE u.is_active = 1 ORDER BY u.name', [today()]); ?>
<div class="pane"><div class="pane-head"><h3>Today — <?= count(array_filter($team, fn($t) => $t['in_at'])) ?>/<?= count($team) ?> in</h3></div><div class="pane-body tight">
<table class="rowlist"><thead><tr><th>Staff</th><th>In</th><th>Out</th><th>Where</th><th></th></tr></thead><tbody>
<?php foreach ($team as $t): $late = $t['in_at'] && $t['shift_start'] && strtotime($t['in_at']) > strtotime(today() . ' ' . $t['shift_start']) + 600; ?>
  <tr><td data-l="Staff"><a href="attendance.php?u=<?= (int)$t['id'] ?>&amp;m=<?= e($month) ?>"><b><?= e($t['name']) ?></b></a></td>
    <td data-l="In"><?= $t['in_at'] ? date('h:i A', strtotime($t['in_at'])) . ($late ? ' <span class="badge badge-warn">late</span>' : '') : '<span class="muted">—</span>' ?></td>
    <td data-l="Out"><?= $t['out_at'] ? date('h:i A', strtotime($t['out_at'])) : '<span class="muted">—</span>' ?></td>
    <td data-l="Where"><?= $t['in_lat'] ? '<a target="_blank" rel="noopener" href="https://maps.google.com/?q=' . (float)$t['in_lat'] . ',' . (float)$t['in_lng'] . '">📍 map</a>' : '' ?></td>
    <td class="act"><?= $t['in_photo'] ? '<a href="attendance.php?photo=' . (int)$t['aid'] . '" target="_blank">🖼️ selfie</a>' : '' ?></td></tr>
<?php endforeach; ?></tbody></table></div></div>
<?php endif; ?>

<?php $days = hr_month_days($who, $month); $cnt = array_count_values($days); ?>
<div class="card">
  <form style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:8px">
    <b><?= e($who['name']) ?></b><?php if ($who['id'] != $u['id']): ?><input type="hidden" name="u" value="<?= (int)$who['id'] ?>"><?php endif; ?>
    <input type="month" name="m" value="<?= e($month) ?>" onchange="this.form.submit()">
    <span class="muted">Present <?= $cnt['present'] ?? 0 ?> · Half <?= $cnt['half'] ?? 0 ?> · Leave <?= $cnt['leave'] ?? 0 ?> · Off <?= $cnt['off'] ?? 0 ?> · Absent <?= $cnt['absent'] ?? 0 ?></span>
  </form>
  <div style="display:grid;grid-template-columns:repeat(7,1fr);gap:4px;max-width:420px">
    <?php for ($i = 0; $i < (int)date('w', strtotime("$month-01")); $i++): ?><div></div><?php endfor; ?>
    <?php foreach ($days as $d => $s): ?>
      <div title="<?= e(dmy($d) . ' ' . $s) ?>" style="text-align:center;padding:6px 0;border-radius:6px;border:1px solid var(--border);<?= $s !== 'future' ? 'color:#fff;background:' . $colors[$s] : '' ?>"><?= (int)substr($d, 8) ?></div>
    <?php endforeach; ?>
  </div>
  <?php if (can('hr.edit')): ?>
  <form method="post" class="mt" style="display:flex;gap:6px;flex-wrap:wrap;align-items:end"><?= csrf_field() ?><input type="hidden" name="do" value="mark"><input type="hidden" name="user_id" value="<?= (int)$who['id'] ?>">
    <input type="date" name="date" value="<?= e(today()) ?>" required>
    <select name="status"><option value="present">Present</option><option value="half">Half day</option><option value="absent">Absent</option><option value="leave">Paid leave</option></select>
    <input type="text" name="note" placeholder="Note" style="max-width:160px"><button class="btn btn-sm" type="submit">Mark</button></form>
  <?php endif; ?>
</div>
<?php $mc = hr_month_calc($who, $month); $rate = hr_rate($who, $month); $hourly = $who['pay_type'] === 'hourly';
      $km = hr_month_km($who['id'], $month); $pens = all('SELECT * FROM hr_penalties WHERE user_id = ? AND DATE_FORMAT(pen_date, "%Y-%m") = ? ORDER BY pen_date', [$who['id'], $month]);
      $canMoney = $who['id'] == $u['id'] || can('hr.payroll'); ?>
<div class="card">
  <h3 style="margin-top:0">📒 <?= e(date('F Y', strtotime("$month-01"))) ?> — day by day</h3>
  <div class="grid-stats">
    <div class="stat"><div class="stat-label">Hours worked</div><div class="stat-value"><?= +$mc['hours'] ?></div></div>
    <div class="stat <?= $mc['penalty_hours'] > 0 ? 's-bad' : '' ?>"><div class="stat-label">Penalty hours</div><div class="stat-value"><?= +$mc['penalty_hours'] ?></div></div>
    <?php if ($canMoney): ?><div class="stat"><div class="stat-label">Rate this month</div><div class="stat-value">₹<?= money($rate) ?><small><?= $hourly ? '/hour' : '/month' ?></small></div></div><?php endif; ?>
    <?php if ($who['bike_allowed']): ?><div class="stat"><div class="stat-label">🏍️ Bike</div><div class="stat-value"><?= +$km ?> km</div></div><?php endif; ?>
  </div>
  <div class="table-wrap"><table class="table-sm"><thead><tr><th>Date</th><th>Day</th><th>In / out</th><th class="num">Hours</th><th class="num">Penalty</th><th>Why</th><?php if (can('hr.edit')): ?><th></th><?php endif; ?></tr></thead><tbody>
  <?php foreach ($mc['days'] as $d => $x): if ($x['st'] === 'future') continue; ?>
    <tr><td><?= dmy($d) ?></td><td><?= e($x['st']) ?></td>
      <td><?= $x['in'] ? date('h:i A', strtotime($x['in'])) : '—' ?> / <?= $x['out'] ? date('h:i A', strtotime($x['out'])) : ($x['flag'] ? '<span class="badge badge-warn">' . e($x['flag']) . '</span>' : '—') ?></td>
      <td class="num"><?= $x['hours'] ? +$x['hours'] : '' ?></td>
      <td class="num" style="<?= $x['penalty'] > 0 ? 'color:var(--bad);font-weight:600' : '' ?>"><?= $x['penalty'] ? +$x['penalty'] . ' h' : '' ?></td>
      <td class="muted" style="font-size:13px"><?= e($x['why']) ?></td>
      <?php if (can('hr.edit')): ?><td><?php if ($x['penalty'] > 0 && strpos($x['why'], 'waived') === false): ?>
        <form method="post" action="team.php" style="display:inline"><?= csrf_field() ?><input type="hidden" name="do" value="penalty"><input type="hidden" name="kind" value="waive">
          <input type="hidden" name="user_id" value="<?= (int)$who['id'] ?>"><input type="hidden" name="date" value="<?= e($d) ?>"><input type="hidden" name="reason" value="let go by <?= e($u['name']) ?>">
          <button class="btn btn-sm btn-outline" type="submit" onclick="return confirm('Let the automatic penalty for <?= e(dmy($d)) ?> go?')">Let go</button></form><?php endif; ?></td><?php endif; ?></tr>
  <?php endforeach; ?></tbody></table></div>
  <?php if (can('hr.edit')): ?>
  <form method="post" action="team.php" class="mt" style="display:flex;gap:6px;flex-wrap:wrap;align-items:end"><?= csrf_field() ?><input type="hidden" name="do" value="penalty"><input type="hidden" name="kind" value="manual"><input type="hidden" name="user_id" value="<?= (int)$who['id'] ?>">
    <input type="date" name="date" value="<?= e(today()) ?>" required><input type="number" name="hours" min="0.5" step="0.5" placeholder="Hours" style="max-width:90px" required>
    <input type="text" name="reason" placeholder="Why (e.g. phone off on duty)" style="max-width:220px" required><button class="btn btn-sm" type="submit">➕ Add a penalty</button></form>
  <?php foreach ($pens as $p): ?>
    <form method="post" action="team.php" style="display:flex;gap:6px;align-items:center;font-size:13px;margin-top:4px"><?= csrf_field() ?><input type="hidden" name="do" value="penalty_del"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
      <?= dmy($p['pen_date']) ?> · <?= $p['kind'] === 'waive' ? 'penalty let go' : +$p['hours'] . ' h penalty' ?> · <?= e($p['reason']) ?> <button class="btn btn-sm btn-outline" type="submit">✕</button></form>
  <?php endforeach; endif; ?>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
