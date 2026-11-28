<?php
// The online store's shared building blocks - one look on every public page
// (home, all products, product, category, brand). Data always comes from the
// shop's own items / stock / sales; nothing here is made up: no invented
// discounts (there is no MRP column, so no "% OFF"), no invented ratings, no
// stock that is not on the shelf.
require_once __DIR__ . '/seo.php';
require_once __DIR__ . '/store_account.php';
require_once __DIR__ . '/item_content.php';

/** Everything a store page needs about the shop and the visitor, read once. */
function store_ctx() {
    static $c = null;
    if ($c !== null) return $c;
    $wa = wa_normalize_number(setting('wa_shop_number'));
    $acct = !empty($_SESSION['web_account_id']) ? row('SELECT * FROM web_accounts WHERE id = ? AND is_active = 1', [(int)$_SESSION['web_account_id']]) : null;
    $co = seo_company();
    $logo = (string)($co['logo'] ?? '');
    return $c = [
        'app' => setting('app_name', 'AK Computer'), 'wa' => strlen($wa) >= 12 ? $wa : '', 'acct' => $acct,
        'pct' => $acct ? (float)$acct['discount_pct'] : 0, 'co' => $co, 'own' => !tenant_active(),
        'logo' => $logo !== '' && is_file(__DIR__ . '/../' . $logo) ? base_url($logo) : '',
        'stock' => web_stock_map(), 'showQty' => web_show_qty(), 'admin' => (bool)(current_user() && can('items.edit')),
        'cust' => sc_current(),
    ];
}

/** Line icons (one SVG sprite per page, used as <svg><use href="#i-x">). */
function store_icon($n, $cls = 'ico') { return '<svg class="' . $cls . '" aria-hidden="true"><use href="#i-' . $n . '"/></svg>'; }
function store_sprite() {
    $p = [
        'menu' => '<path d="M4 7h16M4 12h16M4 17h16"/>', 'search' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
        'heart' => '<path d="M12 20s-7-4.4-9-9a5 5 0 0 1 9-3 5 5 0 0 1 9 3c-2 4.6-9 9-9 9z"/>',
        'cart' => '<path d="M3 4h2l2.4 11.2a2 2 0 0 0 2 1.6h7.7a2 2 0 0 0 2-1.5L21 8H6.2"/><circle cx="10" cy="20" r="1.4"/><circle cx="17" cy="20" r="1.4"/>',
        'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>', 'home' => '<path d="M3 11 12 4l9 7v9a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"/>',
        'grid' => '<rect x="4" y="4" width="7" height="7" rx="1.5"/><rect x="13" y="4" width="7" height="7" rx="1.5"/><rect x="4" y="13" width="7" height="7" rx="1.5"/><rect x="13" y="13" width="7" height="7" rx="1.5"/>',
        'wa' => '<path d="M4 20l1.3-4A8 8 0 1 1 8 18.7z"/><path d="M9 9.5c.3 2.2 2.3 4.2 4.5 4.5l1.2-1.2 1.8.8-.3 1.6c-3.6.4-7.8-3.8-7.4-7.4l1.6-.3.8 1.8z"/>',
        'back' => '<path d="M15 5l-7 7 7 7"/>', 'next' => '<path d="m9 5 7 7-7 7"/>', 'shield' => '<path d="M12 3 5 6v5c0 4.5 3 8.3 7 10 4-1.7 7-5.5 7-10V6z"/><path d="m9 12 2 2 4-4"/>',
        'truck' => '<path d="M3 6h11v9H3zM14 9h4l3 3v3h-7"/><circle cx="7" cy="17" r="1.8"/><circle cx="17" cy="17" r="1.8"/>',
        'camera' => '<path d="M3 7h12.5l2.5 2v3.5L15.5 14H3z"/><circle cx="7" cy="10.5" r="1.8"/><path d="M18 9.5l3-1.5v5l-3-1.5M8 14v4H5"/>',
        'dome' => '<path d="M4 9h16"/><path d="M6 9a6 6 0 0 0 12 0"/><circle cx="12" cy="11.5" r="2.2"/>',
        'laptop' => '<rect x="5" y="5" width="14" height="10" rx="1.5"/><path d="M3 18h18"/>', 'monitor' => '<rect x="3" y="4" width="18" height="12" rx="1.5"/><path d="M9 20h6M12 16v4"/>',
        'wifi' => '<path d="M2.5 9a14 14 0 0 1 19 0M5.5 12.5a9 9 0 0 1 13 0M8.5 16a4.5 4.5 0 0 1 7 0"/><circle cx="12" cy="19" r=".8"/>',
        'router' => '<rect x="3" y="13" width="18" height="6" rx="1.5"/><path d="M7 13 5 6M17 13l2-7M7 16h.01M11 16h.01"/>',
        'hdd' => '<rect x="4" y="3" width="16" height="18" rx="2"/><circle cx="12" cy="10" r="3.5"/><path d="M8 17h3"/>',
        'printer' => '<path d="M7 9V4h10v5"/><rect x="3" y="9" width="18" height="8" rx="1.5"/><path d="M7 14h10v6H7z"/>',
        'mouse' => '<rect x="7" y="3" width="10" height="18" rx="5"/><path d="M12 7v3"/>', 'keyboard' => '<rect x="2" y="7" width="20" height="10" rx="1.5"/><path d="M6 11h.01M10 11h.01M14 11h.01M18 11h.01M7 14h10"/>',
        'cable' => '<path d="M7 3v5M11 3v5M5 8h8v3a4 4 0 0 1-8 0zM9 15v2a4 4 0 0 0 8 0V9"/>', 'bolt' => '<path d="M13 3 5 13h6l-1 8 8-10h-6z"/>',
        'box' => '<path d="M3 7.5 12 3l9 4.5v9L12 21l-9-4.5z"/><path d="m3 7.5 9 4.5 9-4.5M12 12v9"/>', 'tool' => '<path d="M14.7 6.3a4 4 0 0 0-5.4 5.4L3.5 17.5a1.8 1.8 0 0 0 2.5 2.5l5.8-5.8a4 4 0 0 0 5.4-5.4l-2.6 2.6-2.3-.4-.4-2.3z"/>',
        'phone' => '<path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2"/>',
        'share' => '<circle cx="6" cy="12" r="2.5"/><circle cx="18" cy="6" r="2.5"/><circle cx="18" cy="18" r="2.5"/><path d="m8.2 10.8 7.6-3.6M8.2 13.2l7.6 3.6"/>',
        'star' => '<path d="m12 3 2.8 5.7 6.2.9-4.5 4.4 1 6.2L12 17.3 6.5 20.2l1-6.2L3 9.6l6.2-.9z"/>', 'x' => '<path d="M6 6l12 12M18 6 6 18"/>',
        'filter' => '<path d="M4 6h16M7 12h10M10 18h4"/>', 'moon' => '<path d="M20 14.5A8 8 0 0 1 9.5 4 8 8 0 1 0 20 14.5z"/>', 'tag' => '<path d="M3 12V4h8l10 10-8 8z"/><circle cx="7.5" cy="8.5" r="1.3"/>',
        'pin' => '<path d="M12 21s-7-6.2-7-11.5a7 7 0 0 1 14 0C19 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/>', 'check' => '<path d="m5 12 4.5 4.5L19 7"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>', 'store' => '<path d="M4 9 5.5 4h13L20 9M4 9h16v1a3 3 0 0 1-5.3 2 3 3 0 0 1-5.4 0A3 3 0 0 1 4 10zM5 13v7h14v-7"/>',
        'bag' => '<path d="M5 8h14l-1 12H6z"/><path d="M9 8a3 3 0 0 1 6 0"/>', 'spark' => '<path d="M12 3v4M12 17v4M3 12h4M17 12h4M6 6l2.5 2.5M15.5 15.5 18 18M6 18l2.5-2.5M15.5 8.5 18 6"/>',
        'fire' => '<path d="M12 21a6 6 0 0 0 6-6c0-4-3-6-3-10-2 1.5-4 4-4 6-1-1-1.5-2-1.5-3C7 9.5 6 12 6 15a6 6 0 0 0 6 6z"/>',
        'lock' => '<rect x="5" y="10" width="14" height="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>', 'eye' => '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
        'receipt' => '<path d="M6 3h12v18l-3-2-3 2-3-2-3 2z"/><path d="M9 8h6M9 12h6"/>', 'night' => '<path d="M15 4a7 7 0 1 0 5 11.5A6 6 0 0 1 15 4z"/>',
        'hd' => '<rect x="3" y="6" width="18" height="12" rx="2"/><path d="M7 9.5v5M10 9.5v5M7 12h3M13 9.5v5h1.5a2.5 2.5 0 0 0 0-5z"/>',
        'chip' => '<rect x="6" y="6" width="12" height="12" rx="1.5"/><rect x="9.5" y="9.5" width="5" height="5"/><path d="M9 3v3M15 3v3M9 18v3M15 18v3M3 9h3M3 15h3M18 9h3M18 15h3"/>',
    ];
    $s = '<svg width="0" height="0" style="position:absolute" aria-hidden="true">';
    foreach ($p as $k => $v) $s .= '<symbol id="i-' . $k . '" viewBox="0 0 24 24">' . $v . '</symbol>';
    return $s . '</svg>';
}

