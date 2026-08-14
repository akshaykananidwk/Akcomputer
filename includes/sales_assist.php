<?php
// ============================================================================
//  SALES ASSISTANT — AI reads the sentence, code reads the shop
// ============================================================================
//  A customer says "ઘર માટે wifi વાળું રાઉટર જોઈએ, 2000 સુધીમાં". The shop's
//  catalogue calls it "TP-Link Archer C6 AC1200 Dual Band Router". The search
//  box finds nothing, and the man behind the counter scrolls.
//
//  That gap - between how a customer speaks and how the catalogue is spelt -
//  is the ONLY job given to the AI here. It receives the sentence and returns
//  search words. It never sees a price, never returns a price, and its answer
//  is thrown away unless the shop's own catalogue confirms it.
//
//  Everything with a rupee sign in it - stock, rate, margin, this customer's
//  last price, their outstanding, what to sell alongside - is read from the
//  database by the code below. If the AI is off, out of budget, or down, the
//  screen still works: sa_keywords() simply hands back the words the owner
//  typed, and the rest of the file does not know the difference.
//
//  And nothing here writes a row. The assistant ends at a button that opens
//  the ordinary bill screen with the items filled in; a human then saves it,
//  through the same code that has always saved bills.
// ============================================================================

function sa_rules() {
    return [
        'pool'      => 12,   // how many matches to show
        'cross'     => 5,    // how many "sells with this" suggestions
        'months'    => 12,   // how far back the together-bought history looks
        'min_word'  => 2,
    ];
}

/** Words the search should ignore - they match half the catalogue and mean
 *  nothing on their own. Kept short on purpose; over-filtering loses real
 *  product words. */
function sa_stopwords() {
    return ['જોઈએ', 'છે', 'માટે', 'નું', 'ના', 'ની', 'એક', 'સારું', 'સારો', 'નવું', 'નવો',
            'આપો', 'બતાવો', 'ભાવ', 'કિંમત', 'સુધી', 'માં', 'ગ્રાહક', 'ગ્રાહકને', 'લેવું', 'છું',
            'need', 'want', 'for', 'the', 'a', 'an', 'me', 'my', 'is', 'are', 'with',
            'under', 'below', 'budget', 'price', 'show', 'give', 'good', 'best', 'new'];
}

/** Split a free-text need into usable search words. */
function sa_words($q) {
    $r = sa_rules();
    $stop = sa_stopwords();
    $parts = preg_split('/[\s,.\/\-()]+/u', (string)$q);
    $out = [];
    foreach ($parts as $w) {
        $w = trim($w);
        if ($w === '' || mb_strlen($w) < $r['min_word']) continue;
        if (preg_match('/^[\d,]+$/u', $w)) continue;          // numbers are budget, not words
        if (in_array(mb_strtolower($w), $stop, true)) continue;
        $out[] = $w;
        if (count($out) >= 6) break;
    }
    return $out;
}

/** The budget in the sentence, read by CODE.
 *
 *  Deliberately not asked of the AI: a number the AI invents would be a number
 *  deciding what the shop offers. A plain rule - the largest bare number of
 *  100 or more - is wrong in the same way every time, which is why the screen
 *  shows it as a chip the owner can remove in one tap. */
function sa_budget($q) {
    if (!preg_match_all('/\d[\d,]*/u', (string)$q, $m)) return 0.0;
    $best = 0.0;
    foreach ($m[0] as $n) {
        $v = (float)str_replace(',', '', $n);
        if ($v >= 100 && $v > $best) $best = $v;
    }
    return $best;
}

/** The shop's own category names, as vocabulary for the AI.
 *  Shop data, not customer data - nothing here identifies a person. */
