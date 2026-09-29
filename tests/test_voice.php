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

t_group('Voice — calling hours');

t_ok('8am is too early', !voice_hours_ok(strtotime('today 08:30')));
t_ok('9am is fine', voice_hours_ok(strtotime('today 09:15')));
t_ok('2pm is fine', voice_hours_ok(strtotime('today 14:00')));
t_ok('8:59pm is the last minute', voice_hours_ok(strtotime('today 20:59')));
t_ok('9pm is too late', !voice_hours_ok(strtotime('today 21:00')));
t_ok('midnight is refused', !voice_hours_ok(strtotime('today 00:30')));

// The window is the shop's to set. It used to be clamped to 9-21 in code,
// which also stopped the owner testing their own system in the evening -
// the wrong place to enforce a rule about customers. The screen warns
// instead, and these assertions are about the warning being right.
set_setting('voice_hour_from', '9');
set_setting('voice_hour_to', '21');
$h = voice_hours();
t_ok('the default window is the legal one', $h['from'] === 9 && $h['to'] === 21);
t_eq('and is reported as legal', $h['legal'], true);
t_eq('and is not all day', $h['all_day'], false);

set_setting('voice_hour_from', '10');
set_setting('voice_hour_to', '18');
$h = voice_hours();
t_ok('a narrower window the shop chose is kept', $h['from'] === 10 && $h['to'] === 18);
t_eq('and is still legal', $h['legal'], true);

set_setting('voice_hour_from', '0');
set_setting('voice_hour_to', '24');
$h = voice_hours();
t_ok('0 to 24 means any hour', $h['from'] === 0 && $h['to'] === 24);
t_eq('which the screen must flag as outside the law', $h['legal'], false);
t_eq('and name as all day', $h['all_day'], true);
t_ok('a call at midnight is allowed once it is set that way', voice_hours_ok(strtotime('today 00:30')));
t_ok('and at eleven at night', voice_hours_ok(strtotime('today 23:00')));

set_setting('voice_hour_from', '6');
set_setting('voice_hour_to', '23');
$h = voice_hours();
t_ok('a wider-than-legal window is obeyed', $h['from'] === 6 && $h['to'] === 23);
t_eq('but reported as outside the law', $h['legal'], false);
t_ok('and 6am now passes', voice_hours_ok(strtotime('today 06:30')));

// A typo must not quietly turn into "call at any hour".
set_setting('voice_hour_from', '20');
set_setting('voice_hour_to', '8');
$h = voice_hours();
t_ok('a backwards window falls back to the legal one', $h['from'] === 9 && $h['to'] === 21);
t_ok('so a typo does not start calls at midnight', !voice_hours_ok(strtotime('today 02:00')));

set_setting('voice_hour_from', '9');
set_setting('voice_hour_to', '21');

// The owner ringing their own phone is not a customer being disturbed, so
// the setup screen's test call does not consult the hours at all.
$setup = file_get_contents(__DIR__ . '/../voice_setup.php');
$testBlock = substr($setup, strpos($setup, "post('do') === 'test_call'"), 1200);
t_ok('the test call does not check the calling hours',
     strpos($testBlock, 'voice_hours_ok()') === false);
t_ok('but a call to a CUSTOMER still does',
     strpos(file_get_contents(__DIR__ . '/../includes/voice.php'), 'if (!voice_hours_ok($now))') !== false);

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
foreach (['voice.php', 'voice_in.php'] as $mod) {
    preg_match_all('/^function (voice_\w+)\s*\(/m', file_get_contents(__DIR__ . '/../includes/' . $mod), $m);
    foreach ($m[1] as $fn) $defined[$fn] = true;
}
$called = [];
foreach (array_merge(glob(__DIR__ . '/../*.php'), glob(__DIR__ . '/../includes/*.php')) as $f) {
    if (in_array(basename($f), ['voice.php', 'voice_in.php'], true)) continue;
    preg_match_all('/\b(voice_\w+)\s*\(/', file_get_contents($f), $mm);
    foreach ($mm[1] as $fn) {
        // "INSERT INTO voice_calls (" and "voice_ivr_events (" look exactly
        // like calls. They are tables - the only voice_* names that are not
        // functions.
        if (in_array($fn, ['voice_calls', 'voice_ivr_events'], true)) continue;
        if (!isset($defined[$fn])) $called[$fn] = basename($f);
    }
}
t_ok('every voice_* function a page calls actually exists', !$called,
     implode(', ', array_map(fn($f, $fn) => "$fn in $f", $called, array_keys($called))));

// ---------------------------------------------------------------------------
require_once __DIR__ . '/../includes/voice_in.php';

t_group('Voice IN — the menu a caller hears');

set_setting('voice_inbound', '1');
set_setting('voice_inbound_lang', 'gu');
set_setting('vobiz_inbound_number', '919999900000');
set_setting('voice_shop_open', '9');
set_setting('voice_shop_close', '21');
set_setting('voice_ivr_balance', 'speak');

