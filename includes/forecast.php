<?php
// ============================================================================
//  FORECASTING — and an honest account of how wrong it usually is
// ============================================================================
//  A forecast is a guess. The dangerous thing about putting one on a screen is
//  that a number in a box looks like a fact, and the owner will spend real
//  money against it. So the rule for this whole file is: never show a
//  prediction without showing how badly the same method has missed before.
//
//  That is what fc_backtest() is for. It re-runs this exact method against
//  months that have already happened - using ONLY the data that existed before
//  each of those months - and compares what it would have said with what
//  actually occurred. The average error that comes out of that is not
//  decoration: it IS the range shown around the forecast. If the method has
//  been 20% out, the screen says "between X and Y", not "X".
//
//  The cash projection is a different kind of thing and mostly not a guess at
//  all: the bills exist, the amounts are in the ledger, the due dates are
//  written down. The one judgement in it is WHEN a customer will actually pay,
//  and even that is taken from their own record - a customer who has paid 12
//  days late for two years is expected to be 12 days late again. That is
//  arithmetic over their history, not optimism.
//
//  Nothing here is an "AI forecast". No model, no external service, no
//  hidden weights - a weighted average, a seasonal index built from whole
//  months of the shop's own trade, and a measured error bar.
// ============================================================================

function fc_rules() {
    // deliberately not cached in a static - see the Phase 6 kill-switch lesson
    return [
        'weeks'        => 12,   // how far the cash projection runs
        'base_months'  => 6,    // months of history behind the sales forecast
        'backtest'     => 6,    // months re-forecast to measure the real error
        'min_months'   => 4,    // below this there is nothing worth forecasting
        'recur_months' => 6,    // window for spotting a recurring expense
        'recur_hits'   => 4,    // ...and how many of those months it must appear in
        'floor'        => (float)setting('cash_floor', 0),   // "low" is not always zero
        'max_delay'    => 120,  // a customer 4 months late is not "expected", they are a problem
    ];
}

// ---------------------------------------------------------------- cash in --

/** Cash and bank, right now. The starting point of every projection. */
function fc_opening_cash() {
    $cash = total_cash_in_hand();
    $bank = 0.0;
    foreach (all('SELECT id FROM bank_accounts WHERE is_active = 1') as $b) $bank += bank_account_balance((int)$b['id']);
    return ['cash' => money_r($cash), 'bank' => money_r($bank), 'total' => money_r($cash + $bank)];
}

/** How many days late each customer actually pays, from their own settled
 *  bills. One query for everybody - the per-party version in customer.php is
 *  fine for one screen and ruinous for four hundred.
 *
 *  This is the only judgement in the cash projection, and it is the customer's
 *  own record making it. */
