<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); die('CLI only'); }
// ============================================================================
//  ATTENDANCE RULES: hourly pay with a monthly raise, penalties, emergency
//  leave with a certificate, and bike km for petrol. Salary is money - every
//  rule is checked on real rows, with the figures worked out by hand.
// ============================================================================
t_group('Monthly raise: ₹32/hour becomes ₹33 next month, automatically');
$base = ['pay_type' => 'hourly', 'hourly_rate' => 32, 'salary_monthly' => 0, 'raise_amount' => 1, 'raise_from' => '2026-09', 'raise_max' => 0];
t_eq('in the starting month: ₹32', hr_rate($base, '2026-09'), 32);
t_eq('next month: ₹33', hr_rate($base, '2026-10'), 33);
t_eq('a year later: ₹44', hr_rate($base, '2027-09'), 44);
t_eq('before the starting month it is never lowered below the base', hr_rate($base, '2026-05'), 32);
t_eq('it stops at the limit the owner set', hr_rate(['raise_max' => 40] + $base, '2027-09'), 40);
t_eq('a monthly salary can rise the same way (+₹500 a month)', hr_rate(['pay_type' => 'monthly', 'salary_monthly' => 9000, 'raise_amount' => 500, 'raise_from' => '2026-09', 'raise_max' => 0], '2026-11'), 10000);
t_eq('no raise set: the rate stays', hr_rate(['raise_amount' => 0] + $base, '2027-09'), 32);

