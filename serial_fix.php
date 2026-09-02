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

function serial_fix_loc($itemId) {
    // where an adjustment lands: the location already holding this item's
    // stock (largest first), else the first active location
    return (int)(val('SELECT location_id FROM stock WHERE item_id = ? ORDER BY qty DESC LIMIT 1', [$itemId])
        ?: val('SELECT id FROM locations WHERE is_active = 1 ORDER BY id LIMIT 1'));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'remove_serials') {
    $iid = (int)post('item_id');
    $picked = array_filter(array_map('intval', (array)post('serial_ids', [])));
    $n = 0;
    foreach ($picked as $sidRow) {
        $n += q("UPDATE item_serials SET status = 'adjusted_out' WHERE id = ? AND item_id = ? AND status = 'in_stock'", [$sidRow, $iid])->rowCount();
    }
    log_activity('serial_fix_remove', "item=$iid removed=$n");
    flash($n ? "$n સિરિયલ સ્ટોકમાંથી કાઢ્યા (adjusted out) — સ્ટોકનો આંકડો બદલાયો નથી." : 'કોઈ સિરિયલ પસંદ નહોતો.', $n ? 'success' : 'error');
    redirect('serial_fix.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'add_serials') {
    $iid = (int)post('item_id');
    $locId = serial_fix_loc($iid);
    $n = 0;
    foreach (array_filter(array_map('trim', preg_split('/[\r\n,]+/', (string)post('serials')))) as $sn) {
        $dupe = val("SELECT id FROM item_serials WHERE item_id = ? AND serial_no = ? AND status = 'in_stock'", [$iid, $sn]);
        if ($dupe) continue; // already in stock - never create a double
        q('INSERT INTO item_serials (item_id, serial_no, location_id, status, warranty_months) VALUES (?,?,?,\'in_stock\', COALESCE((SELECT warranty_months FROM items WHERE id = ?),0))', [$iid, $sn, $locId, $iid]);
        $n++;
    }
    log_activity('serial_fix_add', "item=$iid added=$n");
    flash($n ? "$n સિરિયલ સ્ટોકમાં ઉમેર્યા — સ્ટોકનો આંકડો બદલાયો નથી." : 'કોઈ નવો સિરિયલ નહોતો.', $n ? 'success' : 'error');
    redirect('serial_fix.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'match_stock') {
    $iid = (int)post('item_id');
    $serialCount = (int)val("SELECT COUNT(*) FROM item_serials WHERE item_id = ? AND status = 'in_stock'", [$iid]);
    $stockNow = (float)(val('SELECT SUM(qty) FROM stock WHERE item_id = ?', [$iid]) ?? 0);
    $delta = $serialCount - $stockNow;
    if (abs($delta) > 0.001) {
        adjust_stock($iid, serial_fix_loc($iid), $delta, 'serial_fix', null, 'Stock matched to in-stock serial count');
        log_activity('serial_fix_match', "item=$iid delta=$delta");
        flash('સ્ટોક ' . money($stockNow) . ' → ' . $serialCount . ' કરી દીધો (સિરિયલ પ્રમાણે).');
    } else {
        flash('સ્ટોક પહેલેથી સિરિયલ સાથે મળે છે.');
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
  <p class="muted">જે આઇટમમાં "in stock" સિરિયલની સંખ્યા અને સ્ટોકનો આંકડો જુદા છે એ બધી અહીં છે. <strong>પહેલા ફિઝિકલ શેલ્ફ જુઓ</strong>, પછી નક્કી કરો:<br>
  · જે સિરિયલ ખરેખર દુકાનમાં <strong>નથી</strong> (વેચાઈ ગયેલા) → ટિક કરીને <em>Remove</em> કરો<br>
  · જે માલ પડ્યો છે પણ સિરિયલ નોંધાયો <strong>નથી</strong> → નીચે સિરિયલ ટાઈપ/સ્કેન કરીને <em>Add</em> કરો<br>
  · સિરિયલ યાદી જ સાચી છે અને આંકડો ખોટો → <em>Match stock to serials</em> દબાવો (સ્ટોક એડજસ્ટ થાય છે, લેજરમાં નોંધ સાથે)</p>
</div>
<?php if (!$items): ?>
<div class="card"><p>✅ બધી સિરિયલવાળી આઇટમમાં સિરિયલ અને સ્ટોક બરાબર મળે છે — કંઈ સુધારવાનું નથી.</p></div>
<?php endif; ?>
<?php foreach ($items as $it):
    $serials = all("SELECT id, serial_no, created_at FROM item_serials WHERE item_id = ? AND status = 'in_stock' ORDER BY id", [$it['id']]); ?>
<div class="card">
  <h3><a href="item_view.php?id=<?= $it['id'] ?>"><?= e($it['name']) ?></a></h3>
  <p>સ્ટોકનો આંકડો: <strong><?= 0 + $it['stock_qty'] ?></strong> · In-stock સિરિયલ: <strong><?= (int)$it['serial_cnt'] ?></strong>
    <span class="badge badge-bad">ફેર: <?= 0 + ($it['serial_cnt'] - $it['stock_qty']) ?></span></p>
  <?php if ($serials): ?>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="remove_serials"><input type="hidden" name="item_id" value="<?= $it['id'] ?>">
    <div class="sp-list" style="max-height:180px;overflow:auto">
      <?php foreach ($serials as $s): ?>
      <label class="sp-row"><input type="checkbox" name="serial_ids[]" value="<?= $s['id'] ?>"> <?= e($s['serial_no']) ?> <span class="muted">(<?= dmy($s['created_at']) ?>)</span></label>
      <?php endforeach; ?>
    </div>
    <button class="btn btn-sm btn-danger mt" type="submit" onclick="return confirm('ટિક કરેલા સિરિયલ સ્ટોકમાંથી નીકળી જશે (adjusted out). ચાલુ રાખવું?')">🗑 ટિક કરેલા સિરિયલ Remove કરો</button>
  </form>
  <?php endif; ?>
  <form method="post" class="mt">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="add_serials"><input type="hidden" name="item_id" value="<?= $it['id'] ?>">
    <label>ખૂટતા સિરિયલ ઉમેરો (એક લાઇનમાં એક — બારકોડ ગનથી સ્કેન પણ ચાલે)</label>
    <textarea name="serials" rows="2" placeholder="SN001&#10;SN002"></textarea>
    <button class="btn btn-sm mt" type="submit">➕ Add serials</button>
  </form>
  <form method="post" class="mt">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="match_stock"><input type="hidden" name="item_id" value="<?= $it['id'] ?>">
    <button class="btn btn-sm btn-outline" type="submit" onclick="return confirm('સ્ટોકનો આંકડો <?= (int)$it['serial_cnt'] ?> કરી દેવો (સિરિયલ પ્રમાણે)?')">⚖️ Match stock to serials (<?= 0 + $it['stock_qty'] ?> → <?= (int)$it['serial_cnt'] ?>)</button>
  </form>
</div>
<?php endforeach; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
