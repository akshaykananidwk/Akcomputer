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
            flash('Both an amount and a date are needed.', 'error'); redirect($back);
        }
        coll_log($pid, 'promise', ['amount' => $amt, 'due_date' => $on, 'note' => trim(post('note'))]);
        log_activity('collection_promise', "party=$pid amount=$amt on=$on");
        flash('Promise recorded — ' . dmy($on) . ' no reminder goes to this customer until then.');
        redirect($back);
    }
    if (post('do') === 'log_event') {
        require_perm('payments.view');
        $type = in_array(post('type'), ['call', 'note', 'snooze'], true) ? post('type') : 'note';
        $opt = ['note' => trim(post('note')), 'channel' => $type === 'call' ? 'phone' : ''];
        if ($type === 'snooze') {
            $on = post('due_date');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $on)) { flash('Enter a date.', 'error'); redirect($back); }
            $opt['due_date'] = $on;
        }
        coll_log($pid, $type, $opt);
        flash($type === 'snooze' ? 'this customer ' . dmy($opt['due_date']) . ' no reminder goes until then.' : 'Note saved.');
        redirect($back);
    }
    if (post('do') === 'opt_out') {
        require_perm('parties.edit');
        $v = post('value') === '1' ? 1 : 0;
        q('UPDATE parties SET collection_opt_out = ? WHERE id = ?', [$v, $pid]);
        log_activity('collection_opt_out', "party=$pid value=$v");
        consent_record($pid, 'collection', !$v, 'staff (customer page)');
        flash($v ? 'No collection messages will go to this customer now.' : 'Messages can go to this customer now.');
        redirect($back);
    }
    // Marketing consent is its own switch, on purpose. Someone who does not
    // want offers is still entitled to be told a bill of theirs is due, and
    // someone who has asked to stop the payment chasing has not thereby agreed
    // to be advertised to. One flag for both would break one of those promises.
    if (post('do') === 'voice_dnd') {
        require_perm('parties.edit');
        $v = post('value') === '1' ? 1 : 0;
        q('UPDATE parties SET voice_dnd = ? WHERE id = ?', [$v, $pid]);
        log_activity('voice_dnd', "party=$pid value=$v");
        flash($v ? 'No reminder call will go to this customer now.' : 'Reminder calls can go to this customer now.');
        redirect($back);
    }
    if (post('do') === 'mkt_opt_out') {
        require_perm('parties.edit');
        $v = post('value') === '1' ? 1 : 0;
        q('UPDATE parties SET marketing_opt_out = ? WHERE id = ?', [$v, $pid]);
        log_activity('marketing_opt_out', "party=$pid value=$v");
        consent_record($pid, 'marketing', !$v, 'staff (customer page)');
        flash($v ? 'No advertising or offer messages will go to this customer now.' : 'Offer messages can go to this customer now.');
        redirect($back);
    }
}

$c = cust_360($id);
if (!$c) { flash('Customer not found.', 'error'); redirect('parties.php'); }
$p = $c['party'];
$fav = cust_favourites($id);
$svc = cust_service($id);
$eng = cust_engagement($id);
$ops = cust_opportunities($id);
$events = $seeMoney ? cust_events($id) : [];
$draft = get('do') === 'draft_reactivation' ? cust_reactivation_draft($id) : '';

$segLabels = [
    'vip' => ['⭐ VIP', 's-ok'], 'high_value' => ['💎 Big customers', 's-ok'],
    'regular' => ['🔁 Regular', ''], 'new' => ['🆕 New', ''],
    'at_risk' => ['⚠️ Slipping away', 's-warn'], 'inactive' => ['😴 Gone quiet', 's-warn'],
    'overdue' => ['⏰ Overdue', 's-bad'], 'credit_risk' => ['🚩 Credit risk', 's-bad'],
];
$relLabels = ['excellent' => ['pays on time', 'badge-ok'], 'good' => ['Good', 'badge-ok'],
              'slow' => ['pays slowly', 'badge-warn'], 'poor' => ['Poor', 'badge-bad'],
              'new' => ['not decided yet', 'badge-info']];

