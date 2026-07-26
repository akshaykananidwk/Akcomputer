<?php
// Website orders admin - orders placed from catalog.php
require_once __DIR__ . '/includes/init.php';
require_perm('weborders.view');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'status') {
    require_perm('weborders.edit');
    $oid = (int)post('id');
    $newStatus = post('status');
    q('UPDATE web_orders SET status = ? WHERE id = ?', [$newStatus, $oid]);
    // Referral commission follows the order automatically:
    // completed -> APPROVED (credited to the partner's balance, WhatsApp sent),
    // cancelled -> cancelled, back to new/contacted -> pending again.
    // Already-PAID commissions are never touched.
    $earn = row("SELECT re.*, r.name r_name, r.mobile r_mobile, r.token r_token FROM referral_earnings re
                 JOIN referrers r ON r.id = re.referrer_id WHERE re.web_order_id = ? AND re.status <> 'paid'", [$oid]);
    if ($earn) {
        $map = ['completed' => 'approved', 'cancelled' => 'cancelled', 'new' => 'pending', 'contacted' => 'pending'];
        $to = $map[$newStatus] ?? null;
        if ($to && $to !== $earn['status']) {
            q('UPDATE referral_earnings SET status = ? WHERE id = ?', [$to, $earn['id']]);
            if ($to === 'approved' && $earn['r_mobile']) {
                send_whatsapp($earn['r_mobile'], "✅ *" . setting('app_name', 'AK Computer') . "*\n\nતમારું કમિશન જમા થયું! 🎉\nOrder " . $earn['order_no'] . " પૂરો થયો.\n*₹" . money($earn['commission']) . "* તમારા ખાતામાં જમા.\n\nબેલેન્સ જુઓ: " . base_url('referral.php?t=' . $earn['r_token']));
            }
        }
    }
    flash('Order status updated.');
    redirect('web_orders.php');
}

$orders = all('SELECT wo.*, wa.name dealer_name, wa.discount_pct dealer_pct FROM web_orders wo
               LEFT JOIN web_accounts wa ON wa.id = wo.web_account_id ORDER BY wo.id DESC LIMIT 200');
$page_title = 'Website Orders';
include __DIR__ . '/includes/header.php';
?>
<div class="list-count"><?= count($orders) ?> orders · <a href="catalog.php" target="_blank">🌐 View Store</a></div>
<?php foreach ($orders as $o): $oi = json_decode($o['items_json'], true) ?: []; ?>
<div class="card">
  <h3><?= e($o['order_no']) ?> <?= status_badge($o['status'] === 'new' ? 'pending' : ($o['status'] === 'completed' ? 'completed' : ($o['status'] === 'cancelled' ? 'cancelled' : 'in_progress'))) ?>
    <span class="muted" style="font-weight:normal;font-size:12px"> · <?= dmyt($o['created_at']) ?></span></h3>
  <p><strong><?= e($o['customer_name']) ?></strong> · <a href="tel:<?= e($o['mobile']) ?>"><?= e($o['mobile']) ?></a>
    <?php if (!empty($o['dealer_name'])): ?><span class="badge badge-ok" title="Ordered while logged in as a dealer — prices already include their discount">👷 Dealer: <?= e($o['dealer_name']) ?> (<?= 0 + $o['dealer_pct'] ?>%)</span><?php endif; ?>
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
    <?php if (can('sales.add')): ?><a class="btn btn-sm btn-success" href="sales.php?action=new">→ Create Bill</a><?php endif; ?>
  </form>
  <?php endif; ?>
</div>
<?php endforeach; if (!$orders): ?><div class="card"><p class="muted">No website orders yet.</p></div><?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
