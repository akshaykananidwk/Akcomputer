<?php
// Scaling - will this still work when the shop is three times the size?
//
// Written for someone who does not read EXPLAIN plans: how big is the data,
// how fast is it growing, what will that be in a year, what can safely be
// cleared, and what must never be touched.
require_once __DIR__ . '/includes/init.php';
require_perm('settings.view');
if (!is_full_admin()) { http_response_code(403); die('This screen is for the owner / full admin only.'); }
$u = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'trim') {
    require_perm('settings.edit');
    $r = sc_trim(post('table'), (int)post('days') ?: null);
    dash_cache_forget('scaling_summary');
    if (!$r['ok']) flash($r['why'], 'error');
    else flash($r['deleted'] . ' જૂની નોંધ કાઢી નાખી (' . e($r['table']) . ', ' . (int)$r['days'] . ' દિવસથી જૂની).');
    redirect('scaling.php?tab=cleanup');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'optimize') {
    require_perm('settings.edit');
    $r = sc_optimize(post('table'));
    dash_cache_forget('scaling_summary');
    if (!$r['ok']) flash($r['why'], 'error');
    else flash(e($r['table']) . ' ફરી ગોઠવ્યું — આશરે ' . money($r['freed_mb']) . ' MB ખાલી થયું.');
    redirect('scaling.php?tab=space');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'save_rules') {
    require_perm('settings.edit');
    set_setting('log_keep_days', (string)max(30, (int)post('log_keep_days')));
    dash_cache_forget('scaling_summary');
    flash('સચવાયું.');
    redirect('scaling.php?tab=cleanup');
}

$tab = get('tab', 'size');
$rules = sc_rules();
$sum = sc_summary();

$page_title = 'Scaling';
include __DIR__ . '/includes/header.php';
?>
<div class="card">
  <h2>📦 ધંધો વધશે તો સિસ્ટમ ટકશે?</h2>
  <div class="grid-stats">
    <div class="stat"><div class="stat-label">અત્યારે ડેટા</div><div class="stat-value"><?= money($sum['total_mb']) ?> MB</div></div>
    <div class="stat"><div class="stat-label">વર્ષે વધશે</div><div class="stat-value">+<?= money($sum['grow_mb_year']) ?> MB</div></div>
    <div class="stat"><div class="stat-label">એક વર્ષ પછી</div><div class="stat-value"><?= money($sum['in_a_year_mb']) ?> MB</div></div>
    <div class="stat <?= $sum['freeable_mb'] > 5 ? 's-warn' : '' ?>"><div class="stat-label">સાફ કરી શકાય</div><div class="stat-value"><?= money($sum['freeable_mb']) ?> MB</div></div>
  </div>
  <div class="range-bar">
    <?php foreach (['size' => '📊 શું કેટલું મોટું', 'growth' => '📈 કેટલી ઝડપે વધે છે',
                    'indexes' => '🗂️ ઇન્ડેક્સની તપાસ', 'cleanup' => '🧹 સાફસફાઈ',
                    'space' => '🕳️ ખાલી પડેલી જગ્યા', 'never' => '🔒 જે કદી ડિલીટ ન થાય'] as $k => $lbl): ?>
    <a class="rchip <?= $tab === $k ? 'on' : '' ?>" href="scaling.php?tab=<?= $k ?>"><?= $lbl ?></a>
    <?php endforeach; ?>
  </div>
  <p class="muted" style="font-size:12.5px;margin-bottom:0">
    બધા આંકડા <b>અત્યારના ડેટાબેઝ પરથી માપેલા</b> છે — ધારણા નથી.
    <?php if ($sum['redundant'] > 0): ?>
      <br>⚠️ <b><?= (int)$sum['redundant'] ?> બિનજરૂરી ઇન્ડેક્સ</b> મળ્યા છે — <a href="scaling.php?tab=indexes">જુઓ</a>.
    <?php endif; ?>
  </p>
</div>

