<?php
// Payment reminder CALLS - every rule in one place.
//
// A ringing phone is the most intrusive thing this software does to a
// customer, so the rules about when it may ring live here and nowhere else.
// Every screen that offers a "Remind Now" button calls voice_can_call() and
// voice_call_send(); neither one is allowed to decide anything for itself.
//
// About the language. Vobiz's text-to-speech supports 16 languages - Danish,
// Dutch, English, French, German, Italian, Polish, Portuguese, Russian,
// Spanish, Swedish - and NOT Gujarati, and not Hindi either. Checked against
// their own Speak documentation. So a Gujarati call cannot be typed; it has
// to be assembled from audio that already exists:
//
//     [નમસ્કાર] [<name>] [AK Computer તરફથી...] [બાર][હજાર][ચાર][સો]
//     [રૂપિયા] [...ચૂકવણી બાકી છે...] [આભાર]
//
// which is how a bank's balance line has always worked. The clips are small
// mp3 files under uploads/voice/<lang>/, recorded once in the shop's own
// voice. Until they exist the call still goes out, in English, using the
// provider's own TTS - so the feature works on day one and gets better when
// the recordings are made, instead of waiting for them.
//
// The amount is never approximated. It is decomposed into exact tokens and
// spoken piece by piece, and the whole sentence is written into the call row
// so a disagreement later is settled by a record rather than a memory.

require_once __DIR__ . '/customer.php';   // coll_can_remind(), coll_log()

const VOICE_TOKEN_TTL_HOURS = 6;          // an answer URL stops working after this

function voice_langs() {
    return ['gu' => 'ગુજરાતી', 'hi' => 'हिन्दी', 'en' => 'English'];
}

/** Number words 0-99 plus the scale words, per language.
 *
 *  These are LABELS. They are what the recording checklist prints and what
 *  the script preview shows; the audio that actually plays is chosen by the
 *  token (n47), not by this text. A typo here is a typo on a checklist, not
 *  a wrong amount spoken down the phone.
 *
 *  Gujarati and Hindi both need all hundred: 47 is "સુડતાલીસ", not "forty"
 *  followed by "seven", so there is nothing to compose them from. */
function voice_words($lang) {
    static $w = null;
    if ($w === null) {
        $w = [];
        $w['gu'] = array_merge(
            explode(' ', 'શૂન્ય એક બે ત્રણ ચાર પાંચ છ સાત આઠ નવ'),
            explode(' ', 'દસ અગિયાર બાર તેર ચૌદ પંદર સોળ સત્તર અઢાર ઓગણીસ'),
            explode(' ', 'વીસ એકવીસ બાવીસ તેવીસ ચોવીસ પચીસ છવીસ સત્તાવીસ અઠ્ઠાવીસ ઓગણત્રીસ'),
            explode(' ', 'ત્રીસ એકત્રીસ બત્રીસ તેત્રીસ ચોત્રીસ પાંત્રીસ છત્રીસ સાડત્રીસ આડત્રીસ ઓગણચાલીસ'),
            explode(' ', 'ચાલીસ એકતાલીસ બેતાલીસ ત્રેતાલીસ ચુંમાલીસ પિસ્તાલીસ છેતાલીસ સુડતાલીસ અડતાલીસ ઓગણપચાસ'),
            explode(' ', 'પચાસ એકાવન બાવન ત્રેપન ચોપન પંચાવન છપ્પન સત્તાવન અઠ્ઠાવન ઓગણસાઠ'),
            explode(' ', 'સાઠ એકસઠ બાસઠ ત્રેસઠ ચોસઠ પાંસઠ છાસઠ સડસઠ અડસઠ ઓગણસિત્તેર'),
            explode(' ', 'સિત્તેર એકોતેર બોતેર તોતેર ચુમોતેર પંચોતેર છોતેર સિત્યોતેર ઇઠ્યોતેર ઓગણાએંસી'),
            explode(' ', 'એંસી એક્યાસી બ્યાસી ત્યાસી ચોર્યાસી પંચાસી છ્યાસી સિત્યાસી ઈઠ્યાસી નેવ્યાસી'),
            explode(' ', 'નેવું એકાણું બાણું ત્રાણું ચોરાણું પંચાણું છન્નું સત્તાણું અઠ્ઠાણું નવ્વાણું')
        );
        $w['hi'] = array_merge(
            explode(' ', 'शून्य एक दो तीन चार पाँच छह सात आठ नौ'),
            explode(' ', 'दस ग्यारह बारह तेरह चौदह पंद्रह सोलह सत्रह अठारह उन्नीस'),
            explode(' ', 'बीस इक्कीस बाईस तेईस चौबीस पच्चीस छब्बीस सत्ताईस अट्ठाईस उनतीस'),
            explode(' ', 'तीस इकतीस बत्तीस तैंतीस चौंतीस पैंतीस छत्तीस सैंतीस अड़तीस उनतालीस'),
            explode(' ', 'चालीस इकतालीस बयालीस तैंतालीस चवालीस पैंतालीस छियालीस सैंतालीस अड़तालीस उनचास'),
            explode(' ', 'पचास इक्यावन बावन तिरपन चौवन पचपन छप्पन सत्तावन अट्ठावन उनसठ'),
            explode(' ', 'साठ इकसठ बासठ तिरसठ चौंसठ पैंसठ छियासठ सड़सठ अड़सठ उनहत्तर'),
            explode(' ', 'सत्तर इकहत्तर बहत्तर तिहत्तर चौहत्तर पचहत्तर छिहत्तर सतहत्तर अठहत्तर उन्यासी'),
            explode(' ', 'अस्सी इक्यासी बयासी तिरासी चौरासी पचासी छियासी सत्तासी अट्ठासी नवासी'),
            explode(' ', 'नब्बे इक्यानवे बानवे तिरानवे चौरानवे पंचानवे छियानवे सत्तानवे अट्ठानवे निन्यानवे')
        );
        $w['en'] = array_merge(
            explode(' ', 'zero one two three four five six seven eight nine'),
            explode(' ', 'ten eleven twelve thirteen fourteen fifteen sixteen seventeen eighteen nineteen'),
            explode(' ', 'twenty twenty-one twenty-two twenty-three twenty-four twenty-five twenty-six twenty-seven twenty-eight twenty-nine'),
            explode(' ', 'thirty thirty-one thirty-two thirty-three thirty-four thirty-five thirty-six thirty-seven thirty-eight thirty-nine'),
            explode(' ', 'forty forty-one forty-two forty-three forty-four forty-five forty-six forty-seven forty-eight forty-nine'),
            explode(' ', 'fifty fifty-one fifty-two fifty-three fifty-four fifty-five fifty-six fifty-seven fifty-eight fifty-nine'),
            explode(' ', 'sixty sixty-one sixty-two sixty-three sixty-four sixty-five sixty-six sixty-seven sixty-eight sixty-nine'),
            explode(' ', 'seventy seventy-one seventy-two seventy-three seventy-four seventy-five seventy-six seventy-seven seventy-eight seventy-nine'),
            explode(' ', 'eighty eighty-one eighty-two eighty-three eighty-four eighty-five eighty-six eighty-seven eighty-eight eighty-nine'),
            explode(' ', 'ninety ninety-one ninety-two ninety-three ninety-four ninety-five ninety-six ninety-seven ninety-eight ninety-nine')
        );
    }
    return $w[$lang] ?? $w['en'];
}

/** The fixed words - scales, and the four sentence pieces. */
function voice_phrases($lang) {
    $p = [
        'gu' => [
            'hundred' => 'સો', 'thousand' => 'હજાર', 'lakh' => 'લાખ', 'crore' => 'કરોડ',
            'rupees' => 'રૂપિયા', 'paise' => 'પૈસા',
            'greet' => 'નમસ્કાર', 'from_shop' => 'તરફથી બોલું છું', 'your' => 'તમારા',
            'due' => 'ની ચુકવણી બાકી છે', 'request' => 'કૃપા કરીને જલ્દી ચૂકવણી કરવા વિનંતી',
            'thanks' => 'આભાર',
        ],
        'hi' => [
            'hundred' => 'सौ', 'thousand' => 'हज़ार', 'lakh' => 'लाख', 'crore' => 'करोड़',
            'rupees' => 'रुपये', 'paise' => 'पैसे',
            'greet' => 'नमस्ते', 'from_shop' => 'से बोल रहे हैं', 'your' => 'आपका',
            'due' => 'का भुगतान बाकी है', 'request' => 'कृपया जल्दी भुगतान करें',
            'thanks' => 'धन्यवाद',
        ],
        'en' => [
            'hundred' => 'hundred', 'thousand' => 'thousand', 'lakh' => 'lakh', 'crore' => 'crore',
            'rupees' => 'rupees', 'paise' => 'paise',
            'greet' => 'Hello', 'from_shop' => 'calling from', 'your' => 'your',
            'due' => 'payment is pending', 'request' => 'Please pay at your earliest convenience',
            'thanks' => 'Thank you',
        ],
    ];
    return $p[$lang] ?? $p['en'];
}

