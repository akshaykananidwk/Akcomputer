<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); die('CLI only'); }
// Forecasting.
//
// A forecast is a guess, so the tests are mostly about the guard rails around
// it: that money already overdue is never promised as arriving, that the range
// comes from the method's measured error rather than a made-up number, that a
// backtest can only see data older than the month it is scoring, and that thin
// history produces a refusal instead of a confident figure.
$_SESSION['user_id'] = (int)val("SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id
                                 WHERE r.permissions LIKE '%*%' AND u.is_active = 1 ORDER BY u.id LIMIT 1");
$_ROOT = dirname(__DIR__);
$fLoc = (int)val('SELECT id FROM locations ORDER BY id LIMIT 1');
$fCo  = (int)val('SELECT id FROM companies ORDER BY id LIMIT 1');
set_setting('cash_floor', '0');

/** A credit sale with an explicit due date, so the projection has something
 *  to place on the calendar. */
function tf_bill($partyId, $total, $saleDate, $dueDate, $paid = 0) {
    global $fCo, $fLoc;
    q("INSERT INTO sales (company_id, location_id, party_id, customer_name, invoice_no, sale_date, due_date,
        subtotal, total, paid, payment_mode, status, created_by)
       VALUES (?,?,?, 'TF', ?, ?, ?, ?, ?, ?, 'credit', ?, 1)",
      [$fCo, $fLoc, $partyId, 'TF-' . bin2hex(random_bytes(4)), $saleDate, $dueDate, $total, $total, $paid,
       payment_status($total, $paid)]);
    return insert_id();
}

$d = fn($days) => date('Y-m-d', strtotime(($days >= 0 ? '+' : '') . $days . ' days'));

// ------------------------------------------------------------- the weeks --
t_group('the calendar is cut into weeks from today');
t_eq('today is week 0', fc_week_index(today()), 0);
t_eq('six days out is still week 0', fc_week_index($d(6)), 0);
t_eq('seven days out is week 1', fc_week_index($d(7)), 1);
t_eq('twenty days out is week 2', fc_week_index($d(20)), 2);
t_eq('a date in the past is clamped to week 0', fc_week_index($d(-40)), 0);

