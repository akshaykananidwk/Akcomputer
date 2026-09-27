<?php
// Campaigns - pick who, write what, see who is left out and why, send in
// batches, then find out whether it did anything.
//
// The screen is deliberately slow to let you send: you choose an audience,
// you see the exact list with every refusal spelled out, you preview the
// message a real customer will receive, and only then does a Send button
// appear. Nothing is sent by opening a page.
require_once __DIR__ . '/includes/init.php';
require_perm('campaigns.view');
$u = current_user();
$rules = cam_rules();
$auds = cam_audiences();

// ---------- actions ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'save') {
    require_perm('campaigns.add');
    $name = trim(post('name'));
    $aud = post('audience');
    $msg = trim(post('message'));
    if ($name === '' || $msg === '' || !isset($auds[$aud])) {
        flash('A name, an audience and a message — all three are needed.', 'error');
        redirect('campaigns.php?action=new');
    }
    $id = (int)post('id');
    if ($id) {
        $c = row('SELECT * FROM campaigns WHERE id = ?', [$id]);
        if (!$c || !in_array($c['status'], ['draft', 'ready'], true)) {
            flash('A campaign that has been sent cannot be changed.', 'error');
            redirect('campaigns.php?id=' . $id);
        }
        q("UPDATE campaigns SET name=?, audience=?, audience_params=?, message=?, holdout_pct=?, measure_days=?, notes=?, status='draft' WHERE id=?",
          [$name, $aud, (string)post('audience_params'), $msg,
           min(50, max(0, (int)post('holdout_pct'))), max(1, (int)post('measure_days', 30)), (string)post('notes'), $id]);
        q('DELETE FROM campaign_targets WHERE campaign_id = ?', [$id]);
    } else {
        q("INSERT INTO campaigns (name, audience, audience_params, message, holdout_pct, measure_days, notes, created_by)
           VALUES (?,?,?,?,?,?,?,?)",
          [$name, $aud, (string)post('audience_params'), $msg,
           min(50, max(0, (int)post('holdout_pct', $rules['holdout']))), max(1, (int)post('measure_days', 30)),
           (string)post('notes'), $u['id']]);
        $id = insert_id();
    }
    log_activity('campaign_save', $name);
    flash('Saved. Now prepare the list.');
    redirect('campaigns.php?id=' . $id);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'prepare') {
    require_perm('campaigns.add');
    $id = (int)post('id');
    $r = cam_prepare($id);
    if (!empty($r['error'])) flash($r['error'], 'error');
    else flash("List ready: {$r['queued']} will be sent, {$r['holdout']} held back for comparison, {$r['skipped']} excluded.");
    redirect('campaigns.php?id=' . $id);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'send') {
    require_perm('campaigns.send');
    $id = (int)post('id');
    $r = cam_send_batch($id, (int)post('batch') ?: null);
    if (!empty($r['error'])) flash($r['error'], 'error');
    else {
        log_activity('campaign_send', "campaign=$id sent={$r['sent']} failed={$r['failed']}");
        flash("{$r['sent']} messages sent" . ($r['failed'] ? ", {$r['failed']} failed" : '')
            . ($r['skipped'] ? ", {$r['skipped']} excluded" : '') . ". Remaining {$r['left']}.");
    }
    redirect('campaigns.php?id=' . $id);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'cancel') {
    require_perm('campaigns.add');
    $id = (int)post('id');
    q("UPDATE campaigns SET status = 'cancelled', finished_at = NOW() WHERE id = ? AND status <> 'done'", [$id]);
    log_activity('campaign_cancel', 'campaign=' . $id);
    flash('Campaign stopped. The remaining messages will not go.');
    redirect('campaigns.php?id=' . $id);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'save_rules') {
    require_perm('settings.edit');
    foreach (['campaign_gap_days' => 21, 'campaign_max_month' => 2, 'campaign_hour_from' => 10,
              'campaign_hour_to' => 20, 'campaign_batch' => 20, 'campaign_holdout_pct' => 10,
              'campaign_msg_paise' => 0] as $k => $def) {
        if (post($k) !== '') set_setting($k, (string)max(0, (int)post($k)));
    }
    set_setting('campaign_optout_line', (string)post('campaign_optout_line'));
    flash('Rules saved.');
    redirect('campaigns.php?action=rules');
}

$action = get('action', '');
$id = (int)get('id');
$camp = $id ? row('SELECT * FROM campaigns WHERE id = ?', [$id]) : null;

$page_title = 'Campaigns';
include __DIR__ . '/includes/header.php';
?>

