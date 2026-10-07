<?php
// A salon's day: who is coming when, for what, and with whom. A finished
// appointment becomes a normal bill (sales.php?appt=), so money and the
// ledger follow the usual rules. Also prepaid packages ("6 haircuts") and
// what each staff member's services brought in.
require_once __DIR__ . '/includes/init.php';
require_perm('sales.view');
if (!biz_on('biz_appointments')) { flash('Appointments are for salons and clinics — switch them on in Settings → Type of business.', 'info'); redirect('sales.php'); }
$u = current_user();
$day = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)get('d')) ? get('d') : today();
$tab = get('tab', 'day');

function appt_party_by_mobile($mobile) {
    $m = preg_replace('/\D/', '', (string)$mobile);
    if (strlen($m) < 10) return null;
    return row("SELECT id, name, mobile FROM parties WHERE RIGHT(REPLACE(mobile, ' ', ''), 10) = ? AND is_active = 1 LIMIT 1", [substr($m, -10)]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_perm('sales.add');
    $do = post('do');
    if ($do === 'book') {
        $d = post('appt_date'); $t = post('appt_time'); $dur = max(5, min(600, (int)post('duration_min') ?: 30));
        $staff = (int)post('staff_id') ?: null; $item = (int)post('item_id') ?: null;
        $name = trim((string)post('customer_name')); $mobile = trim((string)post('customer_mobile'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || !preg_match('/^\d{2}:\d{2}$/', $t) || ($name === '' && $mobile === '')) {
            flash('Give a date, a time and the customer.', 'error'); redirect('appointments.php?d=' . $day);
        }
        // the same person cannot be in two chairs at once
        if ($staff && ($clash = row("SELECT customer_name, appt_time FROM appointments WHERE staff_id = ? AND appt_date = ? AND status = 'booked'
                  AND appt_time < ADDTIME(?, SEC_TO_TIME(? * 60)) AND ADDTIME(appt_time, SEC_TO_TIME(duration_min * 60)) > ?", [$staff, $d, "$t:00", $dur, "$t:00"]))) {
            flash('That staff member is busy then (' . $clash['customer_name'] . ' at ' . substr($clash['appt_time'], 0, 5) . '). Pick another time or person.', 'error');
            redirect('appointments.php?d=' . $d);
        }
        $p = appt_party_by_mobile($mobile);
        q('INSERT INTO appointments (appt_date, appt_time, duration_min, party_id, customer_name, customer_mobile, item_id, staff_id, notes, created_by)
           VALUES (?,?,?,?,?,?,?,?,?,?)', [$d, "$t:00", $dur, $p['id'] ?? null, $name ?: ($p['name'] ?? ''), $mobile, $item, $staff, mb_substr((string)post('notes'), 0, 255), $u['id']]);
        log_activity('appt_book', "$name $d $t");
        flash('Booked.');
        redirect('appointments.php?d=' . $d);
    }
    if ($do === 'status' && in_array(post('status'), ['no_show', 'cancelled', 'booked'], true)) {
        q('UPDATE appointments SET status = ? WHERE id = ? AND sale_id IS NULL', [post('status'), (int)post('id')]);
        redirect('appointments.php?d=' . $day);
    }
    if ($do === 'pkg_add') {
        $p = appt_party_by_mobile(post('mobile'));
        $n = (int)post('sessions_total');
        if (!$p || $n < 1 || trim((string)post('name')) === '') {
            flash($p ? 'Give the package a name and the number of visits.' : 'No customer with that mobile — add them in Parties first.', 'error');
            redirect('appointments.php?tab=packages');
        }
        q('INSERT INTO customer_packages (party_id, name, item_id, sessions_total, price, valid_till, created_by) VALUES (?,?,?,?,?,?,?)',
          [$p['id'], mb_substr(trim(post('name')), 0, 120), (int)post('item_id') ?: null, $n, (float)post('price'), post('valid_till') ?: null, $u['id']]);
        log_activity('package_add', $p['name'] . ': ' . post('name'));
        flash('Package added for ' . $p['name'] . '. Make its bill as usual so the money is in the books.');
        redirect('appointments.php?tab=packages');
    }
    if ($do === 'pkg_use') {
        $pk = row('SELECT * FROM customer_packages WHERE id = ?', [(int)post('id')]);
        if ($pk && $pk['sessions_used'] < $pk['sessions_total'] && (!$pk['valid_till'] || $pk['valid_till'] >= today())) {
            q('UPDATE customer_packages SET sessions_used = sessions_used + 1 WHERE id = ? AND sessions_used < sessions_total', [$pk['id']]);
            q('INSERT INTO package_uses (package_id, used_on, staff_id, note, created_by) VALUES (?,?,?,?,?)',
              [$pk['id'], today(), (int)post('staff_id') ?: null, '', $u['id']]);
            flash('One visit used — ' . ($pk['sessions_total'] - $pk['sessions_used'] - 1) . ' left.');
        } else flash('This package has no visits left or has run out of date.', 'error');
        redirect('appointments.php?tab=packages');
    }
    redirect('appointments.php');
}

$staff = all('SELECT id, name FROM users WHERE is_active = 1 ORDER BY name');
$services = all("SELECT id, name, selling_price FROM items WHERE is_active = 1 AND item_type = 'service' ORDER BY name");
$page_title = 'Appointments';
include __DIR__ . '/includes/header.php';
?>
<div class="page-head"><h1>📅 Appointments</h1>
  <div class="tabs" style="display:flex;gap:6px">
    <a class="btn btn-sm <?= $tab === 'day' ? '' : 'btn-outline' ?>" href="appointments.php">Day</a>
    <a class="btn btn-sm <?= $tab === 'packages' ? '' : 'btn-outline' ?>" href="appointments.php?tab=packages">Packages</a>
    <?php if (can('reports.view')): ?><a class="btn btn-sm <?= $tab === 'staff' ? '' : 'btn-outline' ?>" href="appointments.php?tab=staff">Staff earnings</a><?php endif; ?>
  </div>
</div>

<?php if ($tab === 'packages'):
  $pkgs = all('SELECT cp.*, p.name party, p.mobile, i.name service FROM customer_packages cp JOIN parties p ON p.id = cp.party_id
               LEFT JOIN items i ON i.id = cp.item_id ORDER BY (cp.sessions_used < cp.sessions_total) DESC, cp.id DESC LIMIT 200'); ?>
<div class="card"><form method="post" class="form-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:8px;align-items:end">
  <?= csrf_field() ?><input type="hidden" name="do" value="pkg_add">
  <div class="field"><label>Customer mobile</label><input type="tel" name="mobile" required></div>
  <div class="field"><label>Package name</label><input type="text" name="name" placeholder="6 haircuts" required></div>
  <div class="field"><label>Service</label><select name="item_id"><option value="">Any</option><?php foreach ($services as $s): ?><option value="<?= $s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
  <div class="field"><label>Visits</label><input type="number" name="sessions_total" min="1" value="6" required></div>
  <div class="field"><label>Price ₹</label><input type="number" name="price" min="0" step="0.01"></div>
  <div class="field"><label>Valid till</label><input type="date" name="valid_till"></div>
  <button class="btn" type="submit">➕ Add package</button>
</form></div>
<div class="pane"><div class="pane-body tight"><table class="rowlist">
  <thead><tr><th>Customer</th><th>Package</th><th class="num">Left</th><th>Valid till</th><th class="act"></th></tr></thead><tbody>
  <?php foreach ($pkgs as $p): $left = $p['sessions_total'] - $p['sessions_used']; $dead = $p['valid_till'] && $p['valid_till'] < today(); ?>
  <tr><td data-l="Customer"><strong><?= e($p['party']) ?></strong> <span class="muted"><?= e($p['mobile']) ?></span></td>
    <td data-l="Package"><?= e($p['name']) ?><?= $p['service'] ? ' <span class="muted">· ' . e($p['service']) . '</span>' : '' ?></td>
    <td class="num" data-l="Left"><b><?= $left ?></b> / <?= (int)$p['sessions_total'] ?></td>
    <td data-l="Valid till" class="<?= $dead ? 'text-danger' : 'muted' ?>"><?= $p['valid_till'] ? dmy($p['valid_till']) : '—' ?></td>
    <td class="act"><?php if ($left > 0 && !$dead): ?><form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="do" value="pkg_use"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
      <button class="btn btn-sm" type="submit">✔ Use 1 visit</button></form><?php endif; ?></td></tr>
  <?php endforeach; if (!$pkgs): ?><tr><td class="muted">No packages yet.</td></tr><?php endif; ?>
  </tbody></table></div></div>

<?php elseif ($tab === 'staff' && can('reports.view')):
  $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)get('from')) ? get('from') : date('Y-m-01');
  $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)get('to')) ? get('to') : today();
  $rows = all("SELECT COALESCE(us.name, '— not set') name, COUNT(*) n, SUM(si.total) amt FROM sale_items si
               JOIN sales s ON s.id = si.sale_id AND s.is_cancelled = 0 JOIN items i ON i.id = si.item_id AND i.item_type = 'service'
               LEFT JOIN users us ON us.id = si.staff_id WHERE s.sale_date BETWEEN ? AND ? GROUP BY si.staff_id, us.name ORDER BY amt DESC", [$from, $to]); ?>
<form class="card" style="display:flex;gap:8px;align-items:end;flex-wrap:wrap">
  <input type="hidden" name="tab" value="staff">
  <div class="field"><label>From</label><input type="date" name="from" value="<?= e($from) ?>"></div>
  <div class="field"><label>To</label><input type="date" name="to" value="<?= e($to) ?>"></div>
  <button class="btn btn-outline" type="submit">Show</button></form>
<div class="pane"><div class="pane-body tight"><table class="rowlist">
  <thead><tr><th>Staff</th><th class="num">Services</th><th class="num">Amount</th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?><tr><td data-l="Staff"><strong><?= e($r['name']) ?></strong></td><td class="num" data-l="Services"><?= (int)$r['n'] ?></td><td class="num" data-l="Amount">₹<?= money($r['amt']) ?></td></tr><?php endforeach; ?>
  <?php if (!$rows): ?><tr><td class="muted">No services billed in these dates.</td></tr><?php endif; ?>
  </tbody></table></div></div>

<?php else:
  $list = all("SELECT a.*, i.name service, us.name staff FROM appointments a LEFT JOIN items i ON i.id = a.item_id
               LEFT JOIN users us ON us.id = a.staff_id WHERE a.appt_date = ? ORDER BY a.appt_time", [$day]); ?>
<div class="card" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
  <a class="btn btn-sm btn-outline" href="appointments.php?d=<?= date('Y-m-d', strtotime("$day -1 day")) ?>">◀</a>
  <form><input type="date" name="d" value="<?= e($day) ?>" onchange="this.form.submit()"></form>
  <a class="btn btn-sm btn-outline" href="appointments.php?d=<?= date('Y-m-d', strtotime("$day +1 day")) ?>">▶</a>
  <span class="muted"><?= count(array_filter($list, fn($a) => $a['status'] === 'booked')) ?> waiting</span>
</div>
<?php if (can('sales.add')): ?>
<details class="card" <?= get('action') === 'new' ? 'open' : '' ?>><summary style="cursor:pointer;font-weight:600">➕ Book an appointment</summary>
<form method="post" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:8px;align-items:end;margin-top:10px">
  <?= csrf_field() ?><input type="hidden" name="do" value="book">
  <div class="field"><label>Date</label><input type="date" name="appt_date" value="<?= e($day) ?>" required></div>
  <div class="field"><label>Time</label><input type="time" name="appt_time" required></div>
  <div class="field"><label>Minutes</label><input type="number" name="duration_min" value="30" min="5" step="5"></div>
  <div class="field"><label>Customer name</label><input type="text" name="customer_name"></div>
  <div class="field"><label>Mobile</label><input type="tel" name="customer_mobile"></div>
  <div class="field"><label>Service</label><select name="item_id"><option value="">—</option><?php foreach ($services as $s): ?><option value="<?= $s['id'] ?>"><?= e($s['name']) ?> (₹<?= money($s['selling_price']) ?>)</option><?php endforeach; ?></select></div>
  <div class="field"><label>Staff</label><select name="staff_id"><option value="">Anyone</option><?php foreach ($staff as $s): ?><option value="<?= $s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
  <div class="field"><label>Note</label><input type="text" name="notes"></div>
  <button class="btn" type="submit">Book</button>
</form></details>
<?php endif; ?>
<div class="pane"><div class="pane-body tight"><table class="rowlist">
  <thead><tr><th>Time</th><th>Customer</th><th>Service</th><th>Staff</th><th>Status</th><th class="act"></th></tr></thead><tbody>
  <?php foreach ($list as $a): ?>
  <tr><td data-l="Time"><b><?= date('h:i A', strtotime($a['appt_time'])) ?></b> <span class="muted"><?= (int)$a['duration_min'] ?>m</span></td>
    <td data-l="Customer"><?= e($a['customer_name']) ?> <span class="muted"><?= e($a['customer_mobile']) ?></span><?= $a['notes'] !== '' ? '<br><small class="muted">' . e($a['notes']) . '</small>' : '' ?></td>
    <td data-l="Service"><?= e($a['service'] ?? '—') ?></td><td data-l="Staff"><?= e($a['staff'] ?? 'Anyone') ?></td>
    <td data-l="Status"><span class="badge badge-<?= ['booked' => 'info', 'done' => 'ok', 'no_show' => 'warn', 'cancelled' => 'muted'][$a['status']] ?? 'info' ?>"><?= e(str_replace('_', ' ', $a['status'])) ?></span></td>
    <td class="act">
      <?php if ($a['sale_id']): ?><a class="btn btn-sm btn-outline" href="sale_view.php?id=<?= (int)$a['sale_id'] ?>">🧾 Bill</a>
      <?php elseif ($a['status'] === 'booked' && can('sales.add')): ?>
        <?php if ($a['item_id']): ?><a class="btn btn-sm" href="sales.php?action=new&amp;appt=<?= (int)$a['id'] ?>">✔ Done → bill</a><?php endif; ?>
        <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="do" value="status"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
          <button class="btn btn-sm btn-outline" name="status" value="no_show" type="submit">Didn't come</button>
          <button class="btn btn-sm btn-outline" name="status" value="cancelled" type="submit" onclick="return confirm('Cancel this appointment?')">✕</button></form>
      <?php endif; ?></td></tr>
  <?php endforeach; if (!$list): ?><tr><td class="muted">Nothing booked for <?= dmy($day) ?>.</td></tr><?php endif; ?>
  </tbody></table></div></div>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
