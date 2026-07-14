<?php
// Repair job sheets - in-house / outsourced with party turnaround tracking
require_once __DIR__ . '/includes/init.php';
require_perm('repairs.view');
$u = current_user();
$action = get('action', 'list');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'save') {
    $id = (int)post('id');
    require_perm($id ? 'repairs.edit' : 'repairs.add');
    $data = [
        (int)post('party_id') ?: null, post('customer_name'), post('customer_mobile'), post('device_type'),
        post('brand_model'), post('serial_no'), post('accessories'), post('problem'), post('status', 'received'),
        post('received_date', today()), (int)post('outsource_party_id') ?: null,
        post('sent_date') ?: null, post('received_back_date') ?: null,
        (float)post('estimate_cost'), (float)post('outsource_cost'), (float)post('final_charge'),
        (float)post('advance'), post('delivered_date') ?: null, post('notes'), (int)post('assigned_to') ?: null,
    ];
    if (is_period_locked(post('received_date', today()))) { flash(period_lock_message(), 'error'); redirect('repairs.php?action=' . ($id ? 'edit&id=' . $id : 'new')); }
    if ($id) {
        q('UPDATE repairs SET party_id=?, customer_name=?, customer_mobile=?, device_type=?, brand_model=?, serial_no=?,
           accessories=?, problem=?, status=?, received_date=?, outsource_party_id=?, sent_date=?, received_back_date=?,
           estimate_cost=?, outsource_cost=?, final_charge=?, advance=?, delivered_date=?, notes=?, assigned_to=? WHERE id=?',
          array_merge($data, [$id]));
        flash('Job updated.');
        // WhatsApp status update to customer
        if (post('notify') && post('customer_mobile')) {
            $stMsg = ['ready' => 'is READY for pickup ✅', 'delivered' => 'has been delivered. Thank you!',
                      'outsourced' => 'has been sent for specialist repair.', 'in_progress' => 'is under repair.'];
            if (isset($stMsg[post('status')])) {
                send_whatsapp(post('customer_mobile'), wa_template('repair_status', [
                    'job_no' => post('job_no'), 'device' => post('device_type'),
                    'status_line' => $stMsg[post('status')],
                ]));
            }
        }
        // job handed back to customer - ask for feedback once, separately
        // from the status update above (its own message, own template)
        if (post('status') === 'delivered' && post('customer_mobile')) {
            $link = feedback_link('repair', $id, post('customer_name'), post('customer_mobile'));
            send_whatsapp(post('customer_mobile'), wa_template('feedback_request', [
                'customer' => post('customer_name') ?: 'Customer', 'job_no' => post('job_no'), 'link' => $link,
            ]));
        }
    } else {
        q('INSERT INTO repairs (party_id, customer_name, customer_mobile, device_type, brand_model, serial_no, accessories,
           problem, status, received_date, outsource_party_id, sent_date, received_back_date, estimate_cost, outsource_cost,
           final_charge, advance, delivered_date, notes, assigned_to, location_id, created_by)
           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
          array_merge($data, [$u['location_id'], $u['id']]));
        $id = insert_id();
        q('UPDATE repairs SET job_no = ? WHERE id = ?', [doc_no('JOB', $id), $id]);
        if (post('customer_mobile')) {
            send_whatsapp(post('customer_mobile'), wa_template('repair_received', [
                'job_no' => doc_no('JOB', $id), 'device' => trim(post('device_type') . ' ' . post('brand_model')),
                'problem' => post('problem'), 'customer' => post('customer_name'),
            ]));
        }
        flash('Job sheet ' . doc_no('JOB', $id) . ' created.');
    }
    log_activity('repair_save', doc_no('JOB', $id));
    redirect('repairs.php?action=edit&id=' . $id);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'delete') {
    require_perm('repairs.delete');
    $rid = (int)post('id');
    $job = row('SELECT * FROM repairs WHERE id = ?', [$rid]);
    if ($job && is_period_locked($job['received_date'])) { flash(period_lock_message(), 'error'); redirect('repairs.php'); }
    if ($job) {
        $pdo = db();
        $pdo->beginTransaction();
        foreach (all('SELECT * FROM repair_materials WHERE repair_id = ?', [$rid]) as $rm) {
            adjust_stock($rm['item_id'], $job['location_id'], (float)$rm['qty'], 'repair_delete', $rid, $job['job_no']);
        }
        q('DELETE FROM repair_materials WHERE repair_id = ?', [$rid]);
        q('DELETE FROM repair_checklist WHERE repair_id = ?', [$rid]);
        q('DELETE FROM repair_photos WHERE repair_id = ?', [$rid]);
        q('DELETE FROM repairs WHERE id = ?', [$rid]);
        $pdo->commit();
        log_activity('repair_delete', $job['job_no']);
        flash('Job sheet ' . $job['job_no'] . ' deleted, any parts used were returned to stock.');
    }
    redirect('repairs.php');
}

