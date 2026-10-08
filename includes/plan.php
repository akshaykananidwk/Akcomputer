<?php
// A shop's plan, held to: which parts of the software it opens, how much
// it may use, and what happens when it has not been paid for.
// Nothing here applies to the owner's own shop - every check answers
// "yes" when no other shop is being served.

/** The plan row of the shop being served (null for the owner's own shop). */
function tenant_plan() {
    if (!tenant_active()) return null;
    if (!array_key_exists('_plan', $GLOBALS['_tenant']))    // read once per request, kept on the shop
        $GLOBALS['_tenant']['_plan'] = prow('SELECT * FROM plans WHERE id = ?', [(int)tenant()['plan_id']]) ?: null;
    return $GLOBALS['_tenant']['_plan'];
}

/** Does the shop's plan open this feature (a platform_features() code)? */
function plan_allows($feature) {
    // a service business keeps no stock: the stock screens are simply not there
    if ($feature === 'stock' && function_exists('setting') && setting('biz_no_stock', '0') === '1') return false;
    if (!tenant_active()) return true;
    $p = tenant_plan();
    if (!$p) return false;
    $f = json_decode((string)$p['features'], true) ?: [];
    return in_array('*', $f, true) || in_array($feature, $f, true);
}

/**
 * The plan feature a permission module belongs to. can() asks this, so a
 * part the plan does not open disappears everywhere at once - menu, quick
 * actions, the bottom bar, every button - without each one being told.
 * Modules not listed (settings, users, roles...) are on every plan.
 */
function perm_feature($perm) {
    static $map = [
        'sales' => 'billing', 'dayclose' => 'billing', 'estimates' => 'billing', 'sales_return' => 'billing', 'challans' => 'billing',
        'parties' => 'parties', 'cheques' => 'parties', 'items' => 'items',
        'payments' => 'payments', 'cashbank' => 'payments', 'expenses' => 'expenses',
        'stock' => 'stock', 'handover' => 'stock', 'stock_audit' => 'stock', 'batches' => 'stock',
        'purchases' => 'purchase', 'purchase_return' => 'purchase', 'campaigns' => 'whatsapp',
        'repairs' => 'repairs', 'tasks' => 'repairs', 'warranty' => 'repairs', 'amc' => 'repairs', 'sites' => 'repairs', 'netconn' => 'repairs',
        'report_schedules' => 'reports', 'leads' => 'crm', 'tickets' => 'crm', 'followups' => 'crm', 'reminders' => 'crm',
        'accounting' => 'accounting', 'weborders' => 'online_store', 'webcustomers' => 'online_store', 'referrals' => 'online_store',
        'webhooks' => 'api', 'locations' => 'locations', 'hr' => 'hr', 'deliveries' => 'billing', 'books' => 'accounting',
    ];
    return $map[strtok((string)$perm, '.')] ?? '';
}
function perm_plan_ok($perm) {
    if (!tenant_active()) return true;
    $f = perm_feature($perm);
    return $f === '' || plan_allows($f);
}

/** Which feature a screen belongs to ('' = every plan has it). */
function script_feature($script) {
    $s = preg_replace('/\.php$/', '', basename((string)$script));
    require_once __DIR__ . '/platform_features.php';
    foreach (platform_features() as $code => [, $screens]) if (in_array($s, $screens, true)) return $code;
    return '';
}

/**
 * Where the shop stands with paying:
 *   ok     - paid or on trial
 *   grace  - the date has passed, the shop still works for a few days
 *   locked - past the grace days: only "My plan" (to pay), login, logout
 * ['state', 'until' => last paid/trial day, 'days' => days left (negative = gone)]
 */
function tenant_expiry(array $t = null) {
    $t = $t ?: tenant();
    if (!$t || !empty($t['is_demo'])) return ['state' => 'ok', 'until' => null, 'days' => 999];
    $until = max((string)($t['paid_until'] ?? ''), (string)($t['trial_ends'] ?? ''));
    if ($until === '') return ['state' => 'ok', 'until' => null, 'days' => 999];
    $days = (int)floor((strtotime($until) - strtotime(date('Y-m-d'))) / 86400);
    $grace = max(0, (int)(pval("SELECT value FROM settings WHERE name = 'platform_grace_days'") ?? 3));
    $state = $days >= 0 ? 'ok' : ($days >= -$grace ? 'grace' : 'locked');
    return ['state' => $state, 'until' => $until, 'days' => $days, 'grace' => $grace];
}

