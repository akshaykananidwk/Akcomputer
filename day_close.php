<?php
// Day closing - count the drawer, put it next to what the books say.
//
// The whole screen is a comparison of two numbers: what the cash rule says
// should be in the drawer at the end of this date, and what was physically
// counted. Everything else - the note-by-note slip, the day's movements, the
// history below - exists to explain a gap between those two.
//
// A gap is never written into the books on its own. Cash the shop cannot
// explain has to stay visible; the owner decides whether to post a correction,
// and it goes through the SAME money_transfers 'cash_adjust' path as the Cash
// & Bank page, so there is one way for cash to change and one place to see it.
require_once __DIR__ . '/includes/init.php';
require_perm('dayclose.view');
$u = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'save') {
    require_perm('dayclose.add');
    $date = post('close_date', today());
    if ($date > today()) { flash('Tomorrow cannot be closed today.', 'error'); redirect('day_close.php'); }
    if (is_period_locked($date)) { flash(period_lock_message(), 'error'); redirect('day_close.php'); }

    // the count is rebuilt from the note boxes on the server - a total typed
    // into a hidden field is not the count, it is a claim about the count
    $denoms = [];
    $counted = 0.0;
    foreach (cash_denominations() as $v) {
        $n = (int)post('d' . $v);
        if ($n > 0) { $denoms[] = $v . 'x' . $n; $counted += $v * $n; }
    }
    // loose change that does not fit the note boxes
    $counted += round((float)post('loose'), 2);

    $f = day_close_figures($date);
    $diff = money_r($counted - $f['expected']);

    $adjustId = null;
    if (post('post_adjust') && abs($diff) > 0.009) {
        require_perm('cashbank.adjust');
        q("INSERT INTO money_transfers (txn_type, amount, adjust_dir, from_user_id, txn_date, notes, status, created_by)
           VALUES ('cash_adjust', ?, ?, ?, ?, ?, 'done', ?)",
          [abs($diff), $diff > 0 ? 'add' : 'reduce', $u['id'], $date,
           'days closed ' . dmy($date) . ' — counting difference', $u['id']]);
        $adjustId = insert_id();
    }

    // re-closing a day replaces that day's count; the correction it posted
    // (if any) stays where it is - it already moved money and undoing it
    // silently would change a figure someone has read
    q("INSERT INTO day_closes (close_date, counted, expected, diff, denoms, notes, adjust_id, closed_by)
       VALUES (?,?,?,?,?,?,?,?)
       ON DUPLICATE KEY UPDATE counted = VALUES(counted), expected = VALUES(expected), diff = VALUES(diff),
         denoms = VALUES(denoms), notes = VALUES(notes),
         adjust_id = COALESCE(VALUES(adjust_id), adjust_id), closed_by = VALUES(closed_by)",
      [$date, money_r($counted), $f['expected'], $diff, implode(',', $denoms), trim((string)post('notes')), $adjustId, $u['id']]);

    log_activity('day_close', dmy($date) . ' Counted Rs ' . money($counted) . ' / books Rs ' . money($f['expected'])
        . ' Difference Rs ' . money($diff) . ($adjustId ? ' (squared off)' : ''));
    flash(abs($diff) <= 0.009
        ? '✅ ' . dmy($date) . ' — the till and the books agree.'
        : '⚠️ ' . dmy($date) . ' — difference Rs ' . money(abs($diff)) . ' ' . ($diff > 0 ? 'more' : 'fewer') . ' .',
        abs($diff) <= 0.009 ? 'success' : 'error');
    redirect('day_close.php?d=' . $date);
}

