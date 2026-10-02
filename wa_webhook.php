<?php
// Incoming-WhatsApp webhook: paste this page's URL (shown in Settings >
// WhatsApp) into the gateway's (bulk.akdwk.in) webhook box. Every incoming
// customer message lands here; the bot searches OUR items database and
// replies with price + product link. Secured by the ?key= secret; different
// gateways name their JSON fields differently, so parsing is tolerant.
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/wa_bot.php';
header('Content-Type: application/json');

if (setting('wa_webhook_key', '') === '' || !hash_equals(setting('wa_webhook_key'), (string)get('key'))) {
    http_response_code(403);
    die(json_encode(['ok' => false, 'error' => 'bad key']));
}

// Official Meta Cloud API webhook VERIFICATION handshake: Meta sends a GET
// with hub.mode/hub.verify_token/hub.challenge and expects the raw challenge
// back. Use the same secret as the ?key= for the verify token.
if (get('hub_mode') !== '' || get('hub_challenge') !== '') {
    header('Content-Type: text/plain');
    if (hash_equals(setting('wa_webhook_key'), (string)get('hub_verify_token'))) { echo get('hub_challenge'); exit; }
    http_response_code(403);
    exit('bad verify token');
}

// The bot flag only gates AUTO-REPLIES - incoming messages are always
// recorded into the WhatsApp Inbox (wa_inbox.php) so the owner sees them.
$botEnabled = setting('wa_bot_enabled', '0') === '1';

$raw = file_get_contents('php://input');
$p = json_decode($raw, true);
if (!is_array($p)) $p = $_POST;

// Official Meta Cloud API payload: unwrap entry[0].changes[0].value so the
// generic parsing below sees the same shape the gateways send. Delivery/read
// receipts (statuses-only events) are ACKed and ignored.
// WHICH of the shop's numbers did this arrive on?
//
// The shop has two WhatsApp numbers - the official Meta one and the
// gateway's - and the reply used to go out by a fixed preference that had
// nothing to do with which one the customer wrote to. So a customer who
// messaged the Meta number got the answer from the gateway's number: a
// different chat, on a different number, which to them looks exactly like
// no answer at all. Answer on the number they wrote to.
$inVia = '';
if (isset($p['object'], $p['entry'][0]['changes'][0]['value'])) $inVia = 'meta';

if (isset($p['object'], $p['entry'][0]['changes'][0]['value']) && is_array($p['entry'][0]['changes'][0]['value'])) {
    $v = $p['entry'][0]['changes'][0]['value'];

    // A delivery receipt is not noise - it is the only honest answer to
    // "why did my customer never get that message?".
    //
    // These used to be acknowledged and thrown away, so a message Meta
    // ACCEPTED and then failed to deliver looked on screen exactly like one
    // the customer had read, and the shop was left guessing at gateways.
    // Meta names the fate of every message here, with its own reason when it
    // failed, matched to the id kept when it was sent.
    if (empty($v['messages'][0])) {
        $n = 0;
        foreach ($v['statuses'] ?? [] as $st) {
            $id = (string)($st['id'] ?? '');
            $state = strtolower((string)($st['status'] ?? ''));
            if ($id === '' || $state === '') continue;
            $why = '';
            if ($state === 'failed') {
                $e = $st['errors'][0] ?? [];
                $why = trim((string)($e['title'] ?? '') . ' — ' . (string)($e['error_data']['details'] ?? $e['message'] ?? ''), " —\t\n");
                if ($why === '') $why = 'Meta gave no reason';
                if (!empty($e['code'])) $why = '[' . (int)$e['code'] . '] ' . $why;
            }
            try {
                // Never walk a status backwards: a 'sent' receipt arriving
                // after 'read' must not un-read the message.
                q("UPDATE wa_chats SET status = ?, status_at = NOW(), fail_reason = ?
                   WHERE msg_id = ? AND FIELD(status, 'sent', 'delivered', 'read') <= FIELD(?, 'sent', 'delivered', 'read')",
                  [$state, mb_substr($why, 0, 255), $id, $state]);
                if ($state === 'failed')
                    q('UPDATE wa_chats SET status = ?, status_at = NOW(), fail_reason = ? WHERE msg_id = ?',
                      [$state, mb_substr($why, 0, 255), $id]);
                $n++;
            } catch (Exception $e) { /* pre-v82 column - never fail a webhook over history */ }
            if ($state === 'failed') log_activity('wa_send_failed', mb_substr($id . ': ' . $why, 0, 400));
        }
        die(json_encode(['ok' => true, 'status' => 'status-event', 'noted' => $n]));
    }
    $p = $v;
}
// some gateways nest the message: {data:{...}} / {message:{...}} / {messages:[{...}]}
foreach (['data', 'message', 'payload'] as $k) {
    if (isset($p[$k]) && is_array($p[$k]) && !isset($p[$k][0])) $p = array_merge($p, $p[$k]);
}
if (isset($p['messages'][0]) && is_array($p['messages'][0])) $p = array_merge($p, $p['messages'][0]);

