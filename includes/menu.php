<?php
// ---------------------------------------------------------------------------
// THE MENU — one definition, used by every face of this shop.
//
// The website draws its sidebar from here (includes/header.php) and the phone
// app draws its "બધું" screen from here too (api.php?r=menu). Add a screen in
// one place and it appears in both; there is no second list to forget.
//
// Shape:  ['link',  href, icon, label, perm]
//         ['group', id, icon, label, items[]]
//             item = [href, label, perm, plus_href, plus_perm]
//         perm === null means "anybody logged in".
// ---------------------------------------------------------------------------
function nav_menu() {
// Menu: link => [href, icon, label, perm]
// group => [id, icon, label, items[]] ; item = [href, label, perm, plus_href, plus_perm]
return simple_filter_menu(plan_filter_menu([
    ['link', 'index.php', 'home', 'Dashboard', 'dashboard.view'],
    ['group', 'parties', 'users', 'Parties', [
        ['parties.php', 'All Parties', 'parties.view', 'parties.php?action=new', 'parties.add'],
        ['payments.php', 'Party Payments', 'payments.view', null, null],
        ['collection.php', '📮 Collection Queue', 'payments.view', null, null],
        ['voice_calls.php', '📞 Calls (in & out)', 'payments.view', null, null],
        ['voice_setup.php', '⚙️ Call Setup', 'settings.view', null, null],
        ['voice_words.php', '🗣 What the Phone Says', 'settings.edit', null, null],
        ['campaigns.php', '📣 Campaigns', 'campaigns.view', 'campaigns.php?action=new', 'campaigns.add'],
    ]],
    ['group', 'items', 'box', 'Items', [
        ['items.php', 'All Items', 'items.view', 'items.php?action=new', 'items.add'],
        ['items_import.php', 'Import Excel/CSV', 'items.add', null, null],
        ['stock.php', 'Stock Levels', 'stock.view', null, null],
        ['barcode_labels.php', 'Barcode Label Printing', 'items.view', null, null],
        ['ai_enrich.php', 'AI Auto-Fill (Photo/Desc)', 'items.edit', null, null],
        ['ai_categorize.php', 'AI Categories (Auto-sort)', 'items.edit', null, null],
        ['batches.php', 'Batch / Expiry Tracking', 'batches.view', 'batches.php?action=new', 'batches.add'],
    ]],
    ['group', 'sale', 'receipt', 'Sale', [
        ['sales.php', 'Sale Invoices', 'sales.view', 'sales.php?action=new', 'sales.add'],
        ['pos.php', '🖐️ Counter (tablet)', 'sales.add', null, null],
        ...(function_exists('biz_on') && biz_on('biz_tables') ? [['tables.php', '🍽️ Tables & kitchen', 'sales.add', null, null]] : []),
        ...(function_exists('biz_on') && biz_on('biz_appointments') ? [['appointments.php', '📅 Appointments', 'sales.view', 'appointments.php?action=new', 'sales.add']] : []),
        ['assistant.php', '🧑‍💼 What the customer wants (Sales Assistant)', 'items.view', null, null],
        ['estimates.php', 'Estimate / Quotation', 'estimates.view', 'estimates.php?action=new', 'estimates.add'],
        ['sales_return.php', 'Sale Return', 'sales_return.view', 'sales_return.php?action=new', 'sales_return.add'],
        ['challans.php', 'Delivery Challan', 'challans.view', 'challans.php?action=new', 'challans.add'],
    ]],
    ['group', 'purchase', 'box', 'Purchase', [
        ['purchases.php', 'Purchase Bills', 'purchases.view', 'purchases.php?action=new', 'purchases.add'],
        ['purchase_intel.php', '🛒 What to buy (Purchase Intelligence)', 'purchases.view', null, null],
        ['purchase_scan.php', 'Scan Bill (OCR)', 'purchases.add', null, null],
        ['purchase_return.php', 'Purchase Return', 'purchase_return.view', 'purchase_return.php?action=new', 'purchase_return.add'],
    ]],
    ['link', 'expenses.php', 'wallet', 'Expenses', 'expenses.view'],
    ['group', 'cashbank', 'card', 'Cash & Bank', [
        ['cash_bank.php', 'Cash & Bank Overview', null, null, null],
        ['day_close.php', '🌙 Day closing', 'dayclose.view', null, null],
        ['cheques.php', '🧾 Cheque register', 'cheques.view', null, null],
        ['my_collections.php', '🏃 Today collections (mobile)', 'payments.view', null, null],
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
        ['stock_audit.php', 'Stock Audit / Cycle Counting', 'stock_audit.view', 'stock_audit.php?action=new', 'stock_audit.add'],
    ]],
    ['group', 'service', 'tool', 'Repair & Service', [
        ['my_jobs.php', 'My Jobs (Technician)', null, null, null],
        ['repairs.php', 'Repair Jobs', 'repairs.view', 'repairs.php?action=new', 'repairs.add'],
        ['tasks.php', 'Field Tasks', 'tasks.view', 'tasks.php?action=new', 'tasks.add'],
        ['warranty.php', 'Warranty Claims', 'warranty.view', 'warranty.php?action=new', 'warranty.add'],
        ['amc.php', 'AMC / Recurring Billing', 'amc.view', 'amc.php?action=new', 'amc.add'],
        ['amc.php?action=calendar', 'AMC / Visit Calendar', 'amc.view', null, null],
        ['net_connections.php', 'Internet Connections', 'netconn.view', null, null],
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
        ['market.php', '📊 Market Intelligence', 'reports.view', null, null],
        ['forecast.php', '🔮 What lies ahead (Forecast)', 'reports.view', null, null],
        ['reports.php?r=custom', 'Custom Report Builder', 'reports.builder', null, null],
        ['report_schedules.php', 'Scheduled Reports', 'report_schedules.view', 'report_schedules.php?action=new', 'report_schedules.add'],
        ['cost_analytics.php', 'Cost Analytics (AI + WhatsApp)', '*', null, null],
    ]],
    ['group', 'store', 'globe', 'My Online Store', [
        ['catalog.php', 'View Website', null, null, null],
        ['wa_inbox.php', 'WhatsApp Inbox', '*', null, null],
        ['web_orders.php', 'Website Orders', 'weborders.view', null, null],
        ['reviews.php', 'Product Reviews', 'items.view', null, null],
        ['items.php', 'Website Items (ON/OFF)', 'items.view', null, null],
        ['referrals.php', 'Referral Partners (Refer & Earn)', 'referrals.view', null, null],
        ['web_customers.php', 'Dealer Logins (B2B Price)', 'webcustomers.view', null, null],
    ]],
    ['group', 'integrations', 'link', 'API & Integrations', [
        ['my_account.php?tab=api', 'API Tokens', null, null, null],
        ['webhooks.php', 'Webhooks', 'webhooks.view', 'webhooks.php', 'webhooks.add'],
    ]],
    ['group', 'team', 'users', 'Team', [
        ['attendance.php', '🕘 Attendance', null, null, null],
        ['leaves.php', '🏖️ Leave', null, null, null],
        ['chat.php', '💬 Staff chat', null, null, null],
        ['payroll.php', '💵 Payroll', 'hr.payroll', null, null],
        ['team.php', '👥 Shifts, performance, documents', 'hr.view', null, null],
    ]],
    ['group', 'admin', 'briefcase', 'Staff & Company', [
        ['users.php', 'Staff Users', 'users.view', 'users.php?action=new', 'users.add'],
        ['approvals.php', 'Bill Edit Approvals', '*', null, null],
        ['cron_manager.php', 'Cron Manager (Auto Tasks)', '*', null, null],
        ['scaling.php', '📦 What if the business grows? (Scaling)', '*', null, null],
        ['roles.php', 'Roles / Permissions', 'roles.view', 'roles.php?action=new', 'roles.add'],
        ['locations.php', 'Locations', 'locations.view', null, null],
        ['companies.php', 'Companies / Firms', 'companies.view', null, null],
        // the platform: the owner sees every shop; a shop sees its own plan
        ...(function_exists('tenant_active') && tenant_active()
            ? [['my_plan.php', '💳 My plan & usage', '*', null, null]]
            : [['platform.php', '🌐 Shops on this software', '*', null, null]]),
    ]],
    ['link', 'settings.php', 'gear', 'Settings', 'settings.view'],
    ['link', 'help.php', 'book', 'Help', null],
]));
}

