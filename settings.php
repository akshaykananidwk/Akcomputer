<?php
// Settings: app, WhatsApp API, credit terms, bill design, backup, software updates
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/version.php';
require_once __DIR__ . '/includes/updater.php';
require_perm('settings.view');

// ---- software update: save key / apply package ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'update_key') {
    require_perm('settings.edit');
    set_setting('update_key', post('update_key'));
    flash('Update Key saved.');
    redirect('settings.php');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'apply_update') {
    require_perm('settings.edit');
    $key = setting('update_key', '');
    if ($key === '') {
        flash('પહેલા Update Key set કરો.', 'error');
    } elseif (empty($_FILES['pkg']['tmp_name'])) {
        flash('.akupd file select કરો.', 'error');
    } else {
        list($ok, $msg) = akupd_apply($_FILES['pkg']['tmp_name'], $key);
        flash($msg, $ok ? 'success' : 'error');
    }
    redirect('settings.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'save') {
    require_perm('settings.edit');
    foreach (['app_name', 'wa_api_url', 'wa_session_id', 'wa_api_key', 'default_tax', 'wa_shop_number', 'review_api_url', 'review_api_key'] as $k) {
        set_setting($k, post($k));
    }
    set_setting('login_otp', post('login_otp') ? '1' : '0');
    set_setting('allow_negative_stock', post('allow_negative_stock') ? '1' : '0');
    log_activity('settings_save');
    flash('Settings saved.');
    redirect('settings.php');
}

// ---- WhatsApp message templates ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'templates') {
    require_perm('settings.edit');
    foreach (array_keys(wa_template_defaults()) as $k) {
        set_setting('wa_tpl_' . $k, post('tpl_' . $k));
    }
    log_activity('wa_templates_save');
    flash('Message templates saved.');
    redirect('settings.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'design') {
    require_perm('settings.edit');
    set_setting('invoice_theme', (int)post('invoice_theme'));
    flash('Bill design બદલાઈ ગઈ ✔ — કોઈ પણ bill ખોલીને જુઓ.');
    redirect('settings.php');
}

if (setting('cron_key', '') === '') set_setting('cron_key', bin2hex(random_bytes(12)));
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'reminder_gap') {
    require_perm('settings.edit');
    set_setting('reminder_gap_days', max(1, (int)post('gap')));
    flash('Reminder gap saved.');
    redirect('settings.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'wa_test') {
    require_perm('settings.edit');
    $ok = send_whatsapp(post('test_mobile'), '✅ Test message from ' . setting('app_name', 'AK Computer') . ' billing system. WhatsApp API is working!');
    flash($ok ? 'Test message sent - check WhatsApp.' : 'Send failed. Check API URL / session / key.', $ok ? 'success' : 'error');
    redirect('settings.php');
}

// full database backup download (plain PHP SQL dump - works on shared hosting)
if (get('do') === 'backup') {
    require_perm('settings.edit');
    $pdo = db();
    header('Content-Type: application/sql');
    header('Content-Disposition: attachment; filename="backup_' . DB_NAME . '_' . date('Ymd_His') . '.sql"');
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
    log_activity('backup_download');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'term_add') {
    require_perm('settings.edit');
    q('INSERT INTO credit_terms (days, label) VALUES (?, ?)', [(int)post('days'), post('label') ?: ((int)post('days') . ' days')]);
    flash('Credit term added.');
    redirect('settings.php');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'term_del') {
    require_perm('settings.edit');
    q('DELETE FROM credit_terms WHERE id = ?', [(int)post('id')]);
    flash('Credit term removed.');
    redirect('settings.php');
}

$terms = all('SELECT * FROM credit_terms ORDER BY days');
$page_title = 'Settings';
include __DIR__ . '/includes/header.php';
?>
<div class="card">
  <h2>General & WhatsApp API</h2>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="save">
    <div class="form-row cols-2">
      <div><label>App / shop name</label><input type="text" name="app_name" value="<?= e(setting('app_name')) ?>"></div>
      <div><label>Default GST %</label><input type="number" step="any" name="default_tax" value="<?= e(setting('default_tax', '18')) ?>"></div>
    </div>
    <div class="form-row cols-3">
      <div><label>WhatsApp API URL</label><input type="text" name="wa_api_url" value="<?= e(setting('wa_api_url', 'https://bulk.akdwk.in/api.php')) ?>"></div>
      <div><label>Session ID</label><input type="text" name="wa_session_id" value="<?= e(setting('wa_session_id')) ?>"></div>
      <div><label>API Key</label><input type="text" name="wa_api_key" value="<?= e(setting('wa_api_key')) ?>"></div>
    </div>
    <div class="form-row cols-2">
      <div><label>Shop WhatsApp number (website catalog ના "Order" button માટે)</label>
        <input type="tel" name="wa_shop_number" value="<?= e(setting('wa_shop_number')) ?>" placeholder="91XXXXXXXXXX"></div>
    </div>
    <div class="form-row cols-2">
      <div><label>Google Review API URL</label><input type="text" name="review_api_url" value="<?= e(setting('review_api_url', 'https://review.akdwk.in/api/v1/trigger_invite.php')) ?>"></div>
      <div><label>Review API Key</label><input type="text" name="review_api_key" value="<?= e(setting('review_api_key')) ?>"></div>
    </div>
    <label class="check-inline mb"><input type="checkbox" name="login_otp" value="1" <?= setting('login_otp') === '1' ? 'checked' : '' ?>> Login પર WhatsApp OTP ફરજિયાત (2-step)</label>
    <label class="check-inline mb"><input type="checkbox" name="allow_negative_stock" value="1" <?= setting('allow_negative_stock', '1') === '1' ? 'checked' : '' ?>> Purchase વગર sale કરવા દેવું (negative stock allowed)</label>
    <button class="btn" type="submit">Save Settings</button>
  </form>
</div>

<div class="card">
  <h3>💬 WhatsApp Message Templates</h3>
  <p class="muted mb">દરેક message તમારી રીતે લખો. Variables જેમ છે એમ જ રાખવા — મોકલતી વખતે સાચી value થી બદલાઈ જશે. ખાલી છોડો તો default વપરાશે.</p>
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

<div class="card">
  <h3>🎨 Bill / Invoice Design (<?= count(invoice_themes()) ?> designs)</h3>
  <p class="muted mb">Design પસંદ કરો — બધા bills, estimates અને challans પર લાગુ થશે.</p>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="design">
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px">
      <?php $cur = (int)setting('invoice_theme', '1');
      foreach (invoice_themes() as $tid => $th): ?>
      <label style="border:2px solid <?= $cur === $tid ? 'var(--primary)' : 'var(--border)' ?>;border-radius:10px;padding:10px;cursor:pointer;display:block">
        <input type="radio" name="invoice_theme" value="<?= $tid ?>" <?= $cur === $tid ? 'checked' : '' ?> style="width:auto"> <strong style="font-size:13px"><?= e($th[0]) ?></strong>
        <div style="margin-top:6px;border:1px solid var(--border);border-radius:6px;overflow:hidden">
          <div style="background:<?= e($th[2]) ?>;height:16px"></div>
          <div style="padding:5px;font-size:9px;line-height:1.5;color:#475569">
            INVOICE #001<br>
            <span style="display:inline-block;width:70%;height:4px;background:#e2e8f0"></span><br>
            <span style="display:inline-block;width:50%;height:4px;background:#e2e8f0"></span>
          </div>
        </div>
      </label>
      <?php endforeach; ?>
    </div>
    <button class="btn mt" type="submit">Apply Design</button>
  </form>
</div>

<div class="card">
  <h3>⏰ Auto Overdue Reminders (cron)</h3>
  <?php $cronUrl = base_url('cron.php?key=' . setting('cron_key')); ?>
  <p class="muted mb">Due date વીતી ગયેલા unpaid bills પર આપોઆપ WhatsApp reminder જાય. Hosting ના cPanel → Cron Jobs માં રોજ એક વાર આ URL ચલાવવા મૂકો:</p>
  <p class="mb"><code style="word-break:break-all;background:var(--bg);padding:8px;border-radius:8px;display:block"><?= e($cronUrl) ?></code></p>
  <p class="muted mb">cPanel command: <code>wget -qO- "<?= e($cronUrl) ?>"</code> (દા.ત. રોજ સવારે 10:00)</p>
  <form method="post" class="filterbar">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="reminder_gap">
    <div><label>એક જ bill પર ફરી reminder કેટલા દિવસે?</label><input type="number" name="gap" min="1" value="<?= (int)setting('reminder_gap_days', '3') ?>"></div>
    <button class="btn btn-sm" type="submit">Save</button>
    <a class="btn btn-sm btn-outline" href="<?= e($cronUrl) ?>" target="_blank">▶ અત્યારે Test Run</a>
  </form>
</div>

<div class="card">
  <h3>💾 Backup</h3>
  <p class="muted mb">આખા database નો backup (.sql file) download કરો — Google Drive / pen drive માં સાચવી રાખો.</p>
  <a class="btn btn-outline" href="settings.php?do=backup">⬇ Download full backup</a>
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
  <h3>🛠️ Database Update <span class="badge badge-warn">Files FTP/cPanel થી upload કર્યા હોય તો</span></h3>
  <p class="muted mb">જો files સીધી server પર (FTP / cPanel File Manager થી) upload કરી હોય — "Apply Update" વાળી નીચેની રીતથી નહીં — તો database એ નવી files ને અનુરૂપ update કરવાનું ભૂલાઈ શકે, અને pages બરાબર ના ચાલે. નીચેની link ખોલો એટલે <strong>આપોઆપ</strong> database update થઈ જાય — કંઈ ક્લિક/button નહીં, ફક્ત link ખોલવાની. જૂનો ડેટા (bills, parties, બધું) સચવાય જ છે, ફક્ત ખૂટતું ઉમેરાય છે. આ link save/bookmark કરી રાખો — Files upload કર્યા પછી હંમેશા આ ખોલી લેવાની, ગમે એટલી વાર ખોલવામાં કંઈ નુકસાન નથી:</p>
  <a class="btn btn-outline" href="install/migrate.php" target="_blank">⚡ Database Update Link ખોલો</a>
</div>

<div class="card">
  <h3>🔄 Software Update <span class="badge badge-info">v<?= e(setting('app_version', APP_VERSION)) ?></span></h3>
  <p class="muted mb">Encrypted update file (<code>.akupd</code>) અહીં upload કરો — files + database બધું આપોઆપ update થઈ જશે. Update Key બંને બાજુ સરખી હોવી જોઈએ (ખોટી key વાળી કે બગડેલી file લાગશે નહીં).</p>
  <form method="post" class="filterbar">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="update_key">
    <div><label>Update Key (secret)</label><input type="password" name="update_key" value="<?= e(setting('update_key')) ?>" placeholder="secret key"></div>
    <button class="btn btn-sm btn-outline" type="submit">Save Key</button>
  </form>
  <form method="post" enctype="multipart/form-data" class="filterbar mt" onsubmit="return confirm('Update લગાડવું છે? પહેલા backup લેવાની સલાહ છે.')">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="apply_update">
    <div><label>.akupd update file</label><input type="file" name="pkg" accept=".akupd" required></div>
    <button class="btn btn-sm" type="submit">⬆ Apply Update</button>
  </form>
  <?php $hist = update_history(); if ($hist): ?>
  <h3 class="mt">Update history</h3>
  <table class="table-sm">
    <?php foreach (array_slice($hist, 0, 10) as $h): ?>
    <tr><td><strong>v<?= e($h['version']) ?></strong></td><td><?= e($h['applied_at']) ?></td><td><?= (int)$h['files'] ?> files</td><td><?= e($h['by']) ?></td></tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
</div>

<div class="card">
  <h3>📱 Install as app (PWA)</h3>
  <p class="muted">Mobile browser (Chrome) માં આ website ખોલી → menu → <strong>"Add to Home screen"</strong> → app જેવી રીતે open થશે, full screen.</p>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