<?php if ($tab === 'size'): $tables = sc_tables(25); $db = sc_db_size(); ?>
<div class="card">
  <h2>📊 કયું ટેબલ કેટલું મોટું</h2>
  <p class="muted" style="margin-top:0;font-size:13px">
    કુલ <?= (int)$db['tables'] ?> ટેબલ · ડેટા <?= money($db['data_mb']) ?> MB · ઇન્ડેક્સ <?= money($db['idx_mb']) ?> MB.
    <b>ઇન્ડેક્સ</b> એટલે શોધવાની ઝડપ માટે રાખેલી અલગ યાદી — એ પણ જગ્યા રોકે છે અને દરેક એન્ટ્રી વખતે લખવી પડે છે.
  </p>
  <div class="table-wrap"><table>
    <thead><tr><th>ટેબલ</th><th class="num">આશરે લાઇન</th><th class="num">ડેટા</th><th class="num">ઇન્ડેક્સ</th><th class="num">કુલ</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($tables as $t): if ($t['total_mb'] < 0.02 && $t['free_mb'] < 1) continue;
          $heavy = $t['data_mb'] > 0.1 && $t['idx_mb'] > $t['data_mb'] * $rules['idx_ratio']; ?>
      <tr>
        <td><?= e($t['name']) ?><?= isset(sc_log_tables()[$t['name']]) ? ' <span class="badge">નોંધ</span>' : '' ?></td>
        <td class="num muted"><?= number_format((int)$t['rows_est']) ?></td>
        <td class="num"><?= money($t['data_mb']) ?></td>
        <td class="num"><?= money($t['idx_mb']) ?></td>
        <td class="num"><b><?= money($t['total_mb']) ?></b></td>
        <td><?= $heavy ? '<span class="badge b-warn">ઇન્ડેક્સ ડેટા કરતાં મોટા</span>' : '' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <p class="muted" style="font-size:12px">"આશરે લાઇન" MySQL નો અંદાજ છે, ચોક્કસ ગણતરી નથી — મોટા ટેબલ પર ચોક્કસ ગણવું મોંઘું પડે.</p>
</div>

<?php elseif ($tab === 'growth'): $g = sc_growth(15); ?>
<div class="card">
  <h2>📈 કેટલી ઝડપે વધે છે</h2>
  <p class="muted" style="margin-top:0;font-size:13px">
    <b>છેલ્લા <?= (int)$rules['window'] ?> દિવસમાં ખરેખર ઉમેરાયેલી લાઇનો</b> પરથી ગણ્યું છે — આખા ઇતિહાસની સરેરાશ પરથી નહીં.
    દુકાન નાની હતી ત્યારના મહિના ભેળવીએ તો આજની ગતિ ઓછી દેખાય.
  </p>
  <div class="table-wrap"><table>
    <thead><tr><th>ટેબલ</th><th class="num">અત્યારે લાઇન</th><th class="num">દર મહિને ઉમેરાય</th><th class="num">એક વર્ષ પછી</th><th class="num">ત્યારે જગ્યા</th></tr></thead>
    <tbody>
    <?php foreach ($g as $x): ?>
      <tr>
        <td><?= e($x['name']) ?></td>
        <td class="num"><?= number_format($x['rows']) ?></td>
        <td class="num"><?= $x['per_month'] === null ? '<span class="muted">—</span>' : '+' . number_format($x['per_month'], 0) ?></td>
        <td class="num"><?= $x['per_month'] === null ? '<span class="muted">—</span>' : number_format($x['in_a_year']) ?></td>
        <td class="num"><?= $x['per_month'] === null ? '<span class="muted title="' . e($x['why']) . '">માપી શકાતું નથી</span>'
                            : money($x['mb_in_a_year']) . ' MB' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <div class="flash flash-info">
    આ ગતિએ એક વર્ષમાં ડેટા <b><?= money($sum['total_mb']) ?> MB થી <?= money($sum['in_a_year_mb']) ?> MB</b> થશે.
    <?php if ($sum['in_a_year_mb'] < 500): ?>
      સામાન્ય હોસ્ટિંગ પ્લાનમાં આટલું આરામથી સમાય — ચિંતાની વાત નથી.
    <?php else: ?>
      હોસ્ટિંગની જગ્યા કેટલી છે એ એક વાર ચકાસી લેજો.
    <?php endif; ?>
  </div>
</div>

