<?php
// Many shops, one code base.
//
// The owner's own install (config.php's database) is the PLATFORM: its
// tenants table says which other shops exist, which web address each one
// answers on and which database holds its books. A request that arrives on
// a shop's address is switched to that shop's database before anything
// reads a row; a request on the owner's own address is untouched - the
// owner's shop works exactly as it did before any of this existed.
//
// One database per shop, deliberately, not a shop_id column in every table:
// with a shared table, one query that forgets its WHERE shows one shop's
// customers to another. With separate databases there is no such query to
// forget.

/** The shop this request belongs to (a tenants row), or null for the owner's own. */
function tenant() { return $GLOBALS['_tenant'] ?? null; }
function tenant_active() { return tenant() !== null; }
/** '' for the owner's own shop, the slug for any other - used to keep files and sessions apart. */
function tenant_key() { return tenant()['slug'] ?? ''; }

/** The host this request came in on, lower case, without the port. */
function request_host() {
    $h = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    return preg_replace('/:\d+$/', '', $h);
}

/** The owner's own host, from BASE_URL ('' when BASE_URL is not set). */
function platform_host() {
    $h = defined('BASE_URL') && BASE_URL ? (string)parse_url(BASE_URL, PHP_URL_HOST) : '';
    return strtolower($h);
}

/** The platform (owner's) database - always config.php's, whatever shop is being served. */
function platform_db() {
    static $pdo = null;
    if ($pdo === null) {
        if (!tenant_active()) return db();   // on the owner's own address they are one and the same
        $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false]);
        $pdo->exec("SET time_zone = '+05:30'");
    }
    return $pdo;
}
function pq($sql, $params = []) { $st = platform_db()->prepare($sql); $st->execute($params); return $st; }
function prow($sql, $params = []) { $r = pq($sql, $params)->fetch(); return $r === false ? null : $r; }
function pall($sql, $params = []) { return pq($sql, $params)->fetchAll(); }
function pval($sql, $params = []) { $v = pq($sql, $params)->fetchColumn(); return $v === false ? null : $v; }

/** Connection details for db(): the shop's own database, or config.php's. */
function db_conf() {
    $t = tenant();
    if ($t) return [$t['db_host'] ?: 'localhost', $t['db_name'], $t['db_user'], (string)($t['_db_pass'] ?? '')];
    return [DB_HOST, DB_NAME, DB_USER, DB_PASS];
}
function db_name() { return db_conf()[1]; }

/** Find the shop for a host: its own domain or the custom one pointed at us. */
function tenant_find_by_host($host) {
    if ($host === '') return null;
    try {
        $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS,
                       [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $st = $pdo->prepare('SELECT * FROM tenants WHERE domain = ? OR custom_domain = ? LIMIT 1');
        $st->execute([$host, $host]);
        $r = $st->fetch();
        if ($r) return $r;
        // an address under the shops' domain that no shop has (any more):
        // say so, rather than quietly showing the owner's own login page
        $st = $pdo->prepare("SELECT value FROM settings WHERE name = 'platform_domain'");
        $st->execute();
        $dom = strtolower(trim((string)$st->fetchColumn(), ' ./'));
        if ($dom !== '' && substr($host, -strlen($dom) - 1) === '.' . $dom && $host !== platform_host())
            return ['status' => 'closed', 'name' => $host, 'slug' => ''];
        return null;
    } catch (Exception $e) {
        return null;   // before v87: there are no other shops
    }
}

/**
 * Decide, once, at the top of every request, whose shop this is.
 * Must run before the first db() call.
 */
function tenant_boot() {
    $GLOBALS['_tenant'] = null;
    $host = PHP_SAPI === 'cli' ? strtolower((string)getenv('TENANT_HOST')) : request_host();
    // The owner's own address never looks anything up: no extra query, no
    // change of any kind for the shop that was here first.
    if ($host === '' || ($host === platform_host() && PHP_SAPI !== 'cli')) { tenant_bind_session(''); return; }
    $t = tenant_find_by_host($host);
    if (!$t) { tenant_bind_session(''); return; }   // an alias of the owner's own site
    if (in_array($t['status'], ['provisioning', 'closed'], true) || ($t['status'] === 'suspended' && !tenant_allow_when_locked())) {
        tenant_closed_page($t);
    }
    $t['_db_pass'] = function_exists('vault_decrypt') ? (string)vault_decrypt((string)$t['db_pass_enc']) : '';
    $GLOBALS['_tenant'] = $t;
    tenant_bind_session($t['slug']);
}

/** Pages a suspended shop may still open: its own plan page (to pay) and logging in/out. */
function tenant_allow_when_locked() {
    return in_array(basename((string)($_SERVER['SCRIPT_NAME'] ?? '')), ['my_plan.php', 'login.php', 'logout.php', 'platform_webhook.php'], true);
}

/**
 * A session belongs to one shop. Without this, a session id copied from
 * one shop's address to another's would arrive already logged in - as
 * whoever has the same user id over there.
 */
function tenant_bind_session($key) {
    if (session_status() !== PHP_SESSION_ACTIVE) return;
    if (isset($_SESSION['_shop']) && $_SESSION['_shop'] !== $key) {
        $_SESSION = [];
        if (!headers_sent() && PHP_SAPI !== 'cli') session_regenerate_id(true);
    }
    $_SESSION['_shop'] = $key;
}

function tenant_closed_page(array $t) {
    http_response_code($t['status'] === 'suspended' ? 402 : 404);
    $msg = $t['status'] === 'provisioning' ? 'This shop is still being set up. Please try again in a minute.'
         : ($t['status'] === 'suspended' ? 'This shop\'s plan has ended. The owner can log in and renew it from "My plan".'
         : 'This shop is no longer here.');
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'
       . htmlspecialchars($t['name']) . '</title><body style="font-family:system-ui,sans-serif;background:#f1f5f9;margin:0;display:grid;place-items:center;min-height:100vh">'
       . '<div style="background:#fff;border-radius:14px;padding:28px 24px;max-width:420px;margin:16px;box-shadow:0 8px 30px rgba(15,23,42,.08)">'
       . '<h2 style="margin:0 0 8px">' . htmlspecialchars($t['name']) . '</h2><p style="color:#475569;line-height:1.5">' . htmlspecialchars($msg) . '</p>'
       . ($t['status'] === 'suspended' ? '<p><a href="login.php" style="color:#1d4ed8">Log in →</a></p>' : '') . '</div></body>';
    exit;
}

/** Stop here unless this is the owner's own address: some actions (updating
 *  the code every shop runs on, the platform screens) belong to the owner of
 *  the platform, never to a shop's own admin. */
function platform_owner_only() {
    if (!tenant_active()) return;
    http_response_code(403);
    if (function_exists('flash')) { flash('This belongs to the software\'s owner, not to a shop.', 'error'); redirect('index.php'); }
    exit('Not available here.');
}

/** Where this shop's files live on disk: uploads/ for the owner's shop, uploads/t/<slug>/ for any other. */
function up_dir($sub = '') {
    $base = dirname(__DIR__) . '/uploads' . (tenant_active() ? '/t/' . tenant_key() : '');
    $dir = $base . ($sub !== '' ? '/' . trim($sub, '/') : '');
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    return $dir;
}
/** The same place as a web path ("uploads/..."), for links and for what is stored in the database. */
function up_rel($sub = '') {
    return 'uploads' . (tenant_active() ? '/t/' . tenant_key() : '') . ($sub !== '' ? '/' . trim($sub, '/') : '');
}
