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
if (isset($p['object'], $p['entry'][0]['changes'][0]['value']) && is_array($p['entry'][0]['changes'][0]['value'])) {
    $v = $p['entry'][0]['changes'][0]['value'];
    if (empty($v['messages'][0])) die(json_encode(['ok' => true, 'status' => 'status-event']));
    $p = $v;
}
// some gateways nest the message: {data:{...}} / {message:{...}} / {messages:[{...}]}
foreach (['data', 'message', 'payload'] as $k) {
    if (isset($p[$k]) && is_array($p[$k]) && !isset($p[$k][0])) $p = array_merge($p, $p[$k]);
}
if (isset($p['messages'][0]) && is_array($p['messages'][0])) $p = array_merge($p, $p['messages'][0]);

// never react to our own outgoing messages or group chats
$fromMe = $p['fromMe'] ?? $p['from_me'] ?? $p['self'] ?? false;
if ($fromMe === true || $fromMe === 'true' || $fromMe === 1 || $fromMe === '1') die(json_encode(['ok' => true, 'status' => 'own-message']));
$jid = (string)($p['from'] ?? $p['sender'] ?? $p['remoteJid'] ?? $p['chatId'] ?? $p['number'] ?? $p['phone'] ?? $p['mobile'] ?? $p['waId'] ?? '');
if (strpos($jid, '@g.us') !== false || strpos($jid, '-') !== false && strpos($jid, '@') !== false) die(json_encode(['ok' => true, 'status' => 'group-skip']));
$mobile = preg_replace('/\D/', '', explode('@', $jid)[0]);

$text = $p['text'] ?? $p['body'] ?? $p['message'] ?? $p['msg'] ?? $p['caption'] ?? $p['content'] ?? '';
// text sometimes arrives as a nested object {body:...} (Meta Cloud API does
// this always) - casting an array to string would log the literal "Array"
if (is_array($text)) $text = $text['body'] ?? '';
$text = trim((string)$text);

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

// record into the Inbox + ping the admins on Telegram (customers only -
// staff already chat with the shop assistant all day)
wa_chat_log($mobile, 'in', $text !== '' ? $text : ($jpeg ? '📷 [photo]' : '🎙 [voice message]'), 'whatsapp', preg_match('#^https?://#i', $mediaUrl) ? $mediaUrl : '');
$isStaffSender = (bool)row("SELECT id FROM users WHERE is_active = 1 AND mobile <> ''
                            AND ? LIKE CONCAT('%', RIGHT(REPLACE(REPLACE(mobile, '+', ''), ' ', ''), 10))", [$mobile]);
if (!$isStaffSender) {
    try {
        tg_notify_admins("💬 નવો WhatsApp મેસેજ\nFrom: +$mobile\n" . mb_substr($text !== '' ? $text : '📷 media', 0, 300)
            . "\n\nજવાબ આપવા: " . base_url('wa_inbox.php?m=' . $mobile));
    } catch (Exception $e) { /* telegram optional */ }
}

if (!$botEnabled) die(json_encode(['ok' => true, 'status' => 'logged-bot-off']));
$status = wa_bot_handle($mobile, $text, $jpeg);
echo json_encode(['ok' => true, 'status' => $status]);
