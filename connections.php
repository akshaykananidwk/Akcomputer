<?php
// 🔗 Connections: everything that talks to another app, in one place.
// Passwords and keys entered here are stored encrypted.
require_once __DIR__ . '/includes/init.php';
require_perm('settings.edit');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = post('do');
    if ($do === 'smtp') {
        foreach (['smtp_host', 'smtp_user', 'smtp_from', 'smtp_from_name'] as $k) set_setting($k, trim((string)post($k)));
        set_setting('smtp_port', (string)(in_array((int)post('smtp_port'), [25, 465, 587, 2525], true) ? (int)post('smtp_port') : 587));
        if (post('smtp_pass') !== '') set_setting('smtp_pass', (string)post('smtp_pass'));
        flash('E-mail settings saved.');
    }
    if ($do === 'smtp_test') {
        $to = trim((string)post('test_to')) ?: (string)(current_user()['email'] ?? '');
        $ok = send_mail($to, 'Test e-mail from ' . setting('app_name', 'the shop'), mail_html('E-mail works ✔', '<p>If you can read this, password-reset links and welcome mails will reach people.</p>'));
        flash($ok ? "Test e-mail sent to $to — check the inbox (and spam)." : 'It did not go: ' . mail_last_error(), $ok ? 'success' : 'error');
        redirect('connections.php#smtp');
    }
    if ($do === 'feeds_new') { set_setting('feeds_token', bin2hex(random_bytes(20))); flash('New links made — the old ones stop working.'); }
    if ($do === 'upi_new') { set_setting('upi_hook_secret', bin2hex(random_bytes(20))); flash('New phone link made — put it in the SMS app again.'); }
    if ($do === 'mail') {
        set_setting('imap_host', trim((string)post('imap_host'))); set_setting('imap_user', trim((string)post('imap_user')));
        if (post('imap_pass') !== '') set_setting('imap_pass', (string)post('imap_pass'));
        flash('Mailbox saved.');
    }
    if ($do === 'courier') {
        set_setting('shiprocket_email', trim((string)post('shiprocket_email'))); set_setting('shiprocket_pickup', trim((string)post('shiprocket_pickup')) ?: 'Primary');
        if (post('shiprocket_password') !== '') { set_setting('shiprocket_password', (string)post('shiprocket_password')); set_setting('shiprocket_token', ''); }
        flash('Courier account saved.');
    }
    log_activity('connections_save', $do);
    redirect('connections.php');
}
$feed = setting('feeds_token', ''); $upiKey = setting('upi_hook_secret', '');
$fu = fn($f) => base_url('feeds.php?f=' . $f . '&k=' . $feed);
$page_title = 'Connections';
include __DIR__ . '/includes/header.php';
?>
<a class="settings-back" href="settings.php">← Settings</a>
<div class="page-head"><h1>🔗 Connections</h1></div>