<?php if ($action === 'rules'): ?>
<div class="card">
  <h2>⚙️ Campaign rules</h2>
  <p class="muted" style="margin-top:0;font-size:13px">
    These rules <b>Each</b> apply to the campaign — and the same rules hold when the cron sends automatically,
    the same as when a person presses the button. The cron gets no exemption.
  </p>
  <?php if (can('settings.edit')): ?>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="do" value="save_rules">
    <div class="grid-2">
      <div class="field"><label>Minimum days between two messages to one customer</label>
        <input type="number" name="campaign_gap_days" value="<?= (int)$rules['gap_days'] ?>" min="0"></div>
      <div class="field"><label>Maximum messages to one customer in 30 days</label>
        <input type="number" name="campaign_max_month" value="<?= (int)$rules['max_month'] ?>" min="0"></div>
      <div class="field"><label>from this hour in the morning</label>
        <input type="number" name="campaign_hour_from" value="<?= (int)$rules['hour_from'] ?>" min="0" max="23"></div>
      <div class="field"><label>until this hour in the evening</label>
        <input type="number" name="campaign_hour_to" value="<?= (int)$rules['hour_to'] ?>" min="0" max="23"></div>
      <div class="field"><label>How many messages at a time</label>
        <input type="number" name="campaign_batch" value="<?= (int)$rules['batch'] ?>" min="1" max="200"></div>
      <div class="field"><label>What percentage to hold back for comparison</label>
        <input type="number" name="campaign_holdout_pct" value="<?= (int)$rules['holdout'] ?>" min="0" max="50"></div>
      <div class="field"><label>Cost per message (in paise)</label>
        <input type="number" name="campaign_msg_paise" value="<?= (int)$rules['msg_paise'] ?>" min="0">
        <p class="muted" style="font-size:12.5px">From your WhatsApp provider bill. Leave it blank and no cost is shown — better nothing than a wrong figure.</p></div>
    </div>
    <div class="field"><label>The line written at the end of every message</label>
      <input type="text" name="campaign_optout_line" value="<?= e($rules['optout_line']) ?>" maxlength="120">
      <p class="muted" style="font-size:12.5px">Customer "STOP" types it and <b>really</b> turns it off — a WhatsApp reply is caught automatically.
        Do not leave it blank: sending advertising without showing a way out is not right.</p></div>
    <button class="btn btn-primary" type="submit">Save</button>
  </form>
  <?php else: ?><p class="muted">Changing the rule needs the settings.edit permission.</p><?php endif; ?>
</div>

<?php elseif ($action === 'new' || ($camp && $action === 'edit')): $c = $camp ?: []; ?>
<div class="card">
  <h2><?= $camp ? '✏️ Edit the campaign' : '📣 New campaign' ?></h2>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="do" value="save">
    <?php if ($camp): ?><input type="hidden" name="id" value="<?= (int)$camp['id'] ?>"><?php endif; ?>
    <div class="field"><label>Name (for you only)</label>
      <input type="text" name="name" value="<?= e($c['name'] ?? '') ?>" required maxlength="120" placeholder="e.g. Diwali Offer 2026"></div>
    <div class="field"><label>Who to send to</label>
      <select name="audience" id="audSel" onchange="audChanged()">
        <?php foreach ($auds as $k => $a): ?>
          <option value="<?= $k ?>" data-param="<?= e($a[2]) ?>" data-desc="<?= e($a[1]) ?>" <?= ($c['audience'] ?? 'inactive') === $k ? 'selected' : '' ?>><?= e($a[0]) ?></option>
        <?php endforeach; ?>
      </select>
      <p class="muted" id="audDesc" style="font-size:12.5px"></p></div>
    <div class="field" id="paramWrap"><label id="paramLbl">Detail</label>
      <select name="audience_params" id="paramSel" style="display:none">
        <option value="">— choose —</option>
      </select>
      <input type="number" name="audience_params_num" id="paramNum" value="<?= e($c['audience_params'] ?? '') ?>" style="display:none"></div>
    <div class="field"><label>Message</label>
      <textarea name="message" rows="5" required maxlength="900" placeholder="Hello {customer}, ..."><?= e($c['message'] ?? '') ?></textarea>
      <p class="muted" style="font-size:12.5px">
        <code>{customer}</code> = the customer name, <code>{shop}</code> = the shop name.
        the line below <b>automatic</b> will be added: "<?= e($rules['optout_line']) ?>"
      </p></div>
    <div class="grid-2">
      <div class="field"><label>What percentage to hold back for comparison</label>
        <input type="number" name="holdout_pct" value="<?= (int)($c['holdout_pct'] ?? $rules['holdout']) ?>" min="0" max="50">
        <p class="muted" style="font-size:12.5px">to this many customers <b>deliberately not messaged</b>. Comparing the two groups then shows whether
          whether the campaign really made a difference or they were coming anyway. Set 0 and the difference cannot be measured.</p></div>
      <div class="field"><label>How many days to measure the effect over</label>
        <input type="number" name="measure_days" value="<?= (int)($c['measure_days'] ?? 30) ?>" min="1" max="365"></div>
    </div>
    <button class="btn btn-primary" type="submit">Save</button>
    <a class="btn btn-outline" href="campaigns.php">Cancelled</a>
  </form>
