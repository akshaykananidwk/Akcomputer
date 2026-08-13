<?php
// Customer 360 - everything worth knowing about one customer on one screen.
//
// This does NOT replace the party ledger (parties.php?action=ledger), which
// already lists every transaction and every service job. This page adds what
// the ledger cannot tell you: what the customer is worth, how they behave,
// whether they are drifting away, what they should be offered next, and where
// they stand in the collection queue. Transaction detail is one click away.
require_once __DIR__ . '/includes/init.php';
require_perm('parties.view');
$u = current_user();
$id = (int)get('id');

$seeMoney  = can('payments.view');
$seeProfit = can('reports.profit');

// ---------- actions ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pid = (int)post('id');
    $back = 'customer.php?id=' . $pid;
    if (post('do') === 'promise') {
        require_perm('payments.view');
        $amt = round((float)post('amount'), 2);
        $on = post('due_date');
        if ($amt <= 0.009 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $on)) {
            flash('રકમ અને તારીખ બંને જોઈએ.', 'error'); redirect($back);
        }
        coll_log($pid, 'promise', ['amount' => $amt, 'due_date' => $on, 'note' => trim(post('note'))]);
        log_activity('collection_promise', "party=$pid amount=$amt on=$on");
        flash('વાયદો નોંધાયો — ' . dmy($on) . ' સુધી આ ગ્રાહકને રિમાઇન્ડર નહીં જાય.');
        redirect($back);
    }
    if (post('do') === 'log_event') {
        require_perm('payments.view');
        $type = in_array(post('type'), ['call', 'note', 'snooze'], true) ? post('type') : 'note';
        $opt = ['note' => trim(post('note')), 'channel' => $type === 'call' ? 'phone' : ''];
        if ($type === 'snooze') {
            $on = post('due_date');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $on)) { flash('તારીખ નાખો.', 'error'); redirect($back); }
            $opt['due_date'] = $on;
        }
        coll_log($pid, $type, $opt);
        flash($type === 'snooze' ? 'આ ગ્રાહકને ' . dmy($opt['due_date']) . ' સુધી રિમાઇન્ડર નહીં જાય.' : 'નોંધ સચવાઈ.');
        redirect($back);
    }
    if (post('do') === 'opt_out') {
        require_perm('parties.edit');
        $v = post('value') === '1' ? 1 : 0;
        q('UPDATE parties SET collection_opt_out = ? WHERE id = ?', [$v, $pid]);
        log_activity('collection_opt_out', "party=$pid value=$v");
        flash($v ? 'હવે આ ગ્રાહકને ઉઘરાણીના મેસેજ નહીં જાય.' : 'હવે આ ગ્રાહકને મેસેજ જઈ શકશે.');
        redirect($back);
    }
}

$c = cust_360($id);
if (!$c) { flash('ગ્રાહક મળ્યો નહીં.', 'error'); redirect('parties.php'); }
$p = $c['party'];
$fav = cust_favourites($id);
$svc = cust_service($id);
$eng = cust_engagement($id);
$ops = cust_opportunities($id);
$events = $seeMoney ? cust_events($id) : [];
$draft = get('do') === 'draft_reactivation' ? cust_reactivation_draft($id) : '';

$segLabels = [
    'vip' => ['⭐ VIP', 's-ok'], 'high_value' => ['💎 મોટા ગ્રાહક', 's-ok'],
    'regular' => ['🔁 નિયમિત', ''], 'new' => ['🆕 નવા', ''],
    'at_risk' => ['⚠️ છૂટી રહ્યા છે', 's-warn'], 'inactive' => ['😴 બંધ થઈ ગયા', 's-warn'],
    'overdue' => ['⏰ મુદત વીતી', 's-bad'], 'credit_risk' => ['🚩 ઉધાર જોખમ', 's-bad'],
];
$relLabels = ['excellent' => ['સમયસર ચૂકવે છે', 'badge-ok'], 'good' => ['સારું', 'badge-ok'],
              'slow' => ['ધીમું ચૂકવે છે', 'badge-warn'], 'poor' => ['ખરાબ', 'badge-bad'],
              'new' => ['હજી નક્કી નથી', 'badge-info']];

