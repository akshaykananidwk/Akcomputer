<?php
// The team: attendance, leave and the month's salary. Every rupee here is
// worked out the same way every time, from what was recorded:
//   paid days = days present + half days x 0.5 + approved paid leave + weekly offs
//   earned    = monthly salary x paid days / days in the month
//   net       = earned + commission - advance taken - other deduction (never below 0)
// Paying a slip writes ONE ordinary "Salary & Wages" expense - the books,
// cash and bank then follow exactly the rules every expense follows.

/** Each day of a month for one person: date => present / half / absent / leave / unpaid / off / future. */
function hr_month_days(array $user, $month) {
    $first = $month . '-01';
    $days = (int)date('t', strtotime($first));
    $att = [];
    foreach (all('SELECT att_date, in_at, status FROM attendance WHERE user_id = ? AND att_date BETWEEN ? AND ?',
                 [$user['id'], $first, "$month-$days"]) as $a) $att[$a['att_date']] = $a;
    $leave = [];
    foreach (all("SELECT from_date, to_date, paid FROM leave_requests WHERE user_id = ? AND status = 'approved' AND from_date <= ? AND to_date >= ?",
                 [$user['id'], "$month-$days", $first]) as $l)
        for ($t = strtotime(max($l['from_date'], $first)); $t <= strtotime(min($l['to_date'], "$month-$days")); $t += 86400)
            $leave[date('Y-m-d', $t)] = $l['paid'] ? 'leave' : 'unpaid';
    $out = [];
    for ($d = 1; $d <= $days; $d++) {
        $date = sprintf('%s-%02d', $month, $d);
        if ($date > today()) { $out[$date] = 'future'; continue; }
        if (!empty($user['joined_on']) && $date < $user['joined_on']) { $out[$date] = 'unpaid'; continue; }
        if (isset($att[$date]) && $att[$date]['status'] !== 'present') $out[$date] = $att[$date]['status'];   // the owner's word wins
        elseif (isset($att[$date]) && $att[$date]['in_at']) $out[$date] = 'present';
        elseif (isset($leave[$date])) $out[$date] = $leave[$date];
        elseif ($user['weekly_off'] !== null && $user['weekly_off'] !== '' && (int)date('w', strtotime($date)) === (int)$user['weekly_off']) $out[$date] = 'off';
        else $out[$date] = 'absent';
    }
    return $out;
}

/** The days counted for pay out of a day map (future days count as worked only when $wholeMonth). */
function hr_paid_days(array $days, $wholeMonth = false) {
    $n = 0.0;
    foreach ($days as $s) {
        if (in_array($s, ['present', 'leave', 'off'], true)) $n += 1;
        elseif ($s === 'half') $n += 0.5;
        elseif ($s === 'future' && $wholeMonth) $n += 1;
    }
    return $n;
}

/** Sales made by one person in a month, before GST - what commission is paid on. */
function hr_sales_base($userId, $month) {
    return (float)val("SELECT COALESCE(SUM(total - tax_amount), 0) FROM sales WHERE created_by = ? AND is_cancelled = 0 AND DATE_FORMAT(sale_date, '%Y-%m') = ?", [$userId, $month]);
}

/** The month's slip for one person, worked out (not saved).
 *  Monthly pay: salary x paid days / days in month. Hourly pay: hours worked x
 *  the month's rate (paid leave counts as the minimum hours of a day). Either
 *  way: + commission + petrol for bike km - penalty hours x the hourly rate
 *  - advance - other, never below 0. The rate is the month's own (a monthly
 *  raise never rewrites an older month). */
