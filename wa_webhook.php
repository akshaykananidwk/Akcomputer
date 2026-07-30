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
if (setting('wa_bot_enabled', '0') !== '1') die(json_encode(['ok' => true, 'status' => 'bot-disabled']));

$raw = file_get_contents('php://input');
$p = json_decode($raw, true);
if (!is_array($p)) $p = $_POST;
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

$text = trim((string)($p['text'] ?? $p['body'] ?? $p['message'] ?? $p['msg'] ?? $p['caption'] ?? $p['content'] ?? ''));
// text field itself sometimes arrives as a nested object {body:...}
if ($text === '' && isset($p['text']['body'])) $text = trim((string)$p['text']['body']);

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

if ($mobile === '' || ($text === '' && !$jpeg)) die(json_encode(['ok' => true, 'status' => 'nothing-to-do']));

$status = wa_bot_handle($mobile, $text, $jpeg);
echo json_encode(['ok' => true, 'status' => $status]);