$page_title = 'Customer 360 — ' . $p['name'];
include __DIR__ . '/includes/header.php';
?>
<div class="card">
  <div class="page-actions no-print" style="margin:0 0 8px;justify-content:space-between">
    <h2 style="margin:0"><?= e($p['name']) ?></h2>
    <span>
      <a class="btn btn-sm btn-outline" href="parties.php?action=ledger&id=<?= $id ?>">📒 Ledger</a>
      <?php if (can('sales.add')): ?><a class="btn btn-sm" href="sales.php?action=new&party_id=<?= $id ?>">🧾 New bill</a><?php endif; ?>
      <?php if (can('payments.add')): ?><a class="btn btn-sm btn-success" href="payments.php?action=new&dir=in&party_id=<?= $id ?>">💵 Payment</a><?php endif; ?>
    </span>
  </div>
  <p class="muted" style="margin:0 0 10px">
    <?php if ($p['mobile']): ?>📞 <?= mobile_link($p['mobile']) ?> · <?php endif; ?>
    <?= e(trim(($p['city'] ?? '') . ' ' . ($p['address'] ?? ''))) ?: 'No address' ?>
    · customer <?= $c['since'] ? dmy($c['since']) . ' from' : '—' ?>
    · <?= e($p['type']) ?>
    <?php if ($c['opt_out']): ?> · <span class="badge badge-warn">Messages off</span><?php endif; ?>
  </p>
  <div class="range-bar">
    <?php foreach ($c['segments'] as $k => $why):
      list($lbl, $tone) = $segLabels[$k] ?? [$k, '']; ?>
    <span class="rchip" title="<?= e($why) ?>"><?= $lbl ?></span>
    <?php endforeach; ?>
    <?php if (!$c['segments']): ?><span class="muted">No particular grouping yet</span><?php endif; ?>
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
  <h2>📣 A win-back message</h2>
  <p class="muted mb">This message was built from their own purchases. Read it, edit it if you like, then send.</p>
  <textarea rows="8" id="reactMsg" style="width:100%"><?= e($draft) ?></textarea>
  <p class="mt">
    <?php if ($p['mobile']): ?>
    <a class="btn btn-wa" id="reactSend" target="_blank"
       href="https://wa.me/<?= e(preg_replace('/\D/', '', $p['mobile'])) ?>?text=<?= rawurlencode($draft) ?>">📲 Open WhatsApp</a>
    <?php else: ?><span class="muted">No mobile number.</span><?php endif; ?>
    <a class="btn btn-outline" href="customer.php?id=<?= $id ?>">Turn off</a>
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
  <h2>💰 Account</h2>
  <div class="kpi-grid">
    <?php
      dash_card('Total purchases', money($c['lifetime_value']), 'reports.php?r=party_sales');
      dash_card('in the last year', money($c['year_value']), 'reports.php?r=party_sales');
      dash_card('monthly average', money($c['monthly_value']), 'reports.php?r=party_sales');
      dash_card('Average bill', money($c['avg_bill']), 'parties.php?action=ledger&id=' . $id);
      dash_card('Biggest bill', money($c['biggest_bill']), 'parties.php?action=ledger&id=' . $id);
      dash_card('Bill', (string)$c['bills'], 'parties.php?action=ledger&id=' . $id, null, null, '', '');
      if ($seeMoney) {
        dash_card('Due', money($c['outstanding']), 'parties.php?action=ledger&id=' . $id, null, null, $c['outstanding'] > 0.009 ? 's-bad' : 's-ok');
        dash_card('Overdue', money($c['overdue']), 'reports.php?r=aging', null, null, $c['overdue'] > 0.009 ? 's-bad' : '');
      }
      if ($seeProfit && $c['margin']) {
        dash_card('Margin contributed', money($c['margin']['contribution']), 'reports.php?r=profit', null, null, 's-ok');
        dash_card('Discount given', money($c['discount_given']), 'parties.php?action=ledger&id=' . $id, null, null, 's-warn');
      }
    ?>
  </div>
  <?php if ($seeProfit && $c['margin']): ?>
  <p class="muted" style="font-size:12px;margin:8px 0 0">
    "Margin contributed" = sales − cost of goods − discount given. Rent, salaries and other costs are not in it,
    so do not read it as net profit.
  </p>
  <?php endif; ?>
  <p class="muted" style="font-size:13px;margin:8px 0 0">
    Last purchase: <strong><?= $c['last_sale'] ? dmy($c['last_sale']) : '—' ?></strong>
    <?php if ($c['frequency']['gap_days']): ?>
      · usually every <strong><?= (int)$c['frequency']['gap_days'] ?></strong> days they buy
      (about per year <?= $c['frequency']['per_year'] ?> times)
    <?php endif; ?>
    <?php if ($seeMoney && $c['last_payment']): ?>
      · last payment <strong><?= dmy($c['last_payment']['pay_date']) ?></strong> (₹<?= money($c['last_payment']['amount']) ?>)
    <?php elseif ($seeMoney): ?> · <span class="muted">No payment has ever come in</span><?php endif; ?>
  </p>
