<?php
// Public website - the online store's home, all-products / search, categories,
// wishlist and dealer account pages (the look lives in includes/store_ui.php,
// assets/store.css and assets/store.js).
// - products in a smart order (real sales, repeat buyers, stock, admin pin)
// - dealer login: shows each dealer THEIR price directly (no % and no
//   struck-through MRP - the dealer never learns their discount rate)
// - dealer self-registration (pending until the shop approves)
// - daily unique-visitor tracking for the admin dashboard
// Orders land in web_orders (admin: web_orders.php) + WhatsApp alert to shop.
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/seo.php';
require_once __DIR__ . '/includes/store_ui.php';

// ---------- instant-search index (public card data only, same as the pages show) ----------
if (get('index') === '1') {
    // a simple brake per visitor: the page itself needs it once
    $_SESSION['akc_idx'] = array_values(array_filter($_SESSION['akc_idx'] ?? [], fn($t) => $t > time() - 600));
    if (count($_SESSION['akc_idx']) >= 60) { http_response_code(429); header('Retry-After: 600'); exit('[]'); }
    $_SESSION['akc_idx'][] = time();
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, max-age=300');
    echo json_encode(store_index(store_sort(store_items())), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ---------- admin: pin / unpin a product to the front of the store ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'pin') {
    header('Content-Type: application/json');
    if (!current_user() || !can('items.edit')) { echo json_encode(['ok' => false, 'error' => 'Only staff who can edit items can pin.']); exit; }
    try {
        $pid = (int)post('id');
        q('UPDATE items SET web_priority = IF(web_priority > 0, 0, 1) WHERE id = ?', [$pid]);
        $on = (int)val('SELECT web_priority FROM items WHERE id = ?', [$pid]) > 0;
        log_activity('store_pin', "#$pid " . ($on ? 'pinned' : 'unpinned'));
        echo json_encode(['ok' => true, 'on' => $on]);
    } catch (Exception $e) { echo json_encode(['ok' => false, 'error' => 'Run Settings > Update > Migrate first.']); }
    exit;
}

$app_name = setting('app_name', 'AK Computer');
$waShop = wa_normalize_number(setting('wa_shop_number'));
if (strlen($waShop) < 12) $waShop = '';
$orderOk = false;

// ---------- daily unique visitors (guests only, one row per person/day) ----------
site_visit_track('catalog');

// ---------- referral link capture (?ref=CODE) ----------
// The code rides a 30-day cookie so the partner still gets credit when the
// customer orders days later. Clicks are counted once per arrival.
if (get('ref') !== '') {
    $refRow = row('SELECT id, code FROM referrers WHERE code = ? AND is_active = 1', [strtoupper(trim(get('ref')))]);
    if ($refRow) {
        if (($_COOKIE['akc_ref'] ?? '') !== $refRow['code']) q('UPDATE referrers SET clicks = clicks + 1 WHERE id = ?', [$refRow['id']]);
        setcookie('akc_ref', $refRow['code'], time() + 30 * 86400, '/');
        $_COOKIE['akc_ref'] = $refRow['code'];
    }
}

// ---------- dealer (electrician/B2B) web login ----------
// Once logged in, every price is shown already reduced by the discount % on
// the account. The page never reveals the % or the original price - the
// dealer just sees "their" price, as the owner wants different hidden rates
// per dealer. Orders are priced the same way server-side.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'wlogin') {
    $acc = row('SELECT * FROM web_accounts WHERE mobile = ? AND is_active = 1', [preg_replace('/\D/', '', post('wmobile'))]);
    if ($acc && password_verify(post('wpassword'), $acc['password_hash'])) {
        $_SESSION['web_account_id'] = (int)$acc['id'];
        q('UPDATE web_accounts SET last_login = NOW() WHERE id = ?', [$acc['id']]);
        redirect('catalog.php');
    }
    $wloginError = 'Mobile or password is wrong. Not approved yet? The shop will confirm on WhatsApp.';
}
if (get('wlogout') === '1') { unset($_SESSION['web_account_id']); redirect('catalog.php'); }

// ---------- dealer self-registration (pending until the shop approves) ----------
$wregDone = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'wregister') {
    $rname = trim(post('rname'));
    $rmobile = preg_replace('/\D/', '', post('rmobile'));
    if ($rname === '' || strlen($rmobile) < 10 || strlen(post('rpassword')) < 4) {
        $wregError = 'Fill name, a valid mobile and a password (4+ letters).';
    } elseif (val('SELECT id FROM web_accounts WHERE mobile = ?', [$rmobile])) {
        $wregError = 'This mobile already has an account. Try logging in.';
    } else {
        q('INSERT INTO web_accounts (name, mobile, password_hash, discount_pct, is_active, self_registered) VALUES (?,?,?,0,0,1)',
          [$rname, $rmobile, password_hash(post('rpassword'), PASSWORD_DEFAULT)]);
        ak_earn(fn() => ak_track_signup($rname, mobile10($rmobile), null, ['kind' => 'dealer_account']), 'signup');
        if ($waShop) send_whatsapp($waShop, "🆕 *Dealer registration*\n\n$rname ($rmobile) has asked for a dealer account on the website.\nApprove: " . base_url('web_customers.php'));
        $wregDone = true;
    }
}

// ---------- customer: track an order with its number + the mobile it was placed with ----------
$trackRes = null; $trackErr = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'track') {
    $_SESSION['akc_track'] = array_values(array_filter($_SESSION['akc_track'] ?? [], fn($t) => $t > time() - 600));
    if (count($_SESSION['akc_track']) >= 8) $trackErr = 'Too many tries — please wait 10 minutes, or ask us on WhatsApp.';
    else {
        $_SESSION['akc_track'][] = time();
        $m10 = substr(preg_replace('/\D/', '', post('tmobile')), -10);
        $ono = strtoupper(trim(post('torder')));
        $trackRes = strlen($m10) === 10 && $ono !== '' ? row("SELECT order_no, status, total, created_at, items_json, address FROM web_orders WHERE order_no = ? AND RIGHT(REPLACE(REPLACE(mobile, ' ', ''), '+', ''), 10) = ?", [$ono, $m10]) : null;
        if (!$trackRes) $trackErr = 'No order found with that number and mobile. Check both and try again.';
    }
}

$webAcct = null;
if (!empty($_SESSION['web_account_id'])) {
    $webAcct = row('SELECT * FROM web_accounts WHERE id = ? AND is_active = 1', [(int)$_SESSION['web_account_id']]);
    if (!$webAcct) unset($_SESSION['web_account_id']);
}
$waPct = $webAcct ? (float)$webAcct['discount_pct'] : 0;

// ---------- customer accounts (login, register, code login, profile, addresses, wishlist) ----------
require_once __DIR__ . '/includes/store_account.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($scRes = sc_handle_post())) {
    flash($scRes[0], $scRes[1] ? 'error' : 'success');
    redirect($scRes[2]);
}
$cust = sc_current();

