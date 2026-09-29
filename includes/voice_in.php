<?php
// The shop's number answers by itself.
//
// Vobiz routes an incoming call to an Application, and the Application's
// answer_url is ours. Every decision about what the caller hears is made
// here - the menu, who is recognised, what they may be told, and when a
// person is rung instead.
//
// Three things shape the whole design:
//
//   * Caller ID is not proof. It can be forged, so this file treats the
//     number as a convenience, not a password. What a recognised caller may
//     be TOLD is a setting the owner chooses (voice_ivr_balance), and the
//     safe option sends the figures to WhatsApp - which needs the actual SIM
//     - instead of reading them to whoever is on the line.
//   * Nobody waits in silence. Speech that has never been said before takes
//     seconds to make, so every fixed sentence is generated once and cached,
//     and only the one line that cannot be (their own balance) may generate
//     mid-call, on an eight-second leash with English underneath it.
//   * A call that asked for something is not finished when it hangs up. An
//     order left at nine at night is worth money only if somebody sees it in
//     the morning, so those calls are marked needing action and stay on the
//     screen until a person closes them.
//
// The caller is matched to a party by wa_bot_party_for() - the same function
// the WhatsApp bot uses, so a customer is recognised the same way on both,
// and there is one rule to get wrong instead of two.

require_once __DIR__ . '/voice.php';
require_once __DIR__ . '/wa_bot.php';   // wa_bot_party_for()

function voice_in_on() { return (int)setting('voice_inbound', 0) === 1; }
function voice_in_lang() {
    $l = setting('voice_inbound_lang', 'gu');
    return isset(voice_langs()[$l]) ? $l : 'gu';
}

/** Is the shop open right now? Outside these hours the menu still works -
 *  orders and complaints are recorded all night - but "talk to someone"
 *  offers a message instead of ringing a phone at somebody's bedside. */
function voice_in_open($now = null) {
    $from = max(0, min(24, (int)setting('voice_shop_open', 9)));
    $to   = max(0, min(24, (int)setting('voice_shop_close', 21)));
    if ($to <= $from) { $from = 9; $to = 21; }
    $h = (int)date('G', $now ?: time());
    return $h >= $from && $h < $to;
}

/** The phones that ring when a caller asks for a person, in order. */
function voice_in_agents() {
    $out = [];
    foreach (preg_split('/[,\s]+/', (string)setting('voice_agent_numbers', '')) as $n) {
        $n = trim($n);
        if ($n !== '' && voice_mobile_ok($n)) $out[] = voice_e164($n);
    }
    return $out;
}

// ---------- telling Vobiz where to send the calls ----------
//
// A Vobiz number does not hold a URL of its own. The URL lives on an
// "Application", and a number is pointed at one. So going live is two calls:
// make the application, then attach the number to it. Both are done from the
// setup screen so the owner never has to open a console.

/** One authenticated request to Vobiz. Returns [decoded body, ''] or [null, why]. */
function voice_api($method, $path, array $body = null) {
    if (!function_exists('curl_init')) return [null, 'The server does not have curl.'];
    $authId = setting('vobiz_auth_id', '');
    $authTok = setting('vobiz_auth_token', '');
    if ($authId === '' || $authTok === '') return [null, 'Vobiz is not configured.'];

    $ch = curl_init('https://api.vobiz.ai/api/v1/Account/' . rawurlencode($authId) . '/' . ltrim($path, '/'));
    $opts = [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Auth-ID: ' . $authId, 'X-Auth-Token: ' . $authTok],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
    ];
    if ($body !== null) $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
    curl_setopt_array($ch, $opts);
    $res = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);

    if ($res === false) return [null, 'Could not reach Vobiz: ' . $cerr];
    $j = json_decode($res, true);
    if ($code < 200 || $code >= 300) {
        $msg = $j['error'] ?? $j['message'] ?? mb_substr((string)$res, 0, 200);
        return [null, 'Vobiz returned HTTP ' . $code . ': ' . (is_string($msg) ? $msg : json_encode($msg))];
    }
    return [is_array($j) ? $j : [], ''];
}

/** Every application on the account, so ours can be recognised rather than
 *  remembered. The reply shape differs between list endpoints, so all three
 *  spellings are accepted. */
function voice_in_apps() {
    [$j, $err] = voice_api('GET', 'Application/?per_page=50');
    if ($j === null) return ['ok' => false, 'error' => $err, 'apps' => []];
    $rows = $j['items'] ?? $j['objects'] ?? $j['applications'] ?? (isset($j[0]) ? $j : []);
    $out = [];
    foreach ($rows as $a) {
        if (!is_array($a)) continue;
        $out[] = [
            'id'   => (string)($a['app_id'] ?? $a['application_id'] ?? $a['id'] ?? ''),
            'name' => (string)($a['app_name'] ?? $a['name'] ?? ''),
            'url'  => (string)($a['answer_url'] ?? ''),
        ];
    }
    return ['ok' => true, 'error' => '', 'apps' => $out];
}

/** The applications that point at THIS software.
 *
 *  Identified by their answer_url, not by an id in a setting. The id in the
 *  setting is only what we last created, and the console can show a
 *  different one - which is exactly what happened: pressing "refresh" made a
 *  second application instead of editing the first, and then the check
 *  looked for a number attached to the one the console was not showing. */
function voice_in_our_apps() {
    $want = voice_public_url('voice_in.php');
    $r = voice_in_apps();
    if (!$r['ok']) return [];
    return array_values(array_filter($r['apps'], fn($a) => $a['url'] === $want && $a['id'] !== ''));
}

/** Create (or re-point) the application that answers our number. */
function voice_in_app_setup() {
    // Adopt one that already points here rather than making another. This
    // used to POST unconditionally, so every press left one more application
    // behind and the stored id drifted away from the one in the console.
    $ours = voice_in_our_apps();
    if ($ours) {
        set_setting('vobiz_app_id', $ours[0]['id']);
        log_activity('voice_app', 'adopted existing application ' . $ours[0]['id']
                                . (count($ours) > 1 ? ' (' . count($ours) . ' point here)' : ''));
        return ['ok' => true, 'error' => '', 'app_id' => $ours[0]['id'], 'adopted' => true,
                'duplicates' => max(0, count($ours) - 1)];
    }
    return voice_in_app_create();
}

function voice_in_app_create() {
    [$j, $err] = voice_api('POST', 'Application/', [
        'app_name'      => preg_replace('/[^A-Za-z0-9_-]/', '-', setting('app_name', 'AK Computer')) . '-inbound',
        'answer_url'    => voice_public_url('voice_in.php'),
        'answer_method' => 'POST',
        'hangup_url'    => voice_public_url('voice_webhook.php'),
        'hangup_method' => 'POST',
    ]);
    if ($j === null) return ['ok' => false, 'error' => $err, 'app_id' => ''];
    $appId = (string)($j['app_id'] ?? $j['application_id'] ?? $j['id'] ?? '');
    if ($appId === '') return ['ok' => false, 'error' => 'Vobiz did not return an application id.', 'app_id' => ''];
    set_setting('vobiz_app_id', $appId);
    log_activity('voice_app', 'application ' . $appId);
    return ['ok' => true, 'error' => '', 'app_id' => $appId];
}

