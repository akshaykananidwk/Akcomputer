<?php
// WhatsApp API integration (bulk.akdwk.in)
// API URL, session id and key are stored in settings (Settings page).

// People type mobile numbers every possible way - with a leading 0 (old STD
// habit), spaces/dashes, +91, 91, or even 0091. An Indian mobile number is
// always exactly 10 digits, so no matter what prefix junk is in front of it,
// keeping only the LAST 10 digits after stripping non-digits always recovers
// the real number - then 91 is added once, consistently.
function wa_normalize_number($mobile) {
    $n = preg_replace('/\D/', '', (string)$mobile);
    if (strlen($n) >= 10) $n = substr($n, -10);
    if (strlen($n) === 10) $n = '91' . $n;
    return $n;
}

/**
 * The gateway (bulk.akdwk.in) always answers HTTP 200, even on failure -
 * the real result is inside the JSON body: {"status":"success"|"error",
 * "message": "..."}. Checking only the HTTP status code (as this used to)
 * meant every failed send - bad number, unreachable media_url, WhatsApp
 * session logged out, etc. - was silently reported back as a success.
 */
function wa_interpret_response($resp, $httpCode) {
    $GLOBALS['_wa_last_error'] = '';
    if ($resp === false || $httpCode >= 400) {
        $GLOBALS['_wa_last_error'] = 'Server error (HTTP ' . $httpCode . ')';
        return false;
    }
    $data = json_decode($resp, true);
    if (is_array($data) && isset($data['status'])) {
        if ($data['status'] === 'success' || $data['status'] === 'ok') return true;
        $GLOBALS['_wa_last_error'] = $data['message'] ?? ('API returned an error: ' . mb_substr($resp, 0, 300));
        return false;
    }
    // Non-JSON 2xx response (some gateways just echo plain "OK") - accept it.
    return true;
}

/** Human-readable reason for the last send_whatsapp() failure, or ''. */
function whatsapp_last_error() { return $GLOBALS['_wa_last_error'] ?? ''; }

/** Is the third-party gateway (bulk.akdwk.in style) configured? */
/**
 * Is this incoming message from a GROUP (or a status/broadcast)?
 *
 * The shop's number sits in a good many WhatsApp groups. Somebody posts a
 * link in one of them, and the bot answered - privately, to that person, who
 * had not written to the shop at all. That is the software talking to
 * strangers in the shop's name.
 *
 * The old guard looked for "@g.us" in ONE field, whichever of half a dozen
 * names happened to be filled in first. Gateways differ: some put the group
 * in "from" and the person in "participant", others put the PERSON in "from"
 * and the group in "chatId" or "remoteJid". With the second shape the guard
 * saw a perfectly ordinary mobile number and let it through.
 *
 * So: every field that could hold a chat id is looked at, not the first one
 * that answers. And "participant"/"author" being filled in AT ALL is taken
 * as proof on its own - no gateway sets those for a one-to-one chat, because
 * in a one-to-one chat the sender and the chat are the same thing.
 *
 * This fails CLOSED on purpose. Missing a group message costs the shop
 * nothing; answering one costs it an unsolicited message to a stranger.
 */
function wa_is_group(array $p) {
    // Anything that names a chat, from any gateway we have seen.
    // Only fields that NAME A CHAT. 'conversation' is deliberately not here:
    // in several gateways that is the message TEXT, and a customer writing
    // "mail me at raj@gmail.com - thanks" would have been silently dropped
    // as a group by a rule looking for an @ and a dash.
    foreach (['from', 'sender', 'remoteJid', 'remote_jid', 'chatId', 'chat_id', 'to',
              'jid', 'groupId', 'group_id', 'recipient'] as $k) {
        $v = $p[$k] ?? '';
        if (!is_string($v) || $v === '') continue;
        if (stripos($v, '@g.us') !== false) return true;
        if (stripos($v, '@broadcast') !== false) return true;          // status / broadcast list
        // Legacy group jids are digits-digits@server, nothing looser.
        if (preg_match('/^\d{5,}-\d{5,}@/', $v)) return true;
    }
    // A named participant means there is a chat with more than two people in
    // it - which is the definition of the thing we must not reply to.
    foreach (['participant', 'author', 'participantJid', 'group_participant'] as $k) {
        if (trim((string)($p[$k] ?? '')) !== '') return true;
    }
    // Some gateways simply say so.
    foreach (['isGroup', 'is_group', 'isGroupMsg'] as $k) {
        $v = $p[$k] ?? null;
        if ($v === true || $v === 1 || $v === '1' || $v === 'true') return true;
    }
    if (stripos((string)($p['chatType'] ?? $p['chat_type'] ?? ''), 'group') !== false) return true;
    return false;
}

