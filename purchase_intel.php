<?php
// Purchase Intelligence - "what should I buy today, from whom, at what price".
//
// Cost figures are the whole point of this screen, so it sits behind the same
// permission that guards purchase prices everywhere else. Selected rows hand
// off to the existing New Purchase form through the same sessionStorage
// mechanism the Purchase Recommendations report already uses - one prefill
// path, not two.
require_once __DIR__ . '/includes/init.php';
require_perm('purchases.view');
if (!can('items.cost') && !can('reports.profit')) {
    http_response_code(403);
    die('This screen shows purchase prices. You need the items.cost or reports.profit permission.');
}
$u = current_user();

// ---------- actions ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'save_lead') {
    require_perm('parties.edit');
    $pid = (int)post('party_id');
    q('UPDATE parties SET lead_days = ? WHERE id = ?', [max(0, (int)post('lead_days')), $pid]);
    log_activity('supplier_lead_days', "party=$pid days=" . (int)post('lead_days'));
    flash('Delivery days saved.');
    redirect('purchase_intel.php?tab=suppliers');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'save_rules') {
    require_perm('settings.edit');
    foreach (['purchase_lead_days' => 7, 'purchase_safety_days' => 7, 'purchase_cover_days' => 30] as $k => $def) {
        if (post($k) !== null) set_setting($k, (string)max(0, (int)post($k)));
    }
    flash('Ordering rules saved.');
    redirect('purchase_intel.php');
}

$tab = get('tab', 'reorder');
$rules = pi_rules();
$sum = pi_summary();

$page_title = 'Purchase Intelligence';
include __DIR__ . '/includes/header.php';
?>
<div class="card">
  <h2>🛒 What to buy</h2>
  <div class="grid-stats">
    <a class="stat s-bad" href="purchase_intel.php?tab=reorder"><div class="stat-label">ran out</div><div class="stat-value"><?= (int)$sum['out_of_stock'] ?></div></a>
    <a class="stat s-warn" href="purchase_intel.php?tab=reorder"><div class="stat-label">worth ordering</div><div class="stat-value"><?= (int)$sum['reorder_items'] ?></div></a>
    <a class="stat" href="purchase_intel.php?tab=reorder"><div class="stat-label">Estimated cost</div><div class="stat-value">₹<?= money($sum['reorder_cost']) ?></div></a>
    <a class="stat <?= $sum['thin_margin'] ? 's-warn' : '' ?>" href="purchase_intel.php?tab=margin"><div class="stat-label">Thin margin</div><div class="stat-value"><?= (int)$sum['thin_margin'] ?></div></a>
  </div>
  <div class="range-bar">
    <?php foreach (['reorder' => '🛒 To order', 'suppliers' => '🚚 Supplier', 'price' => '📈 Price increases',
                    'margin' => '🏷️ Margin', 'lost' => '🚫 The cost of running out', 'rules' => '⚙️ Rules'] as $k => $lbl): ?>
    <a class="rchip <?= $tab === $k ? 'on' : '' ?>" href="purchase_intel.php?tab=<?= $k ?>"><?= $lbl ?></a>
    <?php endforeach; ?>
  </div>
</div>

