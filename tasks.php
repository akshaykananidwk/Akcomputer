<?php
// Field tasks: assign to staff, start/end time, material used (deducted from staff stock)
require_once __DIR__ . '/includes/init.php';
require_perm('tasks.view');
$u = current_user();
$action = get('action', 'list');

// ---------- create ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'save') {
    require_perm('tasks.add');
    q('INSERT INTO tasks (party_id, customer_name, customer_mobile, address, assigned_to, description, scheduled_date, location_id, created_by)
       VALUES (?,?,?,?,?,?,?,?,?)',
      [(int)post('party_id') ?: null, post('customer_name'), post('customer_mobile'), post('address'),
       (int)post('assigned_to'), post('description'), post('scheduled_date') ?: null, $u['location_id'], $u['id']]);
    $tid = insert_id();
    q('UPDATE tasks SET task_no = ? WHERE id = ?', [doc_no('TSK', $tid), $tid]);
    $staff = row('SELECT * FROM users WHERE id = ?', [(int)post('assigned_to')]);
    if ($staff && $staff['mobile']) {
        send_whatsapp($staff['mobile'], wa_template('task', [
            'task_no' => doc_no('TSK', $tid), 'customer' => post('customer_name'),
            'mobile' => post('customer_mobile'), 'address' => post('address'), 'work' => post('description'),
        ]));
    }
    log_activity('task_add', doc_no('TSK', $tid));
    flash('Task assigned' . ($staff && $staff['mobile'] ? ' & sent on WhatsApp.' : '.'));
    redirect('tasks.php');
}

// ---------- start ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'start') {
    $t = row('SELECT * FROM tasks WHERE id = ?', [(int)post('id')]);
    if ($t && ($t['assigned_to'] == $u['id'] || can('tasks.all')) && $t['status'] === 'assigned') {
        q("UPDATE tasks SET status='started', start_time=NOW() WHERE id=?", [$t['id']]);
        flash('Task started - timer running ⏱');
    }
    redirect('tasks.php?action=view&id=' . (int)post('id'));
}

// ---------- complete (with materials) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'complete') {
    $t = row('SELECT * FROM tasks WHERE id = ?', [(int)post('id')]);
    if (!$t || !($t['assigned_to'] == $u['id'] || can('tasks.all')) || !in_array($t['status'], ['assigned', 'started'])) {
        flash('Cannot complete this task.', 'error');
        redirect('tasks.php');
    }
    $item_ids = post('m_item', []);
    $qtys = post('m_qty', []);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $matTotal = 0;
        foreach ($item_ids as $i => $iid) {
            $iid = (int)$iid; $qty = (float)($qtys[$i] ?? 0);
            if (!$iid || $qty <= 0) continue;
            $staffQty = staff_stock_qty($t['assigned_to'], $iid);
            $item = row('SELECT * FROM items WHERE id = ?', [$iid]);
            if ($staffQty < $qty) throw new Exception("Staff holds only $staffQty of {$item['name']}.");
            $price = (float)$item['selling_price'];
            q('INSERT INTO task_materials (task_id, item_id, qty, price, total) VALUES (?,?,?,?,?)',
              [$t['id'], $iid, $qty, $price, $qty * $price]);
            adjust_staff_stock($t['assigned_to'], $iid, -$qty, 'task_use', $t['id'], $t['task_no']);
            $matTotal += $qty * $price;
        }
        q("UPDATE tasks SET status='completed', end_time=NOW(), work_done=?, service_charge=?, material_total=?,
           start_time=COALESCE(start_time, NOW()) WHERE id=?",
          [post('work_done'), (float)post('service_charge'), $matTotal, $t['id']]);
        $pdo->commit();
        log_activity('task_complete', $t['task_no']);
        flash('Task completed. Material used deducted from your stock.');
    } catch (Exception $ex) {
        $pdo->rollBack();
        flash('Error: ' . $ex->getMessage(), 'error');
    }
    redirect('tasks.php?action=view&id=' . $t['id']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'cancel' && can('tasks.edit')) {
    q("UPDATE tasks SET status='cancelled' WHERE id=? AND status IN ('assigned','started')", [(int)post('id')]);
    flash('Task cancelled.');
    redirect('tasks.php');
}