/**
 * The shop's own greeting, said before anything the software has to say.
 *
 * "જય દ્વારકાધીશ" is not a feature, it is how this shop answers its phone,
 * and it belongs to the shop - so it is an ordinary editable line. Clearing
 * it switches it off. It lives here rather than in the incoming menu's table
 * because a reminder going OUT opens with it too, and one greeting that both
 * directions read beats two that drift apart.
 */
function voice_greeting_default($lang) {
    $d = ['gu' => 'જય દ્વારકાધીશ.', 'hi' => 'जय द्वारकाधीश.', 'en' => 'Jay Dwarkadhish.'];
    return $d[$lang] ?? $d['en'];
}
function voice_greeting($lang) {
    $edited = voice_lines_edited($lang);
    if (!array_key_exists('hello', $edited)) return voice_greeting_default($lang);
    return $edited['hello'] === null ? '' : trim((string)$edited['hello']);
}

/** What this shop has rewritten, for one language. Bad JSON is no wording. */
function voice_lines_edited($lang) {
    $raw = (string)setting('voice_lines_' . $lang, '');
    if (trim($raw) === '') return [];
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}

/**
 * Save this shop's wording.
 *
 * Only keys the software actually speaks are kept, and a line left the same
 * as the default is DROPPED rather than frozen - so a later improvement to
 * that sentence still reaches this shop, and clearing a box restores it.
 */
function voice_lines_save($lang, array $lines, array $off = []) {
    $defaults = voice_in_defaults($lang);
    $keep = []; $refused = [];
    // Switched off is not the same as left blank. A blank box means "go back
    // to the wording the software ships with"; switching a line off means
    // "say nothing here", and that has to survive as a decision of its own or
    // the next save would quietly turn the line back on.
    foreach ($off as $k) if (isset($defaults[$k])) $keep[$k] = null;
    foreach ($lines as $k => $text) {
        $text = trim((string)$text);
        if (!isset($defaults[$k]) || array_key_exists($k, $keep)) continue;
        if ($text === '' || $text === $defaults[$k]) continue;
        // A sentence that carries a figure must keep the slot the figure
        // goes in. Drop {amount} and the call says "your is outstanding" -
        // the one wording mistake that turns a reminder into nonsense and
        // that nobody would notice until a customer heard it.
        $bad = false;
        foreach (voice_line_slots($defaults[$k]) as $slot)
            if (strpos($text, '{' . $slot . '}') === false) { $bad = true; break; }
        if ($bad) { $refused[] = $k; continue; }
        $keep[$k] = mb_substr($text, 0, 600);
    }
    set_setting('voice_lines_' . $lang, $keep ? json_encode($keep, JSON_UNESCAPED_UNICODE) : '');
    return ['saved' => count($keep), 'refused' => $refused];
}

/** The {slots} a sentence carries - the customer's name, their amount. */
function voice_line_slots($text) {
    preg_match_all('/\{(\w+)\}/', (string)$text, $m);
    return array_values(array_unique($m[1]));
}

/** Is this line switched off - said by nobody, rather than merely unedited? */
function voice_line_off($lang, $key) {
    $e = voice_lines_edited($lang);
    return array_key_exists($key, $e) && $e[$key] === null;
}

/** An amount as an exact, language-free list of tokens.
 *
 *  Indian grouping - crore, lakh, thousand, hundred, then what is left, which
 *  is why the 0-99 table has to be complete. Paise are spoken only when they
 *  exist; they are never rounded away, because the amount said on the phone
 *  has to be the amount in the ledger.
 *
 *  ₹12,400.00 -> n12 thousand n4 hundred rupees
 *  ₹1,05,250.50 -> n1 lakh n5 thousand n2 hundred n50 rupees n50 paise */
function voice_amount_tokens($amount) {
    $amount = round((float)$amount, 2);
    if ($amount < 0) $amount = 0.0;
    $rupees = (int)floor($amount + 0.0000001);
    $paise = (int)round(($amount - $rupees) * 100);
    if ($paise >= 100) { $rupees++; $paise = 0; }   // 0.999 -> 1.00, never 1.100

    $out = array_merge(voice_number_tokens($rupees), ['rupees']);
    if ($paise > 0) $out = array_merge($out, voice_number_tokens($paise), ['paise']);
    return $out;
}

/** A whole number 0..99,99,99,999 as tokens. */
function voice_number_tokens($n) {
    $n = (int)$n;
    if ($n <= 0) return ['n0'];
    $out = [];
    foreach ([['crore', 10000000], ['lakh', 100000], ['thousand', 1000], ['hundred', 100]] as [$word, $unit]) {
        $part = intdiv($n, $unit);
        if ($part > 0) {
            // the count itself can run past 99 only for crores, and a shop
            // ledger will not; recursing keeps it correct anyway.
            $out = array_merge($out, $part > 99 ? voice_number_tokens($part) : ['n' . $part], [$word]);
            $n -= $part * $unit;
        }
    }
    if ($n > 0) $out[] = 'n' . $n;
    return $out;
}

/** Tokens back into readable text - the script preview, and what English TTS
 *  is handed when the clips are not recorded yet. */
function voice_tokens_text(array $tokens, $lang) {
    $words = voice_words($lang);
    $ph = voice_phrases($lang);
    $out = [];
    foreach ($tokens as $t) {
        if (strncmp($t, 'n', 1) === 0 && ctype_digit(substr($t, 1))) {
            $i = (int)substr($t, 1);
            $out[] = $words[$i] ?? (string)$i;
        } elseif (isset($ph[$t])) {
            $out[] = $ph[$t];
        }
    }
    return implode(' ', $out);
}

/** The full sentence, exactly as the customer will hear it. */
function voice_script($name, $amount, $lang) {
    $ph = voice_phrases($lang);
    // The shop's greeting opens the call. Empty means the shop cleared it.
    $hello = trim(voice_greeting($lang));
    $hello = $hello === '' ? '' : rtrim($hello, '.।') . '. ';
    $shop = setting('app_name', 'AK Computer');
    $amt = voice_tokens_text(voice_amount_tokens($amount), $lang);
    $name = trim((string)$name);

    if ($lang === 'en') {
        return trim($hello . $ph['greet'] . ($name !== '' ? ' ' . $name : '') . ', ' . $ph['from_shop'] . ' ' . $shop
                    . '. Your payment of ' . $amt . ' is pending. ' . $ph['request'] . '. ' . $ph['thanks'] . '.');
    }
    // Gujarati/Hindi read in the same order the clips are played in.
    return trim($hello . $ph['greet'] . ($name !== '' ? ' ' . $name : '') . ', ' . $shop . ' ' . $ph['from_shop']
                . '. ' . $ph['your'] . ' ' . $amt . ' ' . $ph['due'] . '. ' . $ph['request'] . '. ' . $ph['thanks'] . '.');
}

/** The question the call ends on, and what is said to each answer.
 *
 *  Two versions of the question, because the shop is in two different
 *  conversations. A customer who has promised a date gets reminded of their
 *  own words; everybody else just gets asked. Anything else would either
 *  accuse somebody of breaking a promise they never made, or let somebody
 *  who did make one off the hook. */
function voice_question($lang, $promiseDate = null) {
    $on = $promiseDate ? dmy($promiseDate) : '';
    $q = [
        'gu' => [
            'plain'   => 'શું તમે આજે ચૂકવણી કરી દેશો? હા માટે એક દબાવો, ના માટે બે દબાવો.',
            'promise' => 'તમે ' . $on . ' ના રોજ ચૂકવવાનું કહ્યું હતું. શું તમે આજે ચૂકવણી કરી દેશો? હા માટે એક દબાવો, ના માટે બે દબાવો.',
            'yes'     => 'આભાર. અમે આજની રાહ જોઈશું.',
            'no'      => 'ઠીક છે, જણાવવા બદલ આભાર. અમે ફરી સંપર્ક કરીશું.',
            'none'    => 'આભાર.',
        ],
        'hi' => [
            'plain'   => 'क्या आप आज भुगतान कर देंगे? हाँ के लिए एक दबाएँ, ना के लिए दो दबाएँ.',
            'promise' => 'आपने ' . $on . ' को भुगतान करने को कहा था. क्या आप आज भुगतान कर देंगे? हाँ के लिए एक दबाएँ, ना के लिए दो दबाएँ.',
            'yes'     => 'धन्यवाद. हम आज का इंतज़ार करेंगे.',
            'no'      => 'ठीक है, बताने के लिए धन्यवाद. हम दोबारा संपर्क करेंगे.',
            'none'    => 'धन्यवाद.',
        ],
        'en' => [
            'plain'   => 'Will you be paying today? Press one for yes, press two for no.',
            'promise' => 'You had told us you would pay on ' . $on . '. Will you be paying today? Press one for yes, press two for no.',
            'yes'     => 'Thank you. We will expect it today.',
            'no'      => 'That is alright, thank you for telling us. We will be in touch again.',
            'none'    => 'Thank you.',
        ],
    ];
    return $q[$lang] ?? $q['en'];
}

