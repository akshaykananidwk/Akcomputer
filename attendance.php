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
$r = $today['row'];
$mode = !$r || !$r['in_at'] ? 'in' : (!$r['out_at'] ? 'out' : 'done');
$fmt = fn($t) => $t ? date('h:i A', strtotime($t)) : '—';
$dur = function ($a, $b) { $m = max(0, (int)round(((strtotime($b ?: 'now')) - strtotime($a)) / 60)); return intdiv($m, 60) . 'h ' . ($m % 60) . 'm'; };
$stName = ['present' => 'Present', 'half' => 'Half day', 'absent' => 'Absent', 'leave' => 'Paid leave', 'unpaid' => 'Leave (no pay)', 'off' => 'Weekly off', 'future' => ''];
$stChip = ['present' => 'ok', 'half' => 'warn', 'absent' => 'bad', 'leave' => 'info', 'unpaid' => 'info', 'off' => '', 'future' => ''];
?>
<?= hr_tabs('attendance') ?>

<div class="hr-hero<?= $mode === 'done' ? ' done' : '' ?>">
  <div class="who"><?= e($u['name']) ?> · <?= e(date('l, d M', strtotime(today()))) ?></div>
  <div class="clock" id="hrClock"><?= date('h:i A') ?></div>
  <div class="meta">
    <?php if (!empty($u['shift_start'])): ?><span><?= icon('clock', 14) ?> Shift <?= date('h:i A', strtotime($u['shift_start'])) ?><?= !empty($u['shift_end']) ? ' – ' . date('h:i A', strtotime($u['shift_end'])) : '' ?></span><?php endif; ?>
    <?php if ($r && $r['in_at']): ?><span><?= icon('arrow-down', 14) ?> In <?= $fmt($r['in_at']) ?><?= $today['late'] ? ' · late' : '' ?></span><?php endif; ?>
    <?php if ($r && $r['out_at']): ?><span><?= icon('arrow-up', 14) ?> Out <?= $fmt($r['out_at']) ?></span><?php endif; ?>
    <?php if ($r && $r['in_at']): ?><span><?= icon('check', 14) ?> <?= $dur($r['in_at'], $r['out_at']) ?></span><?php endif; ?>
  </div>
  <?php if ($mode !== 'done'): ?>
  <form method="post" enctype="multipart/form-data" id="attForm"><?= csrf_field() ?>
    <input type="hidden" name="do" value="<?= $mode ?>"><input type="hidden" name="lat" id="attLat"><input type="hidden" name="lng" id="attLng">
    <label class="hr-punch"><?= icon('camera', 22) ?> <?= $mode === 'in' ? 'Check in' : 'Check out' ?> — take a selfie
      <input type="file" name="selfie" accept="image/*" capture="user" hidden onchange="this.form.submit()"></label>
    <div class="note" id="attWhere" style="text-align:center;margin-top:8px;font-size:12.5px;opacity:.9"><?= icon('pin', 13) ?> Finding where you are…</div>
  </form>
  <?php else: ?><div class="hr-punch" style="cursor:default"><?= icon('check', 22) ?> Done for today</div><?php endif; ?>
</div>
<script>
(function () {
  var c = document.getElementById('hrClock');
  setInterval(function () { c.textContent = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }); }, 15000);
  var w = document.getElementById('attWhere');
  if (w && navigator.geolocation) navigator.geolocation.getCurrentPosition(function (p) {
    document.getElementById('attLat').value = p.coords.latitude; document.getElementById('attLng').value = p.coords.longitude;
    w.textContent = '📍 Location found (±' + Math.round(p.coords.accuracy) + ' m)';
  }, function () { w.textContent = '📍 Location not shared — it will be saved without it.'; }, { enableHighAccuracy: true, timeout: 8000 });
})();
</script>

<?php if ($seeAll): $team = all('SELECT u.*, a.id aid, a.in_at, a.out_at, a.in_lat, a.in_lng, a.in_photo FROM users u LEFT JOIN attendance a ON a.user_id = u.id AND a.att_date = ? WHERE u.is_active = 1 ORDER BY (a.in_at IS NULL), u.name', [today()]); ?>
<div class="hr-card">
  <h3><span><?= icon('users', 18) ?> Today</span><span class="hr-chip <?= count(array_filter($team, fn($t) => $t['in_at'])) === count($team) ? 'ok' : 'warn' ?>"><?= count(array_filter($team, fn($t) => $t['in_at'])) ?> / <?= count($team) ?> in</span></h3>
  <div class="hr-list">
  <?php foreach ($team as $t): $late = $t['in_at'] && $t['shift_start'] && strtotime($t['in_at']) > strtotime(today() . ' ' . $t['shift_start']) + 600; ?>
    <div class="hr-row"><div class="hr-av" style="<?= $t['in_at'] ? '' : 'background:var(--muted)' ?>"><?= e(hr_initials($t['name'])) ?></div>
      <div class="hr-main"><a class="t" href="attendance.php?u=<?= (int)$t['id'] ?>&amp;m=<?= e($month) ?>"><?= e($t['name']) ?></a>
        <div class="s"><?= $t['in_at'] ? 'In ' . $fmt($t['in_at']) . ($t['out_at'] ? ' · Out ' . $fmt($t['out_at']) : '') : 'Not in yet' ?>
          <?= $t['in_lat'] ? ' · <a target="_blank" rel="noopener" href="https://maps.google.com/?q=' . (float)$t['in_lat'] . ',' . (float)$t['in_lng'] . '">map</a>' : '' ?>
          <?= $t['in_photo'] ? ' · <a href="attendance.php?photo=' . (int)$t['aid'] . '" target="_blank">selfie</a>' : '' ?></div></div>
      <div class="hr-end"><?= $t['in_at'] ? ($late ? '<span class="hr-chip warn">late</span>' : '<span class="hr-chip ok">in</span>') : '<span class="hr-chip">—</span>' ?></div></div>
  <?php endforeach; ?></div>