$page_title = 'Customer 360 — ' . $p['name'];
include __DIR__ . '/includes/header.php';
?>
<div class="card">
  <div class="page-actions no-print" style="margin:0 0 8px;justify-content:space-between">
    <h2 style="margin:0"><?= e($p['name']) ?></h2>
    <span>
      <a class="btn btn-sm btn-outline" href="parties.php?action=ledger&id=<?= $id ?>">📒 ખાતાવહી</a>
      <?php if (can('sales.add')): ?><a class="btn btn-sm" href="sales.php?action=new&party_id=<?= $id ?>">🧾 નવું બિલ</a><?php endif; ?>
      <?php if (can('payments.add')): ?><a class="btn btn-sm btn-success" href="payments.php?action=new&dir=in&party_id=<?= $id ?>">💵 પેમેન્ટ</a><?php endif; ?>
    </span>
  </div>
  <p class="muted" style="margin:0 0 10px">
    <?php if ($p['mobile']): ?><a href="tel:<?= e($p['mobile']) ?>">📞 <?= e($p['mobile']) ?></a> · <?php endif; ?>
    <?= e(trim(($p['city'] ?? '') . ' ' . ($p['address'] ?? ''))) ?: 'સરનામું નથી' ?>
    · ગ્રાહક <?= $c['since'] ? dmy($c['since']) . ' થી' : '—' ?>
    · <?= e($p['type']) ?>
    <?php if ($c['opt_out']): ?> · <span class="badge badge-warn">મેસેજ બંધ</span><?php endif; ?>
  </p>
  <div class="range-bar">
    <?php foreach ($c['segments'] as $k => $why):
      list($lbl, $tone) = $segLabels[$k] ?? [$k, '']; ?>
    <span class="rchip" title="<?= e($why) ?>"><?= $lbl ?></span>
    <?php endforeach; ?>
    <?php if (!$c['segments']): ?><span class="muted">હજી કોઈ ખાસ વર્ગીકરણ નથી</span><?php endif; ?>
  </div>
  <?php if ($c['segments']): ?>
  <p class="muted" style="font-size:12px;margin:8px 0 0">
    <?php foreach ($c['segments'] as $k => $why): ?>
      <strong><?= e(strip_tags($segLabels[$k][0] ?? $k)) ?>:</strong> <?= e($why) ?><br>
    <?php endforeach; ?>
  </p>
  <?php endif; ?>
</div>

<?php if ($draft): ?>
<div class="card">
  <h2>📣 ફરી સંપર્કનો મેસેજ</h2>
  <p class="muted mb">આ મેસેજ એમની પોતાની ખરીદી પરથી બન્યો છે. વાંચીને, જોઈએ તો સુધારીને, પછી મોકલો.</p>
  <textarea rows="8" id="reactMsg" style="width:100%"><?= e($draft) ?></textarea>
  <p class="mt">
    <?php if ($p['mobile']): ?>
    <a class="btn btn-wa" id="reactSend" target="_blank"
       href="https://wa.me/<?= e(preg_replace('/\D/', '', $p['mobile'])) ?>?text=<?= rawurlencode($draft) ?>">📲 WhatsApp ખોલો</a>
    <?php else: ?><span class="muted">મોબાઇલ નંબર નથી.</span><?php endif; ?>
    <a class="btn btn-outline" href="customer.php?id=<?= $id ?>">બંધ કરો</a>
  </p>
  <script>
  (function(){ var t=document.getElementById('reactMsg'), a=document.getElementById('reactSend');
    if(!t||!a) return; var base=a.href.split('?text=')[0];
    t.addEventListener('input',function(){ a.href = base + '?text=' + encodeURIComponent(t.value); }); })();
  </script>
</div>
<?php endif; ?>

