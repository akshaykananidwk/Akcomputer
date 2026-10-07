<?php
/**
 * What a plan can switch on, and the screens each one is. A screen not
 * named here (login, dashboard, settings, my account...) is open on every
 * plan. "*" in a plan opens everything.
 */
function platform_features() {
    return [
        'billing'      => ['🧾 Billing', ['sales', 'sale_view', 'sale_pdf', 'estimates', 'sales_return', 'challans', 'pay', 'day_close']],
        'parties'      => ['👥 Parties', ['parties', 'customer', 'cheques']],
        'items'        => ['📦 Items', ['items', 'item_view', 'items_import', 'barcode', 'barcode_labels', 'price']],
        'payments'     => ['💰 Payments & cash', ['payments', 'cash_bank', 'bank_accounts', 'payment_methods', 'collection', 'my_collections']],
        'expenses'     => ['💸 Expenses', ['expenses']],
        'reports_basic'=> ['📊 Basic reports', ['reports', 'report_pdf', 'report_xlsx']],
        'stock'        => ['🏬 Stock & godown', ['stock', 'handover', 'transfers', 'my_stock', 'stock_audit', 'batches', 'serial_fix']],
        'purchase'     => ['🛒 Purchase', ['purchases', 'purchase_view', 'purchase_return', 'purchase_scan', 'purchase_intel']],
        'whatsapp'     => ['💬 WhatsApp & campaigns', ['wa_inbox', 'campaigns']],
        'repairs'      => ['🛠️ Repairs, tasks, AMC', ['repairs', 'tasks', 'warranty', 'amc', 'my_jobs', 'service_report', 'net_connections', 'sites']],
        'reports'      => ['📈 Advanced reports & AI', ['market', 'forecast', 'report_schedules', 'cost_analytics', 'scaling', 'assistant', 'ai_categorize', 'ai_enrich']],
        'crm'          => ['🤝 Leads & CRM', ['leads', 'tickets', 'follow_ups', 'reminders', 'feedback']],
        'accounting'   => ['📒 Accounting', ['accounts', 'journal', 'bank_reconcile', 'tally_export']],
        'online_store' => ['🌐 Online store', ['web_orders', 'reviews', 'referrals', 'web_customers']],
        'voice'        => ['📞 Calls (IVR)', ['voice_calls', 'voice_setup', 'voice_words']],
        'api'          => ['🔗 API & webhooks', ['webhooks']],
        'locations'    => ['📍 More than one location', ['locations']],
    ];
}

