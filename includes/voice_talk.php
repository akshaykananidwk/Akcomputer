<?php
// The reminder call, as a conversation.
//
// "જય દ્વારકાધીશ. નમસ્કાર રમેશભાઈ, કેમ છો? … સાહેબ, તમે ક્યારે પેમેન્ટ કરાવી
// આપશો?" - and then it listens, understands the answer, and replies.
//
// The division of labour is the whole design, and it is deliberate:
//
//   AI LISTENS. The recording goes to the model and comes back as a
//   classification - promised / already paid / no money / wrong person /
//   unclear, and a date if one was said. Nothing else.
//
//   ORDINARY CODE DECIDES. The date is checked here: it must parse, it must
//   not be in the past, and it must not be further out than the shop allows.
//   A model that "heard" the year 2047 gets the same answer as one that
//   heard nothing.
//
//   THE SHOP SPEAKS. Every word the customer hears is one of the shop's own
//   sentences, editable on the wording screen and spoken in the shop's own
//   voice, made in advance. The model never composes a line. A model that
//   improvises down a phone line is a model that one day promises a discount
//   nobody authorised, in a shop's own voice, to a customer who then holds
//   the shop to it.
//
//   THE LEDGER IS WRITTEN THE ORDINARY WAY. A promise goes in through
//   coll_log(), exactly as if a person had typed it, so every screen that
//   already reads promises sees it without being taught anything.
//
// The model is told the recording and today's date. Not the name, not the
// number, not the amount, not the history - none of it is needed to work out
// "આવતા મંગળવારે", and a customer's ledger has no business on somebody
// else's server.
//
// Nobody waits in silence. Every sentence the call can say is made before the
// phone rings; the only slow step is the listening, which runs on a short
// leash with the keypad question underneath it.
require_once __DIR__ . '/voice.php';
require_once __DIR__ . '/ai.php';

function voice_talk_on() {
    return (int)setting('voice_talk', 0) === 1 && voice_tts_enabled() && voice_ivr_on();
}
/**
 * Is a talking call safe to start in this language?
 *
 * Not just switched on: every sentence it could need must already be made.
 * A call that opens a conversation and then cannot say "I did not catch
 * that" is worse than one that never opened it - the customer is left
 * listening to nothing, in English, at the shop's expense.
 */
function voice_talk_ready($lang) {
    if (!voice_talk_on()) return false;
    if ($lang === 'en') return true;
    return voice_talk_voice_status($lang)['ready'];
}

function voice_talk_turns()    { return max(1, min(4, (int)setting('voice_talk_turns', 2))); }
function voice_talk_max_days() { return max(1, min(365, (int)setting('voice_talk_max_days', 30))); }
function voice_talk_secs()     { return max(3, min(20, (int)setting('voice_talk_secs', 8))); }

// ---------- what the call says ----------

/**
 * Every sentence a talking call can use, in the shop's own words.
 *
 * These go through the same editing and switching-off as the incoming menu -
 * voice_lines_edited() - so the owner writes how their shop speaks. Politeness
 * is not something a piece of software should be deciding on their behalf.
 *
 * Deliberately free of {slots} except the name: a sentence with a date in it
 * would be a different recording for every date, and a recording that has to
 * be made while somebody is on the line is a recording that is not there. The
 * day is spoken by one of the small fixed day clips below instead.
 */