function hr_payslip_calc(array $user, $month, $advance = 0, $other = 0) {
    $m = hr_month_calc($user, $month);
    $days = array_map(fn($d) => $d['st'], $m['days']);
    $dim = count($days);
    $paid = hr_paid_days($days);
    $rules = hr_rules();
    $hourly = ($user['pay_type'] ?? 'monthly') === 'hourly';
    $rate = hr_rate($user, $month);
    $count = array_count_values($days);
    if ($hourly) {
        $earned = round(($m['hours'] + ($count['leave'] ?? 0) * $rules['min_hours']) * $rate, 2);
        $perHour = $rate;
    } else {
        $earned = round($rate * $paid / max(1, $dim), 2);
        $perHour = $rate / max(1, $dim - ($count['off'] ?? 0)) / max(1, $rules['min_hours']);
    }
    $penaltyAmt = round($m['penalty_hours'] * $perHour, 2);
    $km = hr_month_km($user['id'], $month);
    $petrol = round($km * hr_petrol_rate($user), 2);
    $commission = round(hr_sales_base($user['id'], $month) * (float)$user['commission_pct'] / 100, 2);
    $advance = max(0, round((float)$advance, 2)); $other = max(0, round((float)$other, 2));
    return ['days_in_month' => $dim, 'paid_days' => $paid, 'salary' => $hourly ? 0.0 : $rate, 'earned' => $earned, 'commission' => $commission,
            'pay_type' => $hourly ? 'hourly' : 'monthly', 'rate' => $rate, 'hours' => $m['hours'],
            'penalty_hours' => $m['penalty_hours'], 'penalty_amt' => $penaltyAmt, 'km' => $km, 'petrol' => $petrol,
            'advance_deduct' => $advance, 'other_deduct' => $other,
            'net' => max(0, round($earned + $commission + $petrol - $penaltyAmt - $advance - $other, 2)),
            'present' => $count['present'] ?? 0, 'half' => $count['half'] ?? 0, 'absent' => $count['absent'] ?? 0,
            'leave' => $count['leave'] ?? 0, 'unpaid' => $count['unpaid'] ?? 0, 'off' => $count['off'] ?? 0];
}

// ---------------------------------------------------------------------------
// ATTENDANCE RULES - penalties, hours, the monthly raise and bike km.
// Everything is worked out from what was recorded, the same way every time;
// nothing here writes. Penalties count only from the day the owner switched
// the rules on (hr_rules_from), so turning them on never reaches back.
// ---------------------------------------------------------------------------

/** The owner's rules (Team -> Attendance rules). */
function hr_rules() {
    $n = fn($k, $d) => max(0, (float)setting($k, (string)$d));
    return ['from' => (string)setting('hr_rules_from', ''),                 // '' = penalties off
            'min_hours' => $n('hr_min_hours', 10),                            // a full day's work
            'late_after' => (int)$n('hr_late_after', 60),                     // minutes after the shift start
            'late_hours' => $n('hr_late_hours', 5),                           // penalty for coming late
            'absent_hours' => $n('hr_absent_hours', 20),                      // penalty for a day away without leave or certificate
            'noout_hours' => $n('hr_noout_hours', 0),                         // penalty for forgetting to check out
            'notice_days' => (int)$n('hr_leave_notice', 2),                   // leave must be asked this many days ahead
            'petrol_rate' => $n('hr_petrol_rate', 0)];                         // ₹ per km on the bike
}

/** Whole months from $from (YYYY-MM) to $to (YYYY-MM); 0 when $to is not later. */
function hr_months_between($from, $to) {
    if (!preg_match('/^\d{4}-\d{2}$/', (string)$from) || !preg_match('/^\d{4}-\d{2}$/', (string)$to)) return 0;
    [$fy, $fm] = array_map('intval', explode('-', $from)); [$ty, $tm] = array_map('intval', explode('-', $to));
    return max(0, ($ty - $fy) * 12 + $tm - $fm);
}

/** The rate in force in a month: hourly ₹/hour or monthly salary, plus the
 *  raise for every month since raise_from (32 → 33 → 34...), up to raise_max. */
function hr_rate(array $user, $month) {
    $base = ($user['pay_type'] ?? 'monthly') === 'hourly' ? (float)($user['hourly_rate'] ?? 0) : (float)($user['salary_monthly'] ?? 0);
    $rate = $base + (float)($user['raise_amount'] ?? 0) * hr_months_between($user['raise_from'] ?? '', $month);
    if ((float)($user['raise_max'] ?? 0) > 0) $rate = min($rate, (float)$user['raise_max']);
    return round($rate, 2);
}

/** ₹ per km for this person's bike (their own rate, else the shop's). */
function hr_petrol_rate(array $user) {
    if (empty($user['bike_allowed'])) return 0.0;
    return $user['petrol_rate'] !== null && $user['petrol_rate'] !== '' ? (float)$user['petrol_rate'] : hr_rules()['petrol_rate'];
}

