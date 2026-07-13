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

// ---------- Activity log ----------
function log_activity($action, $details = '') {
    $uid = $_SESSION['user_id'] ?? null;
    q('INSERT INTO activity_log (user_id, action, details) VALUES (?, ?, ?)', [$uid, $action, mb_substr($details, 0, 500)]);
}

// ---------- Document numbers ----------
function doc_no($prefix, $id) {
    return $prefix . '-' . str_pad($id, 5, '0', STR_PAD_LEFT);
}

// ---------- Stock helpers (always use inside a transaction for documents) ----------
function adjust_stock($item_id, $location_id, $delta, $ref_type, $ref_id = null, $note = '') {
    q('INSERT INTO stock (item_id, location_id, qty) VALUES (?, ?, ?)
       ON DUPLICATE KEY UPDATE qty = qty + VALUES(qty)', [$item_id, $location_id, $delta]);
    q('INSERT INTO stock_ledger (item_id, location_id, change_qty, ref_type, ref_id, note, created_by)
       VALUES (?, ?, ?, ?, ?, ?, ?)',
      [$item_id, $location_id, $delta, $ref_type, $ref_id, $note, $_SESSION['user_id'] ?? null]);
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

// ---------- OTP ----------
function create_otp($purpose, $target, $minutes = 10) {
    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    q('UPDATE otp_codes SET used = 1 WHERE purpose = ? AND target = ? AND used = 0', [$purpose, $target]);
    q('INSERT INTO otp_codes (purpose, target, code, expires_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE))',
      [$purpose, $target, $code, $minutes]);
    return $code;
}
function verify_otp($purpose, $target, $code) {
    $r = row('SELECT id FROM otp_codes WHERE purpose = ? AND target = ? AND code = ? AND used = 0 AND expires_at > NOW()
              ORDER BY id DESC LIMIT 1', [$purpose, $target, trim($code)]);
    if (!$r) return false;
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

// ---------- Invoice design themes ----------
function invoice_themes() {
    // id => [name, layout-classes, accent hex for preview]
    return [
        1 => ['Classic Blue', 'inv-band', '#1a56db'],
        2 => ['Royal Dark', 'inv-band', '#0f172a'],
        3 => ['Emerald', 'inv-band', '#047857'],
        4 => ['Maroon', 'inv-band', '#9f1239'],
        5 => ['Purple', 'inv-band', '#6d28d9'],
        6 => ['Teal', 'inv-band', '#0f766e'],
        7 => ['Orange', 'inv-band', '#c2410c'],
        8 => ['Slate Minimal', 'inv-accentline', '#334155'],
        9 => ['Blue Minimal', 'inv-accentline', '#1a56db'],
        10 => ['Green Minimal', 'inv-accentline', '#047857'],
        11 => ['Elegant Centre', 'inv-center inv-serif', '#0f172a'],
        12 => ['Gold Classic', 'inv-center inv-serif', '#a16207'],
        13 => ['Boxed Blue', 'inv-boxed', '#1a56db'],
        14 => ['Boxed Dark', 'inv-boxed', '#0f172a'],
        15 => ['Thermal Compact', 'inv-compact', '#0f172a'],
        16 => ['Gradient Indigo', 'inv-grad', '#1a56db'],
        17 => ['Gradient Sunset', 'inv-grad', '#db2777'],
        18 => ['Gradient Forest', 'inv-grad', '#0f766e'],
        19 => ['Zebra Maroon', 'inv-zebra', '#9f1239'],
        20 => ['Zebra Dark', 'inv-zebra', '#1e293b'],
    ];
}
function invoice_theme_class() {
    $n = (int)setting('invoice_theme', '1');
    $themes = invoice_themes();
    if (!isset($themes[$n])) $n = 1;
    return 'invt-' . $n . ' ' . $themes[$n][1];
}

// ---------- Party running-account balance (single source of truth) ----------
// Positive = party owes shop ("લેવાના" / You'll Get). Negative = shop owes
// party ("દેવાના" / You'll Give) - this also covers customer ADVANCES: a
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
function report_extra_where($alias, $fCompany, $fParty, $fStatus) {
    $where = ''; $params = [];
    if ($fCompany) { $where .= " AND $alias.company_id = ?"; $params[] = $fCompany; }
    if ($fParty) { $where .= " AND $alias.party_id = ?"; $params[] = $fParty; }
    if ($fStatus) { $where .= " AND $alias.status = ?"; $params[] = $fStatus; }
    return [$where, $params];
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
function invoice_theme_accent_rgb() {
    $n = (int)setting('invoice_theme', '1');
    $themes = invoice_themes();
    $hex = ltrim($themes[$n][2] ?? '#1a56db', '#');
    if (strlen($hex) !== 6) $hex = '1a56db';
    return [hexdec(substr($hex, 0, 2)) / 255, hexdec(substr($hex, 2, 2)) / 255, hexdec(substr($hex, 4, 2)) / 255];
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
function razorpay_payment_link($amount, $description, $customerName = '', $customerMobile = '', $referenceId = '') {
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
    ];
    $cls = $map[$status] ?? 'info';
    return '<span class="badge badge-' . $cls . '">' . e(str_replace('_', ' ', $status)) . '</span>';
}
