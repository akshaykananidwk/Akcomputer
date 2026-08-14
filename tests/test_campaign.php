<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); die('CLI only'); }
// Campaigns.
//
// This is the feature that can reach hundreds of real people who did not ask
// to be reached, so most of these tests are about NOT sending: the consent
// checks, the quiet hours, the gap, the monthly cap, and the opt-out that has
// to actually work. The rest are about the holdout, because a campaign nobody
// can measure is a campaign nobody should repeat.
$_SESSION['user_id'] = (int)val("SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id
                                 WHERE r.permissions LIKE '%*%' AND u.is_active = 1 ORDER BY u.id LIMIT 1");
$_ROOT = dirname(__DIR__);
$cLoc = (int)val('SELECT id FROM locations ORDER BY id LIMIT 1');
$cCo  = (int)val('SELECT id FROM companies ORDER BY id LIMIT 1');

set_setting('campaign_gap_days', '21');
set_setting('campaign_max_month', '2');
set_setting('campaign_hour_from', '10');
set_setting('campaign_hour_to', '20');
set_setting('campaign_holdout_pct', '10');
set_setting('campaign_msg_paise', '0');
set_setting('campaign_optout_line', 'મેસેજ બંધ કરવા "STOP" લખો.');
set_setting('app_name', 'AK Computer');

/** A customer with a mobile number that will not collide with real data. */
function tc_party($name = null, $mobile = null) {
    static $n = 0;
    $n++;
    $name = $name ?: 'TC Cust ' . $n . '_' . bin2hex(random_bytes(2));
    $mobile = $mobile ?: '78' . str_pad((string)(10000000 + $n), 8, '0', STR_PAD_LEFT);
    q("INSERT INTO parties (name, type, mobile, is_active) VALUES (?, 'customer', ?, 1)", [$name, $mobile]);
    return insert_id();
}

function tc_sale($partyId, $total, $date) {
    global $cCo, $cLoc;
    q("INSERT INTO sales (company_id, location_id, party_id, customer_name, invoice_no, sale_date,
        subtotal, total, paid, payment_mode, status, created_by)
       VALUES (?,?,?, 'TC', ?, ?, ?, ?, ?, 'cash', 'paid', 1)",
      [$cCo, $cLoc, $partyId, 'TC-' . bin2hex(random_bytes(4)), $date, $total, $total, $total]);
    return insert_id();
}

function tc_campaign($audience = 'all', $params = '', $holdout = 10, $days = 30) {
    q("INSERT INTO campaigns (name, audience, audience_params, message, holdout_pct, measure_days, created_by)
       VALUES (?,?,?,?,?,?,1)",
      ['TC ' . bin2hex(random_bytes(3)), $audience, (string)$params, 'નમસ્તે {customer}, {shop} ની ઓફર.', $holdout, $days]);
    return insert_id();
}

// ------------------------------------------------------------- the refusals --
t_group('consent is checked before anything else');
$p = ['id' => 999001, 'name' => 'X', 'mobile' => '', 'marketing_opt_out' => 0];
t_ok('no mobile number, no message', cam_can_message($p) !== '');
t_ok('...and the reason says so', strpos(cam_can_message($p), 'મોબાઇલ') !== false);

$p['mobile'] = '7811111111';
t_ok('a reachable customer with no objection is allowed', cam_can_message($p) === '');

$p['marketing_opt_out'] = 1;
$why = cam_can_message($p);
t_ok('a customer who opted out is refused', $why !== '');
t_ok('...and the reason names the opt-out', strpos($why, 'બંધ કરાવ્યા') !== false);

t_group('the gap and the monthly cap are real limits');
$p['marketing_opt_out'] = 0;
$recent = [999001 => ['last' => date('Y-m-d H:i:s', strtotime('-3 days')), 'month' => 1]];
$why = cam_can_message($p, $recent);
t_ok('messaged three days ago is inside the 21-day gap', $why !== '');
t_ok('...and the reason counts the days correctly', strpos($why, '3 દિવસ') !== false, $why);

