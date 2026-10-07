<?php
// A shop's own page about the software it rents: its plan and how long it
// runs, what it has used this month, paying for the next month or year,
// asking the software's owner a question, taking all its data away, and
// closing the account. Only on another shop's address, only its admin.
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/platform_features.php';
if (!tenant_active()) redirect('platform.php');
require_login();
if (!is_full_admin()) { flash('Only the shop\'s admin can see the plan.', 'error'); redirect('index.php'); }

$t = prow('SELECT * FROM tenants WHERE id = ?', [tenant()['id']]);   // fresh, not the copy from the top of the request
$plans = pall('SELECT * FROM plans WHERE is_active = 1 ORDER BY sort_order, id');
$cur = prow('SELECT * FROM plans WHERE id = ?', [(int)$t['plan_id']]);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = post('do');
    if ($do === 'pay') {
        $pl = prow('SELECT * FROM plans WHERE id = ? AND is_active = 1', [(int)post('plan_id')]);
        $link = $pl ? platform_payment_link($t, $pl, (int)post('months')) : '';
        if ($link !== '') { log_activity('plan_pay_start', $pl['code'] . ' x' . (int)post('months')); header('Location: ' . $link); exit; }
        flash('Online payment is not available right now. Please contact us on WhatsApp and we will renew it for you.', 'error');
    }
    if ($do === 'ask' && trim(post('subject')) !== '') {
        if (!api_rate_ok('platform_ticket:' . $t['id'], 10, 86400)) flash('Too many questions today. Please try tomorrow.', 'error');
        else {
            pq('INSERT INTO platform_tickets (tenant_id, raised_by, subject, body) VALUES (?,?,?,?)',
               [$t['id'], current_user()['name'], mb_substr(post('subject'), 0, 200), mb_substr(post('body'), 0, 4000)]);
            flash('Sent. The answer will appear here.');
        }
    }
    if ($do === 'powered_by' && $cur && $cur['white_label']) {
        set_setting('hide_powered_by', post('hide') ? '1' : '0');
        flash('Saved.');
    }
    if ($do === 'export') {
        // everything the shop owns, in two forms: a database file another
        // install can load, and plain CSV sheets anyone can open
        $zipPath = up_dir('exports') . '/export_' . $t['slug'] . '_' . date('Ymd_His') . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
            $zip->addFromString('database.sql', db_backup_sql());
            foreach (['parties', 'items', 'sales', 'sale_items', 'purchases', 'purchase_items', 'payments', 'expenses', 'stock'] as $tbl) {
                $rows = all("SELECT * FROM `$tbl`");
                $fh = fopen('php://temp', 'r+');
                fwrite($fh, "\xEF\xBB\xBF");
                if ($rows) { fputcsv($fh, array_keys($rows[0])); foreach ($rows as $r) fputcsv($fh, $r); }
                rewind($fh); $zip->addFromString($tbl . '.csv', stream_get_contents($fh)); fclose($fh);
            }
            $zip->close();
            log_activity('shop_export', basename($zipPath));
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . basename($zipPath) . '"');
            header('Content-Length: ' . filesize($zipPath));
            readfile($zipPath); @unlink($zipPath);
            exit;
        }
        flash('The export could not be made. Please try again.', 'error');
    }
    if ($do === 'close') {
        if (strtolower(trim(post('confirm'))) !== strtolower($t['slug'])) flash('Type the shop\'s address name exactly to close it.', 'error');
        else {
            pq('UPDATE tenants SET close_requested_at = NOW() WHERE id = ?', [$t['id']]);
            log_activity('shop_close_request', $t['slug']);
            flash('Closing requested. The shop keeps working for 30 days - download your data before then. Ask us any time to undo this.');
        }
    }
    if ($do === 'undo_close') {
        pq('UPDATE tenants SET close_requested_at = NULL WHERE id = ?', [$t['id']]);
        flash('Closing cancelled.');
    }
    redirect('my_plan.php');
}

