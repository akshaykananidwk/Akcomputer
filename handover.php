<?php
// Stock handover: issue to staff / return from staff / branch transfer - with WhatsApp OTP
require_once __DIR__ . '/includes/init.php';
require_perm('handover.view');
$u = current_user();
$action = get('action', 'list');

// ---------- create ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'save') {
    require_perm('handover.add');
    $type = post('type', 'issue'); // issue | transfer
    $loc_id = (int)post('location_id') ?: $u['location_id'];
    $staff_id = (int)post('staff_id');
    $to_loc = (int)post('to_location_id');
    $item_ids = post('item_id', []);
    $qtys = post('qty', []);
    $serialSel = post('serial_sel', []);

    $rows = [];
    foreach ($item_ids as $i => $iid) {
        $iid = (int)$iid; $qty = (float)($qtys[$i] ?? 0);
        if ($iid && $qty > 0) $rows[] = ['item_id' => $iid, 'qty' => $qty, 'n' => $i + 1];
    }
    if (!$rows || ($type === 'issue' && !$staff_id) || ($type === 'transfer' && !$to_loc)) {
        flash('Fill all required fields and add items.', 'error');
        redirect('handover.php?action=new');
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $staff = $staff_id ? row('SELECT * FROM users WHERE id = ?', [$staff_id]) : null;
        $otp = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $otp_mobile = $type === 'issue' ? ($staff['mobile'] ?? '') : '';
        q('INSERT INTO handovers (type, location_id, to_location_id, staff_id, status, otp, otp_mobile, notes, created_by)
           VALUES (?,?,?,?,?,?,?,?,?)',
          [$type, $loc_id, $to_loc ?: null, $staff_id ?: null, 'pending', $otp, $otp_mobile, post('notes'), $u['id']]);
        $hid = insert_id();
        q('UPDATE handovers SET handover_no = ? WHERE id = ?', [doc_no('HO', $hid), $hid]);

        foreach ($rows as $r) {
            $item = row('SELECT * FROM items WHERE id = ?', [$r['item_id']]);
            $serials = array_values(array_filter(array_map('trim', (array)($serialSel[$r['n']] ?? []))));
            if ($item['serial_tracked'] && count($serials) != $r['qty']) {
                throw new Exception("Select {$r['qty']} serial(s) for {$item['name']}.");
            }
            if (stock_qty($r['item_id'], $loc_id) < $r['qty']) {
                throw new Exception("Not enough stock of {$item['name']}.");
            }
            q('INSERT INTO handover_items (handover_id, item_id, qty, serials) VALUES (?,?,?,?)',
              [$hid, $r['item_id'], $r['qty'], $serials ? implode(',', $serials) : null]);
            // reserve: remove from source location immediately
            adjust_stock($r['item_id'], $loc_id, -$r['qty'], 'handover_out', $hid);
            foreach ($serials as $sn) {
                $upd = q("UPDATE item_serials SET status='with_staff', user_id=?, location_id=NULL
                          WHERE item_id=? AND serial_no=? AND status='in_stock' AND location_id=?",
                         [$staff_id ?: null, $r['item_id'], $sn, $loc_id]);
                if ($upd->rowCount() === 0) throw new Exception("Serial $sn not available.");
            }
        }
        $pdo->commit();

        if ($type === 'issue' && $staff && $staff['mobile']) {
            send_whatsapp($staff['mobile'],
                '*' . setting('app_name', 'AK Computer') . "*\nStock handover " . doc_no('HO', $hid) .
                " is ready for you.\nAccept it in your panel with OTP: *$otp*\nDo not share this OTP.");
        }
        log_activity('handover_add', doc_no('HO', $hid) . " type=$type");
        flash('Handover created' . ($type === 'issue' ? ' - OTP sent on staff WhatsApp.' : '.'));
        redirect('handover.php?action=view&id=' . $hid);
    } catch (Exception $ex) {
        $pdo->rollBack();
        flash('Error: ' . $ex->getMessage(), 'error');
        redirect('handover.php?action=new');
    }
}

