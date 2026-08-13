<?php
// Data health checks - every check is a READ-ONLY query for a situation
// that should never exist. Used by the Data Health Check report tab and by
// cron.php's weekly auto-run (which pings the owner only when something
// is actually wrong).
function health_checks() {
    $checks = [];
    $add = function ($title, $gu, $rows, $sev = 'bad') use (&$checks) {
        $checks[] = ['t' => $title, 'gu' => $gu, 'rows' => $rows, 'sev' => $sev];
    };

    $add('Cash payment carrying a bank account',
        'કેશ પેમેન્ટ પર બેંક ID ચોંટેલું છે — આ એન્ટ્રી બેંક લેજરમાં ખોટી દેખાશે. (migrate v41 ચલાવવાથી સાફ થાય છે)',
        all("SELECT py.id, py.pay_date d, py.amount, py.mode, COALESCE(p.name,'Walk-in') who FROM payments py LEFT JOIN parties p ON p.id=py.party_id JOIN payment_methods pm ON pm.code=py.mode WHERE pm.type='cash' AND py.bank_account_id IS NOT NULL LIMIT 10"));
    $add('Cash expense carrying a bank account',
        'કેશ ખર્ચ પર બેંક ID ચોંટેલું છે — બેંક લેજર ખોટું બતાવશે. (migrate v41 થી સાફ થાય)',
        all("SELECT e.id, e.exp_date d, e.amount, e.category who, e.mode FROM expenses e JOIN payment_methods pm ON pm.code=e.mode WHERE pm.type='cash' AND e.bank_account_id IS NOT NULL LIMIT 10"));
    $add('Bank-mode payment without a bank account',
        'બેંક/ચેક મોડનું પેમેન્ટ છે પણ કયા બેંક ખાતામાં એ નક્કી નથી — કોઈ બેંક લેજરમાં નહીં દેખાય. પેમેન્ટ ખોલીને બેંક પસંદ કરી Update કરો.',
        all("SELECT py.id, py.pay_date d, py.amount, py.mode, COALESCE(p.name,'Walk-in') who FROM payments py LEFT JOIN parties p ON p.id=py.party_id JOIN payment_methods pm ON pm.code=py.mode WHERE pm.type='bank' AND py.bank_account_id IS NULL LIMIT 10"), 'warn');
    $add('Sale bill: paid more than total',
        'બિલની રકમ કરતાં વધુ "paid" નોંધાયું છે — બિલ ખોલીને paid સરખું કરો.',
        all("SELECT id, invoice_no no, sale_date d, total, paid FROM sales WHERE paid > total + 0.01 AND is_cancelled=0 LIMIT 10"));
    $add('Sale bill: totals do not add up',
        'બિલમાં સરવાળો ખોટો છે (subtotal − discount + tax + shipping + adjustment + round off ≠ total). બિલ એક વાર Edit કરીને Save કરો એટલે ફરી ગણાઈ જશે.',
        all("SELECT id, invoice_no no, sale_date d, total, ROUND(subtotal - discount + tax_amount + COALESCE(shipping,0) + COALESCE(adjustment,0) + COALESCE(round_off,0), 2) expect FROM sales WHERE is_cancelled=0 AND ABS(ROUND(subtotal - discount + tax_amount + COALESCE(shipping,0) + COALESCE(adjustment,0) + COALESCE(round_off,0), 2) - total) > 1 LIMIT 10"));
    $add('Sale bill: item lines do not match subtotal',
        'બિલની આઇટમ-લાઇનોનો સરવાળો subtotal સાથે નથી મળતો — બિલ Edit કરીને Save કરો.',
        all("SELECT s.id, s.invoice_no no, s.sale_date d, s.subtotal amount, ROUND(SUM(si.total),2) expect FROM sales s JOIN sale_items si ON si.sale_id=s.id WHERE s.is_cancelled=0 GROUP BY s.id HAVING ABS(expect - s.subtotal) > 1 LIMIT 10"));
    $add('Purchase bill: paid more than total',
        'પરચેસ બિલમાં total કરતાં વધુ paid છે.',
        all("SELECT id, bill_no no, purchase_date d, total, paid FROM purchases WHERE paid > total + 0.01 AND is_cancelled=0 LIMIT 10"));
    $add('Zero-amount purchase bills',
        '₹0 ની પરચેસ એન્ટ્રી — અધૂરું બિલ લાગે છે; પૂરું કરો અથવા ડિલીટ કરો.',
        all("SELECT id, bill_no no, purchase_date d, total, paid FROM purchases WHERE total <= 0 AND is_cancelled=0 LIMIT 10"), 'warn');
    $add('Serial number sold on two bills at once',
        'એક જ સિરિયલ નંબર બે અલગ-અલગ બિલ પર "sold" છે — બેમાંથી એક બિલ ખોટું છે.',
        all("SELECT i2.name who, s2.serial_no no, COUNT(*) amount FROM item_serials s2 JOIN items i2 ON i2.id=s2.item_id WHERE s2.status='sold' GROUP BY s2.item_id, s2.serial_no HAVING COUNT(*) > 1 LIMIT 10"));
    $add('Same serial in stock twice',
        'એક જ સિરિયલ નંબર બે વાર સ્ટોકમાં પડ્યો છે (ડબલ એન્ટ્રી).',
        all("SELECT i2.name who, s2.serial_no no, COUNT(*) amount FROM item_serials s2 JOIN items i2 ON i2.id=s2.item_id WHERE s2.status='in_stock' GROUP BY s2.item_id, s2.serial_no HAVING COUNT(*) > 1 LIMIT 10"));
    $add('Serial count vs stock quantity mismatch',
        'સિરિયલવાળી આઇટમમાં "in stock" સિરિયલની સંખ્યા અને સ્ટોકનો આંકડો જુદા છે — 🔧 Serial/Stock Repair ટૂલથી બે મિનિટમાં સુધારો: serial_fix.php ખોલો (નીચે લિંક).',
        all("SELECT i2.id, i2.name who, COALESCE((SELECT SUM(qty) FROM stock st WHERE st.item_id=i2.id),0) amount, (SELECT COUNT(*) FROM item_serials s2 WHERE s2.item_id=i2.id AND s2.status='in_stock') expect FROM items i2 WHERE i2.serial_tracked=1 AND i2.is_active=1 HAVING ABS(amount - expect) > 0 LIMIT 10"), 'warn');
    $add('Cancelled bills still holding payments',
        'કેન્સલ થયેલા બિલ પર હજી પેમેન્ટ ચોંટેલાં છે — એ પૈસા લેજરમાં ખોટા ગણાય છે.',
        all("SELECT py.id, s.invoice_no no, py.pay_date d, py.amount FROM payments py JOIN sales s ON s.id=py.ref_id AND py.ref_type='sale' WHERE s.is_cancelled=1 LIMIT 10"));
    $add('Payments pointing to deleted bills',
        'પેમેન્ટ જે બિલ સાથે જોડાયેલું હતું એ બિલ હવે નથી — પેમેન્ટ ખોલીને ચકાસો (પાર્ટી બેલેન્સમાં તો ગણાય જ છે).',
        all("SELECT py.id, py.pay_date d, py.amount, py.ref_type who FROM payments py WHERE (py.ref_type='sale' AND NOT EXISTS (SELECT 1 FROM sales s WHERE s.id=py.ref_id)) OR (py.ref_type='purchase' AND NOT EXISTS (SELECT 1 FROM purchases pu WHERE pu.id=py.ref_id)) LIMIT 10"), 'warn');

    // ---- stock: every movement must be explained by the ledger ----
    // adjust_stock() writes the stock row and the ledger row together, so
    // the two can only drift if something wrote to stock directly.
    // Stock and ledger are written together by adjust_stock(), so for an item
    // that has ever moved through the software the two should agree. Opening
    // stock loaded straight into the table at go-live has no ledger rows and
    // legitimately shows a gap, so items that never moved are skipped and the
    // rest is reported as a warning, not an error.
    $add('Stock does not match its own movement history',
        'આઇટમનો સ્ટોક અને એની આવક-જાવકનો સરવાળો જુદા પડે છે. જો શરૂઆતનો સ્ટોક સોફ્ટવેરની બહારથી નાખેલો હોય તો આ ફરક સામાન્ય છે — ચિંતા ન કરો. પણ ફરક અચાનક બદલાય તો Stock પેજ પરથી Adjust કરીને સાચો આંકડો નાખો.',
        all("SELECT i.id, i.name who, st.qty amount, COALESCE(sl.s,0) expect
             FROM stock st JOIN items i ON i.id=st.item_id
             JOIN (SELECT item_id, location_id, SUM(change_qty) s FROM stock_ledger GROUP BY item_id, location_id) sl
               ON sl.item_id=st.item_id AND sl.location_id=st.location_id
             WHERE ABS(st.qty - COALESCE(sl.s,0)) > 0.01 LIMIT 10"), 'warn');
    $add('Negative stock',
        'સ્ટોક માઈનસમાં છે — જેટલો માલ હતો એના કરતાં વધુ વેચાયો દેખાય છે. પરચેસ એન્ટ્રી બાકી હોય તો નાખો.',
        all("SELECT i.id, i.name who, st.qty amount, l.name no FROM stock st JOIN items i ON i.id=st.item_id
             LEFT JOIN locations l ON l.id=st.location_id WHERE st.qty < -0.01 LIMIT 10"), 'warn');

    // ---- allocations: the link between a payment and the bill it settles ----
    $add('Bill "paid" does not match its linked payments',
        'બિલ પર લખેલી "paid" રકમ અને એની સાથે લિંક થયેલાં પેમેન્ટનો સરવાળો જુદો છે — બિલ ખોલીને પેમેન્ટ ફરી લિંક કરો.',
        all("SELECT s.id, s.invoice_no no, s.sale_date d, s.paid amount, ROUND(SUM(pa.amount),2) expect
             FROM sales s JOIN payment_allocations pa ON pa.ref_type='sale' AND pa.ref_id=s.id
             WHERE s.is_cancelled=0 GROUP BY s.id HAVING ABS(s.paid - expect) > 1 LIMIT 10"), 'warn');
    $add('Payment linked to more than it is worth',
        'એક પેમેન્ટ એની પોતાની રકમ કરતાં વધુ બિલોમાં વહેંચાયેલું છે — લિંક ખોટી છે, પેમેન્ટ ખોલીને સુધારો.',
        all("SELECT py.id, py.pay_date d, py.amount, ROUND(SUM(pa.amount),2) expect, COALESCE(p.name,'Walk-in') who
             FROM payments py JOIN payment_allocations pa ON pa.payment_id=py.id
             LEFT JOIN parties p ON p.id=py.party_id
             GROUP BY py.id HAVING expect > py.amount + 1 LIMIT 10"));
    $add('Payment link pointing at a deleted bill',
        'પેમેન્ટની લિંક જે બિલ પર હતી એ બિલ હવે નથી — લિંક ખાલી પડી છે.',
        all("SELECT pa.id, pa.amount, pa.ref_type who FROM payment_allocations pa
             WHERE (pa.ref_type='sale' AND NOT EXISTS (SELECT 1 FROM sales s WHERE s.id=pa.ref_id))
                OR (pa.ref_type='purchase' AND NOT EXISTS (SELECT 1 FROM purchases pu WHERE pu.id=pa.ref_id))
                OR (pa.ref_type='opening' AND NOT EXISTS (SELECT 1 FROM parties p WHERE p.id=pa.ref_id)) LIMIT 10"), 'warn');

    // ---- the over-claim that reminders and WhatsApp used to send ----
    $add('Party whose bills ask for more than the ledger says',
        'આ પાર્ટીનાં બાકી બિલોનો સરવાળો એના ખરેખરના બાકી હિસાબ કરતાં વધારે છે — પેમેન્ટ આવ્યું છે પણ બિલ સાથે લિંક નથી થયું. પેમેન્ટ ખોલીને બિલ સાથે લિંક કરો, નહીંતર રિમાઇન્ડરમાં વધારે રકમ દેખાય.',
        all("SELECT p.id, p.name who,
                    COALESCE((SELECT SUM(ROUND(s.total - s.paid,2)) FROM sales s WHERE s.party_id=p.id AND s.status<>'paid' AND s.is_cancelled=0),0) amount,
                    " . party_balance_expr('p') . " expect
             FROM parties p
             HAVING amount > expect + 1 AND amount > 0 ORDER BY amount - expect DESC LIMIT 10"), 'warn');

    $add('Duplicate invoice number',
        'એક જ ઇન્વોઇસ નંબર બે બિલ પર છે — GST/હિસાબમાં ગૂંચવાડો થશે.',
        all("SELECT MIN(s.id) id, s.invoice_no no, COUNT(*) amount FROM sales s WHERE s.is_cancelled=0 AND s.invoice_no <> ''
             GROUP BY s.invoice_no HAVING COUNT(*) > 1 LIMIT 10"));

    return array_merge($checks, health_system_checks());
}

/** System-side checks: not "bad data" but "the server is not protecting
 *  you properly". Same row shape as the data checks so the report table
 *  renders them without any special casing. */
function health_system_checks() {
    $out = [];
    $add = function ($title, $gu, $rows, $sev = 'warn') use (&$out) {
        $out[] = ['t' => $title, 'gu' => $gu, 'rows' => $rows, 'sev' => $sev];
    };

    // secrets that are still sitting in the database as readable text
    $plain = [];
    foreach (secret_setting_keys() as $k) {
        $raw = (string)val('SELECT value FROM settings WHERE name = ?', [$k]);
        if ($raw !== '' && strpos($raw, SECRET_PREFIX) !== 0) $plain[] = ['who' => $k];
    }
    $add('API key stored as plain text', 'આ કી ડેટાબેઝમાં ખુલ્લી પડી છે. Settings ખોલીને એક વાર Save કરો — આપોઆપ એન્ક્રિપ્ટ થઈ જશે (રોજની હાઉસકીપિંગ પણ સુધારી દેશે).', $plain);

    // automatic backup: switched on, protected, and actually running
    $b = [];
    if (setting('backup_passphrase', '') === '') {
        $b[] = ['who' => 'બેકઅપનો પાસવર્ડ સેટ નથી — બેકઅપ ફાઈલ ખુલ્લી રહેશે'];
    }
    $lastBk = val("SELECT MAX(finished_at) FROM cron_runs WHERE job='auto_backup' AND status='ok'");
    if (!$lastBk) $b[] = ['who' => 'આજ સુધી એકેય ઓટોમેટિક બેકઅપ સફળ થયું નથી'];
    elseif (strtotime($lastBk) < strtotime('-3 days')) $b[] = ['who' => 'છેલ્લું સફળ બેકઅપ ' . dmy(substr($lastBk, 0, 10)) . ' — 3 દિવસથી જૂનું'];
    $add('Backup is not fully protected', 'Settings → Backup ખોલીને પાસવર્ડ સેટ કરો અને ઓટોમેટિક બેકઅપ ચાલુ છે એ ચકાસો.', $b);

    // the master cron is the heartbeat: no tick = reminders/backups all dead
    $c = [];
    $lastTick = val("SELECT MAX(started_at) FROM cron_runs");
    if (!$lastTick) $c[] = ['who' => 'ક્રોન એકેય વાર ચાલ્યું નથી — હોસ્ટિંગમાં cron સેટ કરવાનું બાકી છે'];
    elseif (strtotime($lastTick) < strtotime('-30 minutes')) $c[] = ['who' => 'છેલ્લે ' . dmyt($lastTick) . ' — ક્રોન બંધ લાગે છે'];
    foreach (all("SELECT job who, COUNT(*) amount FROM cron_runs WHERE status='fail' AND started_at > NOW() - INTERVAL 1 DAY GROUP BY job LIMIT 5") as $f) $c[] = $f;
    $add('Automatic tasks are not running cleanly', 'Cron Manager ખોલીને જુઓ કયું કામ અટક્યું છે — રિમાઇન્ડર, બેકઅપ અને WhatsApp બધું આના પર ચાલે છે.', $c);

    // indexes are what keep the shop fast as the years pile up; they arrive
    // with a migration, so a missing one usually means Update was pressed but
    // Migrate was not
    $wantIdx = [
        'payments' => ['idx_pay_date', 'idx_pay_created_date'],
        'sale_items' => ['idx_si_item'],
        'sales' => ['idx_sale_status_due'],
        'activity_log' => ['idx_log_action_time'],
        'estimates' => ['idx_est_status_date'],
        'web_orders' => ['idx_wo_status', 'idx_wo_created', 'idx_wo_account'],
    ];
    $missing = [];
    try {
        $have = array_column(all("SELECT DISTINCT INDEX_NAME n FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE()"), 'n');
        foreach ($wantIdx as $tbl => $idxs) {
            foreach ($idxs as $ix) if (!in_array($ix, $have, true)) $missing[] = ['who' => "$tbl → $ix"];
        }
    } catch (Exception $e2) { /* no access to information_schema - skip */ }
    $add('Database speed-up indexes are missing',
         'આ ઇન્ડેક્સ વગર મોટા રિપોર્ટ અને પેમેન્ટની યાદી ધીમી ચાલશે. Settings → Update પછી "Migrate" પણ એક વાર દબાવો, પછી આ લિસ્ટ ખાલી થઈ જશે.', $missing);

    // errors the software hit recently
    $e = [];
    $logf = function_exists('error_log_path') ? error_log_path() : '';
    if ($logf && is_file($logf) && filesize($logf) > 0) {
        $lines = @file($logf, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $recent = 0;
        $cut = date('Y-m-d', strtotime('-7 days'));
        foreach ($lines as $ln) if (substr($ln, 1, 10) >= $cut) $recent++;
        if ($recent) $e[] = ['who' => 'છેલ્લા 7 દિવસમાં ' . $recent . ' ભૂલ નોંધાઈ', 'amount' => $recent];
    }
    $add('Software errors were recorded', 'Settings → Backup ના તળિયે છેલ્લી ભૂલો વાંચી શકાય છે. વારંવાર એક જ ભૂલ આવે તો જણાવો.', $e);

    return $out;
}

/** Just the failing checks, as ['title' => count] - what the weekly
 *  cron auto-run reports to the owner. */
function health_issue_summary() {
    $out = [];
    foreach (health_checks() as $c) {
        if (count($c['rows']) > 0) $out[$c['t']] = count($c['rows']);
    }
    return $out;
}
