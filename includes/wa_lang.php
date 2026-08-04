<?php
// WhatsApp bot language layer: every customer-facing bot/portal string lives
// here as a key with English (default) + Gujarati + Hindi text. wa_t() picks
// the sender's saved language (wa_prefs, chosen on first contact), falling
// back to English. The admin can override ANY string per language via a
// setting named wa_txt_<key>_<lang> - the Settings page exposes the two that
// matter most (welcome + fallback); the rest work the moment such a setting
// exists. Also home of the configurable trigger-keyword list and the
// admin-defined auto-replies, so the whole conversation is tunable from the
// panel with zero code changes.

function wa_langs_enabled() {
    $l = array_values(array_intersect(array_filter(array_map('trim', explode(',', setting('wa_langs', 'en,gu,hi')))), ['en', 'gu', 'hi']));
    return $l ?: ['en'];
}
function wa_lang_default() {
    $d = setting('wa_lang_default', 'en');
    return in_array($d, wa_langs_enabled(), true) ? $d : wa_langs_enabled()[0];
}
function wa_lang_name($code) { return ['en' => 'English', 'gu' => 'ગુજરાતી', 'hi' => 'हिंदी'][$code] ?? 'English'; }
function wa_lang_name_en($code) { return ['en' => 'English', 'gu' => 'Gujarati', 'hi' => 'Hindi'][$code] ?? 'English'; }

/** Saved language of one number; null = never picked (ask first). Before the
 *  v50 migration runs the table is missing - then just use the default so
 *  the bot keeps working (Update before Migrate must never break it). */
function wa_lang_of($mobile) {
    try { return val('SELECT lang FROM wa_prefs WHERE mobile = ?', [$mobile]) ?: null; }
    catch (Exception $e) { return wa_lang_default(); }
}
function wa_lang_set($mobile, $lang) {
    if (!in_array($lang, ['en', 'gu', 'hi'], true)) $lang = wa_lang_default();
    try { q('INSERT INTO wa_prefs (mobile, lang) VALUES (?,?) ON DUPLICATE KEY UPDATE lang = VALUES(lang)', [$mobile, $lang]); }
    catch (Exception $e) { /* pre-migration */ }
    $GLOBALS['_wa_lang'] = $lang;
}

/** Admin-configurable trigger keywords: ANY of these as the whole message
 *  always (re)opens the main menu - old state, old session, time passed since
 *  the last chat: nothing blocks them. */
function wa_kw_list() {
    $kw = setting('wa_bot_keywords', '');
    if (trim($kw) === '') $kw = 'hi,hii,hiii,hello,helo,hey,menu,start,home,namaste,namaskar,jsk,jay shree krishna,jay shri krishna,નમસ્તે,નમસ્કાર,જય શ્રી કૃષ્ણ,હેલો,હાય,મેનુ,મેન્યુ,नमस्ते,नमस्कार,मेनू,मेन्यू';
    return array_values(array_filter(array_map(fn($w) => mb_strtolower(trim($w)), explode(',', $kw))));
}
function wa_is_trigger($text) {
    $t = mb_strtolower(trim(preg_replace('/[^\p{L}\p{N} ]+/u', '', (string)$text)));
    return $t !== '' && in_array($t, wa_kw_list(), true);
}

/** Admin-defined auto replies (Settings: one per line "keyword | reply").
 *  Whole-message match, case-insensitive. Returns the reply text or null. */
function wa_auto_reply($t) {
    foreach (preg_split('/\r?\n/', (string)setting('wa_auto_replies', '')) as $line) {
        $parts = explode('|', $line, 2);
        if (count($parts) < 2) continue;
        $kw = mb_strtolower(trim($parts[0]));
        if ($kw !== '' && $kw === mb_strtolower(trim($t))) return trim($parts[1]);
    }
    return null;
}

/** Translate one key into the current chat's language, with {var} fills.
 *  Priority: admin override setting > chosen language > English > the key. */
function wa_t($key, array $vars = []) {
    $lang = $GLOBALS['_wa_lang'] ?? wa_lang_default();
    $ov = setting('wa_txt_' . $key . '_' . $lang, '');
    $S = wa_strings();
    $s = $ov !== '' ? $ov : ($S[$key][$lang] ?? ($S[$key]['en'] ?? $key));
    foreach ($vars as $k => $v) $s = str_replace('{' . $k . '}', (string)$v, $s);
    return $s;
}