function voice_ivr_on() { return (int)setting('voice_ivr', 1) === 1; }

/** A URL the phone network can actually fetch.
 *
 *  Vobiz requires audio over HTTPS and skips any file it cannot fetch -
 *  silently, continuing to the next element. A shop running the site on
 *  plain http would therefore hear a call with every clip missing and no
 *  error anywhere: a ring, a silence, a hangup. Forcing the scheme here
 *  costs nothing when the site is already https, and voice_public_ok()
 *  puts the warning on the setup screen for the case where it is not. */
function voice_public_url($path) {
    return preg_replace('#^http://#i', 'https://', base_url($path));
}
function voice_public_ok() {
    return strncasecmp(base_url(''), 'https://', 8) === 0;
}

/** One thing the call has to say, and how it will be said.
 *
 *  Returns ['url' => '...'] to play a file, or ['text' => '...'] for the
 *  provider to speak in English. Every spoken moment in a call - the
 *  reminder, the question, the reply to what was pressed - goes through
 *  here, so the fallback is the same everywhere: if the Gujarati cannot be
 *  made, that line is said in English rather than skipped. A call that
 *  drops a sentence is worse than one that changes language for it. */
function voice_say($text, $lang, $englishText = null, $cachedOnly = false) {
    if ($lang !== 'en') {
        $tts = voice_tts_audio($text, $lang, $cachedOnly);
        if ($tts['ok']) return ['url' => $tts['url'], 'text' => '', 'lang' => $lang, 'error' => ''];
        return ['url' => '', 'text' => $englishText ?? $text, 'lang' => 'en', 'error' => $tts['error']];
    }
    return ['url' => '', 'text' => $text, 'lang' => 'en', 'error' => ''];
}

/** The same thing, for code running while somebody is on the line.
 *
 *  Generating speech takes seconds and is allowed up to forty-five. The
 *  answer URL has far less than that before the network gives up, so a call
 *  that had to make a new sentence mid-conversation was a call that rang,
 *  went quiet, and died - and it only happened for sentences nobody had
 *  spoken before, which is exactly the case nobody tests.
 *
 *  On the live path we play what is already on disk, and say the rest in
 *  English. The shop hears an English line instead of losing the call. */
function voice_say_live($text, $lang, $englishText = null) {
    return voice_say($text, $lang, $englishText, true);
}

/** For the one line per call that cannot be prepared in advance - the
 *  caller's own balance, their own delivery status. It may generate, but on
 *  a short leash: eight seconds, then English. A caller waiting in silence
 *  hangs up long before a forty-five second timeout would notice. */
function voice_say_now($text, $lang, $englishText = null) {
    if ($lang === 'en') return ['url' => '', 'text' => $text, 'lang' => 'en', 'error' => ''];
    $tts = voice_tts_audio($text, $lang, false, 8);
    if ($tts['ok']) return ['url' => $tts['url'], 'text' => '', 'lang' => $lang, 'error' => ''];
    return ['url' => '', 'text' => $englishText ?? $text, 'lang' => 'en', 'error' => $tts['error']];
}

/** Everything one call will say, prepared before it is dialled.
 *
 *  Built here and not inside the answer URL because the answer URL has
 *  seconds to reply and generating speech takes longer than that. By the
 *  time the customer's phone rings, every file already exists.
 *
 *  The three replies are prepared too, even though at most one will be
 *  used: they are fixed sentences, so they are generated once in the life
 *  of the shop and then cost nothing, and preparing them here means the
 *  moment after the customer presses a key is a file that already exists
 *  rather than a pause on the line. */
function voice_audio_plan($partyName, $amount, $lang, $promiseDate = null, $cachedOnly = false) {
    $q = voice_question($lang, $promiseDate);
    $qEn = voice_question('en', $promiseDate);
    $key = $promiseDate ? 'promise' : 'plain';

    $main = voice_say(voice_script($partyName, $amount, $lang), $lang, voice_script($partyName, $amount, 'en'), $cachedOnly);
    $plan = ['lang' => $main['lang'], 'main' => $main, 'error' => $main['error'],
             'fell_back' => $lang !== 'en' && $main['lang'] === 'en'];

    if (voice_ivr_on()) {
        $plan['question'] = voice_say($q[$key], $lang, $qEn[$key], $cachedOnly);
        foreach (['yes', 'no', 'none'] as $r) $plan['replies'][$r] = voice_say($q[$r], $lang, $qEn[$r], $cachedOnly);
        if ($plan['question']['error'] && !$plan['error']) $plan['error'] = $plan['question']['error'];
    }
    return $plan;
}

// ---------- the spoken audio, generated ----------
//
// Vobiz has no Gujarati and no Hindi voice, and recording a hundred and
// twelve clips by hand is a chore that will never get done. So the sentence
// is spoken by a text-to-speech model that does have those languages, saved
// as a file, and played down the phone as ordinary audio - which is all the
// provider ever needs to know.
//
// Four rules, because this is an outside AI service and the shop's own rules
// about those apply:
//
//   * It runs BEFORE the call is dialled, never while the customer's phone
//     is connecting. The answer URL has seconds to reply; generating speech
//     inside it would be a call that rings and then dies in silence.
//   * The same sentence is generated once. The file is named after a hash of
//     the words, so the second customer who owes twelve thousand four hundred
//     costs nothing, and the question at the end - which never changes - is
//     generated once in the life of the shop.
//   * Every generation is counted against a monthly cap. Past it, calls keep
//     going out in English rather than running up a bill nobody approved.
//   * If it fails for any reason, the call still happens. English, spoken by
//     the provider, is always there underneath.
//
// The amount in the sentence comes from the ledger and is written by
// voice_script(). The model is given words to pronounce, never a number to
// interpret and never any say over what the number is.

function voice_tts_enabled() { return (int)setting('voice_tts', 1) === 1 && setting('gemini_api_key', '') !== ''; }
function voice_tts_dir() { return up_dir('voice/tts'); }

/** How many sentences were generated this month, and the cap. */
function voice_tts_usage() {
    $month = date('Y-m');
    $n = setting('voice_tts_month', '') === $month ? (int)setting('voice_tts_count', 0) : 0;
    return ['month' => $month, 'used' => $n, 'cap' => max(1, (int)setting('voice_tts_month_cap', 2000))];
}
function voice_tts_count_up() {
    $u = voice_tts_usage();
    set_setting('voice_tts_month', $u['month']);
    set_setting('voice_tts_count', (string)($u['used'] + 1));
}

/** The file one sentence lives in. Same words, same language, same file. */
function voice_tts_file($text, $lang) {
    return 'tts_' . $lang . '_' . substr(hash('sha256', $lang . '|' . setting('voice_tts_voice', 'Kore') . '|' . $text), 0, 24) . '.wav';
}

/** Speak this sentence, or say why not.
 *
 *  Returns ['ok'=>bool, 'file'=>'tts_gu_ab12.wav', 'url'=>..., 'error'=>..., 'cached'=>bool].
 *  A cached hit never touches the network and never counts against the cap. */
function voice_tts_audio($text, $lang, $cachedOnly = false, $timeout = 45) {
    $text = trim((string)$text);
    if ($text === '') return ['ok' => false, 'file' => '', 'url' => '', 'error' => 'nothing to say', 'cached' => false];

    $name = voice_tts_file($text, $lang);
    $dir = voice_tts_dir();
    if (is_file($dir . '/' . $name) && filesize($dir . '/' . $name) > 1000) {
        return ['ok' => true, 'file' => $name, 'url' => voice_public_url(up_rel('voice/tts') . '/' . $name),
                'error' => '', 'cached' => true];
    }

    // Called from a live call: play what exists, never wait to make more.
    if ($cachedOnly) return ['ok' => false, 'file' => '', 'url' => '', 'error' => 'not generated yet', 'cached' => false];
    if (!voice_tts_enabled()) return ['ok' => false, 'file' => '', 'url' => '', 'error' => 'Generated voice is off, or the Gemini key is missing', 'cached' => false];
    $u = voice_tts_usage();
    if ($u['used'] >= $u['cap'])
        return ['ok' => false, 'file' => '', 'url' => '', 'error' => "This month's voice limit ({$u['cap']}) is used up", 'cached' => false];
    if (!function_exists('curl_init')) return ['ok' => false, 'file' => '', 'url' => '', 'error' => 'The server does not have curl.', 'cached' => false];

    [$wav, $err] = voice_tts_fetch($text, $timeout);
    if ($wav === null) return ['ok' => false, 'file' => '', 'url' => '', 'error' => $err, 'cached' => false];

    if (!is_dir($dir)) mkdir($dir, 0755, true);
    if (file_put_contents($dir . '/' . $name, $wav) === false)
        return ['ok' => false, 'file' => '', 'url' => '', 'error' => 'Could not save the audio file', 'cached' => false];

    voice_tts_count_up();
    log_activity('voice_tts', $lang . ' ' . strlen($wav) . ' bytes · ' . mb_substr($text, 0, 80));
    return ['ok' => true, 'file' => $name, 'url' => voice_public_url(up_rel('voice/tts') . '/' . $name),
            'error' => '', 'cached' => false];
}