// ---------- accept (OTP verify) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'accept') {
    $h = row("SELECT * FROM handovers WHERE id = ? AND status = 'pending'", [(int)post('id')]);
    if (!$h) { flash('Handover not found or already processed.', 'error'); redirect('handover.php'); }

    $eligible = ($h['type'] === 'issue' && $h['staff_id'] == $u['id'])
             || ($h['type'] !== 'issue' && can('handover.accept'))
             || ($h['type'] === 'return' && can('handover.accept'));
    if (!$eligible) { flash('You cannot accept this handover.', 'error'); redirect('handover.php'); }

    if (!hash_equals($h['otp'], trim(post('otp')))) {
        flash('Wrong OTP.', 'error');
        redirect(($h['type'] === 'issue' && $h['staff_id'] == $u['id']) ? 'my_stock.php' : 'handover.php?action=view&id=' . $h['id']);
    }

    $pdo = db();
    $pdo->beginTransaction();
    foreach (all('SELECT * FROM handover_items WHERE handover_id = ?', [$h['id']]) as $hi) {
        if ($h['type'] === 'issue') {
            adjust_staff_stock($h['staff_id'], $hi['item_id'], (float)$hi['qty'], 'handover_accept', $h['id']);
        } elseif ($h['type'] === 'return') {
            adjust_stock($hi['item_id'], $h['location_id'], (float)$hi['qty'], 'handover_return', $h['id']);
            if ($hi['serials']) foreach (explode(',', $hi['serials']) as $sn) {
                q("UPDATE item_serials SET status='in_stock', user_id=NULL, location_id=? WHERE item_id=? AND serial_no=?",
                  [$h['location_id'], $hi['item_id'], trim($sn)]);
            }
        } elseif ($h['type'] === 'transfer') {
            adjust_stock($hi['item_id'], $h['to_location_id'], (float)$hi['qty'], 'transfer_in', $h['id']);
            if ($hi['serials']) foreach (explode(',', $hi['serials']) as $sn) {
                q("UPDATE item_serials SET status='in_stock', user_id=NULL, location_id=? WHERE item_id=? AND serial_no=?",
                  [$h['to_location_id'], $hi['item_id'], trim($sn)]);
            }
        }
    }
    q("UPDATE handovers SET status='accepted', accepted_at=NOW(), accepted_by=? WHERE id=?", [$u['id'], $h['id']]);
    $pdo->commit();
    log_activity('handover_accept', $h['handover_no']);
    flash('Handover ' . $h['handover_no'] . ' accepted ✔');
    redirect($h['type'] === 'issue' ? 'my_stock.php' : 'handover.php');
}

// ---------- send OTP to me (for return/transfer acceptance) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'otp_me') {
    $h = row("SELECT * FROM handovers WHERE id = ? AND status = 'pending'", [(int)post('id')]);
    if ($h && can('handover.accept') && $u['mobile']) {
        send_whatsapp($u['mobile'], '*' . setting('app_name', 'AK Computer') . "*\nOTP to accept " . $h['handover_no'] . ": *{$h['otp']}*");
        flash('OTP sent to your WhatsApp.');
    } else {
        flash('Cannot send OTP (check your mobile number).', 'error');
    }
    redirect('handover.php?action=view&id=' . (int)post('id'));
}

// ---------- resend OTP to staff ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'otp_resend') {
    $h = row("SELECT h.*, s.mobile staff_mobile FROM handovers h LEFT JOIN users s ON s.id = h.staff_id
              WHERE h.id = ? AND h.status = 'pending'", [(int)post('id')]);
    if ($h && $h['staff_mobile']) {
        send_whatsapp($h['staff_mobile'], '*' . setting('app_name', 'AK Computer') . "*\nOTP for handover " . $h['handover_no'] . ": *{$h['otp']}*");
        flash('OTP re-sent.');
    }
    redirect('handover.php?action=view&id=' . (int)post('id'));
}

// ---------- cancel ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'cancel') {
    require_perm('handover.add');
    $h = row("SELECT * FROM handovers WHERE id = ? AND status = 'pending'", [(int)post('id')]);
    if ($h) {
        $pdo = db();
        $pdo->beginTransaction();
        foreach (all('SELECT * FROM handover_items WHERE handover_id = ?', [$h['id']]) as $hi) {
            if ($h['type'] === 'return') {
                adjust_staff_stock((int)$h['staff_id'], $hi['item_id'], (float)$hi['qty'], 'handover_cancel', $h['id']);
                if ($hi['serials']) foreach (explode(',', $hi['serials']) as $sn)
                    q("UPDATE item_serials SET status='with_staff', user_id=?, location_id=NULL WHERE item_id=? AND serial_no=?",
                      [$h['staff_id'], $hi['item_id'], trim($sn)]);
            } else {
                adjust_stock($hi['item_id'], $h['location_id'], (float)$hi['qty'], 'handover_cancel', $h['id']);
                if ($hi['serials']) foreach (explode(',', $hi['serials']) as $sn)
                    q("UPDATE item_serials SET status='in_stock', user_id=NULL, location_id=? WHERE item_id=? AND serial_no=?",
                      [$h['location_id'], $hi['item_id'], trim($sn)]);
            }
        }
        q("UPDATE handovers SET status='cancelled' WHERE id=?", [$h['id']]);
        $pdo->commit();
        flash('Handover cancelled, stock restored.');
    }
    redirect('handover.php');
}

