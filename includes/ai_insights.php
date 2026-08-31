<?php
// "AI" features - all rule-based/statistical, not real ML (no server-side
// ML stack, no external AI API anywhere in this project - confirmed by
// research before building this). Weighted-moving-average forecasting is a
// standard, well-understood technique, good enough to guide reorder/demand
// decisions without needing GPUs or a third-party service.

/** Weighted moving average forecast for the NEXT period, weighting recent
 *  values higher (oldest=weight 1 ... newest=weight N). */
function weighted_forecast(array $values) {
    $n = count($values);
    if ($n === 0) return 0.0;
    $weightSum = $n * ($n + 1) / 2;
    $sum = 0;
    foreach (array_values($values) as $i => $v) $sum += $v * ($i + 1);
    return $sum / $weightSum;
}

// Compares the average of the second half of the window against the first
// half, rather than requiring every single step to move the same
// direction - a strict step-by-step check is fragile over a wider window
// (e.g. a newer item's leading zero-sales months before it was even
// stocked would mask a genuine recent uptrend).
function trend_direction(array $values) {
    $vals = array_values($values);
    $n = count($vals);
    if ($n < 2) return 'flat';
    $half = intdiv($n, 2);
    $recentAvg = array_sum(array_slice($vals, -$half)) / $half;
    $earlierAvg = array_sum(array_slice($vals, 0, $n - $half)) / ($n - $half);
    if ($recentAvg <= 0.009 && $earlierAvg <= 0.009) return 'flat';
    if ($recentAvg > $earlierAvg * 1.1) return 'up';
    if ($recentAvg < $earlierAvg * 0.9) return 'down';
    return 'flat';
}

/** Per-item demand history + next-month forecast over the last $months full
 *  calendar months (wider window than the existing 3-month "AI Sales
 *  Forecast" report tab, for a steadier weighted average) - only items with
 *  at least one sale in the window are returned. */
