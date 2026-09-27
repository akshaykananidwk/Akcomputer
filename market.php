<?php
// Market Intelligence - what the shop's own books say about its market.
//
// The first thing this screen does is say what it cannot know. There is no
// competitor price here, no industry figure, no market share: the shop has no
// source for any of it, and a made-up number that looks official is the one
// kind of mistake this whole system is built to avoid.
//
// Everything shown is money that already passed through the books, and every
// row links to the documents it came from.
require_once __DIR__ . '/includes/init.php';
require_perm('reports.view');
if (!can('reports.profit') && !can('items.cost')) {
    http_response_code(403);
    die('This screen compares what you pay with what you get. You need the reports.profit or items.cost permission.');
}
$u = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'save_rules') {
    require_perm('settings.edit');
    set_setting('market_min_amount', (string)max(0, (float)post('market_min_amount')));
    dash_cache_forget('market_summary');
    flash('Rule saved.');
    redirect('market.php?tab=momentum');
}

$tab = get('tab', 'momentum');
$h = mk_history();
$sum = mk_summary();
$rules = mk_rules();

/** Up/down/flat as a coloured chip - the same words on every tab. */
function mk_chip(array $x) {
    $cls = ['up' => 'b-good', 'down' => 'b-bad', 'flat' => ''][$x['dir']] ?? '';
    $arrow = ['up' => '▲', 'down' => '▼', 'flat' => '＝'][$x['dir']] ?? '';
    return '<span class="badge ' . $cls . '">' . $arrow . ' ' . e($x['label']) . '</span>';
}

$page_title = 'Market Intelligence';
include __DIR__ . '/includes/header.php';
?>
<div class="card">
  <h2>📊 Market Intelligence</h2>
  <div class="grid-stats">
    <div class="stat"><div class="stat-label">Sales in the last year</div><div class="stat-value">₹<?= money($sum['sales_year']) ?></div></div>
    <div class="stat <?= $sum['change']['dir'] === 'up' ? 's-good' : ($sum['change']['dir'] === 'down' ? 's-bad' : '') ?>">
      <div class="stat-label">against the previous year</div><div class="stat-value"><?= e($sum['change']['label']) ?></div></div>
    <a class="stat s-good" href="market.php?tab=momentum"><div class="stat-label">Growing category</div><div class="stat-value"><?= (int)$sum['growing'] ?></div></a>
    <a class="stat <?= $sum['shrinking'] ? 's-bad' : '' ?>" href="market.php?tab=momentum"><div class="stat-label">Shrinking category</div><div class="stat-value"><?= (int)$sum['shrinking'] ?></div></a>
  </div>
  <div class="range-bar">
    <?php foreach (['momentum' => '📈 What grew and what shrank', 'squeeze' => '🪤 Price squeeze',
                    'discount' => '✂️ Money given away in discount', 'life' => '🌱 The new and the dead',
                    'season' => '📅 Season', 'where' => '📍 Where the customer is',
                    'quotes' => '📝 Lost deals', 'rules' => '⚙️ Rules'] as $k => $lbl): ?>
    <a class="rchip <?= $tab === $k ? 'on' : '' ?>" href="market.php?tab=<?= $k ?>"><?= $lbl ?></a>
    <?php endforeach; ?>
  </div>
  <p class="muted" style="font-size:12.5px;margin-bottom:0">
    ⚠️ <b>There is no outside information here.</b> a competitor price, a market rate or your market share —
    the shop does not have that data, and inventing it would be wrong. Everything below comes <b>from your own books</b>,
    and every figure can be opened all the way down to the bill.
    History: <b><?= $h['years'] ?> years</b> (from <?= dmy($h['first_sale']) ?>), <?= number_format($h['bills']) ?> bills.
  </p>
</div>