$ex = tenant_expiry($t);
$tickets = pall('SELECT * FROM platform_tickets WHERE tenant_id = ? ORDER BY id DESC LIMIT 20', [$t['id']]);
$pays = pall("SELECT p.*, pl.name plan FROM tenant_payments p LEFT JOIN plans pl ON pl.id = p.plan_id WHERE p.tenant_id = ? AND p.status = 'paid' ORDER BY p.id DESC LIMIT 12", [$t['id']]);
$features = platform_features();
$page_title = 'My plan';
include __DIR__ . '/includes/header.php';
?>
<div class="page-head"><h1>💳 My plan</h1></div>
<?php if (get('paid')): ?><div class="flash flash-success">Thank you! Your payment is being confirmed — this page shows the new date within a minute.</div><?php endif; ?>
<?php if ($ex['state'] !== 'ok' || $t['status'] === 'suspended'): ?>
<div class="flash flash-error"><strong><?= $t['status'] === 'suspended' || $ex['state'] === 'locked' ? 'Your plan has ended.' : 'Your plan ended on ' . dmy($ex['until']) . '.' ?></strong>
  <?= $ex['state'] === 'grace' ? 'The software keeps working for ' . ($ex['grace'] + $ex['days'] + 1) . ' more day(s). ' : '' ?>Renew below to keep using it.</div>
<?php elseif ($ex['days'] <= 5): ?>
<div class="flash flash-warn">Your <?= $t['paid_until'] ? 'plan' : 'free trial' ?> ends on <strong><?= dmy($ex['until']) ?></strong> (<?= $ex['days'] ?> day<?= $ex['days'] === 1 ? '' : 's' ?> left).</div>
<?php endif; ?>
<?php if ($t['close_requested_at']): ?>
<div class="flash flash-error">Closing was asked for on <?= dmyt($t['close_requested_at']) ?>. The shop and its data are removed 30 days after that.
  <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="do" value="undo_close"><button class="btn btn-sm" type="submit">Keep my shop</button></form></div>
<?php endif; ?>

<div class="kpi-row">
  <div class="kpi k-info"><div class="kpi-top">📦 Plan</div><div class="kpi-val"><?= e($cur['name'] ?? '—') ?></div><div class="kpi-sub"><?= $t['paid_until'] ? 'paid' : 'free trial' ?><?= $ex['until'] ? ' till ' . dmy($ex['until']) : '' ?></div></div>
  <?php foreach (['bills' => '🧾 Bills this month', 'users' => '👥 Staff logins', 'wa' => '💬 WhatsApp this month', 'locations' => '📍 Locations'] as $k => $label):
      $u = plan_usage($k); $pct = $u['max'] > 0 ? min(100, round($u['used'] * 100 / $u['max'])) : 0; ?>
  <div class="kpi <?= $u['max'] > 0 && $pct >= 90 ? 'k-bad' : 'k-ok' ?>"><div class="kpi-top"><?= $label ?></div>
    <div class="kpi-val"><?= $u['used'] ?><span style="font-size:13px;color:var(--muted)"> / <?= $u['max'] > 0 ? $u['max'] : '∞' ?></span></div>
    <div class="kpi-sub"><?= $u['max'] > 0 ? $pct . '% used' : 'no limit' ?></div></div>
  <?php endforeach; ?>
</div>

