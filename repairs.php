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
        (float)post('advance'), post('delivered_date') ?: null, post('notes'),
    ];
    if ($id) {
        q('UPDATE repairs SET party_id=?, customer_name=?, customer_mobile=?, device_type=?, brand_model=?, serial_no=?,
           accessories=?, problem=?, status=?, received_date=?, outsource_party_id=?, sent_date=?, received_back_date=?,
           estimate_cost=?, outsource_cost=?, final_charge=?, advance=?, delivered_date=?, notes=? WHERE id=?',
          array_merge($data, [$id]));
        flash('Job updated.');
        // WhatsApp status update to customer
        if (post('notify') && post('customer_mobile')) {
            $stMsg = ['ready' => 'is READY for pickup ✅', 'delivered' => 'has been delivered. Thank you!',
                      'outsourced' => 'has been sent for specialist repair.', 'in_progress' => 'is under repair.'];
            if (isset($stMsg[post('status')])) {
                send_whatsapp(post('customer_mobile'),
                    '*' . setting('app_name', 'AK Computer') . "*\nYour repair job " . post('job_no') .
                    ' (' . post('device_type') . ') ' . $stMsg[post('status')]);
            }
        }
    } else {
        q('INSERT INTO repairs (party_id, customer_name, customer_mobile, device_type, brand_model, serial_no, accessories,
           problem, status, received_date, outsource_party_id, sent_date, received_back_date, estimate_cost, outsource_cost,
           final_charge, advance, delivered_date, notes, location_id, created_by)
           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
          array_merge($data, [$u['location_id'], $u['id']]));
        $id = insert_id();
        q('UPDATE repairs SET job_no = ? WHERE id = ?', [doc_no('JOB', $id), $id]);
        if (post('customer_mobile')) {
            send_whatsapp(post('customer_mobile'),
                '*' . setting('app_name', 'AK Computer') . "*\nRepair job received: *" . doc_no('JOB', $id) . "*\n" .
                'Device: ' . post('device_type') . ' ' . post('brand_model') . "\nProblem: " . post('problem') .
                "\nWe will update you on WhatsApp. 🙏");
        }
        flash('Job sheet ' . doc_no('JOB', $id) . ' created.');
    }
    log_activity('repair_save', doc_no('JOB', $id));
    redirect('repairs.php?action=edit&id=' . $id);
}

$repairParties = all("SELECT id, name FROM parties WHERE is_active = 1 AND type IN ('supplier','both') ORDER BY name");
$customers = all("SELECT id, name, mobile FROM parties WHERE is_active = 1 AND type IN ('customer','both') ORDER BY name");

if ($action === 'new' || $action === 'edit') {
    $id = (int)get('id');
    require_perm($id ? 'repairs.edit' : 'repairs.add');
    $r = $id ? row('SELECT * FROM repairs WHERE id = ?', [$id]) : null;
    $page_title = $r ? 'Job ' . $r['job_no'] : 'New Repair Job';
    include __DIR__ . '/includes/header.php';
    $tatOut = ($r && $r['sent_date']) ? days_between($r['sent_date'], $r['received_back_date'] ?: null) : null;
    ?>
    <div class="card">
      <h2><?= $page_title ?> <?= $r ? status_badge($r['status']) : '' ?></h2>
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
        <div class="form-row cols-3">
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
    <?php
    include __DIR__ . '/includes/footer.php';
    exit;
}

// ---------- list ----------
$st = get('st');
$where = ' 1=1 ';
$params = [];
if ($st) { $where .= ' AND r.status = ? '; $params[] = $st; }
$jobs = all("SELECT r.*, p.name outsource_name FROM repairs r
             LEFT JOIN parties p ON p.id = r.outsource_party_id
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
  <thead><tr><th>Job</th><th>Customer</th><th>Device</th><th>Status</th><th>Outsourced to</th><th class="num">Days</th><th></th></tr></thead>
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
      <td><?= e($j['outsource_name'] ?: '-') ?><?= $j['sent_date'] && !$j['received_back_date'] ? '<br><span class="badge badge-warn">' . days_between($j['sent_date']) . 'd with party</span>' : '' ?></td>
      <td class="num"><?= $days ?>d</td>
      <td><?php if (can('repairs.edit')): ?><a class="btn btn-sm btn-outline" href="repairs.php?action=edit&id=<?= $j['id'] ?>">Open</a><?php endif; ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<script>tableFilter('jFilter', 'jTable');</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
