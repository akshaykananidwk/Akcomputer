<?php
// Website orders admin - orders placed from catalog.php
require_once __DIR__ . '/includes/init.php';
require_perm('weborders.view');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'status') {
    require_perm('weborders.edit');
    q('UPDATE web_orders SET status = ? WHERE id = ?', [post('status'), (int)post('id')]);
    flash('Order status updated.');
    redirect('web_orders.php');
}

$orders = all('SELECT * FROM web_orders ORDER BY id DESC LIMIT 200');
$page_title = 'Website Orders';
include __DIR__ . '/includes/header.php';
?>
<div class="list-count"><?= count($orders) ?> orders · <a href="catalog.php" target="_blank">🌐 Store જુઓ</a></div>
<?php foreach ($orders as $o): $oi = json_decode($o['items_json'], true) ?: []; ?>
<div class="card">
  <h3><?= e($o['order_no']) ?> <?= status_badge($o['status'] === 'new' ? 'pending' : ($o['status'] === 'completed' ? 'completed' : ($o['status'] === 'cancelled' ? 'cancelled' : 'in_progress'))) ?>
    <span class="muted" style="font-weight:normal;font-size:12px"> · <?= dmyt($o['created_at']) ?></span></h3>
  <p><strong><?= e($o['customer_name']) ?></strong> · <a href="tel:<?= e($o['mobile']) ?>"><?= e($o['mobile']) ?></a>
    <a class="btn btn-sm btn-wa" href="https://wa.me/<?= e(wa_normalize_number($o['mobile'])) ?>" target="_blank" rel="noopener">📲 WhatsApp</a><br>
  <?= $o['address'] ? '📍 ' . e($o['address']) . '<br>' : '' ?>
  <?= $o['notes'] ? '📝 ' . e($o['notes']) : '' ?></p>
  <table class="table-sm mt">
    <?php foreach ($oi as $it): ?>
    <tr><td><?= e($it['name']) ?></td><td class="num"><?= (int)$it['qty'] ?> × <?= money($it['price']) ?></td><td class="num">₹<?= money($it['total']) ?></td></tr>
    <?php endforeach; ?>
    <tr><td><strong>Total</strong></td><td></td><td class="num"><strong>₹<?= money($o['total']) ?></strong></td></tr>
  </table>
  <?php if (can('weborders.edit')): ?>
  <form method="post" class="filterbar mt">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="status"><input type="hidden" name="id" value="<?= $o['id'] ?>">
    <div><select name="status">
      <?php foreach (['new', 'contacted', 'completed', 'cancelled'] as $s): ?>
      <option value="<?= $s ?>" <?= $o['status'] === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
      <?php endforeach; ?>
    </select></div>
    <button class="btn btn-sm" type="submit">Update</button>
    <?php if (can('sales.add')): ?><a class="btn btn-sm btn-success" href="sales.php?action=new">→ Bill બનાવો</a><?php endif; ?>
  </form>
  <?php endif; ?>
</div>
<?php endforeach; if (!$orders): ?><div class="card"><p class="muted">હજી કોઈ website order નથી.</p></div><?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