</div>

<?php // ---------- credit ---------- ?>
<?php if ($seeMoney): $cr = $c['credit']; list($rl, $rc) = $relLabels[$cr['reliability']['rating']] ?? ['—', 'badge-info']; ?>
<div class="card">
  <h2>🏦 Credit position</h2>
  <div class="grid-stats">
    <div class="stat <?= $cr['over_limit'] ? 's-bad' : '' ?>"><div class="stat-label">outstanding now</div><div class="stat-value">₹<?= money($cr['outstanding']) ?></div></div>
    <div class="stat"><div class="stat-label">Suggested credit limit</div><div class="stat-value">₹<?= money($cr['suggested']) ?></div></div>
    <div class="stat"><div class="stat-label">Payment habit</div><div class="stat-value" style="font-size:15px"><span class="badge <?= $rc ?>"><?= $rl ?></span></div></div>
    <div class="stat"><div class="stat-label">Average delay</div><div class="stat-value"><?= (int)($cr['reliability']['avg_delay'] ?? 0) ?> d</div></div>
  </div>
  <?php if ($cr['over_limit']): ?>
  <p class="alertrow al-bad" style="border:0">⚠️ This customer owes Rs <?= money($cr['outstanding']) ?> , which against the suggested limit of Rs <?= money($cr['suggested']) ?> is above it. Think before giving more credit.</p>
  <?php endif; ?>
  <p class="muted" style="font-size:12px;margin:6px 0 0">
    The limit comes from their purchases last year (about Rs <?= money($cr['monthly_buy']) ?>) and from their payment habits <strong>Suggested</strong> .
    The system blocks nothing — the decision is yours.
  </p>
</div>
<?php endif; ?>

<?php // ---------- collection ---------- ?>
<?php if ($seeMoney && $c['outstanding'] > 0.009): $due = cust_outstanding($id, $c['balance']); ?>
<div class="card">
  <h2>📮 Collections</h2>
  <div class="table-wrap"><table class="table-sm">
    <thead><tr><th>Bill</th><th>Date</th><th>Term</th><th class="num">Due Rs </th></tr></thead>
    <tbody>
    <?php foreach ($due['lines'] as $l): ?>
    <tr>
      <td><a href="sale_view.php?id=<?= (int)$l['id'] ?>"><?= e($l['invoice_no']) ?></a></td>
      <td><?= dmy($l['date']) ?></td>
      <td><?= $l['due_date'] ? dmy($l['due_date']) : '—' ?> <?= $l['overdue'] ? '<span class="badge badge-bad">overdue</span>' : '' ?></td>
      <td class="num">₹<?= money($l['due']) ?></td>
    </tr>
    <?php endforeach; ?>
    <tr><td colspan="3"><strong>Total</strong></td><td class="num"><strong>₹<?= money($due['total']) ?></strong></td></tr>
    </tbody>
  </table></div>
  <p class="muted" style="font-size:12px">These figures are per the ledger — payments not linked to a bill are deducted too.</p>

  <div class="grid-2 mt">
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="do" value="promise"><input type="hidden" name="id" value="<?= $id ?>">
      <h3>🤝 Record a payment promise</h3>
      <div class="form-row cols-2">
        <div><label>Amount (Rs)</label><input type="number" step="any" name="amount" value="<?= 0 + $due['total'] ?>" required></div>
        <div><label>When will they pay</label><input type="date" name="due_date" value="<?= e(date('Y-m-d', strtotime('+3 days'))) ?>" required></div>
      </div>
      <div class="field"><label>Note</label><input type="text" name="note" placeholder="e.g. will come to the shop on Friday and pay"></div>
      <button class="btn btn-sm" type="submit">Save the promise</button>
      <span class="muted" style="font-size:12px">Reminders stay off until the promised date.</span>
    </form>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="do" value="log_event"><input type="hidden" name="id" value="<?= $id ?>">
      <h3>📝 Contact note</h3>
      <div class="form-row cols-2">
        <div><label>What was done</label>
          <select name="type"><option value="call">Called</option><option value="note">Note</option><option value="snooze">Leave it a few days</option></select></div>
        <div><label>Until when (for the snooze)</label><input type="date" name="due_date" value="<?= e(date('Y-m-d', strtotime('+7 days'))) ?>"></div>
      </div>
      <div class="field"><label>Note</label><input type="text" name="note" placeholder="e.g. did not answer the phone"></div>
      <button class="btn btn-sm btn-outline" type="submit">Save</button>
    </form>
  </div>
</div>
<?php endif; ?>

