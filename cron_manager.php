<?php
// Cron Manager (admin only): every scheduled/background job of the whole
// system in one screen - master-cron health, per-job enable/interval,
// last-run status, manual run, and the execution history. The server needs
// exactly ONE cron entry (cron.php every minute); everything else is here.
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/cron_jobs.php';
require_login();
if (!is_full_admin()) {
    http_response_code(403);
    include __DIR__ . '/includes/header.php';
    echo '<div class="card"><h2>Access denied</h2><p>Only the admin can manage cron jobs.</p></div>';
    include __DIR__ . '/includes/footer.php';
    exit;
}

$JOBS = cron_jobs();

// ---------- save enable/interval per job ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'save_jobs') {
    $on = (array)post('job_on', []);
    foreach ($JOBS as $id => $def) {
        set_setting("cron_{$id}_on", in_array($id, $on, true) ? '1' : '0');
        $ev = (int)post("every_$id");
        if ($ev > 0) set_setting("cron_{$id}_every", (string)min(10080, $ev));
    }
    log_activity('cron_jobs_save');
    flash('Cron job settings saved.');
    redirect('cron_manager.php');
}

// ---------- manual run (also = retry for a failed job) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'run') {
    $id = post('job');
    if (isset($JOBS[$id])) {
        $res = cron_run_all([$id], true);
        $r = $res[0] ?? ['status' => 'skip', 'detail' => 'nothing ran'];
        flash($JOBS[$id][0] . ' — ' . strtoupper($r['status']) . ': ' . $r['detail'], $r['status'] === 'fail' ? 'error' : 'success');
        log_activity('cron_manual_run', $id . ' ' . $r['status']);
    }
    redirect('cron_manager.php');
}

$cronUrl = base_url('cron.php?key=' . setting('cron_key'));
$lastTick = setting('cron_last_tick', '');
$tickAge = $lastTick !== '' ? (time() - strtotime($lastTick)) / 60 : null;
$tickState = $tickAge === null ? ['🔴', 'Never ran — set up the server cron below', 'var(--bad)']
    : ($tickAge <= 5 ? ['🟢', 'Running (last tick ' . dmyt($lastTick) . ')', 'var(--ok)']
    : ($tickAge <= 90 ? ['🟡', 'Slow — last tick ' . dmyt($lastTick) . ' (' . round($tickAge) . ' min ago). Cron should hit every minute.', 'var(--warn, orange)']
    : ['🔴', 'STOPPED — last tick ' . dmyt($lastTick) . ' (' . round($tickAge / 60, 1) . ' hours ago). Check the server cron!', 'var(--bad)']));

$lastRuns = [];
try {
    foreach (all("SELECT c1.* FROM cron_runs c1 JOIN (SELECT job, MAX(id) mid FROM cron_runs GROUP BY job) c2 ON c2.mid = c1.id") as $r) $lastRuns[$r['job']] = $r;
} catch (Exception $e) { /* pre-v51 */ }

$histJob = get('hist');
try {
    $history = $histJob !== '' && isset($JOBS[$histJob])
        ? all('SELECT * FROM cron_runs WHERE job = ? ORDER BY id DESC LIMIT 40', [$histJob])
        : all('SELECT * FROM cron_runs ORDER BY id DESC LIMIT 40');
} catch (Exception $e) { $history = []; }

$page_title = 'Cron Manager';
include __DIR__ . '/includes/header.php';
?>
<div class="card">
  <h3><?= $tickState[0] ?> Master Cron <span style="color:<?= $tickState[2] ?>;font-size:14px;font-weight:600"><?= e($tickState[1]) ?></span></h3>
  <p class="muted mb">આખી સિસ્ટમ માટે સર્વર પર <strong>ફક્ત આ એક જ cron</strong> જોઈએ — <strong>દર 1 મિનિટે</strong>. બાકીના બધા કામ (રિમાઇન્ડર, બેકઅપ, રિપોર્ટ...) આ પેજ પરથી મેનેજ થાય છે; નવું job ઉમેરાય ત્યારે સર્વર પર કંઈ બદલવું પડતું નથી.</p>
  <p class="mb"><strong>cPanel → Cron Jobs</strong> (Common Settings: <em>Once Per Minute</em> <code>* * * * *</code>):</p>
  <p class="mb"><code style="word-break:break-all;background:var(--bg);padding:8px;border-radius:8px;display:block">wget -qO- "<?= e($cronUrl) ?>"</code></p>
  <p class="muted" style="font-size:12.5px">અથવા browser માં ટેસ્ટ કરવા: <a href="<?= e($cronUrl) ?>" target="_blank"><?= e($cronUrl) ?></a></p>
</div>

