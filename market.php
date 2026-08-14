<?php
// Market Intelligence - what the shop's own books say about its market.
//
// The first thing this screen does is say what it cannot know. There is no
// competitor price here, no industry figure, no market share: the shop has no
// source for any of it, and a made-up number that looks official is the one
// kind of mistake this whole system is built to avoid.
//
// Everything shown is money that already passed through the books, and every
// row links to the documents it came from.
require_once __DIR__ . '/includes/init.php';
require_perm('reports.view');
if (!can('reports.profit') && !can('items.cost')) {
    http_response_code(403);
    die('This screen compares what you pay with what you get. You need the reports.profit or items.cost permission.');
}
$u = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'save_rules') {
    require_perm('settings.edit');
    set_setting('market_min_amount', (string)max(0, (float)post('market_min_amount')));
    dash_cache_forget('market_summary');
    flash('નિયમ સચવાયો.');
    redirect('market.php?tab=momentum');
}

$tab = get('tab', 'momentum');
$h = mk_history();
$sum = mk_summary();
$rules = mk_rules();

/** Up/down/flat as a coloured chip - the same words on every tab. */
function mk_chip(array $x) {
    $cls = ['up' => 'b-good', 'down' => 'b-bad', 'flat' => ''][$x['dir']] ?? '';
    $arrow = ['up' => '▲', 'down' => '▼', 'flat' => '＝'][$x['dir']] ?? '';
    return '<span class="badge ' . $cls . '">' . $arrow . ' ' . e($x['label']) . '</span>';
}

$page_title = 'Market Intelligence';
include __DIR__ . '/includes/header.php';
?>
<div class="card">
  <h2>📊 બજારની સમજ</h2>
  <div class="grid-stats">
    <div class="stat"><div class="stat-label">છેલ્લા વર્ષનું વેચાણ</div><div class="stat-value">₹<?= money($sum['sales_year']) ?></div></div>
    <div class="stat <?= $sum['change']['dir'] === 'up' ? 's-good' : ($sum['change']['dir'] === 'down' ? 's-bad' : '') ?>">
      <div class="stat-label">આગલા વર્ષ સામે</div><div class="stat-value"><?= e($sum['change']['label']) ?></div></div>
    <a class="stat s-good" href="market.php?tab=momentum"><div class="stat-label">વધતી કેટેગરી</div><div class="stat-value"><?= (int)$sum['growing'] ?></div></a>
    <a class="stat <?= $sum['shrinking'] ? 's-bad' : '' ?>" href="market.php?tab=momentum"><div class="stat-label">ઘટતી કેટેગરી</div><div class="stat-value"><?= (int)$sum['shrinking'] ?></div></a>
  </div>
  <div class="range-bar">
    <?php foreach (['momentum' => '📈 શું વધ્યું, શું ઘટ્યું', 'squeeze' => '🪤 ભાવનું દબાણ',
                    'discount' => '✂️ વટાવમાં ગયેલા પૈસા', 'life' => '🌱 નવું અને મરી ગયેલું',
                    'season' => '📅 સીઝન', 'where' => '📍 ગ્રાહક ક્યાંના',
                    'quotes' => '📝 ગુમાવેલા સોદા', 'rules' => '⚙️ નિયમો'] as $k => $lbl): ?>
    <a class="rchip <?= $tab === $k ? 'on' : '' ?>" href="market.php?tab=<?= $k ?>"><?= $lbl ?></a>
    <?php endforeach; ?>
  </div>
  <p class="muted" style="font-size:12.5px;margin-bottom:0">
    ⚠️ <b>અહીં બહારની કોઈ માહિતી નથી.</b> હરીફની કિંમત, બજારનો ભાવ કે તમારો બજારહિસ્સો —
    એ ડેટા દુકાન પાસે નથી, અને ધારીને લખવો એ ખોટું છે. નીચે જે છે એ બધું <b>તમારા પોતાના ચોપડામાંથી</b> જ છે,
    અને દરેક આંકડો ખોલીને બિલ સુધી પહોંચી શકાય છે.
    ઇતિહાસ: <b><?= $h['years'] ?> વર્ષ</b> (<?= dmy($h['first_sale']) ?> થી), <?= number_format($h['bills']) ?> બિલ.
  </p>
</div>