/**
 * Remember how the last send went.
 *
 * Every send in this system ends here, so there is one honest answer to
 * "did that message actually leave?" - and the bot's own log can stop
 * guessing. It used to record a tick for every reply it COMPOSED, while
 * throwing away the boolean that said whether the send worked.
 */
/**
 * Answer on the number they wrote to.
 *
 * A shop can have two WhatsApp numbers - the official Meta one and the
 * gateway's - and the reply used to go out by a fixed preference that had
 * nothing to do with which one the message arrived on. A customer who wrote
 * to the Meta number got the answer from the gateway's number: a different
 * chat, on a different number, which from their side is indistinguishable
 * from no answer at all. That was weeks of "the bot doesn't reply".
 *
 * Set by the incoming webhook for the length of that request only. Anything
 * the shop sends of its own accord - a bill, a reminder, a campaign - has no
 * conversation to answer and keeps following the order in Settings.
 */
function wa_reply_via($p) {
    $GLOBALS['_wa_reply_via'] = in_array($p, ['meta', 'thirdparty'], true) ? $p : '';
}

/**
 * Which WhatsApp this shop uses: 'thirdparty', 'meta' or 'both'.
 *
 * Before this existed both were always live and the only say the owner had
 * was a priority buried under a page of other boxes - so a shop with two
 * numbers answered customers from whichever one that setting happened to
 * name. Now it is a choice, made at the top of the page.
 *
 * Falls back to whatever is actually configured, so a shop that upgrades
 * without touching the setting carries on exactly as before.
 */
function wa_provider_mode() {
    $m = setting('wa_provider_mode', '');
    if (in_array($m, ['thirdparty', 'meta', 'both'], true)) return $m;
    require_once __DIR__ . '/wa_meta.php';
    if (meta_wa_configured() && wa_thirdparty_configured()) return 'both';
    return meta_wa_configured() ? 'meta' : 'thirdparty';
}

/**
 * The providers to try, in order, for THIS send.
 *
 * Three things decide it, in this order of authority:
 *   1. The shop's choice. A provider it did not pick is never used, however
 *      completely its keys happen to be filled in.
 *   2. Which number a customer just wrote to, when this is a reply to them.
 *      Answering from the other number is indistinguishable, from their
 *      side, from not answering at all.
 *   3. The shop's stated priority, for everything it starts itself.
 */
/** Does this shop use that provider at all? */
function wa_uses($p) { $m = wa_provider_mode(); return $m === $p || $m === 'both'; }

function wa_providers() {
    $mode = wa_provider_mode();
    if ($mode !== 'both') return [$mode];

    $answer = (string)($GLOBALS['_wa_reply_via'] ?? '');
    if ($answer === 'meta' || $answer === 'thirdparty')
        return [$answer, $answer === 'meta' ? 'thirdparty' : 'meta'];

    return setting('wa_provider_order', 'thirdparty_first') === 'meta_first'
        ? ['meta', 'thirdparty'] : ['thirdparty', 'meta'];
}

function wa_mark_send($ok, $err = '') {
    $GLOBALS['_wa_send_ok'] = (bool)$ok;
    $GLOBALS['_wa_send_err'] = (string)$err;
}
function wa_last_send() {
    return ['ok' => (bool)($GLOBALS['_wa_send_ok'] ?? false),
            'error' => (string)($GLOBALS['_wa_send_err'] ?? '')];
}

function wa_thirdparty_configured() {
    return setting('wa_api_url', 'https://bulk.akdwk.in/api.php') !== '' && setting('wa_session_id') !== '' && setting('wa_api_key') !== '';
}

/**
 * Tap-buttons under a message - one shape for the whole app.
 *
 * Each button is ['title' => what it says] plus exactly one of:
 *   'id'    => reply button: the tap comes back to wa_webhook.php as a
 *              message whose text is this id (portal:stmt, task:start:5 ...)
 *   'url'   => opens a link        'phone' => starts a call
 *   'code'  => copies a code (UPI id, coupon)
 * The gateway draws them as real buttons. Meta draws reply buttons and a
 * single link button; anything it cannot draw is written out under the text,
 * so a link or phone number is never lost whichever number the message
 * leaves from.
 */
