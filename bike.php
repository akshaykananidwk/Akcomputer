<?php
// 🏍️ Bike: a person presses Start when they ride for the shop, the phone's
// GPS records where they go, Stop ends the trip and the km are worked out on
// the server from the points (bad fixes and jumps are dropped - hr_trip_km).
// The month's km x the petrol rate goes into the salary slip. The owner sees
// every trip, its route, and can correct the km with a reason.
require_once __DIR__ . '/includes/init.php';
require_login();
$u = current_user();
$seeAll = can('hr.view');
$month = preg_match('/^\d{4}-\d{2}$/', (string)get('m')) ? get('m') : date('Y-m');
$running = fn($uid) => row("SELECT * FROM bike_trips WHERE user_id = ? AND status = 'running' ORDER BY id DESC LIMIT 1", [$uid]);
$finish = function ($trip) {
    $pts = all('SELECT at, lat, lng, acc FROM bike_points WHERE trip_id = ? ORDER BY at, id', [$trip['id']]);
    $end = $pts ? end($pts)['at'] : date('Y-m-d H:i:s');
    q("UPDATE bike_trips SET status = 'done', ended_at = ?, km = ? WHERE id = ? AND status = 'running'", [$end, hr_trip_km($pts), $trip['id']]);
};
// a trip left running for half a day is closed at its last point
foreach (all("SELECT t.* FROM bike_trips t WHERE t.status = 'running' AND COALESCE((SELECT MAX(at) FROM bike_points p WHERE p.trip_id = t.id), t.started_at) < NOW() - INTERVAL 12 HOUR") as $old) $finish($old);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = post('do');
    if ($do === 'pts') {   // GPS points from the phone, in small batches
        header('Content-Type: application/json');
        $t = row("SELECT * FROM bike_trips WHERE id = ? AND user_id = ? AND status = 'running'", [(int)post('trip'), $u['id']]);
        if (!$t || !api_rate_ok('bike:' . $u['id'], 400, 3600)) { echo json_encode(['ok' => false]); exit; }
        $n = 0;
        foreach (array_slice((array)json_decode((string)post('pts'), true), 0, 200) as $p) {
            $lat = (float)($p['lat'] ?? 0); $lng = (float)($p['lng'] ?? 0); $at = (int)($p['t'] ?? 0) / 1000;
            if (abs($lat) > 90 || abs($lng) > 180 || ($lat == 0 && $lng == 0) || $at < strtotime($t['started_at']) - 60 || $at > time() + 120) continue;
            q('INSERT INTO bike_points (trip_id, at, lat, lng, acc) VALUES (?,?,?,?,?)', [$t['id'], date('Y-m-d H:i:s', (int)$at), $lat, $lng, min(9999, (int)($p['acc'] ?? 0))]);
            $n++;
        }
        echo json_encode(['ok' => true, 'saved' => $n]); exit;
    }
    if ($do === 'start') {
        if (!$u['bike_allowed']) { flash('Bike allowance is not on for you — ask the owner.', 'error'); redirect('bike.php'); }
        if (!$running($u['id'])) q("INSERT INTO bike_trips (user_id, started_at, note) VALUES (?, NOW(), ?)", [$u['id'], mb_substr(trim((string)post('note')), 0, 200)]);
        redirect('bike.php');
    }
    if ($do === 'stop') {
        $t = $running($u['id']);
        if ($t) { $finish($t); $km = (float)val('SELECT km FROM bike_trips WHERE id = ?', [$t['id']]); flash('Trip saved: ' . +$km . ' km.'); }
        redirect('bike.php');
    }
    if ($do === 'adjust') {
        require_perm('hr.edit');
        $t = row('SELECT * FROM bike_trips WHERE id = ?', [(int)post('id')]);
        if ($t) {
            $v = trim((string)post('km'));
            q('UPDATE bike_trips SET adj_km = ?, adj_note = ? WHERE id = ?', [$v === '' ? null : max(0, (float)$v), mb_substr(trim((string)post('note')), 0, 200), $t['id']]);
            log_activity('bike_adjust', "trip #{$t['id']} {$t['km']} → " . ($v === '' ? 'GPS' : $v));
        }
        redirect('bike.php?m=' . $month . (get('u') ? '&u=' . (int)get('u') : ''));
    }
}

$who = $seeAll && (int)get('u') ? row('SELECT * FROM users WHERE id = ?', [(int)get('u')]) : $u;
$cur = $running($u['id']);
$trips = all("SELECT t.*, (SELECT COUNT(*) FROM bike_points p WHERE p.trip_id = t.id) n FROM bike_trips t WHERE t.user_id = ? AND DATE_FORMAT(t.started_at, '%Y-%m') = ? ORDER BY t.started_at DESC", [$who['id'], $month]);
$route = function ($tripId) {
    $pts = all('SELECT lat, lng FROM bike_points WHERE trip_id = ? AND acc <= 60 ORDER BY at, id', [$tripId]);
    if (count($pts) < 2) return '';
    $ll = fn($p) => round($p['lat'], 6) . ',' . round($p['lng'], 6);
    $step = max(1, intdiv(count($pts), 9)); $way = [];
    for ($i = $step; $i < count($pts) - 1 && count($way) < 8; $i += $step) $way[] = $ll($pts[$i]);
    return 'https://www.google.com/maps/dir/?api=1&travelmode=driving&origin=' . $ll($pts[0]) . '&destination=' . $ll(end($pts)) . ($way ? '&waypoints=' . implode('|', $way) : '');
};
$myKm = hr_month_km($who['id'], $month); $myRate = hr_petrol_rate($who);
$page_title = 'Bike';
include __DIR__ . '/includes/header.php';
?>
<?= hr_tabs('bike') ?>

