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
    log_activity('settings_save');
    flash('WhatsApp settings saved.');
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
    log_activity('settings_save');
    flash('Transaction settings saved.');
    redirect('settings.php?cat=transaction');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'save_invoice') {
    require_perm('settings.edit');
    foreach (['google_review_link', 'razorpay_key_id', 'razorpay_key_secret', 'razorpay_webhook_secret', 'ocr_api_key', 'gemini_api_key', 'gcs_api_key', 'gcs_cx'] as $k) set_setting($k, post($k));
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
    flash('Reminder gap saved.');
    redirect('settings.php?cat=reminders');
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
    $pdo = db();
    ob_start();
    echo "-- AK Computer backup " . date('Y-m-d H:i:s') . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n";
    $tables = array_column($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM), 0);
    foreach ($tables as $t) {
        $create = $pdo->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_NUM);
        echo "DROP TABLE IF EXISTS `$t`;\n" . $create[1] . ";\n\n";
        $rs = $pdo->query("SELECT * FROM `$t`");
        while ($rowD = $rs->fetch(PDO::FETCH_NUM)) {
            $vals = array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v), $rowD);
            echo "INSERT INTO `$t` VALUES (" . implode(',', $vals) . ");\n";
        }
        echo "\n";
    }
    echo "SET FOREIGN_KEY_CHECKS=1;\n";
    $sql = ob_get_clean();

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
        q('INSERT INTO item_custom_fields (label, sort_order, is_active) VALUES (?, ?, 1)', [$label, $next]);
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
    'invoice'   => ['🎨', 'Invoice / Bill', 'Design, Google review, online payment'],
    'reminders' => ['⏰', 'Reminders', 'Auto overdue payment reminders'],
    'party'     => ['👥', 'Party', 'Credit term options'],
    'accounting' => ['📒', 'Accounting', 'Period lock, Chart of Accounts, Journal Entries'],
    'service'   => ['🔧', 'Service Checklist', 'Repair job checklist items'],
    'inventory' => ['📦', 'Inventory', 'Costing method, dead-stock threshold, audit/bins/transfers/reservations'],
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
    <label class="check-inline mb"><input type="checkbox" name="show_profit_billing" value="1" <?= setting('show_profit_billing') === '1' ? 'checked' : '' ?>> Show Profit while making Sale Invoice</label>
    <label class="check-inline mb"><input type="checkbox" name="show_purchase_price_billing" value="1" <?= setting('show_purchase_price_billing') === '1' ? 'checked' : '' ?>> Display Purchase Price of Items <span class="muted" style="font-weight:normal">(in the item search list while billing)</span></label>
    <label class="check-inline mb"><input type="checkbox" name="add_time_transactions" value="1" <?= setting('add_time_transactions', '1') === '1' ? 'checked' : '' ?>> Add Time on Transactions <span class="muted" style="font-weight:normal">(shows Time next to the bill's Date - view, PDF, WhatsApp image)</span></label>
    <button class="btn" type="submit">Save</button>
  </form>
</div>

<div class="card">
  <h3>Item Custom Fields</h3>
  <p class="muted mb">Extra labeled fields (e.g. "Exp. Date", "Brand") that show up under every item while adding it to a sale. Add as many as you need.</p>
  <table class="table-sm mb">
    <?php foreach ($customFields as $cf): ?>
    <tr>
      <td><?= e($cf['label']) ?></td>
      <td class="right">
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
  <h3>Test WhatsApp API</h3>
  <form method="post" class="filterbar">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="wa_test">
    <div><input type="tel" name="test_mobile" placeholder="10-digit mobile" required></div>
    <button class="btn btn-wa btn-sm" type="submit">Send test</button>
  </form>
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
  <p class="muted mb">Unpaid bills past their due date automatically get a WhatsApp reminder. In your hosting's cPanel → Cron Jobs, set this URL to run once a day:</p>
  <p class="mb"><code style="word-break:break-all;background:var(--bg);padding:8px;border-radius:8px;display:block"><?= e($cronUrl) ?></code></p>
  <p class="muted mb">cPanel command: <code>wget -qO- "<?= e($cronUrl) ?>"</code> (e.g. every day at 10:00 AM)</p>
  <form method="post" class="filterbar">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="reminder_gap">
    <div><label>Days before re-reminding the same bill?</label><input type="number" name="gap" min="1" value="<?= (int)setting('reminder_gap_days', '3') ?>"></div>
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
  <a class="btn btn-sm btn-outline" href="bins.php">Bin / Rack Locations</a>
  <a class="btn btn-sm btn-outline" href="transfers.php">Warehouse Transfers</a>
  <a class="btn btn-sm btn-outline" href="reservations.php">Stock Reservations</a>
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
