<?php
// Help: the questions shop people really ask, answered in their language.
// The answer to a question is found here first (by matching words, the same
// every time); only when nothing here fits is the AI asked - with the
// question and this help text alone, never the shop's data.

/** [question words, [en, gu]] - hi falls back to en. */
function help_faq() {
    return [
        ['bill sale invoice new make banavu બિલ', ['Make a bill: tap ➕ New Bill (or Alt+N). Pick Cash or Credit, the customer, then Add Items. Press Save — the bill, stock and payment are all done together.',
            'બિલ બનાવવું: ➕ નવું બિલ દબાવો (અથવા Alt+N). રોકડ કે ઉધાર પસંદ કરો, ગ્રાહક પસંદ કરો, પછી આઇટમ ઉમેરો. સેવ દબાવો — બિલ, સ્ટોક અને પેમેન્ટ સાથે જ થઈ જાય.']],
        ['payment receive collect money udhar paisa પૈસા ઉઘરાણી', ['Money received from a customer: Party Payments → New (or Alt+P). Pick the party and the amount; it is set against their oldest bills on its own.',
            'ગ્રાહક પાસેથી પૈસા આવ્યા: પાર્ટી પેમેન્ટ → નવું (અથવા Alt+P). પાર્ટી અને રકમ પસંદ કરો; જૂના બિલ સામે આપોઆપ જમા થશે.']],
        ['item product add new stock price આઇટમ', ['Add an item: Items → All Items → ➕. Give the name, selling price and GST. For many items at once use Items → Import Excel.',
            'આઇટમ ઉમેરવી: આઇટમ → બધી આઇટમ → ➕. નામ, વેચાણ ભાવ અને GST લખો. ઘણી આઇટમ એકસાથે માટે આઇટમ → Excel માંથી લાવો.']],
        ['purchase buy supplier goods khareedi ખરીદી', ['Goods came in: Purchase → Purchase Bills → ➕. Stock goes up when you save. A photo of the supplier bill can be read for you with Scan Bill.',
            'માલ આવ્યો: ખરીદી → ખરીદી બિલ → ➕. સેવ કરતાં સ્ટોક વધી જાય. સપ્લાયરના બિલનો ફોટો બિલ સ્કેનથી વાંચી શકાય.']],
        ['expense kharch spend rent salary ખર્ચ', ['Shop spending (rent, salary, tea…): Expenses → Add expense (Alt+E). The category is suggested as you type.',
            'દુકાનનો ખર્ચ (ભાડું, પગાર, ચા…): ખર્ચ → ખર્ચ ઉમેરો (Alt+E). લખતાં જ કેટેગરી સૂચવાય છે.']],
        ['whatsapp send bill share pdf મોકલ', ['Send a bill on WhatsApp: open the bill and tap WhatsApp. The customer gets the PDF and a pay link.',
            'બિલ WhatsApp પર મોકલવું: બિલ ખોલો અને WhatsApp દબાવો. ગ્રાહકને PDF અને પેમેન્ટ લિંક મળશે.']],
        ['staff user login employee password સ્ટાફ', ['Add staff: Staff & Company → Staff Users → ➕. Give each person their own login; a role decides what they can see.',
            'સ્ટાફ ઉમેરવો: સ્ટાફ અને કંપની → સ્ટાફ → ➕. દરેકને અલગ લૉગિન આપો; રોલ નક્કી કરે કે તે શું જોઈ શકે.']],
        ['gst gstin tax invoice firm ટેક્સ', ['GST bills: set your GSTIN in Companies / Firms (or the setup steps). Pick the GST firm on the bill; CGST and SGST are worked out for you.',
            'GST બિલ: કંપની / ફર્મમાં GSTIN લખો. બિલમાં GST ફર્મ પસંદ કરો; CGST અને SGST આપોઆપ ગણાશે.']],
        ['language gujarati hindi bhasha font text size big ભાષા', ['Language and text size: your avatar (top right) → Display & language.',
            'ભાષા અને અક્ષરનું કદ: ઉપર જમણે તમારું નામ → દેખાવ અને ભાષા.']],
        ['report profit sale today month રિપોર્ટ નફો', ['Reports → All Reports shows sale, profit, stock and dues. The dashboard shows today at a glance; 🔊 reads it aloud.',
            'રિપોર્ટ → બધા રિપોર્ટમાં વેચાણ, નફો, સ્ટોક અને બાકી. ડેશબોર્ડ પર આજનું એક નજરમાં; 🔊 બોલીને સંભળાવે.']],
        ['cancel delete wrong mistake undo bill ભૂલ રદ', ['A wrong bill: open it → Cancel. Stock and the customer account go back exactly. A cancelled bill stays in the list, marked cancelled.',
            'ખોટું બિલ: ખોલો → રદ કરો. સ્ટોક અને ગ્રાહકનું ખાતું પહેલા જેવું થઈ જાય. રદ બિલ યાદીમાં રદ તરીકે દેખાય.']],
        ['backup data safe export download', ['Your data is backed up every day. The owner can download everything from My plan → Export (or Settings → Backup).',
            'તમારો ડેટા રોજ બેકઅપ થાય છે. માલિક મારો પ્લાન → Export (અથવા સેટિંગ → બેકઅપ)થી બધું ડાઉનલોડ કરી શકે.']],
    ];
}

