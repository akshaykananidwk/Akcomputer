<?php
// Official Meta (Facebook) WhatsApp Cloud API - the second provider next to
// the third-party gateway (includes/whatsapp.php). Which one goes first is
// the wa_provider_order setting; send_whatsapp() tries the primary and falls
// back to the other automatically.
//
// Business-initiated messages on the official API are only allowed inside a
// 24-hour customer window; outside it Meta demands a pre-APPROVED template.
// So this file also owns the template lifecycle: our utility templates are
// auto-SUBMITTED to Meta on "Connect & Sync" (no manual work in Business
// Manager) and their live status (Approved/Pending/Rejected/Draft) is pulled
// back and cached for the Settings page.

function meta_wa_configured() {
    return setting('meta_wa_token') !== '' && setting('meta_wa_phone_id') !== '';
}

/** Graph API call. Returns [ok(bool), data(array), err(string)]. */
function meta_wa_call($method, $path, $payload = null) {
    $url = 'https://graph.facebook.com/v21.0/' . ltrim($path, '/');
    $ch = curl_init($url);
    $headers = ['Authorization: Bearer ' . setting('meta_wa_token')];
    $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_SSL_VERIFYPEER => true];
    if ($method === 'POST') {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $headers[] = 'Content-Type: application/json';
    }
    $opts[CURLOPT_HTTPHEADER] = $headers;
    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode((string)$resp, true) ?: [];
    if ($resp === false) return [false, [], 'Network error reaching graph.facebook.com'];
    if ($http >= 400 || isset($data['error'])) {
        $e = $data['error'] ?? [];
        return [false, $data, trim(($e['message'] ?? ('HTTP ' . $http)) . (isset($e['error_data']['details']) ? ' - ' . $e['error_data']['details'] : ''))];
    }
    return [true, $data, ''];
}

/** Template parameters must not contain newlines/tabs - flatten the app's
 *  multi-line messages into one " | "-separated line for the fallback. */
function meta_wa_flatten($msg, $max = 950) {
    $t = preg_replace('/[ \t]*\R+[ \t]*/u', ' | ', trim((string)$msg));
    $t = preg_replace('/\s{2,}/u', ' ', $t);
    return mb_substr($t, 0, $max);
}

/** The utility templates this app auto-submits for approval. Keep names
 *  stable - Meta treats a name+language pair as one template forever. */
function meta_wa_template_catalog() {
    $shop = setting('app_name', 'AK Computer');
    return [
        'akc_update' => [
            'category' => 'UTILITY', 'language' => 'en',
            'components' => [[
                'type' => 'BODY',
                'text' => "Dear customer, an update from {$shop}:\n\n{{1}}\n\nThank you.",
                'example' => ['body_text' => [['Invoice INV-26-00042 of Rs.5,500 is ready. View: https://shop.akdwk.in']]],
            ]],
        ],
        'akc_reminder' => [
            'category' => 'UTILITY', 'language' => 'en',
            'components' => [[
                'type' => 'BODY',
                'text' => "Dear customer, this is a payment reminder from {$shop}.\n\n{{1}}\n\nKindly arrange the payment. Thank you.",
                'example' => ['body_text' => [['Balance due Rs.2,500 against invoice INV-26-00040']]],
            ]],
        ],
        'akc_bill' => [
            'category' => 'UTILITY', 'language' => 'en',
            'components' => [[
                'type' => 'BODY',
                'text' => "Dear customer, your invoice {{1}} of Rs. {{2}} from {{3}} is ready.\n\nView or download your bill here: {{4}}\n\nThank you for your business.",
                'example' => ['body_text' => [['INV-26-00042', '5,500.00', $shop, 'https://shop.akdwk.in/sale_view.php?share=abc123']]],
            ]],
        ],
        'akc_receipt' => [
            'category' => 'UTILITY', 'language' => 'en',
            'components' => [[
                'type' => 'BODY',
                'text' => "Dear customer, we have received your payment of Rs. {{1}} on {{2}}. Your balance is now {{3}}.\n\nThank you - {{4}}",
                'example' => ['body_text' => [['2,500.00', '02-08-2026', 'Rs. 0.00 (clear)', $shop]]],
            ]],
        ],
        // OTP: Meta only allows the AUTHENTICATION category for codes - the
        // body text is fixed by Meta, we just declare the copy-code button.
        'akc_otp' => [
            'category' => 'AUTHENTICATION', 'language' => 'en',
            'components' => [
                ['type' => 'BODY', 'add_security_recommendation' => true],
                ['type' => 'FOOTER', 'code_expiration_minutes' => 10],
                ['type' => 'BUTTONS', 'buttons' => [['type' => 'OTP', 'otp_type' => 'COPY_CODE']]],
            ],
        ],
    ];
}

