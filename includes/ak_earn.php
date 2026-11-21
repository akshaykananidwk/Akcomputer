<?php
// AK Earn - the central partner / commission system (earn.akdwk.in).
//
// This file is the ONLY place the shop talks to AK Earn. It sends what
// happened (a sign-up, an order, a payment, a refund); the master decides
// which partner it belongs to and what they earn. Nothing here ever sends a
// commission amount, and nothing here changes how the shop itself works.
//
// Three rules hold everywhere below:
//  1. Tracking can never break the page. Every call is queued and only sent
//     after the visitor already has their answer (the response is finished
//     first), inside try/catch. A master that is down costs nothing: the
//     SDK keeps the event in a private file and the hourly cron retries it.
//  2. Only AK Computer's own business is reported. This code base also runs
//     other shops on their own addresses (tenants); their customers and
//     orders are theirs, so the shop events below are skipped on a tenant.
//     The software's own plan payments ARE the owner's business, wherever
//     the shop happened to pay from, so those are sent either way.
//  3. The secret stays on the server. It comes from config.php (AK_SITE_SECRET)
//     or from Settings, where it is stored encrypted like every other key.
//     The browser only ever sees the public site key.

/** Where the three values come from: config.php constants first, then the
 *  owner's own settings (platform database). ['url', 'key', 'secret']. */
function ak_earn_conf() {
    static $c = null;
    if ($c !== null) return $c;
    $set = function ($name) {
        try {
            $v = function_exists('pval') ? pval('SELECT value FROM settings WHERE name = ?', [$name])
                                         : val('SELECT value FROM settings WHERE name = ?', [$name]);
        } catch (Throwable $e) { return ''; }
        $v = (string)$v;
        if ($v !== '' && strncmp($v, SECRET_PREFIX, strlen(SECRET_PREFIX)) === 0) $v = (string)vault_decrypt(substr($v, strlen(SECRET_PREFIX)));
        return trim($v);
    };
    $url = defined('AK_MASTER_URL') ? (string)AK_MASTER_URL : ($set('ak_earn_url') ?: 'https://earn.akdwk.in');
    $key = defined('AK_SITE_KEY') ? (string)AK_SITE_KEY : $set('ak_earn_site_key');
    $sec = defined('AK_SITE_SECRET') ? (string)AK_SITE_SECRET : $set('ak_earn_site_secret');
    $off = $set('ak_earn_off') === '1';
    return $c = ['url' => rtrim($url, '/'), 'key' => $key, 'secret' => $sec, 'off' => $off];
}

/** Connected and switched on (key + secret known, curl present). */
function ak_earn_ready() {
    $c = ak_earn_conf();
    return !$c['off'] && $c['key'] !== '' && $c['secret'] !== '' && function_exists('curl_init')
        // https only - plain http just for a master on this machine (tests)
        && preg_match('~^(https://|http://(127\.0\.0\.1|localhost)(:\d+)?(/|$))~', $c['url']);
}

/** Load the SDK with the three constants defined. false when not set up. */
function ak_earn_boot() {
    static $ok = null;
    if ($ok !== null) return $ok;
    if (!ak_earn_ready()) return $ok = false;
    $c = ak_earn_conf();
    if (!defined('AK_MASTER_URL')) define('AK_MASTER_URL', $c['url']);
    if (!defined('AK_SITE_KEY')) define('AK_SITE_KEY', $c['key']);
    if (!defined('AK_SITE_SECRET')) define('AK_SITE_SECRET', $c['secret']);
    // the retry queue holds customers' names and mobiles: a private folder,
    // never the system temp dir other accounts on the host can read
    if (!defined('AK_QUEUE_DIR')) {
        $d = dirname(__DIR__) . '/uploads/ak_earn';
        if (!is_dir($d)) @mkdir($d, 0750, true);
        if (!is_file("$d/.htaccess")) @file_put_contents("$d/.htaccess", "Require all denied\nDeny from all\n");
        if (!is_file("$d/index.html")) @file_put_contents("$d/index.html", '');
        define('AK_QUEUE_DIR', is_dir($d) && is_writable($d) ? $d : sys_get_temp_dir());
    }
    require_once __DIR__ . '/vendor/ak-connect.php';
    return $ok = true;
}

/** Is this request AK Computer's own address (not another shop on the platform)? */
function ak_earn_own_site() { return !function_exists('tenant_active') || !tenant_active(); }