/** The HTTP call to Gemini TTS. Returns [wav bytes, ''] or [null, 'why not'].
 *
 *  The model detects the language from the words themselves, so Gujarati text
 *  comes back in a Gujarati voice with nothing else to configure. A unary
 *  request returns audio/wav - 24kHz mono 16-bit with a RIFF header - which
 *  is a format Vobiz plays directly, so nothing has to be converted here. */
function voice_tts_fetch($text, $timeout = 45) {
    $key = setting('gemini_api_key', '');
    if ($key === '') return [null, 'No Gemini API key (Settings → AI).'];

    $body = [
        'model' => setting('voice_tts_model', 'gemini-3.8-flash-tts'),
        'input' => [[
            'type' => 'user_input',
            'content' => [[
                'type' => 'text',
                'text' => $text,
                // said plainly and warmly: this is a shop asking for its
                // money, not an advertisement and not a threat
                'annotations' => [['type' => 'speech_metadata', 'style' => 'calm, polite and clear']],
            ]],
        ]],
        'response_format' => ['type' => 'audio', 'mime_type' => 'audio/wav', 'sample_rate' => 24000],
        'generation_config' => ['speech_config' => [['voice' => setting('voice_tts_voice', 'Kore')]]],
    ];

    $ch = curl_init('https://generativelanguage.googleapis.com/v1beta/interactions');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-goog-api-key: ' . $key],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => max(5, (int)$timeout),
    ]);
    $res = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);
    if ($res === false) return [null, 'Could not reach the voice service: ' . $cerr];

    $j = json_decode($res, true);
    if ($code < 200 || $code >= 300) {
        $msg = $j['error']['message'] ?? mb_substr((string)$res, 0, 200);
        return [null, 'Voice service returned HTTP ' . $code . ': ' . $msg];
    }
    $b64 = voice_tts_find_audio($j);
    if ($b64 === '') return [null, 'The voice service sent no audio back.'];
    $wav = base64_decode($b64, true);
    if ($wav === false || strlen($wav) < 1000) return [null, 'The audio that came back was empty or unreadable.'];
    return [$wav, ''];
}

/** Dig the base64 audio out of the reply.
 *
 *  Walked rather than indexed on purpose. This is the one part of the whole
 *  feature that depends on somebody else's JSON shape, and that shape is
 *  versioned, renamed and reorganised by people who do not know this shop
 *  exists. A walk keeps working when a field moves one level; a hard-coded
 *  path turns a working Gujarati call into a silent English one. */
function voice_tts_find_audio($node, $depth = 0) {
    if ($depth > 8 || !is_array($node)) return '';
    // a long base64 string sitting under a data/audio-ish key is the payload
    foreach (['data', 'audio', 'audio_data', 'inline_data', 'inlineData', 'bytes'] as $k) {
        if (isset($node[$k]) && is_string($node[$k]) && strlen($node[$k]) > 1000) return $node[$k];
    }
    foreach ($node as $v) {
        if (is_array($v)) { $found = voice_tts_find_audio($v, $depth + 1); if ($found !== '') return $found; }
        elseif (is_string($v) && strlen($v) > 5000 && preg_match('#^[A-Za-z0-9+/=\r\n]+$#', substr($v, 0, 200))) return $v;
    }
    return '';
}

// ---------- configuration ----------

function voice_configured() {
    return setting('vobiz_auth_id', '') !== ''
        && setting('vobiz_auth_token', '') !== ''
        && setting('vobiz_caller_id', '') !== '';
}
function voice_test_mode() { return (int)setting('voice_test_mode', 1) === 1; }
function voice_enabled()   { return (int)setting('voice_enabled', 0) === 1; }

/** Calling hours, exactly as the shop set them.
 *
 *  These used to be clamped to 9-21 and could not be widened. That is TRAI's
 *  window for commercial calls and it is still the default, but clamping it
 *  in code was the wrong place to enforce it: it also blocked the owner from
 *  testing their own system in the evening, which is when a shopkeeper has
 *  time to sit down with it. The window is the owner's to set; the setup
 *  screen says plainly what the legal one is and warns when the setting is
 *  outside it, so the choice is informed rather than prevented.
 *
 *  0 to 24 means any hour. A window that makes no sense (to <= from) falls
 *  back to the legal one rather than blocking every call or allowing all of
 *  them - a typo should not silently change what the shop does at night. */
function voice_hours($now = null) {
    $from = max(0, min(24, (int)setting('voice_hour_from', 9)));
    $to   = max(0, min(24, (int)setting('voice_hour_to', 21)));
    if ($to <= $from) { $from = 9; $to = 21; }
    return ['from' => $from, 'to' => $to, 'all_day' => $from === 0 && $to === 24,
            'legal' => $from >= 9 && $to <= 21];
}
function voice_hours_ok($now = null) {
    $h = voice_hours();
    $hour = (int)date('G', $now ?: time());
    return $hour >= $h['from'] && $hour < $h['to'];
}

// ---------- may we ring this person? ----------

/** The queue row shape coll_can_remind() expects, for one party.
 *  Built from the same tables coll_queue() reads, so a call is held back by
 *  exactly the things that hold a WhatsApp reminder back - an opt-out, an
 *  open promise, a snooze, the cooldown, the monthly cap. */
function voice_party_context($partyId) {
    return voice_contexts([(int)$partyId])[(int)$partyId] ?? null;
}

/** The same thing for a whole screenful, in three queries instead of three
 *  per row. The collection list asks "may I call this one?" for every row it
 *  draws, and doing that one party at a time turned a page render into a
 *  hundred queries. The context carries its own last call, so voice_can_call()
 *  does not go back to the database either. */