<?php if ($tab === 'reorder'): $rows = pi_reorder(150); ?>
<div class="card">
  <h2>🛒 Items worth ordering <span class="muted" style="font-weight:400;font-size:13px">· whatever runs out first is at the top</span></h2>
  <p class="muted mb" style="font-size:13px">
    only "Low stock" no — <strong>Goods that will run out before the delivery arrives</strong> shows that.
    An item that never sells never appears here.
  </p>
  <?php if (!$rows): ?>
    <p class="muted">Nothing needs ordering right now. 🎉</p>
  <?php else: ?>
  <div class="table-wrap"><table class="table-sm">
    <thead><tr>
      <?php if (can('purchases.add')): ?><th style="width:28px"><input type="checkbox" onclick="document.querySelectorAll('.rocb').forEach(c=>c.checked=this.checked)"></th><?php endif; ?>
      <th>Item</th><th class="num">Stock</th><th class="num">sold per day</th><th class="num">How many days it lasts</th>
      <th class="num">Order</th><th class="num">Price</th><th class="num">Estimated cost</th><th>From whom</th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $x): ?>
    <tr>
      <?php if (can('purchases.add')): ?>
      <td><input type="checkbox" class="rocb" data-id="<?= (int)$x['item_id'] ?>" data-name="<?= e($x['name']) ?>"
                 data-qty="<?= (int)$x['suggest_qty'] ?>" data-tax="<?= (float)$x['tax_rate'] ?>" data-price="<?= (float)$x['unit_cost'] ?>"></td>
      <?php endif; ?>
      <td><a href="item_view.php?id=<?= (int)$x['item_id'] ?>"><?= e($x['name']) ?></a>
        <?php if ($x['reason'] === 'min_stock'): ?><br><span class="muted" style="font-size:11px">below the minimum stock</span><?php endif; ?>
      </td>
      <td class="num <?= $x['out_of_stock'] ? '' : 'muted' ?>">
        <?= $x['out_of_stock'] ? '<span class="badge badge-bad">Out of stock</span>' : (float)$x['stock'] . ' ' . e($x['unit']) ?></td>
      <td class="num"><?= $x['per_day'] > 0 ? $x['per_day'] : '—' ?></td>
      <td class="num"><?= $x['days_left'] === null ? '—' : '<strong>' . (int)$x['days_left'] . '</strong> d' ?>
        <br><span class="muted" style="font-size:11px">Delivery <?= (int)$x['lead_days'] ?> d</span></td>
      <td class="num"><strong><?= (int)$x['suggest_qty'] ?></strong> <?= e($x['unit']) ?></td>
      <td class="num">₹<?= money($x['unit_cost']) ?>
        <br><span class="muted" style="font-size:11px"><?= $x['cost_source'] === 'last_purchase'
            ? 'Last ' . dmy($x['cost_date']) : 'Catalogue price' ?></span></td>
      <td class="num">₹<?= money($x['est_cost']) ?></td>
      <td><?php if ($x['supplier']):
            // the cheapest supplier's price against what we last actually paid:
            // when it is lower, that gap is money left on the table
            $save = round(($x['unit_cost'] - $x['supplier']['price']) * $x['suggest_qty'], 2); ?>
          <a href="parties.php?action=ledger&id=<?= (int)$x['supplier']['party_id'] ?>"><?= e($x['supplier']['name']) ?></a>
          <br><span class="muted" style="font-size:11px">₹<?= money($x['supplier']['price']) ?> · <?= (int)$x['supplier']['times'] ?> times</span>
          <?php if ($save > 0.5): ?>
          <br><span class="badge badge-ok" style="font-size:10px">Buying from them saves about Rs <?= money($save) ?> saved</span>
          <?php endif; ?>
        <?php else: ?><span class="muted">never bought from anywhere before</span><?php endif; ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <p class="muted" style="font-size:12px">
    Calculation: <?= (int)$rules['cover_days'] ?> days worth of goods + <?= (int)$rules['safety_days'] ?> days of buffer,
    less what is on hand. These figures <a href="purchase_intel.php?tab=rules">Rules</a> can be changed from there.
  </p>
  <?php if (can('purchases.add')): ?>
  <button type="button" class="btn mt" onclick="piBuild()">🛒 Make a purchase for the selected</button>
  <script>
  function piBuild() {
    var items = [];
    document.querySelectorAll('.rocb:checked').forEach(function (cb) {
      items.push({id: cb.dataset.id, name: cb.dataset.name, qty: cb.dataset.qty, tax: cb.dataset.tax, price: cb.dataset.price});
    });
    if (!items.length) { alert('Choose at least one item.'); return; }
    sessionStorage.setItem('reorderItems', JSON.stringify(items));
    location.href = 'purchases.php?action=new&reorder=1';
  }
  </script>
  <?php endif; ?>
  <?php endif; ?>
</div>

