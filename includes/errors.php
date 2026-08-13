<?php
// Production error capture + alerting.
//
// Before this file existed the app ran with display_errors=0 and no log
// destination at all: a fatal showed a blank page and told nobody. Now every
// PHP error, uncaught exception and fatal shutdown is written to
// uploads/logs/error.log (web-inaccessible) and, for the serious ones, sent
// to the admins on Telegram - throttled so one broken page in a loop can
// never spam the chat or the disk.
//
// Nothing here changes what the visitor sees: pages still never print an
// error. app_error() is the same pipe for handled failures that used to be
// swallowed silently inside try/catch - WhatsApp, Razorpay, AI, cron.

function error_log_path() {
    $dir = dirname(__DIR__) . '/uploads/logs';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    return $dir . '/error.log';
}

/** One line per event, appended. Rotated by cron once it passes 2 MB. */
function error_log_write($kind, $msg, $where = '') {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . strtoupper($kind) . ' | '
          . str_replace(["\r", "\n"], ' ', mb_substr((string)$msg, 0, 1000))
          . ($where !== '' ? ' | ' . $where : '')
          . ' | ' . ($_SERVER['REQUEST_METHOD'] ?? 'CLI') . ' ' . ($_SERVER['REQUEST_URI'] ?? (PHP_SAPI === 'cli' ? 'cli' : '-'))
          . ' | user=' . ($_SESSION['user_id'] ?? '-')
          . ' | ip=' . ($_SERVER['REMOTE_ADDR'] ?? '-') . "\n";
    @file_put_contents(error_log_path(), $line, FILE_APPEND | LOCK_EX);
}

/** Trim the log when it passes 2 MB (called from cron housekeeping). */
function error_log_rotate($maxBytes = 2097152) {
    $f = error_log_path();
    if (!is_file($f) || filesize($f) < $maxBytes) return 0;
    @rename($f, $f . '.1');
    return 1;
}

/** Telegram alert, throttled per fingerprint so a repeating fault alerts
 *  once per hour instead of every single request. */
function error_alert($kind, $msg, $where = '') {
    // the DB itself may be what failed - never let alerting throw a second
    // error on top of the first one
    try { if (setting('error_alerts', '1') !== '1') return; } catch (Throwable $e) { return; }
    $fp = md5($kind . '|' . mb_substr((string)$msg, 0, 200) . '|' . $where);
    try { $seen = json_decode((string)setting('error_alert_seen', '{}'), true) ?: []; } catch (Throwable $e) { $seen = []; }
    $now = time();
    foreach ($seen as $k => $t) if ($t < $now - 86400) unset($seen[$k]); // forget day-old fingerprints
    if (isset($seen[$fp]) && $seen[$fp] > $now - 3600) return;           // already alerted this hour
    $seen[$fp] = $now;
    try { set_setting('error_alert_seen', json_encode($seen)); } catch (Exception $e) {}
    $text = "🚨 *" . setting('app_name', 'AK Computer') . "* — " . strtoupper($kind) . "\n"
          . mb_substr((string)$msg, 0, 500) . "\n"
          . ($where !== '' ? "📄 " . $where . "\n" : '')
          . "🔗 " . (($_SERVER['REQUEST_METHOD'] ?? 'CLI') . ' ' . ($_SERVER['REQUEST_URI'] ?? 'cli')) . "\n"
          . "🕐 " . date('d-m-Y H:i');
    try { tg_notify_admins($text); } catch (Throwable $e) { /* alerting must never throw */ }
}

/** The one call every module uses for a failure it handled but should not
 *  hide: app_error('whatsapp', 'send failed: …'). $alert=false logs only. */
function app_error($kind, $msg, $where = '', $alert = true) {
    error_log_write($kind, $msg, $where);
    if ($alert) error_alert($kind, $msg, $where);
}

// ---------- global handlers ----------
set_error_handler(function ($no, $str, $file, $line) {
    if (!(error_reporting() & $no)) return false; // respects the @ operator and the configured level
    $where = basename($file) . ':' . $line;
    $fatalish = in_array($no, [E_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR], true);
    app_error($fatalish ? 'php-error' : 'php-warning', $str, $where, $fatalish);
    return true; // handled - nothing is printed to the page
});

set_exception_handler(function ($e) {
    app_error('exception', get_class($e) . ': ' . $e->getMessage(), basename($e->getFile()) . ':' . $e->getLine());
    if (PHP_SAPI !== 'cli' && !headers_sent()) http_response_code(500);
    if (PHP_SAPI === 'cli') {
        echo "Error: " . $e->getMessage() . "\n";
    } else {
        echo '<div style="font-family:system-ui;max-width:560px;margin:60px auto;padding:22px;border:1px solid #ddd;border-radius:12px">'
           . '<h2 style="margin:0 0 8px">કંઈક ગડબડ થઈ 😔</h2>'
           . '<p style="color:#555;margin:0 0 14px">આ ભૂલ નોંધાઈ ગઈ છે અને એડમિનને જાણ થઈ ગઈ છે. થોડી વારે ફરી પ્રયત્ન કરો.</p>'
           . '<a href="index.php" style="color:#2f5fd0">← ડેશબોર્ડ પર જાવ</a></div>';
    }
});

register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        app_error('fatal', $e['message'], basename($e['file']) . ':' . $e['line']);
    }
});
