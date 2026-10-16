<?php
// 🏖️ Leave.
// Rules OFF (Team → Attendance rules not switched on): a person asks, the owner
// says yes or no, as it always was.
// Rules ON: a person takes leave themselves -
//   · PLANNED leave must start at least N days ahead (on the 17th, the 19th);
//     it is approved at once, without pay (the owner can make it paid).
//   · EMERGENCY leave can be for today, tomorrow or the last few days; with a
//     doctor's / hospital certificate it carries no penalty. Without one the
//     day counts as absent and the absence penalty applies. The owner can
//     refuse a certificate.
require_once __DIR__ . '/includes/init.php';
require_login();
$u = current_user();
$rules = hr_rules();
$ruled = $rules['from'] !== '';
$certDir = function () { $d = up_dir('leave_certs'); if (!is_file("$d/.htaccess")) @file_put_contents("$d/.htaccess", "Require all denied\n"); return $d; };
$saveCert = function () use ($certDir) {
    $f = $_FILES['certificate'] ?? null;
    if (!$f || empty($f['tmp_name']) || $f['error'] !== UPLOAD_ERR_OK || $f['size'] > 8 * 1024 * 1024) return null;
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'pdf'], true)) return null;
    if ($ext !== 'pdf' && !@getimagesize($f['tmp_name'])) return null;
    if ($ext === 'pdf' && file_get_contents($f['tmp_name'], false, null, 0, 5) !== '%PDF-') return null;
    $name = 'cert_' . bin2hex(random_bytes(8)) . '.' . $ext;
    return move_uploaded_file($f['tmp_name'], $certDir() . '/' . $name) ? $name : null;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'ask') {
    $f = post('from_date'); $t = post('to_date') ?: $f;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $f) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $t) || $t < $f) { flash('Pick the dates again.', 'error'); redirect('leaves.php'); }
    $kind = $ruled && post('kind') === 'emergency' ? 'emergency' : 'planned';
    $status = 'pending'; $paid = post('paid') === '0' ? 0 : 1; $cert = null;
    if ($ruled) {
        $paid = 0;
        if ($kind === 'planned') {
            if ($f < hr_leave_earliest()) {
                flash('Planned leave can start on ' . dmy(hr_leave_earliest()) . ' or later (' . $rules['notice_days'] . ' days ahead). For sudden illness or an emergency choose "Emergency" and upload the doctor\'s certificate.', 'error');
                redirect('leaves.php');
            }
            $status = 'approved';
        } else {
            if ($f < date('Y-m-d', strtotime(today() . ' -7 days')) || $t > date('Y-m-d', strtotime(today() . ' +3 days'))) {
                flash('Emergency leave is for the last 7 days up to 3 days ahead. Further ahead, take planned leave.', 'error'); redirect('leaves.php');
            }
            $cert = $saveCert();
            $status = $cert ? 'approved' : 'pending';
        }
    }
    q('INSERT INTO leave_requests (user_id, from_date, to_date, paid, reason, kind, certificate, status) VALUES (?,?,?,?,?,?,?,?)',
      [$u['id'], $f, $t, $paid, mb_substr(trim((string)post('reason')), 0, 255), $kind, $cert, $status]);
    owner_alert('🏖️ ' . ($kind === 'emergency' ? 'Emergency leave' : 'Leave') . ': ' . $u['name'] . ' · ' . dmy($f) . ($t !== $f ? ' to ' . dmy($t) : '')
                . ($kind === 'emergency' ? ($cert ? ' (certificate uploaded)' : ' (NO certificate yet)') : '') . (post('reason') ? ' — ' . mb_substr(post('reason'), 0, 80) : ''));
    flash(!$ruled ? 'Sent to the owner.'
        : ($kind === 'planned' ? 'Leave taken: ' . dmy($f) . ($t !== $f ? ' to ' . dmy($t) : '') . '.'
        : ($cert ? 'Emergency leave saved with the certificate — no penalty.' : 'Emergency leave saved. Upload the doctor\'s certificate, or the days count as absent with a penalty.')));
    redirect('leaves.php');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'cert') {
    $l = row("SELECT * FROM leave_requests WHERE id = ? AND user_id = ? AND kind = 'emergency' AND status IN ('pending','approved')", [(int)post('id'), $u['id']]);
    $cert = $l ? $saveCert() : null;
    if (!$cert) { flash('Choose a photo or PDF of the certificate (up to 8 MB).', 'error'); redirect('leaves.php'); }
    q("UPDATE leave_requests SET certificate = ?, status = 'approved' WHERE id = ?", [$cert, $l['id']]);
    owner_alert('📄 Certificate uploaded: ' . $u['name'] . ' · leave ' . dmy($l['from_date']));
    flash('Certificate uploaded — no penalty for this leave.');
    redirect('leaves.php');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(post('do'), ['approved', 'rejected'], true)) {
    require_perm('hr.edit');
    // a refused certificate is a refused leave: the days count as absent again
    q("UPDATE leave_requests SET status = ?, decided_by = ?, decided_at = NOW() WHERE id = ? AND (status = 'pending' OR (kind = 'emergency' AND ? = 'rejected'))",
      [post('do'), $u['id'], (int)post('id'), post('do')]);
    log_activity('leave_' . post('do'), '#' . (int)post('id'));
    redirect('leaves.php');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'paid') {
    require_perm('hr.edit');
    q('UPDATE leave_requests SET paid = 1 - paid WHERE id = ?', [(int)post('id')]);
    log_activity('leave_paid_toggle', '#' . (int)post('id'));
    redirect('leaves.php');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'withdraw') {
    // a leave not yet begun can be taken back by its owner
    q("DELETE FROM leave_requests WHERE id = ? AND user_id = ? AND (status = 'pending' OR from_date > ?)", [(int)post('id'), $u['id'], today()]);
    redirect('leaves.php');
}
// a certificate is shown only to its owner and to whoever may see everyone
if ((int)get('cert')) {
    $l = row('SELECT user_id, certificate FROM leave_requests WHERE id = ?', [(int)get('cert')]);
    if (!$l || !$l['certificate'] || ((int)$l['user_id'] !== (int)$u['id'] && !can('hr.view'))) { http_response_code(404); exit; }
    $file = $certDir() . '/' . basename($l['certificate']);
    header('Content-Type: ' . (str_ends_with($file, '.pdf') ? 'application/pdf' : 'image/' . (str_ends_with($file, '.png') ? 'png' : (str_ends_with($file, '.webp') ? 'webp' : 'jpeg'))));
    header('Cache-Control: private, no-store'); header('X-Content-Type-Options: nosniff');
    readfile($file); exit;
}