$locations = all('SELECT * FROM locations WHERE is_active = 1 ORDER BY name');

// ---------- new form ----------
if ($action === 'new') {
    require_perm('handover.add');
    $staffList = all('SELECT id, name, mobile FROM users WHERE is_active = 1 ORDER BY name');
    $page_title = 'New Handover / Transfer';
    include __DIR__ . '/includes/header.php';
    ?>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="save">
      <div class="card">
        <div class="form-row cols-4">
          <div><label>Type</label>
            <select name="type" id="ho_type" onchange="hoType()">
              <option value="issue">Issue to staff</option>
              <option value="transfer">Transfer to branch/godown</option>
            </select></div>
          <div><label>From location</label>
            <select name="location_id" id="location_id">
              <?php foreach ($locations as $l): ?>
              <option value="<?= $l['id'] ?>" <?= $l['id'] == $u['location_id'] ? 'selected' : '' ?>><?= e($l['name']) ?></option>
              <?php endforeach; ?>
            </select></div>
          <div id="staffBox"><label>Give to staff</label>
            <select name="staff_id">
              <option value="">-- select staff --</option>
              <?php foreach ($staffList as $s): ?><option value="<?= $s['id'] ?>"><?= e($s['name']) ?> (<?= e($s['mobile']) ?>)</option><?php endforeach; ?>
            </select></div>
          <div id="toLocBox" style="display:none"><label>To location</label>
            <select name="to_location_id">
              <option value="">-- select --</option>
              <?php foreach ($locations as $l): ?><option value="<?= $l['id'] ?>"><?= e($l['name']) ?></option><?php endforeach; ?>
            </select></div>
        </div>
        <div class="field"><label>Notes</label><input type="text" name="notes" placeholder="e.g. Site fitting material"></div>
      </div>
      <div class="card">
        <h3>Items</h3>
        <div class="bill-items" id="billItems"></div>
        <button type="button" class="btn btn-outline btn-sm" id="addRowBtn">+ Add item</button>
      </div>
      <button class="btn btn-block" type="submit">Create & Send OTP</button>
    </form>
    <script>
      Bill.init({mode: 'sale', serials: true, locSel: 'location_id', gst: false});
      function hoType() {
        var t = document.getElementById('ho_type').value;
        document.getElementById('staffBox').style.display = t === 'issue' ? '' : 'none';
        document.getElementById('toLocBox').style.display = t === 'transfer' ? '' : 'none';
      }
    </script>
    <?php
    include __DIR__ . '/includes/footer.php';
    exit;
}

