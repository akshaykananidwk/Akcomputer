<?php
// Public Privacy Policy page - required by Meta (WhatsApp Cloud API app
// review / Live mode) and good practice for the storefront anyway.
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/seo.php';

$app_name = setting('app_name', 'AK Computer');
$co = seo_company();
$curl = base_url('privacy.php');
$page = [
    ['Information We Collect',
     'When you order from our store, request a repair/service, or contact us, we collect the details needed to serve you: your name, mobile number, delivery address, and the products or services you purchase. If you create a dealer account, we also store your login credentials (password is stored encrypted).'],
    ['How We Use Your Information',
     'We use your information only to run our shop: preparing invoices and estimates, delivering orders, providing warranty and repair service, sending order/billing updates, one-time passwords (OTP) and payment receipts on WhatsApp or SMS, and payment collection. We may send occasional service messages such as payment reminders related to your own purchases.'],
    ['WhatsApp Messages',
     'With your consent (by sharing your WhatsApp number with us), we send transactional messages - invoices, receipts, order updates, OTPs and reminders - through the WhatsApp Business Platform (Meta) and/or authorised messaging providers. Message delivery is processed by Meta Platforms as per their own privacy policy. You may ask us to stop WhatsApp messages at any time.'],
    ['What We Never Do',
     'We do not sell, rent or trade your personal information to anyone. Your data is shared only with services strictly needed to operate (for example, our payment gateway for online payments or WhatsApp for message delivery), and only to the extent required.'],
    ['Data Security & Retention',
     'Your data is stored on secured servers with access limited to authorised staff. Billing records are retained as required by Indian tax law. You may request correction of your contact details at any time.'],
    ['Cookies',
     'Our website uses only essential cookies (for example, to keep your cart or dealer login active). We do not use third-party advertising or tracking cookies.'],
    ['Contact / Grievance',
     'For any privacy question, correction or deletion request, contact us: ' . $app_name . ', ' . ($co['address'] ?: 'Dwarka, Gujarat') . ($co['phone'] ? ', Phone/WhatsApp: ' . $co['phone'] : '') . ($co['email'] ? ', Email: ' . $co['email'] : '') . '.'],
];
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Privacy Policy | <?= e($app_name) ?>, Dwarka</title>
<meta name="description" content="Privacy Policy of <?= e($app_name) ?>, Dwarka, Gujarat — what customer information we collect, how it is used for billing and WhatsApp updates, and how to contact us.">
<link rel="canonical" href="<?= e($curl) ?>">
<meta property="og:type" content="website">
<meta property="og:title" content="Privacy Policy | <?= e($app_name) ?>">
<meta property="og:url" content="<?= e($curl) ?>">
<link rel="icon" href="<?= e(base_url('assets/icon.svg')) ?>" type="image/svg+xml">
<link rel="stylesheet" href="<?= e(base_url('assets/style.css')) ?>?v=<?= asset_v('style.css') ?>">
<?= seo_public_css() ?>
</head>
<body>
<?= seo_public_header() ?>
<div class="crumbs"><a href="<?= e(base_url('catalog.php')) ?>">Store</a> › Privacy Policy</div>
<div class="swrap">
  <div class="scard">
    <h1>🔒 Privacy Policy</h1>
    <p class="muted">Last updated: <?= date('d-m-Y') ?> · <?= e($app_name) ?> ("we", "our shop") — Dwarka, Gujarat, India</p>
    <p>This policy explains what information we collect from our customers and website visitors, and how we use and protect it. By using our website (shop.akdwk.in), ordering from us, or messaging us on WhatsApp, you agree to this policy.</p>
    <?php foreach ($page as $i => [$h, $t]): ?>
    <h2 style="margin-top:18px"><?= ($i + 1) ?>. <?= e($h) ?></h2>
    <p><?= e($t) ?></p>
    <?php endforeach; ?>
  </div>
</div>
<?= seo_footer() ?>
</body>
</html>