<?php if ($tab === 'momentum'): $by = get('by', 'category'); $rows = mk_momentum($by); ?>
<div class="card">
  <h2>📈 What grew and what shrank</h2>
  <p class="muted" style="margin-top:0;font-size:13px">
    <b>The last 365 days</b> against <b>the 365 days before it</b>. A full year is compared against a year — not against last month —
    otherwise the Diwali spike reads as growth and the monsoon as decline.
    <?= $rules['move_pct'] ?>a change under this % counts as the same.
  </p>
  <?php if (!$h['can_compare_year']): ?>
    <div class="flash flash-info">there is less than two years of history (<?= $h['years'] ?> years), so this comparison is incomplete — read it carefully.</div>
  <?php endif; ?>
  <div class="range-bar" style="margin-top:8px">
    <a class="rchip <?= $by === 'category' ? 'on' : '' ?>" href="market.php?tab=momentum&by=category">By category</a>
    <a class="rchip <?= $by === 'brand' ? 'on' : '' ?>" href="market.php?tab=momentum&by=brand">By brand</a>
  </div>
  <?php if (!$rows): ?><p class="muted">There are not enough sales to compare.</p><?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th><?= $by === 'brand' ? 'Brand' : 'Category' ?></th><th class="num">This year</th><th class="num">Last year</th>
      <th class="num">Change</th><th>Trend</th><th class="num">Bills</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $x): ?>
      <tr>
        <td><?= $x['id'] ? '<a href="items.php?f_cat=' . (int)$x['id'] . '">' . e($x['name']) . '</a>' : e($x['name']) ?></td>
        <td class="num">₹<?= money($x['now']) ?></td>
        <td class="num muted">₹<?= money($x['prev']) ?></td>
        <td class="num" style="color:<?= $x['diff'] >= 0 ? 'var(--good,#15803d)' : 'var(--bad,#b91c1c)' ?>">
          <?= $x['diff'] >= 0 ? '+' : '−' ?>₹<?= money(abs($x['diff'])) ?></td>
        <td><?= mk_chip($x) ?></td>
        <td class="num muted"><?= (int)$x['now_bills'] ?> <span style="font-size:11px">← <?= (int)$x['prev_bills'] ?></span></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<?php elseif ($tab === 'squeeze'): $sq = mk_squeeze(); ?>
<div class="card">
  <h2>🪤 Price squeeze — what you pay against what you get</h2>
  <p class="muted" style="margin-top:0;font-size:13px">
    Two real figures for every item: what you <b>actually paid</b> average cost price, and the price on your bill
    <b>actually received</b> average price (after line discounts). Both over the last <?= $sq['window'] ?> of days
    compared with the preceding <?= $sq['window'] ?> days.
    <b>buying gets dearer and the selling price does not keep up — that is the squeeze.</b>
  </p>
  <?php if ($sq['skipped']): ?>
    <div class="flash flash-info">
      <?= (int)$sq['skipped'] ?> items could not be compared — they were bought in both periods <b>and</b> a comparison is possible only if it sold.
      Nothing has been assumed from partial figures.
    </div>
  <?php endif; ?>
  <?php if (!$sq['rows']): ?><p class="muted">No item could be found that is comparable.</p><?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th>Item</th><th class="num">Cost price</th><th class="num">Price received</th>
      <th class="num">Margin</th><th class="num">Squeeze</th></tr></thead>
    <tbody>
    <?php foreach ($sq['rows'] as $x): ?>
      <tr>
        <td><a href="item_view.php?id=<?= (int)$x['item_id'] ?>"><?= e($x['name']) ?></a>
          <div class="muted" style="font-size:12px"><?= e($x['brand']) ?> · <?= rtrim(rtrim(number_format($x['qty'], 2), '0'), '.') ?> units sold</div></td>
        <td class="num">₹<?= money($x['pay_now']) ?><div class="muted" style="font-size:11px">← ₹<?= money($x['pay_prev']) ?> (<?= $x['pay_pct'] > 0 ? '+' : '' ?><?= $x['pay_pct'] ?>%)</div></td>
        <td class="num">₹<?= money($x['got_now']) ?><div class="muted" style="font-size:11px">← ₹<?= money($x['got_prev']) ?> (<?= $x['got_pct'] > 0 ? '+' : '' ?><?= $x['got_pct'] ?>%)</div></td>
        <td class="num">₹<?= money($x['margin_now']) ?><div class="muted" style="font-size:11px">← ₹<?= money($x['margin_prev']) ?></div></td>
        <td class="num"><span class="badge <?= $x['squeeze'] < -5 ? 'b-bad' : ($x['squeeze'] > 5 ? 'b-good' : '') ?>">
          <?= $x['squeeze'] > 0 ? '+' : '' ?><?= $x['squeeze'] ?></span></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <p class="muted" style="font-size:12px">Squeeze = change in selling price % − change in cost price %. Negative means buying got dearer faster.</p>
  <?php endif; ?>