<?php elseif ($tab === 'suppliers'): $sups = pi_suppliers(); ?>
<div class="card">
  <h2>🚚 Supplier</h2>
  <p class="muted mb" style="font-size:13px">
    <strong>The delivery days are for you to fill in.</strong> A bill records only the purchase date, not when the goods arrived —
    so the system cannot work it out by itself. Once you fill it in, "How many days it lasts" will then be worked out correctly.
  </p>
  <?php if (!$sups): ?><p class="muted">No purchases in the last year.</p><?php else: ?>
  <div class="table-wrap"><table class="table-sm">
    <thead><tr><th>Supplier</th><th class="num">Bill</th><th class="num">Purchases Rs </th><th class="num">Items</th>
      <th>Last</th><th>Price trend</th><th class="num">we owe</th><th class="num">Delivery d</th></tr></thead>
    <tbody>
    <?php foreach ($sups as $s): ?>
    <tr>
      <td><a href="parties.php?action=ledger&id=<?= (int)$s['id'] ?>"><?= e($s['name']) ?></a>
        <?php if ($s['mobile']): ?><br><a class="muted" style="font-size:11px" href="tel:<?= e($s['mobile']) ?>"><?= e($s['mobile']) ?></a><?php endif; ?></td>
      <td class="num"><?= (int)$s['bills'] ?></td>
      <td class="num">₹<?= money($s['spend']) ?><br><span class="muted" style="font-size:11px">Average Rs <?= money($s['avg_bill']) ?></span></td>
      <td class="num"><?= (int)$s['items'] ?></td>
      <td><?= $s['last_buy'] ? dmy($s['last_buy']) : '—' ?>
        <?php if ($s['idle_days'] !== null && $s['idle_days'] > 120): ?><br><span class="badge badge-warn"><?= (int)$s['idle_days'] ?> not for days</span><?php endif; ?></td>
      <td><?php if ($s['prices_up']): ?><span class="badge badge-bad">↑ <?= (int)$s['prices_up'] ?> items dearer</span><?php endif; ?>
          <?php if ($s['prices_down']): ?><span class="badge badge-ok">↓ <?= (int)$s['prices_down'] ?> cheaper</span><?php endif; ?>
          <?php if (!$s['prices_up'] && !$s['prices_down']): ?><span class="muted">steady</span><?php endif; ?></td>
      <td class="num"><?= $s['we_owe'] > 0.009 ? '₹' . money($s['we_owe']) : '—' ?></td>
      <td class="num">
        <?php if (can('parties.edit')): ?>
        <form method="post" style="display:flex;gap:4px;align-items:center">
          <?= csrf_field() ?><input type="hidden" name="do" value="save_lead"><input type="hidden" name="party_id" value="<?= (int)$s['id'] ?>">
          <input type="number" name="lead_days" min="0" max="365" value="<?= (int)$s['lead_days'] ?>" style="width:64px;padding:4px 6px">
          <button class="btn btn-sm btn-outline" type="submit">✓</button>
        </form>
        <?php else: ?><?= (int)$s['lead_days'] ?: '—' ?><?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<?php elseif ($tab === 'price'): $alerts = pi_price_alerts(); ?>
<div class="card">
  <h2>📈 Items whose cost price has risen</h2>
  <p class="muted mb" style="font-size:13px">A list where the same supplier charged more than before for the same item. Worth a word before the next order.</p>
  <?php if (!$alerts): ?><p class="muted">Nobody has raised a price. 👍</p><?php else: ?>
  <div class="table-wrap"><table class="table-sm">
    <thead><tr><th>Item</th><th>Supplier</th><th class="num">before</th><th class="num">now</th><th class="num">increase</th><th>When</th></tr></thead>
    <tbody>
    <?php foreach ($alerts as $a): ?>
    <tr><td><a href="item_view.php?id=<?= (int)$a['item_id'] ?>"><?= e($a['name']) ?></a></td>
        <td><a href="parties.php?action=ledger&id=<?= (int)$a['party_id'] ?>"><?= e($a['supplier']) ?></a></td>
        <td class="num">₹<?= money($a['old_price']) ?></td>
        <td class="num">₹<?= money($a['new_price']) ?></td>
        <td class="num"><span class="badge badge-bad">+<?= $a['pct'] ?>%</span></td>
        <td><?= dmy($a['date']) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<?php elseif ($tab === 'margin'): $mw = pi_margin_watch(100); ?>
<div class="card">
  <h2>🏷️ Items on a thin margin</h2>
  <p class="muted mb" style="font-size:13px">
    against the selling price <strong>The cost price actually paid last</strong> is used — not the old catalogue price.
    so without waiting for the monthly report, <em>before the next sale happens</em> the price can be corrected.
    What sells is kept at the top.
  </p>
  <?php if (!$mw): ?><p class="muted">Every item margin is above target. 🎉</p><?php else: ?>
  <div class="table-wrap"><table class="table-sm">
    <thead><tr><th>Item</th><th class="num">Selling price</th><th class="num">Cost price</th><th class="num">Margin</th>
      <th class="num">Price for the target</th><th class="num">Stock</th><th>does it sell?</th></tr></thead>
    <tbody>
    <?php foreach ($mw as $m): ?>
    <tr>
      <td><a href="item_view.php?id=<?= (int)$m['item_id'] ?>"><?= e($m['name']) ?></a></td>
      <td class="num">₹<?= money($m['sell']) ?></td>
      <td class="num">₹<?= money($m['cost']) ?><br><span class="muted" style="font-size:11px"><?= $m['cost_source'] === 'last_purchase' ? dmy($m['cost_date']) : 'Catalogue' ?></span></td>
      <td class="num"><span class="badge <?= $m['margin_pct'] < 0 ? 'badge-bad' : 'badge-warn' ?>"><?= $m['margin_pct'] ?>%</span></td>
      <td class="num"><strong>₹<?= money($m['suggested_price']) ?></strong></td>
      <td class="num"><?= (float)$m['stock'] ?></td>
      <td><?= $m['moves'] ? '<span class="badge badge-bad">Yes</span>' : '<span class="muted">No</span>' ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <p class="muted" style="font-size:12px">"Price for the target" = <?= $rules['target_margin'] ?>% margin. The target is changed in Settings.</p>
  <?php endif; ?>