$all = can('hr.view');
$rows = all('SELECT l.*, us.name FROM leave_requests l JOIN users us ON us.id = l.user_id ' . ($all ? '' : 'WHERE l.user_id = ' . (int)$u['id']) .
            " ORDER BY (l.status = 'pending') DESC, l.from_date DESC LIMIT 200");
$page_title = 'Leave';
include __DIR__ . '/includes/header.php';
$emMin = date('Y-m-d', strtotime(today() . ' -7 days')); $emMax = date('Y-m-d', strtotime(today() . ' +3 days'));
?>
<?= hr_tabs('leave') ?>
<form method="post" enctype="multipart/form-data" class="hr-card" style="display:grid;gap:12px"><?= csrf_field() ?><input type="hidden" name="do" value="ask">
  <h3 style="margin:0"><span><?= icon('calendar', 18) ?> <?= $ruled ? 'Take leave' : 'Ask for leave' ?></span></h3>
  <?php if ($ruled): ?>
  <div class="hr-seg" role="radiogroup" aria-label="Kind of leave">
    <label><input type="radio" name="kind" value="planned" checked onchange="lvKind()"><span class="box"><b>📅 Planned</b><span>From <?= dmy(hr_leave_earliest()) ?> onwards · no certificate</span></span></label>
    <label><input type="radio" name="kind" value="emergency" onchange="lvKind()"><span class="box"><b>🚑 Emergency</b><span>Illness / hospital · certificate = no penalty</span></span></label>
  </div>
  <?php endif; ?>
  <div class="hr-form">
    <div><label for="lvFrom">From</label><input type="date" name="from_date" id="lvFrom" required <?= $ruled ? 'min="' . e(hr_leave_earliest()) . '"' : '' ?>></div>
    <div><label for="lvTo">To</label><input type="date" name="to_date" id="lvTo"></div>
    <?php if (!$ruled): ?><div><label>Type</label><select name="paid"><option value="1">Paid leave</option><option value="0">Without pay</option></select></div><?php endif; ?>
    <div style="grid-column:1/-1"><label for="lvWhy">Reason</label><input type="text" name="reason" id="lvWhy" maxlength="255" placeholder="e.g. family function"></div>
    <?php if ($ruled): ?><div id="lvCert" hidden style="grid-column:1/-1"><label>Doctor / hospital certificate (photo or PDF)</label><input type="file" name="certificate" accept="image/*,application/pdf"></div><?php endif; ?>
  </div>
  <button class="btn btn-block" type="submit" style="min-height:48px;font-size:16px"><?= $ruled ? 'Take leave' : 'Send to the owner' ?></button>
  <?php if ($ruled): ?><div class="muted" style="font-size:12.5px">Planned leave is without pay and starts <?= (int)$rules['notice_days'] ?> days ahead at the earliest. Away without leave or a certificate: <?= +$rules['absent_hours'] ?> hours' penalty a day.</div><?php endif; ?>