function wa_btn($title, $kind, $value) {
    return ['title' => (string)$title, $kind => (string)$value];
}

/** The buttons in the gateway's own format; broken ones are dropped. */
function wa_gateway_buttons(array $buttons) {
    $out = [];
    foreach ($buttons as $b) {
        $t = trim(preg_replace('/\s+/u', ' ', (string)($b['title'] ?? '')));
        if ($t === '') continue;
        if (mb_strlen($t) > 25) $t = mb_substr($t, 0, 24) . '…';
        if (isset($b['id']) && $b['id'] !== '') $out[] = ['type' => 'reply', 'text' => $t, 'id' => mb_substr($b['id'], 0, 64)];
        elseif (!empty($b['url']) && preg_match('#^https?://#i', $b['url'])) $out[] = ['type' => 'url', 'text' => $t, 'url' => $b['url']];
        elseif (!empty($b['phone']) && strlen(wa_normalize_number($b['phone'])) >= 12) $out[] = ['type' => 'call', 'text' => $t, 'phone' => wa_normalize_number($b['phone'])];
        elseif (isset($b['code']) && $b['code'] !== '') $out[] = ['type' => 'copy', 'text' => $t, 'code' => mb_substr($b['code'], 0, 200)];
    }
    return array_slice($out, 0, 10);
}

/** Link / call / copy buttons written out as text, for a channel that cannot
 *  draw them. Reply buttons are left out: typed, they would mean nothing. */
function wa_buttons_text(array $buttons) {
    $lines = [];
    foreach (wa_gateway_buttons($buttons) as $b) {
        if ($b['type'] === 'url') $lines[] = $b['text'] . ': ' . $b['url'];
        elseif ($b['type'] === 'call') $lines[] = $b['text'] . ': +' . $b['phone'];
        elseif ($b['type'] === 'copy') $lines[] = $b['text'] . ': ' . $b['code'];
    }
    return $lines ? "\n\n" . implode("\n", $lines) : '';
}

/** The same buttons as a Meta interactive message, or null when Meta has no
 *  shape for them (a mix of links and replies, or more than ten). */
function wa_buttons_interactive($text, array $buttons) {
    $g = wa_gateway_buttons($buttons);
    if (!$g) return null;
    $replies = array_values(array_filter($g, fn($b) => $b['type'] === 'reply'));
    $body = ['text' => mb_substr($text, 0, 1024)];
    $cut = fn($s, $n) => mb_strlen($s) > $n ? mb_substr($s, 0, $n - 1) . '…' : $s;
    if (count($replies) === count($g) && count($g) <= 3)
        return ['type' => 'button', 'body' => $body, 'action' => ['buttons' => array_map(
            fn($b) => ['type' => 'reply', 'reply' => ['id' => $b['id'], 'title' => $cut($b['text'], 20)]], $g)]];
    if (count($replies) === count($g))
        return ['type' => 'list', 'body' => $body, 'action' => ['button' => $cut(function_exists('wa_t') ? wa_t('menu_btn') : 'Menu', 20),
            'sections' => [['title' => $cut(setting('app_name', 'AK Computer'), 24), 'rows' => array_map(
                fn($b) => ['id' => $b['id'], 'title' => $cut($b['text'], 24)], $g)]]]];
    if (count($g) === 1 && $g[0]['type'] === 'url')
        return ['type' => 'cta_url', 'body' => $body, 'action' => ['name' => 'cta_url',
            'parameters' => ['display_text' => $cut($g[0]['text'], 20), 'url' => $g[0]['url']]]];
    return null;
}

/**
 * Send through the THIRD-PARTY gateway. Returns true on success.
 * $media_url (optional) sends an image/document with $message as caption;
 * $buttons (optional, see wa_btn) go under it as tap-buttons.
 *
 * POST with a JSON body, as the gateway asks: the API key used to ride in
 * the URL, where every proxy and server log along the way wrote it down.
 * A gateway that only understands the old GET form gets one retry that way,
 * so a shop on some other provider keeps working.
 */