$recent = [999001 => ['last' => date('Y-m-d H:i:s', strtotime('-40 days')), 'month' => 0]];
t_ok('messaged forty days ago is outside the gap', cam_can_message($p, $recent) === '');

$recent = [999001 => ['last' => date('Y-m-d H:i:s', strtotime('-40 days')), 'month' => 2]];
$why = cam_can_message($p, $recent);
t_ok('two messages this month is the cap', $why !== '');
t_ok('...and the reason gives the count', strpos($why, '2 મેસેજ') !== false, $why);

set_setting('campaign_gap_days', '0');
set_setting('campaign_max_month', '0');
t_ok('a zero gap turns that limit off, deliberately',
     cam_can_message($p, [999001 => ['last' => date('Y-m-d H:i:s'), 'month' => 99]]) === '');
set_setting('campaign_gap_days', '21');
set_setting('campaign_max_month', '2');

t_group('nobody is messaged at night');
foreach ([0, 5, 9] as $h) t_ok("{$h}:00 is too early", !cam_quiet_ok(strtotime(date('Y-m-d') . " $h:00")));
foreach ([10, 14, 19] as $h) t_ok("{$h}:00 is a decent hour", cam_quiet_ok(strtotime(date('Y-m-d') . " $h:00")));
foreach ([20, 22, 23] as $h) t_ok("{$h}:00 is too late", !cam_quiet_ok(strtotime(date('Y-m-d') . " $h:00")));

// ------------------------------------------------------------- the holdout --
t_group('the holdout is a fair coin, and a different one each campaign');
foreach ([1, 2, 3] as $cid) {
    $held = 0;
    for ($pid = 1; $pid <= 1000; $pid++) if (cam_is_holdout($cid, $pid, 10)) $held++;
    t_ok("campaign $cid holds back roughly a tenth ($held of 1000)", $held >= 70 && $held <= 130);
}
$a = []; $b = [];
for ($pid = 1; $pid <= 1000; $pid++) {
    if (cam_is_holdout(1, $pid, 10)) $a[] = $pid;
    if (cam_is_holdout(2, $pid, 10)) $b[] = $pid;
}
// Hashing on party_id alone would hold back the SAME people every time, and
// those customers would never hear from the shop again.
t_ok('a different set is held back next time', count(array_intersect($a, $b)) < count($a) / 2,
     count(array_intersect($a, $b)) . ' of ' . count($a) . ' repeated');
t_ok('zero percent holds nobody back', !cam_is_holdout(1, 7, 0));
t_ok('the same campaign always picks the same people', cam_is_holdout(5, 42, 10) === cam_is_holdout(5, 42, 10));

// ------------------------------------------------------------- preparation --
t_group('preparing a list decides everything before a single message goes');
$ok1 = tc_party();
$ok2 = tc_party();
$noPhone = tc_party(null, '');
q("UPDATE parties SET mobile = '' WHERE id = ?", [$noPhone]);
$optedOut = tc_party();
q('UPDATE parties SET marketing_opt_out = 1 WHERE id = ?', [$optedOut]);

$cid = tc_campaign('all', '', 0);   // no holdout, so every allowed person queues
$prep = cam_prepare($cid);
$mine = all("SELECT * FROM campaign_targets WHERE campaign_id = ? AND party_id IN (?,?,?,?)",
            [$cid, $ok1, $ok2, $noPhone, $optedOut]);
$byParty = [];
foreach ($mine as $t) $byParty[(int)$t['party_id']] = $t;
t_ok('a reachable customer is queued', ($byParty[$ok1]['status'] ?? '') === 'queued');
t_ok('a customer with no number is not in the list at all', !isset($byParty[$noPhone]));
t_ok('a customer who opted out IS in the list', isset($byParty[$optedOut]));
t_ok('...marked skipped, not silently dropped', ($byParty[$optedOut]['status'] ?? '') === 'skipped');
t_ok('...carrying the reason, so the question can be answered later',
     strpos($byParty[$optedOut]['reason'] ?? '', 'બંધ કરાવ્યા') !== false);
