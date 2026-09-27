<?php
// Sales Assistant - "Customer શું માગે is?" typed in plain words, answered from
// the shop's own catalogue.
//
// The division of labour is the point, and the screen says it out loud:
// AI only turns the sentence into search words; stock, rate, margin, this
// customer's last price and their credit position all come from the database.
// With AI off the box still works on the typed words, and the screen tells the
// reader which of the two happened.
//
// This screen writes nothing. It ends at a button that opens the ordinary New
// Bill form with the ticked items filled in.
require_once __DIR__ . '/includes/init.php';
require_perm('items.view');
$u = current_user();

$q = trim((string)get('q'));
$partyId = (int)get('party');
$loc = locked_location_id() ?: (int)get('loc');
$budget = get('nb') === '1' ? 0.0 : sa_budget($q);   // nb = "no budget", the chip's remove link
$locations = all('SELECT id, name FROM locations WHERE is_active = 1' . (locked_location_id() ? ' AND id = ' . locked_location_id() : '') . ' ORDER BY name');
$party = $partyId ? row('SELECT id, name, mobile FROM parties WHERE id = ?', [$partyId]) : null;

$rows = []; $kw = null; $cross = []; $mov = []; $lastP = []; $credit = null;
if ($q !== '') {
    // 1. the shop's own words first - and if they are enough, the AI is never
    //    called at all. Most searches end here, and cost nothing.
    $typed = sa_words($q);
    $rows = sa_find($typed, $loc);
    // 2. only a thin result buys an AI call, and its words are then run
    //    through the SAME deterministic search
    $kw = sa_keywords($q, count($rows));
    if ($kw['src'] === 'ai') {
        $aiRows = sa_find($kw['words'], $loc);
        if ($aiRows) $rows = $aiRows;               // catalogue confirmed them
        else $kw['why'] = 'The AI words found nothing either — the result for the typed words is shown.';
    }
    // 3. a budget only SORTS; nothing is hidden for being expensive
    if ($budget > 0 && $rows) {
        usort($rows, function ($a, $b) use ($budget) {
            $fa = (float)$a['selling_price'] <= $budget ? 0 : 1;
            $fb = (float)$b['selling_price'] <= $budget ? 0 : 1;
            if ($fa !== $fb) return $fa - $fb;
            return (float)$b['selling_price'] <=> (float)$a['selling_price'];
        });
    }
    $ids = array_column($rows, 'id');
    $mov = sa_movement($ids);
    $cross = sa_cross_sell($ids, $loc);
    if ($partyId && can('sales.view')) $lastP = sa_last_prices($partyId, $ids);
    if ($partyId) $credit = sa_credit_warning($partyId);
}
$showCost = can('items.cost');

$page_title = 'Sales Assistant';
include __DIR__ . '/includes/header.php';
?>
<div class="card">
  <h2>🧑‍💼 What does the customer want?</h2>
  <form method="get" action="assistant.php" class="sa-form">
    <input type="text" name="q" value="<?= e($q) ?>" autofocus autocomplete="off"
           placeholder="e.g. a wifi router for home under 2000&hellip;" style="flex:1;min-width:220px">
    <?php if (count($locations) > 1): ?>
    <select name="loc"><option value="0">— all locations —</option>
      <?php foreach ($locations as $l): ?><option value="<?= (int)$l['id'] ?>" <?= $loc == $l['id'] ? 'selected' : '' ?>><?= e($l['name']) ?></option><?php endforeach; ?>
    </select>
    <?php endif; ?>
    <?php if ($partyId): ?><input type="hidden" name="party" value="<?= $partyId ?>"><?php endif; ?>
    <button class="btn btn-primary" type="submit">Search</button>
  </form>
  <p class="muted" style="margin:8px 0 0;font-size:13px">
    In Gujarati or English, as the customer says it. The AI only <b>to understand the words</b> is used for —
    Stock, price, margin and credit are all worked out from the shop own data.
  </p>
</div>

<?php if ($q === ''): ?>
  <div class="card"><p class="muted">Write what the customer needs above.</p></div>