t_group('Penalties — off until switched on, then by the rules');
q("INSERT INTO users (name, mobile, username, password, role_id, location_id, is_active, pay_type, hourly_rate, shift_start, shift_end, weekly_off, joined_on, bike_allowed, petrol_rate)
   VALUES ('Rule Test', '9000000002', ?, 'x', (SELECT MIN(id) FROM roles), (SELECT MIN(id) FROM locations), 1, 'hourly', 32, '09:00:00', '19:00:00', 0, '2026-01-01', 1, 3)", ['rt' . mt_rand(1000, 9999)]);
$rid = (int)val('SELECT MAX(id) FROM users');
$ru = row('SELECT * FROM users WHERE id = ?', [$rid]);
$in = function ($d, $from, $to = null) use ($rid) { q('INSERT INTO attendance (user_id, att_date, in_at, out_at) VALUES (?,?,?,?)', [$rid, $d, "$d $from", $to ? "$d $to" : null]); };
$in('2026-09-01', '09:00:00', '19:00:00');      // on time, 10 h
$in('2026-09-02', '09:50:00', '19:00:00');      // 50 minutes late: inside the hour, no penalty, 9h10m
$in('2026-09-03', '10:05:00', '19:05:00');      // after 10:00 → late penalty, 9 h
// 4th: absent, no leave → absent penalty
// 6th: Sunday = weekly off
q("INSERT INTO leave_requests (user_id, from_date, to_date, paid, status, kind) VALUES (?, '2026-09-08', '2026-09-08', 0, 'approved', 'planned')", [$rid]);
q("INSERT INTO leave_requests (user_id, from_date, to_date, paid, status, kind, certificate) VALUES (?, '2026-09-09', '2026-09-09', 0, 'approved', 'emergency', 'cert_x.jpg')", [$rid]);
q("INSERT INTO leave_requests (user_id, from_date, to_date, paid, status, kind) VALUES (?, '2026-09-10', '2026-09-10', 0, 'pending', 'emergency')", [$rid]);
$keep = setting('hr_rules_from', '');
set_setting('hr_rules_from', '');
t_eq('rules off: no penalty at all', hr_month_calc($ru, '2026-09')['penalty_hours'], 0);
set_setting('hr_rules_from', '2026-09-02');
foreach (['hr_min_hours' => 10, 'hr_late_after' => 60, 'hr_late_hours' => 5, 'hr_absent_hours' => 20, 'hr_noout_hours' => 0, 'hr_leave_notice' => 2, 'hr_petrol_rate' => 2] as $k => $v) set_setting($k, (string)$v);
$m = hr_month_calc($ru, '2026-09');
$p = fn($d) => $m['days'][$d]['penalty'];
t_eq('on time: no penalty, 10 hours', [$p('2026-09-01'), $m['days']['2026-09-01']['hours']], [0.0, 10.0]);
t_eq('50 minutes late (inside the hour): no penalty', $p('2026-09-02'), 0);
t_eq('in after 10:00: 5 hours\' penalty', $p('2026-09-03'), 5);
t_eq('away with no leave and no certificate: 20 hours', $p('2026-09-04'), 20);
t_eq('weekly off (Sunday): nothing', [$m['days']['2026-09-06']['st'], $p('2026-09-06')], ['off', 0.0]);
t_eq('planned leave taken in time: nothing', $p('2026-09-08'), 0);
t_eq('emergency leave WITH a certificate: nothing', $p('2026-09-09'), 0);
t_eq('emergency leave with NO certificate: counts as absent, 20 hours', $p('2026-09-10'), 20);
t_eq('days before the rules were switched on are never charged', $p('2026-09-01'), 0);
q("INSERT INTO hr_penalties (user_id, pen_date, kind, reason) VALUES (?, '2026-09-04', 'waive', 'was in hospital')", [$rid]);
q("INSERT INTO hr_penalties (user_id, pen_date, kind, hours, reason) VALUES (?, '2026-09-05', 'manual', 2, 'phone off on duty')", [$rid]);
$m = hr_month_calc($ru, '2026-09');
t_eq('the owner let one day go: that day is 0', $m['days']['2026-09-04']['penalty'], 0);
t_eq('...and a penalty by hand is added on its day', $m['days']['2026-09-05']['penalty'], 22);
t_ok('every penalty says why', strpos($m['days']['2026-09-03']['why'], 'late') !== false && strpos($m['days']['2026-09-05']['why'], 'phone off') !== false);

t_group('Hourly pay, penalty and petrol on the slip');
$hours = $m['hours'];
t_eq('hours worked: 10 + 9h10m + 9 = 28.17', $hours, 28.17);
q("INSERT INTO bike_trips (user_id, started_at, ended_at, km, status) VALUES (?, '2026-09-03 11:00:00', '2026-09-03 12:00:00', 12.5, 'done'),
   (?, '2026-09-05 11:00:00', '2026-09-05 12:00:00', 7.5, 'done'), (?, '2026-09-07 11:00:00', NULL, 99, 'running')", [$rid, $rid, $rid]);
q('UPDATE bike_trips SET adj_km = 10 WHERE user_id = ? AND km = 12.5', [$rid]);
t_eq('bike km of the month: the owner\'s correction wins, a running trip is not counted', hr_month_km($rid, '2026-09'), 17.5);
$c = hr_payslip_calc($ru, '2026-09');
t_eq('earned = hours x ₹32', $c['earned'], round(28.17 * 32, 2));
// 3rd late 5 + 5th by hand 2 + 10th no certificate 20 + 18 more days away with nothing recorded
// (7th and 11th-30th, less the Sundays 13, 20, 27) x 20 = 360 → 407 hours
t_eq('penalty: 5 late + 2 by hand + 20 (no certificate) + 18 days away x 20 = 407 hours', $c['penalty_hours'], 407);
t_eq('...at ₹32 an hour', $c['penalty_amt'], 407 * 32);
t_eq('petrol = 17.5 km x this person\'s ₹3', $c['petrol'], 52.5);
t_eq('net = earned + petrol − penalty, never below 0 (here the penalties are bigger)', $c['net'], max(0, round(28.17 * 32 + 52.5 - 407 * 32, 2)));
t_eq('the shop\'s ₹/km is used when the person has none', hr_petrol_rate(['bike_allowed' => 1, 'petrol_rate' => null]), 2);
t_eq('no bike allowance: no petrol', hr_petrol_rate(['bike_allowed' => 0, 'petrol_rate' => 5]), 0);
$mon = row('SELECT * FROM users WHERE id = ?', [$rid]); $mon['pay_type'] = 'monthly'; $mon['salary_monthly'] = 26000; $mon['raise_amount'] = 0;
$cm = hr_payslip_calc($mon, '2026-09');
t_eq('monthly salary: a penalty hour = salary ÷ working days ÷ 10', $cm['penalty_amt'], round(407 * 26000 / (30 - 4) / 10, 2));

t_group('Leave rules and GPS km');
t_eq('planned leave: on the 17th the earliest day is the 19th', hr_leave_earliest(), date('Y-m-d', strtotime(today() . ' +2 days')));
$pt = fn($sec, $lat, $lng, $acc = 10) => ['at' => date('Y-m-d H:i:s', strtotime('2026-09-03 11:00:00') + $sec), 'lat' => $lat, 'lng' => $lng, 'acc' => $acc];
// 0.009° of latitude ≈ 1.0 km
t_ok('1 km north is counted as about 1 km', abs(hr_trip_km([$pt(0, 22.2400, 69.0000), $pt(120, 22.2490, 69.0000)]) - 1.0) < 0.02);
t_eq('standing still (2 m wobble) adds nothing', hr_trip_km([$pt(0, 22.24, 69.0), $pt(60, 22.24002, 69.0)]), 0);
t_eq('a fix with 300 m accuracy is skipped', hr_trip_km([$pt(0, 22.24, 69.0), $pt(60, 22.30, 69.0, 300)]), 0);
t_eq('a jump no bike makes (6 km in 10 s) is dropped', hr_trip_km([$pt(0, 22.24, 69.0), $pt(10, 22.294, 69.0)]), 0);
set_setting('hr_rules_from', $keep);
$src = file_get_contents(__DIR__ . '/../leaves.php');
t_ok('planned leave sooner than the notice is refused', strpos($src, "if (\$f < hr_leave_earliest())") !== false);
t_ok('a certificate is private (served only to its owner or HR)', strpos($src, "Require all denied") !== false && strpos($src, "!can('hr.view')") !== false);