</div>
<?php endif; ?>

<?php $mc = hr_month_calc($who, $month); $rate = hr_rate($who, $month); $hourly = $who['pay_type'] === 'hourly';
      $km = hr_month_km($who['id'], $month); $canMoney = $who['id'] == $u['id'] || can('hr.payroll');
      $cnt = array_count_values(array_map(fn($d) => $d['st'], $mc['days'])); ?>
<div class="hr-title"><div><h1 style="font-size:18px"><?= e($who['name']) ?></h1><div class="sub"><?= e(date('F Y', strtotime("$month-01"))) ?></div></div>
  <form><?php if ($who['id'] != $u['id']): ?><input type="hidden" name="u" value="<?= (int)$who['id'] ?>"><?php endif; ?>
    <input type="month" name="m" value="<?= e($month) ?>" onchange="this.form.submit()" aria-label="Month" style="width:175px;max-width:48vw"></form></div>

<div class="hr-tiles">
  <div class="hr-tile ok"><div class="ic"><?= icon('check', 20) ?></div><div><div class="v"><?= ($cnt['present'] ?? 0) + 0.5 * ($cnt['half'] ?? 0) ?> <small>days</small></div><div class="l">Present</div></div></div>
  <div class="hr-tile"><div class="ic"><?= icon('clock', 20) ?></div><div><div class="v"><?= +$mc['hours'] ?> <small>h</small></div><div class="l">Hours worked</div></div></div>
  <div class="hr-tile <?= $mc['penalty_hours'] > 0 ? 'bad' : '' ?>"><div class="ic"><?= icon('alert', 20) ?></div><div><div class="v"><?= +$mc['penalty_hours'] ?> <small>h</small></div><div class="l">Penalty</div></div></div>
  <?php if ($canMoney): ?><div class="hr-tile warn"><div class="ic"><?= icon('rupee', 20) ?></div><div><div class="v">₹<?= money($rate) ?> <small><?= $hourly ? '/hr' : '/mo' ?></small></div><div class="l">Rate this month</div></div></div><?php endif; ?>
  <?php if ($who['bike_allowed']): ?><a class="hr-tile" href="bike.php?m=<?= e($month) ?><?= $who['id'] != $u['id'] ? '&amp;u=' . (int)$who['id'] : '' ?>"><div class="ic"><?= icon('bike', 20) ?></div><div><div class="v"><?= +$km ?> <small>km</small></div><div class="l">Bike</div></div></a><?php endif; ?>
</div>

<div class="hr-card">
  <h3><span><?= icon('calendar', 18) ?> Calendar</span></h3>
  <div class="hr-cal">
    <?php foreach (['S', 'M', 'T', 'W', 'T', 'F', 'S'] as $wd): ?><div class="wd"><?= $wd ?></div><?php endforeach; ?>
    <?php for ($i = 0; $i < (int)date('w', strtotime("$month-01")); $i++): ?><div></div><?php endfor; ?>
    <?php foreach ($mc['days'] as $d => $x): ?>
      <div class="d <?= e($x['st']) ?><?= $d === today() ? ' today' : '' ?>" title="<?= e(dmy($d) . ' · ' . ($stName[$x['st']] ?? '') . ($x['penalty'] ? ' · penalty ' . +$x['penalty'] . ' h' : '')) ?>"><?= (int)substr($d, 8) ?><?= $x['penalty'] > 0 ? '<i class="pen"></i>' : '' ?></div>
    <?php endforeach; ?>
  </div>
  <div class="hr-legend"><span><i style="background:var(--ok)"></i>Present</span><span><i style="background:var(--warn)"></i>Half</span><span><i style="background:#2563eb"></i>Leave</span>
    <span><i style="background:rgba(220,38,38,.3)"></i>Absent</span><span><i style="border:1px dashed var(--muted)"></i>Off</span><span><i style="background:var(--bad)"></i>Penalty</span></div>
