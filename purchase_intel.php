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
    flash('ડિલિવરીના દિવસ સચવાયા.');
    redirect('purchase_intel.php?tab=suppliers');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'save_rules') {
    require_perm('settings.edit');
    foreach (['purchase_lead_days' => 7, 'purchase_safety_days' => 7, 'purchase_cover_days' => 30] as $k => $def) {
        if (post($k) !== null) set_setting($k, (string)max(0, (int)post($k)));
    }
    flash('ઓર્ડરના નિયમો સચવાયા.');
    redirect('purchase_intel.php');
}

$tab = get('tab', 'reorder');
$rules = pi_rules();
$sum = pi_summary();

$page_title = 'Purchase Intelligence';
include __DIR__ . '/includes/header.php';
?>
<div class="card">
  <h2>🛒 શું મંગાવવું</h2>
  <div class="grid-stats">
    <a class="stat s-bad" href="purchase_intel.php?tab=reorder"><div class="stat-label">ખલાસ થઈ ગયું</div><div class="stat-value"><?= (int)$sum['out_of_stock'] ?></div></a>
    <a class="stat s-warn" href="purchase_intel.php?tab=reorder"><div class="stat-label">મંગાવવા જેવું</div><div class="stat-value"><?= (int)$sum['reorder_items'] ?></div></a>
    <a class="stat" href="purchase_intel.php?tab=reorder"><div class="stat-label">અંદાજિત ખર્ચ</div><div class="stat-value">₹<?= money($sum['reorder_cost']) ?></div></a>
    <a class="stat <?= $sum['thin_margin'] ? 's-warn' : '' ?>" href="purchase_intel.php?tab=margin"><div class="stat-label">પાતળું માર્જિન</div><div class="stat-value"><?= (int)$sum['thin_margin'] ?></div></a>
  </div>
  <div class="range-bar">
    <?php foreach (['reorder' => '🛒 મંગાવવાનું', 'suppliers' => '🚚 સપ્લાયર', 'price' => '📈 ભાવ વધ્યા',
                    'margin' => '🏷️ માર્જિન', 'lost' => '🚫 ખલાસ થવાથી નુકસાન', 'rules' => '⚙️ નિયમો'] as $k => $lbl): ?>
    <a class="rchip <?= $tab === $k ? 'on' : '' ?>" href="purchase_intel.php?tab=<?= $k ?>"><?= $lbl ?></a>
    <?php endforeach; ?>
  </div>
</div>

