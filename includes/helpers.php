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

// ---------- Misc ----------
function share_token() { return bin2hex(random_bytes(16)); }

function payment_status($total, $paid) {
    if ($paid <= 0.009) return 'due';
    if ($paid + 0.009 >= $total) return 'paid';
    return 'partial';
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
