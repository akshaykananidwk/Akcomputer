<?php
// Business Dashboard (Vyapar-style)
require_once __DIR__ . '/includes/init.php';
require_perm('dashboard.view');
$u = current_user();

$today = today();
list($saleScope, $saleParams) = own_scope('sales');

// Optional branch filter (multi-location shops) - "own scope" (staff seeing
// only their own bills) still applies on top of it, same as everywhere else.
$locsAllDash = can('locations.view') ? all('SELECT id, name FROM locations WHERE is_active = 1 ORDER BY name') : [];
$dashLoc = (int)get('loc');
$locScope = $dashLoc ? ' AND location_id = ?' : '';
$locParam = $dashLoc ? [$dashLoc] : [];

$todaySales = row("SELECT COUNT(*) c, COALESCE(SUM(total),0) t FROM sales WHERE is_cancelled = 0 AND sale_date = ? $saleScope$locScope", array_merge([$today], $saleParams, $locParam));
$monthSales = row("SELECT COALESCE(SUM(total),0) t FROM sales WHERE is_cancelled = 0 AND sale_date >= ? $saleScope$locScope", array_merge([date('Y-m-01')], $saleParams, $locParam));

// Same party-ledger formula as Parties list / Payment-In-Out, so this
// number never disagrees with what those pages show (it used to be a
// separate per-invoice sum that quietly drifted out of sync).
$canMoney = can('payments.view');
$recv = 0; $paybl = 0; $walkinDue = 0;
if ($canMoney) {
    // Inactive parties are still included when they carry a balance - a
    // party deactivated with money outstanding must not vanish from the
    // dashboard totals either.
    $balExprDash = party_balance_expr('p');
    $bals = all("SELECT $balExprDash AS bal FROM parties p WHERE p.is_active = 1 OR ABS($balExprDash) > 0.009");
    foreach ($bals as $b) { if ($b['bal'] > 0.009) $recv += $b['bal']; elseif ($b['bal'] < -0.009) $paybl += -$b['bal']; }
    $walkinDue = walkin_due();
}

