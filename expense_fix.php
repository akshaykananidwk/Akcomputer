<?php
// Expenses -> Fix categories: old entries whose note points to another
// category (salary saved as "Tea & Water", petrol as "Courier" ...). The
// owner sees every proposed change, unticks what is wrong, and applies.
// Only the category changes - amount, date, mode and cash/bank stay. Undo
// puts the last batch back. Entries in a locked period are never touched.
require_once __DIR__ . '/includes/init.php';
require_perm('expenses.edit');
if (!is_full_admin()) { flash('Only the admin can correct old expense categories.', 'error'); redirect('expenses.php'); }
require_once __DIR__ . '/includes/expense_ai.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'apply') {
    $changes = [];
    foreach ((array)post('fix', []) as $id => $cat) if (is_string($cat)) $changes[(int)$id] = $cat;
    $n = expense_fix_apply($changes);
    log_activity('expense_fix_apply', "$n expenses re-categorised");
    flash("$n expenses moved to the right category. Press Undo if any is wrong.");
    redirect('expense_fix.php?m=' . urlencode((string)post('m')));
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'undo') {
    $n = expense_fix_undo();
    log_activity('expense_fix_undo', "$n expenses restored");
    flash("$n expenses are back in their old category.");
    redirect('expense_fix.php');
}

// one month at a time (this month by default) - fast enough to press once a month
$month = preg_match('/^\d{4}-\d{2}$/', (string)get('m')) ? get('m') : (get('m') === 'all' ? 'all' : date('Y-m'));
[$from, $to] = $month === 'all' ? [null, null] : [$month . '-01', date('Y-m-t', strtotime($month . '-01'))];
$useAi = get('ai') === '1';
$rows = expense_fix_candidates($from, $to, $useAi);
$undo = json_decode((string)setting('expense_fix_undo'), true);
$haveAi = setting('gemini_api_key') !== '' || setting('gemini_api_key_paid') !== '';
$home = array_flip(expense_category_groups()[expense_home_group()]);
$page_title = 'Fix Expense Categories';
include __DIR__ . '/includes/header.php';
?>
<div class="card">
  <h2>🤖 Fix expense categories</h2>
  <p class="muted">These saved expenses have a note that points to a different category. Untick any that are wrong, then press Apply.
    Only the category changes — amount, date and cash/bank stay as they are. Pick a month and press Apply once — that month is done. Entries without a note stay as they are; locked periods are skipped.</p>
  <form method="get" class="filterbar" style="margin-top:8px">
    <div><label>Month</label><select name="m" onchange="this.form.submit()">
      <?php for ($i = 0; $i < 12; $i++): $mv = date('Y-m', strtotime(date('Y-m-01') . " -$i month")); ?>
        <option value="<?= $mv ?>" <?= $month === $mv ? 'selected' : '' ?>><?= date('F Y', strtotime($mv . '-01')) ?></option>
      <?php endfor; ?>
      <option value="all" <?= $month === 'all' ? 'selected' : '' ?>>All old entries</option>
    </select></div>
    <?php if ($haveAi): ?><label class="check-inline"><input type="checkbox" name="ai" value="1" <?= $useAi ? 'checked' : '' ?> onchange="this.form.submit()"> 🤖 Also ask AI about notes the keyword list does not know (slower)</label><?php endif; ?>
  </form>
  <?php if ($undo): ?>
  <form method="post" onsubmit="return confirm('Put the <?= count($undo['rows']) ?> expenses back in their old category?')" style="margin-top:8px">
    <?= csrf_field() ?><input type="hidden" name="do" value="undo">
    <button class="btn btn-outline" type="submit">↩ Undo last fix (<?= count($undo['rows']) ?> expenses, <?= e(dmyt($undo['at'])) ?>)</button>
  </form>
  <?php endif; ?>
</div>

<?php if (!$rows): ?>
<div class="card"><p>✅ Nothing to fix for <?= $month === 'all' ? 'any month' : e(date('F Y', strtotime($month . '-01'))) ?> — every expense with a note is in the category its note points to.</p></div>
<?php else: ?>
<form method="post" onsubmit="return confirm('Change the category of the ticked expenses?')">
  <?= csrf_field() ?><input type="hidden" name="do" value="apply"><input type="hidden" name="m" value="<?= e($month) ?>">
  <p><button class="btn" type="submit">✅ Apply all <?= count($rows) ?> changes</button> <span class="muted">— untick below any you do not want</span></p>
  <div class="table-wrap">
  <table>
    <thead><tr><th></th><th>Date</th><th>Note</th><th class="num">Amount</th><th>Saved as</th><th>Should be</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $flip = isset($home[$r['category']]) !== isset($home[$r['suggest']]); ?>
      <tr>
        <td><input type="checkbox" name="fix[<?= (int)$r['id'] ?>]" value="<?= e($r['suggest']) ?>" checked></td>
        <td><?= dmy($r['exp_date']) ?></td>
        <td><?= e($r['notes']) ?><br><small class="muted"><?= e($r['staff'] ?: '-') ?></small></td>
        <td class="num">₹<?= money($r['amount']) ?></td>
        <td><?= e($r['category']) ?></td>
        <td><strong><?= e($r['suggest']) ?></strong> <small class="muted"><?= $r['by'] === 'ai' ? '🤖 AI' : 'keyword' ?></small>
          <?php if ($flip): ?><br><small style="color:var(--bad)">moves between shop and household spending</small><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <p style="margin-top:10px"><button class="btn" type="submit">✅ Apply ticked changes</button></p>
</form>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
