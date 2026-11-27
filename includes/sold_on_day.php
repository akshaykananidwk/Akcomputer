<?php
// "What was sold TODAY" - by the day an item was really put on a bill, not
// the bill's date (27-11-2026: a bill made on the 1st, and on the 10th one
// more item the customer took is added to that same bill - that item is
// the 10th's sale and the 10th's profit).
//
// Nothing new is stored: every bill and every bill edit already writes the
// stock movement with its own time (an edit puts the old lines back and
// takes the new ones out in the same moment, so only the change is left on
// that day). Lines with no stock movement (services, kits, very old bills)
// count on the day the bill was entered. Value = the line's price on the
// bill as it is now, less its share of the bill discount; cost the same way
// the Bill Profit report counts it.

require_once __DIR__ . '/money.php';

/** One entry per (day, bill, item): ['d','sale_id','invoice_no','sale_date','customer','item','units','rev','cost'] */
function sold_on_day_rows($from, $to, $ew = '', $ep = []) {
    $toNext = date('Y-m-d', strtotime($to . ' +1 day'));
    $ids = array_map('intval', array_column(all("SELECT DISTINCT ref_id id FROM stock_ledger WHERE ref_type IN ('sale', 'sale_edit') AND created_at >= ? AND created_at < ?
                                                  UNION SELECT id FROM sales WHERE created_at >= ? AND created_at < ?", [$from, $toNext, $from, $toNext]), 'id'));
    if (!$ids) return [];
    $in = implode(',', $ids);
    $sales = [];
    foreach (all("SELECT s.id, s.invoice_no, s.sale_date, s.customer_name, DATE(s.created_at) made, s.discount, s.loyalty_discount, s.adjustment, s.round_off
                  FROM sales s WHERE s.id IN ($in) $ew", $ep) as $s) $sales[(int)$s['id']] = $s;
    if (!$sales) return [];
    $in = implode(',', array_keys($sales));
    $lines = [];
    foreach (all("SELECT si.sale_id, si.item_id, i.name, SUM(si.qty + si.free_qty) units, SUM(si.total) rev, SUM(" . profit_cost_sql() . ") cost
                  FROM sale_items si JOIN items i ON i.id = si.item_id WHERE si.sale_id IN ($in) GROUP BY si.sale_id, si.item_id, i.name") as $l)
        $lines[(int)$l['sale_id']][(int)$l['item_id']] = $l;
    $moves = [];
    foreach (all("SELECT ref_id, item_id, DATE(created_at) d, -SUM(change_qty) q FROM stock_ledger
                  WHERE ref_type IN ('sale', 'sale_edit') AND ref_id IN ($in) GROUP BY ref_id, item_id, DATE(created_at)") as $m)
        if (abs((float)$m['q']) > 0.004) $moves[(int)$m['ref_id']][(int)$m['item_id']][$m['d']] = (float)$m['q'];
    $out = [];
    foreach ($sales as $sid => $s) {
        $billRev = array_sum(array_map(fn($l) => (float)$l['rev'], $lines[$sid] ?? []));
        // the bill discount / adjustment shared out over its lines by value
        $k = $billRev > 0 ? ($billRev - (float)$s['discount'] - (float)$s['loyalty_discount'] + (float)$s['adjustment'] + (float)$s['round_off']) / $billRev : 1;
        foreach ($lines[$sid] ?? [] as $iid => $l) {
            $units = (float)$l['units'];
            $days = $moves[$sid][$iid] ?? [];
            // the stock moves must add up to the line; if not (a kit, a service,
            // a bill older than the stock log) the whole line is the entry day's
            if (abs(array_sum($days) - $units) > 0.01) $days = [$s['made'] => $units];
            foreach ($days as $d => $q) {
                if ($d < $from || $d > $to || $units <= 0) continue;
                $share = $q / $units;
                $out[] = ['d' => $d, 'sale_id' => $sid, 'invoice_no' => $s['invoice_no'], 'sale_date' => $s['sale_date'], 'customer' => $s['customer_name'],
                          'item' => $l['name'], 'units' => $q, 'rev' => round((float)$l['rev'] * $k * $share, 2), 'cost' => round((float)$l['cost'] * $share, 2)];
            }
        }
    }
    usort($out, fn($a, $b) => [$b['d'], $b['sale_id']] <=> [$a['d'], $a['sale_id']]);
    return $out;
}

/** Day totals: d => ['bills','units','rev','cost','late'] (late = put on a bill of an earlier date) */
function sold_on_day_totals(array $rows) {
    $t = [];
    foreach ($rows as $r) {
        $x = &$t[$r['d']];
        $x = ($x ?? ['bills' => [], 'units' => 0, 'rev' => 0, 'cost' => 0, 'late' => 0]);
        $x['bills'][$r['sale_id']] = 1; $x['units'] += $r['units']; $x['rev'] += $r['rev']; $x['cost'] += $r['cost'];
        if ($r['sale_date'] < $r['d']) $x['late'] += $r['rev'];
        unset($x);
    }
    return $t;
}
