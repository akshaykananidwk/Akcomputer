<?php
// ☁️ The daily backup copied to the owner's own Google Drive. The owner
// makes a Google "OAuth client" once, pastes its id and secret in Settings →
// Backup and presses Connect; Google then gives a key that is kept
// encrypted. Only the ENCRYPTED backup is ever sent (a passphrase must be
// set), and only into a file this app created (scope drive.file).

function gdrive_http($url, array $post = null, array $headers = [], $raw = null) {
    if (isset($GLOBALS['gdrive_http_mock'])) return ($GLOBALS['gdrive_http_mock'])($url, $post, $headers, $raw);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120, CURLOPT_HTTPHEADER => $headers]);
    if ($raw !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, $raw); }
    elseif ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
    $body = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return [$code, json_decode((string)$body, true) ?: []];
}
function gdrive_redirect_uri() { return base_url('gdrive.php'); }
function gdrive_connected() { return (string)setting('gdrive_refresh_token', '') !== ''; }

function gdrive_auth_url() {
    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
        'client_id' => setting('gdrive_client_id', ''), 'redirect_uri' => gdrive_redirect_uri(), 'response_type' => 'code',
        'scope' => 'https://www.googleapis.com/auth/drive.file', 'access_type' => 'offline', 'prompt' => 'consent',
        'state' => portal_sig('gdrive', current_user()['id'] ?? 0)]);
}

/** Swap the one-time code from Google for the lasting key. Returns '' or what went wrong. */
function gdrive_connect($code) {
    [$st, $j] = gdrive_http('https://oauth2.googleapis.com/token', ['code' => $code, 'client_id' => setting('gdrive_client_id', ''),
        'client_secret' => setting('gdrive_client_secret', ''), 'redirect_uri' => gdrive_redirect_uri(), 'grant_type' => 'authorization_code']);
    if ($st !== 200 || empty($j['refresh_token'])) return 'Google did not give a key (' . ($j['error_description'] ?? $j['error'] ?? $st) . ').';
    set_setting('gdrive_refresh_token', $j['refresh_token']);
    return '';
}

function gdrive_access_token() {
    [$st, $j] = gdrive_http('https://oauth2.googleapis.com/token', ['client_id' => setting('gdrive_client_id', ''), 'client_secret' => setting('gdrive_client_secret', ''),
        'refresh_token' => setting('gdrive_refresh_token', ''), 'grant_type' => 'refresh_token']);
    return $st === 200 ? (string)($j['access_token'] ?? '') : '';
}

/** Copy one backup file to Drive. Returns [ok, message]. */
function gdrive_upload($file) {
    if (!gdrive_connected()) return [false, 'Google Drive is not connected.'];
    if (substr($file, -4) !== '.enc') return [false, 'Only an encrypted backup is sent — set a passphrase in Settings → Backup.'];
    $tok = gdrive_access_token();
    if ($tok === '') return [false, 'Google refused the key — press Connect again.'];
    $b = 'akb' . bin2hex(random_bytes(8));
    $meta = json_encode(['name' => basename($file), 'description' => setting('app_name', '') . ' daily backup (encrypted)']);
    $body = "--$b\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n$meta\r\n--$b\r\nContent-Type: application/octet-stream\r\n\r\n" . file_get_contents($file) . "\r\n--$b--";
    [$st, $j] = gdrive_http('https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&fields=id,name',
        null, ["Authorization: Bearer $tok", "Content-Type: multipart/related; boundary=$b"], $body);
    if ($st === 200 && !empty($j['id'])) { set_setting('gdrive_last', date('Y-m-d H:i') . ' ' . basename($file)); return [true, 'sent ' . basename($file)]; }
    return [false, 'Drive upload failed (' . ($j['error']['message'] ?? $st) . ').'];
}
