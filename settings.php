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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'save_invoice') {
    require_perm('settings.edit');
    foreach (['google_review_link', 'razorpay_key_id', 'razorpay_key_secret'] as $k) set_setting($k, post($k));
    log_activity('settings_save');
    flash('Invoice settings saved.');
    redirect('settings.php?cat=invoice');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'design') {
    require_perm('settings.edit');
    set_setting('invoice_theme', (int)post('invoice_theme'));
    flash('Bill design બદલાઈ ગઈ ✔ — કોઈ પણ bill ખોલીને જુઓ.');
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
    redirect('settings.php?cat=party');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'term_del') {
    require_perm('settings.edit');
    q('DELETE FROM credit_terms WHERE id = ?', [(int)post('id')]);
    flash('Credit term removed.');
    redirect('settings.php?cat=party');
}

$terms = all('SELECT * FROM credit_terms ORDER BY days');
$page_title = 'Settings';
include __DIR__ . '/includes/header.php';

// Categories shown as a tappable list (like Vyapar's Settings screen) -
// each opens its own section instead of one long confusing page.
$categories = [
    'general'   => ['⚙️', 'General', 'App name, GST %, login security'],
    'whatsapp'  => ['💬', 'WhatsApp', 'API connection, templates, test send'],
    'invoice'   => ['🎨', 'Invoice / Bill', 'Design, Google review, online payment'],
    'reminders' => ['⏰', 'Reminders', 'Auto overdue payment reminders'],
    'party'     => ['👥', 'Party', 'Credit term options'],
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
    <label class="check-inline mb"><input type="checkbox" name="login_otp" value="1" <?= setting('login_otp') === '1' ? 'checked' : '' ?>> Login પર WhatsApp OTP ફરજિયાત (2-step)</label>
    <label class="check-inline mb"><input type="checkbox" name="allow_negative_stock" value="1" <?= setting('allow_negative_stock', '1') === '1' ? 'checked' : '' ?>> Purchase વગર sale કરવા દેવું (negative stock allowed)</label>
    <button class="btn" type="submit">Save</button>
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
      <div><label>Shop WhatsApp number (website catalog ના "Order" button માટે)</label>
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
  <h3>💬 Message Templates</h3>
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
<?php endif; ?>

<?php if ($cat === 'invoice'): ?>
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
  <h3>Google Review & Online Payment</h3>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="save_invoice">
    <div class="form-row cols-2">
      <div><label>Google Review Link <span class="muted" style="font-weight:normal">(bill પરથી "Review Invite" દબાવો એટલે આ link સીધો WhatsApp થાય)</span></label>
        <input type="text" name="google_review_link" value="<?= e(setting('google_review_link')) ?>" placeholder="https://g.page/r/xxxxxxx/review"></div>
    </div>
    <div class="form-row cols-2">
      <div><label>Razorpay Key ID <span class="muted" style="font-weight:normal">(bill પર online payment link માટે, optional)</span></label><input type="text" name="razorpay_key_id" value="<?= e(setting('razorpay_key_id')) ?>" placeholder="rzp_live_..."></div>
      <div><label>Razorpay Key Secret</label><input type="password" name="razorpay_key_secret" value="<?= e(setting('razorpay_key_secret')) ?>"></div>
    </div>
    <button class="btn" type="submit">Save</button>
  </form>
</div>
<?php endif; ?>

<?php if ($cat === 'reminders'): ?>
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
<?php endif; ?>

<?php if ($cat === 'backup'): ?>
<div class="card">
  <h3>💾 Backup</h3>
  <p class="muted mb">આખા database નો backup (.sql file) download કરો — Google Drive / pen drive માં સાચવી રાખો.</p>
  <a class="btn btn-outline" href="settings.php?do=backup">⬇ Download full backup</a>
</div>

<div class="card">
  <h3>🔗 GitHub Update <span class="badge badge-info">v<?= e(setting('app_version', APP_VERSION)) ?></span></h3>
  <p class="muted mb">Repo/branch એકવાર set કરો, પછી ફક્ત "Check for Update" → "Update Now" — code GitHub પરથી સીધો server પર આવી જશે, database પણ આપોઆપ update થઈ જશે. <code>config.php</code> (database password વગેરે) અને <code>uploads/</code> (bills, photos) ને ક્યારેય touch નહીં કરે.</p>
  <form method="post" class="form-row cols-3">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="gh_save">
    <div><label>GitHub repo (owner/repo)</label><input type="text" name="gh_repo" value="<?= e(setting('gh_repo', 'akshaykananidwk/Akcomputer')) ?>" placeholder="akshaykananidwk/Akcomputer"></div>
    <div><label>Branch</label><input type="text" name="gh_branch" value="<?= e(setting('gh_branch', 'claude/multi-location-billing-system-rs1ly6')) ?>" placeholder="main"></div>
    <div><label>GitHub Token <span class="muted" style="font-weight:normal">(private repo હોય તો જરૂરી, ખાલી છોડો તો જૂનો રહેશે)</span></label><input type="password" name="gh_token" placeholder="ghp_xxxxxxxxxxxx"></div>
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
    <p class="flash flash-success mt">✅ તમે latest version પર જ છો (<?= e($ghCheck['short']) ?>).</p>
  <?php else: ?>
    <div class="card mt" style="background:var(--bg)">
      <p><strong>🆕 નવું update ઉપલબ્ધ છે</strong></p>
      <p class="muted">અત્યારે: <?= e($ghCheck['current_short'] ?: '(none)') ?> &nbsp;→&nbsp; નવું: <strong><?= e($ghCheck['short']) ?></strong></p>
      <p class="muted">"<?= e($ghCheck['message']) ?>" — <?= e($ghCheck['author']) ?>, <?= dmyt($ghCheck['date']) ?></p>
      <form method="post" onsubmit="return confirm('Update લગાડવું છે? Files replace થશે ને database migrate થશે. config.php/uploads touch નહીં થાય.')">
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
  <p class="muted">Mobile browser (Chrome) માં આ website ખોલી → menu → <strong>"Add to Home screen"</strong> → app જેવી રીતે open થશે, full screen.</p>
</div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
