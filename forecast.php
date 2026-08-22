<?php
// Forecast - cash ahead, sales ahead, and how wrong this method usually is.
//
// The last part is not an afterthought. Every prediction on this screen sits
// next to the record of the same method's past attempts, because a number in a
// box looks like a fact and the owner will spend money against it.
require_once __DIR__ . '/includes/init.php';
require_perm('reports.view');
if (!can('payments.view')) {
    http_response_code(403);
    die('This screen is about money coming in and going out. You need the payments.view permission.');
}
$u = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'save_rules') {
    require_perm('settings.edit');
    set_setting('cash_floor', (string)max(0, (float)post('cash_floor')));
    set_setting('sales_goal', (string)max(0, (float)post('sales_goal')));
    dash_cache_forget('forecast_summary');
    flash('સચવાયું.');
    redirect('forecast.php?tab=' . get('tab', 'cash'));
}

$tab = get('tab', 'cash');
$rules = fc_rules();
$weeks = max(4, min(26, (int)get('w', $rules['weeks'])));

$page_title = 'Forecast';
include __DIR__ . '/includes/header.php';
?>
<div class="card">
  <h2>🔮 આગળ શું દેખાય છે</h2>
  <div class="range-bar">
    <?php foreach (['cash' => '💵 રોકડનું ચિત્ર', 'sales' => '📈 વેચાણનું અનુમાન',
                    'accuracy' => '🎯 અનુમાન કેટલું સાચું પડે છે', 'target' => '🏁 આ મહિનાનું લક્ષ્ય',
                    'rules' => '⚙️ નિયમો'] as $k => $lbl): ?>
    <a class="rchip <?= $tab === $k ? 'on' : '' ?>" href="forecast.php?tab=<?= $k ?>"><?= $lbl ?></a>
    <?php endforeach; ?>
  </div>
  <p class="muted" style="font-size:12.5px;margin-bottom:0">
    ⚠️ <b>અનુમાન એ ધારણા છે, હકીકત નથી.</b> એટલે અહીં દરેક અનુમાનની બાજુમાં
    <a href="forecast.php?tab=accuracy">એ જ પદ્ધતિ ભૂતકાળમાં કેટલી ખોટી પડી હતી</a> એ પણ લખેલું છે.
    રોકડનું ચિત્ર મોટે ભાગે ધારણા નથી — બિલ, રકમ અને તારીખો ચોપડામાં લખેલી જ છે.
  </p>
</div>