/** Every day of a month with its status, hours worked and penalty hours (and why). */
function hr_month_calc(array $user, $month) {
    $rules = hr_rules();
    $states = hr_month_days($user, $month);
    $first = "$month-01"; $last = date('Y-m-t', strtotime($first));
    $att = [];
    foreach (all('SELECT * FROM attendance WHERE user_id = ? AND att_date BETWEEN ? AND ?', [$user['id'], $first, $last]) as $a) $att[$a['att_date']] = $a;
    $waived = []; $manual = [];
    foreach (all('SELECT * FROM hr_penalties WHERE user_id = ? AND pen_date BETWEEN ? AND ?', [$user['id'], $first, $last]) as $p) {
        if ($p['kind'] === 'waive') $waived[$p['pen_date']] = $p['reason'];
        else $manual[$p['pen_date']][] = $p;
    }
    $now = time(); $today = today();
    $out = []; $hours = 0.0; $pen = 0.0;
    foreach ($states as $date => $st) {
        $a = $att[$date] ?? null;
        $h = 0.0; $flag = '';
        if ($a && $a['in_at'] && in_array($st, ['present', 'half'], true)) {
            $end = $a['out_at'] ? strtotime($a['out_at'])
                 : ($date < $today && !empty($user['shift_end']) ? strtotime("$date {$user['shift_end']}") : ($date === $today ? $now : null));
            if (!$a['out_at'] && $date < $today) $flag = 'no check-out';
            if ($end) $h = max(0, round(($end - strtotime($a['in_at'])) / 3600, 2));
        }
        $p = 0.0; $why = [];
        $ruled = $rules['from'] !== '' && $date >= $rules['from'] && $date <= $today && $st !== 'future'
                 && (empty($user['joined_on']) || $date >= $user['joined_on']);
        if ($ruled) {
            $start = !empty($user['shift_start']) ? strtotime("$date {$user['shift_start']}") + $rules['late_after'] * 60 : null;
            if ($st === 'absent' && $date < $today) { $p = $rules['absent_hours']; $why[] = 'absent without leave or certificate'; }
            elseif ($a && $a['in_at'] && $start && strtotime($a['in_at']) > $start) { $p = $rules['late_hours']; $why[] = 'late (in at ' . date('h:i A', strtotime($a['in_at'])) . ')'; }
            elseif ($st === 'absent' && $date === $today && $start && $now > $start) { $p = $rules['late_hours']; $why[] = 'not in yet'; }
            if ($flag && $rules['noout_hours'] > 0) { $p += $rules['noout_hours']; $why[] = 'forgot to check out'; }
            if (isset($waived[$date]) && $p > 0) { $why = ['waived: ' . $waived[$date]]; $p = 0.0; }
        }
        foreach ($manual[$date] ?? [] as $mp) { $p += (float)$mp['hours']; $why[] = $mp['reason'] ?: 'penalty'; }
        $out[$date] = ['st' => $st, 'hours' => $h, 'penalty' => round($p, 2), 'why' => implode('; ', $why), 'flag' => $flag, 'in' => $a['in_at'] ?? null, 'out' => $a['out_at'] ?? null];
        $hours += $h; $pen += $p;
    }
    return ['days' => $out, 'hours' => round($hours, 2), 'penalty_hours' => round($pen, 2)];
}

/** Distance of a trip in km from its GPS points: points worse than 60 m are
 *  skipped, and so are jumps faster than 130 km/h (a bad fix) and wobbles
 *  under 5 m while standing still. */
function hr_trip_km(array $points) {
    $km = 0.0; $prev = null;
    foreach ($points as $p) {
        if ((int)($p['acc'] ?? 0) > 60) continue;
        if ($prev) {
            $la1 = deg2rad((float)$prev['lat']); $la2 = deg2rad((float)$p['lat']);
            $dLa = $la2 - $la1; $dLo = deg2rad((float)$p['lng'] - (float)$prev['lng']);
            $d = 2 * 6371 * asin(min(1, sqrt(sin($dLa / 2) ** 2 + cos($la1) * cos($la2) * sin($dLo / 2) ** 2)));
            $secs = max(1, strtotime($p['at']) - strtotime($prev['at']));
            if ($d * 1000 < 5) continue;                       // standing still: keep the earlier point
            if ($d / ($secs / 3600) > 130) continue;           // a jump no bike makes: drop this fix
            $km += $d;
        }
        $prev = $p;
    }
    return round($km, 2);
}

