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
$page_title = 'Bike';
include __DIR__ . '/includes/header.php';
?>
<div class="page-head"><h1>🏍️ Bike km &amp; petrol</h1><form><input type="month" name="m" value="<?= e($month) ?>" onchange="this.form.submit()"><?php if ($who['id'] != $u['id']): ?><input type="hidden" name="u" value="<?= (int)$who['id'] ?>"><?php endif; ?></form></div>

<?php if ($u['bike_allowed']): ?>
<div class="card" id="bikeCard">
  <?php if ($cur): ?>
    <p style="margin-top:0"><b>🟢 Trip running</b> since <?= date('h:i A', strtotime($cur['started_at'])) ?> · <span id="bkKm">0.00</span> km so far · <span id="bkGps" class="muted">waiting for GPS…</span></p>
    <form method="post" id="bkStop"><?= csrf_field() ?><input type="hidden" name="do" value="stop">
      <button class="btn btn-block btn-danger" style="font-size:18px;padding:16px" type="submit">⏹ Stop — I have reached</button></form>
    <p class="muted" style="font-size:13px">Keep this screen open while riding (the screen stays on by itself). If the phone locks, the km between the last two points are still counted in a straight line.</p>
  <?php else: ?>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="start">
      <input type="text" name="note" maxlength="200" placeholder="Where to? (e.g. Okha site — CCTV)" style="margin-bottom:8px">
      <button class="btn btn-block" style="font-size:18px;padding:16px" type="submit" onclick="if(navigator.geolocation)navigator.geolocation.getCurrentPosition(function(){},function(){},{enableHighAccuracy:true})">▶️ Start bike trip</button></form>
    <p class="muted" style="font-size:13px">Allow location when the phone asks. The km are counted from where the bike actually goes.</p>
  <?php endif; ?>
</div>
<?php elseif (!$seeAll): ?><div class="card">Bike allowance is not on for you. The owner turns it on in Team → Shifts &amp; salary.</div><?php endif; ?>

<?php if ($seeAll):
  $sum = all("SELECT us.id, us.name, us.petrol_rate, us.bike_allowed, COUNT(t.id) trips, COALESCE(SUM(COALESCE(t.adj_km, t.km)), 0) km
              FROM users us LEFT JOIN bike_trips t ON t.user_id = us.id AND t.status = 'done' AND DATE_FORMAT(t.started_at, '%Y-%m') = ?
              WHERE us.is_active = 1 AND (us.bike_allowed = 1 OR t.id IS NOT NULL) GROUP BY us.id ORDER BY km DESC", [$month]); ?>
<div class="pane"><div class="pane-head"><h3><?= e(date('F Y', strtotime("$month-01"))) ?> — everyone</h3></div><div class="pane-body tight">
<table class="rowlist"><thead><tr><th>Staff</th><th class="num">Trips</th><th class="num">Km</th><th class="num">₹/km</th><th class="num">Petrol allowance</th></tr></thead><tbody>
<?php foreach ($sum as $r): $rate = hr_petrol_rate($r + ['bike_allowed' => 1]); ?>
  <tr><td data-l="Staff"><a href="bike.php?u=<?= (int)$r['id'] ?>&amp;m=<?= e($month) ?>"><b><?= e($r['name']) ?></b></a></td><td class="num" data-l="Trips"><?= (int)$r['trips'] ?></td>
    <td class="num" data-l="Km"><?= +round($r['km'], 2) ?></td><td class="num" data-l="₹/km"><?= +$rate ?></td><td class="num" data-l="Allowance"><b>₹<?= money($r['km'] * $rate) ?></b></td></tr>
<?php endforeach; if (!$sum): ?><tr><td class="muted">No one has bike allowance on yet (Team → Shifts &amp; salary → 🏍️).</td></tr><?php endif; ?>
</tbody></table></div></div>
<?php endif; ?>

<div class="pane"><div class="pane-head"><h3><?= e($who['name']) ?> — trips (<?= +hr_month_km($who['id'], $month) ?> km)</h3></div><div class="pane-body tight">
<table class="rowlist"><thead><tr><th>When</th><th>Where to</th><th class="num">Km</th><th>Route</th><?php if (can('hr.edit')): ?><th>Correct km</th><?php endif; ?></tr></thead><tbody>
<?php foreach ($trips as $t): $link = $route($t['id']); ?>
  <tr><td data-l="When"><?= date('d-m h:i A', strtotime($t['started_at'])) ?><?= $t['ended_at'] ? ' → ' . date('h:i A', strtotime($t['ended_at'])) : ' <span class="badge badge-ok">running</span>' ?></td>
    <td data-l="Where to"><?= e($t['note']) ?></td>
    <td class="num" data-l="Km"><?= $t['adj_km'] !== null ? '<b>' . +$t['adj_km'] . '</b> <small class="muted" title="' . e($t['adj_note']) . '">(GPS ' . +$t['km'] . ')</small>' : +$t['km'] ?></td>
    <td data-l="Route"><?= $link ? '<a href="' . e($link) . '" target="_blank" rel="noopener">🗺️ map</a>' : '<span class="muted">' . (int)$t['n'] . ' points</span>' ?></td>
    <?php if (can('hr.edit')): ?><td data-l="Correct km"><form method="post" style="display:flex;gap:4px"><?= csrf_field() ?><input type="hidden" name="do" value="adjust"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
      <input type="number" name="km" step="0.1" min="0" value="<?= $t['adj_km'] === null ? '' : +$t['adj_km'] ?>" placeholder="<?= +$t['km'] ?>" style="width:70px">
      <input type="text" name="note" value="<?= e($t['adj_note']) ?>" placeholder="why" style="width:90px"><button class="btn btn-sm btn-outline" type="submit">✔</button></form></td><?php endif; ?></tr>
<?php endforeach; if (!$trips): ?><tr><td class="muted">No trips this month.</td></tr><?php endif; ?>
</tbody></table></div></div>

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
