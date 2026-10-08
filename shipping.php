<?php
// 📦 Send a bill's goods by courier (Shiprocket) and follow it.
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/shiprocket.php';
require_perm('sales.view');
$sale = row('SELECT s.*, p.address p_address, p.city p_city, p.email p_email FROM sales s LEFT JOIN parties p ON p.id = s.party_id WHERE s.id = ?', [(int)(get('sale') ?: post('sale'))]);
if ((int)get('track')) { $sh = row('SELECT * FROM shipments WHERE id = ?', [(int)get('track')]); if ($sh) { flash('Status: ' . sr_track($sh)); redirect('shipping.php?sale=' . $sh['sale_id']); } }
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'ship' && $sale) {
    require_perm('sales.add');
    if (!api_rate_ok('ship:' . current_user()['id'], 20, 3600)) { flash('Too many tries. Wait a little.', 'error'); redirect('shipping.php?sale=' . $sale['id']); }
    if (!preg_match('/^\d{6}$/', (string)post('pincode')) || !preg_match('/^\d{10}$/', preg_replace('/\D/', '', (string)post('phone')))) { flash('Give a 6-digit PIN code and a 10-digit phone.', 'error'); redirect('shipping.php?sale=' . $sale['id']); }
    [$ok, $msg] = sr_ship($sale['id'], ['name' => post('name'), 'address' => post('address'), 'city' => post('city'), 'pincode' => post('pincode'), 'state' => post('state'), 'phone' => substr(preg_replace('/\D/', '', post('phone')), -10), 'email' => post('email')],
                         ['weight' => max(0.05, (float)post('weight')), 'l' => max(1, (float)post('l')), 'b' => max(1, (float)post('b')), 'h' => max(1, (float)post('h')), 'cod' => post('cod') ? 1 : 0]);
    log_activity('shipment', $sale['invoice_no'] . ' ' . $msg);
    if ($ok && ($awbRow = row("SELECT * FROM shipments WHERE sale_id = ? AND awb <> '' ORDER BY id DESC LIMIT 1", [$sale['id']])) && $sale['customer_mobile'] !== '')
        try { send_whatsapp($sale['customer_mobile'], '📦 Your order (bill ' . $sale['invoice_no'] . ') is sent by ' . $awbRow['courier'] . ".\nTrack it: " . $awbRow['tracking_url']); } catch (Throwable $e) {}
    flash($msg, $ok ? 'success' : 'error');
    redirect('shipping.php?sale=' . $sale['id']);
}
if (!$sale) { flash('Open a bill and press Ship by courier.', 'info'); redirect('sales.php'); }
$ships = all('SELECT * FROM shipments WHERE sale_id = ? ORDER BY id DESC', [$sale['id']]);
$page_title = 'Courier';
include __DIR__ . '/includes/header.php';
?>
<div class="page-head"><h1>📦 Courier — <?= e($sale['invoice_no']) ?></h1><a class="btn btn-sm btn-outline" href="sale_view.php?id=<?= (int)$sale['id'] ?>">← Bill</a></div>
<?php foreach ($ships as $sh): ?><div class="card"><b><?= e($sh['courier'] ?: 'Shiprocket') ?></b> · AWB <?= e($sh['awb'] ?: '—') ?> · <span class="badge badge-info"><?= e($sh['status']) ?></span>
  <?php if ($sh['tracking_url']): ?><a target="_blank" rel="noopener" href="<?= e($sh['tracking_url']) ?>">🔎 Track</a> · <a href="shipping.php?track=<?= (int)$sh['id'] ?>">🔄 Update</a><?php endif; ?></div><?php endforeach; ?>
<?php if (setting('shiprocket_email', '') === ''): ?><div class="card">Connect your Shiprocket account first in <a href="connections.php#courier">Connections → Courier</a>.</div>
<?php elseif (can('sales.add')): ?>
<form method="post" class="card" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:8px;align-items:end"><?= csrf_field() ?><input type="hidden" name="do" value="ship"><input type="hidden" name="sale" value="<?= (int)$sale['id'] ?>">
  <div class="field"><label>Name</label><input name="name" value="<?= e($sale['customer_name']) ?>" required></div>
  <div class="field"><label>Phone</label><input name="phone" value="<?= e($sale['customer_mobile']) ?>" required></div>
  <div class="field" style="grid-column:1/-1"><label>Address</label><input name="address" value="<?= e($sale['delivery_address'] ?: $sale['p_address']) ?>" required></div>
  <div class="field"><label>City</label><input name="city" value="<?= e($sale['p_city']) ?>" required></div>
  <div class="field"><label>PIN code</label><input name="pincode" pattern="\d{6}" required></div>
  <div class="field"><label>State</label><input name="state" value="Gujarat" required></div>
  <div class="field"><label>E-mail</label><input name="email" type="email" value="<?= e($sale['p_email']) ?>"></div>
  <div class="field"><label>Weight (kg)</label><input name="weight" type="number" step="0.01" value="0.5"></div>
  <div class="field"><label>Box L × B × H (cm)</label><div style="display:flex;gap:4px"><input name="l" type="number" value="20"><input name="b" type="number" value="15"><input name="h" type="number" value="10"></div></div>
  <?php if ($sale['total'] - $sale['paid'] > 0.009): ?><label class="check-inline"><input type="checkbox" name="cod" value="1" checked> Cash on delivery ₹<?= money($sale['total'] - $sale['paid']) ?></label><?php endif; ?>
  <button class="btn" type="submit">📦 Book courier</button></form>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