function fc_delay_map() {
    $r = fc_rules();
    $rows = all("SELECT s.party_id,
                        AVG(GREATEST(DATEDIFF(pd.paid_on, COALESCE(s.due_date, s.sale_date)), 0)) avg_late,
                        COUNT(*) bills
                 FROM sales s
                 JOIN (SELECT pa.ref_id, MAX(p.pay_date) paid_on
                       FROM payment_allocations pa JOIN payments p ON p.id = pa.payment_id
                       WHERE pa.ref_type = 'sale' GROUP BY pa.ref_id) pd ON pd.ref_id = s.id
                 WHERE s.is_cancelled = 0 AND s.status = 'paid' AND s.party_id IS NOT NULL
                   AND s.sale_date >= DATE_SUB(CURDATE(), INTERVAL 730 DAY)
                 GROUP BY s.party_id
                 HAVING bills >= 2");
    $out = [];
    foreach ($rows as $x) $out[(int)$x['party_id']] = min($r['max_delay'], (int)round((float)$x['avg_late']));
    return $out;
}

/** Every listed party's ledger balance in ONE query, on the side asked for.
 *
 *  money_cap_bill_dues() calls party_balance_side() per party, which is right
 *  for a screen showing one page of bills and ruinous here: two thousand open
 *  bills across two hundred and fifty customers cost 280 queries. The capping
 *  RULE is untouched - money_trim_dues() still does the trimming - only the
 *  balance lookup is batched. */
function fc_balance_map(array $partyIds, $dir) {
    if (!$partyIds) return [];
    $in = implode(',', array_map('intval', array_unique($partyIds)));
    $out = [];
    foreach (all('SELECT p.id, ' . party_balance_expr('p') . ' bal FROM parties p WHERE p.id IN (' . $in . ')') as $x) {
        $bal = (float)$x['bal'];
        $out[(int)$x['id']] = $dir === 'in' ? max(0.0, $bal) : max(0.0, -$bal);
    }
    return $out;
}

/** money_cap_bill_dues() with batched balances. Same rule, same ordering, same
 *  answer - proven equal to the original before this was used anywhere. */
function fc_cap_dues(array $bills, $dir) {
    $byParty = [];
    foreach ($bills as $i => $b) if (!empty($b['party_id'])) $byParty[(int)$b['party_id']][] = $i;
    $bal = fc_balance_map(array_keys($byParty), $dir);
    foreach ($byParty as $pid => $idxs) {
        $dues = [];
        foreach ($idxs as $i) $dues[$i] = $bills[$i]['total'] - $bills[$i]['paid'];
        foreach (money_trim_dues($dues, $bal[$pid] ?? 0.0) as $i => $adj) $bills[$i]['adj_due'] = $adj;
    }
    return array_values(array_filter($bills, fn($b) => money_r($b['adj_due'] ?? ($b['total'] - $b['paid'])) > MONEY_EPS));
}

/** Money expected IN, week by week.
 *
 *  Every bill is capped by the ledger first, so this can never expect more
 *  than the customer actually owes - the same rule the reminders and the
 *  Payments screen use. Then each bill is moved to the date that customer
 *  usually pays on, not the date printed on it.
 *
 *  Money that is ALREADY late is deliberately kept OUT of the weekly figures
 *  and reported on its own. A bill 600 days overdue is not "arriving next
 *  week", and dropping it into week 0 would have the projection promise the
 *  owner a fortune that is not coming - the single most dishonest thing a cash
 *  forecast can do. Collecting it is a separate job, with its own screen. */
function fc_receivables($weeks = null) {
    $r = fc_rules();
    $weeks = (int)($weeks ?: $r['weeks']);
    $bills = all("SELECT id, party_id, invoice_no, sale_date, due_date, total, paid
                  FROM sales WHERE status <> 'paid' AND is_cancelled = 0
                  ORDER BY due_date IS NULL, due_date, id");
    $bills = fc_cap_dues($bills, 'in');
    $delay = fc_delay_map();

    $out = []; $overdue = 0.0; $overdueN = 0; $noParty = 0.0; $beyond = 0.0; $rows = [];
    foreach ($bills as $b) {
        $amt = money_r($b['adj_due'] ?? ($b['total'] - $b['paid']));
        if ($amt <= MONEY_EPS) continue;
        if (empty($b['party_id'])) { $noParty += $amt; continue; }   // walk-in: nobody to chase
        $late = $delay[(int)$b['party_id']] ?? 0;
        $base = $b['due_date'] ?: $b['sale_date'];
        $when = date('Y-m-d', strtotime($base . ' +' . $late . ' days'));
        if ($when < today()) { $overdue += $amt; $overdueN++; continue; }   // late already - not a prediction
        $wk = fc_week_index($when);
        if ($wk >= $weeks) { $beyond += $amt; continue; }
        $out[$wk] = ($out[$wk] ?? 0) + $amt;
        $rows[] = ['id' => $b['id'], 'invoice_no' => $b['invoice_no'], 'party_id' => (int)$b['party_id'],
                   'amount' => $amt, 'due_date' => $b['due_date'], 'expected' => $when, 'late_days' => $late, 'week' => $wk];
    }
    return ['weeks' => $out, 'rows' => $rows, 'overdue' => money_r($overdue), 'overdue_bills' => $overdueN,
            'walkin' => money_r($noParty), 'beyond' => money_r($beyond)];
}

/** Which week from today a date falls in. Week 0 is the next 7 days. */
function fc_week_index($date) {
    $d = (int)floor((strtotime($date) - strtotime(today())) / 86400);
    return (int)floor(max(0, $d) / 7);
}

function fc_week_label($i) {
    $from = date('Y-m-d', strtotime('+' . ($i * 7) . ' days'));
    $to = date('Y-m-d', strtotime('+' . ($i * 7 + 6) . ' days'));
    return dmy($from) . ' – ' . dmy($to);
}

// --------------------------------------------------------------- cash out --

/** Money expected OUT on supplier bills. No behavioural adjustment here: when
 *  the shop pays is the shop's own choice, so the due date stands. */
function fc_payables($weeks = null) {
    $r = fc_rules();
    $weeks = (int)($weeks ?: $r['weeks']);
    $bills = all("SELECT id, party_id, bill_no, purchase_date, due_date, total, paid
                  FROM purchases WHERE status <> 'paid' AND is_cancelled = 0
                  ORDER BY due_date IS NULL, due_date, id");
    $bills = fc_cap_dues($bills, 'out');
    $out = []; $rows = []; $overdue = 0.0;
    foreach ($bills as $b) {
        $amt = money_r($b['adj_due'] ?? ($b['total'] - $b['paid']));
        if ($amt <= MONEY_EPS) continue;
        $when = $b['due_date'] ?: $b['purchase_date'];
        // A supplier bill already past its date is different from a customer
        // one: it is still owed and still has to be paid, and the shop chooses
        // when. It goes into week 0 because that is when it is payable NOW.
        if ($when < today()) { $overdue += $amt; $when = today(); }
        $wk = fc_week_index($when);
        if ($wk >= $weeks) continue;
        $out[$wk] = ($out[$wk] ?? 0) + $amt;
        $rows[] = ['id' => $b['id'], 'bill_no' => $b['bill_no'], 'party_id' => (int)$b['party_id'],
                   'amount' => $amt, 'due_date' => $b['due_date'], 'week' => $wk];
    }
    return ['weeks' => $out, 'rows' => $rows, 'overdue' => money_r($overdue)];
}

/** Expenses that clearly repeat - rent, salary, internet - found by looking
 *  for a category that appears in most of the last few months.
 *
 *  The projected amount is the MEDIAN of those months, not the average: one
 *  freak month (a deposit, an annual payment) would drag an average up and
 *  quietly inflate every future week. */
function fc_recurring() {
    $r = fc_rules();
    $from = date('Y-m-01', strtotime('-' . $r['recur_months'] . ' months'));
    $rows = all("SELECT category, DATE_FORMAT(exp_date, '%Y-%m') m, SUM(amount) amt, MIN(DAY(exp_date)) day
                 FROM expenses WHERE exp_date >= ? AND exp_date < DATE_FORMAT(CURDATE(), '%Y-%m-01')
                 GROUP BY category, m ORDER BY category, m", [$from]);
    $byCat = [];
    foreach ($rows as $x) {
        $c = (string)$x['category'];
        $byCat[$c]['amts'][] = (float)$x['amt'];
        $byCat[$c]['days'][] = (int)$x['day'];
    }
    $out = [];
    foreach ($byCat as $cat => $d) {
        if (count($d['amts']) < $r['recur_hits']) continue;   // not regular enough to count on
        $amts = $d['amts']; sort($amts);
        $mid = intdiv(count($amts), 2);
        $median = count($amts) % 2 ? $amts[$mid] : ($amts[$mid - 1] + $amts[$mid]) / 2;
        $days = $d['days']; sort($days);
        $out[] = ['category' => $cat, 'amount' => money_r($median), 'months' => count($amts),
                  'day' => $days[intdiv(count($days), 2)]];
    }
    usort($out, fn($a, $b) => $b['amount'] <=> $a['amount']);
    return $out;
}

/** Spread the recurring expenses across the projection weeks, on the day of
 *  the month each one usually lands. */
function fc_recurring_weeks($weeks) {
    $recur = fc_recurring();
    $out = [];
    if (!$recur) return $out;
    for ($w = 0; $w < $weeks; $w++) {
        $from = strtotime('+' . ($w * 7) . ' days');
        $to = strtotime('+' . ($w * 7 + 6) . ' days');
        foreach ($recur as $e) {
            // does this expense's usual day fall inside this week?
            for ($t = $from; $t <= $to; $t += 86400) {
                if ((int)date('j', $t) === (int)$e['day']) { $out[$w] = ($out[$w] ?? 0) + $e['amount']; break; }
            }
        }
    }
    return $out;
}

/** The projection: opening balance, then week by week in and out, and the
 *  running balance that comes out of it. */
function fc_cashflow($weeks = null) {
    $r = fc_rules();
    $weeks = max(1, min(26, (int)($weeks ?: $r['weeks'])));
    $open = fc_opening_cash();
    $in = fc_receivables($weeks);
    $out = fc_payables($weeks);
    $recur = fc_recurring_weeks($weeks);

    $bal = $open['total'];
    $rows = []; $danger = [];
    for ($w = 0; $w < $weeks; $w++) {
        $i = money_r($in['weeks'][$w] ?? 0);
        $o = money_r(($out['weeks'][$w] ?? 0) + ($recur[$w] ?? 0));
        $bal = money_r($bal + $i - $o);
        $rows[] = ['week' => $w, 'label' => fc_week_label($w), 'in' => $i, 'out' => $o,
                   'net' => money_r($i - $o), 'balance' => $bal,
                   'bills' => money_r($out['weeks'][$w] ?? 0), 'fixed' => money_r($recur[$w] ?? 0)];
        if ($bal < $r['floor']) $danger[] = ['week' => $w, 'label' => fc_week_label($w), 'balance' => $bal];
    }
    return [
        'opening' => $open, 'rows' => $rows, 'danger' => $danger, 'weeks' => $weeks,
        'total_in' => money_r(array_sum(array_column($rows, 'in'))),
        'total_out' => money_r(array_sum(array_column($rows, 'out'))),
        'closing' => $bal, 'floor' => $r['floor'],
        'overdue_in' => $in['overdue'], 'overdue_bills' => $in['overdue_bills'],
        'overdue_out' => $out['overdue'], 'walkin' => $in['walkin'], 'beyond' => $in['beyond'],
        'recurring' => fc_recurring(),
    ];
}

// ------------------------------------------------------------ sales ahead --

/** Completed calendar months of sales, oldest first. The current month is
 *  excluded everywhere - half a month is not a data point. */
/** Every month the shop has ever traded, in one query.
 *
 *  The backtest re-forecasts six months, and each of those forecasts wants its
 *  own slice of history plus five years of seasonal data - which used to be a
 *  fresh GROUP BY over the sales table every single time. Measured at 45,000
 *  bills that was 672ms for one screen. Fetching the series ONCE and slicing it
 *  in PHP is the same arithmetic against the same rows.
 *
 *  Deliberately NOT held in a static: a caller that inserts a sale and then
 *  forecasts in the same request must see it. It is passed down by argument
 *  instead, so the sharing is visible in the call and cannot go stale. */
function fc_all_month_sales() {
    $rows = all("SELECT DATE_FORMAT(sale_date, '%Y-%m') m, COALESCE(SUM(total),0) amt
                 FROM sales WHERE is_cancelled = 0 GROUP BY m ORDER BY m");
    $out = [];
    foreach ($rows as $x) $out[$x['m']] = money_r($x['amt']);
    return $out;
}

/** The months in [before - N months, before), from a pre-fetched series when
 *  one is handed in and from the database when it is not. */
function fc_slice_months(array $series, $months, $before) {
    $from = date('Y-m', strtotime($before . ' -' . (int)$months . ' months'));
    $to = date('Y-m', strtotime($before . ' -1 month'));
    $out = [];
    foreach ($series as $ym => $amt) if ($ym >= $from && $ym <= $to) $out[$ym] = $amt;
    return $out;
}

function fc_month_sales($months = 24, $before = null, array $series = null) {
    if ($series !== null) return fc_slice_months($series, $months, $before ?: date('Y-m-01'));
    $before = $before ?: date('Y-m-01');
    $from = date('Y-m-01', strtotime($before . ' -' . (int)$months . ' months'));
    $rows = all("SELECT DATE_FORMAT(sale_date, '%Y-%m') m, COALESCE(SUM(total),0) amt
                 FROM sales WHERE is_cancelled = 0 AND sale_date >= ? AND sale_date < ?
                 GROUP BY m ORDER BY m", [$from, $before]);
    $out = [];
    foreach ($rows as $x) $out[$x['m']] = money_r($x['amt']);
    return $out;
}

/** The seasonal multiplier for one calendar month, from whole years only.
 *  Returns 1.0 (no adjustment) when there is not enough history to know -
 *  which is honest: an unknown season should change nothing. */
function fc_season_factor($monthNo, $before = null, array $series = null) {
    $hist = fc_month_sales(60, $before, $series);
    if (count($hist) < 24) return ['factor' => 1.0, 'years' => 0, 'why' => 'બે વર્ષથી ઓછો ઇતિહાસ — સીઝનનો ગુણાકાર લગાડ્યો નથી.'];
    $byMonth = []; $all = [];
    foreach ($hist as $ym => $amt) {
        $byMonth[(int)substr($ym, 5, 2)][] = $amt;
        $all[] = $amt;
    }
    $mean = array_sum($all) / count($all);
    if ($mean <= 0 || empty($byMonth[$monthNo])) return ['factor' => 1.0, 'years' => 0, 'why' => 'આ મહિનાનો ઇતિહાસ નથી.'];
    $mAvg = array_sum($byMonth[$monthNo]) / count($byMonth[$monthNo]);
    return ['factor' => round($mAvg / $mean, 3), 'years' => count($byMonth[$monthNo]), 'why' => ''];
}

/** One month's forecast: a weighted average of recent months, nudged by that
 *  month's seasonal factor. Exposed on its own so the backtest can call the
 *  SAME function against the past - a forecast method that is not the one you
 *  tested is not a tested method. */
function fc_predict_month($targetYm, $before = null, array $series = null) {
    $r = fc_rules();
    $hist = fc_month_sales($r['base_months'], $before, $series);
    if (count($hist) < $r['min_months']) return null;
    $base = weighted_forecast(array_values($hist));
    $season = fc_season_factor((int)substr($targetYm, 5, 2), $before, $series);
    return ['month' => $targetYm, 'base' => money_r($base),
            'factor' => $season['factor'], 'years' => $season['years'], 'season_why' => $season['why'],
            'value' => money_r($base * $season['factor']), 'from_months' => count($hist)];
}

/** Re-run the forecast against months that have already happened, using only
 *  what was known before each one, and report how far out it was.
 *
 *  This is the point of the whole file. Without it the screen is a confident
 *  number with nothing behind it. */
function fc_backtest($months = null) {
    $r = fc_rules();
    $months = (int)($months ?: $r['backtest']);
    $series = fc_all_month_sales();          // once, for every month scored below
    $rows = []; $errs = [];
    for ($i = $months; $i >= 1; $i--) {
        $target = month_start($i);
        $ym = date('Y-m', strtotime($target));
        $actual = $series[$ym] ?? null;
        if ($actual === null) continue;                 // no trade that month, nothing to score
        // $target is the cut-off: fc_month_sales() slices strictly before it,
        // so this forecast still cannot see the month it is being scored on
        $p = fc_predict_month($ym, $target, $series);   // knows nothing after $target
        if (!$p || $actual <= 0) continue;
        $err = round(abs($p['value'] - $actual) / $actual * 100, 1);
        $errs[] = $err;
        $rows[] = ['month' => $ym, 'predicted' => $p['value'], 'actual' => money_r($actual),
                   'diff' => money_r($p['value'] - $actual), 'err_pct' => $err];
    }
    $mape = $errs ? round(array_sum($errs) / count($errs), 1) : null;
    return ['rows' => $rows, 'mape' => $mape, 'n' => count($rows),
            'worst' => $errs ? max($errs) : null, 'best' => $errs ? min($errs) : null];
}

/** Next months, each as a RANGE built from the method's own measured error.
 *  Refuses outright when there is not enough history to test against. */
function fc_sales_ahead($ahead = 3) {
    $r = fc_rules();
    $hist = fc_month_sales($r['base_months']);
    if (count($hist) < $r['min_months']) {
        return ['ok' => false, 'rows' => [], 'backtest' => null,
                'why' => 'અનુમાન કરવા માટે ઓછામાં ઓછા ' . $r['min_months'] . ' પૂરા મહિનાનો ઇતિહાસ જોઈએ. અત્યારે ' . count($hist) . ' છે.'];
    }
    $bt = fc_backtest();
    $band = $bt['mape'];   // null when the method has never been scored
    $series = fc_all_month_sales();
    $rows = [];
    for ($i = 0; $i < max(1, (int)$ahead); $i++) {
        $ym = date('Y-m', strtotime('+' . $i . ' months'));
        $p = fc_predict_month($ym, null, $series);
        if (!$p) continue;
        $p['low'] = $band === null ? null : money_r($p['value'] * (1 - $band / 100));
        $p['high'] = $band === null ? null : money_r($p['value'] * (1 + $band / 100));
        $p['partial'] = $i === 0;   // this month is already part-spent
        $rows[] = $p;
    }
    return ['ok' => true, 'rows' => $rows, 'backtest' => $bt, 'band' => $band, 'why' => ''];
}

/** This month so far, and what the rest of it needs to look like to reach a
 *  target. Pure arithmetic - no forecasting in it at all. */
function fc_target($goal = 0) {
    $goal = max(0.0, (float)$goal);
    $sofar = money_r((float)val("SELECT COALESCE(SUM(total),0) FROM sales
                                 WHERE is_cancelled = 0 AND sale_date >= ?", [date('Y-m-01')]));
    $days = (int)date('t');
    $done = (int)date('j');
    $left = max(0, $days - $done);
    $pace = $done > 0 ? money_r($sofar / $done) : 0.0;
    return [
        'goal' => money_r($goal), 'sofar' => $sofar, 'days' => $days, 'done' => $done, 'left' => $left,
        'per_day_so_far' => $pace,
        'at_this_pace' => money_r($pace * $days),
        'needed' => $goal > 0 ? money_r(max(0, $goal - $sofar)) : 0.0,
        'per_day_needed' => ($goal > 0 && $left > 0) ? money_r(max(0, $goal - $sofar) / $left) : 0.0,
    ];
}

/** Headline numbers, cached - the screen and the dashboard both want them. */
function fc_summary() {
    $val = dash_cache('forecast_summary', 900, function () {
        $cf = fc_cashflow();
        $sa = fc_sales_ahead(1);
        $first = $cf['danger'][0] ?? null;
        return [
            'opening' => $cf['opening']['total'],
            'closing' => $cf['closing'],
            'total_in' => $cf['total_in'],
            'total_out' => $cf['total_out'],
            'danger_weeks' => count($cf['danger']),
            'danger_label' => $first['label'] ?? '',
            'danger_balance' => $first['balance'] ?? 0.0,
            'next_month' => $sa['ok'] && $sa['rows'] ? $sa['rows'][0]['value'] : 0.0,
            'band' => $sa['ok'] ? $sa['band'] : null,
            'forecast_ok' => (bool)$sa['ok'],
        ];
    });
    // JSON turns 2.0 into 2; pin the money fields so a cached read is identical
    foreach (['opening', 'closing', 'total_in', 'total_out', 'danger_balance', 'next_month'] as $k) $val[$k] = (float)$val[$k];
    if ($val['band'] !== null) $val['band'] = (float)$val['band'];
    return $val;
}
