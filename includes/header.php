<?php
$u = current_user();
$page_title = $page_title ?? 'AK Computer';
$app_name = setting('app_name', 'AK Computer');

// Menu: link => [href, icon, label, perm]
// group => [id, icon, label, items[]] ; item = [href, label, perm, plus_href, plus_perm]
$_navMenu = [
    ['link', 'index.php', '🏠', 'Dashboard', 'dashboard.view'],
    ['group', 'parties', '👥', 'Parties', [
        ['parties.php', 'All Parties', 'parties.view', 'parties.php?action=new', 'parties.add'],
        ['payments.php', 'Party Payments', 'payments.view', null, null],
    ]],
    ['group', 'items', '🖥️', 'Items', [
        ['items.php', 'All Items', 'items.view', 'items.php?action=new', 'items.add'],
        ['items_import.php', 'Import Excel/CSV', 'items.add', null, null],
        ['stock.php', 'Stock Levels', 'stock.view', null, null],
    ]],
    ['group', 'sale', '🧾', 'Sale', [
        ['sales.php', 'Sale Invoices', 'sales.view', 'sales.php?action=new', 'sales.add'],
        ['estimates.php', 'Estimate / Quotation', 'estimates.view', 'estimates.php?action=new', 'estimates.add'],
        ['sales_return.php', 'Sale Return', 'sales_return.view', 'sales_return.php?action=new', 'sales_return.add'],
        ['challans.php', 'Delivery Challan', 'challans.view', 'challans.php?action=new', 'challans.add'],
    ]],
    ['group', 'purchase', '📦', 'Purchase', [
        ['purchases.php', 'Purchase Bills', 'purchases.view', 'purchases.php?action=new', 'purchases.add'],
        ['purchase_return.php', 'Purchase Return', 'purchase_return.view', 'purchase_return.php?action=new', 'purchase_return.add'],
    ]],
    ['link', 'expenses.php', '💸', 'Expenses', 'expenses.view'],
    ['group', 'cashbank', '🏦', 'Cash & Bank', [
        ['cash_bank.php', 'Cash & Bank Overview', 'payments.view', null, null],
        ['bank_accounts.php', 'Bank Accounts', 'settings.view', 'bank_accounts.php', 'settings.edit'],
        ['payment_methods.php', 'Payment Methods', 'settings.view', 'payment_methods.php', 'settings.edit'],
    ]],
    ['group', 'godown', '🏬', 'Stock / Godown', [
        ['handover.php', 'Handover / Transfer', 'handover.view', 'handover.php?action=new', 'handover.add'],
        ['my_stock.php', 'My Stock', null, null, null],
        ['stock.php', 'Location Stock', 'stock.view', null, null],
    ]],
    ['group', 'service', '🛠️', 'Repair & Service', [
        ['repairs.php', 'Repair Jobs', 'repairs.view', 'repairs.php?action=new', 'repairs.add'],
        ['tasks.php', 'Field Tasks', 'tasks.view', 'tasks.php?action=new', 'tasks.add'],
        ['warranty.php', 'Warranty Claims', 'warranty.view', 'warranty.php?action=new', 'warranty.add'],
        ['amc.php', 'AMC / Recurring Billing', 'amc.view', 'amc.php?action=new', 'amc.add'],
    ]],
    ['link', 'reports.php', '📈', 'Reports', 'reports.view'],
    ['group', 'store', '🌐', 'My Online Store', [
        ['catalog.php', 'View Website', null, null, null],
        ['web_orders.php', 'Website Orders', 'weborders.view', null, null],
        ['items.php', 'Website Items (🌐 ON/OFF)', 'items.view', null, null],
    ]],
    ['group', 'admin', '🧑‍💼', 'Staff & Company', [
        ['users.php', 'Staff Users', 'users.view', 'users.php?action=new', 'users.add'],
        ['roles.php', 'Roles / Permissions', 'roles.view', 'roles.php?action=new', 'roles.add'],
        ['locations.php', 'Locations', 'locations.view', null, null],
        ['companies.php', 'Companies / Firms', 'companies.view', null, null],
    ]],
    ['link', 'settings.php', '⚙️', 'Settings', 'settings.view'],
];

$_navCur = basename($_SERVER['SCRIPT_NAME']);