<div class="card">
  <h3>⚙️ Jobs</h3>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="save_jobs">
    <div class="table-wrap" style="box-shadow:none"><table class="table-sm">
      <thead><tr><th>On</th><th>Job</th><th style="width:110px">Every (min)</th><th>Last run</th><th>Next due</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($JOBS as $id => $def):
          $on = setting("cron_{$id}_on", '1') === '1';
          $every = max(1, (int)setting("cron_{$id}_every", (string)$def[2]));
          $lr = $lastRuns[$id] ?? null;
          $stIcon = !$lr ? '—' : ($lr['status'] === 'ok' ? '✅' : ($lr['status'] === 'fail' ? '❌' : '⏳'));
          $nextDue = !$on ? '<span class="muted">off</span>'
              : (!$lr ? '<strong>now</strong>'
              : ($lr['status'] === 'fail' ? '<strong style="color:var(--bad)">retry ≤5 min</strong>'
              : e(dmyt(date('Y-m-d H:i:s', strtotime($lr['started_at']) + $every * 60)))));
      ?>
      <tr <?= $lr && $lr['status'] === 'fail' ? 'style="background:rgba(220,38,38,.06)"' : '' ?>>
        <td><input type="checkbox" name="job_on[]" value="<?= e($id) ?>" <?= $on ? 'checked' : '' ?>></td>
        <td><strong><?= e($def[0]) ?></strong><br><span class="muted" style="font-size:12px"><?= e($def[1]) ?></span></td>
        <td><input type="number" min="1" max="10080" name="every_<?= e($id) ?>" value="<?= $every ?>" style="width:90px"></td>
        <td><?= $stIcon ?> <?= $lr ? e(dmyt($lr['started_at'])) : '<span class="muted">never</span>' ?>
            <?php if ($lr): ?><br><span class="muted" style="font-size:12px"><?= e(mb_substr($lr['detail'] ?? '', 0, 90)) ?></span><?php endif; ?></td>
        <td><?= $nextDue ?></td>
        <td class="right"><button class="btn btn-sm btn-outline" type="submit" form="run<?= e($id) ?>" title="Run this job right now">▶ Run</button></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <button class="btn mt" type="submit">💾 Save Job Settings</button>
  </form>
  <?php foreach ($JOBS as $id => $def): ?>
  <form method="post" id="run<?= e($id) ?>"><?= csrf_field() ?><input type="hidden" name="do" value="run"><input type="hidden" name="job" value="<?= e($id) ?>"></form>
  <?php endforeach; ?>
  <p class="muted mt" style="font-size:12.5px">💡 Fail થયેલું job આપોઆપ 5 મિનિટમાં ફરી ટ્રાય થાય છે; "▶ Run" થી તરત પણ ચલાવી શકાય. ઘણા job અંદરથી "દિવસમાં એક જ વાર" જેવા ગાર્ડ રાખે છે એટલે વારંવાર ચલાવવાથી ડબલ મેસેજ કદી નહીં જાય.</p>
</div>

<div class="card">
  <h3>📜 Execution History <span class="muted" style="font-size:13px;font-weight:normal">(last 40)</span></h3>
  <form method="get" class="filterbar mb">
    <select name="hist" onchange="this.form.submit()">
      <option value="">All jobs</option>
      <?php foreach ($JOBS as $id => $def): ?>
      <option value="<?= e($id) ?>" <?= $histJob === $id ? 'selected' : '' ?>><?= e($def[0]) ?></option>
      <?php endforeach; ?>
    </select>
  </form>
  <?php if (!$history): ?>
  <p class="muted">હજી કોઈ execution નોંધાયું નથી. (Migrate ચલાવ્યા પછી અહીં દરેક run દેખાશે.)</p>
  <?php else: ?>
  <div class="table-wrap" style="box-shadow:none"><table class="table-sm">
    <thead><tr><th>Started</th><th>Job</th><th>Status</th><th>Took</th><th>Detail</th></tr></thead>
    <tbody>
    <?php foreach ($history as $h):
        $took = $h['finished_at'] ? max(0, strtotime($h['finished_at']) - strtotime($h['started_at'])) . 's' : '…';
    ?>
    <tr>
      <td><?= e(dmyt($h['started_at'])) ?></td>
      <td><?= e($JOBS[$h['job']][0] ?? $h['job']) ?></td>
      <td><?= $h['status'] === 'ok' ? '✅ OK' : ($h['status'] === 'fail' ? '<span style="color:var(--bad)">❌ FAIL</span>' : '⏳ running') ?></td>
      <td><?= e($took) ?></td>
      <td style="max-width:420px"><span class="muted" style="font-size:12.5px"><?= e($h['detail'] ?? '') ?></span></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