<?php if ($tab === 'cash'): $cf = fc_cashflow($weeks); ?>
<div class="card">
  <h2>💵 આવતાં <?= (int)$weeks ?> અઠવાડિયાંનું રોકડ ચિત્ર</h2>
  <div class="grid-stats">
    <div class="stat"><div class="stat-label">અત્યારે હાથમાં</div><div class="stat-value">₹<?= money($cf['opening']['total']) ?></div></div>
    <div class="stat s-good"><div class="stat-label">આવવાની આશા</div><div class="stat-value">₹<?= money($cf['total_in']) ?></div></div>
    <div class="stat s-warn"><div class="stat-label">જવાના</div><div class="stat-value">₹<?= money($cf['total_out']) ?></div></div>
    <div class="stat <?= $cf['closing'] < $cf['floor'] ? 's-bad' : '' ?>"><div class="stat-label">છેલ્લે બચશે</div><div class="stat-value">₹<?= money($cf['closing']) ?></div></div>
  </div>
  <?php if ($cf['danger']): $d = $cf['danger'][0]; ?>
    <div class="flash flash-error">
      ⚠️ <b><?= e($d['label']) ?></b> ના અઠવાડિયે રોકડ ₹<?= money($d['balance']) ?> સુધી નીચે જાય એવું દેખાય છે
      (<?= count($cf['danger']) ?> અઠવાડિયાં ખેંચમાં). અત્યારથી ઉઘરાણી શરૂ કરો —
      <a href="collection.php">ઉઘરાણી યાદી</a> ખોલો.
    </div>
  <?php else: ?>
    <div class="flash flash-success">✅ આ ગાળામાં રોકડ ક્યાંય <?= $cf['floor'] > 0 ? '₹' . money($cf['floor']) : 'શૂન્ય' ?> થી નીચે જતી દેખાતી નથી.</div>
  <?php endif; ?>
  <div class="table-wrap"><table>
    <thead><tr><th>અઠવાડિયું</th><th class="num">આવશે</th><th class="num">જશે</th><th class="num">ફેર</th><th class="num">બાકી રહેશે</th></tr></thead>
    <tbody>
    <?php foreach ($cf['rows'] as $w): ?>
      <tr>
        <td><?= e($w['label']) ?><?= $w['week'] === 0 ? ' <span class="badge">આ અઠવાડિયું</span>' : '' ?></td>
        <td class="num"><?= $w['in'] > 0 ? '₹' . money($w['in']) : '<span class="muted">·</span>' ?></td>
        <td class="num"><?= $w['out'] > 0 ? '₹' . money($w['out']) : '<span class="muted">·</span>' ?>
          <?php if ($w['fixed'] > 0): ?><div class="muted" style="font-size:11px">પગાર/ભાડું ₹<?= money($w['fixed']) ?></div><?php endif; ?></td>
        <td class="num" style="color:<?= $w['net'] >= 0 ? 'var(--good,#15803d)' : 'var(--bad,#b91c1c)' ?>">
          <?= $w['net'] >= 0 ? '+' : '−' ?>₹<?= money(abs($w['net'])) ?></td>
        <td class="num"><b <?= $w['balance'] < $cf['floor'] ? 'style="color:var(--bad,#b91c1c)"' : '' ?>>₹<?= money($w['balance']) ?></b></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <div class="range-bar" style="margin-top:10px">
    <?php foreach ([4, 8, 12, 26] as $wk): ?>
      <a class="rchip <?= $weeks === $wk ? 'on' : '' ?>" href="forecast.php?tab=cash&w=<?= $wk ?>"><?= $wk ?> અઠવાડિયાં</a>
    <?php endforeach; ?>
  </div>
</div>

<div class="card">
  <h2>🚫 આમાં જે ગણ્યું <b>નથી</b></h2>
  <p class="muted" style="margin-top:0;font-size:13px">
    ઉપરના આંકડામાં આ પૈસા <b>જાણી જોઈને ઉમેર્યા નથી</b> — ઉમેરી દેત તો ચિત્ર ખોટી રીતે સારું દેખાત.
  </p>
  <div class="table-wrap"><table>
    <thead><tr><th>શું</th><th class="num">રકમ</th><th>કેમ ગણ્યું નથી</th></tr></thead>
    <tbody>
      <tr>
        <td>⏰ મુદત વીતી ગયેલી ઉઘરાણી<?= $cf['overdue_bills'] ? ' (' . (int)$cf['overdue_bills'] . ' બિલ)' : '' ?></td>
        <td class="num">₹<?= money($cf['overdue_in']) ?></td>
        <td class="muted" style="font-size:12.5px">આ પૈસા <b>પહેલેથી મોડા</b> છે. "આવતા અઠવાડિયે આવશે" એમ માની લેવું ખોટું છે —
          એ ઉઘરાવવા પડશે. <a href="collection.php">ઉઘરાણી યાદી</a></td>
      </tr>
      <tr>
        <td>🚶 છૂટક બિલ (ગ્રાહક નોંધ્યો નથી)</td>
        <td class="num">₹<?= money($cf['walkin']) ?></td>
        <td class="muted" style="font-size:12.5px">કોઈ ખાતું જ નથી, એટલે કોની પાસે માગવું એ ખબર નથી.</td>
      </tr>
      <tr>
        <td>📅 <?= (int)$weeks ?> અઠવાડિયાં પછી આવનારું</td>
        <td class="num">₹<?= money($cf['beyond']) ?></td>
        <td class="muted" style="font-size:12.5px">આ ગાળાની બહાર છે. ઉપર "26 અઠવાડિયાં" દબાવો તો દેખાશે.</td>
      </tr>
      <tr>
        <td>🛒 હજી ન થયેલું વેચાણ</td>
        <td class="num muted">—</td>
        <td class="muted" style="font-size:12.5px">રોકડના ચિત્રમાં ફક્ત <b>જે બિલ ખરેખર બની ગયાં છે</b> એ જ છે.
          ભવિષ્યના વેચાણનું અનુમાન <a href="forecast.php?tab=sales">અલગ ટૅબમાં</a> છે — એ ધારણા છે, એટલે એને રોકડમાં ભેળવી નથી.</td>
      </tr>
    </tbody>
  </table></div>
