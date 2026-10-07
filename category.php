<?php
// Public category landing page (/c/{slug}) - server-rendered so Google can
// crawl a real "CCTV Camera in Dwarka" page with all products linked, unlike
// the JS-filtered catalog. One page per category with live products.
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/seo.php';

$app_name = setting('app_name', 'AK Computer');
$slug = seo_slug(get('s'));
$cat = null;
foreach (seo_cats() as $c) if ($slug !== '' && seo_slug($c['name']) === $slug) { $cat = $c; break; }
if (!$cat) { header('HTTP/1.1 404 Not Found'); die('<meta charset="utf-8"><p style="font-family:sans-serif;text-align:center;margin-top:60px">Category not found. <a href="catalog.php">← Store</a></p>'); }

site_visit_track('category');

$webAcct = !empty($_SESSION['web_account_id']) ? row('SELECT * FROM web_accounts WHERE id = ? AND is_active = 1', [(int)$_SESSION['web_account_id']]) : null;
$waPct = $webAcct ? (float)$webAcct['discount_pct'] : 0;

$items = all('SELECT i.* FROM items i JOIN categories c ON c.id = i.category_id
              WHERE i.is_active = 1 AND i.show_on_website = 1 AND c.name = ? ORDER BY i.name', [$cat['name']]);
$brands = [];
foreach ($items as $i) if ($i['brand'] !== '') $brands[$i['brand']] = true;
$brands = array_keys($brands);
$curl = seo_cat_url($cat['name']);
$metaDesc = $cat['name'] . ' in ' . shop_place() . ' at best price — ' . count($items) . ' products from ₹' . money($cat['pmin']) . ' to ₹' . money($cat['pmax']) .
            ($brands ? '. Brands: ' . implode(', ', array_slice($brands, 0, 6)) : '') .
            '. Genuine products, warranty & installation at ' . $app_name . '. WhatsApp ordering.';

$listLd = [];
foreach (array_slice($items, 0, 30) as $pos => $i) {
    $listLd[] = ['@type' => 'ListItem', 'position' => $pos + 1, 'name' => $i['name'], 'url' => seo_product_url($i)];
}
?><!DOCTYPE html>
<html lang="gu">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($cat['name']) ?> in <?= e(shop_place()) ?> — Price List &amp; Best Deals | <?= e($app_name) ?></title>
<meta name="description" content="<?= e(mb_substr($metaDesc, 0, 300)) ?>">
<link rel="canonical" href="<?= e($curl) ?>">
<meta property="og:type" content="website">
<meta property="og:site_name" content="<?= e($app_name) ?>">
<meta property="og:title" content="<?= e($cat['name']) ?> in <?= e(shop_city()) ?> — Best Price | <?= e($app_name) ?>">
<meta property="og:description" content="<?= e(mb_substr($metaDesc, 0, 200)) ?>">
<meta property="og:url" content="<?= e($curl) ?>">
<link rel="icon" href="<?= e(base_url('assets/icon.svg')) ?>" type="image/svg+xml">
<link rel="stylesheet" href="<?= e(base_url('assets/style.css')) ?>?v=<?= asset_v('style.css') ?>">
<?= seo_public_css() ?>
<?= seo_jsonld(['@context' => 'https://schema.org', '@type' => 'ItemList', 'name' => $cat['name'] . ' — ' . $app_name . ' ' . shop_city(), 'itemListElement' => $listLd]) ?>
<?= seo_breadcrumbs([[$app_name, base_url('catalog.php')], [$cat['name']]]) ?>
<?= seo_localbusiness_jsonld() ?>
</head>
<body>
<?= seo_public_header() ?>
<div class="crumbs"><a href="<?= e(base_url('catalog.php')) ?>">Store</a> › <?= e($cat['name']) ?></div>

<div class="swrap">
  <div class="scard">
    <h1><?= e(cat_icon($cat['name'])) ?> <?= e($cat['name']) ?> in <?= e(shop_place()) ?></h1>
    <p><?= e($cat['name']) ?> to buy in <?= e(shop_city()) ?> <?= e($app_name) ?> — <strong><?= count($items) ?> Products</strong>,
       Price Rs <?= money($cat['pmin']) ?> from Rs <?= money($cat['pmax']) ?>. 100% genuine products, with warranty and installation service.
       <?php if ($brands): ?>Brands: <strong><?= e(implode(', ', array_slice($brands, 0, 8))) ?></strong>.<?php endif; ?>
       Order on WhatsApp — home delivery is available too.</p>
    <?php if ($brands): ?>
    <div class="chips">
      <?php foreach (array_slice($brands, 0, 12) as $b): if (seo_slug($b) === '') continue; ?>
      <a href="<?= e(seo_brand_url($b)) ?>"><?= e($b) ?></a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <div class="pgrid">
    <?php foreach ($items as $i): $pp = dealer_price($i['selling_price'], $waPct); ?>
    <a class="pcard" href="<?= e(seo_product_url($i)) ?>">
      <?php if ($i['photo']): ?><img src="<?= e(base_url($i['photo'])) ?>" alt="<?= e($i['name'] . ' price in ' . shop_city()) ?>" loading="lazy">
      <?php else: ?><div class="ph"><?= e(cat_icon($cat['name'])) ?></div><?php endif; ?>
      <div class="pb"><div class="pn"><?= e($i['name']) ?></div><div class="pp">₹<?= money($pp) ?></div></div>
    </a>
    <?php endforeach; ?>
  </div>
</div>
<?= seo_footer() ?>
</body>
</html>