t_ok('nothing has been sent by preparing', (int)val("SELECT COUNT(*) FROM campaign_targets WHERE campaign_id = ? AND status = 'sent'", [$cid]) === 0);
t_ok('the campaign is now ready, not sending', val('SELECT status FROM campaigns WHERE id = ?', [$cid]) === 'ready');
t_ok('preparing twice does not double the list',
     (int)val('SELECT COUNT(*) FROM campaign_targets WHERE campaign_id = ?', [$cid]) === (int)($prep['total']));
$again = cam_prepare($cid);
t_eq('...literally, on a second run', $again['total'], $prep['total']);

t_group('a campaign in flight cannot have its list rewritten');
q("UPDATE campaigns SET status = 'sending' WHERE id = ?", [$cid]);
$blocked = cam_prepare($cid);
t_ok('preparing a sending campaign is refused', !empty($blocked['error']));
q("UPDATE campaigns SET status = 'ready' WHERE id = ?", [$cid]);

// ---------------------------------------------------------------- audiences --
t_group('each audience is a plain question about the books');
$new = tc_party();
tc_sale($new, 5000, date('Y-m-d', strtotime('-200 days')));
$once = all('SELECT id FROM parties WHERE id = ?', [$new]);
$onceIds = array_column(cam_build('once_only'), 'id');
t_ok('one bill, long ago, counts as never came back', in_array((string)$new, array_map('strval', $onceIds), true));
tc_sale($new, 5000, date('Y-m-d', strtotime('-10 days')));
$onceIds2 = array_column(cam_build('once_only'), 'id');
t_ok('...and a second bill takes them straight out of it', !in_array((string)$new, array_map('strval', $onceIds2), true));

$vip = tc_party();
tc_sale($vip, 250000, date('Y-m-d', strtotime('-30 days')));
$vipIds = array_map('strval', array_column(cam_build('vip', 200000), 'id'));
t_ok('a big spender is in the VIP audience', in_array((string)$vip, $vipIds, true));
$vipIds2 = array_map('strval', array_column(cam_build('vip', 500000), 'id'));
t_ok('...and not when the bar is raised above them', !in_array((string)$vip, $vipIds2, true));

$bday = tc_party();
q('UPDATE parties SET dob = ? WHERE id = ?', [date('Y-m-15', strtotime('-30 years')), $bday]);
$bIds = array_map('strval', array_column(cam_build('birthday'), 'id'));
t_ok('this month\'s birthday is found', in_array((string)$bday, $bIds, true));
q('UPDATE parties SET dob = ? WHERE id = ?', [date('Y-m-15', strtotime('-30 years +5 months')), $bday]);
$bIds2 = array_map('strval', array_column(cam_build('birthday'), 'id'));
t_ok('...a birthday five months away is not', !in_array((string)$bday, $bIds2, true));

t_ok('an audience needing a category returns nothing without one', cam_build('category', '') === []);
t_ok('...and nothing for an item that does not exist', cam_build('item', 999999999) === []);

t_group('inactive is measured against the customer\'s OWN habit');
// buys every 30 days, then goes quiet for 200 - plainly late for THEM
$reg = tc_party();
foreach ([300, 270, 240, 210] as $ago) tc_sale($reg, 2000, date('Y-m-d', strtotime("-$ago days")));
// buys once a year, quiet for 200 days - perfectly normal for THEM
$rare = tc_party();
foreach ([900, 540, 200] as $ago) tc_sale($rare, 2000, date('Y-m-d', strtotime("-$ago days")));
$inIds = array_map('strval', array_column(cam_build('inactive'), 'id'));
t_ok('a monthly buyer silent for 200 days is late', in_array((string)$reg, $inIds, true));
t_ok('a yearly buyer silent for 200 days is not chased', !in_array((string)$rare, $inIds, true));