</div>

<div class="hr-card">
  <h3><span><?= icon('book', 18) ?> Day by day</span><?php if ($mc['penalty_hours'] > 0): ?><span class="hr-chip bad"><?= +$mc['penalty_hours'] ?> h penalty</span><?php endif; ?></h3>
  <div class="hr-list">
  <?php $shown = 0; foreach (array_reverse($mc['days'], true) as $d => $x): if ($x['st'] === 'future') continue; $shown++; ?>
    <div class="hr-row">
      <div class="hr-date"><b><?= (int)substr($d, 8) ?></b><span><?= date('D', strtotime($d)) ?></span></div>
      <div class="hr-main"><div class="t"><span class="hr-chip <?= $stChip[$x['st']] ?? '' ?>"><?= e($stName[$x['st']] ?? $x['st']) ?></span>
          <?= $x['in'] ? '<span style="font-weight:500;font-size:13px"> ' . $fmt($x['in']) . ' – ' . ($x['out'] ? $fmt($x['out']) : '…') . '</span>' : '' ?></div>
        <?php if ($x['hours'] || $x['why'] || $x['flag']): ?><div class="s"><?= $x['hours'] ? +$x['hours'] . ' h worked' : '' ?><?= $x['flag'] ? ' · ' . e($x['flag']) : '' ?><?= $x['why'] ? ($x['hours'] ? ' · ' : '') . e($x['why']) : '' ?></div><?php endif; ?></div>
      <div class="hr-end"><?php if ($x['penalty'] > 0): ?><span class="pen">−<?= +$x['penalty'] ?> h</span>
        <?php if (can('hr.edit') && strpos($x['why'], 'waived') === false): ?>
        <form method="post" action="team.php"><?= csrf_field() ?><input type="hidden" name="do" value="penalty"><input type="hidden" name="kind" value="waive">
          <input type="hidden" name="user_id" value="<?= (int)$who['id'] ?>"><input type="hidden" name="date" value="<?= e($d) ?>"><input type="hidden" name="reason" value="let go by <?= e($u['name']) ?>">
          <button class="hr-mini" type="submit" onclick="return confirm('Let the penalty for <?= e(dmy($d)) ?> go?')">Let go</button></form><?php endif; ?>
      <?php elseif ($x['hours']): ?><span class="hr-chip"><?= +$x['hours'] ?> h</span><?php endif; ?></div>
    </div>
  <?php endforeach; if (!$shown): ?><div class="hr-empty">Nothing yet this month.</div><?php endif; ?></div>
</div>

<?php if (can('hr.edit')): $pens = all('SELECT * FROM hr_penalties WHERE user_id = ? AND DATE_FORMAT(pen_date, "%Y-%m") = ? ORDER BY pen_date', [$who['id'], $month]); ?>
<details class="hr-tools"><summary><?= icon('gear', 18) ?> Owner tools — <?= e($who['name']) ?></summary><div class="in">
  <form method="post" class="hr-form"><?= csrf_field() ?><input type="hidden" name="do" value="mark"><input type="hidden" name="user_id" value="<?= (int)$who['id'] ?>">
    <div><label>Day</label><input type="date" name="date" value="<?= e(today()) ?>" required></div>
    <div><label>Mark as</label><select name="status"><option value="present">Present</option><option value="half">Half day</option><option value="absent">Absent</option><option value="leave">Paid leave</option></select></div>
    <div><label>Note</label><input type="text" name="note" placeholder="Note"></div><div><button class="btn btn-block" type="submit">Mark the day</button></div></form>
  <form method="post" action="team.php" class="hr-form"><?= csrf_field() ?><input type="hidden" name="do" value="penalty"><input type="hidden" name="kind" value="manual"><input type="hidden" name="user_id" value="<?= (int)$who['id'] ?>">
    <div><label>Day</label><input type="date" name="date" value="<?= e(today()) ?>" required></div>
    <div><label>Penalty hours</label><input type="number" name="hours" min="0.5" step="0.5" required></div>
    <div><label>Why</label><input type="text" name="reason" placeholder="e.g. phone off on duty" required></div><div><button class="btn btn-block btn-outline" type="submit">➕ Add a penalty</button></div></form>
  <?php foreach ($pens as $p): ?>
    <form method="post" action="team.php" class="hr-row" style="grid-template-columns:minmax(0,1fr) auto"><?= csrf_field() ?><input type="hidden" name="do" value="penalty_del"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
      <div class="hr-main"><div class="t"><?= dmy($p['pen_date']) ?> · <?= $p['kind'] === 'waive' ? 'penalty let go' : +$p['hours'] . ' h penalty' ?></div><div class="s"><?= e($p['reason']) ?></div></div>
      <button class="hr-mini" type="submit" title="Remove">✕ Remove</button></form>
  <?php endforeach; ?>
</div></details>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