function voice_contexts(array $ids) {
    $ids = array_values(array_unique(array_map('intval', $ids)));
    if (!$ids) return [];
    $in = implode(',', $ids);
    $t = today();

    $ev = coll_event_map($ids);
    $last = [];
    foreach (all("SELECT v.* FROM voice_calls v
                  JOIN (SELECT party_id, MAX(id) mx FROM voice_calls WHERE party_id IN ($in) GROUP BY party_id) m
                    ON m.mx = v.id") as $r) $last[(int)$r['party_id']] = $r;

    $out = [];
    // Both sides are selected because the amount a call may ask for is the
    // SMALLER of the two - see money_chase_due(). Reading the sale side alone
    // rang a party whose two sides cancelled out and asked them for money the
    // shop was holding on their behalf.
    foreach (all('SELECT p.id, p.name, p.mobile, p.collection_opt_out, p.voice_dnd, p.voice_name_clip,
                         ' . party_balance_side_expr('p', 'in') . ' recv_due,
                         ' . party_balance_expr('p') . " bal
                  FROM parties p WHERE p.id IN ($in)") as $p) {
        $id = (int)$p['id'];
        $e = $ev[$id] ?? [];
        $out[$id] = [
            'id' => $id, 'name' => $p['name'], 'mobile' => $p['mobile'],
            'opt_out' => (int)$p['collection_opt_out'] === 1,
            'dnd' => (int)$p['voice_dnd'] === 1,
            'name_clip' => (string)$p['voice_name_clip'],
            'due' => money_chase_due($p['recv_due'], $p['bal']),
            'promise_open' => $e['promise_open'] ?? null,
            'snoozed_until' => (!empty($e['snooze_until']) && $e['snooze_until'] >= $t) ? $e['snooze_until'] : null,
            'last_contact' => $e['last_contact'] ?? null,
            'contacts_30d' => $e['contacts_30d'] ?? 0,
            'last_call' => $last[$id] ?? null,
        ];
    }
    return $out;
}

/**
 * The "ring this customer" button, drawn the same everywhere it appears.
 *
 * It is on the collection queue, the aging report, the invoice and the party
 * ledger - four screens, and the owner should not have to learn four
 * different buttons. Every one of them leads to the SAME confirm screen,
 * which is where the rules are applied, the amount is shown and the
 * recording can be heard before anybody is dialled. Nothing here dials.
 *
 * Returns '' when the button has no business being there: calling switched
 * off, no permission, a walk-in with no customer record, no mobile number,
 * or nothing owed. A button that is always refused is worse than no button.
 *
 * $back is the screen to return to - see coll_back_to(), which only honours
 * a page of ours.
 */
function voice_call_button($partyId, $back = '', $small = true) {
    $partyId = (int)$partyId;
    if ($partyId <= 0 || !voice_enabled() || !can('payments.add')) return '';
    $ctx = voice_party_context($partyId);
    if (!$ctx || !voice_mobile_ok($ctx['mobile']) || $ctx['due'] <= 0.009) return '';

    $href = 'collection.php?call=' . $partyId . ($back !== '' ? '&back=' . rawurlencode($back) : '');
    return '<a class="btn ' . ($small ? 'btn-sm ' : '') . 'btn-success" href="' . htmlspecialchars($href, ENT_QUOTES)
         . '" title="An automatic reminder call — you see and hear it before it goes">📞 Call Rs '
         . money($ctx['due']) . '</a>';
}

/** The last call to this party, whatever became of it. */
function voice_last_call($partyId) {
    return row('SELECT * FROM voice_calls WHERE party_id = ? ORDER BY id DESC LIMIT 1', [(int)$partyId]);
}

function voice_cooldown_hours() { return max(1, (int)setting('voice_cooldown_hours', 6)); }

/** Real calls placed today, counted once per request.
 *
 *  The collection list asks voice_can_call() for every row it draws, and a
 *  COUNT(*) per row is a hundred queries for a number that cannot change
 *  mid-render. It CAN change when a call is actually placed, though, so
 *  voice_call_send() bumps it - without that, a loop placing calls would
 *  keep reading the count from before the first one and sail past the cap. */
function voice_calls_today($bump = false) {
    static $n = null;
    if ($n === null) $n = (int)val("SELECT COUNT(*) FROM voice_calls
                                    WHERE DATE(created_at) = CURDATE() AND test_mode = 0 AND direction = 'out'");
    if ($bump) $n++;
    return $n;
}

/** May a call go to this party right now, and if not, why not - in words a
 *  shop owner can read off the screen. Every caller asks this; nobody is
 *  allowed to decide for themselves. */
function voice_can_call($partyId, $ctx = null, $now = null) {
    if (!voice_enabled())    return ['ok' => false, 'why' => 'Reminder calls are switched off (Settings → Reminder Calls)'];
    if (!voice_configured()) return ['ok' => false, 'why' => 'Vobiz is not set up yet — Auth ID, token and caller ID are needed'];

    $c = $ctx ?: voice_party_context($partyId);
    if (!$c) return ['ok' => false, 'why' => 'No such customer'];
    if ($c['dnd']) return ['ok' => false, 'why' => 'This customer has asked not to be called'];
    if (!voice_mobile_ok($c['mobile'])) return ['ok' => false, 'why' => 'No usable mobile number'];
    if ($c['due'] <= 0.009) return ['ok' => false, 'why' => 'Nothing is outstanding'];

    // The refusals that pass by themselves. 'wait' tells the queue runner that
    // this call is early, not failed, so it is kept rather than dropped.
    $hold = voice_queue_hold($now);
    if ($hold !== '') return ['ok' => false, 'wait' => true, 'why' => $hold];

    // everything the WhatsApp reminder respects, a call respects too
    $gate = coll_can_remind($c);
    if (!$gate['ok']) return $gate;

    // and one more that is specific to ringing a phone: the same customer is
    // not called twice in a few hours, however many times the button is hit.
    $last = array_key_exists('last_call', $c) ? $c['last_call'] : voice_last_call($partyId);
    if ($last && strtotime($last['created_at']) > time() - voice_cooldown_hours() * 3600) {
        $mins = (int)ceil((strtotime($last['created_at']) + voice_cooldown_hours() * 3600 - time()) / 60);
        return ['ok' => false, 'soft' => true,
                'why' => 'Called ' . voice_ago($last['created_at']) . ' — the next call can go in '
                       . ($mins >= 60 ? (int)round($mins / 60) . ' hour(s)' : $mins . ' minutes')];
    }

    return ['ok' => true, 'why' => ''];
}

/**
 * The two reasons a call is EARLY rather than refused: the clock, and today's
 * cap. Both pass by themselves - one when the hour comes round, the other at
 * midnight - so a queued call that meets either must be kept, not thrown away.
 * Losing thirty calls because the clock struck nine would be the software
 * discarding an evening's work the owner had already decided to do.
 *
 * Both live here, in one place, so voice_can_call() (one customer, one button)
 * and voice_queue_run() (a whole list, by itself) cannot disagree about them.
 * Returns '' when neither applies.
 */
function voice_queue_hold($now = null) {
    if (!voice_hours_ok($now)) {
        $h = voice_hours();
        return 'Calls go out between ' . $h['from'] . ':00 and ' . $h['to'] . ':00 only';
    }
    $cap = max(1, (int)setting('voice_max_per_day', 50));
    if (voice_calls_today() >= $cap) return "Today's call limit ($cap) is used up";
    return '';
}

/** 10 digits, Indian mobile. A landline or a short code is not called. */
function voice_mobile_ok($mobile) {
    // The last ten digits, whatever came before them.
    //
    // This used to trim a leading 91 and nothing else, so a number stored the
    // way half of India writes it - 07990263599 - was declared invalid while
    // voice_e164() beside it dialled the very same number quite happily. Two
    // rules for one question, disagreeing. A customer whose number carried a
    // leading zero could therefore never be rung at all.
    $d = preg_replace('/\D/', '', (string)$mobile);
    if (strlen($d) > 10) $d = substr($d, -10);
    return strlen($d) === 10 && strpos('6789', $d[0]) !== false;
}
function voice_e164($mobile) {
    $d = preg_replace('/\D/', '', (string)$mobile);
    if (strlen($d) > 10) $d = substr($d, -10);
    return '91' . $d;
}

function voice_ago($ts) {
    $s = time() - strtotime((string)$ts);
    if ($s < 90) return 'just now';
    if ($s < 5400) return (int)round($s / 60) . ' minutes ago';
    if ($s < 172800) return (int)round($s / 3600) . ' hours ago';
    return (int)round($s / 86400) . ' days ago';
}

// ---------- placing the call ----------

/** Place ONE reminder call. The only function in the codebase that does.
 *
 *  Order matters and is deliberate: every check runs BEFORE the first write,
 *  so a refused call leaves no row, no log line and no impression on the
 *  cooldown. Only once a call is really going does anything get recorded.
 *
 *  $opts: amount (defaults to the ledger balance), lang, client_uuid, test.
 *  Returns ['ok'=>bool, 'error'=>string, 'call_id'=>int, 'status'=>string]. */
function voice_call_send($partyId, array $opts = []) {
    $partyId = (int)$partyId;
    // $opts['now'] exists so the suite can check the rules at a fixed hour
    // instead of only passing between nine and nine. Nothing in the app
    // passes it, so a real call is always judged against the real clock.
    $now = $opts['now'] ?? null;

    // A repeat of a press that already went through returns that call rather
    // than ringing the customer a second time.
    $cuid = trim((string)($opts['client_uuid'] ?? ''));
    if ($cuid !== '') {
        $dup = row('SELECT * FROM voice_calls WHERE client_uuid = ?', [$cuid]);
        if ($dup) return ['ok' => true, 'error' => '', 'call_id' => (int)$dup['id'],
                          'status' => $dup['status'], 'duplicate' => true];
    }

    $ctx = voice_party_context($partyId);
    $gate = voice_can_call($partyId, $ctx, $now);

    // A person may go over this shop's OWN throttle - the days between
    // reminders, the monthly limit, the hours between calls - and take
    // responsibility for it. They may never go over the customer's wishes:
    // a do-not-call flag, an opt-out, a promise, a hold. Those come back
    // without 'soft' and no override reaches them.
    //
    // The check is here, inside the function that actually dials, rather
    // than on the screen that offers the button. A screen can be skipped.
    $over = !empty($opts['override']) && !empty($gate['soft']) && can('payments.add');
    if (!$gate['ok'] && !$over) return ['ok' => false, 'error' => $gate['why'], 'call_id' => 0, 'status' => 'refused'];
    if ($over) {
        log_activity('voice_override', 'Called ' . ($ctx['name'] ?? '#' . $partyId)
                                     . ' despite: ' . $gate['why']);
        coll_log($partyId, 'call', ['channel' => 'phone', 'status' => 'done',
                                    'note' => 'Called anyway — ' . $gate['why']]);
    }

    $amount = isset($opts['amount']) ? round((float)$opts['amount'], 2) : $ctx['due'];
    if ($amount <= 0.009) return ['ok' => false, 'error' => 'Nothing is outstanding', 'call_id' => 0, 'status' => 'refused'];

    // The date the money was actually due - the oldest open bill's, not
    // today's. Stored with the call so the record answers "how late were they
    // when we rang", and so the scheduled stage ("call three days before the
    // due date") has the date it will need without another lookup.
    $due = $opts['due_date'] ?? val("SELECT COALESCE(due_date, sale_date) FROM sales
                                     WHERE party_id = ? AND is_cancelled = 0 AND status IN ('due','partial')
                                     ORDER BY due_date IS NULL, due_date, id LIMIT 1", [$partyId]);

    $lang = $opts['lang'] ?? setting('voice_lang', 'gu');
    if (!isset(voice_langs()[$lang])) $lang = 'gu';

    // The voice is made here, before anything is dialled, so that the file
    // already exists by the time the phone is answered.
    $promise = (!empty($ctx['promise_open']) && $ctx['promise_open']['due_date'] <= today())
        ? $ctx['promise_open']['due_date'] : null;
    $plan = voice_audio_plan($ctx['name'], $amount, $lang, $promise);
    $script = voice_script($ctx['name'], $amount, $plan['lang']);
    $test = array_key_exists('test', $opts) ? (bool)$opts['test'] : voice_test_mode();

    // ---- from here on it is really happening ----
    $token = bin2hex(random_bytes(20));
    q('INSERT INTO voice_calls (party_id, mobile, amount, due_date, lang, script, provider, token,
                                status, test_mode, client_uuid, created_by, audio_file, question_asked, started_at)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())',
      [$partyId, voice_e164($ctx['mobile']), $amount, $due ?: null, $plan['lang'], $script,
       setting('voice_provider', 'vobiz'), $token, 'queued', $test ? 1 : 0,
       $cuid !== '' ? $cuid : null, $_SESSION['user_id'] ?? null,
       voice_audio_basename($plan['main']['url'] ?? ''), voice_ivr_on() ? 1 : 0]);
    $callId = (int)insert_id();
    if (!$test) voice_calls_today(true);   // keep the daily cap honest inside one request

    // Note what is NOT done here: the collection history is not written yet.
    //
    // A call that was answered is a contact, and voice_mark_answered() logs
    // it as one - which is what makes the collection screen's three-day
    // cooldown and its "contacted this month" counter include calls and not
    // only messages. A phone that rang and was not picked up is not a
    // contact, because nobody was reached. Logging the attempt here instead
    // looked tidier and was wrong twice over: it locked a customer out of
    // reminders for three days over a call they never received, and it made
    // the six-hour call cooldown below unreachable - a setting on the screen
    // that could never do anything.
    log_activity('voice_call', 'party ' . $partyId . ' ₹' . money($amount) . ' ' . $plan['lang'] . ($test ? ' (test)' : ''));

    if ($test) {
        q("UPDATE voice_calls SET status = 'test' WHERE id = ?", [$callId]);
        return ['ok' => true, 'error' => '', 'call_id' => $callId, 'status' => 'test',
                'script' => $script, 'plan' => $plan];
    }

    // Picked from a list rather than pressed one at a time: everything is
    // prepared and checked, and the dialling is left to voice_queue_run().
    // Fifty calls leaving at once is what a provider's concurrency limit
    // exists to stop, and what makes a shop's number look like a dialler.
    if (!empty($opts['queue'])) {
        q("UPDATE voice_calls SET status = 'waiting' WHERE id = ?", [$callId]);
        return ['ok' => true, 'error' => '', 'call_id' => $callId, 'status' => 'waiting', 'script' => $script];
    }

    [$uuid, $err] = voice_provider_call(voice_e164($ctx['mobile']), $token);
    if ($uuid === null) {
        q("UPDATE voice_calls SET status = 'failed', error = ? WHERE id = ?", [mb_substr($err, 0, 255), $callId]);
        return ['ok' => false, 'error' => $err, 'call_id' => $callId, 'status' => 'failed'];
    }
    q("UPDATE voice_calls SET call_uuid = ?, status = 'ringing' WHERE id = ?", [$uuid, $callId]);
    return ['ok' => true, 'error' => '', 'call_id' => $callId, 'status' => 'ringing', 'script' => $script];
}

/** How many calls are still waiting to go out. */
function voice_queue_count() {
    return (int)val("SELECT COUNT(*) FROM voice_calls WHERE status = 'waiting'");
}

/** Dial the next few waiting calls.
 *
 *  Every guard is checked AGAIN here, not just when the list was picked. A
 *  customer can promise to pay, be marked do-not-call, or simply pay, in the
 *  minutes between the owner ticking a box and the call going out - and
 *  ringing them anyway would be the software doing something the shop had
 *  already decided against.
 *
 *  Returns ['dialled', 'dropped', 'left']. */
function voice_queue_run($limit = null, $now = null) {
    $limit = $limit ?: max(1, (int)setting('voice_bulk_per_run', 5));
    $dialled = 0; $dropped = 0;

    // Outside calling hours, or past today's cap, nothing is dialled and
    // nothing is thrown away - the queue simply waits for the hour.
    $hold = voice_queue_hold($now);
    if ($hold !== '') return ['dialled' => 0, 'dropped' => 0, 'left' => voice_queue_count(), 'holding' => $hold];

    foreach (all("SELECT * FROM voice_calls WHERE status = 'waiting' ORDER BY id LIMIT " . (int)$limit) as $c) {
        $gate = voice_can_call((int)$c['party_id'], null, $now);
        if (!empty($gate['wait'])) break;           // the clock or the cap: stop, keep the rest
        if (!$gate['ok']) {
            q("UPDATE voice_calls SET status = 'failed', error = ? WHERE id = ?",
              [mb_substr('Not called: ' . $gate['why'], 0, 255), $c['id']]);
            $dropped++;
            continue;
        }
        [$uuid, $err] = voice_provider_call($c['mobile'], $c['token']);
        if ($uuid === null) {
            q("UPDATE voice_calls SET status = 'failed', error = ? WHERE id = ?", [mb_substr($err, 0, 255), $c['id']]);
            $dropped++;
            continue;
        }
        q("UPDATE voice_calls SET call_uuid = ?, status = 'ringing', started_at = NOW() WHERE id = ?", [$uuid, $c['id']]);
        $dialled++;
    }
    return ['dialled' => $dialled, 'dropped' => $dropped, 'left' => voice_queue_count()];
}

/** The HTTP call to Vobiz. Returns [request_uuid, ''] or [null, 'why not'].
 *
 *  POST https://api.vobiz.ai/api/v1/Account/{auth_id}/Call/
 *  headers X-Auth-ID / X-Auth-Token. The trailing slash and the capital A
 *  in /Account/ are load-bearing - their own docs list 404 and 401 as what
 *  you get for dropping either. */
function voice_provider_call($toE164, $token) {
    if (!function_exists('curl_init')) return [null, 'The server does not have curl.'];
    $authId = setting('vobiz_auth_id', '');
    $authTok = setting('vobiz_auth_token', '');
    $from = preg_replace('/\D/', '', setting('vobiz_caller_id', ''));
    if ($authId === '' || $authTok === '' || $from === '') return [null, 'Vobiz is not configured.'];

    $body = [
        'from' => $from,
        'to' => $toE164,
        'answer_url' => voice_public_url('voice_answer.php?t=' . $token),
        'answer_method' => 'POST',
        'ring_url' => voice_public_url('voice_webhook.php?t=' . $token),
        'ring_method' => 'POST',
        'hangup_url' => voice_public_url('voice_webhook.php?t=' . $token),
        'hangup_method' => 'POST',
        'time_limit' => 90,      // the script is ~20 seconds; nothing here needs 4 hours
        'hangup_on_ring' => 30,  // stop ringing after 30s rather than chase
    ];

    // Machine detection is OFF unless the shop asks for it, and that is not a
    // small detail - it decides whether the customer hears anything at all.
    //
    // With it on, the provider answers the call and LISTENS before it will
    // ask us what to say: it has to hear enough to decide human or answering
    // machine. Somebody who says "હલો" gives it that in a moment and the
    // greeting follows. Somebody who just puts the phone to their ear gives
    // it nothing, so it keeps listening until its own window runs out - and
    // for those several seconds the customer hears silence, which is exactly
    // when people hang up.
    //
    // That trade is the wrong way round. It was bought to avoid reading a
    // reminder to an answering machine; the price was every quiet customer
    // hearing nothing and hanging up on a call that had not started.
    if ((int)setting('voice_machine_detect', 0) === 1) $body['machine_detection'] = 'hangup';
    $ch = curl_init('https://api.vobiz.ai/api/v1/Account/' . rawurlencode($authId) . '/Call/');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Auth-ID: ' . $authId, 'X-Auth-Token: ' . $authTok],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
    ]);
    $res = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);

    if ($res === false) return [null, 'Could not reach Vobiz: ' . $cerr];
    $j = json_decode($res, true);
    if ($code >= 200 && $code < 300 && !empty($j['request_uuid'])) return [$j['request_uuid'], ''];

    // Their documented failures, said in a way the owner can act on.
    $known = [400 => 'Vobiz rejected the number or the request (400).',
              401 => 'Vobiz auth failed — check the Auth ID and token (401).',
              402 => 'Vobiz balance is empty — top it up (402).',
              404 => 'Vobiz Auth ID not found (404).',
              429 => 'Too many calls at once for this Vobiz account (429).'];
    $msg = $known[$code] ?? ('Vobiz returned HTTP ' . $code);
    if (!empty($j['error'])) $msg .= ' ' . (is_string($j['error']) ? $j['error'] : json_encode($j['error']));
    elseif (!empty($j['message']) && $code >= 300) $msg .= ' ' . $j['message'];
    return [null, $msg];
}

