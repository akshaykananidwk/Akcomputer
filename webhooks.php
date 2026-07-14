<?php
// Outbound webhooks admin - notify a third-party URL (Zapier, a custom
// integration, another system) when key events happen in this app. See
// includes/webhooks.php's fire_webhook() for the events currently wired up:
// sale.created, payment.recorded, repair.status_changed.
require_once __DIR__ . '/includes/init.php';
require_perm('webhooks.view');

$id = (int)get('id');
$EVENTS = ['sale.created' => 'Sale Created', 'payment.recorded' => 'Payment Recorded', 'repair.status_changed' => 'Repair Status Changed'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'save') {
    require_perm($id ? 'webhooks.edit' : 'webhooks.add');
    $events = implode(',', array_intersect((array)post('events', []), array_merge(array_keys($EVENTS), ['*'])));
    if ($id) {
        q('UPDATE webhooks SET name=?, url=?, events=?, is_active=? WHERE id=?',
          [post('name'), post('url'), $events, post('is_active') ? 1 : 0, $id]);
        flash('Webhook updated.');
    } else {
        $secret = bin2hex(random_bytes(20));
        q('INSERT INTO webhooks (name, url, secret_enc, events, is_active, created_by) VALUES (?,?,?,?,?,?)',
          [post('name'), post('url'), vault_encrypt($secret), $events, post('is_active') ? 1 : 0, current_user()['id']]);
        flash('Webhook added. Its signing secret is shown once you open "Edit".');
    }
    log_activity('webhook_save', post('name'));
    redirect('webhooks.php');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'delete') {
    require_perm('webhooks.delete');
    $wid = (int)post('id');
    q('DELETE FROM webhook_deliveries WHERE webhook_id = ?', [$wid]);
    q('DELETE FROM webhooks WHERE id = ?', [$wid]);
    flash('Webhook deleted.');
    redirect('webhooks.php');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'test') {
    require_perm('webhooks.edit');
    $h = row('SELECT * FROM webhooks WHERE id = ?', [(int)post('id')]);
    if ($h) {
        webhook_deliver($h, 'webhook.test', ['message' => 'Test delivery from AK Computer', 'sent_at' => date('c')]);
        flash('Test event sent to ' . $h['name'] . ' - check its "Last delivery" status below.');
    }
    redirect('webhooks.php');
}

$wh = $id ? row('SELECT * FROM webhooks WHERE id = ?', [$id]) : null;
$hooks = all('SELECT * FROM webhooks ORDER BY id DESC');
foreach ($hooks as &$h) {
    $h['recent'] = all('SELECT * FROM webhook_deliveries WHERE webhook_id = ? ORDER BY id DESC LIMIT 5', [$h['id']]);
}
unset($h);
$page_title = 'Webhooks';
include __DIR__ . '/includes/header.php';
?>
<?php if (can('webhooks.add') || can('webhooks.edit')): ?>
<div class="card">
  <h2><?= $wh ? 'Edit Webhook' : 'Add Webhook' ?></h2>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="save">
    <?php if ($wh): ?>
    <div class="field"><label>Signing secret <span class="muted" style="font-weight:normal">(send this as HMAC-SHA256 verification: header <code>X-Webhook-Signature: sha256=&lt;hex&gt;</code> over the raw request body)</span></label>
      <div style="font-family:monospace;font-size:14px;word-break:break-all;background:var(--card-alt,rgba(0,0,0,.04));padding:8px;border-radius:8px"><?= e(vault_decrypt($wh['secret_enc'])) ?></div>
    </div>
    <?php endif; ?>
    <div class="form-row cols-2">
      <div><label>Name *</label><input type="text" name="name" value="<?= e($wh['name'] ?? '') ?>" required></div>
      <div><label>URL *</label><input type="url" name="url" value="<?= e($wh['url'] ?? '') ?>" placeholder="https://..." required></div>
    </div>
    <div class="field mb"><label>Events</label>
      <?php $selEvents = $wh ? explode(',', $wh['events']) : []; ?>
      <label class="check-inline"><input type="checkbox" name="events[]" value="*" <?= in_array('*', $selEvents, true) ? 'checked' : '' ?>> All events</label><br>
      <?php foreach ($EVENTS as $k => $label): ?>
      <label class="check-inline"><input type="checkbox" name="events[]" value="<?= $k ?>" <?= in_array($k, $selEvents, true) ? 'checked' : '' ?>> <?= e($label) ?></label><br>
      <?php endforeach; ?>
    </div>
    <label class="check-inline mb"><input type="checkbox" name="is_active" value="1" <?= ($wh === null || $wh['is_active']) ? 'checked' : '' ?>> Active</label>
    <button class="btn" type="submit">Save</button>
    <?php if ($wh): ?><a class="btn btn-muted" href="webhooks.php">Cancel edit</a><?php endif; ?>
  </form>
</div>
<?php endif; ?>

<?php foreach ($hooks as $h): ?>
<div class="card">
  <h3><?= e($h['name']) ?> <?= $h['is_active'] ? '<span class="badge badge-ok">active</span>' : '<span class="badge badge-bad">off</span>' ?></h3>
  <p class="muted"><?= e($h['url']) ?></p>
  <p class="muted">Events: <?= e($h['events'] === '*' ? 'All events' : ($h['events'] ?: 'none selected')) ?></p>
  <p class="muted">Last delivery: <?= $h['last_triggered_at'] ? e($h['last_status']) . ' · ' . dmyt($h['last_triggered_at']) : 'never' ?></p>
  <?php if ($h['recent']): ?>
  <table class="table-sm">
    <thead><tr><th>Event</th><th>Status</th><th>When</th></tr></thead>
    <tbody><?php foreach ($h['recent'] as $d): ?>
    <tr><td><?= e($d['event']) ?></td><td><?= $d['ok'] ? '<span class="badge badge-ok">' : '<span class="badge badge-bad">' ?><?= (int)$d['status_code'] ?></span></td><td><?= dmyt($d['created_at']) ?></td></tr>
    <?php endforeach; ?></tbody>
  </table>
  <?php endif; ?>
  <div class="page-actions mt">
    <a class="btn btn-sm btn-outline" href="webhooks.php?id=<?= $h['id'] ?>">Edit</a>
    <?php if (can('webhooks.edit')): ?>
    <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="do" value="test"><input type="hidden" name="id" value="<?= $h['id'] ?>">
      <button class="btn btn-sm btn-outline" type="submit">Send Test</button></form>
    <?php endif; ?>
    <?php if (can('webhooks.delete')): ?>
    <form method="post" style="display:inline" onsubmit="return confirm('Delete this webhook?')"><?= csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= $h['id'] ?>">
      <button class="btn btn-sm btn-danger" type="submit">Delete</button></form>
    <?php endif; ?>
  </div>
</div>
<?php endforeach; ?>
<?php if (!$hooks): ?><p class="muted">No webhooks configured yet.</p><?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