<?php if ($u['bike_allowed']): ?>
<div class="hr-hero ride" id="bikeCard">
  <?php if ($cur): ?>
    <div class="who">🟢 Riding since <?= date('h:i A', strtotime($cur['started_at'])) ?><?= $cur['note'] !== '' ? ' · ' . e($cur['note']) : '' ?></div>
    <div class="hr-big" style="margin-top:10px"><span id="bkKm">0.00</span> <small>km</small></div>
    <div class="meta" style="justify-content:center;margin-top:8px"><span id="bkGps"><?= icon('pin', 14) ?> waiting for GPS…</span></div>
    <form method="post" id="bkStop"><?= csrf_field() ?><input type="hidden" name="do" value="stop">
      <button class="hr-round stop" type="submit"><?= icon('stop', 34) ?>STOP</button></form>
    <div class="note">Keep this screen open while riding — it stays on by itself.</div>
  <?php else: ?>
    <div class="who"><?= e($u['name']) ?> · ₹<?= +hr_petrol_rate($u) ?> per km</div>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="start">
      <button class="hr-round" type="submit" onclick="if(navigator.geolocation)navigator.geolocation.getCurrentPosition(function(){},function(){},{enableHighAccuracy:true})"><?= icon('play', 34) ?>START</button>
      <input type="text" name="note" maxlength="200" placeholder="Where to? (e.g. Okha site — CCTV)" aria-label="Where to" style="margin-top:6px"></form>
    <div class="note">Allow location when the phone asks. Km are counted from where the bike really goes.</div>
  <?php endif; ?>
</div>
<?php elseif (!$seeAll): ?><div class="hr-card hr-empty">Bike allowance is not on for you. The owner turns it on in Staff pay.</div><?php endif; ?>

<div class="hr-title"><div><h1 style="font-size:18px"><?= e($who['name']) ?></h1><div class="sub"><?= e(date('F Y', strtotime("$month-01"))) ?></div></div>
  <form><?php if ($who['id'] != $u['id']): ?><input type="hidden" name="u" value="<?= (int)$who['id'] ?>"><?php endif; ?><input type="month" name="m" value="<?= e($month) ?>" onchange="this.form.submit()" aria-label="Month" style="width:175px;max-width:48vw"></form></div>
<div class="hr-tiles">
  <div class="hr-tile"><div class="ic"><?= icon('bike', 20) ?></div><div><div class="v"><?= +$myKm ?> <small>km</small></div><div class="l"><?= count(array_filter($trips, fn($t) => $t['status'] === 'done')) ?> trips</div></div></div>
  <div class="hr-tile ok"><div class="ic"><?= icon('rupee', 20) ?></div><div><div class="v">₹<?= money($myKm * $myRate) ?></div><div class="l">Petrol allowance</div></div></div>
</div>

