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
        'A cash payment has a bank ID stuck on it — this entry shows wrongly in the bank ledger. (Running migrate v41 clears it)',
        all("SELECT py.id, py.pay_date d, py.amount, py.mode, COALESCE(p.name,'Walk-in') who FROM payments py LEFT JOIN parties p ON p.id=py.party_id JOIN payment_methods pm ON pm.code=py.mode WHERE pm.type='cash' AND py.bank_account_id IS NOT NULL LIMIT 10"));
    $add('Cash expense carrying a bank account',
        'A cash expense has a bank ID stuck on it — the bank ledger will be wrong. (migrate v41 clears it)',
        all("SELECT e.id, e.exp_date d, e.amount, e.category who, e.mode FROM expenses e JOIN payment_methods pm ON pm.code=e.mode WHERE pm.type='cash' AND e.bank_account_id IS NOT NULL LIMIT 10"));
    $add('Bank-mode payment without a bank account',
        'A bank or cheque payment with no bank account set — it appears in no bank ledger. Open the payment, choose the bank and update.',
        all("SELECT py.id, py.pay_date d, py.amount, py.mode, COALESCE(p.name,'Walk-in') who FROM payments py LEFT JOIN parties p ON p.id=py.party_id JOIN payment_methods pm ON pm.code=py.mode WHERE pm.type='bank' AND py.bank_account_id IS NULL LIMIT 10"), 'warn');
    $add('Sale bill: paid more than total',
        'more than the bill amount "paid" is recorded — open the bill and correct the paid amount.',
        all("SELECT id, invoice_no no, sale_date d, total, paid FROM sales WHERE paid > total + 0.01 AND is_cancelled=0 LIMIT 10"));
    $add('Sale bill: totals do not add up',
        'The arithmetic on the bill is wrong (subtotal − discount + tax + shipping + adjustment + round off ≠ total). Edit the bill once and save, and it is recalculated.',
        all("SELECT id, invoice_no no, sale_date d, total, ROUND(subtotal - discount + tax_amount + COALESCE(shipping,0) + COALESCE(adjustment,0) + COALESCE(round_off,0), 2) expect FROM sales WHERE is_cancelled=0 AND ABS(ROUND(subtotal - discount + tax_amount + COALESCE(shipping,0) + COALESCE(adjustment,0) + COALESCE(round_off,0), 2) - total) > 1 LIMIT 10"));
    $add('Sale bill: item lines do not match subtotal',
        'The sum of the bill item lines does not match the subtotal — edit the bill and save.',
        all("SELECT s.id, s.invoice_no no, s.sale_date d, s.subtotal amount, ROUND(SUM(si.total),2) expect FROM sales s JOIN sale_items si ON si.sale_id=s.id WHERE s.is_cancelled=0 GROUP BY s.id HAVING ABS(expect - s.subtotal) > 1 LIMIT 10"));
    $add('Purchase bill: paid more than total',
        'A purchase bill is paid more than its total.',
        all("SELECT id, bill_no no, purchase_date d, total, paid FROM purchases WHERE paid > total + 0.01 AND is_cancelled=0 LIMIT 10"));
    $add('Zero-amount purchase bills',
        'A purchase entry of Rs 0 — it looks unfinished; complete it or delete it.',
        all("SELECT id, bill_no no, purchase_date d, total, paid FROM purchases WHERE total <= 0 AND is_cancelled=0 LIMIT 10"), 'warn');
    $add('Serial number sold on two bills at once',
        'The same serial number is on two different bills "sold" — one of the two bills is wrong.',
        all("SELECT i2.name who, s2.serial_no no, COUNT(*) amount FROM item_serials s2 JOIN items i2 ON i2.id=s2.item_id WHERE s2.status='sold' GROUP BY s2.item_id, s2.serial_no HAVING COUNT(*) > 1 LIMIT 10"));
    $add('Same serial in stock twice',
        'The same serial number is in stock twice (a double entry).',
        all("SELECT i2.name who, s2.serial_no no, COUNT(*) amount FROM item_serials s2 JOIN items i2 ON i2.id=s2.item_id WHERE s2.status='in_stock' GROUP BY s2.item_id, s2.serial_no HAVING COUNT(*) > 1 LIMIT 10"));
    // A serial is picked BY LOCATION everywhere - billing, returns, handover -
    // but listed on the item page without one. So a serial in stock with no
    // location, or at a location where this item has no stock, is counted in
    // one place and offered in none: the screens disagree and neither can be
    // made right by hand. (migrate v65 puts the stranded ones back on a shelf)
    $add('Serial in stock but on no shelf',
        'These serials count as in stock but sit at no location — they show on the item page, but cannot be picked on a bill, a sales return or a purchase return. (Running migrate v65 clears this)',
        all("SELECT s2.id, i2.name who, s2.serial_no no FROM item_serials s2 JOIN items i2 ON i2.id = s2.item_id
             WHERE s2.status = 'in_stock'
               AND (s2.location_id IS NULL OR s2.location_id = 0
                    OR NOT EXISTS (SELECT 1 FROM locations l WHERE l.id = s2.location_id)) LIMIT 10"));
    $add('Serial in stock where the item has none',
        'Stock of this item is zero at the location where the serial sits — it can be picked on a bill, but the bill will stop for want of stock. Transfer stock, or use the 🔧 Serial/Stock Repair tool.',
        all("SELECT s2.id, i2.name who, s2.serial_no no, l.name expect FROM item_serials s2
               JOIN items i2 ON i2.id = s2.item_id JOIN locations l ON l.id = s2.location_id
             WHERE s2.status = 'in_stock' AND i2.is_active = 1
               AND COALESCE((SELECT st.qty FROM stock st WHERE st.item_id = s2.item_id AND st.location_id = s2.location_id), 0) <= 0
             LIMIT 10"), 'warn');
    $add('Serial count vs stock quantity mismatch',
        'for serial-tracked items "in stock" The serial count and the stock figure disagree — fix it in two minutes with the 🔧 Serial/Stock Repair tool: open serial_fix.php (link below).',
        all("SELECT i2.id, i2.name who, COALESCE((SELECT SUM(qty) FROM stock st WHERE st.item_id=i2.id),0) amount, (SELECT COUNT(*) FROM item_serials s2 WHERE s2.item_id=i2.id AND s2.status='in_stock') expect FROM items i2 WHERE i2.serial_tracked=1 AND i2.is_active=1 HAVING ABS(amount - expect) > 0 LIMIT 10"), 'warn');
    $add('Cancelled bills still holding payments',
        'Payments are still attached to a cancelled bill — that money counts wrongly in the ledger.',
        all("SELECT py.id, s.invoice_no no, py.pay_date d, py.amount FROM payments py JOIN sales s ON s.id=py.ref_id AND py.ref_type='sale' WHERE s.is_cancelled=1 LIMIT 10"));
    $add('Payments pointing to deleted bills',
        'The bill a payment was linked to no longer exists — open the payment and check (it does still count in the party balance).',
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
        'The item stock and the sum of its movements do not agree. If the opening stock was entered from outside the software this difference is normal — do not worry. But if the difference suddenly changes, Adjust from the Stock page and enter the right figure.',
        all("SELECT i.id, i.name who, st.qty amount, COALESCE(sl.s,0) expect
             FROM stock st JOIN items i ON i.id=st.item_id
             JOIN (SELECT item_id, location_id, SUM(change_qty) s FROM stock_ledger GROUP BY item_id, location_id) sl
               ON sl.item_id=st.item_id AND sl.location_id=st.location_id
             WHERE ABS(st.qty - COALESCE(sl.s,0)) > 0.01 LIMIT 10"), 'warn');
    $add('Negative stock',
        'Stock is negative — more has been sold than there was. Enter any missing purchase.',
        all("SELECT i.id, i.name who, st.qty amount, l.name no FROM stock st JOIN items i ON i.id=st.item_id
             LEFT JOIN locations l ON l.id=st.location_id WHERE st.qty < -0.01 LIMIT 10"), 'warn');

    // ---- allocations: the link between a payment and the bill it settles ----
    $add('Bill "paid" does not match its linked payments',
        'written on the bill "paid" The amount and the sum of the payments linked to it do not agree — open the bill and relink the payments.',
        all("SELECT s.id, s.invoice_no no, s.sale_date d, s.paid amount, ROUND(SUM(pa.amount),2) expect
             FROM sales s JOIN payment_allocations pa ON pa.ref_type='sale' AND pa.ref_id=s.id
             WHERE s.is_cancelled=0 GROUP BY s.id HAVING ABS(s.paid - expect) > 1 LIMIT 10"), 'warn');
    $add('Payment linked to more than it is worth',
        'A payment is spread across bills for more than its own amount — the links are wrong, open the payment and fix them.',
        all("SELECT py.id, py.pay_date d, py.amount, ROUND(SUM(pa.amount),2) expect, COALESCE(p.name,'Walk-in') who
             FROM payments py JOIN payment_allocations pa ON pa.payment_id=py.id
             LEFT JOIN parties p ON p.id=py.party_id
             GROUP BY py.id HAVING expect > py.amount + 1 LIMIT 10"));
    $add('Payment link pointing at a deleted bill',
        'The bill a payment link pointed at no longer exists — the link is dangling.',
        all("SELECT pa.id, pa.amount, pa.ref_type who FROM payment_allocations pa
             WHERE (pa.ref_type='sale' AND NOT EXISTS (SELECT 1 FROM sales s WHERE s.id=pa.ref_id))
                OR (pa.ref_type='purchase' AND NOT EXISTS (SELECT 1 FROM purchases pu WHERE pu.id=pa.ref_id))
                OR (pa.ref_type='opening' AND NOT EXISTS (SELECT 1 FROM parties p WHERE p.id=pa.ref_id)) LIMIT 10"), 'warn');

    // ---- the over-claim that reminders and WhatsApp used to send ----
    $add('Party whose bills ask for more than the ledger says',
        'The sum of this party outstanding bills is more than what they really owe — a payment has come in but is not linked to a bill. Open the payment and link it, or a reminder will ask for too much.',
        all("SELECT p.id, p.name who,
                    COALESCE((SELECT SUM(ROUND(s.total - s.paid,2)) FROM sales s WHERE s.party_id=p.id AND s.status<>'paid' AND s.is_cancelled=0),0) amount,
                    " . party_balance_expr('p') . " expect
             FROM parties p
             HAVING amount > expect + 1 AND amount > 0 ORDER BY amount - expect DESC LIMIT 10"), 'warn');

    $add('Duplicate invoice number',
        'The same invoice number is on two bills — this will confuse GST and the accounts.',
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
    $add('API key stored as plain text', 'This key is lying unencrypted in the database. Open Settings and save once — it is encrypted automatically (the daily housekeeping fixes it too).', $plain);

    // automatic backup: switched on, protected, and actually running
    $b = [];
    if (setting('backup_passphrase', '') === '') {
        $b[] = ['who' => 'No backup password is set — the backup file stays unlocked'];
    }
    $lastBk = val("SELECT MAX(finished_at) FROM cron_runs WHERE job='auto_backup' AND status='ok'");
    if (!$lastBk) $b[] = ['who' => 'No automatic backup has ever succeeded'];
    elseif (strtotime($lastBk) < strtotime('-3 days')) $b[] = ['who' => 'Last successful backup ' . dmy(substr($lastBk, 0, 10)) . ' — older than 3 days'];
    $add('Backup is not fully protected', 'Open Settings → Backup, set a password and check that automatic backup is on.', $b);

    // the master cron is the heartbeat: no tick = reminders/backups all dead
    $c = [];
    $lastTick = val("SELECT MAX(started_at) FROM cron_runs");
    if (!$lastTick) $c[] = ['who' => 'The cron has never run — it still has to be set up in the hosting'];
    elseif (strtotime($lastTick) < strtotime('-30 minutes')) $c[] = ['who' => 'Last ' . dmyt($lastTick) . ' — the cron looks to be off'];
    foreach (all("SELECT job who, COUNT(*) amount FROM cron_runs WHERE status='fail' AND started_at > NOW() - INTERVAL 1 DAY GROUP BY job LIMIT 5") as $f) $c[] = $f;
    $add('Automatic tasks are not running cleanly', 'Open Cron Manager to see which job is stuck — reminders, backups and WhatsApp all run on it.', $c);

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
         'Without this index the big reports and the payment list run slowly. After Settings → Update "Migrate" press it once too, and this list empties.', $missing);

    // errors the software hit recently
    $e = [];
    $logf = function_exists('error_log_path') ? error_log_path() : '';
    if ($logf && is_file($logf) && filesize($logf) > 0) {
        $lines = @file($logf, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $recent = 0;
        $cut = date('Y-m-d', strtotime('-7 days'));
        foreach ($lines as $ln) if (substr($ln, 1, 10) >= $cut) $recent++;
        if ($recent) $e[] = ['who' => 'in the last 7 days ' . $recent . ' Error recorded', 'amount' => $recent];
    }
    $add('Software errors were recorded', 'The latest errors can be read at the bottom of Settings → Backup. Tell us if the same one keeps coming.', $e);

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
