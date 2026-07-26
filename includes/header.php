<?php
$u = current_user();
$page_title = $page_title ?? 'AK Computer';
$app_name = setting('app_name', 'AK Computer');

// Menu: link => [href, icon, label, perm]
// group => [id, icon, label, items[]] ; item = [href, label, perm, plus_href, plus_perm]
$_navMenu = [
    ['link', 'index.php', 'home', 'Dashboard', 'dashboard.view'],
    ['group', 'parties', 'users', 'Parties', [
        ['parties.php', 'All Parties', 'parties.view', 'parties.php?action=new', 'parties.add'],
        ['payments.php', 'Party Payments', 'payments.view', null, null],
    ]],
    ['group', 'items', 'box', 'Items', [
        ['items.php', 'All Items', 'items.view', 'items.php?action=new', 'items.add'],
        ['items_import.php', 'Import Excel/CSV', 'items.add', null, null],
        ['stock.php', 'Stock Levels', 'stock.view', null, null],
        ['barcode_labels.php', 'Barcode Label Printing', 'items.view', null, null],
        ['ai_enrich.php', 'AI Auto-Fill (Photo/Desc)', 'items.edit', null, null],
        ['batches.php', 'Batch / Expiry Tracking', 'batches.view', 'batches.php?action=new', 'batches.add'],
    ]],
    ['group', 'sale', 'receipt', 'Sale', [
        ['sales.php', 'Sale Invoices', 'sales.view', 'sales.php?action=new', 'sales.add'],
        ['estimates.php', 'Estimate / Quotation', 'estimates.view', 'estimates.php?action=new', 'estimates.add'],
        ['sales_return.php', 'Sale Return', 'sales_return.view', 'sales_return.php?action=new', 'sales_return.add'],
        ['challans.php', 'Delivery Challan', 'challans.view', 'challans.php?action=new', 'challans.add'],
    ]],
    ['group', 'purchase', 'box', 'Purchase', [
        ['purchases.php', 'Purchase Bills', 'purchases.view', 'purchases.php?action=new', 'purchases.add'],
        ['purchase_scan.php', 'Scan Bill (OCR)', 'purchases.add', null, null],
        ['purchase_return.php', 'Purchase Return', 'purchase_return.view', 'purchase_return.php?action=new', 'purchase_return.add'],
    ]],
    ['link', 'expenses.php', 'wallet', 'Expenses', 'expenses.view'],
    ['group', 'cashbank', 'card', 'Cash & Bank', [
        ['cash_bank.php', 'Cash & Bank Overview', 'payments.view', null, null],
        ['bank_accounts.php', 'Bank Accounts', 'settings.view', 'bank_accounts.php', 'settings.edit'],
        ['payment_methods.php', 'Payment Methods', 'settings.view', 'payment_methods.php', 'settings.edit'],
    ]],
    ['group', 'accounting', 'book', 'Accounting', [
        ['accounts.php', 'Chart of Accounts', 'accounting.view', 'accounts.php', 'accounting.edit'],
        ['journal.php', 'Journal Entries', 'accounting.view', 'journal.php?action=new', 'accounting.edit'],
        ['bank_reconcile.php', 'Bank Reconciliation', 'accounting.view', null, null],
        ['reports.php?r=general_ledger', 'General Ledger', 'reports.accounting', null, null],
        ['reports.php?r=trial_balance', 'Trial Balance', 'reports.accounting', null, null],
        ['reports.php?r=balance_sheet', 'Balance Sheet', 'reports.accounting', null, null],
        ['reports.php?r=profit_loss', 'Profit & Loss', 'reports.accounting', null, null],
    ]],
    ['group', 'godown', 'archive', 'Stock / Godown', [
        ['handover.php', 'Handover / Transfer', 'handover.view', 'handover.php?action=new', 'handover.add'],
        ['transfers.php', 'Warehouse Transfers', 'handover.view', 'handover.php?action=new&type=transfer', 'handover.add'],
        ['my_stock.php', 'My Stock', null, null, null],
        ['stock.php', 'Location Stock', 'stock.view', null, null],
        ['stock_audit.php', 'Stock Audit / Cycle Counting', 'stock_audit.view', 'stock_audit.php?action=new', 'stock_audit.add'],
        ['bins.php', 'Bin / Rack Locations', 'bins.view', null, null],
        ['reservations.php', 'Stock Reservations', 'reservations.view', null, null],
    ]],
    ['group', 'service', 'tool', 'Repair & Service', [
        ['my_jobs.php', 'My Jobs (Technician)', null, null, null],
        ['repairs.php', 'Repair Jobs', 'repairs.view', 'repairs.php?action=new', 'repairs.add'],
        ['tasks.php', 'Field Tasks', 'tasks.view', 'tasks.php?action=new', 'tasks.add'],
        ['warranty.php', 'Warranty Claims', 'warranty.view', 'warranty.php?action=new', 'warranty.add'],
        ['amc.php', 'AMC / Recurring Billing', 'amc.view', 'amc.php?action=new', 'amc.add'],
        ['amc.php?action=calendar', 'AMC / Visit Calendar', 'amc.view', null, null],
        ['sites.php', 'Customer Sites / DVR-NVR Vault', 'sites.view', 'sites.php?action=new', 'sites.add'],
    ]],
    ['group', 'crm', 'handshake', 'Leads & CRM', [
        ['leads.php', 'Leads', 'leads.view', 'leads.php?action=new', 'leads.add'],
        ['tickets.php', 'Complaints / Tickets', 'tickets.view', 'tickets.php?action=new', 'tickets.add'],
        ['follow_ups.php', 'Follow-ups', 'followups.view', 'follow_ups.php?action=new', 'followups.add'],
        ['reminders.php', 'Reminders', 'reminders.view', 'reminders.php?action=new', 'reminders.add'],
    ]],
    ['group', 'reports', 'bar-chart', 'Reports', [
        ['reports.php', 'All Reports', 'reports.view', null, null],
        ['reports.php?r=custom', 'Custom Report Builder', 'reports.builder', null, null],
        ['report_schedules.php', 'Scheduled Reports', 'report_schedules.view', 'report_schedules.php?action=new', 'report_schedules.add'],
    ]],
    ['group', 'store', 'globe', 'My Online Store', [
        ['catalog.php', 'View Website', null, null, null],
        ['web_orders.php', 'Website Orders', 'weborders.view', null, null],
        ['items.php', 'Website Items (ON/OFF)', 'items.view', null, null],
        ['referrals.php', 'Referral Partners (Refer & Earn)', 'referrals.view', null, null],
        ['web_customers.php', 'Dealer Logins (B2B Price)', 'webcustomers.view', null, null],
    ]],
    ['group', 'integrations', 'link', 'API & Integrations', [
        ['my_account.php?tab=api', 'API Tokens', null, null, null],
        ['webhooks.php', 'Webhooks', 'webhooks.view', 'webhooks.php', 'webhooks.add'],
    ]],
    ['group', 'admin', 'briefcase', 'Staff & Company', [
        ['users.php', 'Staff Users', 'users.view', 'users.php?action=new', 'users.add'],
        ['roles.php', 'Roles / Permissions', 'roles.view', 'roles.php?action=new', 'roles.add'],
        ['locations.php', 'Locations', 'locations.view', null, null],
        ['companies.php', 'Companies / Firms', 'companies.view', null, null],
    ]],
    ['link', 'settings.php', 'gear', 'Settings', 'settings.view'],
];