<?php if ($tab === 'reorder'): $rows = pi_reorder(150); ?>
<div class="card">
  <h2>🛒 મંગાવવા જેવી વસ્તુઓ <span class="muted" style="font-weight:400;font-size:13px">· સૌથી પહેલાં ખલાસ થાય એ ઉપર</span></h2>
  <p class="muted mb" style="font-size:13px">
    માત્ર "ઓછો સ્ટોક" નહીં — <strong>જે માલ ડિલિવરી આવે એ પહેલાં જ ખલાસ થઈ જશે</strong> એ બતાવે છે.
    જે વસ્તુ વેચાતી જ નથી એ અહીં ક્યારેય નહીં આવે.
  </p>
  <?php if (!$rows): ?>
    <p class="muted">અત્યારે કંઈ મંગાવવાની જરૂર નથી. 🎉</p>
  <?php else: ?>
  <div class="table-wrap"><table class="table-sm">
    <thead><tr>
      <?php if (can('purchases.add')): ?><th style="width:28px"><input type="checkbox" onclick="document.querySelectorAll('.rocb').forEach(c=>c.checked=this.checked)"></th><?php endif; ?>
      <th>વસ્તુ</th><th class="num">સ્ટોક</th><th class="num">રોજ વેચાય</th><th class="num">કેટલા દિવસ ચાલશે</th>
      <th class="num">મંગાવો</th><th class="num">ભાવ</th><th class="num">અંદાજિત ખર્ચ</th><th>કોની પાસેથી</th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $x): ?>
    <tr>
      <?php if (can('purchases.add')): ?>
      <td><input type="checkbox" class="rocb" data-id="<?= (int)$x['item_id'] ?>" data-name="<?= e($x['name']) ?>"
                 data-qty="<?= (int)$x['suggest_qty'] ?>" data-tax="<?= (float)$x['tax_rate'] ?>" data-price="<?= (float)$x['unit_cost'] ?>"></td>
      <?php endif; ?>
      <td><a href="item_view.php?id=<?= (int)$x['item_id'] ?>"><?= e($x['name']) ?></a>
        <?php if ($x['reason'] === 'min_stock'): ?><br><span class="muted" style="font-size:11px">લઘુતમ સ્ટોકથી નીચે</span><?php endif; ?>
      </td>
      <td class="num <?= $x['out_of_stock'] ? '' : 'muted' ?>">
        <?= $x['out_of_stock'] ? '<span class="badge badge-bad">ખલાસ</span>' : (float)$x['stock'] . ' ' . e($x['unit']) ?></td>
      <td class="num"><?= $x['per_day'] > 0 ? $x['per_day'] : '—' ?></td>
      <td class="num"><?= $x['days_left'] === null ? '—' : '<strong>' . (int)$x['days_left'] . '</strong> દિ.' ?>
        <br><span class="muted" style="font-size:11px">ડિલિવરી <?= (int)$x['lead_days'] ?> દિ.</span></td>
      <td class="num"><strong><?= (int)$x['suggest_qty'] ?></strong> <?= e($x['unit']) ?></td>
      <td class="num">₹<?= money($x['unit_cost']) ?>
        <br><span class="muted" style="font-size:11px"><?= $x['cost_source'] === 'last_purchase'
            ? 'છેલ્લે ' . dmy($x['cost_date']) : 'કેટલોગ ભાવ' ?></span></td>
      <td class="num">₹<?= money($x['est_cost']) ?></td>
      <td><?php if ($x['supplier']):
            // the cheapest supplier's price against what we last actually paid:
            // when it is lower, that gap is money left on the table
            $save = round(($x['unit_cost'] - $x['supplier']['price']) * $x['suggest_qty'], 2); ?>
          <a href="parties.php?action=ledger&id=<?= (int)$x['supplier']['party_id'] ?>"><?= e($x['supplier']['name']) ?></a>
          <br><span class="muted" style="font-size:11px">₹<?= money($x['supplier']['price']) ?> · <?= (int)$x['supplier']['times'] ?> વાર</span>
          <?php if ($save > 0.5): ?>
          <br><span class="badge badge-ok" style="font-size:10px">આમની પાસેથી લો તો ~₹<?= money($save) ?> બચે</span>
          <?php endif; ?>
        <?php else: ?><span class="muted">પહેલાં ક્યાંયથી નથી લીધું</span><?php endif; ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <p class="muted" style="font-size:12px">
    ગણતરી: <?= (int)$rules['cover_days'] ?> દિવસ ચાલે એટલો માલ + <?= (int)$rules['safety_days'] ?> દિવસનો બફર,
    જે હાથ પર છે એ બાદ કરીને. આ આંકડા <a href="purchase_intel.php?tab=rules">નિયમો</a> માંથી બદલી શકાય છે.
  </p>
  <?php if (can('purchases.add')): ?>
  <button type="button" class="btn mt" onclick="piBuild()">🛒 પસંદ કરેલાની ખરીદી બનાવો</button>
  <script>
  function piBuild() {
    var items = [];
    document.querySelectorAll('.rocb:checked').forEach(function (cb) {
      items.push({id: cb.dataset.id, name: cb.dataset.name, qty: cb.dataset.qty, tax: cb.dataset.tax, price: cb.dataset.price});
    });
    if (!items.length) { alert('ઓછામાં ઓછી એક વસ્તુ પસંદ કરો.'); return; }
    sessionStorage.setItem('reorderItems', JSON.stringify(items));
    location.href = 'purchases.php?action=new&reorder=1';
  }
  </script>
  <?php endif; ?>
  <?php endif; ?>
</div>

