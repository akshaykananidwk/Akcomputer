<?php
// Common helpers

function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function redirect($url) { header('Location: ' . $url); exit; }

function post($key, $default = '') { return isset($_POST[$key]) ? (is_string($_POST[$key]) ? trim($_POST[$key]) : $_POST[$key]) : $default; }
function get($key, $default = '') { return isset($_GET[$key]) ? (is_string($_GET[$key]) ? trim($_GET[$key]) : $_GET[$key]) : $default; }

function money($n) { return number_format((float)$n, 2); }

function today() { return date('Y-m-d'); }

function dmy($date) {
    if (!$date || $date === '0000-00-00') return '-';
    return date('d-m-Y', strtotime($date));
}
function dmyt($dt) {
    if (!$dt) return '-';
    return date('d-m-Y h:i A', strtotime($dt));
}

function days_between($from, $to = null) {
    if (!$from) return null;
    $to = $to ?: today();
    return (int) floor((strtotime($to) - strtotime($from)) / 86400);
}

// ---------- Flash messages ----------
function flash($msg, $type = 'success') {
    $_SESSION['flash'][] = ['msg' => $msg, 'type' => $type];
}
function get_flashes() {
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

// ---------- CSRF ----------
function csrf_token() {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}
function csrf_field() {
    return '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
}
function csrf_check() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // api.php (Bearer-token REST/mobile API) and razorpay_webhook.php
        // (HMAC-signature-verified inbound webhook) authenticate every
        // request their own way and have no browser session/CSRF token to
        // check against - both are genuinely different trust boundaries
        // from the rest of this cookie-session-based app.
        $exempt = ['api.php', 'razorpay_webhook.php'];
        if (in_array(basename($_SERVER['SCRIPT_NAME'] ?? ''), $exempt, true)) return;
        if (!hash_equals(csrf_token(), (string)post('csrf'))) {
            http_response_code(400);
            die('Invalid request (CSRF). Go back and try again.');
        }
    }
}