/** Which line icon fits a category / group name. */
function store_cat_icon($name) {
    $n = mb_strtolower((string)$name);
    foreach (['dome' => 'dome', 'cctv' => 'camera', 'camera' => 'camera', 'security' => 'camera', 'dvr' => 'hdd', 'nvr' => 'hdd', 'laptop' => 'laptop',
              'monitor' => 'monitor', 'display' => 'monitor', 'desktop' => 'monitor', 'computer' => 'monitor', 'printer' => 'printer', 'toner' => 'printer', 'ink' => 'printer',
              'router' => 'router', 'wifi' => 'wifi', 'network' => 'wifi', 'switch' => 'router', 'storage' => 'hdd', 'ssd' => 'hdd', 'hdd' => 'hdd', 'pendrive' => 'hdd', 'memory' => 'chip', 'ram' => 'chip',
              'processor' => 'chip', 'mouse' => 'mouse', 'keyboard' => 'keyboard', 'peripheral' => 'keyboard', 'cable' => 'cable', 'connector' => 'cable', 'power' => 'bolt', 'ups' => 'bolt',
              'smps' => 'bolt', 'adapter' => 'bolt', 'battery' => 'bolt', 'software' => 'spark', 'service' => 'tool', 'repair' => 'tool'] as $k => $ic)
        if (strpos($n, $k) !== false) return $ic;
    return 'box';
}

/** Group = the main category (parent) of an item, else its own category. */
function store_parents() {
    static $p = null;
    if ($p === null) { $p = []; try { foreach (all('SELECT c.name child, pc.name parent FROM categories c JOIN categories pc ON pc.id = c.parent_id') as $r) $p[$r['child']] = $r['parent']; } catch (Exception $e) {} }
    return $p;
}
function store_group($it) { $c = (string)($it['cat_name'] ?? ''); return store_parents()[$c] ?? $c; }