function voice_talk_defaults($lang) {
    $t = [
        'gu' => [
            'talk_how'      => 'કેમ છો સાહેબ? મજામાં?',
            'talk_ask'      => 'સાહેબ, તમે આ પેમેન્ટ ક્યારે કરાવી આપશો? બીપ પછી જણાવો.',
            'talk_again'    => 'માફ કરશો, બરાબર સંભળાયું નહીં. ક્યારે કરાવી આપશો એ ફરીથી જણાવશો?',
            'talk_got'      => 'સારું સાહેબ, નોંધી લીધું —',
            'talk_thanks'   => 'ખૂબ ખૂબ આભાર. જય દ્વારકાધીશ.',
            'talk_paid'     => 'સારું સાહેબ, અમે ચેક કરી લઈશું. ગેરસમજ બદલ માફ કરશો. આભાર.',
            'talk_nomoney'  => 'સમજી શકું છું સાહેબ. અનુકૂળતાએ જણાવશો તો સારું. આભાર.',
            'talk_wrong'    => 'માફ કરશો, ખોટી જગ્યાએ ફોન લાગ્યો લાગે છે. તકલીફ બદલ દિલગીર છીએ.',
            'talk_giveup'   => 'કંઈ વાંધો નહીં સાહેબ. અમે ફરી સંપર્ક કરીશું. આભાર.',
        ],
        'hi' => [
            'talk_how'      => 'कैसे हैं आप? सब ठीक?',
            'talk_ask'      => 'साहब, आप यह भुगतान कब तक करा देंगे? बीप के बाद बताइए.',
            'talk_again'    => 'माफ़ कीजिए, ठीक से सुनाई नहीं दिया. कब तक करा देंगे, फिर से बताइएगा?',
            'talk_got'      => 'ठीक है साहब, दर्ज कर लिया —',
            'talk_thanks'   => 'बहुत बहुत धन्यवाद. जय द्वारकाधीश.',
            'talk_paid'     => 'ठीक है साहब, हम जाँच लेंगे. ग़लतफ़हमी के लिए क्षमा करें. धन्यवाद.',
            'talk_nomoney'  => 'समझ सकते हैं साहब. सुविधा हो तो बता दीजिएगा. धन्यवाद.',
            'talk_wrong'    => 'माफ़ कीजिए, ग़लत जगह फ़ोन लग गया लगता है. तकलीफ़ के लिए खेद है.',
            'talk_giveup'   => 'कोई बात नहीं साहब. हम दोबारा संपर्क करेंगे. धन्यवाद.',
        ],
        'en' => [
            'talk_how'      => 'How are you, sir? All well?',
            'talk_ask'      => 'Sir, when will you be able to make this payment? Please say after the beep.',
            'talk_again'    => 'Sorry, I did not catch that. Could you say again when you will pay?',
            'talk_got'      => 'Very good sir, I have noted it down —',
            'talk_thanks'   => 'Thank you very much.',
            'talk_paid'     => 'Very good sir, we will check our records. Apologies for the confusion. Thank you.',
            'talk_nomoney'  => 'I understand, sir. Do let us know when it suits you. Thank you.',
            'talk_wrong'    => 'I am sorry, it seems we have the wrong number. Apologies for the trouble.',
            'talk_giveup'   => 'That is quite all right, sir. We will get in touch again. Thank you.',
        ],
    ];
    return $t[$lang] ?? $t['en'];
}

/**
 * The days a promise can be repeated back in.
 *
 * A handful of fixed clips rather than a spoken date, because "on the
 * fourteenth of October" is a different recording for every date in the year
 * and would have to be made with the customer already on the line. These
 * cover how anybody actually answers: today, tomorrow, the day after, this
 * week, next week, this month.
 */
function voice_talk_day_words($lang) {
    $t = [
        'gu' => ['today' => 'આજે.', 'tomorrow' => 'કાલે.', 'day2' => 'પરમ દિવસે.',
                 'week' => 'આ અઠવાડિયે.', 'next_week' => 'આવતા અઠવાડિયે.', 'month' => 'આ મહિને.'],
        'hi' => ['today' => 'आज.', 'tomorrow' => 'कल.', 'day2' => 'परसों.',
                 'week' => 'इसी हफ़्ते.', 'next_week' => 'अगले हफ़्ते.', 'month' => 'इसी महीने.'],
        'en' => ['today' => 'Today.', 'tomorrow' => 'Tomorrow.', 'day2' => 'The day after tomorrow.',
                 'week' => 'This week.', 'next_week' => 'Next week.', 'month' => 'This month.'],
    ];
    return $t[$lang] ?? $t['en'];
}

/** Which day clip fits a date, counted from today. */
function voice_talk_day_key($date, $now = null) {
    $days = (int)floor((strtotime($date . ' 12:00') - strtotime(date('Y-m-d 12:00', $now ?: time()))) / 86400);
    if ($days <= 0) return 'today';
    if ($days === 1) return 'tomorrow';
    if ($days === 2) return 'day2';
    if ($days <= 7) return 'week';
    if ($days <= 14) return 'next_week';
    return 'month';
}

