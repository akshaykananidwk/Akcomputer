<?php
// What the customer said, and what the call says back.
//
// Vobiz posts the recording's URL here the moment the customer stops talking,
// and plays whatever XML comes out - so this runs with somebody on the line.
// Everything slow is therefore on a leash, and every failure has a sentence
// under it. A caller must never hear silence.
//
// Guarded by the same per-call ?t= token as the other call endpoints. This
// request comes from the phone network: no session, no CSRF token, and the
// token is the only thing standing between a guessed URL and a customer's
// recorded voice.
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/voice_talk.php';

header('Content-Type: text/xml; charset=utf-8');
header('Cache-Control: no-store');

$call = voice_call_by_token(get('t'));
if (!$call || !voice_token_fresh($call) || ($call['direction'] ?? 'out') !== 'out') {
    log_activity('voice_talk_reject', $call ? 'expired token' : 'unknown token');
    echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<Response></Response>\n";
    exit;
}

$p = $_POST;
if (!$p) {
    $raw = file_get_contents('php://input');
    $j = $raw ? json_decode($raw, true) : null;
    if (is_array($j)) $p = $j;
}
$recUrl = trim((string)($p['RecordUrl'] ?? $p['RecordFile'] ?? $p['record_url'] ?? ''));
$turn = max(1, (int)get('turn'));

// The recording is kept on the row whatever comes of it, so the owner can
// always play back what the customer actually said.
if ($recUrl !== '') q('UPDATE voice_calls SET recording_url = ? WHERE id = ?', [mb_substr($recUrl, 0, 255), (int)$call['id']]);

/**
 * Everything that can go wrong here ends the same way: ask again if there is
 * an ask left, otherwise thank them and stop. Never an explanation, never
 * silence - the customer is not interested in why a server is unhappy.
 */
$giveUp = function ($why) use ($call, $turn) {
    if ($why !== '') log_activity('voice_talk_fail', mb_substr($why, 0, 180));
    $lang = $call['lang'];
    $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<Response>\n";
    if ($turn < voice_talk_turns()) {
        q('UPDATE voice_calls SET talk_turns = talk_turns + 1 WHERE id = ?', [(int)$call['id']]);
        $xml .= voice_talk_ask_xml($call, $turn + 1);
    } else {
        q("UPDATE voice_calls SET response = IF(response = '' OR response IS NULL, 'none', response),
                  talk_turns = talk_turns + 1 WHERE id = ?", [(int)$call['id']]);
        $xml .= voice_talk_say('talk_giveup', $lang);
    }
    echo $xml . "</Response>\n";
    exit;
};

if ($recUrl === '') $giveUp('');   // they said nothing at all - not a failure

// The recording lives on the provider and needs the account's own headers,
// which is why it is fetched here rather than handed to anybody else.
list($audio, $mime, $err) = voice_fetch_recording($recUrl, 5);
if ($audio === null) $giveUp('could not fetch the recording: ' . $err);

list($ai, $aiErr) = voice_talk_listen($audio, $mime, $call['lang'], 7);
if ($ai === null) $giveUp('could not make out the answer: ' . $aiErr);

// From here on nothing is the model's decision. voice_talk_decide() checks
// the date with ordinary code, and only a date that passes becomes a promise.
$decision = voice_talk_decide($ai, $turn);
voice_talk_apply($call, $ai, $decision);

echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<Response>\n"
   . voice_talk_reply_xml($call, $decision, $turn)
   . "</Response>\n";
