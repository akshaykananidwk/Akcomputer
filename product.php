<?php
// Public per-product page - one indexable URL per item for SEO (title, meta
// description, OG tags, Product JSON-LD with price), dealer-aware pricing
// (a logged-in dealer sees their own price directly, never the %), related
// products, and Add-to-Cart that hands over to the catalog's cart.
require_once __DIR__ . '/includes/init.php';

$app_name = setting('app_name', 'AK Computer');
$waShop = wa_normalize_number(setting('wa_shop_number'));
if (strlen($waShop) < 12) $waShop = '';

$it = row('SELECT i.*, c.name cat_name FROM items i LEFT JOIN categories c ON c.id = i.category_id
           WHERE i.id = ? AND i.is_active = 1 AND i.show_on_website = 1', [(int)get('id')]);
if (!$it) { header('HTTP/1.1 404 Not Found'); die('<meta charset="utf-8"><p style="font-family:sans-serif;text-align:center;margin-top:60px">Product not found. <a href="catalog.php">← Store</a></p>'); }

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
$purl = base_url('product.php?id=' . $it['id']);
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
<meta property="og:title" content="<?= e($label) ?> — ₹<?= money($dp) ?>">
<meta property="og:description" content="<?= e(mb_substr($desc, 0, 200)) ?>">
<meta property="og:url" content="<?= e($purl) ?>">
<?php if ($it['photo']): ?><meta property="og:image" content="<?= e(base_url($it['photo'])) ?>"><?php endif; ?>
<link rel="icon" href="assets/icon.svg" type="image/svg+xml">
<link rel="stylesheet" href="assets/style.css?v=3">
<script type="application/ld+json">
<?= json_encode([
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
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
</script>
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
  <a class="logo" href="catalog.php">🖥️ <?= e($app_name) ?></a>
  <a class="back" href="catalog.php">← Store</a>
</header>

<div class="pwrap">
  <div class="pimg">
    <?php if ($it['photo']): ?><img src="<?= e($it['photo']) ?>" alt="<?= e($label) ?>">
    <?php else: ?><div class="ph"><?= e(cat_icon($it['cat_name'] ?? '')) ?></div><?php endif; ?>
  </div>
  <div class="pinfo">
    <?php if ($it['cat_name']): ?><div class="ptag"><?= e(cat_icon($it['cat_name'])) ?> <?= e($it['cat_name']) ?></div><?php endif; ?>
    <h1><?= e($it['name']) ?></h1>
    <?php if ($it['brand'] || $it['model']): ?><div class="pbrand"><?= e(trim($it['brand'] . ' ' . $it['model'])) ?></div><?php endif; ?>
    <div class="pprice">₹<?= money($dp) ?><?php if ($webAcct): ?> <span style="font-size:13px;color:#059669;font-weight:700">👷 તમારો ભાવ</span><?php endif; ?></div>
    <div class="pstock" style="color:<?= $inStock ? '#059669' : '#dc2626' ?>"><?= $inStock ? '✅ In stock — આજે જ મળે' : '📦 Order પર મળશે — WhatsApp કરો' ?></div>
    <?php if ($it['description']): ?><p class="pdesc"><?= nl2br(e($it['description'])) ?></p><?php endif; ?>
    <div class="facts">
      <?php if ($it['warranty_months'] > 0): ?><div>🛡️ Warranty: <?= (int)$it['warranty_months'] ?> months</div><?php endif; ?>
      <div>🏪 <?= e($app_name) ?>, Dwarka</div>
      <div>🛠️ Installation &amp; service available</div>
      <div>✅ Genuine product</div>
    </div>
    <div class="pbtns">
      <a class="btn btn-block" href="catalog.php?add=<?= $it['id'] ?>" style="background:linear-gradient(100deg,#4f46e5,#2563eb);border:0">🛒 Add to Cart</a>
      <?php if ($waShop): ?>
      <a class="btn btn-success btn-block" href="https://wa.me/<?= e($waShop) ?>?text=<?= rawurlencode("નમસ્તે! મને આ પ્રોડક્ટ જોઈએ છે:\n" . $label . " - ₹" . money($dp) . "\n" . $purl) ?>" target="_blank" rel="noopener">📲 WhatsApp પર ઓર્ડર કરો</a>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php if ($related): ?>
<div class="rel">
  <h2>આવી બીજી પ્રોડક્ટ</h2>
  <div class="grid">
    <?php foreach ($related as $r): $rp = dealer_price($r['selling_price'], $waPct); ?>
    <a class="rcard" href="product.php?id=<?= $r['id'] ?>">
      <?php if ($r['photo']): ?><img src="<?= e($r['photo']) ?>" alt="<?= e($r['name']) ?>" loading="lazy">
      <?php else: ?><div class="ph"><?= e(cat_icon($it['cat_name'] ?? '')) ?></div><?php endif; ?>
      <div class="rb"><div class="rn"><?= e($r['name']) ?></div><div class="rp">₹<?= money($rp) ?></div></div>
    </a>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>
</body>
</html>