/** Send via the Cloud API. Free-form first (works inside the 24h window);
 *  if Meta refuses because the window is closed, fall back to the approved
 *  akc_update template with the flattened message (+ media link as text). */
function meta_wa_send($mobile, $message, $media_url = '') {
    $GLOBALS['_wa_last_error'] = '';
    $number = wa_normalize_number($mobile);
    if (!meta_wa_configured() || strlen($number) < 12) {
        $GLOBALS['_wa_last_error'] = 'Meta Cloud API not configured (token / phone number id) or bad mobile.';
        return false;
    }
    $phoneId = setting('meta_wa_phone_id');

    if ($media_url) {
        $isImg = preg_match('/\.(jpe?g|png|webp)(\?|$)/i', $media_url);
        $payload = ['messaging_product' => 'whatsapp', 'to' => $number,
                    'type' => $isImg ? 'image' : 'document',
                    ($isImg ? 'image' : 'document') => array_filter([
                        'link' => $media_url,
                        'caption' => mb_substr($message, 0, 1024),
                        'filename' => $isImg ? null : basename(parse_url($media_url, PHP_URL_PATH)),
                    ])];
    } else {
        $payload = ['messaging_product' => 'whatsapp', 'to' => $number,
                    'type' => 'text', 'text' => ['preview_url' => true, 'body' => mb_substr($message, 0, 4096)]];
    }
    [$ok, $data, $err] = meta_wa_call('POST', "$phoneId/messages", $payload);
    if ($ok) return true;

    // 131047 / 131026: outside the 24h window -> an approved template is the
    // only way in. wa_context() tells us WHAT is being sent (otp / bill /
    // receipt / reminder) so the matching approved template carries it;
    // anything else rides the generic akc_update.
    $code = (int)($data['error']['code'] ?? 0);
    if (in_array($code, [131047, 131026, 470], true)) {
        $ctx = is_array($GLOBALS['_wa_ctx'] ?? null) ? $GLOBALS['_wa_ctx'] : [];
        $st = json_decode(setting('meta_wa_tpl_status', ''), true) ?: [];
        $approved = fn($n) => ($st[$n]['status'] ?? '') === 'APPROVED';
        $kind = $ctx['kind'] ?? '';
        $tpl = null;

        if ($kind === 'otp' && $approved('akc_otp') && ($ctx['code'] ?? '') !== '') {
            $tpl = ['name' => 'akc_otp', 'language' => ['code' => 'en'], 'components' => [
                ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => $ctx['code']]]],
                ['type' => 'button', 'sub_type' => 'url', 'index' => '0',
                 'parameters' => [['type' => 'text', 'text' => $ctx['code']]]],
            ]];
        } elseif ($kind === 'bill' && $approved('akc_bill') && ($ctx['invoice'] ?? '') !== '') {
            $tpl = ['name' => 'akc_bill', 'language' => ['code' => 'en'], 'components' => [[
                'type' => 'body', 'parameters' => array_map(fn($v) => ['type' => 'text', 'text' => meta_wa_flatten($v, 300)],
                    [$ctx['invoice'], $ctx['total'] ?? '', $ctx['firm'] ?? setting('app_name', 'AK Computer'), $ctx['link'] ?? ($media_url ?: '')]),
            ]]];
        } elseif ($kind === 'receipt' && $approved('akc_receipt') && ($ctx['amount'] ?? '') !== '') {
            $tpl = ['name' => 'akc_receipt', 'language' => ['code' => 'en'], 'components' => [[
                'type' => 'body', 'parameters' => array_map(fn($v) => ['type' => 'text', 'text' => meta_wa_flatten($v, 300)],
                    [$ctx['amount'], $ctx['date'] ?? dmy(today()), $ctx['balance'] ?? '-', $ctx['shop'] ?? setting('app_name', 'AK Computer')]),
            ]]];
        }
        if ($tpl === null) {
            // generic carrier - a reminder prefers the reminder wording
            $prefs = $kind === 'reminder' ? ['akc_reminder', 'akc_update'] : ['akc_update', 'akc_reminder'];
            $tplName = $prefs[0];
            foreach ($prefs as $cand) if ($approved($cand)) { $tplName = $cand; break; }
            $body = meta_wa_flatten($message . ($media_url ? ' | Download: ' . $media_url : ''));
            $tpl = ['name' => $tplName, 'language' => ['code' => 'en'],
                    'components' => [['type' => 'body', 'parameters' => [['type' => 'text', 'text' => $body]]]]];
        }
        [$ok2, $d2, $err2] = meta_wa_call('POST', "$phoneId/messages", [
            'messaging_product' => 'whatsapp', 'to' => $number, 'type' => 'template', 'template' => $tpl,
        ]);
        if ($ok2) return true;
        $GLOBALS['_wa_last_error'] = 'Meta template send failed: ' . $err2;
        return false;
    }
    $GLOBALS['_wa_last_error'] = 'Meta: ' . $err;
    return false;
}