// Notification bell: a small real, aggregated count (not decorative) - low
// stock items + this staff member's pending handovers + overdue unpaid
// bills, each gated by the same permission that page itself requires.
$_notifItems = [];
if ($u) {
    if (can('stock.view')) {
        $_lowStock = (int)val("SELECT COUNT(*) FROM (SELECT i.id, i.min_stock, COALESCE(SUM(s.qty),0) q FROM items i LEFT JOIN stock s ON s.item_id = i.id
                               WHERE i.is_active = 1 AND i.item_type <> 'service' AND i.min_stock > 0 GROUP BY i.id, i.min_stock HAVING q < i.min_stock) x");
        if ($_lowStock) $_notifItems[] = ['label' => $_lowStock . ' item(s) low on stock', 'href' => 'reports.php?r=low'];
    }
    $_myHandoversN = (int)val("SELECT COUNT(*) FROM handovers WHERE staff_id = ? AND status = 'pending' AND type = 'issue'", [$u['id']]);
    if ($_myHandoversN) $_notifItems[] = ['label' => $_myHandoversN . ' handover(s) pending your accept', 'href' => 'my_stock.php'];
    if (can('payments.view')) {
        $_overdue = (int)val("SELECT COUNT(*) FROM sales WHERE status <> 'paid' AND is_cancelled = 0 AND due_date IS NOT NULL AND due_date < CURDATE()");
        if ($_overdue) $_notifItems[] = ['label' => $_overdue . ' bill(s) overdue', 'href' => 'payments.php'];
    }
}
$_notifCount = count($_notifItems);

