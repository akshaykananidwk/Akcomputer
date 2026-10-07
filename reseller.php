<?php
// A reseller's own page: the shops that signed up with their code, what
// those shops paid, and the commission earned, paid and still due. Logged in
// with the reseller code and the password the owner gave them. Owner's
// address only. Nothing here is a shop's data - only names, plans and dates.
require_once __DIR__ . '/includes/init.php';
if (tenant_active()) { http_response_code(404); exit('Not here.'); }

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'login') {
    $code = strtoupper(trim(post('code')));
    $key = 'reseller:' . ($_SERVER['REMOTE_ADDR'] ?? '') . ':' . $code;
    if (login_throttle_blocked($key)) $err = 'Too many tries. Please wait a few minutes.';
    else {
        $r = row('SELECT * FROM resellers WHERE code = ? AND is_active = 1', [$code]);
        if ($r && $r['password'] !== '' && password_verify((string)($_POST['password'] ?? ''), $r['password'])) {
            login_throttle_reset($key);
            session_regenerate_id(true);
            $_SESSION['reseller_id'] = (int)$r['id'];
            redirect('reseller.php');
        }
        login_throttle_hit($key);
        $err = 'Wrong code or password.';
    }
}
if (get('logout')) { unset($_SESSION['reseller_id']); redirect('reseller.php'); }

$me = !empty($_SESSION['reseller_id']) ? row('SELECT * FROM resellers WHERE id = ? AND is_active = 1', [(int)$_SESSION['reseller_id']]) : null;
$page_title = 'Reseller';
include __DIR__ . '/includes/header.php';
?>
<div style="max-width:720px;margin:20px auto;padding:0 16px">
<?php if (!$me): ?>
  <div class="login-box" style="margin-top:0">
    <div class="login-title">🤝 Reseller login</div>
    <div class="card">
      <?php if ($err): ?><div class="flash flash-error"><?= e($err) ?></div><?php endif; ?>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="login">
        <div class="field"><label>Your code</label><input type="text" name="code" required autocapitalize="characters"></div>
        <div class="field"><label>Password</label><input type="password" name="password" required></div>
        <button class="btn btn-block" type="submit">Log in</button></form>
    </div>
  </div>
<?php else:
  $shops = all("SELECT t.name, t.domain, t.status, t.created_at, t.trial_ends, t.paid_until, p.name plan
                FROM tenants t LEFT JOIN plans p ON p.id = t.plan_id WHERE t.reseller_id = ? ORDER BY t.id DESC", [$me['id']]);
  $earned = (float)val("SELECT COALESCE(SUM(ABS(commission)),0) FROM tenant_payments WHERE reseller_id = ? AND status = 'paid'", [$me['id']]);
  $due = (float)val("SELECT COALESCE(SUM(commission),0) FROM tenant_payments WHERE reseller_id = ? AND status = 'paid' AND commission > 0", [$me['id']]); ?>
  <div class="page-head"><h1>🤝 <?= e($me['name']) ?></h1><a class="btn btn-sm btn-outline" href="reseller.php?logout=1">Log out</a></div>
  <div class="kpi-row">
    <div class="kpi k-info"><div class="kpi-top">🏪 Your shops</div><div class="kpi-val"><?= count($shops) ?></div><div class="kpi-sub"><?= count(array_filter($shops, fn($s) => $s['status'] === 'active')) ?> paying</div></div>
    <div class="kpi k-ok"><div class="kpi-top">💰 Earned</div><div class="kpi-val">₹<?= money($earned) ?></div><div class="kpi-sub"><?= (float)$me['commission_pct'] ?>% of every payment</div></div>
    <div class="kpi k-warn"><div class="kpi-top">⏳ Still to be paid to you</div><div class="kpi-val">₹<?= money($due) ?></div><div class="kpi-sub">paid out by the owner</div></div>
  </div>
  <div class="card"><p style="margin:0 0 6px">Share this link with shop owners:</p>
    <input type="text" readonly value="<?= e(base_url('signup.php?ref=' . $me['code'])) ?>" onclick="this.select()"></div>
  <div class="pane"><div class="pane-head"><h3>Shops that joined with your code</h3></div><div class="pane-body tight">
  <table class="rowlist rl-scan"><thead><tr><th>Shop</th><th>Status</th><th>Plan</th><th>Till</th><th>Since</th></tr></thead><tbody>
  <?php foreach ($shops as $s): $till = $s['paid_until'] ?: $s['trial_ends']; ?>
    <tr><td class="rl-main"><?= e($s['name']) ?></td><td><span class="badge badge-<?= $s['status'] === 'active' ? 'ok' : ($s['status'] === 'trial' ? 'info' : 'warn') ?>"><?= e($s['status']) ?></span></td>
      <td><?= e($s['plan'] ?? '') ?></td><td><?= $till ? dmy($till) : '' ?></td><td><?= dmy($s['created_at']) ?></td></tr>
  <?php endforeach; if (!$shops): ?><tr><td class="rl-main muted">No shops yet — share your link.</td></tr><?php endif; ?>
  </tbody></table></div></div>
<?php endif; ?>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
