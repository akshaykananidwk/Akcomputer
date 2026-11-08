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
    redirect('expense_fix.php');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'undo') {
    $n = expense_fix_undo();
    log_activity('expense_fix_undo', "$n expenses restored");
    flash("$n expenses are back in their old category.");
    redirect('expense_fix.php');
}

$rows = expense_fix_candidates();
$undo = json_decode((string)setting('expense_fix_undo'), true);
$haveAi = setting('gemini_api_key') !== '' || setting('gemini_api_key_paid') !== '';
$home = array_flip(expense_category_groups()[expense_home_group()]);
$page_title = 'Fix Expense Categories';
include __DIR__ . '/includes/header.php';
?>
<div class="card">
  <h2>🤖 Fix expense categories</h2>
  <p class="muted">These saved expenses have a note that points to a different category. Untick any that are wrong, then press Apply.
    Only the category changes — amount, date and cash/bank stay as they are. The last 300 entries with a note are checked; locked periods are skipped.</p>
  <?php if (!$haveAi): ?><div class="flash flash-info">Only the keyword list was used. Add a Gemini key in Settings → Invoice &amp; Payment for AI to read every note.</div><?php endif; ?>
  <?php if ($undo): ?>
  <form method="post" onsubmit="return confirm('Put the <?= count($undo['rows']) ?> expenses back in their old category?')" style="margin-top:8px">
    <?= csrf_field() ?><input type="hidden" name="do" value="undo">
    <button class="btn btn-outline" type="submit">↩ Undo last fix (<?= count($undo['rows']) ?> expenses, <?= e(dmyt($undo['at'])) ?>)</button>
  </form>
  <?php endif; ?>
</div>

<?php if (!$rows): ?>
<div class="card"><p>✅ Every expense with a note is already in the category its note points to.</p></div>
<?php else: ?>
<form method="post" onsubmit="return confirm('Change the category of the ticked expenses?')">
  <?= csrf_field() ?><input type="hidden" name="do" value="apply">
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
