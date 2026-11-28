<?php
// Public per-product page - one indexable URL per item for SEO (title, meta
// description, OG tags, Product JSON-LD with price, stock, brand, model, SKU), dealer-aware pricing
// (a logged-in dealer sees their own price directly, never the %), related
// products, and Add-to-Cart that hands over to the catalog's cart.
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/seo.php';

$app_name = setting('app_name', 'AK Computer');
$waShop = wa_normalize_number(setting('wa_shop_number'));
if (strlen($waShop) < 12) $waShop = '';

$it = row('SELECT i.*, c.name cat_name FROM items i LEFT JOIN categories c ON c.id = i.category_id
           WHERE i.id = ? AND i.is_active = 1 AND i.show_on_website = 1', [(int)get('id')]);
if (!$it) {
    // a product that is off the website: a real 404 page, with the way back
    require_once __DIR__ . '/includes/store_ui.php';
    http_response_code(404);
    store_head('Product not available | ' . setting('app_name', 'AK Computer'), 'This product is not on the website right now.', '', '', 'noindex');
    store_header('back', 'Not found');
    echo '<main class="sec" style="max-width:520px"><div class="panel empty">' . store_icon('search') . '<h3>This product is not available</h3><p>It may be sold out or no longer listed. Search for something similar, or ask us.</p><a class="btn" href="' . e(base_url('catalog.php?view=all')) . '">Browse products</a></div></main>';
    store_footer(); store_end('home'); exit;
}

// ---------- customer review submit ----------
// Real reviews only: a review whose mobile matches an actual sale of THIS
// item is auto-approved ("Verified purchase"); everything else waits for
// the admin (Online Store > Product Reviews). These reviews are the ONLY
// source of the aggregateRating/review structured data below - nothing is
// ever invented.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'review') {
    $rvName = mb_substr(trim(post('name')), 0, 120);
    $rvMob = preg_replace('/\D/', '', post('mobile'));
    $rvRate = max(1, min(5, (int)post('rating')));
    $rvTxt = mb_substr(trim(post('comment')), 0, 1000);
    $err = '';
    if (post('website') !== '') $err = 'spam'; // honeypot - bots fill every field
    elseif ($rvName === '' || (int)post('rating') < 1) $err = 'Both a name and a star rating are required.';
    else {
        try {
            if ($rvMob !== '' && val('SELECT id FROM product_reviews WHERE item_id = ? AND mobile = ?', [$it['id'], $rvMob])) {
                $err = 'A review for this product has already been given from this number. Thank you!';
            } else {
                $ver = 0;
                if (strlen($rvMob) >= 10) {
                    $ten = '%' . substr($rvMob, -10);
                    $ver = (int)(bool)val("SELECT s.id FROM sales s JOIN sale_items si ON si.sale_id = s.id
                                           WHERE si.item_id = ? AND s.is_cancelled = 0
                                           AND REPLACE(REPLACE(s.customer_mobile, '+', ''), ' ', '') LIKE ? LIMIT 1", [$it['id'], $ten]);
                }
                q("INSERT INTO product_reviews (item_id, customer_name, mobile, rating, comment, is_verified, status) VALUES (?,?,?,?,?,?,?)",
                  [$it['id'], $rvName, $rvMob, $rvRate, $rvTxt, $ver, $ver ? 'approved' : 'pending']);
                if (!$ver) { try { tg_notify_admins("⭐ New product review (awaiting approval)\n{$it['name']} — $rvRate★ — $rvName\n" . mb_substr($rvTxt, 0, 200) . "\n\n" . base_url('reviews.php')); } catch (Exception $e) {} }
                flash($ver ? '✅ Thank you! Your review is published (verified purchase).' : '✅ Thank you! We have your review — it appears shortly after checking.');
            }
        } catch (Exception $e) { $err = 'The review could not be saved just now — please try again shortly.'; }
    }
    if ($err !== '' && $err !== 'spam') flash($err, 'error');
    redirect(seo_product_url($it) . '#reviews');
}

// approved reviews: full stats for schema + latest few for display
$rvStats = ['n' => 0, 'avg' => 0];
$revs = [];
try {
    $rvStats = row("SELECT COUNT(*) n, COALESCE(AVG(rating),0) avg FROM product_reviews WHERE item_id = ? AND status = 'approved'", [$it['id']]) ?: $rvStats;
    if ((int)$rvStats['n'] > 0) $revs = all("SELECT * FROM product_reviews WHERE item_id = ? AND status = 'approved' ORDER BY is_verified DESC, id DESC LIMIT 10", [$it['id']]);
} catch (Exception $e) { /* pre-v52 */ }
$rvN = (int)$rvStats['n'];
$rvAvg = round((float)$rvStats['avg'], 1);

site_visit_track('product');

$webAcct = null;
if (!empty($_SESSION['web_account_id'])) {
    $webAcct = row('SELECT * FROM web_accounts WHERE id = ? AND is_active = 1', [(int)$_SESSION['web_account_id']]);
}
$waPct = $webAcct ? (float)$webAcct['discount_pct'] : 0;
$dp = dealer_price($it['selling_price'], $waPct);
$label = trim($it['name'] . ($it['brand'] ? ' - ' . trim($it['brand'] . ' ' . $it['model']) : ''));
$desc = $it['description'] ?: ($label . ' available at ' . $app_name . ', ' . shop_place() . '. Best price, genuine product, warranty & service. Price Rs ' . money($dp) . ' - order on WhatsApp.');
$purl = seo_product_url($it);

// ---------- the new store look (includes/store_ui.php) ----------
require_once __DIR__ . '/includes/store_ui.php';
$c = store_ctx();
// similar = same category, in the store's smart order (not random)
$related = array_slice(store_sort(array_values(array_filter(store_items(), fn($x) => (int)$x['id'] !== (int)$it['id'] && (int)$x['category_id'] === (int)$it['category_id']))), 0, 10);
// bought together = other products on the same bills in the last year (real bills only)
$together = [];
try {
    $tIds = array_map('intval', array_column(all("SELECT si2.item_id, COUNT(*) n FROM sale_items si JOIN sale_items si2 ON si2.sale_id = si.sale_id AND si2.item_id <> si.item_id
                                                  JOIN sales s ON s.id = si.sale_id WHERE si.item_id = ? AND s.is_cancelled = 0 AND s.sale_date >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)
                                                  GROUP BY si2.item_id ORDER BY n DESC LIMIT 20", [$it['id']]), 'item_id'));
    $byId = []; foreach (store_items() as $x) $byId[(int)$x['id']] = $x;
    foreach ($tIds as $tid) if (isset($byId[$tid]) && store_promotable($byId[$tid])) $together[] = $byId[$tid];
    $together = array_slice($together, 0, 8);
} catch (Exception $e) {}
[$sk, $st, $stNote] = store_stock($it);
[$mrp, $off] = store_mrp($it, $dp);
$specs = store_specs($it);
$city = shop_city() ?: 'Dwarka';
$grp = store_group($it);
$cond = preg_match('/refurbish/i', $it['name']) ? 'RefurbishedCondition' : (preg_match('/\(old\)|\bused\b|second ?hand/i', $it['name']) ? 'UsedCondition' : 'NewCondition');
$seoTitle = $it['name'] . ' | ' . $c['app'] . ' ' . $city;
$metaDesc = 'Buy ' . $label . ' from ' . $c['app'] . ', ' . shop_place() . '.'
    . ($specs ? ' ' . implode(', ', array_column($specs, 1)) . '.' : '')
    . ' Price ₹' . money($dp) . ($sk === 'out' ? ', available on order' : ', in stock') . ((int)$it['warranty_months'] > 0 ? ', ' . (int)$it['warranty_months'] . ' months warranty' : '')
    . '. ' . ($c['own'] ? 'Installation support and ' : '') . 'WhatsApp ordering.';
if ($it['description']) $metaDesc = mb_substr(trim(preg_replace('/\s+/', ' ', $it['description'])), 0, 160) . ' — ₹' . money($dp) . ' at ' . $c['app'] . ', ' . $city . '.';

// structured data: real values only - price, stock, brand, model, SKU; ratings only from approved reviews
$ld = ['@context' => 'https://schema.org', '@type' => 'Product', 'name' => $it['name'], 'description' => mb_substr($desc, 0, 400),
       'image' => $it['photo'] ? [base_url($it['photo'])] : [], 'sku' => (string)($it['barcode'] ?: 'AKC-' . $it['id']), 'category' => $it['cat_name'],
       'offers' => ['@type' => 'Offer', 'url' => $purl, 'priceCurrency' => 'INR', 'price' => number_format($dp, 2, '.', ''),
                    'availability' => $sk === 'out' ? 'https://schema.org/BackOrder' : 'https://schema.org/InStock', 'itemCondition' => 'https://schema.org/' . $cond,
                    'seller' => ['@type' => 'Organization', 'name' => $c['app']]]];
if ($it['brand']) $ld['brand'] = ['@type' => 'Brand', 'name' => $it['brand']];
if ($it['model']) $ld['mpn'] = $it['model'];
if ((int)$it['warranty_months'] > 0) $ld['offers']['warranty'] = ['@type' => 'WarrantyPromise', 'durationOfWarranty' => ['@type' => 'QuantitativeValue', 'value' => (int)$it['warranty_months'], 'unitCode' => 'MON']];
if ($rvN > 0) {
    $ld['aggregateRating'] = ['@type' => 'AggregateRating', 'ratingValue' => (string)$rvAvg, 'reviewCount' => $rvN, 'bestRating' => '5', 'worstRating' => '1'];
    $ld['review'] = array_map(fn($r) => ['@type' => 'Review', 'author' => ['@type' => 'Person', 'name' => $r['customer_name'] ?: 'Customer'],
        'datePublished' => date('Y-m-d', strtotime($r['created_at'])), 'reviewRating' => ['@type' => 'Rating', 'ratingValue' => (string)(int)$r['rating'], 'bestRating' => '5', 'worstRating' => '1'],
        'reviewBody' => $r['comment']], array_slice($revs, 0, 5));
}
$crumbs = [[$c['app'], base_url('') . '/']];
if ($grp !== '' && $grp !== $it['cat_name']) $crumbs[] = [$grp, store_group_url($grp)];
if ($it['cat_name']) $crumbs[] = [$it['cat_name'], seo_cat_url($it['cat_name'])];
$crumbs[] = [$it['name']];
$head = seo_jsonld($ld) . seo_breadcrumbs($crumbs) . ($it['photo'] ? '<meta property="og:image" content="' . e(base_url($it['photo'])) . '"><meta property="og:type" content="product">' : '');
store_head($seoTitle, $metaDesc, $purl, $head);
store_header('back', $it['cat_name'] ?: 'Product');
store_nav(store_groups(store_items()), $grp);
$waMsg = "Hello! I would like this product:\n" . $label . ($it['model'] ? ' (' . $it['model'] . ')' : '') . "\nPrice: ₹" . money($dp) . "\n" . $purl;
$specRows = array_filter([['Brand', $it['brand']], ['Model', $it['model']], ['SKU', $it['barcode']], ['Category', $it['cat_name']], ['Variant', $it['variant'] ?? ''],
    ['Warranty', (int)$it['warranty_months'] > 0 ? (int)$it['warranty_months'] . ' months' : ''], ['Unit', $it['unit']], ['Condition', ['NewCondition' => 'New', 'UsedCondition' => 'Used / old stock', 'RefurbishedCondition' => 'Refurbished'][$cond]]],
    fn($r) => trim((string)$r[1]) !== '');
?>
<main data-viewed="<?= (int)$it['id'] ?>">
<div class="pd" data-card data-id="<?= (int)$it['id'] ?>" data-n="<?= e($it['name']) ?>" data-p="<?= e($dp) ?>" data-f="<?= e($it['photo'] ? base_url($it['photo']) : '') ?>" data-m="<?= e($it['model']) ?>">
  <div class="gal">
    <div class="main" style="position:relative">
      <div><?php if ($it['photo']): ?><img src="<?= e(base_url($it['photo'])) ?>" alt="<?= e($label) ?>" fetchpriority="high" width="600" height="600"><?php else: ?><span class="noimg" style="width:70%;height:70%"><?= store_icon(store_cat_icon(($it['cat_name'] ?? '') . ' ' . $it['name'])) ?></span><?php endif; ?></div>
    </div>
  </div>
  <div style="display:grid;gap:12px;align-content:start">
    <nav class="crumb" aria-label="Breadcrumb"><a href="<?= e(base_url('') . '/') ?>">Home</a> › <?php if ($grp !== '' && $grp !== $it['cat_name']): ?><a href="<?= e(store_group_url($grp)) ?>"><?= e($grp) ?></a> › <?php endif; ?><?php if ($it['cat_name']): ?><a href="<?= e(seo_cat_url($it['cat_name'])) ?>"><?= e($it['cat_name']) ?></a><?php endif; ?></nav>
    <div>
      <?php if ($it['brand']): ?><a class="brl" href="<?= e(seo_brand_url($it['brand'])) ?>"><?= e($it['brand']) ?></a><?php endif; ?>
      <h1><?= e($it['name']) ?></h1>
      <?php if ($it['model']): ?><p class="muted" style="font-size:13px;margin-top:4px">Model: <?= e($it['model']) ?><?= $it['barcode'] ? ' · SKU: ' . e($it['barcode']) : '' ?></p><?php endif; ?>
      <?php if ($rvN > 0): ?><a href="#reviews" style="display:inline-flex;gap:6px;align-items:center;margin-top:6px;font-weight:700;font-size:14px"><span class="stars">★</span><?= $rvAvg ?> <span class="muted" style="font-weight:500">(<?= $rvN ?> review<?= $rvN > 1 ? 's' : '' ?>)</span></a><?php endif; ?>
    </div>
    <div style="display:flex;align-items:baseline;gap:10px;flex-wrap:wrap">
      <span class="bigp">₹<?= money($dp) ?></span>
      <?php if ($mrp): ?><s class="muted" style="font-size:16px">₹<?= money($mrp) ?></s><span class="tag" style="position:static">-<?= $off ?>%</span><?php endif; ?>
      <?php if ($webAcct): ?><span class="note ok" style="padding:3px 10px;font-weight:700">Your dealer price</span><?php endif; ?>
    </div>
    <div><span class="stkbox <?= $sk ?>"><?= e($st) ?></span><?php if ($stNote): ?> <span class="muted" style="font-size:13px"><?= e($stNote) ?> — we get it for you</span><?php endif; ?></div>
    <?php if ($specs): ?><div class="specs" style="gap:8px 14px"><?php foreach ($specs as $sp): ?><span style="font-size:13px"><?= store_icon($sp[0]) ?><?= e($sp[1]) ?></span><?php endforeach; ?></div><?php endif; ?>

    <div class="buybar" data-buybar>
      <span class="qsel"><button type="button" data-pq="-1" aria-label="One less">−</button><span data-pqn>1</span><button type="button" data-pq="1" aria-label="One more">+</button></span>
      <button class="btn" type="button" data-padd><?= store_icon('cart') ?>Add to Cart</button>
    </div>
    <div class="buybar">
      <button class="btn alt" type="button" data-buy><?= store_icon('bolt') ?>Buy Now</button>
      <?php if ($c['wa']): ?><a class="btn wa" href="https://wa.me/<?= e($c['wa']) ?>?text=<?= rawurlencode($waMsg) ?>" target="_blank" rel="noopener"><?= store_icon('wa') ?>WhatsApp</a><?php endif; ?>
    </div>
    <div class="links">
      <button type="button" data-wish="<?= (int)$it['id'] ?>" class="wl" style="position:static;width:auto;height:36px;border-radius:999px;padding:0 12px;box-shadow:none;border:1px solid var(--line);gap:6px;display:inline-flex"><?= store_icon('heart') ?>Wishlist</button>
      <button type="button" data-share="<?= e($purl) ?>"><?= store_icon('share') ?>Share</button>
      <?php if ($c['wa']): ?><a href="https://wa.me/<?= e($c['wa']) ?>?text=<?= rawurlencode("Hello! Please send a quotation for:\n" . $label . "\nQuantity: ") ?>" target="_blank" rel="noopener"><?= store_icon('receipt') ?>Get quote</a><?php endif; ?>
      <a href="<?= e(seo_price_url($it)) ?>"><?= store_icon('tag') ?>Price details</a>
    </div>

    <div class="facts">
      <div><?= store_icon('shield') ?><?= (int)$it['warranty_months'] > 0 ? (int)$it['warranty_months'] . ' months warranty' : 'Genuine product' ?></div>
      <div><?= store_icon('truck') ?>Delivery in <?= e($city) ?> or shop pickup</div>
      <?php if ($c['own']): ?><div><?= store_icon('tool') ?>Installation &amp; service</div><?php endif; ?>
      <div><?= store_icon('wa') ?>Order confirmed on WhatsApp</div>
    </div>

    <?php if ($it['description']): ?><div class="panel"><h2 style="font-size:16px;margin-bottom:6px">About this product</h2><p style="font-size:14.5px;line-height:1.6"><?= nl2br(e($it['description'])) ?></p></div><?php endif; ?>
    <div class="panel"><h2 style="font-size:16px;margin-bottom:4px">Specifications</h2>
      <table class="spec"><tbody><?php foreach ($specRows as [$k, $v]): ?><tr><th scope="row"><?= e($k) ?></th><td><?= e($v) ?></td></tr><?php endforeach; ?></tbody></table></div>
    <details class="panel faq" style="padding:0 16px"><summary>Delivery, returns &amp; warranty</summary>
      <p>We deliver in <?= e($city) ?> and nearby, or keep it ready for pickup at the shop. Every order is confirmed on WhatsApp or a call first.<?= (int)$it['warranty_months'] > 0 ? ' Warranty: ' . (int)$it['warranty_months'] . ' months as per the company.' : '' ?> For a return or a warranty claim, bring the product with its bill.</p></details>
  </div>
</div>

<?php if ($together): ?>
<section class="sec"><div class="sec-h"><h2><?= store_icon('bag') ?>Frequently bought together</h2></div><p class="sec-sub">From our own bills — customers who took this also took</p>
  <div class="row"><?php foreach ($together as $x) store_card($x); ?></div></section>
<?php endif; ?>
<?php if ($related): ?>
<section class="sec"><div class="sec-h"><h2>Similar products</h2><?php if ($it['cat_name']): ?><a class="more" href="<?= e(seo_cat_url($it['cat_name'])) ?>">View all<?= store_icon('next') ?></a><?php endif; ?></div>
  <div class="row"><?php foreach ($related as $x) store_card($x); ?></div></section>
<?php endif; ?>

<section class="sec" id="reviews" style="max-width:820px">
  <div class="sec-h"><h2><?= store_icon('star') ?>Reviews<?= $rvN ? " · $rvAvg★ ($rvN)" : '' ?></h2></div>
  <?php foreach (get_flashes() as $f): ?><p class="note <?= $f['type'] === 'error' ? 'bad' : 'ok' ?>" style="margin-bottom:10px"><?= e($f['msg']) ?></p><?php endforeach; ?>
  <div style="display:grid;gap:8px">
  <?php foreach ($revs as $r): ?>
    <div class="rv"><div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap"><b style="font-size:14px"><?= e($r['customer_name'] ?: 'Customer') ?></b>
      <?php if ($r['is_verified']): ?><span class="vbadge">✓ Verified purchase</span><?php endif; ?><span class="muted" style="font-size:12px;margin-left:auto"><?= dmy($r['created_at']) ?></span></div>
      <div class="stars"><?= str_repeat('★', (int)$r['rating']) . str_repeat('☆', 5 - (int)$r['rating']) ?></div>
      <?php if ($r['comment']): ?><p style="font-size:14px;margin-top:4px"><?= nl2br(e($r['comment'])) ?></p><?php endif; ?></div>
  <?php endforeach; ?>
  </div>
  <?php if (!$revs): ?><p class="muted" style="margin-bottom:10px">No reviews yet — bought it from us? Be the first.</p><?php endif; ?>
  <details class="panel" style="margin-top:10px"><summary style="cursor:pointer;font-weight:700">Write a review</summary>
    <form method="post" action="<?= e(base_url('product.php?id=' . $it['id'])) ?>" style="margin-top:12px">
      <?= csrf_field() ?><input type="hidden" name="do" value="review"><input type="text" name="website" value="" style="display:none" tabindex="-1" autocomplete="off">
      <div id="rvStars" role="radiogroup" aria-label="Rating" style="font-size:34px;letter-spacing:6px;cursor:pointer;color:#f5a300;margin-bottom:8px">☆☆☆☆☆</div>
      <input type="hidden" name="rating" id="rvRating" value="0">
      <div class="field"><label for="rv1">Your name *</label><input id="rv1" name="name" required maxlength="120"></div>
      <div class="field"><label for="rv2">Mobile (the number you bought with gets the ✓ Verified badge)</label><input id="rv2" type="tel" name="mobile" maxlength="15"></div>
      <div class="field"><label for="rv3">Your review</label><textarea id="rv3" name="comment" rows="3" maxlength="1000"></textarea></div>
      <button class="btn" type="submit">Send review</button>
    </form>
    <script>document.getElementById('rvStars').addEventListener('click',function(e){var r=this.getBoundingClientRect(),n=Math.min(5,Math.max(1,Math.ceil((e.clientX-r.left)/(r.width/5))));document.getElementById('rvRating').value=n;this.textContent='★★★★★'.slice(0,n)+'☆☆☆☆☆'.slice(n);});</script>
  </details>
</section>
<section class="sec" data-hide-empty hidden><div class="sec-h"><h2><?= store_icon('clock') ?>Recently viewed</h2></div><div class="row" data-recent></div></section>
</main>
<div class="sticky-buy" data-card data-id="<?= (int)$it['id'] ?>" data-n="<?= e($it['name']) ?>" data-p="<?= e($dp) ?>" data-f="<?= e($it['photo'] ? base_url($it['photo']) : '') ?>" data-m="<?= e($it['model']) ?>">
  <span class="price">₹<?= money($dp) ?></span>
  <button class="btn" type="button" data-padd><?= store_icon('cart') ?>Add</button>
  <button class="btn alt" type="button" data-buy>Buy Now</button>
</div>
<?php store_footer(); store_end('home');
