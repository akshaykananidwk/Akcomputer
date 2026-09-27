<?php
// Forecast - cash ahead, sales ahead, and how wrong this method usually is.
//
// The last part is not an afterthought. Every prediction on this screen sits
// next to the record of the same method's past attempts, because a number in a
// box looks like a fact and the owner will spend money against it.
require_once __DIR__ . '/includes/init.php';
require_perm('reports.view');
if (!can('payments.view')) {
    http_response_code(403);
    die('This screen is about money coming in and going out. You need the payments.view permission.');
}
$u = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'save_rules') {
    require_perm('settings.edit');
    set_setting('cash_floor', (string)max(0, (float)post('cash_floor')));
    set_setting('sales_goal', (string)max(0, (float)post('sales_goal')));
    dash_cache_forget('forecast_summary');
    flash('Saved.');
    redirect('forecast.php?tab=' . get('tab', 'cash'));
}

$tab = get('tab', 'cash');
$rules = fc_rules();
$weeks = max(4, min(26, (int)get('w', $rules['weeks'])));

$page_title = 'Forecast';
include __DIR__ . '/includes/header.php';
?>
<div class="card">
  <h2>🔮 What lies ahead</h2>
  <div class="range-bar">
    <?php foreach (['cash' => '💵 The cash picture', 'sales' => '📈 Sales forecast',
                    'accuracy' => '🎯 How accurate the forecast is', 'target' => '🏁 This month target',
                    'rules' => '⚙️ Rules'] as $k => $lbl): ?>
    <a class="rchip <?= $tab === $k ? 'on' : '' ?>" href="forecast.php?tab=<?= $k ?>"><?= $lbl ?></a>
    <?php endforeach; ?>
  </div>
  <p class="muted" style="font-size:12.5px;margin-bottom:0">
    ⚠️ <b>A forecast is a guess, not a fact.</b> so beside every forecast here
    <a href="forecast.php?tab=accuracy">how wrong that same method was in the past</a> is written there too.
    The cash picture is mostly not a guess — the bills, the amounts and the dates are written in the books.
  </p>
</div>

<?php if ($tab === 'cash'): $cf = fc_cashflow($weeks); ?>
<div class="card">
  <h2>💵 Coming in <?= (int)$weeks ?> week cash picture</h2>
  <div class="grid-stats">
    <div class="stat"><div class="stat-label">on hand right now</div><div class="stat-value">₹<?= money($cf['opening']['total']) ?></div></div>
    <div class="stat s-good"><div class="stat-label">expected in</div><div class="stat-value">₹<?= money($cf['total_in']) ?></div></div>
    <div class="stat s-warn"><div class="stat-label">Going out</div><div class="stat-value">₹<?= money($cf['total_out']) ?></div></div>
    <div class="stat <?= $cf['closing'] < $cf['floor'] ? 's-bad' : '' ?>"><div class="stat-label">will be left at the end</div><div class="stat-value">₹<?= money($cf['closing']) ?></div></div>
  </div>
  <?php if ($cf['danger']): $d = $cf['danger'][0]; ?>
    <div class="flash flash-error">
      ⚠️ <b><?= e($d['label']) ?></b> week cash Rs <?= money($d['balance']) ?> looks like falling to
      (<?= count($cf['danger']) ?> tight weeks). Start chasing payment now —
      <a href="collection.php">Collection list</a>.
    </div>
  <?php else: ?>
    <div class="flash flash-success">✅ In this period cash never <?= $cf['floor'] > 0 ? '₹' . money($cf['floor']) : 'zero' ?>.</div>
  <?php endif; ?>
  <div class="table-wrap"><table>
    <thead><tr><th>Week</th><th class="num">In</th><th class="num">Out</th><th class="num">Net</th><th class="num">will be left</th></tr></thead>
    <tbody>
    <?php foreach ($cf['rows'] as $w): ?>
      <tr>
        <td><?= e($w['label']) ?><?= $w['week'] === 0 ? ' <span class="badge">This week</span>' : '' ?></td>
        <td class="num"><?= $w['in'] > 0 ? '₹' . money($w['in']) : '<span class="muted">·</span>' ?></td>
        <td class="num"><?= $w['out'] > 0 ? '₹' . money($w['out']) : '<span class="muted">·</span>' ?>
          <?php if ($w['fixed'] > 0): ?><div class="muted" style="font-size:11px">Salaries/rent Rs <?= money($w['fixed']) ?></div><?php endif; ?></td>
        <td class="num" style="color:<?= $w['net'] >= 0 ? 'var(--good,#15803d)' : 'var(--bad,#b91c1c)' ?>">
          <?= $w['net'] >= 0 ? '+' : '−' ?>₹<?= money(abs($w['net'])) ?></td>
        <td class="num"><b <?= $w['balance'] < $cf['floor'] ? 'style="color:var(--bad,#b91c1c)"' : '' ?>>₹<?= money($w['balance']) ?></b></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <div class="range-bar" style="margin-top:10px">
    <?php foreach ([4, 8, 12, 26] as $wk): ?>
      <a class="rchip <?= $weeks === $wk ? 'on' : '' ?>" href="forecast.php?tab=cash&w=<?= $wk ?>"><?= $wk ?> weeks</a>
    <?php endforeach; ?>
  </div>