function nav_visible_items($items) {
    $out = [];
    foreach ($items as $it) {
        if ($it[2] === null || can($it[2])) $out[] = $it;
    }
    return $out;
}
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
<link rel="stylesheet" href="assets/style.css?v=3">
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
    <div class="sidebar-avatar">🏪</div>
    <div>
      <div class="sidebar-appname"><?= e($app_name) ?></div>
      <div class="sidebar-username"><?= e($u['name']) ?> · <?= e($u['role_name']) ?><br><?= e($u['location_name']) ?></div>
    </div>
  </div>
  <div class="sidebar-links">
  <?php foreach ($_navMenu as $_nm): ?>
    <?php if ($_nm[0] === 'link'):
        if ($_nm[4] !== null && !can($_nm[4])) continue; ?>
      <a href="<?= $_nm[1] ?>" class="nav-link <?= $_navCur === $_nm[1] ? 'active' : '' ?>"><span class="nav-ico"><?= $_nm[2] ?></span><?= $_nm[3] ?></a>
    <?php else:
        $_navItems = nav_visible_items($_nm[4]);
        if (!$_navItems) continue;
        $_navOpen = false;
        foreach ($_navItems as $_ni) if (basename(parse_url($_ni[0], PHP_URL_PATH)) === $_navCur) $_navOpen = true;
    ?>
      <div class="nav-group <?= $_navOpen ? 'open' : '' ?>" data-group="<?= $_nm[1] ?>">
        <button type="button" class="nav-group-head <?= $_navOpen ? 'active' : '' ?>">
          <span class="nav-ico"><?= $_nm[2] ?></span><?= $_nm[3] ?><span class="nav-chev">▾</span>
        </button>
        <div class="nav-sub">
        <?php foreach ($_navItems as $_ni): ?>
          <div class="nav-sub-row">
            <a href="<?= $_ni[0] ?>" class="<?= basename(parse_url($_ni[0], PHP_URL_PATH)) === $_navCur ? 'active' : '' ?>"><?= $_ni[1] ?></a>
            <?php if ($_ni[3] && ($_ni[4] === null || can($_ni[4]))): ?>
              <a href="<?= $_ni[3] ?>" class="nav-plus" title="Add new">＋</a>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>
  <?php endforeach; ?>
  </div>
  <div class="sidebar-foot">v<?= e(setting('app_version', '2.2.0')) ?></div>
</nav>

<!-- quick action sheet (center + button) -->
<div class="sheet-overlay" id="sheetOverlay"></div>
<div class="action-sheet" id="actionSheet">
  <div class="sheet-handle"></div>
  <h3>ઝડપી કામ</h3>
  <div class="sheet-grid">
    <?php
    $_navQuick = [
        ['sales.php?action=new', '🧾', 'New Bill', 'sales.add'],
        ['estimates.php?action=new', '📋', 'Estimate', 'estimates.add'],
        ['purchases.php?action=new', '📦', 'Purchase', 'purchases.add'],
        ['payments.php?action=new&dir=in', '⬇️', 'Payment In', 'payments.add'],
        ['payments.php?action=new&dir=out', '⬆️', 'Payment Out', 'payments.add'],
        ['expenses.php', '💸', 'Expense', 'expenses.add'],
        ['repairs.php?action=new', '🛠️', 'Repair Job', 'repairs.add'],
        ['tasks.php?action=new', '🔧', 'Task', 'tasks.add'],
        ['handover.php?action=new', '🤝', 'Handover', 'handover.add'],
        ['challans.php?action=new', '🚚', 'Challan', 'challans.add'],
        ['parties.php?action=new', '👥', 'Party', 'parties.add'],
        ['items.php?action=new', '🖥️', 'Item', 'items.add'],
        ['sales_return.php?action=new', '↩️', 'Sale Return', 'sales_return.add'],
    ];
    foreach ($_navQuick as $_nq): if (!can($_nq[3])) continue; ?>
    <a href="<?= $_nq[0] ?>" class="sheet-item"><span><?= $_nq[1] ?></span><?= $_nq[2] ?></a>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>
<script src="assets/app.js?v=3"></script>
<?php unset($_navMenu, $_navItems, $_navQuick, $_nm, $_ni, $_nq, $_navOpen); ?>
<main class="content<?= $u ? '' : ' content-full' ?>">
<?php foreach (get_flashes() as $f): ?>
  <div class="flash flash-<?= e($f['type']) ?>"><?= e($f['msg']) ?></div>
<?php endforeach; ?>