/** The numbers this Vobiz account actually owns.
 *
 *  Worth a screen of its own because the commonest way to get stuck here is
 *  to type the shop's ordinary mobile into the box. A number can only be
 *  attached if Vobiz sold it to you; anything else comes back as a flat
 *  "access denied" that explains nothing. Showing the real list turns a
 *  guess into a choice. */
function voice_in_numbers() {
    [$j, $err] = voice_api('GET', 'numbers?per_page=50');
    if ($j === null) return ['ok' => false, 'error' => $err, 'numbers' => []];
    $out = [];
    foreach (($j['items'] ?? []) as $n) {
        $out[] = [
            'id'       => (string)($n['id'] ?? ''),
            'e164'     => (string)($n['e164'] ?? ''),
            'status'   => (string)($n['status'] ?? ''),
            'voice'    => !empty($n['voice_enabled']) || !empty($n['capabilities']['voice']),
            'app_id'   => (string)($n['application_id'] ?? ''),
            'blocked'  => !empty($n['is_blocked']),
            // India requires the number's holder to be verified before it
            // will carry traffic; an unverified one attaches and then simply
            // does not ring, which is the worst kind of working.
            'kyc_need' => !empty($n['aadhaar_verification_required']),
            'kyc_done' => !empty($n['aadhaar_verified']),
        ];
    }
    return ['ok' => true, 'error' => '', 'numbers' => $out];
}

/** Just the digits, for comparing two numbers written differently.
 *  "+918065354620" and "918065354620" are the same number, and a message
 *  that says otherwise is worse than no message. */
function voice_num_same($a, $b) {
    $d = fn($x) => substr(preg_replace('/\D/', '', (string)$x), -10);
    return $d($a) !== '' && $d($a) === $d($b);
}

/** Point the shop's number at that application.
 *
 *  Vobiz publishes one shape for this and answers "access denied" to it on
 *  at least some accounts - a message that says nothing about what is
 *  actually wrong. Rather than leave the shop with a dead line and a wrong
 *  guess, the documented call is tried first and the shapes this API is
 *  compatible with after it, and every attempt is kept so the screen can
 *  show exactly what Vobiz said to each one.
 *
 *  All four are the same request - attach this number to this application -
 *  written the way four different versions of this API have spelled it.
 *  None of them can do anything else. */
function voice_in_attach_shapes($e164, $appId, $numId = '') {
    $shapes = [
        ['POST', 'numbers/' . rawurlencode('+' . $e164) . '/application', ['application_id' => $appId],
         'documented: POST numbers/%2B<number>/application'],
        ['POST', 'numbers/' . rawurlencode($e164) . '/application', ['application_id' => $appId],
         'same, without the plus'],
        ['POST', 'Number/' . rawurlencode($e164) . '/', ['app_id' => $appId],
         'compatible: POST Number/<number>/ with app_id'],
        ['POST', 'numbers/' . rawurlencode('+' . $e164) . '/application', ['app_id' => $appId],
         'documented path, app_id instead of application_id'],
        // The listing gives each number an id of its own. An API that keys
        // everything else by id may well key this by id too, and the E.164
        // in the path may simply not be what it is looking for.
        ['PUT', 'numbers/' . rawurlencode('+' . $e164) . '/application', ['application_id' => $appId],
         'documented path as a PUT rather than a POST'],
        ['PATCH', 'numbers/' . rawurlencode('+' . $e164), ['application_id' => $appId],
         'update the number itself (PATCH)'],
    ];
    if ($numId !== '') {
        array_splice($shapes, 4, 0, [
            ['POST', 'numbers/' . rawurlencode($numId) . '/application', ['application_id' => $appId],
             'by the number\'s own id, not its digits'],
            ['PATCH', 'numbers/' . rawurlencode($numId), ['application_id' => $appId],
             'update the number by its id (PATCH)'],
        ]);
    }
    return $shapes;
}

function voice_in_number_attach($number, $appId) {
    $e164 = voice_e164($number);

    $mine = voice_in_numbers();
    $owned = $mine['ok'] ? $mine['numbers'] : [];
    $match = array_values(array_filter($owned, fn($n) => voice_num_same($n['e164'], $e164)));
    $isMine = (bool)$match;
    $numId = $match[0]['id'] ?? '';

    $trail = [];
    foreach (voice_in_attach_shapes($e164, $appId, $numId) as [$m, $path, $body, $label]) {
        [$j, $err] = voice_api($m, $path, $body);
        if ($j !== null) {
            set_setting('vobiz_inbound_number', $e164);
            set_setting('vobiz_numbers_cache', '');      // it has an application now
            set_setting('vobiz_attach_shape', $label);   // remember what worked
            log_activity('voice_number', $e164 . ' -> ' . $appId . ' via ' . $label);
            return ['ok' => true, 'error' => '', 'via' => $label, 'trail' => $trail];
        }
        $trail[] = ['tried' => $label, 'said' => $err];
    }

    // Everything refused. Say what is true rather than guess a cause.
    $err = $isMine
        ? 'Vobiz refused every way of attaching ' . $e164 . ' over the API, although the number IS on your '
        . 'account. Do it in the Vobiz console instead — it is the same thing, and it works: '
        . 'Phone Numbers → ' . $e164 . ' → set its Application to ' . $appId . '. '
        . 'Then press "Check my setup" here.'
        : $e164 . ' is not on your Vobiz account. Buy a number under Phone Numbers first.';
    log_activity('voice_number_fail', $e164 . ': ' . count($trail) . ' shapes refused');
    return ['ok' => false, 'error' => $err, 'trail' => $trail];
}

/** May we answer a call made to this number?
 *
 *  This guard exists so a stranger cannot drive the menu by posting to the
 *  URL. Its first version compared against one setting, and that setting is
 *  only written when a number attaches successfully - so until the attach
 *  worked, EVERY real call was refused and the caller heard the line cut
 *  dead. A guard that fails closed on a shop's main number is worse than the
 *  thing it guards against.
 *
 *  So it now matches against every number the Vobiz account owns, not just
 *  the stored one. And when it cannot tell - no keys, no numbers, nothing
 *  configured - it answers anyway, in a limited way: the menu works, but
 *  nothing private is read out. A working line that says less beats a dead
 *  line that says nothing.
 *
 *  Returns ['ok', 'strict', 'why']. strict=false means "answered, but this
 *  call is not proven to be ours, so keep it to public information". */
function voice_in_to_ok($to) {
    $to = voice_e164($to);
    if ($to === '91') return ['ok' => false, 'strict' => false, 'why' => 'the call carried no number to us'];

    $stored = voice_e164(setting('vobiz_inbound_number', ''));
    if ($stored !== '91' && $to === $stored) return ['ok' => true, 'strict' => true, 'why' => ''];

    foreach (voice_in_account_numbers() as $n) {
        if (voice_e164($n) === $to) return ['ok' => true, 'strict' => true, 'why' => ''];
    }

    if ($stored === '91' && !voice_in_account_numbers()) {
        return ['ok' => true, 'strict' => false,
                'why' => 'no number is set up yet, so this call was answered without reading out anything private'];
    }
    return ['ok' => false, 'strict' => false, 'why' => 'the call was to ' . $to . ', which is not one of our numbers'];
}