function wa_send_thirdparty($mobile, $message, $media_url = '', array $buttons = [], $footer = '') {

    $GLOBALS['_wa_last_error'] = '';
    $api_url    = rtrim(setting('wa_api_url', 'https://bulk.akdwk.in/api.php'), '/');
    $session_id = setting('wa_session_id', '');
    $api_key    = setting('wa_api_key', '');
    $number     = wa_normalize_number($mobile);

    if (!$api_url || !$session_id || !$api_key || strlen($number) < 12) {
        $GLOBALS['_wa_last_error'] = 'Missing WhatsApp API URL / Session ID / API Key (Settings) or mobile number.';
        return false;
    }

    $params = [
        'number' => $number,
        'message' => $message,
        'session_id' => $session_id,
        'api_key' => $api_key,
    ];
    if ($media_url) $params['media_url'] = $media_url;
    $btns = wa_gateway_buttons($buttons);
    if ($btns) $params['buttons'] = $btns;
    // One message: the text with its buttons under it (a PDF, if any, goes
    // just before it). The small grey footer names the shop.
    if ($btns) $params['footer'] = mb_substr(trim(preg_replace('/\s+/u', ' ', $footer !== '' ? $footer : setting('app_name', 'AK Computer'))), 0, 60);

    [$resp, $httpCode] = wa_http('POST', $api_url, $params);
    // An old GET-only gateway: try once more the old way (buttons cannot
    // travel in a URL, so they are written out instead).
    if ($httpCode === 404 || $httpCode === 405) {
        unset($params['buttons']);
        $params['message'] = $message . wa_buttons_text($buttons);
        [$resp, $httpCode] = wa_http('GET', $api_url . '?' . http_build_query($params));
    }
    $ok = wa_interpret_response($resp, $httpCode);
    if ($ok) api_usage_log('whatsapp', 'gateway', 0, 1); // own recharge - counted, not costed
    else { log_activity('whatsapp_send_fail', mb_substr($number . ': ' . whatsapp_last_error(), 0, 400));
           if (function_exists('app_error')) app_error('whatsapp', 'send failed to ' . $number . ': ' . whatsapp_last_error(), 'whatsapp.php'); }
    return $ok;
}

/** One HTTP call to the gateway: [body|false, http code]. */
function wa_http($method, $url, array $json = null) {
    if (isset($GLOBALS['_wa_http_mock'])) return ($GLOBALS['_wa_http_mock'])($method, $url, $json);
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $opt = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_SSL_VERIFYPEER => true];
        if ($method === 'POST') $opt += [CURLOPT_POST => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
        curl_setopt_array($ch, $opt);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$resp, $code];
    }
    $http = ['timeout' => 20, 'ignore_errors' => true, 'method' => $method];
    if ($method === 'POST') $http += ['header' => "Content-Type: application/json\r\n",
        'content' => json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
    $resp = @file_get_contents($url, false, stream_context_create(['http' => $http]));
    $code = 200;
    if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) $code = (int)$m[1];
    return [$resp, $code];
}

/**
 * Send a WhatsApp message - the single entry point the whole app uses.
 * Two providers are available: the third-party gateway and the official
 * Meta (Facebook) Cloud API (includes/wa_meta.php). The wa_provider_order
 * setting decides which goes FIRST; if the primary fails or isn't
 * configured, the other automatically takes over as backup.
 * $buttons (optional, see wa_btn) become tap-buttons under the message.
 */
