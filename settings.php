<?php
// Settings: app, WhatsApp API, credit terms, bill design, backup, software updates
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/version.php';
require_once __DIR__ . '/includes/gh_updater.php';
require_perm('settings.view');

$cat = get('cat', '');

// ---- GitHub update: save repo settings / check / apply ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'gh_save') {
    require_perm('settings.edit');
    gh_save_settings(post('gh_repo'), post('gh_branch'), post('gh_token'));
    flash('GitHub update settings saved.');
    redirect('settings.php?cat=backup');
}
$ghCheck = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'gh_check') {
    require_perm('settings.edit');
    $ghCheck = gh_check_update();
    $cat = 'backup';
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'gh_apply') {
    require_perm('settings.edit');
    list($ok, $msg) = gh_apply_update(post('sha'));
    flash($msg, $ok ? 'success' : 'error');
    redirect('settings.php?cat=backup');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'save_general') {
    require_perm('settings.edit');
    set_setting('app_name', post('app_name'));
    set_setting('default_tax', post('default_tax'));
    set_setting('login_otp', post('login_otp') ? '1' : '0');
    set_setting('allow_negative_stock', post('allow_negative_stock') ? '1' : '0');
    log_activity('settings_save');
    flash('Settings saved.');
    redirect('settings.php?cat=general');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'save_whatsapp') {
    require_perm('settings.edit');
    foreach (['wa_api_url', 'wa_session_id', 'wa_api_key', 'wa_shop_number'] as $k) set_setting($k, post($k));
    set_setting('wa_bot_enabled', post('wa_bot_enabled') ? '1' : '0');
    set_setting('wa_catalog_enabled', post('wa_catalog_enabled') ? '1' : '0');
    if (post('wa_bot_ai_monthly_cap') !== '') set_setting('wa_bot_ai_monthly_cap', (string)max(0, (int)post('wa_bot_ai_monthly_cap')));
    log_activity('settings_save');
    flash('WhatsApp settings saved.');
    redirect('settings.php?cat=whatsapp');
}

// ---- WhatsApp bot: keywords, languages, menu, auto-replies, statement ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'save_wabot') {
    require_perm('settings.edit');
    set_setting('wa_bot_keywords', trim(post('wa_bot_keywords')));
    $langs = array_values(array_intersect((array)post('wa_langs', []), ['en', 'gu', 'hi']));
    set_setting('wa_langs', implode(',', $langs ?: ['en']));
    set_setting('wa_lang_default', in_array(post('wa_lang_default'), $langs ?: ['en'], true) ? post('wa_lang_default') : ($langs[0] ?? 'en'));
    set_setting('wa_bot_fallback', post('wa_bot_fallback') ? '1' : '0');
    set_setting('wa_auto_replies', trim(post('wa_auto_replies')));
    set_setting('wa_stmt_entries', (string)max(3, min(25, (int)post('wa_stmt_entries', 10))));
    // menu management: unchecked rows are stored as hidden
    require_once __DIR__ . '/includes/wa_bot.php';
    $on = (array)post('wa_menu_on', []);
    $off = [];
    foreach (wa_portal_menu_defs() as $d) if (!in_array($d[0], $on, true)) $off[] = $d[0];
    set_setting('wa_menu_off', implode(',', $off));
    // welcome / fallback overrides per language (blank = built-in text)
    foreach (['en', 'gu', 'hi'] as $L) {
        set_setting('wa_txt_welcome_' . $L, trim(post('wa_txt_welcome_' . $L)));
        set_setting('wa_txt_fallback_' . $L, trim(post('wa_txt_fallback_' . $L)));
    }
    log_activity('settings_save', 'wa bot');
    flash('WhatsApp bot settings saved.');
    redirect('settings.php?cat=whatsapp');
}

// ---- official Meta (Facebook) Cloud API: save + connect & auto-sync templates ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'save_meta_wa') {
    require_perm('settings.edit');
    foreach (['meta_wa_token', 'meta_wa_phone_id', 'meta_wa_waba_id', 'meta_catalog_id'] as $k) set_setting($k, trim(post($k)));
    set_setting('wa_provider_order', post('wa_provider_order') === 'meta_first' ? 'meta_first' : 'thirdparty_first');
    log_activity('settings_save', 'meta whatsapp');
    flash('Meta WhatsApp settings saved.');
    redirect('settings.php?cat=whatsapp');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'meta_full_sync') {
    require_perm('settings.edit');
    require_once __DIR__ . '/includes/wa_meta.php';
    if (!meta_wa_configured()) { flash('પહેલા Meta Token + Phone ID Save કરો.', 'error'); redirect('settings.php?cat=whatsapp'); }
    $rep = meta_wa_full_sync();
    $bad = count(array_filter($rep, fn($x) => $x['status'] === 'fail'));
    $warn = count(array_filter($rep, fn($x) => $x['status'] === 'warn'));
    flash($bad ? "Sync પૂરું — $bad વસ્તુ ધ્યાન માંગે છે (નીચે લાલ લાઈન + ઉકેલ જુઓ)." : ($warn ? "Sync પૂરું ✔ — $warn નાની નોંધ નીચે જુઓ." : 'બધું Sync થઈ ગયું ✔ બધું લીલું!'), $bad ? 'error' : 'success');
    log_activity('meta_full_sync', json_encode(array_map(fn($x) => $x['status'], $rep)));
    redirect('settings.php?cat=whatsapp');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'meta_wa_sync') {
    require_perm('settings.edit');
    require_once __DIR__ . '/includes/wa_meta.php';
    $res = meta_wa_sync_templates();
    if (isset($res['_error'])) flash('Meta connect failed: ' . $res['_error'], 'error');
    else {
        $n = count($res);
        $appr = count(array_filter($res, fn($t) => $t['status'] === 'APPROVED'));
        flash("Meta સાથે કનેક્ટ થયું ✔ $n ટેમ્પ્લેટ સિંક થયા ($appr Approved). સ્ટેટસ નીચે કાર્ડમાં દેખાય છે.");
        log_activity('meta_wa_sync', json_encode(array_map(fn($t) => $t['status'], $res)));
    }
    redirect('settings.php?cat=whatsapp');
}

// ---- WhatsApp message templates ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'templates') {
    require_perm('settings.edit');
    foreach (array_keys(wa_template_defaults()) as $k) {
        set_setting('wa_tpl_' . $k, post('tpl_' . $k));
    }
    log_activity('wa_templates_save');
    flash('Message templates saved.');
    redirect('settings.php?cat=whatsapp');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'checklist_add') {
    require_perm('settings.edit');
    $label = trim(post('label'));
    if ($label) {
        $n = (int)val('SELECT COALESCE(MAX(sort_order),0) FROM service_checklist_items') + 10;
        q('INSERT INTO service_checklist_items (label, sort_order) VALUES (?, ?)', [$label, $n]);
        flash('Checklist item added.');
    }
    redirect('settings.php?cat=service');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'checklist_toggle') {
    require_perm('settings.edit');
    $ci = row('SELECT * FROM service_checklist_items WHERE id = ?', [(int)post('id')]);
    if ($ci) {
        q('UPDATE service_checklist_items SET is_active = ? WHERE id = ?', [$ci['is_active'] ? 0 : 1, $ci['id']]);
        flash($ci['is_active'] ? 'Item deactivated.' : 'Item activated.');
    }
    redirect('settings.php?cat=service');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'save_pwd_policy') {
    require_perm('settings.edit');
    set_setting('pwd_min_length', (string)max(4, (int)post('pwd_min_length')));
    set_setting('pwd_require_number', post('pwd_require_number') ? '1' : '0');
    set_setting('pwd_require_mixed_case', post('pwd_require_mixed_case') ? '1' : '0');
    log_activity('settings_save', 'password policy');
    flash('Password policy saved.');
    redirect('settings.php?cat=security');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'save_autologout') {
    require_perm('settings.edit');
    set_setting('auto_logout_minutes', (string)max(0, (int)post('auto_logout_minutes')));
    log_activity('settings_save', 'auto-logout');
    flash('Auto-logout setting saved.');
    redirect('settings.php?cat=security');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'save_login_limits') {
    require_perm('settings.edit');
    set_setting('login_max_attempts', (string)max(0, (int)post('login_max_attempts')));
    set_setting('login_lockout_minutes', (string)max(1, (int)post('login_lockout_minutes')));
    log_activity('settings_save', 'login limits');
    flash('Login attempt limits saved.');
    redirect('settings.php?cat=security');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'save_ip_whitelist') {
    require_perm('settings.edit');
    set_setting('ip_whitelist', trim(post('ip_whitelist')));
    log_activity('settings_save', 'ip whitelist');
    flash('IP restriction saved.');
    redirect('settings.php?cat=security');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'save_inventory') {
    require_perm('settings.edit');
    $method = post('costing_method');
    if (in_array($method, ['current', 'fifo', 'weighted_avg'], true)) set_setting('costing_method', $method);
    set_setting('dead_stock_days', (string)max(1, (int)post('dead_stock_days')));
    flash('Inventory settings saved.');
    redirect('settings.php?cat=inventory');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'save_transaction') {
    require_perm('settings.edit');
    set_setting('cash_sale_default', post('cash_sale_default') ? '1' : '0');
    set_setting('round_off_default', post('round_off_default') ? '1' : '0');
    set_setting('show_profit_billing', post('show_profit_billing') ? '1' : '0');
    set_setting('show_purchase_price_billing', post('show_purchase_price_billing') ? '1' : '0');
    set_setting('add_time_transactions', post('add_time_transactions') ? '1' : '0');
    set_setting('default_margin_pct', (string)max(0, (float)post('default_margin_pct')));
    log_activity('settings_save');
    flash('Transaction settings saved.');
    redirect('settings.php?cat=transaction');
}