<?php // ---------- money ---------- ?>
<div class="card">
  <h2>💰 હિસાબ</h2>
  <div class="kpi-grid">
    <?php
      dash_card('કુલ ખરીદી', money($c['lifetime_value']), 'reports.php?r=party_sales');
      dash_card('છેલ્લા વર્ષમાં', money($c['year_value']), 'reports.php?r=party_sales');
      dash_card('મહિને સરેરાશ', money($c['monthly_value']), 'reports.php?r=party_sales');
      dash_card('સરેરાશ બિલ', money($c['avg_bill']), 'parties.php?action=ledger&id=' . $id);
      dash_card('સૌથી મોટું બિલ', money($c['biggest_bill']), 'parties.php?action=ledger&id=' . $id);
      dash_card('બિલ', (string)$c['bills'], 'parties.php?action=ledger&id=' . $id, null, null, '', '');
      if ($seeMoney) {
        dash_card('બાકી', money($c['outstanding']), 'parties.php?action=ledger&id=' . $id, null, null, $c['outstanding'] > 0.009 ? 's-bad' : 's-ok');
        dash_card('મુદત વીતી', money($c['overdue']), 'reports.php?r=aging', null, null, $c['overdue'] > 0.009 ? 's-bad' : '');
      }
      if ($seeProfit && $c['margin']) {
        dash_card('માર્જિન યોગદાન', money($c['margin']['contribution']), 'reports.php?r=profit', null, null, 's-ok');
        dash_card('ડિસ્કાઉન્ટ આપ્યું', money($c['discount_given']), 'parties.php?action=ledger&id=' . $id, null, null, 's-warn');
      }
    ?>
  </div>
  <?php if ($seeProfit && $c['margin']): ?>
  <p class="muted" style="font-size:12px;margin:8px 0 0">
    "માર્જિન યોગદાન" = વેચાણ − માલની પડતર − આપેલું ડિસ્કાઉન્ટ. એમાં ભાડું, પગાર કે બીજા ખર્ચ ગણ્યા નથી,
    એટલે એને ચોખ્ખો નફો ન સમજવો.
  </p>
  <?php endif; ?>
  <p class="muted" style="font-size:13px;margin:8px 0 0">
    છેલ્લી ખરીદી: <strong><?= $c['last_sale'] ? dmy($c['last_sale']) : '—' ?></strong>
    <?php if ($c['frequency']['gap_days']): ?>
      · સામાન્ય રીતે દર <strong><?= (int)$c['frequency']['gap_days'] ?></strong> દિવસે ખરીદે છે
      (વર્ષે ~<?= $c['frequency']['per_year'] ?> વાર)
    <?php endif; ?>
    <?php if ($seeMoney && $c['last_payment']): ?>
      · છેલ્લું પેમેન્ટ <strong><?= dmy($c['last_payment']['pay_date']) ?></strong> (₹<?= money($c['last_payment']['amount']) ?>)
    <?php elseif ($seeMoney): ?> · <span class="muted">ક્યારેય પેમેન્ટ નથી આવ્યું</span><?php endif; ?>
  </p>
</div>

<?php // ---------- credit ---------- ?>
<?php if ($seeMoney): $cr = $c['credit']; list($rl, $rc) = $relLabels[$cr['reliability']['rating']] ?? ['—', 'badge-info']; ?>
<div class="card">
  <h2>🏦 ઉધારની સ્થિતિ</h2>
  <div class="grid-stats">
    <div class="stat <?= $cr['over_limit'] ? 's-bad' : '' ?>"><div class="stat-label">અત્યારે બાકી</div><div class="stat-value">₹<?= money($cr['outstanding']) ?></div></div>
    <div class="stat"><div class="stat-label">સૂચવેલી ઉધાર મર્યાદા</div><div class="stat-value">₹<?= money($cr['suggested']) ?></div></div>
    <div class="stat"><div class="stat-label">ચૂકવણીની ટેવ</div><div class="stat-value" style="font-size:15px"><span class="badge <?= $rc ?>"><?= $rl ?></span></div></div>
    <div class="stat"><div class="stat-label">સરેરાશ મોડું</div><div class="stat-value"><?= (int)($cr['reliability']['avg_delay'] ?? 0) ?> દિ.</div></div>
  </div>
  <?php if ($cr['over_limit']): ?>
  <p class="alertrow al-bad" style="border:0">⚠️ આ ગ્રાહકનું બાકી ₹<?= money($cr['outstanding']) ?> છે, જે સૂચવેલી મર્યાદા ₹<?= money($cr['suggested']) ?> કરતાં વધારે છે. નવું ઉધાર આપતાં પહેલાં વિચારજો.</p>
  <?php endif; ?>
  <p class="muted" style="font-size:12px;margin:6px 0 0">
    મર્યાદા એમની છેલ્લા વર્ષની ખરીદી (મહિને ~₹<?= money($cr['monthly_buy']) ?>) અને ચૂકવણીની ટેવ પરથી <strong>સૂચવેલી</strong> છે.
    સિસ્ટમ કંઈ રોકતી નથી — નિર્ણય તમારો.
  </p>