/** The best FAQ answers for a question: [[score, en, gu], ...], best first. Deterministic. */
function help_search($q) {
    $words = array_filter(preg_split('/[\s,.?!]+/u', mb_strtolower((string)$q)), fn($w) => mb_strlen($w) >= 3);
    $out = [];
    foreach (help_faq() as [$keys, $ans]) {
        $hay = mb_strtolower($keys . ' ' . $ans[0] . ' ' . $ans[1]);
        $score = 0;
        foreach ($words as $w) if (mb_strpos($hay, $w) !== false) $score += mb_strpos(mb_strtolower($keys), $w) !== false ? 2 : 1;
        if ($score > 0) $out[] = [$score, $ans[0], $ans[1]];
    }
    usort($out, fn($a, $b) => $b[0] <=> $a[0]);
    return $out;
}

/** Answer a "how do I" question: from the FAQ when it clearly fits, else the AI (if on), else the closest FAQ. [text, source] */
function help_answer($q, $lang = 'en') {
    $q = trim(mb_substr((string)$q, 0, 300));
    if ($q === '') return ['', ''];
    $pick = fn($r) => $lang === 'gu' ? $r[2] : $r[1];
    $hits = help_search($q);
    if ($hits && $hits[0][0] >= 3) return [$pick($hits[0]), 'faq'];
    if (function_exists('ai_ask')) {
        $guide = implode("\n", array_map(fn($f) => '- ' . $f[1][0], help_faq()));
        $menu = [];
        foreach (nav_menu() as $m) $menu[] = $m[0] === 'link' ? $m[3] : $m[3] . ': ' . implode(', ', array_column($m[4], 1));
        $prompt = "You help staff of an Indian shop use their billing software. Answer in at most 4 short lines, in "
                . (['gu' => 'Gujarati', 'hi' => 'Hindi'][$lang] ?? 'simple English');
        $prompt .= ". Use only these facts; if they do not cover it, say to ask the owner.\nMenu: " . implode(' | ', $menu)
                . "\nGuide:\n$guide\n\nQuestion: " . ai_safe_text($q, 300);
        [$out, ] = ai_ask('help', [['text' => $prompt]], 20);
        if ($out !== null && trim($out) !== '') return [trim(mb_substr($out, 0, 900)), 'ai'];
    }
    return $hits ? [$pick($hits[0]), 'faq'] : ['', ''];
}

/** Help videos the platform owner listed ("Title | link" lines), for every shop. */
function help_videos() {
    $raw = function_exists('pval') ? (string)pval("SELECT value FROM settings WHERE name = 'help_videos'") : (string)setting('help_videos', '');
    $out = [];
    foreach (preg_split('/\r?\n/', $raw) as $ln) {
        $p = array_map('trim', explode('|', $ln, 2));
        if (count($p) === 2 && preg_match('~^https://~', $p[1])) $out[] = $p;
    }
    return $out;
}
