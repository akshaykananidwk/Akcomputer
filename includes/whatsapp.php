<?php
// WhatsApp API integration (bulk.akdwk.in)
// API URL, session id and key are stored in settings (Settings page).

function wa_normalize_number($mobile) {
    $n = preg_replace('/\D/', '', (string)$mobile);
    if (strlen($n) === 10) $n = '91' . $n;
    return $n;
}

/**
 * Send a WhatsApp message. Returns true on success.
 * $media_url (optional) sends an image/document with $message as caption.
 */
function send_whatsapp($mobile, $message, $media_url = '') {
    $api_url    = rtrim(setting('wa_api_url', 'https://bulk.akdwk.in/api.php'), '/');
    $session_id = setting('wa_session_id', '');
    $api_key    = setting('wa_api_key', '');
    $number     = wa_normalize_number($mobile);

    if (!$api_url || !$session_id || !$api_key || strlen($number) < 12) return false;

    $params = [
        'number' => $number,
        'message' => $message,
        'session_id' => $session_id,
        'api_key' => $api_key,
    ];
    if ($media_url) $params['media_url'] = $media_url;

    $url = $api_url . '?' . http_build_query($params);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $resp = curl_exec($ch);
        $ok = $resp !== false && curl_getinfo($ch, CURLINFO_HTTP_CODE) < 400;
        curl_close($ch);
        return $ok;
    }
    $ctx = stream_context_create(['http' => ['timeout' => 15]]);
    return @file_get_contents($url, false, $ctx) !== false;
}

function send_otp_whatsapp($mobile, $code, $reason = 'verification') {
    $shop = setting('app_name', 'AK Computer');
    $msg = "*$shop*\nYour OTP for $reason is: *$code*\nValid for 10 minutes. Do not share.";
    return send_whatsapp($mobile, $msg);
}
