<?php
// Staff panel: stock in my hand, accept handovers with OTP, return stock to shop
require_once __DIR__ . '/includes/init.php';
require_login();
$u = current_user();

// ---------- create return (staff -> shop) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'return') {
    $loc_id = (int)post('location_id') ?: $u['location_id'];
    $item_ids = post('item_id', []);
    $qtys = post('qty', []);
    $rows = [];
    foreach ($item_ids as $i => $iid) {
        $iid = (int)$iid; $qty = (float)($qtys[$i] ?? 0);
        if ($iid && $qty > 0) $rows[] = ['item_id' => $iid, 'qty' => $qty];
    }
    if (!$rows) { flash('Select items to return.', 'error'); redirect('my_stock.php'); }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $otp = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        q("INSERT INTO handovers (type, location_id, staff_id, status, otp, notes, created_by)
           VALUES ('return', ?, ?, 'pending', ?, ?, ?)", [$loc_id, $u['id'], $otp, post('notes'), $u['id']]);
        $hid = insert_id();
        q('UPDATE handovers SET handover_no = ? WHERE id = ?', [doc_no('HO', $hid), $hid]);
        foreach ($rows as $r) {
            if (staff_stock_qty($u['id'], $r['item_id']) < $r['qty']) {
                throw new Exception('You do not hold that much quantity.');
            }
            // serials the staff holds for this item, up to qty (serial-tracked returns whole units)
            $item = row('SELECT * FROM items WHERE id = ?', [$r['item_id']]);
            $serials = [];
            if ($item['serial_tracked']) {
                $serials = array_column(all("SELECT serial_no FROM item_serials WHERE item_id=? AND user_id=? AND status='with_staff' LIMIT " . (int)$r['qty'],
                    [$r['item_id'], $u['id']]), 'serial_no');
            }
            q('INSERT INTO handover_items (handover_id, item_id, qty, serials) VALUES (?,?,?,?)',
              [$hid, $r['item_id'], $r['qty'], $serials ? implode(',', $serials) : null]);
            adjust_staff_stock($u['id'], $r['item_id'], -$r['qty'], 'handover_return_out', $hid);
        }
        $pdo->commit();
        log_activity('handover_return', doc_no('HO', $hid));
        flash('Return created. Shop manager will accept it with OTP.');
    } catch (Exception $ex) {
        $pdo->rollBack();
        flash('Error: ' . $ex->getMessage(), 'error');
    }
    redirect('my_stock.php');
}

$myStock = all('SELECT ss.*, i.name, i.unit, i.serial_tracked FROM staff_stock ss
                JOIN items i ON i.id = ss.item_id WHERE ss.user_id = ? AND ss.qty > 0 ORDER BY i.name', [$u['id']]);
$mySerials = all("SELECT isr.serial_no, i.name FROM item_serials isr JOIN items i ON i.id = isr.item_id
                  WHERE isr.user_id = ? AND isr.status = 'with_staff' ORDER BY i.name", [$u['id']]);
$pending = all("SELECT h.*, l.name loc_name, c.name creator_name FROM handovers h
                JOIN locations l ON l.id = h.location_id
                JOIN users c ON c.id = h.created_by
                WHERE h.staff_id = ? AND h.status = 'pending' AND h.type = 'issue'
                ORDER BY h.id DESC", [$u['id']]);
$myReturns = all("SELECT h.* FROM handovers h WHERE h.staff_id = ? AND h.type = 'return' AND h.status = 'pending'", [$u['id']]);
$locations = all('SELECT * FROM locations WHERE is_active = 1 ORDER BY name');

$page_title = 'My Stock';
include __DIR__ . '/includes/header.php';
?>
<?php foreach ($pending as $h): $hitems = all('SELECT hi.*, i.name FROM handover_items hi JOIN items i ON i.id = hi.item_id WHERE hi.handover_id = ?', [$h['id']]); ?>
<div class="card">
  <h2>📥 Incoming handover <?= e($h['handover_no']) ?></h2>
  <p class="muted">From <?= e($h['loc_name']) ?> by <?= e($h['creator_name']) ?> · <?= dmyt($h['created_at']) ?></p>
  <ul style="margin:8px 0 12px 20px">
    <?php foreach ($hitems as $hi): ?><li><?= e($hi['name']) ?> — <?= (float)$hi['qty'] ?><?= $hi['serials'] ? ' (SN: ' . e($hi['serials']) . ')' : '' ?></li><?php endforeach; ?>
  </ul>
  <form method="post" action="handover.php" class="filterbar">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="accept">
    <input type="hidden" name="id" value="<?= $h['id'] ?>">
    <div><input type="text" name="otp" placeholder="OTP from WhatsApp" inputmode="numeric" maxlength="6" required></div>
    <button class="btn btn-success" type="submit">✔ Accept Stock</button>
  </form>
</div>
<?php endforeach; ?>

<div class="card">
  <h2>🎒 Stock in my hand</h2>
  <?php if (!$myStock): ?><p class="muted">No stock held right now.</p><?php else: ?>
  <div class="table-wrap" style="box-shadow:none">
    <table class="table-sm">
      <thead><tr><th>Item</th><th class="num">Qty</th></tr></thead>
      <tbody><?php foreach ($myStock as $s): ?>
        <tr><td><?= e($s['name']) ?></td><td class="num"><?= (float)$s['qty'] ?> <?= e($s['unit']) ?></td></tr>
      <?php endforeach; ?></tbody>
    </table>
  </div>
  <?php endif; ?>
  <?php if ($mySerials): ?>
    <p class="muted mt">Serial units with me: <?php foreach ($mySerials as $s) echo '<span class="badge badge-warn">' . e($s['name'] . ' ' . $s['serial_no']) . '</span> '; ?></p>
  <?php endif; ?>
</div>

<?php if ($myReturns): ?>
<div class="flash flash-info">↩️ You have <?= count($myReturns) ?> return(s) waiting for shop manager acceptance.</div>
<?php endif; ?>

<?php if ($myStock): ?>
<div class="card">
  <h2>↩️ Return stock to shop</h2>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="return">
    <div class="field"><label>Return to location</label>
      <select name="location_id">
        <?php foreach ($locations as $l): ?>
        <option value="<?= $l['id'] ?>" <?= $l['id'] == $u['location_id'] ? 'selected' : '' ?>><?= e($l['name']) ?></option>
        <?php endforeach; ?>
      </select></div>
    <?php foreach ($myStock as $n => $s): ?>
      <div class="form-row cols-2">
        <div>
          <label><?= e($s['name']) ?> (holding <?= (float)$s['qty'] ?>)</label>
          <input type="hidden" name="item_id[]" value="<?= $s['item_id'] ?>">
          <input type="number" step="any" min="0" max="<?= (float)$s['qty'] ?>" name="qty[]" value="0" placeholder="Qty to return">
        </div>
      </div>
    <?php endforeach; ?>
    <div class="field"><label>Notes</label><input type="text" name="notes" placeholder="e.g. Leftover from site work"></div>
    <button class="btn" type="submit">Create Return</button>
  </form>
</div>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
