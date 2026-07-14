<?php
// Self-service account page: change password, set up/disable 2FA (TOTP),
// review your own login history, and see/revoke your own active sessions
// (device management) - the personal counterpart to the admin actions in
// users.php (force logout, disable 2FA for someone else).
require_once __DIR__ . '/includes/init.php';
require_login();
$u = current_user();
$tab = get('tab', 'overview');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'change_password') {
    if (!password_verify(post('current_password'), $u['password'])) {
        flash('Current password is incorrect.', 'error');
        redirect('my_account.php?tab=password');
    }
    $pwdErr = password_policy_check(post('new_password'));
    if ($pwdErr) { flash($pwdErr, 'error'); redirect('my_account.php?tab=password'); }
    if (post('new_password') !== post('confirm_password')) {
        flash('New password and confirmation do not match.', 'error');
        redirect('my_account.php?tab=password');
    }
    q('UPDATE users SET password = ?, password_changed_at = NOW() WHERE id = ?', [password_hash(post('new_password'), PASSWORD_DEFAULT), $u['id']]);
    log_activity('user_password_change');
    flash('Password updated.');
    redirect('my_account.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'setup_2fa_start') {
    $secret = totp_generate_secret();
    $_SESSION['pending_totp_secret'] = $secret;
    redirect('my_account.php?tab=2fa');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'setup_2fa_confirm') {
    $secret = $_SESSION['pending_totp_secret'] ?? '';
    if (!$secret || !totp_verify($secret, post('code'))) {
        flash('That code did not match. Make sure your authenticator app is showing the current code and try again.', 'error');
        redirect('my_account.php?tab=2fa');
    }
    q('UPDATE users SET totp_enabled = 1, totp_secret_enc = ? WHERE id = ?', [totp_secret_encrypt($secret), $u['id']]);
    unset($_SESSION['pending_totp_secret']);
    $codes = totp_generate_backup_codes($u['id']);
    $_SESSION['new_backup_codes'] = $codes;
    log_activity('user_2fa_enable');
    flash('Two-factor authentication is now on for your account. Save your backup codes below.');
    redirect('my_account.php?tab=2fa');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'disable_2fa_self') {
    if (!password_verify(post('current_password'), $u['password'])) {
        flash('Current password is incorrect.', 'error');
        redirect('my_account.php?tab=2fa');
    }
    q('UPDATE users SET totp_enabled = 0, totp_secret_enc = NULL WHERE id = ?', [$u['id']]);
    q('DELETE FROM totp_backup_codes WHERE user_id = ?', [$u['id']]);
    log_activity('user_2fa_disable');
    flash('Two-factor authentication turned off.');
    redirect('my_account.php?tab=2fa');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'revoke_session') {
    $sid = (int)post('id');
    $row = row('SELECT * FROM user_sessions WHERE id = ? AND user_id = ?', [$sid, $u['id']]);
    if ($row) {
        q('UPDATE user_sessions SET revoked = 1 WHERE id = ?', [$sid]);
        flash('That device has been signed out.');
    }
    redirect('my_account.php?tab=sessions');
}

$page_title = 'My Account';
include __DIR__ . '/includes/header.php';
?>
<div class="page-actions" style="flex-wrap:wrap">
  <a class="btn btn-sm <?= $tab === 'overview' ? '' : 'btn-outline' ?>" href="my_account.php?tab=overview">Overview</a>
  <a class="btn btn-sm <?= $tab === 'password' ? '' : 'btn-outline' ?>" href="my_account.php?tab=password">Change Password</a>
  <a class="btn btn-sm <?= $tab === '2fa' ? '' : 'btn-outline' ?>" href="my_account.php?tab=2fa">Two-Factor Auth</a>
  <a class="btn btn-sm <?= $tab === 'sessions' ? '' : 'btn-outline' ?>" href="my_account.php?tab=sessions">Active Sessions</a>
  <a class="btn btn-sm <?= $tab === 'history' ? '' : 'btn-outline' ?>" href="my_account.php?tab=history">Login History</a>
</div>

<?php if ($tab === 'overview'): ?>
<div class="card">
  <h2><?= e($u['name']) ?></h2>
  <p class="muted"><?= e($u['username']) ?> · <?= e($u['role_name']) ?> · <?= e($u['location_name']) ?></p>
  <p class="mt">Password last changed: <?= $u['password_changed_at'] ? dmyt($u['password_changed_at']) : 'never (still your original password)' ?></p>
  <p>Two-factor authentication: <?= $u['totp_enabled'] ? '<span class="badge badge-ok">on</span>' : '<span class="badge badge-bad">off</span>' ?></p>
</div>

<?php elseif ($tab === 'password'): ?>
<div class="card">
  <h2>🔑 Change password</h2>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="change_password">
    <div class="field"><label>Current password</label><input type="password" name="current_password" required></div>
    <div class="field"><label>New password</label><input type="password" name="new_password" required></div>
    <div class="field"><label>Confirm new password</label><input type="password" name="confirm_password" required></div>
    <p class="muted mb">Minimum <?= (int)setting('pwd_min_length', 8) ?> characters<?= setting('pwd_require_number', '1') === '1' ? ', at least one number' : '' ?><?= setting('pwd_require_mixed_case', '0') === '1' ? ', upper and lower case letters' : '' ?>.</p>
    <button class="btn" type="submit">Update Password</button>
  </form>
</div>

<?php elseif ($tab === '2fa'):
    $pendingSecret = $_SESSION['pending_totp_secret'] ?? '';
    $newBackupCodes = $_SESSION['new_backup_codes'] ?? null;
    unset($_SESSION['new_backup_codes']); ?>
<div class="card">
  <h2>🔐 Two-Factor Authentication</h2>
  <?php if ($newBackupCodes): ?>
  <div class="flash flash-info">
    <strong>Save these backup codes somewhere safe - each works once, and this is the only time they're shown:</strong>
    <div class="mt" style="font-family:monospace;font-size:16px;line-height:1.8"><?php foreach ($newBackupCodes as $c): ?><?= e($c) ?><br><?php endforeach; ?></div>
  </div>
  <?php endif; ?>
  <?php if ($u['totp_enabled']): ?>
    <p>Status: <span class="badge badge-ok">Enabled</span></p>
    <p class="muted mb">Your account requires a code from your authenticator app at login, in addition to your password.</p>
    <form method="post" onsubmit="return confirm('Turn off two-factor authentication?')">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="disable_2fa_self">
      <div class="field"><label>Confirm your password</label><input type="password" name="current_password" required style="max-width:300px"></div>
      <button class="btn btn-danger btn-sm" type="submit">Turn Off 2FA</button>
    </form>
  <?php elseif ($pendingSecret): ?>
    <p class="muted mb">Scan isn't available offline-safely here, so enter this manually in your authenticator app (Google Authenticator, Authy, etc.): <strong>Add account → Enter a setup key → Time-based</strong>.</p>
    <div class="field">
      <label>Account name</label>
      <div style="font-family:monospace"><?= e(setting('app_name', 'AK Computer')) ?>: <?= e($u['username']) ?></div>
    </div>
    <div class="field">
      <label>Secret key</label>
      <div style="font-family:monospace;font-size:20px;letter-spacing:2px;background:var(--card-alt,rgba(0,0,0,.04));padding:10px;border-radius:8px;display:inline-block"><?= e($pendingSecret) ?></div>
    </div>
    <form method="post" class="mt">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="setup_2fa_confirm">
      <div class="field"><label>Enter the 6-digit code from the app to confirm</label><input type="text" name="code" inputmode="numeric" maxlength="6" required style="max-width:200px"></div>
      <button class="btn" type="submit">Confirm & Enable</button>
    </form>
  <?php else: ?>
    <p>Status: <span class="badge badge-bad">Disabled</span></p>
    <p class="muted mb">Adds a second step at login (a 6-digit code from an authenticator app) on top of your password.</p>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="setup_2fa_start">
      <button class="btn" type="submit">Set Up Two-Factor Auth</button></form>
  <?php endif; ?>
</div>

<?php elseif ($tab === 'sessions'):
    $sessions = all('SELECT * FROM user_sessions WHERE user_id = ? AND revoked = 0 ORDER BY last_seen_at DESC', [$u['id']]);
    $curToken = session_id(); ?>
<div class="card">
  <h2>💻 Active Sessions</h2>
  <p class="muted mb">Every device currently signed in to your account. Revoked devices are signed out within about a minute.</p>
  <?php foreach ($sessions as $s): ?>
  <div class="list-row" style="cursor:default">
    <div class="list-row-main">
      <strong><?= e($s['ip_address']) ?></strong><?= $s['session_token'] === $curToken ? ' <span class="badge badge-ok">this device</span>' : '' ?>
      <div class="muted list-row-sub"><?= e(mb_substr($s['user_agent'], 0, 70)) ?></div>
      <div class="muted list-row-sub">Signed in <?= dmyt($s['created_at']) ?> · last active <?= dmyt($s['last_seen_at']) ?></div>
    </div>
    <div class="list-row-val">
      <?php if ($s['session_token'] !== $curToken): ?>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="revoke_session"><input type="hidden" name="id" value="<?= $s['id'] ?>">
        <button class="btn btn-sm btn-outline" type="submit">Sign out</button></form>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
  <?php if (!$sessions): ?><p class="muted">No active sessions found.</p><?php endif; ?>
</div>

<?php elseif ($tab === 'history'):
    $history = all('SELECT * FROM login_history WHERE user_id = ? ORDER BY id DESC LIMIT 100', [$u['id']]); ?>
<div class="card">
  <h2>🕓 Login History</h2>
  <div class="table-wrap">
  <table class="table-sm">
    <thead><tr><th>Date</th><th>Result</th><th>Method</th><th>IP</th><th>Device</th></tr></thead>
    <tbody>
    <?php foreach ($history as $h): ?>
    <tr>
      <td><?= dmyt($h['created_at']) ?></td>
      <td><?= $h['success'] ? '<span class="badge badge-ok">success</span>' : '<span class="badge badge-bad">failed</span>' ?></td>
      <td><?= e($h['reason']) ?></td>
      <td><?= e($h['ip_address']) ?></td>
      <td><?= e(mb_substr($h['user_agent'], 0, 50)) ?></td>
    </tr>
    <?php endforeach; ?>
    <?php if (!$history): ?><tr><td colspan="5" class="muted">No login history yet.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