</div>
<script>
var CATS = <?= json_encode(all('SELECT id, name FROM categories ORDER BY name')) ?>;
var ITEMS = <?= json_encode(all('SELECT id, name FROM items WHERE is_active = 1 ORDER BY name LIMIT 500')) ?>;
var CUR = <?= json_encode((string)($c['audience_params'] ?? '')) ?>;
function audChanged() {
  var sel = document.getElementById('audSel');
  var opt = sel.options[sel.selectedIndex];
  document.getElementById('audDesc').textContent = opt.dataset.desc || '';
  var need = opt.dataset.param || '';
  var wrap = document.getElementById('paramWrap');
  var ps = document.getElementById('paramSel'), pn = document.getElementById('paramNum');
  wrap.style.display = need ? '' : 'none';
  document.getElementById('paramLbl').textContent = need;
  ps.style.display = 'none'; pn.style.display = 'none';
  ps.name = ''; pn.name = '';
  if (!need) return;
  if (sel.value === 'category' || sel.value === 'item') {
    var list = sel.value === 'category' ? CATS : ITEMS;
    ps.innerHTML = '<option value="">— choose —</option>' +
      list.map(function (x) { return '<option value="' + x.id + '"' + (String(x.id) === CUR ? ' selected' : '') + '>' + x.name + '</option>'; }).join('');
    ps.style.display = ''; ps.name = 'audience_params';
  } else {
    pn.style.display = ''; pn.name = 'audience_params';
  }
}
audChanged();
</script>

<?php elseif ($camp): $cnt = cam_counts($camp['id']);
      $targets = all('SELECT * FROM campaign_targets WHERE campaign_id = ? ORDER BY FIELD(status,"failed","queued","sent","skipped"), id LIMIT 400', [$camp['id']]);
      $res = in_array($camp['status'], ['sending', 'done', 'cancelled'], true) ? cam_result($camp['id']) : null;
      $preview = $targets ? cam_message_for($camp, $targets[0]) : cam_message_for($camp, ['name' => 'Ramesh']); ?>
