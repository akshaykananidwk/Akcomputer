<?php
/**
 * CONNECT CALL — ring the shopkeeper first, then the customer.
 *
 * Every other call in this software is the software talking. This one is a
 * person talking, and the only thing the software does is put the two ends
 * together through the shop's own line.
 *
 * Why not just tap the number and dial it from the handset? Because then the
 * customer has that handset's number. They ring it at eleven at night, they
 * ring it after that person has left the shop, and nothing about the call is
 * in the shop's records. Here:
 *
 *   1. the provider rings the person who pressed the button, on the mobile
 *      their own user account carries
 *   2. when they pick up, it dials the customer with the SHOP's caller ID
 *   3. both legs are one call, so one recording holds the whole thing -
 *      including our own side, which a recording made from the customer's
 *      leg alone would miss
 *
 * The staff member's number is sent to the provider to be rung and to nobody
 * else. It is never the caller ID.
 */

require_once __DIR__ . '/voice.php';

function voice_bridge_on() { return (int)setting('voice_bridge', 1) === 1; }
function voice_bridge_records() { return (int)setting('voice_bridge_record', 1) === 1; }
function voice_bridge_ring() { return max(10, min(120, (int)setting('voice_bridge_ring', 35))); }

/**
 * May this person connect to this customer right now, and if not, why not.
 *
 * NOTE WHICH RULES DO NOT APPLY HERE, and why.
 *
 * The collection throttles - the days between reminders, the monthly
 * maximum, the six-hour gap, the calling hours, the daily cap - all exist to
 * stop the SOFTWARE pestering somebody while nobody is watching. A shopkeeper
 * deciding to ring a customer is not that, and a screen that says "you may
 * not telephone your own customer until Thursday" is a screen people work
 * around by using their own phone, which is the very thing this exists to
 * prevent.
 *
 * What does apply is the customer's own wish. A do-not-call flag is not a
 * throttle; it is an answer, and no button overrides it.
 */
function voice_bridge_can($partyId, $ctx = null, $user = null) {
    if (!voice_bridge_on())  return ['ok' => false, 'why' => 'Connect calls are switched off (Settings → Reminder Calls)'];
    if (!voice_configured()) return ['ok' => false, 'why' => 'Vobiz is not set up yet — Auth ID, token and caller ID are needed'];

    $u = $user ?: current_user();
    if (!$u) return ['ok' => false, 'why' => 'Not signed in'];
    // The whole call starts by ringing this person. Without a number there is
    // nothing to ring, and the message has to say where to put one - "no
    // mobile number" on its own sends somebody looking at the customer.
    if (!voice_mobile_ok($u['mobile'] ?? ''))
        return ['ok' => false, 'why' => 'Your own mobile number is missing — add it in My Account. The call rings you first, so there has to be a number to ring.'];

    $c = $ctx ?: voice_party_context($partyId);
    if (!$c) return ['ok' => false, 'why' => 'No such customer'];
    if ($c['dnd']) return ['ok' => false, 'why' => 'This customer has asked not to be called'];
    if (!voice_mobile_ok($c['mobile'])) return ['ok' => false, 'why' => 'This customer has no usable mobile number'];
    if (voice_e164($c['mobile']) === voice_e164($u['mobile']))
        return ['ok' => false, 'why' => 'That is your own number — the call would ring you twice'];

    return ['ok' => true, 'why' => ''];
}

/**
 * Place the call. Rings the PERSON; the customer is dialled by the XML that
 * voice_answer.php serves when they pick up.
 */
function voice_bridge_send($partyId, array $opts = []) {
    $partyId = (int)$partyId;
    $u = current_user();
    $ctx = voice_party_context($partyId);
    $gate = voice_bridge_can($partyId, $ctx, $u);
    // The check is here, inside the function that dials, and not only on the
    // screen that draws the button. A screen can be skipped.
    if (!$gate['ok']) return ['ok' => false, 'error' => $gate['why'], 'call_id' => 0, 'status' => 'refused'];

    $mine  = voice_e164($u['mobile']);
    $their = voice_e164($ctx['mobile']);
    $test  = array_key_exists('test', $opts) ? (bool)$opts['test'] : voice_test_mode();
    $token = bin2hex(random_bytes(20));

    // from_number is the leg that rings first (ours), to_number the customer.
    // Reading the row later answers "who called whom" without a second table.
    q("INSERT INTO voice_calls (party_id, mobile, amount, lang, script, provider, token, status,
                                test_mode, created_by, direction, intent, from_number, to_number,
                                question_asked, started_at)
       VALUES (?,?,0,?,?,?,?,?,?,?,'out','bridge',?,?,0,NOW())",
      [$partyId, $their, setting('voice_lang', 'gu'),
       'Connect ' . ($u['name'] ?? '') . ' ↔ ' . ($ctx['name'] ?? ''),
       setting('voice_provider', 'vobiz'), $token, 'queued', $test ? 1 : 0,
       (int)$u['id'], $mine, $their]);
    $callId = (int)insert_id();

    log_activity('voice_bridge', 'Connect call to ' . ($ctx['name'] ?? '#' . $partyId) . ($test ? ' (test)' : ''));

    if ($test) {
        q("UPDATE voice_calls SET status = 'test' WHERE id = ?", [$callId]);
        return ['ok' => true, 'error' => '', 'call_id' => $callId, 'status' => 'test',
                'ring' => $mine, 'then' => $their];
    }

    [$uuid, $err] = voice_provider_call($mine, $token);
    if ($uuid === null) {
        q("UPDATE voice_calls SET status = 'failed', error = ? WHERE id = ?", [mb_substr($err, 0, 255), $callId]);
        return ['ok' => false, 'error' => $err, 'call_id' => $callId, 'status' => 'failed'];
    }
    q("UPDATE voice_calls SET call_uuid = ?, status = 'ringing' WHERE id = ?", [$uuid, $callId]);
    return ['ok' => true, 'error' => '', 'call_id' => $callId, 'status' => 'ringing',
            'ring' => $mine, 'then' => $their];
}

