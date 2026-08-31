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

/**
 * Month arithmetic that survives the 29th, 30th and 31st.
 *
 * PHP's strtotime('-1 month') means "the same day number, one month back", so
 * on 31 August it lands on 31 July, and '-2 months' lands on 1 July, because
 * 31 June does not exist and rolls forward. Ask for six months that way and on
 * a month-end day you get "Mar, May, May, Jul, Jul, Aug" - two months counted
 * twice and two missing entirely.
 *
 * That was live on four days of every month: the dashboard's six-month trend
 * lost April and June, and "sales last month vs the month before" compared
 * July with July and always reported no change.
 *
 * Anchoring to the FIRST of the month first makes the arithmetic exact,
 * because the 1st exists in every month.
 *
 * month_start(0) = this month's 1st, month_start(1) = last month's 1st.
 */
function month_start($back = 0, $from = null) {
    $base = $from ? date('Y-m-01', strtotime($from)) : date('Y-m-01');
    $back = (int)$back;
    return $back === 0 ? $base : date('Y-m-01', strtotime($base . ' -' . $back . ' months'));
}
/** Last day of that month. */
function month_end($back = 0, $from = null) { return date('Y-m-t', strtotime(month_start($back, $from))); }
/** Its "YYYY-MM" key. */
function month_key($back = 0, $from = null) { return date('Y-m', strtotime(month_start($back, $from))); }

/**
 * Move a real date forward or back by whole months, CLAMPING the day instead
 * of letting it roll over into the next month.
 *
 * Same trap as above, and it costs money here: an AMC billed monthly from the
 * 31st advanced to "31 February", which PHP turns into 3 March - so February
 * was never billed at all and every later bill drifted a day or two further.
 * Clamping gives 31 Jan -> 28 Feb -> 28 Mar: no month is ever skipped. The
 * day settles a few days earlier once and then stays put, which is the right
 * trade - a bill two days early beats a month never billed at all.
 */
function month_add($date, $months) {
    $t = strtotime($date);
    if ($t === false) return $date;
    $day = (int)date('d', $t);
    $firstOfTarget = date('Y-m-01', strtotime(date('Y-m-01', $t) . ' ' . ($months >= 0 ? '+' : '-') . abs((int)$months) . ' months'));
    $lastDay = (int)date('t', strtotime($firstOfTarget));
    return date('Y-m-', strtotime($firstOfTarget)) . str_pad((string)min($day, $lastDay), 2, '0', STR_PAD_LEFT);
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
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        // api.php (Bearer-token REST/mobile API) and razorpay_webhook.php
        // (HMAC-signature-verified inbound webhook) authenticate every
        // request their own way and have no browser session/CSRF token to
        // check against - both are genuinely different trust boundaries
        // from the rest of this cookie-session-based app.
        $exempt = ['api.php', 'razorpay_webhook.php', 'wa_webhook.php', 'telegram_webhook.php'];
        if (in_array(basename($_SERVER['SCRIPT_NAME'] ?? ''), $exempt, true)) return;
        if (!hash_equals(csrf_token(), (string)post('csrf'))) {
            http_response_code(400);
            die('Invalid request (CSRF). Go back and try again.');
        }
    }
}

// ---------- Settings ----------
// Third-party credentials are encrypted at rest (AES-256 via the same
// vault_encrypt/vault_decrypt pair the site-credential vault uses), so a
// database dump - including the nightly backup - never carries a usable
// live key. Encryption and decryption happen INSIDE setting()/set_setting(),
// which is why no call site changed: every existing setting('meta_wa_token')
// keeps returning the plain token. Values stored before this change stay
// readable and are upgraded in place by secrets_encrypt_existing().
const SECRET_PREFIX = 'enc:v1:';

function secret_setting_keys() {
    return ['meta_wa_token', 'razorpay_key_secret', 'razorpay_webhook_secret', 'gemini_api_key',
            'gemini_api_key_paid', 'wa_api_key', 'tg_bot_token', 'ocr_api_key', 'google_cse_key',
            'smtp_pass', 'wa_session_id', 'backup_passphrase'];
}
function is_secret_setting($name) { return in_array($name, secret_setting_keys(), true); }