function send_whatsapp($mobile, $message, $media_url = '', array $buttons = [], $footer = '') {
    require_once __DIR__ . '/wa_meta.php';
    // another shop's plan may cap its WhatsApp messages a month
    if (function_exists('plan_limit_problem') && ($lim = plan_limit_problem('wa')) !== '') {
        $GLOBALS['_wa_last_error'] = $lim; wa_mark_send(false, $lim); return false;
    }
    $order = wa_providers();
    $errs = [];
    $sent = false;
    $logged = $message . wa_buttons_label($buttons);
    foreach ($order as $p) {
        if ($p === 'meta') {
            if (!meta_wa_configured()) { $errs[] = 'Meta: not configured'; continue; }
            // Buttons first when Meta can draw them; outside the 24-hour
            // window it refuses, and the plain message (a template) goes.
            $ia = !$media_url && $buttons ? wa_buttons_interactive(trim($message . ($footer !== '' ? "\n\n" . $footer : '')), $buttons) : null;
            if ($ia && meta_wa_send_interactive($mobile, $ia)[0]) { $sent = true; break; }
            if (meta_wa_send($mobile, $message . wa_buttons_text($buttons), $media_url)) { $sent = true; break; }
            $errs[] = 'Meta: ' . whatsapp_last_error();
            log_activity('whatsapp_send_fail', mb_substr('meta ' . wa_normalize_number($mobile) . ': ' . whatsapp_last_error(), 0, 400));
            if (function_exists('app_error')) app_error('whatsapp', 'meta send failed to ' . wa_normalize_number($mobile) . ': ' . whatsapp_last_error(), 'whatsapp.php');
        } else {
            if (!wa_thirdparty_configured()) { $errs[] = 'Gateway: not configured'; continue; }
            if (wa_send_thirdparty($mobile, $message, $media_url, $buttons, $footer)) { $sent = true; break; }
            $errs[] = 'Gateway: ' . whatsapp_last_error();
        }
    }
    $ctxKind = is_array($GLOBALS['_wa_ctx'] ?? null) ? ($GLOBALS['_wa_ctx']['kind'] ?? '') : '';
    $GLOBALS['_wa_ctx'] = []; // the context only ever applies to one send
    if ($sent) {
        // a reply typed by a person in the Inbox is marked as such: while one
        // is talking, the bot keeps out of that chat (wa_human_active)
        wa_chat_log($mobile, 'out', $ctxKind === 'otp' ? '🔐 [OTP message]' : $logged, $ctxKind === 'human' ? 'inbox' : $p, $media_url,
                    (string)($GLOBALS['_wa_last_msg_id'] ?? ''));
        wa_mark_send(true);
        return true;
    }
    $GLOBALS['_wa_last_error'] = $errs ? implode(' | ', $errs) : 'No WhatsApp provider is configured (Settings > WhatsApp).';
    wa_mark_send(false, $GLOBALS['_wa_last_error']);
    return false;
}

/**
 * The buttons a message to a CUSTOMER carries, by what the message is about:
 *   bill    - pay this bill (when something is due), statement, menu
 *   due     - pay, statement, call the shop
 *   receipt - statement, my bills, menu
 *   repair  - repair status, call the shop, menu
 * Labels follow the language that customer chose in the chat. Reply buttons
 * only go out while the bot is switched on - with nobody to answer, a button
 * that does nothing is worse than none. Link and call buttons always go.
 */
function wa_customer_buttons($mobile, $kind, array $sale = null, $due = null) {
    require_once __DIR__ . '/wa_lang.php';
    $was = $GLOBALS['_wa_lang'] ?? null;
    $GLOBALS['_wa_lang'] = wa_lang_of(wa_normalize_number($mobile)) ?: wa_lang_default();
    $bot = setting('wa_bot_enabled', '0') === '1';
    $phone = setting('wa_shop_number', '') ?: (string)val('SELECT phone FROM companies ORDER BY id LIMIT 1');
    $b = [];
    if ($kind === 'bill' || $kind === 'due') {
        if ($due === null) $due = $sale ? (float)$sale['total'] - (float)$sale['paid'] : 0;
        if ($sale && $due > 0.009) $b[] = wa_btn(wa_t('btn_pay', ['amt' => money($due)]), 'url', invoice_pay_url($sale));
        elseif ($kind === 'due' && $bot) $b[] = wa_btn(wa_t('m_pay'), 'id', 'portal:pay');
    }
    if ($kind === 'repair' && $bot) $b[] = wa_btn(wa_t('m_repairs'), 'id', 'portal:repairs');
    if ($kind !== 'repair' && $bot) $b[] = wa_btn(wa_t('btn_stmt'), 'id', 'portal:stmt');
    if ($kind === 'receipt' && $bot) $b[] = wa_btn(wa_t('m_bills'), 'id', 'portal:bills');
    if (($kind === 'due' || $kind === 'repair') && $phone !== '') $b[] = wa_btn(wa_t('btn_call'), 'phone', $phone);
    if ($kind !== 'due' && $bot) $b[] = wa_btn(wa_t('btn_menu'), 'id', 'portal:menu');
    $GLOBALS['_wa_lang'] = $was;
    if ($was === null) unset($GLOBALS['_wa_lang']);
    return array_slice($b, 0, 3);
}

/** "[Pay] [Statement]" - how the buttons look in the Inbox history. */
function wa_buttons_label(array $buttons) {
    $g = wa_gateway_buttons($buttons);
    return $g ? "\n[" . implode('] [', array_column($g, 'text')) . ']' : '';
}

