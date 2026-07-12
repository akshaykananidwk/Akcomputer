<?php
// Auto overdue payment reminders (run via hosting cron, e.g. daily 10:00)
//   Browser/wget : https://your-site/cron.php?key=YOUR_CRON_KEY
//   CLI          : php /path/to/cron.php YOUR_CRON_KEY
// Finds unpaid bills past their due date and WhatsApps the reminder template;
// repeats every N days (Settings > reminder gap) until paid.
require_once __DIR__ . '/includes/init.php';

$key = PHP_SAPI === 'cli' ? ($argv[1] ?? '') : get('key');
$cronKey = setting('cron_key', '');
if ($cronKey === '') {
    // first run bootstraps the key so admin can copy it from Settings
    $cronKey = bin2hex(random_bytes(12));
    set_setting('cron_key', $cronKey);
}
if (!hash_equals($cronKey, (string)$key)) {
    http_response_code(403);
    die("Invalid cron key. Settings page પરથી સાચી cron URL copy કરો.\n");
}

// "today" is computed in PHP (Asia/Kolkata, per APP_TZ) rather than
// MySQL's CURDATE() - the DB server's own timezone is often UTC on shared
// hosting, which would run these ~5.5 hours behind IST and delay
// reminders/renewals by up to a day right around midnight.
$today = today();
$gap = max(1, (int)setting('reminder_gap_days', '3'));
$bills = all("SELECT s.*, c.name company_name FROM sales s
              JOIN companies c ON c.id = s.company_id
              WHERE s.status <> 'paid' AND s.is_cancelled = 0
                AND s.customer_mobile <> ''
                AND s.due_date IS NOT NULL AND s.due_date < ?
                AND (s.last_reminder IS NULL OR s.last_reminder <= DATE_SUB(?, INTERVAL ? DAY))
              ORDER BY s.due_date LIMIT 30", [$today, $today, $gap]);

$sent = 0;
foreach ($bills as $s) {
    $ok = send_whatsapp($s['customer_mobile'], wa_template('reminder', [
        'firm' => $s['company_name'], 'invoice_no' => $s['invoice_no'], 'date' => dmy($s['sale_date']),
        'due' => money($s['total'] - $s['paid']),
        'due_date_line' => 'Due date: ' . dmy($s['due_date']) . " (OVERDUE)\n",
    ]));
    if ($ok) {
        q('UPDATE sales SET last_reminder = ? WHERE id = ?', [$today, $s['id']]);
        $sent++;
    }
    usleep(400000); // gentle on the WhatsApp API
}
q('INSERT INTO activity_log (user_id, action, details) VALUES (NULL, ?, ?)',
  ['cron_reminders', "checked=" . count($bills) . " sent=$sent"]);
echo "Overdue reminders: checked " . count($bills) . ", sent $sent\n";

// ---------- AMC / recurring billing auto-renewal ----------
$due = all("SELECT * FROM amc_contracts WHERE status = 'active' AND next_bill_date <= ?
            AND (end_date IS NULL OR end_date >= ?) ORDER BY next_bill_date LIMIT 20", [$today, $today]);
$amcGenerated = 0;
foreach ($due as $c) {
    $res = amc_generate_invoice($c);
    if (!$res) continue;
    q('UPDATE amc_contracts SET next_bill_date = ? WHERE id = ?', [amc_advance_date($c['next_bill_date'], $c['billing_cycle']), $c['id']]);
    $party = row('SELECT mobile FROM parties WHERE id = ?', [$c['party_id']]);
    if ($party && $party['mobile']) {
        send_whatsapp($party['mobile'], wa_template('amc_bill', [
            'firm' => row('SELECT name FROM companies WHERE id=?', [$c['company_id']])['name'] ?? '',
            'invoice_no' => $res['invoice_no'], 'title' => $c['title'], 'total' => money($res['total']),
            'next_date' => dmy(amc_advance_date($c['next_bill_date'], $c['billing_cycle'])),
        ]));
    }
    $amcGenerated++;
    usleep(400000);
}
q('INSERT INTO activity_log (user_id, action, details) VALUES (NULL, ?, ?)',
  ['cron_amc', "due=" . count($due) . " generated=$amcGenerated"]);
echo "AMC renewals: due " . count($due) . ", generated $amcGenerated\n";

// ---------- Birthday / anniversary wishes ----------
// Matches on month+day (not full date) so it fires every year. Guarded by
// today's activity_log so re-running the cron (e.g. "Test Run" in Settings)
// the same day never double-sends to the same party.
$mmdd = substr($today, 5); // 'MM-DD'
$wishSent = 0;
foreach (['dob' => 'birthday', 'anniversary' => 'anniversary'] as $col => $tpl) {
    $alreadyWished = array_column(all("SELECT details FROM activity_log WHERE action = ? AND DATE(created_at) = ?", ["cron_$tpl", $today]), 'details');
    $matches = all("SELECT id, name, mobile FROM parties WHERE is_active = 1 AND mobile <> '' AND $col IS NOT NULL AND DATE_FORMAT($col, '%m-%d') = ?", [$mmdd]);
    foreach ($matches as $p) {
        $marker = "party:{$p['id']}";
        if (in_array($marker, $alreadyWished, true)) continue;
        $ok = send_whatsapp($p['mobile'], wa_template($tpl, ['customer' => $p['name']]));
        if ($ok) {
            q('INSERT INTO activity_log (user_id, action, details) VALUES (NULL, ?, ?)', ["cron_$tpl", $marker]);
            $wishSent++;
        }
        usleep(400000);
    }
}
echo "Birthday/anniversary wishes sent: $wishSent\n";
