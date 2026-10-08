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

/** The month's slip for one person, worked out (not saved). */
function hr_payslip_calc(array $user, $month, $advance = 0, $other = 0) {
    $days = hr_month_days($user, $month);
    $dim = count($days);
    $paid = hr_paid_days($days);
    $salary = (float)$user['salary_monthly'];
    $earned = round($salary * $paid / max(1, $dim), 2);
    $commission = round(hr_sales_base($user['id'], $month) * (float)$user['commission_pct'] / 100, 2);
    $advance = max(0, round((float)$advance, 2)); $other = max(0, round((float)$other, 2));
    $count = array_count_values($days);
    return ['days_in_month' => $dim, 'paid_days' => $paid, 'salary' => $salary, 'earned' => $earned, 'commission' => $commission,
            'advance_deduct' => $advance, 'other_deduct' => $other, 'net' => max(0, round($earned + $commission - $advance - $other, 2)),
            'present' => $count['present'] ?? 0, 'half' => $count['half'] ?? 0, 'absent' => $count['absent'] ?? 0,
            'leave' => $count['leave'] ?? 0, 'unpaid' => $count['unpaid'] ?? 0, 'off' => $count['off'] ?? 0];
}

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