</div>

<?php if ($cf['recurring']): ?>
<div class="card">
  <h2>🔁 દર મહિને નીકળતા ખર્ચ</h2>
  <p class="muted" style="margin-top:0;font-size:13px">
    છેલ્લા <?= (int)$rules['recur_months'] ?> માંથી ઓછામાં ઓછા <?= (int)$rules['recur_hits'] ?> મહિનામાં આવેલા ખર્ચ.
    રકમ એ મહિનાઓની <b>વચલી</b> રકમ છે (સરેરાશ નહીં) — એક વખતનો મોટો ખર્ચ આખા અનુમાનને ફુલાવી ન દે એટલા માટે.
  </p>
  <div class="table-wrap"><table>
    <thead><tr><th>ખર્ચ</th><th class="num">દર મહિને (અંદાજ)</th><th class="num">કેટલા મહિનામાં આવ્યો</th><th class="num">સામાન્ય તારીખ</th></tr></thead>
    <tbody>
    <?php foreach ($cf['recurring'] as $e): ?>
      <tr><td><?= e($e['category']) ?></td><td class="num">₹<?= money($e['amount']) ?></td>
        <td class="num muted"><?= (int)$e['months'] ?> / <?= (int)$rules['recur_months'] ?></td>
        <td class="num muted"><?= (int)$e['day'] ?> તારીખ</td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>

