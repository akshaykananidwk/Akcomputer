<?php
// Public service landing pages (/s/{slug}) - one real content page per local
// service keyword ("cctv installation dwarka", "laptop repair dwarka"...).
// Without ?s= it lists all services. Content lives in seo_services().
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/seo.php';

$app_name = setting('app_name', 'AK Computer');
$waShop = wa_normalize_number(setting('wa_shop_number'));
if (strlen($waShop) < 12) $waShop = '';
$co = seo_company();
$services = seo_services();
$slug = seo_slug(get('s'));
$sv = $slug !== '' ? ($services[$slug] ?? null) : null;
if ($slug !== '' && !$sv) { header('HTTP/1.1 404 Not Found'); die('<meta charset="utf-8"><p style="font-family:sans-serif;text-align:center;margin-top:60px">Service not found. <a href="services.php">← Services</a></p>'); }

site_visit_track('service');

$curl = $sv ? seo_service_url($slug) : base_url('services.php');
$title = $sv ? $sv['title'] : 'Computer, CCTV & IT Services in Dwarka, Gujarat | ' . $app_name;
$metaDesc = $sv ? $sv['desc'] : 'Computer repair, laptop repair, CCTV installation, networking, printer service, data recovery, AMC and internet connection in Dwarka, Gujarat — all IT services by ' . $app_name . '.';
?><!DOCTYPE html>
<html lang="gu">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?></title>
<meta name="description" content="<?= e(mb_substr($metaDesc, 0, 300)) ?>">
<link rel="canonical" href="<?= e($curl) ?>">
<meta property="og:type" content="website">
<meta property="og:site_name" content="<?= e($app_name) ?>">
<meta property="og:title" content="<?= e($title) ?>">
<meta property="og:description" content="<?= e(mb_substr($metaDesc, 0, 200)) ?>">
<meta property="og:url" content="<?= e($curl) ?>">
<link rel="icon" href="<?= e(base_url('assets/icon.svg')) ?>" type="image/svg+xml">
<link rel="stylesheet" href="<?= e(base_url('assets/style.css')) ?>?v=3">
<?= seo_public_css() ?>
<?= seo_localbusiness_jsonld() ?>
<?php if ($sv): ?>
<?= seo_jsonld([
    '@context' => 'https://schema.org', '@type' => 'Service',
    'name' => $sv['name'] . ' — Dwarka', 'serviceType' => $sv['name'],
    'areaServed' => ['@type' => 'City', 'name' => 'Dwarka'],
    'provider' => ['@type' => 'ElectronicsStore', 'name' => $app_name, 'telephone' => (string)$co['phone'],
                   'address' => ['@type' => 'PostalAddress', 'streetAddress' => (string)$co['address'],
                                 'addressLocality' => 'Dwarka', 'addressRegion' => 'Gujarat', 'addressCountry' => 'IN']],
]) ?>
<?= seo_faq_jsonld($sv['faqs']) ?>
<?= seo_breadcrumbs([[$app_name, base_url('catalog.php')], ['Services', base_url('services.php')], [$sv['name']]]) ?>
<?php endif; ?>
</head>
<body>
<?= seo_public_header() ?>
<div class="crumbs"><a href="<?= e(base_url('catalog.php')) ?>">Store</a> › <?php if ($sv): ?><a href="<?= e(base_url('services.php')) ?>">Services</a> › <?= e($sv['name']) ?><?php else: ?>Services<?php endif; ?></div>

<div class="swrap">
<?php if ($sv): ?>
  <div class="scard">
    <h1><?= $sv['emoji'] ?> <?= e($sv['name']) ?> in Dwarka, Gujarat</h1>
    <?php foreach ($sv['paras'] as $p): ?><p><?= e($p) ?></p><?php endforeach; ?>
    <div class="chips" style="margin-top:14px">
      <?php foreach ($sv['points'] as $pt): ?><a href="<?= e($curl) ?>" onclick="return false" style="cursor:default">✔ <?= e($pt) ?></a><?php endforeach; ?>
    </div>
    <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:16px">
      <?php if ($waShop): ?>
      <a class="btn btn-success" href="https://wa.me/<?= e($waShop) ?>?text=<?= rawurlencode('નમસ્તે! મારે ' . $sv['name'] . ' માટે વાત કરવી છે.') ?>" rel="noopener">💬 WhatsApp પર પૂછો</a>
      <?php endif; ?>
      <?php if ($co['phone']): ?><a class="btn btn-outline" href="tel:<?= e(preg_replace('/\D/', '', $co['phone'])) ?>">📞 <?= e($co['phone']) ?></a><?php endif; ?>
    </div>
  </div>
  <div class="scard faq">
    <h2>❓ FAQ</h2>
    <?php foreach ($sv['faqs'] as $q => $a): ?>
    <details><summary><?= e($q) ?></summary><p><?= e($a) ?></p></details>
    <?php endforeach; ?>
  </div>
  <div class="scard">
    <h2>બીજી સર્વિસ</h2>
    <div class="chips">
      <?php foreach ($services as $s2 => $d2): if ($s2 === $slug) continue; ?>
      <a href="<?= e(seo_service_url($s2)) ?>"><?= $d2['emoji'] ?> <?= e($d2['name']) ?></a>
      <?php endforeach; ?>
    </div>
  </div>
<?php else: ?>
  <div class="scard">
    <h1>🛠️ Our Services — <?= e($app_name) ?>, Dwarka</h1>
    <p>Dwarka (દ્વારકા), Gujarat માં કમ્પ્યુટર, લેપટોપ, CCTV, પ્રિન્ટર અને નેટવર્કિંગની બધી સર્વિસ એક જ જગ્યાએ. નીચેની કોઈ પણ સર્વિસ પર ક્લિક કરી વિગત જુઓ.</p>
  </div>
  <div class="pgrid" style="grid-template-columns:repeat(auto-fill,minmax(240px,1fr))">
    <?php foreach ($services as $s2 => $d2): ?>
    <a class="pcard" href="<?= e(seo_service_url($s2)) ?>" style="padding:16px">
      <div style="font-size:34px"><?= $d2['emoji'] ?></div>
      <div class="pn" style="font-size:15px;margin-top:6px"><?= e($d2['name']) ?></div>
      <div class="muted" style="font-size:12.5px;margin-top:4px"><?= e(mb_substr($d2['desc'], 0, 90)) ?>…</div>
    </a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
</div>
<?= seo_footer() ?>
</body>
</html>
