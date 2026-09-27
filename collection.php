<?php
// Today's Collection Queue - who to chase, in what order, and what to do
// about each one.
//
// The ordering is the whole point: coll_priority() weighs how much, how late,
// whether they usually pay, whether they broke a promise, and how many bills
// are stacked up, and every row shows the reasons out loud. A customer is
// never pushed up the list for a reason nobody can read.
//
// Sending is deliberately awkward in the right places. A customer who has an
// open promise, was contacted two days ago, has been snoozed, or has opted
// out simply cannot be messaged from here, and the row says why.
require_once __DIR__ . '/includes/init.php';
require_perm('payments.view');
$u = current_user();

// ---------- actions ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pid = (int)post('id');
    $back = 'collection.php' . (get('show') === 'all' ? '?show=all' : '');

    if (post('do') === 'snooze') {
        $days = max(1, (int)post('days'));
        coll_log($pid, 'snooze', ['due_date' => date('Y-m-d', strtotime("+$days days")), 'note' => trim(post('note'))]);
        flash(dmy(date('Y-m-d', strtotime("+$days days"))) . ' no reminder goes to this customer.');
        redirect($back);
    }
    if (post('do') === 'call') {
        coll_log($pid, 'call', ['channel' => 'phone', 'note' => trim(post('note')) ?: 'Called']);
        flash('Call note saved.');
        redirect($back);
    }
    if (post('do') === 'promise') {
        $amt = round((float)post('amount'), 2);
        $on = post('due_date');
        if ($amt <= 0.009 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $on)) { flash('An amount and a date are needed.', 'error'); redirect($back); }
        coll_log($pid, 'promise', ['amount' => $amt, 'due_date' => $on, 'note' => trim(post('note'))]);
        flash('Promise recorded — ' . dmy($on) . ' reminders are off until then.');
        redirect($back);
    }

    // ---- bulk reminder: preview first, exactly like the aging report ----
    if (post('do') === 'preview_bulk') {
        $picked = [];
        $skipped = [];
        $queue = coll_queue(500, true);
        $byId = [];
        foreach ($queue as $c) $byId[$c['id']] = $c;
        foreach (post('pick', []) as $rawId) {
            $c = $byId[(int)$rawId] ?? null;
            if (!$c) continue;
            if (!$c['can_remind']['ok']) { $skipped[] = $c; continue; }
            $picked[] = $c;
        }
        $rate = (float)setting('wa_cost_per_msg', 0);
        $shop = setting('app_name', 'AK Computer');
        $total = array_sum(array_column($picked, 'overdue'));
        $sample = $picked ? wa_template('aging_reminder', [
            'amount' => money($picked[0]['overdue']), 'shop' => $shop, 'customer' => $picked[0]['name'],
        ]) : '';
        $page_title = 'Before sending reminders';
        include __DIR__ . '/includes/header.php'; ?>
        <div class="card">
          <h2>📲 Have a look before sending</h2>
          <?php if ($skipped): ?>
          <div class="flash flash-info">
            <?= count($skipped) ?> customers were left out (a promise, a recent contact, a snooze, or messages off):<br>
            <?php foreach ($skipped as $s): ?>
              <span class="muted" style="font-size:12px">• <?= e($s['name']) ?> — <?= e($s['can_remind']['why']) ?></span><br>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
          <?php if (!$picked): ?>
            <p class="muted">No customer is left to send to.</p>
            <a class="btn btn-outline" href="collection.php">← Back</a>
          <?php else: ?>
          <div class="grid-stats">
            <div class="stat"><div class="stat-label">How many customers</div><div class="stat-value"><?= count($picked) ?></div></div>
            <div class="stat s-bad"><div class="stat-label">Total receivable</div><div class="stat-value">₹<?= money($total) ?></div></div>
            <div class="stat <?= $rate > 0 ? 's-warn' : '' ?>"><div class="stat-label">Estimated cost</div>
              <div class="stat-value"><?= $rate > 0 ? '₹' . money($rate * count($picked)) : '—' ?></div></div>
            <div class="stat"><div class="stat-label">Cost per message</div>
              <div class="stat-value"><?= $rate > 0 ? '₹' . money($rate) : '—' ?></div></div>
          </div>
          <?php if ($rate <= 0): ?>
          <p class="muted mb">to show the cost <a href="settings.php?cat=whatsapp">Settings → WhatsApp</a> enter a price in.</p>
          <?php endif; ?>
          <h3>The message will look like this</h3>
          <p class="muted" style="font-size:12px">Each with their own name and amount. Below <strong><?= e($picked[0]['name']) ?></strong> sample.</p>
          <pre style="white-space:pre-wrap;background:var(--bg);padding:12px;border-radius:10px;font-family:inherit;font-size:14px"><?= e($sample) ?></pre>
          <h3>will go to these customers</h3>
          <div class="table-wrap"><table class="table-sm">
            <thead><tr><th>Customer</th><th>Mobile</th><th class="num">Due Rs </th><th class="num">days</th></tr></thead>
            <tbody>
            <?php foreach ($picked as $c): ?>
            <tr><td><?= e($c['name']) ?></td><td><?= e($c['mobile']) ?></td>
                <td class="num">₹<?= money($c['overdue']) ?></td><td class="num"><?= (int)$c['days'] ?>d</td></tr>
            <?php endforeach; ?>
            </tbody>
          </table></div>
          <form method="post" class="mt" onsubmit="this.querySelector('button').disabled=true">
            <?= csrf_field() ?><input type="hidden" name="do" value="send_bulk">
            <?php foreach ($picked as $c): ?><input type="hidden" name="pick[]" value="<?= (int)$c['id'] ?>"><?php endforeach; ?>
            <button class="btn btn-wa" type="submit">✅ Yes, <?= count($picked) ?> Send to the customer</button>
            <a class="btn btn-outline" href="collection.php">Leave it</a>
          </form>
          <?php endif; ?>
        </div>
        <?php include __DIR__ . '/includes/footer.php'; exit;
    }

    if (post('do') === 'send_bulk') {
        require_once __DIR__ . '/includes/billimage.php';
        $shop = setting('app_name', 'AK Computer');
        $imgDir = __DIR__ . '/uploads/reminders';
        if (!is_dir($imgDir)) mkdir($imgDir, 0755, true);
        $queue = coll_queue(500, true);
        $byId = [];
        foreach ($queue as $c) $byId[$c['id']] = $c;
        $sent = 0; $failed = 0; $blocked = 0;
        foreach (post('pick', []) as $rawId) {
            $c = $byId[(int)$rawId] ?? null;
            // re-checked at send time, not just at preview: a promise could
            // have been recorded in between
            if (!$c || !$c['can_remind']['ok']) { $blocked++; continue; }
            $img = 'reminder_' . preg_replace('/\D/', '', $c['mobile']) . '_' . substr(md5(microtime() . $c['id']), 0, 6) . '.jpg';
            file_put_contents($imgDir . '/' . $img, reminder_image_jpg($shop, $c['overdue']));
            wa_context(['kind' => 'reminder']);
            $msg = wa_template('aging_reminder', ['amount' => money($c['overdue']), 'shop' => $shop, 'customer' => $c['name']]);
            if (send_whatsapp($c['mobile'], $msg, base_url('uploads/reminders/' . $img))) {
                $sent++;
                coll_log($c['id'], 'reminder', ['channel' => 'whatsapp', 'amount' => $c['overdue'],
                                               'note' => '₹' . money($c['overdue']) . ' reminder', 'status' => 'done']);
            } else $failed++;
        }
        log_activity('collection_bulk_reminder', "sent=$sent failed=$failed blocked=$blocked");
        if ($sent && !$failed) flash("✅ Reminders went to $sent customers." . ($blocked ? " ($blocked left out)" : ''));
        elseif ($sent) flash("Sent to $sent, $failed failed. " . whatsapp_last_error(), 'error');
        else flash('No message went out. ' . whatsapp_last_error(), 'error');
        redirect('collection.php');
    }
}