</form>
<?php if ($ruled): ?>
<script>
function lvKind() {
  var em = document.querySelector('input[name=kind][value=emergency]').checked, f = document.getElementById('lvFrom');
  document.getElementById('lvCert').hidden = !em;
  f.min = em ? '<?= e($emMin) ?>' : '<?= e(hr_leave_earliest()) ?>'; f.max = em ? '<?= e($emMax) ?>' : '';
}
</script>
<?php endif; ?>

<div class="hr-card">
  <h3><span><?= icon('book', 18) ?> <?= $all ? 'Everyone\'s leave' : 'My leave' ?></span></h3>
  <div class="hr-list">
  <?php foreach ($rows as $l): $em = $l['kind'] === 'emergency'; $mine = (int)$l['user_id'] === (int)$u['id']; ?>
    <div class="hr-row">
      <div class="hr-date"><b><?= (int)substr($l['from_date'], 8) ?></b><span><?= date('M', strtotime($l['from_date'])) ?></span></div>
      <div class="hr-main">
        <div class="t"><?= $all ? e($l['name']) . ' · ' : '' ?><?= $em ? '🚑 Emergency' : '📅 Planned' ?><?= $l['to_date'] !== $l['from_date'] ? ' · till ' . dmy($l['to_date']) : '' ?></div>
        <div class="s"><?= $l['paid'] ? 'Paid' : 'Without pay' ?><?= $l['reason'] !== '' ? ' · ' . e($l['reason']) : '' ?>
          <?= $l['certificate'] ? ' · <a href="leaves.php?cert=' . (int)$l['id'] . '" target="_blank">📄 certificate</a>' : ($em ? ' · <b style="color:var(--bad)">no certificate</b>' : '') ?></div>
        <div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:6px">
          <form method="post" style="display:contents"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
          <?php if (can('hr.edit')): ?>
            <?php if ($l['status'] === 'pending'): ?><button class="hr-mini" name="do" value="approved">✔ Yes</button><?php endif; ?>
            <?php if ($l['status'] === 'pending' || ($em && $l['status'] === 'approved')): ?><button class="hr-mini" name="do" value="rejected" style="color:var(--bad)" onclick="return confirm('Refuse this leave? Its days count as absent, with the penalty.')">✕ <?= $em && $l['certificate'] ? 'Refuse certificate' : 'No' ?></button><?php endif; ?>
            <?php if ($l['status'] === 'approved'): ?><button class="hr-mini" name="do" value="paid"><?= $l['paid'] ? 'Make without pay' : 'Make paid' ?></button><?php endif; ?>
          <?php endif; ?>
          <?php if ($mine && !($em && !$l['certificate']) && ($l['status'] === 'pending' || $l['from_date'] > today())): ?><button class="hr-mini" name="do" value="withdraw">Withdraw</button><?php endif; ?>
          </form>
          <?php if ($mine && $em && !$l['certificate'] && $l['status'] === 'pending'): ?>
          <form method="post" enctype="multipart/form-data" style="display:contents"><?= csrf_field() ?><input type="hidden" name="do" value="cert"><input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
            <label class="hr-mini" style="background:var(--primary);color:#fff;border-color:var(--primary)">📄 Upload certificate<input type="file" name="certificate" accept="image/*,application/pdf" hidden onchange="this.form.submit()"></label></form>
          <?php endif; ?>
        </div>
      </div>
      <div class="hr-end"><span class="hr-chip <?= ['pending' => 'warn', 'approved' => 'ok', 'rejected' => 'bad'][$l['status']] ?? '' ?>"><?= e(['pending' => 'waiting', 'approved' => 'approved', 'rejected' => 'refused'][$l['status']] ?? $l['status']) ?></span></div>
    </div>
  <?php endforeach; if (!$rows): ?><div class="hr-empty">No leave yet.</div><?php endif; ?></div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
