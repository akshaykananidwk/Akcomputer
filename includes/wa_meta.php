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
    if ($ok) { api_usage_log('whatsapp', 'meta:freeform', 0, 1); return true; } // service window = free

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
        if ($ok2) { api_usage_log('whatsapp', 'meta:tpl:' . $tpl['name'], 0, 1); return true; }
        $GLOBALS['_wa_last_error'] = 'Meta template send failed: ' . $err2;
        return false;
    }
    $GLOBALS['_wa_last_error'] = 'Meta: ' . $err;
    return false;
}

/** Send an interactive message (list / reply-buttons / cta_url) - powers the
 *  in-WhatsApp catalog menu. Free-form interactive messages only work inside
 *  the 24h customer window, which is always open here because the customer
 *  just messaged us. Returns [ok(bool), err(string)]. */
function meta_wa_send_interactive($mobile, array $interactive) {
    $number = wa_normalize_number($mobile);
    if (!meta_wa_configured() || strlen($number) < 12) return [false, 'Meta Cloud API not configured'];
    [$ok, , $err] = meta_wa_call('POST', setting('meta_wa_phone_id') . '/messages', [
        'messaging_product' => 'whatsapp', 'to' => $number, 'type' => 'interactive', 'interactive' => $interactive,
    ]);
    if ($ok) api_usage_log('whatsapp', 'meta:freeform', 0, 1); // interactive = inside 24h window = free
    return [$ok, $err];
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

// ==================== ONE-CLICK FULL SYNC ====================
// "Synchronize" in Settings runs every Meta chore the Graph API allows with
// the shop's token - so the owner never opens the Meta dashboard for routine
// work. Each section AUTO-FIXES what it can (subscribe the app to the WABA,
// submit missing templates, push the business profile, upsert catalog
// products) and reports what genuinely needs a human, with the fix spelled
// out. Report rows: ['name', 'status' => ok|warn|fail|skip, 'detail', 'fix'].

function meta_wa_full_sync() {
    $R = [];
    $rowf = function ($name, $status, $detail, $fix = '') use (&$R) { $R[] = ['name' => $name, 'status' => $status, 'detail' => $detail, 'fix' => $fix]; };

    // ---- 1. token + phone number health (everything else needs this) ----
    [$ok, $ph, $err] = meta_wa_call('GET', setting('meta_wa_phone_id') . '?fields=display_phone_number,verified_name,quality_rating,code_verification_status');
    if (!$ok) {
        $expired = stripos($err, 'expired') !== false || stripos($err, 'Session') !== false || stripos($err, 'access token') !== false;
        $rowf('🔑 Token / કનેક્શન', 'fail', $err,
            $expired ? 'Token જૂનું થઈ ગયું છે. business.facebook.com → Business Settings → Users → System Users → તમારો user → Generate New Token (permissions: whatsapp_business_messaging + whatsapp_business_management, expiry: Never) → નવું token અહીં Meta કાર્ડમાં Save કરો.'
                     : 'Meta કાર્ડમાં Token / Phone ID / WABA ID ફરી ચકાસો.');
        set_setting('meta_sync_report', json_encode($R, JSON_UNESCAPED_UNICODE));
        set_setting('meta_sync_at', date('Y-m-d H:i:s'));
        return $R; // token વગર આગળનું બધું fail જ થાય
    }
    $rowf('📱 Phone Number', 'ok', ($ph['display_phone_number'] ?? '') . ' · ' . ($ph['verified_name'] ?? '') . ' · Quality: ' . ($ph['quality_rating'] ?? '?'));

    // ---- 2. webhook: the app must be subscribed to the WABA (auto-fix) ----
    [$ok, $subs, $err] = meta_wa_call('GET', setting('meta_wa_waba_id') . '/subscribed_apps');
    $nSubs = $ok ? count($subs['data'] ?? []) : 0;
    if ($ok && $nSubs === 0) {
        [$fx, , ] = meta_wa_call('POST', setting('meta_wa_waba_id') . '/subscribed_apps', []);
        if ($fx) { $nSubs = 1; $rowf('🔔 Webhook Subscription', 'ok', 'App WABA સાથે subscribe નહોતી - આપોઆપ કરી દીધી ✔'); }
        else $rowf('🔔 Webhook Subscription', 'fail', 'App subscribe થઈ શકી નહીં.', 'developers.facebook.com → તમારી App → WhatsApp → Configuration માં Callback URL: ' . base_url('wa_webhook.php?key=' . setting('wa_webhook_key')) . ' · Verify token: ' . setting('wa_webhook_key') . ' · "messages" field subscribe કરો.');
    } elseif ($ok) {
        $rowf('🔔 Webhook Subscription', 'ok', $nSubs . ' app subscribed. મેસેજ ન આવે તો જ Callback URL ચકાસવી.');
    } else {
        $rowf('🔔 Webhook Subscription', 'warn', $err, 'App Dashboard → WhatsApp → Configuration માં Callback URL ચકાસો: ' . base_url('wa_webhook.php?key=' . setting('wa_webhook_key')));
    }

    // ---- 3. templates: submit missing + refresh approval statuses ----
    $tpls = meta_wa_sync_templates();
    if (isset($tpls['_error'])) {
        $rowf('📝 Templates', 'fail', $tpls['_error'], 'Token ને whatsapp_business_management permission છે કે ચકાસો.');
    } else {
        $ap = 0; $pend = 0; $rej = [];
        foreach ($tpls as $nm => $t) {
            if ($t['status'] === 'APPROVED') $ap++;
            elseif ($t['status'] === 'REJECTED') $rej[] = $nm . ($t['reason'] !== '' ? ' (' . $t['reason'] . ')' : '');
            else $pend++;
        }
        $det = "$ap Approved · $pend Pending" . ($rej ? ' · ' . count($rej) . ' Rejected' : '');
        if ($rej) $rowf('📝 Templates', 'warn', $det . ' — Rejected: ' . implode(', ', $rej), 'Rejected ટેમ્પ્લેટ Meta ની પોલિસીથી નકારાયું છે - મને (Claude ને) કારણ મોકલો, હું લખાણ બદલી નવું સબમિટ કરી આપીશ.');
        else $rowf('📝 Templates', $pend ? 'warn' : 'ok', $det . ($pend ? ' — મંજૂરી સામાન્ય રીતે થોડા કલાકમાં આવે છે, cron આપોઆપ ફરી ચકાસે છે.' : ''));
    }

    // ---- 4. business profile: push OUR info onto the WhatsApp profile ----
    $co = row('SELECT * FROM companies ORDER BY id LIMIT 1') ?: [];
    $want = array_filter([
        'about' => mb_substr(setting('app_name', 'AK Computer') . ' — Computer · CCTV · Printer · Networking, Dwarka', 0, 139),
        'address' => mb_substr(trim(($co['address'] ?? '') !== '' ? $co['address'] : 'Dwarka, Gujarat'), 0, 256),
        'description' => mb_substr(setting('app_name', 'AK Computer') . ' - Dwarka નું વિશ્વાસુ કમ્પ્યુટર અને CCTV સ્ટોર. બધા ભાવ ઓનલાઇન: ' . base_url('') . '/ . આ નંબર પર "catalog" લખો - આખો કેટલોગ WhatsApp માં જ!', 0, 512),
        'email' => trim($co['email'] ?? ''),
        'vertical' => 'RETAIL',
    ]);
    $want['websites'] = [base_url('') . '/'];
    [$ok, $cur, ] = meta_wa_call('GET', setting('meta_wa_phone_id') . '/whatsapp_business_profile?fields=about,address,description,email,websites,vertical');
    $cur = $cur['data'][0] ?? [];
    $same = ($cur['about'] ?? '') === $want['about'] && ($cur['address'] ?? '') === ($want['address'] ?? '')
         && ($cur['description'] ?? '') === $want['description'] && (($cur['websites'] ?? []) === $want['websites']);
    if ($same) {
        $rowf('👤 Business Profile', 'ok', 'About / Address / Description / Website બધું sync માં જ છે.');
    } else {
        [$up, , $err] = meta_wa_call('POST', setting('meta_wa_phone_id') . '/whatsapp_business_profile', array_merge(['messaging_product' => 'whatsapp'], $want));
        if ($up) $rowf('👤 Business Profile', 'ok', 'પ્રોફાઇલ અપડેટ કરી દીધી ✔ (About, Address, Description, Website)');
        else $rowf('👤 Business Profile', 'warn', $err, 'પ્રોફાઇલ ફોટો ફક્ત WhatsApp Business app / Meta Business Suite માંથી બદલાય છે - બાકીનું અહીંથી થાય છે.');
    }

    // ---- 5. product catalog (Commerce Manager) ----
    $catId = trim(setting('meta_catalog_id', ''));
    if ($catId === '') {
        $rowf('🛒 Product Catalog', 'skip', 'Meta Catalog ID નાખેલું નથી.',
            'એક જ વાર: business.facebook.com/commerce → Create Catalog (E-commerce) → Settings માંથી Catalog ID કૉપી કરીને Meta કાર્ડમાં નાખો - પછી દરેક Sync માં પ્રોડક્ટ પણ આપોઆપ અપડેટ થશે. (વૈકલ્પિક: Data Feed URL ' . base_url('catalog_feed.php') . ' શેડ્યૂલ કરો.)');
    } else {
        $items = all('SELECT i.id, i.name, i.brand, i.model, i.selling_price, i.photo, i.description FROM items i WHERE i.is_active = 1 AND i.show_on_website = 1');
        $reqs = []; $noPhoto = 0;
        foreach ($items as $it) {
            $img = trim((string)$it['photo']);
            if ($img === '') { $noPhoto++; continue; } // image is mandatory in Commerce Manager
            if (!preg_match('#^https?://#i', $img)) $img = base_url($img);
            $reqs[] = ['method' => 'UPDATE', 'data' => [
                'id' => 'akc_' . $it['id'],
                'title' => mb_substr($it['name'], 0, 150),
                'description' => mb_substr(trim(($it['description'] ?: $it['name']) . ' ' . $it['brand'] . ' ' . $it['model']), 0, 5000),
                'availability' => 'in stock', 'condition' => 'new',
                'price' => number_format((float)$it['selling_price'], 2, '.', '') . ' INR',
                'link' => base_url('catalog.php?add=' . $it['id']),
                'image_link' => $img,
                'brand' => $it['brand'] ?: setting('app_name', 'AK Computer'),
            ]];
        }
        if (!$reqs) {
            $rowf('🛒 Product Catalog', 'warn', 'ફોટાવાળી કોઈ પ્રોડક્ટ નથી (Meta કેટલોગમાં ફોટો ફરજિયાત છે).', 'Items → AI Auto-Fill થી પ્રોડક્ટ ફોટા લગાવો, પછી ફરી Sync કરો.');
        } else {
            [$ok, , $err] = meta_wa_call('POST', $catId . '/items_batch', ['item_type' => 'PRODUCT_ITEM', 'requests' => $reqs]);
            if ($ok) $rowf('🛒 Product Catalog', 'ok', count($reqs) . ' પ્રોડક્ટ Meta કેટલોગમાં અપડેટ થઈ' . ($noPhoto ? " ($noPhoto ફોટા વગરની skip)" : '') . '.');
            else $rowf('🛒 Product Catalog', 'fail', $err, 'System User ને catalog_management permission + Business Settings → Data Sources → Catalogs માં આ catalog પર Assets access આપો.');
        }
    }

    // ---- 6. things that need no syncing (informational) ----
    $rowf('📚 Interactive Menus', 'ok', 'કેટલોગ/પોર્ટલ મેનુ સોફ્ટવેરમાંથી લાઈવ જ જાય છે - Meta માં કંઈ રાખવાનું નથી.');

    set_setting('meta_sync_report', json_encode($R, JSON_UNESCAPED_UNICODE));
    set_setting('meta_sync_at', date('Y-m-d H:i:s'));
    return $R;
}
