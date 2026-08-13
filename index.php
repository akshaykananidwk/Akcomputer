<?php
// Business Dashboard (Vyapar-style)
require_once __DIR__ . '/includes/init.php';
// A visitor (not logged in) landing on the site sees the STORE with all the
// products, not a login wall - staff reach the dashboard via Staff Login.
// The domain root IS the public store for visitors (Google included):
// serving the catalog right here - instead of 302-redirecting to
// /catalog.php - gives the site a real homepage with content, which is
// what search engines rank. Logged-in staff still get the dashboard.
if (!current_user()) { require __DIR__ . '/catalog.php'; exit; }
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
// The To Receive / To Pay totals come from dash_balances() further down, which
// runs the ledger expression ONCE for the whole page - this block used to run
// the same expensive sweep a second time.
$canMoney = can('payments.view');
$recv = 0; $paybl = 0; $walkinDue = 0;

// Last 6 months sales + profit for the trend charts. Two grouped queries for
// the whole window - this used to be three queries per month inside a loop,
// which is the N+1 shape the dashboard must not have.
$chart = []; $profitChart = [];
$chartFrom = date('Y-m-01', strtotime('-5 months'));
$chartTo = date('Y-m-t');
$byMonth = [];
foreach (all("SELECT DATE_FORMAT(sale_date, '%Y-%m') ym, COALESCE(SUM(total),0) t
              FROM sales WHERE is_cancelled = 0 AND sale_date BETWEEN ? AND ? $saleScope$locScope
              GROUP BY ym", array_merge([$chartFrom, $chartTo], $saleParams, $locParam)) as $r)
    $byMonth[$r['ym']] = (float)$r['t'];
$costByMonth = [];
if (can('reports.profit')) {
    foreach (all("SELECT DATE_FORMAT(s.sale_date, '%Y-%m') ym, COALESCE(SUM(si.qty * i.purchase_price),0) c
                  FROM sale_items si JOIN sales s ON s.id = si.sale_id JOIN items i ON i.id = si.item_id
                  WHERE s.is_cancelled = 0 AND s.sale_date BETWEEN ? AND ? "
                . str_replace([' created_by', ' location_id'], [' s.created_by', ' s.location_id'], $saleScope . $locScope)
                . " GROUP BY ym", array_merge([$chartFrom, $chartTo], $saleParams, $locParam)) as $r)
        $costByMonth[$r['ym']] = (float)$r['c'];
}
for ($i = 5; $i >= 0; $i--) {
    $mStart = date('Y-m-01', strtotime("-$i months"));
    $ym = date('Y-m', strtotime($mStart));
    $label = date('M', strtotime($mStart));
    $rev = $byMonth[$ym] ?? 0.0;
    $chart[] = ['label' => $label, 'val' => $rev];
    if (can('reports.profit')) $profitChart[] = ['label' => $label, 'val' => $rev - ($costByMonth[$ym] ?? 0.0)];
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

// ---------- Smart Dashboard ----------
// Everything below comes from includes/dashboard.php, which in turn reads the
// same money/stock rules the rest of the shop uses. Sections are computed only
// when the user may see them AND has not hidden the widget, so a limited staff
// login does less work, not just sees less.
$rangeKey = get('range', 'today');
if (!in_array($rangeKey, ['today', 'yesterday', 'week', 'month', 'prev_month', 'custom'], true)) $rangeKey = 'today';
list($dFrom, $dTo, $dLabel) = dash_range($rangeKey, get('from'), get('to'));
list($cFrom, $cTo, $cLabel) = dash_compare_range($dFrom, $dTo, $rangeKey);
$dashOwner = can('sales.all') ? null : $u['id']; // same own-records fence as every other screen
$seeMoney  = can('payments.view');
$seeProfit = can('reports.profit');
$seeStock  = can('stock.view') || can('items.view');

$sKpis = $sPrev = $sCol = $sStock = $sSeg = $sIntel = $sTrend = $sHealth = null;
$sLowMargin = $sPriceUp = [];
if (can('sales.view')) {
    $sKpis = dash_kpis($dFrom, $dTo, $dashOwner, $dashLoc);
    $sPrev = dash_kpis($cFrom, $cTo, $dashOwner, $dashLoc);
}
$sBals = $seeMoney ? dash_balances() : null;
if ($sBals) {
    // the same figures the To Receive / To Pay cards show - one sweep, not two
    $recv = $sBals['receivable'];
    $paybl = $sBals['payable'];
    $walkinDue = $sBals['walkin_due'];
    $sCol = dash_collection($sBals);
}
if ($seeStock) $sStock = dash_stock($dashLoc);
if ($seeProfit) { $sLowMargin = dash_low_margin($dFrom, $dTo); $sPriceUp = dash_price_increases(); }
if (can('parties.view') && $sBals) $sSeg = dash_segments($sBals, $sCol);
if (can('sales.view')) { $sIntel = dash_sales_intel($dFrom, $dTo); $sTrend = dash_sales_trend(); }
if (is_full_admin()) $sHealth = dash_health();

$repairsReady = can('repairs.view') ? (int)val("SELECT COUNT(*) FROM repairs WHERE status = 'ready'") : 0;
$newOrders = can('weborders.view') ? (int)val("SELECT COUNT(*) FROM web_orders WHERE status = 'new'") : 0;

// The summary, Action Center and alerts are built from a context that hides
// figures the reader is not allowed to see. Cost-derived money (profit, stock
// value, dead-stock value) is behind reports.profit exactly as it is on every
// other screen, so a sales user gets the shortage COUNTS without the rupees.
$ctxKpis = $sKpis;
$ctxStock = $sStock;
if (!$seeProfit) {
    if ($ctxKpis) $ctxKpis['profit'] = 0.0;
    if ($ctxStock) { $ctxStock['dead_value'] = 0.0; $ctxStock['stock_value'] = 0.0; }
}
$dashCtx = [
    'kpis' => $ctxKpis, 'prev' => $sPrev, 'collection' => $sCol, 'stock' => $ctxStock,
    'compare_label' => $cLabel, 'low_margin' => $sLowMargin, 'price_up' => $sPriceUp,
    'health_issues' => $sHealth['issues'] ?? [], 'repairs_ready' => $repairsReady, 'new_orders' => $newOrders,
    'reorder' => $sStock ? array_filter($sStock['low'], fn($x) => $x['reorder_qty'] > 0) : [],
];
$sActions = dash_actions($dashCtx);
$sAlerts  = dash_alerts($dashCtx);
$sSummary = $ctxKpis ? dash_summary($dashCtx) : '';

/** One clickable KPI tile with its comparison arrow. */
function dash_card($label, $value, $link, $now = null, $before = null, $tone = '', $suffix = '₹') {
    $d = ($now === null) ? null : dash_delta($now, $before);
    $arrow = $d === null ? '' : ($d > 0 ? '↑' : ($d < 0 ? '↓' : '→'));
    $dTone = $d === null ? '' : ($d > 0 ? 'up' : ($d < 0 ? 'down' : ''));
    echo '<a class="kpi ' . $tone . '" href="' . e($link) . '">'
       . '<div class="kpi-label">' . e($label) . '</div>'
       . '<div class="kpi-value">' . ($suffix === '₹' ? '₹' : '') . e($value) . ($suffix !== '₹' ? $suffix : '') . '</div>'
       . ($d === null ? '<div class="kpi-delta muted">—</div>'
                      : '<div class="kpi-delta ' . $dTone . '">' . $arrow . ' ' . abs($d) . '%</div>')
       . '</a>';
}

// ---------- Customizable widgets ----------
// Two groups so reordering can't break the layout: "top" widgets are
// full-width cards, "grid" widgets share the 2-column .grid-2 row (a CSS
// grid just lays children out in DOM order, so reordering within a group
// is a plain array sort - no positioning math needed). See
// dashboard_customize.php for the show/hide + reorder UI.
$aiInsights = can('reports.profit') ? ai_dashboard_insights($saleScope . $locScope, array_merge($saleParams, $locParam)) : [];
// The daily summary, the Action Center and the Data Health chip are NOT
// widgets: they are the reason the page exists, so they always sit at the top
// and a stale saved widget order can never push them below the fold.
$topWidgetDefs = [
    'smart_kpis' => (bool)$sKpis,
    'duo' => $canMoney,
    'smart_collection' => (bool)$sCol && $sCol['total'] > 0.009,
    'smart_stock' => (bool)$sStock,
    'smart_sales' => (bool)$sIntel,
    'smart_segments' => (bool)$sSeg,
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

<?php // the handful of things the owner opens every single day ?>
<div class="range-bar no-print" style="margin-bottom:12px">
  <?php if (can('sales.add')): ?><a class="rchip" href="sales.php?action=new">🧾 નવું બિલ</a><?php endif; ?>
  <?php if (can('purchases.add')): ?><a class="rchip" href="purchases.php?action=new">📦 નવી ખરીદી</a><?php endif; ?>
  <?php if (can('payments.add')): ?><a class="rchip" href="payments.php?action=new&dir=in">💵 પેમેન્ટ લો</a><?php endif; ?>
  <?php if ($canMoney): ?><a class="rchip" href="reports.php?r=aging">💰 ઉઘરાણી</a><?php endif; ?>
  <?php if ($seeStock): ?><a class="rchip" href="reports.php?r=low">📉 ઓછો સ્ટોક</a><?php endif; ?>
  <?php if (can('repairs.view')): ?><a class="rchip" href="repairs.php">🛠️ રિપેર<?= $repairsReady ? ' (' . $repairsReady . ')' : '' ?></a><?php endif; ?>
  <?php if (can('weborders.view')): ?><a class="rchip" href="web_orders.php">🌐 ઓર્ડર<?= $newOrders ? ' (' . $newOrders . ')' : '' ?></a><?php endif; ?>
  <a class="rchip" href="reports.php">📊 રિપોર્ટ</a>
  <?php if (is_full_admin()): ?><a class="rchip" href="reports.php?r=health">🩺 ડેટા ચેક</a><?php endif; ?>
</div>

<?php if ($myHandovers): ?>
<div class="flash flash-info">🤝 You have <?= $myHandovers ?> stock handover(s) pending. <a href="my_stock.php">Accept with OTP →</a></div>
<?php endif; ?>

<?php if (is_full_admin()):
    try { $pendEditReq = (int)val("SELECT COUNT(*) FROM edit_requests WHERE status = 'pending'"); } catch (Exception $e) { $pendEditReq = 0; }
    if ($pendEditReq): ?>
<div class="flash flash-info">✏️ <?= $pendEditReq ?> બિલ-એડિટ મંજૂરી બાકી છે. <a href="approvals.php">Review &amp; approve →</a></div>
<?php endif;
    try { $waUnread = (int)val("SELECT COUNT(*) FROM wa_chats WHERE direction = 'in' AND is_read = 0"); } catch (Exception $e) { $waUnread = 0; }
    if ($waUnread): ?>
<div class="flash flash-info">💬 WhatsApp માં <?= $waUnread ?> નવા મેસેજ છે. <a href="wa_inbox.php">Inbox ખોલો →</a></div>
<?php endif; endif; ?>

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

<?php // ---------- આજે શું ચાલે છે (always on top) ---------- ?>
<?php if ($sSummary): ?>
<div class="card smart-summary">
  <div class="ss-head">
    <h2 style="margin:0">આજે દુકાનની સ્થિતિ <span class="muted" style="font-weight:400;font-size:13px">· <?= e($dLabel) ?></span></h2>
    <?php if ($sHealth): ?>
    <a class="hchip h-<?= e($sHealth['state']) ?>" href="reports.php?r=health" title="Data Health Check">
      <?= $sHealth['state'] === 'ok' ? '🟢' : ($sHealth['state'] === 'warn' ? '🟡' : '🔴') ?>
      <?= (int)$sHealth['healthy'] ?>/<?= (int)$sHealth['total'] ?>
    </a>
    <?php endif; ?>
  </div>
  <p class="ss-text"><?= e($sSummary) ?></p>
  <form method="get" class="range-bar">
    <?php if ($dashLoc): ?><input type="hidden" name="loc" value="<?= (int)$dashLoc ?>"><?php endif; ?>
    <?php foreach (['today' => 'આજે', 'yesterday' => 'ગઈકાલે', 'week' => 'આ અઠવાડિયું', 'month' => 'આ મહિનો', 'prev_month' => 'ગયો મહિનો'] as $rk => $rl): ?>
    <button type="submit" name="range" value="<?= $rk ?>" class="rchip <?= $rangeKey === $rk ? 'on' : '' ?>"><?= $rl ?></button>
    <?php endforeach; ?>
    <span class="rcustom">
      <input type="date" name="from" value="<?= e($rangeKey === 'custom' ? $dFrom : '') ?>">
      <input type="date" name="to" value="<?= e($rangeKey === 'custom' ? $dTo : '') ?>">
      <button type="submit" name="range" value="custom" class="rchip <?= $rangeKey === 'custom' ? 'on' : '' ?>">બતાવો</button>
    </span>
  </form>
</div>
<?php endif; ?>

<?php if ($sActions): ?>
<div class="card">
  <h2>🔔 આજે શું કરવાનું છે <span class="muted" style="font-weight:400;font-size:13px">(<?= count($sActions) ?>)</span></h2>
  <?php foreach ($sActions as $a): ?>
  <div class="act act-<?= e($a['sev']) ?>">
    <span class="act-ico"><?= $a['icon'] ?></span>
    <span class="act-body"><span class="act-group"><?= e($a['group']) ?></span><?= e($a['text']) ?></span>
    <a class="btn btn-sm" href="<?= e($a['link']) ?>"><?= e($a['cta']) ?></a>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($sAlerts): ?>
<div class="card">
  <h2>⚠️ ધ્યાન રાખવા જેવું</h2>
  <?php foreach ($sAlerts as $al): ?>
  <a class="alertrow al-<?= e($al['sev']) ?>" href="<?= e($al['link']) ?>"><?= e($al['text']) ?> <span class="muted">›</span></a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php foreach ($topOrder as $_w):
    if (!$allWidgetDefs[$_w] || in_array($_w, $hiddenWidgets, true)) continue;
    if ($_w === 'smart_kpis'): ?>
<div class="card">
  <h2><?= e($dLabel) ?> <span class="muted" style="font-weight:400;font-size:13px">· <?= e($cLabel) ?> સાથે સરખામણી</span></h2>
  <div class="kpi-grid">
    <?php
      $rq = 'from=' . urlencode($dFrom) . '&to=' . urlencode($dTo);
      dash_card('વેચાણ', money($sKpis['sales']), 'reports.php?r=daily&' . $rq, $sKpis['sales'], $sPrev['sales']);
      if ($seeMoney) dash_card('ઉઘરાણી આવી', money($sKpis['collected']), 'reports.php?r=cashbook&' . $rq, $sKpis['collected'], $sPrev['collected'], 's-ok');
      if ($seeProfit) dash_card('નફો', money($sKpis['profit']), 'reports.php?r=profit&' . $rq, $sKpis['profit'], $sPrev['profit'], 's-ok');
      if (can('expenses.view')) dash_card('ખર્ચ', money($sKpis['expenses']), 'expenses.php', $sKpis['expenses'], $sPrev['expenses'], 's-warn');
      if ($seeMoney) dash_card('ચોખ્ખી રોકડ', money($sKpis['net_cash']), 'cash_bank.php', $sKpis['net_cash'], $sPrev['net_cash'], $sKpis['net_cash'] < 0 ? 's-bad' : '');
      dash_card('બિલ', (string)$sKpis['bills'], 'sales.php', $sKpis['bills'], $sPrev['bills'], '', '');
      dash_card('સરેરાશ બિલ', money($sKpis['avg_bill']), 'reports.php?r=daily&' . $rq, $sKpis['avg_bill'], $sPrev['avg_bill']);
      dash_card('નવા ગ્રાહક', (string)$sKpis['new_customers'], 'parties.php', $sKpis['new_customers'], $sPrev['new_customers'], '', '');
      if ($seeMoney && $sBals) {
        dash_card('બાકી લેણું', money($sBals['receivable']), $partiesLink ? 'parties.php?bal=get' : 'reports.php?r=aging', null, null, 's-bad');
        dash_card('બાકી દેવું', money($sBals['payable']), $partiesLink ? 'parties.php?bal=give' : 'reports.php?r=payables', null, null, 's-warn');
      }
      if ($repairsReady) dash_card('તૈયાર રિપેર', (string)$repairsReady, 'repairs.php', null, null, 's-warn', '');
      if ($newOrders) dash_card('નવા ઓર્ડર', (string)$newOrders, 'web_orders.php', null, null, 's-warn', '');
    ?>
  </div>
  <p class="muted" style="margin:8px 0 0;font-size:12px">દરેક આંકડા પર ક્લિક કરો એટલે એની પાછળનું લિસ્ટ ખૂલશે.
    <?php if ($seeProfit && $sKpis['discount'] > 0.009): ?>· ડિસ્કાઉન્ટ આપ્યું ₹<?= money($sKpis['discount']) ?><?php endif; ?>
    <?php if ($seeProfit && $sKpis['margin_pct']): ?>· માર્જિન <?= $sKpis['margin_pct'] ?>%<?php endif; ?>
    · <?= (float)$sKpis['units'] ?> નંગ વેચાયા</p>
</div>
<?php elseif ($_w === 'smart_collection'): ?>
<div class="card">
  <h2>💰 ઉઘરાણી <span class="muted" style="font-weight:400;font-size:13px">(ખાતાવહી પ્રમાણે સાચી બાકી)</span></h2>
  <div class="grid-stats">
    <a class="stat s-bad" href="reports.php?r=aging"><div class="stat-label">કુલ બાકી</div><div class="stat-value">₹<?= money($sCol['total']) ?></div></a>
    <a class="stat s-bad" href="reports.php?r=aging"><div class="stat-label">મુદત વીતી</div><div class="stat-value">₹<?= money($sCol['overdue']) ?></div></a>
    <a class="stat s-warn" href="reports.php?r=aging"><div class="stat-label">આજે ભરવાના</div><div class="stat-value">₹<?= money($sCol['due_today']) ?></div></a>
    <a class="stat" href="reports.php?r=aging"><div class="stat-label">આ અઠવાડિયે</div><div class="stat-value">₹<?= money($sCol['due_week']) ?></div></a>
  </div>
  <?php if ($sCol['oldest_days'] > 0): ?>
  <p class="muted mb">સૌથી જૂનું બાકી: <strong><?= e($sCol['oldest_party']) ?></strong> — <?= (int)$sCol['oldest_days'] ?> દિવસથી</p>
  <?php endif; ?>
  <?php if ($sCol['customers']): ?>
  <div class="table-wrap"><table class="table-sm">
    <thead><tr><th>ગ્રાહક</th><th class="num">બાકી ₹</th><th class="num">દિવસ</th><th>છેલ્લું પેમેન્ટ</th><th>વર્તન</th></tr></thead>
    <tbody>
    <?php foreach (array_slice($sCol['customers'], 0, 8) as $c):
      $bmap = ['excellent' => ['badge-ok', 'સમયસર'], 'good' => ['badge-ok', 'સારું'], 'slow' => ['badge-warn', 'ધીમું'],
               'poor' => ['badge-bad', 'ખરાબ'], 'new' => ['badge-info', 'નવું']];
      list($bcls, $btxt) = $bmap[$c['behaviour']] ?? ['badge-info', '-']; ?>
    <tr>
      <td><a href="parties.php?action=ledger&id=<?= (int)$c['id'] ?>"><?= e($c['name']) ?></a></td>
      <td class="num">₹<?= money($c['amount']) ?></td>
      <td class="num"><?= $c['days'] > 0 ? (int)$c['days'] . 'd' : '-' ?></td>
      <td><?= $c['last_payment'] ? dmy($c['last_payment']) : '<span class="muted">ક્યારેય નહીં</span>' ?></td>
      <td><span class="badge <?= $bcls ?>"><?= $btxt ?></span></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <p class="mt"><a class="btn btn-sm btn-wa" href="reports.php?r=aging">📲 રિમાઇન્ડર મોકલો</a>
     <a class="btn btn-sm btn-outline" href="reports.php?r=aging">આખું લિસ્ટ →</a></p>
  <?php endif; ?>
</div>
<?php elseif ($_w === 'smart_stock'): ?>
<div class="card">
  <h2>📦 સ્ટોકની સ્થિતિ</h2>
  <?php // stock VALUE is cost-price information, so it follows the same
        // reports.profit fence the Inventory Summary card already uses;
        // the shortage COUNTS are safe for anyone who may see items ?>
  <div class="grid-stats">
    <a class="stat s-bad" href="reports.php?r=low"><div class="stat-label">ખલાસ</div><div class="stat-value"><?= count($sStock['out']) ?></div></a>
    <a class="stat s-warn" href="reports.php?r=low"><div class="stat-label">ખૂટવાની તૈયારીમાં</div><div class="stat-value"><?= count($sStock['low']) ?></div></a>
    <?php if ($seeProfit): ?>
    <a class="stat" href="reports.php?r=stockval"><div class="stat-label">સ્ટોકની કિંમત</div><div class="stat-value">₹<?= money($sStock['stock_value']) ?></div></a>
    <a class="stat s-warn" href="reports.php?r=dead_stock"><div class="stat-label">પડી રહેલો માલ</div><div class="stat-value">₹<?= money($sStock['dead_value']) ?></div></a>
    <?php else: ?>
    <a class="stat" href="reports.php?r=low"><div class="stat-label">કુલ પ્રોડક્ટ</div><div class="stat-value"><?= (int)$sStock['items'] ?></div></a>
    <a class="stat s-warn" href="reports.php?r=low"><div class="stat-label">પડી રહેલી પ્રોડક્ટ</div><div class="stat-value"><?= count($sStock['deadlist']) ?></div></a>
    <?php endif; ?>
  </div>
  <?php if ($seeProfit && $sStock['dead_value'] > 0.009): ?>
  <h3>🐌 કેટલા વખતથી પડ્યું છે</h3>
  <div class="grid-stats">
    <?php foreach ($sStock['dead_buckets'] as $bk => $bv): ?>
    <a class="stat <?= $bv > 0 ? ($bk === '180+' ? 's-bad' : 's-warn') : '' ?>" href="reports.php?r=dead_stock">
      <div class="stat-label"><?= e($bk) ?> દિવસ</div><div class="stat-value">₹<?= money($bv) ?></div></a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <?php $short = array_merge($sStock['out'], $sStock['low']); if ($short): ?>
  <h3>તરત ધ્યાન આપો</h3>
  <div class="table-wrap"><table class="table-sm">
    <thead><tr><th>પ્રોડક્ટ</th><th class="num">સ્ટોક</th><th class="num">રોજ વેચાય</th><th class="num">કેટલા દિવસ ચાલશે</th><th class="num">મંગાવો</th><th>છેલ્લે વેચાયું</th></tr></thead>
    <tbody>
    <?php foreach (array_slice($short, 0, 8) as $it): ?>
    <tr>
      <td><a href="item_view.php?id=<?= (int)$it['id'] ?>"><?= e($it['name']) ?></a></td>
      <td class="num <?= $it['qty'] <= 0.009 ? 'muted' : '' ?>"><?= (float)$it['qty'] ?> <?= e($it['unit']) ?></td>
      <td class="num"><?= $it['per_day'] > 0 ? $it['per_day'] : '-' ?></td>
      <td class="num"><?= $it['days_left'] === null ? '-' : (int)$it['days_left'] . 'd' ?></td>
      <td class="num"><?= $it['reorder_qty'] > 0 ? '<strong>' . (int)$it['reorder_qty'] . '</strong>' : '-' ?></td>
      <td><?= $it['last_sale'] ? dmy($it['last_sale']) : '<span class="muted">ક્યારેય નહીં</span>' ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <p class="mt"><a class="btn btn-sm" href="purchases.php?action=new">🛒 પરચેસ કરો</a>
     <a class="btn btn-sm btn-outline" href="reports.php?r=purchase_reco">સૂચન જુઓ →</a></p>
  <?php endif; ?>
</div>
<?php elseif ($_w === 'smart_sales'): ?>
<div class="card">
  <h2>📈 વેચાણનું વિશ્લેષણ
    <span class="muted" style="font-weight:400;font-size:13px">·
      <?= $sTrend['direction'] === 'up' ? '↑ વધી રહ્યું છે' : ($sTrend['direction'] === 'down' ? '↓ ઘટી રહ્યું છે' : '→ સ્થિર') ?>
      (છેલ્લા 6 મહિના)</span></h2>
  <div class="grid-2">
    <?php
      $blocks = [
        ['ટોપ પ્રોડક્ટ', $sIntel['products'], 'item_view.php?id=', 'reports.php?r=sales'],
        ['ટોપ ગ્રાહક', $sIntel['customers'], 'parties.php?action=ledger&id=', 'reports.php?r=party_sales'],
        ['ટોપ કેટેગરી', $sIntel['categories'], '', 'reports.php?r=sales'],
        ['ટોપ બ્રાન્ડ', $sIntel['brands'], '', 'reports.php?r=sales'],
      ];
      foreach ($blocks as list($bTitle, $bRows, $bLink, $bMore)):
        if (!$bRows) continue; ?>
    <div>
      <h3><?= e($bTitle) ?></h3>
      <table class="table-sm"><tbody>
      <?php foreach ($bRows as $x): ?>
        <tr>
          <td><?= $bLink && isset($x['id']) ? '<a href="' . e($bLink . (int)$x['id']) . '">' . e($x['name']) . '</a>' : e($x['name']) ?></td>
          <td class="num">₹<?= money($x['amount']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody></table>
      <p class="mt"><a class="muted" href="<?= e($bMore) ?>" style="font-size:12px">બધું જુઓ →</a></p>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php elseif ($_w === 'smart_segments'): ?>
<div class="card">
  <h2>👥 ગ્રાહકોના પ્રકાર</h2>
  <div class="grid-stats">
    <?php
      $segLabels = ['vip' => ['⭐ VIP', 's-ok'], 'high_value' => ['💎 મોટા ગ્રાહક', 's-ok'], 'regular' => ['🔁 નિયમિત', ''],
                    'new' => ['🆕 નવા', ''], 'at_risk' => ['⚠️ છૂટી રહ્યા', 's-warn'],
                    'inactive' => ['😴 બંધ થઈ ગયા', 's-warn'], 'overdue' => ['⏰ મુદત વીતી', 's-bad']];
      foreach ($segLabels as $sk => list($sl, $stone)):
        $sv = $sSeg[$sk] ?? null; if (!$sv || !$sv['count']) continue; ?>
    <a class="stat <?= $stone ?>" href="parties.php?seg=<?= e($sk) ?>">
      <div class="stat-label"><?= $sl ?></div>
      <div class="stat-value"><?= (int)$sv['count'] ?></div>
      <div class="muted" style="font-size:11px">
        ₹<?= money($sv['sales']) ?> વેચાણ<?= $sv['outstanding'] > 0.009 ? ' · ₹' . money($sv['outstanding']) . ' બાકી' : '' ?>
        <?= $sv['last_purchase'] ? '<br>છેલ્લે ' . dmy($sv['last_purchase']) : '' ?>
      </div>
    </a>
    <?php endforeach; ?>
  </div>
  <p class="muted" style="font-size:12px;margin:0">આ ભાગ પાડવાનું કામ ચોક્કસ નિયમોથી થાય છે — વેચાણની રકમ, બિલની સંખ્યા અને છેલ્લી ખરીદીની તારીખ. કોઈ અંદાજ નથી.</p>
</div>
<?php elseif ($_w === 'duo'): ?>
<div class="duo-cards">
  <a class="duo-card duo-get" href="<?= $partiesLink ? 'parties.php?bal=get' : 'payments.php?action=new&dir=in' ?>"><div class="duo-label">To Receive</div><div class="duo-value">₹ <?= money($recv) ?></div></a>
  <a class="duo-card duo-give" href="<?= $partiesLink ? 'parties.php?bal=give' : 'payments.php?action=new&dir=out' ?>"><div class="duo-label">To Pay</div><div class="duo-value">₹ <?= money($paybl) ?></div></a>
</div>
<?php if ($walkinDue > 0.009): ?>
<p class="muted mt" style="margin-top:-6px;margin-bottom:14px">+ ₹<?= money($walkinDue) ?> due on walk-in bills (no party - collect directly from the Sale List)</p>
<?php endif; ?>
<?php if (can('weborders.view')):
    try { $_wv = row('SELECT COUNT(*) u, COALESCE(SUM(views),0) v FROM site_visits WHERE visit_date = CURDATE()'); } catch (Exception $e) { $_wv = null; }
    if ($_wv): ?>
<p class="muted" style="margin-top:-6px;margin-bottom:14px">🌐 Website today: <strong><?= (int)$_wv['u'] ?></strong> visitors · <?= (int)$_wv['v'] ?> views — <a href="reports.php?r=web_visits">full report</a></p>
<?php endif; endif; ?>
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
