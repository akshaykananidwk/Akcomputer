<?php
$u = current_user();
$page_title = $page_title ?? 'AK Computer';
$app_name = setting('app_name', 'AK Computer');

$nav = [
    ['dashboard.view', 'index.php', '🏠', 'Dashboard'],
    ['sales.view', 'sales.php', '🧾', 'Sales / Billing'],
    ['estimates.view', 'estimates.php', '📋', 'Estimates'],
    ['sales_return.view', 'sales_return.php', '↩️', 'Sales Return'],
    ['purchases.view', 'purchases.php', '📦', 'Purchase'],
    ['purchase_return.view', 'purchase_return.php', '↪️', 'Purchase Return'],
    ['items.view', 'items.php', '🖥️', 'Items'],
    ['parties.view', 'parties.php', '👥', 'Parties'],
    ['stock.view', 'stock.php', '📊', 'Stock'],
    ['handover.view', 'handover.php', '🤝', 'Handover'],
    [null, 'my_stock.php', '🎒', 'My Stock'],
    ['tasks.view', 'tasks.php', '🔧', 'Field Tasks'],
    ['repairs.view', 'repairs.php', '🛠️', 'Repair Jobs'],
    ['warranty.view', 'warranty.php', '🛡️', 'Warranty'],
    ['payments.view', 'payments.php', '💰', 'Payments'],
    ['expenses.view', 'expenses.php', '🧾', 'Expenses'],
    ['challans.view', 'challans.php', '🚚', 'Challans'],
    ['reports.view', 'reports.php', '📈', 'Reports'],
    ['users.view', 'users.php', '🧑‍💼', 'Staff Users'],
    ['roles.view', 'roles.php', '🔑', 'Roles'],
    ['locations.view', 'locations.php', '🏪', 'Locations'],
    ['companies.view', 'companies.php', '🏢', 'Companies'],
    ['settings.view', 'settings.php', '⚙️', 'Settings'],
];
$current = basename($_SERVER['SCRIPT_NAME']);
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#1a56db">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<link rel="manifest" href="manifest.json">
<link rel="icon" href="assets/icon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="assets/icon-192.png">
<title><?= e($page_title) ?> - <?= e($app_name) ?></title>
<link rel="stylesheet" href="assets/style.css?v=1">
</head>
<body>
<?php if ($u): ?>
<header class="topbar">
  <button class="menu-btn" id="menuBtn" aria-label="Menu">☰</button>
  <div class="topbar-title"><?= e($page_title) ?></div>
  <div class="topbar-user">
    <span class="topbar-loc"><?= e($u['location_name']) ?></span>
    <a href="logout.php" class="logout-link" onclick="return confirm('Logout?')">⎋</a>
  </div>
</header>
<div class="sidebar-overlay" id="sidebarOverlay"></div>
<nav class="sidebar" id="sidebar">
  <div class="sidebar-head">
    <div class="sidebar-appname"><?= e($app_name) ?></div>
    <div class="sidebar-username"><?= e($u['name']) ?> · <?= e($u['role_name']) ?></div>
  </div>
  <div class="sidebar-links">
  <?php foreach ($nav as $n): if ($n[0] !== null && !can($n[0])) continue; ?>
    <a href="<?= $n[1] ?>" class="<?= $current === $n[1] ? 'active' : '' ?>"><span class="nav-ico"><?= $n[2] ?></span><?= $n[3] ?></a>
  <?php endforeach; ?>
  </div>
</nav>
<?php endif; ?>
<main class="content<?= $u ? '' : ' content-full' ?>">
<?php foreach (get_flashes() as $f): ?>
  <div class="flash flash-<?= e($f['type']) ?>"><?= e($f['msg']) ?></div>
<?php endforeach; ?>