<?php elseif ($tab === 'sales'): $sa = fc_sales_ahead(3); ?>
<div class="card">
  <h2>📈 આવતા મહિનાઓનું વેચાણ</h2>
  <?php if (!$sa['ok']): ?>
    <div class="flash flash-info">📉 <?= e($sa['why']) ?></div>
    <p class="muted">ઓછા ઇતિહાસ પરથી આંકડો કાઢીને બતાવવો સહેલો છે, પણ એ ખોટો રસ્તો છે.</p>
  <?php else: ?>
  <p class="muted" style="margin-top:0;font-size:13px">
    છેલ્લા <?= (int)$rules['base_months'] ?> પૂરા મહિનાની ભારાંકિત સરેરાશ, અને એના પર
    <b>એ મહિનાની સીઝન</b>નો ગુણાકાર (બે વર્ષથી ઓછો ઇતિહાસ હોય તો સીઝન લગાડાતી નથી).
    <?php if ($sa['band'] !== null): ?>
      રેન્જ <b>±<?= $sa['band'] ?>%</b> ની છે — એ મનઘડંત નથી, <a href="forecast.php?tab=accuracy">આ પદ્ધતિ ભૂતકાળમાં સરેરાશ એટલી જ ખોટી પડી છે</a>.
    <?php else: ?>
      રેન્જ બતાવી નથી — પદ્ધતિ હજી ભૂતકાળ સામે ચકાસાઈ નથી.
    <?php endif; ?>
  </p>
  <div class="table-wrap"><table>
    <thead><tr><th>મહિનો</th><th class="num">અંદાજ</th><th class="num">આટલાથી આટલા વચ્ચે</th><th class="num">સીઝન</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($sa['rows'] as $r): ?>
      <tr>
        <td><?= e(date('F Y', strtotime($r['month'] . '-01'))) ?>
          <?= $r['partial'] ? ' <span class="badge b-warn">ચાલુ મહિનો</span>' : '' ?></td>
        <td class="num"><b>₹<?= money($r['value']) ?></b></td>
        <td class="num"><?= $r['low'] === null ? '<span class="muted">—</span>' : '₹' . money($r['low']) . ' – ₹' . money($r['high']) ?></td>
        <td class="num"><?= $r['factor'] == 1.0 ? '<span class="muted">લગાડી નથી</span>' : '×' . $r['factor'] ?>
          <?php if ($r['years']): ?><div class="muted" style="font-size:11px"><?= (int)$r['years'] ?> વર્ષ પરથી</div><?php endif; ?></td>
        <td class="muted" style="font-size:12px"><?= e($r['season_why']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <p class="muted" style="font-size:12.5px">
    ⚠️ ચાલુ મહિનાનો અંદાજ <b>આખા મહિનાનો</b> છે — અત્યાર સુધીનું વેચાણ <a href="forecast.php?tab=target">લક્ષ્ય ટૅબમાં</a> જુઓ.
    અને આ આંકડામાં આવતા મહિને શું થશે એની કોઈ બહારની માહિતી નથી — ફક્ત તમારો પોતાનો ઇતિહાસ.
  </p>
  <?php endif; ?>
</div>

<?php elseif ($tab === 'accuracy'): $bt = fc_backtest(12); ?>
<div class="card">
  <h2>🎯 આ પદ્ધતિ ખરેખર કેટલી સાચી પડે છે</h2>
  <p class="muted" style="margin-top:0;font-size:13px">
    જે મહિનાઓ <b>વીતી ગયા છે</b> એના માટે આ જ પદ્ધતિ ફરી ચલાવી છે — <b>એ મહિના પહેલાંનો ડેટા જ વાપરીને</b> —
    અને એણે શું કહ્યું હોત એની સામે ખરેખર શું થયું એ મૂક્યું છે. આ જ કારણે અનુમાનની રેન્જ ભરોસાપાત્ર છે.
  </p>
  <?php if (!$bt['n']): ?>
    <div class="flash flash-info">ચકાસવા માટે પૂરતા વીતેલા મહિના નથી.</div>
  <?php else: ?>
  <div class="grid-stats">
    <div class="stat"><div class="stat-label">ચકાસેલા મહિના</div><div class="stat-value"><?= (int)$bt['n'] ?></div></div>
    <div class="stat <?= $bt['mape'] > 25 ? 's-bad' : ($bt['mape'] > 15 ? 's-warn' : 's-good') ?>">
      <div class="stat-label">સરેરાશ ભૂલ</div><div class="stat-value"><?= $bt['mape'] ?>%</div></div>
    <div class="stat s-good"><div class="stat-label">સૌથી નજીક</div><div class="stat-value"><?= $bt['best'] ?>%</div></div>
    <div class="stat s-warn"><div class="stat-label">સૌથી દૂર</div><div class="stat-value"><?= $bt['worst'] ?>%</div></div>
  </div>
  <?php if ($bt['mape'] > 25): ?>
    <div class="flash flash-error">⚠️ સરેરાશ ભૂલ <?= $bt['mape'] ?>% છે — એટલે આ અનુમાન પર મોટા નિર્ણય <b>ન</b> લેશો.
      વેચાણ બહુ ઉપર-નીચે થતું હોય ત્યારે કોઈ પણ પદ્ધતિ સાચી પડતી નથી.</div>
  <?php endif; ?>
  <div class="table-wrap"><table>
    <thead><tr><th>મહિનો</th><th class="num">પદ્ધતિએ કહ્યું હોત</th><th class="num">ખરેખર થયું</th><th class="num">ફેર</th><th class="num">ભૂલ</th></tr></thead>
    <tbody>
    <?php foreach ($bt['rows'] as $r): ?>
      <tr>
        <td><?= e(date('M Y', strtotime($r['month'] . '-01'))) ?></td>
        <td class="num muted">₹<?= money($r['predicted']) ?></td>
        <td class="num">₹<?= money($r['actual']) ?></td>
        <td class="num" style="color:<?= $r['diff'] >= 0 ? 'var(--warn,#b45309)' : 'var(--bad,#b91c1c)' ?>">
          <?= $r['diff'] >= 0 ? 'વધુ કહ્યું +' : 'ઓછું કહ્યું −' ?>₹<?= money(abs($r['diff'])) ?></td>
        <td class="num"><span class="badge <?= $r['err_pct'] > 20 ? 'b-bad' : ($r['err_pct'] > 10 ? 'b-warn' : 'b-good') ?>"><?= $r['err_pct'] ?>%</span></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<?php elseif ($tab === 'target'): $goal = (float)setting('sales_goal', 0); $tg = fc_target($goal); ?>
<div class="card">
  <h2>🏁 આ મહિનો — <?= e(date('F Y')) ?></h2>
  <div class="grid-stats">
    <div class="stat"><div class="stat-label">અત્યાર સુધી</div><div class="stat-value">₹<?= money($tg['sofar']) ?></div></div>
    <div class="stat"><div class="stat-label">રોજની સરેરાશ</div><div class="stat-value">₹<?= money($tg['per_day_so_far']) ?></div></div>
    <div class="stat"><div class="stat-label">આ ગતિએ મહિનો પૂરો થાય</div><div class="stat-value">₹<?= money($tg['at_this_pace']) ?></div></div>
    <div class="stat"><div class="stat-label">બાકી દિવસ</div><div class="stat-value"><?= (int)$tg['left'] ?></div></div>
  </div>
  <p class="muted" style="font-size:12.5px">આમાં કોઈ અનુમાન નથી — ફક્ત <?= (int)$tg['done'] ?> દિવસનું ખરેખરનું વેચાણ ભાગ્યા <?= (int)$tg['done'] ?>, ગુણ્યા <?= (int)$tg['days'] ?>.</p>
  <?php if ($goal > 0): ?>
    <div class="flash <?= $tg['needed'] <= 0 ? 'flash-success' : ($tg['at_this_pace'] >= $goal ? 'flash-info' : 'flash-error') ?>">
      <?php if ($tg['needed'] <= 0): ?>
        ✅ ₹<?= money($goal) ?> નું લક્ષ્ય પૂરું થઈ ગયું.
      <?php elseif ($tg['left'] <= 0): ?>
        મહિનો પૂરો થયો — ₹<?= money($tg['needed']) ?> ઘટ્યા.
      <?php else: ?>
        ₹<?= money($goal) ?> સુધી પહોંચવા બાકીના <?= (int)$tg['left'] ?> દિવસમાં <b>રોજ ₹<?= money($tg['per_day_needed']) ?></b> જોઈએ
        (અત્યારે રોજ ₹<?= money($tg['per_day_so_far']) ?> થાય છે).
      <?php endif; ?>
    </div>
  <?php else: ?>
    <p class="muted">લક્ષ્ય નક્કી કરવું હોય તો <a href="forecast.php?tab=rules">નિયમોમાં</a> ભરો.</p>
  <?php endif; ?>
</div>

<?php elseif ($tab === 'rules'): ?>
<div class="card">
  <h2>⚙️ નિયમો</h2>
  <?php if (can('settings.edit')): ?>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="do" value="save_rules">
    <div class="field"><label>રોકડ આટલાથી નીચે જાય તો ચેતવણી (₹)</label>
      <input type="number" name="cash_floor" value="<?= (float)$rules['floor'] ?>" min="0" step="any">
      <p class="muted" style="font-size:12.5px">ઘણી દુકાનોને ખાતામાં થોડી રકમ હંમેશાં જોઈતી હોય છે. 0 રાખો તો શૂન્યથી નીચે જાય ત્યારે જ ચેતવણી.</p></div>
    <div class="field"><label>મહિનાનું વેચાણ લક્ષ્ય (₹)</label>
      <input type="number" name="sales_goal" value="<?= (float)setting('sales_goal', 0) ?>" min="0" step="any">
      <p class="muted" style="font-size:12.5px">ખાલી રાખો તો લક્ષ્યની ગણતરી બતાવાશે નહીં.</p></div>
    <button class="btn btn-primary" type="submit">સાચવો</button>
  </form>
  <?php else: ?><p class="muted">બદલવા માટે settings.edit પરવાનગી જોઈએ.</p><?php endif; ?>
  <hr>
  <p class="muted" style="font-size:13px">
    બાકીના નિયમો કોડમાં નક્કી છે: અનુમાન માટે છેલ્લા <?= (int)$rules['base_months'] ?> પૂરા મહિના ·
    ઓછામાં ઓછા <?= (int)$rules['min_months'] ?> મહિના હોય તો જ અનુમાન ·
    પદ્ધતિ <?= (int)$rules['backtest'] ?> વીતેલા મહિના સામે ચકાસાય છે ·
    દર મહિનાનો ખર્ચ = <?= (int)$rules['recur_months'] ?> માંથી <?= (int)$rules['recur_hits'] ?> મહિનામાં આવેલો ·
    ગ્રાહકનું મોડું ચૂકવવું વધુમાં વધુ <?= (int)$rules['max_delay'] ?> દિવસ સુધી ગણાય.
  </p>
</div>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
