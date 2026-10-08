<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); die('CLI only'); }
// Salary is money: the same attendance must always give the same pay.
//   paid days = present + half x 0.5 + approved paid leave + weekly offs
//   earned = salary x paid days / days in month;  net = earned + commission - deductions

t_group('Payroll: attendance into pay');
q("INSERT INTO users (name, mobile, username, password, role_id, location_id, is_active, salary_monthly, commission_pct, weekly_off, joined_on)
   VALUES ('HR Test', '9000000001', ?, 'x', (SELECT MIN(id) FROM roles), (SELECT MIN(id) FROM locations), 1, 30000, 2, 0, '2026-01-01')", ['hrtest' . mt_rand(1000, 9999)]);
$hid = insert_id();
$hu = row('SELECT * FROM users WHERE id = ?', [$hid]);
for ($d = 1; $d <= 5; $d++) q("INSERT INTO attendance (user_id, att_date, in_at) VALUES (?, ?, ?)", [$hid, "2026-09-0$d", "2026-09-0$d 10:00:00"]);
q("INSERT INTO attendance (user_id, att_date, in_at, status) VALUES (?, '2026-09-07', '2026-09-07 10:00:00', 'half')", [$hid]);
q("INSERT INTO attendance (user_id, att_date, status) VALUES (?, '2026-09-11', 'absent')", [$hid]);
q("INSERT INTO leave_requests (user_id, from_date, to_date, paid, status) VALUES (?, '2026-09-08', '2026-09-09', 1, 'approved'), (?, '2026-09-10', '2026-09-10', 0, 'approved'), (?, '2026-09-14', '2026-09-15', 1, 'pending')", [$hid, $hid, $hid]);
$days = hr_month_days($hu, '2026-09');
t_eq('September has 30 days', count($days), 30);
t_eq('Sundays are the weekly off', [$days['2026-09-06'], $days['2026-09-13'], $days['2026-09-27']], ['off', 'off', 'off']);
t_eq('a checked-in day is present', $days['2026-09-01'], 'present');
t_eq('the owner\'s "half" wins over the check-in', $days['2026-09-07'], 'half');
t_eq('approved paid leave counts', $days['2026-09-08'], 'leave');
t_eq('leave without pay does not', $days['2026-09-10'], 'unpaid');
t_eq('leave still waiting for a yes is absent', $days['2026-09-14'], 'absent');
t_eq('paid days: 5 present + 0.5 half + 2 leave + 4 offs', hr_paid_days($days), 11.5);

$ps = t_party('HR sale party'); $sid = t_sale($ps, 10000, 10000, '2026-09-12');
q('UPDATE sales SET created_by = ?, tax_amount = 0 WHERE id = ?', [$hid, $sid]);
$c = hr_payslip_calc($hu, '2026-09', 1000, 0);
t_eq('earned = 30000 x 11.5 / 30', $c['earned'], 11500);
t_eq('commission = 2% of sales before GST', $c['commission'], 200);
t_eq('net = earned + commission - advance', $c['net'], 10700);
t_eq('a cancelled bill earns no commission', (function () use ($hu, $sid) { q('UPDATE sales SET is_cancelled = 1 WHERE id = ?', [$sid]); return hr_payslip_calc($hu, '2026-09')['commission']; })(), 0);
t_eq('deductions never make pay negative', hr_payslip_calc($hu, '2026-09', 999999, 0)['net'], 0);
t_eq('days before joining are not paid', hr_paid_days(hr_month_days(array_merge($hu, ['joined_on' => '2026-09-20']), '2026-09')), 2.0);

t_group('Payroll: the owner hears about big payments');
$keepAlert = setting('big_payment_alert', '0');
set_setting('big_payment_alert', '0'); t_ok('off by default', big_payment_alert(500000, 'X') === false);
set_setting('big_payment_alert', '50000'); t_ok('below the amount: quiet', big_payment_alert(49999, 'X') === false);
set_setting('big_payment_alert', $keepAlert);
