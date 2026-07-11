<?php
// Business Dashboard (Vyapar-style)
require_once __DIR__ . '/includes/init.php';
require_perm('dashboard.view');
$u = current_user();

$today = today();
list($saleScope, $saleParams) = own_scope('sales');

$todaySales = row("SELECT COUNT(*) c, COALESCE(SUM(total),0) t FROM sales WHERE is_cancelled = 0 AND sale_date = ? $saleScope", array_merge([$today], $saleParams));
$monthSales = row("SELECT COALESCE(SUM(total),0) t FROM sales WHERE is_cancelled = 0 AND sale_date >= ? $saleScope", array_merge([date('Y-m-01')], $saleParams));

// Same party-ledger formula as Parties list / Payment-In-Out, so this
// number never disagrees with what those pages show (it used to be a
// separate per-invoice sum that quietly drifted out of sync).
$canMoney = can('payments.view');
$recv = 0; $paybl = 0; $walkinDue = 0;
if ($canMoney) {
    $bals = all('SELECT ' . party_balance_expr('p') . ' AS bal FROM parties p WHERE p.is_active = 1');
    foreach ($bals as $b) { if ($b['bal'] > 0.009) $recv += $b['bal']; elseif ($b['bal'] < -0.009) $paybl += -$b['bal']; }
    $walkinDue = walkin_due();
}

// last 6 months sales for chart
$chart = [];
for ($i = 5; $i >= 0; $i--) {
    $mStart = date('Y-m-01', strtotime("-$i months"));
    $mEnd = date('Y-m-t', strtotime($mStart));
    $chart[] = [
        'label' => date('M', strtotime($mStart)),
        'val' => (float)val("SELECT COALESCE(SUM(total),0) FROM sales WHERE is_cancelled = 0 AND sale_date BETWEEN ? AND ? $saleScope",
                            array_merge([$mStart, $mEnd], $saleParams)),
    ];
}
$maxVal = max(1, max(array_column($chart, 'val')));