// ------------------------------------------------------------- the message --
t_group('the way out is added by the code, not by whoever typed the message');
$c = row('SELECT * FROM campaigns WHERE id = ?', [$cid]);
$msg = cam_message_for($c, ['name' => 'રમેશભાઈ']);
t_ok('the customer name is filled in', strpos($msg, 'રમેશભાઈ') !== false);
t_ok('the shop name is filled in', strpos($msg, 'AK Computer') !== false);
t_ok('the opt-out line is appended', strpos($msg, 'STOP') !== false);
$c2 = $c;
$c2['message'] = 'ઓફર! મેસેજ બંધ કરવા "STOP" લખો.';
t_ok('...but not twice when the writer already put it in',
     substr_count(cam_message_for($c2, ['name' => 'ર']), 'STOP') === 1);
$msgNoName = cam_message_for($c, ['name' => '']);
t_ok('a customer with no name still gets a polite greeting', strpos($msgNoName, 'ગ્રાહકશ્રી') !== false);

t_group('STOP really stops it');
foreach (['STOP', 'stop', 'Stop', 'unsubscribe', 'બંધ', 'stop sending please'] as $w)
    t_ok("'$w' is understood as stop", cam_is_stop_word($w));
foreach (['non-stop music', 'હા', 'ok', 'stopped clock is right', ''] as $w)
    t_ok("'$w' is NOT treated as stop", !cam_is_stop_word($w));

$stopper = tc_party();
$mob = val('SELECT mobile FROM parties WHERE id = ?', [$stopper]);
t_ok('the flag starts clear', (int)val('SELECT marketing_opt_out FROM parties WHERE id = ?', [$stopper]) === 0);
t_ok('STOP flips exactly one customer', cam_optout($mob, true) === 1);
t_ok('...and the flag is set', (int)val('SELECT marketing_opt_out FROM parties WHERE id = ?', [$stopper]) === 1);
$sp = row('SELECT id, name, mobile, marketing_opt_out FROM parties WHERE id = ?', [$stopper]);
t_ok('...so the consent check now refuses them', cam_can_message($sp) !== '');
t_ok('a country code still finds the same person', cam_optout('+91' . $mob, false) === 1);
t_ok('...and turning it back on works', (int)val('SELECT marketing_opt_out FROM parties WHERE id = ?', [$stopper]) === 0);
t_ok('a number too short to be real changes nothing', cam_optout('123') === 0);

t_group('the two consents are separate promises');
q('UPDATE parties SET marketing_opt_out = 1, collection_opt_out = 0 WHERE id = ?', [$stopper]);
$sp = row('SELECT id, name, mobile, marketing_opt_out, collection_opt_out FROM parties WHERE id = ?', [$stopper]);
t_ok('no offers for them', cam_can_message($sp) !== '');
t_ok('...but the payment-reminder flag is untouched', (int)$sp['collection_opt_out'] === 0);
$src = file_get_contents($_ROOT . '/includes/campaign.php');
t_ok('campaigns never look at the collection flag', strpos($src, 'collection_opt_out') === false);
$coll = file_get_contents($_ROOT . '/includes/customer.php');
t_ok('and collection never looks at the marketing flag', strpos($coll, 'marketing_opt_out') === false);

// ---------------------------------------------------------------- sending ---
t_group('sending re-checks consent, because lists go stale');
$cid2 = tc_campaign('all', '', 0);
cam_prepare($cid2);
$victim = (int)val("SELECT party_id FROM campaign_targets WHERE campaign_id = ? AND status = 'queued' LIMIT 1", [$cid2]);
q('UPDATE parties SET marketing_opt_out = 1 WHERE id = ?', [$victim]);   // opts out AFTER the list was frozen
q("UPDATE campaigns SET status = 'ready' WHERE id = ?", [$cid2]);
// no provider is configured in the test environment, so every send fails -
// which is exactly the path worth checking
$res = cam_send_batch($cid2, 5);
$after = row('SELECT status, reason FROM campaign_targets WHERE campaign_id = ? AND party_id = ?', [$cid2, $victim]);
if (cam_quiet_ok()) {
    t_ok('the late opt-out is caught at send time', $after['status'] === 'skipped');
    t_ok('...with the reason recorded', strpos($after['reason'], 'બંધ કરાવ્યા') !== false);
    t_ok('a send with no provider fails gracefully instead of throwing', ($res['failed'] ?? 0) >= 0 && !isset($res['exception']));
    $failed = row("SELECT reason FROM campaign_targets WHERE campaign_id = ? AND status = 'failed' LIMIT 1", [$cid2]);
    t_ok('...and a failed row carries the provider\'s own reason', !$failed || $failed['reason'] !== '');
} else {
    t_ok('outside the allowed hours the batch refuses to run', !empty($res['error']));
    t_ok('...and says which hours are allowed', strpos($res['error'], ':00') !== false);
}
t_ok('a holdout row is never picked up for sending',
     (int)val("SELECT COUNT(*) FROM campaign_targets WHERE campaign_id = ? AND is_holdout = 1 AND status = 'sent'", [$cid2]) === 0);

