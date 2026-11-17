<?php
// Parties -> Merge duplicates. The same customer saved two or three times
// ("Tanveer", "Tanveer Rupen bandar", "BAHARUNI TANVIRAHMED") is brought into
// ONE party: pick the one to keep, every bill / payment / return of the
// others moves to it with its own date, and the others are closed. Undo
// puts everything back.
require_once __DIR__ . '/includes/init.php';
require_perm('parties.edit');
if (!is_full_admin()) { flash('Only the admin can merge parties - it moves bills and payments.', 'error'); redirect('parties.php'); }
require_once __DIR__ . '/includes/party_merge.php';
$u = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'merge') {
    $to = (int)post('to');
    $done = 0;
    try {
        foreach (array_unique(array_map('intval', (array)post('from', []))) as $from) {
            if ($from && $from !== $to) { party_merge_apply($from, $to, $u['id']); $done++; }
        }
        flash($done ? "$done part" . ($done === 1 ? 'y' : 'ies') . ' merged - everything is now under ' . val('SELECT name FROM parties WHERE id = ?', [$to]) . '. Undo is below if needed.' : 'Tick at least one party to merge.', $done ? 'success' : 'error');
    } catch (Throwable $e) { flash('Not merged: ' . $e->getMessage(), 'error'); }
    redirect($done ? 'parties.php?action=ledger&id=' . $to : 'party_merge.php');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'undo') {
    try { party_merge_undo((int)post('id')); flash('Merge undone - both parties are back as they were.'); }
    catch (Throwable $e) { flash($e->getMessage(), 'error'); }
    redirect('party_merge.php');
}