/** Another shop sees only what its plan opens. */
/** Simple menu (My Account → Display): only the everyday screens, for someone who finds the full list too much. */
function simple_filter_menu(array $menu) {
    if (!function_exists('ui_prefs') || !ui_prefs()['simple']) return $menu;
    $keep = ['index.php', 'sales.php', 'parties.php', 'payments.php', 'items.php', 'stock.php', 'expenses.php', 'cash_bank.php',
             'purchases.php', 'reports.php', 'tables.php', 'appointments.php', 'repairs.php', 'help.php', 'pos.php', 'attendance.php', 'leaves.php', 'chat.php'];
    $out = [];
    foreach ($menu as $m) {
        if ($m[0] === 'link') { if (in_array(strtok($m[1], '?'), $keep, true)) $out[] = $m; continue; }
        $m[4] = array_values(array_filter($m[4], fn($it) => in_array(strtok($it[0], '?'), $keep, true)));
        if ($m[4]) $out[] = $m;
    }
    return $out;
}

function plan_filter_menu(array $menu) {
    if (!function_exists('tenant_active') || !tenant_active() || !function_exists('script_feature')) return $menu;
    $ok = function ($href) { $f = script_feature(strtok((string)$href, '?')); return $f === '' || plan_allows($f); };
    $out = [];
    foreach ($menu as $m) {
        if ($m[0] === 'link') { if ($ok($m[1])) $out[] = $m; continue; }
        $m[4] = array_values(array_filter($m[4], fn($it) => $ok($it[0])));
        if ($m[4]) $out[] = $m;
    }
    return $out;
}
