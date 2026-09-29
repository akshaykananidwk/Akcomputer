<?php
// What the customer hears. Vobiz fetches this the moment the phone is picked
// up and plays back whatever XML comes out of it.
//
// This URL is fetched by the phone network, not by a browser, so it carries
// no session and no CSRF token. What guards it is the ?t= token: 160 random
// bits, made for one call, dead six hours later, and useless for any other
// customer. Without it this page would read a customer's name and their
// outstanding amount aloud to anyone who guessed a URL.
//
// Vobiz also signs its requests (X-Vobiz-Signature-V2/V3), but publishes the
// headers without the exact scheme. A guessed implementation would reject
// real calls, so the token above is the guard and the signature check is
// left as a marked extension, not faked.
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/voice.php';

header('Content-Type: text/xml; charset=utf-8');
header('Cache-Control: no-store');

$call = voice_call_by_token(get('t'));

// A dead token gets silence, not an explanation. Saying "expired" to whoever
// is on the line tells a stranger that the token was once real.
if (!$call || !voice_token_fresh($call)) {
    log_activity('voice_answer_reject', $call ? 'expired token' : 'unknown token');
    echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<Response></Response>\n";
    exit;
}

// The network only asks for this XML once somebody has actually picked up,
// which makes this the single most reliable "answered" signal in the whole
// flow - more reliable than any hangup cause. Stamp it before playing.
voice_mark_answered($call);

echo voice_answer_xml($call);
