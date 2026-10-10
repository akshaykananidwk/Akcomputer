<?php
require_once __DIR__ . '/includes/init.php';

// Arriving from inside the phone app, carrying a one-time key instead of a
// password. The key does the whole job: it names the user and the page, it
// works once, and it dies two minutes after it was made. A wrong, spent or
// stale key simply falls through to the ordinary login form below - it is
// never an invitation to guess again.
if (($_GET['app'] ?? '') !== '') {
    $handover = app_link_consume($_GET['app']);
    if ($handover) {
        establish_session($handover['user_id']);
        log_activity('app_open', 'Opened ' . $handover['target'] . ' from the phone app');
        redirect($handover['target']);
    }
}

if (current_user()) redirect('index.php');

$err = '';
$need_otp = false;
$need_totp = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (post('step') === 'otp') {
        // second step: verify login OTP (WhatsApp) or TOTP/backup code,
        // whichever this user's account uses (set in the password step below)
        $uid = (int)($_SESSION['pending_login'] ?? 0);
        $method = $_SESSION['pending_login_method'] ?? 'whatsapp';
        $u = $uid ? row('SELECT * FROM users WHERE id = ?', [$uid]) : null;
        $throttleKey = 'login2fa:' . client_ip() . ':' . $uid;
        $ok = false;
        if ($u && login_throttle_blocked($throttleKey)) {
            $err = 'Too many failed attempts. Try again in a few minutes.';
        } elseif ($u && $method === 'totp') {
            $secret = totp_secret_decrypt($u['totp_secret_enc']);
            $ok = $secret && (totp_verify($secret, post('otp')) || totp_verify_backup_code($uid, post('otp')));
        } elseif ($u) {
            $ok = verify_otp('login', 'user:' . $uid, post('otp'));
        }
        if ($ok) {
            unset($_SESSION['pending_login'], $_SESSION['pending_login_method']);
            login_throttle_reset($throttleKey);
            establish_session($uid);
            record_login_history($uid, $u['username'] ?? '', true, $method);
            log_activity('login', $method === 'totp' ? 'TOTP login' : 'OTP login');
            redirect('index.php');
        }
        if (!$err) {
            login_throttle_hit($throttleKey);
            record_login_history($uid ?: null, $u['username'] ?? '', false, $method . '_wrong_code');
            $err = 'Wrong or expired code. Try again.';
        }
        $need_otp = $method === 'whatsapp';
        $need_totp = $method === 'totp';
    } else {
        $username = post('username');
        $throttleKey = 'login:' . client_ip() . ':' . mb_strtolower($username);
        if (login_throttle_blocked($throttleKey)) {
            $err = 'Too many failed attempts. Try again in a few minutes.';
            record_login_history(null, $username, false, 'throttled');
        } else {
            $user = login_find_user($username);   // username, or the e-mail on the account
            if ($user && password_verify(post('password'), $user['password'])) {
                if (!ip_allowed_for($user, client_ip())) {
                    $err = 'Login is not allowed from this network. Contact an admin.';
                    record_login_history($user['id'], $username, false, 'ip_blocked');
                } else {
                    // credentials are correct from here on, regardless of
                    // whether a second factor is still needed
                    login_throttle_reset($throttleKey);
                    if ($user['totp_enabled']) {
                        $_SESSION['pending_login'] = $user['id'];
                        $_SESSION['pending_login_method'] = 'totp';
                        $need_totp = true;
                    } elseif (setting('login_otp', '0') === '1' && $user['mobile']) {
                        $code = create_otp('login', 'user:' . $user['id']);
                        if (send_otp_whatsapp($user['mobile'], $code, 'login')) {
                            $_SESSION['pending_login'] = $user['id'];
                            $_SESSION['pending_login_method'] = 'whatsapp';
                            $need_otp = true;
                        } else {
                            // surfacing the real reason beats a silent OTP box
                            $err = 'The OTP could not be sent on WhatsApp. (' . whatsapp_last_error() . ')';
                        }
                    } else {
                        establish_session($user['id']);
                        record_login_history($user['id'], $username, true, 'password');
                        log_activity('login', 'Password login');
                        redirect('index.php');
                    }
                }
            } else {
                login_throttle_hit($throttleKey);
                record_login_history($user['id'] ?? null, $username, false, 'bad_password');
                $err = 'Invalid username or password.';
            }
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
    <?php if ($need_totp): ?>
      <p class="muted mb">Enter the 6-digit code from your authenticator app (or one of your backup codes).</p>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="step" value="otp">
        <div class="field"><label>Authenticator code</label><input type="text" name="otp" inputmode="numeric" maxlength="10" required autofocus></div>
        <button class="btn btn-block" type="submit">Verify & Login</button>
      </form>
    <?php elseif ($need_otp): ?>
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
        <div class="field"><label>Username or e-mail</label><input type="text" name="username" required autofocus autocapitalize="none" autocomplete="username"></div>
        <div class="field"><label>Password</label><input type="password" name="password" required></div>
        <button class="btn btn-block" type="submit">Login</button>
      </form>
      <button type="button" class="btn btn-block btn-outline mt" id="pkLogin" style="display:none">👆 Log in with fingerprint / face</button>
      <div id="pkMsg" class="muted" style="text-align:center;font-size:13px"></div>
      <p class="mt" style="text-align:center"><a href="forgot_password.php">Forgot password?</a></p>
      <script src="assets/passkey.js?v=<?= asset_v('passkey.js') ?>"></script>
      <script>
      (function () {
        var b = document.getElementById('pkLogin'); if (!Passkey.supported()) return; b.style.display = '';
        b.addEventListener('click', function () {
          var u = document.querySelector('input[name=username]').value.trim(), m = document.getElementById('pkMsg');
          if (!u) { m.textContent = 'Type your username first.'; return; }
          m.textContent = '…';
          Passkey.login(u, <?= json_encode(csrf_token()) ?>).then(function (r) { if (r.ok) location.href = r.go; else m.textContent = r.msg; })
            .catch(function (e) { m.textContent = e.message || 'Cancelled.'; });
        });
      })();
      </script>
    <?php endif; ?>
  </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