// the party to keep, and the ones that will move into it
$keep = (int)get('keep');
$fromIds = array_values(array_filter(array_map('intval', explode(',', (string)get('from'))), fn($x) => $x && $x !== $keep));
$keepRow = $keep ? row('SELECT * FROM parties WHERE id = ? AND is_active = 1', [$keep]) : null;
$fromRows = $fromIds ? all('SELECT * FROM parties WHERE is_active = 1 AND id IN (' . implode(',', $fromIds) . ')') : [];
$allParties = all('SELECT id, name, mobile FROM parties WHERE is_active = 1 ORDER BY name');
$dups = $keepRow ? [] : party_merge_duplicates();
$history = all('SELECT m.*, pf.name from_name, pt.name to_name, us.name by_name FROM party_merges m
                LEFT JOIN parties pf ON pf.id = m.from_party_id LEFT JOIN parties pt ON pt.id = m.to_party_id
                LEFT JOIN users us ON us.id = m.created_by ORDER BY m.id DESC LIMIT 20');
$card = function ($p, $tag) {
    $bal = party_balance($p['id']);
    $n = party_merge_counts($p['id']);
    $what = $n ? implode(' · ', array_map(fn($k, $v) => $v . ' ' . party_merge_label($k), array_keys($n), $n)) : 'no entries';
    return '<div><strong>' . e($p['name']) . '</strong> ' . $tag . '<br><small class="muted">' . e(show_mobile($p['mobile'])) . ' · #' . (int)$p['id']
        . ' · balance ₹' . money(abs($bal)) . ($bal > 0.009 ? ' to get' : ($bal < -0.009 ? ' to give' : '')) . '<br>' . e($what) . '</small></div>';
};
$page_title = 'Merge Parties';
include __DIR__ . '/includes/header.php';
?>
<div class="card">
  <h2>🔀 Merge duplicate parties</h2>
  <p class="muted">When one customer was saved two or three times, keep ONE and move the others into it. Every sale, purchase,
    payment, return, estimate, repair and follow-up moves with its own date; opening balances and loyalty points are added together;
    the extra parties are closed (not deleted). Total money in the books stays exactly the same. Undo puts everything back.</p>
</div>

<?php if ($keepRow): ?>
<form method="post" class="card" onsubmit="return confirm('Move everything of the ticked parties into <?= e(addslashes($keepRow['name'])) ?>?')">
  <?= csrf_field() ?><input type="hidden" name="do" value="merge"><input type="hidden" name="to" value="<?= (int)$keepRow['id'] ?>">
  <h3>Keep</h3>
  <?= $card($keepRow, '<span class="badge badge-ok">stays</span>') ?>
  <h3 class="mt">Move into it</h3>
  <?php foreach ($fromRows as $fr): ?>
  <label class="check-inline" style="display:flex;gap:8px;align-items:flex-start;margin:8px 0"><input type="checkbox" name="from[]" value="<?= (int)$fr['id'] ?>" checked>
    <?= $card($fr, '<span class="badge badge-bad">will close</span>') ?></label>
  <?php endforeach; ?>
  <div class="form-row cols-2 mt">
    <div><label>Add another party to move in</label>
      <select onchange="if(this.value)location.href='party_merge.php?keep=<?= (int)$keepRow['id'] ?>&from=<?= e(implode(',', $fromIds)) ?>,'+this.value">
        <option value="">-- pick --</option>
        <?php foreach ($allParties as $ap): if ((int)$ap['id'] === (int)$keepRow['id'] || in_array((int)$ap['id'], $fromIds, true)) continue; ?>
        <option value="<?= (int)$ap['id'] ?>"><?= e($ap['name']) ?> <?= e($ap['mobile']) ?></option><?php endforeach; ?>
      </select></div>
  </div>
  <?php if ($fromRows): ?><button class="btn mt" type="submit">🔀 Merge into <?= e($keepRow['name']) ?></button><?php endif; ?>
  <a class="btn btn-muted mt" href="party_merge.php">Back</a>
</form>
<?php else: ?>
<div class="card">
  <h3>Same mobile number, saved more than once <span class="muted" style="font-weight:normal">· <?= count($dups) ?> group(s)</span></h3>
  <p class="muted">Press <strong>Keep this</strong> on the right one — the others in that group move into it (you see everything before anything moves).</p>
  <?php foreach ($dups as $grp): $ids = array_map(fn($x) => (int)$x['id'], $grp); ?>
  <div style="border-top:1px solid var(--border,#e5e7eb);padding:10px 0">
    <?php foreach ($grp as $gp): ?>
    <div style="display:flex;justify-content:space-between;gap:8px;align-items:center;margin:6px 0">
      <?= $card($gp, '') ?>
      <a class="btn btn-sm btn-outline" style="white-space:nowrap" href="party_merge.php?keep=<?= (int)$gp['id'] ?>&from=<?= implode(',', array_diff($ids, [(int)$gp['id']])) ?>">Keep this</a>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endforeach; if (!$dups): ?><p>✅ No two active parties share a mobile number.</p><?php endif; ?>
  <div class="form-row cols-2 mt">
    <div><label>Or pick the party to keep</label>
      <select onchange="if(this.value)location.href='party_merge.php?keep='+this.value">
        <option value="">-- pick --</option>
        <?php foreach ($allParties as $ap): ?><option value="<?= (int)$ap['id'] ?>"><?= e($ap['name']) ?> <?= e($ap['mobile']) ?></option><?php endforeach; ?>
      </select></div>
  </div>
</div>
<?php endif; ?>

<?php if ($history): ?>
<div class="card">
  <h3>Recent merges</h3>
  <div class="table-wrap" style="box-shadow:none"><table>
    <thead><tr><th>When</th><th>Moved</th><th>Into</th><th></th></tr></thead>
    <tbody><?php foreach ($history as $h): ?>
      <tr><td><?= dmyt($h['created_at']) ?><br><small class="muted"><?= e($h['by_name'] ?? '') ?></small></td>
        <td><?= e($h['from_name'] ?? '#' . $h['from_party_id']) ?></td><td><?= e($h['to_name'] ?? '#' . $h['to_party_id']) ?></td>
        <td><?php if ($h['undone_at']): ?><span class="muted">undone <?= dmyt($h['undone_at']) ?></span><?php else: ?>
          <form method="post" onsubmit="return confirm('Put <?= e(addslashes((string)$h['from_name'])) ?> and all its entries back?')"><?= csrf_field() ?>
            <input type="hidden" name="do" value="undo"><input type="hidden" name="id" value="<?= (int)$h['id'] ?>"><button class="btn btn-sm btn-outline" type="submit">↩ Undo</button></form>
        <?php endif; ?></td></tr>
    <?php endforeach; ?></tbody>
  </table></div>
</div>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