/** Auto-submit any catalog template Meta doesn't have yet and pull the live
 *  status of all of them. Returns name => [status, category, reason]; also
 *  cached in settings for the Settings page and the cron refresher.
 *  Status: APPROVED / PENDING / REJECTED / DRAFT (= not submitted yet). */
function meta_wa_sync_templates() {
    $waba = setting('meta_wa_waba_id');
    if (!meta_wa_configured() || $waba === '') {
        return ['_error' => 'Fill the Access Token, Phone Number ID and WABA ID first.'];
    }
    [$ok, $data, $err] = meta_wa_call('GET', "$waba/message_templates?fields=name,status,category,language,rejected_reason&limit=200");
    if (!$ok) return ['_error' => 'Could not read templates from Meta: ' . $err];
    $existing = [];
    foreach (($data['data'] ?? []) as $t) $existing[$t['name']] = $t;

    $out = [];
    foreach (meta_wa_template_catalog() as $name => $def) {
        if (isset($existing[$name])) {
            $reason = (string)($existing[$name]['rejected_reason'] ?? '');
            if (strtoupper($reason) === 'NONE') $reason = ''; // Meta sends the literal word "NONE" when there is no reason
            $out[$name] = ['status' => strtoupper($existing[$name]['status'] ?? 'PENDING'),
                           'category' => $existing[$name]['category'] ?? $def['category'],
                           'reason' => $reason];
            continue;
        }
        // not on Meta yet -> submit it now (this IS the approval request)
        [$cok, $cdata, $cerr] = meta_wa_call('POST', "$waba/message_templates", [
            'name' => $name, 'category' => $def['category'], 'language' => $def['language'],
            'components' => $def['components'],
        ]);
        $out[$name] = $cok
            ? ['status' => strtoupper($cdata['status'] ?? 'PENDING'), 'category' => $def['category'], 'reason' => '']
            : ['status' => 'DRAFT', 'category' => $def['category'], 'reason' => $cerr];
    }
    set_setting('meta_wa_tpl_status', json_encode($out, JSON_UNESCAPED_UNICODE));
    set_setting('meta_wa_tpl_synced_at', date('Y-m-d H:i:s'));
    return $out;
}
