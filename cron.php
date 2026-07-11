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

$gap = max(1, (int)setting('reminder_gap_days', '3'));
$bills = all("SELECT s.*, c.name company_name FROM sales s
              JOIN companies c ON c.id = s.company_id
              WHERE s.status <> 'paid' AND s.is_cancelled = 0
                AND s.customer_mobile <> ''
                AND s.due_date IS NOT NULL AND s.due_date < CURDATE()
                AND (s.last_reminder IS NULL OR s.last_reminder <= DATE_SUB(CURDATE(), INTERVAL ? DAY))
              ORDER BY s.due_date LIMIT 30", [$gap]);

$sent = 0;
foreach ($bills as $s) {
    $ok = send_whatsapp($s['customer_mobile'], wa_template('reminder', [
        'firm' => $s['company_name'], 'invoice_no' => $s['invoice_no'], 'date' => dmy($s['sale_date']),
        'due' => money($s['total'] - $s['paid']),
        'due_date_line' => 'Due date: ' . dmy($s['due_date']) . " (OVERDUE)\n",
    ]));
    if ($ok) {
        q('UPDATE sales SET last_reminder = CURDATE() WHERE id = ?', [$s['id']]);
        $sent++;
    }
    usleep(400000); // gentle on the WhatsApp API
}
q('INSERT INTO activity_log (user_id, action, details) VALUES (NULL, ?, ?)',
  ['cron_reminders', "checked=" . count($bills) . " sent=$sent"]);
echo "Overdue reminders: checked " . count($bills) . ", sent $sent\n";
