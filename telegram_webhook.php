<?php
// Telegram management bot webhook. Staff link their chat once with
// /link <code> (code shown in Settings > WhatsApp/Telegram); after that the
// whole shop answers from Telegram buttons - sale, dues, cash, stock, web
// orders, repairs, wallets - reusing the same database-first (AI-last)
// answer engine as the WhatsApp bot. Secured by ?key= + only linked, active
// staff accounts get any data.
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/wa_bot.php';
header('Content-Type: application/json');

if (setting('tg_webhook_key', '') === '' || !hash_equals(setting('tg_webhook_key'), (string)get('key'))) {
    http_response_code(403);
    die(json_encode(['ok' => false]));
}
$token = setting('tg_bot_token', '');
if ($token === '') die(json_encode(['ok' => true, 'status' => 'no-token']));

function tg_api($method, array $params) {
    $ch = curl_init('https://api.telegram.org/bot' . setting('tg_bot_token') . '/' . $method);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 15,
        CURLOPT_POSTFIELDS => http_build_query($params), CURLOPT_SSL_VERIFYPEER => true]);
    $r = curl_exec($ch);
    curl_close($ch);
    return json_decode((string)$r, true);
}
function tg_menu() {
    return json_encode(['inline_keyboard' => [
        [['text' => '📊 આજનું વેચાણ', 'callback_data' => 'sale'], ['text' => '💰 ઉધાર/બાકી', 'callback_data' => 'baki']],
        [['text' => '💵 કેશ + બેંક', 'callback_data' => 'cash'], ['text' => '📦 સ્ટોક શોધો', 'callback_data' => 'stockhelp']],
        [['text' => '🌐 વેબ ઓર્ડર', 'callback_data' => 'order'], ['text' => '🛠️ રિપેર જોબ', 'callback_data' => 'repair']],
        [['text' => '👥 સ્ટાફ વોલેટ', 'callback_data' => 'wallets'], ['text' => '📈 વિઝિટર', 'callback_data' => 'visitor']],
    ]]);
}
function tg_send($chatId, $text, $withMenu = true) {
    $p = ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'Markdown'];
    if ($withMenu) $p['reply_markup'] = tg_menu();
    $r = tg_api('sendMessage', $p);
    if (!($r['ok'] ?? false)) { unset($p['parse_mode']); tg_api('sendMessage', $p); } // markdown edge cases
}

$upd = json_decode(file_get_contents('php://input'), true) ?: [];
$msg = $upd['message'] ?? null;
$cb = $upd['callback_query'] ?? null;
$chatId = (string)($msg['chat']['id'] ?? $cb['message']['chat']['id'] ?? '');
$text = trim((string)($msg['text'] ?? ''));
$data = trim((string)($cb['data'] ?? ''));
if ($chatId === '') die(json_encode(['ok' => true]));
if ($cb) tg_api('answerCallbackQuery', ['callback_query_id' => $cb['id']]);

$staff = row('SELECT * FROM users WHERE telegram_chat_id = ? AND is_active = 1', [$chatId]);

// ---------- linking ----------
if (!$staff) {
    if (preg_match('/^\/link\s+([A-Za-z0-9]{4,12})$/', $text, $m)) {
        $target = row('SELECT * FROM users WHERE tg_link_code = ? AND is_active = 1', [$m[1]]);
        if ($target) {
            q('UPDATE users SET telegram_chat_id = ?, tg_link_code = NULL WHERE id = ?', [$chatId, $target['id']]);
            log_activity('tg_linked', $target['name'] . " chat $chatId");
            tg_send($chatId, "✅ *" . setting('app_name', 'AK Computer') . "*\n" . $target['name'] . ", તમારું Telegram જોડાઈ ગયું! નીચેના બટનથી આખી દુકાન ચલાવો 👇");
        } else {
            tg_send($chatId, "❌ કોડ ખોટો છે. Settings → Telegram માં તમારો સાચો કોડ જુઓ.", false);
        }
    } else {
        tg_send($chatId, "🔒 આ " . setting('app_name', 'AK Computer') . " નો પ્રાઇવેટ મેનેજમેન્ટ બોટ છે.\nજોડાવા માટે લખો:\n/link તમારો-કોડ\n(કોડ સોફ્ટવેરમાં Settings → WhatsApp/Telegram માં દેખાય છે)", false);
    }
    die(json_encode(['ok' => true]));
}

// ---------- linked staff: buttons + free text ----------
$q = $data !== '' ? $data : $text;
if ($q === '/start' || $q === 'menu' || $q === '/menu') {
    tg_send($chatId, "🙏 બોલો " . $staff['name'] . "! શું જોવું છે?");
    die(json_encode(['ok' => true]));
}
if ($q === 'stockhelp') {
    tg_send($chatId, "📦 સ્ટોક જોવા લખો:\n`stock કેમેરા`\n(કોઈપણ આઇટમનું નામ)");
    die(json_encode(['ok' => true]));
}
if ($q === 'wallets') {
    // whole-shop wallets only for a full admin / cashbank.viewall holder
    $perms = array_merge(json_decode((string)val('SELECT permissions FROM roles WHERE id = ?', [$staff['role_id']]), true) ?: [],
                         json_decode((string)$staff['permissions'], true) ?: []);
    if (in_array('*', $perms, true) || in_array('cashbank.viewall', $perms, true)) {
        $out = "👥 *સ્ટાફ વોલેટ*";
        foreach (all('SELECT id, name FROM users WHERE is_active = 1 ORDER BY name') as $s2) {
            $out .= "\n- " . $s2['name'] . ": ₹" . money(staff_cash($s2['id']));
        }
        $out .= "\n*કુલ કેશ: ₹" . money(total_cash_in_hand()) . "*";
    } else {
        $out = "💵 તમારી કેશ: *₹" . money(staff_cash($staff['id'])) . "*";
    }
    tg_send($chatId, $out);
    die(json_encode(['ok' => true]));
}

// everything else runs through the same shop-assistant brain as WhatsApp
// (sale/baki/cash/stock <item>/order/repair/visitor keywords answer straight
// from the database; anything unmatched gets one tiny capped AI call)
$map = ['sale' => 'sale', 'baki' => 'baki', 'cash' => 'cash', 'order' => 'order', 'repair' => 'repair', 'visitor' => 'visitor'];
$question = $map[$q] ?? $q;
$usedAi = 0;
$reply = wa_bot_owner_answer($question, $usedAi);
if ($reply === null) $reply = "સમજાયું નહીં 🤔 — નીચેના બટન વાપરો કે 'help' લખો.";
try {
    q('INSERT INTO wa_bot_log (mobile, in_text, had_image, reply, matched, used_ai, sender_role) VALUES (?,?,0,?,0,?,?)',
      ['tg:' . $chatId, mb_substr($question, 0, 500), mb_substr($reply, 0, 1500), $usedAi, 'owner']);
} catch (Exception $e) {}
tg_send($chatId, $reply);
echo json_encode(['ok' => true]);