/** One sentence of a talking call, with this shop's edits on top. */
function voice_talk_line($key, $lang) {
    $d = voice_talk_defaults($lang);
    if (!isset($d[$key])) return '';
    $e = voice_lines_edited($lang);
    if (array_key_exists($key, $e)) return $e[$key] === null ? '' : trim((string)$e[$key]);
    return $d[$key];
}

/** Say it, from what was made in advance. Never generates: the customer is
 *  on the line. An empty line - one the shop switched off - says nothing. */
function voice_talk_say($key, $lang) {
    $line = voice_talk_line($key, $lang);
    if ($line === '') return '';
    $say = voice_say_live($line, $lang, voice_talk_defaults('en')[$key] ?? $line);
    return $say['url'] ? voice_xml_play($say['url']) : voice_xml_speak($say['text']);
}

function voice_talk_say_day($dayKey, $lang) {
    $w = voice_talk_day_words($lang);
    $line = $w[$dayKey] ?? '';
    if ($line === '') return '';
    $say = voice_say_live($line, $lang, voice_talk_day_words('en')[$dayKey] ?? $line);
    return $say['url'] ? voice_xml_play($say['url']) : voice_xml_speak($say['text']);
}

/** Every line a talking call can need, so they can all be made before the
 *  first customer is rung. */
function voice_talk_all_lines($lang) {
    $out = [];
    foreach (array_keys(voice_talk_defaults($lang)) as $k) {
        $l = voice_talk_line($k, $lang);
        if ($l !== '') $out[] = $l;
    }
    foreach (voice_talk_day_words($lang) as $l) $out[] = $l;
    return $out;
}

/** How ready the talking call is to speak this shop's language. */
function voice_talk_voice_status($lang) {
    if ($lang === 'en') return ['ready' => true, 'done' => 0, 'total' => 0];
    $lines = voice_talk_all_lines($lang);
    $done = 0;
    foreach ($lines as $l) if (voice_tts_audio($l, $lang, true)['ok']) $done++;
    return ['ready' => $done === count($lines), 'done' => $done, 'total' => count($lines)];
}

/** Make them all, once. Safe to run again - a cached line costs nothing. */
function voice_talk_pregenerate($lang) {
    $made = 0; $failed = 0; $err = '';
    foreach (voice_talk_all_lines($lang) as $l) {
        if (voice_tts_audio($l, $lang, true)['ok']) continue;
        $r = voice_tts_audio($l, $lang);
        if ($r['ok']) { $made++; continue; }
        $failed++; $err = $err ?: $r['error'];
        break;   // a cap or a missing key fails the same way for all the rest
    }
    return ['ok' => !$failed, 'made' => $made, 'failed' => $failed, 'error' => $err];
}

// ---------- the listening ----------

/** This month's listens, so a stuck call cannot run up a bill. */
function voice_talk_usage($bump = false) {
    $m = date('Y-m');
    if (setting('voice_talk_month') !== $m) { set_setting('voice_talk_month', $m); set_setting('voice_talk_count', '0'); }
    $used = (int)setting('voice_talk_count', 0);
    if ($bump) { $used++; set_setting('voice_talk_count', (string)$used); }
    return ['used' => $used, 'cap' => max(1, (int)setting('voice_talk_month_cap', 500))];
}

/**
 * What the customer said, as a classification.
 *
 * The model is given the recording and today's date, and nothing else. It
 * answers in a fixed shape; anything it says beyond that shape is discarded
 * here rather than carried further into the shop's data.
 *
 * Returns ['intent' => …, 'date' => 'YYYY-MM-DD'|'', 'said' => '…'] or null.
 */
