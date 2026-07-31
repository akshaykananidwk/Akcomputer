<?php
// Stock Audit / Cycle Counting: start a count session for a location
// (snapshotting today's system qty per item), let staff enter what they
// physically counted, then post the variance as normal stock adjustments
// (adjust_stock with ref_type='cycle_count') so the audit trail stays in
// the same stock_ledger everything else already uses.
require_once __DIR__ . '/includes/init.php';
require_perm('stock_audit.view');
$u = current_user();
$action = get('action', 'list');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'start') {
    require_perm('stock_audit.add');
    $locId = (int)post('location_id');
    if (!$locId) { flash('Pick a location.', 'error'); redirect('stock_audit.php?action=new'); }
    $onlyLow = post('only_low') === '1';
    $includeZero = post('include_zero') === '1';
    $pdo = db();
    $pdo->beginTransaction();
    q('INSERT INTO stock_counts (location_id, notes, created_by) VALUES (?,?,?)', [$locId, post('notes'), $u['id']]);
    $cid = insert_id();
    q('UPDATE stock_counts SET count_no = ? WHERE id = ?', [doc_no('AUD', $cid), $cid]);
    $items = $onlyLow
        ? array_column(low_stock_items($locId), 'id')
        : array_column(all("SELECT id FROM items WHERE is_active = 1 AND item_type <> 'service' ORDER BY name"), 'id');
    $added = 0;
    foreach ($items as $iid) {
        $sysQ = stock_qty($iid, $locId);
        // Zero-stock items clutter a physical count sheet ("માનનીય એ આઇટમ
        // છે જ નહીં") - they're skipped unless the tick asks for them
        // (needed when hunting for stock the system doesn't know about).
        if (!$includeZero && abs($sysQ) < 0.001) continue;
        q('INSERT INTO stock_count_items (count_id, item_id, system_qty) VALUES (?,?,?)', [$cid, $iid, $sysQ]);
        $added++;
    }
    $pdo->commit();
    log_activity('stock_audit_start', doc_no('AUD', $cid));
    flash('Count sheet ' . doc_no('AUD', $cid) . ' started with ' . $added . ' item(s)' . ($includeZero ? '' : ' (ઝીરો-સ્ટોકવાળી બહાર રાખી)') . '.');
    redirect('stock_audit.php?action=count&id=' . $cid);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'save_counts') {
    require_perm('stock_audit.add');
    $cid = (int)post('count_id');
    $count = row('SELECT * FROM stock_counts WHERE id = ?', [$cid]);
    if (!$count || $count['status'] !== 'open') { flash('This count is not open.', 'error'); redirect('stock_audit.php'); }
    $counted = post('counted', []);
    foreach ($counted as $lineId => $val) {
        if ($val === '') continue;
        q('UPDATE stock_count_items SET counted_qty = ? WHERE id = ? AND count_id = ?', [(float)$val, (int)$lineId, $cid]);
    }
    flash('Counts saved.');
    redirect('stock_audit.php?action=count&id=' . $cid);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'post_variance') {
    require_perm('stock_audit.edit');
    $cid = (int)post('count_id');
    $count = row('SELECT * FROM stock_counts WHERE id = ?', [$cid]);
    if (!$count || $count['status'] !== 'open') { flash('This count is not open.', 'error'); redirect('stock_audit.php'); }
    if (is_period_locked(today())) { flash(period_lock_message(), 'error'); redirect('stock_audit.php?action=count&id=' . $cid); }
    $pdo = db();
    $pdo->beginTransaction();
    $lines = all('SELECT * FROM stock_count_items WHERE count_id = ? AND counted_qty IS NOT NULL', [$cid]);
    $posted = 0;
    foreach ($lines as $l) {
        $variance = (float)$l['counted_qty'] - (float)$l['system_qty'];
        if (abs($variance) > 0.009) {
            adjust_stock($l['item_id'], $count['location_id'], $variance, 'cycle_count', $cid, 'Stock audit ' . $count['count_no']);
            $posted++;
        }
    }
    q("UPDATE stock_counts SET status = 'completed', completed_by = ?, completed_at = NOW() WHERE id = ?", [$u['id'], $cid]);
    $pdo->commit();
    log_activity('stock_audit_post', $count['count_no'] . ': ' . $posted . ' adjustment(s)');
    flash('Count posted - ' . $posted . ' item(s) adjusted to match the physical count.');
    redirect('stock_audit.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'cancel') {
    require_perm('stock_audit.edit');
    $cid = (int)post('id');
    q("UPDATE stock_counts SET status = 'cancelled' WHERE id = ? AND status = 'open'", [$cid]);
    flash('Count sheet cancelled - no stock changes were made.');
    redirect('stock_audit.php');
}

if ($action === 'new') {
    require_perm('stock_audit.add');
    $locations = all('SELECT * FROM locations WHERE is_active = 1 ORDER BY name');
    $page_title = 'New Stock Count';
    include __DIR__ . '/includes/header.php';
    ?>
    <div class="card">
      <h2>🔢 Start a stock count</h2>
      <p class="muted mb">Snapshots today's system quantity for every item at the chosen location, then lets you enter what's physically on the shelf. Posting only adjusts the items where the two numbers actually differ.</p>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="start">
        <div class="form-row cols-2">
          <div><label>Location *</label>
            <select name="location_id" required>
              <option value="">-- select --</option>
              <?php foreach ($locations as $l): ?><option value="<?= $l['id'] ?>"><?= e($l['name']) ?></option><?php endforeach; ?>
            </select></div>
          <div><label>Notes</label><input type="text" name="notes" placeholder="e.g. Monthly audit - July"></div>
        </div>
        <label class="check-inline mb"><input type="checkbox" name="only_low" value="1"> Only items currently below minimum stock (faster spot-check instead of a full count)</label>
        <label class="check-inline mb"><input type="checkbox" name="include_zero" value="1"> ઝીરો-સ્ટોકવાળી આઇટમ પણ યાદીમાં લેવી <span class="muted" style="font-weight:normal">(સામાન્ય રીતે જરૂર નથી — જે માલ છે એ જ ગણવાનો હોય; સિસ્ટમમાં 0 હોય પણ શેલ્ફ પર માલ મળે એ શોધવું હોય ત્યારે જ ટિક કરો)</span></label>
        <button class="btn" type="submit">Start Count</button>
        <a class="btn btn-muted" href="stock_audit.php">Back</a>
      </form>
    </div>
    <?php
    include __DIR__ . '/includes/footer.php';
    exit;
}

if ($action === 'count') {
    $cid = (int)get('id');
    $count = row('SELECT sc.*, l.name loc_name FROM stock_counts sc JOIN locations l ON l.id = sc.location_id WHERE sc.id = ?', [$cid]);
    if (!$count) { flash('Count sheet not found.', 'error'); redirect('stock_audit.php'); }
    $lines = all('SELECT sci.*, i.name, i.unit FROM stock_count_items sci JOIN items i ON i.id = sci.item_id WHERE sci.count_id = ? ORDER BY i.name', [$cid]);
    // old count sheets made before the zero-skip existed: same relief via a
    // view-time toggle (?zeros=1 shows them back)
    $showZeros = get('zeros') === '1';
    $zeroCount = count(array_filter($lines, fn($l) => abs((float)$l['system_qty']) < 0.001 && $l['counted_qty'] === null));
    if (!$showZeros) {
        $lines = array_values(array_filter($lines, fn($l) => abs((float)$l['system_qty']) >= 0.001 || $l['counted_qty'] !== null));
    }
    $variances = array_filter($lines, fn($l) => $l['counted_qty'] !== null && abs((float)$l['counted_qty'] - (float)$l['system_qty']) > 0.009);
    $page_title = $count['count_no'];
    include __DIR__ . '/includes/header.php';
    ?>
    <div class="card">
      <h2><?= e($count['count_no']) ?> <?= status_badge($count['status']) ?></h2>
      <p class="muted">📍 <?= e($count['loc_name']) ?> · started <?= dmyt($count['created_at']) ?><?= $count['notes'] ? ' · ' . e($count['notes']) : '' ?></p>
      <?php if ($zeroCount > 0 || $showZeros): ?>
      <p class="no-print"><a class="btn btn-sm btn-outline" href="stock_audit.php?action=count&id=<?= $cid ?><?= $showZeros ? '' : '&zeros=1' ?>">
        <?= $showZeros ? '🙈 ઝીરોવાળી પાછી છુપાવો' : '👁 ઝીરો-સ્ટોકવાળી ' . $zeroCount . ' આઇટમ પણ દેખાડો' ?></a></p>
      <?php endif; ?>
      <?php if ($count['status'] === 'completed'): ?><p class="mt">Posted <?= dmyt($count['completed_at']) ?></p><?php endif; ?>
    </div>

    <?php if ($count['status'] === 'open'): ?>
    <div class="card">
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="save_counts">
        <input type="hidden" name="count_id" value="<?= $cid ?>">
        <div class="table-wrap">
        <table class="table-sm">
          <thead><tr><th>Item</th><th class="num">System qty</th><th class="num">Counted qty</th><th class="num">Variance</th></tr></thead>
          <tbody>
          <?php foreach ($lines as $l):
              $variance = $l['counted_qty'] !== null ? (float)$l['counted_qty'] - (float)$l['system_qty'] : null; ?>
          <tr>
            <td><?= e($l['name']) ?></td>
            <td class="num"><?= (float)$l['system_qty'] ?> <?= e($l['unit']) ?></td>
            <td class="num"><input type="number" step="any" name="counted[<?= $l['id'] ?>]" value="<?= e($l['counted_qty'] ?? '') ?>" style="width:100px;text-align:right"></td>
            <td class="num" style="color:<?= $variance === null ? 'inherit' : ($variance == 0 ? 'var(--ok)' : 'var(--bad)') ?>"><?= $variance === null ? '-' : ($variance > 0 ? '+' : '') . $variance ?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        </div>
        <button class="btn mt" type="submit">💾 Save Counts</button>
      </form>
      <?php if (can('stock_audit.edit')): ?>
      <div class="page-actions mt" style="margin:10px 0 0">
        <form method="post" onsubmit="return confirm('Post <?= count($variances) ?> variance(s) as stock adjustments? This cannot be undone.')">
          <?= csrf_field() ?><input type="hidden" name="do" value="post_variance"><input type="hidden" name="count_id" value="<?= $cid ?>">
          <button class="btn btn-success" type="submit">✅ Post Variances (<?= count($variances) ?>)</button>
        </form>
        <form method="post" onsubmit="return confirm('Cancel this count sheet? No stock changes will be made.')">
          <?= csrf_field() ?><input type="hidden" name="do" value="cancel"><input type="hidden" name="id" value="<?= $cid ?>">
          <button class="btn btn-outline btn-danger" type="submit">Cancel Count</button>
        </form>
      </div>
      <?php endif; ?>
    </div>
    <?php else: ?>
    <div class="card">
      <h3>Result</h3>
      <div class="table-wrap">
      <table class="table-sm">
        <thead><tr><th>Item</th><th class="num">System qty</th><th class="num">Counted qty</th><th class="num">Variance</th></tr></thead>
        <tbody>
        <?php foreach ($lines as $l):
            if ($l['counted_qty'] === null) continue;
            $variance = (float)$l['counted_qty'] - (float)$l['system_qty']; ?>
        <tr>
          <td><?= e($l['name']) ?></td><td class="num"><?= (float)$l['system_qty'] ?></td><td class="num"><?= (float)$l['counted_qty'] ?></td>
          <td class="num" style="color:<?= $variance == 0 ? 'var(--ok)' : 'var(--bad)' ?>"><?= ($variance > 0 ? '+' : '') . $variance ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    </div>
    <?php endif; ?>
    <?php
    include __DIR__ . '/includes/footer.php';
    exit;
}

// ---------- list ----------
$counts = all('SELECT sc.*, l.name loc_name, s.name staff_name FROM stock_counts sc
               JOIN locations l ON l.id = sc.location_id JOIN users s ON s.id = sc.created_by
               ORDER BY sc.id DESC LIMIT 100');
$page_title = 'Stock Audit / Cycle Counting';
include __DIR__ . '/includes/header.php';
?>
<div class="page-actions">
  <?php if (can('stock_audit.add')): ?><a class="btn" href="stock_audit.php?action=new">+ Start Count</a><?php endif; ?>
</div>
<div class="table-wrap">
<table>
  <thead><tr><th>No</th><th>Location</th><th>Started by</th><th>Status</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($counts as $c): ?>
    <tr>
      <td><strong><?= e($c['count_no']) ?></strong><br><span class="muted"><?= dmy($c['created_at']) ?></span></td>
      <td><?= e($c['loc_name']) ?></td>
      <td><?= e($c['staff_name']) ?></td>
      <td><?= status_badge($c['status']) ?></td>
      <td><a class="btn btn-sm btn-outline" href="stock_audit.php?action=count&id=<?= $c['id'] ?>">Open</a></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$counts): ?><tr><td colspan="5" class="muted">No stock counts yet.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
