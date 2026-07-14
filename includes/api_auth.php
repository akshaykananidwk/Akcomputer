<?php
// Bearer-token auth for api.php (also used by the mobile app) - a parallel
// path alongside the existing $_SESSION-based current_user()/can(), not a
// replacement for it. api.php resolves a user from an "Authorization:
// Bearer <token>" header instead of a cookie, then reuses the exact same
// perms-array shape current_user() builds so the rest of the app's
// permission conventions (role perms + per-user extra perms, "*" super
// admin) apply identically to API callers.

function api_token_hash($token) {
    return hash('sha256', $token);
}

function api_bearer_token() {
    $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (!$hdr && function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            if (strcasecmp($k, 'Authorization') === 0) { $hdr = $v; break; }
        }
    }
    return preg_match('/Bearer\s+(\S+)/i', $hdr, $m) ? $m[1] : '';
}

/** Resolves + caches the API caller for this request. Null if no/invalid token. */
function api_auth_user() {
    static $checked = false, $user = null;
    if ($checked) return $user;
    $checked = true;
    $token = api_bearer_token();
    if ($token === '') return null;
    $t = row('SELECT * FROM api_tokens WHERE token_hash = ? AND revoked = 0', [api_token_hash($token)]);
    if (!$t) return null;
    if ($t['expires_at'] && strtotime($t['expires_at']) < time()) return null;
    $u = row('SELECT u.*, r.name AS role_name, r.permissions AS role_permissions, l.name AS location_name
              FROM users u JOIN roles r ON r.id = u.role_id JOIN locations l ON l.id = u.location_id
              WHERE u.id = ? AND u.is_active = 1', [$t['user_id']]);
    if (!$u) return null;
    $perms = json_decode($u['role_permissions'] ?: '[]', true) ?: [];
    $extra = json_decode($u['permissions'] ?: '[]', true) ?: [];
    $u['perms'] = array_unique(array_merge($perms, $extra));
    q('UPDATE api_tokens SET last_used_at = NOW() WHERE id = ?', [$t['id']]);
    $user = $u;
    return $user;
}

function api_can($perm) {
    $u = api_auth_user();
    if (!$u) return false;
    if (in_array('*', $u['perms'], true)) return true;
    return in_array($perm, $u['perms'], true);
}

function api_json($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Call at the top of every api.php action. Exits with 401/403 JSON on failure. */
function api_require($perm = null) {
    $u = api_auth_user();
    if (!$u) api_json(['error' => 'Unauthorized - missing or invalid API token'], 401);
    if ($perm && !api_can($perm)) api_json(['error' => 'Forbidden - missing permission: ' . $perm], 403);
    return $u;
}

/** api.php equivalent of own_scope() - restricts to the token's own records unless "<module>.all". */
function api_own_scope($user, $module, $column = 'created_by') {
    if (in_array('*', $user['perms'], true) || in_array($module . '.all', $user['perms'], true)) return ['', []];
    return [' AND ' . $column . ' = ? ', [$user['id']]];
}

function api_body() {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}