<?php // ---------- follow-up history ---------- ?>
<?php if ($seeMoney && $events): ?>
<div class="card">
  <h2>🕘 Collection history</h2>
  <p class="muted mb" style="font-size:12px">This list exists so the same customer does not get the same message over and over.</p>
  <div class="table-wrap"><table class="table-sm">
    <thead><tr><th>When</th><th>What</th><th class="num">Amount</th><th>Note</th><th>Who</th></tr></thead>
    <tbody>
    <?php
      $evLabels = ['reminder' => '📲 Message sent', 'call' => '📞 Called', 'note' => '📝 Note',
                   'promise' => '🤝 Promise', 'kept' => '✅ Promise kept', 'broken' => '❌ Promise broken',
                   'snooze' => '⏸️ On hold'];
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
  <h2>💡 What could be offered next</h2>
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
  <h2>🛒 What they buy</h2>
  <div class="grid-2">
    <?php foreach ([['Favourite category', $fav['categories']], ['Favourite brand', $fav['brands']]] as list($ft, $frows)):
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
  <h3>Most bought items</h3>
  <div class="table-wrap"><table class="table-sm">
    <thead><tr><th>Item</th><th class="num">Qty</th><th class="num">Amount Rs </th><th>Last</th></tr></thead>
    <tbody>
    <?php foreach ($fav['products'] as $f): ?>
    <tr><td><a href="item_view.php?id=<?= (int)$f['id'] ?>"><?= e($f['name']) ?></a></td>
        <td class="num"><?= (float)$f['qty'] ?></td><td class="num">₹<?= money($f['amount']) ?></td>
        <td><?= dmy($f['last_buy']) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
  <?php if (!$fav['products']): ?><p class="muted">Nothing has been bought yet.</p><?php endif; ?>
</div>

<?php // ---------- service + engagement ---------- ?>
<div class="card">
  <h2>🛠️ Service and contact</h2>
  <div class="grid-stats">
    <?php if (can('repairs.view')): ?>
    <a class="stat <?= $svc['repairs_open'] ? 's-warn' : '' ?>" href="repairs.php"><div class="stat-label">Repair</div>
      <div class="stat-value"><?= $svc['repairs'] ?></div>
      <div class="muted" style="font-size:11px"><?= $svc['repairs_open'] ? $svc['repairs_open'] . ' On' : 'All complete' ?><?= $svc['last_repair'] ? '<br>Last ' . dmy($svc['last_repair']) : '' ?></div></a>
    <?php endif; ?>
    <?php if (can('warranty.view')): ?>
    <a class="stat <?= $svc['warranty_open'] ? 's-warn' : '' ?>" href="warranty.php"><div class="stat-label">Warranty claim</div>
      <div class="stat-value"><?= $svc['warranty'] ?></div>
      <div class="muted" style="font-size:11px"><?= $svc['warranty_open'] ? $svc['warranty_open'] . ' On' : '—' ?></div></a>
    <?php endif; ?>
    <?php if (can('amc.view')): ?>
    <a class="stat" href="amc.php"><div class="stat-label">AMC</div>
      <div class="stat-value"><?= $svc['amc_active'] ?></div>
      <div class="muted" style="font-size:11px"><?= $svc['amc_next'] ? 'Next ' . dmy($svc['amc_next']) : 'not running' ?></div></a>
    <?php endif; ?>
    <?php if (can('estimates.view')): ?>
    <a class="stat" href="estimates.php"><div class="stat-label">Quotation</div>
      <div class="stat-value"><?= $eng['quotes'] ?></div>
      <div class="muted" style="font-size:11px"><?= $eng['quotes_open'] ? $eng['quotes_open'] . ' Open' : '—' ?></div></a>
    <?php endif; ?>
    <?php if (can('weborders.view')): ?>
    <a class="stat" href="web_orders.php"><div class="stat-label">Web orders</div><div class="stat-value"><?= $eng['orders'] ?></div></a>
    <?php endif; ?>
    <a class="stat" href="reviews.php"><div class="stat-label">Reviews</div><div class="stat-value"><?= $eng['reviews'] ?></div></a>
    <?php if ($eng['loyalty']): ?>
    <div class="stat"><div class="stat-label">Loyalty points</div><div class="stat-value"><?= (int)$eng['loyalty'] ?></div></div>
    <?php endif; ?>
    <?php if (is_full_admin() && $eng['wa_msgs']): ?>
    <a class="stat" href="wa_inbox.php"><div class="stat-label">WhatsApp message</div>
      <div class="stat-value"><?= $eng['wa_msgs'] ?></div>
      <div class="muted" style="font-size:11px"><?= $eng['wa_last'] ? 'Last ' . dmy(substr($eng['wa_last'], 0, 10)) : '' ?></div></a>
    <?php endif; ?>
  </div>
