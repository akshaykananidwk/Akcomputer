<?php
// WhatsApp product bot - answers "do you have X?" messages automatically.
// Cost design (per the owner): TEXT questions are answered entirely from OUR
// items database (zero API cost); only a PHOTO question spends one Gemini
// free-tier call to name the product, then the database does the rest.
require_once __DIR__ . '/wa_lang.php';   // languages (en/gu/hi), trigger keywords, auto-replies
require_once __DIR__ . '/wa_portal.php'; // customer self-service portal (portal:* routes)

/** Words that carry no product meaning in a typical Gujarati/Hindi/English
 *  shop chat ("aa product che? bhav su che?") - stripped before searching. */
function wa_bot_stopwords() {
    return ['che', 'chhe', 'che?', 'hoy', 'hoi', 'joie', 'joiye', 'joye', 'bhav', 'bhaav', 'price', 'rate', 'cost',
        'kya', 'kaya', 'su', 'shu', 'ketla', 'ketlo', 'ketli', 'mate', 'malse', 'madse', 'male', 'mali', 'sakse',
        'available', 'avl', 'stock', 'tamari', 'tamara', 'pase', 'mane', 'mare', 'apvo', 'aapo', 'apo', 'this',
        'have', 'you', 'the', 'for', 'need', 'want', 'send', 'photo', 'pic', 'image', 'detail', 'details',
        'hai', 'hain', 'kitna', 'kitne', 'chahiye', 'bhai', 'sir', 'plz', 'pls', 'please', 'ok', 'okay',
        'છે', 'ભાવ', 'શું', 'શુ', 'કેટલો', 'કેટલા', 'કેટલી', 'જોઈએ', 'જોઇએ', 'મળશે', 'તમારી', 'પાસે', 'હોય',
        'મને', 'મારે', 'આપો', 'આપજો', 'ભાઈ', 'નો', 'ની', 'નું', 'માટે', 'એક', 'બે'];
}

/** True for plain greetings / configured trigger keywords ("hi", "menu",
 *  "નમસ્તે"...) that deserve the main menu, not a product search. The list is
 *  admin-editable in Settings (wa_bot_keywords). */
function wa_bot_is_greeting($text) {
    return wa_is_trigger($text);
}

/** Search the items database for a chat question. Returns up to 3 matches
 *  ranked by how many words hit; [] when nothing matches. */
