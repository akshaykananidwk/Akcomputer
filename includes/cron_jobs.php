<?php
// Centralized cron scheduler: ONE server cron hits cron.php every minute and
// cron_run_all() decides which registered jobs are actually due, runs them
// under a MySQL named lock (no overlapping runs ever), and writes every
// execution into cron_runs for the admin's Cron Manager page.
//
// Adding a future job = one entry in cron_jobs() + one cron_job_<id>()
// function that RETURNS a short summary string (throw on error). Nothing on
// the server changes - the master cron picks it up automatically.

/** The registry. id => [label, description, default interval (minutes),
 *  optional extra due-check closure (interval AND this must both say yes -
 *  used for "once a day after midnight" style guards so the history stays
 *  free of no-op rows)]. */
function cron_jobs() {
    return [
        'custom_reminders' => ['⏰ Custom Reminders', 'Reminder module - one-time & recurring scheduled messages', 5, null],
        'overdue_reminders' => ['📅 Payment Due Reminders', 'Overdue-bill WhatsApp reminders, sent at the hour set in Settings → Reminders', 60,
            fn() => (int)date('G') >= min(23, max(0, (int)setting('reminder_hour', '10')))],
        'settle_promises' => ['🤝 Promise-to-Pay Check', 'Marks each promised payment kept or broken once its day arrives', 180, null],
        'amc_renewals' => ['🔄 AMC / Recurring Billing', 'Generates AMC invoices due today and WhatsApps the bill', 60, null],
        'wishes' => ['🎂 Birthday & Anniversary Wishes', 'WhatsApp wishes to parties on their special day', 60, null],
        'report_schedules' => ['📊 Auto Reports', 'Scheduled WhatsApp report digests (daily / weekly / monthly)', 60, null],
        'net_expiry' => ['🌐 Internet Expiry Alerts', 'Connection expiring in 7/1/0 days → alert shop + customer', 180, null],
        'estimate_followup' => ['📋 Quotation Follow-ups', 'One gentle nudge N days after an unanswered quotation', 360,
            fn() => setting('estimate_followup_enabled', '1') === '1'],
        'auto_backup' => ['🗄 Daily Backup → Telegram', 'Full gzipped SQL dump, rotated (7 kept) + sent to admin Telegram', 60,
            fn() => setting('auto_backup_enabled', '1') === '1' && setting('auto_backup_last', '') !== today()],
        'health_check' => ['🩺 Weekly Data Health Check', 'Runs the Data Health checks; owner is pinged only when something is wrong', 720,
            fn() => (int)setting('health_autorun_last', 0) <= strtotime(today() . ' -7 days')],
        'meta_tpl_sync' => ['📱 Meta Template Refresh', 'Refreshes WhatsApp template approval status from Meta (~6h while pending)', 360,
            function () {
                if (setting('meta_wa_token', '') === '' || setting('meta_wa_phone_id', '') === '' || setting('meta_wa_waba_id', '') === '') return false;
                $cur = json_decode(setting('meta_wa_tpl_status', ''), true) ?: [];
                $hasPending = (bool)array_filter($cur, fn($t) => is_array($t) && in_array($t['status'], ['PENDING', 'DRAFT'], true));
                $last = setting('meta_wa_tpl_synced_at', '');
                return ($hasPending || !$cur) && ($last === '' || strtotime($last) < time() - 6 * 3600);
            }],
        'campaign_queue' => ['📣 Campaign Sending', 'Sends the next batch of any running campaign, inside the allowed hours only', 15,
            fn() => cam_quiet_ok()],
        'housekeeping' => ['🧹 Log Cleanup', 'Trims old webhook-delivery and cron-history rows', 1440, null],
    ];
}

/** Last recorded run of one job (from cron_runs). */
function cron_last_run($id) {
    try { return row('SELECT * FROM cron_runs WHERE job = ? ORDER BY id DESC LIMIT 1', [$id]); }
    catch (Exception $e) { return null; } // pre-v51
}

/** Is this job due right now? Interval respected; a FAILED run retries after
 *  5 minutes; a run stuck in 'run' >10 min counts as crashed (due again). */
