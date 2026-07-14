<?php
// Security hardening helpers: TOTP 2FA, login rate-limiting, IP allowlist,
// password policy, session establishment/revocation, auto-logout. Layered
// on top of the existing login flow (WhatsApp OTP, CSRF) - nothing here
// changes behaviour for a shop that leaves every setting at its default.

// ---------- Base32 (RFC 4648) - needed for TOTP, not in PHP core ----------
function base32_encode($data) {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($data) as $c) $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    $out = '';
    foreach (str_split($bits, 5) as $chunk) {
        if (strlen($chunk) < 5) $chunk = str_pad($chunk, 5, '0');
        $out .= $alphabet[bindec($chunk)];
    }
    return $out;
}
function base32_decode($b32) {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $b32 = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $b32));
    $bits = '';
    foreach (str_split($b32) as $c) {
        $pos = strpos($alphabet, $c);
        if ($pos === false) continue;
        $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
    }
    $bytes = '';
    foreach (str_split($bits, 8) as $byte) {
        if (strlen($byte) === 8) $bytes .= chr(bindec($byte));
    }
    return $bytes;
}

// ---------- TOTP (RFC 6238), hand-rolled - no composer/vendor in this project ----------
function totp_generate_secret($length = 20) {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $secret = '';
    for ($i = 0; $i < $length; $i++) $secret .= $alphabet[random_int(0, 31)];
    return $secret;
}
function totp_code($secretBase32, $timeSlice = null) {
    $key = base32_decode($secretBase32);
    $timeSlice = $timeSlice ?? (int)floor(time() / 30);
    $time = pack('N*', 0, $timeSlice); // 8-byte big-endian counter
    $hash = hash_hmac('sha1', $time, $key, true);
    $offset = ord(substr($hash, -1)) & 0x0F;
    $part = substr($hash, $offset, 4);
    $value = unpack('N', $part)[1] & 0x7FFFFFFF;
    return str_pad((string)($value % 1000000), 6, '0', STR_PAD_LEFT);
}
/** $window=1 accepts the previous/current/next 30s step (clock-drift tolerance). */
function totp_verify($secretBase32, $code, $window = 1) {
    $code = preg_replace('/\s+/', '', (string)$code);
    if (!preg_match('/^\d{6}$/', $code)) return false;
    $currentSlice = (int)floor(time() / 30);
    for ($i = -$window; $i <= $window; $i++) {
        if (hash_equals(totp_code($secretBase32, $currentSlice + $i), $code)) return true;
    }
    return false;
}
// Encrypted-at-rest storage for the per-user TOTP secret, same AES-256-CBC
// pattern already used for the site-credential vault (helpers.php), keyed
// with a distinct context string so the two never share a derived key.
function totp_secret_encrypt($secret) {
    $key = hash('sha256', 'totp_secret|' . APP_SECRET, true);
    $iv = random_bytes(16);
    $cipher = openssl_encrypt($secret, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    return $cipher === false ? '' : base64_encode($iv . $cipher);
}
function totp_secret_decrypt($enc) {
    if (!$enc) return '';
    $raw = base64_decode($enc, true);
    if ($raw === false || strlen($raw) < 17) return '';
    $key = hash('sha256', 'totp_secret|' . APP_SECRET, true);
    $plain = openssl_decrypt(substr($raw, 16), 'aes-256-cbc', $key, OPENSSL_RAW_DATA, substr($raw, 0, 16));
    return $plain === false ? '' : $plain;
}
function totp_generate_backup_codes($userId, $count = 8) {
    q('DELETE FROM totp_backup_codes WHERE user_id = ?', [$userId]);
    $codes = [];
    for ($i = 0; $i < $count; $i++) {
        $code = strtoupper(bin2hex(random_bytes(4))); // 8 hex chars, e.g. 3F9A1B2C
        $codes[] = $code;
        q('INSERT INTO totp_backup_codes (user_id, code_hash) VALUES (?, ?)', [$userId, password_hash($code, PASSWORD_DEFAULT)]);
    }
    return $codes;
}
function totp_verify_backup_code($userId, $code) {
    $code = strtoupper(trim($code));
    foreach (all('SELECT * FROM totp_backup_codes WHERE user_id = ? AND used = 0', [$userId]) as $bc) {
        if (password_verify($code, $bc['code_hash'])) {
            q('UPDATE totp_backup_codes SET used = 1 WHERE id = ?', [$bc['id']]);
            return true;
        }
    }
    return false;
}

// ---------- Client IP / user agent ----------
function client_ip() {
    return (string)($_SERVER['REMOTE_ADDR'] ?? '');
}
function client_user_agent() {
    return mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
}

// ---------- IP allowlist (empty = no restriction, the safe default) ----------
function ip_allowed($ip) {
    $list = trim(setting('ip_whitelist', ''));
    if ($list === '') return true;
    $entries = preg_split('/[\r\n,]+/', $list, -1, PREG_SPLIT_NO_EMPTY);
    foreach ($entries as $entry) {
        $entry = trim($entry);
        if ($entry === '') continue;
        if ($entry === $ip) return true;
        if (strpos($entry, '/') !== false && ip_in_cidr($ip, $entry)) return true;
    }
    return false;
}
function ip_in_cidr($ip, $cidr) {
    [$subnet, $bits] = array_pad(explode('/', $cidr), 2, null);
    if ($bits === null || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || !filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return false;
    $bits = (int)$bits;
    if ($bits < 0 || $bits > 32) return false;
    $ipLong = ip2long($ip);
    $subnetLong = ip2long($subnet);
    if ($ipLong === false || $subnetLong === false) return false;
    $mask = $bits === 0 ? 0 : (~0 << (32 - $bits));
    return ($ipLong & $mask) === ($subnetLong & $mask);
}

// ---------- Login rate-limiting (per bucket key, e.g. "ip:username") ----------
function login_throttle_blocked($key) {
    $max = (int)setting('login_max_attempts', 5);
    if ($max <= 0) return false; // disabled
    $lockMin = max(1, (int)setting('login_lockout_minutes', 15));
    $row = row('SELECT * FROM login_throttle WHERE bucket_key = ?', [$key]);
    if (!$row) return false;
    if (strtotime($row['window_start']) < time() - $lockMin * 60) return false; // window expired
    return (int)$row['attempt_count'] >= $max;
}
function login_throttle_hit($key) {
    $lockMin = max(1, (int)setting('login_lockout_minutes', 15));
    $row = row('SELECT * FROM login_throttle WHERE bucket_key = ?', [$key]);
    if ($row && strtotime($row['window_start']) >= time() - $lockMin * 60) {
        q('UPDATE login_throttle SET attempt_count = attempt_count + 1 WHERE bucket_key = ?', [$key]);
    } else {
        q('INSERT INTO login_throttle (bucket_key, attempt_count, window_start) VALUES (?,1,NOW())
           ON DUPLICATE KEY UPDATE attempt_count = 1, window_start = NOW()', [$key]);
    }
}
function login_throttle_reset($key) {
    q('DELETE FROM login_throttle WHERE bucket_key = ?', [$key]);
}

// ---------- Login history ----------
function record_login_history($userId, $usernameAttempted, $success, $reason = '') {
    q('INSERT INTO login_history (user_id, username_attempted, success, reason, ip_address, user_agent) VALUES (?,?,?,?,?,?)',
      [$userId, mb_substr($usernameAttempted, 0, 50), $success ? 1 : 0, $reason, client_ip(), client_user_agent()]);
}

// ---------- Password policy ----------
function password_policy_check($password) {
    $minLen = max(4, (int)setting('pwd_min_length', 8));
    if (mb_strlen($password) < $minLen) return "Password must be at least $minLen characters.";
    if (setting('pwd_require_number', '1') === '1' && !preg_match('/[0-9]/', $password)) return 'Password must include at least one number.';
    if (setting('pwd_require_mixed_case', '0') === '1' && !(preg_match('/[a-z]/', $password) && preg_match('/[A-Z]/', $password))) return 'Password must include both upper and lower case letters.';
    return '';
}

// ---------- Session establishment / revocation (active sessions & devices) ----------
/** Call right after a login (password, or password+OTP/TOTP) succeeds.
 *  Regenerates the session id (fixation protection) and records the new
 *  session as an active device the user can see/revoke later. */
function establish_session($userId) {
    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
    $_SESSION['last_activity'] = time();
    q('INSERT INTO user_sessions (user_id, session_token, ip_address, user_agent) VALUES (?,?,?,?)',
      [$userId, session_id(), client_ip(), client_user_agent()]);
    $_SESSION['session_row_id'] = insert_id();
}
/** Called once per request (from current_user()) for an already-logged-in
 *  session. Returns false if the session should be killed (idle timeout or
 *  remotely revoked from "Active Sessions"), true otherwise. */
function session_security_ok() {
    $timeoutMin = (int)setting('auto_logout_minutes', 0);
    if ($timeoutMin > 0 && !empty($_SESSION['last_activity']) && time() - $_SESSION['last_activity'] > $timeoutMin * 60) {
        return false;
    }
    $_SESSION['last_activity'] = time();
    if (!empty($_SESSION['session_row_id'])) {
        // throttle the last_seen_at write to once a minute - this runs on
        // every authenticated page load, no need to hit the DB every time
        if (empty($_SESSION['sess_last_touch']) || time() - $_SESSION['sess_last_touch'] > 60) {
            $row = row('SELECT revoked FROM user_sessions WHERE id = ?', [$_SESSION['session_row_id']]);
            if ($row && (int)$row['revoked'] === 1) return false;
            q('UPDATE user_sessions SET last_seen_at = NOW() WHERE id = ?', [$_SESSION['session_row_id']]);
            $_SESSION['sess_last_touch'] = time();
        }
    }
    return true;
}