<?php elseif ($tab === 'indexes'): $ri = sc_redundant_indexes(); ?>
<div class="card">
  <h2>🗂️ ઇન્ડેક્સની તપાસ</h2>
  <p class="muted" style="margin-top:0;font-size:13px">
    ઇન્ડેક્સ મફત નથી. <b>દરેક બિલ, દરેક પેમેન્ટ સેવ થાય ત્યારે એ બધા ઇન્ડેક્સમાં લખવું પડે છે.</b>
    જે ઇન્ડેક્સના કૉલમ બીજા મોટા ઇન્ડેક્સની <b>શરૂઆતમાં</b> જ આવી જતા હોય, એ નકામો છે —
    MySQL મોટા ઇન્ડેક્સનો આગળનો ભાગ એકલો પણ વાપરી શકે છે.
  </p>
  <?php if (!$ri): ?>
    <div class="flash flash-success">✅ એકેય બિનજરૂરી ઇન્ડેક્સ મળ્યો નથી.</div>
  <?php else: ?>
    <div class="flash flash-warn"><?= count($ri) ?> બિનજરૂરી ઇન્ડેક્સ મળ્યા. કાઢવા માટે migration ફાઇલ જોઈએ —
      આ સ્ક્રીન જાતે ઇન્ડેક્સ કાઢતી નથી, કારણ કે એ ડેટાબેઝના માળખાનો ફેરફાર છે.</div>
    <div class="table-wrap"><table>
      <thead><tr><th>ટેબલ</th><th>નકામો ઇન્ડેક્સ</th><th>એના કૉલમ</th><th>કોણ એનું કામ કરે છે</th></tr></thead>
      <tbody>
      <?php foreach ($ri as $x): ?>
        <tr>
          <td><?= e($x['table']) ?></td>
          <td><code><?= e($x['index']) ?></code> <?= !empty($x['exact']) ? '<span class="badge b-bad">આબેહૂબ નકલ</span>' : '' ?></td>
          <td class="muted"><?= e($x['cols']) ?></td>
          <td><code><?= e($x['covered_by']) ?></code> <span class="muted">(<?= e($x['covered_cols']) ?>)</span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
  <p class="muted" style="font-size:12.5px">
    યુનિક ઇન્ડેક્સ ક્યારેય "નકામો" ગણાતો નથી — એ ઝડપ માટે નહીં, <b>એક જ નંબર બે વાર ન બને</b> એ નિયમ પાળવા માટે હોય છે.
  </p>
</div>

<?php elseif ($tab === 'cleanup'): $tr = sc_trimmable(); ?>
<div class="card">
  <h2>🧹 સાફ કરી શકાય એવી નોંધો</h2>
  <p class="muted" style="margin-top:0;font-size:13px">
    અહીં <b>ફક્ત નોંધો</b> છે — કોણે લોગિન કર્યું, ક્રોન ક્યારે ચાલ્યું, વેબસાઇટ પર કોણ આવ્યું.
    <b>ધંધાનો કોઈ ડેટા આ યાદીમાં નથી અને ક્યારેય નહીં આવે</b> — બિલ, પેમેન્ટ, સ્ટોકની નોંધ કદી ડિલીટ થતાં નથી
    (<a href="scaling.php?tab=never">યાદી જુઓ</a>).
  </p>
  <div class="table-wrap"><table>
    <thead><tr><th>નોંધ</th><th class="num">કુલ લાઇન</th><th class="num">જૂની</th><th class="num">જગ્યા બચે</th><th>કેટલું રાખવું</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($tr as $t): ?>
      <tr>
        <td><?= e($t['label']) ?><div class="muted" style="font-size:12px"><?= e($t['table']) ?></div></td>
        <td class="num"><?= number_format($t['rows']) ?></td>
        <td class="num"><?= $t['old'] ? '<b>' . number_format($t['old']) . '</b>' : '<span class="muted">·</span>' ?></td>
        <td class="num muted"><?= $t['free_mb'] > 0.01 ? money($t['free_mb']) . ' MB' : '·' ?></td>
        <td class="muted"><?= (int)$t['keep'] ?> દિવસ</td>
        <td>
          <?php if ($t['old'] > 0 && can('settings.edit')): ?>
          <form method="post" onsubmit="return confirm('<?= (int)$t['old'] ?> જૂની નોંધ કાયમ માટે કાઢી નાખવી છે?')">
            <?= csrf_field() ?><input type="hidden" name="do" value="trim">
            <input type="hidden" name="table" value="<?= e($t['table']) ?>">
            <input type="hidden" name="days" value="<?= (int)$t['keep'] ?>">
            <button class="btn btn-sm btn-outline" type="submit">🧹 કાઢો</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <p class="muted" style="font-size:12.5px">ક્રોન પણ આ જ કામ આપોઆપ કરે છે — અહીં ફક્ત હાથે કરવું હોય તો.</p>
</div>
<div class="card">
  <h2>⚙️ નિયમ</h2>
  <?php if (can('settings.edit')): ?>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="do" value="save_rules">
    <div class="field"><label>નોંધો ઓછામાં ઓછી આટલા દિવસ રાખવી</label>
      <input type="number" name="log_keep_days" value="<?= (int)$rules['keep_days'] ?>" min="30">
      <p class="muted" style="font-size:12.5px">30 થી ઓછું રાખી શકાતું નથી. દરેક નોંધનો પોતાનો સમય પણ ઉપર લખેલો છે.</p></div>
    <button class="btn btn-primary" type="submit">સાચવો</button>
  </form>
  <?php else: ?><p class="muted">બદલવા settings.edit પરવાનગી જોઈએ.</p><?php endif; ?>