/**
 * Send something to AK Earn, later: $fn runs after the response has gone
 * to the visitor. $scope 'shop' = only on AK Computer's own address,
 * 'platform' = the software's own business (plans), from any address.
 * Never throws; a failure is written to the error log (no secret in it).
 */
function ak_earn($fn, $label = '', $scope = 'shop') {
    try {
        if ($scope === 'shop' && !ak_earn_own_site()) return;
        if (!ak_earn_ready()) return;
        $GLOBALS['_ak_earn_jobs'][] = [$fn, $label];
        static $hooked = false;
        if (!$hooked) { $hooked = true; register_shutdown_function('ak_earn_run'); }
    } catch (Throwable $e) { /* tracking never breaks the page */ }
}

/** Run the queued calls - at shutdown, or straight away from the tests. */
function ak_earn_run($finishFirst = true) {
    $jobs = $GLOBALS['_ak_earn_jobs'] ?? [];
    $GLOBALS['_ak_earn_jobs'] = [];
    if (!$jobs) return [];
    // the regression suite: hooks are checked, nothing is ever sent
    if (defined('AK_EARN_DRY_RUN')) return [];
    if ($finishFirst && PHP_SAPI !== 'cli') {
        // let go of the session lock and the visitor before talking to the
        // master: the next page they open must not wait on this
        if (session_status() === PHP_SESSION_ACTIVE) @session_write_close();
        if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();        // PHP-FPM
        elseif (function_exists('litespeed_finish_request')) @litespeed_finish_request(); // LiteSpeed (cPanel)
        @ignore_user_abort(true);
    }
    $out = [];
    foreach ($jobs as [$fn, $label]) {
        try {
            if (!ak_earn_boot()) break;
            $r = $fn();
            $out[] = $r;
            if (is_array($r) && ($r['status'] ?? '') === 'error' && empty($r['queued'])
                && function_exists('app_error')) {
                app_error('ak_earn', 'AK Earn ' . $label . ': ' . mb_substr((string)($r['message'] ?? 'error'), 0, 200), 'ak_earn.php', false);
            }
        } catch (Throwable $e) {
            if (function_exists('app_error')) app_error('ak_earn', 'AK Earn ' . $label . ' failed: ' . mb_substr($e->getMessage(), 0, 200), 'ak_earn.php', false);
        }
    }
    return $out;
}

/** The browser script, for the public pages of AK Computer's own address only. */
function ak_earn_tag() {
    try {
        if (!ak_earn_own_site()) return '';
        $c = ak_earn_conf();
        if ($c['off'] || $c['key'] === '') return '';
        return '<script src="' . htmlspecialchars($c['url'] . '/connector/ak-connect.js', ENT_QUOTES) . '" data-site-key="'
             . htmlspecialchars($c['key'], ENT_QUOTES) . '" defer></script>';
    } catch (Throwable $e) { return ''; }
}

// ------------------------------------------------------------ the shop ---

/** A website item's stable product code in AK Earn. */
function ak_earn_item_code($id) { return 'item-' . (int)$id; }

/** The code to report for an order: the line that carries the most money. */
function ak_earn_order_code(array $lines) {
    $best = null; $bestAmt = -1;
    foreach ($lines as $l) {
        $amt = (float)($l['total'] ?? ((float)($l['price'] ?? 0) * (int)($l['qty'] ?? 1)));
        if ($amt > $bestAmt) { $bestAmt = $amt; $best = $l; }
    }
    return $best ? ak_earn_item_code($best['id'] ?? 0) : null;
}

/** A website order (web_orders row) as AK Earn order fields. */
function ak_earn_web_order(array $o) {
    $lines = json_decode((string)($o['items_json'] ?? ''), true) ?: [];
    $items = array_map(fn($l) => ['code' => ak_earn_item_code($l['id'] ?? 0), 'qty' => (int)($l['qty'] ?? 1), 'price' => round((float)($l['price'] ?? 0), 2)], $lines);
    return [
        'id' => (string)($o['order_no'] ?: doc_no('WEB', $o['id'])),
        'amount' => round((float)$o['total'], 2),
        'code' => ak_earn_order_code($lines),
        'name' => (string)$o['customer_name'],
        'mobile' => mobile10($o['mobile']),
        'meta' => ['items' => array_slice($items, 0, 30), 'source' => 'website'],
    ];
}

