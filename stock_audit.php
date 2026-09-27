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
    if (locked_location_id()) $locId = locked_location_id(); // godown/shop manager counts only their own place
    if (!$locId) { flash('Pick a location.', 'error'); redirect('stock_audit.php?action=new'); }
    // one OPEN sheet per location: starting a second one by mistake would
    // split the same physical count across two sheets - reuse the open one
    $already = row("SELECT id, count_no FROM stock_counts WHERE location_id = ? AND status = 'open' ORDER BY id DESC LIMIT 1", [$locId]);
    if ($already) {
        flash('An audit for this location ' . $already['count_no'] . ' is already open — that one has been opened. (To start a new one, post or cancel this first.)', 'error');
        redirect('stock_audit.php?action=count&id=' . $already['id']);
    }
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
        // Zero-stock items clutter a physical count sheet ("માનનીય એ Item
        // છે જ નહીં") - they're skipped unless the tick asks for them
        // (needed when hunting for stock the system doesn't know about).
        if (!$includeZero && abs($sysQ) < 0.001) continue;
        q('INSERT INTO stock_count_items (count_id, item_id, system_qty) VALUES (?,?,?)', [$cid, $iid, $sysQ]);
        $added++;
    }
    $pdo->commit();
    log_activity('stock_audit_start', doc_no('AUD', $cid));
    flash('Count sheet ' . doc_no('AUD', $cid) . ' started with ' . $added . ' item(s)' . ($includeZero ? '' : ' (zero-stock ones left out)') . '.');
    redirect('stock_audit.php?action=count&id=' . $cid);
}

// Delete a count sheet - ADMIN ONLY (cleaning up test sheets etc.). If the
// sheet was already posted, its cycle_count stock adjustments are reversed
// first, so deleting a test audit leaves stock exactly as before it.
// NET effect this audit still has on stock: its cycle_count adjustments
// minus any cycle_count_undo reversals already made (a sheet can be
// posted, re-opened and posted again - only the net may be reversed).
function audit_net_adjustments($cid) {
    return all("SELECT item_id, location_id, SUM(change_qty) q FROM stock_ledger
                WHERE ref_type IN ('cycle_count','cycle_count_undo') AND ref_id = ?
                GROUP BY item_id, location_id HAVING ABS(SUM(change_qty)) > 0.0001", [$cid]);
}

// Un-complete (re-open) a posted audit - ADMIN ONLY. The posted stock
// adjustments are reversed so stock is exactly as before posting, the
// sheet goes back to OPEN with every counted qty still filled in, and
// staff can view/edit and post again (or cancel) as if nothing happened.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'reopen') {
    if (!is_full_admin()) { flash('Only an admin can reopen an audit.', 'error'); redirect('stock_audit.php'); }
    $cid = (int)post('id');
    $count = row("SELECT * FROM stock_counts WHERE id = ? AND status = 'completed'", [$cid]);
    if (!$count) { flash('This audit is not completed.', 'error'); redirect('stock_audit.php'); }
    if (is_period_locked(today())) { flash(period_lock_message(), 'error'); redirect('stock_audit.php?action=count&id=' . $cid); }
    $pdo = db();
    $pdo->beginTransaction();
    $undone = 0;
    foreach (audit_net_adjustments($cid) as $n) {
        adjust_stock($n['item_id'], $n['location_id'], -(float)$n['q'], 'cycle_count_undo', $cid, 'Audit ' . $count['count_no'] . ' re-opened');
        $undone++;
    }
    q("UPDATE stock_counts SET status = 'open', completed_by = NULL, completed_at = NULL WHERE id = ?", [$cid]);
    $pdo->commit();
    log_activity('stock_audit_reopen', $count['count_no'] . ": $undone adjustment(s) reversed");
    flash('Audit ' . $count['count_no'] . ' reopened — ' . $undone . ' items had their stock changes reversed; stock is as it was. The counted quantities are unchanged and can be corrected and posted again.');
    redirect('stock_audit.php?action=count&id=' . $cid);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'delete_count') {
    if (!is_full_admin()) { flash('Only an admin can delete an audit.', 'error'); redirect('stock_audit.php'); }
    $cid = (int)post('id');
    $count = row('SELECT * FROM stock_counts WHERE id = ?', [$cid]);
    if ($count) {
        $pdo = db();
        $pdo->beginTransaction();
        foreach (audit_net_adjustments($cid) as $adj) {
            adjust_stock($adj['item_id'], $adj['location_id'], -(float)$adj['q'], 'cycle_count_undo', $cid, 'Audit ' . $count['count_no'] . ' deleted');
        }
        q('DELETE FROM stock_count_items WHERE count_id = ?', [$cid]);
        q('DELETE FROM stock_counts WHERE id = ?', [$cid]);
        $pdo->commit();
        log_activity('stock_audit_delete', $count['count_no']);
        flash('Audit ' . $count['count_no'] . ' Deleted' . ($count['status'] === 'posted' ? ' — its stock changes were reversed too' : '') . '.');
    }
    redirect('stock_audit.php');
}