</div>
<?php endif; ?>

<?php // ---------- collection ---------- ?>
<?php if ($seeMoney && $c['outstanding'] > 0.009): $due = cust_outstanding($id, $c['balance']); ?>
<div class="card">
  <h2>📮 ઉઘરાણી</h2>
  <div class="table-wrap"><table class="table-sm">
    <thead><tr><th>બિલ</th><th>તારીખ</th><th>મુદત</th><th class="num">બાકી ₹</th></tr></thead>
    <tbody>
    <?php foreach ($due['lines'] as $l): ?>
    <tr>
      <td><a href="sale_view.php?id=<?= (int)$l['id'] ?>"><?= e($l['invoice_no']) ?></a></td>
      <td><?= dmy($l['date']) ?></td>
      <td><?= $l['due_date'] ? dmy($l['due_date']) : '—' ?> <?= $l['overdue'] ? '<span class="badge badge-bad">વીતી ગઈ</span>' : '' ?></td>
      <td class="num">₹<?= money($l['due']) ?></td>
    </tr>
    <?php endforeach; ?>
    <tr><td colspan="3"><strong>કુલ</strong></td><td class="num"><strong>₹<?= money($due['total']) ?></strong></td></tr>
    </tbody>
  </table></div>
  <p class="muted" style="font-size:12px">આ આંકડા ખાતાવહી પ્રમાણે છે — જે પેમેન્ટ બિલ સાથે લિંક નથી થયું એ પણ બાદ થઈ ગયું છે.</p>

  <div class="grid-2 mt">
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="do" value="promise"><input type="hidden" name="id" value="<?= $id ?>">
      <h3>🤝 ચૂકવવાનો વાયદો નોંધો</h3>
      <div class="form-row cols-2">
        <div><label>રકમ (₹)</label><input type="number" step="any" name="amount" value="<?= 0 + $due['total'] ?>" required></div>
        <div><label>ક્યારે આપશે</label><input type="date" name="due_date" value="<?= e(date('Y-m-d', strtotime('+3 days'))) ?>" required></div>
      </div>
      <div class="field"><label>નોંધ</label><input type="text" name="note" placeholder="દા.ત. શુક્રવારે દુકાને આવીને આપશે"></div>
      <button class="btn btn-sm" type="submit">વાયદો સાચવો</button>
      <span class="muted" style="font-size:12px">વાયદાની તારીખ સુધી રિમાઇન્ડર બંધ રહેશે.</span>
    </form>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="do" value="log_event"><input type="hidden" name="id" value="<?= $id ?>">
      <h3>📝 સંપર્કની નોંધ</h3>
      <div class="form-row cols-2">
        <div><label>શું કર્યું</label>
          <select name="type"><option value="call">ફોન કર્યો</option><option value="note">નોંધ</option><option value="snooze">થોડા દિવસ રહેવા દો</option></select></div>
        <div><label>ક્યાં સુધી (snooze માટે)</label><input type="date" name="due_date" value="<?= e(date('Y-m-d', strtotime('+7 days'))) ?>"></div>
      </div>
      <div class="field"><label>નોંધ</label><input type="text" name="note" placeholder="દા.ત. ફોન ઉપાડ્યો નહીં"></div>
      <button class="btn btn-sm btn-outline" type="submit">સાચવો</button>
    </form>
  </div>
</div>
<?php endif; ?>

