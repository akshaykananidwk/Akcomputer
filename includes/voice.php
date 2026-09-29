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
    $shop = setting('app_name', 'AK Computer');
    $amt = voice_tokens_text(voice_amount_tokens($amount), $lang);
    $name = trim((string)$name);

    if ($lang === 'en') {
        return trim($ph['greet'] . ($name !== '' ? ' ' . $name : '') . ', ' . $ph['from_shop'] . ' ' . $shop
                    . '. Your payment of ' . $amt . ' is pending. ' . $ph['request'] . '. ' . $ph['thanks'] . '.');
    }
    // Gujarati/Hindi read in the same order the clips are played in.
    return trim($ph['greet'] . ($name !== '' ? ' ' . $name : '') . ', ' . $shop . ' ' . $ph['from_shop']
                . '. ' . $ph['your'] . ' ' . $amt . ' ' . $ph['due'] . '. ' . $ph['request'] . '. ' . $ph['thanks'] . '.');
}

// ---------- audio clips ----------

function voice_clip_dir($lang) { return dirname(__DIR__) . '/uploads/voice/' . $lang; }

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

/** Every clip a language needs, in the order the checklist prints them. */
function voice_clip_tokens() {
    $t = ['greet', 'shop', 'your', 'due', 'request', 'thanks',
          'hundred', 'thousand', 'lakh', 'crore', 'rupees', 'paise'];
    for ($i = 0; $i <= 99; $i++) $t[] = 'n' . $i;
    return $t;
}

function voice_clip_exists($token, $lang) {
    return is_file(voice_clip_dir($lang) . '/' . $token . '.mp3');
}

/** Which clips a language is still missing. Empty means calls can go out in
 *  that language; anything else and voice_clip_plan() falls back to English. */
function voice_clips_missing($lang) {
    if ($lang === 'en') return [];               // the provider speaks English itself
    $miss = [];
    foreach (voice_clip_tokens() as $t) if (!voice_clip_exists($t, $lang)) $miss[] = $t;
    return $miss;
}

/** How this particular call will be delivered.
 *
 *  Returns either a list of clip URLs to play in order, or the English text
 *  to hand the provider's TTS. One missing clip in the middle of a sentence
 *  would be heard as a gap where the amount should be - the provider simply
 *  skips a file it cannot fetch - so a language is used only when it is
 *  complete. Half a spoken amount is worse than a whole English one. */
function voice_clip_plan($partyName, $amount, $lang, $nameClip = '') {
    $tokens = array_merge(['greet'], $nameClip ? ['__name'] : [], ['shop', 'your'],
                          voice_amount_tokens($amount), ['due', 'request', 'thanks']);
    $missing = voice_clips_missing($lang);
    if ($lang === 'en' || $missing) {
        return ['mode' => 'tts', 'lang' => 'en', 'text' => voice_script($partyName, $amount, 'en'),
                'missing' => $missing, 'fell_back' => $lang !== 'en'];
    }
    $urls = [];
    foreach ($tokens as $t) {
        $urls[] = $t === '__name'
            ? voice_public_url('uploads/voice/names/' . $nameClip)
            : voice_public_url('uploads/voice/' . $lang . '/' . $t . '.mp3');
    }
    return ['mode' => 'clips', 'lang' => $lang, 'urls' => $urls, 'missing' => [], 'fell_back' => false];
}

// ---------- configuration ----------

function voice_configured() {
    return setting('vobiz_auth_id', '') !== ''
        && setting('vobiz_auth_token', '') !== ''
        && setting('vobiz_caller_id', '') !== '';
}
function voice_test_mode() { return (int)setting('voice_test_mode', 1) === 1; }
function voice_enabled()   { return (int)setting('voice_enabled', 0) === 1; }

/** Calling hours. TRAI's rule for commercial calls is 9am to 9pm, and the
 *  shop is free to narrow it further but not to widen it: the two settings
 *  are clamped here rather than trusted, so a stray 0-24 in the settings
 *  table cannot put the shop on the wrong side of the regulator. */
