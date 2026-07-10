<?php
// Forgot password via WhatsApp OTP
require_once __DIR__ . '/includes/init.php';
if (current_user()) redirect('index.php');

$step = 'ask';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (post('step') === 'ask') {
        $user = row('SELECT * FROM users WHERE username = ? AND is_active = 1', [post('username')]);
        if ($user && $user['mobile']) {
            $code = create_otp('forgot', 'user:' . $user['id']);
            send_otp_whatsapp($user['mobile'], $code, 'password reset');
            $_SESSION['forgot_user'] = $user['id'];
            $step = 'reset';
        } else {
            $err = 'User not found or no WhatsApp mobile registered. Contact admin.';
        }
    } elseif (post('step') === 'reset') {
        $uid = (int)($_SESSION['forgot_user'] ?? 0);
        $step = 'reset';
        if (!$uid) { $err = 'Session expired, start again.'; $step = 'ask'; }
        elseif (strlen(post('password')) < 4) { $err = 'Password too short (min 4 chars).'; }
        elseif (!verify_otp('forgot', 'user:' . $uid, post('otp'))) { $err = 'Wrong or expired OTP.'; }
        else {
            q('UPDATE users SET password = ? WHERE id = ?', [password_hash(post('password'), PASSWORD_DEFAULT), $uid]);
            unset($_SESSION['forgot_user']);
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
    <?php if ($step === 'ask'): ?>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="step" value="ask">
        <div class="field"><label>Username</label><input type="text" name="username" required autofocus autocapitalize="none"></div>
        <button class="btn btn-block" type="submit">Send OTP on WhatsApp</button>
      </form>
    <?php else: ?>
      <p class="muted mb">OTP sent to your registered WhatsApp number.</p>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="step" value="reset">
        <div class="field"><label>OTP</label><input type="text" name="otp" inputmode="numeric" maxlength="6" required autofocus></div>
        <div class="field"><label>New Password</label><input type="password" name="password" required></div>
        <button class="btn btn-block" type="submit">Change Password</button>
      </form>
    <?php endif; ?>
    <p class="mt" style="text-align:center"><a href="login.php">← Back to login</a></p>
  </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
