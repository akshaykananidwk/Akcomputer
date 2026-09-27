<?php
// ---------------------------------------------------------------------------
// One-time handover from the phone app's API token to a website session.
//
// The app holds a Bearer token; the website wants a cookie. When the owner
// taps a website-only screen inside the app, this turns the one into the
// other for exactly one page load, so nobody types a password twice.
//
// The whole security of it is in three places and they all live here, so
// there is nowhere else to get it wrong:
//
//   app_link_target_ok()  what a link is allowed to point at
//   app_link_make()       issuing  (hashed, two minutes, one use)
//   app_link_consume()    spending (atomic, so a stolen link cannot be
//                         replayed even by two requests at the same instant)
// ---------------------------------------------------------------------------

/** How long a handover key lives. The app opens it at once; anything longer
 *  is a key lying around in a log or a browser history. */
const APP_LINK_TTL = 120;

/**
 * Is this a page on THIS site?
 *
 * The target arrives from the app, so it is treated as hostile. Anything
 * with a scheme, a host, a backslash or a parent directory is refused
 * outright rather than cleaned up - a redirect that can be pointed at
 * another site is how a login page becomes a phishing page.
 *
 * Returns the safe target, or '' if it is not acceptable.
 */
function app_link_target_ok($target) {
    $target = trim((string)$target);
    if ($target === '') return 'index.php';
    if (strlen($target) > 255) return '';
    if (preg_match('~[\\\\]|^//|^[a-z][a-z0-9+.-]*:|\.\.~i', $target)) return '';
    // <file>.php with an optional query string, and nothing else
    if (!preg_match('~^([a-z0-9_]+\.php)(\?[A-Za-z0-9_\-=&%.,+:/]*)?$~', $target, $m)) return '';
    // ...and it must be a page that actually exists, so a link can never be
    // made to something the site does not serve
    if (!is_file(__DIR__ . '/../' . $m[1])) return '';
    return $target;
}

/** Issues a one-time key. Returns the nonce to put in the URL, or '' if the
 *  target was refused. */
function app_link_make($userId, $target, $device = null) {
    $target = app_link_target_ok($target);
    if ($target === '') return '';
    $nonce = bin2hex(random_bytes(32));
    q('INSERT INTO app_web_links (nonce_hash, user_id, target, device, expires_at) VALUES (?,?,?,?,?)',
      [hash('sha256', $nonce), (int)$userId, $target, $device,
       date('Y-m-d H:i:s', time() + APP_LINK_TTL)]);
    // yesterday's keys are of no use to anybody, including an attacker
    q('DELETE FROM app_web_links WHERE expires_at < DATE_SUB(NOW(), INTERVAL 1 DAY)');
    return $nonce;
}

/**
 * Spends a key. Returns ['user_id' => .., 'target' => ..] or null.
 *
 * The UPDATE is what claims it, not the SELECT: stamping used_at while it is
 * still NULL is a single atomic statement, so of two requests arriving with
 * the same key in the same millisecond exactly one can win.
 */
function app_link_consume($nonce) {
    $nonce = (string)$nonce;
    if (!preg_match('/^[0-9a-f]{64}$/', $nonce)) return null;
    $hash = hash('sha256', $nonce);
    $claimed = q('UPDATE app_web_links SET used_at = NOW() WHERE nonce_hash = ? AND used_at IS NULL AND expires_at > NOW()', [$hash])->rowCount();
    if (!$claimed) return null;
    $row = row('SELECT user_id, target FROM app_web_links WHERE nonce_hash = ?', [$hash]);
    if (!$row) return null;
    // the user may have been switched off since the key was issued
    if (!val('SELECT id FROM users WHERE id = ? AND is_active = 1', [$row['user_id']])) return null;
    // re-checked at spending time, not trusted from the row: a page may have
    // been removed from the site since the key was made
    $target = app_link_target_ok($row['target']);
    if ($target === '') return null;
    return ['user_id' => (int)$row['user_id'], 'target' => $target];
}