function voice_hours($now = null) {
    $from = max(9, min(21, (int)setting('voice_hour_from', 9)));
    $to   = max(9, min(21, (int)setting('voice_hour_to', 21)));
    if ($to <= $from) { $from = 9; $to = 21; }
    return ['from' => $from, 'to' => $to];
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
    foreach (all('SELECT p.id, p.name, p.mobile, p.collection_opt_out, p.voice_dnd, p.voice_name_clip,
                         ' . party_balance_side_expr('p', 'in') . " recv_due
                  FROM parties p WHERE p.id IN ($in)") as $p) {
        $id = (int)$p['id'];
        $e = $ev[$id] ?? [];
        $out[$id] = [
            'id' => $id, 'name' => $p['name'], 'mobile' => $p['mobile'],
            'opt_out' => (int)$p['collection_opt_out'] === 1,
            'dnd' => (int)$p['voice_dnd'] === 1,
            'name_clip' => (string)$p['voice_name_clip'],
            'due' => round((float)$p['recv_due'], 2),
            'promise_open' => $e['promise_open'] ?? null,
            'snoozed_until' => (!empty($e['snooze_until']) && $e['snooze_until'] >= $t) ? $e['snooze_until'] : null,
            'last_contact' => $e['last_contact'] ?? null,
            'contacts_30d' => $e['contacts_30d'] ?? 0,
            'last_call' => $last[$id] ?? null,
        ];
    }
    return $out;
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
    if ($n === null) $n = (int)val('SELECT COUNT(*) FROM voice_calls WHERE DATE(created_at) = CURDATE() AND test_mode = 0');
    if ($bump) $n++;
    return $n;
}

/** May a call go to this party right now, and if not, why not - in words a
 *  shop owner can read off the screen. Every caller asks this; nobody is
 *  allowed to decide for themselves. */
function voice_can_call($partyId, $ctx = null) {
    if (!voice_enabled())    return ['ok' => false, 'why' => 'Reminder calls are switched off (Settings → Reminder Calls)'];
    if (!voice_configured()) return ['ok' => false, 'why' => 'Vobiz is not set up yet — Auth ID, token and caller ID are needed'];

    $c = $ctx ?: voice_party_context($partyId);
    if (!$c) return ['ok' => false, 'why' => 'No such customer'];
    if ($c['dnd']) return ['ok' => false, 'why' => 'This customer has asked not to be called'];
    if (!voice_mobile_ok($c['mobile'])) return ['ok' => false, 'why' => 'No usable mobile number'];
    if ($c['due'] <= 0.009) return ['ok' => false, 'why' => 'Nothing is outstanding'];

    if (!voice_hours_ok()) {
        $h = voice_hours();
        return ['ok' => false, 'why' => 'Calls go out between ' . $h['from'] . ':00 and ' . $h['to'] . ':00 only'];
    }

    // everything the WhatsApp reminder respects, a call respects too
    $gate = coll_can_remind($c);
    if (!$gate['ok']) return $gate;

    // and one more that is specific to ringing a phone: the same customer is
    // not called twice in a few hours, however many times the button is hit.
    $last = array_key_exists('last_call', $c) ? $c['last_call'] : voice_last_call($partyId);
    if ($last && strtotime($last['created_at']) > time() - voice_cooldown_hours() * 3600) {
        $mins = (int)ceil((strtotime($last['created_at']) + voice_cooldown_hours() * 3600 - time()) / 60);
        return ['ok' => false, 'why' => 'Called ' . voice_ago($last['created_at']) . ' — the next call can go in '
                                        . ($mins >= 60 ? (int)round($mins / 60) . ' hour(s)' : $mins . ' minutes')];
    }

    $cap = max(1, (int)setting('voice_max_per_day', 50));
    if (voice_calls_today() >= $cap) return ['ok' => false, 'why' => "Today's call limit ($cap) is used up"];

    return ['ok' => true, 'why' => ''];
}

/** 10 digits, Indian mobile. A landline or a short code is not called. */
function voice_mobile_ok($mobile) {
    $d = preg_replace('/\D/', '', (string)$mobile);
    if (strlen($d) > 10 && strncmp($d, '91', 2) === 0) $d = substr($d, -10);
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

    // A repeat of a press that already went through returns that call rather
    // than ringing the customer a second time.
    $cuid = trim((string)($opts['client_uuid'] ?? ''));
    if ($cuid !== '') {
        $dup = row('SELECT * FROM voice_calls WHERE client_uuid = ?', [$cuid]);
        if ($dup) return ['ok' => true, 'error' => '', 'call_id' => (int)$dup['id'],
                          'status' => $dup['status'], 'duplicate' => true];
    }

    $ctx = voice_party_context($partyId);
    $gate = voice_can_call($partyId, $ctx);
    if (!$gate['ok']) return ['ok' => false, 'error' => $gate['why'], 'call_id' => 0, 'status' => 'refused'];

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
    $plan = voice_clip_plan($ctx['name'], $amount, $lang, $ctx['name_clip']);
    $script = voice_script($ctx['name'], $amount, $plan['lang']);
    $test = array_key_exists('test', $opts) ? (bool)$opts['test'] : voice_test_mode();

    // ---- from here on it is really happening ----
    $token = bin2hex(random_bytes(20));
    q('INSERT INTO voice_calls (party_id, mobile, amount, due_date, lang, script, provider, token,
                                status, test_mode, client_uuid, created_by, started_at)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW())',
      [$partyId, voice_e164($ctx['mobile']), $amount, $due ?: null, $plan['lang'], $script,
       setting('voice_provider', 'vobiz'), $token, 'queued', $test ? 1 : 0,
       $cuid !== '' ? $cuid : null, $_SESSION['user_id'] ?? null]);
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

    [$uuid, $err] = voice_provider_call(voice_e164($ctx['mobile']), $token);
    if ($uuid === null) {
        q("UPDATE voice_calls SET status = 'failed', error = ? WHERE id = ?", [mb_substr($err, 0, 255), $callId]);
        return ['ok' => false, 'error' => $err, 'call_id' => $callId, 'status' => 'failed'];
    }
    q("UPDATE voice_calls SET call_uuid = ?, status = 'ringing' WHERE id = ?", [$uuid, $callId]);
    return ['ok' => true, 'error' => '', 'call_id' => $callId, 'status' => 'ringing', 'script' => $script];
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
        // A reminder read to an answering machine is wasted money and an
        // annoyed customer, so the machine is detected and the call dropped.
        'machine_detection' => 'hangup',
        'time_limit' => 90,      // the script is ~20 seconds; nothing here needs 4 hours
        'hangup_on_ring' => 30,  // stop ringing after 30s rather than chase
    ];
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