// ---------- place order ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'order') {
    $cart = json_decode(post('cart_json'), true) ?: [];
    $name = trim(post('customer_name'));
    $mobile = trim(post('mobile'));
    if ($name && $mobile && $cart) {
        // re-price from DB (never trust browser prices)
        $itemsTxt = '';
        $total = 0;
        $clean = [];
        foreach ($cart as $cid => $cqty) {
            $it = row('SELECT id, name, selling_price FROM items WHERE id = ? AND is_active = 1 AND show_on_website = 1', [(int)$cid]);
            $qty = max(1, (int)$cqty);
            if (!$it) continue;
            $unit = dealer_price($it['selling_price'], $waPct);
            $line = $qty * $unit;
            $total += $line;
            $clean[] = ['id' => $it['id'], 'name' => $it['name'], 'qty' => $qty, 'price' => $unit, 'total' => $line];
            $itemsTxt .= '- ' . $it['name'] . ' x' . $qty . ' = ₹' . money($line) . "\n";
        }
        if ($clean) {
            $refCode = strtoupper(trim($_COOKIE['akc_ref'] ?? ''));
            $referrer = $refCode !== '' ? row('SELECT * FROM referrers WHERE code = ? AND is_active = 1', [$refCode]) : null;
            $fulfil = post('fulfil') === 'Shop pickup' ? 'Shop pickup' : 'Home delivery';
            $addr = $fulfil === 'Shop pickup' ? 'Shop pickup' : implode(', ', array_filter(array_map('trim', [post('address'), post('area'), post('pincode')])));
            $pay = in_array(post('pay_method'), ['UPI', 'Cash on delivery / at shop', 'Bank transfer'], true) ? post('pay_method') : '';
            $notes = trim($fulfil . ($pay !== '' ? ' · Pay: ' . $pay : '') . (trim(post('order_notes')) !== '' ? ' · ' . trim(post('order_notes')) : ''));
            q('INSERT INTO web_orders (customer_name, mobile, address, notes, items_json, total, ref_code, web_account_id) VALUES (?,?,?,?,?,?,?,?)',
              [$name, $mobile, $addr, $notes, json_encode($clean, JSON_UNESCAPED_UNICODE), $total, $referrer ? $referrer['code'] : null,
               $webAcct ? (int)$webAcct['id'] : null]);
            $oid = insert_id();
            q('UPDATE web_orders SET order_no = ? WHERE id = ?', [doc_no('WEB', $oid), $oid]);
            if ($cust) {   // a logged-in customer: the order is on their account; the address can be kept for next time
                q('UPDATE web_orders SET store_customer_id = ? WHERE id = ?', [(int)$cust['id'], $oid]);
                if (post('save_addr') && $fulfil !== 'Shop pickup' && trim(post('address')) !== ''
                    && !val('SELECT id FROM store_addresses WHERE customer_id = ? AND address = ?', [(int)$cust['id'], trim(post('address'))])) {
                    q('INSERT INTO store_addresses (customer_id, label, name, mobile, address, area, pincode, is_default) VALUES (?,?,?,?,?,?,?,?)',
                      [(int)$cust['id'], 'Home', $name, preg_replace('/\D/', '', $mobile), mb_substr(trim(post('address')), 0, 255), mb_substr(trim(post('area')), 0, 120), preg_replace('/\D/', '', post('pincode')),
                       val('SELECT COUNT(*) FROM store_addresses WHERE customer_id = ?', [(int)$cust['id']]) ? 0 : 1]);
                }
            }
            ak_earn_order_created($oid);   // AK Earn: order placed, not paid yet
            // partner commission books itself as PENDING right away; it turns
            // into an approved (payable) balance when the shop marks the order
            // completed, and cancels if the order cancels
            if ($referrer) {
                $comm = round($total * (float)$referrer['commission_pct'] / 100, 2);
                q('INSERT INTO referral_earnings (referrer_id, web_order_id, order_no, order_total, commission) VALUES (?,?,?,?,?)',
                  [$referrer['id'], $oid, doc_no('WEB', $oid), $total, $comm]);
                if ($referrer['mobile']) {
                    send_whatsapp($referrer['mobile'], "🎉 *" . setting('app_name', 'AK Computer') . "*\n\nA new order has come through your link!\nOrder: " . doc_no('WEB', $oid) . " · ₹" . money($total) .
                        "\nYour commission: *Rs " . money($comm) . "* (credited once the order is completed)\n\nStatus: " . base_url('referral.php?t=' . $referrer['token']));
                }
            }
            if ($waShop) {
                send_whatsapp($waShop, wa_template('weborder', [
                    'order_no' => doc_no('WEB', $oid), 'customer' => $name, 'mobile' => $mobile,
                    'address' => $addr . ($notes !== '' ? ' (' . $notes . ')' : ''), 'items' => trim($itemsTxt), 'total' => money($total),
                ]));
            }
            $orderOk = doc_no('WEB', $oid);
        }
    }
}

// ===========================================================================
//  What to show. Everything below reads the shop's own data.
// ===========================================================================
$c = store_ctx();
$items = store_items();
$groups = store_groups($items);
$ownShop = $c['own'];
$view = get('view');
if (!in_array($view, ['all', 'cats', 'wish', 'account'], true)) $view = '';
// Account: customer (default), dealer B2B, or tracking a guest order
$authTab = (get('dlogin') === '1' || get('dregister') === '1' || !empty($wloginError) || !empty($wregError) || $wregDone || get('tab') === 'dealer') ? 'dealer'
         : (get('tab') === 'staff' ? 'staff' : ((get('tab') === 'track' || $trackRes || $trackErr !== '') ? 'track' : 'customer'));
$acctMode = '';
if ($view === 'account' || $authTab !== 'customer' && (get('dlogin') === '1' || get('dregister') === '1' || $wregDone || !empty($wloginError) || !empty($wregError) || $trackRes || $trackErr !== '')) {
    if ($authTab === 'dealer') $acctMode = $webAcct ? 'dealer_home' : 'auth';
    elseif ($authTab === 'track' || $authTab === 'staff') $acctMode = 'auth';
    else $acctMode = $cust ? 'cust_home' : ($webAcct && get('a') === '' ? 'dealer_home' : 'auth');
}
$authView = $acctMode === 'auth';
$ranked = store_sort($items);
$stats = store_sales_stats();
$rows = [];   // home page collections

/** The top few promotable products matching a test, each product at most twice on the page. */
$used = [];
$pick = function (callable $ok, $n = 12, $sortKey = null) use ($ranked, $stats, &$used) {
    $list = array_values(array_filter($ranked, fn($it) => store_promotable($it) && $ok($it) && ($used[(int)$it['id']] ?? 0) < 2));
    if ($sortKey) usort($list, fn($a, $b) => $sortKey($b) <=> $sortKey($a));
    $list = array_slice($list, 0, $n);
    foreach ($list as $it) $used[(int)$it['id']] = ($used[(int)$it['id']] ?? 0) + 1;
    return $list;
};
$has = fn($re) => fn($it) => (bool)preg_match($re, $it['name'] . ' ' . ($it['cat_name'] ?? '') . ' ' . store_group($it));
$inStock = fn($it) => (float)($c['stock'][(int)$it['id']] ?? 0) > 0;

if ($view === '' && !$authView && !$orderOk) {
    $best = $pick(fn($it) => ($stats[(int)$it['id']]['q90'] ?? 0) > 0, 12, fn($it) => $stats[(int)$it['id']]['q90'] ?? 0);
    if ($best) $rows[] = ['fire', 'Best Sellers', 'Most sold in the last 90 days', $best, 'catalog.php?view=all&sort=best', ''];
    $daily = $pick(fn($it) => store_daily_use($it) && $inStock($it), 12);
    if (count($daily) >= 3) $rows[] = ['bag', 'Daily Essentials', 'Cables, mouse, toner and everyday parts — in stock', $daily, 'catalog.php?view=all&stock=1', ''];
    $now = $pick($inStock, 12);
    if (count($now) >= 3) $rows[] = ['truck', 'Ready for Delivery Today', 'On our shelf right now', $now, 'catalog.php?view=all&stock=1', ''];
    $cctv = $pick($has('/cctv|camera|dvr|nvr|security|bnc|junction|3\+1|poe/i'), 12);
    $newest = $pick(fn($it) => true, 12, fn($it) => (int)$it['id']);
    $up = $pick($has('/ssd|\bram\b|hdd|hard disk|smps|power supply|cabinet|graphic|processor|motherboard|storage/i'), 12);
    $net = $pick($has('/router|switch|poe|lan|cat ?6|access point|network|wifi|wi-fi/i'), 12);
    $print = $pick($has('/toner|ink|cartridge|printer/i'), 12);
}
$secGroup = '';
foreach ($groups as $g) if (preg_match('/security|cctv|camera/i', $g['name'])) { $secGroup = $g['name']; break; }

// category tiles: each main group with the photo of its most-wanted product
$tiles = [];
foreach ($groups as $g) {
    $img = '';
    foreach ($ranked as $it) if (store_group($it) === $g['name'] && store_promotable($it)) { $img = base_url($it['photo']); break; }
    $tiles[] = $g + ['img' => $img];
}