/** Live products for the website, with their category name. */
function store_items() {
    static $rows = null;
    if ($rows === null) $rows = all('SELECT i.*, c.name cat_name FROM items i LEFT JOIN categories c ON c.id = i.category_id
                                     WHERE i.is_active = 1 AND i.show_on_website = 1');
    return $rows;
}

/** Stock state shown to customers: in / low (5 or fewer) / out / service. */
function store_stock($it) {
    $c = store_ctx();
    if (($it['item_type'] ?? '') === 'service') return ['svc', 'Service', ''];
    $q = (float)($c['stock'][(int)$it['id']] ?? 0);
    if ($q <= 0) return ['out', 'Out of stock', 'Available on order'];
    $n = rtrim(rtrim(number_format($q, 2), '0'), '.');
    $unit = trim((string)($it['unit'] ?? '')) ?: 'pcs';
    if ($q <= 5) return ['low', $c['showQty'] ? "Only $n $unit left" : 'Limited stock', ''];
    return ['in', $c['showQty'] ? "In stock · $n $unit" : 'In stock', ''];
}

// ---------------------------------------------------------------------------
//  Smart product order. Not database order, not "most pieces on the shelf":
//  real sales of the last 30/90 days, how many different customers buy it,
//  how often the same customers come back for it, daily-use items, whether
//  it can be handed over today, and a little for new arrivals. The admin's
//  pin (items.web_priority) always comes first. Items with no photo sink.
// ---------------------------------------------------------------------------
function store_sales_stats() {
    static $s = null;
    if ($s !== null) return $s;
    $s = [];
    try {
        foreach (all("SELECT si.item_id, SUM(CASE WHEN s.sale_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN si.qty ELSE 0 END) q30, SUM(si.qty) q90,
                      COUNT(DISTINCT s.id) b90, COUNT(DISTINCT COALESCE(NULLIF(s.party_id, 0), NULLIF(s.customer_mobile, ''), CONCAT('s', s.id))) c90
                      FROM sale_items si JOIN sales s ON s.id = si.sale_id
                      WHERE s.is_cancelled = 0 AND s.sale_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY) GROUP BY si.item_id") as $r)
            $s[(int)$r['item_id']] = ['q30' => (float)$r['q30'], 'q90' => (float)$r['q90'], 'b90' => (int)$r['b90'], 'c90' => (int)$r['c90']];
    } catch (Exception $e) {}
    return $s;
}
function store_daily_use($it) {
    return (bool)preg_match('/cable|mouse|keyboard|pen ?drive|toner|ink|cartridge|adapter|charger|bnc|dc pin|connector|clip|battery|memory card|sd card|usb|hdmi|lan|junction|patch|rj45|smps/i',
                            $it['name'] . ' ' . ($it['cat_name'] ?? ''));
}
/** Good enough to put in front of customers: a real price and a photo. */
function store_promotable($it) { return (float)$it['selling_price'] > 0 && trim((string)$it['photo']) !== '' && ($it['item_type'] ?? '') !== 'service'; }

function store_scores(array $items) {
    $st = store_sales_stats(); $c = store_ctx();
    $mx = ['v' => 0, 'i' => 0, 'r' => 0]; $raw = [];
    $maxId = $items ? max(array_map(fn($x) => (int)$x['id'], $items)) : 0; $minId = $items ? min(array_map(fn($x) => (int)$x['id'], $items)) : 0;
    foreach ($items as $it) {
        $s = $st[(int)$it['id']] ?? ['q30' => 0, 'q90' => 0, 'b90' => 0, 'c90' => 0];
        $r = ['v' => log1p($s['q30'] + $s['q90'] / 6), 'i' => log1p($s['c90']), 'r' => log1p(max(0, $s['b90'] - $s['c90']))];
        foreach ($mx as $k => $v) $mx[$k] = max($v, $r[$k]);
        $raw[(int)$it['id']] = $r;
    }
    $out = [];
    foreach ($items as $it) {
        $id = (int)$it['id']; $r = $raw[$id];
        $sc = 30 * ($mx['v'] ? $r['v'] / $mx['v'] : 0) + 20 * ($mx['i'] ? $r['i'] / $mx['i'] : 0) + 20 * ($mx['r'] ? $r['r'] / $mx['r'] : 0)
            + 15 * (store_daily_use($it) ? 1 : 0) + 10 * ((float)($c['stock'][$id] ?? 0) > 0 ? 1 : 0)
            + 5 * ($maxId > $minId ? ($id - $minId) / ($maxId - $minId) : 0);
        if (!store_promotable($it)) $sc *= 0.4;
        $out[$id] = round($sc + 1000 * (int)($it['web_priority'] ?? 0), 3);
    }
    return $out;
}
function store_sort(array $items, $sort = 'popular') {
    $sc = store_scores($items);
    usort($items, function ($a, $b) use ($sort, $sc) {
        if ($sort === 'price_asc') return $a['selling_price'] <=> $b['selling_price'] ?: $b['id'] <=> $a['id'];
        if ($sort === 'price_desc') return $b['selling_price'] <=> $a['selling_price'] ?: $b['id'] <=> $a['id'];
        if ($sort === 'new') return $b['id'] <=> $a['id'];
        if ($sort === 'best') { $s = store_sales_stats(); return ($s[(int)$b['id']]['q90'] ?? 0) <=> ($s[(int)$a['id']]['q90'] ?? 0) ?: $sc[(int)$b['id']] <=> $sc[(int)$a['id']]; }
        return $sc[(int)$b['id']] <=> $sc[(int)$a['id']] ?: $b['id'] <=> $a['id'];
    });
    return $items;
}

// ---------------------------------------------------------------------------
//  Search: name, brand, model, barcode/SKU, category - any word order, common
//  other words for the same thing (also in Gujarati), one-letter typos.
// ---------------------------------------------------------------------------
function store_synonyms() {
    return [
        'cctv' => ['camera', 'security'], 'camera' => ['cctv', 'cam'], 'cam' => ['camera'], 'security' => ['cctv', 'camera'], 'ssd' => ['solid state'], 'hdd' => ['hard disk', 'harddisk'],
        'harddisk' => ['hdd'], 'wifi' => ['wi-fi', 'wireless'], 'wireless' => ['wifi', 'wi-fi'], 'router' => ['wifi', 'wireless'], 'toner' => ['cartridge'], 'cartridge' => ['toner', 'ink'],
        'lan' => ['cat6', 'cat5', 'ethernet', 'network'], 'ethernet' => ['lan'], 'pendrive' => ['pen drive', 'usb drive', 'flash drive'], 'ram' => ['memory'], 'memory' => ['ram'],
        'smps' => ['power supply'], 'adapter' => ['charger', 'power'], 'charger' => ['adapter'], 'laptop' => ['notebook'], 'notebook' => ['laptop'], 'monitor' => ['display', 'screen'],
        'mouse' => ['mice'], 'keyboard' => ['keybord'], 'printer' => ['print'], 'dvr' => ['recorder'], 'nvr' => ['recorder'], 'poe' => ['switch'],
        'કેમેરા' => ['camera', 'cctv'], 'કેમેરો' => ['camera'], 'સીસીટીવી' => ['cctv'], 'લેપટોપ' => ['laptop'], 'કોમ્પ્યુટર' => ['computer'], 'કમ્પ્યુટર' => ['computer'],
        'માઉસ' => ['mouse'], 'કીબોર્ડ' => ['keyboard'], 'પ્રિન્ટર' => ['printer'], 'રાઉટર' => ['router'], 'વાયર' => ['cable'], 'કેબલ' => ['cable'], 'પેનડ્રાઇવ' => ['pendrive'],
        'ચાર્જર' => ['charger'], 'સ્પીકર' => ['speaker'], 'હેડફોન' => ['headphone'], 'મોનિટર' => ['monitor'], 'શાહી' => ['ink'],
    ];
}
function store_hay($it) {
    return mb_strtolower(implode(' ', [$it['name'], $it['brand'], $it['model'], $it['barcode'], $it['cat_name'] ?? '', store_group($it), $it['variant'] ?? '', $it['search_keywords'] ?? '', $it['specs'] ?? '']));
}
function store_search(array $items, $q) {
    $words = array_slice(preg_split('/\s+/u', mb_strtolower(trim($q)), -1, PREG_SPLIT_NO_EMPTY), 0, 8);
    if (!$words) return $items;
    $syn = store_synonyms();
    $out = [];
    foreach ($items as $it) {
        $hay = store_hay($it);
        $hw = null; $ok = true; $hits = 0;
        foreach ($words as $w) {
            $alts = array_merge([$w], $syn[$w] ?? []);
            $m = false;
            foreach ($alts as $a) if (mb_strpos($hay, $a) !== false) { $m = true; break; }
            if (!$m && mb_strlen($w) >= 4) { // one typo: "keybord", "printr", "camra"
                $hw = $hw ?? preg_split('/[^\p{L}\p{N}]+/u', $hay, -1, PREG_SPLIT_NO_EMPTY);
                foreach ($hw as $h) if (abs(strlen($h) - strlen($w)) <= 1 && levenshtein($w, $h) <= 1) { $m = true; break; }
            }
            if ($m) $hits++; else { $ok = false; break; }
        }
        if ($ok) $out[] = $it;
    }
    return $out;
}

/** Up to 3 short specs read from the product's own name: "2MP", "Night Vision", "PoE"... */
function store_specs($it) {
    $n = ' ' . $it['name'] . ' ' . ($it['variant'] ?? '') . ' ';
    $out = [];
    if (preg_match('/(\d+(?:\.\d+)?)\s?MP\b/i', $n, $m)) $out[] = ['hd', $m[1] . 'MP'];
    elseif (preg_match('/\b4K\b/i', $n)) $out[] = ['hd', '4K'];
    elseif (preg_match('/full ?hd|1080p|\bFHD\b/i', $n)) $out[] = ['hd', 'Full HD'];
    if (preg_match('/full[- ]?colou?r|colou?r night|warm light|dual light|starlight/i', $n)) $out[] = ['night', 'Color Night'];
    elseif (preg_match('/night vision|\bIR\b|\d+\s?m IR/i', $n)) $out[] = ['night', 'Night Vision'];
    if (preg_match('/(\d+)\s?-?(?:ch\b|channel)/i', $n, $m)) $out[] = ['hdd', $m[1] . ' Channel'];
    if (preg_match('/(\d+)\s?-?port/i', $n, $m)) $out[] = ['router', $m[1] . '-Port'];
    if (preg_match('/\b(\d+)\s?(GB|TB)\b/i', $n, $m)) $out[] = ['hdd', $m[1] . strtoupper($m[2])];
    if (preg_match('/\bPoE\b/i', $n)) $out[] = ['bolt', 'PoE'];
    if (preg_match('/\b4G\b/i', $n)) $out[] = ['wifi', '4G SIM'];
    elseif (preg_match('/wi-?fi|wireless|2\.4 ?ghz/i', $n)) $out[] = ['wifi', 'Wireless'];
    if (preg_match('/\boutdoor\b|\bIP6[67]\b/i', $n)) $out[] = ['shield', 'Outdoor'];
    elseif (preg_match('/\bindoor\b/i', $n)) $out[] = ['home', 'Indoor'];
    if (preg_match('/mic\b|microphone|audio/i', $n)) $out[] = ['phone', 'Audio'];
    if (preg_match('/(\d+)\s?W\b/', $n, $m)) $out[] = ['bolt', $m[1] . 'W'];
    return array_slice($out, 0, 3);
}
/** A real MRP above the price (typed by the shop) gives the saving; nothing else does. */
function store_mrp($it, $price) {
    $m = (float)($it['mrp'] ?? 0);
    if (store_ctx()['acct'] || $m <= $price + 0.5) return [0, 0];
    return [$m, (int)floor(($m - $price) / $m * 100)];
}

/** Compact public index for the instant search box: only what a card shows. */
function store_index(array $items) {
    $c = store_ctx(); $o = [];
    foreach ($items as $it) {
        [$sk] = store_stock($it);
        $o[] = ['i' => (int)$it['id'], 'n' => $it['name'], 'b' => (string)$it['brand'], 'm' => (string)$it['model'], 'k' => (string)$it['barcode'],
                'c' => (string)($it['cat_name'] ?? ''), 'g' => store_group($it), 'p' => dealer_price($it['selling_price'], $c['pct']),
                'f' => $it['photo'] ? base_url($it['photo']) : '', 'u' => seo_product_url($it), 's' => $sk, 'w' => (int)$it['warranty_months']];
    }
    return $o;
}

// ---------------------------------------------------------------------------
//  Page frame
// ---------------------------------------------------------------------------
function store_head($title, $desc, $canonical, $extra = '', $robots = '') {
    $c = store_ctx(); ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($title) ?></title>
<meta name="description" content="<?= e(mb_substr($desc, 0, 300)) ?>">
<?php if ($canonical): ?><link rel="canonical" href="<?= e($canonical) ?>"><?php endif; ?>
<?php if ($robots): ?><meta name="robots" content="<?= e($robots) ?>"><?php endif; ?>
<meta name="theme-color" content="#e11d2e">
<meta property="og:site_name" content="<?= e($c['app']) ?>">
<meta property="og:title" content="<?= e($title) ?>">
<meta property="og:description" content="<?= e(mb_substr($desc, 0, 200)) ?>">
<?php if ($canonical): ?><meta property="og:url" content="<?= e($canonical) ?>"><?php endif; ?>
<meta name="twitter:card" content="summary_large_image">
<link rel="icon" href="<?= e(base_url('assets/icon.svg')) ?>" type="image/svg+xml">
<link rel="apple-touch-icon" href="<?= e(base_url('assets/icon-192.png')) ?>">
<link rel="manifest" href="<?= e(base_url('store_manifest.php')) ?>">
<link rel="stylesheet" href="<?= e(base_url('assets/store.css')) ?>?v=<?= asset_v('store.css') ?>">
<script>try{var t=localStorage.getItem('akcTheme');if(t)document.documentElement.setAttribute('data-theme',t)}catch(e){}</script>
<?= $extra ?>
</head>
<body>
<?= store_sprite() ?>
<?php }

/** $mode 'home' = logo header, else a back arrow with $title in the middle. */
function store_header($mode = 'home', $title = '', $q = '') {
    $c = store_ctx();
    $ann = setting('store_announce', $c['own'] ? 'Fast Delivery in Dwarka | Genuine Products | Warranty | WhatsApp Support' : 'Genuine Products | WhatsApp Support');
    $ann = preg_replace('/\|/', ' · ', $ann); ?>
<?php if ($mode === 'home'): ?><div class="ann"><?= e($ann) ?></div><?php endif; ?>
<header class="hdr"><div class="hdr-wrap">
  <div class="hdr-in">
    <?php if ($mode === 'home'): ?>
    <button class="hbtn menu-btn" type="button" data-open="drawer" aria-label="Menu"><?= store_icon('menu') ?></button>
    <a class="logo" href="<?= e(base_url('catalog.php')) ?>" aria-label="<?= e($c['app']) ?> home">
      <span class="mark"><?php if ($c['logo']): ?><img src="<?= e($c['logo']) ?>" alt=""><?php else: ?><?= e(mb_strtoupper(mb_substr(preg_replace('/[^\p{L}]/u', '', $c['app']), 0, 2))) ?><?php endif; ?></span>
      <span class="nm"><?= e($c['app']) ?></span></a>
    <?php else: ?>
    <a class="hbtn" href="<?= e(base_url('catalog.php')) ?>" onclick="if(history.length>1&&document.referrer.indexOf(location.host)>-1){history.back();return false}" aria-label="Back"><?= store_icon('back') ?></a>
    <a class="logo desk" href="<?= e(base_url('catalog.php')) ?>"><span class="mark"><?= e(mb_strtoupper(mb_substr(preg_replace('/[^\p{L}]/u', '', $c['app']), 0, 2))) ?></span><span class="nm"><?= e($c['app']) ?></span></a>
    <div class="hdr-title"><?= e($title) ?></div>
    <?php endif; ?>
    <?php if ($c['acct'] || $c['cust']): ?>
    <a class="hbtn" href="<?= e(base_url('catalog.php?view=account' . ($c['cust'] ? '' : '&tab=dealer'))) ?>" aria-label="My account" data-desk style="display:none"><?= store_icon('user') ?><span class="lbl"><?= e(mb_substr(($c['cust'] ?: $c['acct'])['name'], 0, 14)) ?></span></a>
    <?php else: ?>
    <a class="hbtn" href="<?= e(base_url('catalog.php?view=account')) ?>" aria-label="Account" style="display:none" data-desk><?= store_icon('user') ?><span class="lbl">Login</span></a>
    <?php endif; ?>
    <button class="hbtn" type="button" data-theme-toggle aria-label="Dark or light" data-desk style="display:none"><?= store_icon('moon') ?></button>
    <a class="hbtn" href="<?= e(base_url('catalog.php?view=wish')) ?>" aria-label="Wishlist" data-desk style="display:none"><?= store_icon('heart') ?><span class="badge-n" data-wish-n data-n="0">0</span><span class="lbl">Wishlist</span></a>
    <button class="hbtn" type="button" data-open="cart" aria-label="Cart"><?= store_icon('cart') ?><span class="badge-n" data-cart-n data-n="0">0</span><span class="lbl">Cart</span></button>
  </div>
  <div class="srch">
    <form action="<?= e(base_url('catalog.php')) ?>" method="get" role="search">
      <input type="hidden" name="view" value="all">
      <?= store_icon('search') ?>
      <input type="search" name="q" value="<?= e($q) ?>" autocomplete="off" enterkeyhint="search" data-suggest aria-label="Search products"
             placeholder="Search CCTV, laptop, mouse, SSD…">
      <button class="go" type="submit">Search</button>
    </form>
    <div class="sugg" data-sugg role="listbox"></div>
  </div>
</div></header>
<style>@media (min-width:960px){[data-desk]{display:inline-flex!important}}</style>
<?php }

/** Desktop category bar + the side menu used on phones. */
function store_nav(array $groups, $active = '') {
    $c = store_ctx(); ?>
<nav class="dnav" aria-label="Categories"><div class="wrap">
  <a href="<?= e(base_url('catalog.php')) ?>" class="<?= $active === '' ? 'on' : '' ?>">Home</a>
  <?php foreach ($groups as $g): ?><a href="<?= e(store_group_url($g['name'])) ?>" class="<?= $active === $g['name'] ? 'on' : '' ?>"><?= e($g['name']) ?></a><?php endforeach; ?>
  <a href="<?= e(base_url('s/')) ?>">Services</a>
</div></nav>
<aside class="drawer" id="drawer" aria-label="Menu">
  <div class="drawer-h">
    <div class="logo"><span class="mark"><?= e(mb_strtoupper(mb_substr(preg_replace('/[^\p{L}]/u', '', $c['app']), 0, 2))) ?></span><span class="nm"><?= e($c['app']) ?></span></div>
    <?php if ($c['cust']): ?>
    <a class="acct" href="<?= e(base_url('catalog.php?view=account')) ?>"><?= store_icon('user') ?><span><?= e($c['cust']['name']) ?><small>My orders · addresses · wishlist</small></span></a>
    <?php elseif ($c['acct']): ?>
    <a class="acct" href="<?= e(base_url('catalog.php?view=account&tab=dealer')) ?>"><?= store_icon('user') ?><span><?= e($c['acct']['name']) ?><small>Dealer prices are on · My account</small></span></a>
    <?php else: ?>
    <a class="acct" href="<?= e(base_url('catalog.php?view=account')) ?>"><?= store_icon('user') ?><span>Login / Register<small>Wholesale prices for dealers &amp; electricians</small></span></a>
    <?php endif; ?>
  </div>
  <nav>
    <a href="<?= e(base_url('catalog.php')) ?>"><?= store_icon('home') ?>Home</a>
    <a href="<?= e(base_url('catalog.php?view=cats')) ?>"><?= store_icon('grid') ?>All Categories</a>
    <a href="<?= e(base_url('catalog.php?view=all&sort=new')) ?>"><?= store_icon('spark') ?>New Arrivals</a>
    <a href="<?= e(base_url('catalog.php?view=wish')) ?>"><?= store_icon('heart') ?>Wishlist</a>
    <a href="<?= e(base_url('catalog.php?view=account')) ?>"><?= store_icon('receipt') ?>My Orders / Track Order</a>
    <a href="#" data-theme-toggle><?= store_icon('moon') ?>Dark / Light mode</a>
    <div class="lab">Shop by category</div>
    <?php foreach ($groups as $g): ?><a href="<?= e(store_group_url($g['name'])) ?>"><?= store_icon(store_cat_icon($g['name'])) ?><?= e($g['name']) ?> <span class="muted" style="margin-left:auto;font-size:12px"><?= (int)$g['n'] ?></span></a><?php endforeach; ?>
    <div class="lab">Help</div>
    <a href="<?= e(base_url('s/')) ?>"><?= store_icon('tool') ?>Services &amp; Repairs</a>
    <?php if ($c['wa']): ?><a href="https://wa.me/<?= e($c['wa']) ?>" target="_blank" rel="noopener"><?= store_icon('wa') ?>WhatsApp us</a><?php endif; ?>
    <?php if (!empty($c['co']['phone'])): ?><a href="tel:<?= e(preg_replace('/[^\d+]/', '', $c['co']['phone'])) ?>"><?= store_icon('phone') ?>Call <?= e($c['co']['phone']) ?></a><?php endif; ?>
    <a href="<?= e(base_url('referral.php')) ?>"><?= store_icon('tag') ?>Refer &amp; Earn</a>
    <?php if (current_user()): ?><a href="<?= e(base_url('index.php')) ?>"><?= store_icon('store') ?>Shop Dashboard</a><?php else: ?><a href="<?= e(base_url('catalog.php?view=account&tab=staff')) ?>"><?= store_icon('store') ?>Staff Login</a><?php endif; ?>
  </nav>
</aside>
<div class="sheet-bg" data-close></div>
<?php }

function store_group_url($g) { return base_url('catalog.php?view=all&g=' . rawurlencode($g)); }

/** Main groups that actually have products, in the order customers look for them. */
function store_groups(array $items) {
    $order = ['security|cctv|camera', 'laptop', 'desktop|computer', 'storage|ssd|ram', 'peripheral|keyboard|mouse', 'printer', 'network|router|wifi', 'cable', 'power|ups', 'display|monitor', 'mobility|accessor'];
    $g = [];
    foreach ($items as $it) { $n = store_group($it); if ($n === '') continue; $g[$n] = ($g[$n] ?? 0) + 1; }
    $rank = function ($n) use ($order) { foreach ($order as $i => $re) if (preg_match('/' . $re . '/i', $n)) return $i; return 99; };
    $out = [];
    foreach ($g as $n => $cnt) $out[] = ['name' => $n, 'n' => $cnt, 'r' => $rank($n)];
    usort($out, fn($a, $b) => [$a['r'], -$a['n']] <=> [$b['r'], -$b['n']]);
    return $out;
}

/** One product card. $layout 'grid' (tile) or 'list' (row, like the mockup). */
function store_card($it, $layout = 'grid', $tag = '') {
    $c = store_ctx();
    $p = dealer_price($it['selling_price'], $c['pct']);
    $u = seo_product_url($it);
    [$sk, $st] = store_stock($it);
    $img = $it['photo'] ? '<img src="' . e(base_url($it['photo'])) . '" alt="' . e($it['name']) . '" loading="lazy" decoding="async" width="300" height="300" onload="this.classList.add(\'ld\')">'
                        : '<span class="noimg">' . store_icon(store_cat_icon(($it['cat_name'] ?? '') . ' ' . $it['name'])) . '</span>';
    $data = ' data-id="' . (int)$it['id'] . '" data-n="' . e($it['name']) . '" data-p="' . e($p) . '" data-f="' . e($it['photo'] ? base_url($it['photo']) : '') . '" data-m="' . e($it['model']) . '"';
    $wl = '<button type="button" class="wl" data-wish="' . (int)$it['id'] . '" aria-label="Add to wishlist">' . store_icon('heart') . '</button>';
    $qty = '<span class="qty"><button type="button" data-q="-1" aria-label="One less">−</button><span data-qn>1</span><button type="button" data-q="1" aria-label="One more">+</button></span>';
    $pin = $c['admin'] ? '<button type="button" class="pin-btn' . ((int)($it['web_priority'] ?? 0) ? ' on' : '') . '" data-pin="' . (int)$it['id'] . '" title="Admin: show this product first">📌 ' . ((int)($it['web_priority'] ?? 0) ? 'Pinned' : 'Pin') . '</button>' : '';
    [$mrp, $off] = store_mrp($it, $p);
    if ($off >= 1 && !$tag) $tag = '-' . $off . '%';
    $tagH = $tag ? '<span class="tag ' . e($tag === 'NEW' ? 'new' : ($tag === 'TOP' ? 'pin' : '')) . '">' . e($tag === 'TOP' ? 'TOP PICK' : $tag) . '</span>' : '';
    $priceH = '<span class="price">₹' . money($p) . ($mrp ? ' <s>₹' . money($mrp) . '</s>' : '') . ($c['acct'] ? '<small>Your dealer price</small>' : '') . '</span>';
    $specs = store_specs($it);
    $specH = $specs ? '<span class="specs">' . implode('', array_map(fn($x) => '<span>' . store_icon($x[0]) . e($x[1]) . '</span>', $specs)) . '</span>' : '';
    if ($layout === 'list') { ?>
<article class="pl" data-card<?= $data ?>>
  <a class="im" href="<?= e($u) ?>"><?= $img ?><?= $tagH ?></a>
  <div class="bd">
    <?= $wl ?>
    <?php if ($it['brand']): ?><span class="brl"><?= e($it['brand']) ?></span><?php endif; ?>
    <a class="nm" href="<?= e($u) ?>"><?= e($it['name']) ?></a>
    <?= $specH ?>
    <span class="stk <?= $sk ?>"><?= e($st) ?></span>
    <?php if ((int)$it['warranty_months'] > 0): ?><span class="wty"><?= store_icon('shield') ?><?= (int)$it['warranty_months'] ?> months warranty</span><?php endif; ?>
    <div class="ft"><?= $priceH ?><span><button type="button" class="btn-add" data-add><?= store_icon('cart') ?>Add to Cart</button><?= $qty ?></span></div>
  </div><?= $pin ?>
</article>
<?php return; } ?>
<article class="pc" data-card<?= $data ?>>
  <?= $wl ?><?= $tagH ?>
  <a class="im" href="<?= e($u) ?>"><?= $img ?></a>
  <div class="bd">
    <span class="br"><?= e($it['brand'] ?: ($it['cat_name'] ?? '')) ?></span>
    <a class="nm" href="<?= e($u) ?>"><?= e($it['name']) ?></a>
    <span class="stk <?= $sk ?>"><?= e($st) ?></span>
    <div class="ft"><?= $priceH ?><span><button type="button" class="addc" data-add aria-label="Add <?= e($it['name']) ?> to cart"><?= store_icon('cart') ?></button><?= $qty ?></span></div>
  </div><?= $pin ?>
</article>
<?php }

/** Cart sheet + checkout. Orders still go to catalog.php (do=order), which
 *  re-prices every line from the database - the browser's price is never used. */
function store_cart_sheet() {
    $c = store_ctx();
    $pickup = trim((string)($c['co']['address'] ?? '')) ?: shop_place();
    $cu = $c['cust']; $da = $cu ? (sc_addresses($cu['id'])[0] ?? null) : null;
    $pre = ['name' => $da['name'] ?? ($cu['name'] ?? ($c['acct']['name'] ?? '')), 'mobile' => $da['mobile'] ?? ($cu['mobile'] ?? ($c['acct']['mobile'] ?? '')),
            'address' => $da['address'] ?? '', 'area' => $da['area'] ?? shop_city(), 'pincode' => $da['pincode'] ?? '']; ?>
<section class="sheet" id="cart" aria-label="Cart" aria-modal="true" role="dialog">
  <div class="sheet-h"><h2>Your Cart</h2><button class="hbtn" type="button" data-close aria-label="Close"><?= store_icon('x') ?></button></div>
  <div data-cart-lines></div>
  <div data-cart-empty class="empty" hidden><?= store_icon('bag') ?><h3>Your cart is empty</h3><p>Add products and they will wait here for you.</p><a class="btn" href="<?= e(base_url('catalog.php?view=all')) ?>">Browse products</a></div>
  <form method="post" action="<?= e(base_url('catalog.php')) ?>" data-checkout>
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="order"><input type="hidden" name="cart_json" data-cart-json>
    <div class="tot"><span>Total</span><span data-cart-total>₹0</span></div>
    <div class="field"><label for="co_n">Your name *</label><input id="co_n" name="customer_name" required autocomplete="name" value="<?= e($pre['name']) ?>"></div>
    <div class="field"><label for="co_m">Mobile (WhatsApp) *</label><input id="co_m" name="mobile" type="tel" inputmode="numeric" required pattern="[0-9+ ]{10,15}" autocomplete="tel" value="<?= e($pre['mobile']) ?>"></div>
    <div class="field"><label>Delivery or pickup</label>
      <div class="seg"><input type="radio" name="fulfil" id="f_d" value="Home delivery" checked><label for="f_d"><?= store_icon('truck') ?>Delivery</label>
        <input type="radio" name="fulfil" id="f_p" value="Shop pickup"><label for="f_p"><?= store_icon('store') ?>Shop pickup</label></div></div>
    <div data-addr>
      <div class="field"><label for="co_a">Address</label><input id="co_a" name="address" autocomplete="street-address" value="<?= e($pre['address']) ?>"></div>
      <div class="two" style="grid-template-columns:1.4fr 1fr">
        <div class="field" style="margin:0"><label for="co_c">Area / City</label><input id="co_c" name="area" autocomplete="address-level2" value="<?= e($pre['area']) ?>"></div>
        <div class="field" style="margin:0"><label for="co_p">Pincode</label><input id="co_p" name="pincode" inputmode="numeric" pattern="[0-9]{6}" autocomplete="postal-code" value="<?= e($pre['pincode']) ?>"></div>
      </div>
      <?php if ($cu && !$da): ?><label class="chip" style="margin-bottom:12px"><input type="checkbox" name="save_addr" value="1" checked> Save this address to my account</label><?php endif; ?>
    </div>
    <p class="muted" data-pickup hidden style="font-size:13px;margin:-4px 0 12px">Pick up at: <?= e($pickup) ?></p>
    <div class="field"><label for="co_pay">Payment</label><select id="co_pay" name="pay_method"><option>UPI</option><option>Cash on delivery / at shop</option><option>Bank transfer</option></select></div>
    <div class="field"><label for="co_o">Order notes</label><input id="co_o" name="order_notes" placeholder="Anything we should know"></div>
    <button class="btn block" type="submit"><?= store_icon('check') ?>Place Order</button>
    <?php if ($c['wa']): ?><button class="btn wa block" type="button" data-wa-order style="margin-top:8px"><?= store_icon('wa') ?>Order on WhatsApp instead</button><?php endif; ?>
    <?php if (!$cu && !$c['acct']): ?><p class="muted" style="font-size:12.5px;text-align:center;margin-top:10px">Ordering as a guest — <a style="color:var(--brand);font-weight:700" href="<?= e(base_url('catalog.php?view=account')) ?>">log in</a> to keep your orders and addresses.</p><?php endif; ?>
    <p class="muted" style="font-size:12px;text-align:center;margin-top:10px">We confirm every order on WhatsApp or a call before it is packed. Prices are checked again by the shop.</p>
  </form>
</section>
<div class="sheet-bg" data-close></div>
<?php }

function store_bottom_nav($active = 'home') {
    $c = store_ctx(); ?>
<nav class="bnav" aria-label="Main">
  <a href="<?= e(base_url('catalog.php')) ?>" class="<?= $active === 'home' ? 'on' : '' ?>"><?= store_icon('home') ?>Home</a>
  <a href="<?= e(base_url('catalog.php?view=cats')) ?>" class="<?= $active === 'cats' ? 'on' : '' ?>"><?= store_icon('grid') ?>Categories</a>
  <?php if ($c['wa']): ?><a class="wa-n" href="https://wa.me/<?= e($c['wa']) ?>" target="_blank" rel="noopener"><span class="wa-c"><?= store_icon('wa') ?></span>WhatsApp</a><?php else: ?><button type="button" data-open="cart"><?= store_icon('cart') ?><span class="badge-n" data-cart-n data-n="0">0</span>Cart</button><?php endif; ?>
  <a href="<?= e(base_url('catalog.php?view=wish')) ?>" class="<?= $active === 'wish' ? 'on' : '' ?>"><?= store_icon('heart') ?><span class="badge-n" data-wish-n data-n="0">0</span>Wishlist</a>
  <a href="<?= e(base_url('catalog.php?view=account')) ?>" class="<?= $active === 'account' ? 'on' : '' ?>"><?= store_icon('user') ?>Account</a>
</nav>
<?php }

/** Footer: shop details + crawlable links to every category, brand, service. */
function store_footer() {
    $c = store_ctx(); $co = $c['co'];
    $groups = store_groups(store_items()); ?>
<footer class="ft-store"><div class="wrap">
  <div class="cols">
    <div class="about">
      <div class="logo" style="margin-bottom:8px"><span class="mark"><?= e(mb_strtoupper(mb_substr(preg_replace('/[^\p{L}]/u', '', $c['app']), 0, 2))) ?></span><span class="nm"><?= e($c['app']) ?></span></div>
      <?php if ($c['own']): ?><p class="muted">Computer, Laptop, CCTV, Networking, Printer, Accessories, Repairs and IT services — <?= e(shop_place()) ?>.</p><?php endif; ?>
      <?php if (trim((string)$co['address']) !== ''): ?><p style="margin-top:8px;display:flex;gap:6px"><?= store_icon('pin') ?><span><?= e($co['address']) ?></span></p><?php endif; ?>
      <?php if (!empty($co['phone'])): ?><a href="tel:<?= e(preg_replace('/[^\d+]/', '', $co['phone'])) ?>">📞 <?= e($co['phone']) ?></a><?php endif; ?>
      <?php if ($c['wa']): ?><a href="https://wa.me/<?= e($c['wa']) ?>" target="_blank" rel="noopener">💬 WhatsApp +<?= e($c['wa']) ?></a><?php endif; ?>
    </div>
    <div><h3>Categories</h3><?php foreach (array_slice($groups, 0, 12) as $g): ?><a href="<?= e(store_group_url($g['name'])) ?>"><?= e($g['name']) ?></a><?php endforeach; ?>
      <a href="<?= e(base_url('catalog.php?view=cats')) ?>">All categories →</a></div>
    <div><h3>Services</h3><?php foreach (seo_services() as $slug => $sv): ?><a href="<?= e(seo_service_url($slug)) ?>"><?= e($sv['name']) ?></a><?php endforeach; ?></div>
    <div><h3>Help</h3>
      <a href="<?= e(base_url('catalog.php?dregister=1')) ?>">Dealer Registration</a><a href="<?= e(base_url('referral.php')) ?>">Refer &amp; Earn</a>
      <a href="<?= e(base_url('privacy.php')) ?>">Privacy Policy</a><a href="<?= e(base_url('terms.php')) ?>">Terms &amp; Conditions</a>
      <?php if (!current_user()): ?><a href="<?= e(base_url('catalog.php?view=account&tab=staff')) ?>">Staff Login</a><?php endif; ?></div>
  </div>
  <details style="margin-top:14px"><summary>All brands &amp; categories</summary>
  <div class="cop" style="border:0;margin-top:4px;padding-top:4px">
    <?php foreach (array_slice(seo_brands(), 0, 14) as $b): if (seo_slug($b['name']) === '') continue; ?><a href="<?= e(seo_brand_url($b['name'])) ?>"><?= e($b['name']) ?></a><?php endforeach; ?>
    <?php foreach (seo_cats() as $cc): if (seo_slug($cc['name']) === '') continue; ?><a href="<?= e(seo_cat_url($cc['name'])) ?>"><?= e($cc['name']) ?></a><?php endforeach; ?>
  </div></details>
  <div class="cop"><span>© <?= date('Y') ?> <?= e($c['app']) ?>, <?= e(shop_city()) ?></span><a href="<?= e(base_url('privacy.php')) ?>">Privacy</a><a href="<?= e(base_url('terms.php')) ?>">Terms</a></div>
</div></footer>
<?php }

/** Closing part of every store page: menu, cart, nav, WhatsApp button, scripts. */
function store_end($active = 'home', $index = null) {
    store_tail($active, $index); ?>
<?= ak_earn_tag() ?>
</body>
</html>
<?php }

/** Cart sheet, bottom navigation, WhatsApp button and the store scripts. */
function store_tail($active = 'home', $index = null) {
    $c = store_ctx();
    store_cart_sheet();
    store_bottom_nav($active);
    if ($c['wa']): ?><a class="fab-wa" href="https://wa.me/<?= e($c['wa']) ?>?text=<?= rawurlencode('Hello ' . $c['app'] . '!') ?>" target="_blank" rel="noopener" aria-label="WhatsApp"><?= store_icon('wa') ?></a><?php endif; ?>
<div class="toast" data-toast role="status"></div>
<script>window.AKC = <?= json_encode(['wa' => $c['wa'], 'app' => $c['app'], 'base' => base_url(''), 'idx' => base_url('catalog.php?index=1'),
    'syn' => store_synonyms(), 'csrf' => csrf_token(), 'admin' => $c['admin'], 'place' => shop_city(), 'cust' => (bool)$c['cust']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
<?php if ($index !== null): ?>window.AKC_IDX = <?= json_encode($index, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;<?php endif; ?></script>
<script src="<?= e(base_url('assets/store.js')) ?>?v=<?= asset_v('store.js') ?>" defer></script>
<?php }

/** A category or brand landing page (/c/..., /b/...): real H1, intro from the
 *  shop's own numbers, chips to narrow down, the product list, and an FAQ
 *  whose every answer comes from the same data. */
function store_landing($kind, $name, array $items, $canonical) {
    $c = store_ctx(); $city = shop_city() ?: 'Dwarka';
    $f = trim((string)get('f'));
    $prices = array_map(fn($i) => dealer_price($i['selling_price'], $c['pct']), array_filter($items, fn($i) => $i['selling_price'] > 0)) ?: [0];
    $other = [];
    foreach ($items as $i) { $k = $kind === 'cat' ? trim((string)$i['brand']) : (string)($i['cat_name'] ?? ''); if ($k !== '') $other[$k] = ($other[$k] ?? 0) + 1; }
    arsort($other);
    $list = store_sort($f !== '' ? array_values(array_filter($items, fn($i) => strcasecmp($kind === 'cat' ? trim((string)$i['brand']) : (string)($i['cat_name'] ?? ''), $f) === 0)) : $items);
    $inStockN = count(array_filter($items, fn($i) => (float)($c['stock'][(int)$i['id']] ?? 0) > 0));
    $topOther = array_slice(array_keys($other), 0, 6);
    if ($kind === 'cat') {
        $title = $name . ' in ' . $city . ($topOther ? ' – ' . implode(', ', array_slice($topOther, 0, 3)) : '') . ' | ' . $c['app'];
        $h1 = $name . ' in ' . $city;
        $desc = 'Buy ' . $name . ' in ' . shop_place() . ' at ' . $c['app'] . ': ' . count($items) . ' products from ₹' . money(min($prices)) . ($topOther ? ', brands ' . implode(', ', array_slice($topOther, 0, 5)) : '') . '. Price and stock shown, warranty, WhatsApp ordering.';
    } else {
        $title = $name . ' Products in ' . $city . ' – Price & Stock | ' . $c['app'];
        $h1 = $name . ' products in ' . $city;
        $desc = 'Genuine ' . $name . ' products at ' . $c['app'] . ', ' . shop_place() . ': ' . count($items) . ' items' . ($topOther ? ' — ' . implode(', ', array_slice($topOther, 0, 5)) : '') . ', from ₹' . money(min($prices)) . '. Warranty and WhatsApp ordering.';
    }
    $faqs = [
        "What is the price of $name at {$c['app']}?" => count($items) . ' ' . $name . ' products are listed, from ₹' . money(min($prices)) . ' to ₹' . money(max($prices)) . '. Each product shows its current price.',
        "Are they in stock in $city?" => $inStockN . ' of them are on our shelf right now; the rest can be ordered — each product shows its stock.',
    ];
    if ($topOther) $faqs[$kind === 'cat' ? "Which brands of $name do you sell?" : "Which $name products do you have?"] = implode(', ', $topOther) . (count($other) > 6 ? ' and more.' : '.');
    if ($c['wa']) $faqs['Can I order on WhatsApp?'] = 'Yes — add products to the cart and press "Order on WhatsApp", or send us the model number.';
    $ld = ['@context' => 'https://schema.org', '@type' => 'ItemList', 'name' => $h1, 'itemListElement' => []];
    foreach (array_slice($list, 0, 30) as $pos => $i) $ld['itemListElement'][] = ['@type' => 'ListItem', 'position' => $pos + 1, 'name' => $i['name'], 'url' => seo_product_url($i)];
    $head = seo_jsonld($ld) . seo_breadcrumbs([[$c['app'], base_url('') . '/'], [$name]]) . seo_faq_jsonld($faqs) . seo_localbusiness_jsonld();
    store_head($title, $desc, $canonical, $head, $f !== '' ? 'noindex, follow' : '');
    store_header('back', $name);
    store_nav(store_groups(store_items()), $kind === 'cat' ? (store_parents()[$name] ?? $name) : '');
    $img = ''; foreach ($list as $i) if (store_promotable($i)) { $img = base_url($i['photo']); break; } ?>
<main>
  <section class="sec" style="margin-top:4px">
    <div class="cathead"><span class="ti"><?php if ($img): ?><img src="<?= e($img) ?>" alt=""><?php else: ?><?= store_icon(store_cat_icon($name)) ?><?php endif; ?></span>
      <h1><?= e($h1) ?><small><?= count($items) ?> products · from ₹<?= money(min($prices)) ?> · <?= $inStockN ?> in stock</small></h1></div>
    <?php if (count($other) > 1): ?><div class="chips" style="margin-bottom:10px">
      <a class="chip <?= $f === '' ? 'on' : '' ?>" href="<?= e($canonical) ?>">All</a>
      <?php foreach (array_slice($other, 0, 20, true) as $k => $n): ?><a class="chip <?= strcasecmp($f, $k) === 0 ? 'on' : '' ?>" href="<?= e($canonical . '?f=' . rawurlencode($k)) ?>"><?= e($k) ?> <span class="n"><?= $n ?></span></a><?php endforeach; ?>
    </div><?php endif; ?>
    <div class="list"><?php foreach ($list as $i) store_card($i, 'list'); ?></div>
  </section>
  <section class="sec" style="max-width:820px">
    <div class="panel" style="font-size:14px;line-height:1.6">
      <h2 style="font-size:17px;margin-bottom:6px"><?= e($name) ?> at <?= e($c['app']) ?>, <?= e($city) ?></h2>
      <p><?= e($desc) ?><?= $c['own'] ? ' Visit the shop to see them, or order online and we confirm on WhatsApp before delivery.' : '' ?></p>
      <?php if ($topOther): ?><div class="chips" style="flex-wrap:wrap;margin-top:10px"><?php foreach ($topOther as $k): $u = $kind === 'cat' ? seo_brand_url($k) : seo_cat_url($k); ?><a class="chip" href="<?= e($u) ?>"><?= e($k) ?></a><?php endforeach; ?></div><?php endif; ?>
    </div>
  </section>
  <section class="sec faq" style="max-width:820px"><div class="sec-h"><h2>Questions about <?= e($name) ?></h2></div>
    <?php foreach ($faqs as $q => $a): ?><details><summary><?= e($q) ?></summary><p><?= e($a) ?></p></details><?php endforeach; ?></section>
</main>
<?php store_footer(); store_end('cats');
}

/** The "not found" page every store URL uses. */
function store_404($what) {
    http_response_code(404);
    store_head($what . ' not found | ' . store_ctx()['app'], $what . ' not found.', '', '', 'noindex');
    store_header('back', 'Not found');
    echo '<main class="sec" style="max-width:520px"><div class="panel empty">' . store_icon('search') . '<h3>' . e($what) . ' not found</h3><p>It may have moved. Try the search, or browse everything.</p><a class="btn" href="' . e(base_url('catalog.php?view=all')) . '">Browse products</a></div></main>';
    store_footer(); store_end('home'); exit;
}