<?php if ($tab === 'momentum'): $by = get('by', 'category'); $rows = mk_momentum($by); ?>
<div class="card">
  <h2>📈 શું વધ્યું, શું ઘટ્યું</h2>
  <p class="muted" style="margin-top:0;font-size:13px">
    <b>છેલ્લા 365 દિવસ</b> સામે <b>એની આગળના 365 દિવસ</b>. આખા વર્ષ સામે વર્ષ સરખાવ્યું છે — ગયા મહિના સામે નહીં —
    નહીં તો દિવાળીનો ઉછાળો "વિકાસ" દેખાય અને ચોમાસું "પડતી".
    <?= $rules['move_pct'] ?>% થી ઓછો ફેરફાર "સરખું" ગણ્યો છે.
  </p>
  <?php if (!$h['can_compare_year']): ?>
    <div class="flash flash-info">બે વર્ષથી ઓછો ઇતિહાસ છે (<?= $h['years'] ?> વર્ષ), એટલે આ સરખામણી અધૂરી છે — ધ્યાનથી વાંચજો.</div>
  <?php endif; ?>
  <div class="range-bar" style="margin-top:8px">
    <a class="rchip <?= $by === 'category' ? 'on' : '' ?>" href="market.php?tab=momentum&by=category">કેટેગરી પ્રમાણે</a>
    <a class="rchip <?= $by === 'brand' ? 'on' : '' ?>" href="market.php?tab=momentum&by=brand">બ્રાન્ડ પ્રમાણે</a>
  </div>
  <?php if (!$rows): ?><p class="muted">સરખાવવા જેવું પૂરતું વેચાણ નથી.</p><?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th><?= $by === 'brand' ? 'બ્રાન્ડ' : 'કેટેગરી' ?></th><th class="num">આ વર્ષ</th><th class="num">ગયું વર્ષ</th>
      <th class="num">ફેર</th><th>ચાલ</th><th class="num">બિલ</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $x): ?>
      <tr>
        <td><?= $x['id'] ? '<a href="items.php?f_cat=' . (int)$x['id'] . '">' . e($x['name']) . '</a>' : e($x['name']) ?></td>
        <td class="num">₹<?= money($x['now']) ?></td>
        <td class="num muted">₹<?= money($x['prev']) ?></td>
        <td class="num" style="color:<?= $x['diff'] >= 0 ? 'var(--good,#15803d)' : 'var(--bad,#b91c1c)' ?>">
          <?= $x['diff'] >= 0 ? '+' : '−' ?>₹<?= money(abs($x['diff'])) ?></td>
        <td><?= mk_chip($x) ?></td>
        <td class="num muted"><?= (int)$x['now_bills'] ?> <span style="font-size:11px">← <?= (int)$x['prev_bills'] ?></span></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<?php elseif ($tab === 'squeeze'): $sq = mk_squeeze(); ?>