/** Chat-history log (WhatsApp Inbox). Tolerant of the table not existing
 *  yet (pre-migrate v46). OTP bodies are masked - codes don't belong in a
 *  page other eyes might see. Incoming rows start unread. */
function wa_chat_log($mobile, $dir, $body, $via = '', $media = '', $msgId = '') {
    try {
        $n = wa_normalize_number($mobile);
        if (strlen($n) < 12) return;
        // $msgId is the provider's own id for this message. The receipt that
        // says whether it was delivered, read or FAILED comes back later
        // carrying that id; without it there is nothing to match, and
        // "sent" is the last thing the shop ever hears about it.
        q('INSERT INTO wa_chats (mobile, direction, body, media_url, via, is_read, msg_id, status)
           VALUES (?,?,?,?,?,?,?,?)',
          [$n, $dir === 'in' ? 'in' : 'out', mb_substr((string)$body, 0, 4000), $media ?: null,
           mb_substr($via, 0, 12), $dir === 'in' ? 0 : 1, mb_substr((string)$msgId, 0, 80),
           $dir === 'in' ? '' : 'sent']);
    } catch (Exception $e) { /* table ships in v46 - never break a send over history */ }
}

/** Tell the NEXT send_whatsapp() what kind of message it carries, so the
 *  Meta provider can use the matching approved template outside the 24h
 *  window: ['kind'=>'otp','code'=>..] / ['kind'=>'bill','invoice'=>..,
 *  'total'=>..,'firm'=>..,'link'=>..] / ['kind'=>'receipt','amount'=>..,
 *  'date'=>..,'balance'=>..] / ['kind'=>'reminder']. Optional everywhere -
 *  without it the generic akc_update template carries the flattened text. */
function wa_context($ctx) {
    $GLOBALS['_wa_ctx'] = is_array($ctx) ? $ctx : [];
}