function wa_strings() {
    static $S = null;
    if ($S !== null) return $S;
    $S = [
        // ---------- welcome / language / fallback ----------
        'welcome' => [
            'en' => "🙏 Welcome to *{shop}*!\nType any product name (or send a photo) — price & link come instantly.\nPick an option below 👇",
            'gu' => "🙏 નમસ્તે! *{shop}* માં આપનું સ્વાગત છે.\nકોઈપણ પ્રોડક્ટનું નામ લખો (કે ફોટો મોકલો) — ભાવ અને લિંક તરત મળશે!\nનીચેથી પસંદ કરો 👇",
            'hi' => "🙏 *{shop}* में आपका स्वागत है!\nकिसी भी प्रोडक्ट का नाम लिखें (या फोटो भेजें) — दाम और लिंक तुरंत मिलेंगे।\nनीचे से चुनें 👇"],
        'welcome_opts' => [
            'en' => "\n\n*1)* 📚 Catalog\n*2)* 🧾 My Account\n*3)* 💰 Statement\n*4)* 🌐 Change language\n\n👉 Reply with a number (e.g. 1)",
            'gu' => "\n\n*1)* 📚 કેટલોગ\n*2)* 🧾 મારું એકાઉન્ટ\n*3)* 💰 હિસાબ (Statement)\n*4)* 🌐 ભાષા બદલો\n\n👉 નંબર લખીને જવાબ આપો (દા.ત. 1)",
            'hi' => "\n\n*1)* 📚 कैटलॉग\n*2)* 🧾 मेरा खाता\n*3)* 💰 हिसाब (Statement)\n*4)* 🌐 भाषा बदलें\n\n👉 नंबर लिखकर जवाब दें (जैसे 1)"],
        'lang_prompt' => ['en' => "🙏 *{shop}*\nPlease select your language:\nતમારી ભાષા પસંદ કરો:\nअपनी भाषा चुनें:"],
        'lang_set' => [
            'en' => "✅ Language set: *English*.\nType *menu* anytime for all options.",
            'gu' => "✅ ભાષા સેટ થઈ: *ગુજરાતી*.\nબધા વિકલ્પ માટે ગમે ત્યારે *menu* લખો.",
            'hi' => "✅ भाषा सेट: *हिंदी*।\nसभी विकल्पों के लिए कभी भी *menu* लिखें।"],
        'fallback' => [
            'en' => "🙏 Sorry, I didn't quite get that.\nType *menu* to see all options — or our team will reply to you right here soon.",
            'gu' => "🙏 માફ કરજો, બરાબર સમજાયું નહીં.\nબધા વિકલ્પ જોવા *menu* લખો — અથવા અમારા માણસ જલદી અહીં જ જવાબ આપશે.",
            'hi' => "🙏 माफ़ करें, ठीक से समझ नहीं आया।\nसभी विकल्प देखने के लिए *menu* लिखें — या हमारी टीम जल्द ही यहीं जवाब देगी।"],
        'btn_catalog' => ['en' => '📚 Catalog', 'gu' => '📚 કેટલોગ જુઓ', 'hi' => '📚 कैटलॉग'],
        'btn_account' => ['en' => '🧾 My Account', 'gu' => '🧾 મારું એકાઉન્ટ', 'hi' => '🧾 मेरा खाता'],
        'btn_stmt' => ['en' => '💰 Statement', 'gu' => '💰 મારો હિસાબ', 'hi' => '💰 मेरा हिसाब'],
        // ---------- catalog ----------
        'cat_title' => ['en' => "📚 *{shop} — Catalog*\nSelect a category:", 'gu' => "🙏 *{shop} — કેટલોગ* 📚\nકેટેગરી પસંદ કરો:", 'hi' => "📚 *{shop} — कैटलॉग*\nकैटेगरी चुनें:"],
        'products_w' => ['en' => 'products', 'gu' => 'પ્રોડક્ટ', 'hi' => 'प्रोडक्ट'],
        'more_cats' => ['en' => '➡️ More categories...', 'gu' => '➡️ વધુ કેટેગરી...', 'hi' => '➡️ और कैटेगरी...'],
        'more_items' => ['en' => '➡️ More products...', 'gu' => '➡️ વધુ પ્રોડક્ટ...', 'hi' => '➡️ और प्रोडक्ट...'],
        'left_w' => ['en' => 'more', 'gu' => 'બાકી', 'hi' => 'बाकी'],
        'reply_number' => ['en' => '👉 Reply with a number (e.g. 1)', 'gu' => '👉 નંબર લખીને જવાબ આપો (દા.ત. 1)', 'hi' => '👉 नंबर लिखकर जवाब दें (जैसे 1)'],
        'full_store' => ['en' => '🌐 Full store:', 'gu' => '🌐 આખો સ્ટોર:', 'hi' => '🌐 पूरा स्टोर:'],
        'cat_body' => ['en' => "The whole catalog with prices, right here 👇\nPick a category — products, prices & the order button follow.",
                       'gu' => "આખો કેટલોગ ભાવ સાથે અહીં જ 👇\nકેટેગરી પસંદ કરો — પ્રોડક્ટ, ભાવ અને ઓર્ડર બટન તરત મળશે.",
                       'hi' => "पूरा कैटलॉग दामों के साथ यहीं 👇\nकैटेगरी चुनें — प्रोडक्ट, दाम और ऑर्डर बटन तुरंत मिलेंगे।"],
        'cat_btn' => ['en' => 'View categories', 'gu' => 'કેટેગરી જુઓ', 'hi' => 'कैटेगरी देखें'],
        'cat_sec' => ['en' => 'Categories', 'gu' => 'કેટેગરી', 'hi' => 'कैटेगरी'],
        'catalog_empty' => ['en' => "🙏 *{shop}*\nOur online catalog is empty right now.\n🌐 {link}", 'gu' => "🙏 *{shop}*\nહમણાં ઓનલાઇન કેટલોગ ખાલી છે.\n🌐 {link}", 'hi' => "🙏 *{shop}*\nअभी ऑनलाइन कैटलॉग खाली है।\n🌐 {link}"],
        'cat_none' => ['en' => "🙏 No products online in *{cat}* right now.\n🌐 {link}", 'gu' => "🙏 *{cat}* માં હમણાં કોઈ પ્રોડક્ટ ઓનલાઇન નથી.\n🌐 {link}", 'hi' => "🙏 *{cat}* में अभी कोई प्रोडक्ट ऑनलाइन नहीं है।\n🌐 {link}"],
        'items_hint' => ['en' => '👉 Reply with a number — details & order link follow.', 'gu' => '👉 નંબર લખીને જવાબ આપો — વિગત અને ઓર્ડર લિંક મળશે.', 'hi' => '👉 नंबर लिखें — विवरण और ऑर्डर लिंक मिलेगा।'],
        'items_body' => ['en' => "{cat} — {n} products with prices 👇\nPick one, the order button follows.", 'gu' => "{cat} — {n} પ્રોડક્ટ ભાવ સાથે 👇\nપ્રોડક્ટ પસંદ કરો, ઓર્ડર બટન તરત મળશે.", 'hi' => "{cat} — {n} प्रोडक्ट दामों के साथ 👇\nचुनें, ऑर्डर बटन तुरंत मिलेगा।"],
        'items_btn' => ['en' => 'View products', 'gu' => 'પ્રોડક્ટ જુઓ', 'hi' => 'प्रोडक्ट देखें'],
        'price_w' => ['en' => 'Price', 'gu' => 'ભાવ', 'hi' => 'दाम'],
        'in_stock' => ['en' => '✅ In stock', 'gu' => '✅ સ્ટોકમાં છે', 'hi' => '✅ स्टॉक में है'],
        'on_order' => ['en' => '📦 Available on order', 'gu' => '📦 ઓર્ડરથી મળી જશે', 'hi' => '📦 ऑर्डर पर मिल जाएगा'],
        'item_cta' => ['en' => '🛒 Tap the button below — the order happens right on our website.', 'gu' => '🛒 નીચેનું બટન દબાવો — વેબસાઇટ પર ઓર્ડર થઈ જશે.', 'hi' => '🛒 नीचे का बटन दबाएँ — वेबसाइट पर ऑर्डर हो जाएगा।'],
        'btn_order' => ['en' => '🛒 Order now', 'gu' => '🛒 ઓર્ડર કરો', 'hi' => '🛒 ऑर्डर करें'],
        'order_link' => ['en' => '🛒 Open this link to order:', 'gu' => '🛒 ઓર્ડર કરવા આ લિંક ખોલો:', 'hi' => '🛒 ऑर्डर करने के लिए यह लिंक खोलें:'],
        'item_gone' => ['en' => "🙏 This product isn't available right now.\n🌐 {link}", 'gu' => "🙏 આ પ્રોડક્ટ હમણાં ઉપલબ્ધ નથી.\n🌐 {link}", 'hi' => "🙏 यह प्रोडक्ट अभी उपलब्ध नहीं है।\n🌐 {link}"],
        'we_have' => ['en' => 'We have this in stock:', 'gu' => 'આ પ્રોડક્ટ અમારી પાસે છે:', 'hi' => 'यह प्रोडक्ट हमारे पास है:'],
        'photo_seen' => ['en' => 'In the photo: _{guess}_', 'gu' => 'ફોટામાં દેખાય છે: _{guess}_', 'hi' => 'फोटो में दिख रहा है: _{guess}_'],
        'order_cta' => ['en' => "🛒 Open a link to order, or just reply to this message — we'll help right away!", 'gu' => '🛒 લિંક ખોલીને ઓર્ડર કરો, અથવા આ મેસેજનો જવાબ આપો — અમે તરત મદદ કરીશું!', 'hi' => '🛒 लिंक खोलकर ऑर्डर करें, या इस मैसेज का जवाब दें — हम तुरंत मदद करेंगे!'],
        'photo_wait' => ['en' => "🙏 *{shop}*\nGot your photo! Our team will check and reply shortly. 👍\nMeanwhile, browse our store: {link}", 'gu' => "🙏 *{shop}*\nફોટો મળ્યો! અમારા માણસ ચેક કરીને તરત જવાબ આપશે. 👍\nત્યાં સુધી અમારો સ્ટોર જુઓ: {link}", 'hi' => "🙏 *{shop}*\nफोटो मिल गया! हमारी टीम देखकर तुरंत जवाब देगी। 👍\nतब तक हमारा स्टोर देखें: {link}"],
        // ---------- camera quotation mini-flow ----------
        'cam_ask' => ['en' => "🙏 *{shop}*\nGreat! One question for {qty} cameras:\n\n*IP cameras* or *HD (analog)*?\nJust type IP or HD — instant quotation follows. 📋", 'gu' => "🙏 *{shop}*\nસરસ! {qty} કેમેરા માટે એક સવાલ:\n\n*IP કેમેરા* જોઈએ કે *HD (analog)*?\nફક્ત IP અથવા HD લખી દો — તરત ભાવ સાથે કોટેશન મોકલું. 📋", 'hi' => "🙏 *{shop}*\nबढ़िया! {qty} कैमरों के लिए एक सवाल:\n\n*IP कैमरा* चाहिए या *HD (analog)*?\nबस IP या HD लिखें — तुरंत कोटेशन भेजता हूँ। 📋"],
        'cam_retry' => ['en' => "🙏 Just type *IP* or *HD* — and the quotation comes right away!", 'gu' => '🙏 ફક્ત *IP* કે *HD* લખી દો — એટલે તરત કોટેશન મોકલી દઉં!', 'hi' => '🙏 बस *IP* या *HD* लिखें — तुरंत कोटेशन भेज दूँगा!'],
        'cam_head' => ['en' => "🙏 *{shop}*\n📋 *Quotation — {qty} {type} cameras*", 'gu' => "🙏 *{shop}*\n📋 *કોટેશન — {qty} {type} કેમેરા*", 'hi' => "🙏 *{shop}*\n📋 *कोटेशन — {qty} {type} कैमरे*"],
        'cam_in_stock' => ['en' => ' ✅ in stock', 'gu' => ' ✅ સ્ટોકમાં', 'hi' => ' ✅ स्टॉक में'],
        'cam_on_order' => ['en' => ' 📦 on order', 'gu' => ' 📦 ઓર્ડરથી', 'hi' => ' 📦 ऑर्डर पर'],
        'cam_note' => ['en' => "📌 {rec}, hard disk, cable & fitting charges extra.\nReply to this message for final rates — we'll call you right away! 📞", 'gu' => "📌 {rec}, હાર્ડ ડિસ્ક, કેબલ અને ફિટિંગ ચાર્જ અલગથી લાગશે.\nપાક્કા ભાવ માટે આ મેસેજનો જવાબ આપો — અમે તરત ફોન કરીશું! 📞", 'hi' => "📌 {rec}, हार्ड डिस्क, केबल और फिटिंग चार्ज अलग से।\nपक्के दाम के लिए इस मैसेज का जवाब दें — हम तुरंत कॉल करेंगे! 📞"],
        'cam_none' => ['en' => "🙏 *{shop}*\nCamera details aren't online right now — our team will send a quotation right away!", 'gu' => '🙏 *{shop}*\nહમણાં કેમેરાની વિગત ઓનલાઇન નથી — અમારા માણસ તરત કોટેશન મોકલશે!', 'hi' => '🙏 *{shop}*\nअभी कैमरे की जानकारी ऑनलाइन नहीं है — हमारी टीम तुरंत कोटेशन भेजेगी!'],
        // ---------- account / portal ----------
        'no_account' => ['en' => "🙏 *{shop}*\nNo account found on this number. If the shop has this number on record, your ledger shows up right here — please contact the shop once. 📞", 'gu' => "🙏 *{shop}*\nઆ નંબર પર કોઈ ખાતું નથી મળ્યું. દુકાને તમારો આ નંબર નોંધાવેલો હશે તો હિસાબ અહીં જ મળી જશે — એક વાર દુકાનનો સંપર્ક કરો. 📞", 'hi' => "🙏 *{shop}*\nइस नंबर पर कोई खाता नहीं मिला। दुकान पर यह नंबर दर्ज होगा तो हिसाब यहीं मिल जाएगा — एक बार दुकान से संपर्क करें। 📞"],
        'menu_title' => ['en' => 'My Account', 'gu' => 'મારું એકાઉન્ટ', 'hi' => 'मेरा खाता'],
        'hello_w' => ['en' => 'Hello', 'gu' => 'નમસ્તે', 'hi' => 'नमस्ते'],
        'menu_body' => ['en' => 'Your whole account right here — bills, balance, repairs, warranty, payments 👇', 'gu' => 'તમારું આખું ખાતું અહીં જ — બિલ, હિસાબ, રિપેર, વોરંટી, પેમેન્ટ 👇', 'hi' => 'आपका पूरा खाता यहीं — बिल, हिसाब, रिपेयर, वारंटी, पेमेंट 👇'],
        'menu_btn' => ['en' => 'Open menu', 'gu' => 'મેનુ ખોલો', 'hi' => 'मेनू खोलें'],
        'm_bills' => ['en' => '🧾 My Bills', 'gu' => '🧾 મારા બિલ', 'hi' => '🧾 मेरे बिल'],
        'm_bills_d' => ['en' => 'View bills / download PDF', 'gu' => 'બિલ જુઓ / PDF ડાઉનલોડ', 'hi' => 'बिल देखें / PDF डाउनलोड'],
        'm_stmt' => ['en' => '💰 Statement / Balance', 'gu' => '💰 હિસાબ / બાકી', 'hi' => '💰 हिसाब / बकाया'],
        'm_stmt_d' => ['en' => 'Balance + recent entries', 'gu' => 'બેલેન્સ + છેલ્લી એન્ટ્રી', 'hi' => 'बैलेंस + हाल की एंट्री'],
        'm_pay' => ['en' => '💳 Pay Online', 'gu' => '💳 પેમેન્ટ કરો', 'hi' => '💳 पेमेंट करें'],
        'm_pay_d' => ['en' => 'Secure online payment link', 'gu' => 'ઓનલાઇન ચુકવણી લિંક', 'hi' => 'ऑनलाइन भुगतान लिंक'],
        'm_repairs' => ['en' => '🔧 Repair Status', 'gu' => '🔧 રિપેર સ્ટેટસ', 'hi' => '🔧 रिपेयर स्टेटस'],
        'm_repairs_d' => ['en' => 'Track your service / repair', 'gu' => 'સર્વિસ/રિપેર ટ્રેકિંગ', 'hi' => 'सर्विस/रिपेयर ट्रैकिंग'],
        'm_warranty' => ['en' => '🛡 Warranty', 'gu' => '🛡 વોરંટી', 'hi' => '🛡 वारंटी'],
        'm_warranty_d' => ['en' => 'Serial no + warranty card', 'gu' => 'સિરિયલ નં + વોરંટી કાર્ડ', 'hi' => 'सीरियल नं + वारंटी कार्ड'],
        'm_orders' => ['en' => '📦 My Orders', 'gu' => '📦 મારા ઓર્ડર', 'hi' => '📦 मेरे ऑर्डर'],
        'm_orders_d' => ['en' => 'Website order status', 'gu' => 'વેબસાઇટ ઓર્ડર સ્ટેટસ', 'hi' => 'वेबसाइट ऑर्डर स्टेटस'],
        'm_quotes' => ['en' => '📋 Quotations', 'gu' => '📋 કોટેશન', 'hi' => '📋 कोटेशन'],
        'm_quotes_d' => ['en' => 'Approve / decline', 'gu' => 'મંજૂર / નામંજૂર કરો', 'hi' => 'मंज़ूर / नामंज़ूर करें'],
        'm_ticket' => ['en' => '🎫 Complaint / Support', 'gu' => '🎫 ફરિયાદ / સપોર્ટ', 'hi' => '🎫 शिकायत / सपोर्ट'],
        'm_ticket_d' => ['en' => 'Open a support ticket', 'gu' => 'ટિકિટ ખોલો', 'hi' => 'टिकट खोलें'],
        'm_location' => ['en' => '📍 Shop Address', 'gu' => '📍 દુકાનનું સરનામું', 'hi' => '📍 दुकान का पता'],
        'm_location_d' => ['en' => 'Contact details', 'gu' => 'સંપર્ક વિગત', 'hi' => 'संपर्क विवरण'],
        'm_staff' => ['en' => '📞 Talk to Staff', 'gu' => '📞 માણસ સાથે વાત', 'hi' => '📞 स्टाफ से बात'],
        'm_staff_d' => ['en' => 'Direct contact', 'gu' => 'સીધો સંપર્ક', 'hi' => 'सीधा संपर्क'],
        'm_lang' => ['en' => '🌐 Change Language', 'gu' => '🌐 ભાષા બદલો', 'hi' => '🌐 भाषा बदलें'],
        'm_lang_d' => ['en' => 'English / ગુજરાતી / हिंदी'],
        // ---------- bills ----------
        'bills_none' => ['en' => "🙏 *{shop}*\nNo bills found on this number. If your bills carry this mobile they show up here — type 'staff' and we'll link them.", 'gu' => "🙏 *{shop}*\nઆ નંબર પર કોઈ બિલ નથી મળ્યું. બિલમાં આ મોબાઈલ નોંધાયેલો હશે તો અહીં દેખાશે — 'staff' લખો તો અમે જોડી આપીશું.", 'hi' => "🙏 *{shop}*\nइस नंबर पर कोई बिल नहीं मिला। बिल में यह मोबाइल दर्ज होगा तो यहाँ दिखेगा — 'staff' लिखें, हम जोड़ देंगे।"],
        'bills_head' => ['en' => '🧾 *Your recent bills*', 'gu' => '🧾 *તમારા છેલ્લા બિલ*', 'hi' => '🧾 *आपके हाल के बिल*'],
        'bills_hint' => ['en' => '👉 Reply with a number — bill details + PDF link follow', 'gu' => '👉 નંબર લખો — બિલની વિગત + PDF લિંક મળશે', 'hi' => '👉 नंबर लिखें — बिल विवरण + PDF लिंक मिलेगा'],
        'bills_body' => ['en' => 'Pick a bill — details and the PDF download link follow.', 'gu' => 'બિલ પસંદ કરો — વિગત અને PDF ડાઉનલોડ લિંક તરત મળશે.', 'hi' => 'बिल चुनें — विवरण और PDF डाउनलोड लिंक तुरंत मिलेगा।'],
        'bills_btn' => ['en' => 'View bill', 'gu' => 'બિલ જુઓ', 'hi' => 'बिल देखें'],
        'bills_sec' => ['en' => 'Bills', 'gu' => 'બિલ', 'hi' => 'बिल'],
        'due_w' => ['en' => 'due', 'gu' => 'બાકી', 'hi' => 'बकाया'],
        'bill_title' => ['en' => '🧾 *Bill {no}*'],
        'bill_total' => ['en' => '💰 Total:', 'gu' => '💰 કુલ:', 'hi' => '💰 कुल:'],
        'bill_due' => ['en' => '🔴 Due:', 'gu' => '🔴 બાકી:', 'hi' => '🔴 बकाया:'],
        'bill_paid' => ['en' => '✅ Fully paid', 'gu' => '✅ પૂરું ચૂકવેલું', 'hi' => '✅ पूरा भुगतान'],
        'bill_view' => ['en' => '📄 View bill:', 'gu' => '📄 બિલ જુઓ:', 'hi' => '📄 बिल देखें:'],
        'bill_denied' => ['en' => "🙏 This bill isn't linked with this number.", 'gu' => '🙏 આ બિલ આ નંબર સાથે જોડાયેલું નથી.', 'hi' => '🙏 यह बिल इस नंबर से जुड़ा नहीं है।'],
        'btn_paybill' => ['en' => '💳 Pay this bill', 'gu' => '💳 આ બિલ ભરો', 'hi' => '💳 यह बिल भरें'],
        'pay_hint' => ['en' => "💳 Type 'pay' to pay online", 'gu' => "💳 ઓનલાઇન ભરવા 'pay' લખો", 'hi' => "💳 ऑनलाइन भरने के लिए 'pay' लिखें"],
        'inv_notfound' => ['en' => "🙏 Bill *{no}* isn't linked with this number. Use the mobile number on the bill, or type 'staff'.", 'gu' => "🙏 બિલ *{no}* આ નંબર સાથે જોડાયેલું નથી મળ્યું. બિલ પરનો નોંધાયેલો નંબર જ વાપરો, અથવા 'staff' લખો.", 'hi' => "🙏 बिल *{no}* इस नंबर से जुड़ा नहीं मिला। बिल पर दर्ज नंबर ही उपयोग करें, या 'staff' लिखें।"],
        // ---------- statement ----------
        'stmt_title' => ['en' => '💰 *{shop} — Statement*', 'gu' => '💰 *{shop} — હિસાબ*', 'hi' => '💰 *{shop} — हिसाब*'],
        'stmt_due' => ['en' => 'You owe: *₹{amt}*', 'gu' => 'તમારા બાકી: *₹{amt}* (આપવાના)', 'hi' => 'आपका बकाया: *₹{amt}*'],
        'stmt_adv' => ['en' => 'Your advance / credit: *₹{amt}*', 'gu' => 'તમારી જમા: *₹{amt}*', 'hi' => 'आपकी जमा: *₹{amt}*'],
        'stmt_clear' => ['en' => 'Your account is all clear ✅', 'gu' => 'હિસાબ ચોખ્ખો છે ✅', 'hi' => 'आपका हिसाब साफ है ✅'],
        'stmt_last' => ['en' => '*Recent entries:*', 'gu' => '*છેલ્લી એન્ટ્રી:*', 'hi' => '*हाल की एंट्री:*'],
        'lbl_bill' => ['en' => 'Bill', 'gu' => 'બિલ', 'hi' => 'बिल'],
        'lbl_pay_in' => ['en' => 'Payment received', 'gu' => 'ચુકવણી મળી', 'hi' => 'भुगतान मिला'],
        'lbl_pay_out' => ['en' => 'Payment made', 'gu' => 'ચુકવણી કરી', 'hi' => 'भुगतान किया'],
        'stmt_footer' => ['en' => "🧾 Type 'bill' for bill PDFs", 'gu' => "🧾 બિલની PDF માટે 'bill' લખો", 'hi' => "🧾 बिल PDF के लिए 'bill' लिखें"],
        'stmt_pay' => ['en' => " · 💳 Type 'pay' to pay", 'gu' => " · 💳 ભરવા 'pay' લખો", 'hi' => " · 💳 भरने के लिए 'pay' लिखें"],
        // ---------- payment ----------
        'pay_clear' => ['en' => "✅ *{shop}*\nNothing due — your account is clear. Thank you! 🙏", 'gu' => "✅ *{shop}*\nકંઈ બાકી નથી — હિસાબ ચોખ્ખો છે. આભાર! 🙏", 'hi' => "✅ *{shop}*\nकुछ बकाया नहीं — हिसाब साफ है। धन्यवाद! 🙏"],
        'pay_link' => ['en' => "💳 *{shop} — Online Payment*\nAmount: *₹{amt}*\n\nPay securely by UPI / Card using this link:\n{link}\n\nIt gets credited in our books automatically ✅", 'gu' => "💳 *{shop} — ઓનલાઇન પેમેન્ટ*\nરકમ: *₹{amt}*\n\nઆ સેફ લિંકથી UPI/કાર્ડથી ભરો:\n{link}\n\nપેમેન્ટ થતાં જ અમારા ચોપડે આપોઆપ જમા થઈ જશે ✅", 'hi' => "💳 *{shop} — ऑनलाइन पेमेंट*\nराशि: *₹{amt}*\n\nइस सुरक्षित लिंक से UPI/कार्ड से भरें:\n{link}\n\nपेमेंट होते ही हमारे खाते में अपने आप जमा हो जाएगा ✅"],
        'pay_manual' => ['en' => "💳 *{shop}*\nAmount due: *₹{amt}*\nOur team will contact you for the payment right away. 🙏", 'gu' => "💳 *{shop}*\nબાકી રકમ: *₹{amt}*\nઅમારા માણસ ચુકવણી માટે તરત સંપર્ક કરશે. 🙏", 'hi' => "💳 *{shop}*\nबकाया राशि: *₹{amt}*\nहमारी टीम भुगतान के लिए तुरंत संपर्क करेगी। 🙏"],
        // ---------- repairs ----------
        'repairs_none' => ['en' => "🙏 *{shop}*\nNo repair jobs on this number.", 'gu' => '🙏 *{shop}*' . "\nઆ નંબર પર કોઈ રિપેર જોબ નથી.", 'hi' => "🙏 *{shop}*\nइस नंबर पर कोई रिपेयर जॉब नहीं है।"],
        'repairs_head' => ['en' => '🔧 *Your repair jobs*', 'gu' => '🔧 *તમારા રિપેર જોબ*', 'hi' => '🔧 *आपके रिपेयर जॉब*'],
        'repairs_hint' => ['en' => '👉 Reply with a number — full status follows', 'gu' => '👉 નંબર લખો — આખું સ્ટેટસ મળશે', 'hi' => '👉 नंबर लिखें — पूरा स्टेटस मिलेगा'],
        'repairs_body' => ['en' => 'Pick a job — live status and the report link follow.', 'gu' => 'જોબ પસંદ કરો — લાઈવ સ્ટેટસ અને રિપોર્ટ લિંક મળશે.', 'hi' => 'जॉब चुनें — लाइव स्टेटस और रिपोर्ट लिंक मिलेगा।'],
        'repairs_btn' => ['en' => 'View job', 'gu' => 'જોબ જુઓ', 'hi' => 'जॉब देखें'],
        'repairs_sec' => ['en' => 'Repairs', 'gu' => 'રિપેર', 'hi' => 'रिपेयर'],
        'repair_denied' => ['en' => "🙏 This job isn't linked with this number.", 'gu' => '🙏 આ જોબ આ નંબર સાથે જોડાયેલો નથી.', 'hi' => '🙏 यह जॉब इस नंबर से जुड़ा नहीं है।'],
        'rj_title' => ['en' => '🔧 *Job {no}*'],
        'rj_received' => ['en' => '🗓 Received:', 'gu' => '🗓 જમા:', 'hi' => '🗓 जमा:'],
        'rj_problem' => ['en' => '📋 Issue:', 'gu' => '📋 તકલીફ:', 'hi' => '📋 समस्या:'],
        'rj_status' => ['en' => 'Status', 'gu' => 'સ્ટેટસ', 'hi' => 'स्टेटस'],
        'rj_est' => ['en' => '💰 Estimated cost: ₹{amt}', 'gu' => '💰 અંદાજિત ખર્ચ: ₹{amt}', 'hi' => '💰 अनुमानित खर्च: ₹{amt}'],
        'rj_charge' => ['en' => '💰 Charge: ₹{amt}', 'gu' => '💰 ચાર્જ: ₹{amt}', 'hi' => '💰 चार्ज: ₹{amt}'],
        'rj_report' => ['en' => '📄 Service report:', 'gu' => '📄 સર્વિસ રિપોર્ટ:', 'hi' => '📄 सर्विस रिपोर्ट:'],
        'st_received' => ['en' => '📥 Received', 'gu' => '📥 જમા થયું', 'hi' => '📥 जमा हुआ'],
        'st_in_progress' => ['en' => '🔧 Repair in progress', 'gu' => '🔧 રિપેર ચાલુ છે', 'hi' => '🔧 रिपेयर चालू है'],
        'st_outsourced' => ['en' => '🏭 Sent out for repair', 'gu' => '🏭 બહાર રિપેરમાં', 'hi' => '🏭 बाहर रिपेयर में'],
        'st_ready' => ['en' => '✅ Ready — please collect!', 'gu' => '✅ તૈયાર છે - લઈ જાવ!', 'hi' => '✅ तैयार है — ले जाएँ!'],
        'st_delivered' => ['en' => '📦 Delivered', 'gu' => '📦 ડિલિવર થઈ ગયું', 'hi' => '📦 डिलीवर हो गया'],
        'st_returned_unrepaired' => ['en' => '↩️ Returned unrepaired', 'gu' => '↩️ રિપેર વગર પરત', 'hi' => '↩️ बिना रिपेयर वापस'],
        // ---------- warranty ----------
        'warr_head' => ['en' => '🛡 *{shop} — Warranty*', 'gu' => '🛡 *{shop} — વોરંટી*', 'hi' => '🛡 *{shop} — वारंटी*'],
        'warr_none' => ['en' => "No serial-numbered products found on this number's bills.", 'gu' => 'આ નંબરના બિલમાં કોઈ સિરિયલ-નંબરવાળી પ્રોડક્ટ નથી.', 'hi' => 'इस नंबर के बिलों में कोई सीरियल-नंबर वाला प्रोडक्ट नहीं है।'],
        'warr_hint' => ['en' => "🔎 Send any product's *serial number* — its warranty card comes instantly.\nType 'staff' for a claim.", 'gu' => "🔎 કોઈ પ્રોડક્ટનો *સિરિયલ નંબર લખી મોકલો* — એનું વોરંટી કાર્ડ તરત મળશે.\nક્લેમ માટે 'staff' લખો.", 'hi' => "🔎 किसी प्रोडक्ट का *सीरियल नंबर भेजें* — उसका वारंटी कार्ड तुरंत मिलेगा।\nक्लेम के लिए 'staff' लिखें।"],
        'w_till' => ['en' => 'till', 'gu' => 'સુધી', 'hi' => 'तक'],
        'w_norec' => ['en' => 'warranty not recorded', 'gu' => 'વોરંટી નોંધ નથી', 'hi' => 'वारंटी दर्ज नहीं'],
        'wc_title' => ['en' => '🛡 *Warranty Card*', 'gu' => '🛡 *વોરંટી કાર્ડ*', 'hi' => '🛡 *वारंटी कार्ड*'],
        'wc_bill' => ['en' => '🧾 Bill:', 'gu' => '🧾 બિલ:', 'hi' => '🧾 बिल:'],
        'wc_warr' => ['en' => '⏳ Warranty:', 'gu' => '⏳ વોરંટી:', 'hi' => '⏳ वारंटी:'],
        'wc_active' => ['en' => '— ✅ active', 'gu' => '— ✅ ચાલુ છે', 'hi' => '— ✅ चालू है'],
        'wc_over' => ['en' => '— ❌ expired', 'gu' => '— ❌ પૂરી થઈ ગઈ', 'hi' => '— ❌ खत्म हो गई'],
        'wc_none' => ['en' => "Warranty details not recorded — type 'staff'.", 'gu' => "વોરંટી વિગત નોંધાયેલી નથી — 'staff' લખો.", 'hi' => "वारंटी विवरण दर्ज नहीं — 'staff' लिखें।"],
        'wc_claim' => ['en' => "To claim, type 'staff' — we'll help right away.", 'gu' => "ક્લેમ કરવો હોય તો 'staff' લખો — અમે તરત મદદ કરીશું.", 'hi' => "क्लेम के लिए 'staff' लिखें — हम तुरंत मदद करेंगे।"],
        // ---------- web orders ----------
        'orders_none' => ['en' => "🙏 *{shop}*\nNo online orders on this number.\n🛒 Type 'catalog' to order!", 'gu' => "🙏 *{shop}*\nઆ નંબર પર કોઈ ઓનલાઇન ઓર્ડર નથી.\n🛒 ઓર્ડર કરવા 'catalog' લખો!", 'hi' => "🙏 *{shop}*\nइस नंबर पर कोई ऑनलाइन ऑर्डर नहीं है।\n🛒 ऑर्डर करने के लिए 'catalog' लिखें!"],
        'orders_head' => ['en' => '📦 *Your orders*', 'gu' => '📦 *તમારા ઓર્ડર*', 'hi' => '📦 *आपके ऑर्डर*'],
        'ost_new' => ['en' => '🆕 New', 'gu' => '🆕 નવો', 'hi' => '🆕 नया'],
        'ost_confirmed' => ['en' => '✅ Confirmed', 'gu' => '✅ કન્ફર્મ', 'hi' => '✅ कन्फर्म'],
        'ost_completed' => ['en' => '📦 Completed', 'gu' => '📦 પૂરો થયો', 'hi' => '📦 पूरा हुआ'],
        'ost_cancelled' => ['en' => '❌ Cancelled', 'gu' => '❌ કેન્સલ', 'hi' => '❌ रद्द'],
        // ---------- quotations ----------
        'quotes_none' => ['en' => "🙏 *{shop}*\nNo open quotations.", 'gu' => "🙏 *{shop}*\nકોઈ ખુલ્લું કોટેશન નથી.", 'hi' => "🙏 *{shop}*\nकोई खुला कोटेशन नहीं है।"],
        'q_title' => ['en' => '📋 *Quotation {no}*', 'gu' => '📋 *કોટેશન {no}*', 'hi' => '📋 *कोटेशन {no}*'],
        'q_note' => ['en' => "If you approve, we'll start the work right away.", 'gu' => 'મંજૂર કરશો તો અમે તરત કામ શરૂ કરીશું.', 'hi' => 'मंज़ूर करेंगे तो हम तुरंत काम शुरू करेंगे।'],
        'btn_qacc' => ['en' => '✅ Approve', 'gu' => '✅ મંજૂર કરો', 'hi' => '✅ मंज़ूर करें'],
        'btn_qrej' => ['en' => '❌ Decline', 'gu' => '❌ નામંજૂર', 'hi' => '❌ नामंज़ूर'],
        'q_text_hint' => ['en' => "✅ To approve reply: *ok {no}*\n❌ To decline: *no {no}*", 'gu' => "✅ મંજૂર કરવા લખો: *ok {no}*\n❌ નામંજૂર માટે: *no {no}*", 'hi' => "✅ मंज़ूर करने के लिए लिखें: *ok {no}*\n❌ नामंज़ूर के लिए: *no {no}*"],
        'q_gone' => ['en' => '🙏 This quotation is no longer open.', 'gu' => '🙏 આ કોટેશન હવે ખુલ્લું નથી.', 'hi' => '🙏 यह कोटेशन अब खुला नहीं है।'],
        'q_acc_done' => ['en' => "✅ Quotation *{no}* approved — thank you! Our team will contact you and start right away. 🙏", 'gu' => "✅ કોટેશન *{no}* મંજૂર થયું — આભાર! અમારા માણસ તરત સંપર્ક કરી કામ શરૂ કરશે. 🙏", 'hi' => "✅ कोटेशन *{no}* मंज़ूर हुआ — धन्यवाद! हमारी टीम तुरंत संपर्क कर काम शुरू करेगी। 🙏"],
        'q_rej_done' => ['en' => "Noted — quotation *{no}* declined. Want to discuss the price? Type 'staff'. 🙏", 'gu' => "નોંધ્યું — કોટેશન *{no}* નામંજૂર. કિંમત વિશે વાત કરવી હોય તો 'staff' લખો. 🙏", 'hi' => "नोट कर लिया — कोटेशन *{no}* नामंज़ूर। कीमत पर बात करनी हो तो 'staff' लिखें। 🙏"],
        'q_notfound' => ['en' => "🙏 That quotation number wasn't found.", 'gu' => '🙏 આ કોટેશન નંબર મળ્યો નહીં.', 'hi' => '🙏 वह कोटेशन नंबर नहीं मिला।'],
        // ---------- ticket / staff ----------
        'ticket_ask' => ['en' => "🎫 *{shop} — Support*\nWrite your complaint / question *in one message* — a ticket opens and our team contacts you.", 'gu' => "🎫 *{shop} — સપોર્ટ*\nતમારી ફરિયાદ / સવાલ *એક મેસેજમાં* લખી મોકલો — ટિકિટ ખૂલી જશે અને અમારા માણસ સંપર્ક કરશે.", 'hi' => "🎫 *{shop} — सपोर्ट*\nअपनी शिकायत / सवाल *एक मैसेज में* लिखकर भेजें — टिकट खुल जाएगा और हमारी टीम संपर्क करेगी।"],
        'ticket_done' => ['en' => "✅ Complaint registered!\n🎫 Ticket no: *{no}*\n\nOur team will contact you soon. Type 'ticket' anytime for the status.", 'gu' => "✅ ફરિયાદ નોંધાઈ ગઈ!\n🎫 ટિકિટ નં: *{no}*\n\nઅમારા માણસ જલદી સંપર્ક કરશે. સ્ટેટસ પૂછવા આ નંબર પર 'ticket' લખો.", 'hi' => "✅ शिकायत दर्ज हो गई!\n🎫 टिकट नं: *{no}*\n\nहमारी टीम जल्द संपर्क करेगी। स्टेटस के लिए कभी भी 'ticket' लिखें।"],
        'staff_ack' => ['en' => "✅ Our team has been notified — you'll get a reply right here shortly. 🙏\n📞 Urgent? Call: {phone}", 'gu' => "✅ અમારા માણસને જાણ કરી દીધી છે — થોડી જ વારમાં અહીં જ જવાબ મળશે. 🙏\n📞 તાત્કાલિક હોય તો કૉલ કરો: {phone}", 'hi' => "✅ हमारी टीम को सूचित कर दिया है — थोड़ी ही देर में यहीं जवाब मिलेगा। 🙏\n📞 तुरंत ज़रूरत हो तो कॉल करें: {phone}"],
    ];
    return $S;
}