function setting($name, $default = '', $poke = null) {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (all('SELECT name, value FROM settings') as $r) {
            $v = $r['value'];
            if (is_string($v) && strncmp($v, SECRET_PREFIX, strlen(SECRET_PREFIX)) === 0) {
                $v = vault_decrypt(substr($v, strlen(SECRET_PREFIX)));
            }
            $cache[$r['name']] = $v;
        }
    }
    // set_setting() calls this to keep the in-request cache honest - without
    // it, a value written and re-read in the same request returns the stale
    // one, which silently defeated the dashboard result cache.
    if ($poke !== null) { $cache[$name] = $poke; return $poke; }
    return array_key_exists($name, $cache) ? $cache[$name] : $default;
}
function set_setting($name, $value) {
    $stored = (string)$value;
    if (is_secret_setting($name) && $stored !== '') {
        $enc = vault_encrypt($stored);
        if ($enc !== '') $stored = SECRET_PREFIX . $enc; // openssl missing -> store as before rather than lose the key
    }
    q('INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [$name, $stored]);
    setting($name, '', (string)$value); // keep the in-request cache in step (plaintext side)
}

/** Encrypt a backup blob in the SAME "AKENC1" container the manual
 *  Settings download and its decrypt tool already use, so one file format
 *  and one decrypt path serve both. Returns the raw encrypted bytes. */
function backup_encrypt($sql, $passphrase) {
    $salt = random_bytes(16);
    $iv = random_bytes(16);
    $key = hash_pbkdf2('sha256', $passphrase, $salt, 100000, 32, true);
    $cipher = openssl_encrypt($sql, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    return $cipher === false ? '' : "AKENC1" . $salt . $iv . $cipher;
}

/** One-time upgrade of secrets already sitting in the table as plaintext.
 *  Idempotent and cheap (skips anything already encrypted); called by the
 *  migration runner and by the cron housekeeping job. */
function secrets_encrypt_existing() {
    $n = 0;
    foreach (secret_setting_keys() as $k) {
        try {
            $raw = val('SELECT value FROM settings WHERE name = ?', [$k]);
            if ($raw === null || $raw === '' ) continue;
            if (strncmp($raw, SECRET_PREFIX, strlen(SECRET_PREFIX)) === 0) continue; // already done
            $enc = vault_encrypt($raw);
            if ($enc === '') continue;
            q('UPDATE settings SET value = ? WHERE name = ?', [SECRET_PREFIX . $enc, $k]);
            $n++;
        } catch (Exception $e) { /* never block a page over this */ }
    }
    return $n;
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

/**
 * Who may accept this handover.
 *
 * ONE rule, used by both the POST handler and the screen that draws the OTP
 * box. They disagreed before: the handler let the staff member accept an
 * "issue", but the screen only ever drew the box for someone with
 * handover.accept - so the staff member the stock was issued TO opened the
 * page, was told "accept this from your My Stock page", and found no way to
 * type the OTP anywhere on it. Reported from the shop floor as
 * "OTP enter karva no option j nay avto".
 *
 * An issue is still acceptable ONLY by the staff member it is for. That is the
 * whole point of the OTP - it proves the person physically took the goods -
 * and no admin permission overrides it.
 */
function handover_can_accept(array $h, $u = null) {
    $cur = current_user();
    $u = $u ?: $cur;
    if (!$u || ($h['status'] ?? '') !== 'pending') return false;
    // can() always answers for the LOGGED-IN user, so a permission may only be
    // granted when $u IS that user. Asked about somebody else, this falls back
    // to the identity check alone rather than handing them a permission nobody
    // verified - conservative on purpose.
    $isCurrent = $cur && (int)$cur['id'] === (int)$u['id'];
    if (($h['type'] ?? '') === 'issue') {
        // The staff member it was issued to, obviously. And ALSO whoever holds
        // handover.accept - the owner asked for this twice from the shop floor:
        // the staff member had the OTP on WhatsApp and could not, or would not,
        // open their own screen, and the stock sat in limbo counting nowhere.
        //
        // This is not a bypass. The correct OTP is still required, and it only
        // ever went to the staff member's phone - so the owner has to have it
        // read out to them, which is exactly the proof the OTP exists to give.
        // What changes is only WHO may type it in. accepted_by records the
        // person who did, so the trail still says what really happened.
        return (int)$h['staff_id'] === (int)$u['id'] || ($isCurrent && can('handover.accept'));
    }
    return $isCurrent && can('handover.accept');   // returns and transfers are accepted at the shop
}

/** Is this person accepting on someone else's behalf? Used to say so on the
 *  screen and to mark it in the activity log. */
function handover_accepting_for_other(array $h, $u = null) {
    $u = $u ?: current_user();
    return $u && ($h['type'] ?? '') === 'issue' && (int)$h['staff_id'] !== (int)$u['id'];
}

/**
 * What the PUBLIC website should say about one product's availability.
 *
 * Every product card used to read "✔ In stock" whatever the shelf held,
 * including items that ran out months ago - so a customer who came in for one
 * was told something untrue by our own website. This is the one place that
 * decides, so the catalogue grid and the product page can never disagree.
 *
 * Returns [class, text, note]. Services have no shelf and never claim a
 * quantity. An item that is OUT says so whether or not quantities are shown -
 * that half is honesty, not a display preference.
 */
function web_stock_line($it, array $stockMap, $showQty = true) {
    if (($it['item_type'] ?? '') === 'service') return ['svc', '🛠️ સેવા', ''];
    $q = (float)($stockMap[(int)$it['id']] ?? 0);
    if ($q <= 0) return ['out', '✖ અત્યારે ખલાસ', 'ઓર્ડર પર મંગાવી આપીશું'];
    $n = rtrim(rtrim(number_format($q, 2), '0'), '.');
    $unit = trim((string)($it['unit'] ?? ''));
    return ['in', $showQty ? '✔ ' . $n . ' ' . ($unit !== '' ? $unit : 'નંગ') . ' સ્ટોકમાં' : '✔ In stock', ''];
}

/** Stock for every item in ONE query - the website lists hundreds of products
 *  and must never ask per card. */
function web_stock_map() {
    $out = [];
    foreach (all('SELECT item_id, SUM(qty) q FROM stock GROUP BY item_id') as $s)
        $out[(int)$s['item_id']] = (float)$s['q'];
    return $out;
}

/** Does the shop want exact quantities on its public pages? The owner asked
 *  for them, so this defaults ON - but it is a public page, competitors
 *  included, so it can be turned off in Settings without touching code. */
function web_show_qty() { return setting('store_show_qty', '1') === '1'; }

/**
 * Stock that has left one place and not yet arrived anywhere - the items on
 * pending handovers.
 *
 * A handover removes stock from the source the moment it is created, and only
 * adds it to the destination when the OTP is accepted. In between, the goods
 * count in NEITHER place, which is correct (they cannot be sold twice) but was
 * completely invisible: the shop showed 0, the godown showed nothing, and the
 * only honest answer - "it is on HO-00015, waiting to be accepted" - appeared
 * on no screen at all.
 */
function stock_in_transit($item_id = null) {
    $where = $item_id ? ' AND hi.item_id = ' . (int)$item_id : '';
    return all("SELECT h.id, h.handover_no, h.type, h.created_at, hi.item_id, hi.qty,
                       i.name item_name, l.name from_loc, tl.name to_loc, us.name staff_name
                FROM handover_items hi
                JOIN handovers h ON h.id = hi.handover_id AND h.status = 'pending'
                JOIN items i ON i.id = hi.item_id
                LEFT JOIN locations l ON l.id = h.location_id
                LEFT JOIN locations tl ON tl.id = h.to_location_id
                LEFT JOIN users us ON us.id = h.staff_id
                WHERE 1 $where
                ORDER BY h.id DESC");
}

/** Where a transit row is headed, in the owner's words. */
function transit_destination(array $t) {
    if ($t['type'] === 'issue') return trim((string)$t['staff_name']) !== '' ? $t['staff_name'] : 'સ્ટાફ';
    if ($t['type'] === 'transfer') return trim((string)$t['to_loc']) !== '' ? $t['to_loc'] : 'બીજી જગ્યા';
    return trim((string)$t['from_loc']) !== '' ? $t['from_loc'] : 'દુકાન';
}

function adjust_staff_stock($user_id, $item_id, $delta, $ref_type, $ref_id = null, $note = '') {
    q('INSERT INTO staff_stock (user_id, item_id, qty) VALUES (?, ?, ?)
       ON DUPLICATE KEY UPDATE qty = qty + VALUES(qty)', [$user_id, $item_id, $delta]);
    q('INSERT INTO stock_ledger (item_id, user_id, change_qty, ref_type, ref_id, note, created_by)
       VALUES (?, ?, ?, ?, ?, ?, ?)',
      [$item_id, $user_id, $delta, $ref_type, $ref_id, $note, $_SESSION['user_id'] ?? null]);
}

/** Cache-busting version for a file in assets/ - the file's own mtime, so
 *  every "Settings > Update" automatically forces phones to fetch the new
 *  CSS/JS instead of serving a stale 7-day-cached copy (that stale cache is
 *  how a fixed bug kept LOOKING broken on the owner's phone). */
function asset_v($file) {
    static $c = [];
    if (!isset($c[$file])) { $v = @filemtime(__DIR__ . '/../assets/' . $file); $c[$file] = $v ?: 1; }
    return $c[$file];
}

/** sale_items.location_id ships in migrate v45 - detect it so billing keeps
 *  working on the live site between "Update" (new code) and "Migrate". */
function sale_line_loc_ready() {
    static $ok = null;
    if ($ok === null) { try { q('SELECT location_id FROM sale_items LIMIT 1'); $ok = true; } catch (Exception $e) { $ok = false; } }
    return $ok;
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

// Stock reservations were removed (unused for this shop) - available = on-hand.
function stock_available_qty($item_id, $location_id) {
    return stock_qty($item_id, $location_id);
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

// ---------- Party running-account balance ----------
// party_balance(), party_balance_expr(), opening_due() and sale_true_due()
// now live in includes/money.php together with the settlement rules they
// belong with. They are unchanged; see that file for the full explanation.

// ---------- Staff cash wallets & internal money movements ----------
/** How much CASH one staff member is holding right now. Every cash payment
 *  row carries created_by, so the shop's cash naturally partitions by who
 *  collected/spent it; money_transfers moves it between wallets / to-from
 *  the bank / adjusts it. Only status='done' transfers count - a pending
 *  staff handover (waiting for the receiver's OTP) moves nothing yet. */
function staff_cash($userId) {
    $uid = (int)$userId;
    return (float)val("SELECT
        COALESCE((SELECT SUM(amount) FROM payments WHERE mode='cash' AND direction='in' AND created_by=$uid),0)
      - COALESCE((SELECT SUM(amount) FROM payments WHERE mode='cash' AND direction='out' AND created_by=$uid),0)
      - COALESCE((SELECT SUM(amount) FROM expenses WHERE mode='cash' AND created_by=$uid),0)
      + COALESCE((SELECT SUM(amount) FROM money_transfers WHERE status='done' AND to_user_id=$uid AND txn_type IN ('staff_transfer','bank_to_cash')),0)
      - COALESCE((SELECT SUM(amount) FROM money_transfers WHERE status='done' AND from_user_id=$uid AND txn_type IN ('staff_transfer','cash_to_bank')),0)
      + COALESCE((SELECT SUM(amount) FROM money_transfers WHERE status='done' AND from_user_id=$uid AND txn_type='cash_adjust' AND adjust_dir='add'),0)
      - COALESCE((SELECT SUM(amount) FROM money_transfers WHERE status='done' AND from_user_id=$uid AND txn_type='cash_adjust' AND adjust_dir='reduce'),0)");
}

/** Total cash in hand across the whole shop (all wallets together). */
function total_cash_in_hand() {
    return (float)val("SELECT
        COALESCE((SELECT SUM(amount) FROM payments WHERE mode='cash' AND direction='in'),0)
      - COALESCE((SELECT SUM(amount) FROM payments WHERE mode='cash' AND direction='out'),0)
      - COALESCE((SELECT SUM(amount) FROM expenses WHERE mode='cash'),0)
      - COALESCE((SELECT SUM(amount) FROM money_transfers WHERE status='done' AND txn_type='cash_to_bank'),0)
      + COALESCE((SELECT SUM(amount) FROM money_transfers WHERE status='done' AND txn_type='bank_to_cash'),0)
      + COALESCE((SELECT SUM(amount) FROM money_transfers WHERE status='done' AND txn_type='cash_adjust' AND adjust_dir='add'),0)
      - COALESCE((SELECT SUM(amount) FROM money_transfers WHERE status='done' AND txn_type='cash_adjust' AND adjust_dir='reduce'),0)");
}

/** One bank account's live balance, transfers and adjustments included. */
function bank_account_balance($bankId) {
    $bid = (int)$bankId;
    return (float)val("SELECT b.opening_balance
      + COALESCE((SELECT SUM(amount) FROM payments WHERE bank_account_id=b.id AND direction='in'),0)
      - COALESCE((SELECT SUM(amount) FROM payments WHERE bank_account_id=b.id AND direction='out'),0)
      - COALESCE((SELECT SUM(amount) FROM expenses WHERE bank_account_id=b.id),0)
      + COALESCE((SELECT SUM(amount) FROM money_transfers WHERE status='done' AND to_bank_id=b.id AND txn_type IN ('cash_to_bank','bank_to_bank')),0)
      - COALESCE((SELECT SUM(amount) FROM money_transfers WHERE status='done' AND from_bank_id=b.id AND txn_type IN ('bank_to_cash','bank_to_bank')),0)
      + COALESCE((SELECT SUM(amount) FROM money_transfers WHERE status='done' AND to_bank_id=b.id AND txn_type='bank_adjust' AND adjust_dir='add'),0)
      - COALESCE((SELECT SUM(amount) FROM money_transfers WHERE status='done' AND to_bank_id=b.id AND txn_type='bank_adjust' AND adjust_dir='reduce'),0)
      FROM bank_accounts b WHERE b.id = ?", [$bid]);
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
    // month_add() so a reminder set for the 31st does not skip February
    if ($freq === 'monthly') return month_add(date('Y-m-d', $t), 1) . date(' H:i:s', $t);
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
// ---------- Public website (catalog.php / product.php) ----------
/** Dealer price: the selling price reduced by a web account's discount % -
 *  the ONE place this maths lives, used for display AND order pricing so the
 *  browser is never trusted with it. */
function dealer_price($selling, $pct) {
    return $pct > 0 ? round((float)$selling * (1 - $pct / 100), 2) : (float)$selling;
}
/** Emoji for a category name - purely cosmetic, keyword-matched. */
function cat_icon($name) {
    $n = mb_strtolower((string)$name);
    foreach (['cctv' => '📹', 'camera' => '📹', 'laptop' => '💻', 'computer' => '🖥️', 'desktop' => '🖥️', 'monitor' => '🖥️',
              'printer' => '🖨️', 'cable' => '🔌', 'wire' => '🔌', 'power' => '🔌', 'ups' => '🔋', 'battery' => '🔋',
              'storage' => '💾', 'hdd' => '💾', 'ssd' => '💾', 'pen' => '💾', 'memory' => '💾', 'ram' => '💾',
              'network' => '📡', 'router' => '📡', 'wifi' => '📡', 'keyboard' => '⌨️', 'mouse' => '🖱️',
              'speaker' => '🔊', 'audio' => '🔊', 'headphone' => '🎧', 'cctv accessories' => '📹', 'service' => '🛠️'] as $k => $ico) {
        if (strpos($n, $k) !== false) return $ico;
    }
    return '📦';
}
/** Counts one row per visitor per day (guests only; staff browsing the shop's
 *  own site would inflate the numbers). The hash holds no personal data. */
function site_visit_track($page = '') {
    if (current_user()) return;
    $vh = substr(sha1(($_SERVER['REMOTE_ADDR'] ?? '') . '|' . ($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 40);
    try {
        q('INSERT INTO site_visits (visit_date, visitor_hash, views) VALUES (CURDATE(), ?, 1)
           ON DUPLICATE KEY UPDATE views = views + 1', [$vh]);
    } catch (Exception $e) { /* table not migrated yet - never break the public site */ }
}

/** Resolve a posted payment_mode code to [payment_method_id|null, bank_account_id|null].
 *  The bank id is kept ONLY for bank-type modes (falling back to the default
 *  account when none was picked). This guard matters: the bank <select> also
 *  submits when hidden behind a cash mode, and bank balances sum payments by
 *  bank_account_id alone — a cash payment carrying a stray bank id would
 *  silently drain the bank ledger. */
function resolve_payment_target($modeCode, $postedBankId) {
    $pm = row('SELECT id, type FROM payment_methods WHERE code = ?', [$modeCode]);
    $pmId = $pm ? (int)$pm['id'] : null;
    $bank = null;
    if ($pm && $pm['type'] === 'bank') {
        $bank = (int)$postedBankId ?: ((int)val('SELECT id FROM bank_accounts WHERE is_active = 1 ORDER BY is_default DESC, id LIMIT 1') ?: null);
    }
    return [$pmId, $bank];
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

// ---------- Item custom fields: print visibility ----------
// Each custom field (Settings > Transaction) is either customer-facing
// (bill view, print, PDF, WhatsApp copy) or internal-only (staff screen
// only). Labels missing from the table (deleted fields on old bills, or
// the DB not yet migrated) stay customer-facing like before.
function custom_fields_print_map() {
    static $map = null;
    if ($map === null) {
        $map = [];
        try {
            foreach (all('SELECT label, show_on_print FROM item_custom_fields') as $f) $map[$f['label']] = (int)$f['show_on_print'];
        } catch (Exception $e) {}
    }
    return $map;
}
function custom_field_printable($label) {
    $m = custom_fields_print_map();
    return !isset($m[$label]) || $m[$label] === 1;
}

// ---------- Telegram (management bot) outbound ----------
function tg_call($method, array $params, $isMultipart = false) {
    $token = setting('tg_bot_token', '');
    if ($token === '') return null;
    $ch = curl_init('https://api.telegram.org/bot' . $token . '/' . $method);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 60,
        CURLOPT_POSTFIELDS => $isMultipart ? $params : http_build_query($params), CURLOPT_SSL_VERIFYPEER => true]);
    $r = curl_exec($ch);
    curl_close($ch);
    return json_decode((string)$r, true);
}
/** Telegram chat ids of every ACTIVE full-admin who has linked Telegram.
 *  Empty array (never an exception) before migrate v38 runs. */
function tg_admin_chats() {
    try {
        return array_column(all("SELECT u.telegram_chat_id FROM users u JOIN roles r ON r.id = u.role_id
            WHERE u.is_active = 1 AND u.telegram_chat_id IS NOT NULL AND u.telegram_chat_id <> ''
              AND r.permissions LIKE '%\"*\"%'"), 'telegram_chat_id');
    } catch (Exception $e) { return []; }
}
function tg_notify_admins($text) {
    $n = 0;
    foreach (tg_admin_chats() as $chat) {
        $r = tg_call('sendMessage', ['chat_id' => $chat, 'text' => $text]);
        if ($r['ok'] ?? false) $n++;
    }
    return $n;
}

// ---------- Full database dump (same bytes the Settings > Backup button
// downloads; also gzipped + sent to the admin's Telegram by the daily cron) ----------
function db_backup_sql() {
    $pdo = db();
    ob_start();
    echo "-- AK Computer backup " . date('Y-m-d H:i:s') . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n";
    $tables = array_column($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM), 0);
    foreach ($tables as $t) {
        $create = $pdo->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_NUM);
        echo "DROP TABLE IF EXISTS `$t`;\n" . $create[1] . ";\n\n";
        $rs = $pdo->query("SELECT * FROM `$t`");
        while ($rowD = $rs->fetch(PDO::FETCH_NUM)) {
            $vals = array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v), $rowD);
            echo "INSERT INTO `$t` VALUES (" . implode(',', $vals) . ");\n";
        }
        echo "\n";
    }
    echo "SET FOREIGN_KEY_CHECKS=1;\n";
    return ob_get_clean();
}

// ---------- Default profit margin ----------
// Every product must earn at least this % over its purchase price. An item's
// own margin_pct (> 0) overrides it - that's the deliberate escape hatch for
// items priced differently. 0 in Settings switches the rule off.
function default_margin_pct() {
    return (float)setting('default_margin_pct', '30');
}
/** Lift an item's selling price to at least purchase price + margin.
 *  Explicit per-item margin recalculates exactly (old behaviour); the
 *  default margin only ever RAISES a price, never lowers one the owner
 *  set higher. Services and zero-cost items are left alone. */
function enforce_min_margin($itemId) {
    $it = row("SELECT purchase_price, selling_price, margin_pct, item_type FROM items WHERE id = ?", [$itemId]);
    if (!$it || $it['item_type'] === 'service' || (float)$it['purchase_price'] <= 0) return;
    if ((float)$it['margin_pct'] > 0) {
        q('UPDATE items SET selling_price = ROUND(purchase_price * (1 + margin_pct / 100), 2) WHERE id = ?', [$itemId]);
    } elseif (default_margin_pct() > 0) {
        $minSell = round((float)$it['purchase_price'] * (1 + default_margin_pct() / 100), 2);
        if ((float)$it['selling_price'] < $minSell) {
            q('UPDATE items SET selling_price = ? WHERE id = ?', [$minSell, $itemId]);
        }
    }
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

// payment_status() moved to includes/money.php (unchanged).

// ---------- AMC / recurring billing ----------
function amc_advance_date($date, $cycle) {
    // whole months, with the day clamped - see month_add(). A contract billed
    // monthly from the 31st used to jump 31 Jan -> 3 Mar and skip February.
    $map = ['monthly' => 1, 'quarterly' => 3, 'half_yearly' => 6, 'yearly' => 12];
    return month_add($date, $map[$cycle] ?? 12);
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
            // same cost rule as the on-screen Business Report, so the digest
            // that lands on WhatsApp cannot quote a different profit
            $cost = (float)val("SELECT COALESCE(SUM(" . profit_cost_sql() . "),0) FROM sale_items si JOIN sales s ON s.id = si.sale_id JOIN items i ON i.id = si.item_id
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

/** Usage meter for Cost Analytics: one row per API call/message. Tolerant of
 *  the table not existing yet (pre-migrate v49) - never breaks the caller.
 *  gemini: units = tokens (in/out) · whatsapp: units_out = 1 message ·
 *  future services (sms/email/ocr/maps/...) just pass a new $service. */
function api_usage_log($service, $provider = '', $in = 0, $out = 0) {
    try {
        q('INSERT INTO api_usage (service, provider, units_in, units_out) VALUES (?,?,?,?)',
          [mb_substr($service, 0, 20), mb_substr($provider, 0, 60), (int)$in, (int)$out]);
    } catch (Exception $e) { /* metering must never block work */ }
}
