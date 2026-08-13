<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); die('CLI only'); }
// Customer intelligence and smart collection.
//
// These rules decide who gets chased for money, so every one of them is
// checked against a hand-built customer whose history is known exactly. The
// suite runs as the admin: cust_360() and the segment builder ask can() what
// the reader may see, and with no session user everything money-related would
// be hidden and half these assertions would pass for the wrong reason.
$_SESSION['user_id'] = (int)val("SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id
                                 WHERE r.permissions LIKE '%*%' AND u.is_active = 1 ORDER BY u.id LIMIT 1");
t_ok('tests run as a full admin, so permission gates are open', can('payments.view') && can('reports.profit'));

$t = today();
$loc = (int)val('SELECT id FROM locations ORDER BY id LIMIT 1');
$co = (int)val('SELECT id FROM companies ORDER BY id LIMIT 1');

/** A customer who buys every $gap days, $n times, each for $amount. */
function tc_rhythmic($name, $gap, $n, $amount, $paid = true) {
    $p = t_party($name);
    q("UPDATE parties SET mobile = ?, created_at = DATE_SUB(NOW(), INTERVAL ? DAY) WHERE id = ?",
      ['98' . random_int(10000000, 99999999), $gap * $n + 30, $p]);
    for ($i = $n; $i >= 1; $i--) {
        t_sale($p, $amount, $paid ? $amount : 0, date('Y-m-d', strtotime("-" . ($gap * $i) . " days")));
    }
    if ($paid) t_payment($p, $amount * $n);
    return $p;
}

t_group('purchase frequency is read from the customer\'s own history');
$rh = tc_rhythmic('CT Rhythm', 30, 6, 2000);
$f = cust_frequency($rh);
t_eq('the median gap is their real gap', $f['gap_days'], 30);
t_eq('all six bills counted', $f['bills'], 6);
t_ok('which is about a dozen visits a year', $f['per_year'] > 11 && $f['per_year'] < 13);
$one = t_party();
t_sale($one, 500, 500, $t);
t_eq('one bill gives no gap to measure', cust_frequency($one)['gap_days'], null);
t_eq('no bills at all is also safe', cust_frequency(t_party())['gap_days'], null);

t_group('a single freak gap does not distort the rhythm');
// one 310-day absence, then a steady 30-day rhythm: the median must follow
// the rhythm, not the outlier (an average would land near 120)
$odd = t_party('CT Odd');
foreach ([400, 90, 60, 30] as $d) t_sale($odd, 1000, 1000, date('Y-m-d', strtotime("-$d days")));
t_eq('median follows the rhythm, not the one long absence', cust_frequency($odd)['gap_days'], 30);

t_group('at-risk is judged against their OWN rhythm, not a fixed rule');
// buys every 30 days like clockwork, then nothing for 80 days -> At Risk.
// Dates are written out rather than shifted afterwards, so the median gap and
// the idle time are both exactly what the assertions expect.
$risk = t_party('CT Drifting');
q("UPDATE parties SET created_at = DATE_SUB(NOW(), INTERVAL 300 DAY) WHERE id = ?", [$risk]);
foreach ([200, 170, 140, 110, 80] as $d) t_sale($risk, 3000, 3000, date('Y-m-d', strtotime("-$d days")));
t_payment($risk, 15000);
t_eq('their usual gap really is 30 days', cust_frequency($risk)['gap_days'], 30);
$segs = cust_segments_for($risk);
t_ok('a regular buyer who has gone quiet is At Risk', isset($segs['at_risk']));
t_ok('and the reason names their own usual gap', strpos($segs['at_risk'] ?? '', '30') !== false);
t_ok('…and how long they have been away', strpos($segs['at_risk'] ?? '', '80') !== false);

// buys twice a year - 80 days quiet is NORMAL for them, not a problem
$slow = t_party('CT Seasonal');
q("UPDATE parties SET created_at = DATE_SUB(NOW(), INTERVAL 800 DAY) WHERE id = ?", [$slow]);
foreach ([560, 380, 200, 80] as $d) t_sale($slow, 5000, 5000, date('Y-m-d', strtotime("-$d days")));
t_payment($slow, 20000);
$segs2 = cust_segments_for($slow);
t_ok('a twice-a-year buyer is NOT at risk at 80 days', !isset($segs2['at_risk']));

t_group('inactive is a plain threshold');
$dead = t_party('CT Gone');
q("UPDATE parties SET created_at = DATE_SUB(NOW(), INTERVAL 900 DAY) WHERE id = ?", [$dead]);
t_sale($dead, 4000, 4000, date('Y-m-d', strtotime('-300 days')));
t_payment($dead, 4000);
$sd = cust_segments_for($dead);
t_ok('300 days quiet is Inactive', isset($sd['inactive']));
t_ok('and not also At Risk - one label, the worse one', !isset($sd['at_risk']));

t_group('value segments follow the configured thresholds');
set_setting('cust_vip_spend', '50000');
set_setting('cust_high_spend', '20000');
$vip = tc_rhythmic('CT Vip', 20, 6, 12000);      // 72,000 in a year
$hv  = tc_rhythmic('CT High', 40, 3, 9000);      // 27,000
$sm  = tc_rhythmic('CT Small', 40, 2, 1000);     // 2,000
t_ok('72,000 a year is VIP', isset(cust_segments_for($vip)['vip']));
t_ok('27,000 a year is High Value', isset(cust_segments_for($hv)['high_value']));
$smSeg = cust_segments_for($sm);
t_ok('2,000 a year is neither', !isset($smSeg['vip']) && !isset($smSeg['high_value']));
t_ok('six bills in a year is Regular', isset(cust_segments_for($vip)['regular']));

t_group('lifetime value and margin contribution');
$lv = t_party('CT Value');
$it = t_item(100, $loc);
q('UPDATE items SET purchase_price = 700 WHERE id = ?', [$it]);
$s1 = t_sale($lv, 1000, 1000, date('Y-m-d', strtotime('-10 days')));
q('INSERT INTO sale_items (sale_id, item_id, qty, price, cost_price, total) VALUES (?,?,?,?,?,?)', [$s1, $it, 1, 1000, 700, 1000]);
$s2 = t_sale($lv, 3000, 3000, date('Y-m-d', strtotime('-5 days')));
q('INSERT INTO sale_items (sale_id, item_id, qty, price, cost_price, total) VALUES (?,?,?,?,?,?)', [$s2, $it, 3, 1000, 700, 3000]);
t_payment($lv, 4000);
$c = cust_360($lv);
t_eq('lifetime value is every bill ever', $c['lifetime_value'], 4000.0);
t_eq('the year value matches here', $c['year_value'], 4000.0);
t_eq('average bill', $c['avg_bill'], 2000.0);
t_eq('biggest bill', $c['biggest_bill'], 3000.0);
t_eq('monthly average is the year over twelve', $c['monthly_value'], round(4000 / 12, 2));
t_eq('margin contribution is revenue minus cost minus discount', $c['margin']['contribution'], 1200.0);
t_ok('and it is never called profit', true); // enforced by the label in customer.php, asserted below

t_group('a customer with nothing does not break anything');
$empty = t_party('CT Empty');
$ce = cust_360($empty);
t_eq('no bills', $ce['bills'], 0);
t_eq('no lifetime value', $ce['lifetime_value'], 0.0);
t_eq('average bill does not divide by zero', $ce['avg_bill'], 0.0);
t_eq('nothing outstanding', $ce['outstanding'], 0.0);
t_eq('no segments to speak of', isset($ce['segments']['vip']), false);
t_eq('credit suggestion is zero, not negative', $ce['credit']['suggested'] >= 0, true);
t_eq('reliability is "new", not a guess', $ce['credit']['reliability']['rating'], 'new');
t_ok('opportunities on an empty customer are safe', is_array(cust_opportunities($empty)));

t_group('outstanding is capped by the ledger, like everywhere else');
$un = t_party('CT Unlinked');
$b1 = t_sale($un, 2800, 0, date('Y-m-d', strtotime('-60 days')));
$b2 = t_sale($un, 2041, 0, date('Y-m-d', strtotime('-10 days')));
t_payment($un, 2800); // paid, never linked
$o = cust_outstanding($un);
t_eq('an unlinked payment reduces what we claim', $o['total'], 2041.0);
t_eq('which is exactly the ledger balance', $o['total'], party_balance($un));
t_eq('only one bill still stands', $o['bills'], 1);
t_eq('the 360 view agrees', cust_360($un)['outstanding'], 2041.0);

t_group('a fully paid customer owes nothing');
$fp = t_party('CT Paid');
$fb = t_sale($fp, 5000, 5000, date('Y-m-d', strtotime('-20 days')));
t_payment($fp, 5000, 'in', 'cash', [['sale', $fb, 5000]]);
t_eq('nothing outstanding', cust_outstanding($fp)['total'], 0.0);
t_eq('nothing overdue', cust_outstanding($fp)['overdue'], 0.0);
t_ok('and they are not in the collection queue', !array_filter(coll_queue(5000), fn($x) => $x['id'] === $fp));

t_group('a customer in credit is never chased');
$cb = t_party('CT Advance');
t_sale($cb, 1000, 0, date('Y-m-d', strtotime('-30 days')));
t_payment($cb, 4000); // paid far more than owed
t_ok('their balance is negative', party_balance($cb) < 0);
t_eq('nothing is claimable', cust_outstanding($cb)['total'], 0.0);
t_ok('and they never appear in the queue', !array_filter(coll_queue(5000), fn($x) => $x['id'] === $cb));

t_group('opening balance and sales returns flow through');
$ob = t_party('CT Opening', 3000);
t_eq('the opening balance alone is claimable', cust_outstanding($ob)['total'], 0.0); // no bills to attach it to
t_eq('but the ledger still shows it', party_balance($ob), 3000.0);
$ret = t_party('CT Return');
$rb = t_sale($ret, 5000, 0, date('Y-m-d', strtotime('-30 days')));
q('INSERT INTO sales_returns (party_id, location_id, total, return_date, created_by) VALUES (?,?,?,?,1)',
  [$ret, $loc, 2000, date('Y-m-d', strtotime('-20 days'))]);
t_eq('a return reduces what we may claim', cust_outstanding($ret)['total'], 3000.0);
t_eq('matching the ledger', cust_outstanding($ret)['total'], party_balance($ret));

t_group('collection priority weighs the things a shopkeeper would say');
$base = ['overdue' => 0.0, 'days' => 0, 'bill_count' => 1, 'year_value' => 0,
         'reliability' => ['rating' => 'excellent'], 'broken_promises' => 0, 'promise_open' => null];
$small = coll_priority(array_merge($base, ['overdue' => 1000, 'days' => 5]));
$big   = coll_priority(array_merge($base, ['overdue' => 25000, 'days' => 5]));
t_ok('more money scores higher', $big['score'] > $small['score']);
$late  = coll_priority(array_merge($base, ['overdue' => 1000, 'days' => 60]));
t_ok('more delay scores higher', $late['score'] > $small['score']);
$poor  = coll_priority(array_merge($base, ['overdue' => 1000, 'days' => 5, 'reliability' => ['rating' => 'poor']]));
t_ok('a known bad payer scores higher than a good one', $poor['score'] > $small['score']);
$brk   = coll_priority(array_merge($base, ['overdue' => 1000, 'days' => 5, 'broken_promises' => 2]));
t_ok('a broken promise pushes them up', $brk['score'] > $small['score']);
t_ok('and says so in the reasons', (bool)array_filter($brk['why'], fn($w) => strpos($w, 'વાયદો') !== false));
$prom = coll_priority(array_merge($base, ['overdue' => 25000, 'days' => 60,
        'promise_open' => ['due_date' => date('Y-m-d', strtotime('+3 days')), 'amount' => 25000]]));
$noProm = coll_priority(array_merge($base, ['overdue' => 25000, 'days' => 60]));
t_ok('an open promise parks them below the rest', $prom['score'] < $noProm['score']);
t_ok('every score stays within 0-100', $noProm['score'] <= 100 && $small['score'] >= 0);
t_ok('every score carries its reasons', count($noProm['why']) > 0);
foreach (['critical' => 75, 'high' => 55, 'medium' => 35, 'low' => 10] as $lvl => $sc) {
    $probe = coll_priority(array_merge($base, ['overdue' => $sc * 900, 'days' => $sc]));
    t_ok("a score around $sc maps to a sensible level", in_array($probe['level'], ['critical', 'high', 'medium', 'low'], true));
}

t_group('the queue is ordered by priority, not by size alone');
$q = coll_queue(50);
$prev = 101;
$ordered = true;
foreach ($q as $row) { if ($row['priority']['score'] > $prev) $ordered = false; $prev = $row['priority']['score']; }
t_ok('rows come back worst-first', $ordered);
t_ok('every row explains itself', !array_filter($q, fn($r) => empty($r['priority']['why'])));
t_ok('every row says whether it may be messaged', !array_filter($q, fn($r) => !isset($r['can_remind']['ok'])));

t_group('promise to pay');
$pp = t_party('CT Promise');
t_sale($pp, 8000, 0, date('Y-m-d', strtotime('-40 days')), date('Y-m-d', strtotime('-20 days')));
$promId = coll_log($pp, 'promise', ['amount' => 8000, 'due_date' => date('Y-m-d', strtotime('+3 days'))]);
$row = null;
foreach (coll_queue(5000) as $r) if ($r['id'] === $pp) $row = $r;
t_ok('the promise is picked up', $row && $row['promise_open']);
t_ok('and stops any reminder until then', !$row['can_remind']['ok']);
t_ok('with the date as the reason', strpos($row['can_remind']['why'], dmy(date('Y-m-d', strtotime('+3 days')))) !== false);

t_group('a promise kept closes itself');
$pk = t_party('CT Keeps');
t_sale($pk, 6000, 0, date('Y-m-d', strtotime('-40 days')));
coll_log($pk, 'promise', ['amount' => 6000, 'due_date' => date('Y-m-d', strtotime('-1 day'))]);
t_payment($pk, 6000);
list($kept, $broken) = coll_settle_promises();
t_ok('at least one promise settled as kept', $kept >= 1);
$evs = array_column(cust_events($pk), 'event_type');
t_ok('a "kept" event is recorded', in_array('kept', $evs, true));
t_eq('and the promise is closed', val("SELECT status FROM collection_events WHERE party_id = ? AND event_type = 'promise'", [$pk]), 'done');

t_group('a promise broken raises the priority');
$pb = t_party('CT Breaks');
t_sale($pb, 9000, 0, date('Y-m-d', strtotime('-50 days')), date('Y-m-d', strtotime('-30 days')));
coll_log($pb, 'promise', ['amount' => 9000, 'due_date' => date('Y-m-d', strtotime('-2 days'))]);
$beforeRow = null;
foreach (coll_queue(5000) as $r) if ($r['id'] === $pb) $beforeRow = $r;
coll_settle_promises();
$afterRow = null;
foreach (coll_queue(5000) as $r) if ($r['id'] === $pb) $afterRow = $r;
t_ok('a "broken" event is recorded', in_array('broken', array_column(cust_events($pb), 'event_type'), true));
t_eq('the queue counts the broken promise', $afterRow['broken_promises'], 1);
t_ok('and the score goes UP once the promise is gone', $afterRow['priority']['score'] > $beforeRow['priority']['score']);

t_group('reminder guards - the point is to send FEWER messages');
set_setting('collection_cooldown_days', '3');
set_setting('collection_max_reminders', '4');
$g = ['mobile' => '9812345678', 'opt_out' => false, 'snoozed_until' => null,
      'promise_open' => null, 'last_contact' => null, 'contacts_30d' => 0];
t_ok('a plain overdue customer may be messaged', coll_can_remind($g)['ok']);
t_ok('nobody without a mobile number', !coll_can_remind(array_merge($g, ['mobile' => '']))['ok']);
t_ok('nobody who opted out', !coll_can_remind(array_merge($g, ['opt_out' => true]))['ok']);
t_ok('nobody snoozed', !coll_can_remind(array_merge($g, ['snoozed_until' => date('Y-m-d', strtotime('+5 days'))]))['ok']);
t_ok('a snooze that has expired no longer blocks', coll_can_remind(array_merge($g, ['snoozed_until' => date('Y-m-d', strtotime('-1 day'))]))['ok']);
t_ok('nobody with an open promise', !coll_can_remind(array_merge($g, ['promise_open' => ['due_date' => date('Y-m-d', strtotime('+2 days'))]]))['ok']);
t_ok('nobody contacted inside the cooldown', !coll_can_remind(array_merge($g, ['last_contact' => date('Y-m-d H:i:s', strtotime('-1 day'))]))['ok']);
t_ok('but after the cooldown they may be', coll_can_remind(array_merge($g, ['last_contact' => date('Y-m-d H:i:s', strtotime('-5 days'))]))['ok']);
t_ok('nobody already messaged four times this month', !coll_can_remind(array_merge($g, ['contacts_30d' => 4]))['ok']);
foreach ([['opt_out' => true], ['mobile' => ''], ['contacts_30d' => 9]] as $bad)
    t_ok('every refusal explains itself', coll_can_remind(array_merge($g, $bad))['why'] !== '');

t_group('duplicate reminders are prevented by history, not by luck');
$dup = t_party('CT Dup');
q("UPDATE parties SET mobile = '9800011111' WHERE id = ?", [$dup]);
t_sale($dup, 4000, 0, date('Y-m-d', strtotime('-40 days')), date('Y-m-d', strtotime('-25 days')));
$before = null;
foreach (coll_queue(5000) as $r) if ($r['id'] === $dup) $before = $r;
t_ok('before any contact, they are messageable', $before['can_remind']['ok']);
coll_log($dup, 'reminder', ['channel' => 'whatsapp', 'status' => 'done']);
$after = null;
foreach (coll_queue(5000) as $r) if ($r['id'] === $dup) $after = $r;
t_ok('immediately after a reminder, they are not', !$after['can_remind']['ok']);
t_eq('and the contact is counted', $after['contacts_30d'], 1);

t_group('credit intelligence suggests, never blocks');
$cr = tc_rhythmic('CT Credit', 30, 12, 5000);   // 60,000 a year, always paid
$credit = cust_credit($cr);
t_ok('a suggested limit is offered', $credit['suggested'] > 0);
t_ok('based on real monthly buying', $credit['monthly_buy'] > 0);
t_ok('nothing about it blocks a sale', !isset($credit['blocked']));
$over = t_party('CT OverLimit');
t_sale($over, 90000, 0, date('Y-m-d', strtotime('-10 days')));
t_ok('a big balance against no history is flagged over limit', cust_credit($over)['over_limit']);

t_group('payment reliability is measured from real dates');
$rel = t_party('CT Late');
for ($i = 0; $i < 4; $i++) {
    $due = date('Y-m-d', strtotime('-' . (100 - $i * 10) . ' days'));
    $sid = t_sale($rel, 1000, 1000, $due, $due);
    // paid 10 days after the due date each time
    q("INSERT INTO payments (party_id, direction, amount, mode, pay_date, notes, created_by)
       VALUES (?, 'in', 1000, 'cash', ?, 'late', 1)", [$rel, date('Y-m-d', strtotime($due . ' +10 days'))]);
    q('INSERT INTO payment_allocations (payment_id, ref_type, ref_id, amount) VALUES (?, ?, ?, ?)', [insert_id(), 'sale', $sid, 1000]);
}
$r = cust_reliability($rel);
t_eq('every bill was paid late', $r['late'], 4);
t_eq('so the rating is poor', $r['rating'], 'poor');
t_eq('and the average delay is real', $r['avg_delay'], 10);

t_group('collection summary adds up');
$sum = coll_summary();
t_ok('outstanding is never negative', $sum['outstanding'] >= 0);
t_ok('overdue never exceeds outstanding', $sum['overdue'] <= $sum['outstanding'] + 0.01);
t_ok('contactable never exceeds the customer count', $sum['contactable'] <= $sum['customers']);
t_ok('critical and high fit inside the customer count', $sum['critical'] + $sum['high'] <= $sum['customers']);

t_group('money figures stay behind the permissions that own them');
// The guards are asserted at the source, the way the existing page-gate tests
// do it - a runtime flip is impossible here because current_user() caches.
$src = file_get_contents(dirname(__DIR__) . '/includes/customer.php');
t_ok('the money-bearing segments are gated on payments.view',
     (bool)preg_match('/\$bal > MONEY_EPS && can\(\'payments\.view\'\)/', $src));
t_ok('margin contribution is gated on reports.profit',
     (bool)preg_match('/if \(can\(\'reports\.profit\'\)\)\s*\{\s*\$m = row/', $src));
$page = file_get_contents(dirname(__DIR__) . '/customer.php');
t_ok('Customer 360 requires parties.view', strpos($page, "require_perm('parties.view')") !== false);
t_ok('its money blocks are gated on payments.view', substr_count($page, '$seeMoney') >= 3);
t_ok('its margin block is gated on reports.profit', strpos($page, '$seeProfit && $c[\'margin\']') !== false);
t_ok('margin is labelled contribution, never profit', strpos($page, 'માર્જિન યોગદાન') !== false);
$coll = file_get_contents(dirname(__DIR__) . '/collection.php');
t_ok('the collection queue requires payments.view', strpos($coll, "require_perm('payments.view')") !== false);
t_ok('bulk send re-checks the guards at send time, not just at preview',
     substr_count($coll, "can_remind") >= 3);

t_group('the reactivation draft is built from real history, not invented');
$dr = cust_reactivation_draft($rh);
t_ok('it addresses them by name', strpos($dr, 'CT Rhythm') !== false);
t_ok('it is plain text, ready to edit', strlen($dr) > 20 && strpos($dr, '<') === false);
t_eq('an unknown customer yields nothing', cust_reactivation_draft(999999), '');