</div>

<?php // ---------- consent ---------- ?>
<?php if (can('parties.edit')): ?>
<div class="card">
  <h3>🔕 Message permission</h3>
  <p class="muted mb" style="font-size:13px">
    <?= $c['opt_out']
        ? 'This customer has asked not to be sent collection messages. Every bulk send and cron will skip them.'
        : 'Collection messages can go to this customer. If they refuse, switch it off here.' ?>
  </p>
  <form method="post" onsubmit="return confirm('<?= $c['opt_out'] ? 'Turn messages back on?' : 'Stop sending messages to this customer?' ?>')">
    <?= csrf_field() ?><input type="hidden" name="do" value="opt_out"><input type="hidden" name="id" value="<?= $id ?>">
    <input type="hidden" name="value" value="<?= $c['opt_out'] ? '0' : '1' ?>">
    <button class="btn btn-sm <?= $c['opt_out'] ? 'btn-success' : 'btn-outline btn-danger' ?>" type="submit">
      <?= $c['opt_out'] ? '🔔 Turn messages back on' : '🔕 Turn messages off' ?>
    </button>
  </form>
  <hr>
  <?php $mkt = (int)($p['marketing_opt_out'] ?? 0); ?>
  <p class="muted mb" style="font-size:13px">
    <b>Advertising / offer messages</b> — this is a separate permission.
    <?= $mkt
        ? 'This customer has turned off offer messages, so no campaign reaches them. Necessary bill and payment notices continue.'
        : 'Campaign messages can go to this customer. If the customer replies "STOP" on WhatsApp, this turns itself off too.' ?>
  </p>
  <form method="post" onsubmit="return confirm('<?= $mkt ? 'Turn offer messages back on?' : 'Turn off offer messages for this customer?' ?>')">
    <?= csrf_field() ?><input type="hidden" name="do" value="mkt_opt_out"><input type="hidden" name="id" value="<?= $id ?>">
    <input type="hidden" name="value" value="<?= $mkt ? '0' : '1' ?>">
    <button class="btn btn-sm <?= $mkt ? 'btn-success' : 'btn-outline btn-danger' ?>" type="submit">
      <?= $mkt ? '📣 Turn offer messages back on' : '🚫 Turn off offer messages' ?>
    </button>
  </form>
  <?php try { $clog = all('SELECT c.*, us.name by_name FROM consent_log c LEFT JOIN users us ON us.id = c.by_user WHERE c.party_id = ? ORDER BY c.id DESC LIMIT 10', [$id]); } catch (Exception $e) { $clog = []; }
  if ($clog): ?>
  <details class="mt"><summary style="cursor:pointer;font-size:13px">📜 Who said yes / no, and when</summary>
    <?php foreach ($clog as $cl): ?><div style="font-size:12.5px"><?= dmyt($cl['created_at']) ?> — <?= e($cl['kind']) ?>: <b><?= $cl['given'] ? 'yes' : 'no' ?></b> · <?= e($cl['source']) ?><?= $cl['by_name'] ? ' · ' . e($cl['by_name']) : '' ?></div><?php endforeach; ?>
  </details>
  <?php endif; ?>
  <hr>
  <?php $dnd = (int)($p['voice_dnd'] ?? 0); ?>
  <p class="muted mb" style="font-size:13px">
    <b>Reminder calls</b> — a third, separate permission, for the same reason the two above are separate.
    A customer may be perfectly happy to get a WhatsApp reminder and still not want their phone to ring;
    folding the two together would force them to choose between silence and everything.
    <?= $dnd
        ? 'This customer has asked not to be called. The Remind Now button is switched off for them.'
        : 'Reminder calls may go to this customer.' ?>
  </p>
  <form method="post" onsubmit="return confirm('<?= $dnd ? 'Allow reminder calls again?' : 'Stop calling this customer?' ?>')">
    <?= csrf_field() ?><input type="hidden" name="do" value="voice_dnd"><input type="hidden" name="id" value="<?= $id ?>">
    <input type="hidden" name="value" value="<?= $dnd ? '0' : '1' ?>">
    <button class="btn btn-sm <?= $dnd ? 'btn-success' : 'btn-outline btn-danger' ?>" type="submit">
      <?= $dnd ? '📞 Allow reminder calls' : '📵 Do not call' ?>
    </button>
  </form>
</div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