$w = voice_in_words('gu', ['name' => 'રમેશભાઈ']);
t_ok('the greeting uses their name', strpos($w['welcome_name'], 'રમેશભાઈ') !== false);
t_ok('the menu offers the balance', strpos($w['menu'], 'એક દબાવો') !== false);
t_ok('the menu offers ordering', strpos($w['menu'], 'બે દબાવો') !== false);
t_ok('the menu offers a complaint', strpos($w['menu'], 'ત્રણ દબાવો') !== false);
t_ok('the menu offers a person', strpos($w['menu'], 'નવ દબાવો') !== false);
t_ok('Hindi says the same things', strpos(voice_in_words('hi')['menu'], 'दो दबाएँ') !== false);
t_ok('English exists as the fallback', strpos(voice_in_words('en')['menu'], 'press two') !== false);
foreach (['gu', 'hi', 'en'] as $L) {
    $ww = voice_in_words($L);
    t_eq("every line exists in $L", count(array_filter($ww, fn($x) => trim((string)$x) !== '')), count($ww));
}

t_group('Voice IN — shop hours');

t_ok('open at 2pm', voice_in_open(strtotime('today 14:00')));
t_ok('a call at 3am still reaches the menu — only "talk to us" changes',
     strpos(voice_in_menu_xml($call ?? voice_in_start('919000022222', '919999900000', 'uuid-n-' . bin2hex(random_bytes(3))),
                              1, strtotime('today 03:00')), '<Gather') !== false);
t_ok('shut at 11pm', !voice_in_open(strtotime('today 23:00')));
t_ok('shut at 6am', !voice_in_open(strtotime('today 06:00')));
set_setting('voice_shop_open', '20');
set_setting('voice_shop_close', '8');       // nonsense
t_ok('a backwards window falls back to 9-21', voice_in_open(strtotime('today 10:00')));
set_setting('voice_shop_open', '9');
set_setting('voice_shop_close', '21');

t_group('Voice IN — who is calling');

$pidA = t_party('TEST_IN_' . bin2hex(random_bytes(3)));
q("UPDATE parties SET mobile = '9876512345' WHERE id = ?", [$pidA]);
t_sale($pidA, 4500, 0, date('Y-m-d', strtotime('-30 days')), date('Y-m-d', strtotime('-7 days')));

$call = voice_in_start('919876512345', '919999900000', 'uuid-in-' . bin2hex(random_bytes(4)));
t_ok('an incoming call is recorded', (int)$call['id'] > 0);
t_eq('marked as incoming', $call['direction'], 'in');
t_eq('and matched to the customer by their number', (int)$call['party_id'], $pidA);
t_eq('it is answered by definition — we are speaking to them', (int)$call['answered'], 1);
t_ok('it has its own token for the rest of the call', strlen($call['token']) === 40);

// A retried webhook must not become a second call in the shop's records.
$again = voice_in_start('919876512345', '919999900000', $call['call_uuid']);
t_eq('a retried webhook finds the same call', (int)$again['id'], (int)$call['id']);

$unknown = voice_in_start('919000011111', '919999900000', 'uuid-unk-' . bin2hex(random_bytes(4)));
t_eq('a stranger is recorded too', $unknown['direction'], 'in');
t_eq('but tied to no customer', (int)$unknown['party_id'], 0);

t_group('Voice IN — what each key does');

$xml = voice_in_menu_xml($call, 1);
t_ok('the greeting XML parses', @simplexml_load_string($xml) !== false);
t_ok('it waits for one key', strpos($xml, 'numDigits="1"') !== false);
t_ok('the keypress comes back with this call token', strpos($xml, 'voice_in.php?step=menu&amp;t=' . $call['token']) !== false);
t_ok('pressing nothing gets one more go', strpos($xml, '<Redirect>') !== false);

// 2 = order -> records, and is left needing somebody
$x2 = voice_in_branch($call, '2');
t_ok('pressing 2 asks them to speak', strpos($x2, '<Record') !== false);
t_ok('and the recording comes back to us', strpos($x2, 'step=rec_done') !== false);
t_ok('hash ends the recording', strpos($x2, 'finishOnKey="#"') !== false);
$after = row('SELECT * FROM voice_calls WHERE id = ?', [$call['id']]);
t_eq('the call is filed as an order', $after['intent'], 'order');
t_eq('and marked as still needing somebody', (int)$after['needs_action'], 1);

// 3 = complaint
$x3 = voice_in_branch($call, '3');
t_ok('pressing 3 records too', strpos($x3, '<Record') !== false);
t_eq('and is filed as a complaint', val('SELECT intent FROM voice_calls WHERE id = ?', [$call['id']]), 'complaint');

// 1 = balance, for someone we know
$x1 = voice_in_branch($call, '1');
t_ok('pressing 1 says something back', strpos($x1, '<Speak') !== false || strpos($x1, '<Play') !== false);
t_ok('and the amount was read from the ledger, not invented',
     (int)val("SELECT COUNT(*) FROM voice_ivr_events WHERE call_id = ? AND step = 'balance_read'", [$call['id']]) === 1);
$readOut = (string)val("SELECT detail FROM voice_ivr_events WHERE call_id = ? AND step = 'balance_read' ORDER BY id DESC LIMIT 1", [$call['id']]);
t_eq('and it is the real outstanding amount', $readOut, '₹' . money(party_balance_side($pidA, 'in')));

// 1 = balance, for a stranger — must not fish for information
$x1u = voice_in_branch($unknown, '1');
t_ok('a stranger is told their number is not registered',
     strpos($x1u, 'નોંધાયેલો નથી') !== false || stripos($x1u, 'not registered') !== false);
