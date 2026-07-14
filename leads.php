<?php
// Leads / CRM pipeline: capture -> contact -> quote -> won (converted to a
// party) or lost. Kept deliberately simple (one status field, no separate
// "stage" table) to match the rest of this app's low-ceremony style.
require_once __DIR__ . '/includes/init.php';
require_perm('leads.view');
$u = current_user();
$action = get('action', 'list');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'save') {
    $id = (int)post('id');
    require_perm($id ? 'leads.edit' : 'leads.add');
    $data = [post('name'), post('mobile'), post('email'), post('source'), post('interest'),
              post('status', 'new'), (int)post('assigned_to') ?: null, post('notes')];
    if ($id) {
        q('UPDATE leads SET name=?, mobile=?, email=?, source=?, interest=?, status=?, assigned_to=?, notes=? WHERE id=?',
          array_merge($data, [$id]));
        flash('Lead updated.');
    } else {
        q('INSERT INTO leads (name, mobile, email, source, interest, status, assigned_to, notes, location_id, created_by) VALUES (?,?,?,?,?,?,?,?,?,?)',
          array_merge($data, [$u['location_id'], $u['id']]));
        $id = insert_id();
        q('UPDATE leads SET lead_no = ? WHERE id = ?', [doc_no('LED', $id), $id]);
        flash('Lead ' . doc_no('LED', $id) . ' added.');
    }
    log_activity('lead_save', post('name'));
    redirect('leads.php?action=view&id=' . $id);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'set_status') {
    require_perm('leads.edit');
    $lid = (int)post('id');
    $st = post('status');
    if (in_array($st, ['new', 'contacted', 'quoted', 'won', 'lost'], true)) {
        q('UPDATE leads SET status = ? WHERE id = ?', [$st, $lid]);
        flash('Lead marked ' . $st . '.');
    }
    redirect('leads.php?action=view&id=' . $lid);
}

// ---------- convert a won lead into a party (or link to an existing one) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'convert') {
    require_perm('leads.edit');
    $lid = (int)post('id');
    $lead = row('SELECT * FROM leads WHERE id = ?', [$lid]);
    if (!$lead) { flash('Lead not found.', 'error'); redirect('leads.php'); }
    $partyId = (int)post('existing_party_id');
    if (!$partyId) {
        q('INSERT INTO parties (name, type, mobile, email, is_active) VALUES (?,?,?,?,1)',
          [$lead['name'], 'both', $lead['mobile'], $lead['email']]);
        $partyId = insert_id();
    }
    q("UPDATE leads SET status = 'won', party_id = ? WHERE id = ?", [$partyId, $lid]);
    log_activity('lead_convert', $lead['lead_no'] . ' -> party #' . $partyId);
    flash('Lead converted to party.');
    redirect('parties.php?action=ledger&id=' . $partyId);
}

if ($action === 'new' || $action === 'edit') {
    $id = (int)get('id');
    require_perm($id ? 'leads.edit' : 'leads.add');
    $l = $id ? row('SELECT * FROM leads WHERE id = ?', [$id]) : null;
    $staffList = all('SELECT id, name FROM users WHERE is_active = 1 ORDER BY name');
    $page_title = $l ? 'Edit Lead' : 'New Lead';
    include __DIR__ . '/includes/header.php';
    ?>
    <div class="card">
      <h2><?= e($page_title) ?></h2>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="save">
        <input type="hidden" name="id" value="<?= $id ?>">
        <div class="form-row cols-3">
          <div><label>Name *</label><input type="text" name="name" value="<?= e($l['name'] ?? '') ?>" required></div>
          <div><label>Mobile</label><input type="tel" name="mobile" value="<?= e($l['mobile'] ?? '') ?>"></div>
          <div><label>Email</label><input type="email" name="email" value="<?= e($l['email'] ?? '') ?>"></div>
        </div>
        <div class="form-row cols-3">
          <div><label>Source</label>
            <select name="source">
              <?php foreach (['Walk-in', 'Referral', 'WhatsApp', 'Phone', 'Website', 'Social Media', 'Other'] as $s): ?>
              <option value="<?= $s ?>" <?= ($l['source'] ?? '') === $s ? 'selected' : '' ?>><?= $s ?></option>
              <?php endforeach; ?>
            </select></div>
          <div><label>Status</label>
            <select name="status">
              <?php foreach (['new', 'contacted', 'quoted', 'won', 'lost'] as $s): ?>
              <option value="<?= $s ?>" <?= ($l['status'] ?? 'new') === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
              <?php endforeach; ?>
            </select></div>
          <div><label>Assign to</label>
            <select name="assigned_to">
              <option value="">-- unassigned --</option>
              <?php foreach ($staffList as $s): ?><option value="<?= $s['id'] ?>" <?= ($l['assigned_to'] ?? '') == $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?>
            </select></div>
        </div>
        <div class="field"><label>Interested in</label><input type="text" name="interest" value="<?= e($l['interest'] ?? '') ?>" placeholder="e.g. New laptop, CCTV install"></div>
        <div class="field"><label>Notes</label><textarea name="notes" rows="3"><?= e($l['notes'] ?? '') ?></textarea></div>
        <button class="btn" type="submit">Save Lead</button>
        <a class="btn btn-muted" href="leads.php">Back</a>
      </form>
    </div>
    <?php
    include __DIR__ . '/includes/footer.php';
    exit;
}