// inventory summary
$invCard = null;
if (can('stock.view')) {
    $invCard = [
        'items' => (int)val('SELECT COUNT(*) FROM items WHERE is_active = 1'),
        'low' => (int)val('SELECT COUNT(*) FROM (SELECT i.id, i.min_stock, COALESCE(SUM(s.qty),0) q FROM items i LEFT JOIN stock s ON s.item_id = i.id
                           WHERE i.is_active = 1 AND i.min_stock > 0 GROUP BY i.id, i.min_stock HAVING q < i.min_stock) x'),
        'value' => can('reports.profit')
            ? (float)val('SELECT COALESCE(SUM(sq.q * i.purchase_price),0) FROM
                          (SELECT item_id, SUM(qty) q FROM (SELECT item_id, qty FROM stock UNION ALL SELECT item_id, qty FROM staff_stock) z GROUP BY item_id) sq
                          JOIN items i ON i.id = sq.item_id')
            : null,
    ];
}

$openRepairs = can('repairs.view') ? (int)val("SELECT COUNT(*) FROM repairs WHERE status NOT IN ('delivered','returned_unrepaired')") : null;
$openClaims = can('warranty.view') ? (int)val("SELECT COUNT(*) FROM warranty_claims WHERE status NOT IN ('delivered','rejected')") : null;
$openEst = can('estimates.view') ? row("SELECT COUNT(*) c, COALESCE(SUM(total),0) t FROM estimates WHERE status = 'open'") : null;

$myTasks = all("SELECT * FROM tasks WHERE assigned_to = ? AND status IN ('assigned','started') ORDER BY scheduled_date LIMIT 5", [$u['id']]);
$myStock = all('SELECT ss.qty, i.name, i.unit FROM staff_stock ss JOIN items i ON i.id = ss.item_id WHERE ss.user_id = ? AND ss.qty > 0', [$u['id']]);
$myHandovers = (int)val("SELECT COUNT(*) FROM handovers WHERE staff_id = ? AND status = 'pending' AND type = 'issue'", [$u['id']]);

$page_title = 'Dashboard';
include __DIR__ . '/includes/header.php';
?>
<?php if ($canMoney): ?>
<div class="duo-cards">
  <a class="duo-card duo-get" href="payments.php?action=new&dir=in"><div class="duo-label">લેવાના (To Receive)</div><div class="duo-value">₹ <?= money($recv) ?></div></a>
  <a class="duo-card duo-give" href="payments.php?action=new&dir=out"><div class="duo-label">દેવાના (To Pay)</div><div class="duo-value">₹ <?= money($paybl) ?></div></a>
</div>
<?php if ($walkinDue > 0.009): ?>
<p class="muted mt" style="margin-top:-6px;margin-bottom:14px">+ ₹<?= money($walkinDue) ?> walk-in bills માં બાકી (party વગર - Sale List માંથી સીધું collect કરો)</p>
<?php endif; ?>
<?php endif; ?>

<div class="tile-grid">
  <?php if (can('sales.view')): ?><a class="tile" href="sales.php"><span>🧾</span>Sale List</a><?php endif; ?>
  <?php if (can('purchases.view')): ?><a class="tile" href="purchases.php"><span>📦</span>Purchase List</a><?php endif; ?>
  <?php if (can('items.view')): ?><a class="tile" href="items.php"><span>🖥️</span>Stock Items</a><?php endif; ?>
  <?php if (can('parties.view')): ?><a class="tile" href="parties.php"><span>👥</span>Parties</a><?php endif; ?>
  <?php if (!can('sales.view') && can('tasks.view')): ?><a class="tile" href="tasks.php"><span>🔧</span>My Tasks</a><?php endif; ?>
  <?php if (!can('purchases.view')): ?><a class="tile" href="my_stock.php"><span>🎒</span>My Stock</a><?php endif; ?>
</div>

<?php if ($myHandovers): ?>
<div class="flash flash-info">🤝 તમારા માટે <?= $myHandovers ?> stock handover pending છે. <a href="my_stock.php">OTP થી Accept કરો →</a></div>
<?php endif; ?>

<?php if (can('sales.view')): ?>
<div class="card">
  <h2>📈 Sale Overview (છેલ્લા 6 મહિના)</h2>
  <p class="muted">આ મહિને: <strong>₹<?= money($monthSales['t']) ?></strong> · આજે: <strong>₹<?= money($todaySales['t']) ?></strong> (<?= (int)$todaySales['c'] ?> bills)</p>
  <div class="chart-wrap">
    <svg viewBox="0 0 600 220" preserveAspectRatio="xMidYMid meet">
      <?php
      $w = 600; $h = 220; $padL = 10; $padR = 10; $padT = 24; $padB = 34;
      $iw = ($w - $padL - $padR) / (count($chart) - 1 ?: 1);
      $pts = [];
      foreach ($chart as $ci => $cv) {
          $x = $padL + $ci * $iw;
          $y = $padT + ($h - $padT - $padB) * (1 - $cv['val'] / $maxVal);
          $pts[] = [$x, $y, $cv];
      }
      $poly = implode(' ', array_map(fn($p) => round($p[0], 1) . ',' . round($p[1], 1), $pts));
      $area = "$padL," . ($h - $padB) . " $poly " . round(end($pts)[0], 1) . ',' . ($h - $padB);
      ?>
      <polygon points="<?= $area ?>" fill="rgba(26,86,219,.12)"/>
      <polyline points="<?= $poly ?>" fill="none" stroke="#1a56db" stroke-width="3" stroke-linejoin="round" stroke-linecap="round"/>
      <?php foreach ($pts as $p): ?>
        <circle cx="<?= round($p[0], 1) ?>" cy="<?= round($p[1], 1) ?>" r="4.5" fill="#1a56db"/>
        <text x="<?= round($p[0], 1) ?>" y="<?= $h - 12 ?>" text-anchor="middle" font-size="13" fill="#64748b"><?= $p[2]['label'] ?></text>
        <?php if ($p[2]['val'] > 0): ?>
        <text x="<?= round($p[0], 1) ?>" y="<?= round($p[1], 1) - 10 ?>" text-anchor="middle" font-size="11" fill="#334155"><?= $p[2]['val'] >= 100000 ? round($p[2]['val'] / 100000, 1) . 'L' : ($p[2]['val'] >= 1000 ? round($p[2]['val'] / 1000, 1) . 'k' : round($p[2]['val'])) ?></text>
        <?php endif; ?>
      <?php endforeach; ?>
    </svg>
  </div>
</div>
<?php endif; ?>

<div class="grid-2">
<?php if ($invCard): ?>
<div class="card">
  <h2>📊 Inventory</h2>
  <div class="grid-stats" style="margin-bottom:0">
    <?php if ($invCard['value'] !== null): ?>
    <div class="stat s-ok"><div class="stat-label">Stock Value</div><div class="stat-value">₹<?= money($invCard['value']) ?></div></div>
    <?php endif; ?>
    <div class="stat"><div class="stat-label">No. of Items</div><div class="stat-value"><?= $invCard['items'] ?></div></div>
    <div class="stat <?= $invCard['low'] ? 's-bad' : '' ?>"><div class="stat-label">Low Stock Items</div><div class="stat-value"><?= $invCard['low'] ?></div></div>
  </div>
  <p class="mt"><a href="reports.php?r=low">Low stock જુઓ →</a></p>
</div>
<?php endif; ?>

<?php if ($openRepairs !== null || $openEst): ?>
<div class="card">
  <h2>🗂️ Open Transactions</h2>
  <table class="table-sm">
    <?php if ($openEst && $openEst['c']): ?><tr><td>Open Estimates</td><td class="num"><?= $openEst['c'] ?> (₹<?= money($openEst['t']) ?>)</td></tr><?php endif; ?>
    <?php if ($openRepairs !== null): ?><tr><td>Open Repair Jobs</td><td class="num"><a href="repairs.php"><?= $openRepairs ?></a></td></tr><?php endif; ?>
    <?php if ($openClaims !== null): ?><tr><td>Open Warranty Claims</td><td class="num"><a href="warranty.php"><?= $openClaims ?></a></td></tr><?php endif; ?>
  </table>
</div>
<?php endif; ?>

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
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