<div class="card">
  <h2>🪤 ભાવનું દબાણ — જે આપો છો એની સામે જે મળે છે</h2>
  <p class="muted" style="margin-top:0;font-size:13px">
    દરેક વસ્તુ માટે બે સાચા આંકડા: તમે <b>ખરેખર ચૂકવેલો</b> સરેરાશ ખરીદ ભાવ, અને તમને બિલમાં
    <b>ખરેખર મળેલો</b> સરેરાશ ભાવ (લાઇન વટાવ બાદ કર્યા પછીનો). બંનેની છેલ્લા <?= $sq['window'] ?> દિવસની
    સરખામણી એની આગળના <?= $sq['window'] ?> દિવસ સાથે.
    <b>ખરીદી વધુ મોંઘી થાય અને વેચાણ ભાવ એટલો ન વધે — એ દબાણ છે.</b>
  </p>
  <?php if ($sq['skipped']): ?>
    <div class="flash flash-info">
      <?= (int)$sq['skipped'] ?> વસ્તુ સરખાવી શકાઈ નથી — એ બંને ગાળામાં ખરીદાઈ <b>અને</b> વેચાઈ હોય તો જ સરખામણી શક્ય છે.
      અધૂરા આંકડા પરથી કંઈ ધારી લીધું નથી.
    </div>
  <?php endif; ?>
  <?php if (!$sq['rows']): ?><p class="muted">સરખાવી શકાય એવી એકેય વસ્તુ મળી નથી.</p><?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th>વસ્તુ</th><th class="num">ખરીદ ભાવ</th><th class="num">મળેલો ભાવ</th>
      <th class="num">માર્જિન</th><th class="num">દબાણ</th></tr></thead>
    <tbody>
    <?php foreach ($sq['rows'] as $x): ?>
      <tr>
        <td><a href="item_view.php?id=<?= (int)$x['item_id'] ?>"><?= e($x['name']) ?></a>
          <div class="muted" style="font-size:12px"><?= e($x['brand']) ?> · <?= rtrim(rtrim(number_format($x['qty'], 2), '0'), '.') ?> નંગ વેચ્યા</div></td>
        <td class="num">₹<?= money($x['pay_now']) ?><div class="muted" style="font-size:11px">← ₹<?= money($x['pay_prev']) ?> (<?= $x['pay_pct'] > 0 ? '+' : '' ?><?= $x['pay_pct'] ?>%)</div></td>
        <td class="num">₹<?= money($x['got_now']) ?><div class="muted" style="font-size:11px">← ₹<?= money($x['got_prev']) ?> (<?= $x['got_pct'] > 0 ? '+' : '' ?><?= $x['got_pct'] ?>%)</div></td>
        <td class="num">₹<?= money($x['margin_now']) ?><div class="muted" style="font-size:11px">← ₹<?= money($x['margin_prev']) ?></div></td>
        <td class="num"><span class="badge <?= $x['squeeze'] < -5 ? 'b-bad' : ($x['squeeze'] > 5 ? 'b-good' : '') ?>">
          <?= $x['squeeze'] > 0 ? '+' : '' ?><?= $x['squeeze'] ?></span></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <p class="muted" style="font-size:12px">"દબાણ" = વેચાણ ભાવનો ફેરફાર % − ખરીદ ભાવનો ફેરફાર %. ઋણ એટલે ખરીદી વધુ ઝડપથી મોંઘી થઈ.</p>
  <?php endif; ?>
</div>