// ---------- quick status change (used by the technician mobile panel) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'quickstatus') {
    $rid = (int)post('id');
    $job = row('SELECT * FROM repairs WHERE id = ?', [$rid]);
    $newStatus = post('status');
    if (!$job || !($job['assigned_to'] == $u['id'] || can('repairs.edit')) || !in_array($newStatus, ['received', 'in_progress', 'outsourced', 'ready', 'delivered', 'returned_unrepaired'], true)) {
        flash('Cannot update this job.', 'error');
        redirect('my_jobs.php');
    }
    if (is_period_locked($job['received_date'])) { flash(period_lock_message(), 'error'); redirect('my_jobs.php'); }
    q('UPDATE repairs SET status = ?, delivered_date = IF(? = "delivered", COALESCE(delivered_date, ?), delivered_date) WHERE id = ?',
      [$newStatus, $newStatus, today(), $rid]);
    log_activity('repair_status', $job['job_no'] . ' -> ' . $newStatus);
    if ($newStatus === 'delivered' && $job['customer_mobile']) {
        $link = feedback_link('repair', $rid, $job['customer_name'], $job['customer_mobile']);
        send_whatsapp($job['customer_mobile'], wa_template('feedback_request', [
            'customer' => $job['customer_name'] ?: 'Customer', 'job_no' => $job['job_no'], 'link' => $link,
        ]));
    }
    flash('Job ' . $job['job_no'] . ' marked ' . str_replace('_', ' ', $newStatus) . '.');
    redirect(get('back') === 'repairs' ? 'repairs.php?action=edit&id=' . $rid : 'my_jobs.php');
}

// ---------- spare parts used (deducted from this job's location stock) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'add_material') {
    require_perm('repairs.edit');
    $rid = (int)post('repair_id');
    $job = row('SELECT * FROM repairs WHERE id = ?', [$rid]);
    if (!$job) { flash('Job not found.', 'error'); redirect('repairs.php'); }
    if (is_period_locked($job['received_date'])) { flash(period_lock_message(), 'error'); redirect('repairs.php?action=edit&id=' . $rid); }
    $iid = (int)post('item_id');
    $qty = (float)post('qty');
    $item = $iid ? row('SELECT * FROM items WHERE id = ?', [$iid]) : null;
    if (!$item || $qty <= 0) { flash('Pick an item and quantity.', 'error'); redirect('repairs.php?action=edit&id=' . $rid); }
    if (stock_qty($iid, $job['location_id']) < $qty && setting('allow_negative_stock', '1') !== '1') {
        flash("Not enough stock of {$item['name']} at this location.", 'error');
        redirect('repairs.php?action=edit&id=' . $rid);
    }
    $price = (float)$item['selling_price'];
    q('INSERT INTO repair_materials (repair_id, item_id, qty, price, total, created_by) VALUES (?,?,?,?,?,?)',
      [$rid, $iid, $qty, $price, $qty * $price, $u['id']]);
    adjust_stock($iid, $job['location_id'], -$qty, 'repair_use', $rid, $job['job_no']);
    log_activity('repair_material', $job['job_no'] . ': ' . $item['name'] . ' x' . $qty);
    flash('Part added, stock deducted.');
    redirect('repairs.php?action=edit&id=' . $rid);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'remove_material') {
    require_perm('repairs.edit');
    $mid = (int)post('id');
    $rm = row('SELECT rm.*, r.job_no, r.location_id, r.received_date FROM repair_materials rm JOIN repairs r ON r.id = rm.repair_id WHERE rm.id = ?', [$mid]);
    if ($rm) {
        if (is_period_locked($rm['received_date'])) { flash(period_lock_message(), 'error'); redirect('repairs.php?action=edit&id=' . $rm['repair_id']); }
        adjust_stock($rm['item_id'], $rm['location_id'], (float)$rm['qty'], 'repair_use_undo', $rm['repair_id'], $rm['job_no']);
        q('DELETE FROM repair_materials WHERE id = ?', [$mid]);
        flash('Part removed, stock restored.');
        redirect('repairs.php?action=edit&id=' . $rm['repair_id']);
    }
    redirect('repairs.php');
}

