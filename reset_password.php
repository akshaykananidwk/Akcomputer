<?php
// Set a new password from the e-mailed link (forgot_password.php). The link
// works once, for 30 minutes; afterwards every old login is ended and the
// person is told by e-mail that the password changed.
require_once __DIR__ . '/includes/init.php';
$tok = (string)(get('t') ?: post('t'));
if (!api_rate_ok('reset:' . client_ip(), 20, 3600)) { http_response_code(429); exit('Too many tries. Please wait a while.'); }
$user = password_reset_user($tok);
$err = '';
if ($user && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $p = (string)($_POST['password'] ?? '');
    if (($pe = password_policy_check($p)) !== '') $err = $pe;
    elseif ($p !== (string)($_POST['password2'] ?? '')) $err = 'The two passwords are not the same.';
    else {
        q('UPDATE users SET password = ?, password_changed_at = NOW() WHERE id = ?', [password_hash($p, PASSWORD_DEFAULT), $user['id']]);
        q('UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL', [$user['id']]);
        q('UPDATE user_sessions SET revoked = 1 WHERE user_id = ?', [$user['id']]);
        log_activity('user_password_reset', 'user #' . $user['id'] . ' via e-mail link');
        if (!empty($user['email'])) send_mail($user['email'], 'Your password was changed — ' . setting('app_name', 'Shop'), mail_html('Your password was changed',
            '<p>The password for login <b>' . e($user['username']) . '</b> was changed on ' . date('d-m-Y h:i A') . '.</p><p>If this was not you, tell the shop owner at once.</p>'));
        if (session_status() === PHP_SESSION_ACTIVE) session_regenerate_id(true);
        flash('Password changed. Log in with the new password.');
        redirect('login.php');
    }
}
$page_title = 'Set a new password';
include __DIR__ . '/includes/header.php';
?>
<div class="login-box">
  <div class="login-logo">🔑</div>
  <div class="login-title">Set a new password</div>
  <div class="card">
  <?php if (!$user): ?>
    <div class="flash flash-error">This link has expired or was already used. Ask for a new one.</div>
    <a class="btn btn-block" href="forgot_password.php">Ask again</a>
  <?php else: ?>
    <?php if ($err): ?><div class="flash flash-error"><?= e($err) ?></div><?php endif; ?>
    <p class="muted">Login: <b><?= e($user['username']) ?></b></p>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="t" value="<?= e($tok) ?>">
      <div class="field"><label>New password</label><input type="password" name="password" required autocomplete="new-password" autofocus></div>
      <div class="field"><label>Same password again</label><input type="password" name="password2" required autocomplete="new-password"></div>
      <button class="btn btn-block" type="submit">Save the new password</button></form>
  <?php endif; ?>
  </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