// never react to our own outgoing messages or group chats
$fromMe = $p['fromMe'] ?? $p['from_me'] ?? $p['self'] ?? false;
$fromMe = $fromMe === true || $fromMe === 'true' || $fromMe === 1 || $fromMe === '1';
// The owner typed a reply on the shop's phone (the gateway reports it as
// type "phone_reply"). It goes in the Inbox history, and it tells the bot a
// person is handling this chat - see wa_human_active().
if ($fromMe && (string)($p['type'] ?? '') === 'phone_reply') {
    $to = preg_replace('/\D/', '', explode('@', (string)($p['sender'] ?? $p['to'] ?? ''))[0]);
    $body = trim((string)(is_array($p['message'] ?? null) ? '' : ($p['message'] ?? '')));
    // a message this software itself sent a moment ago is not a person
    // talking - if one ever came back this way, it must not silence the bot
    $echo = $body !== '' && (bool)val("SELECT COUNT(*) FROM wa_chats WHERE mobile = ? AND direction = 'out' AND via NOT IN ('phone', 'inbox')
                                       AND created_at > DATE_SUB(NOW(), INTERVAL 3 MINUTE) AND LEFT(body, 200) = LEFT(?, 200)",
                                      [wa_normalize_number($to), $body]);
    if (!$echo && strlen(wa_normalize_number($to)) >= 12 && !wa_is_group($p))
        wa_chat_log($to, 'out', $body !== '' ? $body : '📎 [media]', 'phone');
    if ($echo) die(json_encode(['ok' => true, 'status' => 'own-message']));
    die(json_encode(['ok' => true, 'status' => 'human-reply-noted']));
}
if ($fromMe) die(json_encode(['ok' => true, 'status' => 'own-message']));
// the gateway's own auto-replies come back as "outgoing_message" - never
// treat them as something the customer said
if ((string)($p['event'] ?? '') === 'outgoing_message') die(json_encode(['ok' => true, 'status' => 'own-message']));
// A group message is never answered. The shop's number is in a lot of
// groups, and the bot was replying PRIVATELY to whoever posted in one of
// them - an unasked-for message to a stranger, in the shop's name. The whole
// rule lives in wa_is_group() so there is one place to get it right.
if (wa_is_group($p)) {
    log_activity('wa_group_skip', mb_substr('group message ignored: '
        . (string)($p['from'] ?? $p['chatId'] ?? $p['remoteJid'] ?? '?'), 0, 200));
    die(json_encode(['ok' => true, 'status' => 'group-skip']));
}
$jid = (string)($p['from'] ?? $p['sender'] ?? $p['remoteJid'] ?? $p['chatId'] ?? $p['number'] ?? $p['phone'] ?? $p['mobile'] ?? $p['waId'] ?? '');
$mobile = preg_replace('/\D/', '', explode('@', $jid)[0]);

$text = $p['text'] ?? $p['body'] ?? $p['message'] ?? $p['msg'] ?? $p['caption'] ?? $p['content'] ?? '';
// text sometimes arrives as a nested object {body:...} (Meta Cloud API does
// this always) - casting an array to string would log the literal "Array"
if (is_array($text)) $text = $text['body'] ?? '';
$text = trim((string)$text);