// ---------- Customizable message templates ----------
// Each template: key => [default text, variables, label]
function wa_template_defaults() {
    return [
        'otp' => ["*{shop}*\nYour OTP for {reason} is: *{otp}*\nValid for 10 minutes. Do not share.",
                  '{shop} {otp} {reason}', 'OTP message (login / password / handover)'],
        'bill' => ["*{firm}*\nInvoice: *{invoice_no}*\nDate: {date}\nAmount: *₹{total}*\n{due_line}\n{pay_link}View/download bill:\n{link}\n\nThank you for your business! 🙏",
                   '{firm} {invoice_no} {date} {total} {due_line} {pay_link} {link} {customer}', 'Bill / Invoice send'],
        'estimate' => ["*{firm}*\nEstimate: *{estimate_no}*\n{items}\n*Total: ₹{total}*\nValid for 7 days. Reply to confirm order. 🙏",
                       '{firm} {estimate_no} {items} {total}', 'Estimate send'],
        'reminder' => ["*{firm}*\n🙏 Payment reminder\nBill: {invoice_no} ({date})\nAmount due: *Rs {due}*\n{due_date_line}Please settle it today. Thank you! 🙏",
                       '{firm} {invoice_no} {date} {due} {due_date_line}', 'Payment reminder'],
        'aging_reminder' => ["Hi,\nIt's a friendly reminder to you for paying *₹{amount}* to me.\n\nThank you,\n{shop}",
                             '{amount} {shop} {customer}', 'Payment reminder (Aging / Collection report)'],
        'payment_receipt' => ["*{shop}*\nPayment received ✔\nAmount: *₹{amount}* ({mode})\nDate: {date}\n{alloc}Your current balance: {balance}\nThank you! 🙏",
                              '{shop} {amount} {mode} {date} {alloc} {balance} {party}', 'Payment received confirmation'],
        'ledger' => ["*{shop}*\nAccount statement: {party}\n{lines}\n*Closing balance: {balance}*",
                     '{shop} {party} {lines} {balance}', 'Ledger / statement share'],
        'task' => ["*{shop}*\nNew task {task_no}\nCustomer: {customer} ({mobile})\nAddress: {address}\nWork: {work}",
                   '{shop} {task_no} {customer} {mobile} {address} {work}', 'Task assign (staff)'],
        'handover' => ["*{shop}*\nStock handover {handover_no} is ready for you.\nAccept it in your panel with OTP: *{otp}*\nDo not share this OTP.",
                       '{shop} {handover_no} {otp}', 'Stock handover OTP (staff)'],
        'repair_received' => ["*{shop}*\nRepair job received: *{job_no}*\nDevice: {device}\nProblem: {problem}\nWe will update you on WhatsApp. 🙏",
                              '{shop} {job_no} {device} {problem} {customer}', 'Repair job received'],
        'repair_status' => ["*{shop}*\nYour repair job {job_no} ({device}) {status_line}",
                            '{shop} {job_no} {device} {status_line}', 'Repair status update'],
        'warranty_status' => ["*{shop}*\nYour warranty claim {claim_no} (SN: {serial}) {status_line}",
                              '{shop} {claim_no} {serial} {status_line}', 'Warranty status update'],
        'weborder' => ["*{shop}* 🌐 New website order {order_no}\nName: {customer} ({mobile})\nAddress: {address}\n{items}\n*Total: ₹{total}*",
                       '{shop} {order_no} {customer} {mobile} {address} {items} {total}', 'Website order alert (shop)'],
        'amc_bill' => ["*{firm}*\nAMC Renewal Invoice: *{invoice_no}*\nContract: {title}\nAmount: *₹{total}*\nNext renewal: {next_date}\nThank you for staying with us! 🙏",
                       '{firm} {invoice_no} {title} {total} {next_date}', 'AMC auto-renewal invoice'],
        'review' => ["*{shop}*\nThank you for choosing us, {customer}! 🙏\nIf you liked our service, a quick Google review would mean a lot:\n{link}",
                     '{shop} {customer} {link}', 'Google review invite (WhatsApp)'],
        'birthday' => ["*{shop}*\n🎉 Happy Birthday, {customer}! 🎂\nWishing you a wonderful year ahead. Visit us for a special birthday discount! 🙏",
                       '{shop} {customer}', 'Birthday wish'],
        'anniversary' => ["*{shop}*\n🎉 Happy Anniversary, {customer}! 💐\nThank you for being with us. Visit us for a special discount! 🙏",
                          '{shop} {customer}', 'Anniversary wish'],
        'feedback_request' => ["*{shop}*\nHi {customer}, your job {job_no} is complete! 🙏\nWe'd love your feedback - please rate our service:\n{link}",
                               '{shop} {customer} {job_no} {link}', 'Feedback request (after job completion)'],
        'service_report' => ["*{shop}*\nHi {customer}, here's the digital service report for your job {job_no}:\n{link}\nThank you! 🙏",
                             '{shop} {customer} {job_no} {link}', 'Digital service report link'],
    ];
}

function wa_template($key, $vars) {
    $defs = wa_template_defaults();
    $tpl = setting('wa_tpl_' . $key, '');
    if ($tpl === '') $tpl = $defs[$key][0] ?? '';
    $vars['shop'] = $vars['shop'] ?? setting('app_name', 'AK Computer');
    foreach ($vars as $k => $v) $tpl = str_replace('{' . $k . '}', (string)$v, $tpl);
    return $tpl;
}

function send_otp_whatsapp($mobile, $code, $reason = 'verification') {
    wa_context(['kind' => 'otp', 'code' => $code]);
    return send_whatsapp($mobile, wa_template('otp', ['otp' => $code, 'reason' => $reason]));
}

/**
 * Send a bill on WhatsApp — the one place that decides what a customer
 * receives.
 *
 * Two callers now: the invoice screen's "WhatsApp" button, and the phone
 * app through api.php. They must produce the identical message, the
 * identical PDF and the identical pay link, or the same shop starts
 * sending two different things depending on which device the bill happened
 * to be made on.
 *
 * Returns ['ok' => bool, 'error' => string, 'mobile' => string, 'pdf' => url].
 */