// ---------- service checklist ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'save_checklist') {
    $rid = (int)post('repair_id');
    $job = row('SELECT * FROM repairs WHERE id = ?', [$rid]);
    if (!$job || !($job['assigned_to'] == $u['id'] || can('repairs.edit'))) { flash('Cannot update this job.', 'error'); redirect('repairs.php'); }
    $results = post('result', []);
    $notes = post('c_notes', []);
    foreach ($results as $cid => $res) {
        $cid = (int)$cid;
        if (!in_array($res, ['pass', 'fail', 'na'], true)) continue;
        q('INSERT INTO repair_checklist (repair_id, checklist_item_id, result, notes) VALUES (?,?,?,?)
           ON DUPLICATE KEY UPDATE result = VALUES(result), notes = VALUES(notes)',
          [$rid, $cid, $res, mb_substr($notes[$cid] ?? '', 0, 255)]);
    }
    flash('Checklist saved.');
    redirect('repairs.php?action=edit&id=' . $rid);
}

// ---------- before/after photos ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'upload_photo') {
    $rid = (int)post('repair_id');
    $job = row('SELECT * FROM repairs WHERE id = ?', [$rid]);
    if (!$job || !($job['assigned_to'] == $u['id'] || can('repairs.edit'))) { flash('Cannot update this job.', 'error'); redirect('repairs.php'); }
    $type = post('type') === 'after' ? 'after' : 'before';
    if (!empty($_FILES['photo']['tmp_name']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            if (!is_dir(__DIR__ . '/uploads/repair_photos')) mkdir(__DIR__ . '/uploads/repair_photos', 0755, true);
            $path = 'uploads/repair_photos/repair_' . $rid . '_' . time() . '_' . rand(100, 999) . '.' . $ext;
            move_uploaded_file($_FILES['photo']['tmp_name'], __DIR__ . '/' . $path);
            q('INSERT INTO repair_photos (repair_id, type, path, uploaded_by) VALUES (?,?,?,?)', [$rid, $type, $path, $u['id']]);
            flash(ucfirst($type) . ' photo added.');
        } else {
            flash('Only jpg/png/webp images are allowed.', 'error');
        }
    } else {
        flash('Choose a photo to upload.', 'error');
    }
    redirect('repairs.php?action=edit&id=' . $rid);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'delete_photo') {
    require_perm('repairs.edit');
    $ph = row('SELECT * FROM repair_photos WHERE id = ?', [(int)post('id')]);
    if ($ph) {
        @unlink(__DIR__ . '/' . $ph['path']);
        q('DELETE FROM repair_photos WHERE id = ?', [$ph['id']]);
        flash('Photo removed.');
        redirect('repairs.php?action=edit&id=' . $ph['repair_id']);
    }
    redirect('repairs.php');
}

// ---------- generate / share the digital service report link ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'share_report') {
    require_perm('repairs.edit');
    $rid = (int)post('repair_id');
    $job = row('SELECT * FROM repairs WHERE id = ?', [$rid]);
    if ($job) {
        $token = $job['report_token'] ?: bin2hex(random_bytes(16));
        if (!$job['report_token']) q('UPDATE repairs SET report_token = ? WHERE id = ?', [$token, $rid]);
        $link = base_url('service_report.php?token=' . $token);
        if (post('notify') && $job['customer_mobile']) {
            send_whatsapp($job['customer_mobile'], wa_template('service_report', [
                'customer' => $job['customer_name'] ?: 'Customer', 'job_no' => $job['job_no'], 'link' => $link,
            ]));
            flash('Service report link sent on WhatsApp: ' . $link);
        } else {
            flash('Service report link: ' . $link);
        }
    }
    redirect('repairs.php?action=edit&id=' . $rid);
}