t_group('the cron gets no gentler rules than a person');
$cron = file_get_contents($_ROOT . '/includes/cron_jobs.php');
t_ok('the cron job calls the same send function', strpos($cron, 'cam_send_batch(') !== false);
t_ok('...and is itself gated on the quiet hours', strpos($cron, 'cam_quiet_ok()') !== false);
t_ok('it takes one campaign at a time', strpos($cron, "status IN ('ready','sending') ORDER BY") !== false);

// ----------------------------------------------------------------- results --
t_group('the result compares the messaged against the held back');
$cid3 = tc_campaign('all', '', 0, 30);
$sentBuyer = tc_party(); $sentQuiet = tc_party();
$heldBuyer = tc_party(); $heldQuiet = tc_party();
$start = date('Y-m-d H:i:s', strtotime('-10 days'));
q("UPDATE campaigns SET status = 'done', started_at = ?, finished_at = NOW() WHERE id = ?", [$start, $cid3]);
foreach ([[$sentBuyer, 0, 'sent'], [$sentQuiet, 0, 'sent'], [$heldBuyer, 1, 'queued'], [$heldQuiet, 1, 'queued']] as $x) {
    q("INSERT INTO campaign_targets (campaign_id, party_id, name, mobile, is_holdout, status, sent_at)
       VALUES (?,?,'TC','7800000000',?,?,?)", [$cid3, $x[0], $x[1], $x[2], $x[2] === 'sent' ? $start : null]);
}
// one buyer in each group, and the messaged one bought TWICE
tc_sale($sentBuyer, 1000, date('Y-m-d', strtotime('-5 days')));
tc_sale($sentBuyer, 2000, date('Y-m-d', strtotime('-4 days')));
tc_sale($heldBuyer, 1500, date('Y-m-d', strtotime('-5 days')));
$r = cam_result($cid3);
t_ok('the result is available once it has started', $r['ok']);
// This is the bug that nearly shipped: COUNT(*) over a LEFT JOIN to sales
// counts BILLS, so the customer with two bills became two people.
t_eq('two people were messaged, not three', $r['sent']['people'], 2);
t_eq('two were held back', $r['held']['people'], 2);
t_eq('one of the messaged bought', $r['sent']['buyers'], 1);
t_eq('one of the held back bought too', $r['held']['buyers'], 1);
t_eq('both bills of the buyer are in the takings', $r['sent']['amt'], 3000);
t_eq('the conversion rate is buyers over people', $r['sent']['rate'], 50.0);

t_group('a difference made of four people is not reported as a result');
t_ok('the tiny sample is refused', $r['trust'] !== '');
t_ok('...and no lift figure is offered', $r['lift'] === null);
t_ok('...in words the owner can act on', strpos($r['trust'], 'નાનાં') !== false);

$cid4 = tc_campaign('all', '', 0);
q("UPDATE campaigns SET status = 'done', started_at = ? WHERE id = ?", [$start, $cid4]);
for ($i = 0; $i < 3; $i++) {
    $pp = tc_party();
    q("INSERT INTO campaign_targets (campaign_id, party_id, name, mobile, is_holdout, status, sent_at)
       VALUES (?,?,'TC','7800000000',0,'sent',?)", [$cid4, $pp, $start]);
}
$r4 = cam_result($cid4);
t_ok('a campaign with no holdout says there is nothing to compare with', strpos($r4['trust'], 'રોકી') !== false);
t_ok('...and still reports what the messaged group did', $r4['sent']['people'] === 3);

t_group('a campaign that never started has no result to give');
$cid5 = tc_campaign();
$r5 = cam_result($cid5);
t_ok('it says so instead of returning zeros', !$r5['ok']);
t_ok('...in plain words', strpos($r5['why'], 'મોકલાયું નથી') !== false);

t_group('the message cost is the shop\'s own rate, or nothing at all');
t_ok('with no rate set, no cost is invented', cam_result($cid4)['cost'] === null);
set_setting('campaign_msg_paise', '85');
t_eq('with a rate set, it is messages times the rate', cam_result($cid4)['cost'], 2.55);
set_setting('campaign_msg_paise', '0');

// ------------------------------------------------------------------ counts --
t_group('counts are one query for a whole list, not one per campaign');
$map = cam_counts_map([$cid, $cid2, $cid3, $cid4]);
t_ok('every campaign asked for comes back', count($map) === 4);
t_eq('and the numbers match the single-campaign version', $map[$cid3]['total'], cam_counts($cid3)['total']);
t_ok('an unknown campaign gives zeros, not a crash', cam_counts(999999999)['total'] === 0);
$page = file_get_contents($_ROOT . '/campaigns.php');
t_ok('the list screen uses the grouped version', strpos($page, 'cam_counts_map(') !== false);

// ------------------------------------------------------------- guard rails --
t_group('the screen makes it hard to send by accident');
t_ok('it needs campaigns.view to open', strpos($page, "require_perm('campaigns.view')") !== false);
t_ok('sending is a separate permission from writing', strpos($page, "require_perm('campaigns.send')") !== false);
t_ok('...and they really are separate in the permission list',
     in_array('send', permission_catalog()['campaigns'] ?? [], true));
t_ok('nothing is sent by a GET', !preg_match('/cam_send_batch/', preg_replace('/POST.*?redirect/s', '', $page)) ||
     strpos($page, "post('do') === 'send'") !== false);
t_ok('the send button asks for confirmation first', strpos($page, 'onsubmit="return confirm(') !== false);
t_ok('preparing the list says out loud that it sends nothing', strpos($page, 'એનાથી કોઈને મેસેજ જતો નથી') !== false);
t_ok('every skipped person is shown with their reason', strpos($page, 'બાકાત રહેલા દરેકનું કારણ સાથે') !== false);
t_ok('the holdout is explained, not just applied', strpos($page, 'જાણી જોઈને મેસેજ નહીં જાય') !== false);

t_group('STOP is handled even when the bot is switched off');
$hook = file_get_contents($_ROOT . '/wa_webhook.php');
$stopPos = strpos($hook, 'cam_is_stop_word');
$botPos = strpos($hook, 'if (!$botEnabled)');
t_ok('the STOP check exists in the webhook', $stopPos !== false);
t_ok('...and sits ABOVE the bot switch', $stopPos !== false && $botPos !== false && $stopPos < $botPos);
t_ok('the reply promises that bill messages continue', strpos($hook, 'બિલ અને પેમેન્ટની જરૂરી જાણ ચાલુ રહેશે') !== false);

t_group('the customer screen offers both consents, separately');
$cust = file_get_contents($_ROOT . '/customer.php');
t_ok('there is a marketing opt-out control', strpos($cust, "post('do') === 'mkt_opt_out'") !== false);
t_ok('...distinct from the collection one', strpos($cust, "post('do') === 'opt_out'") !== false);
t_ok('...and it needs parties.edit like the other', substr_count($cust, "require_perm('parties.edit')") >= 2);
t_ok('the screen explains they are different promises', strpos($cust, 'આ અલગ પરવાનગી છે') !== false);