function cron_job_due($id, array $def) {
    if (setting("cron_{$id}_on", '1') !== '1') return false;
    if ($def[3] instanceof Closure && !$def[3]()) return false;
    $last = cron_last_run($id);
    if (!$last) return true;
    $ageMin = (time() - strtotime($last['started_at'])) / 60;
    if ($last['status'] === 'run') return $ageMin >= 10; // crashed mid-run
    $every = max(1, (int)setting("cron_{$id}_every", (string)$def[2]));
    if ($last['status'] === 'fail') return $ageMin >= min($every, 5);
    return $ageMin >= $every;
}

/** Run every due job (or the given ids, forced). Returns a result list.
 *  A MySQL named lock guarantees two overlapping cron hits never double-run
 *  anything - the second caller just reports "already running". */
function cron_run_all(array $only = [], $force = false) {
    if (!(int)val("SELECT GET_LOCK('akc_cron', 0)")) {
        return [['job' => '(master)', 'status' => 'skip', 'detail' => 'another cron run is already in progress']];
    }
    set_setting('cron_last_tick', date('Y-m-d H:i:s'));
    $out = [];
    foreach (cron_jobs() as $id => $def) {
        if ($only && !in_array($id, $only, true)) continue;
        if ($force && $only) {
            if (setting("cron_{$id}_on", '1') !== '1') { $out[] = ['job' => $id, 'status' => 'skip', 'detail' => 'job is disabled']; continue; }
        } elseif (!cron_job_due($id, $def)) {
            continue;
        }
        try { q("INSERT INTO cron_runs (job, started_at) VALUES (?, NOW())", [$id]); $runId = insert_id(); }
        catch (Exception $e) { $runId = 0; } // pre-v51: still run, just unlogged
        try {
            $detail = (string)call_user_func('cron_job_' . $id);
            if ($runId) q("UPDATE cron_runs SET finished_at = NOW(), status = 'ok', detail = ? WHERE id = ?", [mb_substr($detail, 0, 500), $runId]);
            $out[] = ['job' => $id, 'status' => 'ok', 'detail' => $detail];
        } catch (Throwable $e) {
            $err = $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine();
            if ($runId) q("UPDATE cron_runs SET finished_at = NOW(), status = 'fail', detail = ? WHERE id = ?", [mb_substr($err, 0, 500), $runId]);
            try { q('INSERT INTO activity_log (user_id, action, details) VALUES (NULL, ?, ?)', ['cron_fail', mb_substr("$id: $err", 0, 400)]); } catch (Exception $e2) {}
            if (function_exists('app_error')) app_error('cron', "job '$id' failed: $err", 'cron_jobs.php');
            $out[] = ['job' => $id, 'status' => 'fail', 'detail' => $err];
        }
    }
    val("SELECT RELEASE_LOCK('akc_cron')");
    return $out;
}

// ============================ the jobs ============================