/**
 * At the top of every page of another shop: a part its plan does not open
 * shows an upgrade note instead; a shop past its grace days reaches only
 * the pages it needs to pay.
 */
function tenant_plan_guard() {
    if (!tenant_active() || PHP_SAPI === 'cli') return;
    $script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $ex = tenant_expiry();
    if (tenant()['status'] === 'suspended') $ex['state'] = 'locked';   // the owner switched it off
    if ($ex['state'] === 'locked' && !tenant_allow_when_locked() && !in_array($script, ['cron.php'], true)) {
        if (tenant()['status'] !== 'suspended') pq("UPDATE tenants SET status = 'suspended' WHERE id = ?", [tenant()['id']]);
        if (in_array($script, ['index.php'], true) && !(function_exists('current_user') && current_user())) redirect('login.php');
        if (function_exists('current_user') && current_user()) redirect('my_plan.php');
        tenant_closed_page(['name' => tenant()['name'], 'status' => 'suspended']);
    }
    $feat = script_feature($script);
    if ($feat !== '' && !plan_allows($feat)) {
        http_response_code(402);
        require_once __DIR__ . '/platform_features.php';
        $label = platform_features()[$feat][0] ?? $feat;
        echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Upgrade</title>'
           . '<body style="font-family:system-ui,sans-serif;background:#f1f5f9;margin:0;display:grid;place-items:center;min-height:100vh">'
           . '<div style="background:#fff;border-radius:14px;padding:26px 22px;max-width:420px;margin:16px;box-shadow:0 8px 30px rgba(15,23,42,.08)">'
           . '<h2 style="margin:0 0 8px">' . htmlspecialchars($label) . '</h2>'
           . '<p style="color:#475569;line-height:1.5">This part is not in your current plan (' . htmlspecialchars(tenant_plan()['name'] ?? '—') . ').</p>'
           . '<p><a href="my_plan.php" style="display:inline-block;background:#1d4ed8;color:#fff;padding:10px 16px;border-radius:9px;text-decoration:none">See plans →</a> '
           . '<a href="index.php" style="color:#1d4ed8;margin-left:10px">Back</a></p></div></body>';
        exit;
    }
}

/** This month's use of one limit, and the limit (0 = none). */
function plan_usage($what) {
    $p = tenant_plan();
    $col = ['users' => 'max_users', 'bills' => 'max_bills_month', 'wa' => 'max_wa_month', 'locations' => 'max_locations'][$what];
    $max = $p ? (int)$p[$col] : 0;
    $m1 = date('Y-m-01');
    try {
        $used = (int)($what === 'users' ? val('SELECT COUNT(*) FROM users WHERE is_active = 1')
            : ($what === 'bills' ? val('SELECT COUNT(*) FROM sales WHERE is_cancelled = 0 AND sale_date >= ?', [$m1])
            : ($what === 'wa' ? val("SELECT COUNT(*) FROM wa_chats WHERE direction = 'out' AND created_at >= ?", [$m1])
            : val('SELECT COUNT(*) FROM locations'))));
    } catch (Exception $e) { $used = 0; }
    return ['used' => $used, 'max' => $max];
}

/** '' if one more is allowed, else the reason (for the shop's staff to read). */
function plan_limit_problem($what) {
    if (!tenant_active()) return '';
    $u = plan_usage($what);
    if ($u['max'] <= 0 || $u['used'] < $u['max']) return '';
    $name = ['users' => 'staff logins', 'bills' => 'bills this month', 'wa' => 'WhatsApp messages this month', 'locations' => 'locations'][$what];
    return "Your plan allows {$u['max']} $name, and that is used up. Upgrade from \"My plan\" to add more.";
}

/**
 * A Razorpay link for a shop to pay its plan, made with the OWNER's
 * Razorpay keys (the money goes to the software's owner, not to the shop).
 * Records a pending tenant_payments row; the webhook on the owner's address
 * marks it paid. Returns the link or ''.
 */