/** A web order was placed (not paid yet). */
function ak_earn_order_created($webOrderId) {
    ak_earn(function () use ($webOrderId) {
        $o = row('SELECT * FROM web_orders WHERE id = ?', [(int)$webOrderId]);
        if (!$o) return null;
        $w = ak_earn_web_order($o);
        return ak_track_order($w['id'], $w['amount'], $w['code'], $w['name'], $w['mobile'], 'created', $w['meta']);
    }, 'order_created');
}

/** The shop moved a web order from $old to $new status. completed = the
 *  customer has paid and has the goods; that is the commission event. */
function ak_earn_order_status($webOrderId, $old, $new) {
    if ($old === $new) return;
    ak_earn(function () use ($webOrderId, $old, $new) {
        $o = row('SELECT * FROM web_orders WHERE id = ?', [(int)$webOrderId]);
        if (!$o) return null;
        $w = ak_earn_web_order($o);
        if ($new === 'completed') return ak_track_order($w['id'], $w['amount'], $w['code'], $w['name'], $w['mobile'], 'paid', $w['meta']);
        if ($new === 'cancelled') return $old === 'completed'
            ? ak_track_refund($w['id'], 'Order cancelled after it was completed', $w['amount'])
            : ak_track_cancel($w['id'], 'Order cancelled by the shop');
        return null;
    }, 'order_' . $new);
}

/** One website item as an AK Earn product. */
function ak_earn_item_product(array $it) {
    $img = (string)($it['photo'] ?? '');
    if ($img !== '' && !preg_match('~^https?://~', $img)) $img = base_url(ltrim($img, '/'));
    $p = [
        'code' => ak_earn_item_code($it['id']),
        'name' => mb_substr(trim((string)$it['name'] . ((string)($it['variant'] ?? '') !== '' ? ' ' . $it['variant'] : '')), 0, 190),
        'price' => round((float)$it['selling_price'], 2),
        'renewal_price' => 0,
        'billing' => 'one_time',
        'description' => mb_substr(trim(strip_tags((string)($it['description'] ?? ''))), 0, 1000),
        'url' => base_url('product.php?id=' . (int)$it['id']),
        'status' => ((int)$it['is_active'] && (int)$it['show_on_website']) ? 'active' : 'inactive',
    ];
    if (preg_match('~^https://~', $img)) $p['image'] = $img;
    return $p;
}

/** After an item is saved, switched on/off the website ($force) or deleted.
 *  Only website items are the master's business: an item that is not on
 *  the website is sent only when it has just been taken off (inactive). */
function ak_earn_sync_item($itemId, $force = false) {
    ak_earn(function () use ($itemId, $force) {
        $it = row('SELECT * FROM items WHERE id = ?', [(int)$itemId]);
        if ($it && !(int)$it['show_on_website'] && !$force) return null;
        $p = $it ? ak_earn_item_product($it)
                 : ['code' => ak_earn_item_code($itemId), 'name' => 'Item ' . (int)$itemId, 'price' => 0, 'billing' => 'one_time', 'status' => 'inactive'];
        return ak_sync_products([$p]);
    }, 'product_sync');
}

/** Every product the master should know: the website items, and (on the
 *  platform) the software's plans. Sent in chunks of 100. */
function ak_earn_sync_all() {
    if (!ak_earn_boot()) return ['status' => 'error', 'message' => 'AK Earn is not set up'];
    $products = [];
    if (ak_earn_own_site()) {
        foreach (all('SELECT * FROM items WHERE is_active = 1 AND show_on_website = 1 ORDER BY id') as $it) $products[] = ak_earn_item_product($it);
    }
    $products = array_merge($products, ak_earn_plan_products());
    $synced = 0; $errors = [];
    foreach (array_chunk($products, 100) as $chunk) {
        $r = ak_sync_products($chunk);
        $synced += (int)($r['synced'] ?? 0);
        if (($r['status'] ?? '') !== 'ok') $errors[] = (string)($r['message'] ?? 'error');
        foreach ((array)($r['errors'] ?? []) as $e) $errors[] = is_string($e) ? $e : json_encode($e);
    }
    if (!$errors) { try { set_setting('ak_earn_synced_at', date('Y-m-d H:i:s')); } catch (Throwable $e) {} }
    return ['status' => $errors ? 'error' : 'ok', 'sent' => count($products), 'synced' => $synced, 'errors' => array_slice($errors, 0, 10)];
}

