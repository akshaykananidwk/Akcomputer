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
        flash('નામ, ઓડિયન્સ અને મેસેજ - ત્રણેય જોઈએ.', 'error');
        redirect('campaigns.php?action=new');
    }
    $id = (int)post('id');
    if ($id) {
        $c = row('SELECT * FROM campaigns WHERE id = ?', [$id]);
        if (!$c || !in_array($c['status'], ['draft', 'ready'], true)) {
            flash('મોકલાઈ ગયેલું કેમ્પેન બદલી શકાય નહીં.', 'error');
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
    flash('સચવાયું. હવે યાદી તૈયાર કરો.');
    redirect('campaigns.php?id=' . $id);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'prepare') {
    require_perm('campaigns.add');
    $id = (int)post('id');
    $r = cam_prepare($id);
    if (!empty($r['error'])) flash($r['error'], 'error');
    else flash("યાદી તૈયાર: {$r['queued']} ને મોકલાશે, {$r['holdout']} ને સરખામણી માટે રોકી રાખ્યા, {$r['skipped']} બાકાત.");
    redirect('campaigns.php?id=' . $id);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'send') {
    require_perm('campaigns.send');
    $id = (int)post('id');
    $r = cam_send_batch($id, (int)post('batch') ?: null);
    if (!empty($r['error'])) flash($r['error'], 'error');
    else {
        log_activity('campaign_send', "campaign=$id sent={$r['sent']} failed={$r['failed']}");
        flash("{$r['sent']} મેસેજ ગયા" . ($r['failed'] ? ", {$r['failed']} નિષ્ફળ" : '')
            . ($r['skipped'] ? ", {$r['skipped']} બાકાત" : '') . ". બાકી {$r['left']}.");
    }
    redirect('campaigns.php?id=' . $id);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'cancel') {
    require_perm('campaigns.add');
    $id = (int)post('id');
    q("UPDATE campaigns SET status = 'cancelled', finished_at = NOW() WHERE id = ? AND status <> 'done'", [$id]);
    log_activity('campaign_cancel', 'campaign=' . $id);
    flash('કેમ્પેન બંધ કર્યું. બાકીના મેસેજ નહીં જાય.');
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
    flash('નિયમો સચવાયા.');
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
  <h2>⚙️ કેમ્પેનના નિયમો</h2>
  <p class="muted" style="margin-top:0;font-size:13px">
    આ નિયમો <b>દરેક</b> કેમ્પેન પર લાગુ પડે છે — અને cron આપોઆપ મોકલે ત્યારે પણ એ જ નિયમો ચાલે છે,
    માણસ બટન દબાવે ત્યારે જે ચાલે છે એ જ. cron ને કોઈ છૂટ નથી.
  </p>
  <?php if (can('settings.edit')): ?>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="do" value="save_rules">
    <div class="grid-2">
      <div class="field"><label>એક ગ્રાહકને બે મેસેજ વચ્ચે ઓછામાં ઓછા દિવસ</label>
        <input type="number" name="campaign_gap_days" value="<?= (int)$rules['gap_days'] ?>" min="0"></div>
      <div class="field"><label>એક ગ્રાહકને 30 દિવસમાં વધુમાં વધુ મેસેજ</label>
        <input type="number" name="campaign_max_month" value="<?= (int)$rules['max_month'] ?>" min="0"></div>
      <div class="field"><label>સવારે આટલા વાગ્યાથી</label>
        <input type="number" name="campaign_hour_from" value="<?= (int)$rules['hour_from'] ?>" min="0" max="23"></div>
      <div class="field"><label>સાંજે આટલા વાગ્યા સુધી</label>
        <input type="number" name="campaign_hour_to" value="<?= (int)$rules['hour_to'] ?>" min="0" max="23"></div>
      <div class="field"><label>એક વખતમાં કેટલા મેસેજ</label>
        <input type="number" name="campaign_batch" value="<?= (int)$rules['batch'] ?>" min="1" max="200"></div>
      <div class="field"><label>સરખામણી માટે કેટલા ટકા રોકી રાખવા</label>
        <input type="number" name="campaign_holdout_pct" value="<?= (int)$rules['holdout'] ?>" min="0" max="50"></div>
      <div class="field"><label>એક મેસેજનો ખર્ચ (પૈસામાં)</label>
        <input type="number" name="campaign_msg_paise" value="<?= (int)$rules['msg_paise'] ?>" min="0">
        <p class="muted" style="font-size:12.5px">તમારા WhatsApp પ્રોવાઇડરના બિલમાંથી. ખાલી રાખો તો ખર્ચ બતાવાશે નહીં — ખોટો આંકડો બતાવવા કરતાં કંઈ ન બતાવવું સારું.</p></div>
    </div>
    <div class="field"><label>દરેક મેસેજના છેડે લખાતી લાઇન</label>
      <input type="text" name="campaign_optout_line" value="<?= e($rules['optout_line']) ?>" maxlength="120">
      <p class="muted" style="font-size:12.5px">ગ્રાહક "STOP" લખે એટલે <b>ખરેખર</b> બંધ થાય છે — WhatsApp નો જવાબ આપોઆપ પકડાય છે.
        ખાલી ન રાખશો: બહાર નીકળવાનો રસ્તો દેખાડ્યા વગર જાહેરાત મોકલવી એ બરાબર નથી.</p></div>
    <button class="btn btn-primary" type="submit">સાચવો</button>
  </form>
  <?php else: ?><p class="muted">નિયમ બદલવા settings.edit પરવાનગી જોઈએ.</p><?php endif; ?>
</div>

<?php elseif ($action === 'new' || ($camp && $action === 'edit')): $c = $camp ?: []; ?>
<div class="card">
  <h2><?= $camp ? '✏️ કેમ્પેન બદલો' : '📣 નવું કેમ્પેન' ?></h2>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="do" value="save">
    <?php if ($camp): ?><input type="hidden" name="id" value="<?= (int)$camp['id'] ?>"><?php endif; ?>
    <div class="field"><label>નામ (ફક્ત તમારા માટે)</label>
      <input type="text" name="name" value="<?= e($c['name'] ?? '') ?>" required maxlength="120" placeholder="દા.ત. દિવાળી ઓફર 2026"></div>
    <div class="field"><label>કોને મોકલવું</label>
      <select name="audience" id="audSel" onchange="audChanged()">
        <?php foreach ($auds as $k => $a): ?>
          <option value="<?= $k ?>" data-param="<?= e($a[2]) ?>" data-desc="<?= e($a[1]) ?>" <?= ($c['audience'] ?? 'inactive') === $k ? 'selected' : '' ?>><?= e($a[0]) ?></option>
        <?php endforeach; ?>
      </select>
      <p class="muted" id="audDesc" style="font-size:12.5px"></p></div>
    <div class="field" id="paramWrap"><label id="paramLbl">વિગત</label>
      <select name="audience_params" id="paramSel" style="display:none">
        <option value="">— પસંદ કરો —</option>
      </select>
      <input type="number" name="audience_params_num" id="paramNum" value="<?= e($c['audience_params'] ?? '') ?>" style="display:none"></div>
    <div class="field"><label>મેસેજ</label>
      <textarea name="message" rows="5" required maxlength="900" placeholder="નમસ્તે {customer}, ..."><?= e($c['message'] ?? '') ?></textarea>
      <p class="muted" style="font-size:12.5px">
        <code>{customer}</code> = ગ્રાહકનું નામ, <code>{shop}</code> = દુકાનનું નામ.
        નીચેની લાઇન <b>આપોઆપ</b> ઉમેરાશે: "<?= e($rules['optout_line']) ?>"
      </p></div>
    <div class="grid-2">
      <div class="field"><label>સરખામણી માટે કેટલા ટકા રોકી રાખવા</label>
        <input type="number" name="holdout_pct" value="<?= (int)($c['holdout_pct'] ?? $rules['holdout']) ?>" min="0" max="50">
        <p class="muted" style="font-size:12.5px">આટલા ગ્રાહકોને <b>જાણી જોઈને મેસેજ નહીં જાય</b>. પછી બંને જૂથ સરખાવીને ખબર પડે કે
          કેમ્પેનથી ખરેખર ફરક પડ્યો કે એ લોકો તો આવવાના જ હતા. 0 કરો તો ફરક માપી નહીં શકાય.</p></div>
      <div class="field"><label>કેટલા દિવસ સુધી અસર માપવી</label>
        <input type="number" name="measure_days" value="<?= (int)($c['measure_days'] ?? 30) ?>" min="1" max="365"></div>
    </div>
    <button class="btn btn-primary" type="submit">સાચવો</button>
    <a class="btn btn-outline" href="campaigns.php">રદ</a>
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
    ps.innerHTML = '<option value="">— પસંદ કરો —</option>' +
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
      $preview = $targets ? cam_message_for($camp, $targets[0]) : cam_message_for($camp, ['name' => 'રમેશભાઈ']); ?>
<div class="card">
  <h2>📣 <?= e($camp['name']) ?>
    <span class="badge <?= ['draft'=>'', 'ready'=>'b-warn', 'sending'=>'b-warn', 'done'=>'b-good', 'cancelled'=>'b-bad'][$camp['status']] ?? '' ?>"><?= e($camp['status']) ?></span></h2>
  <p class="muted" style="margin-top:0;font-size:13px">
    <?= e($auds[$camp['audience']][0] ?? $camp['audience']) ?> ·
    બનાવ્યું <?= dmyt($camp['created_at']) ?>
    <?= $camp['started_at'] ? ' · શરૂ ' . dmyt($camp['started_at']) : '' ?>
  </p>
  <div class="grid-stats">
    <div class="stat"><div class="stat-label">કુલ યાદી</div><div class="stat-value"><?= (int)$cnt['total'] ?></div></div>
    <div class="stat s-good"><div class="stat-label">મોકલ્યા</div><div class="stat-value"><?= (int)$cnt['sent'] ?></div></div>
    <div class="stat s-warn"><div class="stat-label">બાકી</div><div class="stat-value"><?= (int)$cnt['queued'] ?></div></div>
    <div class="stat"><div class="stat-label">રોકી રાખ્યા</div><div class="stat-value"><?= (int)$cnt['holdout'] ?></div></div>
    <div class="stat <?= $cnt['skipped'] ? 's-warn' : '' ?>"><div class="stat-label">બાકાત</div><div class="stat-value"><?= (int)$cnt['skipped'] ?></div></div>
    <div class="stat <?= $cnt['failed'] ? 's-bad' : '' ?>"><div class="stat-label">નિષ્ફળ</div><div class="stat-value"><?= (int)$cnt['failed'] ?></div></div>
  </div>

  <div class="page-actions" style="margin-top:12px;flex-wrap:wrap">
    <?php if (in_array($camp['status'], ['draft', 'ready'], true) && can('campaigns.add')): ?>
      <form method="post" style="display:inline"><?= csrf_field() ?>
        <input type="hidden" name="do" value="prepare"><input type="hidden" name="id" value="<?= (int)$camp['id'] ?>">
        <button class="btn" type="submit">🔄 યાદી તૈયાર કરો</button></form>
      <a class="btn btn-outline" href="campaigns.php?action=edit&id=<?= (int)$camp['id'] ?>">✏️ બદલો</a>
    <?php endif; ?>
    <?php if (in_array($camp['status'], ['ready', 'sending'], true) && $cnt['queued'] > 0 && can('campaigns.send')): ?>
      <?php if (cam_quiet_ok()): ?>
      <form method="post" style="display:inline" onsubmit="return confirm('<?= (int)min($cnt['queued'], $rules['batch']) ?> ગ્રાહકોને મેસેજ જશે. ચાલુ રાખવું?')">
        <?= csrf_field() ?><input type="hidden" name="do" value="send"><input type="hidden" name="id" value="<?= (int)$camp['id'] ?>">
        <button class="btn btn-primary" type="submit">📤 હવે <?= (int)min($cnt['queued'], $rules['batch']) ?> મોકલો</button></form>
      <?php else: ?>
        <span class="badge b-warn">અત્યારે મોકલવાનો સમય નથી — <?= e(cam_quiet_text()) ?> વચ્ચે જ જાય છે</span>
      <?php endif; ?>
    <?php endif; ?>
    <?php if (!in_array($camp['status'], ['done', 'cancelled'], true) && can('campaigns.add')): ?>
      <form method="post" style="display:inline" onsubmit="return confirm('બાકીના મેસેજ નહીં જાય. બંધ કરવું?')">
        <?= csrf_field() ?><input type="hidden" name="do" value="cancel"><input type="hidden" name="id" value="<?= (int)$camp['id'] ?>">
        <button class="btn btn-outline" type="submit">✋ બંધ કરો</button></form>
    <?php endif; ?>
    <a class="btn btn-outline" href="campaigns.php">← બધાં કેમ્પેન</a>
  </div>
  <?php if ($cnt['queued'] > 0 && in_array($camp['status'], ['ready', 'sending'], true)): ?>
  <p class="muted" style="font-size:12.5px;margin-bottom:0">
    બાકીના મેસેજ cron આપોઆપ <?= (int)$rules['batch'] ?> ના ટોળામાં મોકલશે (<?= e(cam_quiet_text()) ?> વચ્ચે).
    હાથે મોકલવું હોય તો ઉપરનું બટન દબાવો.
  </p>
  <?php endif; ?>
</div>

<div class="card">
  <h2>👁️ ગ્રાહકને આ મેસેજ મળશે</h2>
  <pre style="white-space:pre-wrap;background:var(--soft,#f8fafc);padding:12px;border-radius:8px;margin:0;font-family:inherit"><?= e($preview) ?></pre>
  <p class="muted" style="font-size:12.5px;margin-bottom:0">છેલ્લી લાઇન કોડ ઉમેરે છે, જેથી કોઈ કેમ્પેનમાં એ ભુલાય નહીં.</p>
</div>

<?php if ($res && $res['ok']): ?>
<div class="card">
  <h2>📊 ફરક પડ્યો કે નહીં</h2>
  <p class="muted" style="margin-top:0;font-size:13px">
    <?= dmy($res['from']) ?> થી <?= dmy($res['to']) ?> (<?= (int)$res['days'] ?> દિવસ) — જેમને મેસેજ ગયો એ લોકો સામે
    <b>જેમને જાણી જોઈને મેસેજ નહોતો મોકલ્યો</b> એ લોકો. બંનેના આંકડા એક જ ચોપડામાંથી.
    <?= $res['ended'] ? '' : ' ⏳ ગાળો હજી પૂરો થયો નથી — આંકડા વધી શકે છે.' ?>
  </p>
  <div class="table-wrap"><table>
    <thead><tr><th>જૂથ</th><th class="num">માણસ</th><th class="num">ખરીદી કરી</th><th class="num">દર</th><th class="num">વેચાણ</th><th class="num">માથાદીઠ</th></tr></thead>
    <tbody>
      <tr><td>📤 મેસેજ ગયો</td><td class="num"><?= (int)$res['sent']['people'] ?></td>
        <td class="num"><?= (int)$res['sent']['buyers'] ?></td><td class="num"><b><?= $res['sent']['rate'] ?>%</b></td>
        <td class="num">₹<?= money($res['sent']['amt']) ?></td><td class="num">₹<?= money($res['sent']['per_head']) ?></td></tr>
      <tr><td>🤚 રોકી રાખેલા</td><td class="num"><?= (int)$res['held']['people'] ?></td>
        <td class="num"><?= (int)$res['held']['buyers'] ?></td><td class="num"><b><?= $res['held']['rate'] ?>%</b></td>
        <td class="num">₹<?= money($res['held']['amt']) ?></td><td class="num">₹<?= money($res['held']['per_head']) ?></td></tr>
    </tbody>
  </table></div>
  <?php if ($res['trust']): ?>
    <div class="flash flash-info">🤔 <?= e($res['trust']) ?></div>
  <?php else: ?>
    <div class="flash <?= $res['lift'] > 0 ? 'flash-success' : 'flash-info' ?>">
      <?= $res['lift'] > 0 ? '✅' : '➖' ?> મેસેજ મળેલા જૂથમાં <b><?= $res['lift'] > 0 ? '+' : '' ?><?= $res['lift'] ?>%</b>
      વધુ ગ્રાહકોએ ખરીદી કરી. એટલો ફરક કેમ્પેનનો ગણી શકાય.
    </div>
  <?php endif; ?>
  <?php if ($res['cost'] !== null): ?>
    <p class="muted" style="font-size:12.5px;margin-bottom:0">મેસેજનો ખર્ચ ₹<?= money($res['cost']) ?> (તમે નક્કી કરેલા દર પ્રમાણે).</p>
  <?php else: ?>
    <p class="muted" style="font-size:12.5px;margin-bottom:0">મેસેજનો ખર્ચ ગણ્યો નથી — <a href="campaigns.php?action=rules">નિયમોમાં</a> એક મેસેજનો ભાવ ભરો તો દેખાશે.</p>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="card">
  <h2>📋 યાદી <span class="muted" style="font-weight:400;font-size:13px">· બાકાત રહેલા દરેકનું કારણ સાથે</span></h2>
  <?php if (!$targets): ?>
    <p class="muted">યાદી હજી તૈયાર નથી. ઉપર "યાદી તૈયાર કરો" દબાવો — <b>એનાથી કોઈને મેસેજ જતો નથી</b>, ફક્ત યાદી બને છે.</p>
  <?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th>ગ્રાહક</th><th>મોબાઇલ</th><th>સ્થિતિ</th><th>કારણ / સમય</th></tr></thead>
    <tbody>
    <?php foreach ($targets as $t): ?>
      <tr>
        <td><a href="customer.php?id=<?= (int)$t['party_id'] ?>"><?= e($t['name']) ?></a></td>
        <td class="muted"><?= e($t['mobile']) ?></td>
        <td>
          <?php if ((int)$t['is_holdout'] === 1): ?><span class="badge">🤚 રોકી રાખેલા</span>
          <?php elseif ($t['status'] === 'sent'): ?><span class="badge b-good">✔ ગયો</span>
          <?php elseif ($t['status'] === 'failed'): ?><span class="badge b-bad">✗ નિષ્ફળ</span>
          <?php elseif ($t['status'] === 'skipped'): ?><span class="badge b-warn">બાકાત</span>
          <?php else: ?><span class="badge">બાકી</span><?php endif; ?>
        </td>
        <td class="muted" style="font-size:12.5px"><?= $t['sent_at'] ? dmyt($t['sent_at']) : e($t['reason']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php if ($cnt['total'] > 400): ?><p class="muted" style="font-size:12px">પહેલા 400 બતાવ્યા છે (કુલ <?= (int)$cnt['total'] ?>).</p><?php endif; ?>
  <?php endif; ?>
</div>

<?php else: $list = all('SELECT * FROM campaigns ORDER BY id DESC LIMIT 100');
      $kMap = cam_counts_map(array_map(fn($x) => (int)$x['id'], $list));
      $optedOut = (int)val('SELECT COUNT(*) FROM parties WHERE marketing_opt_out = 1'); ?>
<div class="card">
  <h2>📣 કેમ્પેન</h2>
  <p class="muted" style="margin-top:0;font-size:13px">
    ગ્રાહકોને મેસેજ મોકલવાનું સાધન — <b>નિયમો સાથે</b>. રાત્રે કોઈને મેસેજ નથી જતો,
    એક ગ્રાહકને <?= (int)$rules['gap_days'] ?> દિવસમાં બે વાર નથી જતો, અને મહિનામાં <?= (int)$rules['max_month'] ?> થી વધુ નથી જતા.
    દરેક મેસેજમાં બંધ કરવાનો રસ્તો હોય છે, અને એ <b>ખરેખર કામ કરે છે</b>.
    <?php if ($optedOut): ?><br><?= $optedOut ?> ગ્રાહકોએ જાહેરાત બંધ કરાવી છે — એમને કોઈ કેમ્પેન નહીં જાય.<?php endif; ?>
  </p>
  <div class="page-actions">
    <?php if (can('campaigns.add')): ?><a class="btn btn-primary" href="campaigns.php?action=new">+ નવું કેમ્પેન</a><?php endif; ?>
    <a class="btn btn-outline" href="campaigns.php?action=rules">⚙️ નિયમો</a>
  </div>
</div>
<div class="card">
  <?php if (!$list): ?><p class="muted">હજી કોઈ કેમ્પેન નથી.</p><?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th>નામ</th><th>કોને</th><th>સ્થિતિ</th><th class="num">મોકલ્યા</th><th class="num">રોકી રાખ્યા</th><th>તારીખ</th></tr></thead>
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
