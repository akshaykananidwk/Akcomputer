<?php
// 🔗 Live feeds for other apps, opened by a secret link (no login):
//   ?f=sales|items|dues  -> CSV for Google Sheets: =IMPORTDATA("<link>")
//   ?f=calendar          -> a calendar (.ics) for Google Calendar: "From URL"
// No customer phone numbers or addresses are in any feed. The link can be
// changed any time (Settings → Connections), which stops the old one.
require_once __DIR__ . '/includes/init.php';
$tok = (string)setting('feeds_token', '');
if ($tok === '' || !hash_equals($tok, (string)get('k')) || !api_rate_ok('feed:' . client_ip(), 120, 3600)) { http_response_code(404); exit('Not found'); }
$f = get('f');

if ($f === 'calendar') {
    $ev = [];
    $add = function ($date, $title, $id) use (&$ev) { if ($date) $ev[] = [$date, $title, $id]; };
    foreach (all("SELECT d.id, DATE(d.created_at) d, s.invoice_no, COALESCE(s.customer_name, '') c FROM deliveries d JOIN sales s ON s.id = d.sale_id WHERE d.status IN ('assigned','out')") as $r) $add($r['d'], '🚚 Deliver ' . $r['invoice_no'] . ($r['c'] ? ' — ' . $r['c'] : ''), 'dl' . $r['id']);
    foreach (all("SELECT id, title, next_bill_date FROM amc_contracts WHERE status = 'active' AND next_bill_date BETWEEN DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND DATE_ADD(CURDATE(), INTERVAL 120 DAY)") as $r) $add($r['next_bill_date'], '🔁 AMC bill: ' . $r['title'], 'amc' . $r['id']);
    foreach (all("SELECT id, title, due_date FROM follow_ups WHERE status = 'pending' AND due_date IS NOT NULL") as $r) $add($r['due_date'], '📞 ' . $r['title'], 'fu' . $r['id']);
    foreach (all("SELECT id, cheque_no, cheque_date, amount, direction FROM cheques WHERE status IN ('in_hand','deposited') AND cheque_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)") as $r) $add($r['cheque_date'], '🧾 Cheque ' . $r['cheque_no'] . ' ₹' . money($r['amount']) . ($r['direction'] === 'in' ? ' to deposit' : ' to be paid'), 'chq' . $r['id']);
    try { foreach (all("SELECT lp.id, lp.due_date, lp.emi, l.lender FROM loan_payments lp JOIN loans l ON l.id = lp.loan_id WHERE lp.paid_on IS NULL AND lp.due_date <= DATE_ADD(CURDATE(), INTERVAL 120 DAY)") as $r) $add($r['due_date'], '🏦 EMI ₹' . money($r['emi']) . ' — ' . $r['lender'], 'emi' . $r['id']); } catch (Exception $e) {}
    try { foreach (all("SELECT a.id, a.appt_date, a.appt_time, a.customer_name FROM appointments a WHERE a.status = 'booked' AND a.appt_date >= CURDATE()") as $r) $add($r['appt_date'], '📅 ' . substr($r['appt_time'], 0, 5) . ' ' . $r['customer_name'], 'ap' . $r['id']); } catch (Exception $e) {}
    header('Content-Type: text/calendar; charset=utf-8');
    $esc = fn($t) => str_replace(["\\", ';', ',', "\n"], ["\\\\", '\;', '\,', '\n'], $t);
    $host = request_host() ?: 'shop';
    echo "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//AK Shop//Calendar//EN\r\nX-WR-CALNAME:" . $esc(setting('app_name', 'Shop')) . "\r\n";
    foreach ($ev as [$d, $t, $id]) {
        $day = date('Ymd', strtotime($d));
        echo "BEGIN:VEVENT\r\nUID:$id@$host\r\nDTSTAMP:" . gmdate('Ymd\THis\Z') . "\r\nDTSTART;VALUE=DATE:$day\r\nDTEND;VALUE=DATE:" . date('Ymd', strtotime($d . ' +1 day')) . "\r\nSUMMARY:" . $esc($t) . "\r\nEND:VEVENT\r\n";
    }
    echo "END:VCALENDAR\r\n"; exit;
}

$rows = [];
if ($f === 'sales') {
    $rows[] = ['Date', 'Bills', 'Sales', 'Paid at the time', 'GST'];
    foreach (all("SELECT sale_date, COUNT(*) n, SUM(total) t, SUM(paid) p, SUM(tax_amount) g FROM sales WHERE is_cancelled = 0 AND sale_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY) GROUP BY sale_date ORDER BY sale_date DESC") as $r)
        $rows[] = [$r['sale_date'], $r['n'], round($r['t'], 2), round($r['p'], 2), round($r['g'], 2)];
} elseif ($f === 'items') {
    $rows[] = ['Item', 'Brand', 'Selling price', 'Stock'];
    foreach (all("SELECT i.name, i.brand, i.selling_price, COALESCE(SUM(s.qty), 0) q FROM items i LEFT JOIN stock s ON s.item_id = i.id WHERE i.is_active = 1 GROUP BY i.id, i.name, i.brand, i.selling_price ORDER BY i.name") as $r)
        $rows[] = [$r['name'], $r['brand'], round($r['selling_price'], 2), +$r['q']];
} elseif ($f === 'dues') {
    $rows[] = ['Party', 'Balance (+ they owe us, - we owe them)'];
    foreach (all('SELECT p.name, ' . party_balance_expr('p') . ' bal FROM parties p WHERE p.is_active = 1 HAVING ABS(bal) > 0.009 ORDER BY bal DESC') as $r) $rows[] = [$r['name'], round($r['bal'], 2)];
} else { http_response_code(404); exit('Not found'); }
header('Content-Type: text/csv; charset=utf-8');
$out = fopen('php://output', 'w');
foreach ($rows as $r) fputcsv($out, array_map(fn($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'" . $v : $v, $r));   // a name can never become a formula
