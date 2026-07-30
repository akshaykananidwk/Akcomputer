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

    return $checks;
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
