<?php
// Public Terms & Conditions page - companion to privacy.php; the pair is
// what Meta asks for (Privacy Policy URL + Terms of Service URL).
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/seo.php';

$app_name = setting('app_name', 'AK Computer');
$co = seo_company();
$curl = base_url('terms.php');
$page = [
    ['Products & Pricing',
     'All prices shown on the website are in Indian Rupees and include our standard billing terms. Prices can change without notice; the price confirmed on your invoice/estimate at the time of order is final. Product photos are indicative - actual packaging may differ.'],
    ['Orders & Delivery',
     'Website orders are confirmed by our team on WhatsApp/phone before dispatch. Items shown "on order" are procured after confirmation. Delivery timelines communicated are estimates; local delivery in and around Dwarka is arranged mutually.'],
    ['Payments',
     'We accept cash, UPI, bank transfer and payment links. For credit sales, the credit period agreed on the invoice applies; overdue amounts may pause further credit. Online payments are processed by our payment gateway partner - we never store your card/UPI credentials.'],
    ['Warranty & Service',
     'Products carry the manufacturer/brand warranty as per the brand\'s policy; warranty periods noted on the invoice are for reference. Warranty claims are serviced through the brand\'s authorised process - physical damage, burn, liquid damage and tampered serial numbers are not covered. Our own service/installation work carries a workmanship guarantee as agreed on the job card.'],
    ['Returns & Replacement',
     'A product found dead-on-arrival or not as billed should be reported within 48 hours with the original invoice and packaging - we will repair, replace or adjust as appropriate. Software, consumables (ink/toner), cut cables and specially-procured items are not returnable.'],
    ['Repair Jobs',
     'Devices left for repair are handled with care; however we are not responsible for data loss - please back up your data. Repaired devices not collected within 30 days of completion may attract storage handling or be disposed of after due notice.'],
    ['WhatsApp & Communication',
     'By sharing your mobile number you consent to receive transactional messages (invoices, receipts, OTPs, order and repair updates, payment reminders) on WhatsApp/SMS. You may opt out of non-essential messages at any time.'],
    ['Jurisdiction',
     'All dealings are subject to the jurisdiction of courts at Devbhoomi Dwarka, Gujarat, India.'],
    ['Contact',
     $app_name . ', ' . ($co['address'] ?: 'Dwarka, Gujarat') . ($co['phone'] ? ', Phone/WhatsApp: ' . $co['phone'] : '') . ($co['email'] ? ', Email: ' . $co['email'] : '') . '.'],
];
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Terms &amp; Conditions | <?= e($app_name) ?>, Dwarka</title>
<meta name="description" content="Terms &amp; Conditions of <?= e($app_name) ?>, Dwarka, Gujarat — pricing, orders, payments, warranty, returns, repair jobs and communication terms.">
<link rel="canonical" href="<?= e($curl) ?>">
<meta property="og:type" content="website">
<meta property="og:title" content="Terms &amp; Conditions | <?= e($app_name) ?>">
<meta property="og:url" content="<?= e($curl) ?>">
<link rel="icon" href="<?= e(base_url('assets/icon.svg')) ?>" type="image/svg+xml">
<link rel="stylesheet" href="<?= e(base_url('assets/style.css')) ?>?v=<?= asset_v('style.css') ?>">
<?= seo_public_css() ?>
</head>
<body>
<?= seo_public_header() ?>
<div class="crumbs"><a href="<?= e(base_url('catalog.php')) ?>">Store</a> › Terms &amp; Conditions</div>
<div class="swrap">
  <div class="scard">
    <h1>📜 Terms &amp; Conditions</h1>
    <p class="muted">Last updated: <?= date('d-m-Y') ?> · <?= e($app_name) ?> — Dwarka, Gujarat, India</p>
    <p>These terms apply to purchases from our shop and website (shop.akdwk.in), and to repair/installation services. Placing an order or handing over a device for service means you accept these terms.</p>
    <?php foreach ($page as $i => [$h, $t]): ?>
    <h2 style="margin-top:18px"><?= ($i + 1) ?>. <?= e($h) ?></h2>
    <p><?= e($t) ?></p>
    <?php endforeach; ?>
  </div>
</div>
<?= seo_footer() ?>
</body>
</html>