t_ok('and no amount is read to them',
     (int)val("SELECT COUNT(*) FROM voice_ivr_events WHERE call_id = ? AND step = 'balance_read'", [$unknown['id']]) === 0);

// the owner can refuse to discuss money by phone at all
set_setting('voice_ivr_balance', 'off');
$xOff = voice_in_branch($call, '1');
t_ok('with money questions switched off, nothing is read out',
     strpos($xOff, 'નોંધાયેલો નથી') !== false || stripos($xOff, 'not registered') !== false);
set_setting('voice_ivr_balance', 'speak');

// 9 = a person
set_setting('voice_agent_numbers', '9824537749, 9825012345');
$x9 = voice_in_branch($call, '9', $NOON);
t_ok('pressing 9 rings the shop phones', strpos($x9, '<Dial') !== false);
t_ok('both numbers are rung', substr_count($x9, '<Number>') === 2);
t_ok('and the result comes back to us', strpos($x9, 'step=dial_done') !== false);

set_setting('voice_agent_numbers', '');
$x9b = voice_in_branch($call, '9', $NOON);
t_ok('with no phones configured it takes a message instead', strpos($x9b, '<Record') !== false);
t_ok('and does not dial nobody', strpos($x9b, '<Dial') === false);

// a key that means nothing
$xBad = voice_in_branch($call, '7');
t_ok('a key that means nothing repeats the menu', strpos($xBad, '<Gather') !== false);

t_group('Voice IN — the keys pressed are kept');

$path = (string)val('SELECT ivr_path FROM voice_calls WHERE id = ?', [$call['id']]);
t_ok('every key is remembered in order', substr_count($path, '-') >= 3, $path);
t_ok('and each one has its own event row',
     (int)val('SELECT COUNT(*) FROM voice_ivr_events WHERE call_id = ?', [$call['id']]) >= 5);

t_group('Voice IN — a recording becomes work the shop tracks');