$_navCur = basename($_SERVER['SCRIPT_NAME']);

function nav_visible_items($items) {
    $out = [];
    foreach ($items as $it) {
        if ($it[2] === null || can($it[2])) $out[] = $it;
    }
    return $out;
}

// Theme: no row in user_preferences = "auto" = follow the OS
// (prefers-color-scheme, handled purely in CSS) - the data-theme attribute
// is only emitted for an explicit light/dark choice made via the avatar
// menu toggle, so it can override the OS setting in either direction.
$_theme = $u ? user_pref($u['id'], 'theme', 'auto') : 'auto';
?><!DOCTYPE html>
<html lang="en"<?= $_theme !== 'auto' ? ' data-theme="' . e($_theme) . '"' : '' ?>>
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
<link rel="stylesheet" href="assets/style.css?v=9">
</head>
<body>
<?php if ($u): ?>
<header class="topbar">
  <button class="menu-btn" id="menuBtn" aria-label="Menu"><?= icon('menu', 22) ?></button>
  <div class="topbar-title"><?= e($page_title) ?></div>
  <div class="topbar-search no-print">
    <?= icon('search', 16) ?>
    <input type="text" id="navSearch" placeholder="Search menu..." autocomplete="off">
    <div class="topbar-search-results" id="navSearchResults"></div>
  </div>
  <div class="topbar-user">
    <div class="topbar-bell" id="bellBtn">
      <?= icon('bell', 20) ?>
      <?php if ($_notifCount): ?><span class="bell-badge"><?= $_notifCount > 9 ? '9+' : $_notifCount ?></span><?php endif; ?>
      <div class="bell-panel" id="bellPanel">
        <?php if ($_notifItems): foreach ($_notifItems as $_ni2): ?>
          <a href="<?= e($_ni2['href']) ?>"><?= e($_ni2['label']) ?></a>
        <?php endforeach; else: ?>
          <div class="bell-empty">No notifications</div>
        <?php endif; ?>
      </div>
    </div>
    <div class="topbar-avatar" id="avatarBtn" title="<?= e($u['name']) ?>">
      <?= e(mb_strtoupper(mb_substr($u['name'], 0, 1))) ?>
      <div class="avatar-panel" id="avatarPanel">
        <div class="avatar-panel-name"><?= e($u['name']) ?><span><?= e($u['role_name']) ?> · <?= e($u['location_name']) ?></span></div>
        <a href="my_account.php"><?= icon('gear', 16) ?> My Account</a>
        <button type="button" id="themeToggleBtn" data-theme="<?= e($_theme) ?>"><?= icon('moon', 16) ?> <span id="themeToggleLabel"><?= $_theme === 'dark' ? 'Light Mode' : ($_theme === 'light' ? 'Auto Theme' : 'Dark Mode') ?></span></button>
        <a href="logout.php" onclick="return confirm('Logout?')"><?= icon('log-out', 16) ?> Logout</a>
      </div>
    </div>
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
  <div class="sidebar-search no-print">
    <?= icon('search', 16) ?>
    <input type="text" id="sidebarSearch" placeholder="Search parties, items, bills..." autocomplete="off">
  </div>
  <div class="sidebar-search-results" id="sidebarSearchResults"></div>
  <div class="sidebar-links">
  <?php foreach ($_navMenu as $_nm): ?>
    <?php if ($_nm[0] === 'link'):
        if ($_nm[4] !== null && !can($_nm[4])) continue; ?>
      <a href="<?= $_nm[1] ?>" class="nav-link <?= $_navCur === $_nm[1] ? 'active' : '' ?>"><span class="nav-ico"><?= icon($_nm[2]) ?></span><?= $_nm[3] ?></a>
    <?php else:
        $_navItems = nav_visible_items($_nm[4]);
        if (!$_navItems) continue;
        $_navOpen = false;
        foreach ($_navItems as $_ni) if (basename(parse_url($_ni[0], PHP_URL_PATH)) === $_navCur) $_navOpen = true;
    ?>
      <div class="nav-group <?= $_navOpen ? 'open' : '' ?>" data-group="<?= $_nm[1] ?>">
        <button type="button" class="nav-group-head <?= $_navOpen ? 'active' : '' ?>">
          <span class="nav-ico"><?= icon($_nm[2]) ?></span><?= $_nm[3] ?><span class="nav-chev"><?= icon('chevron-right', 14) ?></span>
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
  <h3>Quick Actions</h3>
  <div class="sheet-grid">
    <?php
    $_navQuick = [
        ['sales.php?action=new', 'receipt', 'New Bill', 'sales.add'],
        ['estimates.php?action=new', 'receipt', 'Estimate', 'estimates.add'],
        ['purchases.php?action=new', 'box', 'Purchase', 'purchases.add'],
        ['payments.php?action=new&dir=in', 'arrow-down', 'Payment In', 'payments.add'],
        ['payments.php?action=new&dir=out', 'arrow-up', 'Payment Out', 'payments.add'],
        ['expenses.php', 'wallet', 'Expense', 'expenses.add'],
        ['repairs.php?action=new', 'tool', 'Repair Job', 'repairs.add'],
        ['tasks.php?action=new', 'tool', 'Task', 'tasks.add'],
        ['handover.php?action=new', 'handshake', 'Handover', 'handover.add'],
        ['challans.php?action=new', 'truck', 'Challan', 'challans.add'],
        ['parties.php?action=new', 'users', 'Party', 'parties.add'],
        ['items.php?action=new', 'box', 'Item', 'items.add'],
        ['sales_return.php?action=new', 'return', 'Sale Return', 'sales_return.add'],
        ['leads.php?action=new', 'handshake', 'Lead', 'leads.add'],
        ['tickets.php?action=new', 'bell', 'Ticket', 'tickets.add'],
        ['follow_ups.php?action=new', 'archive', 'Follow-up', 'followups.add'],
    ];
    foreach ($_navQuick as $_nq): if (!can($_nq[3])) continue; ?>
    <a href="<?= $_nq[0] ?>" class="sheet-item"><span><?= icon($_nq[1], 24) ?></span><?= $_nq[2] ?></a>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>
<script>var CSRF_TOKEN = <?= json_encode(csrf_token()) ?>;</script>
<script src="assets/app.js?v=9"></script>
<?php unset($_navMenu, $_navItems, $_navQuick, $_nm, $_ni, $_nq, $_navOpen); ?>
<main class="content<?= $u ? '' : ' content-full' ?>">
<?php if ($u && !empty($_SESSION['impersonator_id'])): ?>
<div class="impersonate-bar">👁️ Viewing as <?= e($u['name']) ?> (<?= e($u['role_name']) ?>)
  <form method="post" action="users.php" style="display:inline">
    <?= csrf_field() ?><input type="hidden" name="do" value="stop_impersonate">
    <button type="submit" class="btn btn-sm">Back to Admin</button>
  </form>
</div>
<?php endif; ?>
<?php foreach (get_flashes() as $f): ?>
  <div class="flash flash-<?= e($f['type']) ?>"><?= e($f['msg']) ?></div>
<?php endforeach; ?>