<?php $mc = mail_conf(); $ownSmtp = setting('smtp_host', '') !== ''; ?>
<div class="card" id="smtp"><h3 style="margin-top:0">✉️ E-mail sending</h3>
  <p class="muted">Used for "Forgot password" links, welcome e-mails, new shop sign-ups and bills by e-mail.
  <?= tenant_active() && !$ownSmtp ? ($mc['host'] !== '' ? ' Right now the platform\'s e-mail server is used — fill this only to send from your own address.' : '') : '' ?>
  For Gmail: server <code>smtp.gmail.com</code>, port <code>587</code>, your Gmail and an <b>app password</b> (Google account → Security → 2-step → App passwords). On cPanel hosting: make a mailbox (e.g. no-reply@yourdomain) and use <code>mail.yourdomain</code>, port <code>465</code>.</p>
  <form method="post" class="form-row cols-3"><?= csrf_field() ?><input type="hidden" name="do" value="smtp">
    <div><label>SMTP server</label><input name="smtp_host" value="<?= e(setting('smtp_host', '')) ?>" placeholder="smtp.gmail.com"></div>
    <div><label>Port</label><select name="smtp_port"><?php foreach ([587 => '587 (TLS)', 465 => '465 (SSL)', 25 => '25', 2525 => '2525'] as $pv => $pl): ?><option value="<?= $pv ?>" <?= (int)setting('smtp_port', '587') === $pv ? 'selected' : '' ?>><?= $pl ?></option><?php endforeach; ?></select></div>
    <div><label>Login (e-mail)</label><input name="smtp_user" value="<?= e(setting('smtp_user', '')) ?>" autocomplete="off"></div>
    <div><label>Password <span class="muted"><?= setting('smtp_pass', '') !== '' ? '(saved — blank keeps it)' : '' ?></span></label><input type="password" name="smtp_pass" autocomplete="new-password"></div>
    <div><label>From address</label><input name="smtp_from" value="<?= e(setting('smtp_from', '')) ?>" placeholder="same as login"></div>
    <div><label>From name</label><input name="smtp_from_name" value="<?= e(setting('smtp_from_name', '')) ?>" placeholder="<?= e(setting('app_name', '')) ?>"></div>
    <div><button class="btn btn-sm">Save</button></div></form>
  <form method="post" style="display:flex;gap:6px;flex-wrap:wrap;margin-top:8px"><?= csrf_field() ?><input type="hidden" name="do" value="smtp_test">
    <input type="email" name="test_to" placeholder="send a test to…" value="<?= e(current_user()['email'] ?? '') ?>" style="max-width:260px" required><button class="btn btn-sm btn-outline">📨 Send test e-mail</button></form>
  <?php try { $ml = all('SELECT * FROM mail_log ORDER BY id DESC LIMIT 5'); } catch (Exception $e) { $ml = []; } if ($ml): ?>
  <details class="mt"><summary style="cursor:pointer;font-size:13px">Last e-mails</summary>
    <?php foreach ($ml as $m): ?><div style="font-size:12.5px"><?= $m['ok'] ? '✅' : '❌' ?> <?= dmyt($m['created_at']) ?> · <?= e($m['to_addr']) ?> · <?= e($m['subject']) ?><?= $m['ok'] ? '' : ' — ' . e($m['error']) ?></div><?php endforeach; ?></details>
  <?php endif; ?>
</div>

<div class="card" id="sheets"><h3 style="margin-top:0">📊 Google Sheets &amp; 📅 Google Calendar</h3>
  <?php if ($feed === ''): ?><p class="muted">Make secret links to see sales, stock and dues live in a Google Sheet, and deliveries, AMC, EMIs, cheques and appointments in Google Calendar. No phone numbers or addresses are in them.</p>
  <?php else: ?>
  <p>In a Google Sheet cell, paste:</p>
  <?php foreach (['sales' => 'Sales by day (90 days)', 'items' => 'Items & stock', 'dues' => 'Party balances'] as $k => $l): ?>
    <div class="field"><label><?= $l ?></label><input type="text" readonly value="=IMPORTDATA(&quot;<?= e($fu($k)) ?>&quot;)" onclick="this.select()"></div>
  <?php endforeach; ?>
  <div class="field"><label>Google Calendar → Other calendars → ➕ → From URL</label><input type="text" readonly value="<?= e($fu('calendar')) ?>" onclick="this.select()"></div>
  <?php endif; ?>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="feeds_new"><button class="btn btn-sm <?= $feed ? 'btn-outline' : '' ?>" <?= $feed ? 'onclick="return confirm(\'Make new links? The old links stop working.\')"' : '' ?>><?= $feed ? '🔄 New links (stop the old ones)' : '🔗 Make the links' ?></button></form>
</div>

