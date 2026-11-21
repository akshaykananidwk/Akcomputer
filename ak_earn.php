<?php
// 🤝 AK Earn - connect this shop to the central partner / commission system.
// The owner's own address only (other shops on the platform have their own
// business), and only for someone who may change settings. The secret is
// stored encrypted and is never shown again or sent to the browser.
require_once __DIR__ . '/includes/init.php';
require_perm('settings.edit');
if (!ak_earn_own_site()) { http_response_code(404); exit('Not here.'); }

$fromConfig = defined('AK_SITE_KEY') || defined('AK_SITE_SECRET');
$result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = post('do');
    if ($do === 'save' && !$fromConfig) {
        set_setting('ak_earn_site_key', trim((string)post('site_key')));
        if (trim((string)post('site_secret')) !== '') set_setting('ak_earn_site_secret', trim((string)post('site_secret')));
        set_setting('ak_earn_off', post('on') ? '0' : '1');
        log_activity('ak_earn_settings', 'AK Earn connection saved');   // never the secret
        flash('AK Earn settings saved.');
        redirect('ak_earn.php');
    }
    if ($do === 'ping' || $do === 'sync') {
        if (!ak_earn_boot()) {
            flash('Fill in the site key and the secret (and keep it switched on) first.', 'error');
            redirect('ak_earn.php');
        }
        try {
            $result = $do === 'ping' ? ak_ping() : ak_earn_sync_all();
        } catch (Throwable $e) {
            $result = ['status' => 'error', 'message' => $e->getMessage()];
        }
        log_activity('ak_earn_' . $do, (string)($result['status'] ?? '?'));
    }
}

$c = ak_earn_conf();
$queueN = 0;
if (ak_earn_boot()) {
    $qf = ak_queue_file();
    $queueN = is_file($qf) ? count(file($qf, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : 0;
}
$page_title = 'AK Earn';
include __DIR__ . '/includes/header.php';
?>
<div class="card">
  <h2>🤝 AK Earn — partner commissions</h2>
  <p class="muted">Sends sign-ups, website orders and software plan payments to <b><?= e($c['url']) ?></b>. The master decides which partner earns what; this shop never sends a commission amount.</p>
  <p>Status: <?= ak_earn_ready() ? '<span class="badge badge-ok">Connected (switched on)</span>' : '<span class="badge badge-bad">Not connected</span>' ?>
    <?= $queueN ? ' · <span class="badge badge-warn">' . $queueN . ' event(s) waiting to be re-sent</span>' : '' ?>
    <?php $syn = setting('ak_earn_synced_at', ''); ?><?= $syn ? ' · products last synced ' . e($syn) : '' ?></p>

  <?php if ($result !== null): ?>
  <div class="flash <?= ($result['status'] ?? '') === 'ok' ? 'flash-success' : 'flash-error' ?>">
    <b><?= post('do') === 'ping' ? 'Connection test' : 'Product sync' ?>:</b>
    <?= e((string)($result['status'] ?? '?')) ?><?= isset($result['message']) ? ' — ' . e((string)$result['message']) : '' ?>
    <?= isset($result['site']) ? ' · site: ' . e((string)$result['site']) : '' ?>
    <?= isset($result['sent']) ? ' · sent ' . (int)$result['sent'] . ', synced ' . (int)$result['synced'] : '' ?>
    <?php if (!empty($result['errors'])): ?><br><small><?= e(implode(' · ', array_map('strval', $result['errors']))) ?></small><?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if ($fromConfig): ?>
  <p class="flash flash-info">The site key and secret come from <code>config.php</code> (AK_SITE_KEY / AK_SITE_SECRET), so they are not edited here.</p>
  <?php else: ?>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="do" value="save">
    <div class="form-row cols-2">
      <div><label>Site key <span class="muted" style="font-weight:normal">(AK Earn admin → Sites; starts with sk_)</span></label>
        <input type="text" name="site_key" value="<?= e($c['key']) ?>" autocomplete="off"></div>
      <div><label>Site secret <span class="muted" style="font-weight:normal">(stored encrypted; blank keeps the saved one)</span></label>
        <input type="password" name="site_secret" value="" autocomplete="new-password" placeholder="<?= $c['secret'] !== '' ? '•••••••• saved' : '' ?>"></div>
    </div>
    <label class="check-inline"><input type="checkbox" name="on" value="1" <?= $c['off'] ? '' : 'checked' ?>> Switched on (untick to stop sending without losing the keys)</label>
    <div class="mt"><button class="btn" type="submit">Save</button></div>
  </form>
  <?php endif; ?>
  <div class="page-actions mt">
    <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="do" value="ping"><button class="btn btn-outline" type="submit">🔌 Test connection (ak_ping)</button></form>
    <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="do" value="sync"><button class="btn btn-outline" type="submit">🔄 Sync products now</button></form>
  </div>
</div>
<div class="card">
  <h3>What is sent, and when</h3>
  <ul>
    <li><b>Sign-up</b> — a dealer registers on the online store; a new shop signs up for the software.</li>
    <li><b>Order created</b> — a website / shop-app order is placed, or a plan payment link is made. Nothing is paid yet, so no commission.</li>
    <li><b>Payment</b> — a website order is marked <b>Completed</b>; a plan payment arrives through Razorpay (the shop's first plan is a sale, later ones are renewals). This is what creates commission.</li>
    <li><b>Cancel / refund</b> — a website order is marked Cancelled (a refund if it had been completed).</li>
    <li><b>Products</b> — the website items and the software plans, after an item is saved and once a day.</li>
  </ul>
  <p class="muted">Everything is sent after the page has already answered, so a slow or offline master never slows the shop down. Anything that fails is kept and re-sent every hour (Cron Manager → AK Earn sync).</p>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
