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
require_once __DIR__ . '/includes/voice.php';
require_perm('payments.view');
$u = current_user();

// ---------- actions ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pid = (int)post('id');
    $back = 'collection.php' . (get('show') === 'all' ? '?show=all' : '');
    // The aging report sends its picked customers here too, so "back" has to be
    // able to mean that report. Only a bare page name of ours is accepted, never
    // whatever arrived in the field, so the button cannot be pointed off-site.
    $from = (string)post('back');
    if ($from !== '' && preg_match('~^[a-z0-9_]+\.php(\?[A-Za-z0-9_=&-]*)?$~', $from)
        && is_file(__DIR__ . '/' . explode('?', $from)[0])) $back = $from;

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

    // ---- the automatic reminder call ----
    //
    // Sending needs the permission to CHANGE money matters, not just to look
    // at them: this screen is open to anyone who may see payments, and
    // ringing a customer is not a read-only act.
    if (post('do') === 'voice_call') {
        require_perm('payments.add');
        $res = voice_call_send($pid, ['client_uuid' => trim(post('client_uuid'))]);
        $who = (string)val('SELECT name FROM parties WHERE id = ?', [$pid]);
        if (!empty($res['duplicate'])) flash('That call was already placed — not calling ' . $who . ' twice.');
        elseif ($res['ok'] && $res['status'] === 'test')
            flash('🧪 Test mode: nothing was dialled. ' . $who . ' would have heard — “' . ($res['script'] ?? '') . '”');
        elseif ($res['ok']) flash('📞 Calling ' . $who . ' now.');
        else flash('The call did not go: ' . $res['error'], 'error');
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

    // ---- ring a whole list ----
    //
    // Same shape as the bulk message below it: show who, then do it. The
    // difference is that calls are queued rather than placed, so pressing
    // the button does not sit there dialling thirty people one by one.
    if (post('call_selected') || post('do') === 'send_calls') {
        require_perm('payments.add');
        // The picked ids are taken as they come, from wherever they came from -
        // this screen's own list, or the aging report, which chases parties this
        // queue's own rule leaves out. Reading them back through coll_queue()
        // meant a party the queue did not list was dropped in silence.
        //
        // The figures shown are the context's, which is exactly what the call
        // will SAY (voice_call_send() speaks $ctx['due']). Showing a different
        // number here - the queue's own 'overdue' - would be the same fault as
        // the phone quoting one balance while the statement quoted another.
        $ids = array_map('intval', post('pick', []));
        // The aging report ticks carry "mobile|amount|name|party_id", because the
        // same tick has to serve a WhatsApp message as well as a call. Only the
        // id is taken from them: the amount spoken and the name said are read
        // from the customer here, not from a field the page sent us.
        $walkins = 0;
        foreach (post('rem', []) as $val) {
            $parts = explode('|', (string)$val);
            $rid = (int)end($parts);
            // A walk-in sale has no customer record, so there is no ledger, no
            // do-not-call flag and no cooldown to check - it cannot be rung.
            // It is COUNTED rather than dropped in silence, or the owner ticks
            // five rows, sees three on the next screen and never learns why.
            if ($rid > 0) $ids[] = $rid; else $walkins++;
        }
        $ids = array_values(array_unique($ids));
        $ctxs = voice_contexts($ids);
        $picked = []; $skipped = [];
        foreach ($ids as $pid2) {
            $c = $ctxs[$pid2] ?? null;
            if (!$c) { $skipped[] = ['name' => '#' . $pid2, 'mobile' => '', 'due' => 0,
                                     'why' => 'This customer no longer exists']; continue; }
            $g = voice_can_call($pid2, $c);
            if ($g['ok']) $picked[] = $c + ['why' => ''];
            else $skipped[] = $c + ['why' => $g['why']];
        }

        if (post('do') === 'send_calls') {
            $n = 0;
            foreach ($picked as $c) {
                $r = voice_call_send($c['id'], ['queue' => true,
                                                'client_uuid' => 'bulk-' . $c['id'] . '-' . date('YmdHi')]);
                if ($r['ok']) $n++;
            }
            log_activity('voice_bulk', $n . ' call(s) queued');
            flash($n ? '📞 ' . $n . ' call(s) queued — they go out a few at a time over the next few minutes.'
                     : 'No call could be queued.', $n ? 'success' : 'error');
            redirect($back);
        }

        $page_title = 'Before calling';
        include __DIR__ . '/includes/header.php'; ?>
        <div class="card">
          <h2>📞 About to ring <?= count($picked) ?> customer(s)</h2>
          <?php if (!empty($walkins)): ?>
            <div class="flash flash-info"><?= (int)$walkins ?> walk-in row(s) left out — a walk-in sale has no
              customer record, so there is no balance to read out. Save them as a party to call them.</div>
          <?php endif; ?>
          <?php if (voice_test_mode()): ?>
            <div class="flash flash-error"><strong>Test mode is on — nothing will actually be dialled.</strong>
              Turn it off in <a href="voice_setup.php">Call Setup</a> when you are ready.</div>
          <?php endif; ?>
          <?php if ($skipped): ?>
          <div class="flash flash-info"><?= count($skipped) ?> left out:<br>
            <?php foreach ($skipped as $sk): ?>
              <span class="muted" style="font-size:12px">• <?= e($sk['name']) ?> — <?= e($sk['why']) ?></span><br>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
          <?php if (!$picked): ?>
            <p class="muted">Nobody on that list can be called right now.</p>
            <a class="btn btn-outline" href="<?= e($back) ?>">← Back</a>
          <?php else: ?>
          <div class="table-wrap"><table class="table-sm">
            <thead><tr><th>Customer</th><th>Mobile</th><th class="num">Will be told</th></tr></thead>
            <tbody>
            <?php $tot = 0; foreach ($picked as $c): $tot += $c['due']; ?>
              <tr><td><?= e($c['name']) ?></td><td><?= e($c['mobile']) ?></td>
                  <td class="num">₹<?= money($c['due']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
          </table></div>
          <p class="muted" style="font-size:13px">
            Total ₹<?= money($tot) ?>. They go out <?= (int)setting('voice_bulk_per_run', 5) ?> at a time, every few
            minutes — not all at once, which is what makes a number look like a spam dialler.
            Every guard is checked again at the moment each one is dialled, so anybody who pays or promises
            in the meantime is dropped.
          </p>
          <form method="post" class="mt" onsubmit="this.querySelector('button').disabled=true">
            <?= csrf_field() ?><input type="hidden" name="do" value="send_calls">
            <input type="hidden" name="back" value="<?= e($back) ?>">
            <?php foreach ($picked as $c): ?><input type="hidden" name="pick[]" value="<?= (int)$c['id'] ?>"><?php endforeach; ?>
            <button class="btn btn-success" type="submit">✅ Yes, call these <?= count($picked) ?></button>
            <a class="btn btn-outline" href="collection.php">Leave it</a>
          </form>
          <?php endif; ?>
        </div>
        <?php include __DIR__ . '/includes/footer.php'; exit;
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
            <a class="btn btn-outline" href="<?= e($back) ?>">← Back</a>
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

// ---------- "are you sure you want to call?" ----------
//
// A separate screen rather than a JavaScript confirm box, for the same
// reason the bulk send has one: the owner gets to see the amount and the
// exact words before a customer's phone rings, and a mis-tap on a small
// screen lands here instead of on a live call.
if (($callPid = (int)get('call')) > 0) {
    require_perm('payments.add');
    $vc = voice_party_context($callPid);
    if (!$vc) { flash('No such customer.', 'error'); redirect('collection.php'); }
    $gate = voice_can_call($callPid, $vc);
    $lang = setting('voice_lang', 'gu');
    // Planning here also MAKES the audio, which is the point: by the time the
    // owner presses "Yes, call now" the voice already exists, and the preview
    // below is the very file the customer will hear.
    $vpromise = (!empty($vc['promise_open']) && $vc['promise_open']['due_date'] <= today())
        ? $vc['promise_open']['due_date'] : null;
    $plan = $gate['ok'] ? voice_audio_plan($vc['name'], $vc['due'], $lang, $vpromise)
                        : ['lang' => $lang, 'main' => ['url' => '', 'text' => ''], 'error' => '', 'fell_back' => false];
    $script = voice_script($vc['name'], $vc['due'], $plan['lang']);
    $question = voice_question($plan['lang'], $vpromise)[$vpromise ? 'promise' : 'plain'];
    $last = $vc['last_call'];
    $page_title = 'Before calling';
    include __DIR__ . '/includes/header.php'; ?>
    <div class="card">
      <h2>📞 Really call <?= e($vc['name']) ?>?</h2>
      <div class="grid-stats">
        <div class="stat"><div class="stat-label">Customer</div><div class="stat-value" style="font-size:18px"><?= e($vc['name']) ?></div></div>
        <div class="stat"><div class="stat-label">Number</div><div class="stat-value" style="font-size:18px"><?= e($vc['mobile'] ?: '—') ?></div></div>
        <div class="stat s-bad"><div class="stat-label">Outstanding</div><div class="stat-value">₹<?= money($vc['due']) ?></div></div>
        <div class="stat"><div class="stat-label">Last call</div>
          <div class="stat-value" style="font-size:16px"><?= $last ? e(voice_status_label($last['status'])) . '<br><span class="muted" style="font-size:12px">' . e(voice_ago($last['created_at'])) . '</span>' : '<span class="muted">never</span>' ?></div></div>
      </div>

      <?php if (!$gate['ok']): ?>
        <div class="flash flash-error">This call cannot go out: <?= e($gate['why']) ?></div>
        <a class="btn btn-outline" href="collection.php">← Back</a>
      <?php else: ?>
      <h3>What they will hear</h3>
      <p class="muted" style="font-size:12px">
        <?php if ($plan['fell_back']): ?>
          <strong>In English</strong> — the Gujarati voice could not be made: <?= e($plan['error']) ?>
          <a href="voice_setup.php">Check the setup →</a>
        <?php elseif ($plan['lang'] === 'en'): ?>
          <strong>In English</strong>, spoken by the provider.
        <?php else: ?>
          In <?= e(voice_langs()[$plan['lang']]) ?>. <strong>Listen to it before you call</strong> —
          it is the very recording the customer will hear.
        <?php endif; ?>
      </p>
      <pre style="white-space:pre-wrap;background:var(--bg);padding:12px;border-radius:10px;font-family:inherit;font-size:15px"><?= e($script) ?><?= voice_ivr_on() ? "\n\n" . e($question) : '' ?></pre>
      <?php if (!empty($plan['main']['url'])): ?>
        <audio controls preload="none" src="<?= e($plan['main']['url']) ?>" style="width:100%"></audio>
      <?php endif; ?>
      <?php if (voice_ivr_on()): ?>
        <p class="muted" style="font-size:12px">
          They press <strong>1</strong> → written down as a promise to pay today, and no more reminders go to them until the day is out.<br>
          They press <strong>2</strong> → written down as “said no”, so whoever looks next knows the phone was answered.
        </p>
      <?php endif; ?>

        <?php if (voice_test_mode()): ?>
          <div class="flash flash-info">🧪 Test mode is on — nothing will actually be dialled.
            Turn it off in <a href="voice_setup.php">Reminder Calls</a> when you are ready.</div>
        <?php endif; ?>
        <form method="post" class="mt" onsubmit="this.querySelector('button').disabled=true">
          <?= csrf_field() ?>
          <input type="hidden" name="do" value="voice_call">
          <input type="hidden" name="id" value="<?= $callPid ?>">
          <?php /* the press is stamped here, so a double-submit or a browser
                    retry finds the call it already made instead of ringing
                    the customer a second time */ ?>
          <input type="hidden" name="client_uuid" value="<?= e(bin2hex(random_bytes(16))) ?>">
          <button class="btn btn-success" type="submit">✅ Yes, call now</button>
          <a class="btn btn-outline" href="collection.php">Leave it</a>
        </form>
      <?php endif; ?>
    </div>
    <?php include __DIR__ . '/includes/footer.php'; exit;
}

// ---------- the queue ----------
$showAll = get('show') === 'all';
$queue = coll_queue(300, $showAll);
$sum = coll_summary();
$promises = coll_promises_due();

$levelStyle = ['critical' => ['🔴', 's-bad', 'Urgent'], 'high' => ['🟠', 's-warn', 'High'],
               'medium' => ['🟡', '', 'Medium'], 'low' => ['🟢', 's-ok', 'less']];

// One load for the whole screen: the parties, their collection history and
// their last call, so each row can say whether it may be called without
// going back to the database.
$vctx = voice_contexts(array_column($queue, 'id'));
$vcan = [];
foreach ($vctx as $vid => $vc) $vcan[$vid] = voice_can_call($vid, $vc);

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
        <?php $vl = $vctx[$c['id']]['last_call'] ?? null; $vg = $vcan[$c['id']] ?? ['ok' => false, 'why' => '']; ?>
        <td><?= $c['last_contact'] ? dmy(substr($c['last_contact'], 0, 10)) : '<span class="muted">—</span>' ?>
          <?php if (!$c['can_remind']['ok']): ?><br><span class="badge badge-warn" style="font-size:10px"><?= e($c['can_remind']['why']) ?></span><?php endif; ?>
          <?php /* the last call lives in this cell rather than a column of its
                   own: the table is already eight columns and the owner reads
                   it on a phone, where a ninth pushes the buttons off-screen */ ?>
          <?php if ($vl): ?>
            <br><span class="badge <?= $vl['status'] === 'answered' ? 'badge-ok' : ($vl['status'] === 'failed' ? 'badge-bad' : 'badge-warn') ?>" style="font-size:10px">📞 <?= e(voice_status_label($vl['status'])) ?></span>
            <?php if ($vl['response'] === 'yes'): ?><span class="badge badge-ok" style="font-size:10px">✅ said yes</span>
            <?php elseif ($vl['response'] === 'no'): ?><span class="badge badge-bad" style="font-size:10px">❌ said no</span><?php endif; ?>
            <span class="muted" style="font-size:11px"><?= e(voice_ago($vl['created_at'])) ?></span>
          <?php endif; ?>
        </td>
        <td style="white-space:nowrap">
          <a class="btn btn-sm btn-outline" href="parties.php?action=ledger&id=<?= (int)$c['id'] ?>" title="Ledger">📒</a>
          <?php if ($c['mobile']): ?><a class="btn btn-sm btn-outline" href="tel:<?= e($c['mobile']) ?>" title="Call from this phone">📱</a><?php endif; ?>
          <?php if (can('payments.add')): ?>
            <?php if ($vg['ok']): ?>
              <a class="btn btn-sm btn-success" href="collection.php?call=<?= (int)$c['id'] ?>" title="Automatic reminder call">Remind Now 📞</a>
            <?php else: ?>
              <button class="btn btn-sm btn-outline" type="button" disabled title="<?= e($vg['why']) ?>">📞</button>
            <?php endif; ?>
          <?php endif; ?>
          <?php if (can('payments.add')): ?><a class="btn btn-sm btn-success" href="payments.php?action=new&dir=in&party_id=<?= (int)$c['id'] ?>" title="Record a payment">💵</a><?php endif; ?>
          <a class="btn btn-sm" href="customer.php?id=<?= (int)$c['id'] ?>" title="Customer 360">👤</a>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <div class="page-actions no-print mt">
      <button class="btn btn-wa" type="submit">📲 WhatsApp the selected</button>
      <?php if (can('payments.add')): ?>
        <?php /* A separate field, not a second "do". Two controls with the
                 same name leave PHP taking whichever came last in the
                 document, so the meaning of a button would depend on where
                 it sits on the page. */ ?>
        <button class="btn btn-success" type="submit" name="call_selected" value="1">📞 Call the selected</button>
      <?php endif; ?>
      <span class="muted" style="font-size:12px">Either way you are shown who, and what they will be told, before anything goes.</span>
    </div>
    <?php if (($vq = voice_queue_count()) > 0): ?>
      <p class="muted" style="font-size:13px">📞 <strong><?= $vq ?></strong> call(s) waiting to go out — a few every
        five minutes. <a href="voice_calls.php">Watch them</a>.</p>
    <?php endif; ?>
    <?php endif; ?>
  </div>
</form>

<?php include __DIR__ . '/includes/footer.php'; ?>