if ($action === 'view') {
    $id = (int)get('id');
    $l = row('SELECT lead.*, s.name staff_name, p.name party_name FROM leads lead
              LEFT JOIN users s ON s.id = lead.assigned_to LEFT JOIN parties p ON p.id = lead.party_id WHERE lead.id = ?', [$id]);
    if (!$l) { flash('Lead not found.', 'error'); redirect('leads.php'); }
    $parties = all('SELECT id, name, mobile FROM parties WHERE is_active = 1 ORDER BY name');
    $page_title = $l['lead_no'];
    include __DIR__ . '/includes/header.php';
    ?>
    <div class="card">
      <h2><?= e($l['lead_no']) ?> <?= status_badge($l['status']) ?></h2>
      <p><strong><?= e($l['name']) ?></strong> · <?= e($l['mobile']) ?> <?= $l['email'] ? '· ' . e($l['email']) : '' ?></p>
      <p class="muted">Source: <?= e($l['source'] ?: '-') ?> · Assigned: <?= e($l['staff_name'] ?: 'Unassigned') ?></p>
      <?php if ($l['interest']): ?><p class="mt"><strong>Interested in:</strong> <?= e($l['interest']) ?></p><?php endif; ?>
      <?php if ($l['notes']): ?><p class="mt"><strong>Notes:</strong><br><?= nl2br(e($l['notes'])) ?></p><?php endif; ?>
      <?php if ($l['party_id']): ?><p class="mt">✅ Converted: <a href="parties.php?action=ledger&id=<?= $l['party_id'] ?>"><?= e($l['party_name']) ?></a></p><?php endif; ?>
      <?php if (can('leads.edit') && $l['status'] !== 'won'): ?>
      <div class="page-actions mt" style="margin:0">
        <?php foreach (['new', 'contacted', 'quoted', 'lost'] as $s): if ($s === $l['status']) continue; ?>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="set_status"><input type="hidden" name="id" value="<?= $l['id'] ?>"><input type="hidden" name="status" value="<?= $s ?>">
          <button class="btn btn-sm btn-outline" type="submit">Mark <?= ucfirst($s) ?></button></form>
        <?php endforeach; ?>
        <a class="btn btn-sm btn-outline" href="leads.php?action=edit&id=<?= $l['id'] ?>">✏️ Edit</a>
        <a class="btn btn-sm btn-outline" href="follow_ups.php?action=new&ref_type=lead&ref_id=<?= $l['id'] ?>">⏰ Add Follow-up</a>
      </div>
      <?php endif; ?>
    </div>

    <?php if (can('leads.edit') && $l['status'] !== 'won'): ?>
    <div class="card">
      <h3>🎉 Convert to customer</h3>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="convert">
        <input type="hidden" name="id" value="<?= $l['id'] ?>">
        <div class="form-row cols-2">
          <div><label>Link to existing party (optional)</label>
            <select name="existing_party_id">
              <option value="">-- create new party "<?= e($l['name']) ?>" --</option>
              <?php foreach ($parties as $p): ?><option value="<?= $p['id'] ?>"><?= e($p['name']) ?></option><?php endforeach; ?>
            </select></div>
          <div style="align-self:end"><button class="btn btn-success" type="submit">✅ Convert & Create Party</button></div>
        </div>
      </form>
    </div>
    <?php endif; ?>
    <?php
    include __DIR__ . '/includes/footer.php';
    exit;
}

// ---------- list ----------
$where = ' 1=1 ';
$params = [];
if (!can('leads.all')) { $where = ' (assigned_to = ? OR created_by = ?) '; $params = [$u['id'], $u['id']]; }
$st = get('st');
if ($st) { $where .= ' AND status = ? '; $params[] = $st; }
$leads = all("SELECT lead.*, s.name staff_name FROM leads lead LEFT JOIN users s ON s.id = lead.assigned_to
              WHERE $where ORDER BY lead.id DESC LIMIT 300", $params);
$page_title = 'Leads';
include __DIR__ . '/includes/header.php';
?>
<div class="page-actions">
  <?php if (can('leads.add')): ?><a class="btn" href="leads.php?action=new">+ New Lead</a><?php endif; ?>
  <select onchange="location='leads.php?st='+this.value" style="max-width:170px">
    <option value="">All statuses</option>
    <?php foreach (['new', 'contacted', 'quoted', 'won', 'lost'] as $s): ?>
    <option value="<?= $s ?>" <?= $st === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
    <?php endforeach; ?>
  </select>
</div>
<div class="searchbox"><input type="text" id="lFilter" placeholder="🔍 Search lead / customer..."></div>
<div class="table-wrap">
<table id="lTable">
  <thead><tr><th>No</th><th>Name</th><th>Interested in</th><th>Source</th><th>Status</th><th>Assigned</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($leads as $l): ?>
    <tr>
      <td><strong><?= e($l['lead_no']) ?></strong></td>
      <td><?= e($l['name']) ?><br><span class="muted"><?= e($l['mobile']) ?></span></td>
      <td><?= e($l['interest']) ?></td>
      <td><?= e($l['source']) ?></td>
      <td><?= status_badge($l['status']) ?></td>
      <td><?= e($l['staff_name'] ?: '-') ?></td>
      <td><a class="btn btn-sm btn-outline" href="leads.php?action=view&id=<?= $l['id'] ?>">Open</a></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$leads): ?><tr><td colspan="7" class="muted">No leads yet.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
<script>tableFilter('lFilter', 'lTable');</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
