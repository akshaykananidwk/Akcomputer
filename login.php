<?php
require_once __DIR__ . '/includes/init.php';
if (current_user()) redirect('index.php');

$err = '';
$need_otp = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (post('step') === 'otp') {
        // second step: verify login OTP
        $uid = (int)($_SESSION['pending_login'] ?? 0);
        if ($uid && verify_otp('login', 'user:' . $uid, post('otp'))) {
            $_SESSION['user_id'] = $uid;
            unset($_SESSION['pending_login']);
            log_activity('login', 'OTP login');
            redirect('index.php');
        }
        $err = 'Wrong or expired OTP. Try again.';
        $need_otp = true;
    } else {
        $user = row('SELECT * FROM users WHERE username = ? AND is_active = 1', [post('username')]);
        if ($user && password_verify(post('password'), $user['password'])) {
            if (setting('login_otp', '0') === '1' && $user['mobile']) {
                $code = create_otp('login', 'user:' . $user['id']);
                send_otp_whatsapp($user['mobile'], $code, 'login');
                $_SESSION['pending_login'] = $user['id'];
                $need_otp = true;
            } else {
                $_SESSION['user_id'] = $user['id'];
                log_activity('login', 'Password login');
                redirect('index.php');
            }
        } else {
            $err = 'Invalid username or password.';
        }
    }
}
$page_title = 'Login';
include __DIR__ . '/includes/header.php';
?>
<div class="login-box">
  <div class="login-logo">🖥️</div>
  <div class="login-title"><?= e(setting('app_name', 'AK Computer')) ?></div>
  <div class="card">
    <?php if ($err): ?><div class="flash flash-error"><?= e($err) ?></div><?php endif; ?>
    <?php if ($need_otp): ?>
      <p class="muted mb">OTP sent to your WhatsApp. Enter it below.</p>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="step" value="otp">
        <div class="field"><label>OTP</label><input type="text" name="otp" inputmode="numeric" maxlength="6" required autofocus></div>
        <button class="btn btn-block" type="submit">Verify & Login</button>
      </form>
    <?php else: ?>
      <form method="post">
        <?= csrf_field() ?>
        <div class="field"><label>Username</label><input type="text" name="username" required autofocus autocapitalize="none"></div>
        <div class="field"><label>Password</label><input type="password" name="password" required></div>
        <button class="btn btn-block" type="submit">Login</button>
      </form>
      <p class="mt" style="text-align:center"><a href="forgot_password.php">Forgot password?</a></p>
    <?php endif; ?>
  </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