function demand_prediction($months = 6) {
    $monthKeys = [];
    for ($i = $months; $i >= 1; $i--) $monthKeys[] = month_key($i);
    $from = month_start($months);
    $to = date('Y-m-01');
    $rows = all("SELECT si.item_id, DATE_FORMAT(s.sale_date, '%Y-%m') ym, SUM(si.qty) qty
                 FROM sale_items si JOIN sales s ON s.id = si.sale_id
                 WHERE s.is_cancelled = 0 AND s.sale_date >= ? AND s.sale_date < ?
                 GROUP BY si.item_id, ym", [$from, $to]);
    $byItem = [];
    foreach ($rows as $r) $byItem[$r['item_id']][$r['ym']] = (float)$r['qty'];

    $items = all("SELECT id, name, unit FROM items WHERE is_active = 1 AND item_type = 'product'");
    $result = [];
    foreach ($items as $it) {
        $hist = [];
        foreach ($monthKeys as $mk) $hist[] = $byItem[$it['id']][$mk] ?? 0.0;
        if (array_sum($hist) <= 0) continue;
        $result[] = [
            'item_id' => (int)$it['id'], 'name' => $it['name'], 'unit' => $it['unit'],
            'history' => $hist, 'forecast' => round(weighted_forecast($hist), 1),
            'trend' => trend_direction($hist),
        ];
    }
    usort($result, fn($a, $b) => $b['forecast'] <=> $a['forecast']);
    return $result;
}

/** Demand-driven purchase suggestions - a step beyond the existing Low
 *  Stock report's static "qty < min_stock" threshold: this flags items
 *  trending toward running out based on actual sales velocity, even ones
 *  that haven't crossed their min_stock line yet. Suggests topping up to
 *  20% over next month's forecast. */
function purchase_recommendations($months = 6) {
    $demand = demand_prediction($months);
    if (!$demand) return [];
    $stockByItem = array_column(all('SELECT item_id, SUM(qty) q FROM stock GROUP BY item_id'), 'q', 'item_id');
    $itemMeta = array_column(all('SELECT id, purchase_price, tax_rate FROM items'), null, 'id');
    $out = [];
    foreach ($demand as $d) {
        $stock = (float)($stockByItem[$d['item_id']] ?? 0);
        $suggestQty = max(0, ceil($d['forecast'] * 1.2 - $stock));
        if ($suggestQty <= 0) continue;
        $price = (float)($itemMeta[$d['item_id']]['purchase_price'] ?? 0);
        $tax = (float)($itemMeta[$d['item_id']]['tax_rate'] ?? 0);
        $out[] = $d + ['stock' => $stock, 'suggest_qty' => $suggestQty, 'est_cost' => $suggestQty * $price, 'tax_rate' => $tax, 'purchase_price' => $price];
    }
    usort($out, fn($a, $b) => $b['suggest_qty'] <=> $a['suggest_qty']);
    return $out;
}

/** Suggests an expenses.category value from free-text (the Notes field) by
 *  keyword match against the expense_category_keywords table - the
 *  category field itself stays the fixed dropdown it already was (see
 *  expenses.php), this only pre-selects the likely one. */
function suggest_expense_category($text) {
    $text = mb_strtolower(trim((string)$text));
    if ($text === '') return null;
    static $rules = null;
    if ($rules === null) $rules = all('SELECT keyword, category FROM expense_category_keywords');
    foreach ($rules as $rule) {
        if (mb_strpos($text, mb_strtolower($rule['keyword'])) !== false) return $rule['category'];
    }
    return null;
}

/** A handful of plain-English, data-driven observations for the dashboard's
 *  "AI Insights" card - comparisons use the last two FULL calendar months
 *  (not "this month so far" vs last month, which always looks lower purely
 *  because the current month isn't over yet). */
function ai_dashboard_insights($saleScope = '', $saleParams = []) {
    $today = today();
    // anchored months: on the 31st the plain '-1 month' / '-2 months' both
    // landed in July and this compared a month with itself, always 0%
    $m1Start = month_start(1); $m1End = month_end(1);
    $m2Start = month_start(2); $m2End = month_end(2);
    $insights = [];

    $m1 = (float)val("SELECT COALESCE(SUM(total),0) FROM sales WHERE is_cancelled = 0 AND sale_date BETWEEN ? AND ? $saleScope", array_merge([$m1Start, $m1End], $saleParams));
    $m2 = (float)val("SELECT COALESCE(SUM(total),0) FROM sales WHERE is_cancelled = 0 AND sale_date BETWEEN ? AND ? $saleScope", array_merge([$m2Start, $m2End], $saleParams));
    if ($m2 > 0.009) {
        $pct = round((($m1 - $m2) / $m2) * 100, 1);
        $insights[] = ['icon' => $pct >= 0 ? '📈' : '📉', 'text' => 'Sales ' . ($pct >= 0 ? 'grew' : 'fell') . ' ' . abs($pct) . '% in ' . date('M', strtotime($m1Start)) . ' vs ' . date('M', strtotime($m2Start)) . '.'];
    }

    $topExp = row('SELECT category, SUM(amount) amt FROM expenses WHERE exp_date BETWEEN ? AND ? GROUP BY category ORDER BY amt DESC LIMIT 1', [$m1Start, $m1End]);
    $totalExp = (float)val('SELECT COALESCE(SUM(amount),0) FROM expenses WHERE exp_date BETWEEN ? AND ?', [$m1Start, $m1End]);
    if ($topExp && $totalExp > 0.009) {
        $pct = round((float)$topExp['amt'] / $totalExp * 100);
        $insights[] = ['icon' => '💰', 'text' => 'Biggest expense last month: ' . $topExp['category'] . ' (₹' . money($topExp['amt']) . ' · ' . $pct . '% of total expenses).'];
    }

    $deadCount = (int)val("SELECT COUNT(*) FROM items i WHERE i.is_active = 1 AND i.item_type = 'product'
        AND EXISTS (SELECT 1 FROM stock s WHERE s.item_id = i.id AND s.qty > 0)
        AND NOT EXISTS (SELECT 1 FROM sale_items si JOIN sales s2 ON s2.id = si.sale_id
                        WHERE si.item_id = i.id AND s2.is_cancelled = 0 AND s2.sale_date >= DATE_SUB(?, INTERVAL 60 DAY))", [$today]);
    if ($deadCount > 0) $insights[] = ['icon' => '📦', 'text' => $deadCount . ' item(s) in stock haven\'t sold in 60+ days - a clearance push or discount could free up that cash.'];

    $overdue = row("SELECT COUNT(*) c, COALESCE(SUM(total - paid),0) amt FROM sales WHERE status <> 'paid' AND is_cancelled = 0 AND due_date IS NOT NULL AND due_date < ? $saleScope", array_merge([$today], $saleParams));
    if ($overdue && $overdue['c'] > 0) $insights[] = ['icon' => '⏰', 'text' => (int)$overdue['c'] . ' bill(s) overdue, totalling ₹' . money($overdue['amt']) . '.'];

    // This one JOINs, and sale_items has a location_id of its own - so the
    // caller's scope has to be pointed at the sales table before it goes in,
    // or MySQL rejects the query as ambiguous and the whole dashboard 500s.
    $joinScope = scope_for($saleScope, 's');
    $topItem = row("SELECT i.name, SUM(si.qty) q FROM sale_items si JOIN sales s ON s.id = si.sale_id JOIN items i ON i.id = si.item_id
                    WHERE s.is_cancelled = 0 AND s.sale_date BETWEEN ? AND ? $joinScope GROUP BY si.item_id ORDER BY q DESC LIMIT 1", array_merge([$m1Start, $m1End], $saleParams));
    if ($topItem) $insights[] = ['icon' => '🏆', 'text' => 'Best-seller last month: ' . $topItem['name'] . ' (' . (float)$topItem['q'] . ' units).'];

    return array_slice($insights, 0, 5);
}