/** The account's numbers, cached - this is consulted on every incoming call
 *  and must not become an API request per ring. */
function voice_in_account_numbers() {
    $c = json_decode((string)setting('vobiz_numbers_cache', ''), true);
    if (is_array($c) && ($c['at'] ?? 0) > time() - 900) return $c['nums'] ?? [];
    if (!voice_configured()) return [];
    $r = voice_in_numbers();
    $nums = $r['ok'] ? array_values(array_filter(array_column($r['numbers'], 'e164'))) : [];
    if ($r['ok']) set_setting('vobiz_numbers_cache', json_encode(['at' => time(), 'nums' => $nums]));
    return $nums;
}

/** Why an incoming call was refused, most recent first - so a dead line can
 *  be diagnosed from the screen instead of from a guess. */
function voice_in_rejects($limit = 10) {
    return all("SELECT details, created_at FROM activity_log
                WHERE action = 'voice_in_reject' ORDER BY id DESC LIMIT " . (int)$limit);
}

/** Walk the whole chain and say which link is broken. */
function voice_in_diagnose() {
    $out = [];
    $add = function ($name, $ok, $detail) use (&$out) { $out[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail]; };

    $add('The site is on https', voice_public_ok(),
         voice_public_ok() ? base_url('') : 'Vobiz fetches over https only — it cannot reach ' . base_url(''));
    $add('Vobiz keys are saved', voice_configured(), voice_configured() ? 'Auth ID, token and caller ID are in' : 'missing');
    if (!voice_configured()) return $out;

    $appId = setting('vobiz_app_id', '');
    $add('An application exists', $appId !== '', $appId ?: 'press "Make the application"');

    $want = voice_public_url('voice_in.php');
    if ($appId !== '') {
        [$app, $err] = voice_api('GET', 'Application/' . rawurlencode($appId) . '/');
        if ($app === null) {
            $add('Vobiz can see that application', false, $err);
        } else {
            $have = (string)($app['answer_url'] ?? '');
            $add('It points at this software', $have === $want,
                 $have === $want ? $want : 'it points at ' . ($have ?: '(nothing)') . ' — press "Refresh the application"');
        }
    }

    $nums = voice_in_numbers();
    if (!$nums['ok']) { $add('Your numbers can be read', false, $nums['error']); return $out; }
    $add('This account owns a number', (bool)$nums['numbers'],
         $nums['numbers'] ? implode(', ', array_column($nums['numbers'], 'e164'))
                          : 'none — the caller ID you are using for outgoing calls is not the same thing. '
                          . 'Buy a number in the Vobiz console under Phone Numbers, then come back.');

    // A number counts as attached if it points at ANY application whose
    // answer_url is ours - not only the id we happen to have stored. The
    // console can legitimately show a different id for the same setup.
    $ourIds = array_column(voice_in_our_apps(), 'id');
    if ($appId !== '' && !in_array($appId, $ourIds, true)) $ourIds[] = $appId;
    if (count($ourIds) > 1) {
        $add('Only one application points here', false,
             count($ourIds) . ' applications point at this software — delete the spare ones in the Vobiz console, '
             . 'they do no harm but they are confusing: ' . implode(', ', $ourIds));
    }
    $attached = array_values(array_filter($nums['numbers'],
        fn($n) => $n['app_id'] !== '' && in_array($n['app_id'], $ourIds, true)));
    // Name the number to attach, and offer to do it right here. Saying
    // "press Use this one on a number above" is no help when the table above
    // is empty, and not much when it is not.
    $free = array_values(array_filter($nums['numbers'],
        fn($n) => !in_array($n['app_id'], $ourIds, true) && $n['voice'] && !$n['blocked']
                  && (!$n['kyc_need'] || $n['kyc_done'])));
    if ($attached) {
        $add('A number is pointed at this software', true, implode(', ', array_column($attached, 'e164')));
    } elseif ($free) {
        $out[] = ['name' => 'A number is pointed at this software', 'ok' => false,
                  'detail' => $free[0]['e164'] . ' is free — attach it',
                  'attach' => $free[0]['e164']];
    } elseif ($nums['numbers']) {
        $add('A number is pointed at this software', false,
             'none of your numbers can take calls yet — see the reason next to each one above');
    } else {
        $add('A number is pointed at this software', false, 'there is no number to point — buy one first');
    }

    foreach ($attached as $n) {
        $ready = $n['voice'] && !$n['blocked'] && (!$n['kyc_need'] || $n['kyc_done']);
        $add($n['e164'] . ' can take calls', $ready,
             $n['blocked'] ? 'blocked by Vobiz' :
             (!$n['voice'] ? 'this number has no voice capability' :
             (($n['kyc_need'] && !$n['kyc_done']) ? 'KYC not finished — it will accept the attach and then never ring'
                                                  : 'ready')));
    }
    $add('Answering is switched on', voice_in_on(), voice_in_on() ? 'on' : 'tick "Answer incoming calls"');

    $v = voice_in_voice_status(voice_in_lang());
    $add('The menu can speak ' . (voice_langs()[voice_in_lang()] ?? voice_in_lang()), $v['ready'],
         $v['ready'] ? 'all ' . $v['total'] . ' lines are made'
                     : $v['done'] . ' of ' . $v['total'] . ' lines made — callers are hearing English. '
                     . 'Press "Make the menu speak ..." below.');
    return $out;
}

// ---------- everything the menu says ----------

/** One table, three languages. Kept together so a change to the menu is one
 *  edit and the three never drift apart. */
function voice_in_words($lang, array $v = []) {
    $shop = setting('app_name', 'AK Computer');
    $t = [
        'gu' => [
            'welcome'      => 'નમસ્કાર, ' . $shop . ' માં આપનું સ્વાગત છે.',
            'welcome_name' => 'નમસ્કાર {name}, ' . $shop . ' માં આપનું સ્વાગત છે.',
            'closed'       => 'અત્યારે દુકાન બંધ છે.',
            'menu'         => 'હિસાબ જાણવા એક દબાવો. ઓર્ડર આપવા બે દબાવો. ફરિયાદ કે રિપેરિંગ માટે ત્રણ દબાવો. '
                            . 'માલ અને ભાવ પૂછવા ચાર દબાવો. તમારો ઓર્ડર ક્યાં પહોંચ્યો એ જાણવા પાંચ દબાવો. '
                            . 'અમારી સાથે વાત કરવા નવ દબાવો.',
            'again'        => 'કંઈ દબાયું નથી. ફરી સાંભળો.',
            'bye'          => 'ફોન કરવા બદલ આભાર.',
            'not_known'    => 'તમારો નંબર અમારી પાસે નોંધાયેલો નથી. ઓર્ડર કે ફરિયાદ માટે બે કે ત્રણ દબાવો.',
            'balance'      => 'તમારા {amount} બાકી છે.',
            'balance_nil'  => 'તમારું કોઈ બાકી નથી. આભાર.',
            'balance_wa'   => 'તમારો હિસાબ તમારા WhatsApp પર મોકલી દીધો છે.',
            'ask_order'    => 'બીપ પછી તમારે શું જોઈએ છે એ બોલો. પૂરું થાય એટલે હેશ દબાવો.',
            'ask_problem'  => 'બીપ પછી તમારી ફરિયાદ બોલો. પૂરું થાય એટલે હેશ દબાવો.',
            'ask_stock'    => 'બીપ પછી તમારે કઈ વસ્તુનો ભાવ જોઈએ છે એ બોલો. પૂરું થાય એટલે હેશ દબાવો.',
            'noted'        => 'નોંધી લીધું. અમે તમારો સંપર્ક કરીશું. આભાર.',
            'order_status' => 'તમારો છેલ્લો ઓર્ડર {status} છે.',
            'order_none'   => 'તમારો કોઈ ચાલુ ઓર્ડર નથી.',
            'connecting'   => 'જોડી રહ્યા છીએ, થોડી રાહ જુઓ.',
            'no_answer'    => 'અત્યારે કોઈ ફોન ઉપાડતું નથી. બીપ પછી સંદેશ મૂકો.',
            'closed_msg'   => 'દુકાન બંધ છે. બીપ પછી સંદેશ મૂકો, અમે સવારે સંપર્ક કરીશું.',
        ],
        'hi' => [
            'welcome'      => 'नमस्ते, ' . $shop . ' में आपका स्वागत है.',
            'welcome_name' => 'नमस्ते {name}, ' . $shop . ' में आपका स्वागत है.',
            'closed'       => 'अभी दुकान बंद है.',
            'menu'         => 'हिसाब जानने के लिए एक दबाएँ. ऑर्डर देने के लिए दो दबाएँ. शिकायत या रिपेयरिंग के लिए तीन दबाएँ. '
                            . 'सामान और दाम पूछने के लिए चार दबाएँ. अपना ऑर्डर कहाँ पहुँचा जानने के लिए पाँच दबाएँ. '
                            . 'हमसे बात करने के लिए नौ दबाएँ.',
            'again'        => 'कुछ नहीं दबाया गया. फिर से सुनिए.',
            'bye'          => 'फ़ोन करने के लिए धन्यवाद.',
            'not_known'    => 'आपका नंबर हमारे पास दर्ज नहीं है. ऑर्डर या शिकायत के लिए दो या तीन दबाएँ.',
            'balance'      => 'आपका {amount} बाकी है.',
            'balance_nil'  => 'आपका कोई बकाया नहीं है. धन्यवाद.',
            'balance_wa'   => 'आपका हिसाब आपके WhatsApp पर भेज दिया है.',
            'ask_order'    => 'बीप के बाद बताइए आपको क्या चाहिए. पूरा होने पर हैश दबाएँ.',
            'ask_problem'  => 'बीप के बाद अपनी शिकायत बताइए. पूरा होने पर हैश दबाएँ.',
            'ask_stock'    => 'बीप के बाद बताइए किस सामान का दाम चाहिए. पूरा होने पर हैश दबाएँ.',
            'noted'        => 'दर्ज कर लिया. हम आपसे संपर्क करेंगे. धन्यवाद.',
            'order_status' => 'आपका पिछला ऑर्डर {status} है.',
            'order_none'   => 'आपका कोई चालू ऑर्डर नहीं है.',
            'connecting'   => 'जोड़ रहे हैं, थोड़ा इंतज़ार करें.',
            'no_answer'    => 'अभी कोई फ़ोन नहीं उठा रहा. बीप के बाद संदेश छोड़ें.',
            'closed_msg'   => 'दुकान बंद है. बीप के बाद संदेश छोड़ें, हम सुबह संपर्क करेंगे.',
        ],
        'en' => [
            'welcome'      => 'Hello, welcome to ' . $shop . '.',
            'welcome_name' => 'Hello {name}, welcome to ' . $shop . '.',
            'closed'       => 'The shop is closed right now.',
            'menu'         => 'For your account balance press one. To place an order press two. '
                            . 'For a complaint or a repair press three. To ask about stock or a price press four. '
                            . 'To check your order press five. To speak to us press nine.',
            'again'        => 'Nothing was pressed. Here is the menu again.',
            'bye'          => 'Thank you for calling.',
            'not_known'    => 'Your number is not registered with us. For an order or a complaint press two or three.',
            'balance'      => 'Your outstanding amount is {amount}.',
            'balance_nil'  => 'You have nothing outstanding. Thank you.',
            'balance_wa'   => 'Your statement has been sent to your WhatsApp.',
            'ask_order'    => 'After the beep, say what you need. Press hash when you are done.',
            'ask_problem'  => 'After the beep, describe the problem. Press hash when you are done.',
            'ask_stock'    => 'After the beep, say which item you want the price of. Press hash when you are done.',
            'noted'        => 'Noted. We will get back to you. Thank you.',
            'order_status' => 'Your last order is {status}.',
            'order_none'   => 'You have no order in progress.',
            'connecting'   => 'Connecting you, please hold.',
            'no_answer'    => 'Nobody is picking up right now. Please leave a message after the beep.',
            'closed_msg'   => 'The shop is closed. Leave a message after the beep and we will call in the morning.',
        ],
    ];
    $s = $t[$lang] ?? $t['en'];
    if (!$v) return $s;
    foreach ($s as $k => $line) foreach ($v as $vk => $vv) $s[$k] = str_replace('{' . $vk . '}', (string)$vv, $s[$k]);
    return $s;
}

/** English of the same line, for the fallback when the Gujarati audio is not
 *  ready. Written as one call so no caller ever hears a gap. */
function voice_in_en($key, array $v = []) { return voice_in_words('en', $v)[$key] ?? ''; }

// ---------- the call row ----------

/** Start (or find) the row for an incoming call.
 *
 *  Found rather than always created, because Vobiz retries a webhook that
 *  did not answer 200 - and a retried greeting must not become a second call
 *  in the shop's records. */
function voice_in_start($from, $to, $callUuid) {
    $from = voice_e164($from);
    if ($callUuid !== '') {
        $existing = row('SELECT * FROM voice_calls WHERE call_uuid = ? AND direction = ?', [$callUuid, 'in']);
        if ($existing) return $existing;
    }
    $party = wa_bot_party_for($from);
    $token = bin2hex(random_bytes(20));
    q("INSERT INTO voice_calls (party_id, mobile, direction, from_number, to_number, call_uuid, token,
                                lang, provider, status, answered, started_at)
       VALUES (?,?,'in',?,?,?,?,?,?,'answered',1,NOW())",
      [(int)($party['id'] ?? 0), $from, $from, voice_e164($to), $callUuid ?: null, $token,
       voice_in_lang(), setting('voice_provider', 'vobiz')]);
    $id = (int)insert_id();
    log_activity('voice_in', 'incoming from ' . $from . ($party ? ' (' . $party['name'] . ')' : ' (unknown)'));
    return row('SELECT * FROM voice_calls WHERE id = ?', [$id]);
}

