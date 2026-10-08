<?php
// 👆 Fingerprint / face: add a key to your account (logged in), and log in
// with it (from the login page). JSON in, JSON out. The challenge lives in
// the session and is used once.
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/webauthn.php';
header('Content-Type: application/json');
$do = (string)($_GET['do'] ?? post('do'));
$out = function ($a) { echo json_encode($a); exit; };

if ($do === 'reg_options') {
    require_login(); $u = current_user();
    $_SESSION['wa_chal'] = random_bytes(32);
    $out(['challenge' => wa_b64u($_SESSION['wa_chal']), 'rp' => ['id' => wa_rp_id(), 'name' => setting('app_name', 'Shop')],
          'user' => ['id' => wa_b64u('u' . $u['id']), 'name' => $u['username'], 'displayName' => $u['name']],
          'exclude' => array_column(all('SELECT cred_id FROM webauthn_creds WHERE user_id = ?', [$u['id']]), 'cred_id')]);
}
if ($do === 'reg_verify' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_login(); $u = current_user();
    $chal = $_SESSION['wa_chal'] ?? ''; unset($_SESSION['wa_chal']);
    try {
        if ($chal === '') throw new Exception('Start again.');
        $r = wa_register_verify(post('attestation'), post('client'), $chal);
        q('INSERT INTO webauthn_creds (user_id, cred_id, public_key, sign_count, name) VALUES (?,?,?,?,?)',
          [$u['id'], $r['cred_id'], $r['pem'], $r['count'], mb_substr(trim((string)post('name')) ?: 'This phone', 0, 80)]);
        log_activity('passkey_add', post('name'));
        $out(['ok' => true]);
    } catch (Exception $e) { $out(['ok' => false, 'msg' => $e instanceof PDOException ? plain_error($e) : $e->getMessage()]); }
}
if ($do === 'login_options') {
    if (!api_rate_ok('wa-login:' . client_ip(), 20, 600)) $out(['ok' => false, 'msg' => 'Too many tries. Wait a few minutes.']);
    $user = row('SELECT id FROM users WHERE username = ? AND is_active = 1', [trim((string)($_GET['u'] ?? ''))]);
    $ids = $user ? array_column(all('SELECT cred_id FROM webauthn_creds WHERE user_id = ?', [$user['id']]), 'cred_id') : [];
    if (!$ids) $out(['ok' => false, 'msg' => 'No fingerprint is set up for this login. Log in with the password, then add it in My Account.']);
    $_SESSION['wa_chal'] = random_bytes(32); $_SESSION['wa_user'] = (int)$user['id'];
    $out(['ok' => true, 'challenge' => wa_b64u($_SESSION['wa_chal']), 'rpId' => wa_rp_id(), 'allow' => $ids]);
}
if ($do === 'login_verify' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $chal = $_SESSION['wa_chal'] ?? ''; $uid = (int)($_SESSION['wa_user'] ?? 0); unset($_SESSION['wa_chal'], $_SESSION['wa_user']);
    try {
        $cred = row('SELECT c.*, us.username, us.role_id FROM webauthn_creds c JOIN users us ON us.id = c.user_id AND us.is_active = 1 WHERE c.cred_id = ? AND c.user_id = ?', [(string)post('id'), $uid]);
        if ($chal === '' || !$cred) throw new Exception('This key is not known. Log in with the password.');
        if (!ip_allowed_for($cred, client_ip())) throw new Exception('Login is not allowed from this network.');
        $count = wa_login_verify($cred, post('authData'), post('client'), post('signature'), $chal);
        q('UPDATE webauthn_creds SET sign_count = ?, last_used = NOW() WHERE id = ?', [$count, $cred['id']]);
        establish_session($cred['user_id']);
        record_login_history($cred['user_id'], $cred['username'], true, 'passkey');
        log_activity('login', 'Fingerprint / face login');
        $out(['ok' => true, 'go' => 'index.php']);
    } catch (Exception $e) {
        record_login_history($uid ?: null, '', false, 'passkey_failed');
        $out(['ok' => false, 'msg' => $e->getMessage()]);
    }
}
http_response_code(400); $out(['ok' => false]);