// ---------- new form ----------
if ($action === 'new') {
    require_perm('tasks.add');
    $staffList = all('SELECT id, name FROM users WHERE is_active = 1 ORDER BY name');
    $parties = all("SELECT id, name, mobile, address FROM parties WHERE is_active = 1 AND type IN ('customer','both') ORDER BY name");
    $page_title = 'New Task';
    include __DIR__ . '/includes/header.php';
    ?>
    <div class="card">
      <h2>Assign field task</h2>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="save">
        <div class="form-row cols-2">
          <div><label>Party (optional)</label>
            <select name="party_id" id="party_id" onchange="fillParty(this)">
              <option value="">-- manual entry --</option>
              <?php foreach ($parties as $p): ?>
              <option value="<?= $p['id'] ?>" data-mobile="<?= e($p['mobile']) ?>" data-address="<?= e($p['address']) ?>"><?= e($p['name']) ?></option>
              <?php endforeach; ?>
            </select></div>
          <div><label>Assign to staff *</label>
            <select name="assigned_to" required>
              <option value="">-- select --</option>
              <?php foreach ($staffList as $s): ?><option value="<?= $s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?>
            </select></div>
        </div>
        <div class="form-row cols-3">
          <div><label>Customer name *</label><input type="text" name="customer_name" id="customer_name" required></div>
          <div><label>Mobile</label><input type="tel" name="customer_mobile" id="customer_mobile"></div>
          <div><label>Schedule date</label><input type="date" name="scheduled_date" value="<?= today() ?>"></div>
        </div>
        <div class="field"><label>Address / Site</label><input type="text" name="address" id="address"></div>
        <div class="field"><label>Work description</label><textarea name="description" rows="3" required></textarea></div>
        <button class="btn" type="submit">Assign & Notify on WhatsApp</button>
      </form>
    </div>
    <script>
      function fillParty(sel) {
        var o = sel.options[sel.selectedIndex];
        if (sel.value) {
          document.getElementById('customer_name').value = o.textContent.trim();
          document.getElementById('customer_mobile').value = o.dataset.mobile || '';
          document.getElementById('address').value = o.dataset.address || '';
        }
      }
    </script>
    <?php
    include __DIR__ . '/includes/footer.php';
    exit;
}