<?php else: ?>

  <?php if ($kw): ?>
  <div class="card" style="padding:12px 16px">
    <div class="sa-chips">
      <?php foreach ($kw['words'] as $w): ?><span class="sa-chip"><?= e($w) ?></span><?php endforeach; ?>
      <?php if ($budget > 0): ?>
        <span class="sa-chip sa-chip-b">Budget Rs <?= money($budget) ?>
          <a href="assistant.php?<?= http_build_query(['q' => $q, 'loc' => $loc, 'party' => $partyId, 'nb' => 1]) ?>" title="Remove the budget">✕</a></span>
      <?php endif; ?>
      <span class="muted" style="font-size:12px"><?= $kw['src'] === 'ai' ? '🤖 The AI suggested these words' : '🔎 The words you typed' ?></span>
    </div>
    <?php if ($kw['note']): ?><div class="muted" style="font-size:13px;margin-top:6px">🤖 <?= e($kw['note']) ?></div><?php endif; ?>
    <?php if ($kw['why']): ?><div class="muted" style="font-size:12px;margin-top:6px"><?= e($kw['why']) ?></div><?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if ($credit): ?>
  <div class="flash flash-<?= $credit['level'] === 'high' ? 'error' : 'info' ?>">
    ⚠️ <b><?= e($party['name'] ?? 'This customer') ?></b> —
    <?= e(implode(' · ', $credit['lines'])) ?>.
    <a href="customer.php?id=<?= $partyId ?>">See detail</a>
  </div>
  <?php endif; ?>

  <?php if (!$rows): ?>
    <div class="card"><p class="muted">Nothing in the catalogue matched these words. Try others, or
      <a href="items.php?q=<?= urlencode($q) ?>">search the items by hand</a>.</p></div>
  <?php else: ?>
  <div class="card">
    <h2>🎯 Matching items <span class="muted" style="font-weight:400;font-size:13px">· <?= count($rows) ?></span></h2>
    <div class="table-wrap"><table>
      <thead><tr>
        <th style="width:34px"></th><th>Item</th><th class="num">Stock</th><th class="num">Price</th>
        <?php if ($showCost): ?><th class="num">Margin</th><?php endif; ?>
        <th>in 90 days</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $it):
            $isSvc = $it['item_type'] === 'service';
            $st = $isSvc ? null : (float)$it['stock'];
            $m = $showCost ? sa_margin($it) : null;
            $mv = $mov[(int)$it['id']] ?? null;
            $lp = $lastP[(int)$it['id']] ?? null; ?>
        <tr>
          <td><input type="checkbox" class="sa-tick" value="<?= (int)$it['id'] ?>" <?= ($st !== null && $st <= 0) ? '' : 'checked' ?>></td>
          <td>
            <a href="item_view.php?id=<?= (int)$it['id'] ?>"><?= e($it['name']) ?></a>
            <div class="muted" style="font-size:12px">
              <?= e(trim($it['brand'] . ' ' . $it['model'])) ?><?= $it['category'] ? ' · ' . e($it['category']) : '' ?>
            </div>
            <?php if ($lp): ?><div class="muted" style="font-size:12px">🧾 Last to this customer Rs <?= money($lp['price']) ?> (<?= dmy($lp['sale_date']) ?>)</div><?php endif; ?>
          </td>
          <td class="num">
            <?php if ($isSvc): ?><span class="muted">Service</span>
            <?php elseif ($st <= 0): ?><span class="badge b-bad">Out of stock</span>
            <?php elseif ($st <= (float)$it['min_stock']): ?><span class="badge b-warn"><?= rtrim(rtrim(number_format($st, 2), '0'), '.') ?></span>
            <?php else: ?><?= rtrim(rtrim(number_format($st, 2), '0'), '.') ?><?php endif; ?>
          </td>
          <td class="num">₹<?= money($it['selling_price']) ?>
            <?php if ($budget > 0 && (float)$it['selling_price'] > $budget): ?><div class="muted" style="font-size:11px">above budget</div><?php endif; ?>
          </td>
          <?php if ($showCost): ?>
          <td class="num"><?= $m ? '₹' . money($m['amount']) . '<div class="muted" style="font-size:11px">' . $m['pct'] . '%</div>' : '<span class="muted">—</span>' ?></td>
          <?php endif; ?>
          <td class="muted" style="font-size:12px">
            <?php if ($mv): ?><?= rtrim(rtrim(number_format($mv['qty'], 2), '0'), '.') ?> units sold · last <?= dmy($mv['last_sold']) ?>
            <?php else: ?>not sold<?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php if (can('sales.add')): ?>
    <div class="page-actions" style="margin-top:12px">
      <button type="button" class="btn btn-primary" id="saBill">✅ Make a bill for the selected items</button>
      <span class="muted" style="font-size:12px">The retail price is filled in on the billing page — it can be changed there.</span>
    </div>
    <?php endif; ?>
  </div>

  <?php if ($cross): ?>
  <div class="card">
    <h2>🔗 This sells with this</h2>
    <p class="muted" style="font-size:13px;margin-top:0">Last <?= sa_rules()['months'] ?> months, items that went out on the same bill — a count, not a suggestion.</p>
    <div class="table-wrap"><table>
      <thead><tr><th>Item</th><th class="num">Stock</th><th class="num">Price</th><th class="num">on how many bills together</th></tr></thead>
      <tbody>
      <?php foreach ($cross as $c): ?>
        <tr>
          <td><a href="item_view.php?id=<?= (int)$c['id'] ?>"><?= e($c['name']) ?></a></td>
          <td class="num"><?= $c['stock'] === null ? '<span class="muted">Service</span>' : ((float)$c['stock'] <= 0 ? '<span class="badge b-bad">Out of stock</span>' : rtrim(rtrim(number_format((float)$c['stock'], 2), '0'), '.')) ?></td>
          <td class="num">₹<?= money($c['selling_price']) ?></td>
          <td class="num"><?= (int)$c['bills'] ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
  <?php endif; ?>

  <?php endif; ?>
<?php endif; ?>

<style>
.sa-form { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
.sa-chips { display:flex; gap:6px; flex-wrap:wrap; align-items:center; }
.sa-chip { background:var(--chip-bg,#eef2ff); color:var(--chip-fg,#3730a3); border-radius:99px; padding:3px 10px; font-size:13px; }
.sa-chip-b { background:#fff7ed; color:#9a3412; }
.sa-chip-b a { color:#9a3412; text-decoration:none; margin-left:4px; font-weight:700; }
</style>
<script>
document.getElementById('saBill')?.addEventListener('click', function () {
  var ids = Array.from(document.querySelectorAll('.sa-tick:checked')).map(function (c) { return c.value; });
  if (!ids.length) { alert('No item has been selected.'); return; }
  var qs = 'action=new&pick=' + ids.join(',') + <?= json_encode($partyId ? '&party=' . $partyId : '') ?>;
  location.href = 'sales.php?' + qs;
});
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