/**
 * What the shopkeeper hears when they pick up, and the dial that follows.
 *
 * The <Record> comes FIRST and deliberately so: recordSession records the
 * whole call from that moment, so placing it before the <Dial> is what makes
 * our own side of the conversation part of the recording. After the Dial it
 * would only catch whatever came later.
 */
function voice_bridge_xml($call) {
    // Loaded here rather than at the top: voice_in.php loads voice.php, which
    // loads this file, and a require at the top of all three is a cycle.
    require_once __DIR__ . '/voice_in.php';
    $party = row('SELECT name FROM parties WHERE id = ?', [(int)$call['party_id']]);
    $name  = trim((string)($party['name'] ?? ''));
    $lang  = $call['lang'] ?: 'gu';
    $to    = voice_e164($call['to_number'] ?: $call['mobile']);
    $from  = preg_replace('/\D/', '', setting('vobiz_caller_id', ''));

    $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<Response>\n";
    $xml .= voice_record_session_xml($call, voice_bridge_records());

    // One short line to the person who pressed the button, so they know who
    // is about to answer and that the call is being recorded - which is also
    // their cue to tell the customer. Said in the shop's language when the
    // voice for it already exists, in English when it does not: a caller
    // waiting in silence while speech is generated hangs up.
    $line = voice_in_words($lang, ['name' => $name])['bridge_intro'] ?? '';
    if ($line !== '' && !voice_line_off($lang, 'bridge_intro')) {
        $say = voice_say_live($line, $lang, voice_in_en('bridge_intro', ['name' => $name]));
        $xml .= $say['url'] ? voice_xml_play($say['url']) : voice_xml_speak($say['text']);
    }

    $xml .= '  <Dial callerId="' . htmlspecialchars($from, ENT_XML1) . '"'
          . ' timeout="' . voice_bridge_ring() . '" redirect="false"'
          . ' action="' . htmlspecialchars(voice_public_url('voice_webhook.php?t=' . $call['token']), ENT_XML1) . '"'
          . " method=\"POST\">\n"
          . '    <Number>' . htmlspecialchars($to, ENT_XML1) . "</Number>\n"
          . "  </Dial>\n";
    $xml .= "</Response>\n";
    return $xml;
}

/**
 * The button, wherever a customer is on screen.
 *
 * Drawn only when it would actually work: the gate above decides, not the
 * page. When it refuses for a reason the person can fix - no number on their
 * own account - the button says so instead of vanishing, because a button
 * that is simply absent teaches nobody anything.
 */
function voice_bridge_button($partyId, $back = '', $small = true) {
    $partyId = (int)$partyId;
    if ($partyId <= 0 || !voice_bridge_on() || !can('parties.view')) return '';
    $gate = voice_bridge_can($partyId);
    $cls = 'btn ' . ($small ? 'btn-sm ' : '');

    if (!$gate['ok']) {
        // Nothing to say about a customer who cannot be rung at all; the
        // things the person themselves can fix are worth a word.
        $fixable = strpos($gate['why'], 'My Account') !== false || strpos($gate['why'], 'not set up') !== false;
        if (!$fixable) return '';
        return '<span class="' . $cls . 'btn-muted" title="' . htmlspecialchars($gate['why'], ENT_QUOTES)
             . '" style="cursor:help">📞 Call — not ready</span>';
    }

    return '<form method="post" action="voice_bridge.php" style="display:inline">'
         . csrf_field()
         . '<input type="hidden" name="party_id" value="' . $partyId . '">'
         . '<input type="hidden" name="back" value="' . htmlspecialchars($back, ENT_QUOTES) . '">'
         . '<button class="' . $cls . 'btn-success" type="submit"'
         . ' title="Rings your phone first, then connects the customer. They see the shop number, not yours.">'
         . '📞 Call customer</button></form>';
}