// ---------- view ----------
if ($action === 'view') {
    $h = row('SELECT h.*, l.name loc_name, l2.name to_loc_name, s.name staff_name, c.name creator_name
              FROM handovers h
              JOIN locations l ON l.id = h.location_id
              LEFT JOIN locations l2 ON l2.id = h.to_location_id
              LEFT JOIN users s ON s.id = h.staff_id
              JOIN users c ON c.id = h.created_by
              WHERE h.id = ?', [(int)get('id')]);
    if (!$h) die('Not found');
    $hitems = all('SELECT hi.*, i.name, i.unit FROM handover_items hi JOIN items i ON i.id = hi.item_id WHERE hi.handover_id = ?', [$h['id']]);
    $page_title = 'Handover ' . $h['handover_no'];
    include __DIR__ . '/includes/header.php';
    ?>
    <div class="card">
      <h2><?= e($h['handover_no']) ?> <?= status_badge($h['status']) ?></h2>
      <p>Type: <strong><?= e($h['type']) ?></strong> · From: <?= e($h['loc_name']) ?>
      <?= $h['type'] === 'transfer' ? '→ ' . e($h['to_loc_name']) : '' ?>
      <?= $h['staff_id'] ? ($h['type'] === 'return' ? 'From staff: ' : '→ Staff: ') . e($h['staff_name']) : '' ?><br>
      Created by <?= e($h['creator_name']) ?> on <?= dmyt($h['created_at']) ?>
      <?= $h['accepted_at'] ? '· Accepted ' . dmyt($h['accepted_at']) : '' ?></p>
      <?= $h['notes'] ? '<p class="muted">' . e($h['notes']) . '</p>' : '' ?>
    </div>
    <div class="table-wrap">
      <table class="table-sm">
        <thead><tr><th>Item</th><th class="num">Qty</th><th>Serials</th></tr></thead>
        <tbody>
        <?php foreach ($hitems as $hi): ?>
          <tr><td><?= e($hi['name']) ?></td><td class="num"><?= (float)$hi['qty'] ?> <?= e($hi['unit']) ?></td><td><?= e($hi['serials'] ?: '-') ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php if ($h['status'] === 'pending'): ?>
      <div class="card">
        <?php if ($h['type'] === 'issue'): ?>
          <p class="muted mb">Staff (<?= e($h['staff_name']) ?>) will accept this with the OTP sent on WhatsApp, from their "My Stock" page.</p>
          <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="do" value="otp_resend"><input type="hidden" name="id" value="<?= $h['id'] ?>">
            <button class="btn btn-outline btn-sm" type="submit">Re-send OTP</button></form>
        <?php elseif (can('handover.accept')): ?>
          <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="do" value="otp_me"><input type="hidden" name="id" value="<?= $h['id'] ?>">
            <button class="btn btn-outline btn-sm" type="submit">Send OTP to my WhatsApp</button></form>
          <form method="post" class="filterbar mt"><?= csrf_field() ?><input type="hidden" name="do" value="accept"><input type="hidden" name="id" value="<?= $h['id'] ?>">
            <div><input type="text" name="otp" placeholder="Enter OTP" inputmode="numeric" maxlength="6" required></div>
            <button class="btn btn-success btn-sm" type="submit">Accept</button></form>
        <?php endif; ?>
        <?php if (can('handover.add')): ?>
        <form method="post" class="mt" onsubmit="return confirm('Cancel this handover? Stock will be restored.')">
          <?= csrf_field() ?><input type="hidden" name="do" value="cancel"><input type="hidden" name="id" value="<?= $h['id'] ?>">
          <button class="btn btn-danger btn-sm" type="submit">Cancel handover</button>
        </form>
        <?php endif; ?>
      </div>
    <?php endif; ?>
    <?php
    include __DIR__ . '/includes/footer.php';
    exit;
}

// ---------- list ----------
list($scope, $params) = own_scope('handover', 'h.created_by');
if (!can('handover.all')) {
    // staff also sees handovers addressed to them
    $scope = ' AND (h.created_by = ? OR h.staff_id = ?) ';
    $params = [$u['id'], $u['id']];
}
$hos = all("SELECT h.*, l.name loc_name, l2.name to_loc_name, s.name staff_name FROM handovers h
            JOIN locations l ON l.id = h.location_id
            LEFT JOIN locations l2 ON l2.id = h.to_location_id
            LEFT JOIN users s ON s.id = h.staff_id
            WHERE 1=1 $scope ORDER BY h.id DESC LIMIT 300", $params);
$page_title = 'Stock Handover';
include __DIR__ . '/includes/header.php';
?>
<div class="page-actions">
  <?php if (can('handover.add')): ?><a class="btn" href="handover.php?action=new">+ New Handover / Transfer</a><?php endif; ?>
  <a class="btn btn-outline" href="my_stock.php">🎒 My Stock</a>
</div>
<div class="table-wrap">
<table>
  <thead><tr><th>No</th><th>Type</th><th>From</th><th>To</th><th>Status</th><th>Date</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($hos as $h): ?>
    <tr>
      <td><strong><?= e($h['handover_no']) ?></strong></td>
      <td><?= e($h['type']) ?></td>
      <td><?= e($h['type'] === 'return' ? $h['staff_name'] : $h['loc_name']) ?></td>
      <td><?= e($h['type'] === 'return' ? $h['loc_name'] : ($h['type'] === 'transfer' ? $h['to_loc_name'] : $h['staff_name'])) ?></td>
      <td><?= status_badge($h['status']) ?></td>
      <td><?= dmyt($h['created_at']) ?></td>
      <td><a class="btn btn-sm btn-outline" href="handover.php?action=view&id=<?= $h['id'] ?>">View</a></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