// ---------- hero slides ----------
$topPhoto = function ($re) use ($ranked) { foreach ($ranked as $it) if (store_promotable($it) && preg_match($re, $it['name'] . ' ' . ($it['cat_name'] ?? '') . ' ' . store_group($it))) return base_url($it['photo']); return ''; };
$anyPhoto = $topPhoto('/./');
$slides = [];
$bannerLines = trim(setting('store_banners', ''));
if ($bannerLines !== '') {
    foreach (explode("\n", $bannerLines) as $ln) {
        $pp = array_map(fn($x) => html_entity_decode(trim($x), ENT_QUOTES, 'UTF-8'), explode('|', $ln));
        if (($pp[0] ?? '') === '') continue;
        $slides[] = ['k' => $c['app'], 't' => $pp[0], 'em' => '', 'p' => $pp[1] ?? '', 'img' => $topPhoto('/' . preg_quote(strtok($pp[0], ' '), '/') . '/i') ?: $anyPhoto, 'cta' => [[$pp[5] ?? '' ?: 'catalog.php?view=all', 'Shop Now']]];
    }
} elseif ($ownShop) {
    $slides[] = ['k' => $c['app'] . ' · ' . (shop_city() ?: 'Dwarka'), 't' => 'Your Trusted', 'em' => 'Technology Store in ' . (shop_city() ?: 'Dwarka'),
                 'p' => 'Computer, Laptop, CCTV & Networking products — genuine products, warranty and fast local service.',
                 'img' => $topPhoto('/dome|bullet|ptz|camera(?!.*card)/i') ?: $anyPhoto, 'cta' => [['catalog.php?view=all', 'Shop Now'], [$secGroup ? store_group_url($secGroup) : 'catalog.php?view=all&q=cctv', 'Explore CCTV']], 'wa' => true];
    if ($p = $topPhoto('/dome|bullet|ptz|camera(?!.*card)/i')) $slides[] = ['k' => 'CCTV installation', 't' => 'Secure your home & business with', 'em' => 'CCTV Solutions', 'p' => 'HD & IP cameras, DVR / NVR, wiring and phone live-view — set up by our own team.',
                 'img' => $topPhoto('/bullet|nvr|dvr/i') ?: $p, 'feat' => true, 'cta' => [[$secGroup ? store_group_url($secGroup) : 'catalog.php?view=all&q=cctv', 'Shop CCTV'], [seo_service_url('cctv-installation'), 'Free site visit']]];
    if ($p = $topPhoto('/laptop|desktop|monitor/i')) $slides[] = ['k' => 'Laptop & Desktop', 't' => 'Laptops, desktops', 'em' => '& upgrades', 'p' => 'SSD, RAM and parts fitted while you wait. Repairs in-house.', 'img' => $p, 'cta' => [['catalog.php?view=all&q=laptop', 'Shop Laptops'], [seo_service_url('laptop-repair'), 'Repair']]];
    if ($p = $topPhoto('/printer|toner|ink|cartridge/i')) $slides[] = ['k' => 'Printer & Accessories', 't' => 'Printers, ink', 'em' => '& toner', 'p' => 'Ink tank and laser printers, refills and cartridges.', 'img' => $p, 'cta' => [['catalog.php?view=all&q=printer', 'Shop Printers']]];
    if ($p = $topPhoto('/router|switch|wifi|network|access point/i')) $slides[] = ['k' => 'Networking & WiFi', 't' => 'Fast WiFi for', 'em' => 'home & office', 'p' => 'Routers, PoE switches, LAN cable and installation.', 'img' => $p, 'cta' => [['catalog.php?view=all&q=router', 'Shop Networking'], [seo_service_url('networking'), 'Get it installed']]];
} else {
    $slides[] = ['k' => $c['app'], 't' => $c['app'], 'em' => '', 'p' => 'Genuine products · Order straight on WhatsApp', 'img' => $anyPhoto, 'cta' => [['catalog.php?view=all', 'Shop Now']], 'wa' => true];
}

// ---------- FAQ (answers only what the shop really does) ----------
$hasUpi = (bool)val("SELECT COUNT(*) FROM bank_accounts WHERE is_active = 1 AND COALESCE(upi_id, '') <> ''" );
$faqLines = trim(setting('store_faqs', ''));
$faqs = [];
if ($faqLines !== '') {
    foreach (explode("\n", $faqLines) as $ln) { $pp = explode('|', $ln, 2); if (trim($pp[0] ?? '') !== '' && trim($pp[1] ?? '') !== '') $faqs[] = [trim($pp[0]), trim($pp[1])]; }
} else {
    $city = shop_city() ?: 'our town';
    $faqs = [['How do I order?', 'Tap the cart button on a product, open the cart and enter your name and mobile. The order reaches us at once and we confirm it on WhatsApp or a call.'],
             ['Can I order on WhatsApp?', 'Yes. In the cart, press "Order on WhatsApp" — the products, quantity and total are written for you.'],
             ["Do you deliver in $city?", "Yes, in $city and nearby. You can also pick the order up at the shop."],
             ['Can I order a product that is out of stock?', 'Yes. Order it anyway or ask on WhatsApp — we get it for you and tell you the date.'],
             ['Is there a warranty?', 'Products come with the company warranty; the warranty period is shown on each product.'],
             ['Can I get a quotation?', 'Yes — for bulk, business or a complete CCTV / networking job, WhatsApp us your list and we send a quotation.']];
    if ($ownShop) {
        $faqs[] = ['Do you install CCTV?', 'Yes, HD and IP cameras, DVR / NVR and wiring, with phone live-view set up. Ask for a free site visit.'];
        $faqs[] = ['Do you repair laptops and printers?', 'Yes — laptops, desktops and printers are repaired at our shop.'];
    }
    if ($hasUpi) $faqs[] = ['Do you accept UPI?', 'Yes, UPI is accepted, as well as cash at the shop.'];
}