/** Km ridden in a month (finished trips; the owner's corrected km wins). */
function hr_month_km($userId, $month) {
    return round((float)val("SELECT COALESCE(SUM(COALESCE(adj_km, km)), 0) FROM bike_trips WHERE user_id = ? AND status = 'done' AND DATE_FORMAT(started_at, '%Y-%m') = ?", [$userId, $month]), 2);
}

/** The earliest day a planned leave can start ("2 days ahead": on the 17th, the 19th). */
function hr_leave_earliest() { return date('Y-m-d', strtotime(today() . ' +' . hr_rules()['notice_days'] . ' days')); }

/** Has this person checked in today? Late if after the shift start + 10 minutes. */
function hr_today(array $user) {
    $a = row('SELECT * FROM attendance WHERE user_id = ? AND att_date = ?', [$user['id'], today()]);
    $late = $a && $a['in_at'] && !empty($user['shift_start']) && strtotime($a['in_at']) > strtotime(today() . ' ' . $user['shift_start']) + 600;
    return ['row' => $a, 'late' => $late];
}

/** Tell the owner on WhatsApp and Telegram (a smartwatch shows the phone's notification). */
function owner_alert($msg) {
    try { $no = wa_normalize_number(setting('wa_shop_number')); if ($no) send_whatsapp($no, $msg); } catch (Throwable $e) {}
    try { if (function_exists('tg_notify_admins') && setting('tg_bot_token', '') !== '') tg_notify_admins($msg); } catch (Throwable $e) {}
}

/** A payment big enough that the owner wants to know at once (Settings → amount; 0 = off). */
function big_payment_alert($amount, $who, $what = 'Payment received') {
    $min = (float)setting('big_payment_alert', '0');
    if ($min <= 0 || (float)$amount < $min) return false;
    owner_alert("💰 $what: ₹" . money($amount) . ($who !== '' ? " from $who" : '') . ' — ' . date('d-m h:i A'));
    return true;
}

/** The tab bar on top of every HR screen - each person sees only the tabs they may open. */
function hr_tabs($active) {
    $u = current_user();
    $tabs = [['attendance', 'attendance.php', 'clock', 'Attendance'], ['leave', 'leaves.php', 'calendar', 'Leave']];
    if (!empty($u['bike_allowed']) || can('hr.view')) $tabs[] = ['bike', 'bike.php', 'bike', 'Bike'];
    if (can('hr.payroll')) $tabs[] = ['payroll', 'payroll.php', 'rupee', 'Payroll'];
    if (can('hr.view')) { $tabs[] = ['team', 'team.php?tab=shifts', 'users', 'Staff pay']; $tabs[] = ['rules', 'team.php?tab=rules', 'sliders', 'Rules']; $tabs[] = ['more', 'team.php?tab=perf', 'bar-chart', 'More']; }
    $h = '<nav class="hr-tabs no-print" aria-label="Team">';
    foreach ($tabs as [$k, $href, $ic, $label])
        $h .= '<a class="hr-tab' . ($k === $active ? ' on' : '') . '" href="' . e($href) . '"' . ($k === $active ? ' aria-current="page"' : '') . '>' . icon($ic, 22) . '<span>' . e($label) . '</span></a>';
    // the open tab is always in view, even when the bar scrolls sideways on a phone
    return $h . '</nav><script>(function(){var t=document.querySelector(".hr-tabs .hr-tab.on");if(t&&t.scrollIntoView)t.scrollIntoView({block:"nearest",inline:"center"});})();</script>';
}

/** Initials for a round avatar. */
function hr_initials($name) {
    $p = preg_split('/\s+/', trim((string)$name)) ?: ['?'];
    return mb_strtoupper(mb_substr($p[0], 0, 1) . (isset($p[1]) ? mb_substr($p[1], 0, 1) : ''));
}