// ---------- what came back ----------

function voice_call_by_token($token) {
    $token = preg_replace('/[^a-f0-9]/i', '', (string)$token);
    if (strlen($token) !== 40) return null;
    return row('SELECT * FROM voice_calls WHERE token = ?', [$token]);
}

/** Has this call's answer URL expired? A token that stays valid forever is a
 *  permanent public link to a customer's name and outstanding amount. */
function voice_token_fresh($call) {
    return $call && strtotime($call['created_at']) > time() - VOICE_TOKEN_TTL_HOURS * 3600;
}

/** Fold a provider callback into the row. Called by the webhook only.
 *
 *  The status is worked out defensively. Vobiz documents CallStatus values
 *  ringing / in-progress / completed but does not publish the full list of
 *  hangup causes, so the cause is matched loosely and - more reliably - a
 *  call whose answer URL was fetched is known to have been answered, because
 *  the network only asks for the XML once somebody has picked up. That fact
 *  outranks any cause string, known or not. */
function voice_status_apply($call, array $p) {
    $event = strtolower((string)($p['Event'] ?? ''));
    $cause = strtolower((string)($p['HangupCause'] ?? $p['hangup_cause'] ?? ''));
    $status = strtolower((string)($p['CallStatus'] ?? $p['Status'] ?? ''));
    $dur = (int)($p['Duration'] ?? $p['duration'] ?? 0);
    $answered = (int)$call['answered'] === 1;

    if ($event === 'ring' || $status === 'ringing') {
        if ($call['status'] === 'queued') q("UPDATE voice_calls SET status = 'ringing' WHERE id = ?", [$call['id']]);
        return 'ringing';
    }

    if ($event === 'hangup' || $status === 'completed') {
        $new = $answered ? 'answered' : 'no_answer';
        if (!$answered) {
            if (strpos($cause, 'busy') !== false) $new = 'busy';
            elseif (strpos($cause, 'no_answer') !== false || strpos($cause, 'noanswer') !== false
                    || strpos($cause, 'no-answer') !== false || strpos($cause, 'timeout') !== false
                    || strpos($cause, 'cancel') !== false) $new = 'no_answer';
            elseif ($cause !== '' && strpos($cause, 'normal') === false) $new = 'failed';
        }
        q("UPDATE voice_calls SET status = ?, hangup_cause = ?, duration = ?, ended_at = NOW() WHERE id = ?",
          [$new, mb_substr($cause, 0, 40), $dur, $call['id']]);
        return $new;
    }
    return $call['status'];
}