// ------------------------------------------------------- the capping rule --
t_group('the batched cap gives exactly the old answer');
// money_cap_bill_dues() asks the database for a balance per party. That is one
// query each, and this screen looks at every open bill in the shop - so the
// balances are fetched in one sweep instead. The RULE must not move.
$capParty = t_party();
tf_bill($capParty, 5000, $d(-60), $d(-30));
tf_bill($capParty, 3000, $d(-20), $d(5));
t_payment($capParty, 4000);   // paid, never linked to a bill
$open = all("SELECT id, party_id, invoice_no, sale_date, due_date, total, paid FROM sales
             WHERE party_id = ? AND status <> 'paid' AND is_cancelled = 0
             ORDER BY due_date IS NULL, due_date, id", [$capParty]);
$old = money_cap_bill_dues($open, 'in');
$new = fc_cap_dues($open, 'in');
t_ok('the two agree row for row', $old === $new);
t_ok('...and the unlinked payment really did trim the oldest bill',
     count($old) >= 1 && $old[0]['adj_due'] < 5000);
t_ok('an empty list is safe', fc_cap_dues([], 'in') === []);
t_ok('so is a bill with no party', count(fc_cap_dues([['id' => 1, 'party_id' => null, 'total' => 100, 'paid' => 0]], 'in')) === 1);

// ------------------------------------------------------------- money in ---
t_group('money already late is never promised as arriving');
$lateParty = t_party();
tf_bill($lateParty, 20000, $d(-400), $d(-370));   // 370 days overdue
$soonParty = t_party();
tf_bill($soonParty, 7000, $d(-5), $d(10));        // genuinely due in ten days

$rec = fc_receivables(12);
$weekTotal = array_sum($rec['weeks']);
t_ok('the long-overdue bill is counted as overdue', $rec['overdue'] >= 20000);
t_ok('...and at least one bill is reported in that bucket', $rec['overdue_bills'] >= 1);
// This is the one that matters: dropping overdue money into week 0 would have
// the projection announce a fortune that is not coming.
$inWeek0 = $rec['weeks'][0] ?? 0;
t_ok('...but it is NOT sitting in week 0 as expected income', $inWeek0 < $rec['overdue']);
$found = false;
foreach ($rec['rows'] as $r) if ((int)$r['party_id'] === $lateParty) $found = true;
t_ok('...and it is not in the week-by-week rows at all', !$found);
$foundSoon = false;
foreach ($rec['rows'] as $r) if ((int)$r['party_id'] === $soonParty) { $foundSoon = true; $soonRow = $r; }
t_ok('a bill genuinely due soon IS in the rows', $foundSoon);
t_eq('...in the right week', $soonRow['week'], 1);

t_group('a bill beyond the horizon is reported, not silently dropped');
$farParty = t_party();
tf_bill($farParty, 9000, today(), $d(200));
$rec2 = fc_receivables(4);          // a four-week window
t_ok('it is counted as beyond the horizon', $rec2['beyond'] >= 9000);
$inRows = false;
foreach ($rec2['rows'] as $r) if ((int)$r['party_id'] === $farParty) $inRows = true;
t_ok('...and not in the weekly rows', !$inRows);

t_group('a walk-in bill has nobody to chase');
$walk = tf_bill(null, 4000, today(), $d(5));
q('UPDATE sales SET party_id = NULL WHERE id = ?', [$walk]);
$rec3 = fc_receivables(12);
t_ok('it lands in its own bucket', $rec3['walkin'] >= 4000);

t_group('when a customer pays is taken from when they HAVE paid');
$slow = t_party();
// two settled bills, each paid 20 days after the due date
foreach ([[-200, -170], [-120, -90]] as $k => $pair) {
    $b = tf_bill($slow, 5000, $d($pair[0]), $d($pair[1]), 5000);
    q("INSERT INTO payments (party_id, direction, amount, mode, pay_date, notes, created_by)
       VALUES (?, 'in', 5000, 'cash', ?, 'test', 1)", [$slow, $d($pair[1] + 20)]);
    q('INSERT INTO payment_allocations (payment_id, ref_type, ref_id, amount) VALUES (?, "sale", ?, 5000)',
      [insert_id(), $b]);
}
$delay = fc_delay_map();
t_ok('this customer is known to run late', isset($delay[$slow]));
t_eq('...by about twenty days', $delay[$slow], 20);
// so a bill of theirs due in 3 days is expected in 23, which is week 3
tf_bill($slow, 6000, today(), $d(3));
$rec4 = fc_receivables(12);
$slowRow = null;
foreach ($rec4['rows'] as $r) if ((int)$r['party_id'] === $slow) $slowRow = $r;
t_ok('their new bill appears', $slowRow !== null);
t_eq('...carrying their usual delay', $slowRow['late_days'], 20);
t_eq('...and placed at the date they actually pay, not the printed one', $slowRow['week'], 3);
t_ok('...which is later than the due date alone would give', $slowRow['week'] > fc_week_index($d(3)));

t_group('a customer with no payment record is taken at their word');
$fresh = t_party();
tf_bill($fresh, 3000, today(), $d(10));
$rec5 = fc_receivables(12);
$freshRow = null;
foreach ($rec5['rows'] as $r) if ((int)$r['party_id'] === $fresh) $freshRow = $r;
t_ok('their bill appears', $freshRow !== null);
t_eq('...with no delay assumed', $freshRow['late_days'], 0);

// ------------------------------------------------------- recurring costs --
t_group('a recurring cost is the middle month, not the average');
$cat = 'TFRENT_' . bin2hex(random_bytes(3));
// five months at 10000 and one freak month at 100000: the average would be
// 25000 and would inflate every future week
foreach ([1, 2, 3, 4, 5] as $m)
    q("INSERT INTO expenses (exp_date, category, amount, mode, location_id, created_by) VALUES (?, ?, 10000, 'cash', ?, 1)",
      [date('Y-m-08', strtotime(month_start($m))), $cat, $fLoc]);
q("INSERT INTO expenses (exp_date, category, amount, mode, location_id, created_by) VALUES (?, ?, 100000, 'cash', ?, 1)",
  [date('Y-m-08', strtotime(month_start(6))), $cat, $fLoc]);
$recur = fc_recurring();
$mine = null;
foreach ($recur as $e) if ($e['category'] === $cat) $mine = $e;
t_ok('the recurring cost is found', $mine !== null);
t_eq('...at the median, not the mean', $mine['amount'], 10000);
t_eq('...on the day it usually falls', $mine['day'], 8);

t_group('a one-off expense is not treated as recurring');
$once = 'TFONCE_' . bin2hex(random_bytes(3));
q("INSERT INTO expenses (exp_date, category, amount, mode, location_id, created_by) VALUES (?, ?, 50000, 'cash', ?, 1)",
  [date('Y-m-05', strtotime(month_start(2))), $once, $fLoc]);
$found2 = false;
foreach (fc_recurring() as $e) if ($e['category'] === $once) $found2 = true;
t_ok('one month out of six is not a pattern', !$found2);

// ---------------------------------------------------------- the projection --
t_group('the projection adds up');
$cf = fc_cashflow(8);
t_eq('it runs for the weeks asked for', count($cf['rows']), 8);
$bal = $cf['opening']['total'];
$ok = true;
foreach ($cf['rows'] as $w) {
    $bal = money_r($bal + $w['in'] - $w['out']);
    if (abs($bal - $w['balance']) > 0.01) $ok = false;
}
t_ok('every running balance is the one before it plus in minus out', $ok);
t_eq('the closing balance is the last row', $cf['closing'], $cf['rows'][7]['balance']);
t_eq('the totals are the sum of the weeks', $cf['total_in'], money_r(array_sum(array_column($cf['rows'], 'in'))));
t_ok('the horizon is capped at something sensible', count(fc_cashflow(999)['rows']) <= 26);
t_ok('...and has a floor too', count(fc_cashflow(0)['rows']) >= 1);

t_group('a week below the floor is flagged');
set_setting('cash_floor', '99999999999999');
$cf2 = fc_cashflow(4);
t_ok('every week is now a danger week', count($cf2['danger']) === 4);
t_ok('...and the first one is named', $cf2['danger'][0]['label'] !== '');
set_setting('cash_floor', '0');

// ------------------------------------------------------------- forecasting --
t_group('a month is forecast from history and season, or not at all');
$p = fc_predict_month(date('Y-m', strtotime('+1 month')));
if ($p) {
    t_ok('the forecast is a real number', $p['value'] > 0);
    t_ok('it says how many months it used', $p['from_months'] >= fc_rules()['min_months']);
    t_ok('the seasonal factor is a multiplier near 1', $p['factor'] > 0.2 && $p['factor'] < 5);
    t_eq('the value is the base times that factor', $p['value'], money_r($p['base'] * $p['factor']));
} else {
    t_ok('with too little history it returns nothing at all', $p === null);
}

t_group('the backtest may only see what came before');
$bt = fc_backtest(6);
t_ok('it scored some months', $bt['n'] >= 0);
foreach ($bt['rows'] as $r) {
    t_ok('each scored month has both numbers (' . $r['month'] . ')', $r['predicted'] > 0 && $r['actual'] > 0);
    t_eq('the error is the gap over the actual (' . $r['month'] . ')',
         $r['err_pct'], round(abs($r['predicted'] - $r['actual']) / $r['actual'] * 100, 1));
}
if ($bt['n'] > 0) {
    t_ok('the average error is between the best and the worst',
         $bt['mape'] >= $bt['best'] - 0.05 && $bt['mape'] <= $bt['worst'] + 0.05);
}
// The whole point: a forecast tested against data it already knew would be
// meaninglessly good. fc_predict_month() is given a cut-off date and
// fc_month_sales() only ever reads BEFORE it.
$hist = fc_month_sales(24, month_start(3));
$latest = $hist ? max(array_keys($hist)) : '';
t_ok('history before a cut-off stops before that month',
     $latest === '' || $latest < month_key(3), 'latest = ' . $latest);
t_ok('the current month is never in the history',
     !array_key_exists(date('Y-m'), fc_month_sales(24)));

t_group('the range comes from the measured error, not from thin air');
$sa = fc_sales_ahead(3);
if ($sa['ok']) {
    t_ok('a band is offered', $sa['band'] === null || $sa['band'] >= 0);
    t_ok('the backtest is carried along with it', is_array($sa['backtest']));
    t_eq('the band IS the backtest average', $sa['band'], $sa['backtest']['mape']);
    foreach ($sa['rows'] as $r) {
        if ($r['low'] === null) continue;
        t_ok('the low end is below the estimate (' . $r['month'] . ')', $r['low'] <= $r['value']);
        t_ok('the high end is above it (' . $r['month'] . ')', $r['high'] >= $r['value']);
        t_eq('the low end is exactly value minus the measured error (' . $r['month'] . ')',
             $r['low'], money_r($r['value'] * (1 - $sa['band'] / 100)));
    }
    t_ok('this month is marked as already running', $sa['rows'][0]['partial'] === true);
    t_ok('next month is not', count($sa['rows']) < 2 || $sa['rows'][1]['partial'] === false);
}

t_group('too little history is a refusal, not a guess');
// fc_sales_ahead refuses below min_months; prove the gate exists and is used
$src = file_get_contents($_ROOT . '/includes/forecast.php');
t_ok('the minimum is checked before forecasting', strpos($src, "count(\$hist) < \$r['min_months']") !== false);
t_ok('...and the refusal says how many months there are', strpos($src, 'પૂરા મહિનાનો ઇતિહાસ જોઈએ') !== false);
$season = fc_season_factor(1, month_start(100));
t_eq('an unknown season multiplies by one, changing nothing', $season['factor'], 1.0);
t_ok('...and says why', $season['why'] !== '');

// ---------------------------------------------------------------- target --
t_group('the target tracker is arithmetic, not forecasting');
$tg = fc_target(1000000);
t_eq('the month length is right', $tg['days'], (int)date('t'));
t_eq('the day count is right', $tg['done'], (int)date('j'));
t_eq('days left is the rest of the month', $tg['left'], (int)date('t') - (int)date('j'));
t_eq('the pace is sales so far over days so far', $tg['per_day_so_far'], money_r($tg['sofar'] / max(1, $tg['done'])));
t_eq('at this pace is the pace times the whole month', $tg['at_this_pace'], money_r($tg['per_day_so_far'] * $tg['days']));
$tg0 = fc_target(0);
t_eq('with no goal there is nothing needed', $tg0['needed'], 0);
t_eq('...and no daily requirement', $tg0['per_day_needed'], 0);
$tgBig = fc_target($tg['sofar'] + 90000);
if ($tgBig['left'] > 0) t_eq('what is needed per day is the shortfall over the days left',
    $tgBig['per_day_needed'], money_r(90000 / $tgBig['left']));

// --------------------------------------------------------------- summary --
t_group('the cached summary reads back exactly as written');
dash_cache_forget('forecast_summary');
$cold = fc_summary();
$warm = fc_summary();
unset($cold['cached_at'], $warm['cached_at']);
t_ok('cold and warm are identical, types included', $cold === $warm);
t_ok('the opening balance is a number', is_float($cold['opening']));
t_ok('danger weeks is never negative', $cold['danger_weeks'] >= 0);

// ----------------------------------------------------------- guard rails --
t_group('the screen says what it will not promise');
$page = file_get_contents($_ROOT . '/forecast.php');
t_ok('it needs reports.view', strpos($page, "require_perm('reports.view')") !== false);
t_ok('...and payments.view on top', strpos($page, "!can('payments.view')") !== false);
t_ok('it warns that a forecast is a guess', strpos($page, 'અનુમાન એ ધારણા છે, હકીકત નથી') !== false);
t_ok('it lists what the cash figure excludes', strpos($page, 'આમાં જે ગણ્યું <b>નથી</b>') !== false);
t_ok('...naming overdue money as excluded', strpos($page, 'પહેલેથી મોડા') !== false);
t_ok('...and future sales as excluded', strpos($page, 'હજી ન થયેલું વેચાણ') !== false);
t_ok('the range is linked to the accuracy record', strpos($page, "tab=accuracy") !== false);
t_ok('a badly performing method is called out', strpos($page, "\$bt['mape'] > 25") !== false);
t_ok('the screen writes nothing but its own settings',
     !preg_match('/\b(INSERT INTO|DELETE FROM)\b/i', $page));
t_ok('the data layer only reads', !preg_match('/\b(INSERT INTO|UPDATE\s+\w+\s+SET|DELETE FROM)\b/i', $src));
t_ok('no AI is involved', strpos($src, 'ai_ask') === false && strpos($page, 'ai_ask') === false);
$idx = file_get_contents($_ROOT . '/index.php');
t_ok('the dashboard card only shows on a real danger week', strpos($idx, "\$f['danger_weeks'] > 0") !== false);
t_ok('...behind the money permission', strpos($idx, "if (\$seeMoney && can('reports.view'))") !== false);
t_ok('...and repeats that overdue money was not counted', strpos($idx, 'મુદત વીતી ગયેલી ઉઘરાણી ગણી નથી') !== false);