// ---------- the queue ----------
$showAll = get('show') === 'all';
$queue = coll_queue(300, $showAll);
$sum = coll_summary();
$promises = coll_promises_due();

$levelStyle = ['critical' => ['🔴', 's-bad', 'Urgent'], 'high' => ['🟠', 's-warn', 'High'],
               'medium' => ['🟡', '', 'Medium'], 'low' => ['🟢', 's-ok', 'less']];

$page_title = 'Collection Queue';
include __DIR__ . '/includes/header.php';
?>
<div class="card">
  <h2>📮 Who to chase today</h2>
  <div class="grid-stats">
    <a class="stat s-bad" href="reports.php?r=aging"><div class="stat-label">Total overdue</div><div class="stat-value">₹<?= money($sum['overdue']) ?></div></a>
    <div class="stat s-bad"><div class="stat-label">Urgent</div><div class="stat-value"><?= $sum['critical'] ?></div></div>
    <div class="stat s-warn"><div class="stat-label">High priority</div><div class="stat-value"><?= $sum['high'] ?></div></div>
    <a class="stat s-ok" href="payments.php"><div class="stat-label">Came in today</div><div class="stat-value">₹<?= money($sum['collected_today']) ?></div></a>
  </div>
  <p class="muted" style="font-size:13px;margin:0">
    <?= (int)$sum['customers'] ?> customers owe a total of Rs <?= money($sum['outstanding']) ?> is pending.
    of that <strong><?= (int)$sum['contactable'] ?></strong> can be messaged right now
    (the rest are left out because of a promise, a recent contact or a snooze).
    <?php if ($sum['promises_open']): ?> · <?= (int)$sum['promises_open'] ?> Promises open<?php endif; ?>
    <?php if ($sum['broken']): ?> · <span style="color:var(--bad)"><?= (int)$sum['broken'] ?> Promises broken (in 30 days)</span><?php endif; ?>
  </p>