</div>

<div class="card">
  <h2>🚫 What is NOT counted in this</h2>
  <p class="muted" style="margin-top:0;font-size:13px">
    in the figures above this money <b>deliberately not added</b> — adding it would make the picture look wrongly good.
  </p>
  <div class="table-wrap"><table>
    <thead><tr><th>What</th><th class="num">Amount</th><th>why it is not counted</th></tr></thead>
    <tbody>
      <tr>
        <td>⏰ Overdue receivables<?= $cf['overdue_bills'] ? ' (' . (int)$cf['overdue_bills'] . ' bills)' : '' ?></td>
        <td class="num">₹<?= money($cf['overdue_in']) ?></td>
        <td class="muted" style="font-size:12.5px">this money is <b>already late</b>. Assuming it arrives next week would be wrong —
          will have to be collected. <a href="collection.php">Collection list</a></td>
      </tr>
      <tr>
        <td>🚶 Walk-in bills (no customer recorded)</td>
        <td class="num">₹<?= money($cf['walkin']) ?></td>
        <td class="muted" style="font-size:12.5px">There is no account at all, so there is no telling who to ask.</td>
      </tr>
      <tr>
        <td>📅 <?= (int)$weeks ?> arriving after that many weeks</td>
        <td class="num">₹<?= money($cf['beyond']) ?></td>
        <td class="muted" style="font-size:12.5px">is outside this period. Above "26 weeks" press it and it shows.</td>
      </tr>
      <tr>
        <td>🛒 Sales not yet made</td>
        <td class="num muted">—</td>
        <td class="muted" style="font-size:12.5px">in the cash picture only <b>quotations that really became bills</b> is the same.
          Forecast of future sales <a href="forecast.php?tab=sales">in its own tab</a> — that is a guess, so it is not mixed into the cash.</td>
      </tr>
    </tbody>
  </table></div>
</div>

<?php if ($cf['recurring']): ?>
<div class="card">
  <h2>🔁 Costs that go out every month</h2>
  <p class="muted" style="margin-top:0;font-size:13px">
    Last <?= (int)$rules['recur_months'] ?> at least, out of <?= (int)$rules['recur_hits'] ?> costs that fell in those months.
    for those months this is the <b>median</b> (not the average) — so one large one-off cost does not inflate the whole forecast.
  </p>
  <div class="table-wrap"><table>
    <thead><tr><th>Cost</th><th class="num">per month (estimate)</th><th class="num">in how many months it appeared</th><th class="num">an ordinary date</th></tr></thead>
    <tbody>
    <?php foreach ($cf['recurring'] as $e): ?>
      <tr><td><?= e($e['category']) ?></td><td class="num">₹<?= money($e['amount']) ?></td>
        <td class="num muted"><?= (int)$e['months'] ?> / <?= (int)$rules['recur_months'] ?></td>
        <td class="num muted"><?= (int)$e['day'] ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>