function voice_talk_listen($audioBytes, $mime, $lang, $timeout = 7) {
    if ($audioBytes === '' || $audioBytes === null) return [null, 'nothing was recorded'];
    $u = voice_talk_usage();
    if ($u['used'] >= $u['cap']) return [null, "This month's listening limit ({$u['cap']}) is used up"];

    $langName = ['gu' => 'Gujarati', 'hi' => 'Hindi', 'en' => 'English'][$lang] ?? 'Gujarati';
    $today = today();
    $prompt = "This is a short phone recording of a shop's customer answering the question "
        . "\"when will you pay?\", in {$langName} (they may mix in Hindi or English).\n"
        . "Today's date is {$today}.\n\n"
        . "Return JSON only, exactly these keys:\n"
        . "  said   - what they said, transcribed, at most 200 characters\n"
        . "  intent - one of: promise, paid, no_money, wrong_person, refuse, unclear\n"
        . "           promise      = they said when they will pay\n"
        . "           paid         = they say they have already paid\n"
        . "           no_money     = they cannot pay now, no date given\n"
        . "           wrong_person = this is not the right person or number\n"
        . "           refuse       = they dispute the amount or refuse to pay\n"
        . "           unclear      = silence, noise, or you cannot tell\n"
        . "  date   - if intent is promise, the date they meant as YYYY-MM-DD, "
        . "counting from today's date above; otherwise an empty string\n\n"
        . "Work out relative days yourself: \"કાલે\"/\"कल\" is tomorrow, "
        . "\"આવતા સોમવારે\" is the coming Monday, \"અઠવાડિયામાં\" is seven days from today. "
        . "If they gave no date at all, intent is not promise. Do not guess a date to be helpful.";

    list($text, $err) = gemini_generate([
        ['text' => $prompt],
        ['inline_data' => ['mime_type' => $mime ?: 'audio/mpeg', 'data' => base64_encode($audioBytes)]],
    ], $timeout, true);
    if ($text === null) return [null, (string)$err];
    voice_talk_usage(true);

    $j = json_decode(trim((string)$text), true);
    if (!is_array($j)) return [null, 'the answer could not be read'];

    // Only the three fields, only the values we know. Whatever else the model
    // felt like returning stops here.
    $intent = (string)($j['intent'] ?? '');
    if (!in_array($intent, ['promise', 'paid', 'no_money', 'wrong_person', 'refuse', 'unclear'], true))
        $intent = 'unclear';
    $date = (string)($j['date'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = '';
    return [['intent' => $intent, 'date' => $date,
             'said' => mb_substr(trim((string)($j['said'] ?? '')), 0, 200)], null];
}

/**
 * What to do about it - ordinary code, no model involved.
 *
 * This is where a heard date becomes a date the shop will act on, or does
 * not. It must parse, it must not be in the past, and it must not be further
 * out than the shop allows. A model that heard 2047 is treated exactly like
 * one that heard nothing: the call asks again.
 *
 * Returns ['reply' => line key, 'day' => day key|'', 'date' => date|'',
 *          'again' => bool, 'response' => what to write on the call row].
 */
function voice_talk_decide(array $ai, $turn, $now = null) {
    $now = $now ?: time();
    $today = date('Y-m-d', $now);
    $intent = $ai['intent'] ?? 'unclear';

    if ($intent === 'promise') {
        $d = (string)($ai['date'] ?? '');
        // The SHAPE is checked here as well as at the door, not only there.
        // This function is what decides whether something becomes a promise
        // in the ledger, so it may not lean on its caller having cleaned the
        // model's answer first. Without this, "next tuesday" came back from
        // strtotime() as a perfectly valid timestamp, sailed past the range
        // check, and went into a DATE column as the literal words.
        $ok = preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1 && strtotime($d) !== false
              && date('Y-m-d', strtotime($d)) === $d;
        if ($ok && $d < $today) $d = $today;              // "today" said late in the day
        $far = $ok ? (int)floor((strtotime($d . ' 12:00') - strtotime($today . ' 12:00')) / 86400) : -1;
        if ($ok && $far <= voice_talk_max_days()) {
            return ['reply' => 'talk_got', 'day' => voice_talk_day_key($d, $now), 'date' => $d,
                    'again' => false, 'response' => 'promise'];
        }
        // A date nobody would mean. Ask again rather than write it down.
        $intent = 'unclear';
    }

    if ($intent === 'paid')         return ['reply' => 'talk_paid', 'day' => '', 'date' => '', 'again' => false, 'response' => 'paid'];
    if ($intent === 'no_money')     return ['reply' => 'talk_nomoney', 'day' => '', 'date' => '', 'again' => false, 'response' => 'no'];
    if ($intent === 'refuse')       return ['reply' => 'talk_nomoney', 'day' => '', 'date' => '', 'again' => false, 'response' => 'no'];
    if ($intent === 'wrong_person') return ['reply' => 'talk_wrong', 'day' => '', 'date' => '', 'again' => false, 'response' => 'wrong'];

    // Unclear: ask once more, then leave them in peace.
    if ($turn < voice_talk_turns())
        return ['reply' => 'talk_again', 'day' => '', 'date' => '', 'again' => true, 'response' => ''];
    return ['reply' => 'talk_giveup', 'day' => '', 'date' => '', 'again' => false, 'response' => 'none'];
}

/**
 * Write down what came of it.
 *
 * A promise goes into the ledger's own history through coll_log(), exactly
 * as a person typing it would - so the collection screen, the promise list
 * and the nightly kept-or-broken job all see it without being taught
 * anything about phone calls or models.
 */
function voice_talk_apply($call, array $ai, array $decision) {
    $id = (int)$call['id'];
    q("UPDATE voice_calls SET heard = ?, heard_intent = ?, promise_date = ?, talk_turns = talk_turns + 1,
              response = IF(? = '', response, ?), response_at = IF(? = '', response_at, NOW())
       WHERE id = ?",
      [mb_substr((string)($ai['said'] ?? ''), 0, 500), (string)($ai['intent'] ?? ''),
       $decision['date'] !== '' ? $decision['date'] : null,
       $decision['response'], $decision['response'], $decision['response'], $id]);

    $pid = (int)$call['party_id'];
    if ($pid <= 0) return;

    if ($decision['response'] === 'promise' && $decision['date'] !== '') {
        coll_log($pid, 'promise', ['amount' => (float)$call['amount'], 'due_date' => $decision['date'],
                                   'channel' => 'phone',
                                   'note' => 'Said on the phone: ' . mb_substr((string)($ai['said'] ?? ''), 0, 180)]);
        return;
    }
    if ($decision['response'] === '') return;   // still asking - nothing decided yet

    $note = ['paid' => 'Said on the phone that it is already paid',
             'no' => 'Said on the phone they cannot pay yet',
             'wrong' => 'Wrong number, they said',
             'none' => 'Answered but nothing could be made out'][$decision['response']] ?? 'Answered';
    coll_log($pid, 'call', ['channel' => 'phone', 'status' => 'done',
                            'note' => $note . ($ai['said'] ? ' — ' . mb_substr((string)$ai['said'], 0, 160) : '')]);
}

// ---------- the XML ----------

/** Ask the question and listen. $turn is which time of asking this is. */
function voice_talk_ask_xml($call, $turn = 1) {
    $lang = $call['lang'];
    $xml = '';
    $xml .= $turn === 1 ? voice_talk_say('talk_ask', $lang) : voice_talk_say('talk_again', $lang);
    $action = voice_public_url('voice_talk.php?t=' . $call['token'] . '&turn=' . (int)$turn);
    // redirect="true": the recording's URL is posted to us and whatever XML we
    // answer with carries the call on. finishOnKey lets somebody who is done
    // talking say so, and maxLength ends it for somebody who is not.
    $xml .= '  <Record action="' . htmlspecialchars($action, ENT_XML1) . '" method="POST"'
          . ' maxLength="' . voice_talk_secs() . '" timeout="4" finishOnKey="#"'
          . ' fileFormat="mp3" redirect="true" playBeep="true"/>' . "\n";
    return $xml;
}

/**
 * The reply, once we know what they said.
 *
 * $turn is which ask this was answering, and the next ask must be $turn + 1.
 * It is passed in rather than read off the call row: the row was loaded
 * before this turn was written to it, so its count is one behind. Asking
 * again with the SAME turn number meant the next answer was judged as if it
 * were the first - "unclear" would have asked again, and again, for as long
 * as the customer stayed on the line.
 */
function voice_talk_reply_xml($call, array $decision, $turn = 1) {
    $lang = $call['lang'];
    $xml = voice_talk_say($decision['reply'], $lang);
    if ($decision['day'] !== '') {
        $xml .= voice_talk_say_day($decision['day'], $lang);
        $xml .= voice_talk_say('talk_thanks', $lang);
    }
    if ($decision['again']) $xml .= voice_talk_ask_xml($call, max(1, (int)$turn) + 1);
    return $xml;
}
