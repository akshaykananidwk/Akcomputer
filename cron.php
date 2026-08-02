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
    die("Invalid cron key. Copy the correct cron URL from the Settings page.\n");
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
    wa_context(['kind' => 'reminder']);
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

// ---------- Scheduled reports (WhatsApp digest) ----------
// One shared cron entry point, same as the jobs above - "due today" is
// worked out here (daily/weekly-on-X/monthly-on-X) rather than needing a
// separate per-minute scheduler, and last_run_at stops it firing twice if
// the host's cron (or a manual "Send Now") runs more than once the same day.
$dueSchedules = all("SELECT * FROM report_schedules WHERE is_active = 1");
$scheduleSent = 0;
foreach ($dueSchedules as $sch) {
    if ($sch['last_run_at'] && date('Y-m-d', strtotime($sch['last_run_at'])) === $today) continue;
    $isDue = $sch['frequency'] === 'daily'
        || ($sch['frequency'] === 'weekly' && (int)date('w') === (int)$sch['day_of_week'])
        || ($sch['frequency'] === 'monthly' && (int)date('j') === (int)$sch['day_of_month']);
    if (!$isDue) continue;
    $ok = send_whatsapp($sch['recipient_mobile'], report_schedule_build_message($sch));
    if ($ok) { q('UPDATE report_schedules SET last_run_at = NOW() WHERE id = ?', [$sch['id']]); $scheduleSent++; }
    usleep(400000);
}
q('INSERT INTO activity_log (user_id, action, details) VALUES (NULL, ?, ?)',
  ['cron_report_schedules', "checked=" . count($dueSchedules) . " sent=$scheduleSent"]);
echo "Scheduled reports: checked " . count($dueSchedules) . ", sent $scheduleSent\n";

// ---------- Custom reminders (title / number(s) / note, one-time or recurring) ----------
// Fires any reminder whose date+time has arrived. "now" is PHP-side (IST per
// APP_TZ) so it matches the time the owner picked in the form. For on-time
// delivery the host cron should call this file often (e.g. every 15 minutes);
// reminder_fire() advances a recurring reminder to its next slot or closes a
// one-time one, so re-running the cron never double-sends.
$nowTs = date('Y-m-d H:i:s');
$dueReminders = all("SELECT * FROM reminders WHERE status = 'pending' AND remind_at <= ? ORDER BY remind_at LIMIT 50", [$nowTs]);
$remMsgs = 0;
foreach ($dueReminders as $rem) { list($s, $f) = reminder_fire($rem); $remMsgs += $s; }
q('INSERT INTO activity_log (user_id, action, details) VALUES (NULL, ?, ?)',
  ['cron_reminders_custom', "due=" . count($dueReminders) . " messages=$remMsgs"]);
echo "Reminders: due " . count($dueReminders) . ", messages sent $remMsgs\n";

