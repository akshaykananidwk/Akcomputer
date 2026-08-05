<?php
// Public per-product page - one indexable URL per item for SEO (title, meta
// description, OG tags, Product JSON-LD with price), dealer-aware pricing
// (a logged-in dealer sees their own price directly, never the %), related
// products, and Add-to-Cart that hands over to the catalog's cart.
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/seo.php';

$app_name = setting('app_name', 'AK Computer');
$waShop = wa_normalize_number(setting('wa_shop_number'));
if (strlen($waShop) < 12) $waShop = '';

$it = row('SELECT i.*, c.name cat_name FROM items i LEFT JOIN categories c ON c.id = i.category_id
           WHERE i.id = ? AND i.is_active = 1 AND i.show_on_website = 1', [(int)get('id')]);
if (!$it) { header('HTTP/1.1 404 Not Found'); die('<meta charset="utf-8"><p style="font-family:sans-serif;text-align:center;margin-top:60px">Product not found. <a href="catalog.php">← Store</a></p>'); }

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
    elseif ($rvName === '' || (int)post('rating') < 1) $err = 'નામ અને રેટિંગ (સ્ટાર) બંને જરૂરી છે.';
    else {
        try {
            if ($rvMob !== '' && val('SELECT id FROM product_reviews WHERE item_id = ? AND mobile = ?', [$it['id'], $rvMob])) {
                $err = 'આ નંબર પરથી આ પ્રોડક્ટનો રિવ્યૂ પહેલેથી અપાયેલો છે. આભાર!';
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
                if (!$ver) { try { tg_notify_admins("⭐ નવો પ્રોડક્ટ રિવ્યૂ (મંજૂરી બાકી)\n{$it['name']} — $rvRate★ — $rvName\n" . mb_substr($rvTxt, 0, 200) . "\n\n" . base_url('reviews.php')); } catch (Exception $e) {} }
                flash($ver ? '✅ આભાર! તમારો રિવ્યૂ મુકાઈ ગયો (Verified purchase).' : '✅ આભાર! તમારો રિવ્યૂ મળ્યો — ચકાસીને થોડી વારમાં દેખાશે.');
            }
        } catch (Exception $e) { $err = 'હમણાં રિવ્યૂ સેવ ન થયો — થોડી વારે ફરી પ્રયત્ન કરો.'; }
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
$desc = $it['description'] ?: ($label . ' available at ' . $app_name . ', Dwarka Gujarat. Best price, genuine product, warranty & service. કિંમત ₹' . money($dp) . ' - WhatsApp પર ઓર્ડર કરો.');
$inStock = $it['item_type'] === 'service' || (float)val('SELECT COALESCE(SUM(qty),0) FROM stock WHERE item_id = ?', [$it['id']]) > 0;
$related = all('SELECT i.* FROM items i WHERE i.is_active = 1 AND i.show_on_website = 1 AND i.id <> ? AND i.category_id <=> ? ORDER BY RAND() LIMIT 4',
               [$it['id'], $it['category_id']]);
$purl = seo_product_url($it);
?><!DOCTYPE html>
<html lang="gu">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($label) ?> — ₹<?= money($dp) ?> | <?= e($app_name) ?> Dwarka</title>
<meta name="description" content="<?= e(mb_substr($desc, 0, 300)) ?>">
<meta name="keywords" content="<?= e($it['name']) ?>, <?= e($it['brand']) ?>, <?= e($it['cat_name']) ?>, dwarka, gujarat, price, <?= e($app_name) ?>">
<link rel="canonical" href="<?= e($purl) ?>">
<meta property="og:type" content="product">
<meta property="og:site_name" content="<?= e($app_name) ?>">
<meta name="twitter:card" content="summary_large_image">
<meta property="og:title" content="<?= e($label) ?> — ₹<?= money($dp) ?>">
<meta property="og:description" content="<?= e(mb_substr($desc, 0, 200)) ?>">
<meta property="og:url" content="<?= e($purl) ?>">
<?php if ($it['photo']): ?><meta property="og:image" content="<?= e(base_url($it['photo'])) ?>"><?php endif; ?>
<!-- the page is served under /p/{id}/{slug}, so every URL must be absolute -->
<link rel="icon" href="<?= e(base_url('assets/icon.svg')) ?>" type="image/svg+xml">
<link rel="stylesheet" href="<?= e(base_url('assets/style.css')) ?>?v=<?= asset_v('style.css') ?>">
<script type="application/ld+json">
<?php
// aggregateRating/review come ONLY from real approved customer reviews -
// products without reviews simply omit the fields (Google treats that as a
// non-critical note; it clears per-page as genuine reviews come in)
$ld = [
    '@context' => 'https://schema.org', '@type' => 'Product',
    'name' => $it['name'],
    'description' => mb_substr($desc, 0, 400),
    'image' => $it['photo'] ? [base_url($it['photo'])] : [],
    'brand' => $it['brand'] ? ['@type' => 'Brand', 'name' => $it['brand']] : null,
    'category' => $it['cat_name'],
    'offers' => [
        '@type' => 'Offer', 'url' => $purl, 'priceCurrency' => 'INR',
        'price' => (string)$dp,
        'availability' => $inStock ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
        'seller' => ['@type' => 'Organization', 'name' => $app_name],
    ],
];
if ($rvN > 0) {
    $ld['aggregateRating'] = ['@type' => 'AggregateRating', 'ratingValue' => (string)$rvAvg, 'reviewCount' => $rvN, 'bestRating' => '5', 'worstRating' => '1'];
    $ld['review'] = array_map(fn($r) => [
        '@type' => 'Review',
        'author' => ['@type' => 'Person', 'name' => $r['customer_name'] ?: 'Customer'],
        'datePublished' => date('Y-m-d', strtotime($r['created_at'])),
        'reviewRating' => ['@type' => 'Rating', 'ratingValue' => (string)(int)$r['rating'], 'bestRating' => '5', 'worstRating' => '1'],
        'reviewBody' => $r['comment'],
    ], array_slice($revs, 0, 5));
}
echo json_encode($ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
?>
</script>
<?= seo_breadcrumbs([[$app_name, base_url('catalog.php')],
                     $it['cat_name'] ? [$it['cat_name'], seo_cat_url($it['cat_name'])] : [$app_name . ' Store', base_url('catalog.php')],
                     [$it['name']]]) ?>
<?= seo_public_css() ?>
<style>
:root { --acc1: #4f46e5; --acc2: #06b6d4; }
body { background: var(--bg); }
.shead { position: sticky; top: 0; z-index: 50; background: linear-gradient(100deg, var(--acc1), #2563eb 60%, var(--acc2)); color: #fff;
  padding: 10px 14px; display: flex; align-items: center; gap: 10px; box-shadow: 0 2px 12px rgba(37,99,235,.35); }
.shead .logo { font-size: 18px; font-weight: 800; color: #fff; text-decoration: none; }
.shead .back { margin-left: auto; background: rgba(255,255,255,.18); border: 1px solid rgba(255,255,255,.45); color: #fff;
  border-radius: 10px; padding: 8px 12px; font-size: 13px; font-weight: 700; text-decoration: none; }
.pwrap { max-width: 980px; margin: 16px auto; padding: 0 12px; display: grid; grid-template-columns: 1fr; gap: 16px; }
@media (min-width: 800px) { .pwrap { grid-template-columns: 1fr 1fr; align-items: start; } }
.pimg { background: #fff; border-radius: 16px; box-shadow: 0 2px 10px rgba(0,0,0,.1); display: flex; align-items: center; justify-content: center; min-height: 300px; }
.pimg img { max-width: 100%; max-height: 420px; object-fit: contain; border-radius: 16px; padding: 12px; }
.pimg .ph { font-size: 90px; }
.pinfo { background: var(--card); color: var(--text); border-radius: 16px; padding: 20px 18px; box-shadow: 0 2px 10px rgba(0,0,0,.1); }
.pinfo .ptag { color: var(--acc1); font-weight: 700; font-size: 12.5px; text-transform: uppercase; letter-spacing: .4px; }
.pinfo h1 { font-size: 22px; margin-top: 4px; }
.pinfo .pbrand { color: var(--muted); margin-top: 4px; }
.pinfo .pprice { font-size: 30px; font-weight: 800; color: var(--primary); margin-top: 12px; }
.pinfo .pstock { margin-top: 6px; font-weight: 700; font-size: 13.5px; }
.pinfo .pdesc { margin-top: 12px; color: var(--text); line-height: 1.55; font-size: 14.5px; }
.pinfo .facts { margin-top: 14px; display: grid; grid-template-columns: 1fr 1fr; gap: 8px; font-size: 13px; }
.pinfo .facts div { background: var(--bg); border-radius: 10px; padding: 8px 10px; }
.pbtns { margin-top: 18px; display: flex; flex-direction: column; gap: 9px; }
.rel { max-width: 980px; margin: 8px auto 30px; padding: 0 12px; }
.rel h2 { font-size: 17px; margin-bottom: 10px; color: var(--text); }
.rel .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 12px; }
.rel .rcard { background: var(--card); color: var(--text); border-radius: 12px; overflow: hidden; box-shadow: 0 1px 4px rgba(0,0,0,.08); text-decoration: none; display: block; transition: transform .15s; }
.rel .rcard:hover { transform: translateY(-3px); }
.rel .rcard img { width: 100%; height: 110px; object-fit: contain; background: #fff; padding: 4px; }
.rel .rcard .ph { height: 110px; display: flex; align-items: center; justify-content: center; font-size: 36px; background: var(--bg); }
.rel .rcard .rb { padding: 8px 10px; }
.rel .rcard .rn { font-size: 13px; font-weight: 600; }
.rel .rcard .rp { color: var(--primary); font-weight: 800; margin-top: 3px; }
</style>
</head>
<body>
<header class="shead">
  <a class="logo" href="<?= e(base_url('catalog.php')) ?>">🖥️ <?= e($app_name) ?></a>
  <a class="back" href="<?= e(base_url('catalog.php')) ?>">← Store</a>
</header>

<div class="pwrap">
  <div class="pimg">
    <?php if ($it['photo']): ?><img src="<?= e(base_url($it['photo'])) ?>" alt="<?= e($label) ?>">
    <?php else: ?><div class="ph"><?= e(cat_icon($it['cat_name'] ?? '')) ?></div><?php endif; ?>
  </div>
  <div class="pinfo">
    <?php if ($it['cat_name']): ?><div class="ptag"><?= e(cat_icon($it['cat_name'])) ?> <?= e($it['cat_name']) ?></div><?php endif; ?>
    <h1><?= e($it['name']) ?></h1>
    <?php if ($it['brand'] || $it['model']): ?><div class="pbrand"><?= e(trim($it['brand'] . ' ' . $it['model'])) ?></div><?php endif; ?>
    <div class="pprice">₹<?= money($dp) ?><?php if ($webAcct): ?> <span style="font-size:13px;color:#059669;font-weight:700">👷 તમારો ભાવ</span><?php endif; ?></div>
    <div class="pstock" style="color:<?= $inStock ? '#059669' : '#dc2626' ?>"><?= $inStock ? '✅ In stock — આજે જ મળે' : '📦 Order પર મળશે — WhatsApp કરો' ?></div>
    <?php if ($rvN > 0): ?>
    <a href="#reviews" style="display:inline-block;margin-top:6px;text-decoration:none;color:var(--text);font-weight:700;font-size:14px">
      <span style="color:#f59e0b">★</span> <?= $rvAvg ?> <span class="muted" style="font-weight:500">(<?= $rvN ?> review<?= $rvN > 1 ? 's' : '' ?>)</span></a>
    <?php endif; ?>
    <?php if ($it['description']): ?><p class="pdesc"><?= nl2br(e($it['description'])) ?></p><?php endif; ?>
    <div class="facts">
      <?php if ($it['warranty_months'] > 0): ?><div>🛡️ Warranty: <?= (int)$it['warranty_months'] ?> months</div><?php endif; ?>
      <div>🏪 <?= e($app_name) ?>, Dwarka</div>
      <div>🛠️ Installation &amp; service available</div>
      <div>✅ Genuine product</div>
    </div>
    <div class="chips" style="margin-top:12px">
      <a href="<?= e(seo_price_url($it)) ?>">💰 <?= e($it['name']) ?> Price</a>
      <?php if ($it['cat_name'] && seo_slug($it['cat_name']) !== ''): ?><a href="<?= e(seo_cat_url($it['cat_name'])) ?>"><?= e(cat_icon($it['cat_name'])) ?> બધા <?= e($it['cat_name']) ?></a><?php endif; ?>
      <?php if ($it['brand'] && seo_slug($it['brand']) !== ''): ?><a href="<?= e(seo_brand_url($it['brand'])) ?>">🏷️ <?= e($it['brand']) ?> Products</a><?php endif; ?>
    </div>
    <div class="pbtns">
      <a class="btn btn-block" href="<?= e(base_url('catalog.php?add=' . $it['id'])) ?>" style="background:linear-gradient(100deg,#4f46e5,#2563eb);border:0">🛒 Add to Cart</a>
      <?php if ($waShop): ?>
      <a class="btn btn-success btn-block" href="https://wa.me/<?= e($waShop) ?>?text=<?= rawurlencode("નમસ્તે! મને આ પ્રોડક્ટ જોઈએ છે:\n" . $label . " - ₹" . money($dp) . "\n" . $purl) ?>" target="_blank" rel="noopener">📲 WhatsApp પર ઓર્ડર કરો</a>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="rel" id="reviews">
  <h2>⭐ Reviews<?= $rvN ? " — $rvAvg★ ($rvN)" : '' ?></h2>
  <?php foreach (get_flashes() as $f): ?>
  <div style="background:<?= $f['type'] === 'error' ? '#fee2e2;color:#991b1b' : '#dcfce7;color:#166534' ?>;border-radius:10px;padding:10px 14px;margin-bottom:10px;font-size:14px"><?= e($f['msg']) ?></div>
  <?php endforeach; ?>
  <?php foreach ($revs as $r): ?>
  <div style="background:var(--card);border-radius:12px;padding:12px 14px;margin-bottom:8px;box-shadow:0 1px 4px rgba(0,0,0,.08)">
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
      <strong style="font-size:14px"><?= e($r['customer_name'] ?: 'Customer') ?></strong>
      <?php if ($r['is_verified']): ?><span style="background:#dcfce7;color:#166534;border-radius:99px;padding:1px 8px;font-size:11.5px;font-weight:700">✓ Verified purchase</span><?php endif; ?>
      <span class="muted" style="font-size:12px;margin-left:auto"><?= dmy($r['created_at']) ?></span>
    </div>
    <div style="color:#f59e0b;font-size:15px;margin-top:2px"><?= str_repeat('★', (int)$r['rating']) . str_repeat('☆', 5 - (int)$r['rating']) ?></div>
    <?php if ($r['comment']): ?><p style="margin-top:4px;font-size:14px;line-height:1.5"><?= nl2br(e($r['comment'])) ?></p><?php endif; ?>
  </div>
  <?php endforeach; ?>
  <?php if (!$revs): ?><p class="muted" style="margin-bottom:10px">હજી કોઈ રિવ્યૂ નથી — પહેલા બનો!</p><?php endif; ?>
  <details style="background:var(--card);border-radius:12px;padding:12px 14px;box-shadow:0 1px 4px rgba(0,0,0,.08)">
    <summary style="cursor:pointer;font-weight:700;font-size:14.5px">✍️ તમારો રિવ્યૂ લખો / Write a review</summary>
    <form method="post" action="<?= e(base_url('product.php?id=' . $it['id'])) ?>" style="margin-top:10px;display:grid;gap:10px">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="review">
      <input type="text" name="website" value="" style="display:none" tabindex="-1" autocomplete="off">
      <div>
        <div id="rvStars" style="font-size:34px;letter-spacing:5px;cursor:pointer;color:#f59e0b">☆☆☆☆☆</div>
        <input type="hidden" name="rating" id="rvRating" value="0">
      </div>
      <input type="text" name="name" placeholder="તમારું નામ *" required maxlength="120" style="padding:10px;border-radius:10px;border:1px solid var(--border,#ddd);background:var(--bg);color:var(--text)">
      <input type="tel" name="mobile" placeholder="મોબાઈલ (ખરીદી હોય એ નંબર = ✓ Verified બેજ)" maxlength="15" style="padding:10px;border-radius:10px;border:1px solid var(--border,#ddd);background:var(--bg);color:var(--text)">
      <textarea name="comment" rows="3" maxlength="1000" placeholder="પ્રોડક્ટ કેવી લાગી?" style="padding:10px;border-radius:10px;border:1px solid var(--border,#ddd);background:var(--bg);color:var(--text)"></textarea>
      <button class="btn" type="submit" style="background:linear-gradient(100deg,#4f46e5,#2563eb);border:0">રિવ્યૂ મોકલો</button>
    </form>
    <script>
      (function () {
        var s = document.getElementById('rvStars');
        s.addEventListener('click', function (e) {
          var rect = s.getBoundingClientRect();
          var n = Math.min(5, Math.max(1, Math.ceil((e.clientX - rect.left) / (rect.width / 5))));
          document.getElementById('rvRating').value = n;
          s.textContent = '★★★★★'.slice(0, n) + '☆☆☆☆☆'.slice(n);
        });
      })();
    </script>
  </details>
</div>

<?php if ($related): ?>
<div class="rel">
  <h2>આવી બીજી પ્રોડક્ટ</h2>
  <div class="grid">
    <?php foreach ($related as $r): $rp = dealer_price($r['selling_price'], $waPct); ?>
    <a class="rcard" href="<?= e(seo_product_url($r)) ?>">
      <?php if ($r['photo']): ?><img src="<?= e(base_url($r['photo'])) ?>" alt="<?= e($r['name']) ?>" loading="lazy">
      <?php else: ?><div class="ph"><?= e(cat_icon($it['cat_name'] ?? '')) ?></div><?php endif; ?>
      <div class="rb"><div class="rn"><?= e($r['name']) ?></div><div class="rp">₹<?= money($rp) ?></div></div>
    </a>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>
<?= seo_footer() ?>
</body>
</html>
