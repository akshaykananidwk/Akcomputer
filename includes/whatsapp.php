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
