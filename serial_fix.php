<?php
// Serial <-> Stock repair tool (admin only). Lists every serial-tracked item
// whose "in stock" serial count disagrees with the stock quantity - the
// usual cause is a serial item billed WITHOUT picking serials (advance
// billing), which lowers stock but leaves the sold unit's serial lying
// "in stock". The owner checks the physical shelf and picks the fix:
//   - remove the serials that are NOT physically there (marked adjusted_out)
//   - or add the serial numbers of pieces that ARE there but untracked
//   - or set the stock figure to match the serials in one tap
require_once __DIR__ . '/includes/init.php';
require_login();
if (!is_full_admin()) { http_response_code(403); die('Admin only.'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'remove_serials') {
    $iid = (int)post('item_id');
    $picked = array_filter(array_map('intval', (array)post('serial_ids', [])));
    $n = 0;
    foreach ($picked as $sidRow) {
        $n += q("UPDATE item_serials SET status = 'adjusted_out' WHERE id = ? AND item_id = ? AND status = 'in_stock'", [$sidRow, $iid])->rowCount();
    }
    log_activity('serial_fix_remove', "item=$iid removed=$n");
    flash($n ? "$n serials taken out of stock (adjusted out) — the stock figure has not changed." : 'No serial was selected.', $n ? 'success' : 'error');
    redirect('serial_fix.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'add_serials') {
    $iid = (int)post('item_id');
    $locId = stock_home_location(0, $iid);
    $n = 0; $already = 0; $restored = []; $liveBack = [];
    foreach (array_filter(array_map('trim', preg_split('/[\r\n,]+/', (string)post('serials')))) as $sn) {
        // serial_put_in_stock() looks the row up WITHOUT a status filter. The
        // old code here checked only for 'in_stock', so a serial already on
        // record as sold (or adjusted out, or with staff) got an INSERT and
        // broke uk_serial - the whole page died with a duplicate-entry error.
        $r = serial_put_in_stock($iid, $sn, $locId);
        if ($r['result'] === 'added') $n++;
        elseif ($r['result'] === 'already') $already++;
        elseif ($r['result'] === 'restored') {
            $restored[] = $sn;
            // it was somewhere real a moment ago - say so instead of quietly
            // detaching a customer's unit from their bill
            if (in_array($r['from'], serial_live_statuses(), true)) $liveBack[] = $sn . ' (' . $r['from'] . ')';
        }
    }
    log_activity('serial_fix_add', "item=$iid added=$n restored=" . count($restored) . ($liveBack ? ' live=' . implode('|', $liveBack) : ''));
    $bits = [];
    if ($n) $bits[] = "$n new serials added";
    if ($restored) $bits[] = count($restored) . ' serials taken back into stock';
    if ($already) $bits[] = "$already were already in stock";
    $msg = $bits ? implode(', ', $bits) . ' — the stock figure has not changed.' : 'There was no new serial.';
    if ($liveBack) $msg .= ' ⚠️ These serials were recorded elsewhere and have now been taken into stock: ' . implode(', ', $liveBack) . '. The link to the bill has been cut — please check.';
    flash($msg, $bits ? 'success' : 'error');
    redirect('serial_fix.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'match_stock') {
    $iid = (int)post('item_id');
    $serialCount = (int)val("SELECT COUNT(*) FROM item_serials WHERE item_id = ? AND status = 'in_stock'", [$iid]);
    $stockNow = (float)(val('SELECT SUM(qty) FROM stock WHERE item_id = ?', [$iid]) ?? 0);
    $delta = $serialCount - $stockNow;
    if (abs($delta) > 0.001) {
        adjust_stock($iid, stock_home_location(0, $iid), $delta, 'serial_fix', null, 'Stock matched to in-stock serial count');
        log_activity('serial_fix_match', "item=$iid delta=$delta");
        flash('Stock ' . money($stockNow) . ' → ' . $serialCount . ' done (as per the serials).');
    } else {
        flash('Stock already agrees with the serials.');
    }
    redirect('serial_fix.php');
}

$items = all("SELECT i.id, i.name,
    COALESCE((SELECT SUM(qty) FROM stock st WHERE st.item_id = i.id), 0) stock_qty,
    (SELECT COUNT(*) FROM item_serials s2 WHERE s2.item_id = i.id AND s2.status = 'in_stock') serial_cnt
    FROM items i WHERE i.serial_tracked = 1 AND i.is_active = 1
    HAVING ABS(stock_qty - serial_cnt) > 0 ORDER BY i.name");
$page_title = 'Serial / Stock Repair';
include __DIR__ . '/includes/header.php';
?>
<div class="card">
  <h2>🔧 Serial ↔ Stock Repair</h2>
  <p class="muted">for items where "in stock" Everything where the serial count and the stock figure disagree is here. <strong>Check the physical shelf first</strong>, then decide:<br>
  · serials really in the shop <strong>no</strong> (already sold) → tick them and <em>Remove</em> Do<br>
  · goods on the shelf whose serial was not recorded <strong>no</strong> → type or scan the serials below and <em>Add</em> Do<br>
  · the serial list is right and the figure is wrong → <em>Match stock to serials</em> press it (stock is adjusted, with a note in the ledger)</p>
</div>
<?php if (!$items): ?>
<div class="card"><p>✅ For every serial-tracked item the serials and the stock figure agree — nothing to fix.</p></div>
<?php endif; ?>
<?php foreach ($items as $it):
    $serials = all("SELECT id, serial_no, created_at FROM item_serials WHERE item_id = ? AND status = 'in_stock' ORDER BY id", [$it['id']]); ?>
<div class="card">
  <h3><a href="item_view.php?id=<?= $it['id'] ?>"><?= e($it['name']) ?></a></h3>
  <p>Stock figure: <strong><?= 0 + $it['stock_qty'] ?></strong> · In-stock serials: <strong><?= (int)$it['serial_cnt'] ?></strong>
    <span class="badge badge-bad">Diff: <?= 0 + ($it['serial_cnt'] - $it['stock_qty']) ?></span></p>
  <?php if ($serials): ?>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="remove_serials"><input type="hidden" name="item_id" value="<?= $it['id'] ?>">
    <div class="sp-list" style="max-height:180px;overflow:auto">
      <?php foreach ($serials as $s): ?>
      <label class="sp-row"><input type="checkbox" name="serial_ids[]" value="<?= $s['id'] ?>"> <?= e($s['serial_no']) ?> <span class="muted">(<?= dmy($s['created_at']) ?>)</span></label>
      <?php endforeach; ?>
    </div>
    <button class="btn btn-sm btn-danger mt" type="submit" onclick="return confirm('The ticked serials will be taken out of stock (adjusted out). Continue?')">🗑 Remove the ticked serials</button>
  </form>
  <?php endif; ?>
  <form method="post" class="mt">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="add_serials"><input type="hidden" name="item_id" value="<?= $it['id'] ?>">
    <label>Add the missing serials (one per line — a barcode gun works too)</label>
    <textarea name="serials" rows="2" placeholder="SN001&#10;SN002"></textarea>
    <button class="btn btn-sm mt" type="submit">➕ Add serials</button>
  </form>
  <form method="post" class="mt">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="match_stock"><input type="hidden" name="item_id" value="<?= $it['id'] ?>">
    <button class="btn btn-sm btn-outline" type="submit" onclick="return confirm('Stock figure <?= (int)$it['serial_cnt'] ?> set it (as per the serials)?')">⚖️ Match stock to serials (<?= 0 + $it['stock_qty'] ?> → <?= (int)$it['serial_cnt'] ?>)</button>
  </form>
</div>
<?php endforeach; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
