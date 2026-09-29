<?php
// The shop's phone number, answering.
//
// Vobiz fetches this when a call comes IN. Every later step of the same call
// comes back here with ?step= and the call's own token, so one file holds
// the whole conversation instead of five files holding a fifth of it each.
//
// The first request is the only one that cannot carry a token - Vobiz has
// never heard of this call either. It is guarded three ways instead:
//
//   * the call must be to OUR number (To must match the configured one)
//   * a rate limit per source, the same one the public API routes use
//   * nothing private is said until a step that does carry the token
//
// That last one matters most. Caller ID can be forged, so being recognised
// only buys a greeting by name; whether money is read out to whoever is on
// the line is the owner's decision, made once in Settings, and the cautious
// option sends the figures to WhatsApp instead - which needs the real SIM.
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/voice_in.php';

header('Content-Type: text/xml; charset=utf-8');
header('Cache-Control: no-store');

$empty = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<Response></Response>\n";

// Vobiz posts form fields; JSON is accepted too rather than dropped.
$p = $_POST;
if (!$p) {
    $raw = file_get_contents('php://input');
    $j = $raw ? json_decode($raw, true) : null;
    if (is_array($j)) $p = $j;
}
$step = (string)get('step');

if (!voice_in_on()) { echo $empty; exit; }

// ---------- the first request of a call ----------
if ($step === '') {
    if (!api_rate_ok('voice_in:' . client_ip(), 60, 60)) { echo $empty; exit; }

    $to = voice_e164((string)($p['To'] ?? $_GET['To'] ?? ''));
    $mine = voice_e164(setting('vobiz_inbound_number', ''));
    // Answering calls that were never made to us would mean anybody could
    // drive this menu by posting to the URL.
    if ($mine === '91' || $to !== $mine) {
        log_activity('voice_in_reject', 'To=' . $to . ' expected ' . $mine);
        echo $empty; exit;
    }

    $from = (string)($p['From'] ?? $_GET['From'] ?? '');
    if (!voice_mobile_ok($from)) { log_activity('voice_in_reject', 'bad From'); echo $empty; exit; }

    $call = voice_in_start($from, $to, (string)($p['CallUUID'] ?? ''));
    voice_in_log($call['id'], 'answered');
    echo voice_in_menu_xml($call, 1);
    exit;
}

// ---------- every step after that carries the call's own token ----------
$call = voice_call_by_token(get('t'));
if (!$call || $call['direction'] !== 'in' || !voice_token_fresh($call)) {
    log_activity('voice_in_reject', $call ? 'expired or wrong-direction token' : 'unknown token');
    echo $empty; exit;
}

switch ($step) {
    case 'menu':
        $digit = trim((string)($p['Digits'] ?? ''));
        // Nothing pressed: the menu offers itself once more, then says
        // goodbye. voice_in_menu_xml() decides which, from the try count.
        if ($digit === '') { echo voice_in_menu_xml($call, (int)get('try') >= 2 ? 2 : 2); exit; }
        echo voice_in_branch($call, $digit);
        exit;

    case 'rec_start':
        // Fired the moment recording begins. Nothing to say back - the
        // caller is mid-sentence - but worth knowing it started at all.
        voice_in_log($call['id'], 'rec_start', null, (string)($p['RecordingID'] ?? ''));
        echo $empty;
        exit;

    case 'rec_done':
        voice_in_recording_done($call, $p, (string)get('what'));
        echo $empty;
        exit;

    case 'dial_done':
        echo voice_in_dial_done_xml($call, $p);
        exit;
}

echo $empty;