<?php elseif ($tab === 'sales'): $sa = fc_sales_ahead(3); ?>
<div class="card">
  <h2>📈 Sales in the coming months</h2>
  <?php if (!$sa['ok']): ?>
    <div class="flash flash-info">📉 <?= e($sa['why']) ?></div>
    <p class="muted">It would be easy to produce a figure from thin history, but that is the wrong road.</p>
  <?php else: ?>
  <p class="muted" style="margin-top:0;font-size:13px">
    Last <?= (int)$rules['base_months'] ?> full months, weighted average, and on top of it
    <b>that month season</b>multiplier (with less than two years of history no season is applied).
    <?php if ($sa['band'] !== null): ?>
      Range <b>±<?= $sa['band'] ?>%</b> — it is not invented, <a href="forecast.php?tab=accuracy">this method has been wrong by this much on average in the past</a>.
    <?php else: ?>
      No range is shown — the method has not been tested against the past yet.
    <?php endif; ?>
  </p>
  <div class="table-wrap"><table>
    <thead><tr><th>Month</th><th class="num">Estimate</th><th class="num">Between</th><th class="num">Season</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($sa['rows'] as $r): ?>
      <tr>
        <td><?= e(date('F Y', strtotime($r['month'] . '-01'))) ?>
          <?= $r['partial'] ? ' <span class="badge b-warn">Current month</span>' : '' ?></td>
        <td class="num"><b>₹<?= money($r['value']) ?></b></td>
        <td class="num"><?= $r['low'] === null ? '<span class="muted">—</span>' : '₹' . money($r['low']) . ' – ₹' . money($r['high']) ?></td>
        <td class="num"><?= $r['factor'] == 1.0 ? '<span class="muted">not applied</span>' : '×' . $r['factor'] ?>
          <?php if ($r['years']): ?><div class="muted" style="font-size:11px"><?= (int)$r['years'] ?> from years</div><?php endif; ?></td>
        <td class="muted" style="font-size:12px"><?= e($r['season_why']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <p class="muted" style="font-size:12.5px">
    ⚠️ Estimate for the current month <b>for the full month</b> — sales so far <a href="forecast.php?tab=target">in the Target tab</a>.
    and there is no outside information about next month in these figures — only your own history.
  </p>
  <?php endif; ?>
</div>

<?php elseif ($tab === 'accuracy'): $bt = fc_backtest(12); ?>
<div class="card">
  <h2>🎯 How right this method actually turns out</h2>
  <p class="muted" style="margin-top:0;font-size:13px">
    the months that <b>have passed</b> the same method was run again for it — <b>using only the data from before that month</b> —
    and what it would have said is set against what actually happened. That is why the forecast range can be trusted.
  </p>
  <?php if (!$bt['n']): ?>
    <div class="flash flash-info">There are not enough completed months to test against.</div>
  <?php else: ?>
  <div class="grid-stats">
    <div class="stat"><div class="stat-label">Months tested</div><div class="stat-value"><?= (int)$bt['n'] ?></div></div>
    <div class="stat <?= $bt['mape'] > 25 ? 's-bad' : ($bt['mape'] > 15 ? 's-warn' : 's-good') ?>">
      <div class="stat-label">Average error</div><div class="stat-value"><?= $bt['mape'] ?>%</div></div>
    <div class="stat s-good"><div class="stat-label">closest</div><div class="stat-value"><?= $bt['best'] ?>%</div></div>
    <div class="stat s-warn"><div class="stat-label">furthest</div><div class="stat-value"><?= $bt['worst'] ?>%</div></div>
  </div>
  <?php if ($bt['mape'] > 25): ?>
    <div class="flash flash-error">⚠️ Average error <?= $bt['mape'] ?>% — so big decisions on this forecast should not be taken.
      When sales swing wildly, no method comes out right.</div>
  <?php endif; ?>
  <div class="table-wrap"><table>
    <thead><tr><th>Month</th><th class="num">What the method said</th><th class="num">What happened</th><th class="num">Difference</th><th class="num">Error</th></tr></thead>
    <tbody>
    <?php foreach ($bt['rows'] as $r): ?>
      <tr>
        <td><?= e(date('M Y', strtotime($r['month'] . '-01'))) ?></td>
        <td class="num muted">₹<?= money($r['predicted']) ?></td>
        <td class="num">₹<?= money($r['actual']) ?></td>
        <td class="num" style="color:<?= $r['diff'] >= 0 ? 'var(--warn,#b45309)' : 'var(--bad,#b91c1c)' ?>">
          <?= $r['diff'] >= 0 ? 'overstated +' : 'understated −' ?>₹<?= money(abs($r['diff'])) ?></td>
        <td class="num"><span class="badge <?= $r['err_pct'] > 20 ? 'b-bad' : ($r['err_pct'] > 10 ? 'b-warn' : 'b-good') ?>"><?= $r['err_pct'] ?>%</span></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<?php elseif ($tab === 'target'): $goal = (float)setting('sales_goal', 0); $tg = fc_target($goal); ?>
<div class="card">
  <h2>🏁 This month — <?= e(date('F Y')) ?></h2>
  <div class="grid-stats">
    <div class="stat"><div class="stat-label">so far</div><div class="stat-value">₹<?= money($tg['sofar']) ?></div></div>
    <div class="stat"><div class="stat-label">Daily average</div><div class="stat-value">₹<?= money($tg['per_day_so_far']) ?></div></div>
    <div class="stat"><div class="stat-label">at this pace the month ends at</div><div class="stat-value">₹<?= money($tg['at_this_pace']) ?></div></div>
    <div class="stat"><div class="stat-label">Days left</div><div class="stat-value"><?= (int)$tg['left'] ?></div></div>
  </div>
  <p class="muted" style="font-size:12.5px">There is no guessing in this — only <?= (int)$tg['done'] ?> actual sales over those days divided by <?= (int)$tg['done'] ?>, times <?= (int)$tg['days'] ?>.</p>
  <?php if ($goal > 0): ?>
    <div class="flash <?= $tg['needed'] <= 0 ? 'flash-success' : ($tg['at_this_pace'] >= $goal ? 'flash-info' : 'flash-error') ?>">
      <?php if ($tg['needed'] <= 0): ?>
        ✅ ₹<?= money($goal) ?> target has been met.
      <?php elseif ($tg['left'] <= 0): ?>
        month finished — Rs <?= money($tg['needed']) ?> fell.
      <?php else: ?>
        ₹<?= money($goal) ?> still needed to reach it <?= (int)$tg['left'] ?> in days, so <b>Rs <?= money($tg['per_day_needed']) ?> a day</b> is needed
        (currently Rs per day <?= money($tg['per_day_so_far']) ?> ).
      <?php endif; ?>
    </div>
  <?php else: ?>
    <p class="muted">To set a target <a href="forecast.php?tab=rules">in the rules</a>.</p>
  <?php endif; ?>
</div>

<?php elseif ($tab === 'rules'): ?>
<div class="card">
  <h2>⚙️ Rules</h2>
  <?php if (can('settings.edit')): ?>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="do" value="save_rules">
    <div class="field"><label>Warn if cash falls below this (Rs)</label>
      <input type="number" name="cash_floor" value="<?= (float)$rules['floor'] ?>" min="0" step="any">
      <p class="muted" style="font-size:12.5px">Many shops need to keep some money in the account at all times. Leave 0 and you are warned only when it goes below zero.</p></div>
    <div class="field"><label>Monthly sales target (Rs)</label>
      <input type="number" name="sales_goal" value="<?= (float)setting('sales_goal', 0) ?>" min="0" step="any">
      <p class="muted" style="font-size:12.5px">Leave it blank and no target calculation is shown.</p></div>
    <button class="btn btn-primary" type="submit">Save</button>
  </form>
  <?php else: ?><p class="muted">Changing it needs the settings.edit permission.</p><?php endif; ?>
  <hr>
  <p class="muted" style="font-size:13px">
    The remaining rules are fixed in code: for the forecast, the last <?= (int)$rules['base_months'] ?> full months ·
    at least <?= (int)$rules['min_months'] ?> a forecast only with that many months ·
    Method <?= (int)$rules['backtest'] ?> is tested against completed months ·
    monthly cost = <?= (int)$rules['recur_months'] ?> of <?= (int)$rules['recur_hits'] ?> appeared in months ·
    A customer late payment counts at most <?= (int)$rules['max_delay'] ?> counts up to days.
  </p>
</div>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
