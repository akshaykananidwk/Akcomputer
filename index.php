<?php
// Dashboard
require_once __DIR__ . '/includes/init.php';
require_perm('dashboard.view');
$u = current_user();

$today = today();
list($saleScope, $saleParams) = own_scope('sales');

$todaySales = row("SELECT COUNT(*) c, COALESCE(SUM(total),0) t FROM sales WHERE sale_date = ? $saleScope", array_merge([$today], $saleParams));
$monthSales = row("SELECT COALESCE(SUM(total),0) t FROM sales WHERE sale_date >= ? $saleScope", array_merge([date('Y-m-01')], $saleParams));

$stats = [];
$stats[] = ['Today Sales', '₹' . money($todaySales['t']), 's-ok'];
$stats[] = ['Today Bills', $todaySales['c'], ''];
$stats[] = ['This Month', '₹' . money($monthSales['t']), ''];

if (can('purchases.view')) {
    $todayPur = val('SELECT COALESCE(SUM(total),0) FROM purchases WHERE purchase_date = ?', [$today]);
    $stats[] = ['Today Purchase', '₹' . money($todayPur), 's-warn'];
}
if (can('payments.view')) {
    $recv = val("SELECT COALESCE(SUM(total - paid),0) FROM sales WHERE status <> 'paid'");
    $paybl = val("SELECT COALESCE(SUM(total - paid),0) FROM purchases WHERE status <> 'paid'");
    $stats[] = ['To Receive', '₹' . money($recv), 's-ok'];
    $stats[] = ['To Pay', '₹' . money($paybl), 's-bad'];
}
if (can('repairs.view')) {
    $openRepairs = val("SELECT COUNT(*) FROM repairs WHERE status NOT IN ('delivered','returned_unrepaired')");
    $stats[] = ['Open Repairs', $openRepairs, 's-warn'];
}
if (can('warranty.view')) {
    $openClaims = val("SELECT COUNT(*) FROM warranty_claims WHERE status NOT IN ('delivered','rejected')");
    $stats[] = ['Open Claims', $openClaims, 's-warn'];
}

// my pending tasks (field staff)
$myTasks = all("SELECT * FROM tasks WHERE assigned_to = ? AND status IN ('assigned','started') ORDER BY scheduled_date LIMIT 5", [$u['id']]);
// my held stock
$myStock = all('SELECT ss.qty, i.name, i.unit FROM staff_stock ss JOIN items i ON i.id = ss.item_id WHERE ss.user_id = ? AND ss.qty > 0', [$u['id']]);
// pending handovers for me
$myHandovers = val("SELECT COUNT(*) FROM handovers WHERE staff_id = ? AND status = 'pending' AND type = 'issue'", [$u['id']]);
// low stock (managers)
$lowStock = can('stock.view')
    ? all('SELECT i.name, i.min_stock, COALESCE(SUM(s.qty),0) q FROM items i LEFT JOIN stock s ON s.item_id = i.id
           WHERE i.is_active = 1 AND i.min_stock > 0 GROUP BY i.id HAVING q < i.min_stock ORDER BY q LIMIT 8')
    : [];

$page_title = 'Dashboard';
include __DIR__ . '/includes/header.php';
?>
<div class="grid-stats">
<?php foreach ($stats as $s): ?>
  <div class="stat <?= $s[2] ?>"><div class="stat-label"><?= e($s[0]) ?></div><div class="stat-value"><?= $s[1] ?></div></div>
<?php endforeach; ?>
</div>

<div class="page-actions">
  <?php if (can('sales.add')): ?><a class="btn" href="sales.php?action=new">+ New Bill</a><?php endif; ?>
  <?php if (can('repairs.add')): ?><a class="btn btn-outline" href="repairs.php?action=new">+ Repair Job</a><?php endif; ?>
  <?php if (can('purchases.add')): ?><a class="btn btn-outline" href="purchases.php?action=new">+ Purchase</a><?php endif; ?>
</div>

<?php if ($myHandovers): ?>
<div class="flash flash-info">🤝 You have <?= $myHandovers ?> pending stock handover(s). <a href="my_stock.php">Accept with OTP →</a></div>
<?php endif; ?>

<div class="grid-2">
<?php if ($myTasks): ?>
<div class="card">
  <h2>🔧 My Pending Tasks</h2>
  <?php foreach ($myTasks as $t): ?>
    <p><a href="tasks.php?action=view&id=<?= $t['id'] ?>"><strong><?= e($t['task_no']) ?></strong></a>
    - <?= e($t['customer_name']) ?> <?= status_badge($t['status']) ?><br>
    <span class="muted"><?= e(mb_substr($t['description'], 0, 80)) ?></span></p>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($myStock): ?>
<div class="card">
  <h2>🎒 Stock In My Hand</h2>
  <table class="table-sm">
    <?php foreach ($myStock as $s): ?>
    <tr><td><?= e($s['name']) ?></td><td class="num"><?= (float)$s['qty'] ?> <?= e($s['unit']) ?></td></tr>
    <?php endforeach; ?>
  </table>
  <p class="mt"><a href="my_stock.php">Full details →</a></p>
</div>
<?php endif; ?>

<?php if ($lowStock): ?>
<div class="card">
  <h2>⚠️ Low Stock Alert</h2>
  <table class="table-sm">
    <?php foreach ($lowStock as $s): ?>
    <tr><td><?= e($s['name']) ?></td><td class="num"><?= (float)$s['q'] ?> / min <?= (float)$s['min_stock'] ?></td></tr>
    <?php endforeach; ?>
  </table>
</div>
<?php endif; ?>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
