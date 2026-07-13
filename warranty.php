<?php
// Warranty: serial lookup + claims with courier in/out and company TAT
require_once __DIR__ . '/includes/init.php';
require_perm('warranty.view');
$u = current_user();
$action = get('action', 'list');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'save') {
    $id = (int)post('id');
    require_perm($id ? 'warranty.edit' : 'warranty.add');
    $data = [
        (int)post('item_id') ?: null, post('serial_no'), (int)post('party_id') ?: null,
        post('customer_name'), post('customer_mobile'), post('issue'), post('status', 'received'),
        post('received_date', today()), post('sent_date') ?: null, post('sent_courier'), post('sent_tracking'),
        post('back_date') ?: null, post('back_courier'), post('back_tracking'),
        post('delivered_date') ?: null, post('replacement_serial'), post('notes'),
    ];
    if ($id) {
        q('UPDATE warranty_claims SET item_id=?, serial_no=?, party_id=?, customer_name=?, customer_mobile=?, issue=?,
           status=?, received_date=?, sent_date=?, sent_courier=?, sent_tracking=?, back_date=?, back_courier=?,
           back_tracking=?, delivered_date=?, replacement_serial=?, notes=? WHERE id=?', array_merge($data, [$id]));
        flash('Claim updated.');

        // replacement serial chain: old serial -> 'replaced', new serial added
        // to the database keeping the same sale/warranty so history stays linked
        $repl = trim(post('replacement_serial'));
        $origSn = trim(post('serial_no'));
        if ($repl !== '' && $origSn !== '' && $repl !== $origSn) {
            $old = row('SELECT * FROM item_serials WHERE serial_no = ? ORDER BY id DESC LIMIT 1', [$origSn]);
            if ($old && !row('SELECT id FROM item_serials WHERE item_id = ? AND serial_no = ?', [$old['item_id'], $repl])) {
                q("INSERT INTO item_serials (item_id, serial_no, status, purchase_id, sale_id, warranty_months, warranty_expiry)
                   VALUES (?,?,?,?,?,?,?)",
                  [$old['item_id'], $repl, $old['status'] === 'sold' || $old['status'] === 'claim' ? 'sold' : 'in_stock',
                   $old['purchase_id'], $old['sale_id'], $old['warranty_months'], $old['warranty_expiry']]);
                q("UPDATE item_serials SET status = 'replaced' WHERE id = ?", [$old['id']]);
                flash("Replacement serial $repl added to the database (old $origSn marked 'replaced').", 'info');
            }
        }

        if (post('notify') && post('customer_mobile')) {
            $stMsg = ['sent' => 'has been sent to the company for warranty.',
                      'received_back' => 'is back from the company - ready for pickup ✅',
                      'delivered' => 'has been delivered. Thank you!',
                      'rejected' => 'was rejected by the company. Please contact us.'];
            if (isset($stMsg[post('status')])) {
                send_whatsapp(post('customer_mobile'), wa_template('warranty_status', [
                    'claim_no' => post('claim_no'), 'serial' => post('serial_no'),
                    'status_line' => $stMsg[post('status')],
                ]));
            }
        }
    } else {
        q('INSERT INTO warranty_claims (item_id, serial_no, party_id, customer_name, customer_mobile, issue, status,
           received_date, sent_date, sent_courier, sent_tracking, back_date, back_courier, back_tracking,
           delivered_date, replacement_serial, notes, location_id, created_by)
           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', array_merge($data, [$u['location_id'], $u['id']]));
        $id = insert_id();
        q('UPDATE warranty_claims SET claim_no = ? WHERE id = ?', [doc_no('WC', $id), $id]);
        flash('Warranty claim ' . doc_no('WC', $id) . ' created.');
    }
    log_activity('warranty_save', doc_no('WC', $id));
    redirect('warranty.php?action=edit&id=' . $id);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'delete') {
    require_perm('warranty.delete');
    $c = row('SELECT * FROM warranty_claims WHERE id = ?', [(int)post('id')]);
    if ($c) {
        q('DELETE FROM warranty_claims WHERE id = ?', [$c['id']]);
        log_activity('warranty_delete', $c['claim_no']);
        flash('Claim ' . $c['claim_no'] . ' deleted.');
    }
    redirect('warranty.php');
}

$suppliers = all("SELECT id, name FROM parties WHERE is_active = 1 ORDER BY name");
$itemsList = all('SELECT id, name FROM items WHERE is_active = 1 ORDER BY name');

if ($action === 'new' || $action === 'edit') {
    $id = (int)get('id');
    require_perm($id ? 'warranty.edit' : 'warranty.add');
    $c = $id ? row('SELECT * FROM warranty_claims WHERE id = ?', [$id]) : null;
    $page_title = $c ? 'Claim ' . $c['claim_no'] : 'New Warranty Claim';
    include __DIR__ . '/includes/header.php';
    ?>
    <div class="card">
      <h2>🔎 Serial warranty check</h2>
      <div class="filterbar">
        <div><input type="text" id="snCheck" placeholder="Enter serial number" value="<?= e(get('sn')) ?>"></div>
        <button type="button" class="btn btn-sm" onclick="snLookup()">Check</button>
      </div>
      <div id="snResult" class="muted"></div>
    </div>
    <div class="card">
      <h2><?= $page_title ?> <?= $c ? status_badge($c['status']) : '' ?></h2>
      <?php if ($c && $c['sent_date']): ?>
        <p class="flash flash-info">Sent to company <?= dmy($c['sent_date']) ?><?= $c['back_date'] ? ', back ' . dmy($c['back_date']) : '' ?>
        · <strong><?= days_between($c['sent_date'], $c['back_date'] ?: null) ?> days</strong> with company</p>
      <?php endif; ?>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="save"><input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="claim_no" value="<?= e($c['claim_no'] ?? '') ?>">
        <div class="form-row cols-3">
          <div><label>Item</label>
            <select name="item_id"><option value="">-- select --</option>
            <?php foreach ($itemsList as $it): ?><option value="<?= $it['id'] ?>" <?= ($c['item_id'] ?? '') == $it['id'] ? 'selected' : '' ?>><?= e($it['name']) ?></option><?php endforeach; ?>
            </select></div>
          <div><label>Serial no *</label><input type="text" name="serial_no" value="<?= e($c['serial_no'] ?? get('sn')) ?>" required></div>
          <div><label>Company / Supplier</label>
            <select name="party_id"><option value="">-- select --</option>
            <?php foreach ($suppliers as $s): ?><option value="<?= $s['id'] ?>" <?= ($c['party_id'] ?? '') == $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?>
            </select></div>
        </div>
        <div class="form-row cols-3">
          <div><label>Customer name</label><input type="text" name="customer_name" value="<?= e($c['customer_name'] ?? '') ?>"></div>
          <div><label>Customer mobile</label><input type="tel" name="customer_mobile" value="<?= e($c['customer_mobile'] ?? '') ?>"></div>
          <div><label>Status</label>
            <select name="status">
              <?php foreach (['received', 'sent', 'received_back', 'delivered', 'rejected'] as $s): ?>
              <option value="<?= $s ?>" <?= ($c['status'] ?? 'received') === $s ? 'selected' : '' ?>><?= ucwords(str_replace('_', ' ', $s)) ?></option>
              <?php endforeach; ?>
            </select></div>
        </div>
        <div class="field"><label>Issue / fault</label><textarea name="issue" rows="2" required><?= e($c['issue'] ?? '') ?></textarea></div>
        <h3>Outward (to company)</h3>
        <div class="form-row cols-4">
          <div><label>Received from customer</label><input type="date" name="received_date" value="<?= e($c['received_date'] ?? today()) ?>"></div>
          <div><label>Sent date</label><input type="date" name="sent_date" value="<?= e($c['sent_date'] ?? '') ?>"></div>
          <div><label>Courier name</label><input type="text" name="sent_courier" value="<?= e($c['sent_courier'] ?? '') ?>"></div>
          <div><label>Tracking / docket no</label><input type="text" name="sent_tracking" value="<?= e($c['sent_tracking'] ?? '') ?>"></div>
        </div>
        <h3>Inward (back from company)</h3>
        <div class="form-row cols-4">
          <div><label>Back date</label><input type="date" name="back_date" value="<?= e($c['back_date'] ?? '') ?>"></div>
          <div><label>Courier name</label><input type="text" name="back_courier" value="<?= e($c['back_courier'] ?? '') ?>"></div>
          <div><label>Tracking no</label><input type="text" name="back_tracking" value="<?= e($c['back_tracking'] ?? '') ?>"></div>
          <div><label>Replacement serial (if any)</label><input type="text" name="replacement_serial" value="<?= e($c['replacement_serial'] ?? '') ?>"></div>
        </div>
        <div class="form-row cols-2">
          <div><label>Delivered to customer on</label><input type="date" name="delivered_date" value="<?= e($c['delivered_date'] ?? '') ?>"></div>
          <div><label>Notes</label><input type="text" name="notes" value="<?= e($c['notes'] ?? '') ?>"></div>
        </div>
        <label class="check-inline mb"><input type="checkbox" name="notify" value="1" checked> Send status update on customer WhatsApp</label>
        <button class="btn" type="submit">Save Claim</button>
        <a class="btn btn-muted" href="warranty.php">Back</a>
      </form>
    </div>
    <script>
      function snLookup() {
        var sn = document.getElementById('snCheck').value.trim();
        if (!sn) return;
        fetch('ajax.php?a=serial_lookup&sn=' + encodeURIComponent(sn))
          .then(r => r.json()).then(function (d) {
            var el = document.getElementById('snResult');
            if (d.error) { el.textContent = '❌ ' + d.error; return; }
            var w = d.warranty_expiry
              ? (d.warranty_expiry >= new Date().toISOString().slice(0, 10)
                 ? '🟢 IN WARRANTY till ' + d.warranty_expiry : '🔴 Warranty EXPIRED on ' + d.warranty_expiry)
              : '⚪ No warranty date recorded';
            var h = '<strong>' + d.item_name + '</strong> · status: ' + d.status +
              (d.invoice_no ? ' · sold on ' + d.sale_date + ' (' + d.invoice_no + ') to ' + (d.customer_name || '-') : '') +
              '<br>' + w;
            if (d.purchase_party) h += '<br>📥 Purchased from: ' + d.purchase_party;
            if (d.history && d.history.length) {
              h += '<br><strong>History:</strong>';
              d.history.forEach(function (c) {
                h += '<br>• ' + c.claim_no + ' [' + c.status + '] ' + (c.company ? '→ ' + c.company : '') +
                     (c.sent_date ? ' (sent ' + c.sent_date + (c.back_date ? ', back ' + c.back_date : '') + ')' : '') +
                     (c.replacement_serial ? ' · replaced by SN: ' + c.replacement_serial : '');
              });
            }
            el.innerHTML = h;
          });
      }
      <?php if (get('sn')): ?>snLookup();<?php endif; ?>
    </script>
    <?php
    include __DIR__ . '/includes/footer.php';
    exit;
}

// ---------- list ----------
$st = get('st');
$where = ' 1=1 '; $params = [];
if ($st) { $where .= ' AND w.status = ? '; $params[] = $st; }
$claims = all("SELECT w.*, p.name company_name, i.name item_name FROM warranty_claims w
               LEFT JOIN parties p ON p.id = w.party_id
               LEFT JOIN items i ON i.id = w.item_id
               WHERE $where ORDER BY w.id DESC LIMIT 300", $params);
$page_title = 'Warranty Claims';
include __DIR__ . '/includes/header.php';
?>
<div class="page-actions">
  <?php if (can('warranty.add')): ?><a class="btn" href="warranty.php?action=new">+ New Claim</a><?php endif; ?>
  <select onchange="location='warranty.php?st='+this.value" style="max-width:180px">
    <option value="">All statuses</option>
    <?php foreach (['received', 'sent', 'received_back', 'delivered', 'rejected'] as $s): ?>
    <option value="<?= $s ?>" <?= $st === $s ? 'selected' : '' ?>><?= ucwords(str_replace('_', ' ', $s)) ?></option>
    <?php endforeach; ?>
  </select>
</div>
<div class="searchbox"><input type="text" id="wFilter" placeholder="🔍 Search serial / customer / company..."></div>
<div class="table-wrap">
<table id="wTable">
  <thead><tr><th>Claim</th><th>Item / Serial</th><th>Customer</th><th>Company</th><th>Status</th><th class="num">Company days</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($claims as $c): ?>
    <tr>
      <td><strong><?= e($c['claim_no']) ?></strong><br><span class="muted"><?= dmy($c['received_date']) ?></span></td>
      <td><?= e($c['item_name'] ?: '-') ?><br><span class="muted"><?= e($c['serial_no']) ?></span></td>
      <td><?= e($c['customer_name']) ?></td>
      <td><?= e($c['company_name'] ?: '-') ?></td>
      <td><?= status_badge($c['status']) ?></td>
      <td class="num"><?= $c['sent_date'] ? days_between($c['sent_date'], $c['back_date'] ?: null) . 'd' : '-' ?></td>
      <td style="white-space:nowrap">
        <?php if (can('warranty.edit')): ?><a class="btn btn-sm btn-outline" href="warranty.php?action=edit&id=<?= $c['id'] ?>">Open</a><?php endif; ?>
        <?php if (can('warranty.delete')): ?>
        <form method="post" style="display:inline" onsubmit="return confirm('Delete this claim?')">
          <?= csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= $c['id'] ?>">
          <button class="btn btn-sm btn-danger" type="submit">✕</button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<script>tableFilter('wFilter', 'wTable');</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