// ---------- genuine reviews only (approved customer reviews of our products) ----------
$reviews = [];
try { $reviews = all("SELECT r.*, i.name item_name, i.id item_id, i.brand, i.model FROM product_reviews r JOIN items i ON i.id = r.item_id
                      WHERE r.status = 'approved' AND i.show_on_website = 1 AND i.is_active = 1 ORDER BY r.is_verified DESC, r.id DESC LIMIT 6"); } catch (Exception $e) {}

// ---------- all-products view: filters ----------
$fG = trim((string)get('g')); $fCat = trim((string)get('cat')); $fBrand = trim((string)get('brand')); $fQ = trim((string)get('q'));
$fStock = get('stock') === '1'; $fSort = in_array(get('sort'), ['popular', 'best', 'price_asc', 'price_desc', 'new'], true) ? get('sort') : 'popular';
$fMin = (float)get('min'); $fMax = (float)get('max');
// product filters read from the product names ("Audio", "4MP", "DDR4" ...)
$fx = array_filter(array_map(fn($v) => trim((string)$v), is_array($_GET['fx'] ?? null) ? $_GET['fx'] : []), fn($v, $k) => $v !== '' && isset(ic_facet_labels()[$k]), ARRAY_FILTER_USE_BOTH);
$facets = [];
$list = []; $pages = 1; $page = max(1, (int)get('page')); $per = 24; $nList = 0; $brandsHere = []; $catsHere = [];
if ($view === 'all') {
    $list = $fQ !== '' ? store_search($items, $fQ) : $items;
    if ($fG !== '') $list = array_values(array_filter($list, fn($it) => store_group($it) === $fG));
    foreach ($list as $it) { if ($it['cat_name']) $catsHere[$it['cat_name']] = ($catsHere[$it['cat_name']] ?? 0) + 1; }
    if ($fCat !== '') $list = array_values(array_filter($list, fn($it) => $it['cat_name'] === $fCat));
    foreach ($list as $it) { $b = trim((string)$it['brand']); if ($b !== '') $brandsHere[$b] = ($brandsHere[$b] ?? 0) + 1; }
    arsort($brandsHere); ksort($catsHere);
    if ($fBrand !== '') $list = array_values(array_filter($list, fn($it) => strcasecmp(trim((string)$it['brand']), $fBrand) === 0));
    // filters offered = what the products in view actually have (2+ different values, or a yes that not all have)
    $fmap = ic_facet_map($list);
    foreach (ic_facet_labels() as $k => $lab) {
        $vals = [];
        foreach ($fmap as $m) if (isset($m[$k])) $vals[$m[$k]] = ($vals[$m[$k]] ?? 0) + 1;
        if (count($vals) >= 2 || ($vals && array_sum($vals) < count($list)) || isset($fx[$k])) { ksort($vals, SORT_NATURAL); $facets[$k] = $vals; }
    }
    foreach ($fx as $k => $v) $list = array_values(array_filter($list, fn($it) => ($fmap[(int)$it['id']][$k] ?? null) === $v));
    if ($fStock) $list = array_values(array_filter($list, $inStock));
    if ($fMin > 0) $list = array_values(array_filter($list, fn($it) => dealer_price($it['selling_price'], $c['pct']) >= $fMin));
    if ($fMax > 0) $list = array_values(array_filter($list, fn($it) => dealer_price($it['selling_price'], $c['pct']) <= $fMax));
    $list = store_sort($list, $fSort);
    $nList = count($list);
    $pages = max(1, (int)ceil($nList / $per)); $page = min($page, $pages);
    $list = array_slice($list, ($page - 1) * $per, $per);
}
$qsKeep = fn(array $set) => 'catalog.php?' . http_build_query(array_filter(array_merge(['view' => 'all', 'g' => $fG, 'cat' => $fCat, 'brand' => $fBrand, 'q' => $fQ, 'stock' => $fStock ? 1 : '', 'sort' => $fSort === 'popular' ? '' : $fSort, 'min' => $fMin ?: '', 'max' => $fMax ?: '', 'fx' => $fx ?: ''], $set), fn($v) => $v !== '' && $v !== null && $v !== []));

// ---------- titles, canonical, robots ----------
$city = shop_city() ?: 'Dwarka';
$metaDesc = $ownShop
    ? $c['app'] . ' — trusted computer and CCTV store in ' . shop_place() . '. Laptops, CCTV cameras, printers, networking, accessories & repairs. ' . count($items) . '+ products with price and stock — order online or on WhatsApp.'
    : $c['app'] . ' — ' . ucfirst(shop_trade()) . (shop_place() !== '' ? ' in ' . shop_place() : '') . '. ' . count($items) . '+ products online — order on WhatsApp.';
$title = $ownShop ? $c['app'] . ' — Computer & CCTV Store in ' . $city . ' | Laptops, CCTV Cameras, Printers' : $c['app'] . ' — ' . ucfirst(shop_trade()) . (shop_place() !== '' ? ', ' . shop_place() : '');
$canon = base_url('') . '/'; $robots = '';
$hdrMode = 'home'; $hdrTitle = '';
if ($view === 'all') {
    $hdrMode = 'back';
    $hdrTitle = $fQ !== '' ? '“' . $fQ . '”' : ($fCat ?: ($fG ?: 'All Products'));
    $catHead = $fCat ?: $fG; $catImg = '';
    if ($catHead !== '') foreach ($ranked as $it) if (($it['cat_name'] === $catHead || store_group($it) === $catHead) && store_promotable($it)) { $catImg = base_url($it['photo']); break; }
    $title = ($fQ !== '' ? 'Search: ' . $fQ : ($fCat ?: ($fG ?: 'All Products'))) . ' in ' . $city . ' | ' . $c['app'];
    $canon = base_url('catalog.php?view=all') . ($fG !== '' && $fCat === '' && $fBrand === '' && $fQ === '' ? '&g=' . rawurlencode($fG) : '');
    if ($fQ !== '' || $fBrand !== '' || $fStock || $fSort !== 'popular' || $fMin || $fMax || $page > 1 || $fCat !== '' || $fx) $robots = 'noindex, follow';
} elseif ($view === 'cats') { $hdrMode = 'back'; $hdrTitle = 'All Categories'; $title = 'All Categories | ' . $c['app'] . ' ' . $city; $canon = base_url('catalog.php?view=cats'); }
elseif ($view === 'wish') { $hdrMode = 'back'; $hdrTitle = 'Wishlist'; $title = 'My Wishlist | ' . $c['app']; $robots = 'noindex'; $canon = ''; }
elseif ($view === 'account') { $hdrMode = 'back'; $hdrTitle = 'My Account'; $title = 'My Account | ' . $c['app']; $robots = 'noindex'; $canon = ''; }
if ($authView || $orderOk) { $robots = 'noindex'; $hdrMode = 'back'; $hdrTitle = $orderOk ? 'Order placed' : 'Account'; }

$ld = seo_localbusiness_jsonld() . seo_jsonld(['@context' => 'https://schema.org', '@type' => 'WebSite', 'name' => $c['app'], 'url' => base_url('') . '/',
      'potentialAction' => ['@type' => 'SearchAction', 'target' => base_url('catalog.php?view=all&q={search_term_string}'), 'query-input' => 'required name=search_term_string']]);
if ($view === '' && $faqs) $ld .= seo_faq_jsonld(array_combine(array_column($faqs, 0), array_column($faqs, 1)));
store_head($title, $metaDesc, $canon, $ld, $robots);
if ($orderOk) echo '<script>document.body.dataset.ordered="1"</script>';
store_header($hdrMode, $hdrTitle, $fQ);
store_nav($groups, $fG);
$tileHtml = function ($t) { ?>
  <a class="tile" href="<?= e(store_group_url($t['name'])) ?>"><span class="ti"><?php if ($t['img']): ?><img src="<?= e($t['img']) ?>" alt="" loading="lazy"><?php else: ?><?= store_icon(store_cat_icon($t['name'])) ?><?php endif; ?></span><span><?= e($t['name']) ?></span></a>
<?php };
?>
<main>
<?php if ($orderOk): ?>
  <section class="sec" style="max-width:560px">
    <div class="panel empty">
      <?= store_icon('check') ?>
      <h3>Order received — <?= e($orderOk) ?></h3>
      <p>Thank you! We will confirm it on WhatsApp or a call shortly.</p>
      <a class="btn" href="catalog.php">Continue shopping</a>
      <?php if ($c['wa']): ?><a class="btn wa" style="margin-top:8px" href="https://wa.me/<?= e($c['wa']) ?>?text=<?= rawurlencode('Hello! I just placed order ' . $orderOk . ' on the website.') ?>" target="_blank" rel="noopener"><?= store_icon('wa') ?>Message us about it</a><?php endif; ?>
    </div>
  </section>

<?php elseif ($authView): ?>
  <div class="auth-top"><div class="wrap" style="max-width:480px;padding:0">
    <div class="logo"><span class="mark"><?= e(mb_strtoupper(mb_substr(preg_replace('/[^\p{L}]/u', '', $c['app']), 0, 2))) ?></span><span class="nm"><?= e($c['app']) ?></span></div>
    <p><?= $ownShop ? 'IT Products · Security · Networking · Service' : e(ucfirst(shop_trade())) ?><?= shop_place() !== '' ? ' — ' . e(shop_place()) : '' ?></p>
  </div></div>
  <div class="auth-card">
    <?php if ($wregDone): ?>
      <div class="empty"><?= store_icon('check') ?><h3>Registration received</h3><p>You get a WhatsApp once the shop approves your account. Log in after that to see your dealer prices.</p><a class="btn" href="catalog.php">Back to the store</a></div>
    <?php else: ?>
    <?php $scA = in_array(get('a'), ['login', 'otp', 'register', 'reset'], true) ? get('a') : 'login';
    $isDReg = get('dregister') === '1' || !empty($wregError);
    [$h1a, $h1b, $hsub] = $authTab === 'staff' ? ['Staff', 'Login', 'For the shop team — billing, stock and the dashboard']
        : ($authTab === 'track' ? ['Track', 'Your Order', 'No account needed — use your order number and mobile']
        : ($authTab === 'dealer' ? ($isDReg ? ['Register', 'as a Dealer', 'Wholesale prices for dealers, electricians and installers'] : ['Dealer', 'Login (B2B)', 'Dealer prices, your orders and quotations'])
        : ($scA === 'register' ? ['Create', 'Your Account', 'Save addresses, track every order, keep your wishlist'] : ($scA === 'reset' ? ['Reset', 'Your Password', 'We send a code to your mobile or e-mail'] : ['Login', 'to Your Account', 'Access your orders, addresses and wishlist'])))); ?>
    <h1><span><?= e($h1a) ?></span> <?= e($h1b) ?></h1>
    <p class="sub"><?= e($hsub) ?></p>
    <div class="tabs" role="tablist" style="grid-template-columns:repeat(3,1fr)">
      <a href="catalog.php?view=account" class="<?= in_array($authTab, ['customer', 'track'], true) ? 'on' : '' ?>" role="tab"><?= store_icon('user') ?>Customer</a>
      <a href="catalog.php?view=account&amp;tab=dealer" class="<?= $authTab === 'dealer' ? 'on' : '' ?>" role="tab"><?= store_icon('store') ?>Dealer</a>
      <a href="catalog.php?view=account&amp;tab=staff" class="<?= $authTab === 'staff' ? 'on' : '' ?>" role="tab"><?= store_icon('lock') ?>Staff</a>
    </div>
    <?php foreach (get_flashes() as $f): ?><p class="note <?= $f['type'] === 'error' ? 'bad' : 'ok' ?>" style="margin-bottom:12px"><?= e($f['msg']) ?></p><?php endforeach; ?>

    <?php if ($authTab === 'staff'): ?>
      <?php if (current_user()): ?>
      <div class="empty" style="padding:10px 0"><?= store_icon('check') ?><h3>Logged in as <?= e(current_user()['name'] ?? current_user()['username'] ?? '') ?></h3><p>Open the shop software.</p><a class="btn" href="<?= e(base_url('index.php')) ?>"><?= store_icon('store') ?>Open Dashboard</a></div>
      <?php else: ?>
      <!-- the shop's own login page does the checking (2-step code, lock-out after wrong tries, IP rules) -->
      <form method="post" action="<?= e(base_url('login.php')) ?>"><?= csrf_field() ?>
        <div class="ifield"><?= store_icon('user') ?><input name="username" required autocapitalize="none" autocomplete="username" placeholder="Username or e-mail" aria-label="Username or e-mail"></div>
        <div class="ifield"><?= store_icon('lock') ?><input type="password" name="password" required autocomplete="current-password" placeholder="Password" aria-label="Password" data-pw>
          <button class="hbtn eye" type="button" data-eye aria-label="Show password"><?= store_icon('eye') ?></button></div>
        <button class="btn block" type="submit"><?= store_icon('next') ?>Staff Login</button>
      </form>
      <div style="display:flex;justify-content:space-between;margin-top:12px;font-size:13px">
        <a style="color:var(--brand);font-weight:700" href="<?= e(base_url('login.php')) ?>">Log in with fingerprint / face</a>
        <a style="color:var(--brand);font-weight:700" href="<?= e(base_url('forgot_password.php')) ?>">Forgot password?</a>
      </div>
      <?php endif; ?>
    <?php elseif ($authTab === 'customer'): sc_auth_body($scA, (string)get('step')); ?>
    <?php elseif ($authTab === 'track'): ?>
      <?php if ($trackErr !== ''): ?><p class="note bad" style="margin-bottom:12px"><?= e($trackErr) ?></p><?php endif; ?>
      <?php if ($trackRes): $tItems = json_decode((string)$trackRes['items_json'], true) ?: []; ?>
      <div class="panel" style="margin-bottom:14px">
        <div style="display:flex;justify-content:space-between;align-items:center"><b><?= e($trackRes['order_no']) ?></b><span class="stkbox <?= in_array($trackRes['status'], ['completed', 'delivered'], true) ? 'in' : ($trackRes['status'] === 'cancelled' ? 'out' : 'ord') ?>"><?= e(ucfirst((string)$trackRes['status'] ?: 'Received')) ?></span></div>
        <p class="muted" style="font-size:13px;margin-top:4px">Placed <?= dmy($trackRes['created_at']) ?> · <?= e($trackRes['address']) ?></p>
        <?php foreach ($tItems as $ti): ?><div class="cl" style="grid-template-columns:1fr auto;padding:8px 0"><span class="n"><?= e($ti['name'] ?? '') ?> × <?= (int)($ti['qty'] ?? 1) ?></span><b>₹<?= money($ti['total'] ?? 0) ?></b></div><?php endforeach; ?>
        <div class="tot"><span>Total</span><span>₹<?= money($trackRes['total']) ?></span></div>
      </div>
      <?php endif; ?>
      <form method="post" action="catalog.php?view=account&amp;tab=track"><?= csrf_field() ?><input type="hidden" name="do" value="track">
        <div class="ifield"><?= store_icon('receipt') ?><input name="torder" required placeholder="Order number, e.g. WEB-00012" autocapitalize="characters" aria-label="Order number" value="<?= e(post('torder')) ?>"></div>
        <div class="ifield"><?= store_icon('phone') ?><input name="tmobile" type="tel" inputmode="numeric" required placeholder="Mobile used for the order" aria-label="Mobile" value="<?= e(post('tmobile')) ?>"></div>
        <button class="btn block" type="submit"><?= store_icon('search') ?>Track order</button>
      </form>
      <?php if ($c['wa']): ?><a class="btn ghost block" style="margin-top:8px" href="https://wa.me/<?= e($c['wa']) ?>?text=<?= rawurlencode('Hello! I want to know the status of my order.') ?>" target="_blank" rel="noopener"><?= store_icon('wa') ?>Ask on WhatsApp</a><?php endif; ?>
      <p class="muted" style="font-size:13px;text-align:center;margin-top:12px">Have an account? <a style="color:var(--brand);font-weight:700" href="catalog.php?view=account">Log in</a> to see all your orders.</p>

    <?php elseif (get('dregister') === '1' || !empty($wregError)): ?>
      <?php if (!empty($wregError)): ?><p class="note bad" style="margin-bottom:12px"><?= e($wregError) ?></p><?php endif; ?>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="wregister">
        <div class="ifield"><?= store_icon('store') ?><input name="rname" required placeholder="Name / firm, e.g. Ramesh Electricals" aria-label="Name or firm"></div>
        <div class="ifield"><?= store_icon('phone') ?><input type="tel" inputmode="numeric" name="rmobile" required pattern="[0-9]{10,12}" placeholder="Mobile (WhatsApp)" aria-label="Mobile"></div>
        <div class="ifield"><?= store_icon('lock') ?><input type="password" name="rpassword" required minlength="4" autocomplete="new-password" placeholder="Choose a password" aria-label="Password" data-pw>
          <button class="hbtn eye" type="button" data-eye aria-label="Show password"><?= store_icon('eye') ?></button></div>
        <button class="btn block" type="submit">Register</button>
        <p class="muted" style="font-size:12.5px;text-align:center;margin-top:10px">The shop checks every dealer request and confirms on WhatsApp.</p>
      </form>
      <div class="newbox"><span><b>Already approved?</b><p>Log in to see your prices.</p></span><a class="btn ghost" href="catalog.php?dlogin=1">Log in</a></div>

    <?php else: ?>
      <?php if (!empty($wloginError)): ?><p class="note bad" style="margin-bottom:12px"><?= e($wloginError) ?></p><?php endif; ?>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="wlogin">
        <div class="ifield"><?= store_icon('phone') ?><input type="tel" inputmode="numeric" name="wmobile" required autocomplete="username" placeholder="Mobile number" aria-label="Mobile"></div>
        <div class="ifield"><?= store_icon('lock') ?><input type="password" name="wpassword" required autocomplete="current-password" placeholder="Password" aria-label="Password" data-pw>
          <button class="hbtn eye" type="button" data-eye aria-label="Show password"><?= store_icon('eye') ?></button></div>
        <button class="btn block" type="submit"><?= store_icon('next') ?>Login</button>
        <?php if ($c['wa']): ?><p class="muted" style="font-size:12.5px;text-align:center;margin-top:10px">Forgot your password? <a style="color:var(--brand);font-weight:700" href="https://wa.me/<?= e($c['wa']) ?>?text=<?= rawurlencode('Hello! I forgot my dealer login password.') ?>" target="_blank" rel="noopener">Ask the shop on WhatsApp</a></p><?php endif; ?>
      </form>
      <div class="newbox"><span><b>New dealer?</b><p>Register to get wholesale prices, orders and quotations.</p></span><a class="btn ghost" href="catalog.php?dregister=1"><?= store_icon('user') ?>Register</a></div>
    <?php endif; ?>
    <div class="trust4" style="margin-top:16px;border:0;background:var(--bg)">
      <div><?= store_icon('truck') ?>Fast<br>delivery</div><div><?= store_icon('shield') ?>Genuine<br>products</div><div><?= store_icon('tool') ?>Expert<br>support</div><div><?= store_icon('check') ?>Warranty<br>&amp; service</div>
    </div>
    <?php endif; ?>
  </div>

<?php elseif ($acctMode === 'cust_home'): ?>
  <?php foreach (get_flashes() as $f): ?><div class="sec" style="max-width:820px"><p class="note <?= $f['type'] === 'error' ? 'bad' : 'ok' ?>"><?= e($f['msg']) ?></p></div><?php endforeach; ?>
  <?php sc_account_body($cust); ?>

<?php elseif ($acctMode === 'dealer_home'): $myOrders = all('SELECT order_no, created_at, total, status FROM web_orders WHERE web_account_id = ? ORDER BY id DESC LIMIT 20', [(int)$webAcct['id']]); ?>
  <section class="sec" style="max-width:720px">
    <div class="panel" style="display:flex;align-items:center;gap:12px">
      <span class="mark" style="width:48px;height:48px;border-radius:50%;background:var(--brand-soft);color:var(--brand);display:grid;place-items:center"><?= store_icon('user') ?></span>
      <div style="flex:1"><b style="font-size:16px"><?= e($webAcct['name']) ?></b><div class="muted" style="font-size:13px"><?= e($webAcct['mobile']) ?> · dealer prices on</div></div>
      <a class="btn ghost" style="height:40px" href="catalog.php?wlogout=1">Log out</a>
    </div>
    <div class="sec-h" style="margin-top:18px"><h2>My orders</h2></div>
    <?php if ($myOrders): ?><div class="panel" style="padding:4px 16px">
      <?php foreach ($myOrders as $o): ?><div class="cl" style="grid-template-columns:1fr auto"><div><div class="n"><?= e($o['order_no']) ?></div><div class="p"><?= dmy($o['created_at']) ?> · <?= e(ucfirst((string)$o['status'])) ?></div></div><b>₹<?= money($o['total']) ?></b></div><?php endforeach; ?>
    </div><?php else: ?><div class="panel empty"><?= store_icon('bag') ?><h3>No orders yet</h3><p>Your website orders will show here.</p><a class="btn" href="catalog.php?view=all">Shop now</a></div><?php endif; ?>
    <?php if ($c['wa']): ?><div class="band" style="margin-top:16px"><div><h2>Need a <em>wholesale quotation</em>?</h2><p>Send your list — we reply with your best prices.</p></div><div class="cta"><a class="btn-w" href="https://wa.me/<?= e($c['wa']) ?>?text=<?= rawurlencode('Hello! Dealer ' . $webAcct['name'] . ' here — I need a quotation for:') ?>" target="_blank" rel="noopener"><?= store_icon('wa') ?>Ask on WhatsApp</a></div></div><?php endif; ?>
  </section>

<?php elseif ($view === 'cats'): $byG = []; foreach (seo_cats() as $cc) { $g = store_parents()[$cc['name']] ?? $cc['name']; $byG[$g][] = $cc['name']; } ?>
  <section class="sec">
    <div class="sec-h"><h2>All Categories</h2></div>
    <div class="crows">
    <?php foreach ($tiles as $t): $kids = array_filter($byG[$t['name']] ?? [], fn($k) => $k !== $t['name']); ?>
      <a class="crow" href="<?= e(store_group_url($t['name'])) ?>"><span class="ti"><?php if ($t['img']): ?><img src="<?= e($t['img']) ?>" alt="" loading="lazy"><?php else: ?><?= store_icon(store_cat_icon($t['name'])) ?><?php endif; ?></span>
        <span><b><?= e($t['name']) ?></b><small><?= e($kids ? implode(', ', $kids) : $t['n'] . ' products') ?></small></span><?= store_icon('next') ?></a>
    <?php endforeach; ?>
    </div>
  </section>

<?php elseif ($view === 'wish'): ?>
  <section class="sec">
    <div class="grid" data-wish-grid></div>
    <div class="panel empty" data-wish-empty hidden><?= store_icon('heart') ?><h3>Your wishlist is empty</h3><p>Tap the heart on any product to keep it here.</p><a class="btn" href="catalog.php?view=all">Browse products</a></div>
  </section>

<?php elseif ($view === 'all'): ?>
  <section class="sec" style="margin-top:4px">
    <div class="cathead"><span class="ti"><?php if ($catImg): ?><img src="<?= e($catImg) ?>" alt=""><?php else: ?><?= store_icon($fQ !== '' ? 'search' : store_cat_icon($catHead ?: 'box')) ?><?php endif; ?></span>
      <h1><?= e($fQ !== '' ? 'Results for “' . $fQ . '”' : ($catHead ?: 'All Products')) ?><small><?= $nList ?> product<?= $nList === 1 ? '' : 's' ?><?= $fBrand !== '' ? ' · ' . e($fBrand) : '' ?></small></h1>
      <button class="hbtn" type="button" data-open="filters" aria-label="Filters"><?= store_icon('filter') ?></button></div>
    <?php if ($catsHere && count($catsHere) > 1): ?>
    <div class="chips" style="margin-bottom:8px">
      <a class="chip <?= $fCat === '' ? 'on' : '' ?>" href="<?= e($qsKeep(['cat' => '', 'brand' => '', 'page' => ''])) ?>">All</a>
      <?php foreach ($catsHere as $cn => $n): ?><a class="chip <?= $fCat === $cn ? 'on' : '' ?>" href="<?= e($qsKeep(['cat' => $cn, 'brand' => '', 'page' => ''])) ?>"><?= e($cn) ?> <span class="n"><?= $n ?></span></a><?php endforeach; ?>
    </div>
    <?php endif; ?>
    <?php $fxChip = function ($k, $v) use ($fx, $qsKeep) { $on = ($fx[$k] ?? '') === $v; $nfx = $fx; if ($on) unset($nfx[$k]); else $nfx[$k] = $v;
        return '<a class="chip ' . ($on ? 'on' : '') . '" href="' . e($qsKeep(['fx' => $nfx, 'page' => ''])) . '">' . e($v) . '</a>'; };
    $quick = [];   // the few filters people use most, in this order: megapixels, audio, night vision ...
    foreach (['res', 'audio', 'night', 'ctype', 'cap', 'ddr', 'for', 'ptype', 'wired', 'screen', 'tech'] as $qk) if (isset($facets[$qk]) && count($quick) < 3) $quick[$qk] = $facets[$qk];
    if ($quick): ?>
    <div class="chips" style="margin-bottom:8px">
      <?php foreach ($quick as $k => $vals) foreach ($vals as $v => $n) echo $fxChip($k, (string)$v); ?>
    </div>
    <?php endif; ?>
    <?php if (count($brandsHere) > 1): ?>
    <div class="chips" style="margin-bottom:8px">
      <a class="chip <?= $fBrand === '' ? 'on' : '' ?>" href="<?= e($qsKeep(['brand' => '', 'page' => ''])) ?>">All brands</a>
      <?php foreach (array_slice($brandsHere, 0, 20, true) as $bn => $n): ?><a class="chip <?= strcasecmp($fBrand, $bn) === 0 ? 'on' : '' ?>" href="<?= e($qsKeep(['brand' => strcasecmp($fBrand, $bn) === 0 ? '' : $bn, 'page' => ''])) ?>"><?= e($bn) ?></a><?php endforeach; ?>
    </div>
    <?php endif; ?>
    <div style="display:flex;align-items:center;gap:8px;margin:6px 0 12px">
      <span class="muted" style="font-size:13px;margin-right:auto">Sort by</span>
      <form method="get" data-autosubmit style="display:contents">
        <?php foreach (['view' => 'all', 'g' => $fG, 'cat' => $fCat, 'brand' => $fBrand, 'q' => $fQ, 'stock' => $fStock ? '1' : '', 'min' => $fMin ?: '', 'max' => $fMax ?: ''] as $k => $v) if ($v !== '') echo '<input type="hidden" name="' . $k . '" value="' . e($v) . '">'; ?>
        <select name="sort" class="chip" aria-label="Sort" style="padding-right:8px">
          <?php foreach (['popular' => 'Popular', 'best' => 'Best selling', 'price_asc' => 'Price: low to high', 'price_desc' => 'Price: high to low', 'new' => 'Newest'] as $k => $lab): ?><option value="<?= $k ?>" <?= $fSort === $k ? 'selected' : '' ?>><?= $lab ?></option><?php endforeach; ?>
        </select>
      </form>
    </div>
    <?php if ($list): ?>
    <div class="list"><?php foreach ($list as $it) store_card($it, 'list'); ?></div>
    <?php if ($pages > 1): ?>
    <nav class="chips" style="justify-content:center;margin-top:16px" aria-label="Pages">
      <?php if ($page > 1): ?><a class="chip" rel="prev" href="<?= e($qsKeep(['page' => $page - 1])) ?>">‹ Previous</a><?php endif; ?>
      <span class="chip on"><?= $page ?> / <?= $pages ?></span>
      <?php if ($page < $pages): ?><a class="chip" rel="next" href="<?= e($qsKeep(['page' => $page + 1])) ?>">Next ›</a><?php endif; ?>
    </nav>
    <?php endif; ?>
    <?php else: ?>
    <div class="panel empty"><?= store_icon('search') ?><h3>No products found<?= $fQ !== '' ? ' for “' . e($fQ) . '”' : '' ?></h3>
      <p>Try another word or fewer filters<?= $c['wa'] ? ' — or ask us, we can get it for you' : '' ?>.</p>
      <a class="btn ghost" href="catalog.php?view=all">Show all products</a>
      <?php if ($c['wa']): ?><a class="btn wa" style="margin-top:8px" href="https://wa.me/<?= e($c['wa']) ?>?text=<?= rawurlencode('Hello! Do you have: ' . ($fQ ?: $fCat ?: $fG)) ?>" target="_blank" rel="noopener"><?= store_icon('wa') ?>Ask on WhatsApp</a><?php endif; ?>
    </div>
    <?php endif; ?>
  </section>
  <section class="sheet" id="filters" aria-label="Filters" role="dialog">
    <div class="sheet-h"><h2>Filters</h2><button class="hbtn" type="button" data-close aria-label="Close"><?= store_icon('x') ?></button></div>
    <form method="get">
      <input type="hidden" name="view" value="all"><?php foreach (['q' => $fQ, 'sort' => $fSort === 'popular' ? '' : $fSort] as $k => $v) if ($v !== '') echo '<input type="hidden" name="' . $k . '" value="' . e($v) . '">'; ?>
      <div class="field"><label for="fg">Category</label><select id="fg" name="g"><option value="">All categories</option><?php foreach ($groups as $g): ?><option <?= $fG === $g['name'] ? 'selected' : '' ?>><?= e($g['name']) ?></option><?php endforeach; ?></select></div>
      <?php if ($brandsHere): ?><div class="field"><label for="fb">Brand</label><select id="fb" name="brand"><option value="">All brands</option><?php foreach (array_keys($brandsHere) as $bn): ?><option <?= strcasecmp($fBrand, $bn) === 0 ? 'selected' : '' ?>><?= e($bn) ?></option><?php endforeach; ?></select></div><?php endif; ?>
      <div class="two">
        <div class="field" style="margin:0"><label for="fmin">Min price ₹</label><input id="fmin" name="min" inputmode="numeric" value="<?= $fMin ?: '' ?>"></div>
        <div class="field" style="margin:0"><label for="fmax">Max price ₹</label><input id="fmax" name="max" inputmode="numeric" value="<?= $fMax ?: '' ?>"></div>
      </div>
      <?php foreach ($facets as $k => $vals): ?>
      <div class="field"><label><?= e(ic_facet_labels()[$k]) ?></label>
        <div class="chips" style="flex-wrap:wrap">
          <label class="chip"><input type="radio" name="fx[<?= e($k) ?>]" value="" <?= !isset($fx[$k]) ? 'checked' : '' ?>> Any</label>
          <?php foreach ($vals as $v => $n): ?><label class="chip"><input type="radio" name="fx[<?= e($k) ?>]" value="<?= e($v) ?>" <?= ($fx[$k] ?? '') === (string)$v ? 'checked' : '' ?>> <?= e($v) ?> <span class="n"><?= (int)$n ?></span></label><?php endforeach; ?>
        </div></div>
      <?php endforeach; ?>
      <?php if ($fCat !== ''): ?><input type="hidden" name="cat" value="<?= e($fCat) ?>"><?php endif; ?>
      <label class="chip" style="width:100%;justify-content:flex-start;height:46px;margin-bottom:14px"><input type="checkbox" name="stock" value="1" <?= $fStock ? 'checked' : '' ?>> In stock only</label>
      <button class="btn block" type="submit">Show products</button>
      <a class="btn ghost block" style="margin-top:8px" href="catalog.php?view=all">Clear all</a>
    </form>
  </section>
  <div class="sheet-bg" data-close></div>

<?php else: /* ---------------- home ---------------- */ ?>
  <?php foreach (get_flashes() as $f): ?><div class="sec"><p class="note <?= $f['type'] === 'error' ? 'bad' : 'ok' ?>"><?= e($f['msg']) ?></p></div><?php endforeach; ?>
  <?php if ($webAcct): ?><div class="sec"><p class="note ok">Welcome, <?= e($webAcct['name']) ?> — your dealer prices are on.</p></div><?php endif; ?>
  <div class="sec" style="margin-top:10px"><div class="install" data-install><?= store_icon('store') ?><span><b>Install the <?= e($c['app']) ?> app</b><br><span class="muted">Opens like an app, right from your home screen.</span></span>
    <button class="btn" type="button" data-install-go>Install</button><button class="hbtn" type="button" data-install-no aria-label="Not now"><?= store_icon('x') ?></button></div></div>

  <section class="hero" aria-label="Offers">
    <div class="slides">
      <?php foreach ($slides as $i => $s): $h = $i === 0 ? 'h1' : 'h2'; ?>
      <div class="slide">
        <div>
          <div class="k"><?= e($s['k']) ?></div>
          <<?= $h ?>><?= e($s['t']) ?><?php if ($s['em'] !== ''): ?> <em><?= e($s['em']) ?></em><?php endif; ?></<?= $h ?>>
          <p><?= e($s['p']) ?></p>
          <?php if (!empty($s['feat'])): ?><div class="feat"><span><?= store_icon('camera') ?>HD quality</span><span><?= store_icon('moon') ?>Night vision</span><span><?= store_icon('phone') ?>Phone view</span></div><?php endif; ?>
          <div class="cta">
            <?php foreach ($s['cta'] as $k => [$href, $lab]): ?><a class="<?= $k ? 'btn-g' : 'btn-w' ?>" href="<?= e($href) ?>"><?= e($lab) ?><?= $k ? '' : ' →' ?></a><?php endforeach; ?>
            <?php if (!empty($s['wa']) && $c['wa']): ?><a class="btn-g" href="https://wa.me/<?= e($c['wa']) ?>" target="_blank" rel="noopener"><?= store_icon('wa') ?>WhatsApp</a><?php endif; ?>
          </div>
        </div>
        <div class="pic"><?php if ($s['img']): ?><img src="<?= e($s['img']) ?>" alt="" <?= $i ? 'loading="lazy"' : 'fetchpriority="high"' ?>><?php endif; ?></div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php if (count($slides) > 1): ?><div class="dots"><?php foreach ($slides as $i => $s): ?><button type="button" class="<?= $i ? '' : 'on' ?>" aria-label="Slide <?= $i + 1 ?>"></button><?php endforeach; ?></div><?php endif; ?>
  </section>

  <?php if ($ownShop): ?>
  <section class="sec" style="margin-top:12px"><div class="trust4">
    <div><?= store_icon('truck') ?>Fast delivery<br>in <?= e($city) ?></div>
    <div><?= store_icon('shield') ?>100% genuine<br>products</div>
    <div><?= store_icon('tool') ?>Expert support<br>&amp; installation</div>
    <div><?= store_icon('check') ?>Warranty<br>&amp; service</div>
  </div></section>
  <?php endif; ?>

  <?php if ($tiles): ?>
  <section class="sec">
    <div class="sec-h"><h2>Shop by Category</h2><a class="more" href="catalog.php?view=cats">View all<?= store_icon('next') ?></a></div>
    <div class="tiles"><?php foreach (array_slice($tiles, 0, 8) as $t) $tileHtml($t); ?></div>
  </section>
  <?php endif; ?>


  <?php $dId = (int)setting('store_deal_item'); $dE = trim(setting('store_deal_ends', ''));
  $deal = $dId && ($dE === '' || strtotime($dE) > time()) ? (array_values(array_filter($items, fn($x) => (int)$x['id'] === $dId))[0] ?? null) : null;
  if ($deal): ?>
  <section class="sec"><div class="sec-h"><h2><?= store_icon('tag') ?>Deal of the Day</h2></div><div class="row" style="grid-auto-columns:min(100%,360px)"><?php store_card($deal, 'list', 'DEAL'); ?></div></section>
  <?php endif; ?>

  <?php $rowHtml = function ($icon, $t, $sub, $list, $more, $tag = '') { ?>
  <section class="sec">
    <div class="sec-h"><h2><?= store_icon($icon) ?><?= e($t) ?></h2><?php if ($more): ?><a class="more" href="<?= e($more) ?>">View all<?= store_icon('next') ?></a><?php endif; ?></div>
    <?php if ($sub): ?><p class="sec-sub"><?= e($sub) ?></p><?php endif; ?>
    <div class="row"><?php foreach ($list as $it) store_card($it, 'grid', $tag === 'NEW' && strtotime((string)$it['created_at']) > time() - 45 * 86400 ? 'NEW' : ((int)($it['web_priority'] ?? 0) ? 'TOP' : '')); ?></div>
  </section>
  <?php };
  foreach ($rows as $i => $r) {
      $rowHtml(...$r);
      if ($i === 0 && ($topBrands = array_slice(array_filter(seo_brands(), fn($b) => seo_slug($b['name']) !== ''), 0, 12))) { ?>
  <section class="sec"><div class="sec-h"><h2>Top Brands</h2></div>
    <div class="brands"><?php foreach ($topBrands as $b): ?><a href="<?= e(seo_brand_url($b['name'])) ?>"><?= e($b['name']) ?></a><?php endforeach; ?></div></section>
  <?php }
  } ?>

  <?php if ($ownShop && $cctv): ?>
  <section class="sec">
    <div class="band">
      <div><h2>Complete <em>CCTV Solutions</em></h2><p>HD &amp; IP cameras, 4G / WiFi cameras, DVR / NVR, hard disks, PoE switches, cable and power — planned, installed and serviced by us.</p></div>
      <div class="cta">
        <a class="btn-w" href="<?= e(seo_service_url('cctv-installation')) ?>">Request free site visit</a>
        <?php if ($c['wa']): ?><a class="btn-g" href="https://wa.me/<?= e($c['wa']) ?>?text=<?= rawurlencode('Hello! I need a CCTV installation quote for my place.') ?>" target="_blank" rel="noopener"><?= store_icon('wa') ?>Get CCTV quote</a><?php endif; ?>
      </div>
    </div>
  </section>
  <?php $rowHtml('camera', 'CCTV Installation Essentials', 'Cameras, recorders, cable and power — everything for one job', $cctv, $secGroup ? store_group_url($secGroup) : ''); endif; ?>

  <?php if ($newest) $rowHtml('spark', 'New Arrivals', '', $newest, 'catalog.php?view=all&sort=new', 'NEW'); ?>
  <?php if (count($up) >= 3) $rowHtml('chip', 'Computer Upgrade Products', 'SSD, RAM, power supply and more — fitted at the shop', $up, 'catalog.php?view=all&q=ssd'); ?>
  <?php if (count($net) >= 3) $rowHtml('wifi', 'Networking Essentials', '', $net, 'catalog.php?view=all&q=router'); ?>
  <?php if (count($print) >= 3) $rowHtml('printer', 'Printer & Consumables', '', $print, 'catalog.php?view=all&q=printer'); ?>

  <section class="sec" data-hide-empty hidden><div class="sec-h"><h2><?= store_icon('clock') ?>Recently Viewed</h2></div><div class="row" data-recent></div></section>

  <?php if ($ownShop): ?>
  <section class="sec">
    <div class="sec-h"><h2><?= store_icon('tool') ?>Services</h2><a class="more" href="<?= e(base_url('s/')) ?>">All services<?= store_icon('next') ?></a></div>
    <div class="svc">
      <?php foreach (seo_services() as $slug => $sv): ?><a href="<?= e(seo_service_url($slug)) ?>"><span class="si"><?= store_icon(store_cat_icon($slug . ' ' . $sv['name']) === 'box' ? 'tool' : store_cat_icon($slug . ' ' . $sv['name'])) ?></span><b><?= e($sv['name']) ?></b><small><?= e(mb_substr(strtok($sv['desc'], '.'), 0, 70)) ?></small></a><?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <?php if ($reviews): ?>
  <section class="sec">
    <div class="sec-h"><h2><?= store_icon('star') ?>Customer Reviews</h2></div>
    <div class="row" style="grid-auto-columns:min(86%,340px)">
      <?php foreach ($reviews as $rv): ?>
      <div class="rv"><div class="stars" aria-label="<?= (int)$rv['rating'] ?> out of 5"><?= str_repeat('★', (int)$rv['rating']) . str_repeat('☆', 5 - (int)$rv['rating']) ?></div>
        <?php if ($rv['comment']): ?><p style="font-size:14px;margin-top:6px">“<?= e(mb_substr($rv['comment'], 0, 220)) ?>”</p><?php endif; ?>
        <p class="muted" style="font-size:12.5px;margin-top:8px"><b><?= e($rv['customer_name'] ?: 'Customer') ?></b> · <?= dmy($rv['created_at']) ?><?php if ($rv['is_verified']): ?> · <span class="vbadge">Verified purchase</span><?php endif; ?></p>
        <a class="muted" style="font-size:12px;display:block;margin-top:4px;text-decoration:underline" href="<?= e(seo_product_url(['id' => $rv['item_id'], 'name' => $rv['item_name'], 'brand' => $rv['brand'], 'model' => $rv['model']])) ?>"><?= e($rv['item_name']) ?></a></div>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <?php if ($faqs): ?>
  <section class="sec faq">
    <div class="sec-h"><h2>Questions customers ask</h2></div>
    <?php foreach ($faqs as $fq): ?><details><summary><?= e($fq[0]) ?></summary><p><?= e($fq[1]) ?></p></details><?php endforeach; ?>
  </section>
  <?php endif; ?>

  <?php if ($c['wa']): ?>
  <section class="sec"><div class="band"><div><h2>Can't find it? <em>Ask us.</em></h2><p>Send a photo or model number on WhatsApp — we tell you the price and when we can deliver.</p></div>
    <div class="cta"><a class="btn-w" href="https://wa.me/<?= e($c['wa']) ?>?text=<?= rawurlencode('Hello! I am looking for:') ?>" target="_blank" rel="noopener"><?= store_icon('wa') ?>Chat on WhatsApp</a></div></div></section>
  <?php endif; ?>

  <?php if ($c['admin']): $hidden = (int)val('SELECT COUNT(*) FROM items WHERE is_active = 1 AND show_on_website = 0'); $noPhoto = count(array_filter($items, fn($x) => trim((string)$x['photo']) === '')); ?>
  <section class="sec"><div class="note info">Admin: <?= $noPhoto ?> product(s) on the website have no photo (they show lower down) — <a style="text-decoration:underline" href="items.php?f_web=on&amp;f_photo=none">add photos</a>.
    <?php if ($hidden): ?> <?= $hidden ?> active item(s) are switched OFF for the website.<?php endif; ?> Tap 📌 on a card to show it first.</div></section>
  <?php endif; ?>
<?php endif; ?>
</main>
<?php store_footer();
// the search index is fetched only when the search box / wishlist needs it, keeping the page light
store_end($view === 'cats' ? 'cats' : ($view === 'wish' ? 'wish' : 'home'));