<?php // ---------- follow-up history ---------- ?>
<?php if ($seeMoney && $events): ?>
<div class="card">
  <h2>🕘 ઉઘરાણીનો ઇતિહાસ</h2>
  <p class="muted mb" style="font-size:12px">આ યાદી એટલા માટે છે કે એક જ ગ્રાહકને વારંવાર એકના એક મેસેજ ન જાય.</p>
  <div class="table-wrap"><table class="table-sm">
    <thead><tr><th>ક્યારે</th><th>શું</th><th class="num">રકમ</th><th>નોંધ</th><th>કોણે</th></tr></thead>
    <tbody>
    <?php
      $evLabels = ['reminder' => '📲 મેસેજ ગયો', 'call' => '📞 ફોન કર્યો', 'note' => '📝 નોંધ',
                   'promise' => '🤝 વાયદો', 'kept' => '✅ વાયદો પળાયો', 'broken' => '❌ વાયદો તૂટ્યો',
                   'snooze' => '⏸️ રોકી રાખ્યું'];
      foreach ($events as $ev): ?>
    <tr>
      <td><?= dmyt($ev['created_at']) ?></td>
      <td><?= $evLabels[$ev['event_type']] ?? e($ev['event_type']) ?>
        <?= $ev['due_date'] ? '<br><span class="muted" style="font-size:11px">' . dmy($ev['due_date']) . '</span>' : '' ?></td>
      <td class="num"><?= $ev['amount'] > 0.009 ? '₹' . money($ev['amount']) : '—' ?></td>
      <td><?= e($ev['note']) ?></td>
      <td class="muted"><?= e($ev['staff'] ?? '—') ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>

<?php // ---------- opportunities ---------- ?>
<?php if ($ops): ?>
<div class="card">
  <h2>💡 આગળ શું આપી શકાય</h2>
  <?php foreach ($ops as $o): ?>
  <div class="act act-info">
    <span class="act-ico"><?= $o['icon'] ?></span>
    <span class="act-body"><strong><?= $o['title'] ?></strong><br><span class="muted" style="font-size:12px"><?= $o['why'] ?></span></span>
    <a class="btn btn-sm btn-outline" href="<?= e($o['link']) ?>"><?= e($o['cta']) ?></a>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php // ---------- what they buy ---------- ?>
<div class="card">
  <h2>🛒 શું ખરીદે છે</h2>
  <div class="grid-2">
    <?php foreach ([['મનપસંદ કેટેગરી', $fav['categories']], ['મનપસંદ બ્રાન્ડ', $fav['brands']]] as list($ft, $frows)):
      if (!$frows) continue; ?>
    <div>
      <h3><?= $ft ?></h3>
      <table class="table-sm"><tbody>
        <?php foreach ($frows as $f): ?>
        <tr><td><?= e($f['name']) ?></td><td class="num">₹<?= money($f['amount']) ?></td></tr>
        <?php endforeach; ?>
      </tbody></table>
    </div>
    <?php endforeach; ?>
  </div>
  <?php if ($fav['products']): ?>
  <h3>સૌથી વધુ ખરીદેલી વસ્તુઓ</h3>
  <div class="table-wrap"><table class="table-sm">
    <thead><tr><th>વસ્તુ</th><th class="num">નંગ</th><th class="num">રકમ ₹</th><th>છેલ્લે</th></tr></thead>
    <tbody>
    <?php foreach ($fav['products'] as $f): ?>
    <tr><td><a href="item_view.php?id=<?= (int)$f['id'] ?>"><?= e($f['name']) ?></a></td>
        <td class="num"><?= (float)$f['qty'] ?></td><td class="num">₹<?= money($f['amount']) ?></td>
        <td><?= dmy($f['last_buy']) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
  <?php if (!$fav['products']): ?><p class="muted">હજી કોઈ વસ્તુ ખરીદી નથી.</p><?php endif; ?>
</div>

