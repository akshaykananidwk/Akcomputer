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

if ($mobile === '' || ($text === '' && !$jpeg)) die(json_encode(['ok' => true, 'status' => 'nothing-to-do']));

$status = wa_bot_handle($mobile, $text, $jpeg);
echo json_encode(['ok' => true, 'status' => $status]);