// ---------- Internet connection expiry alerts ----------
// 7 days before, 1 day before and ON the expiry day: WhatsApp the shop (and
// the customer too when the connection has notify_customer ticked).
// last_alert_date keeps one alert per connection per day even if the cron
// runs every 15 minutes.
try {
    $shopNo = wa_normalize_number(setting('wa_shop_number'));
    $due = all("SELECT *, DATEDIFF(expiry_date, CURDATE()) dl FROM net_connections
                WHERE status = 'active' AND DATEDIFF(expiry_date, CURDATE()) IN (7, 1, 0)
                AND (last_alert_date IS NULL OR last_alert_date < CURDATE())");
    $ncSent = 0;
    foreach ($due as $c) {
        $when = $c['dl'] == 0 ? 'આજે' : ($c['dl'] == 1 ? 'કાલે' : $c['dl'] . ' દિવસમાં');
        if (strlen($shopNo) >= 12) {
            $ncSent += send_whatsapp($shopNo, "🌐 *Internet connection expiry*\n\n" . $c['customer_name'] . ' (' . $c['mobile'] . ")\nPlan: " . ($c['plan_name'] ?: '-') . " · ₹" . money($c['price']) . "\n*$when બંધ થાય છે* (" . dmy($c['expiry_date']) . ")\n\nRenew: " . base_url('net_connections.php')) ? 1 : 0;
        }
        if ($c['notify_customer'] && $c['mobile']) {
            send_whatsapp($c['mobile'], "🙏 *" . setting('app_name', 'AK Computer') . "*\n\n" . $c['customer_name'] . ", તમારું ઇન્ટરનેટ કનેક્શન *$when* પૂરું થાય છે (" . dmy($c['expiry_date']) . ").\nચાલુ રાખવા અમને મેસેજ/કૉલ કરો. 📞");
        }
        q('UPDATE net_connections SET last_alert_date = CURDATE() WHERE id = ?', [$c['id']]);
    }
    // a lapsed connection flips to expired so the list and counts stay honest
    q("UPDATE net_connections SET status = 'expired' WHERE status = 'active' AND expiry_date < DATE_SUB(CURDATE(), INTERVAL 3 DAY)");
    echo "Net connections: alerts for " . count($due) . " (shop msgs $ncSent)\n";
} catch (Exception $e) { /* table not migrated yet */ }

// ---------- Housekeeping: trim old webhook delivery logs ----------
// webhook_deliveries has no cap on insert (every fire_webhook() call adds a
// row) - trimmed here instead, same "let cron sweep it up" pattern as
// everything else in this file, rather than deleting inline on every fire.
$trimmed = q('DELETE FROM webhook_deliveries WHERE created_at < DATE_SUB(?, INTERVAL 30 DAY)', [$today])->rowCount();
echo "Webhook delivery log cleanup: removed $trimmed old row(s)\n";

// ---------- Estimate auto follow-up ----------
// N days (Settings, default 3) after a quotation nothing happened: one
// gentle WhatsApp nudge to the customer - once per estimate, only while
// it's still open.
if (setting('estimate_followup_enabled', '1') === '1') {
    try {
        $efDays = max(1, (int)setting('estimate_followup_days', '3'));
        $efSent = 0;
        foreach (all("SELECT e.*, COALESCE(NULLIF(e.customer_mobile,''), p.mobile) mob FROM estimates e
                      LEFT JOIN parties p ON p.id = e.party_id
                      WHERE e.status = 'open' AND e.followup_sent_at IS NULL
                        AND e.estimate_date <= DATE_SUB(?, INTERVAL ? DAY)
                        AND e.estimate_date >= DATE_SUB(?, INTERVAL 60 DAY) LIMIT 25", [$today, $efDays, $today]) as $e2) {
            if (!$e2['mob']) { q('UPDATE estimates SET followup_sent_at = ? WHERE id = ?', [$today, $e2['id']]); continue; }
            $name = $e2['customer_name'] ?: 'Sir/Madam';
            $ok = send_whatsapp($e2['mob'], "🙏 *" . setting('app_name', 'AK Computer') . "*\n\n$name, અમે તમને " . dmy($e2['estimate_date']) . " ના રોજ *₹" . money($e2['total']) . "* નું ક્વોટેશન (" . $e2['estimate_no'] . ") આપ્યું હતું.\nકંઈ વિચાર્યું? કોઈ પ્રશ્ન હોય કે ભાવમાં વાત કરવી હોય તો બેધડક કૉલ/મેસેજ કરો. 😊\n\nThank you!");
            q('UPDATE estimates SET followup_sent_at = ? WHERE id = ?', [$today, $e2['id']]);
            if ($ok) $efSent++;
        }
        echo "Estimate follow-ups: $efSent sent\n";
    } catch (Exception $e) { /* estimates.followup_sent_at not migrated yet */ }
}

// ---------- Daily automatic backup -> admin's Telegram ----------
// Once a day: full SQL dump, gzipped into uploads/backups (7 kept), and the
// file itself dropped into every linked full-admin's Telegram chat - an
// off-server copy with zero extra accounts or keys.
if (setting('auto_backup_enabled', '1') === '1' && setting('auto_backup_last', '') !== $today) {
    set_setting('auto_backup_last', $today);
    $bkDir = __DIR__ . '/uploads/backups';
    if (!is_dir($bkDir)) mkdir($bkDir, 0755, true);
    $bkFile = $bkDir . '/backup_' . date('Ymd') . '.sql.gz';
    file_put_contents($bkFile, gzencode(db_backup_sql(), 6));
    // rotate: keep the newest 7
    $old = glob($bkDir . '/backup_*.sql.gz');
    rsort($old);
    foreach (array_slice($old, 7) as $f) @unlink($f);
    $tgSent = 0;
    if (setting('tg_bot_token', '') !== '') {
        foreach (tg_admin_chats() as $chat) {
            $r = tg_call('sendDocument', ['chat_id' => $chat, 'document' => new CURLFile($bkFile, 'application/gzip', basename($bkFile)),
                                          'caption' => '🗄 ' . setting('app_name', 'AK Computer') . ' daily backup ' . dmy($today)], true);
            if ($r['ok'] ?? false) $tgSent++;
        }
    }
    echo 'Auto backup: ' . basename($bkFile) . ' (' . round(filesize($bkFile) / 1024) . ' KB), Telegram sent to ' . $tgSent . " admin(s)\n";
}

// ---------- Weekly data health auto-run ----------
// Every 7 days the same checks as Reports > Data Health Check run silently;
// the owner hears about it ONLY when something is actually wrong.
if ((int)setting('health_autorun_last', 0) <= strtotime($today . ' -7 days')) {
    set_setting('health_autorun_last', (string)strtotime($today));
    require_once __DIR__ . '/includes/health.php';
    try {
        $issues = health_issue_summary();
        if ($issues) {
            $msgH = "🩺 *Data Health Check*\n" . count($issues) . " પ્રકારની ગરબડ મળી:\n";
            foreach ($issues as $t2 => $n2) $msgH .= "• $t2 — $n2\n";
            $msgH .= "\nસોફ્ટવેરમાં Reports → Data Health Check ખોલીને સુધારો.";
            $shopNoH = wa_normalize_number(setting('wa_shop_number'));
            if ($shopNoH) send_whatsapp($shopNoH, $msgH);
            tg_notify_admins($msgH);
            echo 'Health auto-run: ' . count($issues) . " issue type(s) - owner notified\n";
        } else {
            echo "Health auto-run: all clean\n";
        }
    } catch (Exception $e) { echo "Health auto-run failed: " . $e->getMessage() . "\n"; }
}
// ---------- Meta WhatsApp template status auto-refresh (every ~6h) ----------
// A Pending template turns Approved on Meta's side without telling us - this
// keeps the Settings status table fresh so the owner never checks manually.
try {
    require_once __DIR__ . '/includes/wa_meta.php';
    if (meta_wa_configured() && setting('meta_wa_waba_id') !== '') {
        $last = setting('meta_wa_tpl_synced_at', '');
        $cur = json_decode(setting('meta_wa_tpl_status', ''), true) ?: [];
        $hasPending = (bool)array_filter($cur, fn($t) => is_array($t) && in_array($t['status'], ['PENDING', 'DRAFT'], true));
        if (($hasPending || !$cur) && ($last === '' || strtotime($last) < time() - 6 * 3600)) {
            $res = meta_wa_sync_templates();
            echo 'Meta WA template sync: ' . (isset($res['_error']) ? $res['_error'] : count($res) . " template(s) refreshed\n");
        }
    }
} catch (Exception $e) { echo 'Meta WA template sync skipped: ' . $e->getMessage() . "\n"; }