<div class="card">
  <h2>📣 <?= e($camp['name']) ?>
    <span class="badge <?= ['draft'=>'', 'ready'=>'b-warn', 'sending'=>'b-warn', 'done'=>'b-good', 'cancelled'=>'b-bad'][$camp['status']] ?? '' ?>"><?= e($camp['status']) ?></span></h2>
  <p class="muted" style="margin-top:0;font-size:13px">
    <?= e($auds[$camp['audience']][0] ?? $camp['audience']) ?> ·
    Created <?= dmyt($camp['created_at']) ?>
    <?= $camp['started_at'] ? ' · started ' . dmyt($camp['started_at']) : '' ?>
  </p>
  <div class="grid-stats">
    <div class="stat"><div class="stat-label">Full list</div><div class="stat-value"><?= (int)$cnt['total'] ?></div></div>
    <div class="stat s-good"><div class="stat-label">sent</div><div class="stat-value"><?= (int)$cnt['sent'] ?></div></div>
    <div class="stat s-warn"><div class="stat-label">Due</div><div class="stat-value"><?= (int)$cnt['queued'] ?></div></div>
    <div class="stat"><div class="stat-label">held back</div><div class="stat-value"><?= (int)$cnt['holdout'] ?></div></div>
    <div class="stat <?= $cnt['skipped'] ? 's-warn' : '' ?>"><div class="stat-label">excluded</div><div class="stat-value"><?= (int)$cnt['skipped'] ?></div></div>
    <div class="stat <?= $cnt['failed'] ? 's-bad' : '' ?>"><div class="stat-label">Failed</div><div class="stat-value"><?= (int)$cnt['failed'] ?></div></div>
  </div>

  <div class="page-actions" style="margin-top:12px;flex-wrap:wrap">
    <?php if (in_array($camp['status'], ['draft', 'ready'], true) && can('campaigns.add')): ?>
      <form method="post" style="display:inline"><?= csrf_field() ?>
        <input type="hidden" name="do" value="prepare"><input type="hidden" name="id" value="<?= (int)$camp['id'] ?>">
        <button class="btn" type="submit">🔄 Prepare the list</button></form>
      <a class="btn btn-outline" href="campaigns.php?action=edit&id=<?= (int)$camp['id'] ?>">✏️ Edit</a>
    <?php endif; ?>
    <?php if (in_array($camp['status'], ['ready', 'sending'], true) && $cnt['queued'] > 0 && can('campaigns.send')): ?>
      <?php if (cam_quiet_ok()): ?>
      <form method="post" style="display:inline" onsubmit="return confirm('<?= (int)min($cnt['queued'], $rules['batch']) ?> customers will be messaged. Continue?')">
        <?= csrf_field() ?><input type="hidden" name="do" value="send"><input type="hidden" name="id" value="<?= (int)$camp['id'] ?>">
        <button class="btn btn-primary" type="submit">📤 Now <?= (int)min($cnt['queued'], $rules['batch']) ?> Send</button></form>
      <?php else: ?>
        <span class="badge b-warn">This is not the time to send — <?= e(cam_quiet_text()) ?> only between these</span>
      <?php endif; ?>
    <?php endif; ?>
    <?php if (!in_array($camp['status'], ['done', 'cancelled'], true) && can('campaigns.add')): ?>
      <form method="post" style="display:inline" onsubmit="return confirm('The remaining messages will not go. Stop it?')">
        <?= csrf_field() ?><input type="hidden" name="do" value="cancel"><input type="hidden" name="id" value="<?= (int)$camp['id'] ?>">
        <button class="btn btn-outline" type="submit">✋ Stop</button></form>
    <?php endif; ?>
    <a class="btn btn-outline" href="campaigns.php">← All campaigns</a>
  </div>
  <?php if ($cnt['queued'] > 0 && in_array($camp['status'], ['ready', 'sending'], true)): ?>
  <p class="muted" style="font-size:12.5px;margin-bottom:0">
    the cron sends the rest automatically <?= (int)$rules['batch'] ?> in batches of (<?= e(cam_quiet_text()) ?> ).
    To send by hand, press the button above.
  </p>
  <?php endif; ?>
</div>

<div class="card">
  <h2>👁️ This is what the customer gets</h2>
  <pre style="white-space:pre-wrap;background:var(--soft,#f8fafc);padding:12px;border-radius:8px;margin:0;font-family:inherit"><?= e($preview) ?></pre>
  <p class="muted" style="font-size:12.5px;margin-bottom:0">the code adds the last line, so no campaign can forget it.</p>
</div>