function platform_payment_link(array $t, array $plan, $months) {
    $months = in_array((int)$months, [1, 12], true) ? (int)$months : 1;
    $amount = $months === 12 && (float)$plan['price_year'] > 0 ? (float)$plan['price_year'] : (float)$plan['price_month'] * $months;
    if ($amount <= 0) return '';
    $ps = fn($k) => (string)pval('SELECT value FROM settings WHERE name = ?', [$k]);
    $keyId = $ps('razorpay_key_id');
    $secret = $ps('razorpay_key_secret');
    if (strncmp($secret, SECRET_PREFIX, strlen(SECRET_PREFIX)) === 0) $secret = vault_decrypt(substr($secret, strlen(SECRET_PREFIX)));
    if ($keyId === '' || $secret === '' || !function_exists('curl_init')) return '';
    $ref = 'PLT-' . $t['id'] . '-' . bin2hex(random_bytes(4));
    $commission = 0;
    if (!empty($t['reseller_id'])) {
        $pct = (float)pval('SELECT commission_pct FROM resellers WHERE id = ? AND is_active = 1', [$t['reseller_id']]);
        $commission = round($amount * $pct / 100, 2);
    }
    pq("INSERT INTO tenant_payments (tenant_id, plan_id, months, amount, ref, status, reseller_id, commission) VALUES (?,?,?,?,?,'pending',?,?)",
       [$t['id'], $plan['id'], $months, $amount, $ref, $t['reseller_id'] ?: null, $commission]);
    $ch = curl_init('https://api.razorpay.com/v1/payment_links');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 15,
        CURLOPT_USERPWD => $keyId . ':' . $secret, CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode([
            'amount' => (int)round($amount * 100), 'currency' => 'INR', 'reference_id' => $ref,
            'description' => mb_substr($plan['name'] . ' plan, ' . $months . ' month' . ($months > 1 ? 's' : '') . ' - ' . $t['name'], 0, 250),
            'customer' => array_filter(['name' => $t['owner_name'], 'contact' => $t['owner_mobile'], 'email' => $t['owner_email']]),
            'notify' => ['sms' => false, 'email' => false], 'notes' => ['kind' => 'platform', 'shop' => $t['slug']],
            'callback_url' => 'https://' . $t['domain'] . '/my_plan.php?paid=1', 'callback_method' => 'get'])]);
    $resp = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $data = json_decode((string)$resp, true);
    if ($code >= 300 || empty($data['short_url'])) { pq("UPDATE tenant_payments SET status = 'failed' WHERE ref = ?", [$ref]); return ''; }
    return $data['short_url'];
}

/**
 * The owner's Razorpay says a plan payment came in: mark it paid (once),
 * move the shop's paid-until date on by the months bought - from today, or
 * from its current end if that is still ahead - open the shop and put it
 * on the plan it paid for. Returns a short status.
 */
function platform_payment_paid($ref, $payId, $amountPaise) {
    $p = prow('SELECT * FROM tenant_payments WHERE ref = ?', [$ref]);
    if (!$p) return 'no such payment';
    if ($p['status'] === 'paid') return 'already processed';
    if ($amountPaise / 100 + 0.009 < (float)$p['amount']) return 'amount short';
    $t = prow('SELECT * FROM tenants WHERE id = ?', [$p['tenant_id']]);
    if (!$t) return 'no such shop';
    $from = max(date('Y-m-d'), (string)($t['paid_until'] ?: ''), (string)($t['trial_ends'] ?: ''));
    $until = date('Y-m-d', strtotime($from . ' +' . (int)$p['months'] . ' months'));
    // only the delivery that actually flips the row moves the date: Razorpay
    // retries, and two copies arriving together must not extend twice
    if (pq("UPDATE tenant_payments SET status = 'paid', paid_at = NOW() WHERE id = ? AND status <> 'paid'", [$p['id']])->rowCount() !== 1)
        return 'already processed';
    pq("UPDATE tenants SET paid_until = ?, status = 'active', plan_id = COALESCE(?, plan_id) WHERE id = ?", [$until, $p['plan_id'], $t['id']]);
    if (function_exists('log_activity')) log_activity('platform_payment', $t['slug'] . ' ₹' . $p['amount'] . ' till ' . $until . ' (' . $payId . ')');
    return 'ok';
}
