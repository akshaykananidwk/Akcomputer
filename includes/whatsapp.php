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
        $GLOBALS['_wa_last_error'] = $data['message'] ?? ('API એ error આપ્યો: ' . mb_substr($resp, 0, 300));
        return false;
    }
    // Non-JSON 2xx response (some gateways just echo plain "OK") - accept it.
    return true;
}

/** Human-readable reason for the last send_whatsapp() failure, or ''. */
function whatsapp_last_error() { return $GLOBALS['_wa_last_error'] ?? ''; }

/**
 * Send a WhatsApp message. Returns true on success.
 * $media_url (optional) sends an image/document with $message as caption.
 */
function send_whatsapp($mobile, $message, $media_url = '') {
    $GLOBALS['_wa_last_error'] = '';
    $api_url    = rtrim(setting('wa_api_url', 'https://bulk.akdwk.in/api.php'), '/');
    $session_id = setting('wa_session_id', '');
    $api_key    = setting('wa_api_key', '');
    $number     = wa_normalize_number($mobile);

    if (!$api_url || !$session_id || !$api_key || strlen($number) < 12) {
        $GLOBALS['_wa_last_error'] = 'WhatsApp API URL / Session ID / API Key (Settings) અથવા mobile number ખૂટે છે.';
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
        if (!$ok) log_activity('whatsapp_send_fail', mb_substr($number . ': ' . whatsapp_last_error(), 0, 400));
        return $ok;
    }
    $ctx = stream_context_create(['http' => ['timeout' => 20, 'ignore_errors' => true]]);
    $resp = @file_get_contents($url, false, $ctx);
    $httpCode = 200;
    if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) $httpCode = (int)$m[1];
    $ok = wa_interpret_response($resp, $httpCode);
    if (!$ok) log_activity('whatsapp_send_fail', mb_substr($number . ': ' . whatsapp_last_error(), 0, 400));
    return $ok;
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
        'reminder' => ["*{firm}*\nPayment reminder 🙏\nInvoice: {invoice_no} ({date})\nBalance due: *₹{due}*\n{due_date_line}Kindly arrange the payment. Thank you!",
                       '{firm} {invoice_no} {date} {due} {due_date_line}', 'Payment reminder'],
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
    return send_whatsapp($mobile, wa_template('otp', ['otp' => $code, 'reason' => $reason]));
}