</div>

<?php elseif ($tab === 'discount'): $d = mk_discount(13); $dc = mk_discount_by_category(); ?>
<div class="card">
  <h2>✂️ How much money actually went in discounts</h2>
  <p class="muted" style="margin-top:0;font-size:13px">
    every place a discount was given on a bill is counted — <b>Line discount</b>, <b>Bill discount</b> and
    <b>Loyalty points</b> that were used as money. "Full price" that is, what the bill would have been with no discount.
  </p>
  <?php if (!$d): ?><p class="muted">No bills yet.</p><?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th>Month</th><th class="num">Bills</th><th class="num">Full price</th><th class="num">discount given</th>
      <th class="num">%</th><th class="num">Line / bill / loyalty</th></tr></thead>
    <tbody>
    <?php foreach (array_reverse($d) as $m): ?>
      <tr>
        <td><?= e($m['month']) ?></td>
        <td class="num muted"><?= (int)$m['bills'] ?></td>
        <td class="num">₹<?= money($m['gross']) ?></td>
        <td class="num">₹<?= money($m['given']) ?></td>
        <td class="num"><span class="badge <?= $m['pct'] >= 5 ? 'b-bad' : ($m['pct'] >= 2 ? 'b-warn' : '') ?>"><?= $m['pct'] ?>%</span></td>
        <td class="num muted" style="font-size:12px">₹<?= money($m['line_disc']) ?> / ₹<?= money($m['bill_disc']) ?> / ₹<?= money($m['loyalty']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>
<div class="card">
  <h2>✂️ Which category gives away the most discount</h2>
  <p class="muted" style="margin-top:0;font-size:13px">
    here only <b>Line discount</b> is counted. A bill-level discount belongs to the whole bill — splitting it across categories
    and that split would be a made-up number. So it is not counted here.
  </p>
  <?php if (!$dc): ?><p class="muted">No line discount was given in the last year.</p><?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th>Category</th><th class="num">Discount</th><th class="num">Net sales</th><th class="num">Bills</th></tr></thead>
    <tbody>
    <?php foreach ($dc as $x): ?>
      <tr><td><?= e($x['name']) ?></td><td class="num">₹<?= money($x['given']) ?></td>
        <td class="num muted">₹<?= money($x['net']) ?></td><td class="num muted"><?= (int)$x['bills'] ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<?php elseif ($tab === 'life'): $lc = mk_lifecycle(); ?>
<div class="card">
  <h2>🌱 What new has caught on</h2>
  <p class="muted" style="margin-top:0;font-size:13px">Last <?= $lc['new_days'] ?> in days <b>first time</b> items sold.</p>
  <?php if (!$lc['rising']): ?><p class="muted">No new item was sold in this period.</p><?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th>Item</th><th>first time</th><th class="num">Qty</th><th class="num">Sales</th><th class="num">Bills</th></tr></thead>
    <tbody>
    <?php foreach ($lc['rising'] as $x): ?>
      <tr><td><a href="item_view.php?id=<?= (int)$x['id'] ?>"><?= e($x['name']) ?></a>
          <div class="muted" style="font-size:12px"><?= e($x['brand']) ?></div></td>
        <td><?= dmy($x['first_sold']) ?></td>
        <td class="num"><?= rtrim(rtrim(number_format($x['qty'], 2), '0'), '.') ?></td>
        <td class="num">₹<?= money($x['amt']) ?></td><td class="num muted"><?= (int)$x['bills'] ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>
<div class="card">
  <h2>🪦 What used to sell and has stopped</h2>
  <p class="muted" style="margin-top:0;font-size:13px">
    used to sell regularly, but in the last <?= $lc['fade_days'] ?> Active items that have not been on a bill for that many days.
    if stock is sitting, money is stuck in it — <a href="purchase_intel.php?tab=reorder">What to buy</a> has the full detail.
  </p>
  <?php if (!$lc['fading']): ?><p class="muted">There is no such item — everything is active.</p><?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th>Item</th><th class="num">how many days since it stopped</th><th class="num">Stock sitting</th><th class="num">total sales were</th></tr></thead>
    <tbody>
    <?php foreach ($lc['fading'] as $x): ?>
      <tr><td><a href="item_view.php?id=<?= (int)$x['id'] ?>"><?= e($x['name']) ?></a>
          <div class="muted" style="font-size:12px"><?= e($x['brand']) ?> · last <?= dmy($x['last_sold']) ?></div></td>
        <td class="num"><span class="badge <?= $x['quiet_days'] >= 365 ? 'b-bad' : 'b-warn' ?>"><?= (int)$x['quiet_days'] ?></span></td>
        <td class="num"><?= rtrim(rtrim(number_format((float)$x['stock'], 2), '0'), '.') ?></td>
        <td class="num muted">₹<?= money($x['amt']) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<?php elseif ($tab === 'season'): $se = mk_season(); ?>
<div class="card">
  <h2>📅 Which months of the year are heavy and which are light</h2>
  <?php if (!$se['ok']): ?>
    <div class="flash flash-info">📉 <?= e($se['why']) ?></div>
    <p class="muted">from a single December "business runs well in December" would be a guess, not a fact. So nothing is shown here.</p>
  <?php else: ?>
  <p class="muted" style="margin-top:0;font-size:13px">
    Only <b>full months</b> are counted (<?= dmy($se['from']) ?> to <?= dmy($se['to']) ?>) — the partial first month and the current one left out,
    otherwise half a month reads as a slump. 100 means an average month (Rs <?= money($se['mean']) ?>).
    Each line also says how many years it rests on.
  </p>
  <div class="table-wrap"><table>
    <thead><tr><th>Month</th><th class="num">Average sales</th><th class="num">Index</th><th></th><th class="num">How many years</th></tr></thead>
    <tbody>
    <?php foreach ($se['months'] as $m): ?>
      <tr>
        <td><?= e($m['name']) ?></td>
        <td class="num">₹<?= money($m['avg']) ?></td>
        <td class="num"><span class="badge <?= $m['index'] >= 110 ? 'b-good' : ($m['index'] <= 90 && $m['index'] > 0 ? 'b-warn' : '') ?>"><?= (int)$m['index'] ?></span></td>
        <td style="width:40%"><div style="background:var(--line,#e5e7eb);height:10px;border-radius:5px;overflow:hidden">
          <div style="width:<?= min(100, (int)$m['index'] / 1.5) ?>%;height:100%;background:var(--accent,#2563eb)"></div></div></td>
        <td class="num muted"><?= (int)$m['years'] ?><?= (int)$m['years'] < 2 ? ' <span class="badge b-warn">partial</span>' : '' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<?php elseif ($tab === 'where'): $g = mk_geography(); ?>
<div class="card">
  <h2>📍 Where the customers are</h2>
  <?php if (!$g['ok']): ?>
    <div class="flash flash-info">📍 <?= e($g['why']) ?></div>
    <p class="muted"><a href="parties.php">among customers</a> Start filling in the city field and this appears by itself.
      now <?= (int)$g['total'] ?> of <?= (int)$g['filled'] ?> have a city filled in.</p>
  <?php else: ?>
  <p class="muted" style="margin-top:0;font-size:13px">
    Last year sales, by customer city. <?= (int)$g['total'] ?> of <b><?= (int)$g['filled'] ?></b> customers have a city filled in —
    the rest are not on this list, so this is a partial picture.
  </p>
  <div class="table-wrap"><table>
    <thead><tr><th>City</th><th class="num">Customers</th><th class="num">Bills</th><th class="num">Sales</th></tr></thead>
    <tbody>
    <?php foreach ($g['rows'] as $x): ?>
      <tr><td><?= e($x['city']) ?></td><td class="num"><?= (int)$x['customers'] ?></td>
        <td class="num muted"><?= (int)$x['bills'] ?></td><td class="num">₹<?= money($x['amt']) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<?php elseif ($tab === 'quotes'): $lq = mk_lost_quotes(); $li = mk_lost_items(); ?>
<div class="card">
  <h2>📝 Quotations that never became bills</h2>
  <?php if (!$lq['ok']): ?>
    <div class="flash flash-info">📝 <?= e($lq['why']) ?></div>
    <p class="muted"><a href="estimates.php">Quotations</a> start making them, and how many deals were won and lost will show here.</p>
  <?php else: $st = $lq['stats']; ?>
  <p class="muted" style="margin-top:0;font-size:13px">
    Quotations from the last year. <b>the books do not know why a deal was lost</b> — so no reason is written here, only the fact.
  </p>
  <div class="grid-stats">
    <div class="stat s-good"><div class="stat-label">became bills</div><div class="stat-value"><?= (int)$st['won'] ?></div></div>
    <div class="stat s-bad"><div class="stat-label">Refused / cancelled</div><div class="stat-value"><?= (int)$st['lost'] ?></div></div>
    <div class="stat s-warn"><div class="stat-label">still open</div><div class="stat-value"><?= (int)$st['pending'] ?></div></div>
    <div class="stat"><div class="stat-label">Win rate</div><div class="stat-value"><?= $st['win_pct'] === null ? '—' : $st['win_pct'] . '%' ?></div></div>
  </div>
  <p class="muted" style="font-size:12.5px">became Rs <?= money($st['won_amt']) ?> · lost Rs <?= money($st['lost_amt']) ?> · open Rs <?= money($st['pending_amt']) ?>
    <?php if ($st['win_pct'] === null): ?><br>No quotation has been closed, so a win rate cannot be worked out.<?php endif; ?></p>
  <div class="table-wrap"><table>
    <thead><tr><th>Quotations</th><th>Customer</th><th class="num">Amount</th><th>Status</th><th class="num">Age</th></tr></thead>
    <tbody>
    <?php foreach ($lq['rows'] as $x): ?>
      <tr><td><a href="estimates.php?action=view&id=<?= (int)$x['id'] ?>"><?= e($x['estimate_no']) ?></a>
          <div class="muted" style="font-size:12px"><?= dmy($x['estimate_date']) ?></div></td>
        <td><?= $x['party_id'] ? '<a href="customer.php?id=' . (int)$x['party_id'] . '">' . e($x['customer_name']) . '</a>' : e($x['customer_name']) ?></td>
        <td class="num">₹<?= money($x['total']) ?></td>
        <td><span class="badge <?= $x['status'] === 'rejected' ? 'b-bad' : 'b-warn' ?>"><?= e($x['status']) ?></span></td>
        <td class="num muted"><?= (int)$x['age'] ?> days</td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>
<?php if ($li): ?>
<div class="card">
  <h2>📝 What gets quoted but does not sell</h2>
  <div class="table-wrap"><table>
    <thead><tr><th>Item</th><th class="num">In how many quotations</th><th class="num">Amount</th></tr></thead>
    <tbody>
    <?php foreach ($li as $x): ?>
      <tr><td><a href="item_view.php?id=<?= (int)$x['id'] ?>"><?= e($x['name']) ?></a></td>
        <td class="num"><?= (int)$x['quotes'] ?></td><td class="num">₹<?= money($x['amt']) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>

<?php elseif ($tab === 'rules'): ?>
<div class="card">
  <h2>⚙️ Rules</h2>
  <?php if (can('settings.edit')): ?>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="do" value="save_rules">
    <div class="field"><label>Ignore categories and brands smaller than this</label>
      <input type="number" name="market_min_amount" value="<?= (float)$rules['min_amount'] ?>" min="0" step="any">
      <p class="muted" style="font-size:12.5px">sales of a few rupees "up 200%" is misleading. To leave such small lines out of the list.</p>
    </div>
    <button class="btn btn-primary" type="submit">Save</button>
  </form>
  <?php else: ?><p class="muted">Changing the rule needs the settings.edit permission.</p><?php endif; ?>
  <hr>
  <p class="muted" style="font-size:13px">
    The remaining rules are fixed in code and written on every page:
    <?= $rules['move_pct'] ?>a change under % counts as the same ·
    for the price squeeze <?= $rules['squeeze_win'] ?> day window ·
    New = <?= $rules['new_days'] ?> sold for the first time within days ·
    Stopped = <?= $rules['fade_days'] ?> not on any bill for days ·
    at least 2 years for a season.
  </p>
</div>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