/** Overdue payment reminders: due-date day + every N days after, until paid. */
function cron_job_overdue_reminders() {
    $today = today();
    $gap = max(1, (int)setting('reminder_gap_days', '1'));
    $bills = all("SELECT s.*, c.name company_name FROM sales s
                  JOIN companies c ON c.id = s.company_id
                  WHERE s.status <> 'paid' AND s.is_cancelled = 0
                    AND s.customer_mobile <> ''
                    AND s.due_date IS NOT NULL AND s.due_date <= ?
                    AND (s.last_reminder IS NULL OR s.last_reminder <= DATE_SUB(?, INTERVAL ? DAY))
                  ORDER BY s.due_date LIMIT 30", [$today, $today, $gap]);
    // The collection guards apply to the cron exactly as they apply to a human
    // pressing Send: a customer who opted out, promised to pay on Friday, was
    // snoozed, or was contacted yesterday must not be messaged automatically
    // either. Without this the queue would politely hold back while the cron
    // quietly nagged the same people every night.
    $partyIds = array_values(array_unique(array_filter(array_map(fn($b) => (int)$b['party_id'], $bills))));
    $evMap = $partyIds ? coll_event_map($partyIds) : [];
    $optOut = [];
    if ($partyIds) {
        foreach (all("SELECT id, collection_opt_out FROM parties WHERE id IN (" . implode(',', $partyIds) . ")") as $p)
            $optOut[(int)$p['id']] = (int)$p['collection_opt_out'] === 1;
    }

    $sent = 0; $covered = 0; $held = 0;
    foreach ($bills as $s) {
        // the message must claim only what is GENUINELY left: the bill's due
        // capped by the party's real ledger (sale_true_due) - a fully covered
        // bill sends nothing at all and stops being re-checked today
        $trueDue = sale_true_due($s);
        if ($trueDue <= 0.009) { q('UPDATE sales SET last_reminder = ? WHERE id = ?', [$today, $s['id']]); $covered++; continue; }

        $pid = (int)$s['party_id'];
        if ($pid) {
            $e = $evMap[$pid] ?? [];
            $guard = coll_can_remind([
                'mobile' => $s['customer_mobile'],
                'opt_out' => $optOut[$pid] ?? false,
                'snoozed_until' => $e['snooze_until'] ?? null,
                'promise_open' => $e['promise_open'] ?? null,
                'last_contact' => $e['last_contact'] ?? null,
                'contacts_30d' => $e['contacts_30d'] ?? 0,
            ]);
            if (!$guard['ok']) { $held++; continue; }
        }
        $lateDays = (int)floor((strtotime($today) - strtotime($s['due_date'])) / 86400);
        $dueLine = $lateDays <= 0 ? "📅 આજે પેમેન્ટની છેલ્લી તારીખ છે!\n"
                 : '📅 Due date: ' . dmy($s['due_date']) . " — ⏰ *$lateDays દિવસ* થઈ ગયા\n";
        wa_context(['kind' => 'reminder']);
        $ok = send_whatsapp($s['customer_mobile'], wa_template('reminder', [
            'firm' => $s['company_name'], 'invoice_no' => $s['invoice_no'], 'date' => dmy($s['sale_date']),
            'due' => money($trueDue),
            'due_date_line' => $dueLine,
        ]));
        if ($ok) {
            q('UPDATE sales SET last_reminder = ? WHERE id = ?', [$today, $s['id']]);
            $sent++;
            // recorded in the same history a human's reminder goes into, so
            // the cooldown and the Customer 360 trail cover both
            if ($pid) coll_log($pid, 'reminder', ['channel' => 'whatsapp', 'amount' => $trueDue,
                                                  'note' => 'ઓટોમેટિક રિમાઇન્ડર — ' . $s['invoice_no'], 'status' => 'done']);
        }
        usleep(400000);
    }
    return 'checked ' . count($bills) . ', sent ' . $sent
         . ($covered ? ", already-covered $covered" : '') . ($held ? ", held-back $held" : '');
}

/** Turn promises whose day has come into kept/broken, so the queue and the
 *  priority score reflect reality without anybody having to tick anything. */
function cron_job_settle_promises() {
    list($kept, $broken) = coll_settle_promises();
    return "promises kept $kept, broken $broken";
}

/** Custom reminders (Reminder module): fire anything whose time arrived. */
function cron_job_custom_reminders() {
    $due = all("SELECT * FROM reminders WHERE status = 'pending' AND remind_at <= ? ORDER BY remind_at LIMIT 50", [date('Y-m-d H:i:s')]);
    $msgs = 0;
    foreach ($due as $rem) { [$s, ] = reminder_fire($rem); $msgs += $s; }
    return 'due ' . count($due) . ', messages sent ' . $msgs;
}

/** AMC / recurring billing auto-renewal. */
function cron_job_amc_renewals() {
    $today = today();
    $due = all("SELECT * FROM amc_contracts WHERE status = 'active' AND next_bill_date <= ?
                AND (end_date IS NULL OR end_date >= ?) ORDER BY next_bill_date LIMIT 20", [$today, $today]);
    $generated = 0;
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
        $generated++;
        usleep(400000);
    }
    return 'due ' . count($due) . ', generated ' . $generated;
}

/** Birthday / anniversary wishes (month+day match, once per party per day). */
function cron_job_wishes() {
    $today = today();
    $mmdd = substr($today, 5);
    $sent = 0;
    foreach (['dob' => 'birthday', 'anniversary' => 'anniversary'] as $col => $tpl) {
        // a plain range on created_at instead of DATE(created_at) = ?: wrapping
        // the column in a function stops idx_log_action_time being used at all,
        // so this scanned the whole activity log every minute (19ms -> 0.3ms)
        $alreadyWished = array_column(all("SELECT details FROM activity_log WHERE action = ? AND created_at >= ? AND created_at < ? + INTERVAL 1 DAY",
                                          ["cron_$tpl", $today, $today]), 'details');
        $matches = all("SELECT id, name, mobile FROM parties WHERE is_active = 1 AND mobile <> '' AND $col IS NOT NULL AND DATE_FORMAT($col, '%m-%d') = ?", [$mmdd]);
        foreach ($matches as $p) {
            $marker = "party:{$p['id']}";
            if (in_array($marker, $alreadyWished, true)) continue;
            if (send_whatsapp($p['mobile'], wa_template($tpl, ['customer' => $p['name']]))) {
                q('INSERT INTO activity_log (user_id, action, details) VALUES (NULL, ?, ?)', ["cron_$tpl", $marker]);
                $sent++;
            }
            usleep(400000);
        }
    }
    return 'wishes sent ' . $sent;
}

/** Scheduled WhatsApp report digests (daily / weekly-on-X / monthly-on-X). */
function cron_job_report_schedules() {
    $today = today();
    $schedules = all("SELECT * FROM report_schedules WHERE is_active = 1");
    $sent = 0;
    foreach ($schedules as $sch) {
        if ($sch['last_run_at'] && date('Y-m-d', strtotime($sch['last_run_at'])) === $today) continue;
        $isDue = $sch['frequency'] === 'daily'
            || ($sch['frequency'] === 'weekly' && (int)date('w') === (int)$sch['day_of_week'])
            || ($sch['frequency'] === 'monthly' && (int)date('j') === (int)$sch['day_of_month']);
        if (!$isDue) continue;
        if (send_whatsapp($sch['recipient_mobile'], report_schedule_build_message($sch))) {
            q('UPDATE report_schedules SET last_run_at = NOW() WHERE id = ?', [$sch['id']]);
            $sent++;
        }
        usleep(400000);
    }
    return 'checked ' . count($schedules) . ', sent ' . $sent;
}

/** Internet connection expiry alerts (7/1/0 days before). */
function cron_job_net_expiry() {
    $shopNo = wa_normalize_number(setting('wa_shop_number'));
    $due = all("SELECT *, DATEDIFF(expiry_date, CURDATE()) dl FROM net_connections
                WHERE status = 'active' AND DATEDIFF(expiry_date, CURDATE()) IN (7, 1, 0)
                AND (last_alert_date IS NULL OR last_alert_date < CURDATE())");
    $sent = 0;
    foreach ($due as $c) {
        $when = $c['dl'] == 0 ? 'આજે' : ($c['dl'] == 1 ? 'કાલે' : $c['dl'] . ' દિવસમાં');
        if (strlen($shopNo) >= 12) {
            $sent += send_whatsapp($shopNo, "🌐 *Internet connection expiry*\n\n" . $c['customer_name'] . ' (' . $c['mobile'] . ")\nPlan: " . ($c['plan_name'] ?: '-') . " · ₹" . money($c['price']) . "\n*$when બંધ થાય છે* (" . dmy($c['expiry_date']) . ")\n\nRenew: " . base_url('net_connections.php')) ? 1 : 0;
        }
        if ($c['notify_customer'] && $c['mobile']) {
            send_whatsapp($c['mobile'], "🙏 *" . setting('app_name', 'AK Computer') . "*\n\n" . $c['customer_name'] . ", તમારું ઇન્ટરનેટ કનેક્શન *$when* પૂરું થાય છે (" . dmy($c['expiry_date']) . ").\nચાલુ રાખવા અમને મેસેજ/કૉલ કરો. 📞");
        }
        q('UPDATE net_connections SET last_alert_date = CURDATE() WHERE id = ?', [$c['id']]);
    }
    q("UPDATE net_connections SET status = 'expired' WHERE status = 'active' AND expiry_date < DATE_SUB(CURDATE(), INTERVAL 3 DAY)");
    return 'alerts for ' . count($due) . ' connection(s), shop msgs ' . $sent;
}

/** Quotation follow-up nudge N days after no answer (once per estimate). */
function cron_job_estimate_followup() {
    $today = today();
    $efDays = max(1, (int)setting('estimate_followup_days', '3'));
    $sent = 0;
    foreach (all("SELECT e.*, COALESCE(NULLIF(e.customer_mobile,''), p.mobile) mob FROM estimates e
                  LEFT JOIN parties p ON p.id = e.party_id
                  WHERE e.status = 'open' AND e.followup_sent_at IS NULL
                    AND e.estimate_date <= DATE_SUB(?, INTERVAL ? DAY)
                    AND e.estimate_date >= DATE_SUB(?, INTERVAL 60 DAY) LIMIT 25", [$today, $efDays, $today]) as $e2) {
        if (!$e2['mob']) { q('UPDATE estimates SET followup_sent_at = ? WHERE id = ?', [$today, $e2['id']]); continue; }
        $name = $e2['customer_name'] ?: 'Sir/Madam';
        $ok = send_whatsapp($e2['mob'], "🙏 *" . setting('app_name', 'AK Computer') . "*\n\n$name, અમે તમને " . dmy($e2['estimate_date']) . " ના રોજ *₹" . money($e2['total']) . "* નું ક્વોટેશન (" . $e2['estimate_no'] . ") આપ્યું હતું.\nકંઈ વિચાર્યું? કોઈ પ્રશ્ન હોય કે ભાવમાં વાત કરવી હોય તો બેધડક કૉલ/મેસેજ કરો. 😊\n\nThank you!");
        q('UPDATE estimates SET followup_sent_at = ? WHERE id = ?', [$today, $e2['id']]);
        if ($ok) $sent++;
    }
    return 'follow-ups sent ' . $sent;
}

/** Daily automatic backup: gzipped SQL dump, 7 kept, sent to admin Telegram. */
function cron_job_auto_backup() {
    $today = today();
    set_setting('auto_backup_last', $today);
    $bkDir = dirname(__DIR__) . '/uploads/backups';
    if (!is_dir($bkDir)) mkdir($bkDir, 0755, true);

    // The dump carries EVERYTHING - customers, prices, password hashes and
    // (until they were encrypted) API keys - so it is AES-256 encrypted
    // whenever a passphrase is set in Settings > Backup, using the same
    // AKENC1 container the manual download and its decrypt tool use.
    $pass = (string)setting('backup_passphrase', '');
    $sql = db_backup_sql();
    if ($pass !== '') {
        $blob = backup_encrypt($sql, $pass);
        $ext = '.sql.enc';
    } else {
        $blob = gzencode($sql, 6);
        $ext = '.sql.gz';
    }
    $bkFile = $bkDir . '/backup_' . date('Ymd') . $ext;
    file_put_contents($bkFile, $blob);
    foreach (['.sql.gz', '.sql.enc'] as $e) {
        $old = glob($bkDir . '/backup_*' . $e);
        rsort($old);
        foreach (array_slice($old, 7) as $f) @unlink($f);
    }

    // Sending the file itself to Telegram puts the whole business in a chat
    // history. Encrypted backups may be attached; an UNENCRYPTED one never
    // is - admins get a notice telling them where it is and how to turn
    // encryption on. Settings > Backup can also switch attachments off.
    $tgSent = 0; $mode = 'not sent';
    $attach = $pass !== '' && setting('backup_telegram', '1') === '1';
    if (setting('tg_bot_token', '') !== '') {
        foreach (tg_admin_chats() as $chat) {
            if ($attach) {
                $r = tg_call('sendDocument', ['chat_id' => $chat, 'document' => new CURLFile($bkFile, 'application/octet-stream', basename($bkFile)),
                                              'caption' => '🗄 ' . setting('app_name', 'AK Computer') . ' daily backup ' . dmy($today) . ' (encrypted)'], true);
                $mode = 'encrypted attachment';
            } else {
                $note = "🗄 " . setting('app_name', 'AK Computer') . " daily backup " . dmy($today) . "\n"
                      . basename($bkFile) . ' · ' . round(filesize($bkFile) / 1024) . " KB\n"
                      . ($pass === ''
                          ? "⚠️ બેકઅપ ફાઈલ અહીં નથી મોકલી — એ ખુલ્લી (unencrypted) છે.\nSettings → Backup માં પાસફ્રેઝ નાખો એટલે એન્ક્રિપ્ટ થઈને અહીં આવશે."
                          : "Attachment off in Settings → Backup.");
                $r = tg_call('sendMessage', ['chat_id' => $chat, 'text' => $note]);
                $mode = $pass === '' ? 'notice only (no passphrase set)' : 'notice only (attachment off)';
            }
            if ($r['ok'] ?? false) $tgSent++;
        }
    }
    return basename($bkFile) . ' (' . round(filesize($bkFile) / 1024) . ' KB), Telegram: ' . $mode . ' → ' . $tgSent . ' admin(s)';
}

/** Weekly data-health auto-run; owner hears about it only when wrong. */
function cron_job_health_check() {
    set_setting('health_autorun_last', (string)strtotime(today()));
    require_once __DIR__ . '/health.php';
    $issues = health_issue_summary();
    if (!$issues) return 'all clean';
    $msgH = "🩺 *Data Health Check*\n" . count($issues) . " પ્રકારની ગરબડ મળી:\n";
    foreach ($issues as $t2 => $n2) $msgH .= "• $t2 — $n2\n";
    $msgH .= "\nસોફ્ટવેરમાં Reports → Data Health Check ખોલીને સુધારો.";
    $shopNoH = wa_normalize_number(setting('wa_shop_number'));
    if ($shopNoH) send_whatsapp($shopNoH, $msgH);
    try { tg_notify_admins($msgH); } catch (Exception $e) {}
    return count($issues) . ' issue type(s) - owner notified';
}

/** Meta WhatsApp template approval-status refresh. */
function cron_job_meta_tpl_sync() {
    require_once __DIR__ . '/wa_meta.php';
    $res = meta_wa_sync_templates();
    if (isset($res['_error'])) throw new Exception($res['_error']);
    return count($res) . ' template(s) refreshed';
}

/** Housekeeping: trim old webhook-delivery + cron-history rows. */
/** Campaign sending, one batch at a time.
 *
 *  The cron does not get its own rules: it calls the same cam_send_batch()
 *  the Send button calls, so quiet hours, the gap between messages, the
 *  monthly cap and the opt-out check apply exactly as they do to a human
 *  pressing send. A second campaign waits its turn rather than doubling the
 *  night's traffic on one customer. */
function cron_job_campaign_queue() {
    $c = row("SELECT id, name FROM campaigns WHERE status IN ('ready','sending') ORDER BY started_at IS NULL, id LIMIT 1");
    if (!$c) return 'no campaign is waiting';
    $r = cam_send_batch((int)$c['id']);
    if (!empty($r['error'])) return $c['name'] . ': ' . $r['error'];
    return sprintf('%s — sent %d, failed %d, skipped %d, %d left',
        $c['name'], $r['sent'], $r['failed'], $r['skipped'], $r['left']);
}

function cron_job_housekeeping() {
    $wh = q('DELETE FROM webhook_deliveries WHERE created_at < DATE_SUB(?, INTERVAL 30 DAY)', [today()])->rowCount();
    $cr = 0;
    try { $cr = q('DELETE FROM cron_runs WHERE started_at < DATE_SUB(NOW(), INTERVAL 30 DAY)')->rowCount(); } catch (Exception $e) {}
    // safety net: encrypt any secret that reached the settings table as
    // plaintext (e.g. an install that updated code but never hit Migrate)
    $sec = function_exists('secrets_encrypt_existing') ? secrets_encrypt_existing() : 0;
    // error log rotation - keep the file bounded on shared hosting
    $rot = function_exists('error_log_rotate') ? error_log_rotate() : 0;
    return "trimmed $wh webhook + $cr cron rows" . ($sec ? ", encrypted $sec secret(s)" : '') . ($rot ? ', rotated error log' : '');
}
