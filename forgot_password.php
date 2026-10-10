<?php
// Forgot password: a reset LINK by e-mail (when the login has an e-mail), or
// a 6-digit OTP on WhatsApp (when it has a mobile). Both are rate-limited.
// For the e-mail way the answer is the same whether the account exists or
// not, so nobody can use this page to find out who has an account.
require_once __DIR__ . '/includes/init.php';
if (current_user()) redirect('index.php');

$step = 'ask';
$err = ''; $info = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (in_array(post('step'), ['email', 'ask'], true)) {
        $typed = trim((string)post('username'));
        $throttleKey = 'forgot:' . client_ip() . ':' . mb_strtolower($typed);
        if (login_throttle_blocked($throttleKey) || !api_rate_ok('forgot-ip:' . client_ip(), 10, 3600)) {
            $err = 'Too many requests. Try again in a few minutes.';
        } else {
            login_throttle_hit($throttleKey);
            $user = login_find_user($typed);
            if (post('step') === 'email') {
                if ($user && !empty($user['email'])) {
                    $link = password_reset_link($user['id']);
                    $ok = send_mail($user['email'], 'Reset your password — ' . setting('app_name', 'Shop'), mail_html('Reset your password',
                        '<p>Hello ' . e($user['name']) . ',</p><p>Someone (we hope you) asked to reset the password for login <b>' . e($user['username']) . '</b>.</p>'
                        . '<p><a href="' . e($link) . '" style="display:inline-block;background:#1a56db;color:#fff;padding:11px 20px;border-radius:8px;text-decoration:none">Set a new password</a></p>'
                        . '<p style="font-size:13px;color:#64748b">This link works for 30 minutes, once. If you did not ask for it, ignore this e-mail — your password stays as it is.</p>'));
                    log_activity('password_reset_email', 'user #' . $user['id'] . ($ok ? '' : ' FAILED: ' . mail_last_error()));
                    if (!$ok) error_log('reset e-mail failed: ' . mail_last_error());
                }
                $info = 'If that login has an e-mail address, a link to set a new password has been sent to it. Check the inbox (and spam) — the link works for 30 minutes.';
            } elseif ($user && $user['mobile']) {
                $code = create_otp('forgot', 'user:' . $user['id']);
                // If the send FAILS, say so - this used to show the OTP box
                // anyway, leaving the user staring at a code that never came.
                if (send_otp_whatsapp($user['mobile'], $code, 'password reset')) {
                    $_SESSION['forgot_user'] = $user['id'];
                    $step = 'reset';
                } else {
                    $err = 'The OTP could not be sent on WhatsApp. (' . whatsapp_last_error() . ') Try the e-mail way, or contact the admin.';
                }
            } else {
                $err = 'User not found or no WhatsApp mobile registered. Try the e-mail way, or contact the admin.';
            }
        }
    } elseif (post('step') === 'reset') {
        $uid = (int)($_SESSION['forgot_user'] ?? 0);
        $step = 'reset';
        $pwdErr = password_policy_check(post('password'));
        if (!$uid) { $err = 'Session expired, start again.'; $step = 'ask'; }
        elseif ($pwdErr) { $err = $pwdErr; }
        elseif (!verify_otp('forgot', 'user:' . $uid, post('otp'))) { $err = 'Wrong or expired OTP.'; }
        else {
            q('UPDATE users SET password = ?, password_changed_at = NOW() WHERE id = ?', [password_hash(post('password'), PASSWORD_DEFAULT), $uid]);
            q('UPDATE user_sessions SET revoked = 1 WHERE user_id = ?', [$uid]);   // every old login ends with the old password
            unset($_SESSION['forgot_user']);
            log_activity('user_password_reset', "user #$uid via forgot-password OTP");
            flash('Password changed. Login now.');
            redirect('login.php');
        }
    }
}
$page_title = 'Forgot Password';
include __DIR__ . '/includes/header.php';
?>
<div class="login-box">
  <div class="login-logo">🔑</div>
  <div class="login-title">Reset Password</div>
  <div class="card">
    <?php if ($err): ?><div class="flash flash-error"><?= e($err) ?></div><?php endif; ?>
    <?php if ($info): ?><div class="flash flash-success"><?= e($info) ?></div><?php endif; ?>
    <?php if ($step === 'ask'): ?>
      <form method="post">
        <?= csrf_field() ?>
        <div class="field"><label>Username or e-mail</label><input type="text" name="username" required autofocus autocapitalize="none" autocomplete="username" value="<?= e(post('username')) ?>"></div>
        <button class="btn btn-block" type="submit" name="step" value="email">📧 Send a reset link by e-mail</button>
        <button class="btn btn-block btn-outline mt" type="submit" name="step" value="ask">💬 Send OTP on WhatsApp</button>
      </form>
    <?php else: ?>
      <p class="muted mb">OTP sent to your registered WhatsApp number.</p>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="step" value="reset">
        <div class="field"><label>OTP</label><input type="text" name="otp" inputmode="numeric" maxlength="6" required autofocus></div>
        <div class="field"><label>New Password</label><input type="password" name="password" required autocomplete="new-password"></div>
        <button class="btn btn-block" type="submit">Change Password</button>
      </form>
    <?php endif; ?>
    <p class="mt" style="text-align:center"><a href="login.php">← Back to login</a></p>
  </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