$tk = bin2hex(random_bytes(20));
q("INSERT INTO voice_calls (party_id, mobile, direction, from_number, to_number, token, lang, status, answered)
   VALUES (?, '919876512345', 'in', '919876512345', '919999900000', ?, 'gu', 'answered', 1)", [$pidA, $tk]);
$recCall = row('SELECT * FROM voice_calls WHERE token = ?', [$tk]);
$ticketsBefore = (int)val('SELECT COUNT(*) FROM tickets');
voice_in_recording_done($recCall, ['RecordUrl' => 'https://rec.example/abc.mp3', 'RecordingDuration' => '31'], 'complaint');
$recCall = row('SELECT * FROM voice_calls WHERE id = ?', [$recCall['id']]);
t_eq('the recording is kept on the call', $recCall['recording_url'], 'https://rec.example/abc.mp3');
t_eq('with its length', (int)$recCall['recording_secs'], 31);
t_eq('a spoken complaint becomes a real ticket', (int)val('SELECT COUNT(*) FROM tickets'), $ticketsBefore + 1);
t_eq('and the call points at it', $recCall['ref_type'], 'ticket');
$tkt = row('SELECT * FROM tickets WHERE id = ?', [(int)$recCall['ref_id']]);
t_ok('the ticket is numbered like any other', !empty($tkt['ticket_no']), (string)($tkt['ticket_no'] ?? ''));
t_ok('and carries the number to call back', strpos((string)$tkt['customer_mobile'], '9876512345') !== false);
t_ok('and where to hear what they said', strpos((string)$tkt['description'], 'rec.example') !== false);

$leadsBefore = (int)val('SELECT COUNT(*) FROM leads');
$tk2 = bin2hex(random_bytes(20));
q("INSERT INTO voice_calls (party_id, mobile, direction, from_number, to_number, token, lang, status, answered)
   VALUES (0, '919000011111', 'in', '919000011111', '919999900000', ?, 'gu', 'answered', 1)", [$tk2]);
$rc2 = row('SELECT * FROM voice_calls WHERE token = ?', [$tk2]);
voice_in_recording_done($rc2, ['RecordUrl' => 'https://rec.example/def.mp3', 'RecordingDuration' => '12'], 'order');
t_eq('a spoken order becomes a lead', (int)val('SELECT COUNT(*) FROM leads'), $leadsBefore + 1);
t_eq('even from somebody we have never heard of',
     val('SELECT ref_type FROM voice_calls WHERE id = ?', [$rc2['id']]), 'lead');
t_ok('a recording with no url is ignored rather than filed empty',
     (function () use ($rc2) {
         $before = (int)val('SELECT COUNT(*) FROM leads');
         voice_in_recording_done($rc2, ['RecordingDuration' => '5'], 'order');
         return (int)val('SELECT COUNT(*) FROM leads') === $before;
     })());

t_group('Voice IN — nobody is left waiting silently');

t_ok('the waiting list has these calls', voice_in_pending_count() >= 2);
voice_call_handled($recCall['id'], 'rang them back');
$done = row('SELECT * FROM voice_calls WHERE id = ?', [$recCall['id']]);
t_eq('marking one done clears it', (int)$done['needs_action'], 0);
t_ok('and records who did it', (int)$done['handled_by'] > 0);
t_eq('with the note', $done['notes'], 'rang them back');

t_group('Voice IN — the door is not open to anyone');

$src = file_get_contents(__DIR__ . '/../voice_in.php');
t_ok('a call to a number that is not ours is refused',
     strpos($src, 'voice_in_to_ok($to)') !== false && strpos($src, "if (!\$gate['ok'])") !== false);
t_ok('the first request is rate limited', strpos($src, 'api_rate_ok(') !== false);
t_ok('every later step needs the call token', strpos($src, 'voice_call_by_token(get(\'t\'))') !== false);
t_ok('an outgoing call token cannot drive the incoming menu',
     strpos($src, "\$call['direction'] !== 'in'") !== false);
t_ok('the incoming page is exempt from CSRF, like the other phone-network pages',
     strpos(file_get_contents(__DIR__ . '/../includes/helpers.php'), "'voice_in.php'") !== false);

t_group('Voice — speech is never made while somebody is on the line');

// Generating a sentence takes seconds and is allowed up to forty-five. The
// live paths must play what exists and say the rest in English instead.
set_setting('gemini_api_key', 'TESTKEY');
set_setting('voice_tts', '1');
$fresh = voice_tts_audio('કદી ન બોલાયેલું વાક્ય ' . bin2hex(random_bytes(6)), 'gu', true);
t_eq('a sentence never spoken before is not made on the live path', $fresh['ok'], false);
t_ok('and says why without pretending', stripos($fresh['error'], 'not generated') !== false, $fresh['error']);
set_setting('gemini_api_key', '');

foreach (['voice_answer.php' => 'the answer URL', 'voice_gather.php' => 'the keypress URL'] as $f => $label) {
    $fs = file_get_contents(__DIR__ . '/../' . $f);
    $usesPlan = strpos($fs, 'voice_audio_plan(') !== false;
    t_ok($label . ' never waits for new speech',
         !$usesPlan || preg_match('/voice_audio_plan\([^;]*,\s*true\s*\)/s', $fs) === 1);
}
$vs = file_get_contents(__DIR__ . '/../includes/voice.php');
t_ok('the answer XML asks for cached audio only',
     preg_match('/voice_audio_plan\([^;]*\$promise,\s*true\)/', $vs) === 1);

t_group('Voice IN — a sentence falls back whole, or not at all');

// A line that could not be made in Gujarati is said in English - but the
// values put INTO it must switch language too. "Your outstanding amount is
// નવ હજાર રૂપિયા" is half a sentence in each, which is worse than either.
set_setting('gemini_api_key', '');          // force the English fallback
set_setting('voice_ivr_balance', 'speak');
$pidM = t_party('TEST_MIX_' . bin2hex(random_bytes(3)));
q("UPDATE parties SET mobile = '9876543211' WHERE id = ?", [$pidM]);
t_sale($pidM, 9000, 0, date('Y-m-d', strtotime('-20 days')), date('Y-m-d', strtotime('-5 days')));
$cm = voice_in_start('919876543211', '919999900000', 'uuid-mix-' . bin2hex(random_bytes(4)));
$xb = voice_in_balance_xml($cm);

$hasGu = preg_match('/[\x{0A80}-\x{0AFF}]/u', $xb) === 1;
$hasEn = preg_match('/\b(outstanding|Thank you)\b/', $xb) === 1;
t_ok('the balance line does not mix the two languages', !($hasGu && $hasEn),
     trim(strip_tags($xb)));
t_ok('and the amount is still there, in whichever one it chose',
     stripos($xb, 'nine thousand') !== false || strpos($xb, 'નવ હજાર') !== false,
     trim(strip_tags($xb)));

// the same rule for the delivery line
$xs = voice_in_status_word('shipped', 'en');
t_eq('status words exist in English too', $xs, 'on its way');
t_ok('and in Gujarati', voice_in_status_word('shipped', 'gu') === 'નીકળી ગયો છે');

t_group('Voice IN — attaching a number is not a guessing game');

// Vobiz answers "access denied" for a number it never sold you, which reads
// like a permissions problem and almost never is. The message has to say so.
$vsrc2 = file_get_contents(__DIR__ . '/../includes/voice_in.php');
t_ok('the numbers on the account can be listed', strpos($vsrc2, "voice_api('GET', 'numbers") !== false);
// This hint used to fire whenever Vobiz said "access denied" and told the
// owner their own number was not theirs - comparing "918065354620" against
// "+918065354620" and calling them different. It has to stay gone.
t_ok('the old hint that accused a number of not being owned is gone',
     strpos($vsrc2, 'this usually means') === false);
t_ok('ownership is decided on the digits, not on how it is written',
     strpos($vsrc2, 'voice_num_same(') !== false);
t_ok('a number that needs KYC is flagged rather than silently attached',
     strpos($vsrc2, 'aadhaar_verification_required') !== false);

$setup2 = file_get_contents(__DIR__ . '/../voice_setup.php');
t_ok('making the application and attaching a number are separate presses',
     strpos($setup2, "post('do') === 'attach_number'") !== false
     && strpos($setup2, "post('do') === 'app_setup'") !== false);
t_ok('the application step no longer tries to attach anything',
     strpos(substr($setup2, strpos($setup2, "post('do') === 'app_setup'"), 700), 'voice_in_number_attach') === false);
t_ok('the numbers are shown as a list to choose from', strpos($setup2, 'Use this one') !== false);

t_group('Voice IN — a dead line is the worst failure, so the guard fails open');

// The first version of this guard compared the dialled number against one
// setting, and that setting is only written when a number attaches. Until
// the attach worked, EVERY real call was refused and the caller heard the
// line cut dead - the shop's main number, silently broken by its own guard.
set_setting('vobiz_inbound_number', '');
set_setting('vobiz_numbers_cache', json_encode(['at' => time(), 'nums' => []]));
$g = voice_in_to_ok('918065354620');
t_eq('with nothing set up at all, the call is still answered', $g['ok'], true);
t_eq('but not treated as proven', $g['strict'], false);
t_ok('and it says why in words', strlen($g['why']) > 10, $g['why']);

// once a number IS known, only that number is answered
set_setting('vobiz_inbound_number', '918065354620');
$g = voice_in_to_ok('918065354620');
t_ok('the configured number is answered, and proven', $g['ok'] && $g['strict']);
t_ok('+91 and spacing do not matter', voice_in_to_ok('+91 80653 54620')['ok']);
$g = voice_in_to_ok('919111122223');
t_eq('a call to some other number is refused', $g['ok'], false);
t_ok('with the number in the reason', strpos($g['why'], '919111122223') !== false, $g['why']);
t_eq('a call carrying no number at all is refused', voice_in_to_ok('')['ok'], false);

// a number bought later must work without anyone re-saving a setting
set_setting('vobiz_inbound_number', '');
set_setting('vobiz_numbers_cache', json_encode(['at' => time(), 'nums' => ['918065354620', '918065354621']]));
$g = voice_in_to_ok('918065354621');
t_ok('any number the account owns is answered, and proven', $g['ok'] && $g['strict']);
$g = voice_in_to_ok('919111122223');
t_eq('but still not a number we do not own', $g['ok'], false);
set_setting('vobiz_numbers_cache', '');

t_group('Voice IN — everybody gets answered');

// Refusing callers we could not identify was wrong: not recognising a number
// is normal, and is no reason to hang up on a customer.
$src = file_get_contents(__DIR__ . '/../voice_in.php');
t_ok('a caller is no longer refused for being unrecognisable',
     !preg_match('/if \(!voice_mobile_ok\(\$from\)\).*exit/', $src));
t_ok('an unproven call is stripped of any customer match',
     strpos($src, "UPDATE voice_calls SET party_id = 0") !== false);

$land = voice_in_start('02212345678', '918065354620', 'uuid-land-' . bin2hex(random_bytes(4)));
t_ok('a landline caller gets a call record', (int)$land['id'] > 0);
$xl = voice_in_menu_xml($land, 1, $NOON);
t_ok('and hears the menu', strpos($xl, '<Gather') !== false);

t_group('Voice IN — the setup checks itself');

$checks = voice_in_diagnose();
t_ok('the diagnosis returns a list of checks', count($checks) >= 2);
t_ok('every check has a name and a verdict',
     count(array_filter($checks, fn($c) => isset($c['name'], $c['ok'], $c['detail']))) === count($checks));
t_ok('https is checked first — nothing works without it', strpos($checks[0]['name'], 'https') !== false);
t_ok('a failing check explains what to press',
     (bool)array_filter($checks, fn($c) => !$c['ok'] && strlen($c['detail']) > 5) || !array_filter($checks, fn($c) => !$c['ok']));

// refusals must be readable by the owner, not only by whoever reads the log
t_ok('refusals are recorded where the screen can show them',
     strpos(file_get_contents(__DIR__ . '/../includes/voice_in.php'), "action = 'voice_in_reject'") !== false);
$setup3 = file_get_contents(__DIR__ . '/../voice_setup.php');
t_ok('and the screen does show them', strpos($setup3, 'turned away') !== false);
t_ok('with a button to run the whole check', strpos($setup3, 'Check my setup') !== false);

// An unproven call must not leak the customer's NAME either - the greeting
// is built from the copy already in hand, so clearing only the row left the
// name being read out to whoever was on the line.
$srcIn = file_get_contents(__DIR__ . '/../voice_in.php');
t_ok('an unproven call is stripped in memory as well as in the row',
     preg_match("/UPDATE voice_calls SET party_id = 0.*?\\\$call\\['party_id'\\] = 0;/s", $srcIn) === 1);

t_group('Voice IN — the diagnosis points at the fix, not at a scroll');

// "press Use this one on a number above" is no help when the table above is
// empty. Each outcome has to read differently, and the one that can be fixed
// on the spot offers to do it.
$vin = file_get_contents(__DIR__ . '/../includes/voice_in.php');
t_ok('owning no numbers says so, and says caller ID is a different thing',
     strpos($vin, 'the caller ID you are using for outgoing calls is not the same thing') !== false);
t_ok('a free number is named rather than gestured at',
     strpos($vin, "\$free[0]['e164'] . ' is free — attach it'") !== false);
t_ok('and the check carries the number so it can be attached in place',
     strpos($vin, "'attach' => \$free[0]['e164']") !== false);
t_ok('numbers that exist but cannot take calls say that instead',
     strpos($vin, 'none of your numbers can take calls yet') !== false);
t_ok('and no numbers at all says there is nothing to point',
     strpos($vin, 'there is no number to point') !== false);

$su = file_get_contents(__DIR__ . '/../voice_setup.php');
t_ok('the screen turns that into a button', strpos($su, 'Attach <?= e($d[\'attach\']) ?> now') !== false);

t_group('Voice IN — attaching: say what is true, not what is guessed');

// The hint compared "918065354620" against "+918065354620" and declared them
// different numbers. A message that tells the owner their own number is not
// theirs is worse than no message.
t_ok('the same number written two ways is one number', voice_num_same('918065354620', '+918065354620'));
t_ok('and with spaces and dashes', voice_num_same('+91 80653-54620', '918065354620'));
t_ok('but two different numbers are still two', !voice_num_same('918065354620', '919111122223'));
t_ok('and nothing matches nothing', !voice_num_same('', '918065354620'));

// Four spellings of one request, tried in order, documented one first.
$shapes = voice_in_attach_shapes('918065354620', 'APP123');
t_ok('several spellings are tried, not one', count($shapes) >= 4, count($shapes) . ' shapes');
t_ok('the documented one goes first', strpos($shapes[0][3], 'documented') === 0);
foreach ($shapes as $i => $sh) {
    t_ok('attempt ' . ($i + 1) . ' names the number and the application',
         strpos($sh[1], '8065354620') !== false && in_array('APP123', array_values($sh[2]), true),
         $sh[0] . ' ' . $sh[1]);
}
// Whatever the verb, every one of them addresses a number and carries only
// the application to put on it. None can reach anything else on the account.
t_ok('all of them address a number and nothing else',
     count(array_filter($shapes, fn($sh) => !preg_match('#^(numbers|Number)/#', $sh[1]))) === 0);
t_ok('and each body carries only the application id',
     count(array_filter($shapes, fn($sh) => count($sh[2]) !== 1)) === 0);
t_ok('the verbs are all safe to repeat',
     count(array_filter($shapes, fn($sh) => !in_array($sh[0], ['POST', 'PUT', 'PATCH'], true))) === 0);

$vin2 = file_get_contents(__DIR__ . '/../includes/voice_in.php');
t_ok('every attempt is kept so the screen can show it', strpos($vin2, "\$trail[] = ['tried'") !== false);
t_ok('a number that IS owned is not accused of not being owned',
     strpos($vin2, 'although the number IS on your') !== false);
t_ok('and one that is not owned is told plainly',
     strpos($vin2, 'is not on your Vobiz account') !== false);
t_ok('the shape that worked is remembered', strpos($vin2, "set_setting('vobiz_attach_shape'") !== false);
t_ok('the cached number list is dropped once something attaches',
     strpos($vin2, "set_setting('vobiz_numbers_cache', '')") !== false);

$su2 = file_get_contents(__DIR__ . '/../voice_setup.php');
t_ok('the screen prints what Vobiz said to each attempt', strpos($su2, 'What Vobiz said to each way') !== false);

t_group('Voice IN — when the API will not, the console still will');

$sh = voice_in_attach_shapes('918065354620', 'APP1', 'NUMID7');
t_eq('every known spelling is tried, including by the number id', count($sh), 8);
t_ok('the number id is used as a path of its own',
     (bool)array_filter($sh, fn($x) => strpos($x[1], 'NUMID7') !== false));
t_ok('other verbs are tried, not just POST',
     count(array_unique(array_column($sh, 0))) >= 3, implode(',', array_unique(array_column($sh, 0))));
t_ok('without an id, the id-based shapes are simply not attempted',
     count(voice_in_attach_shapes('918065354620', 'APP1')) === 6);
t_ok('the numbers listing keeps each number id',
     strpos(file_get_contents(__DIR__ . '/../includes/voice_in.php'), "'id'       => (string)(\$n['id'] ?? '')") !== false);

$vin3 = file_get_contents(__DIR__ . '/../includes/voice_in.php');
t_ok('total refusal points at the console rather than blaming and stopping',
     strpos($vin3, 'Do it in the Vobiz console instead') !== false);
t_ok('and names the number and the application to set there',
     strpos($vin3, 'set its Application to') !== false);
$su3 = file_get_contents(__DIR__ . '/../voice_setup.php');
t_ok('the screen offers to remember a number attached by hand',
     strpos($su3, 'I attached it in the Vobiz console') !== false);

t_group('Voice IN — one application, recognised rather than remembered');

// "Refresh the application" used to POST unconditionally, so every press left
// another application behind and the stored id drifted away from the one the
// console showed. Then the check looked for a number attached to an id the
// owner could not see, and said no number was attached when one was.
$vin4 = file_get_contents(__DIR__ . '/../includes/voice_in.php');
t_ok('applications can be listed', strpos($vin4, "voice_api('GET', 'Application/") !== false);
t_ok('ours are found by the address they point at, not by a stored id',
     strpos($vin4, "\$a['url'] === \$want") !== false);
t_ok('setup adopts an existing one instead of making another',
     strpos($vin4, 'Adopt one that already points here') !== false);
t_ok('and only creates when there is none', strpos($vin4, 'return voice_in_app_create();') !== false);
t_ok('a number attached to ANY application of ours counts',
     strpos($vin4, "in_array(\$n['app_id'], \$ourIds, true)") !== false);
t_ok('spare applications are reported rather than left to confuse',
     strpos($vin4, 'Only one application points here') !== false);

$su4 = file_get_contents(__DIR__ . '/../voice_setup.php');
t_ok('the button says it is safe to press twice', strpos($su4, 'pressing it twice is safe') !== false);
t_ok('and says when it reused one', strpos($su4, 'was already there was used') !== false);

t_group('Voice IN — the menu speaks the shop\'s language, not English');

// An incoming call arrives unannounced. There is no moment to make speech
// while somebody is already on the line, and the live path is forbidden from
// trying - so the menu had nothing to play and fell back to English on every
// single call. The fixed lines have to be made before the first call.
$fixed = voice_in_fixed_keys();
t_ok('the lines that never change are identified', count($fixed) >= 12, count($fixed) . ' lines');
t_ok('the menu itself is one of them', in_array('menu', $fixed, true));
t_ok('so is the greeting without a name', in_array('welcome', $fixed, true));
t_ok('and every reply the caller can reach',
     count(array_intersect(['noted', 'not_known', 'no_answer', 'closed_msg', 'bye'], $fixed)) === 5);

// anything with a placeholder cannot be made in advance
$dyn = array_values(array_diff(array_keys(voice_in_words('en')), $fixed));
t_eq('only the three that carry a value are left out', count($dyn), 3);
t_ok('and they are the ones with a placeholder in them',
     count(array_intersect(['welcome_name', 'balance', 'order_status'], $dyn)) === 3);
foreach ($fixed as $k) {
    if (strpos(voice_in_words('en')[$k], '{') !== false) { t_ok('no fixed line carries a placeholder', false, $k); break; }
}
t_ok('no fixed line carries a placeholder',
     count(array_filter($fixed, fn($k) => strpos(voice_in_words('en')[$k], '{') !== false)) === 0);

// with nothing generated, the status says so rather than claiming readiness
set_setting('gemini_api_key', '');
$st = voice_in_voice_status('gu');
t_eq('an unmade menu is reported as not ready', $st['ready'], false);
t_eq('and counts what is missing', $st['done'] < $st['total'], true);
t_eq('English needs nothing made', voice_in_voice_status('en')['ready'], true);

$vin5 = file_get_contents(__DIR__ . '/../includes/voice_in.php');
t_ok('the greeting falls back to the plain one in the SAME language first',
     strpos($vin5, "'welcome_name', \$lang, ['name' => \$party['name']], false, null, 'welcome'") !== false);
t_ok('language comes before politeness in that fallback',
     strpos($vin5, 'Language first, politeness second') !== false);
t_ok('the check tells the owner when callers are hearing English',
     strpos($vin5, 'callers are hearing English') !== false);
$su5 = file_get_contents(__DIR__ . '/../voice_setup.php');
t_ok('and the screen has the button that fixes it', strpos($su5, 'make_in_voice') !== false);

t_group('Voice IN — the phone asks, WhatsApp answers');

set_setting('voice_wa_followup', '1');
$pidW = t_party('TEST_WA_' . bin2hex(random_bytes(3)));
q("UPDATE parties SET mobile = '9876500077' WHERE id = ?", [$pidW]);
t_sale($pidW, 300, 0, date('Y-m-d', strtotime('-20 days')), date('Y-m-d', strtotime('-5 days')));
$cw = voice_in_start('919876500077', '918065354620', 'uuid-wa-' . bin2hex(random_bytes(4)));

voice_in_branch($cw, '1', $NOON);
t_eq('asking for the balance queues the statement',
     val('SELECT wa_send FROM voice_calls WHERE id = ?', [$cw['id']]), 'stmt');
t_ok('but nothing is sent while the caller is still on the line',
     val('SELECT wa_sent_at FROM voice_calls WHERE id = ?', [$cw['id']]) === null);

voice_in_branch($cw, '3', $NOON);
t_eq('a complaint queues the ticket number instead',
     val('SELECT wa_send FROM voice_calls WHERE id = ?', [$cw['id']]), 'ticket');
voice_in_branch($cw, '2', $NOON);
t_eq('an order queues the order confirmation',
     val('SELECT wa_send FROM voice_calls WHERE id = ?', [$cw['id']]), 'order');
voice_in_branch($cw, '4', $NOON);
t_eq('a price question queues the catalog',
     val('SELECT wa_send FROM voice_calls WHERE id = ?', [$cw['id']]), 'catalog');

// sending twice is worse than sending once, late
q("UPDATE voice_calls SET wa_send = 'stmt', wa_sent_at = NULL WHERE id = ?", [$cw['id']]);
$row = row('SELECT * FROM voice_calls WHERE id = ?', [$cw['id']]);
voice_in_wa_flush($row);
$stamped = val('SELECT wa_sent_at FROM voice_calls WHERE id = ?', [$cw['id']]);
t_ok('flushing stamps it as sent', $stamped !== null);
t_eq('and flushing again sends nothing', voice_in_wa_flush(row('SELECT * FROM voice_calls WHERE id = ?', [$cw['id']])), '');

// a call with nothing promised must not send anything
$cw2 = voice_in_start('919876500078', '918065354620', 'uuid-wa2-' . bin2hex(random_bytes(4)));
t_eq('a call that asked for nothing sends nothing', voice_in_wa_flush($cw2), '');

// the safety net
q("UPDATE voice_calls SET wa_send = 'order', wa_sent_at = NULL, created_at = DATE_SUB(NOW(), INTERVAL 10 MINUTE) WHERE id = ?", [$cw2['id']]);
$n = voice_in_wa_flush_pending(120);
t_ok('a call whose hangup never arrived is swept up later', $n >= 1, (string)$n);
t_ok('and is not swept twice', voice_in_wa_flush_pending(120) === 0);

// switched off means off
set_setting('voice_wa_followup', '0');
$cw3 = voice_in_start('919876500079', '918065354620', 'uuid-wa3-' . bin2hex(random_bytes(4)));
voice_in_branch($cw3, '1', $NOON);
t_ok('with the follow-up switched off nothing is queued',
     val('SELECT wa_send FROM voice_calls WHERE id = ?', [$cw3['id']]) === null);
set_setting('voice_wa_followup', '1');

$wh = file_get_contents(__DIR__ . '/../voice_webhook.php');
t_ok('an incoming hangup with no token is matched by the call id', strpos($wh, 'CallUUID') !== false);
t_ok('and that is when the follow-up goes out', strpos($wh, 'voice_in_wa_flush(') !== false);
t_ok('a cron sweep catches the ones that never got a hangup',
     strpos(file_get_contents(__DIR__ . '/../includes/cron_jobs.php'), 'cron_job_voice_followup') !== false);

t_group('Voice IN — "it said I owe nothing when I owe 300"');

// Chasing that from the outside is impossible: wrong customer matched, no
// customer matched, or a ledger that really does read zero all sound the
// same on the phone. This answers it without ringing anybody.
$pv = voice_in_preview('9876500077');
t_ok('the matched customer is named', $pv['party'] && (int)$pv['party']['id'] === $pidW);
t_eq('and the figure that would be read out is shown', round((float)$pv['due'], 2), 300.0);
t_ok('along with the exact words', strpos($pv['lines']['press_1'], 'ત્રણ સો') !== false, $pv['lines']['press_1']);

// a number that certainly belongs to nobody in this database
$absent = null;
for ($i = 0; $i < 40 && $absent === null; $i++) {
    $try = '9' . str_pad((string)random_int(0, 999999999), 9, '0', STR_PAD_LEFT);
    if (!wa_bot_party_for(voice_e164($try))) $absent = $try;
}
$pvNone = voice_in_preview($absent ?? '9111100001');
t_ok('an unmatched number is called out as unmatched', $pvNone['party'] === null);
t_ok('and shows what such a caller is told',
     strpos($pvNone['lines']['press_1'], 'નોંધાયેલો') !== false || stripos($pvNone['lines']['press_1'], 'not registered') !== false);

// two records for one person is the commonest cause of a wrong figure
$dupe = t_party('TEST_DUP_' . bin2hex(random_bytes(3)));
q("UPDATE parties SET mobile = '9876500077' WHERE id = ?", [$dupe]);
$pv2 = voice_in_preview('9876500077');
t_ok('every customer sharing that number is listed', count($pv2['others']) >= 2, count($pv2['others']) . ' records');
t_ok('with each one\'s own balance, so the wrong match is obvious',
     count(array_filter($pv2['others'], fn($o) => array_key_exists('due', $o))) === count($pv2['others']));

// A party whose mobile is shorter than ten digits matches any caller whose
// number ends with it, and that caller then hears somebody else's ledger.
q("INSERT INTO parties (name, type, mobile, opening_balance) VALUES ('TEST_SHORT_MOB', 'customer', '123', 0)");
$pvS = voice_in_preview('9876500077');
t_ok('a dangerously short stored number is reported',
     (bool)array_filter($pvS['short'] ?? [], fn($o) => $o['mobile'] === '123'));
t_ok('the screen warns about it',
     strpos(file_get_contents(__DIR__ . '/../voice_setup.php'), 'shorter than 10 digits') !== false);

t_group('Voice — the recent list does not call an incoming call a test');

// Every row was drawn as if it were an outgoing reminder, so a customer
// ringing the shop appeared as "test" owing "₹0.00" for nought seconds -
// three wrong facts about a call that worked perfectly.
$su6 = file_get_contents(__DIR__ . '/../voice_setup.php');
$tbl = substr($su6, strpos($su6, 'Last 20 calls'));
t_ok('the direction is shown', strpos($tbl, "=== 'in'") !== false);
t_ok('an incoming caller is not labelled a test', strpos($tbl, 'unknown caller') !== false);
t_ok('an incoming call shows what it was about, not an amount',
     strpos($tbl, 'voice_intent_label') !== false);
t_ok('and which keys were pressed', strpos($tbl, 'ivr_path') !== false);
t_ok('and whether the WhatsApp follow-up went', strpos($tbl, 'wa_sent_at') !== false);
t_ok('an outgoing call still shows the amount and the answer',
     strpos($tbl, "money(\$r['amount'])") !== false && strpos($tbl, "'yes'") !== false);