// Change an OPEN sheet's location (staff picked the wrong one): the sheet
// moves to the new location and every line's system qty re-snapshots from
// THAT location's stock. Whatever was already counted stays entered.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'change_loc') {
    require_perm('stock_audit.edit');
    $cid = (int)post('id');
    $count = row('SELECT * FROM stock_counts WHERE id = ?', [$cid]);
    $newLoc = (int)post('location_id');
    if (locked_location_id()) { flash('Only an admin can change the location.', 'error'); redirect('stock_audit.php?action=count&id=' . $cid); }
    if (!$count || $count['status'] !== 'open' || !$newLoc) { flash('This count is not open.', 'error'); redirect('stock_audit.php'); }
    if ($newLoc !== (int)$count['location_id']) {
        $pdo = db();
        $pdo->beginTransaction();
        q('UPDATE stock_counts SET location_id = ? WHERE id = ?', [$newLoc, $cid]);
        foreach (all('SELECT id, item_id FROM stock_count_items WHERE count_id = ?', [$cid]) as $l) {
            q('UPDATE stock_count_items SET system_qty = ? WHERE id = ?', [stock_qty($l['item_id'], $newLoc), $l['id']]);
        }
        $pdo->commit();
        $ln = val('SELECT name FROM locations WHERE id = ?', [$newLoc]);
        log_activity('stock_audit_change_loc', $count['count_no'] . ' -> ' . $ln);
        flash('The audit is now "' . $ln . '"  — every item system quantity has been re-read for that location. The counted quantities are unchanged.');
    }
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
    $locations = all('SELECT * FROM locations WHERE is_active = 1' . (locked_location_id() ? ' AND id = ' . locked_location_id() : '') . ' ORDER BY name');
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
        <label class="check-inline mb"><input type="checkbox" name="include_zero" value="1"> Include items with zero stock in the list <span class="muted" style="font-weight:normal">(not normally needed — you count what is there; tick this only when looking for goods on the shelf that the system shows as 0)</span></label>
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
    if (locked_location_id() && (int)$count['location_id'] !== locked_location_id()) { flash('This audit is not for your location.', 'error'); redirect('stock_audit.php'); }
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
      <div style="background:linear-gradient(100deg,var(--primary,#1a56db),#6D28D9);color:#fff;border-radius:12px;padding:12px 16px;margin:8px 0;display:flex;align-items:center;gap:10px;flex-wrap:wrap">
        <span style="font-size:22px">📍</span>
        <span style="font-size:17px;font-weight:800">You "<?= e($count['loc_name']) ?>" stock are you counting</span>
        <span style="font-size:12.5px;opacity:.85">— the system quantity is for this location only; do not count goods from elsewhere</span>
        <?php if ($count['status'] === 'open' && can('stock_audit.edit') && !locked_location_id()): ?>
        <form method="post" style="margin-left:auto;display:flex;gap:6px;align-items:center" onsubmit="return confirm('Change the location? Every item system quantity is re-read for the new location (counted quantities stay).')">
          <?= csrf_field() ?><input type="hidden" name="do" value="change_loc"><input type="hidden" name="id" value="<?= $cid ?>">
          <select name="location_id" style="padding:6px 8px;border-radius:8px;border:0">
            <?php foreach (all('SELECT * FROM locations WHERE is_active = 1 ORDER BY name') as $l): ?>
            <option value="<?= $l['id'] ?>" <?= $l['id'] == $count['location_id'] ? 'selected' : '' ?>><?= e($l['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <button class="btn btn-sm" type="submit" style="background:rgba(255,255,255,.2);border:1px solid rgba(255,255,255,.5)">Change</button>
        </form>
        <?php endif; ?>
      </div>
      <p class="muted">started <?= dmyt($count['created_at']) ?><?= $count['notes'] ? ' · ' . e($count['notes']) : '' ?></p>
      <?php if ($zeroCount > 0 || $showZeros): ?>
      <p class="no-print"><a class="btn btn-sm btn-outline" href="stock_audit.php?action=count&id=<?= $cid ?><?= $showZeros ? '' : '&zeros=1' ?>">
        <?= $showZeros ? '🙈 Hide the zero ones again' : '👁 Zero-stock ones ' . $zeroCount . ' Show items too' ?></a></p>
      <?php endif; ?>
      <?php if ($count['status'] === 'completed'): ?>
      <p class="mt">Posted <?= dmyt($count['completed_at']) ?></p>
      <?php if (is_full_admin()): ?>
      <form method="post" class="mt no-print" onsubmit="return confirm('Audit <?= e($count['count_no']) ?> Reopen it? Every posted stock change is reversed (stock as it was), the counted quantities stay, and the sheet opens again.')">
        <?= csrf_field() ?><input type="hidden" name="do" value="reopen"><input type="hidden" name="id" value="<?= $cid ?>">
        <button class="btn btn-outline" type="submit">🔓 Un-complete (reopen)</button>
      </form>
      <?php endif; ?>
      <?php endif; ?>
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
$fLoc = (int)get('loc') ?: 0;
if (locked_location_id()) $fLoc = locked_location_id();
$llFilter = $fLoc ? ' WHERE sc.location_id = ' . $fLoc : '';
$counts = all('SELECT sc.*, l.name loc_name, s.name staff_name FROM stock_counts sc
               JOIN locations l ON l.id = sc.location_id JOIN users s ON s.id = sc.created_by
               ' . $llFilter . ' ORDER BY sc.id DESC LIMIT 100');
$allLocs = all('SELECT * FROM locations WHERE is_active = 1' . (locked_location_id() ? ' AND id = ' . locked_location_id() : '') . ' ORDER BY name');
$page_title = 'Stock Audit / Cycle Counting';
include __DIR__ . '/includes/header.php';
?>
<div class="page-actions">
  <?php if (can('stock_audit.add')): ?><a class="btn" href="stock_audit.php?action=new">+ Start Count</a><?php endif; ?>
</div>
<div class="filterbar mb no-print" style="flex-wrap:wrap;gap:6px">
  <a class="btn btn-sm <?= !$fLoc ? '' : 'btn-outline' ?>" href="stock_audit.php">All locations</a>
  <?php foreach ($allLocs as $l):
      $open = row("SELECT id, count_no FROM stock_counts WHERE location_id = ? AND status = 'open' ORDER BY id DESC LIMIT 1", [$l['id']]); ?>
  <a class="btn btn-sm <?= $fLoc === (int)$l['id'] ? '' : 'btn-outline' ?>" href="stock_audit.php?loc=<?= $l['id'] ?>">📍 <?= e($l['name']) ?><?= $open ? ' · 🟢 on' : '' ?></a>
  <?php endforeach; ?>
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
      <td style="white-space:nowrap"><a class="btn btn-sm btn-outline" href="stock_audit.php?action=count&id=<?= $c['id'] ?>">Open</a>
        <?php if (is_full_admin()): ?>
        <form method="post" style="display:inline" onsubmit="return confirm('Audit <?= e($c['count_no']) ?> Delete it? If it was posted, its stock changes are reversed too.')">
          <?= csrf_field() ?><input type="hidden" name="do" value="delete_count"><input type="hidden" name="id" value="<?= $c['id'] ?>">
          <button class="btn btn-sm btn-danger" type="submit">✕</button>
        </form>
        <?php endif; ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$counts): ?><tr><td colspan="5" class="muted">No stock counts yet.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
