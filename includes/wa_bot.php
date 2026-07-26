<?php
// WhatsApp product bot - answers "do you have X?" messages automatically.
// Cost design (per the owner): TEXT questions are answered entirely from OUR
// items database (zero API cost); only a PHOTO question spends one Gemini
// free-tier call to name the product, then the database does the rest.

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

/** True for plain greetings ("hi", "નમસ્તે") that deserve a welcome, not a search. */
function wa_bot_is_greeting($text) {
    $t = mb_strtolower(trim(preg_replace('/[^\p{L}\p{N} ]+/u', '', $text)));
    return in_array($t, ['hi', 'hii', 'hiii', 'hello', 'helo', 'hey', 'namaste', 'namaskar', 'jsk',
        'jay shree krishna', 'jay shri krishna', 'નમસ્તે', 'નમસ્કાર', 'જય શ્રી કૃષ્ણ', 'હેલો', 'હાય'], true);
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
    if ($photoGuess !== '') $out .= "ફોટામાં દેખાય છે: _" . $photoGuess . "_\n";
    $out .= "\nઆ પ્રોડક્ટ અમારી પાસે છે:\n";
    $n = 1;
    foreach ($matches as $m) {
        $label = trim($m['name'] . ($m['brand'] ? ' (' . trim($m['brand'] . ' ' . $m['model']) . ')' : ''));
        $out .= "\n$n) *$label*\n   ભાવ: *₹" . money($m['selling_price']) . "*\n   " . base_url('product.php?id=' . $m['id']) . "\n";
        $n++;
    }
    $out .= "\n🛒 લિંક ખોલીને ઓર્ડર કરો, અથવા આ મેસેજનો જવાબ આપો — અમે તરત મદદ કરીશું!";
    return $out;
}

/** The whole bot: takes [mobile, text, jpegBytes|null], decides, replies, logs.
 *  Returns a short status string (for the webhook's JSON echo / debugging). */
function wa_bot_handle($mobile, $text, $jpeg = null) {
    $mobile = wa_normalize_number($mobile);
    if (strlen($mobile) < 12) return 'bad-number';

    // anti-loop / anti-spam: never answer the same person more than once per
    // 15 seconds, and never react to text identical to our own last reply
    $recent = row('SELECT reply, created_at FROM wa_bot_log WHERE mobile = ? ORDER BY id DESC LIMIT 1', [$mobile]);
    if ($recent && strtotime($recent['created_at']) > time() - 15) return 'rate-limited';
    if ($recent && $text !== '' && trim($recent['reply'] ?? '') === trim($text)) return 'own-echo';

    $matches = []; $photoGuess = ''; $reply = null;

    if ($jpeg) {
        $photoGuess = (string)wa_bot_identify_photo($jpeg);
        if ($photoGuess !== '') $matches = wa_bot_search($photoGuess);
        if ($matches) {
            $reply = wa_bot_reply_text($matches, $photoGuess);
        } else {
            $reply = "🙏 *" . setting('app_name', 'AK Computer') . "*\nફોટો મળ્યો! અમારા માણસ ચેક કરીને તરત જવાબ આપશે. 👍\nત્યાં સુધી અમારો સ્ટોર જુઓ: " . base_url('catalog.php');
        }
    } elseif (wa_bot_is_greeting($text)) {
        // welcome at most once a day per person
        $lastHello = val("SELECT created_at FROM wa_bot_log WHERE mobile = ? AND matched = 0 AND in_text IS NOT NULL AND created_at > DATE_SUB(NOW(), INTERVAL 1 DAY) ORDER BY id DESC LIMIT 1", [$mobile]);
        if ($lastHello) return 'greeted-recently';
        $reply = "🙏 નમસ્તે! *" . setting('app_name', 'AK Computer') . "* માં આપનું સ્વાગત છે.\n\nકોઈપણ પ્રોડક્ટનું નામ લખો (કે ફોટો મોકલો) — ભાવ અને લિંક તરત મળશે!\n\n🌐 આખો સ્ટોર: " . base_url('catalog.php');
    } elseif (trim($text) !== '') {
        $matches = wa_bot_search($text);
        if ($matches) $reply = wa_bot_reply_text($matches);
        // no match on plain text -> stay SILENT so the bot never talks over a
        // real conversation the owner is having with the customer
    }

    q('INSERT INTO wa_bot_log (mobile, in_text, had_image, reply, matched) VALUES (?,?,?,?,?)',
      [$mobile, mb_substr((string)$text, 0, 500), $jpeg ? 1 : 0, $reply ? mb_substr($reply, 0, 1500) : null, count($matches)]);

    if ($reply === null) return 'silent';
    send_whatsapp($mobile, $reply);
    return 'replied:' . count($matches);
}