<?php elseif ($tab === 'suppliers'): $sups = pi_suppliers(); ?>
<div class="card">
  <h2>🚚 સપ્લાયર</h2>
  <p class="muted mb" style="font-size:13px">
    <strong>ડિલિવરીના દિવસ તમારે ભરવાના છે.</strong> બિલમાં ફક્ત ખરીદીની તારીખ નોંધાય છે, માલ ક્યારે આવ્યો એ નહીં —
    એટલે સિસ્ટમ એ જાતે ગણી શકતી નથી. તમે ભરશો એટલે "કેટલા દિવસ ચાલશે" ની ગણતરી સાચી થશે.
  </p>
  <?php if (!$sups): ?><p class="muted">છેલ્લા વર્ષમાં કોઈ ખરીદી નથી.</p><?php else: ?>
  <div class="table-wrap"><table class="table-sm">
    <thead><tr><th>સપ્લાયર</th><th class="num">બિલ</th><th class="num">ખરીદી ₹</th><th class="num">વસ્તુઓ</th>
      <th>છેલ્લે</th><th>ભાવનું વલણ</th><th class="num">આપણે દેવું</th><th class="num">ડિલિવરી દિ.</th></tr></thead>
    <tbody>
    <?php foreach ($sups as $s): ?>
    <tr>
      <td><a href="parties.php?action=ledger&id=<?= (int)$s['id'] ?>"><?= e($s['name']) ?></a>
        <?php if ($s['mobile']): ?><br><a class="muted" style="font-size:11px" href="tel:<?= e($s['mobile']) ?>"><?= e($s['mobile']) ?></a><?php endif; ?></td>
      <td class="num"><?= (int)$s['bills'] ?></td>
      <td class="num">₹<?= money($s['spend']) ?><br><span class="muted" style="font-size:11px">સરેરાશ ₹<?= money($s['avg_bill']) ?></span></td>
      <td class="num"><?= (int)$s['items'] ?></td>
      <td><?= $s['last_buy'] ? dmy($s['last_buy']) : '—' ?>
        <?php if ($s['idle_days'] !== null && $s['idle_days'] > 120): ?><br><span class="badge badge-warn"><?= (int)$s['idle_days'] ?> દિવસથી નહીં</span><?php endif; ?></td>
      <td><?php if ($s['prices_up']): ?><span class="badge badge-bad">↑ <?= (int)$s['prices_up'] ?> વસ્તુ મોંઘી</span><?php endif; ?>
          <?php if ($s['prices_down']): ?><span class="badge badge-ok">↓ <?= (int)$s['prices_down'] ?> સસ્તી</span><?php endif; ?>
          <?php if (!$s['prices_up'] && !$s['prices_down']): ?><span class="muted">સ્થિર</span><?php endif; ?></td>
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
  <h2>📈 જે વસ્તુના ખરીદ ભાવ વધ્યા છે</h2>
  <p class="muted mb" style="font-size:13px">એ જ સપ્લાયરે એ જ વસ્તુ માટે પહેલાં કરતાં વધુ ભાવ લીધો હોય એવી યાદી. આગળનો ઓર્ડર આપતાં પહેલાં વાત કરી લેવા જેવી.</p>
  <?php if (!$alerts): ?><p class="muted">કોઈના ભાવ વધ્યા નથી. 👍</p><?php else: ?>
  <div class="table-wrap"><table class="table-sm">
    <thead><tr><th>વસ્તુ</th><th>સપ્લાયર</th><th class="num">પહેલાં</th><th class="num">હવે</th><th class="num">વધારો</th><th>ક્યારે</th></tr></thead>
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
  <h2>🏷️ પાતળા માર્જિનવાળી વસ્તુઓ</h2>
  <p class="muted mb" style="font-size:13px">
    વેચાણ ભાવ સામે <strong>છેલ્લે ખરેખર ચૂકવેલો ખરીદ ભાવ</strong> મૂકીને ગણેલું — કેટલોગનો જૂનો ભાવ નહીં.
    એટલે મહિનાના રિપોર્ટની રાહ જોયા વગર, <em>આગળનું વેચાણ થાય એ પહેલાં</em> ભાવ સુધારી શકાય.
    જે વસ્તુ વેચાય છે એ ઉપર રાખી છે.
  </p>
  <?php if (!$mw): ?><p class="muted">બધી વસ્તુનું માર્જિન ટાર્ગેટ ઉપર છે. 🎉</p><?php else: ?>
  <div class="table-wrap"><table class="table-sm">
    <thead><tr><th>વસ્તુ</th><th class="num">વેચાણ ભાવ</th><th class="num">ખરીદ ભાવ</th><th class="num">માર્જિન</th>
      <th class="num">ટાર્ગેટ માટે ભાવ</th><th class="num">સ્ટોક</th><th>વેચાય છે?</th></tr></thead>
    <tbody>
    <?php foreach ($mw as $m): ?>
    <tr>
      <td><a href="item_view.php?id=<?= (int)$m['item_id'] ?>"><?= e($m['name']) ?></a></td>
      <td class="num">₹<?= money($m['sell']) ?></td>
      <td class="num">₹<?= money($m['cost']) ?><br><span class="muted" style="font-size:11px"><?= $m['cost_source'] === 'last_purchase' ? dmy($m['cost_date']) : 'કેટલોગ' ?></span></td>
      <td class="num"><span class="badge <?= $m['margin_pct'] < 0 ? 'badge-bad' : 'badge-warn' ?>"><?= $m['margin_pct'] ?>%</span></td>
      <td class="num"><strong>₹<?= money($m['suggested_price']) ?></strong></td>
      <td class="num"><?= (float)$m['stock'] ?></td>
      <td><?= $m['moves'] ? '<span class="badge badge-bad">હા</span>' : '<span class="muted">ના</span>' ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <p class="muted" style="font-size:12px">"ટાર્ગેટ માટે ભાવ" = <?= $rules['target_margin'] ?>% માર્જિન મળે એવો વેચાણ ભાવ. ટાર્ગેટ Settings માંથી બદલાય છે.</p>
  <?php endif; ?>
