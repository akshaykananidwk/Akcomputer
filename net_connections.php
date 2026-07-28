<?php
// Internet connections the shop supplies: each customer's plan with its
// expiry date. Expiring/expired connections stand out on top, renew is one
// tap (extends by the plan months from the old expiry), and the daily cron
// WhatsApps the shop (and optionally the customer) as expiry nears.
require_once __DIR__ . '/includes/init.php';
require_perm('netconn.view');
$u = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'save') {
    require_perm('netconn.edit');
    $id = (int)post('id');
    $start = post('start_date', today());
    $months = max(1, (int)post('months'));
    $expiry = post('expiry_date') ?: date('Y-m-d', strtotime("$start +$months months"));
    $data = [trim(post('customer_name')), preg_replace('/\D/', '', post('mobile')), trim(post('address')),
             trim(post('plan_name')), (float)post('price'), $start, $months, $expiry,
             post('notify_customer') ? 1 : 0, trim(post('notes'))];
    if ($data[0] === '' || strlen($data[1]) < 10) { flash('Name and a valid mobile are required.', 'error'); redirect('net_connections.php'); }
    if ($id) {
        q("UPDATE net_connections SET customer_name=?, mobile=?, address=?, plan_name=?, price=?, start_date=?, months=?, expiry_date=?, notify_customer=?, notes=?, status='active' WHERE id=?",
          array_merge($data, [$id]));
        flash('Connection updated.');
    } else {
        q('INSERT INTO net_connections (customer_name, mobile, address, plan_name, price, start_date, months, expiry_date, notify_customer, notes, created_by)
           VALUES (?,?,?,?,?,?,?,?,?,?,?)', array_merge($data, [$u['id']]));
        flash('Connection added.');
    }
    log_activity('netconn_save', $data[0]);
    redirect('net_connections.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'renew') {
    require_perm('netconn.edit');
    $c = row('SELECT * FROM net_connections WHERE id = ?', [(int)post('id')]);
    if ($c) {
        // renew from the OLD expiry (or today if it lapsed long ago) so the
        // customer never loses paid days
        $base = max($c['expiry_date'], today());
        $newExp = date('Y-m-d', strtotime("$base +{$c['months']} months"));
        q("UPDATE net_connections SET expiry_date = ?, status = 'active', last_alert_date = NULL WHERE id = ?", [$newExp, $c['id']]);
        if ($c['notify_customer'] && $c['mobile']) {
            send_whatsapp($c['mobile'], "✅ *" . setting('app_name', 'AK Computer') . "*\n\n" . $c['customer_name'] . ", તમારું ઇન્ટરનેટ કનેક્શન રિન્યૂ થઈ ગયું! 🎉\nPlan: " . ($c['plan_name'] ?: '-') . "\nહવે ચાલશે: *" . dmy($newExp) . "* સુધી\n\nThank you! 🙏");
        }
        log_activity('netconn_renew', $c['customer_name'] . ' till ' . $newExp);
        flash('Renewed till ' . dmy($newExp) . '.');
    }
    redirect('net_connections.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'stop') {
    require_perm('netconn.edit');
    q("UPDATE net_connections SET status = 'stopped' WHERE id = ?", [(int)post('id')]);
    flash('Connection marked stopped.');
    redirect('net_connections.php');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'delete') {
    require_perm('netconn.edit');
    q('DELETE FROM net_connections WHERE id = ?', [(int)post('id')]);
    flash('Connection deleted.');
    redirect('net_connections.php');
}

$edit = (int)get('edit') ? row('SELECT * FROM net_connections WHERE id = ?', [(int)get('edit')]) : null;
$conns = all("SELECT *, DATEDIFF(expiry_date, CURDATE()) days_left FROM net_connections
              ORDER BY (status = 'active') DESC, expiry_date ASC");
$expSoon = count(array_filter($conns, fn($c) => $c['status'] === 'active' && $c['days_left'] <= 7));
$page_title = 'Internet Connections';
include __DIR__ . '/includes/header.php';
?>
<div class="grid-stats">
  <div class="stat"><div class="stat-label">🌐 Total connections</div><div class="stat-value"><?= count($conns) ?></div></div>
  <div class="stat s-ok"><div class="stat-label">✅ Active</div><div class="stat-value"><?= count(array_filter($conns, fn($c) => $c['status'] === 'active')) ?></div></div>
  <div class="stat <?= $expSoon ? 's-bad' : '' ?>"><div class="stat-label">⏳ Expiring in 7 days</div><div class="stat-value"><?= $expSoon ?></div></div>
  <div class="stat"><div class="stat-label">💰 Monthly-equivalent ₹</div><div class="stat-value">₹<?= money(array_sum(array_map(fn($c) => $c['status'] === 'active' && $c['months'] > 0 ? $c['price'] / $c['months'] : 0, $conns))) ?></div></div>
</div>

<?php if (can('netconn.edit')): ?>
<div class="card">
  <h3><?= $edit ? '✏️ Edit connection' : '+ New connection' ?></h3>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="save">
    <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
    <div class="form-row cols-3">
      <div><label>Customer name *</label><input type="text" name="customer_name" required value="<?= e($edit['customer_name'] ?? '') ?>"></div>
      <div><label>Mobile (WhatsApp) *</label><input type="tel" name="mobile" required value="<?= e($edit['mobile'] ?? '') ?>"></div>
      <div><label>Address / Area</label><input type="text" name="address" value="<?= e($edit['address'] ?? '') ?>"></div>
    </div>
    <div class="form-row cols-4">
      <div><label>Plan</label><input type="text" name="plan_name" placeholder="e.g. 100 Mbps unlimited" value="<?= e($edit['plan_name'] ?? '') ?>"></div>
      <div><label>Price ₹ (whole period)</label><input type="number" step="any" name="price" value="<?= 0 + ($edit['price'] ?? 0) ?>"></div>
      <div><label>Start date</label><input type="date" name="start_date" value="<?= e($edit['start_date'] ?? today()) ?>"></div>
      <div><label>Months</label><input type="number" min="1" name="months" value="<?= (int)($edit['months'] ?? 3) ?>"></div>
    </div>
    <div class="form-row cols-2">
      <div><label>Expiry date <span class="muted" style="font-weight:normal">(blank = start + months)</span></label>
        <input type="date" name="expiry_date" value="<?= e($edit['expiry_date'] ?? '') ?>"></div>
      <div><label>Notes</label><input type="text" name="notes" value="<?= e($edit['notes'] ?? '') ?>"></div>
    </div>
    <label class="check-inline"><input type="checkbox" name="notify_customer" value="1" <?= ($edit['notify_customer'] ?? 1) ? 'checked' : '' ?>> Expiry પહેલા ગ્રાહકને પણ WhatsApp કરવો</label>
    <div class="mt"><button class="btn" type="submit"><?= $edit ? 'Update' : 'Add Connection' ?></button>
    <?php if ($edit): ?><a class="btn btn-muted" href="net_connections.php">Cancel</a><?php endif; ?></div>
  </form>
</div>
<?php endif; ?>

<div class="table-wrap">
<table>
  <thead><tr><th>Customer</th><th>Plan</th><th class="num">₹</th><th>Expiry</th><th>Status</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($conns as $c):
      $soon = $c['status'] === 'active' && $c['days_left'] <= 7;
      $gone = $c['status'] === 'active' && $c['days_left'] < 0; ?>
    <tr<?= $gone ? ' style="background:rgba(220,38,38,.08)"' : ($soon ? ' style="background:rgba(245,158,11,.10)"' : '') ?>>
      <td><strong><?= e($c['customer_name']) ?></strong><br>
        <a href="tel:<?= e($c['mobile']) ?>" class="muted"><?= e($c['mobile']) ?></a>
        <?= $c['address'] ? ' · <span class="muted">' . e($c['address']) . '</span>' : '' ?></td>
      <td><?= e($c['plan_name'] ?: '-') ?> <span class="muted">(<?= (int)$c['months'] ?> mo)</span></td>
      <td class="num">₹<?= money($c['price']) ?></td>
      <td><?= dmy($c['expiry_date']) ?><br>
        <?php if ($c['status'] !== 'active'): ?><span class="muted">-</span>
        <?php elseif ($gone): ?><span class="badge badge-bad">EXPIRED <?= abs((int)$c['days_left']) ?>d ago</span>
        <?php elseif ($soon): ?><span class="badge badge-bad" style="background:#f59e0b">⏳ <?= (int)$c['days_left'] ?> day(s) left</span>
        <?php else: ?><span class="muted"><?= (int)$c['days_left'] ?> days left</span><?php endif; ?></td>
      <td><?= $c['status'] === 'active' ? '<span class="badge badge-ok">active</span>' : '<span class="badge badge-bad">stopped</span>' ?></td>
      <td style="white-space:nowrap">
        <?php if (can('netconn.edit')): ?>
        <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="do" value="renew"><input type="hidden" name="id" value="<?= $c['id'] ?>">
          <button class="btn btn-sm" type="submit">🔄 Renew +<?= (int)$c['months'] ?>mo</button></form>
        <a class="btn btn-sm btn-outline" href="net_connections.php?edit=<?= $c['id'] ?>">Edit</a>
        <?php if ($c['status'] === 'active'): ?>
        <form method="post" style="display:inline" onsubmit="return confirm('Stop this connection?')"><?= csrf_field() ?><input type="hidden" name="do" value="stop"><input type="hidden" name="id" value="<?= $c['id'] ?>">
          <button class="btn btn-sm btn-outline" type="submit">⏹</button></form>
        <?php else: ?>
        <form method="post" style="display:inline" onsubmit="return confirm('Delete permanently?')"><?= csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= $c['id'] ?>">
          <button class="btn btn-sm btn-danger" type="submit">✕</button></form>
        <?php endif; ?>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$conns): ?><tr><td colspan="6" class="muted">No connections yet — add the first one above.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
<p class="muted">⏰ રોજનો cron ચાલે એટલે: 7 દિવસ પહેલાં, 1 દિવસ પહેલાં અને expiry ના દિવસે દુકાનના WhatsApp પર એલર્ટ આવે છે (અને ટિક કરેલા ગ્રાહકને પણ યાદ અપાવાય છે).</p>
<?php include __DIR__ . '/includes/footer.php'; ?>