// last 6 months sales + profit for the trend chart
$chart = []; $profitChart = [];
for ($i = 5; $i >= 0; $i--) {
    $mStart = date('Y-m-01', strtotime("-$i months"));
    $mEnd = date('Y-m-t', strtotime($mStart));
    $label = date('M', strtotime($mStart));
    $chart[] = [
        'label' => $label,
        'val' => (float)val("SELECT COALESCE(SUM(total),0) FROM sales WHERE is_cancelled = 0 AND sale_date BETWEEN ? AND ? $saleScope$locScope",
                            array_merge([$mStart, $mEnd], $saleParams, $locParam)),
    ];
    if (can('reports.profit')) {
        $rev = (float)val("SELECT COALESCE(SUM(total),0) FROM sales WHERE is_cancelled = 0 AND sale_date BETWEEN ? AND ? $saleScope$locScope", array_merge([$mStart, $mEnd], $saleParams, $locParam));
        $cost = (float)val("SELECT COALESCE(SUM(si.qty * i.purchase_price),0) FROM sale_items si JOIN sales s ON s.id = si.sale_id JOIN items i ON i.id = si.item_id
                             WHERE s.is_cancelled = 0 AND s.sale_date BETWEEN ? AND ? $saleScope$locScope", array_merge([$mStart, $mEnd], $saleParams, $locParam));
        $profitChart[] = ['label' => $label, 'val' => $rev - $cost];
    }
}

// inventory summary
$invCard = null;
if (can('stock.view')) {
    $invCard = [
        'items' => (int)val('SELECT COUNT(*) FROM items WHERE is_active = 1'),
        'low' => (int)val("SELECT COUNT(*) FROM (SELECT i.id, i.min_stock, COALESCE(SUM(s.qty),0) q FROM items i LEFT JOIN stock s ON s.item_id = i.id
                           WHERE i.is_active = 1 AND i.item_type <> 'service' AND i.min_stock > 0 GROUP BY i.id, i.min_stock HAVING q < i.min_stock) x"),
        'value' => can('reports.profit')
            ? (float)val("SELECT COALESCE(SUM(sq.q * i.purchase_price),0) FROM
                          (SELECT item_id, SUM(qty) q FROM (SELECT item_id, qty FROM stock UNION ALL SELECT item_id, qty FROM staff_stock) z GROUP BY item_id) sq
                          JOIN items i ON i.id = sq.item_id WHERE i.item_type <> 'service'")
            : null,
    ];
}

$openRepairs = can('repairs.view') ? (int)val("SELECT COUNT(*) FROM repairs WHERE status NOT IN ('delivered','returned_unrepaired')") : null;
$openClaims = can('warranty.view') ? (int)val("SELECT COUNT(*) FROM warranty_claims WHERE status NOT IN ('delivered','rejected')") : null;
$openEst = can('estimates.view') ? row("SELECT COUNT(*) c, COALESCE(SUM(total),0) t FROM estimates WHERE status = 'open'") : null;

$myTasks = all("SELECT * FROM tasks WHERE assigned_to = ? AND status IN ('assigned','started') ORDER BY scheduled_date LIMIT 5", [$u['id']]);
$myStock = all('SELECT ss.qty, i.name, i.unit FROM staff_stock ss JOIN items i ON i.id = ss.item_id WHERE ss.user_id = ? AND ss.qty > 0', [$u['id']]);
$myHandovers = (int)val("SELECT COUNT(*) FROM handovers WHERE staff_id = ? AND status = 'pending' AND type = 'issue'", [$u['id']]);

$partiesLink = can('parties.view');

// ---------- Customizable widgets ----------
// Two groups so reordering can't break the layout: "top" widgets are
// full-width cards, "grid" widgets share the 2-column .grid-2 row (a CSS
// grid just lays children out in DOM order, so reordering within a group
// is a plain array sort - no positioning math needed). See
// dashboard_customize.php for the show/hide + reorder UI.
$aiInsights = can('reports.profit') ? ai_dashboard_insights($saleScope . $locScope, array_merge($saleParams, $locParam)) : [];
$topWidgetDefs = [
    'duo' => $canMoney,
    'sale_overview' => can('sales.view'),
    'profit_trend' => can('sales.view') && $profitChart,
    'ai_insights' => can('reports.profit') && $aiInsights,
];
$gridWidgetDefs = [
    'inventory' => (bool)$invCard,
    'open_tx' => ($openRepairs !== null || $openEst || $openClaims !== null),
    'tasks' => (bool)$myTasks,
    'stock' => (bool)$myStock,
];
$allWidgetDefs = array_merge($topWidgetDefs, $gridWidgetDefs);
$defaultOrder = array_keys($allWidgetDefs);
$prefRaw = json_decode((string)user_pref($u['id'], 'dashboard_widgets', ''), true) ?: [];
$storedOrder = is_array($prefRaw['order'] ?? null) ? $prefRaw['order'] : [];
$hiddenWidgets = is_array($prefRaw['hidden'] ?? null) ? $prefRaw['hidden'] : [];
$order = array_values(array_intersect($storedOrder, $defaultOrder));
foreach ($defaultOrder as $w) if (!in_array($w, $order, true)) $order[] = $w; // new widgets added after this phase land at the end
$topOrder = array_values(array_intersect($order, array_keys($topWidgetDefs)));
$gridOrder = array_values(array_intersect($order, array_keys($gridWidgetDefs)));

$page_title = 'Dashboard';
include __DIR__ . '/includes/header.php';
?>

<div class="tile-grid">
  <?php if (can('sales.view')): ?><a class="tile" href="sales.php"><span><?= icon('receipt', 26) ?></span>Sale List</a><?php endif; ?>
  <?php if (can('purchases.view')): ?><a class="tile" href="purchases.php"><span><?= icon('box', 26) ?></span>Purchase List</a><?php endif; ?>
  <?php if (can('items.view')): ?><a class="tile" href="items.php"><span><?= icon('archive', 26) ?></span>Stock Items</a><?php endif; ?>
  <?php if (can('parties.view')): ?><a class="tile" href="parties.php"><span><?= icon('users', 26) ?></span>Parties</a><?php endif; ?>
  <?php if (!can('sales.view') && can('tasks.view')): ?><a class="tile" href="tasks.php"><span><?= icon('tool', 26) ?></span>My Tasks</a><?php endif; ?>
  <?php if (!can('purchases.view')): ?><a class="tile" href="my_stock.php"><span><?= icon('archive', 26) ?></span>My Stock</a><?php endif; ?>
</div>

<?php if ($myHandovers): ?>
<div class="flash flash-info">🤝 You have <?= $myHandovers ?> stock handover(s) pending. <a href="my_stock.php">Accept with OTP →</a></div>
<?php endif; ?>

<div class="page-actions no-print" style="margin-bottom:10px">
  <?php if ($locsAllDash): ?>
  <form method="get" class="filterbar" style="margin:0">
    <div><label>Branch</label>
      <select name="loc" onchange="this.form.submit()">
        <option value="0">All branches</option>
        <?php foreach ($locsAllDash as $l): ?><option value="<?= $l['id'] ?>" <?= $dashLoc == $l['id'] ? 'selected' : '' ?>><?= e($l['name']) ?></option><?php endforeach; ?>
      </select></div>
  </form>
  <?php endif; ?>
  <a class="btn btn-sm btn-outline" href="dashboard_customize.php">⚙️ Customize Dashboard</a>
</div>

<?php foreach ($topOrder as $_w):
    if (!$allWidgetDefs[$_w] || in_array($_w, $hiddenWidgets, true)) continue;
    if ($_w === 'duo'): ?>
<div class="duo-cards">
  <a class="duo-card duo-get" href="<?= $partiesLink ? 'parties.php?bal=get' : 'payments.php?action=new&dir=in' ?>"><div class="duo-label">To Receive</div><div class="duo-value">₹ <?= money($recv) ?></div></a>
  <a class="duo-card duo-give" href="<?= $partiesLink ? 'parties.php?bal=give' : 'payments.php?action=new&dir=out' ?>"><div class="duo-label">To Pay</div><div class="duo-value">₹ <?= money($paybl) ?></div></a>
</div>
<?php if ($walkinDue > 0.009): ?>
<p class="muted mt" style="margin-top:-6px;margin-bottom:14px">+ ₹<?= money($walkinDue) ?> due on walk-in bills (no party - collect directly from the Sale List)</p>
<?php endif; ?>
<?php elseif ($_w === 'sale_overview'): ?>
<div class="card">
  <h2>Sale Overview <span class="muted" style="font-weight:400;font-size:13px">(Last 6 Months<?= $dashLoc ? ' - ' . e($locsAllDash[array_search($dashLoc, array_column($locsAllDash, 'id'))]['name'] ?? '') : '' ?>)</span></h2>
  <p class="muted">This month: <strong>₹<?= money($monthSales['t']) ?></strong> · Today: <strong>₹<?= money($todaySales['t']) ?></strong> (<?= (int)$todaySales['c'] ?> bills) · <a href="reports.php?r=daily">View Daily Sales report →</a></p>
  <?= svg_line_chart($chart) ?>
</div>
<?php elseif ($_w === 'profit_trend'): ?>
<div class="card">
  <h2>Profit Trend <span class="muted" style="font-weight:400;font-size:13px">(Last 6 Months, item profit only)</span></h2>
  <?= svg_line_chart($profitChart, '#16a34a') ?>
  <p class="mt"><a href="reports.php?r=profit">View full Profit report →</a></p>
</div>
<?php elseif ($_w === 'ai_insights'): ?>
<div class="card">
  <h2>🤖 AI Insights</h2>
  <?php foreach ($aiInsights as $ins): ?>
  <p class="mb"><?= $ins['icon'] ?> <?= e($ins['text']) ?></p>
  <?php endforeach; ?>
</div>
<?php endif; endforeach; ?>

<div class="grid-2">
<?php foreach ($gridOrder as $_w):
    if (!$allWidgetDefs[$_w] || in_array($_w, $hiddenWidgets, true)) continue;
    if ($_w === 'inventory'): ?>
<div class="card">
  <h2>Inventory Summary</h2>
  <div class="grid-stats" style="margin-bottom:0">
    <?php if ($invCard['value'] !== null): ?>
    <div class="stat s-ok"><div class="stat-label">Stock Value</div><div class="stat-value">₹<?= money($invCard['value']) ?></div></div>
    <?php endif; ?>
    <div class="stat"><div class="stat-label">No. of Items</div><div class="stat-value"><?= $invCard['items'] ?></div></div>
    <div class="stat <?= $invCard['low'] ? 's-bad' : '' ?>"><div class="stat-label">Low Stock Items</div><div class="stat-value"><?= $invCard['low'] ?></div></div>
  </div>
  <p class="mt"><a href="reports.php?r=low">View low stock →</a></p>
</div>
<?php elseif ($_w === 'open_tx'): ?>
<div class="card">
  <h2>Open Transactions</h2>
  <table class="table-sm">
    <?php if ($openEst && $openEst['c']): ?><tr><td>Open Estimates</td><td class="num"><?= $openEst['c'] ?> (₹<?= money($openEst['t']) ?>)</td></tr><?php endif; ?>
    <?php if ($openRepairs !== null): ?><tr><td>Open Repair Jobs</td><td class="num"><a href="repairs.php"><?= $openRepairs ?></a></td></tr><?php endif; ?>
    <?php if ($openClaims !== null): ?><tr><td>Open Warranty Claims</td><td class="num"><a href="warranty.php"><?= $openClaims ?></a></td></tr><?php endif; ?>
  </table>
</div>
<?php elseif ($_w === 'tasks'): ?>
<div class="card">
  <h2>My Pending Tasks</h2>
  <?php foreach ($myTasks as $t): ?>
    <p><a href="tasks.php?action=view&id=<?= $t['id'] ?>"><strong><?= e($t['task_no']) ?></strong></a>
    - <?= e($t['customer_name']) ?> <?= status_badge($t['status']) ?><br>
    <span class="muted"><?= e(mb_substr($t['description'], 0, 80)) ?></span></p>
  <?php endforeach; ?>
</div>
<?php elseif ($_w === 'stock'): ?>
<div class="card">
  <h2>Stock In My Hand</h2>
  <table class="table-sm">
    <?php foreach ($myStock as $s): ?>
    <tr><td><?= e($s['name']) ?></td><td class="num"><?= (float)$s['qty'] ?> <?= e($s['unit']) ?></td></tr>
    <?php endforeach; ?>
  </table>
  <p class="mt"><a href="my_stock.php">Full details →</a></p>
</div>
<?php endif; endforeach; ?>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