</div>

<?php elseif ($tab === 'lost'): $ls = pi_lost_sales(90); ?>
<div class="card">
  <h2>🚫 માલ ખલાસ થવાથી કેટલું ગુમાવ્યું</h2>
  <div class="flash flash-info">
    <strong>આ આંકડો અંદાજ છે, માપેલો નથી.</strong>
    જે ગ્રાહક વસ્તુ માગીને પાછો ગયો હોય એની ક્યાંય નોંધ થતી નથી. એટલે આ ગણતરી આ રીતે થાય છે:
    દરેક વસ્તુ કેટલા દિવસ ઝીરો સ્ટોકમાં રહી × એ વસ્તુ સામાન્ય રીતે રોજ કેટલી વેચાય છે.
    "કેટલું નુકસાન થયું" એ ચોક્કસ કહેવા માટે નહીં — <em>"માલ ખૂટવો મોંઘો પડે છે કે નહીં"</em> એ સમજવા માટે વાપરો.
  </div>
  <div class="grid-stats">
    <div class="stat s-warn"><div class="stat-label">અંદાજિત ગુમાવેલું માર્જિન (<?= (int)$ls['days'] ?> દિવસ)</div><div class="stat-value">₹<?= money($ls['value']) ?></div></div>
    <div class="stat"><div class="stat-label">અંદાજિત ગુમાવેલા નંગ</div><div class="stat-value"><?= $ls['units'] ?></div></div>
    <div class="stat"><div class="stat-label">કેટલી વસ્તુ ખૂટી</div><div class="stat-value"><?= count($ls['items']) ?></div></div>
  </div>
  <?php if (!$ls['items']): ?><p class="muted">છેલ્લા <?= (int)$ls['days'] ?> દિવસમાં વેચાતી કોઈ વસ્તુ ખૂટી નથી. 🎉</p><?php else: ?>
  <div class="table-wrap"><table class="table-sm">
    <thead><tr><th>વસ્તુ</th><th class="num">કેટલા દિવસ ખલાસ</th><th class="num">રોજ વેચાય</th><th class="num">અંદાજિત ગુમાવેલા નંગ</th><th class="num">અંદાજિત માર્જિન ₹</th></tr></thead>
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
  <h2>⚙️ ઓર્ડરના નિયમો</h2>
  <p class="muted mb" style="font-size:13px">આ ત્રણ આંકડા "શું મંગાવવું અને કેટલું" ની આખી ગણતરી ચલાવે છે.</p>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="do" value="save_rules">
    <div class="form-row cols-2">
      <div><label>ડિલિવરીના સામાન્ય દિવસ (સપ્લાયર ન ભર્યો હોય ત્યારે)</label>
        <input type="number" name="purchase_lead_days" min="0" max="365" value="<?= (int)$rules['lead_days'] ?>"></div>
      <div><label>સલામતી માટે વધારાના દિવસ</label>
        <input type="number" name="purchase_safety_days" min="0" max="180" value="<?= (int)$rules['safety_days'] ?>"></div>
    </div>
    <div class="form-row cols-2">
      <div><label>એક ઓર્ડર કેટલા દિવસ ચાલવો જોઈએ</label>
        <input type="number" name="purchase_cover_days" min="7" max="365" value="<?= (int)$rules['cover_days'] ?>"></div>
    </div>
    <?php if (can('settings.edit')): ?><button class="btn" type="submit">સાચવો</button>
    <?php else: ?><p class="muted">આ બદલવા માટે settings.edit પરવાનગી જોઈએ.</p><?php endif; ?>
  </form>
  <p class="muted mt" style="font-size:12px">
    ઉદાહરણ: ડિલિવરી <?= (int)$rules['lead_days'] ?> દિ. + સલામતી <?= (int)$rules['safety_days'] ?> દિ. =
    <strong><?= (int)($rules['lead_days'] + $rules['safety_days']) ?> દિવસ</strong> માં ખલાસ થાય એવી વસ્તુ યાદીમાં આવશે,
    અને <?= (int)($rules['cover_days'] + $rules['lead_days'] + $rules['safety_days']) ?> દિવસ ચાલે એટલો માલ મંગાવવાનું સૂચવાશે.
  </p>
</div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