<div class="pane"><div class="pane-head"><h3>🔁 Renew or change plan</h3></div><div class="pane-body">
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px">
  <?php foreach ($plans as $pl): $f = json_decode((string)$pl['features'], true) ?: []; ?>
    <div style="border:1px solid <?= $pl['id'] == $t['plan_id'] ? 'var(--primary)' : 'var(--border)' ?>;border-radius:12px;padding:12px">
      <div style="font-weight:800;font-size:16px"><?= e($pl['name']) ?> <?= $pl['id'] == $t['plan_id'] ? '<span class="badge badge-info">yours</span>' : '' ?></div>
      <div style="font-size:20px;font-weight:800;margin:4px 0">₹<?= money($pl['price_month']) ?><span style="font-size:12px;color:var(--muted)">/month</span></div>
      <?php if ((float)$pl['price_year'] > 0): ?><div class="muted" style="font-size:12px">or ₹<?= money($pl['price_year']) ?> a year</div><?php endif; ?>
      <ul style="font-size:12.5px;padding-left:18px;margin:8px 0">
        <li><?= $pl['max_users'] ? (int)$pl['max_users'] . ' staff logins' : 'Any number of staff' ?></li>
        <li><?= $pl['max_bills_month'] ? (int)$pl['max_bills_month'] . ' bills a month' : 'Unlimited bills' ?></li>
        <?php if (in_array('*', $f, true)): ?><li>Everything in the software</li>
        <?php else: foreach ($f as $code): if (isset($features[$code])): ?><li><?= e($features[$code][0]) ?></li><?php endif; endforeach; endif; ?>
      </ul>
      <form method="post" style="display:flex;gap:6px;flex-wrap:wrap"><?= csrf_field() ?><input type="hidden" name="do" value="pay"><input type="hidden" name="plan_id" value="<?= $pl['id'] ?>">
        <button class="btn btn-sm" type="submit" name="months" value="1">Pay 1 month</button>
        <?php if ((float)$pl['price_year'] > 0): ?><button class="btn btn-sm btn-outline" type="submit" name="months" value="12">Pay 1 year</button><?php endif; ?>
      </form>
    </div>
  <?php endforeach; ?>
  </div>
  <?php if ($pays): ?><p class="muted mt" style="font-size:12.5px">Paid: <?php foreach ($pays as $p) echo e($p['plan']) . ' ₹' . money($p['amount']) . ' on ' . dmy($p['paid_at']) . ' · '; ?></p><?php endif; ?>
</div></div>

<div class="pane"><div class="pane-head"><h3>❓ Ask us</h3></div><div class="pane-body">
  <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="ask">
    <div class="field"><label>What is it about?</label><input type="text" name="subject" maxlength="200" required></div>
    <div class="field"><label>Details</label><textarea name="body" rows="3"></textarea></div>
    <button class="btn btn-sm" type="submit">Send</button></form>
  <?php foreach ($tickets as $k): ?>
    <div style="border-top:1px solid var(--border);margin-top:10px;padding-top:8px">
      <strong><?= e($k['subject']) ?></strong> <span class="badge badge-<?= $k['status'] === 'open' ? 'warn' : 'ok' ?>"><?= e($k['status']) ?></span>
      <span class="muted" style="font-size:12px"> · <?= dmyt($k['created_at']) ?></span>
      <?php if ($k['reply']): ?><p style="margin:4px 0 0;white-space:pre-wrap">💬 <?= e($k['reply']) ?></p><?php endif; ?>
    </div>
  <?php endforeach; ?>
</div></div>

<?php if ($cur && $cur['white_label']): ?>
<div class="pane"><div class="pane-head"><h3>🏷 Your own name only</h3></div><div class="pane-body">
  <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="powered_by">
    <label class="check-inline"><input type="checkbox" name="hide" value="1" <?= setting('hide_powered_by', '0') === '1' ? 'checked' : '' ?>> Hide the "Powered by" line on bills and pages</label>
    <button class="btn btn-sm" type="submit">Save</button></form>
</div></div>
<?php endif; ?>

<div class="pane"><div class="pane-head"><h3>📦 Your data</h3></div><div class="pane-body">
  <p class="muted" style="margin-top:0">Your data is yours. Download all of it any time: a database file and Excel-ready CSV sheets of parties, items, bills, purchases, payments, expenses and stock.</p>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="export"><button class="btn btn-sm btn-outline" type="submit">⬇ Download all my data</button></form>
  <?php if (!$t['close_requested_at']): ?>
  <details class="mt"><summary class="muted">Close my shop's account</summary>
    <form method="post" class="mt" onsubmit="return confirm('Close this shop? Everything is removed 30 days from now.')"><?= csrf_field() ?><input type="hidden" name="do" value="close">
      <p class="muted" style="font-size:12.5px">The shop keeps working for 30 days, then the shop and all its data are removed for good. Type <strong><?= e($t['slug']) ?></strong> to confirm.</p>
      <input name="confirm" autocomplete="off" style="max-width:220px"> <button class="btn btn-sm btn-danger" type="submit">Close account</button></form>
  </details>
  <?php endif; ?>
</div></div>
<?php include __DIR__ . '/includes/footer.php'; ?>