function sale_whatsapp_send($saleId, $mobile = null) {
    require_once __DIR__ . '/pdf.php';
    $sale = row('SELECT s.*, c.name company_name FROM sales s
                 LEFT JOIN companies c ON c.id = s.company_id WHERE s.id = ?', [(int)$saleId]);
    if (!$sale) return ['ok' => false, 'error' => 'Bill not found', 'mobile' => '', 'pdf' => ''];
    $mobile = trim((string)($mobile !== null && $mobile !== '' ? $mobile : $sale['customer_mobile']));
    if ($mobile === '') return ['ok' => false, 'error' => 'No mobile number on this bill', 'mobile' => '', 'pdf' => ''];

    $items = all("SELECT si.*, COALESCE(i.name, '(deleted item)') name FROM sale_items si
                  LEFT JOIN items i ON i.id = si.item_id WHERE si.sale_id = ?", [$sale['id']]);
    $link = base_url('sale_view.php?id=' . $sale['id'] . '&token=' . $sale['share_token']);

    // A real PDF document, not a picture: WhatsApp only treats it as a
    // document if the URL itself ends in .pdf, which is why the bytes are
    // written to a static file instead of linking to sale_pdf.php.
    $pdfDir = up_dir('invoices');
    if (!is_dir($pdfDir)) mkdir($pdfDir, 0755, true);
    $pdfName = preg_replace('/[^A-Za-z0-9\-]/', '_', $sale['invoice_no']) . '_' . substr($sale['share_token'], 0, 10) . '.pdf';
    file_put_contents($pdfDir . '/' . $pdfName, invoice_pdf($sale, $items));
    $pdfUrl = base_url(up_rel('invoices') . '/' . $pdfName);

    $due = $sale['total'] - $sale['paid'];
    $payLink = $due > 0.009 ? invoice_pay_url($sale) : null;
    $msg = wa_template('bill', [
        'firm' => $sale['company_name'], 'invoice_no' => $sale['invoice_no'], 'date' => dmy($sale['sale_date']),
        'total' => money($sale['total']),
        'due_line' => $due > 0.009 ? 'Balance due: Rs ' . money($due) : 'Paid ✔',
        'pay_link' => $payLink ? "💳 Pay online: $payLink\n" : '',
        'link' => $link, 'customer' => $sale['customer_name'],
    ]);
    wa_context(['kind' => 'bill', 'invoice' => $sale['invoice_no'], 'total' => money($sale['total']),
                'firm' => $sale['company_name'], 'link' => $link]);

    if (send_whatsapp($mobile, $msg, $pdfUrl, wa_customer_buttons($mobile, 'bill', $sale, $due))) {
        log_activity('sale_whatsapp', $sale['invoice_no'] . ' to ' . $mobile);
        return ['ok' => true, 'error' => '', 'mobile' => $mobile, 'pdf' => $pdfUrl];
    }
    return ['ok' => false, 'error' => whatsapp_last_error() ?: 'WhatsApp send failed',
            'mobile' => $mobile, 'pdf' => $pdfUrl];
}


/** The same bill by e-mail: PDF attached, what is still due, the pay link.
 *  Lives next to sale_whatsapp_send() so both say the same thing. */
function sale_email_send($saleId, $to) {
    require_once __DIR__ . '/pdf.php';
    $sale = row('SELECT s.*, c.name company_name, c.gstin, c.is_gst, c.address c_address, c.phone c_phone, c.terms c_terms, l.name loc_name, l.city loc_city, p.name party_name, p.gstin party_gstin
                 FROM sales s JOIN companies c ON c.id = s.company_id JOIN locations l ON l.id = s.location_id LEFT JOIN parties p ON p.id = s.party_id WHERE s.id = ?', [(int)$saleId]);
    if (!$sale) return false;
    $items = all("SELECT si.*, COALESCE(i.name, '(deleted item)') name, i.unit FROM sale_items si LEFT JOIN items i ON i.id = si.item_id WHERE si.sale_id = ? AND si.qty > 0", [$sale['id']]);
    $due = (float)$sale['total'] - (float)$sale['paid'];
    $pay = $due > 0.009 ? invoice_pay_url($sale) : null;
    return send_mail($to, 'Bill ' . $sale['invoice_no'] . ' from ' . setting('app_name', ''), mail_html('Your bill ' . $sale['invoice_no'],
        '<p>Thank you for shopping with us. Your bill is attached.</p><p>Total: <b>₹' . money($sale['total']) . '</b>'
        . ($due > 0.009 ? '<br>Still to pay: <b>₹' . money($due) . '</b>' : '<br>Paid in full ✔') . '</p>'
        . ($pay ? '<p><a href="' . e($pay) . '">Pay online</a></p>' : '')),
        '', [[preg_replace('/[^\w\-]/', '_', $sale['invoice_no']) . '.pdf', invoice_pdf($sale, $items), 'application/pdf']]);
}
