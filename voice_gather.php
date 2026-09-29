<?php
// What the customer pressed.
//
// Vobiz posts here when a key is pressed during the question at the end of a
// reminder call - and also when the ten seconds run out with nothing pressed,
// in which case Digits arrives empty. Both are answers worth keeping: "no"
// is information, and "did not answer" is information too.
//
// Guarded by the same per-call ?t= token as the other two endpoints, because
// this request comes from the phone network and carries no session.
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/voice.php';

header('Content-Type: text/xml; charset=utf-8');
header('Cache-Control: no-store');

$call = voice_call_by_token(get('t'));
if (!$call || !voice_token_fresh($call)) {
    log_activity('voice_gather_reject', $call ? 'expired token' : 'unknown token');
    echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<Response></Response>\n";
    exit;
}

$p = $_POST;
if (!$p) {
    $raw = file_get_contents('php://input');
    $j = $raw ? json_decode($raw, true) : null;
    if (is_array($j)) $p = $j;
}

// The promise, the note and the record all happen in here - this page only
// says the answer out loud.
$answer = voice_response_apply($call, $p['Digits'] ?? '');

$party = row('SELECT name FROM parties WHERE id = ?', [(int)$call['party_id']]);
$plan = voice_audio_plan($party['name'] ?? '', (float)$call['amount'], $call['lang'],
                         voice_call_promise_date($call));
$say = $plan['replies'][$answer] ?? null;

echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<Response>\n";
if ($say) echo $say['url'] ? voice_xml_play($say['url']) : voice_xml_speak($say['text']);
echo "</Response>\n";
