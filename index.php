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
$chartFrom = month_start(5);   // anchored, or a month-end day loses months
$chartTo = date('Y-m-t');
$byMonth = [];
foreach (all("SELECT DATE_FORMAT(sale_date, '%Y-%m') ym, COALESCE(SUM(total),0) t
              FROM sales WHERE is_cancelled = 0 AND sale_date BETWEEN ? AND ? $saleScope$locScope
              GROUP BY ym", array_merge([$chartFrom, $chartTo], $saleParams, $locParam)) as $r)
    $byMonth[$r['ym']] = (float)$r['t'];
$costByMonth = [];
if (can('reports.profit')) {
    // the shared cost rule, so the trend line agrees with the profit KPI above
    // it and with every profit report (see profit_cost_sql() in money.php)
    foreach (all("SELECT DATE_FORMAT(s.sale_date, '%Y-%m') ym, COALESCE(SUM(" . profit_cost_sql() . "),0) c
                  FROM sale_items si JOIN sales s ON s.id = si.sale_id JOIN items i ON i.id = si.item_id
                  WHERE s.is_cancelled = 0 AND s.sale_date BETWEEN ? AND ? "
                . scope_for($saleScope . $locScope, 's')   // this query JOINs; see scope_for()
                . " GROUP BY ym", array_merge([$chartFrom, $chartTo], $saleParams, $locParam)) as $r)
        $costByMonth[$r['ym']] = (float)$r['c'];
}
for ($i = 5; $i >= 0; $i--) {
    $mStart = month_start($i);
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
        // the shared rule, so this card and every report agree - see money.php
        'value' => can('reports.profit') ? stock_value() : null,
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
// Phase 5's pi_summary() supersedes these two: it measures margin against the
// price actually last paid rather than a period's sales, and reports price
// rises per supplier. They are only computed when purchase intelligence is not
// available to this user, so the dashboard never works both out.
if (can('parties.view') && $sBals) $sSeg = dash_segments($sBals, $sCol);
if (can('sales.view')) { $sIntel = dash_sales_intel($dFrom, $dTo); $sTrend = dash_sales_trend(); }
if (is_full_admin()) $sHealth = dash_health();

$repairsReady = can('repairs.view') ? (int)val("SELECT COUNT(*) FROM repairs WHERE status = 'ready'") : 0;
$newOrders = can('weborders.view') ? (int)val("SELECT COUNT(*) FROM web_orders WHERE status = 'new'") : 0;

// Collection intelligence (Phase 4). Only for someone who may see money, and
// cached briefly - the queue scores every debtor, which is the one genuinely
// expensive thing on this page.
$sCollSum = null;
$sPromises = ['due' => [], 'broken' => []];
if ($seeMoney) {
    $sCollSum = dash_cache('collection', 300, 'coll_summary');
    $sPromises = coll_promises_due();
}

// Purchase intelligence (Phase 5). Behind the same cost fence the screen
// itself uses, and cached for five minutes - the reorder sweep reads every
// item's sales history, which is not something to redo on every page load.
$sPurch = null;
if (can('purchases.view') && ($seeProfit || can('items.cost'))) {
    $sPurch = dash_cache('purchase', 300, 'pi_summary');
}
if ($seeProfit && !$sPurch) { $sLowMargin = dash_low_margin($dFrom, $dTo); $sPriceUp = dash_price_increases(); }

// Market intelligence (Phase 7). Same fence as the screen, its own 15-minute
// cache, and it only appears when the books actually have something to say -
// a shrinking category, a real fall against last year, or discounting worth
// looking at. A card that says "nothing changed" every day teaches the owner
// to stop reading the dashboard.
$sMarket = null;
if (can('reports.view') && ($seeProfit || can('items.cost'))) {
    $m = mk_summary();
    if ($m['shrinking'] > 0 || $m['change']['dir'] === 'down' || $m['discount_pct'] >= 2) $sMarket = $m;
}

// A campaign mid-flight (Phase 8). Shown only while one is actually running,
// because a finished campaign is not news - and the card carries the count of
// people held back, so the holdout stays visible rather than being a hidden
// mechanism the owner forgets is there.
// Cash ahead (Phase 9). The card appears ONLY when the projection actually
// finds a week where the money runs short - a forecast card that says "you are
// fine" every morning is noise, and the owner stops reading the dashboard.
$sCash = null;
if ($seeMoney && can('reports.view')) {
    $f = fc_summary();
    if ($f['danger_weeks'] > 0) $sCash = $f;
}

$sCampaign = null;
if (can('campaigns.view')) {
    $sCampaign = row("SELECT id, name, status FROM campaigns WHERE status IN ('ready','sending') ORDER BY id LIMIT 1");
    if ($sCampaign) $sCampaign['counts'] = cam_counts((int)$sCampaign['id']);
}

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
    'collection_sum' => $sCollSum,
    'purchase' => $sPurch,
    'promises_due' => count($sPromises['due']),
    'promises_broken' => count($sPromises['broken']),
];
$sActions = dash_actions($dashCtx);
$sAlerts  = dash_alerts($dashCtx);
$sSummary = $ctxKpis ? dash_summary($dashCtx) : '';


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
    'smart_queue' => (bool)$sCollSum && $sCollSum['customers'] > 0,
    'smart_purchase' => (bool)$sPurch && $sPurch['reorder_items'] > 0,
    'smart_market' => (bool)$sMarket,
    'smart_cash' => (bool)$sCash,
    'smart_campaign' => (bool)$sCampaign,
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

<?php
// WHAT IS THE SHOP DOING TODAY - and nothing above it.
//
// The page used to open with four big tiles (Sale List, Purchase List, Stock
// Items, Parties) and a pile of chips. On a phone that was the entire first
// screen: an owner had to scroll past four navigation buttons to find out
// whether anything had been sold. Navigation is what the sidebar is for. The
// tiles are still here, further down, under "More".
$dashBranch = '';
if ($dashLoc) foreach ($locsAllDash as $l) if ((int)$l['id'] === $dashLoc) $dashBranch = $l['name'];
?>
<div class="pg-head">
  <div class="pg-main">
    <div class="pg-crumb"><?= e($app_name ?? 'AK Computer') ?><?= $dashBranch ? ' · ' . e($dashBranch) : ($locsAllDash ? ' · all branches' : '') ?></div>
    <h1>📊 Dashboard</h1>
    <?php if ($sSummary): ?><div class="pg-sub"><?= e($sSummary) ?></div><?php endif; ?>
  </div>
  <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
    <?php if ($sHealth): ?>
    <a class="hchip h-<?= e($sHealth['state']) ?>" href="reports.php?r=health" title="Data Health Check">
      <?= $sHealth['state'] === 'ok' ? '🟢' : ($sHealth['state'] === 'warn' ? '🟡' : '🔴') ?>
      <?= (int)$sHealth['healthy'] ?>/<?= (int)$sHealth['total'] ?>
    </a>
    <?php endif; ?>
    <a class="btn btn-sm btn-outline no-print" href="dashboard_customize.php">⚙️ Customize</a>
  </div>
</div>

<!-- which days, and which branch: one bar, one submit -->
<form method="get" class="dash-filter no-print">
  <div class="range-bar">
    <?php foreach (['today' => 'Today', 'yesterday' => 'Yesterday', 'week' => 'This week', 'month' => 'This month', 'prev_month' => 'Last month'] as $rk => $rl): ?>
    <button type="submit" name="range" value="<?= $rk ?>" class="rchip <?= $rangeKey === $rk ? 'on' : '' ?>"><?= $rl ?></button>
    <?php endforeach; ?>
  </div>
  <div class="dash-filter-right">
    <?php if ($locsAllDash): ?>
    <select name="loc" onchange="this.form.submit()" aria-label="Branch">
      <option value="0">All branches</option>
      <?php foreach ($locsAllDash as $l): ?><option value="<?= $l['id'] ?>" <?= $dashLoc == $l['id'] ? 'selected' : '' ?>><?= e($l['name']) ?></option><?php endforeach; ?>
    </select>
    <?php endif; ?>
    <!-- two date boxes and a Show button are four rows on a phone and are
         wanted about once a month, so they stay shut until they are asked
         for - and open by themselves when a custom range IS the one showing -->
    <details class="dash-custom"<?= $rangeKey === 'custom' ? ' open' : '' ?>>
      <summary class="rchip <?= $rangeKey === 'custom' ? 'on' : '' ?>">📅 Other dates</summary>
      <div class="dash-custom-body">
        <input type="date" name="from" value="<?= e($rangeKey === 'custom' ? $dFrom : '') ?>" aria-label="From">
        <input type="date" name="to" value="<?= e($rangeKey === 'custom' ? $dTo : '') ?>" aria-label="To">
        <button type="submit" name="range" value="custom" class="rchip">Show</button>
      </div>
    </details>
  </div>
</form>

<!-- the three things that get pressed, then everything else behind one line -->
<div class="qa-grid no-print" style="margin-bottom:12px">
  <?php if (can('sales.add')): ?>
  <a class="qa qa-ok" href="sales.php?action=new"><span class="qa-i">🧾</span><span class="qa-n">New bill</span><span class="qa-s">Write a sale</span></a>
  <?php endif; ?>
  <?php if (can('purchases.add')): ?>
  <a class="qa" href="purchases.php?action=new"><span class="qa-i">📦</span><span class="qa-n">New purchase</span><span class="qa-s">Goods coming in</span></a>
  <?php endif; ?>
  <?php if (can('payments.add')): ?>
  <a class="qa qa-ok" href="payments.php?action=new&dir=in"><span class="qa-i">💵</span><span class="qa-n">Take payment</span><span class="qa-s">Money received</span></a>
  <?php endif; ?>
  <?php if ($canMoney): ?>
  <a class="qa qa-warn" href="collection.php"><span class="qa-i">📮</span><span class="qa-n">Collections</span><span class="qa-s">Who to ask today</span></a>
  <?php endif; ?>
</div>

<details class="more-opts no-print" style="margin-bottom:12px">
  <summary>More — lists, reports and the rest</summary>
  <div class="range-bar" style="padding-bottom:10px">
    <?php if (can('sales.view')): ?><a class="rchip" href="sales.php">🧾 Sale list</a><?php endif; ?>
    <?php if (can('purchases.view')): ?><a class="rchip" href="purchases.php">📦 Purchase list</a><?php endif; ?>
    <?php if (can('items.view')): ?><a class="rchip" href="items.php">🗃️ Stock items</a><?php endif; ?>
    <?php if (can('parties.view')): ?><a class="rchip" href="parties.php">👥 Parties</a><?php endif; ?>
    <?php if (can('payments.add')): ?><a class="rchip" href="payments.php?action=new&dir=out">💸 Pay out</a><?php endif; ?>
    <?php if (can('expenses.add')): ?><a class="rchip" href="expenses.php">🧾 Expense</a><?php endif; ?>
    <?php if (can('sales.add')): ?><a class="rchip" href="estimates.php?action=new">📄 Estimate</a><?php endif; ?>
    <?php if (can('sales.add')): ?><a class="rchip" href="challans.php?action=new">🚚 Challan</a><?php endif; ?>
    <?php if (can('sales.add')): ?><a class="rchip" href="sales_return.php?action=new">↩️ Sale return</a><?php endif; ?>
    <?php if (can('parties.add')): ?><a class="rchip" href="parties.php?action=new">👤 New party</a><?php endif; ?>
    <?php if (can('items.add')): ?><a class="rchip" href="items.php?action=new">🏷️ New item</a><?php endif; ?>
    <?php if (can('repairs.view')): ?><a class="rchip" href="repairs.php">🛠️ Repairs<?= $repairsReady ? ' (' . $repairsReady . ')' : '' ?></a><?php endif; ?>
    <?php if (can('tasks.view')): ?><a class="rchip" href="tasks.php">📋 Tasks</a><?php endif; ?>
    <a class="rchip" href="my_stock.php">🤝 My stock</a>
    <?php if (can('leads.view')): ?><a class="rchip" href="leads.php">🎯 Leads</a><?php endif; ?>
    <?php if (can('tickets.view')): ?><a class="rchip" href="tickets.php">🎫 Tickets</a><?php endif; ?>
    <?php if (can('weborders.view')): ?><a class="rchip" href="web_orders.php">🌐 Orders<?= $newOrders ? ' (' . $newOrders . ')' : '' ?></a><?php endif; ?>
    <?php if ($seeStock): ?><a class="rchip" href="reports.php?r=low">📉 Low stock</a><?php endif; ?>
    <a class="rchip" href="reports.php">📊 Reports</a>
    <?php if (is_full_admin()): ?><a class="rchip" href="reports.php?r=health">🩺 Data check</a><?php endif; ?>
  </div>
</details>

<?php if ($myHandovers): ?>
<div class="flash flash-info">🤝 You have <?= $myHandovers ?> stock handover(s) pending. <a href="my_stock.php">Accept with OTP →</a></div>
<?php endif; ?>

<?php if (is_full_admin()):
    try { $pendEditReq = (int)val("SELECT COUNT(*) FROM edit_requests WHERE status = 'pending'"); } catch (Exception $e) { $pendEditReq = 0; }
    if ($pendEditReq): ?>
<div class="flash flash-info">✏️ <?= $pendEditReq ?> bill-edit approvals are waiting. <a href="approvals.php">Review &amp; approve →</a></div>
<?php endif;
    try { $waUnread = (int)val("SELECT COUNT(*) FROM wa_chats WHERE direction = 'in' AND is_read = 0"); } catch (Exception $e) { $waUnread = 0; }
    if ($waUnread): ?>
<div class="flash flash-info">💬 <?= $waUnread ?> new WhatsApp message<?= $waUnread === 1 ? '' : 's' ?>. <a href="wa_inbox.php">Open the inbox →</a></div>
<?php endif; endif; ?>

<?php if ($sActions): ?>
<div class="pane">
  <div class="pane-head"><h3>🔔 What to do today</h3>
    <span class="pane-note" style="padding:0"><?= count($sActions) ?> thing<?= count($sActions) === 1 ? '' : 's' ?></span></div>
  <div class="pane-body">
  <?php foreach ($sActions as $a): ?>
  <div class="act act-<?= e($a['sev']) ?>">
    <span class="act-ico"><?= $a['icon'] ?></span>
    <span class="act-body"><span class="act-group"><?= e($a['group']) ?></span><?= e($a['text']) ?></span>
    <a class="btn btn-sm" href="<?= e($a['link']) ?>"><?= e($a['cta']) ?></a>
  </div>
  <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php if ($sAlerts): ?>
<div class="pane">
  <div class="pane-head"><h3>⚠️ Worth watching</h3></div>
  <div class="pane-body">
  <?php foreach ($sAlerts as $al): ?>
  <a class="alertrow al-<?= e($al['sev']) ?>" href="<?= e($al['link']) ?>"><?= e($al['text']) ?> <span class="muted">›</span></a>
  <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php foreach ($topOrder as $_w):
    if (!$allWidgetDefs[$_w] || in_array($_w, $hiddenWidgets, true)) continue;
    if ($_w === 'smart_kpis'): ?>
<div class="card">
  <h2><?= e($dLabel) ?> <span class="muted" style="font-weight:400;font-size:13px">· against <?= e($cLabel) ?></span></h2>
  <div class="kpi-grid">
    <?php
      $rq = 'from=' . urlencode($dFrom) . '&to=' . urlencode($dTo);
      dash_card('Sales', money($sKpis['sales']), 'reports.php?r=daily&' . $rq, $sKpis['sales'], $sPrev['sales']);
      if ($seeMoney) dash_card('Collected', money($sKpis['collected']), 'reports.php?r=cashbook&' . $rq, $sKpis['collected'], $sPrev['collected'], 's-ok');
      if ($seeProfit) dash_card('Profit', money($sKpis['profit']), 'reports.php?r=profit&' . $rq, $sKpis['profit'], $sPrev['profit'], 's-ok');
      if (can('expenses.view')) dash_card('Expenses', money($sKpis['expenses']), 'expenses.php', $sKpis['expenses'], $sPrev['expenses'], 's-warn');
      if ($seeMoney) dash_card('Net cash', money($sKpis['net_cash']), 'cash_bank.php', $sKpis['net_cash'], $sPrev['net_cash'], $sKpis['net_cash'] < 0 ? 's-bad' : '');
      dash_card('Bill', (string)$sKpis['bills'], 'sales.php', $sKpis['bills'], $sPrev['bills'], '', '');
      dash_card('Average bill', money($sKpis['avg_bill']), 'reports.php?r=daily&' . $rq, $sKpis['avg_bill'], $sPrev['avg_bill']);
      dash_card('New customers', (string)$sKpis['new_customers'], 'parties.php', $sKpis['new_customers'], $sPrev['new_customers'], '', '');
      if ($seeMoney && $sBals) {
        dash_card('Receivable', money($sBals['receivable']), $partiesLink ? 'parties.php?bal=get' : 'reports.php?r=aging', null, null, 's-bad');
        dash_card('Payable', money($sBals['payable']), $partiesLink ? 'parties.php?bal=give' : 'reports.php?r=payables', null, null, 's-warn');
      }
      if ($repairsReady) dash_card('Repairs ready', (string)$repairsReady, 'repairs.php', null, null, 's-warn', '');
      if ($newOrders) dash_card('New orders', (string)$newOrders, 'web_orders.php', null, null, 's-warn', '');
    ?>
  </div>
  <p class="muted" style="margin:8px 0 0;font-size:12px">Click any figure to open the list behind it.
    <?php if ($seeProfit && $sKpis['discount'] > 0.009): ?>· discount given Rs <?= money($sKpis['discount']) ?><?php endif; ?>
    <?php if ($seeProfit && $sKpis['margin_pct']): ?>· margin <?= $sKpis['margin_pct'] ?>%<?php endif; ?>
    · <?= (float)$sKpis['units'] ?> units sold</p>
</div>
<?php elseif ($_w === 'smart_queue'): ?>
<div class="card">
  <h2>📮 Collection priority <span class="muted" style="font-weight:400;font-size:13px">· who to ask first</span></h2>
  <div class="grid-stats">
    <a class="stat s-bad" href="collection.php"><div class="stat-label">Urgent</div><div class="stat-value"><?= (int)$sCollSum['critical'] ?></div></a>
    <a class="stat s-warn" href="collection.php"><div class="stat-label">High priority</div><div class="stat-value"><?= (int)$sCollSum['high'] ?></div></a>
    <a class="stat" href="collection.php"><div class="stat-label">can be messaged now</div><div class="stat-value"><?= (int)$sCollSum['contactable'] ?></div></a>
    <a class="stat <?= $sCollSum['broken'] ? 's-bad' : '' ?>" href="collection.php"><div class="stat-label">Promises broken (30 days)</div><div class="stat-value"><?= (int)$sCollSum['broken'] ?></div></a>
  </div>
  <?php if ($sCollSum['promises_open']): ?>
  <p class="muted mb" style="font-size:13px">🤝 <?= (int)$sCollSum['promises_open'] ?> customers have promised to pay<?= $sCollSum['promised_today'] > 0.009 ? ' — today Rs ' . money($sCollSum['promised_today']) . ' are due in' : '' ?>.</p>
  <?php endif; ?>
  <p class="mt" style="margin:0"><a class="btn btn-sm" href="collection.php">📮 Open the collection list</a></p>
</div>
<?php elseif ($_w === 'smart_purchase'): ?>
<div class="card">
  <h2>🛒 What to buy <span class="muted" style="font-weight:400;font-size:13px">· likely to run short before delivery</span></h2>
  <div class="grid-stats">
    <a class="stat s-bad" href="purchase_intel.php"><div class="stat-label">ran out</div><div class="stat-value"><?= (int)$sPurch['out_of_stock'] ?></div></a>
    <a class="stat s-warn" href="purchase_intel.php"><div class="stat-label">worth ordering</div><div class="stat-value"><?= (int)$sPurch['reorder_items'] ?></div></a>
    <a class="stat" href="purchase_intel.php"><div class="stat-label">Estimated cost</div><div class="stat-value">₹<?= money($sPurch['reorder_cost']) ?></div></a>
    <a class="stat <?= $sPurch['price_alerts'] ? 's-warn' : '' ?>" href="purchase_intel.php?tab=price"><div class="stat-label">cost went up</div><div class="stat-value"><?= (int)$sPurch['price_alerts'] ?></div></a>
  </div>
  <p class="mt" style="margin:0">
    <a class="btn btn-sm" href="purchase_intel.php">🛒 Open the buying list</a>
    <?php if ($sPurch['thin_margin']): ?>
    <a class="btn btn-sm btn-outline" href="purchase_intel.php?tab=margin">🏷️ <?= (int)$sPurch['thin_margin'] ?> items on a thin margin</a>
    <?php endif; ?>
  </p>
</div>
<?php elseif ($_w === 'smart_cash'): ?>
<div class="card">
  <h2>💵 Cash will be tight <span class="muted" style="font-weight:400;font-size:13px">· the weeks ahead</span></h2>
  <div class="grid-stats">
    <a class="stat s-bad" href="forecast.php?tab=cash"><div class="stat-label">tight weeks</div><div class="stat-value"><?= (int)$sCash['danger_weeks'] ?></div></a>
    <a class="stat s-bad" href="forecast.php?tab=cash"><div class="stat-label">First of all</div><div class="stat-value" style="font-size:15px"><?= e($sCash['danger_label']) ?></div></a>
    <a class="stat" href="forecast.php?tab=cash"><div class="stat-label">would be saved</div><div class="stat-value">₹<?= money($sCash['danger_balance']) ?></div></a>
    <a class="stat" href="collection.php"><div class="stat-label">on hand right now</div><div class="stat-value">₹<?= money($sCash['opening']) ?></div></a>
  </div>
  <p class="mt muted" style="margin:0;font-size:12.5px">
    In this calculation <b>overdue receivables are not counted</b> — assuming it will arrive would be wrong.
    Collecting that would improve the picture. <a href="collection.php">Collection list</a> · <a href="forecast.php?tab=cash">The whole picture</a>
  </p>
</div>
<?php elseif ($_w === 'smart_market'): ?>
<div class="card">
  <h2>📊 Market Intelligence <span class="muted" style="font-weight:400;font-size:13px">· the full year against the previous full year</span></h2>
  <div class="grid-stats">
    <a class="stat <?= $sMarket['change']['dir'] === 'down' ? 's-bad' : ($sMarket['change']['dir'] === 'up' ? 's-good' : '') ?>" href="market.php">
      <div class="stat-label">sales against last year</div><div class="stat-value"><?= e($sMarket['change']['label']) ?></div></a>
    <a class="stat s-good" href="market.php?tab=momentum"><div class="stat-label">Growing category</div><div class="stat-value"><?= (int)$sMarket['growing'] ?></div></a>
    <a class="stat <?= $sMarket['shrinking'] ? 's-bad' : '' ?>" href="market.php?tab=momentum"><div class="stat-label">Shrinking category</div><div class="stat-value"><?= (int)$sMarket['shrinking'] ?></div></a>
    <a class="stat <?= $sMarket['discount_pct'] >= 5 ? 's-warn' : '' ?>" href="market.php?tab=discount">
      <div class="stat-label">Discount (<?= e($sMarket['discount_month']) ?>)</div><div class="stat-value"><?= $sMarket['discount_pct'] ?>%</div></a>
  </div>
  <p class="mt" style="margin:0">
    <a class="btn btn-sm" href="market.php">📊 Open Market Intelligence</a>
    <?php if ($sMarket['top_down']): ?>
    <a class="btn btn-sm btn-outline" href="market.php?tab=momentum">▼ Biggest drop: <?= e($sMarket['top_down']) ?></a>
    <?php endif; ?>
  </p>
</div>
<?php elseif ($_w === 'smart_campaign'): $kc = $sCampaign['counts']; ?>
<div class="card">
  <h2>📣 <?= e($sCampaign['name']) ?> <span class="badge b-warn">running</span></h2>
  <div class="grid-stats">
    <a class="stat s-good" href="campaigns.php?id=<?= (int)$sCampaign['id'] ?>"><div class="stat-label">sent</div><div class="stat-value"><?= (int)$kc['sent'] ?></div></a>
    <a class="stat s-warn" href="campaigns.php?id=<?= (int)$sCampaign['id'] ?>"><div class="stat-label">Outstanding</div><div class="stat-value"><?= (int)$kc['queued'] ?></div></a>
    <a class="stat" href="campaigns.php?id=<?= (int)$sCampaign['id'] ?>"><div class="stat-label">held back</div><div class="stat-value"><?= (int)$kc['holdout'] ?></div></a>
    <a class="stat <?= $kc['failed'] ? 's-bad' : '' ?>" href="campaigns.php?id=<?= (int)$sCampaign['id'] ?>"><div class="stat-label">failed</div><div class="stat-value"><?= (int)$kc['failed'] ?></div></a>
  </div>
  <p class="mt muted" style="margin:0;font-size:12.5px">
    <?= (int)$kc['holdout'] ?> customers were deliberately not messaged — comparing against them shows whether the campaign really made a difference.
    <a href="campaigns.php?id=<?= (int)$sCampaign['id'] ?>">See detail</a>
  </p>
</div>
<?php elseif ($_w === 'smart_collection'): ?>
<div class="card">
  <h2>💰 Collections <span class="muted" style="font-weight:400;font-size:13px">(the true balance per the ledger)</span></h2>
  <div class="grid-stats">
    <a class="stat s-bad" href="reports.php?r=aging"><div class="stat-label">Total outstanding</div><div class="stat-value">₹<?= money($sCol['total']) ?></div></a>
    <a class="stat s-bad" href="reports.php?r=aging"><div class="stat-label">Overdue</div><div class="stat-value">₹<?= money($sCol['overdue']) ?></div></a>
    <a class="stat s-warn" href="reports.php?r=aging"><div class="stat-label">Due today</div><div class="stat-value">₹<?= money($sCol['due_today']) ?></div></a>
    <a class="stat" href="reports.php?r=aging"><div class="stat-label">This week</div><div class="stat-value">₹<?= money($sCol['due_week']) ?></div></a>
  </div>
  <?php if ($sCol['oldest_days'] > 0): ?>
  <p class="muted mb">Oldest outstanding: <strong><?= e($sCol['oldest_party']) ?></strong> — <?= (int)$sCol['oldest_days'] ?> days since</p>
  <?php endif; ?>
  <?php if ($sCol['customers']): ?>
  <div class="table-wrap"><table class="table-sm">
    <thead><tr><th>Customer</th><th class="num">Due Rs </th><th class="num">days</th><th>Last payment</th><th>Behaviour</th></tr></thead>
    <tbody>
    <?php foreach (array_slice($sCol['customers'], 0, 8) as $c):
      $bmap = ['excellent' => ['badge-ok', 'On time'], 'good' => ['badge-ok', 'Good'], 'slow' => ['badge-warn', 'Slow'],
               'poor' => ['badge-bad', 'Poor'], 'new' => ['badge-info', 'New']];
      list($bcls, $btxt) = $bmap[$c['behaviour']] ?? ['badge-info', '-']; ?>
    <tr>
      <td><a href="parties.php?action=ledger&id=<?= (int)$c['id'] ?>"><?= e($c['name']) ?></a></td>
      <td class="num">₹<?= money($c['amount']) ?></td>
      <td class="num"><?= $c['days'] > 0 ? (int)$c['days'] . 'd' : '-' ?></td>
      <td><?= $c['last_payment'] ? dmy($c['last_payment']) : '<span class="muted">never</span>' ?></td>
      <td><span class="badge <?= $bcls ?>"><?= $btxt ?></span></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <p class="mt"><a class="btn btn-sm btn-wa" href="reports.php?r=aging">📲 Send a reminder</a>
     <a class="btn btn-sm btn-outline" href="reports.php?r=aging">Full list →</a></p>
  <?php endif; ?>
</div>
<?php elseif ($_w === 'smart_stock'): ?>
<div class="card">
  <h2>📦 Stock position</h2>
  <?php // stock VALUE is cost-price information, so it follows the same
        // reports.profit fence the Inventory Summary card already uses;
        // the shortage COUNTS are safe for anyone who may see items ?>
  <div class="grid-stats">
    <a class="stat s-bad" href="reports.php?r=low"><div class="stat-label">Out of stock</div><div class="stat-value"><?= dash_n($sStock, 'out') ?></div></a>
    <a class="stat s-warn" href="reports.php?r=low"><div class="stat-label">about to run short</div><div class="stat-value"><?= dash_n($sStock, 'low') ?></div></a>
    <?php if ($seeProfit): ?>
    <a class="stat" href="reports.php?r=stockval"><div class="stat-label">Stock value</div><div class="stat-value">₹<?= money($sStock['stock_value']) ?></div></a>
    <a class="stat s-warn" href="reports.php?r=dead_stock"><div class="stat-label">Dead stock</div><div class="stat-value">₹<?= money($sStock['dead_value']) ?></div></a>
    <?php else: ?>
    <a class="stat" href="reports.php?r=low"><div class="stat-label">Total products</div><div class="stat-value"><?= (int)$sStock['items'] ?></div></a>
    <a class="stat s-warn" href="reports.php?r=low"><div class="stat-label">products sitting unsold</div><div class="stat-value"><?= dash_n($sStock, 'deadlist') ?></div></a>
    <?php endif; ?>
  </div>
  <?php if ($seeProfit && $sStock['dead_value'] > 0.009): ?>
  <h3>🐌 How long it has been sitting</h3>
  <div class="grid-stats">
    <?php foreach ($sStock['dead_buckets'] as $bk => $bv): ?>
    <a class="stat <?= $bv > 0 ? ($bk === '180+' ? 's-bad' : 's-warn') : '' ?>" href="reports.php?r=dead_stock">
      <div class="stat-label"><?= e($bk) ?></div><div class="stat-value">₹<?= money($bv) ?></div></a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <?php $short = array_merge($sStock['out'], $sStock['low']); if ($short): ?>
  <h3>Needs attention now</h3>
  <div class="table-wrap"><table class="table-sm">
    <thead><tr><th>Product</th><th class="num">Stock</th><th class="num">sold per day</th><th class="num">Days of cover</th><th class="num">Order</th><th>Last sold</th></tr></thead>
    <tbody>
    <?php foreach (array_slice($short, 0, 8) as $it): ?>
    <tr>
      <td><a href="item_view.php?id=<?= (int)$it['id'] ?>"><?= e($it['name']) ?></a></td>
      <td class="num <?= $it['qty'] <= 0.009 ? 'muted' : '' ?>"><?= (float)$it['qty'] ?> <?= e($it['unit']) ?></td>
      <td class="num"><?= $it['per_day'] > 0 ? $it['per_day'] : '-' ?></td>
      <td class="num"><?= $it['days_left'] === null ? '-' : (int)$it['days_left'] . 'd' ?></td>
      <td class="num"><?= $it['reorder_qty'] > 0 ? '<strong>' . (int)$it['reorder_qty'] . '</strong>' : '-' ?></td>
      <td><?= $it['last_sale'] ? dmy($it['last_sale']) : '<span class="muted">never</span>' ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <p class="mt"><a class="btn btn-sm" href="purchases.php?action=new">🛒 Buy now</a>
     <a class="btn btn-sm btn-outline" href="reports.php?r=purchase_reco">See suggestions →</a></p>
  <?php endif; ?>

  <?php // fast / slow / dead / high-value, side by side ?>
  <div class="grid-2">
    <?php
      $stockBlocks = [
        ['🔥 Selling fast', $sStock['fast'], 'sold90', 'nos'],
        ['🐢 Selling slowly', $sStock['slow'], 'value', 'money'],
        ['🐌 Dead stock', $sStock['deadlist'], 'value', 'money'],
      ];
      if ($seeProfit) $stockBlocks[] = ['💎 Most valuable stock', $sStock['high_value'], 'value', 'money'];
      foreach ($stockBlocks as list($bt, $brows, $bkey, $bfmt)):
        if (!$brows) continue;
        if (!$seeProfit && $bfmt === 'money') continue; // cost data stays behind reports.profit
      ?>
    <div>
      <h3><?= $bt ?></h3>
      <table class="table-sm"><tbody>
      <?php foreach (array_slice($brows, 0, 5) as $x): ?>
        <tr>
          <td><a href="item_view.php?id=<?= (int)$x['id'] ?>"><?= e($x['name']) ?></a>
            <?php if ($x['idle_days'] !== null && $bkey === 'value'): ?><br><span class="muted" style="font-size:11px"><?= (int)$x['idle_days'] ?> days sitting</span><?php endif; ?>
          </td>
          <td class="num"><?= $bfmt === 'money' ? '₹' . money($x[$bkey]) : (float)$x[$bkey] . ' ' . e($x['unit']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody></table>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php elseif ($_w === 'smart_sales'): ?>
<div class="card">
  <h2>📈 Sales analysis
    <span class="muted" style="font-weight:400;font-size:13px">·
      <?= $sTrend['direction'] === 'up' ? '↑ going up' : ($sTrend['direction'] === 'down' ? '↓ going down' : '→ steady') ?>
      (last 6 months)</span></h2>
  <div class="grid-2">
    <?php
      $blocks = [
        ['Top product', $sIntel['products'], 'item_view.php?id=', 'reports.php?r=sales'],
        ['Top customer', $sIntel['customers'], 'parties.php?action=ledger&id=', 'reports.php?r=party_sales'],
        ['Top category', $sIntel['categories'], '', 'reports.php?r=sales'],
        ['Top brand', $sIntel['brands'], '', 'reports.php?r=sales'],
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
      <p class="mt"><a class="muted" href="<?= e($bMore) ?>" style="font-size:12px">See all →</a></p>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php elseif ($_w === 'smart_segments'): ?>
<div class="card">
  <h2>👥 Types of customer</h2>
  <div class="grid-stats">
    <?php
      $segLabels = ['vip' => ['⭐ VIP', 's-ok'], 'high_value' => ['💎 Big customers', 's-ok'], 'regular' => ['🔁 Regular', ''],
                    'new' => ['🆕 New', ''], 'at_risk' => ['⚠️ Slipping away', 's-warn'],
                    'inactive' => ['😴 Gone quiet', 's-warn'], 'overdue' => ['⏰ Overdue', 's-bad']];
      foreach ($segLabels as $sk => list($sl, $stone)):
        $sv = $sSeg[$sk] ?? null; if (!$sv || !$sv['count']) continue; ?>
    <a class="stat <?= $stone ?>" href="parties.php?seg=<?= e($sk) ?>">
      <div class="stat-label"><?= $sl ?></div>
      <div class="stat-value"><?= (int)$sv['count'] ?></div>
      <div class="muted" style="font-size:11px">
        ₹<?= money($sv['sales']) ?> Sales<?= $sv['outstanding'] > 0.009 ? ' · ₹' . money($sv['outstanding']) . ' Outstanding' : '' ?>
        <?= $sv['last_purchase'] ? '<br>Last ' . dmy($sv['last_purchase']) : '' ?>
      </div>
    </a>
    <?php endforeach; ?>
  </div>
  <p class="muted" style="font-size:12px;margin:0">These groups are worked out by fixed rules — sales value, number of bills and the date of the last purchase. Nothing is guessed.</p>
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
