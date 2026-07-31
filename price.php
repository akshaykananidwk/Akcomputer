<?php
// Public "price" page - the second SEO page per product, targeting the huge
// "<product> price" / "<product> price in dwarka" search intent. Different
// content from product.php: big price box with today's date, a price-list
// table of the whole category, and an FAQ (with FAQPage markup).
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/seo.php';

$app_name = setting('app_name', 'AK Computer');
$waShop = wa_normalize_number(setting('wa_shop_number'));
if (strlen($waShop) < 12) $waShop = '';

$it = row('SELECT i.*, c.name cat_name FROM items i LEFT JOIN categories c ON c.id = i.category_id
           WHERE i.id = ? AND i.is_active = 1 AND i.show_on_website = 1', [(int)get('id')]);
if (!$it) { header('HTTP/1.1 404 Not Found'); die('<meta charset="utf-8"><p style="font-family:sans-serif;text-align:center;margin-top:60px">Product not found. <a href="catalog.php">← Store</a></p>'); }

site_visit_track('price');

$webAcct = null;
if (!empty($_SESSION['web_account_id'])) {
    $webAcct = row('SELECT * FROM web_accounts WHERE id = ? AND is_active = 1', [(int)$_SESSION['web_account_id']]);
}
$waPct = $webAcct ? (float)$webAcct['discount_pct'] : 0;
$dp = dealer_price($it['selling_price'], $waPct);
$label = trim($it['name'] . ($it['brand'] ? ' - ' . trim($it['brand'] . ' ' . $it['model']) : ''));
$inStock = $it['item_type'] === 'service' || (float)val('SELECT COALESCE(SUM(qty),0) FROM stock WHERE item_id = ?', [$it['id']]) > 0;
$purl = seo_price_url($it);
$prodUrl = seo_product_url($it);
$monthYear = date('F Y');

// full price list of the same category (this is what makes the page a
// genuinely useful "price list", not a copy of the product page)
$catList = $it['category_id']
    ? all('SELECT * FROM items WHERE is_active = 1 AND show_on_website = 1 AND category_id = ? ORDER BY selling_price LIMIT 40', [$it['category_id']])
    : [];

$faqs = [
    $it['name'] . ' નો ભાવ કેટલો છે?' =>
        $it['name'] . ' ની કિંમત ₹' . money($dp) . ' છે (' . $monthYear . ', ' . $app_name . ', Dwarka). જથ્થાબંધ ભાવ માટે WhatsApp કરો.',
    'શું આ પ્રોડક્ટ સ્ટોકમાં છે?' =>
        $inStock ? 'હા, ' . $app_name . ' Dwarka પર અત્યારે સ્ટોકમાં છે — આજે જ મળી શકે.' : 'અત્યારે ઓર્ડર પર મળે છે — WhatsApp કરો, જલદી મંગાવી આપીશું.',
    'વોરંટી મળે છે?' =>
        ((int)$it['warranty_months'] > 0 ? (int)$it['warranty_months'] . ' મહિનાની વોરંટી સાથે મળે છે.' : 'બ્રાન્ડની ઓફિશિયલ વોરંટી પ્રમાણે વોરંટી મળે છે.') . ' પ્રોડક્ટ 100% જેન્યુઇન છે.',
    'ઇન્સ્ટોલેશન / સર્વિસ મળે?' =>
        'હા, ' . $app_name . ' Dwarka પર ઇન્સ્ટોલેશન અને આફ્ટર-સેલ સર્વિસ બંને મળે છે.',
];
$metaDesc = $it['name'] . ' price in Dwarka, Gujarat: ₹' . money($dp) . ' (' . $monthYear . ') at ' . $app_name . '. ' .
            ($it['brand'] ? $it['brand'] . ' ' . $it['model'] . '. ' : '') .
            'Genuine product, warranty, installation & WhatsApp ordering. ' . ($it['cat_name'] ? $it['cat_name'] . ' price list inside.' : '');
?><!DOCTYPE html>
<html lang="gu">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($it['name']) ?> Price — ₹<?= money($dp) ?> (<?= e($monthYear) ?>) | Dwarka | <?= e($app_name) ?></title>
<meta name="description" content="<?= e(mb_substr($metaDesc, 0, 300)) ?>">
<link rel="canonical" href="<?= e($purl) ?>">
<meta property="og:type" content="product">
<meta property="og:site_name" content="<?= e($app_name) ?>">
<meta property="og:title" content="<?= e($it['name']) ?> Price — ₹<?= money($dp) ?> (<?= e($monthYear) ?>)">
<meta property="og:description" content="<?= e(mb_substr($metaDesc, 0, 200)) ?>">
<meta property="og:url" content="<?= e($purl) ?>">
<?php if ($it['photo']): ?><meta property="og:image" content="<?= e(base_url($it['photo'])) ?>"><?php endif; ?>
<meta name="twitter:card" content="summary_large_image">
<link rel="icon" href="<?= e(base_url('assets/icon.svg')) ?>" type="image/svg+xml">
<link rel="stylesheet" href="<?= e(base_url('assets/style.css')) ?>?v=3">
<?= seo_public_css() ?>
<?= seo_jsonld([
    '@context' => 'https://schema.org', '@type' => 'Product',
    'name' => $it['name'],
    'image' => $it['photo'] ? [base_url($it['photo'])] : [],
    'brand' => $it['brand'] ? ['@type' => 'Brand', 'name' => $it['brand']] : null,
    'category' => $it['cat_name'],
    'offers' => ['@type' => 'Offer', 'url' => $purl, 'priceCurrency' => 'INR', 'price' => (string)$dp,
                 'availability' => $inStock ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
                 'seller' => ['@type' => 'Organization', 'name' => $app_name]],
]) ?>
<?= seo_faq_jsonld($faqs) ?>
<?= seo_breadcrumbs([[$app_name, base_url('catalog.php')],
                     $it['cat_name'] ? [$it['cat_name'], seo_cat_url($it['cat_name'])] : [$app_name . ' Store', base_url('catalog.php')],
                     [$it['name'] . ' Price']]) ?>
<style>
.pricebox { background: linear-gradient(120deg, #eef2ff, #ecfeff); border-radius: 16px; padding: 22px 18px; text-align: center; margin-top: 12px; }
[data-theme="dark"] .pricebox { background: linear-gradient(120deg, #1e1b4b, #164e63); }
.pricebox .amt { font-size: 38px; font-weight: 800; color: var(--primary); }
.pricebox .upd { color: var(--muted); font-size: 12.5px; margin-top: 4px; }
.pbtns { margin-top: 14px; display: flex; flex-direction: column; gap: 9px; max-width: 420px; margin-left: auto; margin-right: auto; }
</style>
</head>
<body>
<?= seo_public_header() ?>
<div class="crumbs"><a href="<?= e(base_url('catalog.php')) ?>">Store</a> ›
  <?php if ($it['cat_name']): ?><a href="<?= e(seo_cat_url($it['cat_name'])) ?>"><?= e($it['cat_name']) ?></a> ›<?php endif; ?>
  <?= e($it['name']) ?> Price</div>

<div class="swrap">
  <div class="scard">
    <h1><?= e($it['name']) ?> Price in Dwarka, Gujarat</h1>
    <?php if ($it['brand'] || $it['model']): ?><p class="muted"><?= e(trim($it['brand'] . ' ' . $it['model'])) ?><?= $it['cat_name'] ? ' · ' . e($it['cat_name']) : '' ?></p><?php endif; ?>
    <div class="pricebox">
      <div class="amt">₹<?= money($dp) ?></div>
      <div class="upd">✔ Updated: <?= e($monthYear) ?> · <?= $inStock ? '✅ In stock at ' . e($app_name) . ', Dwarka' : '📦 Available on order' ?></div>
      <div class="pbtns">
        <a class="btn btn-block" href="<?= e($prodUrl) ?>" style="background:linear-gradient(100deg,#4f46e5,#2563eb);border:0">🛒 View Product &amp; Order</a>
        <?php if ($waShop): ?>
        <a class="btn btn-success btn-block" href="https://wa.me/<?= e($waShop) ?>?text=<?= rawurlencode("નમસ્તે! " . $label . " નો ભાવ ₹" . money($dp) . " જોયો — વધુ વિગત જોઈએ છે.\n" . $purl) ?>" rel="noopener">💬 WhatsApp પર ભાવ પાકો કરો</a>
        <?php endif; ?>
      </div>
    </div>
    <p style="margin-top:14px"><?= e($it['name']) ?> — <?= $it['brand'] ? e(trim($it['brand'] . ' ' . $it['model'])) . ' — ' : '' ?>Dwarka (દ્વારકા), Gujarat માં બેસ્ટ ભાવે <?= e($app_name) ?> પર મળે છે. પ્રોડક્ટ 100% જેન્યુઇન, <?= (int)$it['warranty_months'] > 0 ? (int)$it['warranty_months'] . ' મહિનાની વોરંટી' : 'બ્રાન્ડ વોરંટી' ?> અને ઇન્સ્ટોલેશન-સર્વિસ સપોર્ટ સાથે. ભાવમાં ફેરફાર થઈ શકે — લેટેસ્ટ ભાવ માટે WhatsApp કરો.</p>
  </div>

  <?php if (count($catList) > 1): ?>
  <div class="scard">
    <h2><?= e(cat_icon($it['cat_name'])) ?> <?= e($it['cat_name']) ?> Price List — Dwarka (<?= e($monthYear) ?>)</h2>
    <table class="ptable">
      <tr><th><?= e($it['cat_name']) ?></th><th class="num">Price</th></tr>
      <?php foreach ($catList as $r): ?>
      <tr><td><a href="<?= e(seo_price_url($r)) ?>"><?= e($r['name']) ?></a><?= $r['id'] == $it['id'] ? ' ⭐' : '' ?></td>
          <td class="num">₹<?= money(dealer_price($r['selling_price'], $waPct)) ?></td></tr>
      <?php endforeach; ?>
    </table>
    <p class="muted" style="font-size:12.5px">બધા ભાવ <?= e($monthYear) ?> ના છે અને બદલાઈ શકે છે — પાકા ભાવ માટે સંપર્ક કરો.</p>
  </div>
  <?php endif; ?>

  <div class="scard faq">
    <h2>❓ FAQ — <?= e($it['name']) ?></h2>
    <?php foreach ($faqs as $q => $a): ?>
    <details><summary><?= e($q) ?></summary><p><?= e($a) ?></p></details>
    <?php endforeach; ?>
  </div>
</div>
<?= seo_footer() ?>
</body>
</html>