/** Stamped by the answer URL: the phone was picked up.
 *
 *  This is also where the call becomes a "contact" in the collection screen's
 *  eyes, because this is the moment the customer was actually reached. From
 *  here the ordinary three-day cooldown and the monthly cap apply to them
 *  exactly as they would after a WhatsApp reminder. */
function voice_mark_answered($call) {
    if ((int)$call['answered'] === 1) return;      // a repeated fetch is not a second contact
    q("UPDATE voice_calls SET answered = 1, status = 'answered', answered_at = NOW() WHERE id = ?", [$call['id']]);
    if ((int)$call['party_id'] > 0) {
        coll_log((int)$call['party_id'], 'call', ['channel' => 'voice', 'amount' => (float)$call['amount'],
                 'note' => 'Reminder call answered — ₹' . money($call['amount']), 'ref_id' => (int)$call['id'],
                 'status' => 'done']);
    }
}

/** Just the filename out of a generated-audio URL, or '' for none. */
function voice_audio_basename($url) {
    $url = (string)$url;
    if ($url === '') return null;
    $base = basename(parse_url($url, PHP_URL_PATH) ?: '');
    return preg_match('/^tts_[a-z]{2}_[a-f0-9]{24}\.wav$/', $base) ? $base : null;
}

/** What the customer pressed, and what the shop does about it.
 *
 *  This is the part that matters. A "yes" is not filed as a note nobody
 *  reads - it is logged as a promise to pay today, through the very same
 *  coll_log() a promise taken across the counter goes through. From that
 *  moment the customer appears in the Promises card on the collection
 *  screen, reminders to them stop until the day is out, and the nightly job
 *  marks the promise kept or broken depending on whether the money arrives.
 *  A "no" is filed as a contact with what they said, so the next person to
 *  look at this customer knows the phone was answered and the answer was no.
 *
 *  Returns 'yes' | 'no' | 'none'. */
function voice_response_apply($call, $digit) {
    $digit = preg_replace('/\D/', '', (string)$digit);
    $answer = $digit === '1' ? 'yes' : ($digit === '2' ? 'no' : 'none');

    // A network retry of the same keypress must not stack up two promises.
    if (!empty($call['response'])) return $call['response'];

    q("UPDATE voice_calls SET response = ?, response_at = NOW() WHERE id = ?", [$answer, $call['id']]);

    $pid = (int)$call['party_id'];
    if ($pid > 0 && $answer === 'yes') {
        coll_log($pid, 'promise', [
            'amount' => (float)$call['amount'], 'due_date' => today(), 'channel' => 'voice',
            'note' => 'Said yes on the reminder call — ₹' . money($call['amount']) . ' today',
            'ref_id' => (int)$call['id'], 'status' => 'open',
        ]);
    } elseif ($pid > 0 && $answer === 'no') {
        coll_log($pid, 'call', [
            'amount' => (float)$call['amount'], 'channel' => 'voice',
            'note' => 'Said no on the reminder call — not paying today',
            'ref_id' => (int)$call['id'], 'status' => 'done',
        ]);
    }
    log_activity('voice_response', 'call ' . $call['id'] . ' party ' . $pid . ' pressed ' . ($digit ?: '-') . ' = ' . $answer);
    return $answer;
}

function voice_response_label($r) {
    return ['yes' => 'Said yes — paying today', 'no' => 'Said no', 'none' => 'Did not answer the question'][$r] ?? '';
}

function voice_status_label($status) {
    return [
        'queued' => 'Queued', 'ringing' => 'Ringing', 'answered' => 'Answered',
        'no_answer' => 'Not answered', 'busy' => 'Busy', 'failed' => 'Failed',
        'test' => 'Test (not dialled)', 'refused' => 'Not sent', 'waiting' => 'Waiting to go out',
    ][$status] ?? ucfirst((string)$status);
}

/** The XML Vobiz asks for when the call is answered.
 *
 *  Either a run of <Play> clips or one <Speak>. Nothing else goes in it: no
 *  <Gather>, no redirect, no recording. When the IVR ("press 1 if you have
 *  paid") is added later it is one more element here and one more column on
 *  the row - which is why this function, and not the caller, owns the XML. */
