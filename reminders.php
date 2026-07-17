<?php
// Reminders: schedule a WhatsApp reminder (title, one or more numbers, a note)
// to go out on a chosen date & time - one-time or recurring. Anything: a
// computer/CCTV job to follow up, a staff task ("collect the parcel"), a
// customer call-back. Fired by cron.php (see the reminder block there), which
// should be called frequently (e.g. every 15 min) for on-time delivery.
require_once __DIR__ . '/includes/init.php';
require_perm('reminders.view');
$u = current_user();

$CATS = [
    'general' => '🔔 General', 'computer' => '💻 Computer job', 'cctv' => '📹 CCTV job',
    'staff' => '🧑‍🔧 Staff task', 'customer' => '👤 Customer', 'payment' => '💰 Payment', 'delivery' => '📦 Delivery / Parcel',
];

/** Reads the repeatable recipient rows (name[] + mobile[]) from the POST. */
function reminder_post_recipients() {
    $names = post('rec_name', []); $mobiles = post('rec_mobile', []);
    $out = [];
    foreach ($mobiles as $i => $m) {
        $m = trim(preg_replace('/[^0-9+]/', '', (string)$m));
        if ($m === '') continue;
        $out[] = ['name' => trim((string)($names[$i] ?? '')), 'mobile' => $m];
    }
    return $out;
}

// ---------- save (new) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'save') {
    require_perm('reminders.add');
    $title = trim(post('title'));
    $date = post('remind_date'); $time = post('remind_time') ?: '09:00';
    $recips = reminder_post_recipients();
    if ($title === '' || $date === '' || !$recips) {
        flash('Title, date and at least one mobile number are required.', 'error');
        redirect('reminders.php?action=new');
    }
    $remindAt = $date . ' ' . $time . ':00';
    q('INSERT INTO reminders (title, notes, category, remind_at, repeat_freq, repeat_until, party_id, created_by)
       VALUES (?,?,?,?,?,?,?,?)',
      [$title, post('notes'), post('category', 'general'), $remindAt, post('repeat_freq', 'once'),
       post('repeat_until') ?: null, (int)post('party_id') ?: null, $u['id']]);
    $rid = insert_id();
    foreach ($recips as $rc) q('INSERT INTO reminder_recipients (reminder_id, name, mobile) VALUES (?,?,?)', [$rid, $rc['name'], $rc['mobile']]);
    log_activity('reminder_add', $title . ' @ ' . $remindAt);
    flash('Reminder "' . $title . '" scheduled for ' . dmyt($remindAt) . '.');
    redirect('reminders.php');
}

// ---------- update (edit) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'update') {
    require_perm('reminders.edit');
    $rid = (int)post('id');
    $rem = row('SELECT * FROM reminders WHERE id = ?', [$rid]);
    if (!$rem) { flash('Reminder not found.', 'error'); redirect('reminders.php'); }
    $title = trim(post('title'));
    $date = post('remind_date'); $time = post('remind_time') ?: '09:00';
    $recips = reminder_post_recipients();
    if ($title === '' || $date === '' || !$recips) {
        flash('Title, date and at least one mobile number are required.', 'error');
        redirect('reminders.php?action=edit&id=' . $rid);
    }
    // Editing a fired reminder revives it so the new date/time fires again.
    q('UPDATE reminders SET title=?, notes=?, category=?, remind_at=?, repeat_freq=?, repeat_until=?, party_id=?, status="pending" WHERE id=?',
      [$title, post('notes'), post('category', 'general'), $date . ' ' . $time . ':00', post('repeat_freq', 'once'),
       post('repeat_until') ?: null, (int)post('party_id') ?: null, $rid]);
    q('DELETE FROM reminder_recipients WHERE reminder_id = ?', [$rid]);
    foreach ($recips as $rc) q('INSERT INTO reminder_recipients (reminder_id, name, mobile) VALUES (?,?,?)', [$rid, $rc['name'], $rc['mobile']]);
    log_activity('reminder_edit', $title);
    flash('Reminder updated.');
    redirect('reminders.php');
}