<?php if ($seeAll):
  $sum = all("SELECT us.id, us.name, us.petrol_rate, us.bike_allowed, COUNT(t.id) trips, COALESCE(SUM(COALESCE(t.adj_km, t.km)), 0) km
              FROM users us LEFT JOIN bike_trips t ON t.user_id = us.id AND t.status = 'done' AND DATE_FORMAT(t.started_at, '%Y-%m') = ?
              WHERE us.is_active = 1 AND (us.bike_allowed = 1 OR t.id IS NOT NULL) GROUP BY us.id ORDER BY km DESC", [$month]); ?>
<div class="hr-card">
  <h3><span><?= icon('users', 18) ?> Everyone this month</span></h3>
  <div class="hr-list">
  <?php foreach ($sum as $r): $rate = hr_petrol_rate($r + ['bike_allowed' => 1]); ?>
    <a class="hr-row" href="bike.php?u=<?= (int)$r['id'] ?>&amp;m=<?= e($month) ?>" style="color:inherit"><div class="hr-av"><?= e(hr_initials($r['name'])) ?></div>
      <div class="hr-main"><div class="t"><?= e($r['name']) ?></div><div class="s"><?= (int)$r['trips'] ?> trips · <?= +round($r['km'], 2) ?> km × ₹<?= +$rate ?></div></div>
      <div class="hr-end"><b>₹<?= money($r['km'] * $rate) ?></b></div></a>
  <?php endforeach; if (!$sum): ?><div class="hr-empty">No one has bike allowance yet — turn it on in Staff pay.</div><?php endif; ?></div>
</div>
<?php endif; ?>

<div class="hr-card">
  <h3><span><?= icon('pin', 18) ?> Trips</span></h3>
  <div class="hr-list">
  <?php foreach ($trips as $t): $link = $route($t['id']); $kmShown = $t['adj_km'] !== null ? (float)$t['adj_km'] : (float)$t['km']; ?>
    <div class="hr-row">
      <div class="hr-date"><b><?= date('d', strtotime($t['started_at'])) ?></b><span><?= date('D', strtotime($t['started_at'])) ?></span></div>
      <div class="hr-main"><div class="t"><?= $t['note'] !== '' ? e($t['note']) : 'Trip' ?></div>
        <div class="s"><?= date('h:i A', strtotime($t['started_at'])) ?><?= $t['ended_at'] ? ' – ' . date('h:i A', strtotime($t['ended_at'])) : '' ?>
          <?= $link ? ' · <a href="' . e($link) . '" target="_blank" rel="noopener">🗺️ route</a>' : '' ?><?= $t['adj_km'] !== null ? ' · corrected from ' . +$t['km'] . ' km' . ($t['adj_note'] !== '' ? ' (' . e($t['adj_note']) . ')' : '') : '' ?></div>
        <?php if (can('hr.edit') && $t['status'] === 'done'): ?>
        <details style="margin-top:4px"><summary class="hr-mini" style="display:inline-block;list-style:none">Correct km</summary>
          <form method="post" class="hr-form" style="margin-top:6px"><?= csrf_field() ?><input type="hidden" name="do" value="adjust"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
            <div><input type="number" name="km" step="0.1" min="0" value="<?= $t['adj_km'] === null ? '' : +$t['adj_km'] ?>" placeholder="<?= +$t['km'] ?> km" aria-label="Km"></div>
            <div><input type="text" name="note" value="<?= e($t['adj_note']) ?>" placeholder="why" aria-label="Why"></div><div><button class="btn btn-sm btn-block" type="submit">Save</button></div></form></details>
        <?php endif; ?></div>
      <div class="hr-end"><?= $t['status'] === 'running' ? '<span class="hr-chip ok">riding</span>' : '<b>' . +$kmShown . ' km</b>' ?></div>
    </div>
  <?php endforeach; if (!$trips): ?><div class="hr-empty">No trips this month.</div><?php endif; ?></div>
</div>

<?php if ($cur): ?>
<script>
(function () {
  var TRIP = <?= (int)$cur['id'] ?>, KEY = 'bikeq_' + TRIP, CSRF = <?= json_encode(csrf_token()) ?>, last = null, km = 0;
  var q = []; try { q = JSON.parse(localStorage.getItem(KEY) || '[]'); } catch (e) {}
  var save = function () { try { localStorage.setItem(KEY, JSON.stringify(q)); } catch (e) {} };
  var dist = function (a, b) { var R = 6371, r = Math.PI / 180, dLa = (b.lat - a.lat) * r, dLo = (b.lng - a.lng) * r;
    var h = Math.sin(dLa / 2) * Math.sin(dLa / 2) + Math.cos(a.lat * r) * Math.cos(b.lat * r) * Math.sin(dLo / 2) * Math.sin(dLo / 2); return 2 * R * Math.asin(Math.min(1, Math.sqrt(h))); };
  function flush(then) {
    if (!q.length) { if (then) then(); return; }
    var batch = q.slice(0, 200), fd = new FormData();
    fd.append('csrf', CSRF); fd.append('do', 'pts'); fd.append('trip', TRIP); fd.append('pts', JSON.stringify(batch));
    fetch('bike.php', { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
      if (j && j.ok) { q = q.slice(batch.length); save(); }
      if (then) then();
    }).catch(function () { if (then) then(); });
  }
  if (navigator.geolocation) navigator.geolocation.watchPosition(function (p) {
    var pt = { t: Date.now(), lat: p.coords.latitude, lng: p.coords.longitude, acc: Math.round(p.coords.accuracy) };
    document.getElementById('bkGps').textContent = '📍 GPS ±' + pt.acc + ' m';
    if (pt.acc > 60) return;
    if (last) { var d = dist(last, pt), h = (pt.t - last.t) / 3600000; if (d * 1000 < 5 || (h > 0 && d / h > 130)) return; km += d; }
    last = pt; q.push(pt); save();
    document.getElementById('bkKm').textContent = km.toFixed(2);
  }, function (e) { document.getElementById('bkGps').textContent = '⚠️ Location is off — allow it for this site / app.'; }, { enableHighAccuracy: true, maximumAge: 5000, timeout: 30000 });
  setInterval(function () { flush(); }, 20000);
  if (navigator.wakeLock) { var lock = function () { navigator.wakeLock.request('screen').catch(function () {}); }; lock(); document.addEventListener('visibilitychange', function () { if (!document.hidden) lock(); }); }
  document.getElementById('bkStop').addEventListener('submit', function (ev) {
    ev.preventDefault(); var f = this;
    flush(function () { flush(function () { try { localStorage.removeItem(KEY); } catch (e) {} f.submit(); }); });
  });
})();
</script>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
