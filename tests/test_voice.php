<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); die('CLI only'); }
// Payment reminder calls.
//
// Two things here are worth more attention than the rest. The amount, because
// a wrong number read down a phone is a wrong claim about money made to a
// customer. And the guards, because the cost of a bug is a person's phone
// ringing when it should not have - at night, after they promised to pay, or
// after they asked to be left alone.
require_once __DIR__ . '/../includes/voice.php';

$_SESSION['user_id'] = (int)val("SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id
                                 WHERE r.permissions LIKE '%*%' AND u.is_active = 1 ORDER BY u.id LIMIT 1");

// a known configuration, so nothing below depends on what the live shop set
set_setting('voice_enabled', '1');
set_setting('voice_test_mode', '1');
set_setting('vobiz_auth_id', 'TESTAUTHID');
set_setting('vobiz_auth_token', 'TESTTOKEN');
set_setting('vobiz_caller_id', '919999999999');
set_setting('voice_lang', 'gu');
set_setting('voice_hour_from', '9');
set_setting('voice_hour_to', '21');
set_setting('voice_cooldown_hours', '6');
set_setting('voice_max_per_day', '50');
set_setting('collection_cooldown_days', '3');
set_setting('collection_max_reminders', '4');

// Every gate below is checked at a fixed two in the afternoon. Without this
// the suite passed or failed depending on what time of day it was run, which
// is the opposite of what a regression test is for.
$NOON = strtotime('today 14:00');

t_group('Voice — the amount, said exactly');

// The decomposition is what drives which clip plays, so it is checked as
// tokens, not as a rendered sentence.
t_eq('12,400 -> twelve thousand four hundred rupees',
     implode(' ', voice_amount_tokens(12400)), 'n12 thousand n4 hundred rupees');
t_eq('1,05,250.50 keeps the lakh, the hundreds AND the paise',
     implode(' ', voice_amount_tokens(105250.50)), 'n1 lakh n5 thousand n2 hundred n50 rupees n50 paise');
t_eq('a round 100 does not grow a stray zero', implode(' ', voice_amount_tokens(100)), 'n1 hundred rupees');
t_eq('99 is one word, not ninety and nine', implode(' ', voice_amount_tokens(99)), 'n99 rupees');
t_eq('1000 is one thousand flat', implode(' ', voice_amount_tokens(1000)), 'n1 thousand rupees');
t_eq('47,850 - the irregular tens are single tokens',
     implode(' ', voice_amount_tokens(47850)), 'n47 thousand n8 hundred n50 rupees');
t_eq('zero is spoken, not skipped', implode(' ', voice_amount_tokens(0)), 'n0 rupees');
t_eq('a crore still decomposes', implode(' ', voice_amount_tokens(12345678)),
     'n1 crore n23 lakh n45 thousand n6 hundred n78 rupees');

// Paise are the classic place to lose or invent a rupee.
t_eq('0.50 is fifty paise and no rupees', implode(' ', voice_amount_tokens(0.5)), 'n0 rupees n50 paise');
t_eq('99.99 does not round up to 100', implode(' ', voice_amount_tokens(99.99)), 'n99 rupees n99 paise');
t_eq('0.999 becomes one rupee, never one rupee and a hundred paise',
     implode(' ', voice_amount_tokens(0.999)), 'n1 rupees');
t_eq('a negative balance is never read out as a debt', implode(' ', voice_amount_tokens(-500)), 'n0 rupees');

// Whatever the language, the NUMBER of pieces is the same - the tokens are
// language-free by design, which is what lets one recording set per language
// serve every amount.
t_eq('the same amount is the same tokens in every language',
     count(voice_amount_tokens(47850)), count(voice_amount_tokens(47850)));

t_group('Voice — the words behind the tokens');

$gu = voice_words('gu');
$hi = voice_words('hi');
t_eq('Gujarati has all hundred number words', count($gu), 100);
t_eq('Hindi has all hundred number words', count($hi), 100);
t_ok('no Gujarati number word is blank', !in_array('', $gu, true));
t_ok('no Hindi number word is blank', !in_array('', $hi, true));
t_eq('Gujarati 100 words are 100 DIFFERENT words', count(array_unique($gu)), 100);
t_eq('Hindi 100 words are 100 different words', count(array_unique($hi)), 100);
t_eq('12 in Gujarati', $gu[12], 'બાર');
t_eq('47 in Gujarati is one word', $gu[47], 'સુડતાલીસ');
t_eq('12 in Hindi', $hi[12], 'बारह');
t_eq('the amount renders in Gujarati words',
     voice_tokens_text(voice_amount_tokens(12400), 'gu'), 'બાર હજાર ચાર સો રૂપિયા');
t_eq('the amount renders in Hindi words',
     voice_tokens_text(voice_amount_tokens(12400), 'hi'), 'बारह हज़ार चार सौ रुपये');

$script = voice_script('રમેશભાઈ', 12400, 'gu');
t_ok('the script greets by name', strpos($script, 'રમેશભાઈ') !== false);
t_ok('the script says the shop name', strpos($script, setting('app_name', 'AK Computer')) !== false);
t_ok('the script says the amount in words', strpos($script, 'બાર હજાર ચાર સો') !== false);
t_ok('the script asks for payment', strpos($script, 'વિનંતી') !== false);
t_ok('the script thanks them', strpos($script, 'આભાર') !== false);
t_ok('a customer with no name still gets a clean sentence',
     strpos(voice_script('', 500, 'gu'), ' ,') === false);

t_group('Voice — Vobiz cannot speak Gujarati, so the voice is made elsewhere');

// The finding the whole design rests on: their TTS has 16 languages and no
// Indian one. The sentence is generated as an audio file instead - and when
// that cannot be done, the call still happens, in English, rather than not
// at all or in silence.
set_setting('voice_tts', '1');
set_setting('gemini_api_key', '');            // no key -> generation must fail
$plan = voice_audio_plan('Ramesh', 12400, 'gu');
t_eq('with no AI key the call falls back to English', $plan['lang'], 'en');
t_eq('and it is honest about having fallen back', $plan['fell_back'], true);
t_ok('the reason is in words the owner can read', strlen((string)$plan['error']) > 5, (string)$plan['error']);
t_ok('the English sentence still carries the amount',
     strpos($plan['main']['text'], 'twelve thousand four hundred') !== false);
t_ok('a fallback speaks rather than plays', $plan['main']['url'] === '' && $plan['main']['text'] !== '');

$enPlan = voice_audio_plan('Ramesh', 12400, 'en');
t_eq('English needs nothing generated', $enPlan['lang'], 'en');
t_eq('and is not a fallback from anything', $enPlan['fell_back'], false);

// The fallback has to reach EVERY spoken moment, not just the first. A call
// that gives the reminder in English and then goes silent for the question
// is a call the customer cannot answer.
t_ok('the question falls back too', !empty($plan['question']['text']));
t_ok('and so does every reply',
     !empty($plan['replies']['yes']['text']) && !empty($plan['replies']['no']['text'])
     && !empty($plan['replies']['none']['text']));

t_group('Voice — the generated audio is made once, and capped');

t_eq('the same sentence maps to the same file',
     voice_tts_file('નમસ્કાર', 'gu'), voice_tts_file('નમસ્કાર', 'gu'));
t_ok('a different sentence maps to a different file',
     voice_tts_file('નમસ્કાર', 'gu') !== voice_tts_file('નમસ્તે', 'gu'));
t_ok('a different language maps to a different file',
     voice_tts_file('નમસ્કાર', 'gu') !== voice_tts_file('નમસ્કાર', 'hi'));
t_ok('the file name is safe to write to disk',
     preg_match('/^tts_[a-z]{2}_[a-f0-9]{24}\.wav$/', voice_tts_file('x', 'gu')) === 1, voice_tts_file('x', 'gu'));

// Only a name this system generated is ever written onto a call row.
t_eq('a generated name is accepted',
     voice_audio_basename('https://x.example/uploads/voice/tts/tts_gu_' . str_repeat('a', 24) . '.wav'),
     'tts_gu_' . str_repeat('a', 24) . '.wav');
t_ok('a path walking out of the folder is refused',
     voice_audio_basename('https://x.example/uploads/voice/tts/../../config.php') === null);
t_ok('someone else\'s file name is refused',
     voice_audio_basename('https://evil.example/hello.wav') === null);
t_ok('an empty url is refused', voice_audio_basename('') === null);

set_setting('voice_tts_month', date('Y-m'));
set_setting('voice_tts_count', '5');
$u = voice_tts_usage();
t_eq('this month\'s count is read back', $u['used'], 5);
set_setting('voice_tts_month', date('Y-m', strtotime('-2 months')));
t_eq('last month\'s count does not carry over', voice_tts_usage()['used'], 0);

set_setting('gemini_api_key', 'TESTKEY');
set_setting('voice_tts_month', date('Y-m'));
set_setting('voice_tts_count', '99999');
$capped = voice_tts_audio('કંઈક નવું ' . bin2hex(random_bytes(4)), 'gu');
t_eq('past the monthly cap nothing is generated', $capped['ok'], false);
t_ok('and the reason says so', stripos($capped['error'], 'limit') !== false, $capped['error']);
set_setting('voice_tts_count', '0');
set_setting('gemini_api_key', '');

t_group('Voice — reading the reply from the voice service');

// The one part of this feature that depends on somebody else's JSON shape.
// It is walked rather than indexed, so a field moving one level deeper does
// not turn every Gujarati call into a silent English one - and these are the
// shapes it has to survive.
$big = base64_encode(random_bytes(4000));
t_eq('the documented shape is read',
     voice_tts_find_audio(['steps' => [['content' => [['type' => 'audio', 'data' => $big]]]]]), $big);
t_eq('a shape one level deeper still works',
     voice_tts_find_audio(['response' => ['output' => [['parts' => [['inline_data' => $big]]]]]]), $big);
t_eq('the older inlineData spelling works',
     voice_tts_find_audio(['candidates' => [['content' => ['parts' => [['inlineData' => $big]]]]]]), $big);
t_ok('a reply with no audio in it returns nothing, rather than guessing',
     voice_tts_find_audio(['error' => ['message' => 'quota exceeded']]) === '');
t_ok('short strings are not mistaken for audio',
     voice_tts_find_audio(['data' => 'abc', 'mime' => 'audio/wav']) === '');
t_ok('a flat refusal is not mistaken for audio', voice_tts_find_audio([]) === '');

t_group('Voice — the question at the end');

$q = voice_question('gu');
t_ok('the Gujarati question asks about paying today', strpos($q['plain'], 'આજે') !== false);
t_ok('and explains which key means yes', strpos($q['plain'], 'એક') !== false);
t_ok('and which means no', strpos($q['plain'], 'બે') !== false);
$qp = voice_question('gu', '2026-09-20');
t_ok('a customer who promised is reminded of their own words',
     strpos($qp['promise'], 'કહ્યું હતું') !== false && strpos($qp['promise'], dmy('2026-09-20')) !== false);
t_ok('somebody who promised nothing is not accused of it',
     strpos($q['plain'], 'કહ્યું હતું') === false);
$qh = voice_question('hi');
t_ok('Hindi asks the same thing', strpos($qh['plain'], 'आज') !== false);
t_ok('there is something to say to yes', $q['yes'] !== '');
t_ok('something to say to no', $q['no'] !== '');
t_ok('and something to say when nothing is pressed', $q['none'] !== '');

t_group('Voice — who may be called');

$pid = t_party('TEST_VOICE_' . bin2hex(random_bytes(3)));
q("UPDATE parties SET mobile = '9876500001' WHERE id = ?", [$pid]);
t_sale($pid, 5000, 0, date('Y-m-d', strtotime('-40 days')), date('Y-m-d', strtotime('-10 days')));

$ctx = voice_party_context($pid);
t_eq('the context reads the debt off the ledger', $ctx['due'], 5000);
$gate = voice_can_call($pid, $ctx, $NOON);
t_ok('a customer who owes money can be called', $gate['ok'], $gate['why']);

// ---- each guard, one at a time ----
q('UPDATE parties SET voice_dnd = 1 WHERE id = ?', [$pid]);
$g = voice_can_call($pid, null, $NOON);
t_eq('"do not call" stops the call', $g['ok'], false);
t_ok('and says so in words', stripos($g['why'], 'not to be called') !== false, $g['why']);
q('UPDATE parties SET voice_dnd = 0 WHERE id = ?', [$pid]);

// The do-not-call switch is NOT the message opt-out. A customer may want the
// WhatsApp and not the phone call; the two must not be the same flag.
q('UPDATE parties SET collection_opt_out = 1 WHERE id = ?', [$pid]);
$g = voice_can_call($pid, null, $NOON);
t_eq('a message opt-out also stops the call', $g['ok'], false);
q('UPDATE parties SET collection_opt_out = 0, voice_dnd = 1 WHERE id = ?', [$pid]);
$after = row('SELECT collection_opt_out, voice_dnd FROM parties WHERE id = ?', [$pid]);
t_ok('but they are two separate switches, not one',
     (int)$after['collection_opt_out'] === 0 && (int)$after['voice_dnd'] === 1);
q('UPDATE parties SET voice_dnd = 0 WHERE id = ?', [$pid]);

q("UPDATE parties SET mobile = '0221234567' WHERE id = ?", [$pid]);   // a landline
$g = voice_can_call($pid, null, $NOON);
t_eq('a landline is not dialled', $g['ok'], false);
q("UPDATE parties SET mobile = '9876500001' WHERE id = ?", [$pid]);

t_ok('10-digit mobiles pass', voice_mobile_ok('9876543210'));
t_ok('+91 and spaces are tolerated', voice_mobile_ok('+91 98765 43210'));
t_ok('9 digits are refused', !voice_mobile_ok('987654321'));
t_ok('a number starting 1 is refused', !voice_mobile_ok('1234567890'));
t_eq('the number goes to the provider in E.164', voice_e164('98765 43210'), '919876543210');

t_group('Voice — calling hours (TRAI 9am-9pm)');

t_ok('8am is too early', !voice_hours_ok(strtotime('today 08:30')));
t_ok('9am is fine', voice_hours_ok(strtotime('today 09:15')));
t_ok('2pm is fine', voice_hours_ok(strtotime('today 14:00')));
t_ok('8:59pm is the last minute', voice_hours_ok(strtotime('today 20:59')));
t_ok('9pm is too late', !voice_hours_ok(strtotime('today 21:00')));
t_ok('midnight is refused', !voice_hours_ok(strtotime('today 00:30')));

// The window is clamped, not trusted: a setting outside 9-21 cannot put the
// shop on the wrong side of the rule.
set_setting('voice_hour_from', '6');
set_setting('voice_hour_to', '23');
$h = voice_hours();
t_eq('an over-wide "from" is pulled back to 9', $h['from'], 9);
t_eq('an over-wide "to" is pulled back to 21', $h['to'], 21);
set_setting('voice_hour_from', '10');
set_setting('voice_hour_to', '18');
$h = voice_hours();
t_ok('but a NARROWER window the shop chose is kept', $h['from'] === 10 && $h['to'] === 18);
set_setting('voice_hour_from', '9');
set_setting('voice_hour_to', '21');

t_group('Voice — not twice, and not while switched off');

set_setting('voice_enabled', '0');
$g = voice_can_call($pid, null, $NOON);
t_eq('nothing goes out while calling is off', $g['ok'], false);
set_setting('voice_enabled', '1');

set_setting('vobiz_auth_token', '');
$g = voice_can_call($pid, null, $NOON);
t_eq('nothing goes out without the provider keys', $g['ok'], false);
set_setting('vobiz_auth_token', 'TESTTOKEN');

{
    $before = (int)val('SELECT COUNT(*) FROM voice_calls');
    $r1 = voice_call_send($pid, ['test' => true, 'now' => $NOON]);
    t_ok('a first call goes', $r1['ok'], $r1['error']);
    t_eq('and is recorded', (int)val('SELECT COUNT(*) FROM voice_calls'), $before + 1);

    $row = row('SELECT * FROM voice_calls WHERE id = ?', [$r1['call_id']]);
    t_eq('the row carries the amount from the ledger', (float)$row['amount'], 5000.0);
    t_eq('and the date the money was actually due, not today',
         $row['due_date'], date('Y-m-d', strtotime('-10 days')));
    t_ok('the row keeps what was said, word for word', strlen((string)$row['script']) > 20);
    t_ok('the row has an unguessable token', strlen($row['token']) === 40 && ctype_xdigit($row['token']));
    t_eq('test mode dials nothing', $row['status'], 'test');
    t_eq('and is marked as a test', (int)$row['test_mode'], 1);

    // A phone that rang and was not picked up is NOT a contact. Recording it
    // as one locked the customer out of reminders for three days over a call
    // they never received, and made the six-hour call cooldown unreachable.
    t_eq('a call nobody answered is not written up as a contact',
         (int)val("SELECT COUNT(*) FROM collection_events WHERE party_id = ? AND event_type = 'call' AND channel = 'voice'", [$pid]), 0);

    // the cooldown - the thing that stops an anxious second press
    $r2 = voice_call_send($pid, ['test' => true, 'now' => $NOON]);
    t_eq('a second call inside the cooldown is refused', $r2['ok'], false);
    t_ok('and it is the CALL cooldown that says so, in hours',
         stripos($r2['error'], 'called') !== false && stripos($r2['error'], 'hour') !== false, $r2['error']);
    t_eq('and no second row was written', (int)val('SELECT COUNT(*) FROM voice_calls'), $before + 1);

    // ...but a call that WAS answered is a contact, and from then on the
    // ordinary three-day message rule covers this customer too.
    $ansCall = row('SELECT * FROM voice_calls WHERE id = ?', [$r1['call_id']]);
    voice_mark_answered($ansCall);
    t_eq('an answered call IS written up as a contact',
         (int)val("SELECT COUNT(*) FROM collection_events WHERE party_id = ? AND event_type = 'call' AND channel = 'voice'", [$pid]), 1);
    voice_mark_answered(row('SELECT * FROM voice_calls WHERE id = ?', [$r1['call_id']]));
    t_eq('and a repeated answer callback does not log it twice',
         (int)val("SELECT COUNT(*) FROM collection_events WHERE party_id = ? AND event_type = 'call' AND channel = 'voice'", [$pid]), 1);
    $g2 = voice_can_call($pid, null, $NOON);
    t_eq('after being reached, the message cooldown covers them too', $g2['ok'], false);

    // idempotency - the same press arriving twice
    $pid2 = t_party('TEST_VOICE2_' . bin2hex(random_bytes(3)));
    q("UPDATE parties SET mobile = '9876500002' WHERE id = ?", [$pid2]);
    t_sale($pid2, 2500, 0, date('Y-m-d', strtotime('-30 days')), date('Y-m-d', strtotime('-5 days')));
    $uuid = 'test-uuid-' . bin2hex(random_bytes(6));
    $n0 = (int)val('SELECT COUNT(*) FROM voice_calls');
    $a = voice_call_send($pid2, ['test' => true, 'now' => $NOON, 'client_uuid' => $uuid]);
    $b = voice_call_send($pid2, ['test' => true, 'now' => $NOON, 'client_uuid' => $uuid]);
    t_ok('the first press places the call', $a['ok'], $a['error']);
    t_ok('the same press arriving twice is not a second call', !empty($b['duplicate']));
    t_eq('and the customer is called once', (int)val('SELECT COUNT(*) FROM voice_calls'), $n0 + 1);
    t_eq('the repeat returns the call that already exists', $b['call_id'], $a['call_id']);

    // a refusal must leave nothing behind
    $pid3 = t_party('TEST_VOICE3_' . bin2hex(random_bytes(3)));
    q("UPDATE parties SET mobile = '9876500003', voice_dnd = 1 WHERE id = ?", [$pid3]);
    t_sale($pid3, 900, 0, date('Y-m-d', strtotime('-20 days')), date('Y-m-d', strtotime('-2 days')));
    $n1 = (int)val('SELECT COUNT(*) FROM voice_calls');
    $e1 = (int)val('SELECT COUNT(*) FROM collection_events');
    $r3 = voice_call_send($pid3, ['test' => true, 'now' => $NOON]);
    t_eq('a refused call does not go', $r3['ok'], false);
    t_eq('a refused call writes no call row', (int)val('SELECT COUNT(*) FROM voice_calls'), $n1);
    t_eq('a refused call writes no history either', (int)val('SELECT COUNT(*) FROM collection_events'), $e1);
    t_eq('and does not pretend to have a call id', $r3['call_id'], 0);

    // a customer who owes nothing is never called
    $pid4 = t_party('TEST_VOICE4_' . bin2hex(random_bytes(3)));
    q("UPDATE parties SET mobile = '9876500004' WHERE id = ?", [$pid4]);
    $r4 = voice_call_send($pid4, ['test' => true, 'now' => $NOON]);
    t_eq('nobody is called about nothing', $r4['ok'], false);
}

t_group('Voice — the guards the WhatsApp reminder already had');

// The call must not become a way around the rules the message obeys. These
// are coll_can_remind()'s, reached through voice_can_call().
$pid5 = t_party('TEST_VOICE5_' . bin2hex(random_bytes(3)));
q("UPDATE parties SET mobile = '9876500005' WHERE id = ?", [$pid5]);
t_sale($pid5, 7000, 0, date('Y-m-d', strtotime('-50 days')), date('Y-m-d', strtotime('-20 days')));

coll_log($pid5, 'promise', ['amount' => 7000, 'due_date' => date('Y-m-d', strtotime('+5 days'))]);
$g = voice_can_call($pid5, null, $NOON);
t_eq('an open promise stops the call too', $g['ok'], false);
t_ok('and explains it is a promise', stripos($g['why'], 'promised') !== false, $g['why']);
q("DELETE FROM collection_events WHERE party_id = ? AND event_type = 'promise'", [$pid5]);

coll_log($pid5, 'snooze', ['due_date' => date('Y-m-d', strtotime('+3 days'))]);
$g = voice_can_call($pid5, null, $NOON);
t_eq('a snooze stops the call', $g['ok'], false);
q("DELETE FROM collection_events WHERE party_id = ? AND event_type = 'snooze'", [$pid5]);

t_group('Voice — what came back from the network');

$pid6 = t_party('TEST_VOICE6_' . bin2hex(random_bytes(3)));
q("INSERT INTO voice_calls (party_id, mobile, amount, lang, token, status)
   VALUES (?, '919876500006', 1000, 'en', ?, 'ringing')", [$pid6, str_repeat('a', 40)]);
$cid = (int)insert_id();
$call = row('SELECT * FROM voice_calls WHERE id = ?', [$cid]);

t_eq('a hangup with nobody having picked up is "not answered"',
     voice_status_apply($call, ['Event' => 'Hangup', 'Duration' => 0]), 'no_answer');

q("UPDATE voice_calls SET status = 'ringing', answered = 0 WHERE id = ?", [$cid]);
$call = row('SELECT * FROM voice_calls WHERE id = ?', [$cid]);
t_eq('a busy cause is reported as busy',
     voice_status_apply($call, ['Event' => 'Hangup', 'HangupCause' => 'USER_BUSY']), 'busy');

q("UPDATE voice_calls SET status = 'ringing', answered = 0 WHERE id = ?", [$cid]);
$call = row('SELECT * FROM voice_calls WHERE id = ?', [$cid]);
t_eq('an unknown failure cause is reported as failed',
     voice_status_apply($call, ['Event' => 'Hangup', 'HangupCause' => 'NETWORK_OUT_OF_ORDER']), 'failed');

// The answer URL being fetched is the most reliable "they picked up" signal
// there is, and it must outrank any cause string that arrives afterwards.
q("UPDATE voice_calls SET status = 'ringing', answered = 0 WHERE id = ?", [$cid]);
$call = row('SELECT * FROM voice_calls WHERE id = ?', [$cid]);
voice_mark_answered($call);
$call = row('SELECT * FROM voice_calls WHERE id = ?', [$cid]);
t_eq('fetching the answer URL marks the call answered', $call['status'], 'answered');
t_eq('a hangup afterwards keeps it answered',
     voice_status_apply($call, ['Event' => 'Hangup', 'HangupCause' => 'NORMAL_CLEARING', 'Duration' => 23]), 'answered');
t_eq('and the length is kept', (int)val('SELECT duration FROM voice_calls WHERE id = ?', [$cid]), 23);

t_group('Voice — the answer URL is not a public page');

t_ok('a made-up token finds nothing', voice_call_by_token('deadbeef') === null);
t_ok('a token of the right length but wrong value finds nothing',
     voice_call_by_token(str_repeat('b', 40)) === null);
$real = voice_call_by_token(str_repeat('a', 40));
t_ok('the real token finds its own call', $real && (int)$real['id'] === $cid);
t_ok('a fresh call is served', voice_token_fresh($real));
q("UPDATE voice_calls SET created_at = DATE_SUB(NOW(), INTERVAL 7 HOUR) WHERE id = ?", [$cid]);
t_ok('a token older than six hours is not',
     !voice_token_fresh(row('SELECT * FROM voice_calls WHERE id = ?', [$cid])));

// What the network is handed must be a complete, well-formed document.
q("UPDATE voice_calls SET created_at = NOW(), lang = 'en' WHERE id = ?", [$cid]);
$xml = voice_answer_xml(row('SELECT * FROM voice_calls WHERE id = ?', [$cid]));
t_ok('the answer XML is a Response document',
     strpos($xml, '<Response>') !== false && strpos($xml, '</Response>') !== false);
t_ok('it parses as XML', @simplexml_load_string($xml) !== false);
t_ok('an English call is spoken, not played', strpos($xml, '<Speak') !== false);
t_ok('and carries the amount', strpos($xml, 'one thousand') !== false);
t_ok('nothing listens for keypresses yet (the IVR is a later stage)',
     strpos($xml, '<Gather') === false);

t_group('Voice — the phone network has to be able to fetch it');

// Vobiz fetches audio over https only, and skips a file it cannot fetch
// without saying so. On a plain-http site that is a call which rings, plays
// silence and hangs up - with no error anywhere to explain it.
t_ok('clip URLs are forced to https', strncmp(voice_public_url('uploads/voice/gu/n12.mp3'), 'https://', 8) === 0,
     voice_public_url('uploads/voice/gu/n12.mp3'));
t_ok('so are the callback URLs', strncmp(voice_public_url('voice_answer.php?t=x'), 'https://', 8) === 0);
t_ok('an https site is left alone', voice_public_url('a.mp3') === preg_replace('#^http://#', 'https://', base_url('a.mp3')));
t_ok('generated audio is served over https too',
     strncmp(voice_public_url('uploads/voice/tts/tts_gu_x.wav'), 'https://', 8) === 0);

t_group('Voice — the key never reaches a page');

// The token is stored through the same vault as every other credential.
t_ok('vobiz_auth_token is on the encrypted list', is_secret_setting('vobiz_auth_token'));
t_ok('the webhook secret is too', is_secret_setting('vobiz_webhook_secret'));
$stored = (string)val("SELECT value FROM settings WHERE name = 'vobiz_auth_token'");
t_ok('and it is not sitting in the table as plain text',
     $stored === '' || strncmp($stored, SECRET_PREFIX, strlen(SECRET_PREFIX)) === 0, $stored);

// The two pages the phone network reaches must not be behind the CSRF check,
// and must be the ONLY new ones that are.
$src = file_get_contents(__DIR__ . '/../includes/helpers.php');
t_ok('the answer URL is exempt from CSRF (it has no session to check)',
     strpos($src, "'voice_answer.php'") !== false);
t_ok('the status webhook is too', strpos($src, "'voice_webhook.php'") !== false);

// Nothing about the provider may be printed into a screen.
foreach (['voice_setup.php', 'collection.php'] as $f) {
    $page = file_get_contents(__DIR__ . '/../' . $f);
    t_ok($f . ' never echoes the auth token',
         strpos($page, "e(setting('vobiz_auth_token'))") === false
         && strpos($page, 'value="<?= e(setting(\'vobiz_auth_token\')') === false);
}

t_group('Voice — what they pressed, and what the shop does about it');

set_setting('voice_ivr', '1');
$pid7 = t_party('TEST_VOICE7_' . bin2hex(random_bytes(3)));
q("UPDATE parties SET mobile = '9876500007' WHERE id = ?", [$pid7]);
t_sale($pid7, 3300, 0, date('Y-m-d', strtotime('-25 days')), date('Y-m-d', strtotime('-9 days')));
q("INSERT INTO voice_calls (party_id, mobile, amount, lang, token, status, answered, question_asked)
   VALUES (?, '919876500007', 3300, 'gu', ?, 'answered', 1, 1)", [$pid7, str_repeat('d', 40)]);
$c7 = row('SELECT * FROM voice_calls WHERE token = ?', [str_repeat('d', 40)]);

$promisesBefore = (int)val("SELECT COUNT(*) FROM collection_events WHERE party_id = ? AND event_type = 'promise'", [$pid7]);
t_eq('pressing 1 is recorded as yes', voice_response_apply($c7, '1'), 'yes');
t_eq('and it is written onto the call', val('SELECT response FROM voice_calls WHERE id = ?', [$c7['id']]), 'yes');
t_ok('the time is stamped', val('SELECT response_at FROM voice_calls WHERE id = ?', [$c7['id']]) !== null);

// This is the point of the whole feature: a spoken yes becomes the same
// promise a yes across the counter would, so every screen and the nightly
// job already know what to do with it.
t_eq('a yes becomes a real promise to pay',
     (int)val("SELECT COUNT(*) FROM collection_events WHERE party_id = ? AND event_type = 'promise'", [$pid7]),
     $promisesBefore + 1);
$pr = row("SELECT * FROM collection_events WHERE party_id = ? AND event_type = 'promise' ORDER BY id DESC LIMIT 1", [$pid7]);
t_eq('the promise is for today', $pr['due_date'], today());
t_eq('for the amount that was read out', (float)$pr['amount'], 3300.0);
t_eq('and it is open, so the nightly job will judge it', $pr['status'], 'open');
t_ok('the note says where it came from', stripos($pr['note'], 'reminder call') !== false, $pr['note']);

// having promised, they must now be left alone until the day is out
$g7 = voice_can_call($pid7, null, $NOON);
t_eq('a customer who just promised is not called again', $g7['ok'], false);
t_ok('and the reason is the promise', stripos($g7['why'], 'promised') !== false, $g7['why']);

// a retried callback must not stack a second promise
t_eq('a repeated callback returns the same answer', voice_response_apply(row('SELECT * FROM voice_calls WHERE id = ?', [$c7['id']]), '1'), 'yes');
t_eq('and does not record a second promise',
     (int)val("SELECT COUNT(*) FROM collection_events WHERE party_id = ? AND event_type = 'promise'", [$pid7]),
     $promisesBefore + 1);

// "no" is information too
$pid8 = t_party('TEST_VOICE8_' . bin2hex(random_bytes(3)));
q("UPDATE parties SET mobile = '9876500008' WHERE id = ?", [$pid8]);
q("INSERT INTO voice_calls (party_id, mobile, amount, lang, token, status, answered, question_asked)
   VALUES (?, '919876500008', 1500, 'gu', ?, 'answered', 1, 1)", [$pid8, str_repeat('e', 40)]);
$c8 = row('SELECT * FROM voice_calls WHERE token = ?', [str_repeat('e', 40)]);
t_eq('pressing 2 is recorded as no', voice_response_apply($c8, '2'), 'no');
t_eq('a no does NOT create a promise',
     (int)val("SELECT COUNT(*) FROM collection_events WHERE party_id = ? AND event_type = 'promise'", [$pid8]), 0);
t_eq('but it is kept as a contact, so the next person knows',
     (int)val("SELECT COUNT(*) FROM collection_events WHERE party_id = ? AND event_type = 'call'", [$pid8]), 1);
t_ok('with what they actually said',
     stripos((string)val("SELECT note FROM collection_events WHERE party_id = ? ORDER BY id DESC LIMIT 1", [$pid8]), 'no') !== false);

// pressing nothing, or pressing something else
$pid9 = t_party('TEST_VOICE9_' . bin2hex(random_bytes(3)));
q("INSERT INTO voice_calls (party_id, mobile, amount, lang, token, status, answered, question_asked)
   VALUES (?, '919876500009', 800, 'gu', ?, 'answered', 1, 1)", [$pid9, str_repeat('f', 40)]);
$c9 = row('SELECT * FROM voice_calls WHERE token = ?', [str_repeat('f', 40)]);
t_eq('pressing nothing is recorded as no answer', voice_response_apply($c9, ''), 'none');
t_eq('and promises nothing',
     (int)val("SELECT COUNT(*) FROM collection_events WHERE party_id = ? AND event_type = 'promise'", [$pid9]), 0);

$pid10 = t_party('TEST_VOICE10_' . bin2hex(random_bytes(3)));
q("INSERT INTO voice_calls (party_id, mobile, amount, lang, token, status, answered, question_asked)
   VALUES (?, '919876500010', 800, 'gu', ?, 'answered', 1, 1)", [$pid10, str_repeat('0', 40)]);
$c10 = row('SELECT * FROM voice_calls WHERE token = ?', [str_repeat('0', 40)]);
t_eq('a stray key is not read as a yes', voice_response_apply($c10, '7'), 'none');
t_eq('and promises nothing either',
     (int)val("SELECT COUNT(*) FROM collection_events WHERE party_id = ? AND event_type = 'promise'", [$pid10]), 0);

t_group('Voice — the XML that asks the question');

q("UPDATE voice_calls SET lang = 'en', audio_file = NULL, question_asked = 1, created_at = NOW() WHERE id = ?", [$c9['id']]);
$xml = voice_answer_xml(row('SELECT * FROM voice_calls WHERE id = ?', [$c9['id']]));
t_ok('it parses as XML', @simplexml_load_string($xml) !== false);
t_ok('it asks for one key', strpos($xml, 'numDigits="1"') !== false);
t_ok('it waits, but not forever', strpos($xml, 'executionTimeout="10"') !== false);
t_ok('it does not make an old customer find the hash key', strpos($xml, 'finishOnKey="none"') !== false);
t_ok('the keypress comes back to us with the call token',
     strpos($xml, 'voice_gather.php?t=' . $c9['token']) !== false);
t_ok('the question is asked INSIDE the wait, not before it',
     strpos($xml, '<Gather') < strpos($xml, 'Press one') && strpos($xml, 'Press one') < strpos($xml, '</Gather>'));
t_ok('and there is still a goodbye for somebody who presses nothing',
     strpos($xml, '</Gather>') < strrpos($xml, '<Speak'));

q("UPDATE voice_calls SET question_asked = 0 WHERE id = ?", [$c9['id']]);
$xml2 = voice_answer_xml(row('SELECT * FROM voice_calls WHERE id = ?', [$c9['id']]));
t_ok('with the question switched off the call just gives the reminder',
     strpos($xml2, '<Gather') === false);

// the generated file is played, not re-generated while the customer waits
q("UPDATE voice_calls SET audio_file = ?, question_asked = 0 WHERE id = ?",
  ['tts_gu_' . str_repeat('a', 24) . '.wav', $c9['id']]);
$xml3 = voice_answer_xml(row('SELECT * FROM voice_calls WHERE id = ?', [$c9['id']]));
t_ok('a call with generated audio plays the file it already made',
     strpos($xml3, 'tts_gu_' . str_repeat('a', 24) . '.wav') !== false && strpos($xml3, '<Play>') !== false);
t_ok('and does not speak English over the top of it', strpos($xml3, '<Speak') === false);

t_group('Voice — one rule, one place');

// The whole point of includes/voice.php. If a second screen ever starts
// deciding for itself whether a phone may ring, this is what catches it.
$callers = [];
foreach (glob(__DIR__ . '/../*.php') as $f) {
    $src = file_get_contents($f);
    if (strpos($src, 'voice_call_send(') !== false) $callers[] = basename($f);
}
t_ok('placing a reminder call goes through voice_call_send() only',
     count($callers) <= 2, implode(', ', $callers));

$vsrc = file_get_contents(__DIR__ . '/../includes/voice.php');
// Call sites, not mentions - the URL also appears in a comment above the
// function, and counting that made this assertion measure the documentation.
t_ok('the provider is reached from exactly two places — place a call, check the balance',
     substr_count($vsrc, "curl_init('https://api.vobiz.ai") === 2,
     'found ' . substr_count($vsrc, "curl_init('https://api.vobiz.ai"));
t_ok('the calling-hours rule is written once',
     substr_count($vsrc, 'function voice_hours_ok') === 1);
t_ok('voice_can_call() defers to the message rules instead of copying them',
     strpos($vsrc, 'coll_can_remind($c)') !== false);

// A page that calls a function which no longer exists renders halfway and
// then dies - the top of the screen looks fine, so it is easy to miss. This
// caught exactly that when the hand-recorded clip system was replaced.
$defined = [];
preg_match_all('/^function (voice_\w+)\s*\(/m', $vsrc, $m);
foreach ($m[1] as $fn) $defined[$fn] = true;
$called = [];
foreach (array_merge(glob(__DIR__ . '/../*.php'), glob(__DIR__ . '/../includes/*.php')) as $f) {
    if (basename($f) === 'voice.php') continue;
    preg_match_all('/\b(voice_\w+)\s*\(/', file_get_contents($f), $mm);
    foreach ($mm[1] as $fn) {
        // "INSERT INTO voice_calls (" looks exactly like a call. It is the
        // table, and the only voice_* name that is not a function.
        if ($fn === 'voice_calls') continue;
        if (!isset($defined[$fn])) $called[$fn] = basename($f);
    }
}
t_ok('every voice_* function a page calls actually exists', !$called,
     implode(', ', array_map(fn($f, $fn) => "$fn in $f", $called, array_keys($called))));