// One-time sweep: lift EVERY existing product's selling price to at least
// purchase + margin (item's own margin %, else the default) - so the
// minimum-profit rule covers old items too, not just future purchases.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'apply_margin_all') {
    require_perm('settings.edit');
    $fixed = 0;
    foreach (all("SELECT id, selling_price FROM items WHERE is_active = 1 AND item_type <> 'service' AND purchase_price > 0") as $mi) {
        enforce_min_margin($mi['id']);
        if ((float)val('SELECT selling_price FROM items WHERE id = ?', [$mi['id']]) > (float)$mi['selling_price'] + 0.005) $fixed++;
    }
    log_activity('margin_apply_all', "raised=$fixed");
    flash($fixed ? "$fixed આઇટમના વેચાણ-ભાવ વધારીને મિનિમમ નફા સુધી લાવ્યા. (કોઈનો ભાવ ઘટાડ્યો નથી)" : 'બધી આઇટમ પહેલેથી મિનિમમ નફા ઉપર જ છે ✔');
    redirect('settings.php?cat=transaction');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'save_invoice') {
    require_perm('settings.edit');
    foreach (['google_review_link', 'razorpay_key_id', 'razorpay_key_secret', 'razorpay_webhook_secret', 'ocr_api_key', 'gemini_api_key', 'gemini_api_key_paid', 'gcs_api_key', 'gcs_cx'] as $k) set_setting($k, post($k));
    log_activity('settings_save');
    flash('Invoice settings saved.');
    redirect('settings.php?cat=invoice');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'bill_design') {
    require_perm('settings.edit');
    set_setting('invoice_design', post('invoice_design') === '2' ? '2' : '1');
    log_activity('settings_bill_design', post('invoice_design'));
    flash('Bill design changed ✔ — open any bill to see it.');
    redirect('settings.php?cat=invoice');
}

if (setting('cron_key', '') === '') set_setting('cron_key', bin2hex(random_bytes(12)));
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'reminder_gap') {
    require_perm('settings.edit');
    set_setting('reminder_gap_days', max(1, (int)post('gap')));
    set_setting('reminder_hour', min(23, max(0, (int)post('hour'))));
    flash('Reminder settings saved.');
    redirect('settings.php?cat=reminders');
}

// ---- Telegram management bot: save token / auto-register the webhook ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'tg_save') {
    require_perm('settings.edit');
    set_setting('tg_bot_token', trim(post('tg_bot_token')));
    if (setting('tg_webhook_key', '') === '') set_setting('tg_webhook_key', bin2hex(random_bytes(16)));
    // one tap: tell Telegram where to deliver messages
    if (trim(post('tg_bot_token')) !== '' && post('set_webhook')) {
        $whUrl = base_url('telegram_webhook.php?key=' . setting('tg_webhook_key'));
        $ch = curl_init('https://api.telegram.org/bot' . trim(post('tg_bot_token')) . '/setWebhook');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 15,
            CURLOPT_POSTFIELDS => http_build_query(['url' => $whUrl]), CURLOPT_SSL_VERIFYPEER => true]);
        $r = json_decode((string)curl_exec($ch), true);
        curl_close($ch);
        flash(($r['ok'] ?? false) ? 'Telegram bot connected ✔ — હવે સ્ટાફ /link કોડથી જોડાય.' : ('Webhook set failed: ' . ($r['description'] ?? 'check the token')), ($r['ok'] ?? false) ? 'success' : 'error');
    } else {
        flash('Telegram settings saved.');
    }
    redirect('settings.php?cat=whatsapp');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'tg_gencode') {
    require_perm('settings.edit');
    q('UPDATE users SET tg_link_code = ? WHERE id = ?', [substr(str_shuffle('ABCDEFGHJKMNPQRSTUVWXYZ23456789'), 0, 6), (int)post('uid')]);
    flash('Link code generated — tell it to that staff member.');
    redirect('settings.php?cat=whatsapp');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'tg_unlink') {
    require_perm('settings.edit');
    q('UPDATE users SET telegram_chat_id = NULL WHERE id = ?', [(int)post('uid')]);
    flash('Telegram unlinked for that user.');
    redirect('settings.php?cat=whatsapp');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'wa_test') {
    require_perm('settings.edit');
    $ok = send_whatsapp(post('test_mobile'), '✅ Test message from ' . setting('app_name', 'AK Computer') . ' billing system. WhatsApp API is working!');
    flash($ok ? 'Test message sent - check WhatsApp.' : ('Send failed. ' . whatsapp_last_error()), $ok ? 'success' : 'error');
    redirect('settings.php?cat=whatsapp');
}

