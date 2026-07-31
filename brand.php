<?php
// Public brand landing page (/b/{slug}) - "Hikvision dealer in Dwarka" type
// queries. One server-rendered page per brand with live products.
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/seo.php';

$app_name = setting('app_name', 'AK Computer');
$slug = seo_slug(get('s'));
$brand = null;
foreach (seo_brands() as $b) if ($slug !== '' && seo_slug($b['name']) === $slug) { $brand = $b['name']; break; }
if ($brand === null) { header('HTTP/1.1 404 Not Found'); die('<meta charset="utf-8"><p style="font-family:sans-serif;text-align:center;margin-top:60px">Brand not found. <a href="catalog.php">← Store</a></p>'); }

site_visit_track('brand');

$webAcct = !empty($_SESSION['web_account_id']) ? row('SELECT * FROM web_accounts WHERE id = ? AND is_active = 1', [(int)$_SESSION['web_account_id']]) : null;
$waPct = $webAcct ? (float)$webAcct['discount_pct'] : 0;

$items = all('SELECT i.*, c.name cat_name FROM items i LEFT JOIN categories c ON c.id = i.category_id
              WHERE i.is_active = 1 AND i.show_on_website = 1 AND i.brand = ? ORDER BY i.name', [$brand]);
$cats = [];
foreach ($items as $i) if ($i['cat_name']) $cats[$i['cat_name']] = true;
$cats = array_keys($cats);
$prices = array_map(fn($i) => (float)$i['selling_price'], $items);
$curl = seo_brand_url($brand);
$metaDesc = $brand . ' products in Dwarka, Gujarat — ' . count($items) . ' genuine ' . $brand . ' products' .
            ($cats ? ' (' . implode(', ', array_slice($cats, 0, 5)) . ')' : '') .
            ' from ₹' . money(min($prices)) . ' at ' . $app_name . '. Warranty, installation & WhatsApp ordering.';

$listLd = [];
foreach (array_slice($items, 0, 30) as $pos => $i) {
    $listLd[] = ['@type' => 'ListItem', 'position' => $pos + 1, 'name' => $i['name'], 'url' => seo_product_url($i)];
}
?><!DOCTYPE html>
<html lang="gu">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($brand) ?> in Dwarka, Gujarat — Genuine <?= e($brand) ?> Products | <?= e($app_name) ?></title>
<meta name="description" content="<?= e(mb_substr($metaDesc, 0, 300)) ?>">
<link rel="canonical" href="<?= e($curl) ?>">
<meta property="og:type" content="website">
<meta property="og:site_name" content="<?= e($app_name) ?>">
<meta property="og:title" content="<?= e($brand) ?> — Dwarka | <?= e($app_name) ?>">
<meta property="og:description" content="<?= e(mb_substr($metaDesc, 0, 200)) ?>">
<meta property="og:url" content="<?= e($curl) ?>">
<link rel="icon" href="<?= e(base_url('assets/icon.svg')) ?>" type="image/svg+xml">
<link rel="stylesheet" href="<?= e(base_url('assets/style.css')) ?>?v=3">
<?= seo_public_css() ?>
<?= seo_jsonld(['@context' => 'https://schema.org', '@type' => 'ItemList', 'name' => $brand . ' — ' . $app_name . ' Dwarka', 'itemListElement' => $listLd]) ?>
<?= seo_breadcrumbs([[$app_name, base_url('catalog.php')], [$brand]]) ?>
</head>
<body>
<?= seo_public_header() ?>
<div class="crumbs"><a href="<?= e(base_url('catalog.php')) ?>">Store</a> › <?= e($brand) ?></div>

<div class="swrap">
  <div class="scard">
    <h1><?= e($brand) ?> Products in Dwarka, Gujarat</h1>
    <p><?= e($brand) ?> ની ઓરિજિનલ પ્રોડક્ટ Dwarka (દ્વારકા) માં <?= e($app_name) ?> પર — <strong><?= count($items) ?> પ્રોડક્ટ</strong>,
       ભાવ ₹<?= money(min($prices)) ?> થી ₹<?= money(max($prices)) ?>.
       <?php if ($cats): ?><?= e(implode(', ', array_slice($cats, 0, 6))) ?> —<?php endif; ?>
       બધું વોરંટી અને સર્વિસ સપોર્ટ સાથે. WhatsApp પર ભાવ પૂછો કે ઓર્ડર કરો.</p>
    <?php if ($cats): ?>
    <div class="chips">
      <?php foreach (array_slice($cats, 0, 10) as $c): if (seo_slug($c) === '') continue; ?>
      <a href="<?= e(seo_cat_url($c)) ?>"><?= e(cat_icon($c)) ?> <?= e($c) ?></a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <div class="pgrid">
    <?php foreach ($items as $i): $pp = dealer_price($i['selling_price'], $waPct); ?>
    <a class="pcard" href="<?= e(seo_product_url($i)) ?>">
      <?php if ($i['photo']): ?><img src="<?= e(base_url($i['photo'])) ?>" alt="<?= e($brand . ' ' . $i['name'] . ' Dwarka') ?>" loading="lazy">
      <?php else: ?><div class="ph"><?= e(cat_icon($i['cat_name'] ?? '')) ?></div><?php endif; ?>
      <div class="pb"><div class="pn"><?= e($i['name']) ?></div><div class="pp">₹<?= money($pp) ?></div></div>
    </a>
    <?php endforeach; ?>
  </div>
</div>
<?= seo_footer() ?>
</body>
</html>