<?php elseif ($tab === 'discount'): $d = mk_discount(13); $dc = mk_discount_by_category(); ?>
<div class="card">
  <h2>✂️ વટાવમાં ખરેખર કેટલા પૈસા ગયા</h2>
  <p class="muted" style="margin-top:0;font-size:13px">
    બિલમાં જ્યાં જ્યાં છૂટ આપી છે એ બધું ગણ્યું છે — <b>લાઇનનો વટાવ</b>, <b>બિલનો વટાવ</b> અને
    <b>લોયલ્ટી પોઇન્ટ</b> જે પૈસા તરીકે વપરાયા. "પૂરો ભાવ" એટલે કોઈ છૂટ વગર બિલ કેટલું થાત.
  </p>
  <?php if (!$d): ?><p class="muted">હજી કોઈ બિલ નથી.</p><?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th>મહિનો</th><th class="num">બિલ</th><th class="num">પૂરો ભાવ</th><th class="num">છૂટ આપી</th>
      <th class="num">%</th><th class="num">લાઇન / બિલ / લોયલ્ટી</th></tr></thead>
    <tbody>
    <?php foreach (array_reverse($d) as $m): ?>
      <tr>
        <td><?= e($m['month']) ?></td>
        <td class="num muted"><?= (int)$m['bills'] ?></td>
        <td class="num">₹<?= money($m['gross']) ?></td>
        <td class="num">₹<?= money($m['given']) ?></td>
        <td class="num"><span class="badge <?= $m['pct'] >= 5 ? 'b-bad' : ($m['pct'] >= 2 ? 'b-warn' : '') ?>"><?= $m['pct'] ?>%</span></td>
        <td class="num muted" style="font-size:12px">₹<?= money($m['line_disc']) ?> / ₹<?= money($m['bill_disc']) ?> / ₹<?= money($m['loyalty']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>
<div class="card">
  <h2>✂️ કઈ કેટેગરીમાં સૌથી વધુ છૂટ જાય છે</h2>
  <p class="muted" style="margin-top:0;font-size:13px">
    અહીં ફક્ત <b>લાઇનનો વટાવ</b> ગણ્યો છે. બિલ પરનો વટાવ આખા બિલનો હોય છે — એને કેટેગરીઓમાં વહેંચવો પડે,
    અને એ વહેંચણી બનાવટી આંકડો બની જાત. એટલે એ અહીં ગણ્યો નથી.
  </p>
  <?php if (!$dc): ?><p class="muted">છેલ્લા વર્ષમાં કોઈ લાઇન-વટાવ આપ્યો નથી.</p><?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th>કેટેગરી</th><th class="num">છૂટ</th><th class="num">ચોખ્ખું વેચાણ</th><th class="num">બિલ</th></tr></thead>
    <tbody>
    <?php foreach ($dc as $x): ?>
      <tr><td><?= e($x['name']) ?></td><td class="num">₹<?= money($x['given']) ?></td>
        <td class="num muted">₹<?= money($x['net']) ?></td><td class="num muted"><?= (int)$x['bills'] ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<?php elseif ($tab === 'life'): $lc = mk_lifecycle(); ?>
<div class="card">
  <h2>🌱 નવું શું ચાલ્યું</h2>
  <p class="muted" style="margin-top:0;font-size:13px">છેલ્લા <?= $lc['new_days'] ?> દિવસમાં <b>પહેલી વાર</b> વેચાયેલી વસ્તુઓ.</p>
  <?php if (!$lc['rising']): ?><p class="muted">આ ગાળામાં કોઈ નવી વસ્તુ વેચાઈ નથી.</p><?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th>વસ્તુ</th><th>પહેલી વાર</th><th class="num">નંગ</th><th class="num">વેચાણ</th><th class="num">બિલ</th></tr></thead>
    <tbody>
    <?php foreach ($lc['rising'] as $x): ?>
      <tr><td><a href="item_view.php?id=<?= (int)$x['id'] ?>"><?= e($x['name']) ?></a>
          <div class="muted" style="font-size:12px"><?= e($x['brand']) ?></div></td>
        <td><?= dmy($x['first_sold']) ?></td>
        <td class="num"><?= rtrim(rtrim(number_format($x['qty'], 2), '0'), '.') ?></td>
        <td class="num">₹<?= money($x['amt']) ?></td><td class="num muted"><?= (int)$x['bills'] ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>
<div class="card">
  <h2>🪦 જે વેચાતું હતું અને હવે બંધ</h2>
  <p class="muted" style="margin-top:0;font-size:13px">
    પહેલાં નિયમિત વેચાતી, પણ છેલ્લા <?= $lc['fade_days'] ?> દિવસથી એકેય બિલમાં ન આવેલી ચાલુ વસ્તુઓ.
    સ્ટોક પડ્યો હોય તો પૈસા ત્યાં અટવાયા છે — <a href="purchase_intel.php?tab=reorder">શું મંગાવવું</a> માં એની આખી વિગત છે.
  </p>
  <?php if (!$lc['fading']): ?><p class="muted">એવી કોઈ વસ્તુ નથી — બધું ચાલુ છે.</p><?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th>વસ્તુ</th><th class="num">કેટલા દિવસથી બંધ</th><th class="num">પડેલો સ્ટોક</th><th class="num">કુલ વેચાણ હતું</th></tr></thead>
    <tbody>
    <?php foreach ($lc['fading'] as $x): ?>
      <tr><td><a href="item_view.php?id=<?= (int)$x['id'] ?>"><?= e($x['name']) ?></a>
          <div class="muted" style="font-size:12px"><?= e($x['brand']) ?> · છેલ્લે <?= dmy($x['last_sold']) ?></div></td>
        <td class="num"><span class="badge <?= $x['quiet_days'] >= 365 ? 'b-bad' : 'b-warn' ?>"><?= (int)$x['quiet_days'] ?></span></td>
        <td class="num"><?= rtrim(rtrim(number_format((float)$x['stock'], 2), '0'), '.') ?></td>
        <td class="num muted">₹<?= money($x['amt']) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<?php elseif ($tab === 'season'): $se = mk_season(); ?>
<div class="card">
  <h2>📅 વર્ષમાં કયો મહિનો ભારે, કયો હળવો</h2>
  <?php if (!$se['ok']): ?>
    <div class="flash flash-info">📉 <?= e($se['why']) ?></div>
    <p class="muted">એક જ ડિસેમ્બર જોઈને "ડિસેમ્બરમાં ધંધો સારો ચાલે છે" કહેવું એ અનુમાન છે, હકીકત નહીં. એટલે અહીં કંઈ બતાવ્યું નથી.</p>
  <?php else: ?>
  <p class="muted" style="margin-top:0;font-size:13px">
    ફક્ત <b>આખા મહિના</b> ગણ્યા છે (<?= dmy($se['from']) ?> થી <?= dmy($se['to']) ?>) — અધૂરો પહેલો અને ચાલુ મહિનો બાદ,
    નહીં તો અડધો મહિનો "મંદી" દેખાય. 100 એટલે સરેરાશ મહિનો (₹<?= money($se['mean']) ?>).
    દરેક લાઇનમાં એ કેટલાં વર્ષ પર આધારિત છે એ પણ લખ્યું છે.
  </p>
  <div class="table-wrap"><table>
    <thead><tr><th>મહિનો</th><th class="num">સરેરાશ વેચાણ</th><th class="num">સૂચકાંક</th><th></th><th class="num">કેટલાં વર્ષ</th></tr></thead>
    <tbody>
    <?php foreach ($se['months'] as $m): ?>
      <tr>
        <td><?= e($m['name']) ?></td>
        <td class="num">₹<?= money($m['avg']) ?></td>
        <td class="num"><span class="badge <?= $m['index'] >= 110 ? 'b-good' : ($m['index'] <= 90 && $m['index'] > 0 ? 'b-warn' : '') ?>"><?= (int)$m['index'] ?></span></td>
        <td style="width:40%"><div style="background:var(--line,#e5e7eb);height:10px;border-radius:5px;overflow:hidden">
          <div style="width:<?= min(100, (int)$m['index'] / 1.5) ?>%;height:100%;background:var(--accent,#2563eb)"></div></div></td>
        <td class="num muted"><?= (int)$m['years'] ?><?= (int)$m['years'] < 2 ? ' <span class="badge b-warn">અધૂરું</span>' : '' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<?php elseif ($tab === 'where'): $g = mk_geography(); ?>
<div class="card">
  <h2>📍 ગ્રાહકો ક્યાંના છે</h2>
  <?php if (!$g['ok']): ?>
    <div class="flash flash-info">📍 <?= e($g['why']) ?></div>
    <p class="muted"><a href="parties.php">ગ્રાહકોમાં</a> શહેરનું ખાનું ભરવા માંડો, પછી અહીં આપોઆપ દેખાશે.
      અત્યારે <?= (int)$g['total'] ?> માંથી <?= (int)$g['filled'] ?> ના શહેર ભરેલા છે.</p>
  <?php else: ?>
  <p class="muted" style="margin-top:0;font-size:13px">
    છેલ્લા વર્ષનું વેચાણ, ગ્રાહકના શહેર પ્રમાણે. <?= (int)$g['total'] ?> માંથી <b><?= (int)$g['filled'] ?></b> ગ્રાહકોના શહેર ભરેલા છે —
    બાકીના આ યાદીમાં નથી, એટલે આ અધૂરું ચિત્ર છે.
  </p>
  <div class="table-wrap"><table>
    <thead><tr><th>શહેર</th><th class="num">ગ્રાહક</th><th class="num">બિલ</th><th class="num">વેચાણ</th></tr></thead>
    <tbody>
    <?php foreach ($g['rows'] as $x): ?>
      <tr><td><?= e($x['city']) ?></td><td class="num"><?= (int)$x['customers'] ?></td>
        <td class="num muted"><?= (int)$x['bills'] ?></td><td class="num">₹<?= money($x['amt']) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<?php elseif ($tab === 'quotes'): $lq = mk_lost_quotes(); $li = mk_lost_items(); ?>
<div class="card">
  <h2>📝 ક્વોટેશન જે બિલ ન બન્યાં</h2>
  <?php if (!$lq['ok']): ?>
    <div class="flash flash-info">📝 <?= e($lq['why']) ?></div>
    <p class="muted"><a href="estimates.php">ક્વોટેશન</a> બનાવવાનું શરૂ કરો, પછી કેટલા સોદા જીત્યા અને કેટલા ગયા એ અહીં દેખાશે.</p>
  <?php else: $st = $lq['stats']; ?>
  <p class="muted" style="margin-top:0;font-size:13px">
    છેલ્લા વર્ષનાં ક્વોટેશન. <b>સોદો કેમ ગયો એ ચોપડો જાણતો નથી</b> — એટલે અહીં કારણ લખ્યું નથી, ફક્ત હકીકત છે.
  </p>
  <div class="grid-stats">
    <div class="stat s-good"><div class="stat-label">બિલ બન્યાં</div><div class="stat-value"><?= (int)$st['won'] ?></div></div>
    <div class="stat s-bad"><div class="stat-label">ના પાડી / રદ</div><div class="stat-value"><?= (int)$st['lost'] ?></div></div>
    <div class="stat s-warn"><div class="stat-label">હજી ખુલ્લાં</div><div class="stat-value"><?= (int)$st['pending'] ?></div></div>
    <div class="stat"><div class="stat-label">જીતવાનો દર</div><div class="stat-value"><?= $st['win_pct'] === null ? '—' : $st['win_pct'] . '%' ?></div></div>
  </div>
  <p class="muted" style="font-size:12.5px">બન્યાં ₹<?= money($st['won_amt']) ?> · ગયાં ₹<?= money($st['lost_amt']) ?> · ખુલ્લાં ₹<?= money($st['pending_amt']) ?>
    <?php if ($st['win_pct'] === null): ?><br>એકેય ક્વોટેશન બંધ થયું નથી, એટલે જીતવાનો દર ગણી શકાય એમ નથી.<?php endif; ?></p>
  <div class="table-wrap"><table>
    <thead><tr><th>ક્વોટેશન</th><th>ગ્રાહક</th><th class="num">રકમ</th><th>સ્થિતિ</th><th class="num">ઉંમર</th></tr></thead>
    <tbody>
    <?php foreach ($lq['rows'] as $x): ?>
      <tr><td><a href="estimates.php?action=view&id=<?= (int)$x['id'] ?>"><?= e($x['estimate_no']) ?></a>
          <div class="muted" style="font-size:12px"><?= dmy($x['estimate_date']) ?></div></td>
        <td><?= $x['party_id'] ? '<a href="customer.php?id=' . (int)$x['party_id'] . '">' . e($x['customer_name']) . '</a>' : e($x['customer_name']) ?></td>
        <td class="num">₹<?= money($x['total']) ?></td>
        <td><span class="badge <?= $x['status'] === 'rejected' ? 'b-bad' : 'b-warn' ?>"><?= e($x['status']) ?></span></td>
        <td class="num muted"><?= (int)$x['age'] ?> દિવસ</td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>
<?php if ($li): ?>
<div class="card">
  <h2>📝 કઈ વસ્તુના ભાવ પુછાય છે પણ વેચાતી નથી</h2>
  <div class="table-wrap"><table>
    <thead><tr><th>વસ્તુ</th><th class="num">કેટલાં ક્વોટેશનમાં</th><th class="num">રકમ</th></tr></thead>
    <tbody>
    <?php foreach ($li as $x): ?>
      <tr><td><a href="item_view.php?id=<?= (int)$x['id'] ?>"><?= e($x['name']) ?></a></td>
        <td class="num"><?= (int)$x['quotes'] ?></td><td class="num">₹<?= money($x['amt']) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>

<?php elseif ($tab === 'rules'): ?>
<div class="card">
  <h2>⚙️ નિયમો</h2>
  <?php if (can('settings.edit')): ?>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="do" value="save_rules">
    <div class="field"><label>આટલા રૂપિયાથી નાની કેટેગરી/બ્રાન્ડ ગણવી નહીં</label>
      <input type="number" name="market_min_amount" value="<?= (float)$rules['min_amount'] ?>" min="0" step="any">
      <p class="muted" style="font-size:12.5px">બે-ચાર રૂપિયાનું વેચાણ "200% વધ્યું" દેખાય એ ગેરમાર્ગે દોરે છે. એટલી નાની લાઇન યાદીમાંથી કાઢી નાખવા માટે.</p>
    </div>
    <button class="btn btn-primary" type="submit">સાચવો</button>
  </form>
  <?php else: ?><p class="muted">નિયમ બદલવા માટે settings.edit પરવાનગી જોઈએ.</p><?php endif; ?>
  <hr>
  <p class="muted" style="font-size:13px">
    બાકીના નિયમો કોડમાં નક્કી છે અને દરેક પાના પર લખેલા છે:
    <?= $rules['move_pct'] ?>% થી ઓછો ફેરફાર = સરખું ·
    ભાવના દબાણ માટે <?= $rules['squeeze_win'] ?> દિવસનો ગાળો ·
    "નવું" = <?= $rules['new_days'] ?> દિવસમાં પહેલી વાર વેચાયું ·
    "બંધ" = <?= $rules['fade_days'] ?> દિવસથી એકેય બિલમાં નહીં ·
    સીઝન માટે ઓછામાં ઓછાં 2 વર્ષ.
  </p>
</div>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