</div>

<?php elseif ($tab === 'lost'): $ls = pi_lost_sales(90); ?>
<div class="card">
  <h2>🚫 What running out of stock cost</h2>
  <div class="flash flash-info">
    <strong>This figure is an estimate, not a measurement.</strong>
    A customer who asked for something and left empty-handed is recorded nowhere. So this is worked out like this:
    How many days each item sat at zero stock × how many it normally sells a day.
    "how much was lost" not to say it exactly — <em>"whether running out is costly or not"</em> Use it to understand that.
  </div>
  <div class="grid-stats">
    <div class="stat s-warn"><div class="stat-label">Estimated margin lost (<?= (int)$ls['days'] ?> days)</div><div class="stat-value">₹<?= money($ls['value']) ?></div></div>
    <div class="stat"><div class="stat-label">Estimated units lost</div><div class="stat-value"><?= $ls['units'] ?></div></div>
    <div class="stat"><div class="stat-label">how many items fell short</div><div class="stat-value"><?= count($ls['items']) ?></div></div>
  </div>
  <?php if (!$ls['items']): ?><p class="muted">Last <?= (int)$ls['days'] ?> Nothing selling in these days has run short. 🎉</p><?php else: ?>
  <div class="table-wrap"><table class="table-sm">
    <thead><tr><th>Item</th><th class="num">Days out of stock</th><th class="num">sold per day</th><th class="num">Estimated units lost</th><th class="num">Estimated margin Rs </th></tr></thead>
    <tbody>
    <?php foreach ($ls['items'] as $l): ?>
    <tr><td><a href="item_view.php?id=<?= (int)$l['item_id'] ?>"><?= e($l['name']) ?></a></td>
        <td class="num"><?= (int)$l['zero_days'] ?></td>
        <td class="num"><?= $l['per_day'] ?></td>
        <td class="num"><?= $l['units'] ?> <?= e($l['unit']) ?></td>
        <td class="num">₹<?= money($l['value']) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<?php elseif ($tab === 'rules'): ?>
<div class="card">
  <h2>⚙️ Ordering rules</h2>
  <p class="muted mb" style="font-size:13px">These three figures "What to order and how much" runs the whole calculation.</p>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="do" value="save_rules">
    <div class="form-row cols-2">
      <div><label>Usual delivery days (when the supplier has not been filled in)</label>
        <input type="number" name="purchase_lead_days" min="0" max="365" value="<?= (int)$rules['lead_days'] ?>"></div>
      <div><label>Extra days for safety</label>
        <input type="number" name="purchase_safety_days" min="0" max="180" value="<?= (int)$rules['safety_days'] ?>"></div>
    </div>
    <div class="form-row cols-2">
      <div><label>How many days one order should last</label>
        <input type="number" name="purchase_cover_days" min="7" max="365" value="<?= (int)$rules['cover_days'] ?>"></div>
    </div>
    <?php if (can('settings.edit')): ?><button class="btn" type="submit">Save</button>
    <?php else: ?><p class="muted">Changing this needs the settings.edit permission.</p><?php endif; ?>
  </form>
  <p class="muted mt" style="font-size:12px">
    Example: delivery <?= (int)$rules['lead_days'] ?> d + safety <?= (int)$rules['safety_days'] ?> d =
    <strong><?= (int)($rules['lead_days'] + $rules['safety_days']) ?> days</strong> items running out within that come onto the list,
    and <?= (int)($rules['cover_days'] + $rules['lead_days'] + $rules['safety_days']) ?> days worth of goods is what will be suggested.
  </p>
</div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