// ---------- view ----------
if ($action === 'view') {
    $t = row('SELECT t.*, s.name staff_name FROM tasks t JOIN users s ON s.id = t.assigned_to WHERE t.id = ?', [(int)get('id')]);
    if (!$t) die('Task not found');
    if (!can('tasks.all') && $t['assigned_to'] != $u['id'] && $t['created_by'] != $u['id']) die('Access denied.');
    $mats = all('SELECT tm.*, i.name, i.unit FROM task_materials tm JOIN items i ON i.id = tm.item_id WHERE tm.task_id = ?', [$t['id']]);
    $myItems = all('SELECT ss.item_id, ss.qty, i.name FROM staff_stock ss JOIN items i ON i.id = ss.item_id
                    WHERE ss.user_id = ? AND ss.qty > 0 ORDER BY i.name', [$t['assigned_to']]);
    $mins = ($t['start_time'] && $t['end_time']) ? round((strtotime($t['end_time']) - strtotime($t['start_time'])) / 60) : null;
    $page_title = 'Task ' . $t['task_no'];
    include __DIR__ . '/includes/header.php';
    ?>
    <div class="card">
      <h2><?= e($t['task_no']) ?> <?= status_badge($t['status']) ?></h2>
      <p><strong><?= e($t['customer_name']) ?></strong> · <a href="tel:<?= e($t['customer_mobile']) ?>"><?= e($t['customer_mobile']) ?></a><br>
      📍 <?= e($t['address']) ?><br>
      Staff: <?= e($t['staff_name']) ?> · Scheduled: <?= dmy($t['scheduled_date']) ?></p>
      <p class="mt"><?= nl2br(e($t['description'])) ?></p>
      <?php if ($t['start_time']): ?>
      <p class="mt muted">⏱ Start: <?= dmyt($t['start_time']) ?><?= $t['end_time'] ? ' · End: ' . dmyt($t['end_time']) . " · Duration: $mins min" : ' (running...)' ?></p>
      <?php endif; ?>
    </div>

    <?php if ($t['status'] === 'assigned' && ($t['assigned_to'] == $u['id'] || can('tasks.all'))): ?>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="start"><input type="hidden" name="id" value="<?= $t['id'] ?>">
      <button class="btn btn-block" type="submit">▶ Start Task (reach site)</button></form>
    <?php endif; ?>

    <?php if (in_array($t['status'], ['assigned', 'started']) && ($t['assigned_to'] == $u['id'] || can('tasks.all'))): ?>
    <div class="card mt">
      <h3>✔ Complete task</h3>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="complete"><input type="hidden" name="id" value="<?= $t['id'] ?>">
        <div class="field"><label>Work done</label><textarea name="work_done" rows="3" required></textarea></div>
        <div class="field"><label>Service charge (₹)</label><input type="number" step="any" name="service_charge" value="0"></div>
        <?php if ($myItems): ?>
          <h3 class="mt">Material used (from staff stock)</h3>
          <?php foreach ($myItems as $mi): ?>
          <div class="form-row cols-2">
            <div><label><?= e($mi['name']) ?> (holding <?= (float)$mi['qty'] ?>)</label>
              <input type="hidden" name="m_item[]" value="<?= $mi['item_id'] ?>">
              <input type="number" step="any" min="0" max="<?= (float)$mi['qty'] ?>" name="m_qty[]" value="0"></div>
          </div>
          <?php endforeach; ?>
        <?php else: ?><p class="muted">Staff holds no stock (material usage will be empty).</p><?php endif; ?>
        <button class="btn btn-success btn-block mt" type="submit">Complete Task</button>
      </form>
    </div>
    <?php endif; ?>

    <?php if ($mats): ?>
    <div class="card">
      <h3>Material used</h3>
      <table class="table-sm">
        <?php foreach ($mats as $m): ?>
        <tr><td><?= e($m['name']) ?></td><td class="num"><?= (float)$m['qty'] ?> <?= e($m['unit']) ?></td><td class="num">₹<?= money($m['total']) ?></td></tr>
        <?php endforeach; ?>
        <tr><td><strong>Material total</strong></td><td></td><td class="num"><strong>₹<?= money($t['material_total']) ?></strong></td></tr>
        <tr><td><strong>Service charge</strong></td><td></td><td class="num"><strong>₹<?= money($t['service_charge']) ?></strong></td></tr>
      </table>
      <?php if ($t['work_done']): ?><p class="mt"><?= nl2br(e($t['work_done'])) ?></p><?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if (in_array($t['status'], ['assigned', 'started']) && can('tasks.edit')): ?>
    <form method="post" onsubmit="return confirm('Cancel task?')"><?= csrf_field() ?>
      <input type="hidden" name="do" value="cancel"><input type="hidden" name="id" value="<?= $t['id'] ?>">
      <button class="btn btn-danger btn-sm" type="submit">Cancel task</button></form>
    <?php endif; ?>
    <?php
    include __DIR__ . '/includes/footer.php';
    exit;
}

// ---------- list ----------
$where = ' 1=1 ';
$params = [];
if (!can('tasks.all')) { $where = ' (t.assigned_to = ? OR t.created_by = ?) '; $params = [$u['id'], $u['id']]; }
$st = get('st');
if ($st) { $where .= ' AND t.status = ? '; $params[] = $st; }
$tasks = all("SELECT t.*, s.name staff_name FROM tasks t JOIN users s ON s.id = t.assigned_to
              WHERE $where ORDER BY t.id DESC LIMIT 300", $params);
$page_title = 'Field Tasks';
include __DIR__ . '/includes/header.php';
?>
<div class="page-actions">
  <?php if (can('tasks.add')): ?><a class="btn" href="tasks.php?action=new">+ Assign Task</a><?php endif; ?>
  <select onchange="location='tasks.php?st='+this.value" style="max-width:170px">
    <option value="">All statuses</option>
    <?php foreach (['assigned', 'started', 'completed', 'cancelled'] as $s): ?>
    <option value="<?= $s ?>" <?= $st === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
    <?php endforeach; ?>
  </select>
</div>
<div class="table-wrap">
<table>
  <thead><tr><th>No</th><th>Customer</th><th>Staff</th><th>Date</th><th>Status</th><th class="num">Time</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($tasks as $t):
      $mins = ($t['start_time'] && $t['end_time']) ? round((strtotime($t['end_time']) - strtotime($t['start_time'])) / 60) : null; ?>
    <tr>
      <td><strong><?= e($t['task_no']) ?></strong></td>
      <td><?= e($t['customer_name']) ?><br><span class="muted"><?= e(mb_substr($t['description'], 0, 40)) ?></span></td>
      <td><?= e($t['staff_name']) ?></td>
      <td><?= dmy($t['scheduled_date']) ?></td>
      <td><?= status_badge($t['status']) ?></td>
      <td class="num"><?= $mins !== null ? $mins . ' min' : '-' ?></td>
      <td><a class="btn btn-sm btn-outline" href="tasks.php?action=view&id=<?= $t['id'] ?>">View</a></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