$repairParties = all("SELECT id, name FROM parties WHERE is_active = 1 ORDER BY name");
$customers = all("SELECT id, name, mobile FROM parties WHERE is_active = 1 ORDER BY name");
$staffList = all('SELECT id, name FROM users WHERE is_active = 1 ORDER BY name');

if ($action === 'new' || $action === 'edit') {
    $id = (int)get('id');
    require_perm($id ? 'repairs.edit' : 'repairs.add');
    $r = $id ? row('SELECT * FROM repairs WHERE id = ?', [$id]) : null;
    $page_title = $r ? 'Job ' . $r['job_no'] : 'New Repair Job';
    include __DIR__ . '/includes/header.php';
    $tatOut = ($r && $r['sent_date']) ? days_between($r['sent_date'], $r['received_back_date'] ?: null) : null;
    $repairItems = $id ? all("SELECT id, name, unit, selling_price FROM items WHERE is_active = 1 AND item_type <> 'service' ORDER BY name") : [];
    $materials = $id ? all('SELECT rm.*, i.name, i.unit FROM repair_materials rm JOIN items i ON i.id = rm.item_id WHERE rm.repair_id = ? ORDER BY rm.id', [$id]) : [];
    $materialTotal = array_sum(array_column($materials, 'total'));
    $checklistItems = $id ? all('SELECT * FROM service_checklist_items WHERE is_active = 1 ORDER BY sort_order') : [];
    $checklistResponses = $id ? all('SELECT * FROM repair_checklist WHERE repair_id = ?', [$id]) : [];
    $crByItem = [];
    foreach ($checklistResponses as $cr) $crByItem[$cr['checklist_item_id']] = $cr;
    $photos = $id ? all('SELECT * FROM repair_photos WHERE repair_id = ? ORDER BY created_at DESC', [$id]) : [];
    ?>
    <div class="card">
      <h2><?= $page_title ?> <?= $r ? status_badge($r['status']) : '' ?></h2>
      <?php if ($id && can('followups.add')): ?>
      <p class="mt no-print">
        <a class="btn btn-sm btn-outline" href="follow_ups.php?action=new&ref_type=repair&ref_id=<?= $id ?>">⏰ Add Follow-up</a>
        <?php if (can('tickets.add')): ?><a class="btn btn-sm btn-outline" href="tickets.php?action=new&repair_id=<?= $id ?>">🎫 Raise Ticket</a><?php endif; ?>
      </p>
      <?php endif; ?>
      <?php if ($r && $r['sent_date']): ?>
        <p class="flash flash-info">Outsourced: sent <?= dmy($r['sent_date']) ?><?= $r['received_back_date'] ? ', back ' . dmy($r['received_back_date']) : '' ?>
        · <strong><?= $tatOut ?> days</strong> with repair party</p>
      <?php endif; ?>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="save">
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="job_no" value="<?= e($r['job_no'] ?? '') ?>">
        <h3>Customer & device</h3>
        <div class="form-row cols-3">
          <div><label>Party (optional)</label>
            <select name="party_id" onchange="var o=this.options[this.selectedIndex];if(this.value){document.getElementById('r_name').value=o.textContent.trim();document.getElementById('r_mob').value=o.dataset.m||''}">
              <option value="">-- manual --</option>
              <?php foreach ($customers as $c): ?><option value="<?= $c['id'] ?>" data-m="<?= e($c['mobile']) ?>" <?= ($r['party_id'] ?? '') == $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
            </select></div>
          <div><label>Customer name *</label><input type="text" id="r_name" name="customer_name" value="<?= e($r['customer_name'] ?? '') ?>" required></div>
          <div><label>Mobile (WhatsApp)</label><input type="tel" id="r_mob" name="customer_mobile" value="<?= e($r['customer_mobile'] ?? '') ?>"></div>
        </div>
        <div class="form-row cols-4">
          <div><label>Device type</label><input type="text" name="device_type" value="<?= e($r['device_type'] ?? '') ?>" placeholder="Laptop / PC / Printer"></div>
          <div><label>Brand & model</label><input type="text" name="brand_model" value="<?= e($r['brand_model'] ?? '') ?>"></div>
          <div><label>Serial no</label><input type="text" name="serial_no" value="<?= e($r['serial_no'] ?? '') ?>"></div>
          <div><label>Accessories</label><input type="text" name="accessories" value="<?= e($r['accessories'] ?? '') ?>" placeholder="Charger, bag..."></div>
        </div>
        <div class="field"><label>Problem reported</label><textarea name="problem" rows="2" required><?= e($r['problem'] ?? '') ?></textarea></div>

        <h3 class="mt">Status & outsourcing</h3>
        <div class="form-row cols-3">
          <div><label>Status</label>
            <select name="status">
              <?php foreach (['received', 'in_progress', 'outsourced', 'ready', 'delivered', 'returned_unrepaired'] as $s): ?>
              <option value="<?= $s ?>" <?= ($r['status'] ?? 'received') === $s ? 'selected' : '' ?>><?= ucwords(str_replace('_', ' ', $s)) ?></option>
              <?php endforeach; ?>
            </select></div>
          <div><label>Received date</label><input type="date" name="received_date" value="<?= e($r['received_date'] ?? today()) ?>"></div>
          <div><label>Delivered date</label><input type="date" name="delivered_date" value="<?= e($r['delivered_date'] ?? '') ?>"></div>
        </div>
        <div class="form-row cols-4">
          <div><label>Assign to technician</label>
            <select name="assigned_to">
              <option value="">-- unassigned --</option>
              <?php foreach ($staffList as $s): ?><option value="<?= $s['id'] ?>" <?= ($r['assigned_to'] ?? '') == $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?>
            </select></div>
          <div><label>Outsource repair party</label>
            <select name="outsource_party_id">
              <option value="">-- in-house --</option>
              <?php foreach ($repairParties as $p): ?><option value="<?= $p['id'] ?>" <?= ($r['outsource_party_id'] ?? '') == $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?>
            </select></div>
          <div><label>Sent to party on</label><input type="date" name="sent_date" value="<?= e($r['sent_date'] ?? '') ?>"></div>
          <div><label>Received back on</label><input type="date" name="received_back_date" value="<?= e($r['received_back_date'] ?? '') ?>"></div>
        </div>
        <h3 class="mt">Costing</h3>
        <div class="form-row cols-4">
          <div><label>Estimate (₹)</label><input type="number" step="any" name="estimate_cost" value="<?= e($r['estimate_cost'] ?? '0') ?>"></div>
          <div><label>Outsource cost (₹)</label><input type="number" step="any" name="outsource_cost" value="<?= e($r['outsource_cost'] ?? '0') ?>"></div>
          <div><label>Final charge (₹)</label><input type="number" step="any" name="final_charge" value="<?= e($r['final_charge'] ?? '0') ?>"></div>
          <div><label>Advance taken (₹)</label><input type="number" step="any" name="advance" value="<?= e($r['advance'] ?? '0') ?>"></div>
        </div>
        <div class="field"><label>Notes</label><textarea name="notes" rows="2"><?= e($r['notes'] ?? '') ?></textarea></div>
        <label class="check-inline mb"><input type="checkbox" name="notify" value="1" checked> Send status update on customer WhatsApp</label>
        <button class="btn" type="submit">Save Job</button>
        <a class="btn btn-muted" href="repairs.php">Back</a>
      </form>
    </div>

    <?php if ($id): ?>
    <div class="card">
      <h3>🔧 Spare parts used</h3>
      <?php if ($materials): ?>
      <table class="table-sm">
        <?php foreach ($materials as $m): ?>
        <tr>
          <td><?= e($m['name']) ?></td><td class="num"><?= (float)$m['qty'] ?> <?= e($m['unit']) ?></td>
          <td class="num">₹<?= money($m['total']) ?></td>
          <td><?php if (can('repairs.edit')): ?>
            <form method="post" onsubmit="return confirm('Remove this part? Stock will be restored.')"><?= csrf_field() ?>
            <input type="hidden" name="do" value="remove_material"><input type="hidden" name="id" value="<?= $m['id'] ?>">
            <button class="btn btn-sm btn-danger" type="submit">✕</button></form>
          <?php endif; ?></td>
        </tr>
        <?php endforeach; ?>
        <tr><td><strong>Parts total</strong></td><td></td><td class="num"><strong>₹<?= money($materialTotal) ?></strong></td><td></td></tr>
      </table>
      <?php else: ?><p class="muted">No parts logged yet.</p><?php endif; ?>
      <?php if (can('repairs.edit') && $repairItems): ?>
      <form method="post" class="form-row cols-3 mt">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="add_material">
        <input type="hidden" name="repair_id" value="<?= $id ?>">
        <div><label>Item</label>
          <select name="item_id" required>
            <option value="">-- select part --</option>
            <?php foreach ($repairItems as $it): ?><option value="<?= $it['id'] ?>"><?= e($it['name']) ?> (₹<?= money($it['selling_price']) ?>)</option><?php endforeach; ?>
          </select></div>
        <div><label>Qty</label><input type="number" step="any" min="0.01" name="qty" value="1" required></div>
        <div style="align-self:end"><button class="btn btn-sm" type="submit">+ Add Part</button></div>
      </form>
      <?php endif; ?>
    </div>

    <div class="card">
      <h3>✅ Service checklist</h3>
      <?php if ($checklistItems): ?>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="save_checklist">
        <input type="hidden" name="repair_id" value="<?= $id ?>">
        <table class="table-sm">
          <thead><tr><th>Item</th><th>Result</th><th>Notes</th></tr></thead>
          <?php foreach ($checklistItems as $ci): $cur = $crByItem[$ci['id']] ?? null; ?>
          <tr>
            <td><?= e($ci['label']) ?></td>
            <td>
              <select name="result[<?= $ci['id'] ?>]">
                <?php foreach (['na' => 'N/A', 'pass' => 'Pass', 'fail' => 'Fail'] as $rv => $rl): ?>
                <option value="<?= $rv ?>" <?= ($cur['result'] ?? 'na') === $rv ? 'selected' : '' ?>><?= $rl ?></option>
                <?php endforeach; ?>
              </select>
            </td>
            <td><input type="text" name="c_notes[<?= $ci['id'] ?>]" value="<?= e($cur['notes'] ?? '') ?>" style="width:100%"></td>
          </tr>
          <?php endforeach; ?>
        </table>
        <button class="btn btn-sm mt" type="submit">Save Checklist</button>
      </form>
      <?php else: ?><p class="muted">No checklist items configured - add some from <a href="settings.php?cat=service">Settings</a>.</p><?php endif; ?>
    </div>

    <div class="card">
      <h3>📷 Before / After photos</h3>
      <?php if ($photos): ?>
      <div style="display:flex;flex-wrap:wrap;gap:10px">
        <?php foreach ($photos as $ph): ?>
        <div style="text-align:center">
          <a href="<?= e($ph['path']) ?>" target="_blank"><img src="<?= e($ph['path']) ?>" style="width:120px;height:120px;object-fit:cover;border-radius:8px;border:1px solid var(--border)"></a>
          <div class="muted" style="font-size:12px"><?= ucfirst($ph['type']) ?></div>
          <?php if (can('repairs.edit')): ?>
          <form method="post" onsubmit="return confirm('Remove this photo?')"><?= csrf_field() ?>
          <input type="hidden" name="do" value="delete_photo"><input type="hidden" name="id" value="<?= $ph['id'] ?>">
          <button class="btn btn-sm btn-danger" type="submit">✕</button></form>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
      <?php else: ?><p class="muted">No photos yet.</p><?php endif; ?>
      <form method="post" enctype="multipart/form-data" class="form-row cols-3 mt">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="upload_photo">
        <input type="hidden" name="repair_id" value="<?= $id ?>">
        <div><label>Type</label><select name="type"><option value="before">Before</option><option value="after">After</option></select></div>
        <div><label>Photo</label><input type="file" name="photo" accept="image/*" required></div>
        <div style="align-self:end"><button class="btn btn-sm" type="submit">+ Upload</button></div>
      </form>
    </div>

    <div class="card">
      <h3>📄 Digital service report</h3>
      <p class="muted">A shareable, print-friendly report with the checklist, parts used, photos and charges for this job.</p>
      <?php if ($r['report_token']): ?>
        <p><a href="service_report.php?token=<?= e($r['report_token']) ?>" target="_blank"><?= e(base_url('service_report.php?token=' . $r['report_token'])) ?></a></p>
      <?php endif; ?>
      <form method="post" style="display:inline-flex;gap:8px;flex-wrap:wrap">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="share_report">
        <input type="hidden" name="repair_id" value="<?= $id ?>">
        <button class="btn btn-sm btn-outline" type="submit"><?= $r['report_token'] ? 'Show Link Again' : 'Generate Link' ?></button>
        <?php if ($r['customer_mobile']): ?>
        <button class="btn btn-sm btn-wa" type="submit" name="notify" value="1">📲 Send on WhatsApp</button>
        <?php endif; ?>
      </form>
    </div>
    <?php endif; ?>
    <?php
    include __DIR__ . '/includes/footer.php';
    exit;
}

