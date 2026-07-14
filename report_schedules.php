<?php
// Scheduled Reports: a recurring WhatsApp summary of a chosen report,
// processed by cron.php (the app's one existing scheduled-task entry
// point - see the "Overdue reminders / AMC renewal / birthday wishes" jobs
// already there). Sends a short text digest, not a PDF attachment, to keep
// this reliable without needing a publicly-hosted file for every run.
require_once __DIR__ . '/includes/init.php';
require_perm('report_schedules.view');
$u = current_user();

$reportChoices = [
    'daily' => 'Daily Sales Summary',
    'business' => 'Business Report (P&L)',
    'branch_staff' => 'Branch / Staff Comparison',
    'low' => 'Low Stock Alert',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'save') {
    require_perm('report_schedules.add');
    $name = trim(post('name'));
    $reportKey = post('report_key');
    $mobile = trim(post('recipient_mobile'));
    if ($name === '' || !isset($reportChoices[$reportKey]) || $mobile === '') {
        flash('Name, report type and mobile number are required.', 'error');
        redirect('report_schedules.php?action=new');
    }
    q('INSERT INTO report_schedules (name, report_key, location_id, frequency, day_of_week, day_of_month, recipient_mobile, created_by)
       VALUES (?,?,?,?,?,?,?,?)',
      [$name, $reportKey, (int)post('location_id') ?: null, post('frequency', 'daily'),
       post('frequency') === 'weekly' ? (int)post('day_of_week') : null,
       post('frequency') === 'monthly' ? (int)post('day_of_month') : null,
       $mobile, $u['id']]);
    log_activity('report_schedule_add', $name);
    flash('Schedule "' . $name . '" created.');
    redirect('report_schedules.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'toggle') {
    require_perm('report_schedules.edit');
    $s = row('SELECT * FROM report_schedules WHERE id = ?', [(int)post('id')]);
    if ($s) { q('UPDATE report_schedules SET is_active = ? WHERE id = ?', [$s['is_active'] ? 0 : 1, $s['id']]); flash($s['is_active'] ? 'Schedule paused.' : 'Schedule resumed.'); }
    redirect('report_schedules.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'delete') {
    require_perm('report_schedules.delete');
    q('DELETE FROM report_schedules WHERE id = ?', [(int)post('id')]);
    flash('Schedule deleted.');
    redirect('report_schedules.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'test_send') {
    require_perm('report_schedules.edit');
    $s = row('SELECT * FROM report_schedules WHERE id = ?', [(int)post('id')]);
    if ($s) {
        $ok = send_whatsapp($s['recipient_mobile'], report_schedule_build_message($s));
        flash($ok ? 'Test message sent.' : 'Send failed. ' . whatsapp_last_error(), $ok ? 'success' : 'error');
    }
    redirect('report_schedules.php');
}

if (get('action') === 'new') {
    require_perm('report_schedules.add');
    $locations = all('SELECT id, name FROM locations WHERE is_active = 1 ORDER BY name');
    $page_title = 'New Scheduled Report';
    include __DIR__ . '/includes/header.php';
    ?>
    <div class="card">
      <h2>⏱️ New scheduled report</h2>
      <p class="muted mb">Sends a short WhatsApp text summary automatically. Runs off the site's daily cron job (Settings &gt; Backup &amp; Updates has the "Test Run" trigger for that) - so it fires once the hosting cron actually calls <code>cron.php</code> for the day, not at an exact clock time.</p>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="save">
        <div class="form-row cols-2">
          <div><label>Name *</label><input type="text" name="name" required placeholder="e.g. Morning sales summary"></div>
          <div><label>Report *</label>
            <select name="report_key" required>
              <?php foreach ($reportChoices as $k => $lbl): ?><option value="<?= $k ?>"><?= e($lbl) ?></option><?php endforeach; ?>
            </select></div>
        </div>
        <div class="form-row cols-3">
          <div><label>Frequency</label>
            <select name="frequency" id="rs_freq" onchange="rsFreq()">
              <option value="daily">Daily</option>
              <option value="weekly">Weekly</option>
              <option value="monthly">Monthly</option>
            </select></div>
          <div id="rs_dow" style="display:none"><label>Day of week</label>
            <select name="day_of_week">
              <?php foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $i => $d): ?><option value="<?= $i ?>"><?= $d ?></option><?php endforeach; ?>
            </select></div>
          <div id="rs_dom" style="display:none"><label>Day of month</label><input type="number" name="day_of_month" min="1" max="28" value="1"></div>
        </div>
        <div class="form-row cols-2">
          <div><label>Location (optional, for branch-specific reports)</label>
            <select name="location_id"><option value="">All locations</option>
              <?php foreach ($locations as $l): ?><option value="<?= $l['id'] ?>"><?= e($l['name']) ?></option><?php endforeach; ?>
            </select></div>
          <div><label>WhatsApp number *</label><input type="tel" name="recipient_mobile" required placeholder="9876543210"></div>
        </div>
        <button class="btn" type="submit">Create Schedule</button>
        <a class="btn btn-muted" href="report_schedules.php">Cancel</a>
      </form>
    </div>
    <script>
      function rsFreq() {
        var f = document.getElementById('rs_freq').value;
        document.getElementById('rs_dow').style.display = f === 'weekly' ? '' : 'none';
        document.getElementById('rs_dom').style.display = f === 'monthly' ? '' : 'none';
      }
      rsFreq();
    </script>
    <?php
    include __DIR__ . '/includes/footer.php';
    exit;
}

$schedules = all('SELECT rs.*, l.name loc_name, s.name staff_name FROM report_schedules rs
                  LEFT JOIN locations l ON l.id = rs.location_id JOIN users s ON s.id = rs.created_by
                  ORDER BY rs.id DESC');
$page_title = 'Scheduled Reports';
include __DIR__ . '/includes/header.php';
?>
<div class="page-actions">
  <?php if (can('report_schedules.add')): ?><a class="btn" href="report_schedules.php?action=new">+ New Schedule</a><?php endif; ?>
</div>
<div class="table-wrap">
<table>
  <thead><tr><th>Name</th><th>Report</th><th>Frequency</th><th>Sends to</th><th>Location</th><th>Last run</th><th>Status</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($schedules as $s): ?>
    <tr>
      <td><strong><?= e($s['name']) ?></strong></td>
      <td><?= e($reportChoices[$s['report_key']] ?? $s['report_key']) ?></td>
      <td><?= ucfirst($s['frequency']) ?><?= $s['frequency'] === 'weekly' ? ' (' . ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'][(int)$s['day_of_week']] . ')' : '' ?><?= $s['frequency'] === 'monthly' ? ' (day ' . (int)$s['day_of_month'] . ')' : '' ?></td>
      <td><?= e($s['recipient_mobile']) ?></td>
      <td><?= e($s['loc_name'] ?: 'All') ?></td>
      <td><?= $s['last_run_at'] ? dmyt($s['last_run_at']) : 'Never' ?></td>
      <td><?= $s['is_active'] ? '<span class="badge badge-ok">active</span>' : '<span class="badge badge-bad">paused</span>' ?></td>
      <td style="white-space:nowrap">
        <?php if (can('report_schedules.edit')): ?>
        <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="do" value="test_send"><input type="hidden" name="id" value="<?= $s['id'] ?>">
          <button class="btn btn-sm btn-outline" type="submit">Send Now</button></form>
        <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="do" value="toggle"><input type="hidden" name="id" value="<?= $s['id'] ?>">
          <button class="btn btn-sm btn-outline" type="submit"><?= $s['is_active'] ? 'Pause' : 'Resume' ?></button></form>
        <?php endif; ?>
        <?php if (can('report_schedules.delete')): ?>
        <form method="post" style="display:inline" onsubmit="return confirm('Delete this schedule?')"><?= csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= $s['id'] ?>">
          <button class="btn btn-sm btn-danger" type="submit">✕</button></form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$schedules): ?><tr><td colspan="8" class="muted">No scheduled reports yet.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