function voice_status_label($status) {
    return [
        'queued' => 'Queued', 'ringing' => 'Ringing', 'answered' => 'Answered',
        'no_answer' => 'Not answered', 'busy' => 'Busy', 'failed' => 'Failed',
        'test' => 'Test (not dialled)', 'refused' => 'Not sent',
    ][$status] ?? ucfirst((string)$status);
}

/** The XML Vobiz asks for when the call is answered.
 *
 *  Either a run of <Play> clips or one <Speak>. Nothing else goes in it: no
 *  <Gather>, no redirect, no recording. When the IVR ("press 1 if you have
 *  paid") is added later it is one more element here and one more column on
 *  the row - which is why this function, and not the caller, owns the XML. */
function voice_answer_xml($call) {
    $party = row('SELECT name, voice_name_clip FROM parties WHERE id = ?', [(int)$call['party_id']]);
    $plan = voice_clip_plan($party['name'] ?? '', (float)$call['amount'], $call['lang'], $party['voice_name_clip'] ?? '');

    $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<Response>\n";
    if ($plan['mode'] === 'clips') {
        foreach ($plan['urls'] as $u) $xml .= '  <Play>' . htmlspecialchars($u, ENT_XML1) . "</Play>\n";
    } else {
        $xml .= '  <Speak voice="WOMAN" language="en-US">' . htmlspecialchars($plan['text'], ENT_XML1) . "</Speak>\n";
    }
    $xml .= "</Response>\n";
    return $xml;
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