</div>

<?php elseif ($tab === 'space'): $fr = sc_fragmentation(); ?>
<div class="card">
  <h2>🕳️ ખાલી પડેલી જગ્યા</h2>
  <p class="muted" style="margin-top:0;font-size:13px">
    લાઇન ડિલીટ થાય ત્યારે MySQL એની જગ્યા <b>તરત પાછી આપતું નથી</b> — ટેબલની અંદર ખાલી પડી રહે છે અને નવી લાઇનો
    એમાં ભરાય છે. ટેબલ ફરી ગોઠવીએ તો એ જગ્યા ડિસ્કને પાછી મળે.
  </p>
  <div class="flash flash-info">
    ℹ️ ટેસ્ટ ચલાવવાથી કે જૂની નોંધો કાઢવાથી પણ આવી ખાલી જગ્યા બને છે, એટલે મોટો આંકડો દેખાય એનો અર્થ
    "કંઈક બગડ્યું છે" એવો નથી. કુલ <b><?= money($sum['frag_mb']) ?> MB</b> અત્યારે આ રીતે રોકાયેલી છે.
  </div>
  <?php if (!$fr): ?><p class="muted">કોઈ ટેબલમાં નોંધપાત્ર ખાલી જગ્યા નથી.</p><?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th>ટેબલ</th><th class="num">વપરાયેલી</th><th class="num">ખાલી પડેલી</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($fr as $f): ?>
      <tr>
        <td><?= e($f['name']) ?></td>
        <td class="num muted"><?= money($f['total_mb']) ?> MB</td>
        <td class="num"><b><?= money($f['free_mb']) ?> MB</b></td>
        <td>
          <?php if (can('settings.edit')): ?>
          <form method="post" onsubmit="return confirm('આ ટેબલ ફરી ગોઠવવું છે? થોડી ક્ષણ માટે એ ટેબલ વપરાશે નહીં — દુકાન ચાલુ હોય ત્યારે ન કરવું સારું.')">
            <?= csrf_field() ?><input type="hidden" name="do" value="optimize">
            <input type="hidden" name="table" value="<?= e($f['name']) ?>">
            <button class="btn btn-sm btn-outline" type="submit">♻️ ફરી ગોઠવો</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <p class="muted" style="font-size:12.5px">⚠️ ફરી ગોઠવવાથી <b>ડેટા બદલાતો નથી</b> — ફક્ત ગોઠવણી બદલાય છે. પણ એ ચાલે એટલી વાર
    ટેબલ લૉક રહે છે, એટલે દુકાન બંધ હોય ત્યારે કરવું.</p>
  <?php endif; ?>
</div>

<?php elseif ($tab === 'never'): ?>
<div class="card">
  <h2>🔒 જે કદી ડિલીટ થતું નથી</h2>
  <p class="muted" style="margin-top:0;font-size:13px">
    સાફસફાઈનું બટન <b>ફક્ત ઉપરની નોંધોની યાદી</b> પર જ કામ કરે છે. એ યાદીમાં ન હોય એવું કંઈ પણ —
    આજનું હોય કે દસ વર્ષ જૂનું — કોઈ ક્રોન, કોઈ બટન કે કોઈ સેટિંગ ડિલીટ કરી શકતું નથી.
    આ તપાસ <b>ડિલીટ કરનારા ફંક્શનની અંદર</b> જ છે, બોલાવનાર સ્ક્રીનમાં નહીં — જેથી નવી સ્ક્રીન બને તો પણ ભુલાય નહીં.
  </p>
  <div class="table-wrap"><table>
    <thead><tr><th>ટેબલ</th><th>કેમ કદી નહીં</th></tr></thead>
    <tbody>
    <?php foreach (sc_never_trim() as $t => $why): ?>
      <tr><td><code><?= e($t) ?></code></td><td><?= e($why) ?></td></tr>
    <?php endforeach; ?>
      <tr><td class="muted">બિલ, પેમેન્ટ, ખરીદી, ગ્રાહક, વસ્તુ, હેન્ડઓવર…</td>
        <td class="muted">ધંધાનો મૂળ ડેટા. યાદીમાં જ નથી, એટલે સવાલ જ ઊભો થતો નથી.</td></tr>
    </tbody>
  </table></div>
  <div class="flash flash-info">
    જગ્યા ખરેખર ઓછી પડે તો રસ્તો ડિલીટ કરવાનો નથી — <a href="settings.php">બેકઅપ</a> લઈને હોસ્ટિંગ મોટું કરાવવાનું છે.
    ધંધાનો ડેટા એ જ સૌથી કીમતી વસ્તુ છે.
  </div>
</div>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