// Interactive catalog reply (Meta list/button tap): the row id (cat:5:0 /
// item:12 / ...) drives the bot; the human-readable title goes in the Inbox.
$waTapTitle = '';
if (isset($p['interactive']) && is_array($p['interactive'])) {
    $ir = $p['interactive']['list_reply'] ?? $p['interactive']['button_reply'] ?? null;
    if (is_array($ir) && trim((string)($ir['id'] ?? '')) !== '') {
        $text = trim((string)$ir['id']);
        $waTapTitle = trim((string)($ir['title'] ?? ''));
    }
}

// Gateway button tap: the message text is the button's id already; keep
// its label for the Inbox, the same as a Meta tap above.
if (isset($p['button']) && is_array($p['button']) && trim((string)($p['button']['id'] ?? '')) !== '') {
    $text = trim((string)$p['button']['id']);
    $waTapTitle = trim((string)($p['button']['text'] ?? '')) ?: $text;
}

// image: either a fetchable URL or inline base64
$jpeg = null;
$mediaUrl = (string)($p['media_url'] ?? $p['mediaUrl'] ?? $p['image'] ?? $p['imageUrl'] ?? $p['url'] ?? '');
$mediaB64 = (string)($p['media_base64'] ?? $p['base64'] ?? $p['image_base64'] ?? '');
$mtype = mb_strtolower((string)($p['type'] ?? $p['messageType'] ?? $p['mimetype'] ?? ''));
if ($mediaB64 !== '') {
    $bin = base64_decode($mediaB64, true);
    if ($bin !== false) $jpeg = ai_image_to_jpeg($bin);
} elseif ($mediaUrl !== '' && preg_match('#^https?://#i', $mediaUrl)
          && (strpos($mtype, 'image') !== false || preg_match('/\.(jpe?g|png|webp)(\?|$)/i', $mediaUrl) || $mtype === '')) {
    $jpeg = ai_fetch_image($mediaUrl);
}

// Voice note -> Gemini transcription (staff only: it costs one AI call and
// only staff get action-commands like "ખર્ચ 50 ચા" anyway). The transcript
// then flows through the normal text pipeline, so anything speakable is
// also doable - Gujarati, Hindi or English.
$audioB64 = (string)($p['audio_base64'] ?? $p['voice_base64'] ?? '');
$isAudio = strpos($mtype, 'audio') !== false || strpos($mtype, 'ptt') !== false || strpos($mtype, 'voice') !== false
        || preg_match('/\.(ogg|opus|mp3|m4a|aac)(\?|$)/i', $mediaUrl);
if ($text === '' && !$jpeg && $isAudio && $mobile !== '') {
    $vStaff = row("SELECT id FROM users WHERE is_active = 1 AND mobile <> '' AND ? LIKE CONCAT('%', RIGHT(REPLACE(REPLACE(mobile, '+', ''), ' ', ''), 10)) LIMIT 1", [$mobile]);
    if ($vStaff && wa_bot_ai_allowed()) {
        $bytes = null;
        if ($audioB64 !== '') {
            $bytes = base64_decode($audioB64, true) ?: null;
        } elseif ($mediaUrl !== '' && preg_match('#^https?://#i', $mediaUrl)) {
            $ch = curl_init($mediaUrl);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_FOLLOWLOCATION => true, CURLOPT_SSL_VERIFYPEER => true]);
            $bytes = curl_exec($ch) ?: null;
            curl_close($ch);
        }
        if ($bytes && strlen($bytes) < 8 * 1024 * 1024) {
            $aMime = strpos($mtype, 'mp3') !== false || preg_match('/\.mp3/i', $mediaUrl) ? 'audio/mp3'
                   : (strpos($mtype, 'aac') !== false || preg_match('/\.(m4a|aac)/i', $mediaUrl) ? 'audio/aac' : 'audio/ogg');
            [$tr, $aErr] = gemini_generate([
                ['text' => 'Transcribe this voice message exactly as spoken. It may be Gujarati, Hindi or English. Reply with ONLY the spoken words, no extra commentary.'],
                ['inline_data' => ['mime_type' => $aMime, 'data' => base64_encode($bytes)]],
            ], 45);
            if ($tr !== null && trim($tr) !== '') $text = trim($tr);
        }
    }
}

if ($mobile === '' || ($text === '' && !$jpeg && !$isAudio)) die(json_encode(['ok' => true, 'status' => 'nothing-to-do']));

// Everything this request sends now goes back the way it came.
wa_reply_via($inVia ?: 'thirdparty');