/** Which shop a call belongs to.
 *
 *  tickets.location_id and leads.location_id are NOT NULL with no default,
 *  and a call has no logged-in user to take one from. The configured inbound
 *  location wins; otherwise the first location, which is the only one most
 *  shops have. Getting this wrong meant every spoken order was silently
 *  dropped - the recording survived, but the lead it should have become
 *  never appeared. */
function voice_in_location() {
    $l = (int)setting('voice_inbound_location', 0);
    if ($l > 0) return $l;
    return (int)val('SELECT id FROM locations ORDER BY id LIMIT 1') ?: 1;
}

/** Whose name a record made by the phone goes under.
 *
 *  tickets.created_by and leads.created_by are NOT NULL, and a call has
 *  nobody logged in. The owner's account stands in - they are the one
 *  answerable for what the shop's phone promises - and the note on the
 *  record says plainly that it came off the phone, so nobody reading it
 *  later thinks the owner typed it. */
function voice_in_actor() {
    static $id = null;
    if ($id === null) {
        $id = (int)val("SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id
                        WHERE u.is_active = 1 AND r.permissions LIKE '%*%' ORDER BY u.id LIMIT 1");
        if (!$id) $id = (int)val('SELECT id FROM users WHERE is_active = 1 ORDER BY id LIMIT 1');
    }
    return $id ?: null;
}

function voice_in_log($callId, $step, $digit = null, $detail = null) {
    q('INSERT INTO voice_ivr_events (call_id, step, digit, detail) VALUES (?,?,?,?)',
      [(int)$callId, mb_substr((string)$step, 0, 30), $digit !== '' ? mb_substr((string)$digit, 0, 4) : null,
       $detail !== null ? mb_substr((string)$detail, 0, 255) : null]);
    // the path is kept on the row too, so the list screen needs no join
    $path = (string)val('SELECT ivr_path FROM voice_calls WHERE id = ?', [(int)$callId]);
    if ($digit !== null && $digit !== '') {
        $path = trim($path . '-' . $digit, '-');
        q('UPDATE voice_calls SET ivr_path = ? WHERE id = ?', [mb_substr($path, 0, 60), (int)$callId]);
    }
}

/** Mark what the caller came for, and whether somebody must act on it. */
function voice_in_intent($callId, $intent, $needsAction, $refType = null, $refId = null) {
    q('UPDATE voice_calls SET intent = ?, needs_action = ?, ref_type = ?, ref_id = ? WHERE id = ?',
      [$intent, $needsAction ? 1 : 0, $refType, $refId, (int)$callId]);
}

// ---------- XML helpers ----------

/** Say one line: the cached Gujarati file if it exists, English otherwise.
 *
 *  $vEn is the same substitutions written for the English fallback. Without
 *  it a line that fell back to English still carried the Gujarati values
 *  that were put into it, and the caller heard "Your outstanding amount is
 *  નવ હજાર રૂપિયા" - half a sentence in each language, which is worse than
 *  either one whole. When it is not given the values are used as they are,
 *  which is right for names and wrong for nothing else. */
function voice_in_say($key, $lang, array $v = [], $live = false, array $vEn = null, $fallbackKey = null) {
    $line = voice_in_words($lang, $v)[$key] ?? '';
    $en = voice_in_en($key, $vEn ?? $v);
    $say = $live ? voice_say_now($line, $lang, $en) : voice_say_live($line, $lang, $en);
    if ($say['url']) return voice_xml_play($say['url']);

    // Before dropping to English, try the plainer version of the same line in
    // the SAME language. The greeting with the customer's name in it cannot
    // be made in advance - there is one per customer - but the greeting
    // without it can, and a Gujarati "hello, welcome" is a better answer than
    // an English "hello, Ramesh, welcome". Language first, politeness second.
    if ($fallbackKey !== null) {
        $alt = voice_say_live(voice_in_words($lang)[$fallbackKey] ?? '', $lang, voice_in_en($fallbackKey));
        if ($alt['url']) return voice_xml_play($alt['url']);
    }
    return voice_xml_speak($say['text']);
}

/** The lines that never change, so they can be made once and then cost
 *  nothing. Everything with a {placeholder} is left out - those are made per
 *  call, on a short leash. */
function voice_in_fixed_keys() {
    return array_values(array_filter(array_keys(voice_in_words('en')),
        fn($k) => strpos(voice_in_words('en')[$k], '{') === false));
}

/** Is the incoming menu ready to speak this language? */
function voice_in_voice_status($lang) {
    if ($lang === 'en') return ['ready' => true, 'done' => 0, 'total' => 0, 'missing' => []];
    $missing = [];
    foreach (voice_in_fixed_keys() as $k) {
        $line = voice_in_words($lang)[$k] ?? '';
        if ($line === '') continue;
        if (!voice_tts_audio($line, $lang, true)['ok']) $missing[] = $k;
    }
    $total = count(voice_in_fixed_keys());
    return ['ready' => !$missing, 'done' => $total - count($missing), 'total' => $total, 'missing' => $missing];
}

/** Make every fixed line of the incoming menu, once.
 *
 *  This exists because an incoming call has no "before". An outgoing call is
 *  prepared while the owner is looking at a confirmation screen; a customer
 *  ringing the shop arrives unannounced, and the answer URL has seconds to
 *  reply - far too few to make speech. Without this the menu had no Gujarati
 *  to play and fell back to English on every single call. */
function voice_in_pregenerate($lang) {
    if ($lang === 'en') return ['ok' => true, 'made' => 0, 'failed' => 0, 'error' => ''];
    $made = 0; $failed = 0; $err = '';
    foreach (voice_in_fixed_keys() as $k) {
        $line = voice_in_words($lang)[$k] ?? '';
        if ($line === '') continue;
        $r = voice_tts_audio($line, $lang);
        if ($r['ok']) { if (!$r['cached']) $made++; }
        else { $failed++; if ($err === '') $err = $r['error']; }
    }
    log_activity('voice_in_voice', $lang . ': ' . $made . ' made, ' . $failed . ' failed');
    return ['ok' => $failed === 0, 'made' => $made, 'failed' => $failed, 'error' => $err];
}

function voice_in_url($call, $step) {
    return voice_public_url('voice_in.php?step=' . $step . '&t=' . $call['token']);
}

function voice_in_xml($body) {
    return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<Response>\n" . $body . "</Response>\n";
}

// ---------- the menu itself ----------

/** The greeting and the main menu. $try=2 is the one repeat before hanging up. */
function voice_in_menu_xml($call, $try = 1, $now = null) {
    $lang = $call['lang'];
    $party = (int)$call['party_id'] > 0 ? row('SELECT name FROM parties WHERE id = ?', [(int)$call['party_id']]) : null;

    $body = '';
    if ($try === 1) {
        $body .= $party
            ? voice_in_say('welcome_name', $lang, ['name' => $party['name']], false, null, 'welcome')
            : voice_in_say('welcome', $lang);
        if (!voice_in_open($now)) $body .= voice_in_say('closed', $lang);
    } else {
        $body .= voice_in_say('again', $lang);
    }

    $body .= '  <Gather action="' . htmlspecialchars(voice_in_url($call, 'menu') . '&try=' . $try, ENT_XML1)
           . '" method="POST" inputType="dtmf" numDigits="1" finishOnKey="none" executionTimeout="8">' . "\n";
    $body .= '  ' . voice_in_say('menu', $lang);
    $body .= "  </Gather>\n";

    // Nothing pressed: one more go, then goodbye. Falling through the Gather
    // is how "pressed nothing" is handled - there is no separate timeout URL.
    $body .= $try === 1
        ? '  <Redirect>' . htmlspecialchars(voice_in_url($call, 'menu') . '&try=2&Digits=', ENT_XML1) . "</Redirect>\n"
        : voice_in_say('bye', $lang);
    return voice_in_xml($body);
}

/** What one keypress leads to. Returns the XML to answer with. */
function voice_in_branch($call, $digit, $now = null) {
    $lang = $call['lang'];
    $known = (int)$call['party_id'] > 0;

    switch ($digit) {
        case '1':   // their own account
            voice_in_log($call['id'], 'balance', $digit);
            if (!$known) { voice_in_intent($call['id'], 'balance', 1); voice_in_wa_queue($call['id'], 'menu');
                           return voice_in_xml(voice_in_say('not_known', $lang)); }
            voice_in_wa_queue($call['id'], 'stmt');   // the figures, as something they can keep
            return voice_in_xml(voice_in_balance_xml($call));

        case '2':   // place an order
            voice_in_log($call['id'], 'order', $digit);
            voice_in_intent($call['id'], 'order', 1);
            voice_in_wa_queue($call['id'], 'order');
            return voice_in_xml(voice_in_record_xml($call, 'ask_order', 'order'));

        case '3':   // complaint or repair
            voice_in_log($call['id'], 'complaint', $digit);
            voice_in_intent($call['id'], 'complaint', 1);
            voice_in_wa_queue($call['id'], 'ticket');
            return voice_in_xml(voice_in_record_xml($call, 'ask_problem', 'complaint'));

        case '4':   // stock or price
            voice_in_log($call['id'], 'stock', $digit);
            voice_in_intent($call['id'], 'stock', 1);
            voice_in_wa_queue($call['id'], 'catalog');
            return voice_in_xml(voice_in_record_xml($call, 'ask_stock', 'stock'));

        case '5':   // where is my order
            voice_in_log($call['id'], 'delivery', $digit);
            voice_in_intent($call['id'], 'delivery', $known ? 0 : 1);
            if (!$known) { voice_in_wa_queue($call['id'], 'menu'); return voice_in_xml(voice_in_say('not_known', $lang)); }
            voice_in_wa_queue($call['id'], 'orders');
            return voice_in_xml(voice_in_order_status_xml($call));

        case '9':   // a person
            voice_in_log($call['id'], 'agent', $digit);
            voice_in_intent($call['id'], 'agent', 1);
            return voice_in_xml(voice_in_agent_xml($call, $now));

        default:
            voice_in_log($call['id'], 'menu_unknown', $digit);
            return voice_in_menu_xml($call, 2, $now);
    }
}

/** Their balance, read out or sent to WhatsApp - the owner's choice.
 *
 *  voice_ivr_balance:
 *    speak     - read the figure to whoever is on the line (default)
 *    whatsapp  - send the statement to the registered number instead, which
 *                needs the actual SIM rather than a forged caller ID
 *    off       - do not answer money questions by phone at all */
function voice_in_balance_xml($call) {
    $lang = $call['lang'];
    $mode = setting('voice_ivr_balance', 'speak');
    if ($mode === 'off') return voice_in_say('not_known', $lang);

    $due = round((float)party_balance_side((int)$call['party_id'], 'in'), 2);
    voice_in_log($call['id'], 'balance_read', null, '₹' . money($due));
    if ($due <= 0.009) return voice_in_say('balance_nil', $lang);

    if ($mode === 'whatsapp') {
        require_once __DIR__ . '/wa_portal.php';
        wa_portal_route($call['from_number'], 'portal:stmt');
        return voice_in_say('balance_wa', $lang);
    }

    // The one line of a call that cannot be prepared in advance, so it is the
    // one allowed to generate - on a short leash, English underneath. The
    // amount is written out twice, once per language, so a sentence that
    // falls back to English falls back whole.
    $tokens = voice_amount_tokens($due);
    return voice_in_say('balance', $lang, ['amount' => voice_tokens_text($tokens, $lang)], true,
                        ['amount' => voice_tokens_text($tokens, 'en') ])
         . voice_in_say('bye', $lang);
}

/** Where their last order or unfinished repair has got to. */
function voice_in_order_status_xml($call) {
    $lang = $call['lang'];
    $pid = (int)$call['party_id'];
    // web_orders is keyed by the mobile the order was placed with, not by
    // party_id - it has no such column, because an order can be placed by
    // somebody who is not a party yet.
    $last10 = substr(preg_replace('/\D/', '', (string)$call['from_number']), -10);
    $o = row("SELECT status FROM web_orders
              WHERE mobile <> '' AND RIGHT(REPLACE(REPLACE(mobile,'+',''),' ',''), 10) = ?
              ORDER BY id DESC LIMIT 1", [$last10]);
    if (!$o) {
        // nothing ordered online - a repair they are waiting for is the other
        // thing "where is my thing" usually means
        $r = row("SELECT status FROM repairs WHERE party_id = ? AND status <> 'delivered' ORDER BY id DESC LIMIT 1", [$pid]);
        if (!$r) return voice_in_say('order_none', $lang);
        $o = ['status' => $r['status']];
    }
    voice_in_log($call['id'], 'status_read', null, (string)$o['status']);
    return voice_in_say('order_status', $lang, ['status' => voice_in_status_word($o['status'], $lang)], true,
                        ['status' => voice_in_status_word($o['status'], 'en')])
         . voice_in_say('bye', $lang);
}

/** Status codes are English words in the database; these are what a customer
 *  should actually hear. */
function voice_in_status_word($status, $lang) {
    $map = [
        'gu' => ['new' => 'મળી ગયો છે', 'confirmed' => 'નક્કી થઈ ગયો છે', 'packed' => 'તૈયાર છે',
                 'ready' => 'તૈયાર છે', 'shipped' => 'નીકળી ગયો છે', 'delivered' => 'પહોંચી ગયો છે',
                 'cancelled' => 'રદ થયો છે', 'pending' => 'ચાલુ છે', 'in_progress' => 'ચાલુ છે',
                 'done' => 'તૈયાર છે'],
        'en' => ['new' => 'received', 'confirmed' => 'confirmed', 'packed' => 'ready',
                 'ready' => 'ready', 'shipped' => 'on its way', 'delivered' => 'delivered',
                 'cancelled' => 'cancelled', 'pending' => 'in progress', 'in_progress' => 'in progress',
                 'done' => 'ready'],
        'hi' => ['new' => 'मिल गया है', 'confirmed' => 'तय हो गया है', 'packed' => 'तैयार है',
                 'ready' => 'तैयार है', 'shipped' => 'निकल गया है', 'delivered' => 'पहुँच गया है',
                 'cancelled' => 'रद्द हो गया है', 'pending' => 'चालू है', 'in_progress' => 'चालू है',
                 'done' => 'तैयार है'],
    ];
    $s = strtolower(trim((string)$status));
    return $map[$lang][$s] ?? $s;
}

/** Ask them to speak, and record it. The recording arrives later at the
 *  callback; the caller is not kept waiting for it. */
function voice_in_record_xml($call, $promptKey, $what) {
    $lang = $call['lang'];
    $body = voice_in_say($promptKey, $lang);
    $body .= '  <Record action="' . htmlspecialchars(voice_in_url($call, 'rec_start') . '&what=' . $what, ENT_XML1) . '"'
           . ' method="POST"'
           . ' callbackUrl="' . htmlspecialchars(voice_in_url($call, 'rec_done') . '&what=' . $what, ENT_XML1) . '"'
           . ' callbackMethod="POST" maxLength="120" timeout="8" finishOnKey="#"'
           . " playBeep=\"true\" fileFormat=\"mp3\" redirect=\"false\"/>\n";
    $body .= voice_in_say('noted', $lang);
    return $body;
}

/** Put them through to a person - or take a message when nobody can answer. */
function voice_in_agent_xml($call, $now = null) {
    $lang = $call['lang'];
    $agents = voice_in_agents();

    if (!$agents || !voice_in_open($now)) {
        return (voice_in_open($now) ? voice_in_say('no_answer', $lang) : voice_in_say('closed_msg', $lang))
             . voice_in_record_xml($call, 'bye', 'message');
    }
    $body = voice_in_say('connecting', $lang);
    $body .= '  <Dial callerId="' . htmlspecialchars(voice_e164(setting('vobiz_inbound_number', '')), ENT_XML1) . '"'
           . ' timeout="' . max(10, (int)setting('voice_agent_timeout', 25)) . '"'
           . ' action="' . htmlspecialchars(voice_in_url($call, 'dial_done'), ENT_XML1) . '" method="POST">' . "\n";
    foreach ($agents as $a) $body .= '    <Number>' . htmlspecialchars($a, ENT_XML1) . "</Number>\n";
    $body .= "  </Dial>\n";
    return $body;
}

/** Nobody picked up: take a message rather than drop the caller. */
function voice_in_dial_done_xml($call, array $p) {
    $status = strtolower((string)($p['DialStatus'] ?? ''));
    voice_in_log($call['id'], 'dial_done', null, $status);
    if ($status === 'completed') {
        // a person handled it, so it is not left on the follow-up list
        q('UPDATE voice_calls SET needs_action = 0 WHERE id = ?', [$call['id']]);
        return voice_in_xml('');
    }
    return voice_in_xml(voice_in_say('no_answer', $call['lang']) . voice_in_record_xml($call, 'bye', 'message'));
}

/** The recording is ready. Attach it to the call and turn the call into work
 *  the shop actually tracks - a lead, a ticket - so it does not live only
 *  inside a list of phone calls nobody opens. */
function voice_in_recording_done($call, array $p, $what) {
    $url = (string)($p['RecordUrl'] ?? $p['RecordFile'] ?? '');
    $secs = (int)round((float)($p['RecordingDuration'] ?? 0));
    if ($url === '') return;
    q('UPDATE voice_calls SET recording_url = ?, recording_secs = ? WHERE id = ?',
      [mb_substr($url, 0, 255), $secs, $call['id']]);
    voice_in_log($call['id'], 'recorded', null, $secs . 's');

    $who = $call['party_id'] ? (string)val('SELECT name FROM parties WHERE id = ?', [(int)$call['party_id']]) : '';
    $label = ['order' => 'Order by phone', 'complaint' => 'Complaint by phone',
              'stock' => 'Price/stock question by phone', 'message' => 'Message on the shop phone'][$what] ?? 'Phone call';
    $note = $label . ' — ' . ($who ?: $call['from_number']) . ' · ' . $secs . 's · ' . $url;

    try {
        // Numbered the way tickets.php and leads.php number them - insert,
        // then stamp doc_no() with the new id - so a record born on the phone
        // is indistinguishable from one typed at the counter.
        if ($what === 'complaint') {
            q("INSERT INTO tickets (party_id, customer_name, customer_mobile, subject, description, priority, status, location_id, created_by)
               VALUES (?,?,?,?,?,'medium','open',?,?)",
              [(int)$call['party_id'] ?: null, $who ?: 'Phone caller', $call['from_number'], $label, $note, voice_in_location(), voice_in_actor()]);
            $tid = (int)insert_id();
            q('UPDATE tickets SET ticket_no = ? WHERE id = ?', [doc_no('TKT', $tid), $tid]);
            voice_in_intent($call['id'], $what, 1, 'ticket', $tid);
        } else {
            q("INSERT INTO leads (name, mobile, source, notes, status, party_id, location_id, created_by)
               VALUES (?,?,'phone',?,'new',?,?,?)",
              [$who ?: 'Phone caller', $call['from_number'], $note, (int)$call['party_id'] ?: null, voice_in_location(), voice_in_actor()]);
            $lid = (int)insert_id();
            q('UPDATE leads SET lead_no = ? WHERE id = ?', [doc_no('LED', $lid), $lid]);
            voice_in_intent($call['id'], $what, 1, 'lead', $lid);
        }
    } catch (Exception $e) {
        // the recording is safely on the call row either way - never lose a
        // customer's words because a follow-up table moved
        voice_in_log($call['id'], 'ref_failed', null, mb_substr($e->getMessage(), 0, 200));
    }
}

// ---------- the calls that still need somebody ----------

function voice_in_pending($limit = 50) {
    return all("SELECT v.*, p.name FROM voice_calls v LEFT JOIN parties p ON p.id = v.party_id
                WHERE v.direction = 'in' AND v.needs_action = 1
                ORDER BY v.id DESC LIMIT " . (int)$limit);
}
function voice_in_pending_count() {
    return (int)val("SELECT COUNT(*) FROM voice_calls WHERE direction = 'in' AND needs_action = 1");
}
function voice_call_handled($callId, $note = '') {
    q('UPDATE voice_calls SET needs_action = 0, handled_by = ?, handled_at = NOW(), notes = ? WHERE id = ?',
      [$_SESSION['user_id'] ?? null, mb_substr((string)$note, 0, 500), (int)$callId]);
    log_activity('voice_handled', 'call ' . (int)$callId);
}

function voice_intent_label($i) {
    return ['balance' => '💰 Balance', 'order' => '🛒 Order', 'complaint' => '🔧 Complaint',
            'stock' => '🏷 Price / stock', 'delivery' => '🚚 Delivery', 'agent' => '🙋 Wanted a person',
            'message' => '💬 Message'][$i] ?? ($i ? ucfirst($i) : '—');
}

// ---------- the phone asks, WhatsApp answers ----------
//
// A statement read down a phone is useless: nobody writes down eleven bills
// from memory, and being unable to is why people ring instead of looking it
// up. So the call takes the question and WhatsApp delivers the answer as
// something the customer can keep, scroll back to, and act on.
//
// Nothing is sent while the caller is on the line. Sending a WhatsApp is an
// HTTP request to Meta, and the answer URL has seconds to reply - the same
// trap as making speech mid-call. The call writes down what to send; the
// hangup callback sends it, and a cron sweep catches any call whose hangup
// never arrived.

/** Note what this caller should receive once the call ends. */
function voice_in_wa_queue($callId, $what) {
    if ((int)setting('voice_wa_followup', 1) !== 1) return;
    q('UPDATE voice_calls SET wa_send = ? WHERE id = ? AND wa_sent_at IS NULL',
      [mb_substr((string)$what, 0, 20), (int)$callId]);
}

/** Send it. Returns what was sent, or '' if there was nothing to send.
 *
 *  Marked as sent BEFORE sending, not after: a hangup callback and the cron
 *  sweep can arrive at the same moment, and a customer getting their
 *  statement twice is a worse failure than getting it once late. */
function voice_in_wa_flush($call) {
    $what = (string)($call['wa_send'] ?? '');
    if ($what === '' || !empty($call['wa_sent_at'])) return '';
    $mobile = (string)($call['from_number'] ?: $call['mobile']);
    if (strlen(preg_replace('/\D/', '', $mobile)) < 10) return '';

    q('UPDATE voice_calls SET wa_sent_at = NOW() WHERE id = ? AND wa_sent_at IS NULL', [(int)$call['id']]);
    if (!db()->query('SELECT ROW_COUNT()')->fetchColumn()) return '';   // somebody else got there first

    require_once __DIR__ . '/wa_portal.php';
    $shop = setting('app_name', 'AK Computer');
    $known = (int)$call['party_id'] > 0;

    try {
        switch ($what) {
            case 'stmt':                       // their ledger, as a document
                if ($known) { wa_portal_route($mobile, 'portal:stmt'); return 'stmt'; }
                break;
            case 'orders':                     // where their order has got to
                if ($known) { wa_portal_route($mobile, 'portal:orders'); return 'orders'; }
                break;
            case 'ticket':                     // the complaint's number
                $ref = (int)($call['ref_id'] ?? 0);
                $t = $ref ? row('SELECT ticket_no, subject FROM tickets WHERE id = ?', [$ref]) : null;
                send_whatsapp($mobile, "🔧 *" . $shop . "*\n\n"
                    . ($t && $t['ticket_no'] ? "તમારી ફરિયાદ નોંધાઈ ગઈ છે — *" . $t['ticket_no'] . "*\n\n" : "તમારી ફરિયાદ નોંધાઈ ગઈ છે.\n\n")
                    . "અમે સાંભળીને તમારો સંપર્ક કરીશું. 🙏");
                return 'ticket';
            case 'order':                      // we heard your order
                send_whatsapp($mobile, "🛒 *" . $shop . "*\n\n"
                    . "તમારો ઓર્ડર ફોન પર નોંધાઈ ગયો છે. અમે સાંભળીને પુષ્ટિ માટે સંપર્ક કરીશું.\n\n"
                    . "આખો કેટલોગ અહીં જોઈ શકો છો:\n" . base_url('catalog.php'));
                return 'order';
            case 'catalog':                    // the price they asked about
                send_whatsapp($mobile, "🏷 *" . $shop . "*\n\n"
                    . "તમે પૂછેલી વસ્તુ વિશે અમે સંપર્ક કરીશું.\n\n"
                    . "દરમિયાન ભાવ અને સ્ટોક અહીં જોઈ શકો છો:\n" . base_url('catalog.php')
                    . "\n\nઅહીં *કેટલોગ* લખીને પણ પૂછી શકો છો.");
                return 'catalog';
            case 'menu':                       // we could not place them
                wa_portal_route($mobile, 'portal:menu');
                return 'menu';
        }
    } catch (Exception $e) {
        log_activity('voice_wa_fail', 'call ' . (int)$call['id'] . ': ' . mb_substr($e->getMessage(), 0, 200));
        return '';
    }
    return '';
}

/** Calls that ended without their follow-up going out - a hangup callback
 *  that never arrived, or arrived before the recording did. */
function voice_in_wa_flush_pending($olderThanSecs = 120, $limit = 30) {
    $rows = all("SELECT * FROM voice_calls
                 WHERE wa_send IS NOT NULL AND wa_sent_at IS NULL
                   AND created_at < DATE_SUB(NOW(), INTERVAL ? SECOND)
                 ORDER BY id LIMIT " . (int)$limit, [(int)$olderThanSecs]);
    $n = 0;
    foreach ($rows as $r) if (voice_in_wa_flush($r) !== '') $n++;
    return $n;
}

// ---------- what would this caller hear? ----------

/** Play the whole thing out on paper for one number.
 *
 *  Built because "it says I owe nothing when I owe three hundred" is
 *  impossible to chase from the outside: is the number matched to the wrong
 *  customer, matched to nobody, or matched correctly to a ledger that really
 *  does read zero? This answers that in one press, without ringing anybody. */
function voice_in_preview($mobile) {
    $e164 = voice_e164($mobile);
    $party = wa_bot_party_for($e164);
    $lang = voice_in_lang();
    $out = [
        'mobile' => $e164,
        'party'  => $party ? ['id' => (int)$party['id'], 'name' => $party['name'], 'mobile' => $party['mobile']] : null,
        'others' => [],
        'due'    => null,
        'lines'  => [],
    ];

    // Every party whose number ends the same way - two records for one person
    // is the commonest reason the wrong ledger is read out.
    $last10 = substr(preg_replace('/\D/', '', $e164), -10);
    $out['others'] = all("SELECT id, name, mobile, " . party_balance_side_expr('p', 'in') . " due
                          FROM parties p
                          WHERE mobile <> '' AND RIGHT(REPLACE(REPLACE(mobile,'+',''),' ',''), 10) = ?
                          ORDER BY id", [$last10]);

    // A party whose "mobile" is shorter than ten digits matches ANY caller
    // whose number happens to end with it, because the match is on the last
    // ten digits of what is stored. One bad record can therefore answer
    // other people's calls with the wrong ledger.
    $out['short'] = all("SELECT id, name, mobile FROM parties
                         WHERE mobile <> '' AND CHAR_LENGTH(REPLACE(REPLACE(mobile,'+',''),' ','')) BETWEEN 1 AND 9
                         ORDER BY id LIMIT 10");

    $w = voice_in_words($lang, ['name' => $party['name'] ?? '']);
    $out['lines']['greeting'] = $party ? $w['welcome_name'] : $w['welcome'];
    $out['lines']['menu'] = $w['menu'];

    if (!$party) {
        $out['lines']['press_1'] = $w['not_known'];
        return $out;
    }
    $due = round((float)party_balance_side((int)$party['id'], 'in'), 2);
    $out['due'] = $due;
    $mode = setting('voice_ivr_balance', 'speak');
    $out['lines']['press_1'] = $mode === 'off' ? $w['not_known']
        : ($due <= 0.009 ? $w['balance_nil']
        : ($mode === 'whatsapp' ? $w['balance_wa']
        : voice_in_words($lang, ['amount' => voice_tokens_text(voice_amount_tokens($due), $lang)])['balance']));
    return $out;
}