// ---------- Settings ----------
function setting($name, $default = '') {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (all('SELECT name, value FROM settings') as $r) $cache[$r['name']] = $r['value'];
    }
    return array_key_exists($name, $cache) ? $cache[$name] : $default;
}
function set_setting($name, $value) {
    q('INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [$name, $value]);
}

// ---------- Per-user preferences (theme, dashboard layout, etc.) ----------
function user_pref($user_id, $key, $default = null) {
    $v = val('SELECT pref_value FROM user_preferences WHERE user_id = ? AND pref_key = ?', [$user_id, $key]);
    return $v === null ? $default : $v;
}
function set_user_pref($user_id, $key, $value) {
    q('INSERT INTO user_preferences (user_id, pref_key, pref_value) VALUES (?,?,?)
       ON DUPLICATE KEY UPDATE pref_value = VALUES(pref_value)', [$user_id, $key, $value]);
}

// ---------- Saved filters (per user, per page) ----------
// $page is a short stable key (e.g. "sales", "parties", "reports_cashbook")
// distinguishing where a saved filter applies - it's stored alongside the
// filter so a "Daily Sales" saved view never shows up on "Cashbook".
function render_saved_filters($user_id, $page) {
    $filters = all('SELECT * FROM saved_filters WHERE user_id = ? AND page = ? ORDER BY id DESC', [$user_id, $page]);
    ob_start();
    ?>
    <div class="saved-filters no-print">
      <?php foreach ($filters as $f): ?>
      <span class="saved-filter-chip">
        <a href="?<?= e($f['query_string']) ?>"><?= e($f['name']) ?></a>
        <button type="button" class="saved-filter-del" data-id="<?= (int)$f['id'] ?>" title="Delete this saved filter">×</button>
      </span>
      <?php endforeach; ?>
      <button type="button" class="btn btn-sm btn-outline" onclick="saveCurrentFilter(<?= json_encode($page, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) ?>)">💾 Save current filter</button>
    </div>
    <?php
    return ob_get_clean();
}

// ---------- Activity log ----------
function log_activity($action, $details = '') {
    $uid = $_SESSION['user_id'] ?? null;
    q('INSERT INTO activity_log (user_id, action, details, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)',
      [$uid, $action, mb_substr($details, 0, 500), function_exists('client_ip') ? client_ip() : '', function_exists('client_user_agent') ? client_user_agent() : '']);
}

// ---------- Document numbers ----------
function doc_no($prefix, $id) {
    return $prefix . '-' . str_pad($id, 5, '0', STR_PAD_LEFT);
}

// ---------- Stock helpers (always use inside a transaction for documents) ----------
/** $unit_cost is optional - when given on a positive delta, a FIFO/weighted-
 *  average cost layer is recorded (stock_layer_consume() reads it back on a
 *  negative delta). Every existing call site keeps working unchanged since
 *  this is purely additive; the Stock Report/COGS only start using it once
 *  Settings > Inventory has costing_method switched off "current". */
function adjust_stock($item_id, $location_id, $delta, $ref_type, $ref_id = null, $note = '', $unit_cost = null) {
    q('INSERT INTO stock (item_id, location_id, qty) VALUES (?, ?, ?)
       ON DUPLICATE KEY UPDATE qty = qty + VALUES(qty)', [$item_id, $location_id, $delta]);
    q('INSERT INTO stock_ledger (item_id, location_id, change_qty, ref_type, ref_id, note, created_by)
       VALUES (?, ?, ?, ?, ?, ?, ?)',
      [$item_id, $location_id, $delta, $ref_type, $ref_id, $note, $_SESSION['user_id'] ?? null]);
    if ($delta > 0 && $unit_cost !== null) {
        stock_layer_add($item_id, $location_id, $delta, $unit_cost, $ref_type, $ref_id);
    }
}

function adjust_staff_stock($user_id, $item_id, $delta, $ref_type, $ref_id = null, $note = '') {
    q('INSERT INTO staff_stock (user_id, item_id, qty) VALUES (?, ?, ?)
       ON DUPLICATE KEY UPDATE qty = qty + VALUES(qty)', [$user_id, $item_id, $delta]);
    q('INSERT INTO stock_ledger (item_id, user_id, change_qty, ref_type, ref_id, note, created_by)
       VALUES (?, ?, ?, ?, ?, ?, ?)',
      [$item_id, $user_id, $delta, $ref_type, $ref_id, $note, $_SESSION['user_id'] ?? null]);
}

function stock_qty($item_id, $location_id) {
    return (float) (val('SELECT qty FROM stock WHERE item_id = ? AND location_id = ?', [$item_id, $location_id]) ?? 0);
}
function staff_stock_qty($user_id, $item_id) {
    return (float) (val('SELECT qty FROM staff_stock WHERE user_id = ? AND item_id = ?', [$user_id, $item_id]) ?? 0);
}

// ---------- FIFO / weighted-average cost layers (additive, opt-in) ----------
function stock_layer_add($item_id, $location_id, $qty, $unit_cost, $ref_type = '', $ref_id = null) {
    q('INSERT INTO stock_cost_layers (item_id, location_id, qty_remaining, unit_cost, ref_type, ref_id) VALUES (?,?,?,?,?,?)',
      [$item_id, $location_id, $qty, $unit_cost, $ref_type, $ref_id]);
}
/** Consumes $qty worth of layers (oldest first) for one item/location and
 *  returns the blended unit cost of what was actually consumed - null if no
 *  layers exist yet (caller should fall back to items.purchase_price, same
 *  as before this feature existed). For costing_method='weighted_avg' the
 *  blended cost is effectively the pool average anyway since every
 *  remaining layer is consumed proportionally in id order. */
function stock_layer_consume($item_id, $location_id, $qty) {
    $layers = all('SELECT * FROM stock_cost_layers WHERE item_id = ? AND location_id = ? AND qty_remaining > 0 ORDER BY id ASC', [$item_id, $location_id]);
    if (!$layers) return null;
    $remaining = $qty;
    $costTotal = 0;
    $qtyTaken = 0;
    foreach ($layers as $l) {
        if ($remaining <= 0) break;
        $take = min($remaining, (float)$l['qty_remaining']);
        q('UPDATE stock_cost_layers SET qty_remaining = qty_remaining - ? WHERE id = ?', [$take, $l['id']]);
        $costTotal += $take * (float)$l['unit_cost'];
        $qtyTaken += $take;
        $remaining -= $take;
    }
    if ($qtyTaken <= 0) return null;
    // whatever couldn't be covered by layers (oversold beyond tracked stock)
    // is priced at the last known layer cost, so the average stays sane
    if ($remaining > 0) $costTotal += $remaining * (float)end($layers)['unit_cost'];
    return $costTotal / $qty;
}
/** Current inventory value from remaining cost layers for one item, across
 *  every location (or one, if given) - used by the FIFO/WA valuation view. */
function stock_layer_value($item_id, $location_id = null) {
    $sql = 'SELECT COALESCE(SUM(qty_remaining * unit_cost),0) FROM stock_cost_layers WHERE item_id = ? AND qty_remaining > 0';
    $params = [$item_id];
    if ($location_id) { $sql .= ' AND location_id = ?'; $params[] = $location_id; }
    return (float)val($sql, $params);
}

// ---------- Stock reservations (soft-hold, doesn't touch stock.qty) ----------
function stock_reserved_qty($item_id, $location_id) {
    return (float)val("SELECT COALESCE(SUM(qty),0) FROM stock_reservations WHERE item_id = ? AND location_id = ? AND status = 'active'", [$item_id, $location_id]);
}
function stock_available_qty($item_id, $location_id) {
    return stock_qty($item_id, $location_id) - stock_reserved_qty($item_id, $location_id);
}

// ---------- Low stock (shared by the header bell, the Low Stock report and
// reorder suggestions - one query instead of three near-duplicates) ----------
function low_stock_items($location_id = null) {
    if ($location_id) {
        return all("SELECT i.*, COALESCE(s.qty,0) q,
                     (SELECT pt.name FROM purchases pu JOIN purchase_items pi2 ON pi2.purchase_id = pu.id
                      JOIN parties pt ON pt.id = pu.party_id WHERE pi2.item_id = i.id AND pu.is_cancelled = 0
                      ORDER BY pu.purchase_date DESC, pu.id DESC LIMIT 1) last_supplier
                     FROM items i LEFT JOIN stock s ON s.item_id = i.id AND s.location_id = ?
                     WHERE i.is_active = 1 AND i.item_type <> 'service' AND i.min_stock > 0
                     HAVING q < i.min_stock ORDER BY q", [$location_id]);
    }
    return all("SELECT i.*, COALESCE(SUM(s.qty),0) q,
                (SELECT pt.name FROM purchases pu JOIN purchase_items pi2 ON pi2.purchase_id = pu.id
                 JOIN parties pt ON pt.id = pu.party_id WHERE pi2.item_id = i.id AND pu.is_cancelled = 0
                 ORDER BY pu.purchase_date DESC, pu.id DESC LIMIT 1) last_supplier
                FROM items i LEFT JOIN stock s ON s.item_id = i.id
                WHERE i.is_active = 1 AND i.item_type <> 'service' AND i.min_stock > 0 GROUP BY i.id HAVING q < i.min_stock ORDER BY q");
}

// ---------- OTP ----------
function create_otp($purpose, $target, $minutes = 10) {
    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    q('UPDATE otp_codes SET used = 1 WHERE purpose = ? AND target = ? AND used = 0', [$purpose, $target]);
    q('INSERT INTO otp_codes (purpose, target, code, expires_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE))',
      [$purpose, $target, $code, $minutes]);
    return $code;
}
function verify_otp($purpose, $target, $code) {
    // look up the active (unused, unexpired) code for this purpose/target
    // regardless of whether $code matches yet, so wrong tries can be
    // counted against it and it gets locked out after too many guesses
    $r = row('SELECT * FROM otp_codes WHERE purpose = ? AND target = ? AND used = 0 AND expires_at > NOW()
              ORDER BY id DESC LIMIT 1', [$purpose, $target]);
    if (!$r || (int)$r['attempts'] >= 5) return false;
    if (!hash_equals($r['code'], trim((string)$code))) {
        q('UPDATE otp_codes SET attempts = attempts + 1 WHERE id = ?', [$r['id']]);
        return false;
    }
    q('UPDATE otp_codes SET used = 1 WHERE id = ?', [$r['id']]);
    return true;
}

// ---------- Amount in words (Indian system) ----------
function amount_in_words($num) {
    $num = round((float)$num, 2);
    $paise = round(($num - floor($num)) * 100);
    $num = (int)floor($num);
    if ($num == 0 && $paise == 0) return 'Zero Rupees Only';
    $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve',
             'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
    $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
    $two = function ($n) use ($ones, $tens) {
        if ($n < 20) return $ones[$n];
        return trim($tens[intdiv($n, 10)] . ' ' . $ones[$n % 10]);
    };
    $out = '';
    if ($num >= 10000000) { $out .= $two(intdiv($num, 10000000)) . ' Crore '; $num %= 10000000; }
    if ($num >= 100000) { $out .= $two(intdiv($num, 100000)) . ' Lakh '; $num %= 100000; }
    if ($num >= 1000) { $out .= $two(intdiv($num, 1000)) . ' Thousand '; $num %= 1000; }
    if ($num >= 100) { $out .= $ones[intdiv($num, 100)] . ' Hundred '; $num %= 100; }
    if ($num > 0) $out .= $two($num) . ' ';
    $out = trim($out) . ' Rupees';
    if ($paise > 0) $out .= ' and ' . $two($paise) . ' Paise';
    return trim($out) . ' Only';
}

// ---------- Party running-account balance (single source of truth) ----------
// Positive = party owes shop (You'll Get). Negative = shop owes
// party (You'll Give) - this also covers customer ADVANCES: a
// payment received with no bill against it simply pushes the balance
// negative, exactly like a real khata/ledger book. Used everywhere (party
// list, party ledger, Payment-In/Out, dashboard) so the numbers never
// disagree with each other.
function party_balance_expr($alias = 'p') {
    return "($alias.opening_balance
        + COALESCE((SELECT SUM(total) FROM sales WHERE party_id = $alias.id AND is_cancelled = 0), 0)
        - COALESCE((SELECT SUM(total) FROM sales_returns WHERE party_id = $alias.id), 0)
        - COALESCE((SELECT SUM(total) FROM purchases WHERE party_id = $alias.id AND is_cancelled = 0), 0)
        + COALESCE((SELECT SUM(total) FROM purchase_returns WHERE party_id = $alias.id), 0)
        - COALESCE((SELECT SUM(amount) FROM payments WHERE party_id = $alias.id AND direction = 'in'), 0)
        + COALESCE((SELECT SUM(amount) FROM payments WHERE party_id = $alias.id AND direction = 'out'), 0))";
}
function party_balance($party_id) {
    return (float)val('SELECT ' . party_balance_expr('p') . ' FROM parties p WHERE p.id = ?', [$party_id]);
}
/** Unpaid amount on walk-in bills (no party attached) - can't be collected
 *  via Payment-In (there's no party to pick); shown separately so totals
 *  stay honest instead of silently disagreeing across pages. */
function walkin_due() {
    return (float)val("SELECT COALESCE(SUM(total - paid), 0) FROM sales WHERE party_id IS NULL AND status <> 'paid' AND is_cancelled = 0");
}

// ---------- Reports: shared Firm/Party/Status filter builder ----------
// Used by both reports.php and report_pdf.php (via includes/report_body.php)
// so every sales/purchase-family report tab applies the same filters
// consistently instead of each query reinventing it.
function report_extra_where($alias, $fCompany, $fParty, $fStatus, $fUser = 0) {
    $where = ''; $params = [];
    if ($fCompany) { $where .= " AND $alias.company_id = ?"; $params[] = $fCompany; }
    if ($fParty) { $where .= " AND $alias.party_id = ?"; $params[] = $fParty; }
    if ($fUser) { $where .= " AND $alias.created_by = ?"; $params[] = $fUser; }
    // Status: 'paid' / 'partial' / 'due' map straight to the status column;
    // 'cancelled' and 'overdue' are derived (Vyapar-style) so the Reports
    // filter can offer the same choices the app owner expects.
    if ($fStatus === 'cancelled') {
        $where .= " AND $alias.is_cancelled = 1";
    } elseif ($fStatus === 'overdue') {
        $where .= " AND $alias.is_cancelled = 0 AND $alias.status <> 'paid' AND $alias.due_date IS NOT NULL AND $alias.due_date < CURDATE()";
    } elseif ($fStatus) {
        $where .= " AND $alias.status = ?"; $params[] = $fStatus;
    }
    return [$where, $params];
}

// ---------- Reminder module ----------
/** Emoji shown for each reminder category (falls back to the bell). */
function reminder_category_icon($cat) {
    return ['general' => '🔔', 'computer' => '💻', 'cctv' => '📹', 'staff' => '🧑‍🔧',
            'customer' => '👤', 'payment' => '💰', 'delivery' => '📦'][$cat] ?? '🔔';
}

/** Builds the WhatsApp text for one reminder (optionally greeting a recipient). */
function reminder_message($rem, $recipientName = '') {
    $shop = setting('app_name', 'AK Computer');
    $lines = ['🔔 *Reminder* — ' . $shop, ''];
    if (trim((string)$recipientName) !== '') $lines[] = 'Hello ' . trim($recipientName) . ',';
    $lines[] = '*' . $rem['title'] . '*';
    if (trim((string)$rem['notes']) !== '') { $lines[] = ''; $lines[] = $rem['notes']; }
    $lines[] = '';
    $lines[] = '📅 ' . dmy(substr($rem['remind_at'], 0, 10)) . '   🕒 ' . date('h:i A', strtotime($rem['remind_at']));
    return implode("\n", $lines);
}

/** Next fire time for a recurring reminder (null for a one-time reminder). */
function reminder_next_at($current, $freq) {
    $t = strtotime($current);
    if ($freq === 'daily') return date('Y-m-d H:i:s', strtotime('+1 day', $t));
    if ($freq === 'weekly') return date('Y-m-d H:i:s', strtotime('+7 days', $t));
    if ($freq === 'monthly') return date('Y-m-d H:i:s', strtotime('+1 month', $t));
    return null;
}

/** Sends one reminder to all its recipients now, then either advances a
 *  recurring reminder to its next slot or marks a one-time one as sent.
 *  Returns [sentCount, failedCount]. */
function reminder_fire($rem) {
    $recips = all('SELECT * FROM reminder_recipients WHERE reminder_id = ?', [$rem['id']]);
    $sent = 0; $failed = 0;
    foreach ($recips as $rc) {
        if (trim((string)$rc['mobile']) === '') continue;
        if (send_whatsapp($rc['mobile'], reminder_message($rem, (string)$rc['name']))) $sent++;
        else $failed++;
        usleep(300000); // gentle on the WhatsApp API
    }
    $next = reminder_next_at($rem['remind_at'], $rem['repeat_freq']);
    if ($rem['repeat_freq'] !== 'once' && $next
        && (empty($rem['repeat_until']) || $next <= $rem['repeat_until'] . ' 23:59:59')) {
        q('UPDATE reminders SET last_sent_at = NOW(), send_count = send_count + 1, remind_at = ?, status = "pending" WHERE id = ?',
          [$next, $rem['id']]);
    } else {
        q('UPDATE reminders SET last_sent_at = NOW(), send_count = send_count + 1, status = "sent" WHERE id = ?', [$rem['id']]);
    }
    return [$sent, $failed];
}

// ---------- Loyalty points ----------
/** Adds (or, with a negative $points, deducts) loyalty points for a party
 *  and logs the change. The parties.loyalty_points column is a running
 *  cache kept in sync here, same pattern as stock's qty + stock_ledger. */
function loyalty_add($party_id, $points, $reason, $ref_type = '', $ref_id = null) {
    if (!$party_id || !$points) return;
    q('UPDATE parties SET loyalty_points = GREATEST(0, loyalty_points + ?) WHERE id = ?', [$points, $party_id]);
    q('INSERT INTO loyalty_ledger (party_id, points, reason, ref_type, ref_id, created_by) VALUES (?,?,?,?,?,?)',
      [$party_id, $points, $reason, $ref_type, $ref_id, $_SESSION['user_id'] ?? null]);
}

// ---------- Post-job feedback request ----------
/** Creates (or reuses, if already sent for this job) a feedback row and
 *  returns its public rating-page URL. */
function feedback_link($ref_type, $ref_id, $customer_name, $mobile) {
    $existing = row('SELECT token FROM feedback WHERE ref_type = ? AND ref_id = ?', [$ref_type, $ref_id]);
    if ($existing) return base_url('feedback.php?token=' . $existing['token']);
    $token = bin2hex(random_bytes(16));
    q('INSERT INTO feedback (ref_type, ref_id, token, customer_name, mobile) VALUES (?,?,?,?,?)',
      [$ref_type, $ref_id, $token, $customer_name, $mobile]);
    return base_url('feedback.php?token=' . $token);
}

// ---------- Bank accounts / payment methods / QR ----------
function default_bank_account() {
    static $b = false;
    if ($b === false) $b = row('SELECT * FROM bank_accounts WHERE is_active = 1 ORDER BY is_default DESC, id LIMIT 1');
    return $b;
}
function active_payment_methods() {
    static $l = null;
    if ($l === null) $l = all('SELECT * FROM payment_methods WHERE is_active = 1 ORDER BY sort_order, id');
    return $l;
}
function upi_uri($vpa, $payee, $amount, $note) {
    return 'upi://pay?pa=' . rawurlencode($vpa) . '&pn=' . rawurlencode($payee) .
           '&am=' . number_format((float)$amount, 2, '.', '') . '&cu=INR&tn=' . rawurlencode($note);
}
/** Fetch (and cache) a QR PNG for arbitrary data via a public QR API. Returns a local file path or null. */
function qr_png_path($data) {
    if (!$data) return null;
    $dir = dirname(__DIR__) . '/uploads/qrcache';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $path = $dir . '/' . md5($data) . '.png';
    if (is_file($path) && filesize($path) > 0) return $path;
    if (!function_exists('curl_init')) return null;
    $url = 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&margin=8&data=' . urlencode($data);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_SSL_VERIFYPEER => true]);
    $png = curl_exec($ch);
    $ok = $png !== false && curl_getinfo($ch, CURLINFO_HTTP_CODE) == 200 && substr($png, 1, 3) === 'PNG';
    curl_close($ch);
    if (!$ok) return null;
    file_put_contents($path, $png);
    return $path;
}
/** Relative web path (for <img src>) of the QR for this sale, or null if unavailable. */
function invoice_qr_web_path($sale) {
    $bank = default_bank_account();
    if (!$bank || !$bank['upi_id']) return null;
    $p = qr_png_path(upi_uri($bank['upi_id'], $bank['account_name'], $sale['total'], $sale['invoice_no']));
    if (!$p) return null;
    return 'uploads/qrcache/' . basename($p);
}

// ---------- Site credential vault (DVR/NVR passwords etc, encrypted at rest) ----------
function vault_encrypt($plain) {
    if ($plain === '' || $plain === null) return '';
    $key = hash('sha256', 'site_vault|' . APP_SECRET, true);
    $iv = random_bytes(16);
    $cipher = openssl_encrypt($plain, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    return $cipher === false ? '' : base64_encode($iv . $cipher);
}
function vault_decrypt($enc) {
    if ($enc === '' || $enc === null) return '';
    $raw = base64_decode($enc, true);
    if ($raw === false || strlen($raw) < 17) return '';
    $key = hash('sha256', 'site_vault|' . APP_SECRET, true);
    $plain = openssl_decrypt(substr($raw, 16), 'aes-256-cbc', $key, OPENSSL_RAW_DATA, substr($raw, 0, 16));
    return $plain === false ? '' : $plain;
}

// ---------- Online payment link (Razorpay Payment Links API) ----------
// $saleId (optional): when given, the Razorpay payment-link id is saved on
// that sale row so razorpay_webhook.php can later match the "payment_link.paid"
// event back to this exact bill and auto-mark it paid - without it, the app
// had no way to learn a customer had actually paid via the link.
function razorpay_payment_link($amount, $description, $customerName = '', $customerMobile = '', $referenceId = '', $saleId = null) {
    $keyId = setting('razorpay_key_id'); $keySecret = setting('razorpay_key_secret');
    if (!$keyId || !$keySecret || $amount <= 0 || !function_exists('curl_init')) return null;
    $payload = [
        'amount' => (int)round($amount * 100),
        'currency' => 'INR',
        'description' => mb_substr($description, 0, 250),
        'customer' => array_filter(['name' => $customerName, 'contact' => $customerMobile]),
        'notify' => ['sms' => false, 'email' => false],
        'reference_id' => $referenceId,
    ];
    $ch = curl_init('https://api.razorpay.com/v1/payment_links');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 15,
        CURLOPT_USERPWD => $keyId . ':' . $keySecret,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload),
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($resp === false || $code >= 300) return null;
    $data = json_decode($resp, true);
    if ($saleId && !empty($data['id'])) {
        q('UPDATE sales SET razorpay_link_id = ? WHERE id = ?', [$data['id'], $saleId]);
    }
    return $data['short_url'] ?? null;
}

// ---------- Misc ----------
function share_token() { return bin2hex(random_bytes(16)); }

function payment_status($total, $paid) {
    if ($paid <= 0.009) return 'due';
    if ($paid + 0.009 >= $total) return 'paid';
    return 'partial';
}

// ---------- AMC / recurring billing ----------
function amc_advance_date($date, $cycle) {
    $map = ['monthly' => '+1 month', 'quarterly' => '+3 months', 'half_yearly' => '+6 months', 'yearly' => '+1 year'];
    return date('Y-m-d', strtotime($date . ' ' . ($map[$cycle] ?? '+1 year')));
}

/** Auto-create a sale invoice for one AMC billing cycle. Returns
 *  ['sale_id'=>, 'invoice_no'=>, 'total'=>] on success, null on failure. */
function amc_generate_invoice($contract) {
    $item = row('SELECT * FROM items WHERE id = ?', [$contract['item_id']]);
    $company = row('SELECT * FROM companies WHERE id = ?', [$contract['company_id']]);
    $party = row('SELECT * FROM parties WHERE id = ?', [$contract['party_id']]);
    if (!$item || !$company || !$party) return null;
    $tr = $company['is_gst'] ? (float)$item['tax_rate'] : 0;
    $subtotal = (float)$contract['amount'];
    $tax = round($subtotal * $tr / 100, 2);
    $total = $subtotal + $tax;
    $today = today();
    $credit_days = (int)$party['credit_days'];
    $pdo = db();
    $pdo->beginTransaction();
    try {
        q('INSERT INTO sales (company_id, party_id, customer_name, customer_mobile, location_id, sale_date, price_type,
           credit_days, due_date, subtotal, tax_amount, total, paid, payment_mode, status, notes, created_by, share_token, amc_contract_id)
           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
          [$company['id'], $party['id'], $party['name'], $party['mobile'], $contract['location_id'], $today, 'retail',
           $credit_days, $credit_days ? date('Y-m-d', strtotime("$today +$credit_days days")) : null,
           $subtotal, $tax, $total, 0, 'credit', 'due', 'AMC renewal: ' . $contract['title'], $contract['created_by'], share_token(), $contract['id']]);
        $sale_id = insert_id();
        $invoice_no = $company['invoice_prefix'] . '-' . date('y') . '-' . str_pad($sale_id, 5, '0', STR_PAD_LEFT);
        q('UPDATE sales SET invoice_no = ? WHERE id = ?', [$invoice_no, $sale_id]);
        q('INSERT INTO sale_items (sale_id, item_id, qty, price, cost_price, tax_rate, total) VALUES (?,?,?,?,?,?,?)',
          [$sale_id, $item['id'], 1, $subtotal, 0, $tr, $subtotal]);
        $pdo->commit();
        return ['sale_id' => $sale_id, 'invoice_no' => $invoice_no, 'total' => $total];
    } catch (Exception $e) {
        $pdo->rollBack();
        return null;
    }
}

function status_badge($status) {
    $map = [
        'paid' => 'ok', 'accepted' => 'ok', 'completed' => 'ok', 'delivered' => 'ok', 'received_back' => 'ok', 'converted' => 'ok',
        'due' => 'bad', 'cancelled' => 'bad', 'rejected' => 'bad', 'returned_unrepaired' => 'bad',
        'partial' => 'warn', 'pending' => 'warn', 'sent' => 'warn', 'outsourced' => 'warn', 'started' => 'warn', 'in_progress' => 'warn',
        'received' => 'info', 'assigned' => 'info', 'open' => 'info', 'in_stock' => 'ok', 'with_staff' => 'warn', 'sold' => 'info', 'claim' => 'warn',
        'won' => 'ok', 'lost' => 'bad', 'resolved' => 'ok', 'closed' => 'info', 'done' => 'ok',
        'low' => 'info', 'medium' => 'warn', 'high' => 'warn', 'urgent' => 'bad',
    ];
    $cls = $map[$status] ?? 'info';
    return '<span class="badge badge-' . $cls . '">' . e(str_replace('_', ' ', $status)) . '</span>';
}

// ---------- Scheduled reports (WhatsApp text digest, processed by cron.php) ----------
/** Builds the WhatsApp message body for one report_schedules row. Kept
 *  intentionally short (a text summary, not the full report) since this is
 *  meant to be read on a phone, not a replacement for opening the report. */
function report_schedule_build_message($s) {
    $shop = setting('app_name', 'AK Computer');
    $locJoin = $s['location_id'] ? ' AND s.location_id = ' . (int)$s['location_id'] : '';
    $today = today();
    switch ($s['report_key']) {
        case 'daily':
            $t = row("SELECT COUNT(*) c, COALESCE(SUM(total),0) tot FROM sales WHERE is_cancelled = 0 AND sale_date = ?$locJoin", [$today]);
            $y = row("SELECT COUNT(*) c, COALESCE(SUM(total),0) tot FROM sales WHERE is_cancelled = 0 AND sale_date = DATE_SUB(?, INTERVAL 1 DAY)$locJoin", [$today]);
            return "*$shop* - Daily Sales ({$today})\nToday: *₹" . money($t['tot']) . "* ({$t['c']} bills)\nYesterday: ₹" . money($y['tot']) . " ({$y['c']} bills)";
        case 'business':
            $mStart = date('Y-m-01');
            $rev = (float)val("SELECT COALESCE(SUM(total),0) FROM sales WHERE is_cancelled = 0 AND sale_date BETWEEN ? AND ?$locJoin", [$mStart, $today]);
            $exp = (float)val("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE exp_date BETWEEN ? AND ?" . ($s['location_id'] ? ' AND location_id = ' . (int)$s['location_id'] : ''), [$mStart, $today]);
            $cost = (float)val("SELECT COALESCE(SUM(si.qty * i.purchase_price),0) FROM sale_items si JOIN sales s ON s.id = si.sale_id JOIN items i ON i.id = si.item_id
                                 WHERE s.is_cancelled = 0 AND s.sale_date BETWEEN ? AND ?$locJoin", [$mStart, $today]);
            return "*$shop* - Business Report (month to date)\nRevenue: ₹" . money($rev) . "\nExpenses: ₹" . money($exp) . "\nEst. Gross Profit: *₹" . money($rev - $cost - $exp) . "*";
        case 'branch_staff':
            $top = row("SELECT l.name, COALESCE(SUM(s.total),0) rev FROM locations l LEFT JOIN sales s ON s.location_id = l.id AND s.is_cancelled = 0 AND s.sale_date BETWEEN ? AND ?
                        WHERE l.is_active = 1 GROUP BY l.id ORDER BY rev DESC LIMIT 1", [date('Y-m-01'), $today]);
            $topStaff = row("SELECT u2.name, COALESCE(SUM(s.total),0) rev FROM users u2 LEFT JOIN sales s ON s.created_by = u2.id AND s.is_cancelled = 0 AND s.sale_date BETWEEN ? AND ?
                              WHERE u2.is_active = 1 GROUP BY u2.id ORDER BY rev DESC LIMIT 1", [date('Y-m-01'), $today]);
            return "*$shop* - Branch/Staff Leaders (month to date)\nTop branch: *" . ($top['name'] ?? '-') . "* (₹" . money($top['rev'] ?? 0) . ")\nTop staff: *" . ($topStaff['name'] ?? '-') . "* (₹" . money($topStaff['rev'] ?? 0) . ")\nSee full report in Reports > Branch/Staff Comparison.";
        case 'low':
            $items = low_stock_items($s['location_id'] ?: null);
            $names = array_slice(array_column($items, 'name'), 0, 5);
            return "*$shop* - Low Stock Alert\n" . count($items) . " item(s) below minimum" . ($names ? ":\n- " . implode("\n- ", $names) : '.') . (count($items) > 5 ? "\n...and " . (count($items) - 5) . ' more' : '');
        default:
            return "*$shop* - Scheduled report \"" . $s['name'] . '"';
    }
}

// ---------- Charts (hand-rolled inline SVG - no charting library in this
// project). Same visual idiom as the dashboard's original sales chart,
// pulled out into reusable helpers so trend/comparison reports don't each
// reimplement the SVG math. Both return a ready-to-echo HTML string. ----------
/** $data = [['label'=>..., 'val'=>...], ...]. Line + filled-area chart. */
function svg_line_chart($data, $color = '#1a56db') {
    if (!$data) return '<p class="muted">No data.</p>';
    $w = 600; $h = 220; $padL = 10; $padR = 10; $padT = 24; $padB = 34;
    $maxVal = max(1, max(array_column($data, 'val')));
    $iw = ($w - $padL - $padR) / (count($data) - 1 ?: 1);
    $pts = [];
    foreach ($data as $ci => $cv) {
        $x = $padL + $ci * $iw;
        $y = $padT + ($h - $padT - $padB) * (1 - $cv['val'] / $maxVal);
        $pts[] = [$x, $y, $cv];
    }
    $poly = implode(' ', array_map(fn($p) => round($p[0], 1) . ',' . round($p[1], 1), $pts));
    $area = "$padL," . ($h - $padB) . " $poly " . round(end($pts)[0], 1) . ',' . ($h - $padB);
    $rgb = sscanf($color, '#%02x%02x%02x');
    $fill = 'rgba(' . $rgb[0] . ',' . $rgb[1] . ',' . $rgb[2] . ',.12)';
    $fmt = fn($v) => $v >= 100000 ? round($v / 100000, 1) . 'L' : ($v >= 1000 ? round($v / 1000, 1) . 'k' : round($v));
    $out = '<div class="chart-wrap"><svg viewBox="0 0 600 220" preserveAspectRatio="xMidYMid meet">';
    $out .= '<polygon points="' . e($area) . '" fill="' . e($fill) . '"/>';
    $out .= '<polyline points="' . e($poly) . '" fill="none" stroke="' . e($color) . '" stroke-width="3" stroke-linejoin="round" stroke-linecap="round"/>';
    foreach ($pts as $p) {
        $out .= '<circle cx="' . round($p[0], 1) . '" cy="' . round($p[1], 1) . '" r="4.5" fill="' . e($color) . '"/>';
        $out .= '<text x="' . round($p[0], 1) . '" y="' . ($h - 12) . '" text-anchor="middle" font-size="13" fill="#64748b">' . e($p[2]['label']) . '</text>';
        if ($p[2]['val'] > 0) $out .= '<text x="' . round($p[0], 1) . '" y="' . (round($p[1], 1) - 10) . '" text-anchor="middle" font-size="11" fill="#334155">' . e($fmt($p[2]['val'])) . '</text>';
    }
    $out .= '</svg></div>';
    return $out;
}
/** $data = [['label'=>..., 'val'=>...], ...]. Horizontal bar chart - reads
 *  top-to-bottom, works well for a ranked comparison (branches, staff,
 *  top products) without needing to rotate axis labels. */
function svg_bar_chart($data, $color = '#1a56db') {
    if (!$data) return '<p class="muted">No data.</p>';
    $rowH = 32; $padL = 140; $padR = 70; $w = 600; $h = count($data) * $rowH + 16;
    $maxVal = max(1, max(array_column($data, 'val')));
    $barMaxW = $w - $padL - $padR;
    $fmt = fn($v) => $v >= 100000 ? round($v / 100000, 1) . 'L' : ($v >= 1000 ? round($v / 1000, 1) . 'k' : round($v, 1));
    $out = '<div class="chart-wrap"><svg viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="xMidYMid meet">';
    foreach ($data as $i => $d) {
        $y = $i * $rowH + 8;
        $bw = $maxVal > 0 ? $barMaxW * ($d['val'] / $maxVal) : 0;
        $out .= '<text x="' . ($padL - 10) . '" y="' . ($y + 16) . '" text-anchor="end" font-size="12" fill="#334155">' . e(mb_substr($d['label'], 0, 18)) . '</text>';
        $out .= '<rect x="' . $padL . '" y="' . $y . '" width="' . max(2, round($bw, 1)) . '" height="20" rx="4" fill="' . e($color) . '"/>';
        $out .= '<text x="' . ($padL + $bw + 8) . '" y="' . ($y + 16) . '" font-size="12" fill="#334155">' . e($fmt($d['val'])) . '</text>';
    }
    $out .= '</svg></div>';
    return $out;
}
