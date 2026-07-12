<?php
// AMC / recurring billing contracts - auto-generates a sale invoice every
// billing cycle (also driven by cron.php for unattended renewal).
require_once __DIR__ . '/includes/init.php';
require_perm('amc.view');
$u = current_user();
$action = get('action', 'list');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'save') {
    require_perm(post('id') ? 'amc.edit' : 'amc.add');
    $id = (int)post('id');
    $cycle = in_array(post('billing_cycle'), ['monthly', 'quarterly', 'half_yearly', 'yearly'], true) ? post('billing_cycle') : 'yearly';
    $data = [(int)post('party_id'), (int)post('item_id'), (int)post('company_id', 1), (int)post('location_id') ?: $u['location_id'],
              post('title'), (float)post('amount'), $cycle, post('start_date', today()), post('next_bill_date', today()),
              post('end_date') ?: null, post('notes')];
    if (!$data[0] || !$data[1] || $data[5] <= 0) { flash('Party, item અને amount જરૂરી છે.', 'error'); redirect('amc.php?action=' . ($id ? "edit&id=$id" : 'new')); }
    if ($id) {
        q('UPDATE amc_contracts SET party_id=?, item_id=?, company_id=?, location_id=?, title=?, amount=?, billing_cycle=?,
           start_date=?, next_bill_date=?, end_date=?, notes=? WHERE id=?', array_merge($data, [$id]));
        flash('AMC contract updated.');
    } else {
        q('INSERT INTO amc_contracts (party_id, item_id, company_id, location_id, title, amount, billing_cycle,
           start_date, next_bill_date, end_date, notes, status, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,\'active\',?)',
          array_merge($data, [$u['id']]));
        flash('AMC contract added.');
    }
    log_activity('amc_save', post('title'));
    redirect('amc.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'status') {
    require_perm('amc.edit');
    $st = in_array(post('status'), ['active', 'paused', 'cancelled'], true) ? post('status') : 'active';
    q('UPDATE amc_contracts SET status = ? WHERE id = ?', [$st, (int)post('id')]);
    flash('Status બદલાયું.');
    redirect('amc.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'delete') {
    require_perm('amc.delete');
    q('DELETE FROM amc_contracts WHERE id = ?', [(int)post('id')]);
    flash('AMC contract deleted.');
    redirect('amc.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'generate') {
    require_perm('amc.edit');
    $c = row('SELECT * FROM amc_contracts WHERE id = ?', [(int)post('id')]);
    if (!$c || $c['status'] !== 'active') { flash('Contract active નથી.', 'error'); redirect('amc.php'); }
    $res = amc_generate_invoice($c);
    if ($res) {
        q('UPDATE amc_contracts SET next_bill_date = ? WHERE id = ?', [amc_advance_date($c['next_bill_date'], $c['billing_cycle']), $c['id']]);
        $party = row('SELECT mobile FROM parties WHERE id = ?', [$c['party_id']]);
        if ($party['mobile']) {
            send_whatsapp($party['mobile'], wa_template('amc_bill', [
                'firm' => row('SELECT name FROM companies WHERE id=?', [$c['company_id']])['name'] ?? '',
                'invoice_no' => $res['invoice_no'], 'title' => $c['title'], 'total' => money($res['total']),
                'next_date' => dmy(amc_advance_date($c['next_bill_date'], $c['billing_cycle'])),
            ]));
        }
        log_activity('amc_generate', $res['invoice_no']);
        flash('Invoice ' . $res['invoice_no'] . ' બની ગયું, next renewal date આગળ વધારી.');
    } else {
        flash('Invoice બનાવવામાં ભૂલ આવી.', 'error');
    }
    redirect('amc.php');
}

$companies = all('SELECT * FROM companies WHERE is_active = 1 ORDER BY id');
$locations = all('SELECT * FROM locations WHERE is_active = 1 ORDER BY name');
$serviceItems = all("SELECT * FROM items WHERE is_active = 1 AND item_type = 'service' ORDER BY name");

// ---------- calendar: upcoming AMC renewals + site maintenance visits ----------
if ($action === 'calendar') {
    $month = get('month') ?: date('Y-m');
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) $month = date('Y-m');
    $monthStart = $month . '-01';
    $monthEnd = date('Y-m-t', strtotime($monthStart));
    $prevMonth = date('Y-m', strtotime($monthStart . ' -1 month'));
    $nextMonth = date('Y-m', strtotime($monthStart . ' +1 month'));

    $events = [];
    foreach (all("SELECT ac.next_bill_date d, ac.title, p.name party_name FROM amc_contracts ac
                  JOIN parties p ON p.id = ac.party_id
                  WHERE ac.status = 'active' AND ac.next_bill_date BETWEEN ? AND ?", [$monthStart, $monthEnd]) as $r) {
        $events[$r['d']][] = ['type' => 'amc', 'label' => '💰 ' . $r['title'] . ' (' . $r['party_name'] . ')', 'link' => 'amc.php'];
    }
    foreach (all("SELECT s.next_visit_date d, s.name site_name, s.id, p.name party_name FROM sites s
                  JOIN parties p ON p.id = s.party_id
                  WHERE s.is_active = 1 AND s.next_visit_date BETWEEN ? AND ?", [$monthStart, $monthEnd]) as $r) {
        $events[$r['d']][] = ['type' => 'visit', 'label' => '🌐 ' . $r['site_name'] . ' (' . $r['party_name'] . ')', 'link' => 'sites.php?action=edit&id=' . $r['id']];
    }

    $firstDow = (int)date('w', strtotime($monthStart)); // 0=Sun
    $daysInMonth = (int)date('t', strtotime($monthStart));
    $page_title = 'AMC / Site Visit Calendar';
    include __DIR__ . '/includes/header.php';
    ?>
    <div class="page-actions">
      <a class="btn btn-outline" href="amc.php">← AMC List</a>
      <a class="btn btn-outline" href="amc.php?action=calendar&month=<?= $prevMonth ?>">‹ Prev</a>
      <strong style="align-self:center"><?= date('F Y', strtotime($monthStart)) ?></strong>
      <a class="btn btn-outline" href="amc.php?action=calendar&month=<?= $nextMonth ?>">Next ›</a>
    </div>
    <div class="cal-grid">
      <?php foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $dw): ?>
      <div class="cal-dow"><?= $dw ?></div>
      <?php endforeach; ?>
      <?php for ($i = 0; $i < $firstDow; $i++): ?><div class="cal-cell cal-blank"></div><?php endfor; ?>
      <?php for ($d = 1; $d <= $daysInMonth; $d++):
          $dateStr = $month . '-' . str_pad($d, 2, '0', STR_PAD_LEFT);
          $isToday = $dateStr === today(); ?>
      <div class="cal-cell <?= $isToday ? 'cal-today' : '' ?>">
        <div class="cal-daynum"><?= $d ?></div>
        <?php foreach (($events[$dateStr] ?? []) as $ev): ?>
        <a href="<?= e($ev['link']) ?>" class="cal-event cal-<?= $ev['type'] ?>"><?= e($ev['label']) ?></a>
        <?php endforeach; ?>
      </div>
      <?php endfor; ?>
    </div>
    <div class="mt muted">💰 = AMC renewal due &nbsp; 🌐 = Site maintenance visit</div>
    <?php
    include __DIR__ . '/includes/footer.php';
    exit;
}

if ($action === 'new' || $action === 'edit') {
    require_perm($action === 'edit' ? 'amc.edit' : 'amc.add');
    $c = $action === 'edit' ? row('SELECT * FROM amc_contracts WHERE id = ?', [(int)get('id')]) : null;
    if ($action === 'edit' && !$c) die('Contract not found.');
    $parties = all("SELECT id, name, mobile FROM parties WHERE is_active = 1 ORDER BY name");
    if (!$serviceItems) {
        flash('પહેલા Items માં "Service" type નો item બનાવો (દા.ત. "CCTV AMC", "Computer AMC") - AMC contract એ item સાથે link થાય છે.', 'error');
        redirect('items.php?action=new');
    }
    $page_title = $c ? 'Edit AMC Contract' : 'New AMC Contract';
    include __DIR__ . '/includes/header.php';
    ?>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="save">
      <?php if ($c): ?><input type="hidden" name="id" value="<?= $c['id'] ?>"><?php endif; ?>
      <div class="card">
        <div class="form-row cols-2">
          <div><label>Customer / Party *</label>
            <select name="party_id" required>
              <option value="">-- select --</option>
              <?php foreach ($parties as $p): ?>
              <option value="<?= $p['id'] ?>" <?= ($c['party_id'] ?? 0) == $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option>
              <?php endforeach; ?>
            </select></div>
          <div><label>AMC Service Item *</label>
            <select name="item_id" required>
              <?php foreach ($serviceItems as $it): ?>
              <option value="<?= $it['id'] ?>" <?= ($c['item_id'] ?? 0) == $it['id'] ? 'selected' : '' ?>><?= e($it['name']) ?></option>
              <?php endforeach; ?>
            </select></div>
        </div>
        <div class="field"><label>Title / description</label><input type="text" name="title" value="<?= e($c['title'] ?? '') ?>" placeholder="દા.ત. CCTV AMC - 4 Camera, Dwarka branch"></div>
        <div class="form-row cols-4">
          <div><label>Amount per cycle (₹) *</label><input type="number" step="any" name="amount" value="<?= e($c['amount'] ?? '0') ?>" required></div>
          <div><label>Billing Cycle</label>
            <select name="billing_cycle">
              <?php foreach (['monthly' => 'Monthly', 'quarterly' => 'Quarterly (3 મહિને)', 'half_yearly' => 'Half-Yearly (6 મહિને)', 'yearly' => 'Yearly (વર્ષે)'] as $k => $l): ?>
              <option value="<?= $k ?>" <?= ($c['billing_cycle'] ?? 'yearly') === $k ? 'selected' : '' ?>><?= $l ?></option>
              <?php endforeach; ?>
            </select></div>
          <div><label>Firm / Company</label>
            <select name="company_id"><?php foreach ($companies as $co): ?><option value="<?= $co['id'] ?>" <?= ($c['company_id'] ?? 1) == $co['id'] ? 'selected' : '' ?>><?= e($co['name']) ?></option><?php endforeach; ?></select></div>
          <div><label>Location</label>
            <select name="location_id"><?php foreach ($locations as $l): ?><option value="<?= $l['id'] ?>" <?= ($c['location_id'] ?? $u['location_id']) == $l['id'] ? 'selected' : '' ?>><?= e($l['name']) ?></option><?php endforeach; ?></select></div>
        </div>
        <div class="form-row cols-3">
          <div><label>Start date</label><input type="date" name="start_date" value="<?= e($c['start_date'] ?? today()) ?>"></div>
          <div><label>Next renewal date *</label><input type="date" name="next_bill_date" value="<?= e($c['next_bill_date'] ?? today()) ?>" required></div>
          <div><label>End date (ખાલી = ongoing)</label><input type="date" name="end_date" value="<?= e($c['end_date'] ?? '') ?>"></div>
        </div>
        <div class="field"><label>Notes</label><input type="text" name="notes" value="<?= e($c['notes'] ?? '') ?>"></div>
        <button class="btn btn-block mt" type="submit">💾 Save Contract</button>
      </div>
    </form>
    <?php
    include __DIR__ . '/includes/footer.php';
    exit;
}

// ---------- list ----------
$contracts = all("SELECT ac.*, p.name party_name, p.mobile party_mobile, i.name item_name
                  FROM amc_contracts ac
                  JOIN parties p ON p.id = ac.party_id
                  JOIN items i ON i.id = ac.item_id
                  ORDER BY ac.status = 'active' DESC, ac.next_bill_date");
$dueCount = count(array_filter($contracts, fn($c) => $c['status'] === 'active' && $c['next_bill_date'] <= today()));
$activeTotal = array_sum(array_map(fn($c) => $c['status'] === 'active' ? (float)$c['amount'] : 0, $contracts));
$page_title = 'AMC / Recurring Billing';
include __DIR__ . '/includes/header.php';
?>
<div class="duo-cards">
  <div class="duo-card" style="background:#e0f2fe"><div class="duo-label" style="color:#075985">Active Contracts</div><div class="duo-value" style="color:#0369a1"><?= count(array_filter($contracts, fn($c) => $c['status'] === 'active')) ?></div></div>
  <div class="duo-card <?= $dueCount ? 'duo-give' : '' ?>"><div class="duo-label">Renewal Due Now</div><div class="duo-value"><?= $dueCount ?></div></div>
</div>
<div class="page-actions">
  <?php if (can('amc.add')): ?><a class="btn" href="amc.php?action=new">+ New AMC Contract</a><?php endif; ?>
  <a class="btn btn-outline" href="amc.php?action=calendar">📅 Calendar</a>
</div>
<?php if ($dueCount): ?>
<div class="flash flash-info">⏰ <?= $dueCount ?> contract(s) નું renewal due છે. cron ચાલુ હોય તો આપોઆપ bill બની જશે, નહીં તો નીચે "⚡ Generate Now" દબાવો.</div>
<?php endif; ?>
<div class="table-wrap">
<table>
  <thead><tr><th>Party</th><th>Title / Item</th><th class="num">Amount</th><th>Cycle</th><th>Next Renewal</th><th>Status</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($contracts as $c): $due = $c['status'] === 'active' && $c['next_bill_date'] <= today(); ?>
    <tr>
      <td><?= e($c['party_name']) ?><br><span class="muted"><?= e($c['party_mobile']) ?></span></td>
      <td><?= e($c['title'] ?: $c['item_name']) ?><br><span class="muted"><?= e($c['item_name']) ?></span></td>
      <td class="num">₹<?= money($c['amount']) ?></td>
      <td><?= e(ucfirst(str_replace('_', ' ', $c['billing_cycle']))) ?></td>
      <td><?= dmy($c['next_bill_date']) ?><?= $due ? ' <span class="badge badge-bad">Due</span>' : '' ?></td>
      <td>
        <?php if ($c['status'] === 'active'): ?><span class="badge badge-ok">Active</span>
        <?php elseif ($c['status'] === 'paused'): ?><span class="badge badge-warn">Paused</span>
        <?php else: ?><span class="badge badge-bad">Cancelled</span><?php endif; ?>
      </td>
      <td style="white-space:nowrap">
        <?php if (can('amc.edit')): ?>
        <a class="btn btn-sm btn-outline" href="amc.php?action=edit&id=<?= $c['id'] ?>">Edit</a>
        <?php if ($c['status'] === 'active'): ?>
        <form method="post" style="display:inline" onsubmit="return confirm('Invoice હમણાં જ generate કરવું?')"><?= csrf_field() ?><input type="hidden" name="do" value="generate"><input type="hidden" name="id" value="<?= $c['id'] ?>"><button class="btn btn-sm btn-success" type="submit">⚡ Generate Now</button></form>
        <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="do" value="status"><input type="hidden" name="status" value="paused"><input type="hidden" name="id" value="<?= $c['id'] ?>"><button class="btn btn-sm btn-muted" type="submit">Pause</button></form>
        <?php else: ?>
        <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="do" value="status"><input type="hidden" name="status" value="active"><input type="hidden" name="id" value="<?= $c['id'] ?>"><button class="btn btn-sm btn-success" type="submit">Resume</button></form>
        <?php endif; ?>
        <?php endif; ?>
        <?php if (can('amc.delete')): ?>
        <form method="post" style="display:inline" onsubmit="return confirm('Contract delete કરવો?')"><?= csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= $c['id'] ?>"><button class="btn btn-sm btn-danger" type="submit">✕</button></form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; if (!$contracts): ?><tr><td colspan="7" class="muted">કોઈ AMC contract નથી. "+ New AMC Contract" દબાવીને શરૂ કરો.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