function sa_vocabulary($limit = 60) {
    $rows = all("SELECT c.name FROM categories c
                 JOIN items i ON i.category_id = c.id AND i.is_active = 1
                 GROUP BY c.id ORDER BY COUNT(*) DESC LIMIT $limit");
    return array_column($rows, 'name');
}

/** THE AI step, and the only one. Sentence in, search words out.
 *
 *  Returns ['words' => [...], 'src' => 'typed'|'ai', 'note' => '', 'why' => ''].
 *  'why' is the reason AI was not used, and it is shown on screen, because a
 *  quietly degraded feature is worse than an honest one.
 *
 *  Two cost rules live here: the AI is not called at all when the typed words
 *  already find enough stock (most of the time), and its answer is discarded
 *  unless the catalogue confirms it. */
function sa_keywords($q, $typedHits) {
    $typed = sa_words($q);
    if ($typedHits >= 3) return ['words' => $typed, 'src' => 'typed', 'note' => '', 'why' => 'ટાઇપ કરેલા શબ્દોથી જ મળી ગયું — AI વાપર્યું નથી.'];

    list($ok, $why) = ai_can_call('assistant');
    if (!$ok) return ['words' => $typed, 'src' => 'typed', 'note' => '', 'why' => $why];

    $vocab = sa_vocabulary();
    $prompt = "You are helping a computer/CCTV/internet shop in Gujarat search its OWN product catalogue.\n"
        . "The shopkeeper typed what a customer asked for, in Gujarati, English or both.\n"
        . "Return ONLY search keywords that would appear in a product's name, brand or model in the catalogue.\n"
        . "Rules: English product words only (that is how the catalogue is written). "
        . "No prices, no quantities, no advice, no sentences. 2 to 5 keywords. "
        . "If the request names a product type, use the shop's own words for it.\n"
        . "The shop's categories: " . implode(', ', $vocab) . "\n"
        . "Customer asked: " . ai_safe_text($q, 300) . "\n"
        . 'Reply as JSON only: {"words":["..."],"note":"one short Gujarati line saying what you understood"}';

    list($raw, $err) = ai_ask('assistant', [['text' => $prompt]], 20, true);
    if ($raw === null) return ['words' => $typed, 'src' => 'typed', 'note' => '', 'why' => $err];

    $j = ai_json($raw);
    $words = [];
    foreach ((array)($j['words'] ?? []) as $w) {
        $w = trim(preg_replace('/[^\p{L}\p{N} ]+/u', '', (string)$w));
        if ($w !== '' && mb_strlen($w) >= 2) $words[] = mb_substr($w, 0, 30);
        if (count($words) >= 5) break;
    }
    if (!$words) return ['words' => $typed, 'src' => 'typed', 'note' => '', 'why' => 'AI પાસેથી વાપરી શકાય એવા શબ્દ ન મળ્યા.'];
    return ['words' => $words, 'src' => 'ai', 'note' => mb_substr(trim((string)($j['note'] ?? '')), 0, 120), 'why' => ''];
}

/** Deterministic catalogue search. Every word must appear somewhere in the
 *  item's name, brand, model, barcode or category - the same word-wise rule
 *  the bill screen's own picker uses, widened to the category name.
 *
 *  In-stock items rank above out-of-stock ones, because a shop sells what it
 *  has. Nothing is filtered out for being expensive; a budget only sorts. */
function sa_stock_sql($loc, $alias = 'i') {
    $loc = (int)$loc;
    return "COALESCE((SELECT SUM(s.qty) FROM stock s WHERE s.item_id = $alias.id"
        . ($loc ? " AND s.location_id = $loc" : '') . "), 0)";
}

function sa_search(array $words, $loc, $limit = null, $all = true) {
    $limit = (int)($limit ?: sa_rules()['pool']);
    if (!$words) return [];
    $conds = []; $params = [];
    foreach ($words as $w) {
        $like = '%' . $w . '%';
        // COALESCE is load-bearing, not decoration. c.name is NULL for an item
        // with no category, and "FALSE OR NULL" is NULL in SQL - so a single
        // uncategorised item silently dropped out of the whole search, and the
        // hits score came back NULL and broke the ordering with it.
        $conds[] = "(i.name LIKE ? OR COALESCE(i.brand,'') LIKE ? OR COALESCE(i.model,'') LIKE ?"
                 . " OR COALESCE(i.barcode,'') LIKE ? OR COALESCE(c.name,'') LIKE ?)";
        array_push($params, $like, $like, $like, $like, $like);
    }
    // $all = every word must appear (precise). Otherwise any word will do, and
    // the rows that matched the MOST words come first - see sa_find().
    $hits = implode(' + ', $conds);
    $where = $all ? implode(' AND ', $conds) : "($hits) > 0";
    $params = array_merge($params, $params);   // once for the score, once for the filter
    $params[] = ($words[0] ?? '') . '%';
    $stock = sa_stock_sql($loc);
    return all("SELECT i.id, i.name, i.brand, i.model, i.unit, i.item_type, i.tax_rate, i.photo,
                       i.selling_price, i.b2b_price, i.purchase_price, i.min_stock, c.name category,
                       ($hits) hits,
                       IF(i.item_type = 'service', NULL, $stock) stock
                FROM items i LEFT JOIN categories c ON c.id = i.category_id
                WHERE i.is_active = 1 AND ($where)
                ORDER BY hits DESC,
                         (i.item_type = 'service' OR $stock > 0) DESC,
                         (i.name LIKE ?) DESC, i.name
                LIMIT $limit", $params);
}

/** Search the way a person expects it to work: every word first, and if that
 *  finds nothing, the items that match the most of the words.
 *
 *  This matters with AI off. "ઘર માટે wifi router" strips to ઘર/wifi/router,
 *  and demanding all three finds nothing at all - while the shop plainly has
 *  routers. The second pass is why the screen is still useful without AI. */
function sa_find(array $words, $loc, $limit = null) {
    $rows = sa_search($words, $loc, $limit);
    if ($rows || count($words) < 2) return $rows;
    return sa_search($words, $loc, $limit, false);
}

/** How fast each item moves and when it last sold - one query for the whole
 *  list, so a long result set does not become a long series of queries. */
function sa_movement(array $itemIds, $days = 90) {
    if (!$itemIds) return [];
    $in = implode(',', array_map('intval', $itemIds));
    $rows = all("SELECT si.item_id, SUM(si.qty) qty, MAX(s.sale_date) last_sold
                 FROM sale_items si JOIN sales s ON s.id = si.sale_id
                 WHERE si.item_id IN ($in) AND s.is_cancelled = 0
                   AND s.sale_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
                 GROUP BY si.item_id", [(int)$days]);
    $out = [];
    foreach ($rows as $r) $out[(int)$r['item_id']] = ['qty' => (float)$r['qty'], 'last_sold' => $r['last_sold']];
    return $out;
}

/** What this customer paid for these items last time - one query, not one per
 *  row. Commercial information, so the caller gates it on sales.view. */
function sa_last_prices($partyId, array $itemIds) {
    if (!$partyId || !$itemIds) return [];
    $in = implode(',', array_map('intval', $itemIds));
    $rows = all("SELECT si.item_id, si.price, s.sale_date
                 FROM sale_items si JOIN sales s ON s.id = si.sale_id
                 WHERE s.party_id = ? AND s.is_cancelled = 0 AND si.item_id IN ($in)
                 ORDER BY s.id DESC", [(int)$partyId]);
    $out = [];
    foreach ($rows as $r) if (!isset($out[(int)$r['item_id']])) $out[(int)$r['item_id']] = $r; // first = latest
    return $out;
}

/** "Sells with this" - straight from what actually went on the same bills.
 *  Not a recommendation engine, a count. */
function sa_cross_sell(array $itemIds, $loc, $limit = null) {
    $r = sa_rules();
    $limit = (int)($limit ?: $r['cross']);
    if (!$itemIds) return [];
    $in = implode(',', array_map('intval', $itemIds));
    $stock = sa_stock_sql($loc);
    return all("SELECT i.id, i.name, i.selling_price, COUNT(DISTINCT s.id) bills,
                       IF(i.item_type = 'service', NULL, $stock) stock
                FROM sale_items a
                JOIN sales s ON s.id = a.sale_id AND s.is_cancelled = 0
                JOIN sale_items b ON b.sale_id = a.sale_id AND b.item_id <> a.item_id
                JOIN items i ON i.id = b.item_id AND i.is_active = 1
                WHERE a.item_id IN ($in) AND b.item_id NOT IN ($in)
                  AND s.sale_date >= DATE_SUB(CURDATE(), INTERVAL ? MONTH)
                GROUP BY b.item_id
                HAVING bills >= 2
                ORDER BY bills DESC, i.name LIMIT $limit", [$r['months']]);
}

/** The margin on a suggested rate. Cost data, so the caller gates it on
 *  items.cost - the assistant never shows it to staff who may not see cost. */
function sa_margin(array $it) {
    $cost = (float)$it['purchase_price'];
    $sell = (float)$it['selling_price'];
    if ($cost <= 0 || $sell <= 0) return null;
    return ['amount' => money_r($sell - $cost), 'pct' => round(($sell - $cost) / $sell * 100, 1)];
}

/** Should this sale be on credit at all? Deterministic, from the ledger.
 *  Returns null when there is nothing to warn about. */
function sa_credit_warning($partyId) {
    if (!$partyId || !can('payments.view')) return null;
    $bal = party_balance($partyId);
    if ($bal <= MONEY_EPS) return null;
    $due = cust_outstanding($partyId, $bal);
    $cr = cust_credit($partyId, $bal);
    $lines = [];
    if ($due['overdue'] > MONEY_EPS) $lines[] = '₹' . money($due['overdue']) . ' મુદત વીતી ગયેલું બાકી છે' . ($due['days'] > 0 ? ' (' . $due['days'] . ' દિવસ)' : '');
    if ($cr['over_limit']) $lines[] = 'ચાલુ બાકી ₹' . money($cr['outstanding']) . ' — સૂચવેલી ઉધાર મર્યાદા ₹' . money($cr['suggested']) . ' કરતાં વધારે';
    if (!$lines) return null;
    return ['level' => $due['overdue'] > MONEY_EPS ? 'high' : 'medium', 'lines' => $lines,
            'balance' => $bal, 'reliability' => $cr['reliability']['rating']];
}
