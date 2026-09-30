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
 * Send through the THIRD-PARTY gateway. Returns true on success.
 * $media_url (optional) sends an image/document with $message as caption.
 */
function wa_send_thirdparty($mobile, $message, $media_url = '') {
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

    $url = $api_url . '?' . http_build_query($params);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $resp = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $ok = wa_interpret_response($resp, $httpCode);
        if ($ok) api_usage_log('whatsapp', 'gateway', 0, 1); // own recharge - counted, not costed
        else { log_activity('whatsapp_send_fail', mb_substr($number . ': ' . whatsapp_last_error(), 0, 400));
               if (function_exists('app_error')) app_error('whatsapp', 'send failed to ' . $number . ': ' . whatsapp_last_error(), 'whatsapp.php'); }
        return $ok;
    }
    $ctx = stream_context_create(['http' => ['timeout' => 20, 'ignore_errors' => true]]);
    $resp = @file_get_contents($url, false, $ctx);
    $httpCode = 200;
    if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) $httpCode = (int)$m[1];
    $ok = wa_interpret_response($resp, $httpCode);
    if (!$ok) { log_activity('whatsapp_send_fail', mb_substr($number . ': ' . whatsapp_last_error(), 0, 400));
                if (function_exists('app_error')) app_error('whatsapp', 'send failed to ' . $number . ': ' . whatsapp_last_error(), 'whatsapp.php'); }
    return $ok;
}

/**
 * Send a WhatsApp message - the single entry point the whole app uses.
 * Two providers are available: the third-party gateway and the official
 * Meta (Facebook) Cloud API (includes/wa_meta.php). The wa_provider_order
 * setting decides which goes FIRST; if the primary fails or isn't
 * configured, the other automatically takes over as backup.
 */
function send_whatsapp($mobile, $message, $media_url = '') {
    require_once __DIR__ . '/wa_meta.php';
    $order = setting('wa_provider_order', 'thirdparty_first') === 'meta_first'
        ? ['meta', 'thirdparty'] : ['thirdparty', 'meta'];
    // Replying to a message that just came in? Answer the number it came to,
    // and keep the other as the fallback it always was.
    $answer = (string)($GLOBALS['_wa_reply_via'] ?? '');
    if ($answer !== '') $order = [$answer, $answer === 'meta' ? 'thirdparty' : 'meta'];
    $errs = [];
    $sent = false;
    foreach ($order as $p) {
        if ($p === 'meta') {
            if (!meta_wa_configured()) { $errs[] = 'Meta: not configured'; continue; }
            if (meta_wa_send($mobile, $message, $media_url)) { $sent = true; break; }
            $errs[] = 'Meta: ' . whatsapp_last_error();
            log_activity('whatsapp_send_fail', mb_substr('meta ' . wa_normalize_number($mobile) . ': ' . whatsapp_last_error(), 0, 400));
            if (function_exists('app_error')) app_error('whatsapp', 'meta send failed to ' . wa_normalize_number($mobile) . ': ' . whatsapp_last_error(), 'whatsapp.php');
        } else {
            if (!wa_thirdparty_configured()) { $errs[] = 'Gateway: not configured'; continue; }
            if (wa_send_thirdparty($mobile, $message, $media_url)) { $sent = true; break; }
            $errs[] = 'Gateway: ' . whatsapp_last_error();
        }
    }
    $ctxKind = is_array($GLOBALS['_wa_ctx'] ?? null) ? ($GLOBALS['_wa_ctx']['kind'] ?? '') : '';
    $GLOBALS['_wa_ctx'] = []; // the context only ever applies to one send
    if ($sent) {
        wa_chat_log($mobile, 'out', $ctxKind === 'otp' ? '🔐 [OTP message]' : $message, $p, $media_url,
                    (string)($GLOBALS['_wa_last_msg_id'] ?? ''));
        wa_mark_send(true);
        return true;
    }
    $GLOBALS['_wa_last_error'] = $errs ? implode(' | ', $errs) : 'No WhatsApp provider is configured (Settings > WhatsApp).';
    wa_mark_send(false, $GLOBALS['_wa_last_error']);
    return false;
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
    $pdfDir = __DIR__ . '/../uploads/invoices';
    if (!is_dir($pdfDir)) mkdir($pdfDir, 0755, true);
    $pdfName = preg_replace('/[^A-Za-z0-9\-]/', '_', $sale['invoice_no']) . '_' . substr($sale['share_token'], 0, 10) . '.pdf';
    file_put_contents($pdfDir . '/' . $pdfName, invoice_pdf($sale, $items));
    $pdfUrl = base_url('uploads/invoices/' . $pdfName);

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

    if (send_whatsapp($mobile, $msg, $pdfUrl)) {
        log_activity('sale_whatsapp', $sale['invoice_no'] . ' to ' . $mobile);
        return ['ok' => true, 'error' => '', 'mobile' => $mobile, 'pdf' => $pdfUrl];
    }
    return ['ok' => false, 'error' => whatsapp_last_error() ?: 'WhatsApp send failed',
            'mobile' => $mobile, 'pdf' => $pdfUrl];
}