// ---------- send now ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'send_now') {
    require_perm('reminders.edit');
    $rem = row('SELECT * FROM reminders WHERE id = ?', [(int)post('id')]);
    if ($rem) {
        list($sent, $failed) = reminder_fire($rem);
        if ($sent && !$failed) flash("Reminder sent on WhatsApp to $sent number(s).");
        elseif ($sent) flash("Sent to $sent, $failed failed. " . whatsapp_last_error(), 'error');
        else flash('Send failed. ' . whatsapp_last_error(), 'error');
    }
    redirect('reminders.php');
}

// ---------- mark done ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'done') {
    require_perm('reminders.edit');
    q('UPDATE reminders SET status = "done" WHERE id = ?', [(int)post('id')]);
    flash('Reminder marked done.');
    redirect('reminders.php');
}

// ---------- delete ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'delete') {
    require_perm('reminders.delete');
    $rid = (int)post('id');
    q('DELETE FROM reminder_recipients WHERE reminder_id = ?', [$rid]);
    q('DELETE FROM reminders WHERE id = ?', [$rid]);
    flash('Reminder deleted.');
    redirect('reminders.php');
}

// ---------- new / edit form ----------
if (get('action') === 'new' || get('action') === 'edit') {
    $isEdit = get('action') === 'edit';
    $rem = null; $recs = [];
    if ($isEdit) {
        require_perm('reminders.edit');
        $rem = row('SELECT * FROM reminders WHERE id = ?', [(int)get('id')]);
        if (!$rem) { flash('Reminder not found.', 'error'); redirect('reminders.php'); }
        $recs = all('SELECT * FROM reminder_recipients WHERE reminder_id = ?', [$rem['id']]);
    } else {
        require_perm('reminders.add');
    }
    if (!$recs) $recs = [['name' => '', 'mobile' => '']];
    $parties = all('SELECT id, name, mobile FROM parties WHERE is_active = 1 ORDER BY name');
    $page_title = $isEdit ? 'Edit Reminder' : 'New Reminder';
    include __DIR__ . '/includes/header.php';
    ?>
    <div class="card">
      <h2><?= $isEdit ? '✏️ Edit reminder' : '🔔 New reminder' ?></h2>
      <p class="muted mb">A WhatsApp reminder goes out automatically on the date &amp; time you pick. Add as many numbers as you want. For on-time delivery the site's cron should run every 15 minutes (Settings &gt; Backup &amp; Updates shows the cron URL).</p>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="<?= $isEdit ? 'update' : 'save' ?>">
        <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= $rem['id'] ?>"><?php endif; ?>
        <div class="field"><label>What to remind about? *</label>
          <input type="text" name="title" required placeholder="e.g. Deliver CCTV DVR to Hotel Krishna" value="<?= $isEdit ? e($rem['title']) : '' ?>"></div>
        <div class="form-row cols-3">
          <div><label>Date *</label><input type="date" name="remind_date" required value="<?= $isEdit ? substr($rem['remind_at'], 0, 10) : today() ?>"></div>
          <div><label>Time *</label><input type="time" name="remind_time" required value="<?= $isEdit ? substr($rem['remind_at'], 11, 5) : '09:00' ?>"></div>
          <div><label>Category</label>
            <select name="category">
              <?php foreach ($CATS as $k => $lbl): ?><option value="<?= $k ?>" <?= ($isEdit && $rem['category'] === $k) ? 'selected' : '' ?>><?= e($lbl) ?></option><?php endforeach; ?>
            </select></div>
        </div>
        <div class="form-row cols-2">
          <div><label>Repeat</label>
            <select name="repeat_freq" id="rem_freq" onchange="remFreq()">
              <?php foreach (['once' => 'Do not repeat (one time)', 'daily' => 'Every day', 'weekly' => 'Every week', 'monthly' => 'Every month'] as $k => $lbl): ?>
              <option value="<?= $k ?>" <?= ($isEdit && $rem['repeat_freq'] === $k) ? 'selected' : '' ?>><?= $lbl ?></option><?php endforeach; ?>
            </select></div>
          <div id="rem_until_box" style="display:none"><label>Repeat until (optional)</label>
            <input type="date" name="repeat_until" value="<?= $isEdit && $rem['repeat_until'] ? e($rem['repeat_until']) : '' ?>"></div>
        </div>
        <div class="field"><label>Note (goes into the WhatsApp message)</label>
          <textarea name="notes" rows="3" placeholder="Any extra detail the person should see"><?= $isEdit ? e($rem['notes']) : '' ?></textarea></div>

        <label>Send to (name + WhatsApp number) *</label>
        <div id="recRows">
          <?php foreach ($recs as $rc): ?>
          <div class="rec-row" style="display:flex;gap:8px;margin-bottom:8px">
            <input type="text" name="rec_name[]" placeholder="Name (optional)" value="<?= e($rc['name']) ?>" style="flex:1">
            <input type="tel" name="rec_mobile[]" placeholder="WhatsApp number" value="<?= e($rc['mobile']) ?>" style="flex:1">
            <button type="button" class="btn btn-sm btn-outline" onclick="this.closest('.rec-row').remove()">✕</button>
          </div>
          <?php endforeach; ?>
        </div>
        <div class="page-actions" style="margin-bottom:14px">
          <button type="button" class="btn btn-sm btn-outline" onclick="addRec()">+ Add another number</button>
          <select id="partyPick" onchange="pickParty(this)" style="max-width:220px">
            <option value="">— Pick from a party —</option>
            <?php foreach ($parties as $p): ?><option value="<?= e($p['name']) ?>|<?= e($p['mobile']) ?>"><?= e($p['name']) ?></option><?php endforeach; ?>
          </select>
        </div>

        <button class="btn" type="submit"><?= $isEdit ? '💾 Save changes' : '🔔 Schedule reminder' ?></button>
        <a class="btn btn-muted" href="reminders.php">Cancel</a>
      </form>
    </div>
    <script>
      function remFreq() { document.getElementById('rem_until_box').style.display = document.getElementById('rem_freq').value === 'once' ? 'none' : ''; }
      remFreq();
      function addRec(name, mobile) {
        var wrap = document.getElementById('recRows');
        var div = document.createElement('div');
        div.className = 'rec-row';
        div.style.cssText = 'display:flex;gap:8px;margin-bottom:8px';
        div.innerHTML = '<input type="text" name="rec_name[]" placeholder="Name (optional)" style="flex:1">'
          + '<input type="tel" name="rec_mobile[]" placeholder="WhatsApp number" style="flex:1">'
          + '<button type="button" class="btn btn-sm btn-outline" onclick="this.closest(\'.rec-row\').remove()">✕</button>';
        wrap.appendChild(div);
        if (name) div.querySelector('input[name="rec_name[]"]').value = name;
        if (mobile) div.querySelector('input[name="rec_mobile[]"]').value = mobile;
      }
      function pickParty(sel) {
        if (!sel.value) return;
        var parts = sel.value.split('|');
        // fill the first empty row, else add a new one
        var rows = document.querySelectorAll('#recRows .rec-row');
        var target = null;
        rows.forEach(function (r) { if (!target && !r.querySelector('input[name="rec_mobile[]"]').value) target = r; });
        if (target) {
          target.querySelector('input[name="rec_name[]"]').value = parts[0] || '';
          target.querySelector('input[name="rec_mobile[]"]').value = parts[1] || '';
        } else { addRec(parts[0] || '', parts[1] || ''); }
        sel.value = '';
      }
    </script>
    <?php
    include __DIR__ . '/includes/footer.php';
    exit;
}