<div class="card" id="upi"><h3 style="margin-top:0">📲 UPI money, straight from the shop phone</h3>
  <p class="muted">Install any "SMS forwarder" app on the phone that gets the bank's SMS. Set it to send messages from your bank to this link (POST, the message as <code>text</code>). Every credit then waits in <a href="upi.php">UPI received</a> for you to record.</p>
  <?php if ($upiKey !== ''): ?><div class="field"><label>Link for the SMS app</label><input type="text" readonly value="<?= e(base_url('upi_hook.php?k=' . $upiKey)) ?>" onclick="this.select()"></div><?php endif; ?>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="upi_new"><button class="btn btn-sm <?= $upiKey ? 'btn-outline' : '' ?>"><?= $upiKey ? '🔄 New link' : '🔗 Make the link' ?></button></form>
</div>

<div class="card" id="mail"><h3 style="margin-top:0">📧 Supplier bills by e-mail</h3>
  <p class="muted">The mailbox where suppliers send bills. For Gmail: turn on 2-step login and make an <b>app password</b>; server <code>imap.gmail.com</code>. Bills appear in <a href="mail_bills.php">Bills by e-mail</a>.</p>
  <form method="post" class="form-row cols-3"><?= csrf_field() ?><input type="hidden" name="do" value="mail">
    <div><label>Mail server</label><input name="imap_host" value="<?= e(setting('imap_host', '')) ?>" placeholder="imap.gmail.com"></div>
    <div><label>E-mail</label><input name="imap_user" value="<?= e(setting('imap_user', '')) ?>"></div>
    <div><label>App password <span class="muted"><?= setting('imap_pass', '') !== '' ? '(saved)' : '' ?></span></label><input type="password" name="imap_pass" autocomplete="off"></div>
    <div><button class="btn btn-sm">Save</button></div></form>
</div>

<div class="card" id="courier"><h3 style="margin-top:0">🚛 Courier (Shiprocket)</h3>
  <p class="muted">Your Shiprocket login (Settings → API user in Shiprocket is best). Then open any bill → 📦 Ship by courier.</p>
  <form method="post" class="form-row cols-3"><?= csrf_field() ?><input type="hidden" name="do" value="courier">
    <div><label>Shiprocket e-mail</label><input name="shiprocket_email" value="<?= e(setting('shiprocket_email', '')) ?>"></div>
    <div><label>Password <span class="muted"><?= setting('shiprocket_password', '') !== '' ? '(saved)' : '' ?></span></label><input type="password" name="shiprocket_password" autocomplete="off"></div>
    <div><label>Pickup address name</label><input name="shiprocket_pickup" value="<?= e(setting('shiprocket_pickup', 'Primary')) ?>"></div>
    <div><button class="btn btn-sm">Save</button></div></form>
</div>

<div class="card"><h3 style="margin-top:0">⚡ Zapier / Make and 5,000 other apps</h3>
  <p>Send events (new bill, payment received…) out with <a href="webhooks.php">Webhooks</a> — in Zapier choose <b>Webhooks by Zapier → Catch Hook</b> and paste its address there. To write INTO this software from Zapier, make an API token in <a href="my_account.php?tab=api">My Account → API Tokens</a> and call <code><?= e(base_url('api.php')) ?></code>.</p>
</div>

<div class="card"><h3 style="margin-top:0">✈️ Telegram</h3><p>Owner alerts, daily backup and the shop bot on Telegram are set in <a href="settings.php?cat=whatsapp">Settings</a>.</p></div>

<div class="card"><h3 style="margin-top:0">📱 The app on phones</h3>
  <p>Open this website in Chrome on the phone → ⋮ → <b>Install app</b> (iPhone: Share → Add to Home Screen). It installs with <b><?= e(setting('app_name', '')) ?></b>'s own name and logo.</p>
  <p class="muted">For the Play Store: make the store listing from this website with PWABuilder (pwabuilder.com → enter <?= e(base_url('')) ?> → Android), then upload it in your Google Play developer account (one-time ₹2,000 fee).<?= !tenant_active() ? ' The ready Android app (APK) is in the <code>mobile/</code> folder.' : '' ?></p></div>
<?php include __DIR__ . '/includes/footer.php'; ?>