</div>

<?php if ($promises['due'] || $promises['broken']): ?>
<div class="card">
  <h2>🤝 Promises</h2>
  <?php foreach ($promises['due'] as $pr): ?>
  <div class="act act-info">
    <span class="act-ico"><?= !empty($pr['kept']) ? '✅' : '📅' ?></span>
    <span class="act-body"><strong><?= e($pr['name']) ?></strong> — ₹<?= money($pr['amount']) ?> due today
      <?= !empty($pr['kept']) ? '<br><span class="muted" style="font-size:12px">The money looks to have arrived (Rs ' . money($pr['paid_since']) . ')</span>' : '' ?></span>
    <a class="btn btn-sm" href="customer.php?id=<?= (int)$pr['party_id'] ?>">See</a>
  </div>
  <?php endforeach; ?>
  <?php foreach ($promises['broken'] as $pr): ?>
  <div class="act act-bad">
    <span class="act-ico">❌</span>
    <span class="act-body"><strong><?= e($pr['name']) ?></strong> — Rs <?= money($pr['amount']) ?>
      <?= dmy($pr['due_date']) ?> promise was not kept</span>
    <a class="btn btn-sm btn-danger" href="customer.php?id=<?= (int)$pr['party_id'] ?>">Get in touch</a>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<form method="post" id="collForm">
  <?= csrf_field() ?>
  <input type="hidden" name="do" value="preview_bulk">
  <div class="card">
    <div class="page-actions no-print" style="margin:0 0 8px;justify-content:space-between">
      <h3 style="margin:0">List <span class="muted" style="font-weight:400;font-size:13px">(in priority order)</span></h3>
      <span>
        <a class="btn btn-sm btn-outline" href="collection.php<?= $showAll ? '' : '?show=all' ?>">
          <?= $showAll ? 'Show only the ones worth doing' : 'Show all (including snoozed)' ?></a>
      </span>
    </div>
    <?php if (!$queue): ?>
      <p class="muted">Nobody owes anything. 🎉</p>
    <?php else: ?>
    <div class="table-wrap"><table class="table-sm">
      <thead><tr>
        <th style="width:28px"><input type="checkbox" onclick="document.querySelectorAll('.ckpick').forEach(c=>{if(!c.disabled)c.checked=this.checked})"></th>
        <th>Priority</th><th>Customer</th><th class="num">Due Rs </th><th class="num">days</th>
        <th>Last payment</th><th>Last contact</th><th>What to do</th>
      </tr></thead>
      <tbody>
      <?php foreach ($queue as $c): list($ico, $tone, $lvl) = $levelStyle[$c['priority']['level']]; ?>
      <tr>
        <td><input type="checkbox" class="ckpick" name="pick[]" value="<?= (int)$c['id'] ?>"
                   <?= $c['can_remind']['ok'] ? '' : 'disabled title="' . e($c['can_remind']['why']) . '"' ?>></td>
        <td><span title="<?= e(implode(' · ', $c['priority']['why'])) ?>"><?= $ico ?> <?= $lvl ?>
            <br><span class="muted" style="font-size:11px"><?= (int)$c['priority']['score'] ?>/100</span></span></td>
        <td>
          <a href="customer.php?id=<?= (int)$c['id'] ?>"><strong><?= e($c['name']) ?></strong></a>
          <?php if ($c['mobile']): ?><br><a class="muted" style="font-size:12px" href="tel:<?= e($c['mobile']) ?>"><?= e($c['mobile']) ?></a><?php endif; ?>
          <br><span class="muted" style="font-size:11px"><?= e(implode(' · ', $c['priority']['why'])) ?></span>
        </td>
        <td class="num">₹<?= money($c['overdue']) ?>
          <?php if ($c['outstanding'] > $c['overdue'] + 0.009): ?><br><span class="muted" style="font-size:11px">Total Rs <?= money($c['outstanding']) ?></span><?php endif; ?>
        </td>
        <td class="num"><?= (int)$c['days'] ?>d<br><span class="muted" style="font-size:11px"><?= (int)$c['bill_count'] ?> Bill</span></td>
        <td><?= $c['last_payment'] ? dmy($c['last_payment']) : '<span class="muted">never</span>' ?></td>
        <td><?= $c['last_contact'] ? dmy(substr($c['last_contact'], 0, 10)) : '<span class="muted">—</span>' ?>
          <?php if (!$c['can_remind']['ok']): ?><br><span class="badge badge-warn" style="font-size:10px"><?= e($c['can_remind']['why']) ?></span><?php endif; ?>
        </td>
        <td style="white-space:nowrap">
          <a class="btn btn-sm btn-outline" href="parties.php?action=ledger&id=<?= (int)$c['id'] ?>" title="Ledger">📒</a>
          <?php if ($c['mobile']): ?><a class="btn btn-sm btn-outline" href="tel:<?= e($c['mobile']) ?>" title="Phone">📞</a><?php endif; ?>
          <?php if (can('payments.add')): ?><a class="btn btn-sm btn-success" href="payments.php?action=new&dir=in&party_id=<?= (int)$c['id'] ?>" title="Record a payment">💵</a><?php endif; ?>
          <a class="btn btn-sm" href="customer.php?id=<?= (int)$c['id'] ?>" title="Customer 360">👤</a>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <div class="page-actions no-print mt">
      <button class="btn btn-wa" type="submit">📲 Send reminders to the selected</button>
      <span class="muted" style="font-size:12px">Before sending, you are shown who, what and how much it costs.</span>
    </div>
    <?php endif; ?>
  </div>
</form>

<?php include __DIR__ . '/includes/footer.php'; ?>