// ---------- list ----------
$st = get('st');
$where = ' 1=1 ';
$params = [];
if ($st) { $where .= ' AND r.status = ? '; $params[] = $st; }
$jobs = all("SELECT r.*, p.name outsource_name, s.name tech_name FROM repairs r
             LEFT JOIN parties p ON p.id = r.outsource_party_id
             LEFT JOIN users s ON s.id = r.assigned_to
             WHERE $where ORDER BY r.id DESC LIMIT 300", $params);
$page_title = 'Repair Jobs';
include __DIR__ . '/includes/header.php';
?>
<div class="page-actions">
  <?php if (can('repairs.add')): ?><a class="btn" href="repairs.php?action=new">+ New Job Sheet</a><?php endif; ?>
  <select onchange="location='repairs.php?st='+this.value" style="max-width:190px">
    <option value="">All statuses</option>
    <?php foreach (['received', 'in_progress', 'outsourced', 'ready', 'delivered', 'returned_unrepaired'] as $s): ?>
    <option value="<?= $s ?>" <?= $st === $s ? 'selected' : '' ?>><?= ucwords(str_replace('_', ' ', $s)) ?></option>
    <?php endforeach; ?>
  </select>
</div>
<div class="searchbox"><input type="text" id="jFilter" placeholder="🔍 Search job / customer / serial..."></div>
<div class="table-wrap">
<table id="jTable">
  <thead><tr><th>Job</th><th>Customer</th><th>Device</th><th>Status</th><th>Technician</th><th>Outsourced to</th><th class="num">Days</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($jobs as $j):
      $days = in_array($j['status'], ['delivered', 'returned_unrepaired'])
          ? days_between($j['received_date'], $j['delivered_date'])
          : days_between($j['received_date']); ?>
    <tr>
      <td><strong><?= e($j['job_no']) ?></strong><br><span class="muted"><?= dmy($j['received_date']) ?></span></td>
      <td><?= e($j['customer_name']) ?><br><span class="muted"><?= e($j['customer_mobile']) ?></span></td>
      <td><?= e($j['device_type']) ?> <?= e($j['brand_model']) ?><br><span class="muted"><?= e($j['serial_no']) ?></span></td>
      <td><?= status_badge($j['status']) ?></td>
      <td><?= e($j['tech_name'] ?: '-') ?></td>
      <td><?= e($j['outsource_name'] ?: '-') ?><?= $j['sent_date'] && !$j['received_back_date'] ? '<br><span class="badge badge-warn">' . days_between($j['sent_date']) . 'd with party</span>' : '' ?></td>
      <td class="num"><?= $days ?>d</td>
      <td style="white-space:nowrap">
        <?php if (can('repairs.edit')): ?><a class="btn btn-sm btn-outline" href="repairs.php?action=edit&id=<?= $j['id'] ?>">Open</a><?php endif; ?>
        <?php if (can('repairs.delete')): ?>
        <form method="post" style="display:inline" onsubmit="return confirm('Delete this job sheet?')">
          <?= csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= $j['id'] ?>">
          <button class="btn btn-sm btn-danger" type="submit">✕</button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<script>tableFilter('jFilter', 'jTable');</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
