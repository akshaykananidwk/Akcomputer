<?php
// Settings: app, WhatsApp API, credit terms, test message
require_once __DIR__ . '/includes/init.php';
require_perm('settings.view');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'save') {
    require_perm('settings.edit');
    foreach (['app_name', 'wa_api_url', 'wa_session_id', 'wa_api_key', 'default_tax'] as $k) {
        set_setting($k, post($k));
    }
    set_setting('login_otp', post('login_otp') ? '1' : '0');
    log_activity('settings_save');
    flash('Settings saved.');
    redirect('settings.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'wa_test') {
    require_perm('settings.edit');
    $ok = send_whatsapp(post('test_mobile'), '✅ Test message from ' . setting('app_name', 'AK Computer') . ' billing system. WhatsApp API is working!');
    flash($ok ? 'Test message sent - check WhatsApp.' : 'Send failed. Check API URL / session / key.', $ok ? 'success' : 'error');
    redirect('settings.php');
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
    <label class="check-inline mb"><input type="checkbox" name="login_otp" value="1" <?= setting('login_otp') === '1' ? 'checked' : '' ?>> Login પર WhatsApp OTP ફરજિયાત (2-step)</label>
    <button class="btn" type="submit">Save Settings</button>
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
  <h3>📱 Install as app (PWA)</h3>
  <p class="muted">Mobile browser (Chrome) માં આ website ખોલી → menu → <strong>"Add to Home screen"</strong> → app જેવી રીતે open થશે, full screen.</p>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
