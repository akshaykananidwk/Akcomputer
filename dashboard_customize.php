<?php
// Per-user dashboard layout: reorder / show-hide the cards on index.php.
// Purely a preference (includes/helpers.php's user_pref()/set_user_pref(),
// backed by the user_preferences table) - never affects what data another
// staff member's dashboard shows, only the arrangement of their own.
require_once __DIR__ . '/includes/init.php';
require_perm('dashboard.view');
$u = current_user();

$widgetLabels = [
    'duo' => 'To Receive / To Pay', 'sale_overview' => 'Sale Overview', 'profit_trend' => 'Profit Trend',
    'inventory' => 'Inventory Summary', 'open_tx' => 'Open Transactions', 'tasks' => 'My Pending Tasks', 'stock' => 'Stock In My Hand',
];
$widgetPerms = [
    'duo' => can('payments.view'), 'sale_overview' => can('sales.view'), 'profit_trend' => can('sales.view'),
    'inventory' => can('stock.view'), 'open_tx' => (can('repairs.view') || can('estimates.view') || can('warranty.view')),
    'tasks' => true, 'stock' => true,
];
// Matches index.php's two independent layout groups (full-width cards vs.
// the 2-column .grid-2 row) - a widget can only be reordered against
// others in its own group, since moving it past a different-group widget
// wouldn't actually change anything on the dashboard (the two groups
// render in separate DOM blocks).
$topKeys = ['duo', 'sale_overview', 'profit_trend'];
$gridKeys = ['inventory', 'open_tx', 'tasks', 'stock'];
function dc_group($w, $topKeys) { return in_array($w, $topKeys, true) ? 'top' : 'grid'; }
$defaultOrder = array_keys($widgetLabels);

// Only the widgets this user's role can actually see are ever shown/moved
// here - a widget a role gains access to later (after a permissions
// change) just lands at the end of its group automatically, same as any
// newly-added widget.
function dashboard_pref_order($u, $defaultOrder, $widgetPerms) {
    $permitted = array_values(array_filter($defaultOrder, fn($w) => $widgetPerms[$w] ?? false));
    $prefRaw = json_decode((string)user_pref($u['id'], 'dashboard_widgets', ''), true) ?: [];
    $storedOrder = is_array($prefRaw['order'] ?? null) ? $prefRaw['order'] : [];
    $hidden = is_array($prefRaw['hidden'] ?? null) ? $prefRaw['hidden'] : [];
    $order = array_values(array_intersect($storedOrder, $permitted));
    foreach ($permitted as $w) if (!in_array($w, $order, true)) $order[] = $w;
    return [$order, $hidden];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    [$order, ] = dashboard_pref_order($u, $defaultOrder, $widgetPerms);
    $move = post('move');
    if ($move && strpos($move, '|') !== false) {
        [$key, $dir] = explode('|', $move, 2);
        $idx = array_search($key, $order, true);
        if ($idx !== false) {
            $myGroup = dc_group($key, $topKeys);
            $step = $dir === 'up' ? -1 : 1;
            $j = $idx + $step;
            while ($j >= 0 && $j < count($order) && dc_group($order[$j], $topKeys) !== $myGroup) $j += $step;
            if ($j >= 0 && $j < count($order)) {
                [$order[$idx], $order[$j]] = [$order[$j], $order[$idx]];
            }
        }
    }
    $visiblePosted = (array)post('visible_widgets', []);
    $hidden = array_values(array_diff($order, $visiblePosted));
    set_user_pref($u['id'], 'dashboard_widgets', json_encode(['order' => $order, 'hidden' => $hidden]));
    flash('Dashboard layout saved.');
    redirect('dashboard_customize.php');
}

[$order, $hidden] = dashboard_pref_order($u, $defaultOrder, $widgetPerms);
$topOrder = array_values(array_intersect($order, $topKeys));
$gridOrder = array_values(array_intersect($order, $gridKeys));

function dc_widget_rows($groupOrder, $widgetLabels, $hidden) {
    foreach ($groupOrder as $i => $w): ?>
    <div class="list-row" style="cursor:default">
      <div class="list-row-main">
        <label class="check-inline"><input type="checkbox" name="visible_widgets[]" value="<?= e($w) ?>" <?= in_array($w, $hidden, true) ? '' : 'checked' ?>> <?= e($widgetLabels[$w]) ?></label>
      </div>
      <div class="list-row-val" style="display:flex;gap:4px">
        <button class="btn btn-sm btn-outline" type="submit" name="move" value="<?= e($w) ?>|up" <?= $i === 0 ? 'disabled' : '' ?>>↑</button>
        <button class="btn btn-sm btn-outline" type="submit" name="move" value="<?= e($w) ?>|down" <?= $i === count($groupOrder) - 1 ? 'disabled' : '' ?>>↓</button>
      </div>
    </div>
<?php endforeach;
}

$page_title = 'Customize Dashboard';
include __DIR__ . '/includes/header.php';
?>
<div class="card">
  <h2>⚙️ Customize Dashboard</h2>
  <p class="muted mb">Reorder or hide the cards shown on your dashboard - only you are affected, other staff keep their own layout. Cards with no data to show today (e.g. no pending tasks) stay hidden regardless of this order.</p>
  <form method="post">
    <?= csrf_field() ?>
    <h3 class="mt mb" style="font-size:13px;color:var(--muted);text-transform:uppercase">Top Cards</h3>
    <?php dc_widget_rows($topOrder, $widgetLabels, $hidden); ?>
    <h3 class="mt mb" style="font-size:13px;color:var(--muted);text-transform:uppercase">Summary Cards</h3>
    <?php dc_widget_rows($gridOrder, $widgetLabels, $hidden); ?>
    <button class="btn mt" type="submit">Save</button>
    <a class="btn btn-muted mt" href="index.php">Back to Dashboard</a>
  </form>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