// record into the Inbox + ping the admins on Telegram (customers only -
// staff already chat with the shop assistant all day)
wa_chat_log($mobile, 'in', $waTapTitle !== '' ? '👆 ' . $waTapTitle : ($text !== '' ? $text : ($jpeg ? '📷 [photo]' : '🎙 [voice message]')), $inVia ?: 'whatsapp', preg_match('#^https?://#i', $mediaUrl) ? $mediaUrl : '');
$isStaffSender = (bool)row("SELECT id FROM users WHERE is_active = 1 AND mobile <> ''
                            AND ? LIKE CONCAT('%', RIGHT(REPLACE(REPLACE(mobile, '+', ''), ' ', ''), 10))", [$mobile]);

/**
 * Ping the admins on Telegram - or, by default, don't.
 *
 * This used to fire on EVERY incoming message, before the bot had even had
 * its turn. So "media / media / Bill" arrived on Telegram one after another,
 * all of them already answered by the bot with nothing for a person to do.
 * Alerts nobody needs are what teach a person to stop reading the alerts
 * that matter.
 *
 * It is called AFTER the bot now, and told what the bot made of the message:
 *   all         - every customer message, as before
 *   unanswered  - only what the bot could not answer (the default)
 *   off         - nothing
 *
 * Menu taps are never sent: they are the bot's own conversation with itself.
 */
$notify = function ($botStatus) use ($mobile, $text, $isStaffSender, $waTapTitle) {
    $mode = setting('wa_tg_notify', 'unanswered');
    if ($mode === 'off' || $isStaffSender || $waTapTitle !== '') return;
    // Anything the bot actually answered needs no person. These are the
    // outcomes where it did NOT - named rather than guessed at with a
    // pattern, because a status this list forgets would go quietly unseen,
    // which is the one failure that matters here.
    $noReply = ['unknown', 'bad-number', 'send-failed', 'bot-off', 'rate-limited',
                'empty', 'gone', 'quote-gone', 'bill-denied', 'pay-denied', 'repair-denied'];
    $ignored = ['own-echo', 'silent', 'human-chat', 'ignored'];                       // deliberately not answered
    if (in_array($botStatus, $ignored, true)) return;
    // A status can carry the failure inside it - "replied:send-failed",
    // "portal:bills-failed" - and those matter MORE than an unknown word,
    // not less: the bot had an answer ready and the customer never got it.
    $failed = stripos($botStatus, 'failed') !== false;
    $answered = $botStatus !== '' && !$failed && !in_array($botStatus, $noReply, true);
    if ($mode === 'unanswered' && $answered) return;
    try {
        tg_notify_admins("💬 New WhatsApp message\nFrom: +$mobile\n"
            . mb_substr($text !== '' ? $text : '📷 media', 0, 300)
            . ($answered ? "\n\n🤖 The bot answered this one."
                         : ($failed ? "\n\n❌ The bot had an answer ready and it FAILED to send."
                                    : "\n\n⚠️ Nobody has answered this."))
            . "\n\nTo reply: " . base_url('wa_inbox.php?m=' . $mobile));
    } catch (Exception $e) { /* telegram optional */ }
};

// "STOP" has to actually stop it. Every campaign message carries that line,
// and a way out that does nothing is worse than no way out at all - so this
// sits ABOVE the bot switch and runs even when the bot is off. It turns off
// marketing only: a customer who wants no more offers is still told when a
// bill of theirs falls due, which is a different consent (see campaign.php).
if ($text !== '' && cam_is_stop_word($text)) {
    $n = cam_optout($mobile, true);
    if ($n > 0) {
        log_activity('campaign_optout', 'STOP from ' . $mobile);
        send_whatsapp($mobile, 'Advertising messages to your number have been turned off. 🙏'
            . "\nNecessary bill and payment notices continue.");
        $notify('marketing-opt-out');
        die(json_encode(['ok' => true, 'status' => 'marketing-opt-out']));
    }
}

if (!$botEnabled) { $notify('bot-off'); die(json_encode(['ok' => true, 'status' => 'logged-bot-off'])); }
$status = wa_bot_handle($mobile, $text, $jpeg);
$notify((string)$status);
echo json_encode(['ok' => true, 'status' => $status]);
