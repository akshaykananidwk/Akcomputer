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

/** "મારા કેટલા બાકી?" - the customer's own balance + their last 3 entries. */
function wa_bot_ledger_reply($party) {
    $shop = setting('app_name', 'AK Computer');
    $bx = party_balance_expr('p');
    $bal = (float)val("SELECT $bx FROM parties p WHERE p.id = ?", [$party['id']]);
    $out = "🙏 *$shop*\nનમસ્તે *" . $party['name'] . "*!\n";
    if ($bal > 0.009) $out .= "તમારા બાકી: *₹" . money($bal) . "* (આપવાના)\n";
    elseif ($bal < -0.009) $out .= "તમારી જમા: *₹" . money(-$bal) . "* (અમારે આપવાના)\n";
    else $out .= "તમારો હિસાબ ચોખ્ખો છે — કંઈ બાકી નથી ✅\n";
    $lines = all("(SELECT sale_date d, CONCAT('બિલ ', invoice_no) label, total amt FROM sales WHERE party_id = ? AND is_cancelled = 0)
                  UNION ALL
                  (SELECT pay_date d, IF(direction='in','ચુકવણી મળી','ચુકવણી કરી') label, amount amt FROM payments WHERE party_id = ?)
                  ORDER BY d DESC LIMIT 3", [$party['id'], $party['id']]);
    if ($lines) {
        $out .= "\nછેલ્લી એન્ટ્રી:";
        foreach ($lines as $l) $out .= "\n- " . dmy($l['d']) . ' ' . $l['label'] . ' ₹' . money($l['amt']);
    }
    $out .= "\n\nકોઈ ફરક લાગે તો આ મેસેજનો જવાબ આપો. 🙏";
    return $out;
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
    if (!$cams) return "🙏 *$shop*\nહમણાં કેમેરાની વિગત ઓનલાઇન નથી — અમારા માણસ તરત કોટેશન મોકલશે!";
    $out = "🙏 *$shop*\n📋 *કોટેશન — $qty " . ($type === 'ip' ? 'IP' : 'HD') . " કેમેરા*\n";
    $n = 1;
    foreach ($cams as $c) {
        $tot = $qty * (float)$c['selling_price'];
        $out .= "\n$n) *{$c['name']}*\n   ₹" . money($c['selling_price']) . " × $qty = *₹" . money($tot) . "*"
              . ((float)$c['stk'] >= $qty ? " ✅ સ્ટોકમાં" : " 📦 ઓર્ડરથી")
              . "\n   " . base_url('product.php?id=' . $c['id']) . "\n";
        $n++;
    }
    $out .= "\n📌 " . ($type === 'ip' ? 'NVR' : 'DVR') . ", હાર્ડ ડિસ્ક, કેબલ અને ફિટિંગ ચાર્જ અલગથી લાગશે.\nપાક્કા ભાવ માટે આ મેસેજનો જવાબ આપો — અમે તરત ફોન કરીશું! 📞";
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

    // anti-loop / anti-spam: never answer the same person more than once per
    // 15 seconds, and never react to text identical to our own last reply
    $recent = row('SELECT reply, created_at FROM wa_bot_log WHERE mobile = ? ORDER BY id DESC LIMIT 1', [$mobile]);
    if ($recent && strtotime($recent['created_at']) > time() - 15) return 'rate-limited';
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
            $reply = "🙏 *" . setting('app_name', 'AK Computer') . "*\nફોટો મળ્યો! અમારા માણસ ચેક કરીને તરત જવાબ આપશે. 👍\nત્યાં સુધી અમારો સ્ટોર જુઓ: " . base_url('catalog.php');
        }
    } elseif (wa_bot_is_greeting($text)) {
        // welcome at most once a day per person
        $lastHello = val("SELECT created_at FROM wa_bot_log WHERE mobile = ? AND matched = 0 AND in_text IS NOT NULL AND created_at > DATE_SUB(NOW(), INTERVAL 1 DAY) ORDER BY id DESC LIMIT 1", [$mobile]);
        if ($lastHello) return 'greeted-recently';
        $reply = "🙏 નમસ્તે! *" . setting('app_name', 'AK Computer') . "* માં આપનું સ્વાગત છે.\n\nકોઈપણ પ્રોડક્ટનું નામ લખો (કે ફોટો મોકલો) — ભાવ અને લિંક તરત મળશે!\n\n🌐 આખો સ્ટોર: " . base_url('catalog.php');
    } elseif (trim($text) !== '') {
        $t = mb_strtolower($text);
        $st = wa_bot_get_state($mobile);

        // 1) pending follow-up: we asked "IP કે HD?" - understand the answer
        if ($st && $st['state'] === 'camera_type') {
            $d = json_decode($st['data'], true) ?: [];
            if (preg_match('/ip|આઈપી|આઇપી/iu', $t)) {
                $reply = wa_bot_camera_quote($d['qty'] ?? 4, 'ip'); wa_bot_clear_state($mobile);
            } elseif (preg_match('/hd|analog|એચડી|dvr/iu', $t)) {
                $reply = wa_bot_camera_quote($d['qty'] ?? 4, 'hd'); wa_bot_clear_state($mobile);
            } else {
                $reply = "🙏 ફક્ત *IP* કે *HD* લખી દો — એટલે તરત કોટેશન મોકલી દઉં!";
            }
        }
        // 2) "મારે 4 કેમેરા લગાડવા છે" -> first ASK which type, then quote
        elseif (preg_match('/(\d+)\s*(camera|cam|કેમેરા|કૅમેરા|कैमरा)/iu', $t, $cm)
                || (preg_match('/(camera|કેમેરા|कैमरा)/iu', $t) && preg_match('/lagad|લગાડ|લગાવ|फिट|install|setup|જોઈએ|joie|chahiye/iu', $t))) {
            $qn = isset($cm[1]) ? (int)$cm[1] : 4;
            wa_bot_set_state($mobile, 'camera_type', ['qty' => max(1, $qn)]);
            $reply = "🙏 *" . setting('app_name', 'AK Computer') . "*\nસરસ! " . max(1, $qn) . " કેમેરા માટે એક સવાલ:\n\n*IP કેમેરા* જોઈએ કે *HD (analog)*?\nફક્ત IP અથવા HD લખી દો — તરત ભાવ સાથે કોટેશન મોકલું. 📋";
        }
        // 3) the customer's OWN account: balance / ledger (matched by number)
        elseif (preg_match('/baki|બાકી|balance|hisab|હિસાબ|ledger|ઉધાર|udhar|खाता|बकाया/iu', $t)) {
            $party = wa_bot_party_for($mobile);
            $reply = $party ? wa_bot_ledger_reply($party)
                : "🙏 *" . setting('app_name', 'AK Computer') . "*\nઆ નંબર પર કોઈ ખાતું નથી મળ્યું. દુકાને તમારો આ નંબર નોંધાવેલો હશે તો હિસાબ અહીં જ મળી જશે — એક વાર દુકાનનો સંપર્ક કરો. 📞";
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
            // still nothing -> stay SILENT so the bot never talks over a real
            // conversation the owner is having with the customer
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
    if (!setting('gemini_api_key')) return false;
    $cap = (int)setting('wa_bot_ai_monthly_cap', '1500');
    if ($cap <= 0) return false;
    $used = (int)val("SELECT COUNT(*) FROM wa_bot_log WHERE used_ai = 1 AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')");
    return $used < $cap;
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
        . "Reply in simple Gujarati, MAXIMUM 2 short sentences. If a listed product fits, mention its price and link. "
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