function wa_bot_search($text) {
    $t = mb_strtolower(preg_replace('/[^\p{L}\p{N} ]+/u', ' ', (string)$text));
    $stop = wa_bot_stopwords();
    $words = [];
    foreach (preg_split('/\s+/u', $t) as $w) {
        if (mb_strlen($w) < 2 || in_array($w, $stop, true)) continue;
        $words[] = $w;
        if (count($words) >= 6) break;
    }
    if (!$words) return [];
    $conds = []; $params = [];
    foreach ($words as $w) {
        $like = '%' . $w . '%';
        $conds[] = '(i.name LIKE ? OR i.brand LIKE ? OR i.model LIKE ? OR c.name LIKE ?)';
        array_push($params, $like, $like, $like, $like);
    }
    // any word may hit; PHP re-ranks by how many did
    $cands = all('SELECT i.id, i.name, i.brand, i.model, i.selling_price, c.name cat_name
                  FROM items i LEFT JOIN categories c ON c.id = i.category_id
                  WHERE i.is_active = 1 AND i.show_on_website = 1 AND (' . implode(' OR ', $conds) . ') LIMIT 40', $params);
    foreach ($cands as &$it) {
        $hay = mb_strtolower($it['name'] . ' ' . $it['brand'] . ' ' . $it['model'] . ' ' . $it['cat_name']);
        $score = 0;
        foreach ($words as $w) if (mb_strpos($hay, $w) !== false) $score++;
        $it['score'] = $score + (mb_strpos(mb_strtolower($it['name']), $words[0]) !== false ? 0.5 : 0);
    }
    unset($it);
    usort($cands, fn($a, $b) => $b['score'] <=> $a['score']);
    // require at least half the meaningful words to hit so "hello bhai" never
    // matches a random product
    $need = max(1, (int)ceil(count($words) / 2));
    return array_slice(array_values(array_filter($cands, fn($x) => $x['score'] >= $need)), 0, 3);
}

/** One Gemini free-tier call: name the product in a customer's photo so the
 *  database search can take over. Returns a short text or null. */
function wa_bot_identify_photo($jpegBytes) {
    if (!function_exists('gemini_generate')) return null;
    list($text, $err) = gemini_generate([
        ['text' => "A customer sent this photo to a computer & CCTV shop in India asking if we sell it.\nReply with ONLY the product type plus brand/model if visible, 2-6 words in English, nothing else.\nExamples: 'CP Plus dome CCTV camera', 'HP laptop charger 65W', 'D-Link WiFi router'."],
        ['inline_data' => ['mime_type' => 'image/jpeg', 'data' => base64_encode($jpegBytes)]],
    ], 30);
    if ($err || $text === null) return null;
    return mb_substr(trim($text), 0, 80);
}

/** Format the reply message for a set of matches. */
function wa_bot_reply_text(array $matches, $photoGuess = '') {
    $shop = setting('app_name', 'AK Computer');
    $out = "🙏 *$shop*\n";
    if ($photoGuess !== '') $out .= wa_t('photo_seen', ['guess' => $photoGuess]) . "\n";
    $out .= "\n" . wa_t('we_have') . "\n";
    $n = 1;
    foreach ($matches as $m) {
        $label = trim($m['name'] . ($m['brand'] ? ' (' . trim($m['brand'] . ' ' . $m['model']) . ')' : ''));
        $out .= "\n$n) *$label*\n   " . wa_t('price_w') . ": *₹" . money($m['selling_price']) . "*\n   " . base_url('product.php?id=' . $m['id']) . "\n";
        $n++;
    }
    $out .= "\n" . wa_t('order_cta');
    return $out;
}

// ---------- WhatsApp catalog: the whole store as a list/button menu ----------
// Customer types "catalog" (or taps the greeting button) -> category list ->
// item list with prices -> product card with a 🛒 button that opens the
// website order page (catalog.php?add=ID -> web_orders). Interactive
// list/button messages need the official Meta Cloud API; without it the same
// menu goes out as a numbered TEXT list (reply "2") over any gateway.

function wa_catalog_on() { return setting('wa_catalog_enabled', '1') === '1'; }

/** Trim + cut to Meta's per-field character limits (Gujarati-safe). */
function wa_cat_cut($s, $n) {
    $s = trim(preg_replace('/\s+/u', ' ', (string)$s));
    return mb_strlen($s) > $n ? mb_substr($s, 0, $n - 1) . '…' : $s;
}

/** Decide whether an incoming text belongs to the catalog menu: an
 *  interactive reply id, a remembered number from a text menu, or a
 *  "catalog"/"menu" keyword. Returns the route id or null. */
function wa_catalog_want($t, $st, $mobile) {
    if (preg_match('/^(portal:[a-z]+(:\d+)?|lang:(en|gu|hi|pick))$/', $t)) return $t; // portal/language taps work even with catalog off
    // a remembered number from ANY text menu (catalog, portal, language
    // picker) routes first - this must not depend on the catalog toggle
    if ($st && $st['state'] === 'catalog_pick' && preg_match('/^\d{1,2}$/', $t)) {
        $d = json_decode($st['data'], true) ?: [];
        if (isset($d[$t])) { wa_bot_clear_state($mobile); return $d[$t]; }
        return null;
    }
    if (!wa_catalog_on()) return null;
    if (preg_match('/^(cats:\d+|cat:\d+:\d+|item:\d+|act:[a-z]+)$/', $t)) return $t;
    return wa_catalog_kw($t) ? 'cats:0' : null;
}

/** "catalog" / "menu" / "ભાવ" as the WHOLE message = open the menu. */
function wa_catalog_kw($t) {
    return (bool)preg_match('/^(catalog|catalogue|catlog|કેટલોગ|કૅટલોગ|menu|મેનુ|મેન્યુ|list|લિસ્ટ|price\s?list|rate\s?list|પ્રાઇસ\s?લિસ્ટ|ભાવ|બધા\s?ભાવ)[\s?.!)]*$/iu', $t);
}

/** Try the interactive message first (Meta); otherwise send the numbered
 *  text version and remember the number->route map for the reply. */
function wa_catalog_deliver($mobile, array $interactive, $text, array $map = []) {
    require_once __DIR__ . '/wa_meta.php';
    if (meta_wa_configured()) {
        [$ok, ] = meta_wa_send_interactive($mobile, $interactive);
        if ($ok) { wa_chat_log($mobile, 'out', $text, 'meta'); return 'interactive'; }
    }
    if ($map) wa_bot_set_state($mobile, 'catalog_pick', $map);
    return send_whatsapp($mobile, $text) ? 'text-menu' : 'send-failed';
}

/** Route one menu tap / number reply to the right screen. */
function wa_catalog_route($mobile, $id) {
    if (preg_match('/^cats:(\d+)$/', $id, $m)) return wa_catalog_root($mobile, (int)$m[1]);
    if (preg_match('/^cat:(\d+):(\d+)$/', $id, $m)) return wa_catalog_items($mobile, (int)$m[1], (int)$m[2]);
    if (preg_match('/^item:(\d+)$/', $id, $m)) return wa_catalog_item($mobile, (int)$m[1]);
    return 'unknown';
}

/** Screen 1: categories (max 9 + "more" row - Meta allows 10 list rows). */
function wa_catalog_root($mobile, $offset = 0) {
    $shop = setting('app_name', 'AK Computer');
    $cats = all('SELECT c.id, c.name, COUNT(i.id) n FROM categories c
                 JOIN items i ON i.category_id = c.id AND i.is_active = 1 AND i.show_on_website = 1
                 GROUP BY c.id, c.name ORDER BY n DESC, c.name');
    if (!$cats) { send_whatsapp($mobile, wa_t('catalog_empty', ['shop' => $shop, 'link' => base_url('catalog.php')])); return 'empty'; }
    $rows = []; $map = []; $n = 1;
    $txt = wa_t('cat_title', ['shop' => $shop]) . "\n";
    foreach (array_slice($cats, $offset, 9) as $c) {
        $rows[] = ['id' => 'cat:' . $c['id'] . ':0', 'title' => wa_cat_cut($c['name'], 24), 'description' => $c['n'] . ' ' . wa_t('products_w')];
        $map[(string)$n] = 'cat:' . $c['id'] . ':0';
        $txt .= "\n*$n)* {$c['name']} ({$c['n']})";
        $n++;
    }
    if (count($cats) > $offset + 9) {
        $rows[] = ['id' => 'cats:' . ($offset + 9), 'title' => wa_cat_cut(wa_t('more_cats'), 24), 'description' => (count($cats) - $offset - 9) . ' ' . wa_t('left_w')];
        $map['0'] = 'cats:' . ($offset + 9);
        $txt .= "\n*0)* " . wa_t('more_cats');
    }
    $txt .= "\n\n" . wa_t('reply_number') . "\n" . wa_t('full_store') . ' ' . base_url('catalog.php');
    return wa_catalog_deliver($mobile, [
        'type' => 'list',
        'header' => ['type' => 'text', 'text' => wa_cat_cut('📚 ' . $shop, 60)],
        'body' => ['text' => wa_t('cat_body')],
        'footer' => ['text' => wa_cat_cut(base_url('catalog.php'), 60)],
        'action' => ['button' => wa_cat_cut(wa_t('cat_btn'), 20), 'sections' => [['title' => wa_cat_cut(wa_t('cat_sec'), 24), 'rows' => $rows]]],
    ], $txt, $map);
}

/** Screen 2: items of one category, price in every row. */
function wa_catalog_items($mobile, $catId, $offset = 0) {
    $cat = val('SELECT name FROM categories WHERE id = ?', [$catId]) ?: wa_t('products_w');
    $items = all('SELECT id, name, brand, model, selling_price FROM items
                  WHERE is_active = 1 AND show_on_website = 1 AND category_id = ?
                  ORDER BY selling_price, name', [$catId]);
    if (!$items) { send_whatsapp($mobile, wa_t('cat_none', ['cat' => $cat, 'link' => base_url('catalog.php')])); return 'empty'; }
    $rows = []; $map = []; $n = 1;
    $txt = "📚 *$cat* (" . count($items) . ' ' . wa_t('products_w') . ")\n";
    foreach (array_slice($items, $offset, 9) as $it) {
        $bm = trim($it['brand'] . ' ' . $it['model']);
        $rows[] = ['id' => 'item:' . $it['id'], 'title' => wa_cat_cut($it['name'], 24),
                   'description' => wa_cat_cut('₹' . money($it['selling_price']) . ($bm !== '' ? ' · ' . $bm : ''), 72)];
        $map[(string)$n] = 'item:' . $it['id'];
        $txt .= "\n*$n)* {$it['name']}" . ($bm !== '' ? " ($bm)" : '') . " — *₹" . money($it['selling_price']) . "*";
        $n++;
    }
    if (count($items) > $offset + 9) {
        $rows[] = ['id' => "cat:$catId:" . ($offset + 9), 'title' => wa_cat_cut(wa_t('more_items'), 24), 'description' => (count($items) - $offset - 9) . ' ' . wa_t('left_w')];
        $map['0'] = "cat:$catId:" . ($offset + 9);
        $txt .= "\n*0)* " . wa_t('more_items');
    }
    $txt .= "\n\n" . wa_t('items_hint');
    return wa_catalog_deliver($mobile, [
        'type' => 'list',
        'header' => ['type' => 'text', 'text' => wa_cat_cut('📚 ' . $cat, 60)],
        'body' => ['text' => wa_t('items_body', ['cat' => wa_cat_cut($cat, 200), 'n' => count($items)])],
        'footer' => ['text' => wa_cat_cut(setting('app_name', 'AK Computer'), 60)],
        'action' => ['button' => wa_cat_cut(wa_t('items_btn'), 20), 'sections' => [['title' => wa_cat_cut($cat, 24), 'rows' => $rows]]],
    ], $txt, $map);
}

/** Screen 3: one product - photo + price + 🛒 button that opens the website
 *  with this item already in the cart (order lands in web_orders). */
function wa_catalog_item($mobile, $id) {
    $it = row('SELECT * FROM items WHERE id = ? AND is_active = 1 AND show_on_website = 1', [$id]);
    if (!$it) { send_whatsapp($mobile, wa_t('item_gone', ['link' => base_url('catalog.php')])); return 'gone'; }
    $stk = (float)val('SELECT COALESCE(SUM(qty),0) FROM stock WHERE item_id = ?', [$id]);
    $link = base_url('catalog.php?add=' . (int)$id);
    $bm = trim($it['brand'] . ' ' . $it['model']);
    $body = '*' . $it['name'] . '*' . ($bm !== '' ? "\n$bm" : '')
          . "\n\n💰 " . wa_t('price_w') . ": *₹" . money($it['selling_price']) . '*'
          . "\n" . ($stk > 0 ? wa_t('in_stock') : wa_t('on_order'));
    $photo = trim((string)$it['photo']);
    if ($photo !== '' && !preg_match('#^https?://#i', $photo)) $photo = base_url($photo);
    $interactive = [
        'type' => 'cta_url',
        'body' => ['text' => mb_substr($body . "\n\n" . wa_t('item_cta'), 0, 1024)],
        'footer' => ['text' => wa_cat_cut(setting('app_name', 'AK Computer'), 60)],
        'action' => ['name' => 'cta_url', 'parameters' => ['display_text' => wa_cat_cut(wa_t('btn_order'), 20), 'url' => $link]],
    ];
    if ($photo !== '') $interactive['header'] = ['type' => 'image', 'image' => ['link' => $photo]];
    return wa_catalog_deliver($mobile, $interactive, $body . "\n\n" . wa_t('order_link') . "\n$link");
}

// ---------- tiny per-number conversation memory (for follow-up questions) ----------
function wa_bot_get_state($mobile) {
    $s = row('SELECT * FROM wa_bot_state WHERE mobile = ?', [$mobile]);
    // a stale question (>30 min old) is forgotten - the person moved on
    if ($s && strtotime($s['updated_at']) < time() - 1800) { wa_bot_clear_state($mobile); return null; }
    return $s;
}
function wa_bot_set_state($mobile, $state, $data = []) {
    q('INSERT INTO wa_bot_state (mobile, state, data) VALUES (?,?,?)
       ON DUPLICATE KEY UPDATE state = VALUES(state), data = VALUES(data)', [$mobile, $state, json_encode($data, JSON_UNESCAPED_UNICODE)]);
}
function wa_bot_clear_state($mobile) { q('DELETE FROM wa_bot_state WHERE mobile = ?', [$mobile]); }

/** Customer's own account: matched by THEIR WhatsApp number against the
 *  parties list, so nobody can ever see anyone else's ledger. */
function wa_bot_party_for($mobile) {
    return row("SELECT * FROM parties WHERE mobile <> '' AND ? LIKE CONCAT('%', RIGHT(REPLACE(REPLACE(mobile, '+', ''), ' ', ''), 10)) ORDER BY is_active DESC LIMIT 1", [$mobile]);
}

/** First contact: ask the customer's language with 3 tap-buttons (or a
 *  numbered text menu on plain gateways). English / Gujarati / Hindi. */
function wa_lang_picker($mobile) {
    $prompt = wa_t('lang_prompt', ['shop' => setting('app_name', 'AK Computer')]);
    $langs = wa_langs_enabled();
    require_once __DIR__ . '/wa_meta.php';
    if (meta_wa_configured()) {
        $btns = [];
        foreach (array_slice($langs, 0, 3) as $L) $btns[] = ['type' => 'reply', 'reply' => ['id' => 'lang:' . $L, 'title' => wa_lang_name($L)]];
        [$ok, ] = meta_wa_send_interactive($mobile, ['type' => 'button', 'body' => ['text' => $prompt], 'action' => ['buttons' => $btns]]);
        if ($ok) { wa_chat_log($mobile, 'out', $prompt . "\n[" . implode('] [', array_map('wa_lang_name', $langs)) . ']', 'meta'); return 'lang-buttons'; }
    }
    $txt = $prompt; $map = []; $n = 1;
    foreach ($langs as $L) { $txt .= "\n*$n)* " . wa_lang_name($L); $map[(string)$n] = 'lang:' . $L; $n++; }
    $txt .= "\n\n" . wa_t('reply_number');
    if ($map) wa_bot_set_state($mobile, 'catalog_pick', $map);
    return send_whatsapp($mobile, $txt) ? 'lang-text' : 'send-failed';
}

/** The main menu / welcome, in the customer's language: 3 tap-buttons
 *  (Catalog / My Account / Statement) or a numbered text menu. */
function wa_bot_send_home($mobile) {
    $shop = setting('app_name', 'AK Computer');
    $hello = wa_t('welcome', ['shop' => $shop]);
    if (wa_catalog_on()) {
        require_once __DIR__ . '/wa_meta.php';
        [$bok, ] = meta_wa_configured() ? meta_wa_send_interactive($mobile, [
            'type' => 'button',
            'body' => ['text' => $hello],
            'action' => ['buttons' => [
                ['type' => 'reply', 'reply' => ['id' => 'cats:0', 'title' => wa_cat_cut(wa_t('btn_catalog'), 20)]],
                ['type' => 'reply', 'reply' => ['id' => 'portal:menu', 'title' => wa_cat_cut(wa_t('btn_account'), 20)]],
                ['type' => 'reply', 'reply' => ['id' => 'portal:stmt', 'title' => wa_cat_cut(wa_t('btn_stmt'), 20)]],
            ]],
        ]) : [false, ''];
        if ($bok) {
            wa_chat_log($mobile, 'out', $hello . "\n\n[" . wa_t('btn_catalog') . '] [' . wa_t('btn_account') . '] [' . wa_t('btn_stmt') . ']', 'meta');
            return 'home-buttons';
        }
    }
    $map = ['1' => 'cats:0', '2' => 'portal:menu', '3' => 'portal:stmt', '4' => 'lang:pick'];
    wa_bot_set_state($mobile, 'catalog_pick', $map);
    return send_whatsapp($mobile, $hello . wa_t('welcome_opts')) ? 'home-text' : 'send-failed';
}

/** "મારે 4 કેમેરા લગાડવા છે" (પછી IP/HD) -> stock-based mini quotation. */
function wa_bot_camera_quote($qty, $type) {
    $shop = setting('app_name', 'AK Computer');
    $qty = max(1, min(64, (int)$qty));
    $typeLike = $type === 'ip' ? '%ip%' : '%hd%';
    $cams = all("SELECT i.id, i.name, i.selling_price, COALESCE((SELECT SUM(qty) FROM stock s WHERE s.item_id = i.id),0) stk
                 FROM items i LEFT JOIN categories c ON c.id = i.category_id
                 WHERE i.is_active = 1 AND i.show_on_website = 1
                 AND (i.name LIKE '%camera%' OR i.name LIKE '%કેમેરા%' OR i.name LIKE '%cctv%' OR c.name LIKE '%camera%' OR c.name LIKE '%cctv%')
                 AND (i.name LIKE ? OR c.name LIKE ?)
                 ORDER BY (stk >= ?) DESC, i.selling_price ASC LIMIT 3", [$typeLike, $typeLike, $qty]);
    if (!$cams) {
        // type-specific search found nothing - fall back to any camera
        $cams = all("SELECT i.id, i.name, i.selling_price, COALESCE((SELECT SUM(qty) FROM stock s WHERE s.item_id = i.id),0) stk
                     FROM items i LEFT JOIN categories c ON c.id = i.category_id
                     WHERE i.is_active = 1 AND i.show_on_website = 1
                     AND (i.name LIKE '%camera%' OR i.name LIKE '%કેમેરા%' OR i.name LIKE '%cctv%' OR c.name LIKE '%camera%' OR c.name LIKE '%cctv%')
                     ORDER BY i.selling_price ASC LIMIT 3");
    }
    if (!$cams) return wa_t('cam_none', ['shop' => $shop]);
    $out = wa_t('cam_head', ['shop' => $shop, 'qty' => $qty, 'type' => $type === 'ip' ? 'IP' : 'HD']) . "\n";
    $n = 1;
    foreach ($cams as $c) {
        $tot = $qty * (float)$c['selling_price'];
        $out .= "\n$n) *{$c['name']}*\n   ₹" . money($c['selling_price']) . " × $qty = *₹" . money($tot) . "*"
              . ((float)$c['stk'] >= $qty ? wa_t('cam_in_stock') : wa_t('cam_on_order'))
              . "\n   " . base_url('product.php?id=' . $c['id']) . "\n";
        $n++;
    }
    $out .= "\n" . wa_t('cam_note', ['rec' => $type === 'ip' ? 'NVR' : 'DVR']);
    return $out;
}

/** The whole bot: takes [mobile, text, jpegBytes|null], decides, replies, logs.
 *  Returns a short status string (for the webhook's JSON echo / debugging).
 *
 *  Cost design ("AI kanjoos" mode, per the owner): every message is FIRST
 *  handled locally for free - product search, greetings, and the owner's shop
 *  commands are pure database work. Only when a question is not understood
 *  locally does ONE tiny Gemini call run, and it never sees the whole
 *  catalog: just the question plus at most 5 candidate products (name +
 *  price), answer capped at 2-3 short sentences. A monthly AI-call cap in
 *  Settings makes the worst-case bill impossible to blow past. */
function wa_bot_handle($mobile, $text, $jpeg = null) {
    $mobile = wa_normalize_number($mobile);
    if (strlen($mobile) < 12) return 'bad-number';

    // the chat continues in whatever language this number picked (English
    // until a choice is made; the first trigger keyword asks the question)
    $langKnown = wa_lang_of($mobile);
    $GLOBALS['_wa_lang'] = $langKnown ?: wa_lang_default();

    // anti-loop / anti-spam: never answer the same person more than once per
    // 15 seconds, and never react to text identical to our own last reply
    $recent = row('SELECT reply, created_at FROM wa_bot_log WHERE mobile = ? ORDER BY id DESC LIMIT 1', [$mobile]);
    // (menu taps, trigger keywords like "hi"/"menu" and language picks skip
    // the 15s brake - those must ALWAYS answer, whatever happened before;
    // same for a "2" reply while a numbered text menu is waiting)
    $isMenuTap = (bool)preg_match('/^(cats:|cat:|item:|act:|portal:|lang:)/', trim($text))
        || wa_catalog_kw(mb_strtolower(trim($text)))
        || wa_is_trigger($text)
        || wa_portal_want(mb_strtolower(trim($text))) !== null
        || (preg_match('/^\d{1,2}$/', trim($text)) && val('SELECT state FROM wa_bot_state WHERE mobile = ?', [$mobile]) === 'catalog_pick');
    if ($recent && strtotime($recent['created_at']) > time() - 15 && !$isMenuTap) return 'rate-limited';
    if ($recent && $text !== '' && trim($recent['reply'] ?? '') === trim($text)) return 'own-echo';

    // staff/owner? their WhatsApp number matches an active user -> the bot
    // becomes a shop assistant (answers straight from the database, free)
    $staff = row("SELECT * FROM users WHERE is_active = 1 AND mobile <> '' AND ? LIKE CONCAT('%', RIGHT(REPLACE(REPLACE(mobile, '+', ''), ' ', ''), 10)) LIMIT 1", [$mobile]);
    $role = $staff ? 'owner' : 'customer';

    $matches = []; $photoGuess = ''; $reply = null; $usedAi = 0;

    if ($staff && trim($text) !== '' && !$jpeg) {
        $reply = wa_bot_owner_answer($text, $usedAi, $staff);
    } elseif ($jpeg) {
        $photoGuess = (string)wa_bot_identify_photo($jpeg);
        if ($photoGuess !== '') { $usedAi = 1; $matches = wa_bot_search($photoGuess); }
        if ($matches) {
            $reply = wa_bot_reply_text($matches, $photoGuess);
        } else {
            $reply = wa_t('photo_wait', ['shop' => setting('app_name', 'AK Computer'), 'link' => base_url('catalog.php')]);
        }
    } elseif (wa_is_trigger($text)) {
        // a trigger keyword ("hi"/"menu"/"start"/...) ALWAYS answers - old
        // state is wiped and the freshest menu goes out, whether the last
        // chat was 30 minutes or 30 days ago. First-ever contact (language
        // not chosen yet) asks the language question instead.
        wa_bot_clear_state($mobile);
        $hst = ($langKnown === null && count(wa_langs_enabled()) > 1) ? wa_lang_picker($mobile) : wa_bot_send_home($mobile);
        q('INSERT INTO wa_bot_log (mobile, in_text, had_image, reply, matched, used_ai, sender_role) VALUES (?,?,?,?,?,?,?)',
          [$mobile, mb_substr((string)$text, 0, 500), 0, '🏠 ' . $hst, 0, 0, $role]);
        return 'replied:' . $hst;
    } elseif (trim($text) !== '') {
        $t = mb_strtolower(trim($text));
        $st = wa_bot_get_state($mobile);

        // 0) menu taps + keywords: catalog (cats:/cat:/item:) and the customer
        // portal (portal:*) share one pipe - list/button reply ids, remembered
        // numbers from text menus, and typed keywords all land here
        $croute = wa_catalog_want($t, $st, $mobile);
        if ($croute === null) $croute = wa_portal_want($t);
        if ($croute === 'act:baki') $croute = 'portal:stmt'; // old greeting button
        // language pick (button tap, numbered reply, or "language" keyword)
        if ($croute !== null && preg_match('/^lang:(en|gu|hi|pick)$/', $croute, $lm)) {
            $lst = 'lang-pick';
            if ($lm[1] === 'pick') {
                $lst = wa_lang_picker($mobile);
            } else {
                wa_lang_set($mobile, $lm[1]);
                send_whatsapp($mobile, wa_t('lang_set'));
                $lst = 'lang-set:' . $lm[1] . ':' . wa_bot_send_home($mobile);
            }
            q('INSERT INTO wa_bot_log (mobile, in_text, had_image, reply, matched, used_ai, sender_role) VALUES (?,?,?,?,?,?,?)',
              [$mobile, mb_substr((string)$text, 0, 500), 0, '🌐 ' . $lst, 0, 0, $role]);
            return 'replied:' . $lst;
        }
        // admin-defined auto replies (Settings: "keyword | reply" lines)
        if ($croute === null && ($ar = wa_auto_reply($t)) !== null) {
            q('INSERT INTO wa_bot_log (mobile, in_text, had_image, reply, matched, used_ai, sender_role) VALUES (?,?,?,?,?,?,?)',
              [$mobile, mb_substr((string)$text, 0, 500), 0, mb_substr($ar, 0, 1500), 0, 0, $role]);
            send_whatsapp($mobile, $ar);
            return 'replied:auto-reply';
        }
        if ($croute !== null) {
            if (strpos($croute, 'portal:') === 0) {
                $pst = wa_portal_route($mobile, $croute);
                q('INSERT INTO wa_bot_log (mobile, in_text, had_image, reply, matched, used_ai, sender_role) VALUES (?,?,?,?,?,?,?)',
                  [$mobile, mb_substr((string)$text, 0, 500), 0, '🧾 portal (' . $pst . ')', 0, 0, $role]);
                return 'portal:' . $pst;
            }
            $cst = wa_catalog_route($mobile, $croute);
            q('INSERT INTO wa_bot_log (mobile, in_text, had_image, reply, matched, used_ai, sender_role) VALUES (?,?,?,?,?,?,?)',
              [$mobile, mb_substr((string)$text, 0, 500), 0, '📚 catalog (' . $cst . ')', 0, 0, $role]);
            return 'catalog:' . $cst;
        }

        // 0.5) waiting for the customer's complaint text -> open a ticket
        if ($st && $st['state'] === 'ticket_wait') {
            wa_bot_clear_state($mobile);
            $reply = wa_portal_ticket_create($mobile, $text);
        }
        // 0.6) quotation ok/no typed as text (non-Meta fallback): "ok EST-26-001"
        elseif (preg_match('/^(ok|yes|no|na)\s+([A-Za-z]{2,5}-\d{2}-\d{2,6})$/i', trim($text), $qm)) {
            $qt = row('SELECT id FROM estimates WHERE estimate_no = ?', [$qm[2]]);
            $pst = $qt ? wa_portal_route($mobile, 'portal:q' . (in_array(strtolower($qm[1]), ['ok', 'yes'], true) ? 'acc' : 'rej') . ':' . $qt['id']) : null;
            if ($pst === null) $reply = wa_t('q_notfound');
            else return 'portal:' . $pst;
        }
        // 0.7) an invoice number or serial number typed straight in the chat
        elseif (($plk = wa_portal_lookup($mobile, trim($text))) !== null) {
            $reply = $plk;
        }
        // 1) pending follow-up: we asked "IP કે HD?" - understand the answer
        elseif ($st && $st['state'] === 'camera_type') {
            $d = json_decode($st['data'], true) ?: [];
            if (preg_match('/ip|આઈપી|આઇપી/iu', $t)) {
                $reply = wa_bot_camera_quote($d['qty'] ?? 4, 'ip'); wa_bot_clear_state($mobile);
            } elseif (preg_match('/hd|analog|એચડી|dvr/iu', $t)) {
                $reply = wa_bot_camera_quote($d['qty'] ?? 4, 'hd'); wa_bot_clear_state($mobile);
            } else {
                $reply = wa_t('cam_retry');
            }
        }
        // 2) "મારે 4 કેમેરા લગાડવા છે" -> first ASK which type, then quote
        elseif (preg_match('/(\d+)\s*(camera|cam|કેમેરા|કૅમેરા|कैमरा)/iu', $t, $cm)
                || (preg_match('/(camera|કેમેરા|कैमरा)/iu', $t) && preg_match('/lagad|લગાડ|લગાવ|फिट|install|setup|જોઈએ|joie|chahiye/iu', $t))) {
            $qn = isset($cm[1]) ? (int)$cm[1] : 4;
            wa_bot_set_state($mobile, 'camera_type', ['qty' => max(1, $qn)]);
            $reply = wa_t('cam_ask', ['shop' => setting('app_name', 'AK Computer'), 'qty' => max(1, $qn)]);
        }
        // 3) the customer's OWN account: balance / ledger (matched by number)
        // - same screen as the Statement menu, so it is written only once
        elseif (preg_match('/baki|બાકી|balance|hisab|હિસાબ|ledger|ઉધાર|udhar|खाता|बकाया/iu', $t)) {
            $pst = wa_portal_route($mobile, 'portal:stmt');
            q('INSERT INTO wa_bot_log (mobile, in_text, had_image, reply, matched, used_ai, sender_role) VALUES (?,?,?,?,?,?,?)',
              [$mobile, mb_substr((string)$text, 0, 500), 0, '🧾 portal (' . $pst . ')', 0, 0, $role]);
            return 'portal:' . $pst;
        }
        // 4) product search (any language - matches name/brand/model/category)
        else {
            $matches = wa_bot_search($text);
            if ($matches) {
                $reply = wa_bot_reply_text($matches);
            } elseif (mb_strlen(trim($text)) >= 5 && wa_bot_ai_allowed()) {
                // local search understood nothing - ONE tiny AI call so the
                // customer still gets a helpful 1-2 line answer
                $reply = wa_bot_ai_reply($text);
                if ($reply !== null) $usedAi = 1;
            }
            // nothing matched -> a short "type menu" nudge (once per 10 min,
            // so the bot never spams over a real conversation the owner is
            // having with the customer; switchable off in Settings)
            if ($reply === null && setting('wa_bot_fallback', '1') === '1') {
                $nudged = val("SELECT COUNT(*) FROM wa_bot_log WHERE mobile = ? AND reply IS NOT NULL AND created_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE)", [$mobile]);
                if (!$nudged) $reply = wa_t('fallback');
            }
        }
    }

    q('INSERT INTO wa_bot_log (mobile, in_text, had_image, reply, matched, used_ai, sender_role) VALUES (?,?,?,?,?,?,?)',
      [$mobile, mb_substr((string)$text, 0, 500), $jpeg ? 1 : 0, $reply ? mb_substr($reply, 0, 1500) : null, count($matches), $usedAi, $role]);

    if ($reply === null) return 'silent';
    send_whatsapp($mobile, $reply);
    return 'replied:' . count($matches) . ($usedAi ? ':ai' : '') . ($staff ? ':owner' : '');
}

/** Monthly AI budget guard: counts this month's AI-flagged replies against
 *  the cap in Settings (default 1500/month - comfortably inside the Gemini
 *  free tier, i.e. ₹0; even on paid flash pricing these tiny calls cost a
 *  few paise each, so the month can never cross a few rupees). */
function wa_bot_ai_allowed() {
    if (!setting('gemini_api_key') && !setting('gemini_api_key_paid')) return false;
    $cap = (int)setting('wa_bot_ai_monthly_cap', '1500');
    if ($cap <= 0) return false; // owner explicitly switched AI off
    $used = (int)val("SELECT COUNT(*) FROM wa_bot_log WHERE used_ai = 1 AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')");
    // cap reached: with a paid backup key the replies keep flowing (never-stop
    // policy - a flash call costs paise); without one the brake still holds
    return $used < $cap || setting('gemini_api_key_paid') !== '';
}

/** This month's AI usage [used, cap] - shown on the Settings card. */
function wa_bot_ai_usage() {
    $used = (int)val("SELECT COUNT(*) FROM wa_bot_log WHERE used_ai = 1 AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')");
    return [$used, (int)setting('wa_bot_ai_monthly_cap', '1500')];
}

/** One SMALL Gemini call for a customer question local search couldn't
 *  handle. The prompt carries the question + at most 5 loosely-matching
 *  products (name/price only) - never the 1000-item catalog - and demands a
 *  <=2 sentence Gujarati answer. Roughly 400 tokens in, 80 out. */
function wa_bot_ai_reply($text) {
    // loose candidates: any single word may hit (no half-match requirement)
    $t = mb_strtolower(preg_replace('/[^\p{L}\p{N} ]+/u', ' ', (string)$text));
    $words = array_values(array_filter(preg_split('/\s+/u', $t), fn($w) => mb_strlen($w) >= 3 && !in_array($w, wa_bot_stopwords(), true)));
    $cands = [];
    if ($words) {
        $conds = []; $params = [];
        foreach (array_slice($words, 0, 4) as $w) { $conds[] = 'i.name LIKE ?'; $params[] = '%' . $w . '%'; }
        $cands = all('SELECT i.id, i.name, i.selling_price FROM items i WHERE i.is_active = 1 AND i.show_on_website = 1
                      AND (' . implode(' OR ', $conds) . ') LIMIT 5', $params);
    }
    $catList = '';
    foreach ($cands as $c) $catList .= '- ' . $c['name'] . ' = ₹' . money($c['selling_price']) . ' (' . base_url('product.php?id=' . $c['id']) . ")\n";
    $prompt = "You are the WhatsApp helper of \"" . setting('app_name', 'AK Computer') . "\", a computer & CCTV shop in Dwarka, Gujarat.\n"
        . "Customer message: \"" . mb_substr($text, 0, 200) . "\"\n"
        . ($catList !== '' ? "Possibly matching products from our stock:\n$catList" : "No matching product found in our stock list.\n")
        . "Store link: " . base_url('catalog.php') . "\n\n"
        . "Reply in simple " . wa_lang_name_en($GLOBALS['_wa_lang'] ?? wa_lang_default()) . ", MAXIMUM 2 short sentences. If a listed product fits, mention its price and link. "
        . "If none fits, politely say our team will reply soon and share the store link. Never invent products or prices. Plain text only.";
    list($out, $err) = gemini_generate([['text' => $prompt]], 30);
    if ($err || $out === null || trim($out) === '') return null;
    return "🙏 " . mb_substr(trim($out), 0, 600);
}

/** Owner/staff shop-assistant: answers business questions straight from the
 *  database - zero AI for the common ones, one compact AI call otherwise. */
function wa_bot_owner_answer($text, &$usedAi, $staffUser = null) {
    $t = mb_strtolower($text);
    $has = function (...$kws) use ($t) { foreach ($kws as $k) if (mb_strpos($t, $k) !== false) return true; return false; };
    $shop = setting('app_name', 'AK Computer');

    // Quick expense entry by message/voice: "ખર્ચ 50 ચા" / "kharch 120 petrol"
    // - books a CASH expense to the sender's own account, so a voice note
    // from the road becomes a real entry with zero typing in the software.
    if ($staffUser && preg_match('/(?:ખર્ચ|ખર્ચો|kharch|kharcho|expense)\s*(?:₹|rs\.?|rupiya)?\s*([0-9]+(?:\.[0-9]+)?)\s*(.*)/iu', $text, $m)) {
        $amt = (float)$m[1];
        $note = trim($m[2]) ?: 'WhatsApp entry';
        if ($amt > 0) {
            q('INSERT INTO expenses (exp_date, category, amount, mode, notes, location_id, created_by) VALUES (CURDATE(), ?, ?, \'cash\', ?, ?, ?)',
              ['General', $amt, $note, (int)($staffUser['location_id'] ?: 1), (int)$staffUser['id']]);
            log_activity('expense_add_wa', $staffUser['name'] . ' ₹' . $amt . ' ' . $note);
            return "✅ ખર્ચ નોંધ્યો: *₹" . money($amt) . "* – " . $note . " (Cash, " . $staffUser['name'] . ")";
        }
    }

    if ($has('help', 'મદદ', 'menu')) {
        return "*$shop bot* 🤖 તમે પૂછી શકો:\n- આજનું વેચાણ / sale\n- ઉધાર / baki\n- કેશ / cash\n- stock <આઇટમ નામ>\n- ઓર્ડર / order\n- રિપેર / repair\n- વિઝિટર / visitors\nબીજું કંઈ પણ લખશો તો AI ટૂંકો જવાબ આપશે.";
    }
    if ($has('sale', 'sales', 'વેચાણ', 'vechan')) {
        $td = row("SELECT COUNT(*) c, COALESCE(SUM(total),0) t, COALESCE(SUM(total-paid),0) due FROM sales WHERE is_cancelled = 0 AND sale_date = CURDATE()");
        $mo = (float)val("SELECT COALESCE(SUM(total),0) FROM sales WHERE is_cancelled = 0 AND sale_date >= DATE_FORMAT(NOW(), '%Y-%m-01')");
        return "📊 *$shop*\nઆજે: *₹" . money($td['t']) . "* ({$td['c']} બિલ, બાકી ₹" . money($td['due']) . ")\nઆ મહિને: *₹" . money($mo) . "*";
    }
    if ($has('baki', 'udhar', 'ઉધાર', 'બાકી', 'લેણા', 'due', 'receive')) {
        $recv = 0; $pay = 0;
        $bx = party_balance_expr('p');
        foreach (all("SELECT $bx AS bal FROM parties p WHERE p.is_active = 1 OR ABS($bx) > 0.009") as $b) {
            if ($b['bal'] > 0.009) $recv += $b['bal']; elseif ($b['bal'] < -0.009) $pay += -$b['bal'];
        }
        return "💰 *$shop*\nલેવાના (To Receive): *₹" . money($recv) . "*\nદેવાના (To Pay): *₹" . money($pay) . "*";
    }
    if ($has('cash', 'કેશ', 'રોકડ', 'bank', 'બેંક')) {
        $cash = function_exists('total_cash_in_hand') ? total_cash_in_hand() : 0;
        $out = "💵 *$shop*\nકુલ કેશ: *₹" . money($cash) . "*";
        foreach (all('SELECT * FROM bank_accounts WHERE is_active = 1') as $b) {
            $out .= "\n🏦 " . $b['account_name'] . ": ₹" . money(bank_account_balance($b['id']));
        }
        return $out;
    }
    if (preg_match('/^\s*stock\s+(.+)/iu', $text, $m) || $has('સ્ટોક')) {
        $q2 = isset($m[1]) ? $m[1] : trim(preg_replace('/સ્ટોક/u', '', $text));
        $found = $q2 !== '' ? wa_bot_search($q2) : [];
        if (!$found) return "🔍 \"$q2\" જેવી કોઈ આઇટમ ન મળી. 'stock <નામ>' લખો.";
        $out = "📦 *Stock*";
        foreach ($found as $f) {
            $qty = (float)val('SELECT COALESCE(SUM(qty),0) FROM stock WHERE item_id = ?', [$f['id']]);
            $out .= "\n- " . $f['name'] . ": *" . (0 + $qty) . "* (₹" . money($f['selling_price']) . ")";
        }
        return $out;
    }
    if ($has('order', 'ઓર્ડર')) {
        $new = all("SELECT order_no, customer_name, total FROM web_orders WHERE status = 'new' ORDER BY id DESC LIMIT 3");
        $c = (int)val("SELECT COUNT(*) FROM web_orders WHERE status = 'new'");
        $out = "🌐 *Website Orders*\nનવા ઓર્ડર: *$c*";
        foreach ($new as $o) $out .= "\n- {$o['order_no']} {$o['customer_name']} ₹" . money($o['total']);
        return $out;
    }
    if ($has('repair', 'રિપેર', 'રીપેર')) {
        $c = (int)val("SELECT COUNT(*) FROM repairs WHERE status NOT IN ('delivered','returned_unrepaired')");
        return "🛠️ ચાલુ રિપેર જોબ: *$c*";
    }
    if ($has('visitor', 'વિઝિટર', 'views', 'વ્યુ')) {
        $v = row('SELECT COUNT(*) u, COALESCE(SUM(views),0) v FROM site_visits WHERE visit_date = CURDATE()');
        return "🌐 આજે વેબસાઇટ પર: *" . (int)$v['u'] . "* મુલાકાતી (" . (int)$v['v'] . " views)";
    }
    if (wa_bot_is_greeting($text)) {
        return "🙏 બોલો શેઠ! 'help' લખો એટલે બધા સવાલોની યાદી મળશે.";
    }

    // free intents exhausted - one compact AI call with a tiny shop snapshot
    if (!wa_bot_ai_allowed()) return "🤖 આ મહિનાની AI મર્યાદા પતી ગઈ. 'help' લખો — સીધા સવાલ (વેચાણ/કેશ/સ્ટોક...) મફતમાં ચાલે જ છે.";
    $td = row("SELECT COUNT(*) c, COALESCE(SUM(total),0) t FROM sales WHERE is_cancelled = 0 AND sale_date = CURDATE()");
    $mo = (float)val("SELECT COALESCE(SUM(total),0) FROM sales WHERE is_cancelled = 0 AND sale_date >= DATE_FORMAT(NOW(), '%Y-%m-01')");
    $cash = function_exists('total_cash_in_hand') ? total_cash_in_hand() : 0;
    $snapshot = "Today's sales: ₹" . money($td['t']) . " ({$td['c']} bills). Month sales: ₹" . money($mo) . ". Cash in hand: ₹" . money($cash) . ".";
    $prompt = "You are the private WhatsApp assistant of the OWNER of \"" . setting('app_name', 'AK Computer') . "\" (computer shop, Dwarka).\n"
        . "Shop snapshot: $snapshot\n"
        . "Owner's message: \"" . mb_substr($text, 0, 200) . "\"\n\n"
        . "Answer in simple Gujarati, MAXIMUM 3 short sentences, using ONLY the snapshot numbers (never invent figures). "
        . "If the question needs data you don't have, say which section of the software to open. Plain text only.";
    list($out, $err) = gemini_generate([['text' => $prompt]], 30);
    if ($err || $out === null || trim($out) === '') return null;
    $usedAi = 1;
    return "🤖 " . mb_substr(trim($out), 0, 700);
}