// --------------------------------------------- the software's own plans ---

/** The plans shops buy, as products: one per plan per billing period. */
function ak_earn_plan_products() {
    $out = [];
    try {
        if (!function_exists('pq')) return [];
        foreach (pq('SELECT * FROM plans ORDER BY sort_order, id')->fetchAll() as $pl) {
            $st = (int)$pl['is_active'] ? 'active' : 'inactive';
            $base = ['description' => 'Shop software - ' . $pl['name'] . ' plan', 'url' => base_url('signup.php'), 'status' => $st];
            if ((float)$pl['price_month'] > 0)
                $out[] = ['code' => ak_earn_plan_code($pl, 1), 'name' => $pl['name'] . ' plan (monthly)', 'price' => round((float)$pl['price_month'], 2),
                          'renewal_price' => round((float)$pl['price_month'], 2), 'billing' => 'monthly'] + $base;
            if ((float)$pl['price_year'] > 0)
                $out[] = ['code' => ak_earn_plan_code($pl, 12), 'name' => $pl['name'] . ' plan (yearly)', 'price' => round((float)$pl['price_year'], 2),
                          'renewal_price' => round((float)$pl['price_year'], 2), 'billing' => 'yearly'] + $base;
        }
    } catch (Throwable $e) { /* no platform tables on this install */ }
    return $out;
}

function ak_earn_plan_code(array $plan, $months) {
    $c = preg_replace('/[^a-z0-9_-]/', '', strtolower((string)($plan['code'] ?: 'plan' . $plan['id'])));
    return 'plan-' . $c . ((int)$months === 12 ? '-yearly' : '-monthly');
}

/** A shop signed up for the software. */
function ak_earn_shop_signup(array $tenant) {
    if (!empty($tenant['is_demo'])) return;
    ak_earn(fn() => ak_track_signup((string)$tenant['owner_name'], mobile10($tenant['owner_mobile']),
                                    (string)$tenant['owner_email'] ?: null, ['kind' => 'software_shop', 'shop' => (string)$tenant['slug']]),
            'software_signup', 'platform');
}

/** A plan payment link was made (the order exists, nothing is paid yet). */
function ak_earn_plan_created($ref) { ak_earn_plan_event($ref, 'created'); }

/** The plan payment $ref came in. The shop's first ever paid plan is a
 *  sale; every one after that is a renewal (its partner is the one of the
 *  first sale - the master works that out). */
function ak_earn_plan_paid($ref) { ak_earn_plan_event($ref, 'paid'); }

function ak_earn_plan_event($ref, $what) {
    ak_earn(function () use ($ref, $what) {
        $p = prow('SELECT * FROM tenant_payments WHERE ref = ?', [(string)$ref]);
        if (!$p) return null;
        $t = prow('SELECT * FROM tenants WHERE id = ?', [$p['tenant_id']]);
        if (!$t || !empty($t['is_demo'])) return null;
        $plan = prow('SELECT * FROM plans WHERE id = ?', [$p['plan_id']]) ?: ['code' => 'plan', 'id' => 0];
        $code = ak_earn_plan_code($plan, (int)$p['months']);
        $meta = ['kind' => 'software_plan', 'shop' => (string)$t['slug'], 'months' => (int)$p['months']];
        $name = (string)($t['owner_name'] ?: $t['name']);
        $mob = mobile10($t['owner_mobile']);
        if ($what === 'created') return ak_track_order((string)$p['ref'], (float)$p['amount'], $code, $name, $mob, 'created', $meta);
        if ($p['status'] !== 'paid') return null;   // never report an unpaid plan as paid
        $earlier = (int)pval("SELECT COUNT(*) FROM tenant_payments WHERE tenant_id = ? AND status = 'paid' AND id <> ? AND id < ?",
                             [$t['id'], $p['id'], $p['id']]);
        return $earlier
            ? ak_track_renewal((string)$p['ref'], (float)$p['amount'], $code, $name, $mob, $meta)
            : ak_track_order((string)$p['ref'], (float)$p['amount'], $code, $name, $mob, 'paid', $meta);
    }, 'plan_' . $what, 'platform');
}