<?php // ---------- service + engagement ---------- ?>
<div class="card">
  <h2>🛠️ સેવા અને સંપર્ક</h2>
  <div class="grid-stats">
    <?php if (can('repairs.view')): ?>
    <a class="stat <?= $svc['repairs_open'] ? 's-warn' : '' ?>" href="repairs.php"><div class="stat-label">રિપેર</div>
      <div class="stat-value"><?= $svc['repairs'] ?></div>
      <div class="muted" style="font-size:11px"><?= $svc['repairs_open'] ? $svc['repairs_open'] . ' ચાલુ' : 'બધા પૂરા' ?><?= $svc['last_repair'] ? '<br>છેલ્લે ' . dmy($svc['last_repair']) : '' ?></div></a>
    <?php endif; ?>
    <?php if (can('warranty.view')): ?>
    <a class="stat <?= $svc['warranty_open'] ? 's-warn' : '' ?>" href="warranty.php"><div class="stat-label">વોરંટી ક્લેમ</div>
      <div class="stat-value"><?= $svc['warranty'] ?></div>
      <div class="muted" style="font-size:11px"><?= $svc['warranty_open'] ? $svc['warranty_open'] . ' ચાલુ' : '—' ?></div></a>
    <?php endif; ?>
    <?php if (can('amc.view')): ?>
    <a class="stat" href="amc.php"><div class="stat-label">AMC</div>
      <div class="stat-value"><?= $svc['amc_active'] ?></div>
      <div class="muted" style="font-size:11px"><?= $svc['amc_next'] ? 'આગળ ' . dmy($svc['amc_next']) : 'ચાલુ નથી' ?></div></a>
    <?php endif; ?>
    <?php if (can('estimates.view')): ?>
    <a class="stat" href="estimates.php"><div class="stat-label">ક્વોટેશન</div>
      <div class="stat-value"><?= $eng['quotes'] ?></div>
      <div class="muted" style="font-size:11px"><?= $eng['quotes_open'] ? $eng['quotes_open'] . ' ખુલ્લાં' : '—' ?></div></a>
    <?php endif; ?>
    <?php if (can('weborders.view')): ?>
    <a class="stat" href="web_orders.php"><div class="stat-label">વેબ ઓર્ડર</div><div class="stat-value"><?= $eng['orders'] ?></div></a>
    <?php endif; ?>
    <a class="stat" href="reviews.php"><div class="stat-label">રિવ્યુ</div><div class="stat-value"><?= $eng['reviews'] ?></div></a>
    <?php if ($eng['loyalty']): ?>
    <div class="stat"><div class="stat-label">લોયલ્ટી પોઇન્ટ</div><div class="stat-value"><?= (int)$eng['loyalty'] ?></div></div>
    <?php endif; ?>
    <?php if (is_full_admin() && $eng['wa_msgs']): ?>
    <a class="stat" href="wa_inbox.php"><div class="stat-label">WhatsApp મેસેજ</div>
      <div class="stat-value"><?= $eng['wa_msgs'] ?></div>
      <div class="muted" style="font-size:11px"><?= $eng['wa_last'] ? 'છેલ્લે ' . dmy(substr($eng['wa_last'], 0, 10)) : '' ?></div></a>
    <?php endif; ?>
  </div>
</div>

<?php // ---------- consent ---------- ?>
<?php if (can('parties.edit')): ?>
<div class="card">
  <h3>🔕 મેસેજની પરવાનગી</h3>
  <p class="muted mb" style="font-size:13px">
    <?= $c['opt_out']
        ? 'આ ગ્રાહકે ઉઘરાણીના મેસેજ ન મોકલવાનું કહ્યું છે. કોઈ પણ બલ્ક મેસેજ કે ક્રોન એમને છોડી દેશે.'
        : 'આ ગ્રાહકને ઉઘરાણીના મેસેજ જઈ શકે છે. જો એ ના પાડે તો અહીંથી બંધ કરી દો.' ?>
  </p>
  <form method="post" onsubmit="return confirm('<?= $c['opt_out'] ? 'ફરી મેસેજ ચાલુ કરવા છે?' : 'આ ગ્રાહકને મેસેજ મોકલવાના બંધ કરવા છે?' ?>')">
    <?= csrf_field() ?><input type="hidden" name="do" value="opt_out"><input type="hidden" name="id" value="<?= $id ?>">
    <input type="hidden" name="value" value="<?= $c['opt_out'] ? '0' : '1' ?>">
    <button class="btn btn-sm <?= $c['opt_out'] ? 'btn-success' : 'btn-outline btn-danger' ?>" type="submit">
      <?= $c['opt_out'] ? '🔔 મેસેજ ફરી ચાલુ કરો' : '🔕 મેસેજ બંધ કરો' ?>
    </button>
  </form>
</div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