function voice_answer_xml($call) {
    // A connect call is not a script being read: it is a person who has just
    // picked up, waiting to be joined to a customer. Nothing below applies.
    if (($call['intent'] ?? '') === 'bridge') {
        require_once __DIR__ . '/voice_bridge.php';
        return voice_bridge_xml($call);
    }
    $party = row('SELECT name FROM parties WHERE id = ?', [(int)$call['party_id']]);
    $promise = voice_call_promise_date($call);

    // The reminder itself was made before dialling and its file is on the
    // row. Re-planning here would be a second generation - and a wait - at
    // the worst possible moment, with the customer already on the line.
    $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<Response>\n";
    $xml .= voice_record_session_xml($call);
    $xml .= $call['audio_file']
        ? voice_xml_play(voice_public_url(up_rel('voice/tts') . '/' . $call['audio_file']))
        : voice_xml_speak($call['script'] ?: voice_script($party['name'] ?? '', (float)$call['amount'], 'en'));

    // A conversation, when the shop has switched it on: ask how they are,
    // ask when the payment will come, and listen to the answer. Everything
    // it can say was made before the phone rang. If the wording is not ready
    // - a language switched, the voice never made - it is NOT started, and
    // the keypad question below runs instead: half a conversation in the
    // wrong language is worse than a plain question.
    if (voice_talk_ready($call['lang']) && (int)$call['question_asked'] === 1) {
        $xml .= voice_talk_say('talk_how', $call['lang']);
        $xml .= voice_talk_ask_xml($call, 1);
        $xml .= "</Response>\n";
        return $xml;
    }

    if (voice_ivr_on() && (int)$call['question_asked'] === 1) {
        // cached-only: this runs with the customer already on the line
        $plan = voice_audio_plan($party['name'] ?? '', (float)$call['amount'], $call['lang'], $promise, true);
        $q = $plan['question'] ?? null;
        // One digit, ten seconds, and no # needed - an older customer should
        // not have to know what a hash key is. Pressing nothing simply falls
        // through to the goodbye below, which is why the Gather is not the
        // last thing in the document.
        $xml .= '  <Gather action="' . htmlspecialchars(voice_public_url('voice_gather.php?t=' . $call['token']), ENT_XML1)
              . '" method="POST" inputType="dtmf" numDigits="1" finishOnKey="none" executionTimeout="10">' . "\n";
        if ($q) $xml .= '  ' . ($q['url'] ? voice_xml_play($q['url']) : voice_xml_speak($q['text']));
        $xml .= "  </Gather>\n";
        $none = $plan['replies']['none'] ?? null;
        if ($none) $xml .= $none['url'] ? voice_xml_play($none['url']) : voice_xml_speak($none['text']);
    }
    $xml .= "</Response>\n";
    return $xml;
}

/**
 * Fetch a recording from the provider.
 *
 * It sits behind the account's own credentials - a browser cannot reach it,
 * which is the whole reason voice_rec.php exists - so the one way of asking
 * for it lives here, used both by the player and by the call that has to
 * listen to the customer's answer while they are still on the line. That is
 * why the timeout is an argument: sixty seconds is fine for a download the
 * owner asked for, and would be a dead phone line for the other.
 *
 * Returns [bytes|null, mime, error].
 */
function voice_fetch_recording($url, $timeout = 60) {
    $url = trim((string)$url);
    if ($url === '') return [null, '', 'no recording'];
    if (!function_exists('curl_init')) return [null, '', 'The server does not have curl.'];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => max(2, (int)$timeout),
        // Harmless if the recording turns out to sit on plain storage that
        // ignores them; required if it does not.
        CURLOPT_HTTPHEADER => ['X-Auth-ID: ' . setting('vobiz_auth_id', ''),
                               'X-Auth-Token: ' . setting('vobiz_auth_token', '')],
    ]);
    $audio = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $mime = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $cerr = curl_error($ch);
    curl_close($ch);
    if ($audio === false || $code < 200 || $code >= 300 || strlen((string)$audio) < 512)
        return [null, '', 'HTTP ' . $code . ($cerr ? ' ' . $cerr : '')];
    $mime = strtok($mime, ';') ?: '';
    if (strpos($mime, 'audio') !== 0) $mime = 'audio/mpeg';
    return [$audio, $mime, ''];
}

/**
 * Record the whole call, both sides, from here on.
 *
 * The recording kept before this was the customer's ANSWER only - what the
 * shop's own voice said was never on tape. Half a conversation is no use to
 * a shop whose customer says "your phone told me something else".
 *
 * redirect="false" is what makes it a recording rather than a step: the call
 * carries straight on to the next verb and the finished file is posted to
 * the callback when the call ends. Placed first in the document so the tape
 * starts before the greeting.
 *
 * Off unless the shop switches it on. Recording a phone call is the shop's
 * decision, not this software's. If the provider ignores the verb the call
 * plays out exactly as before and no full recording appears - the customer's
 * own answer is still recorded either way.
 */
function voice_record_session_xml($call, $force = false) {
    // $force is for the connect call, where recording is the point rather
    // than an option the shop switched on for reminders. One rule, extended
    // where it is genuinely different, rather than a second copy of it.
    if (!$force && (int)setting('voice_record_all', 0) !== 1) return '';
    if (empty($call['token'])) return '';
    $cb = voice_public_url('voice_webhook.php?t=' . $call['token'] . '&rec=1');
    return '  <Record callbackUrl="' . htmlspecialchars($cb, ENT_XML1) . '" callbackMethod="POST"'
         . ' recordSession="true" redirect="false" maxLength="'
         . max(30, (int)setting('voice_record_max', 600)) . '" fileFormat="mp3"/>' . "\n";
}

function voice_xml_play($url) { return '  <Play>' . htmlspecialchars($url, ENT_XML1) . "</Play>\n"; }
function voice_xml_speak($text) {
    return '  <Speak voice="WOMAN" language="en-US">' . htmlspecialchars((string)$text, ENT_XML1) . "</Speak>\n";
}

/** The date this customer promised to pay, if they did and it has arrived.
 *  A promise still in the future is why the call would not have gone out at
 *  all, so anything found here is today's or already past. */
function voice_call_promise_date($call) {
    if ((int)$call['party_id'] <= 0) return null;
    return val("SELECT due_date FROM collection_events
                WHERE party_id = ? AND event_type = 'promise' AND status = 'open' AND due_date IS NOT NULL
                ORDER BY due_date DESC LIMIT 1", [(int)$call['party_id']]) ?: null;
}

// ---------- balance ----------

/** Vobiz balance, cached for a few minutes so a screen refresh does not
 *  become an API call. Returns ['ok','balance','currency','error']. */
function voice_balance($force = false) {
    $cached = setting('vobiz_balance_cache', '');
    if (!$force && $cached !== '') {
        $c = json_decode($cached, true);
        if (is_array($c) && ($c['at'] ?? 0) > time() - 600) return $c;
    }
    $authId = setting('vobiz_auth_id', '');
    $authTok = setting('vobiz_auth_token', '');
    if ($authId === '' || $authTok === '' || !function_exists('curl_init'))
        return ['ok' => false, 'balance' => null, 'currency' => 'INR', 'error' => 'Vobiz is not configured', 'at' => time()];

    $ch = curl_init('https://api.vobiz.ai/api/v1/Account/' . rawurlencode($authId) . '/balance/INR');
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Auth-ID: ' . $authId, 'X-Auth-Token: ' . $authTok],
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
    ]);
    $res = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $j = is_string($res) ? json_decode($res, true) : null;
    if ($code < 200 || $code >= 300 || !is_array($j))
        return ['ok' => false, 'balance' => null, 'currency' => 'INR', 'error' => 'Vobiz balance check failed (HTTP ' . $code . ')', 'at' => time()];

    $out = ['ok' => true, 'balance' => (float)($j['available_balance'] ?? $j['balance'] ?? 0),
            'currency' => (string)($j['currency'] ?? 'INR'), 'error' => '', 'at' => time()];
    set_setting('vobiz_balance_cache', json_encode($out));
    return $out;
}

/** Low-balance alert. Run by cron; tells the owner once a day at most, on the
 *  same shop WhatsApp number every other alert uses. An empty Vobiz account
 *  fails calls silently at the provider, so this is the warning that the
 *  ugliest kind of failure - calls that look sent and never rang - is near. */
function voice_balance_check() {
    if (!voice_enabled() || !voice_configured()) return 'calls are off';
    $b = voice_balance(true);
    if (!$b['ok']) return $b['error'];
    $min = (float)setting('voice_balance_min', 100);
    if ($b['balance'] > $min) return 'balance ' . $b['currency'] . ' ' . money($b['balance']) . ' — fine';
    if (setting('vobiz_balance_alert_on', '') === today()) return 'low, already told today';

    $msg = "📞 *Reminder calls — Vobiz balance is low*\n\n"
         . 'Left: ' . $b['currency'] . ' ' . money($b['balance']) . "\n"
         . 'Alert below: ' . $b['currency'] . ' ' . money($min) . "\n\n"
         . "Top up, or reminder calls will stop going out.";
    $shop = wa_normalize_number(setting('wa_shop_number'));
    if (strlen($shop) >= 12) send_whatsapp($shop, $msg);
    set_setting('vobiz_balance_alert_on', today());
    return 'low balance — owner told';
}

// Loaded LAST, on purpose.
//
// The talking call is an extension of this module: it uses voice_say_live(),
// voice_xml_play() and the rest, while voice_answer_xml() above asks it
// whether a conversation is ready to start. Required at the top, its own
// require_once of this file would come back before a single function here
// was defined. At the bottom, everything above exists first and the cycle
// resolves cleanly.
require_once __DIR__ . '/voice_talk.php';
