<?php
// The owner's side of the platform: making a new shop, and the rules every
// shop's plan is held to. Everything here reads and writes the PLATFORM
// database (pq/prow/pall - config.php's database) except where it builds a
// new shop's own database.
require_once __DIR__ . '/seed.php';
require_once __DIR__ . '/dbmigrate.php';
require_once __DIR__ . '/business_packs.php';

/** Platform settings live in the owner's own settings table. */
function platform_setting($name, $default = '') {
    $v = pval('SELECT value FROM settings WHERE name = ?', [$name]);
    if ($v === null) return $default;
    if (strncmp((string)$v, SECRET_PREFIX, strlen(SECRET_PREFIX)) === 0) $v = vault_decrypt(substr($v, strlen(SECRET_PREFIX)));
    return (string)$v;
}

/** The address shops get: <slug>.<this>. '' until the owner sets it. */
function platform_domain() { return strtolower(trim(platform_setting('platform_domain', ''), " ./")); }

/** A web-address-safe shop code from its name: "Shree Ram Mobile" -> "shreerammobile". */
function tenant_slug_from($name) {
    $s = strtolower(preg_replace('/[^a-z0-9]+/i', '', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', (string)$name) ?: ''));
    return substr($s, 0, 30);
}

/** Why a slug can't be used, or '' if it can. */
function tenant_slug_problem($slug) {
    if (!preg_match('/^[a-z0-9]{3,30}$/', $slug)) return 'Use 3 to 30 English letters or numbers, no spaces.';
    if (in_array($slug, ['www', 'mail', 'admin', 'api', 'app', 'shop', 'demo', 'bulk', 'cpanel', 'webmail', 'ftp', 'ns1', 'ns2', 'platform', 'billing'], true))
        return 'That name is reserved. Please pick another.';
    if (pval('SELECT id FROM tenants WHERE slug = ?', [$slug])) return 'That address is taken. Please pick another.';
    return '';
}

/**
 * Make an empty database for a shop and return its connection details.
 * Three ways, chosen in the owner's platform settings:
 *   mysql  - the app's own MySQL user may CREATE DATABASE (a VPS)
 *   cpanel - shared hosting: cPanel's API makes the database and its user
 *   manual - the owner makes it in cPanel and types the details in; the
 *            shop waits in "provisioning" until then
 * Returns [host, name, user, pass] or throws.
 */
function platform_create_database($slug) {
    $mode = platform_setting('platform_db_mode', 'mysql');
    $prefix = preg_replace('/[^a-z0-9_]/', '', strtolower(platform_setting('platform_db_prefix', '')));
    $name = substr($prefix . 'shop_' . $slug, 0, 64);
    if ($mode === 'cpanel') {
        $user = substr($prefix . 's' . substr(md5($slug), 0, 10), 0, 32);
        $pass = bin2hex(random_bytes(12)) . 'Aa9!';
        foreach ([['Mysql/create_database', ['name' => $name]],
                  ['Mysql/create_user', ['name' => $user, 'password' => $pass]],
                  ['Mysql/set_privileges_on_database', ['user' => $user, 'database' => $name, 'privileges' => 'ALL PRIVILEGES']]] as [$fn, $args]) {
            $r = cpanel_uapi($fn, $args);
            if (empty($r['status'])) throw new Exception('cPanel: ' . ($r['errors'][0] ?? 'could not run ' . $fn));
        }
        return ['localhost', $name, $user, $pass];
    }
    if ($mode === 'manual') throw new Exception('manual');
    platform_db()->exec('CREATE DATABASE IF NOT EXISTS `' . $name . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    return [DB_HOST, $name, DB_USER, DB_PASS];
}

/** One cPanel UAPI call with the owner's API token. */
function cpanel_uapi($fn, array $args) {
    $host = platform_setting('cpanel_host', ''); $user = platform_setting('cpanel_user', ''); $token = platform_setting('cpanel_token', '');
    if ($host === '' || $user === '' || $token === '') return ['status' => 0, 'errors' => ['cPanel host, user or API token is not set (Platform settings).']];
    $ch = curl_init('https://' . $host . ':2083/execute/' . $fn . '?' . http_build_query($args));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ['Authorization: cpanel ' . $user . ':' . $token]]);
    $resp = curl_exec($ch); curl_close($ch);
    return json_decode((string)$resp, true) ?: ['status' => 0, 'errors' => ['cPanel did not answer']];
}

/** A PDO for a shop's own database. */
function tenant_pdo($host, $name, $user, $pass) {
    $pdo = new PDO('mysql:host=' . $host . ';dbname=' . $name . ';charset=utf8mb4', $user, $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->exec("SET time_zone = '+05:30'");
    return $pdo;
}

/**
 * Make a new shop, start to finish: the tenants row, its database, every
 * table, the first rows, the owner's login and the business pack.
 * $in: name, owner_name, owner_mobile, owner_email, username, pass_hash,
 *      business_type, slug, city, reseller_id, plan_code, with_samples.
 * Returns the tenants row. Throws with a reason a person can read.
 */
function provision_tenant(array $in) {
    $slug = $in['slug'];
    if (($p = tenant_slug_problem($slug)) !== '') throw new Exception($p);
    $dom = platform_domain();
    if ($dom === '') throw new Exception('The platform web address is not set yet (Platform → Settings).');
    $plan = prow('SELECT * FROM plans WHERE code = ? AND is_active = 1', [$in['plan_code'] ?? platform_setting('platform_trial_plan', 'pro')])
         ?: prow('SELECT * FROM plans WHERE is_active = 1 ORDER BY sort_order LIMIT 1');
    $trialDays = max(0, (int)platform_setting('platform_trial_days', '15'));
    pq('INSERT INTO tenants (slug, name, owner_name, owner_mobile, owner_email, business_type, domain, status, plan_id, trial_ends, reseller_id, is_demo)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
       [$slug, $in['name'], $in['owner_name'] ?? '', $in['owner_mobile'] ?? '', $in['owner_email'] ?? '', $in['business_type'] ?? 'general',
        $slug . '.' . $dom, 'provisioning', $plan['id'] ?? null, date('Y-m-d', strtotime("+$trialDays days")),
        $in['reseller_id'] ?? null, (int)($in['is_demo'] ?? 0)]);
    $tid = (int)platform_db()->lastInsertId();
    try {
        [$h, $n, $u, $pw] = platform_create_database($slug);
    } catch (Exception $e) {
        if ($e->getMessage() === 'manual') {   // the owner finishes it from the Platform screen
            pq('UPDATE tenants SET notes = ? WHERE id = ?', [json_encode(['pending' => $in], JSON_UNESCAPED_UNICODE), $tid]);
            return prow('SELECT * FROM tenants WHERE id = ?', [$tid]);
        }
        pq("UPDATE tenants SET status = 'closed', notes = ? WHERE id = ?", ['setup failed: ' . $e->getMessage(), $tid]);
        throw new Exception('The shop could not be set up: ' . $e->getMessage());
    }
    try {
        return tenant_install($tid, $h, $n, $u, $pw, $in);
    } catch (Exception $e) {
        // a half-made shop is worse than none: take it all back, so the same
        // address can be tried again
        pq('DELETE FROM tenants WHERE id = ?', [$tid]);
        if (platform_setting('platform_db_mode', 'mysql') === 'mysql') {
            try { platform_db()->exec('DROP DATABASE IF EXISTS `' . $n . '`'); } catch (Exception $e2) {}
        }
        throw new Exception('The shop could not be set up: ' . $e->getMessage());
    }
}

/** Fill a shop's (empty) database and open the shop. Also used for "manual" databases. */
function tenant_install($tid, $h, $n, $u, $pw, array $in) {
    $pdo = tenant_pdo($h, $n, $u, $pw);
    $mig = dbmigrate_on($pdo);
    shop_seed($pdo, $in['name'], $in['owner_name'] ?: 'Owner', $in['username'] ?: 'admin', $in['owner_mobile'] ?? '', $in['pass_hash'], $in['city'] ?? '');
    business_pack_apply($pdo, $in['business_type'] ?? 'general', !empty($in['with_samples']));
    // once more now that the first rows exist: migrations that widen a
    // role's permissions only find the roles on this second pass
    dbmigrate_on($pdo);
    $set = $pdo->prepare('INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)');
    $cron = bin2hex(random_bytes(16));
    foreach ([['db_last_migrated', date('Y-m-d H:i:s')], ['cron_key', $cron], ['setup_wizard_done', '0']] as $s) $set->execute($s);
    pq("UPDATE tenants SET db_host = ?, db_name = ?, db_user = ?, db_pass_enc = ?, cron_key_enc = ?, status = ? WHERE id = ?",
       [$h, $n, $u, vault_encrypt($pw), vault_encrypt($cron), !empty($in['is_demo']) ? 'active' : 'trial', $tid]);
    if ($mig['totals']['failed'] > 0) log_activity('tenant_migrate_warn', $n . ': ' . $mig['totals']['failed'] . ' statements failed');
    return prow('SELECT * FROM tenants WHERE id = ?', [$tid]);
}

/** Connection to an existing shop's database. */
function tenant_pdo_for(array $t) {
    return tenant_pdo($t['db_host'] ?: 'localhost', $t['db_name'], $t['db_user'], (string)vault_decrypt((string)$t['db_pass_enc']));
}

/** Bring every shop's database up to the current code (after an update). */
function tenants_migrate_all() {
    $out = [];
    foreach (pall("SELECT * FROM tenants WHERE status IN ('trial','active','suspended') AND db_name <> ''") as $t) {
        try { $r = dbmigrate_on(tenant_pdo_for($t)); $out[$t['slug']] = $r['totals']; }
        catch (Exception $e) { $out[$t['slug']] = ['error' => $e->getMessage()]; }
    }
    return $out;
}
