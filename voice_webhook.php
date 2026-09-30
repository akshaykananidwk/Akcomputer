<?php
// What became of the call. Vobiz posts here when the phone starts ringing
// and again when the call ends, and those two events are what turn a row
// from "queued" into answered / not answered / busy / failed.
//
// Guarded by the same per-call ?t= token as voice_answer.php, and for the
// same reason: no session exists on a request made by the phone network.
//
// Always answers 200 once the token is good. Vobiz retries a non-200 three
// times with backoff, and a retry storm over a problem on this end would
// only bury the real status.
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/voice.php';

http_response_code(200);
header('Content-Type: text/plain');
header('Cache-Control: no-store');

$call = voice_call_by_token(get('t'));

// An INCOMING call's hangup comes from the Application's own hangup_url,
// which carries no token - Vobiz built that URL, not us. Matching on the
// call id it does send is what lets an incoming call be finished off at all;
// without this its duration was never recorded and its WhatsApp follow-up
// never went out.
if (!$call) {
    $uuid = (string)($_POST['CallUUID'] ?? $_GET['CallUUID'] ?? '');
    if ($uuid !== '') $call = row('SELECT * FROM voice_calls WHERE call_uuid = ? ORDER BY id DESC LIMIT 1', [$uuid]);
}
if (!$call) { log_activity('voice_webhook_reject', 'unknown token'); exit('ignored'); }

// Vobiz posts form fields; a JSON body is accepted too rather than dropped
// on the floor if they ever change it.
$p = $_POST;
if (!$p) {
    $raw = file_get_contents('php://input');
    $j = $raw ? json_decode($raw, true) : null;
    if (is_array($j)) $p = $j;
}

// The finished recording of the whole call, posted when it ends. It is not a
// status at all, so it is taken and nothing else about the row is touched -
// a recording callback arriving after the hangup must not re-open a call
// that is already closed.
if (get('rec')) {
    $url = trim((string)($p['RecordUrl'] ?? $p['RecordFile'] ?? ''));
    if ($url !== '') {
        q('UPDATE voice_calls SET full_rec_url = ?, full_rec_secs = ? WHERE id = ?',
          [mb_substr($url, 0, 255), (int)($p['RecordingDuration'] ?? 0), (int)$call['id']]);
    }
    exit('ok rec');
}

// A late callback for a call that already ended must not walk the status
// backwards - a stray "ringing" arriving after "answered" would make a call
// that was picked up look like one that was not.
if (in_array($call['status'], ['answered', 'no_answer', 'busy', 'failed'], true)
    && strtolower((string)($p['Event'] ?? '')) === 'ring') {
    exit('ignored (late ring)');
}

$new = voice_status_apply($call, $p);

// The call is over, so this is the moment to send whatever it promised -
// off the critical path, with nobody waiting on the line.
if (strtolower((string)($p['Event'] ?? '')) === 'hangup') {
    require_once __DIR__ . '/includes/voice_in.php';
    voice_in_wa_flush(row('SELECT * FROM voice_calls WHERE id = ?', [$call['id']]));
}
exit('ok ' . $new);