<?php if ($res && $res['ok']): ?>
<div class="card">
  <h2>📊 Did it make a difference</h2>
  <p class="muted" style="margin-top:0;font-size:13px">
    <?= dmy($res['from']) ?> from <?= dmy($res['to']) ?> (<?= (int)$res['days'] ?> days) — against those who were messaged
    <b>those who were deliberately not messaged</b> those people. Both sets of figures from the same books.
    <?= $res['ended'] ? '' : ' ⏳ The window is not over yet — the figures may still rise.' ?>
  </p>
  <div class="table-wrap"><table>
    <thead><tr><th>group</th><th class="num">a person</th><th class="num">bought</th><th class="num">rate</th><th class="num">Sales</th><th class="num">per head</th></tr></thead>
    <tbody>
      <tr><td>📤 Message sent</td><td class="num"><?= (int)$res['sent']['people'] ?></td>
        <td class="num"><?= (int)$res['sent']['buyers'] ?></td><td class="num"><b><?= $res['sent']['rate'] ?>%</b></td>
        <td class="num">₹<?= money($res['sent']['amt']) ?></td><td class="num">₹<?= money($res['sent']['per_head']) ?></td></tr>
      <tr><td>🤚 Held back</td><td class="num"><?= (int)$res['held']['people'] ?></td>
        <td class="num"><?= (int)$res['held']['buyers'] ?></td><td class="num"><b><?= $res['held']['rate'] ?>%</b></td>
        <td class="num">₹<?= money($res['held']['amt']) ?></td><td class="num">₹<?= money($res['held']['per_head']) ?></td></tr>
    </tbody>
  </table></div>
  <?php if ($res['trust']): ?>
    <div class="flash flash-info">🤔 <?= e($res['trust']) ?></div>
  <?php else: ?>
    <div class="flash <?= $res['lift'] > 0 ? 'flash-success' : 'flash-info' ?>">
      <?= $res['lift'] > 0 ? '✅' : '➖' ?> in the group that was messaged <b><?= $res['lift'] > 0 ? '+' : '' ?><?= $res['lift'] ?>%</b>
      more customers bought. That much difference can be credited to the campaign.
    </div>
  <?php endif; ?>
  <?php if ($res['cost'] !== null): ?>
    <p class="muted" style="font-size:12.5px;margin-bottom:0">Message cost Rs <?= money($res['cost']) ?> (at the rates you set).</p>
  <?php else: ?>
    <p class="muted" style="font-size:12.5px;margin-bottom:0">the message cost is not counted — <a href="campaigns.php?action=rules">in the rules</a> Enter a price per message and it shows.</p>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="card">
  <h2>📋 List <span class="muted" style="font-weight:400;font-size:13px">· with a reason for everyone left out</span></h2>
  <?php if (!$targets): ?>
    <p class="muted">The list is not ready yet. Above, "Prepare the list" press — <b>this sends nobody a message</b>, it only builds the list.</p>
  <?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th>Customer</th><th>Mobile</th><th>Status</th><th>Reason / time</th></tr></thead>
    <tbody>
    <?php foreach ($targets as $t): ?>
      <tr>
        <td><a href="customer.php?id=<?= (int)$t['party_id'] ?>"><?= e($t['name']) ?></a></td>
        <td class="muted"><?= e($t['mobile']) ?></td>
        <td>
          <?php if ((int)$t['is_holdout'] === 1): ?><span class="badge">🤚 Held back</span>
          <?php elseif ($t['status'] === 'sent'): ?><span class="badge b-good">✔ sent</span>
          <?php elseif ($t['status'] === 'failed'): ?><span class="badge b-bad">✗ Failed</span>
          <?php elseif ($t['status'] === 'skipped'): ?><span class="badge b-warn">excluded</span>
          <?php else: ?><span class="badge">Due</span><?php endif; ?>
        </td>
        <td class="muted" style="font-size:12.5px"><?= $t['sent_at'] ? dmyt($t['sent_at']) : e($t['reason']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php if ($cnt['total'] > 400): ?><p class="muted" style="font-size:12px">the first 400 are shown (of <?= (int)$cnt['total'] ?>).</p><?php endif; ?>
  <?php endif; ?>
</div>

<?php else: $list = all('SELECT * FROM campaigns ORDER BY id DESC LIMIT 100');
      $kMap = cam_counts_map(array_map(fn($x) => (int)$x['id'], $list));
      $optedOut = (int)val('SELECT COUNT(*) FROM parties WHERE marketing_opt_out = 1'); ?>
<div class="card">
  <h2>📣 Campaigns</h2>
  <p class="muted" style="margin-top:0;font-size:13px">
    A tool for messaging customers — <b>with the rules</b>. Nobody is messaged at night,
    to one customer <?= (int)$rules['gap_days'] ?> does not go twice in days, and in a month <?= (int)$rules['max_month'] ?> no more than that.
    every message carries a way to stop them, and it <b>really does the work</b>.
    <?php if ($optedOut): ?><br><?= $optedOut ?> customers have turned advertising off — no campaign reaches them.<?php endif; ?>
  </p>
  <div class="page-actions">
    <?php if (can('campaigns.add')): ?><a class="btn btn-primary" href="campaigns.php?action=new">+ New campaign</a><?php endif; ?>
    <a class="btn btn-outline" href="campaigns.php?action=rules">⚙️ Rules</a>
  </div>
</div>
<div class="card">
  <?php if (!$list): ?><p class="muted">No campaigns yet.</p><?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th>Name</th><th>To whom</th><th>Status</th><th class="num">sent</th><th class="num">held back</th><th>Date</th></tr></thead>
    <tbody>
    <?php foreach ($list as $x): $k = $kMap[(int)$x['id']] ?? cam_zero_counts(); ?>
      <tr>
        <td><a href="campaigns.php?id=<?= (int)$x['id'] ?>"><?= e($x['name']) ?></a></td>
        <td class="muted" style="font-size:12.5px"><?= e($auds[$x['audience']][0] ?? $x['audience']) ?></td>
        <td><span class="badge <?= ['draft'=>'', 'ready'=>'b-warn', 'sending'=>'b-warn', 'done'=>'b-good', 'cancelled'=>'b-bad'][$x['status']] ?? '' ?>"><?= e($x['status']) ?></span></td>
        <td class="num"><?= (int)$k['sent'] ?></td>
        <td class="num muted"><?= (int)$k['holdout'] ?></td>
        <td class="muted" style="font-size:12.5px"><?= dmy($x['created_at']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