// full database backup download (plain PHP SQL dump - works on shared
// hosting). An optional passphrase (POST only, never in the URL/history)
// AES-256-CBC-encrypts the whole dump before it's sent - decrypt it back
// via Settings > Backup & Updates > "Decrypt a backup file".
if (get('do') === 'backup' || post('do') === 'backup') {
    require_perm('settings.edit');
    $passphrase = post('passphrase');
    $sql = db_backup_sql();

    if ($passphrase !== '') {
        $salt = random_bytes(16);
        $iv = random_bytes(16);
        $key = hash_pbkdf2('sha256', $passphrase, $salt, 100000, 32, true);
        $cipher = openssl_encrypt($sql, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="backup_' . DB_NAME . '_' . date('Ymd_His') . '.sql.enc"');
        echo "AKENC1" . $salt . $iv . $cipher;
        log_activity('backup_download', 'encrypted');
    } else {
        header('Content-Type: application/sql');
        header('Content-Disposition: attachment; filename="backup_' . DB_NAME . '_' . date('Ymd_His') . '.sql"');
        echo $sql;
        log_activity('backup_download', 'plain');
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'save_backup_auto') {
    require_perm('settings.edit');
    set_setting('backup_passphrase', post('backup_passphrase'));
    set_setting('backup_telegram', post('backup_telegram') ? '1' : '0');
    set_setting('error_alerts', post('error_alerts') ? '1' : '0');
    log_activity('settings_save', 'auto backup + error alerts');
    flash(post('backup_passphrase') !== ''
        ? '✅ સેવ થયું — હવેથી રોજનું બેકઅપ લોક થઈને (encrypted) બનશે.'
        : '✅ સેવ થયું — પાસફ્રેઝ ખાલી છે, એટલે બેકઅપ ફાઈલ Telegram પર નહીં મોકલાય (સર્વર પર સેવ થશે).');
    redirect('settings.php?cat=backup');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'backup_decrypt') {
    require_perm('settings.edit');
    if (empty($_FILES['encfile']['tmp_name']) || $_FILES['encfile']['error'] !== UPLOAD_ERR_OK) {
        flash('Choose the .sql.enc file to decrypt.', 'error');
        redirect('settings.php?cat=backup');
    }
    $raw = file_get_contents($_FILES['encfile']['tmp_name']);
    if (substr($raw, 0, 6) !== 'AKENC1' || strlen($raw) < 38) {
        flash('Not a recognized encrypted backup file.', 'error');
        redirect('settings.php?cat=backup');
    }
    $salt = substr($raw, 6, 16);
    $iv = substr($raw, 22, 16);
    $cipher = substr($raw, 38);
    $key = hash_pbkdf2('sha256', post('passphrase'), $salt, 100000, 32, true);
    $plain = openssl_decrypt($cipher, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    if ($plain === false) {
        flash('Wrong passphrase (or a corrupted file).', 'error');
        redirect('settings.php?cat=backup');
    }
    header('Content-Type: application/sql');
    header('Content-Disposition: attachment; filename="' . preg_replace('/\.enc$/', '', $_FILES['encfile']['name']) . '"');
    log_activity('backup_decrypt');
    echo $plain;
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'term_add') {
    require_perm('settings.edit');
    q('INSERT INTO credit_terms (days, label) VALUES (?, ?)', [(int)post('days'), post('label') ?: ((int)post('days') . ' days')]);
    flash('Credit term added.');
    redirect('settings.php?cat=party');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'term_del') {
    require_perm('settings.edit');
    q('DELETE FROM credit_terms WHERE id = ?', [(int)post('id')]);
    flash('Credit term removed.');
    redirect('settings.php?cat=party');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'custom_field_add') {
    require_perm('settings.edit');
    $label = trim(post('label'));
    if ($label !== '') {
        $next = (int)val('SELECT COALESCE(MAX(sort_order),0)+1 FROM item_custom_fields');
        q('INSERT INTO item_custom_fields (label, sort_order, is_active, show_on_print) VALUES (?, ?, 1, ?)', [$label, $next, post('show_on_print') ? 1 : 0]);
        flash('Custom field added.');
    }
    redirect('settings.php?cat=transaction');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'custom_field_del') {
    require_perm('settings.edit');
    q('DELETE FROM item_custom_fields WHERE id = ?', [(int)post('id')]);
    flash('Custom field removed.');
    redirect('settings.php?cat=transaction');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'custom_field_print') {
    require_perm('settings.edit');
    q('UPDATE item_custom_fields SET show_on_print = 1 - show_on_print WHERE id = ?', [(int)post('id')]);
    flash('Field visibility changed.');
    redirect('settings.php?cat=transaction');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'save_period_lock') {
    require_perm('settings.edit');
    set_setting('period_lock_date', post('period_lock_date'));
    log_activity('settings_save', 'period_lock_date=' . post('period_lock_date'));
    flash(post('period_lock_date') ? 'Period locked through ' . dmy(post('period_lock_date')) . ' - nothing on or before that date can be added, edited or deleted.' : 'Period lock removed.');
    redirect('settings.php?cat=accounting');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'save_loyalty') {
    require_perm('settings.edit');
    set_setting('loyalty_enabled', post('loyalty_enabled') ? '1' : '0');
    set_setting('loyalty_earn_rate', max(0, (float)post('loyalty_earn_rate')));
    set_setting('loyalty_redeem_value', max(0.01, (float)post('loyalty_redeem_value')));
    flash('Loyalty settings saved.');
    redirect('settings.php?cat=party');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'save_store') {
    require_perm('settings.edit');
    foreach (['store_announce', 'store_banners', 'store_faqs', 'store_testimonials', 'store_deal_ends'] as $k) set_setting($k, trim(post($k)));
    set_setting('store_deal_item', (string)(int)post('store_deal_item'));
    log_activity('settings_save', 'store design');
    flash('Store design saved — વેબસાઇટ પર તરત લાગુ.');
    redirect('settings.php?cat=store');
}

$terms = all('SELECT * FROM credit_terms ORDER BY days');
$customFields = all('SELECT * FROM item_custom_fields ORDER BY sort_order, id');
$page_title = 'Settings';
include __DIR__ . '/includes/header.php';

// Categories shown as a tappable list (like Vyapar's Settings screen) -
// each opens its own section instead of one long confusing page.
$categories = [
    'general'   => ['⚙️', 'General', 'App name, GST %, login security'],
    'transaction' => ['🧾', 'Transaction', 'Cash sale default, round off, profit, purchase price, time'],
    'whatsapp'  => ['💬', 'WhatsApp', 'API connection, templates, test send'],
    'store'     => ['🛍️', 'Online Store Design', 'Banners, Deal of the Day, FAQ, testimonials'],
    'invoice'   => ['🎨', 'Invoice / Bill', 'Design, Google review, online payment'],
    'reminders' => ['⏰', 'Reminders', 'Auto overdue payment reminders'],
    'party'     => ['👥', 'Party', 'Credit term options'],
    'accounting' => ['📒', 'Accounting', 'Period lock, Chart of Accounts, Journal Entries'],
    'service'   => ['🔧', 'Service Checklist', 'Repair job checklist items'],
    'inventory' => ['📦', 'Inventory', 'Costing method, dead-stock threshold, audit/transfers'],
    'security'  => ['🛡️', 'Security', 'Password policy, auto-logout, IP restriction, login attempts'],
    'backup'    => ['🔄', 'Backup & Updates', 'Backup download, GitHub update'],
    'about'     => ['📱', 'About', 'Install as app'],
];

if ($cat === '' || !isset($categories[$cat])) {
?>
<div class="settings-cat-list">
  <?php foreach ($categories as $key => $c): ?>
  <a class="settings-cat-row" href="settings.php?cat=<?= $key ?>">
    <span class="sc-ico"><?= $c[0] ?></span>
    <span class="sc-label"><?= e($c[1]) ?><span class="sc-sub"><?= e($c[2]) ?></span></span>
    <span class="sc-chev">›</span>
  </a>
  <?php endforeach; ?>
</div>
<?php
include __DIR__ . '/includes/footer.php';
exit;
}
?>
<a class="settings-back" href="settings.php">← Settings</a>

<?php if ($cat === 'general'): ?>
<div class="card">
  <h2>⚙️ General</h2>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="save_general">
    <div class="form-row cols-2">
      <div><label>App / shop name</label><input type="text" name="app_name" value="<?= e(setting('app_name')) ?>"></div>
      <div><label>Default GST %</label><input type="number" step="any" name="default_tax" value="<?= e(setting('default_tax', '18')) ?>"></div>
    </div>
    <label class="check-inline mb"><input type="checkbox" name="login_otp" value="1" <?= setting('login_otp') === '1' ? 'checked' : '' ?>> Require WhatsApp OTP on login (2-step)</label>
    <label class="check-inline mb"><input type="checkbox" name="allow_negative_stock" value="1" <?= setting('allow_negative_stock', '1') === '1' ? 'checked' : '' ?>> Allow sale without purchase (negative stock allowed)</label>
    <button class="btn" type="submit">Save</button>
  </form>
</div>
<?php endif; ?>

<?php if ($cat === 'transaction'): ?>
<div class="card">
  <h2>🧾 Transaction Settings</h2>
  <p class="muted mb">Default behavior when creating a sale bill - similar to Vyapar's transaction settings.</p>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="save_transaction">
    <label class="check-inline mb"><input type="checkbox" name="cash_sale_default" value="1" <?= setting('cash_sale_default', '1') === '1' ? 'checked' : '' ?>> Cash Sale by default <span class="muted" style="font-weight:normal">(new bill's Cash/Credit toggle stays on Cash)</span></label>
    <label class="check-inline mb"><input type="checkbox" name="round_off_default" value="1" <?= setting('round_off_default', '1') === '1' ? 'checked' : '' ?>> Round Off Total <span class="muted" style="font-weight:normal">(bill total auto-rounds to the nearest rupee - the checkbox can still be changed per bill)</span></label>
    <div class="mb"><label>Default Minimum Profit % <span class="muted" style="font-weight:normal">— દરેક પ્રોડક્ટમાં ઓછામાં ઓછો આટલો નફો: પરચેસ થાય એટલે વેચાણ-ભાવ આપોઆપ ખરીદ + આટલા % થાય, અને ક્યારેય એનાથી નીચે ન રહે. (આઇટમનું પોતાનું Margin % ભરેલું હોય તો એ જ ચાલે; 0 = નિયમ બંધ)</span></label>
      <input type="number" step="any" min="0" name="default_margin_pct" value="<?= 0 + (float)setting('default_margin_pct', '30') ?>" style="max-width:120px"></div>
    <label class="check-inline mb"><input type="checkbox" name="show_profit_billing" value="1" <?= setting('show_profit_billing') === '1' ? 'checked' : '' ?>> Show Profit while making Sale Invoice</label>
    <label class="check-inline mb"><input type="checkbox" name="show_purchase_price_billing" value="1" <?= setting('show_purchase_price_billing') === '1' ? 'checked' : '' ?>> Display Purchase Price of Items <span class="muted" style="font-weight:normal">(in the item search list while billing)</span></label>
    <label class="check-inline mb"><input type="checkbox" name="add_time_transactions" value="1" <?= setting('add_time_transactions', '1') === '1' ? 'checked' : '' ?>> Add Time on Transactions <span class="muted" style="font-weight:normal">(shows Time next to the bill's Date - view, PDF, WhatsApp image)</span></label>
    <button class="btn" type="submit">Save</button>
  </form>
  <form method="post" class="mt" onsubmit="return confirm('બધી જૂની આઇટમના વેચાણ-ભાવ ચેક કરીને, જ્યાં મિનિમમ નફા કરતાં ઓછો હોય ત્યાં વધારી દેવો? (કોઈનો ભાવ ઘટશે નહીં)')">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="apply_margin_all">
    <button class="btn btn-sm btn-outline" type="submit">⚡ બધી હાલની આઇટમ પર મિનિમમ નફો લગાડો</button>
  </form>
</div>

<div class="card">
  <h3>Item Custom Fields / Description Points</h3>
  <p class="muted mb">Extra labeled fields (e.g. "Exp. Date", "Brand", "Warranty", "Install note") that show up under every item while adding it to a sale — 6-7 જેટલા પોઇન્ટ ઉમેરી શકાય. દરેક પોઇન્ટ માટે નક્કી કરો: <strong>🖨️ Print+PDF</strong> = ગ્રાહકના બિલ/PDF/WhatsApp માં દેખાય · <strong>🔒 Internal</strong> = ફક્ત તમને સ્ક્રીન પર દેખાય, બિલમાં ક્યારેય નહીં.</p>
  <table class="table-sm mb">
    <?php foreach ($customFields as $cf): $cfPrint = (int)($cf['show_on_print'] ?? 1); ?>
    <tr>
      <td><?= e($cf['label']) ?></td>
      <td><?= $cfPrint ? '<span class="badge badge-ok">🖨️ Print + PDF</span>' : '<span class="badge">🔒 Only internal</span>' ?></td>
      <td class="right" style="white-space:nowrap">
        <form method="post" style="display:inline"><?= csrf_field() ?>
          <input type="hidden" name="do" value="custom_field_print"><input type="hidden" name="id" value="<?= $cf['id'] ?>">
          <button class="btn btn-sm btn-outline" type="submit" title="Switch where this point is visible"><?= $cfPrint ? 'Make internal 🔒' : 'Show on print 🖨️' ?></button>
        </form>
        <form method="post" style="display:inline"><?= csrf_field() ?>
          <input type="hidden" name="do" value="custom_field_del"><input type="hidden" name="id" value="<?= $cf['id'] ?>">
          <button class="btn btn-sm btn-danger" type="submit" onclick="return confirm('Remove this custom field?')">✕</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
    <?php if (!$customFields): ?><tr><td class="muted">No custom fields yet.</td></tr><?php endif; ?>
  </table>
  <form method="post" class="filterbar">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="custom_field_add">
    <div><input type="text" name="label" placeholder="Field label, e.g. Brand" required></div>
    <label class="check-inline"><input type="checkbox" name="show_on_print" value="1" checked> Print/PDF માં દેખાડવું</label>
    <button class="btn btn-sm" type="submit">Add field</button>
  </form>
</div>
<?php endif; ?>

<?php if ($cat === 'whatsapp'): ?>
<div class="card">
  <h2>💬 WhatsApp API</h2>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="save_whatsapp">
    <div class="form-row cols-3">
      <div><label>WhatsApp API URL</label><input type="text" name="wa_api_url" value="<?= e(setting('wa_api_url', 'https://bulk.akdwk.in/api.php')) ?>"></div>
      <div><label>Session ID</label><input type="text" name="wa_session_id" value="<?= e(setting('wa_session_id')) ?>"></div>
      <div><label>API Key</label><input type="text" name="wa_api_key" value="<?= e(setting('wa_api_key')) ?>"></div>
    </div>
    <div class="form-row cols-2">
      <div><label>Shop WhatsApp number (for the website catalog's "Order" button)</label>
        <input type="tel" name="wa_shop_number" value="<?= e(setting('wa_shop_number')) ?>" placeholder="91XXXXXXXXXX"></div>
    </div>
    <button class="btn" type="submit">Save</button>
  </form>
</div>

<div class="card">
  <h2>☁️ Official Meta (Facebook) WhatsApp Cloud API</h2>
  <p class="muted" style="font-size:13px">બે API સાથે ચાલે છે — નીચે પ્રાયોરિટી પસંદ કરો: પહેલી નિષ્ફળ જાય તો બીજી આપોઆપ બેકઅપ તરીકે વપરાય છે.</p>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="save_meta_wa">
    <div class="field"><label>કઈ API પહેલા વાપરવી? (Priority)</label>
      <select name="wa_provider_order">
        <option value="thirdparty_first" <?= setting('wa_provider_order', 'thirdparty_first') !== 'meta_first' ? 'selected' : '' ?>>1️⃣ થર્ડ-પાર્ટી પહેલા → Meta બેકઅપ</option>
        <option value="meta_first" <?= setting('wa_provider_order') === 'meta_first' ? 'selected' : '' ?>>1️⃣ Meta (Official) પહેલા → થર્ડ-પાર્ટી બેકઅપ</option>
      </select></div>
    <div class="field"><label>Permanent Access Token</label>
      <input type="text" name="meta_wa_token" value="<?= e(setting('meta_wa_token')) ?>" placeholder="EAAG... (Meta Business > System User token)" autocomplete="off"></div>
    <div class="form-row cols-2">
      <div><label>Phone Number ID</label><input type="text" name="meta_wa_phone_id" value="<?= e(setting('meta_wa_phone_id')) ?>" placeholder="1234567890"></div>
      <div><label>WhatsApp Business Account (WABA) ID</label><input type="text" name="meta_wa_waba_id" value="<?= e(setting('meta_wa_waba_id')) ?>" placeholder="1234567890"></div>
    </div>
    <div class="field"><label>Meta Catalog ID <span class="muted" style="font-weight:normal">(વૈકલ્પિક — Commerce Manager કેટલોગમાં પ્રોડક્ટ પણ Sync થાય)</span></label>
      <input type="text" name="meta_catalog_id" value="<?= e(setting('meta_catalog_id')) ?>" placeholder="business.facebook.com/commerce → Catalog → Settings માંથી ID"></div>
    <button class="btn" type="submit">Save</button>
  </form>
  <form method="post" style="margin-top:10px">
    <?= csrf_field() ?><input type="hidden" name="do" value="meta_full_sync">
    <button class="btn" type="submit">🔄 Synchronize — બધું એક ક્લિકમાં</button>
    <span class="muted" style="font-size:12.5px"> — Templates + Profile + Webhook + Catalog + Phone status બધું Sync; જે આપોઆપ ઠીક થાય એ ઠીક, બાકીનું ઉકેલ સાથે નીચે.</span>
  </form>
  <?php $syncRep = json_decode(setting('meta_sync_report', ''), true) ?: [];
        if ($syncRep): $sIcon = ['ok' => '✅', 'warn' => '⚠️', 'fail' => '❌', 'skip' => '⏭️']; ?>
  <table class="table-sm mt">
    <thead><tr><th></th><th>વિભાગ</th><th>સ્થિતિ / ઉકેલ</th></tr></thead>
    <tbody>
    <?php foreach ($syncRep as $sr): ?>
    <tr><td><?= $sIcon[$sr['status']] ?? '·' ?></td><td style="white-space:nowrap"><?= e($sr['name']) ?></td>
        <td><?= e($sr['detail']) ?><?= !empty($sr['fix']) ? '<br><span class="muted" style="font-size:12px">🔧 ' . e($sr['fix']) . '</span>' : '' ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <p class="muted" style="font-size:12px">Last full sync: <?= e(setting('meta_sync_at', '-')) ?></p>
  <?php endif; ?>
  <?php $tplStatus = json_decode(setting('meta_wa_tpl_status', ''), true) ?: [];
        if ($tplStatus): ?>
  <table class="table-sm mt">
    <thead><tr><th>Template</th><th>Category</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($tplStatus as $tn => $ts): if (!is_array($ts)) continue;
        $badge = ['APPROVED' => '<span class="badge badge-ok">✅ Approved</span>',
                  'PENDING' => '<span class="badge badge-info">⏳ Pending</span>',
                  'REJECTED' => '<span class="badge badge-bad">❌ Rejected</span>',
                  'DRAFT' => '<span class="badge">📝 Draft</span>'][$ts['status']] ?? '<span class="badge">' . e($ts['status']) . '</span>'; ?>
    <tr><td><code><?= e($tn) ?></code></td><td><?= e($ts['category'] ?? '') ?></td>
        <td><?= $badge ?><?= !empty($ts['reason']) ? ' <span class="muted" style="font-size:12px">' . e(mb_substr($ts['reason'], 0, 120)) . '</span>' : '' ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <p class="muted" style="font-size:12px">Last sync: <?= e(setting('meta_wa_tpl_synced_at', '-')) ?> · સ્ટેટસ દર 6 કલાકે આપોઆપ પણ રિફ્રેશ થાય છે (Pending → Approved થાય એટલે અહીં દેખાશે).</p>
  <?php endif; ?>
</div>

<div class="card">
  <h3>Test WhatsApp API</h3>
  <form method="post" class="filterbar">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="wa_test">
    <div><input type="tel" name="test_mobile" placeholder="10-digit mobile" required></div>
    <button class="btn btn-wa btn-sm" type="submit">Send test</button>
  </form>
</div>
<div class="card">
  <h3>💰 મહિનાનો અંદાજિત ખર્ચ — AI + Meta WhatsApp API</h3>
  <?php
  require_once __DIR__ . '/includes/wa_bot.php';
  try { list($aiUsed2, $aiCap2) = wa_bot_ai_usage(); } catch (Exception $e) { $aiUsed2 = 0; $aiCap2 = 1500; }
  $aiCatCalls = (int)val("SELECT COUNT(*) FROM activity_log WHERE action = 'ai_categorize' AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')");
  try { $metaOut = (int)val("SELECT COUNT(*) FROM wa_chats WHERE via = 'meta' AND direction = 'out' AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')"); } catch (Exception $e) { $metaOut = 0; }
  $metaEst = round($metaOut * 0.13, 2); // WORST case: every message billed as a utility/auth template (~₹0.115-0.13); replies inside the 24h window are actually FREE
  ?>
  <div class="table-wrap" style="box-shadow:none"><table class="table-sm">
    <thead><tr><th>સર્વિસ</th><th class="num">આ મહિને વપરાશ</th><th class="num">અંદાજિત ખર્ચ</th></tr></thead>
    <tbody>
      <tr><td>🧠 Gemini AI (બોટ જવાબ + ફોટો ઓળખ + કેટેગરી ગોઠવણ)</td><td class="num"><?= $aiUsed2 + $aiCatCalls ?> calls (cap <?= $aiCap2 ?>)</td><td class="num"><strong>₹0</strong> <span class="muted">(ફ્રી ટિયર)</span></td></tr>
      <?php $aiPaidCalls = (int)val("SELECT COUNT(*) FROM activity_log WHERE action = 'ai_paid_call' AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')");
            if ($aiPaidCalls || setting('gemini_api_key_paid') !== ''): ?>
      <tr><td>💳 Gemini Paid Backup (ફ્રી લિમિટ પતે ત્યારે આપોઆપ)</td><td class="num"><?= $aiPaidCalls ?> calls</td><td class="num"><strong>~₹<?= money($aiPaidCalls * 0.06) ?></strong></td></tr>
      <?php endif; ?>
      <tr><td>☁️ Meta WhatsApp Cloud API (Official)</td><td class="num"><?= $metaOut ?> મેસેજ</td><td class="num"><strong>વધુમાં વધુ ~₹<?= money($metaEst) ?></strong></td></tr>
      <tr><td>📨 થર્ડ-પાર્ટી ગેટવે (bulk.akdwk.in)</td><td class="num">—</td><td class="num"><span class="muted">તમારું અલગ રિચાર્જ</span></td></tr>
    </tbody>
  </table></div>
  <p class="muted" style="font-size:12.5px">📌 Meta નો હિસાબ: ગ્રાહકે છેલ્લા 24 કલાકમાં મેસેજ કર્યો હોય એની અંદરના બધા જવાબ (કેટલોગ, બોટ, ટેક્સ્ટ) <strong>ફ્રી</strong>; ફક્ત 24-કલાક બહાર જતા ટેમ્પ્લેટ મેસેજ (OTP/બિલ/રિમાઇન્ડર) આશરે <strong>₹0.12-0.13 પ્રતિ મેસેજ</strong> લાગે. ઉપરનો આંકડો બધા જ મેસેજ paid ગણીને કાઢેલો <em>મહત્તમ</em> અંદાજ છે — સાચું બિલ એનાથી ઓછું જ આવે (ચોક્કસ આંકડો business.facebook.com → Billing માં). Gemini AI હાલના વપરાશે ફ્રી-ટિયરમાં જ રહે છે = ₹0.</p>
</div>
<div class="card">
  <h3>🤖 WhatsApp Product Bot (auto-reply)</h3>
  <?php if (setting('wa_webhook_key', '') === '') set_setting('wa_webhook_key', bin2hex(random_bytes(16)));
        $whUrl = base_url('wa_webhook.php?key=' . setting('wa_webhook_key')); ?>
  <p class="muted">A customer messages "CP Plus camera che?" (or sends a product photo) → the bot searches YOUR items database and replies with the price + product link automatically. Text answers cost ₹0 (pure database search); a photo uses one free-tier Gemini call to recognise the product. If nothing matches, the bot stays silent so it never talks over your own chat.</p>
  <form method="post" class="mt">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="save_whatsapp">
    <input type="hidden" name="wa_api_url" value="<?= e(setting('wa_api_url', 'https://bulk.akdwk.in/api.php')) ?>">
    <input type="hidden" name="wa_session_id" value="<?= e(setting('wa_session_id')) ?>">
    <input type="hidden" name="wa_api_key" value="<?= e(setting('wa_api_key')) ?>">
    <input type="hidden" name="wa_shop_number" value="<?= e(setting('wa_shop_number')) ?>">
    <label class="check-inline"><input type="checkbox" name="wa_bot_enabled" value="1" <?= setting('wa_bot_enabled', '0') === '1' ? 'checked' : '' ?>> Bot ON — auto-reply to product questions</label>
    <label class="check-inline" style="display:block;margin-top:6px"><input type="checkbox" name="wa_catalog_enabled" value="1" <?= setting('wa_catalog_enabled', '1') === '1' ? 'checked' : '' ?>> 📚 WhatsApp Catalog menu — "catalog" લખે (કે welcome બટન દબાવે) એટલે આખો કેટલોગ WhatsApp માં જ ખૂલે: કેટેગરી લિસ્ટ → પ્રોડક્ટ + ભાવ → 🛒 ઓર્ડર બટન (ઓર્ડર વેબસાઇટના Web Orders માં આવે)</label>
    <p class="muted" style="font-size:12.5px;margin:4px 0 0 24px">Official Meta API કનેક્ટ હોય તો સાચા બટન/લિસ્ટ મેનુ જાય છે; નહીંતર એ જ મેનુ નંબરવાળા ટેક્સ્ટ તરીકે જાય છે (ગ્રાહક "1" લખીને જવાબ આપે).</p>
    <div class="form-row cols-2 mt">
      <div><label>AI calls / month limit <span class="muted" style="font-weight:normal">(cost brake — most replies use 0 AI; 1500 stays inside Gemini's FREE tier = ₹0)</span></label>
        <input type="number" min="0" name="wa_bot_ai_monthly_cap" value="<?= (int)setting('wa_bot_ai_monthly_cap', '1500') ?>"></div>
      <?php require_once __DIR__ . '/includes/wa_bot.php'; try { list($aiUsed, $aiCap) = wa_bot_ai_usage(); } catch (Exception $e) { $aiUsed = 0; $aiCap = 1500; } ?>
      <div><label>This month's AI use</label>
        <p style="padding:10px 0;font-weight:700"><?= $aiUsed ?> / <?= $aiCap ?> calls <span class="muted" style="font-weight:normal">(est. cost: ₹0 within free tier)</span></p></div>
    </div>
    <p class="muted" style="font-size:12.5px">💡 Staff/owner numbers (from Staff Users) get the SHOP ASSISTANT: WhatsApp 'sale', 'cash', 'baki', 'stock &lt;item&gt;', 'order', 'visitors' to the shop number — instant answers from the database, zero AI. Type 'help' for the list.</p>
    <button class="btn btn-sm" type="submit">Save</button>
  </form>
  <p class="muted mt">Paste this URL in your WhatsApp gateway's (bulk.akdwk.in) <strong>Webhook / incoming message URL</strong> box:</p>
  <p><code style="word-break:break-all;background:var(--bg);padding:8px;border-radius:8px;display:block"><?= e($whUrl) ?></code></p>
  <?php $botLog = [];
        try { $botLog = all('SELECT * FROM wa_bot_log ORDER BY id DESC LIMIT 10'); } catch (Exception $e) {} ?>
  <?php if ($botLog): ?>
  <h4 class="mt">Last 10 bot conversations</h4>
  <div class="table-wrap" style="box-shadow:none"><table class="table-sm">
    <thead><tr><th>Time</th><th>From</th><th>Asked</th><th class="num">Matches</th><th>Bot replied?</th></tr></thead>
    <tbody><?php foreach ($botLog as $b): ?>
      <tr><td><?= dmyt($b['created_at']) ?></td><td><?= e($b['mobile']) ?></td>
      <td><?= $b['had_image'] ? '🖼️ ' : '' ?><?= e(mb_substr($b['in_text'], 0, 60)) ?></td>
      <td class="num"><?= (int)$b['matched'] ?></td>
      <td><?= $b['reply'] ? '✅' : '<span class="muted">silent</span>' ?></td></tr>
    <?php endforeach; ?></tbody>
  </table></div>
  <?php endif; ?>
</div>
<div class="card">
  <h3>🌐 Bot Menus, Keywords &amp; Languages</h3>
  <p class="muted">"Hi" કે "Menu" જેવો કોઈપણ keyword ગ્રાહક ગમે ત્યારે લખે — જૂની ચેટ ગમે તેટલી જૂની હોય — તરત મુખ્ય મેનુ જાય છે. પહેલી વાર ગ્રાહકને ભાષા પુછાય છે (English / ગુજરાતી / हिंदी) અને પછી બધા મેનુ-જવાબ એ જ ભાષામાં જાય છે. બધું અહીંથી બદલી શકાય — કોડને હાથ લગાડ્યા વગર.</p>
  <?php require_once __DIR__ . '/includes/wa_bot.php'; ?>
  <form method="post" class="mt">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="save_wabot">
    <div><label>Trigger keywords <span class="muted" style="font-weight:normal">(comma separated — any of these always opens the main menu)</span></label>
      <input type="text" name="wa_bot_keywords" value="<?= e(setting('wa_bot_keywords', '')) ?>" placeholder="<?= e(implode(',', array_slice(wa_kw_list(), 0, 12))) ?>,...">
      <p class="muted" style="font-size:12px">ખાલી રાખો તો default લિસ્ટ ચાલે છે: hi, hello, menu, start, home, namaste, નમસ્તે, नमस्ते...</p></div>
    <div class="form-row cols-2 mt">
      <div><label>Languages offered to customers</label>
        <?php $langsOn = wa_langs_enabled(); foreach (['en' => 'English', 'gu' => 'ગુજરાતી (Gujarati)', 'hi' => 'हिंदी (Hindi)'] as $lc => $ln): ?>
        <label class="check-inline" style="display:block"><input type="checkbox" name="wa_langs[]" value="<?= $lc ?>" <?= in_array($lc, $langsOn, true) ? 'checked' : '' ?>> <?= $ln ?></label>
        <?php endforeach; ?></div>
      <div><label>Default language <span class="muted" style="font-weight:normal">(before the customer picks)</span></label>
        <select name="wa_lang_default">
          <?php foreach (['en' => 'English', 'gu' => 'ગુજરાતી', 'hi' => 'हिंदी'] as $lc => $ln): ?>
          <option value="<?= $lc ?>" <?= wa_lang_default() === $lc ? 'selected' : '' ?>><?= $ln ?></option>
          <?php endforeach; ?>
        </select>
        <label class="check-inline mt" style="display:block"><input type="checkbox" name="wa_bot_fallback" value="1" <?= setting('wa_bot_fallback', '1') === '1' ? 'checked' : '' ?>> Unknown message → "Type *menu*" nudge <span class="muted">(max once / 10 min, so it never spams a live chat)</span></label>
        <div class="mt"><label>Statement — entries shown</label><input type="number" min="3" max="25" name="wa_stmt_entries" value="<?= (int)setting('wa_stmt_entries', '10') ?>"></div></div>
    </div>
    <div class="mt"><label>Account menu items <span class="muted" style="font-weight:normal">(uncheck to hide; a free slot shows the 🌐 Change-Language row)</span></label>
      <?php $off = array_filter(array_map('trim', explode(',', setting('wa_menu_off', '')))); ?>
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:2px 12px">
      <?php $GLOBALS['_wa_lang'] = 'en'; foreach (wa_portal_menu_defs() as $d): ?>
        <label class="check-inline"><input type="checkbox" name="wa_menu_on[]" value="<?= e($d[0]) ?>" <?= in_array($d[0], $off, true) ? '' : 'checked' ?>> <?= e(wa_t($d[1])) ?></label>
      <?php endforeach; unset($GLOBALS['_wa_lang']); ?>
      </div></div>
    <div class="mt"><label>Auto replies <span class="muted" style="font-weight:normal">(one per line: <code>keyword | reply text</code> — exact whole-message match, any language)</span></label>
      <textarea name="wa_auto_replies" rows="3" placeholder="timing | We are open 9:30am - 8:30pm, Monday to Saturday.&#10;upi | Our UPI ID: shop@upi"><?= e(setting('wa_auto_replies', '')) ?></textarea></div>
    <details class="mt"><summary style="cursor:pointer;font-weight:600">✍️ Welcome &amp; fallback message text (per language — blank = built-in text)</summary>
      <div class="form-row cols-3 mt">
      <?php foreach (['en' => 'English', 'gu' => 'ગુજરાતી', 'hi' => 'हिंदी'] as $lc => $ln): ?>
        <div><label>Welcome — <?= $ln ?></label>
          <textarea name="wa_txt_welcome_<?= $lc ?>" rows="4" placeholder="<?= e(wa_strings()['welcome'][$lc] ?? '') ?>"><?= e(setting('wa_txt_welcome_' . $lc, '')) ?></textarea></div>
      <?php endforeach; ?>
      </div>
      <div class="form-row cols-3">
      <?php foreach (['en' => 'English', 'gu' => 'ગુજરાતી', 'hi' => 'हिंदी'] as $lc => $ln): ?>
        <div><label>Fallback — <?= $ln ?></label>
          <textarea name="wa_txt_fallback_<?= $lc ?>" rows="3" placeholder="<?= e(wa_strings()['fallback'][$lc] ?? '') ?>"><?= e(setting('wa_txt_fallback_' . $lc, '')) ?></textarea></div>
      <?php endforeach; ?>
      </div>
      <p class="muted" style="font-size:12px">Placeholders: <code>{shop}</code> = દુકાનનું નામ. ખાલી છોડો એટલે સિસ્ટમનું તૈયાર લખાણ વપરાય.</p>
    </details>
    <button class="btn btn-sm mt" type="submit">Save Bot Settings</button>
  </form>
</div>
<div class="card">
  <h3>✈️ Telegram Management Bot</h3>
  <p class="muted">Telegram પર બટન દબાવીને આખી દુકાન: આજનું વેચાણ, ઉધાર, કેશ+બેંક, સ્ટોક, વેબ ઓર્ડર, રિપેર, સ્ટાફ વોલેટ. બનાવવા: Telegram માં <strong>@BotFather</strong> ખોલો → /newbot → જે token મળે એ અહીં નાખો.</p>
  <form method="post" class="mt">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="tg_save">
    <div class="form-row cols-2">
      <div><label>Bot Token</label><input type="password" name="tg_bot_token" value="<?= e(setting('tg_bot_token')) ?>" placeholder="123456:ABC-DEF..."></div>
      <label class="check-inline"><input type="checkbox" name="set_webhook" value="1" checked> Save થતાં જ બોટ ચાલુ કરી દો (webhook auto-set)</label>
    </div>
    <button class="btn btn-sm" type="submit">Save & Connect</button>
  </form>
  <?php if (setting('tg_bot_token')): ?>
  <h4 class="mt">સ્ટાફ જોડાણ (કોણ બોટ વાપરી શકે)</h4>
  <p class="muted" style="font-size:12.5px">દરેક સ્ટાફ Telegram માં બોટ ખોલીને <code>/link કોડ</code> મોકલે એટલે જોડાય. કોડ અહીંથી બનાવો:</p>
  <table class="table-sm">
    <thead><tr><th>Staff</th><th>Status</th><th>Link code</th><th></th></tr></thead>
    <tbody>
    <?php foreach (all('SELECT id, name, telegram_chat_id, tg_link_code FROM users WHERE is_active = 1 ORDER BY name') as $tu): ?>
      <tr>
        <td><?= e($tu['name']) ?></td>
        <td><?= $tu['telegram_chat_id'] ? '<span class="badge badge-ok">✔ joined</span>' : '<span class="muted">not joined</span>' ?></td>
        <td><?= $tu['tg_link_code'] ? '<code>/link ' . e($tu['tg_link_code']) . '</code>' : '<span class="muted">-</span>' ?></td>
        <td>
          <?php if ($tu['telegram_chat_id']): ?>
          <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="do" value="tg_unlink"><input type="hidden" name="uid" value="<?= $tu['id'] ?>">
            <button class="btn btn-sm btn-outline" type="submit">Unlink</button></form>
          <?php else: ?>
          <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="do" value="tg_gencode"><input type="hidden" name="uid" value="<?= $tu['id'] ?>">
            <button class="btn btn-sm btn-outline" type="submit">🔑 Code બનાવો</button></form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
<div class="card">
  <h3>🛍️ WhatsApp / Facebook Catalog Feed</h3>
  <?php if (setting('catalog_feed_key', '') === '') set_setting('catalog_feed_key', bin2hex(random_bytes(10))); ?>
  <p class="muted">વેબસાઇટ પર ON કરેલી બધી આઇટમની Meta-ફોર્મેટ CSV ફીડ. <strong>Meta Commerce Manager → Data Sources → Scheduled Feed</strong> માં આ URL નાખો એટલે WhatsApp Business / Facebook / Instagram નો કેટલોગ દુકાનના સ્ટોક-ભાવ સાથે આપોઆપ સિંક રહે (ભાવ બદલો → કેટલોગ પણ બદલાય).</p>
  <input type="text" readonly value="<?= e(base_url('catalog_feed.php?key=' . setting('catalog_feed_key'))) ?>" onclick="this.select()" style="width:100%">
</div>
<div class="card">
  <h3>💬 Message Templates</h3>
  <p class="muted mb">Write each message however you like. Keep the variables as-is — they get replaced with the real value when sending. Leave blank to use the default.</p>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="templates">
    <?php foreach (wa_template_defaults() as $k => $def): ?>
    <div class="field">
      <label><?= e($def[2]) ?> <span class="muted" style="font-weight:normal">— variables: <code><?= e($def[1]) ?></code></span></label>
      <textarea name="tpl_<?= $k ?>" rows="3" placeholder="<?= e($def[0]) ?>"><?= e(setting('wa_tpl_' . $k)) ?></textarea>
    </div>
    <?php endforeach; ?>
    <button class="btn" type="submit">Save Templates</button>
  </form>
</div>
<?php endif; ?>

<?php if ($cat === 'store'): ?>
<div class="card">
  <h3>🛍️ Online Store Design</h3>
  <p class="muted">વેબસાઇટનું હોમપેજ અહીંથી કંટ્રોલ થાય છે — Save કરો એટલે તરત લાઈવ. કંઈ ખાલી છોડશો તો સરસ ડિફોલ્ટ ડિઝાઈન વપરાય છે.</p>
  <form method="post" class="mt">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="save_store">
    <div class="field"><label>📣 Announcement Bar (હેડરની નીચેની લાઈન)</label>
      <input type="text" name="store_announce" value="<?= e(setting('store_announce')) ?>" placeholder="🚚 Dwarka-માં ઝડપી ડિલિવરી · ✅ Genuine Products..."></div>
    <div class="field"><label>🖼️ Hero Banners <span class="muted" style="font-weight:normal">(એક લાઈન = એક બેનર · ફોર્મેટ: ટાઈટલ|સબટાઈટલ|ઇમોજી|કલર1|કલર2|લિંક)</span></label>
      <textarea name="store_banners" rows="4" placeholder="દિવાળી ઓફર - 10% OFF|બધા CCTV કેમેરા પર|🪔|#7c3aed|#db2777|"><?= e(setting('store_banners')) ?></textarea></div>
    <div class="form-row cols-2">
      <div><label>⚡ Deal of the Day — Item ID <span class="muted" style="font-weight:normal">(Items પેજ પર ID દેખાય છે; 0 = બંધ)</span></label>
        <input type="number" name="store_deal_item" value="<?= (int)setting('store_deal_item') ?>"></div>
      <div><label>Deal ક્યાં સુધી? <span class="muted" style="font-weight:normal">(કાઉન્ટડાઉન ટાઈમર)</span></label>
        <input type="datetime-local" name="store_deal_ends" value="<?= e(setting('store_deal_ends')) ?>"></div>
    </div>
    <div class="field"><label>💬 Testimonials <span class="muted" style="font-weight:normal">(એક લાઈન = એક · ફોર્મેટ: નામ|વાત)</span></label>
      <textarea name="store_testimonials" rows="3"><?= e(setting('store_testimonials')) ?></textarea></div>
    <div class="field"><label>❓ FAQ <span class="muted" style="font-weight:normal">(એક લાઈન = એક · ફોર્મેટ: સવાલ|જવાબ)</span></label>
      <textarea name="store_faqs" rows="4"><?= e(setting('store_faqs')) ?></textarea></div>
    <button class="btn" type="submit">Save</button>
    <a class="btn btn-outline" href="<?= e(base_url('catalog.php')) ?>" target="_blank">🌐 વેબસાઇટ જુઓ</a>
  </form>
  <p class="muted mt" style="font-size:12.5px">Best Sellers / New Arrivals / Brands આપોઆપ બને છે (વેચાણ અને નવી પ્રોડક્ટ પરથી) — એ મેનેજ કરવાના નથી.</p>
</div>
<?php endif; ?>

<?php if ($cat === 'invoice'): ?>
<div class="card">
  <h3>🎨 Bill Design</h3>
  <p class="muted mb">Choose the design used for every bill — on screen, on print, and on the WhatsApp PDF. You can change it any time.</p>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="bill_design">
    <?php $curDesign = setting('invoice_design', '1');
    $designs = [
        '1' => ['Design 1 — Teal & Orange', 'The original clean teal/orange bill.'],
        '2' => ['Design 2 — Purple & Orange', 'Bold purple theme with trust badges & icons.'],
    ];
    foreach ($designs as $dId => $d): ?>
    <label style="display:flex;gap:10px;align-items:flex-start;border:2px solid <?= $curDesign === $dId ? 'var(--primary)' : 'var(--border)' ?>;border-radius:10px;padding:12px;cursor:pointer;margin-bottom:10px">
      <input type="radio" name="invoice_design" value="<?= $dId ?>" <?= $curDesign === $dId ? 'checked' : '' ?> style="width:auto;margin-top:3px">
      <span><strong><?= e($d[0]) ?></strong><br><span class="muted" style="font-size:12.5px"><?= e($d[1]) ?></span></span>
    </label>
    <?php endforeach; ?>
    <button class="btn" type="submit">Save Design</button>
  </form>
</div>
<div class="card">
  <h3>Google Review & Online Payment</h3>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="save_invoice">
    <div class="form-row cols-2">
      <div><label>Google Review Link <span class="muted" style="font-weight:normal">(pressing "Review Invite" on a bill sends this link straight to WhatsApp)</span></label>
        <input type="text" name="google_review_link" value="<?= e(setting('google_review_link')) ?>" placeholder="https://g.page/r/xxxxxxx/review"></div>
    </div>
    <div class="form-row cols-2">
      <div><label>Razorpay Key ID <span class="muted" style="font-weight:normal">(for the online payment link on bills, optional)</span></label><input type="text" name="razorpay_key_id" value="<?= e(setting('razorpay_key_id')) ?>" placeholder="rzp_live_..."></div>
      <div><label>Razorpay Key Secret</label><input type="password" name="razorpay_key_secret" value="<?= e(setting('razorpay_key_secret')) ?>"></div>
    </div>
    <div class="form-row cols-2">
      <div><label>Razorpay Webhook Secret <span class="muted" style="font-weight:normal">(from the webhook you create below - auto-marks a bill paid when the customer pays online)</span></label><input type="password" name="razorpay_webhook_secret" value="<?= e(setting('razorpay_webhook_secret')) ?>"></div>
    </div>
    <div class="form-row cols-2">
      <div><label>OCR.space API Key <span class="muted" style="font-weight:normal">(for Purchase &gt; Upload Bill)</span>
        <br><span class="muted" style="font-weight:normal;font-size:12.5px">Leave blank to use the built-in free reader (shared &amp; rate-limited). For your own reliable, higher-volume key, register free at <strong>ocr.space/ocrapi</strong> — they email you a key — then paste it here.</span></label>
        <input type="password" name="ocr_api_key" value="<?= e(setting('ocr_api_key')) ?>" placeholder="Using built-in free reader"></div>
    </div>
    <div class="form-row cols-2">
      <div><label>Google Gemini API Key <span class="muted" style="font-weight:normal">(for Items &gt; AI Auto-Fill — category + description)</span>
        <br><span class="muted" style="font-weight:normal;font-size:12.5px">Free at <strong>aistudio.google.com/apikey</strong> — sign in with Google, press "Create API key", paste it here. The free tier covers this shop's whole item list at ₹0.</span></label>
        <input type="password" name="gemini_api_key" value="<?= e(setting('gemini_api_key')) ?>" placeholder="AIza..."></div>
      <div><label>Paid Backup Gemini Key <span class="muted" style="font-weight:normal">(optional — કામ કદી ન અટકે)</span>
        <br><span class="muted" style="font-weight:normal;font-size:12.5px">ફ્રી key ની દૈનિક લિમિટ પતી જાય કે કોઈ ભૂલ આવે તો સિસ્ટમ <strong>આપોઆપ</strong> આ key પર ચાલવા લાગે છે — કંઈ મેન્યુઅલ કરવાનું નહીં. બનાવવા: console.cloud.google.com પર એક પ્રોજેક્ટમાં Billing ચાલુ કરી એ પ્રોજેક્ટની Gemini API key અહીં નાખો. ખર્ચ: નાના call દીઠ પૈસા જ (મહિને ₹20-30 થી વધુ ભાગ્યે જ). Paid વપરાશ WhatsApp સેટિંગ્સના ખર્ચ-કાર્ડમાં દેખાય છે.</span></label>
        <input type="password" name="gemini_api_key_paid" value="<?= e(setting('gemini_api_key_paid')) ?>" placeholder="AIza... (billing-enabled project)"></div>
    </div>
    <div class="form-row cols-2">
      <div><label>Google Image Search API Key <span class="muted" style="font-weight:normal">(for AI Auto-Fill product photos, optional)</span>
        <br><span class="muted" style="font-weight:normal;font-size:12.5px">⚠️ NOT the same key as Gemini — the Gemini key will not work here. From <strong>console.cloud.google.com</strong>: pick/create a project → search "Custom Search API" → Enable → Credentials → Create credentials → API key. 100 photo searches/day are free; beyond that Google charges about ₹450 per 1000.</span></label>
        <input type="password" name="gcs_api_key" value="<?= e(setting('gcs_api_key')) ?>" placeholder="AIza..."></div>
      <div><label>Search Engine ID (cx) <span class="muted" style="font-weight:normal">(pairs with the image key)</span>
        <br><span class="muted" style="font-weight:normal;font-size:12.5px">Make one at <strong>programmablesearchengine.google.com</strong> — "Search the entire web" ON, "Image search" ON — then copy its Search engine ID.</span></label>
        <input type="text" name="gcs_cx" value="<?= e(setting('gcs_cx')) ?>" placeholder="a12bc34..."></div>
    </div>
    <button class="btn" type="submit">Save</button>
  </form>
  <?php if (setting('razorpay_key_id')): ?>
  <p class="muted mt">In Razorpay Dashboard → Settings → Webhooks, add this URL with the "payment_link.paid" event, then paste the secret it gives you above:</p>
  <p><code style="word-break:break-all;background:var(--bg);padding:8px;border-radius:8px;display:block"><?= e(base_url('razorpay_webhook.php')) ?></code></p>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($cat === 'reminders'): ?>
<div class="card">
  <h3>⏰ Auto Overdue Reminders (cron)</h3>
  <?php $cronUrl = base_url('cron.php?key=' . setting('cron_key')); ?>
  <p class="muted mb">બિલની due-date આવે એ દિવસે "આજે પેમેન્ટની તારીખ છે" અને પછી બિલ ચૂકતે ન થાય ત્યાં સુધી રોજ "X દિવસ થઈ ગયા" નો WhatsApp મેસેજ કસ્ટમરને આપોઆપ જાય છે — નીચે સેટ કરેલા સમયે. Hosting ના cPanel → Cron Jobs માં આ URL <strong>દર 1 મિનિટે</strong> (<code>* * * * *</code>) ચાલે એમ મૂકો — આ એક જ cron થી આખી સિસ્ટમના બધા auto કામ (રિમાઇન્ડર, બેકઅપ, રિપોર્ટ, AMC...) ચાલે છે; મેસેજ તો સેટ કરેલા સમયે જ જશે, અને એક બિલને દિવસમાં એક જ વાર:</p>
  <p class="mb"><code style="word-break:break-all;background:var(--bg);padding:8px;border-radius:8px;display:block">wget -qO- "<?= e($cronUrl) ?>"</code></p>
  <p class="muted mb">🕹 બધા auto job નું સ્ટેટસ/હિસ્ટ્રી/મેન્યુઅલ રન: <a href="cron_manager.php"><strong>Cron Manager</strong></a></p>
  <form method="post" class="filterbar">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="reminder_gap">
    <div><label>રોજ કેટલા વાગ્યે મોકલવો? (કલાક, 0-23)</label><input type="number" name="hour" min="0" max="23" value="<?= (int)setting('reminder_hour', '10') ?>"></div>
    <div><label>એક જ બિલ માટે કેટલા દિવસે ફરી મેસેજ? (1 = રોજ)</label><input type="number" name="gap" min="1" value="<?= (int)setting('reminder_gap_days', '1') ?>"></div>
    <button class="btn btn-sm" type="submit">Save</button>
    <a class="btn btn-sm btn-outline" href="<?= e($cronUrl) ?>" target="_blank">▶ Test Run Now</a>
  </form>
</div>
<?php endif; ?>

<?php if ($cat === 'party'): ?>
<div class="card">
  <h3>Credit terms (dropdown options)</h3>
  <table class="table-sm mb">
    <?php foreach ($terms as $t): ?>
    <tr>
      <td><?= e($t['label']) ?> (<?= (int)$t['days'] ?> days)</td>
      <td class="right">
        <form method="post" style="display:inline"><?= csrf_field() ?>
          <input type="hidden" name="do" value="term_del"><input type="hidden" name="id" value="<?= $t['id'] ?>">
          <button class="btn btn-sm btn-danger" type="submit" onclick="return confirm('Remove?')">✕</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
  <form method="post" class="filterbar">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="term_add">
    <div><input type="number" name="days" placeholder="Days" required></div>
    <div><input type="text" name="label" placeholder="Label (optional)"></div>
    <button class="btn btn-sm" type="submit">Add term</button>
  </form>
</div>

<div class="card">
  <h3>⭐ Loyalty Points</h3>
  <p class="muted mb">When ON, the party earns points on every bill, and can redeem old points for a discount while billing.</p>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="save_loyalty">
    <label class="check-inline mb"><input type="checkbox" name="loyalty_enabled" value="1" <?= setting('loyalty_enabled') === '1' ? 'checked' : '' ?>> Enable Loyalty Points</label>
    <div class="form-row cols-2">
      <div><label>Points earned per ₹100 bill</label><input type="number" step="any" name="loyalty_earn_rate" value="<?= e(setting('loyalty_earn_rate', '1')) ?>"></div>
      <div><label>Value of 1 point (₹, when redeeming)</label><input type="number" step="any" name="loyalty_redeem_value" value="<?= e(setting('loyalty_redeem_value', '1')) ?>"></div>
    </div>
    <button class="btn" type="submit">Save</button>
  </form>
</div>
<?php endif; ?>

<?php if ($cat === 'accounting'): ?>
<div class="card">
  <h3>📒 Period Lock</h3>
  <p class="muted mb">Nothing dated on or before the lock date can be added, edited or deleted anywhere in the app (Sales, Purchases, Payments, Expenses, Returns, Journal Entries) - use this once a month or year's books are finalized, so they can't change by accident.</p>
  <form method="post" class="filterbar">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="save_period_lock">
    <div><label>Locked through</label><input type="date" name="period_lock_date" value="<?= e(setting('period_lock_date')) ?>"></div>
    <button class="btn btn-sm" type="submit">Save</button>
  </form>
  <?php if (setting('period_lock_date')): ?>
  <form method="post" class="mt"><?= csrf_field() ?>
    <input type="hidden" name="do" value="save_period_lock"><input type="hidden" name="period_lock_date" value="">
    <button class="btn btn-sm btn-outline" type="submit">Remove Lock</button>
  </form>
  <?php endif; ?>
</div>
<div class="card">
  <h3>Accounting Tools</h3>
  <p class="muted mb">Manage the Chart of Accounts and post manual Journal / Adjustment entries.</p>
  <a class="btn btn-sm btn-outline" href="accounts.php">Chart of Accounts</a>
  <a class="btn btn-sm btn-outline" href="journal.php">Journal Entries</a>
  <a class="btn btn-sm btn-outline" href="bank_reconcile.php">Bank Reconciliation</a>
</div>
<?php endif; ?>

<?php if ($cat === 'service'):
    $checklistItems = all('SELECT * FROM service_checklist_items ORDER BY sort_order'); ?>
<div class="card">
  <h3>🔧 Service checklist items</h3>
  <p class="muted mb">Shown on every repair job's checklist card (repairs.php) and on the shareable digital service report.</p>
  <table class="table-sm">
    <?php foreach ($checklistItems as $ci): ?>
    <tr>
      <td><?= e($ci['label']) ?></td>
      <td><?= $ci['is_active'] ? '<span class="badge badge-ok">active</span>' : '<span class="badge badge-bad">off</span>' ?></td>
      <td>
        <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="do" value="checklist_toggle"><input type="hidden" name="id" value="<?= $ci['id'] ?>">
          <button class="btn btn-sm btn-outline" type="submit"><?= $ci['is_active'] ? 'Deactivate' : 'Activate' ?></button></form>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
  <form method="post" class="form-row cols-3 mt">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="checklist_add">
    <div><label>New checklist item</label><input type="text" name="label" required></div>
    <div style="align-self:end"><button class="btn btn-sm" type="submit">+ Add</button></div>
  </form>
</div>
<?php endif; ?>

<?php if ($cat === 'inventory'): ?>
<div class="card">
  <h3>📦 Costing method</h3>
  <p class="muted mb">Controls how the Stock Report's FIFO/Weighted-Average valuation section and each sale's recorded cost are computed. "Current price" (the default) keeps today's behaviour - every valuation uses whatever <?= e(setting('app_name', 'the item')) ?>'s Items page currently shows as Purchase Price. Switching to FIFO or Weighted-Average only affects stock going forward - existing stock has no cost history to draw on until new purchases build it up.</p>
  <form method="post" class="filterbar">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="save_inventory">
    <div><label>Costing method</label>
      <select name="costing_method">
        <?php foreach (['current' => 'Current price (default)', 'fifo' => 'FIFO', 'weighted_avg' => 'Weighted Average'] as $k => $lbl): ?>
        <option value="<?= $k ?>" <?= setting('costing_method', 'current') === $k ? 'selected' : '' ?>><?= $lbl ?></option>
        <?php endforeach; ?>
      </select></div>
    <div><label>Dead stock: no sale in (days)</label><input type="number" name="dead_stock_days" value="<?= (int)setting('dead_stock_days', 90) ?>"></div>
    <button class="btn btn-sm" type="submit">Save</button>
  </form>
</div>
<div class="card">
  <h3>Inventory Tools</h3>
  <a class="btn btn-sm btn-outline" href="stock_audit.php">Stock Audit / Cycle Counting</a>
  <a class="btn btn-sm btn-outline" href="transfers.php">Warehouse Transfers</a>
</div>
<?php endif; ?>

<?php if ($cat === 'security'): ?>
<div class="card">
  <h3>🔑 Password policy</h3>
  <p class="muted mb">Applies whenever a password is set or changed (new user, edit user, My Account, forgot password).</p>
  <form method="post" class="filterbar">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="save_pwd_policy">
    <div><label>Minimum length</label><input type="number" name="pwd_min_length" value="<?= (int)setting('pwd_min_length', 8) ?>" min="4"></div>
    <div><label class="check-inline mt"><input type="checkbox" name="pwd_require_number" value="1" <?= setting('pwd_require_number', '1') === '1' ? 'checked' : '' ?>> Require a number</label></div>
    <div><label class="check-inline mt"><input type="checkbox" name="pwd_require_mixed_case" value="1" <?= setting('pwd_require_mixed_case', '0') === '1' ? 'checked' : '' ?>> Require upper &amp; lower case</label></div>
    <button class="btn btn-sm" type="submit">Save</button>
  </form>
</div>
<div class="card">
  <h3>⏰ Auto-logout</h3>
  <p class="muted mb">Signs out an idle session automatically. 0 = never (default).</p>
  <form method="post" class="filterbar">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="save_autologout">
    <div><label>Minutes of inactivity</label><input type="number" name="auto_logout_minutes" value="<?= (int)setting('auto_logout_minutes', 0) ?>" min="0"></div>
    <button class="btn btn-sm" type="submit">Save</button>
  </form>
</div>
<div class="card">
  <h3>🚫 Login attempt limits</h3>
  <p class="muted mb">Blocks further attempts (per IP + username) after this many wrong passwords/codes, for the given lockout window. 0 attempts = no limit.</p>
  <form method="post" class="filterbar">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="save_login_limits">
    <div><label>Max attempts</label><input type="number" name="login_max_attempts" value="<?= (int)setting('login_max_attempts', 5) ?>" min="0"></div>
    <div><label>Lockout window (minutes)</label><input type="number" name="login_lockout_minutes" value="<?= (int)setting('login_lockout_minutes', 15) ?>" min="1"></div>
    <button class="btn btn-sm" type="submit">Save</button>
  </form>
</div>
<div class="card">
  <h3>🌐 IP restriction</h3>
  <p class="muted mb"><strong>Leave blank to allow login from anywhere (default, safe).</strong> If you add addresses, only those can log in - one per line, either a plain IP (<code>103.21.58.10</code>) or a range (<code>192.168.1.0/24</code>). Double-check your own current address is included before saving, or you may lock yourself out.</p>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="save_ip_whitelist">
    <div class="field"><textarea name="ip_whitelist" rows="4" placeholder="103.21.58.10&#10;192.168.1.0/24"><?= e(setting('ip_whitelist', '')) ?></textarea></div>
    <button class="btn btn-sm" type="submit">Save</button>
  </form>
</div>
<?php endif; ?>

<?php if ($cat === 'backup'): ?>
<div class="card">
  <h3>💾 Backup</h3>
  <p class="muted mb">Download a backup of the whole database (.sql file) — keep it saved on Google Drive / a pen drive. Add a passphrase to encrypt the file (AES-256) - leave it blank for a plain .sql file like before.</p>
  <form method="post" action="settings.php" class="filterbar">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="backup">
    <div><label>Passphrase (optional)</label><input type="password" name="passphrase" placeholder="leave blank for plain .sql"></div>
    <button class="btn btn-outline" type="submit">⬇ Download backup</button>
  </form>
</div>

<div class="card">
  <h3>🔐 રોજનું ઓટોમેટિક બેકઅપ</h3>
  <p class="muted mb">રોજનું બેકઅપ આખા ધંધાની નકલ છે — ગ્રાહકો, ભાવ, પાસવર્ડ, બધું. અહીં પાસફ્રેઝ નાખશો એટલે એ ફાઈલ <strong>લોક (AES-256)</strong> થઈને સેવ થશે અને તો જ Telegram પર મોકલાશે. પાસફ્રેઝ વગર ફાઈલ સર્વર પર જ રહે છે અને Telegram પર ફક્ત જાણ જાય છે — ખુલ્લી ફાઈલ ક્યારેય નહીં મોકલાય.</p>
  <p class="muted mb"><strong>પાસફ્રેઝ સાચવીને રાખજો</strong> — એના વગર બેકઅપ ખૂલશે નહીં (ઉપરના "Decrypt a backup file" થી ખૂલે છે).</p>
  <form method="post" action="settings.php" class="filterbar">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="save_backup_auto">
    <div><label>બેકઅપ પાસફ્રેઝ <span class="muted" style="font-weight:normal">(ખાલી = Telegram પર ફાઈલ નહીં)</span></label>
      <input type="text" name="backup_passphrase" value="<?= e(setting('backup_passphrase')) ?>" placeholder="દા.ત. AkC-2026-Backup!"></div>
    <label class="check-inline"><input type="checkbox" name="backup_telegram" value="1" <?= setting('backup_telegram', '1') === '1' ? 'checked' : '' ?>> એન્ક્રિપ્ટેડ ફાઈલ Telegram પર મોકલો</label>
    <label class="check-inline"><input type="checkbox" name="error_alerts" value="1" <?= setting('error_alerts', '1') === '1' ? 'checked' : '' ?>> 🚨 ભૂલ (error) આવે તો Telegram પર તરત જાણ કરો</label>
    <button class="btn" type="submit">Save</button>
  </form>
  <?php $elog = dirname(__FILE__) . '/uploads/logs/error.log';
        $eSize = is_file($elog) ? filesize($elog) : 0;
        $eLast = [];
        if ($eSize > 0) { $lines = @file($elog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []; $eLast = array_slice($lines, -8); } ?>
  <h4 class="mt">🩺 Error log <span class="muted" style="font-weight:normal">(<?= $eSize ? round($eSize / 1024, 1) . ' KB' : 'ખાલી — કોઈ ભૂલ નથી ✅' ?>)</span></h4>
  <?php if ($eLast): ?>
  <div class="table-wrap" style="box-shadow:none"><table class="table-sm"><tbody>
    <?php foreach (array_reverse($eLast) as $ln): ?>
    <tr><td style="font-size:12px;font-family:monospace;word-break:break-all"><?= e(mb_substr($ln, 0, 300)) ?></td></tr>
    <?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
</div>

<div class="card">
  <h3>🔓 Decrypt a backup file</h3>
  <p class="muted mb">Got an encrypted <code>.sql.enc</code> backup and need the plain <code>.sql</code> back? Upload it with its passphrase here.</p>
  <form method="post" action="settings.php" enctype="multipart/form-data" class="filterbar">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="backup_decrypt">
    <div><label>Encrypted file</label><input type="file" name="encfile" accept=".enc" required></div>
    <div><label>Passphrase</label><input type="password" name="passphrase" required></div>
    <button class="btn btn-outline" type="submit">Decrypt & Download</button>
  </form>
</div>

<div class="card">
  <h3>🔗 GitHub Update <span class="badge badge-info">v<?= e(setting('app_version', APP_VERSION)) ?></span></h3>
  <p class="muted mb">Set the repo/branch once, then just "Check for Update" → "Update Now" — code comes straight from GitHub to the server, and the database updates automatically too. <code>config.php</code> (database password etc.) and <code>uploads/</code> (bills, photos) are never touched.</p>
  <form method="post" class="form-row cols-3">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="gh_save">
    <div><label>GitHub repo (owner/repo)</label><input type="text" name="gh_repo" value="<?= e(setting('gh_repo', 'akshaykananidwk/Akcomputer')) ?>" placeholder="akshaykananidwk/Akcomputer"></div>
    <div><label>Branch</label><input type="text" name="gh_branch" value="<?= e(setting('gh_branch', 'claude/multi-location-billing-system-rs1ly6')) ?>" placeholder="main"></div>
    <div><label>GitHub Token <span class="muted" style="font-weight:normal">(required for a private repo, leave blank to keep the existing one)</span></label><input type="password" name="gh_token" placeholder="ghp_xxxxxxxxxxxx"></div>
    <div class="mt" style="grid-column:1/-1"><button class="btn btn-sm btn-outline" type="submit">Save Repo Settings</button></div>
  </form>
  <form method="post" class="mt">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="gh_check">
    <button class="btn btn-sm" type="submit">🔍 Check for Update</button>
  </form>
  <?php if ($ghCheck !== null): if (!$ghCheck['ok']): ?>
    <p class="flash flash-error mt"><?= e($ghCheck['error']) ?></p>
  <?php elseif (!$ghCheck['has_update']): ?>
    <p class="flash flash-success mt">✅ You're already on the latest version (<?= e($ghCheck['short']) ?>).</p>
  <?php else: ?>
    <div class="card mt" style="background:var(--bg)">
      <p><strong>🆕 A new update is available</strong></p>
      <p class="muted">Current: <?= e($ghCheck['current_short'] ?: '(none)') ?> &nbsp;→&nbsp; New: <strong><?= e($ghCheck['short']) ?></strong></p>
      <p class="muted">"<?= e($ghCheck['message']) ?>" — <?= e($ghCheck['author']) ?>, <?= dmyt($ghCheck['date']) ?></p>
      <form method="post" onsubmit="return confirm('Apply the update? Files will be replaced and the database migrated. config.php/uploads will not be touched.')">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="gh_apply">
        <input type="hidden" name="sha" value="<?= e($ghCheck['sha']) ?>">
        <button class="btn btn-success btn-sm" type="submit">✅ Update Now</button>
      </form>
    </div>
  <?php endif; endif; ?>
  <?php $hist = update_history(); if ($hist): ?>
  <h3 class="mt">Update history</h3>
  <table class="table-sm">
    <?php foreach (array_slice($hist, 0, 10) as $h): ?>
    <tr><td><strong><?= strpos($h['version'], 'gh:') === 0 ? e($h['version']) : 'v' . e($h['version']) ?></strong></td><td><?= e($h['applied_at']) ?></td><td><?= (int)$h['files'] ?> files</td><td><?= e($h['by']) ?></td></tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($cat === 'about'): ?>
<div class="card">
  <h3>📱 Install as app (PWA)</h3>
  <p class="muted">Open this website in a mobile browser (Chrome) → menu → <strong>"Add to Home screen"</strong> → it will open full screen, like an app.</p>
</div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