$date = get('d', today());
if ($date > today()) $date = today();
$f = day_close_figures($date);
$saved = row('SELECT dc.*, us.name closed_name FROM day_closes dc
              LEFT JOIN users us ON us.id = dc.closed_by WHERE dc.close_date = ?', [$date]);
$hist = all("SELECT dc.*, us.name closed_name FROM day_closes dc
             LEFT JOIN users us ON us.id = dc.closed_by
             ORDER BY dc.close_date DESC LIMIT 30");
$openDays = (int)val("SELECT COUNT(DISTINCT d) FROM (
        SELECT pay_date d FROM payments WHERE mode='cash' AND pay_date < ? AND pay_date >= DATE_SUB(?, INTERVAL 30 DAY)
        UNION SELECT exp_date FROM expenses WHERE mode='cash' AND exp_date < ? AND exp_date >= DATE_SUB(?, INTERVAL 30 DAY)
    ) x WHERE d NOT IN (SELECT close_date FROM day_closes)", [$date, $date, $date, $date]);

$page_title = 'Day closing';
include __DIR__ . '/includes/header.php';
$denomSaved = $saved ? cash_denom_parse($saved['denoms']) : [];
?>
<div class="card">
  <div class="page-actions no-print" style="margin:0 0 10px;justify-content:space-between">
    <h2 style="margin:0">🌙 Day closing</h2>
    <form method="get" style="display:inline-flex;gap:6px;align-items:center">
      <input type="date" name="d" value="<?= e($date) ?>" max="<?= today() ?>" onchange="this.form.submit()">
    </form>
  </div>
  <?php if ($openDays): ?>
    <p class="flash flash-error" style="margin:0 0 10px">⚠️ In the last 30 days <strong><?= $openDays ?></strong> days had cash movement but no closing.</p>
  <?php endif; ?>

  <div class="grid-stats mb">
    <div class="stat"><div class="stat-label">was there in the morning</div><div class="stat-value">₹<?= money($f['opening']) ?></div></div>
    <div class="stat s-ok"><div class="stat-label">⬇ Came in today</div><div class="stat-value">₹<?= money($f['in'] + $f['from_bank']) ?></div></div>
    <div class="stat s-bad"><div class="stat-label">⬆ Went out today</div><div class="stat-value">₹<?= money($f['out'] + $f['expense'] + $f['to_bank']) ?></div></div>
    <div class="stat"><div class="stat-label">should be, per the books</div><div class="stat-value">₹<?= money($f['expected']) ?></div></div>
  </div>

  <div class="table-wrap" style="box-shadow:none">
    <table class="table-sm">
      <tbody>
        <tr><td>in the till this morning</td><td class="num">₹<?= money($f['opening']) ?></td></tr>
        <tr><td>+ Cash received from customers</td><td class="num">₹<?= money($f['in']) ?></td></tr>
        <tr><td>+ Withdrawn from the bank</td><td class="num">₹<?= money($f['from_bank']) ?></td></tr>
        <tr><td>− Paid out in cash (supplier/party)</td><td class="num">₹<?= money($f['out']) ?></td></tr>
        <tr><td>− Expenses in cash</td><td class="num">₹<?= money($f['expense']) ?></td></tr>
        <tr><td>− Deposited in the bank</td><td class="num">₹<?= money($f['to_bank']) ?></td></tr>
        <?php if ($f['adj_add'] > 0.009 || $f['adj_less'] > 0.009): ?>
        <tr><td>± Adjustments</td><td class="num">₹<?= money($f['adj_add'] - $f['adj_less']) ?></td></tr>
        <?php endif; ?>
        <tr class="ag-total"><td><strong>should be, per the books</strong></td><td class="num"><strong>₹<?= money($f['expected']) ?></strong></td></tr>
      </tbody>
    </table>
  </div>
</div>

<div class="card no-print">
  <h3>💵 Count the till</h3>
  <?php if ($saved): ?>
    <p class="muted">Closed by <?= e($saved['closed_name'] ?: '-') ?> on <?= dmyt($saved['created_at']) ?>. Recounting and saving will change it.</p>
  <?php endif; ?>
  <form method="post" id="dcForm">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="save">
    <input type="hidden" name="close_date" value="<?= e($date) ?>">
    <div class="form-row cols-3">
      <?php foreach (cash_denominations() as $v): ?>
      <div><label>₹<?= $v ?> notes</label>
        <input type="number" min="0" step="1" name="d<?= $v ?>" class="dcn" data-v="<?= $v ?>"
               value="<?= isset($denomSaved[$v]) ? (int)$denomSaved[$v] : '' ?>" oninput="dcSum()" placeholder="0"></div>
      <?php endforeach; ?>
      <div><label>Change (Rs)</label>
        <input type="number" step="any" min="0" name="loose" id="dcLoose" value="" oninput="dcSum()" placeholder="0"></div>
    </div>
    <div class="grid-stats mb">
      <div class="stat"><div class="stat-label">As counted</div><div class="stat-value" id="dcCounted">₹0.00</div></div>
      <div class="stat" id="dcDiffBox"><div class="stat-label">Difference</div><div class="stat-value" id="dcDiff">₹0.00</div></div>
    </div>
    <div class="field"><label>Note</label><input type="text" name="notes" maxlength="200"
      value="<?= e($saved['notes'] ?? '') ?>" placeholder="e.g. Rs 500 to be put back tomorrow"></div>
    <?php if (can('cashbank.adjust')): ?>
    <label class="check-inline mb"><input type="checkbox" name="post_adjust" value="1">
      If there is a difference, square the books too (an adjustment is recorded in Cash & Bank)</label>
    <?php endif; ?>
    <button class="btn" type="submit">Snooze for days</button>
  </form>
</div>

<?php if ($hist): ?>
<div class="card">
  <h3>🗓️ Recent closings</h3>
  <div class="table-wrap" style="box-shadow:none">
    <table class="table-sm">
      <thead><tr><th>Date</th><th class="num">on the books</th><th class="num">Counted</th><th class="num">Difference</th><th>Who</th><th>Note</th></tr></thead>
      <tbody>
      <?php foreach ($hist as $h): $bad = abs((float)$h['diff']) > 0.009; ?>
        <tr>
          <td><a href="day_close.php?d=<?= e($h['close_date']) ?>"><?= dmy($h['close_date']) ?></a></td>
          <td class="num">₹<?= money($h['expected']) ?></td>
          <td class="num">₹<?= money($h['counted']) ?></td>
          <td class="num" style="font-weight:700;color:<?= $bad ? 'var(--bad)' : 'var(--ok)' ?>">
            <?= $bad ? ((float)$h['diff'] > 0 ? '+' : '−') . '₹' . money(abs($h['diff'])) : '✔' ?>
            <?= $h['adjust_id'] ? ' <span class="badge badge-info">Squared off</span>' : '' ?></td>
          <td class="muted"><?= e($h['closed_name'] ?: '-') ?></td>
          <td class="muted"><?= e($h['notes']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<script>
var DC_EXPECTED = <?= json_encode((float)$f['expected']) ?>;
function dcSum() {
  var t = 0;
  document.querySelectorAll('.dcn').forEach(function (el) {
    t += (parseInt(el.value, 10) || 0) * (parseFloat(el.dataset.v) || 0);
  });
  t += parseFloat(document.getElementById('dcLoose').value) || 0;
  var d = Math.round((t - DC_EXPECTED) * 100) / 100;
  document.getElementById('dcCounted').textContent = '₹' + t.toFixed(2);
  document.getElementById('dcDiff').textContent = (d > 0 ? '+₹' : d < 0 ? '−₹' : '₹') + Math.abs(d).toFixed(2);
  var box = document.getElementById('dcDiffBox');
  box.className = 'stat' + (Math.abs(d) < 0.01 ? ' s-ok' : ' s-bad');
}
dcSum();
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