// ---------- list ----------
$upcoming = all("SELECT r.*, (SELECT COUNT(*) FROM reminder_recipients rr WHERE rr.reminder_id = r.id) recips
                 FROM reminders r WHERE r.status = 'pending' ORDER BY r.remind_at ASC");
$history  = all("SELECT r.*, (SELECT COUNT(*) FROM reminder_recipients rr WHERE rr.reminder_id = r.id) recips
                 FROM reminders r WHERE r.status <> 'pending' ORDER BY r.remind_at DESC LIMIT 60");
$now = date('Y-m-d H:i:s');

function reminder_recip_line($rid) {
    $rs = all('SELECT name, mobile FROM reminder_recipients WHERE reminder_id = ?', [$rid]);
    return implode(', ', array_map(fn($x) => trim(($x['name'] ? $x['name'] . ' ' : '') . '(' . $x['mobile'] . ')'), $rs));
}

$page_title = 'Reminders';
include __DIR__ . '/includes/header.php';
?>
<div class="page-actions">
  <?php if (can('reminders.add')): ?><a class="btn" href="reminders.php?action=new">+ New Reminder</a><?php endif; ?>
</div>

<div class="grid-stats mb">
  <div class="stat"><div class="stat-label">⏰ Upcoming</div><div class="stat-value"><?= count($upcoming) ?></div></div>
  <div class="stat s-bad"><div class="stat-label">⚠️ Due / overdue</div><div class="stat-value"><?= count(array_filter($upcoming, fn($r) => $r['remind_at'] <= $now)) ?></div></div>
  <div class="stat s-ok"><div class="stat-label">✅ Sent / done</div><div class="stat-value"><?= count($history) ?></div></div>
</div>

<h3 class="mb">Upcoming reminders</h3>
<div class="card list-card">
  <?php if (!$upcoming): ?><div class="list-row" style="cursor:default"><div class="list-row-main muted">No reminders scheduled. Tap “+ New Reminder”.</div></div><?php endif; ?>
  <?php foreach ($upcoming as $r): $overdue = $r['remind_at'] <= $now; ?>
  <div class="list-row rem-card" style="cursor:default;display:block">
    <div class="list-row-main">
      <strong><?= reminder_category_icon($r['category']) ?> <?= e($r['title']) ?></strong>
      <div class="muted list-row-sub"><?= $overdue ? '🔴 ' : '📅 ' ?><?= dmyt($r['remind_at']) ?><?= $r['repeat_freq'] !== 'once' ? ' · 🔁 ' . e($r['repeat_freq']) : '' ?></div>
      <div class="muted list-row-sub">📲 <?= e(reminder_recip_line($r['id'])) ?></div>
      <?php if (trim((string)$r['notes']) !== ''): ?><div class="muted list-row-sub">📝 <?= e($r['notes']) ?></div><?php endif; ?>
    </div>
    <div class="rem-actions" style="display:flex;flex-wrap:wrap;gap:6px;margin-top:10px">
      <?php if (can('reminders.edit')): ?>
      <form method="post" style="display:inline" onsubmit="return confirm('Send this reminder on WhatsApp now?')"><?= csrf_field() ?><input type="hidden" name="do" value="send_now"><input type="hidden" name="id" value="<?= $r['id'] ?>"><button class="btn btn-sm btn-outline" type="submit">📲 Send now</button></form>
      <a class="btn btn-sm btn-outline" href="reminders.php?action=edit&id=<?= $r['id'] ?>">✏️ Edit</a>
      <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="do" value="done"><input type="hidden" name="id" value="<?= $r['id'] ?>"><button class="btn btn-sm btn-outline" type="submit">✅ Done</button></form>
      <?php endif; ?>
      <?php if (can('reminders.delete')): ?>
      <form method="post" style="display:inline" onsubmit="return confirm('Delete this reminder?')"><?= csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= $r['id'] ?>"><button class="btn btn-sm btn-danger" type="submit">🗑️</button></form>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<?php if ($history): ?>
<h3 class="mb" style="margin-top:22px">Past reminders</h3>
<div class="card list-card">
  <?php foreach ($history as $r): ?>
  <div class="list-row" style="cursor:default;align-items:flex-start">
    <div class="list-row-main">
      <strong style="opacity:.75"><?= reminder_category_icon($r['category']) ?> <?= e($r['title']) ?></strong>
      <div class="muted list-row-sub"><?= dmyt($r['remind_at']) ?> · <?= $r['status'] === 'done' ? '✅ done' : '📤 sent' ?><?= $r['send_count'] ? ' ×' . (int)$r['send_count'] : '' ?></div>
    </div>
    <div style="display:flex;gap:6px">
      <?php if (can('reminders.add')): ?><a class="btn btn-sm btn-outline" href="reminders.php?action=edit&id=<?= $r['id'] ?>">Re-schedule</a><?php endif; ?>
      <?php if (can('reminders.delete')): ?>
      <form method="post" style="display:inline" onsubmit="return confirm('Delete this reminder?')"><?= csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= $r['id'] ?>"><button class="btn btn-sm btn-danger" type="submit">🗑️</button></form>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
