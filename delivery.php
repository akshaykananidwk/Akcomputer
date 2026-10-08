<?php
// 🚚 Deliveries: the shop gives a bill to a delivery person; the customer
// gets a 4-digit code on WhatsApp; on the doorstep the delivery person
// types that code, takes a photo, and the place is noted. The owner sees
// every delivery; a delivery person sees only their own.
require_once __DIR__ . '/includes/init.php';
require_login();
$u = current_user();
$all = can('deliveries.view');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'assign') {
    require_perm('deliveries.assign');
    $sale = row('SELECT id, invoice_no, customer_name, customer_mobile, delivery_address FROM sales WHERE id = ? AND is_cancelled = 0', [(int)post('sale_id')]);
    if (!$sale) { flash('Bill not found.', 'error'); redirect('delivery.php'); }
    $otp = (string)random_int(1000, 9999);
    q('INSERT INTO deliveries (sale_id, assigned_to, address, otp_hash, created_by) VALUES (?,?,?,?,?)',
      [$sale['id'], (int)post('assigned_to') ?: null, mb_substr(trim((string)(post('address') ?: $sale['delivery_address'])), 0, 255), password_hash($otp, PASSWORD_DEFAULT), $u['id']]);
    if ($sale['customer_mobile'] !== '') {
        try { send_whatsapp($sale['customer_mobile'], '🚚 Your order (bill ' . $sale['invoice_no'] . ') from ' . setting('app_name') . " is on the way.\nGive this code to the delivery person only when you receive it: *$otp*"); }
        catch (Throwable $e) {}
    }
    log_activity('delivery_assign', $sale['invoice_no']);
    flash('Delivery given out. ' . ($sale['customer_mobile'] !== '' ? 'The customer got the code on WhatsApp.' : 'No customer mobile — the code is ' . $otp . '; tell it to the customer.'));
    redirect('sale_view.php?id=' . $sale['id']);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(post('do'), ['out', 'delivered', 'failed'], true)) {
    $d = row('SELECT * FROM deliveries WHERE id = ?', [(int)post('id')]);
    if (!$d || (!$all && (int)$d['assigned_to'] !== (int)$u['id'])) { flash('Not your delivery.', 'error'); redirect('delivery.php'); }
    if (post('do') === 'delivered') {
        if (!api_rate_ok('deliv-otp:' . $d['id'], 6, 600)) { flash('Too many wrong codes. Ask the shop.', 'error'); redirect('delivery.php'); }
        if (!password_verify(preg_replace('/\D/', '', (string)post('otp')), $d['otp_hash'])) { flash('That code is not right — ask the customer for the code on their WhatsApp.', 'error'); redirect('delivery.php'); }
        $photo = null;
        if (!empty($_FILES['photo']['tmp_name']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK && @getimagesize($_FILES['photo']['tmp_name'])) {
            $dir = up_dir('deliveries');
            if (!is_file("$dir/.htaccess")) @file_put_contents("$dir/.htaccess", "Require all denied\n");
            $photo = 'dl_' . $d['id'] . '_' . bin2hex(random_bytes(4)) . '.jpg';
            if (!move_uploaded_file($_FILES['photo']['tmp_name'], "$dir/$photo")) $photo = null;
        }
        q("UPDATE deliveries SET status = 'delivered', delivered_at = NOW(), photo = ?, lat = ?, lng = ? WHERE id = ?",
          [$photo, is_numeric(post('lat')) ? post('lat') : null, is_numeric(post('lng')) ? post('lng') : null, $d['id']]);
        flash('Delivered ✔');
    } else {
        q('UPDATE deliveries SET status = ?, note = ? WHERE id = ?', [post('do'), mb_substr((string)post('note'), 0, 255), $d['id']]);
    }
    log_activity('delivery_' . post('do'), '#' . $d['id']);
    redirect('delivery.php');
}
if ((int)get('photo')) {
    $d = row('SELECT * FROM deliveries WHERE id = ?', [(int)get('photo')]);
    if (!$d || !$d['photo'] || (!$all && (int)$d['assigned_to'] !== (int)$u['id'])) { http_response_code(404); exit; }
    header('Content-Type: image/jpeg'); header('Cache-Control: private, no-store'); readfile(up_dir('deliveries') . '/' . basename($d['photo'])); exit;
}

$rows = all('SELECT d.*, s.invoice_no, s.customer_name, s.customer_mobile, s.total, s.paid, us.name boy FROM deliveries d JOIN sales s ON s.id = d.sale_id
             LEFT JOIN users us ON us.id = d.assigned_to ' . ($all ? '' : 'WHERE d.assigned_to = ' . (int)$u['id']) . "
             ORDER BY (d.status IN ('assigned','out')) DESC, d.id DESC LIMIT 100");
$page_title = 'Deliveries';
include __DIR__ . '/includes/header.php';
?>
<div class="page-head"><h1>🚚 Deliveries</h1></div>
<?php foreach ($rows as $d): $open = in_array($d['status'], ['assigned', 'out'], true); ?>
<div class="card" style="<?= $open ? '' : 'opacity:.7' ?>">
  <div style="display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap">
    <div><b><?= e($d['customer_name'] ?: 'Customer') ?></b> · <?= e($d['invoice_no']) ?><?= $all ? ' · ' . e($d['boy'] ?? '—') : '' ?><br>
      <span class="muted"><?= e($d['address']) ?></span>
      <?php if ($d['total'] - $d['paid'] > 0.009): ?><br><b class="text-danger">Collect ₹<?= money($d['total'] - $d['paid']) ?></b><?php endif; ?></div>
    <div><span class="badge badge-<?= ['assigned' => 'info', 'out' => 'warn', 'delivered' => 'ok', 'failed' => 'bad'][$d['status']] ?>"><?= e($d['status']) ?></span></div>
  </div>
  <?php if ($open): ?>
  <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:8px">
    <?php if ($d['address'] !== ''): ?><a class="btn btn-sm btn-outline" target="_blank" rel="noopener" href="https://maps.google.com/?q=<?= rawurlencode($d['address']) ?>">🗺️ Map</a><?php endif; ?>
    <?php if ($d['customer_mobile'] !== ''): ?><a class="btn btn-sm btn-outline" href="tel:<?= e($d['customer_mobile']) ?>">📞 Call</a><?php endif; ?>
    <?php if ($d['status'] === 'assigned'): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$d['id'] ?>"><button class="btn btn-sm" name="do" value="out">🛵 Leaving now</button></form><?php endif; ?>
  </div>
  <form method="post" enctype="multipart/form-data" class="mt dlv" style="display:grid;gap:6px"><?= csrf_field() ?><input type="hidden" name="do" value="delivered"><input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
    <input type="hidden" name="lat"><input type="hidden" name="lng">
    <input type="text" name="otp" inputmode="numeric" maxlength="4" placeholder="Customer's 4-digit code" required>
    <label class="btn btn-sm btn-outline" style="cursor:pointer">📸 Photo of the delivered goods <input type="file" name="photo" accept="image/*" capture="environment" style="display:none"></label>
    <button class="btn" type="submit">✔ Delivered</button></form>
  <form method="post" style="margin-top:6px"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$d['id'] ?>"><input type="hidden" name="do" value="failed"><input type="text" name="note" placeholder="Why not delivered?" style="max-width:220px"> <button class="btn btn-sm btn-outline">Could not deliver</button></form>
  <?php elseif ($d['photo']): ?><a class="btn btn-sm btn-outline mt" target="_blank" href="delivery.php?photo=<?= (int)$d['id'] ?>">🖼️ Photo</a> <?php if ($d['lat']): ?><a target="_blank" rel="noopener" href="https://maps.google.com/?q=<?= (float)$d['lat'] ?>,<?= (float)$d['lng'] ?>">📍 where</a><?php endif; ?><?php endif; ?>
</div>
<?php endforeach; if (!$rows): ?><div class="card muted">No deliveries.</div><?php endif; ?>
<script>
if (navigator.geolocation && document.querySelector('.dlv')) navigator.geolocation.getCurrentPosition(function (p) {
  document.querySelectorAll('.dlv').forEach(function (f) { f.lat.value = p.coords.latitude; f.lng.value = p.coords.longitude; });
});
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
