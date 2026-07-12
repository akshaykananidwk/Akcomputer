<?php
// Reports hub: sales / purchase / GST / profit / staff stock / repair TAT / warranty company TAT
require_once __DIR__ . '/includes/init.php';
require_perm('reports.view');

$r = get('r', 'daily');
$from = get('from', date('Y-m-01'));
$to = get('to', today());
$page_title = 'Reports';
include __DIR__ . '/includes/header.php';

$tabs = [
    'business' => '🏢 Business Report',
    'daily' => '📅 Daily Sales', 'sales' => '🧾 Sales', 'party_sales' => '👥 Party Sales',
    'aging' => '⏳ Aging / Collection',
    'purchase' => '📦 Purchase', 'vendor_perf' => '🚚 Vendor Performance', 'stockval' => '📊 Stock Report', 'cashbook' => '💵 Cashbook',
    'expense' => '🧾 Expenses', 'gst' => '🧮 GST', 'profit' => '💹 Profit',
    'bill_profit' => '🧮 Bill Profit', 'staff' => '🎒 Staff Stock',
    'repair_tat' => '🛠️ Repair TAT', 'warranty_tat' => '🛡️ Warranty TAT', 'tech_sla' => '⏱️ Technician SLA', 'low' => '⚠️ Low Stock',
    'activity' => '🔍 Activity Log',
];
if (!can('reports.gst')) unset($tabs['gst']);
if (!can('reports.profit')) { unset($tabs['profit']); unset($tabs['bill_profit']); unset($tabs['stockval']); unset($tabs['business']); }
if (!can('expenses.view')) unset($tabs['expense']);
if (!can('users.view')) unset($tabs['activity']);
if ($r === 'business' && !can('reports.profit')) $r = 'daily';
if ($r === 'activity' && !can('users.view')) $r = 'daily';
?>
<div class="page-actions" style="overflow-x:auto;flex-wrap:nowrap">
<?php foreach ($tabs as $k => $label): ?>
  <a class="btn btn-sm <?= $r === $k ? '' : 'btn-outline' ?>" href="reports.php?r=<?= $k ?>&from=<?= e($from) ?>&to=<?= e($to) ?>" style="white-space:nowrap"><?= $label ?></a>
<?php endforeach; ?>
</div>
<form method="get" class="filterbar">
  <input type="hidden" name="r" value="<?= e($r) ?>">
  <div><label>From</label><input type="date" name="from" value="<?= e($from) ?>"></div>
  <div><label>To</label><input type="date" name="to" value="<?= e($to) ?>"></div>
  <button class="btn btn-sm" type="submit">Apply</button>
  <button class="btn btn-sm btn-outline no-print" type="button" onclick="window.print()">🖨️ Print</button>
  <button class="btn btn-sm btn-outline no-print" type="button" onclick="exportCsv()">⬇ Excel/CSV</button>
  <?php if ($r === 'sales' || $r === 'purchase'): ?>
  <a class="btn btn-sm btn-outline no-print" href="tally_export.php?type=<?= $r ?>&from=<?= e($from) ?>&to=<?= e($to) ?>">⬇ Tally XML</a>
  <?php endif; ?>
</form>
<script>
function exportCsv() {
  var rows = [];
  document.querySelectorAll('.content table tr').forEach(function (tr) {
    var cells = [];
    tr.querySelectorAll('th,td').forEach(function (c) {
      cells.push('"' + c.innerText.trim().replace(/"/g, '""') + '"');
    });
    if (cells.length) rows.push(cells.join(','));
  });
  var blob = new Blob(["﻿" + rows.join('\n')], {type: 'text/csv;charset=utf-8'});
  var a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = 'report-<?= e($r) ?>-<?= e($from) ?>-to-<?= e($to) ?>.csv';
  a.click();
}
</script>

<?php
// ---------------- business report (P&L) ----------------
if ($r === 'business' && can('reports.profit')) {
    $sales = (float)val('SELECT COALESCE(SUM(total),0) FROM sales WHERE is_cancelled = 0 AND sale_date BETWEEN ? AND ?', [$from, $to]);
    $salesRet = (float)val('SELECT COALESCE(SUM(total),0) FROM sales_returns WHERE return_date BETWEEN ? AND ?', [$from, $to]);
    $cogs = (float)val('SELECT COALESCE(SUM(si.qty * IF(si.cost_price > 0, si.cost_price, i.purchase_price)),0)
                        FROM sale_items si JOIN sales s ON s.id = si.sale_id JOIN items i ON i.id = si.item_id
                        WHERE s.is_cancelled = 0 AND s.sale_date BETWEEN ? AND ?', [$from, $to]);
    $purch = (float)val('SELECT COALESCE(SUM(total),0) FROM purchases WHERE purchase_date BETWEEN ? AND ?', [$from, $to]);
    $purchRet = (float)val('SELECT COALESCE(SUM(total),0) FROM purchase_returns WHERE return_date BETWEEN ? AND ?', [$from, $to]);
    $svc = (float)val('SELECT COALESCE(SUM(service_charge),0) FROM tasks WHERE status = "completed" AND DATE(end_time) BETWEEN ? AND ?', [$from, $to]);
    $repairIncome = (float)val('SELECT COALESCE(SUM(final_charge),0) FROM repairs WHERE status = "delivered" AND delivered_date BETWEEN ? AND ?', [$from, $to]);
    $repairCost = (float)val('SELECT COALESCE(SUM(outsource_cost),0) FROM repairs WHERE status = "delivered" AND delivered_date BETWEEN ? AND ?', [$from, $to]);
    $exp = (float)val('SELECT COALESCE(SUM(amount),0) FROM expenses WHERE exp_date BETWEEN ? AND ?', [$from, $to]);
    $recv = (float)val("SELECT COALESCE(SUM(total - paid),0) FROM sales WHERE status <> 'paid' AND is_cancelled = 0");
    $paybl = (float)val("SELECT COALESCE(SUM(total - paid),0) FROM purchases WHERE status <> 'paid'");
    $stockVal = (float)val('SELECT COALESCE(SUM(sq.q * i.purchase_price),0) FROM
                            (SELECT item_id, SUM(qty) q FROM (SELECT item_id, qty FROM stock UNION ALL SELECT item_id, qty FROM staff_stock) z GROUP BY item_id) sq
                            JOIN items i ON i.id = sq.item_id');
    $netSales = $sales - $salesRet;
    $gross = $netSales - $cogs;
    $serviceProfit = $svc + $repairIncome - $repairCost;
    $net = $gross + $serviceProfit - $exp;

    echo '<div class="grid-stats">';
    echo '<div class="stat"><div class="stat-label">Net Sales</div><div class="stat-value">₹' . money($netSales) . '</div></div>';
    echo '<div class="stat s-ok"><div class="stat-label">Gross Profit (items)</div><div class="stat-value">₹' . money($gross) . '</div></div>';
    echo '<div class="stat s-ok"><div class="stat-label">Service + Repair</div><div class="stat-value">₹' . money($serviceProfit) . '</div></div>';
    echo '<div class="stat ' . ($net >= 0 ? 's-ok' : 's-bad') . '"><div class="stat-label">NET PROFIT</div><div class="stat-value">₹' . money($net) . '</div></div>';
    echo '</div>';

    echo '<div class="card"><h2>Profit & Loss (' . dmy($from) . ' → ' . dmy($to) . ')</h2>';
    echo '<div class="table-wrap" style="box-shadow:none"><table class="table-sm"><tbody>';
    $rows = [
        ['Sales', $sales, ''], ['Less: Sales Returns', -$salesRet, ''],
        ['Net Sales', $netSales, 'B'],
        ['Less: Cost of goods sold', -$cogs, ''],
        ['Gross Profit', $gross, 'B'],
        ['Add: Service charges (tasks)', $svc, ''],
        ['Add: Repair income', $repairIncome, ''], ['Less: Repair outsource cost', -$repairCost, ''],
        ['Less: Expenses', -$exp, ''],
        ['NET PROFIT', $net, 'B'],
    ];
    foreach ($rows as $x) {
        $b = $x[2] === 'B';
        echo '<tr' . ($b ? ' style="border-top:2px solid var(--text)"' : '') . '><td>' . ($b ? '<strong>' : '') . e($x[0]) . ($b ? '</strong>' : '') .
             '</td><td class="num" style="color:' . ($x[1] < 0 ? 'var(--bad)' : 'inherit') . '">' . ($b ? '<strong>' : '') .
             money($x[1]) . ($b ? '</strong>' : '') . '</td></tr>';
    }
    echo '</tbody></table></div></div>';

    echo '<div class="grid-stats">';
    echo '<div class="stat"><div class="stat-label">Purchases (period)</div><div class="stat-value">₹' . money($purch - $purchRet) . '</div></div>';
    echo '<div class="stat s-ok"><div class="stat-label">To Receive</div><div class="stat-value">₹' . money($recv) . '</div></div>';
    echo '<div class="stat s-bad"><div class="stat-label">To Pay</div><div class="stat-value">₹' . money($paybl) . '</div></div>';
    echo '<div class="stat"><div class="stat-label">Stock Value (today)</div><div class="stat-value">₹' . money($stockVal) . '</div></div>';
    echo '</div>';
}

// ---------------- daily sales ----------------
if ($r === 'daily') {
    $rows = all('SELECT sale_date d, COUNT(*) bills, SUM(total) total, SUM(paid) paid FROM sales
                 WHERE sale_date BETWEEN ? AND ? GROUP BY sale_date ORDER BY sale_date DESC', [$from, $to]);
    echo '<div class="table-wrap"><table><thead><tr><th>Date</th><th class="num">Bills</th><th class="num">Sales ₹</th><th class="num">Collected ₹</th><th class="num">Credit ₹</th></tr></thead><tbody>';
    $tT = $tP = 0;
    foreach ($rows as $x) {
        $tT += $x['total']; $tP += $x['paid'];
        echo '<tr><td>' . dmy($x['d']) . '</td><td class="num">' . $x['bills'] . '</td><td class="num">' . money($x['total']) .
             '</td><td class="num">' . money($x['paid']) . '</td><td class="num">' . money($x['total'] - $x['paid']) . '</td></tr>';
    }
    echo '<tr><td><strong>Total</strong></td><td></td><td class="num"><strong>' . money($tT) . '</strong></td><td class="num"><strong>' . money($tP) . '</strong></td><td class="num"><strong>' . money($tT - $tP) . '</strong></td></tr>';
    echo '</tbody></table></div>';
}

// ---------------- item-wise sales ----------------
if ($r === 'sales') {
    $rows = all('SELECT i.name, SUM(si.qty) qty, SUM(si.total) amount FROM sale_items si
                 JOIN sales s ON s.id = si.sale_id JOIN items i ON i.id = si.item_id
                 WHERE s.is_cancelled = 0 AND s.sale_date BETWEEN ? AND ? GROUP BY si.item_id ORDER BY amount DESC', [$from, $to]);
    echo '<div class="table-wrap"><table><thead><tr><th>Item</th><th class="num">Qty sold</th><th class="num">Amount ₹</th></tr></thead><tbody>';
    foreach ($rows as $x) echo '<tr><td>' . e($x['name']) . '</td><td class="num">' . (float)$x['qty'] . '</td><td class="num">' . money($x['amount']) . '</td></tr>';
    echo '<tr><td><strong>Total</strong></td><td></td><td class="num"><strong>' . money(array_sum(array_column($rows, 'amount'))) . '</strong></td></tr></tbody></table></div>';
}

// ---------------- purchase ----------------
if ($r === 'purchase') {
    $rows = all('SELECT pt.name, COUNT(*) bills, SUM(p.total) total, SUM(p.paid) paid FROM purchases p
                 JOIN parties pt ON pt.id = p.party_id
                 WHERE p.purchase_date BETWEEN ? AND ? GROUP BY p.party_id ORDER BY total DESC', [$from, $to]);
    echo '<div class="table-wrap"><table><thead><tr><th>Supplier</th><th class="num">Bills</th><th class="num">Total ₹</th><th class="num">Paid ₹</th><th class="num">Due ₹</th></tr></thead><tbody>';
    foreach ($rows as $x) echo '<tr><td>' . e($x['name']) . '</td><td class="num">' . $x['bills'] . '</td><td class="num">' . money($x['total']) . '</td><td class="num">' . money($x['paid']) . '</td><td class="num">' . money($x['total'] - $x['paid']) . '</td></tr>';
    echo '</tbody></table></div>';
}

// ---------------- vendor performance ----------------
if ($r === 'vendor_perf') {
    $rows = all("SELECT pt.id, pt.name,
                 COUNT(p.id) bills, COALESCE(SUM(p.total),0) spend,
                 COALESCE((SELECT SUM(pr.total) FROM purchase_returns pr WHERE pr.party_id = pt.id AND pr.return_date BETWEEN ? AND ?),0) returns,
                 MAX(p.purchase_date) last_purchase
                 FROM parties pt JOIN purchases p ON p.party_id = pt.id
                 WHERE p.purchase_date BETWEEN ? AND ?
                 GROUP BY pt.id ORDER BY spend DESC", [$from, $to, $from, $to]);
    echo '<div class="table-wrap"><table><thead><tr><th>Supplier</th><th class="num">Bills</th><th class="num">Total Spend ₹</th><th class="num">Avg Bill ₹</th><th class="num">Returns ₹</th><th class="num">Return %</th><th>Last Purchase</th></tr></thead><tbody>';
    foreach ($rows as $x) {
        $avg = $x['bills'] > 0 ? $x['spend'] / $x['bills'] : 0;
        $retPct = $x['spend'] > 0 ? $x['returns'] / $x['spend'] * 100 : 0;
        echo '<tr><td>' . e($x['name']) . '</td><td class="num">' . $x['bills'] . '</td><td class="num">' . money($x['spend']) . '</td>'
           . '<td class="num">' . money($avg) . '</td><td class="num">' . money($x['returns']) . '</td>'
           . '<td class="num">' . number_format($retPct, 1) . '%</td><td>' . dmy($x['last_purchase']) . '</td></tr>';
    }
    if (!$rows) echo '<tr><td colspan="7" class="muted">આ સમયગાળામાં કોઈ purchase નથી.</td></tr>';
    echo '</tbody></table></div>';
}

// ---------------- GST (company-wise) ----------------
if ($r === 'gst' && can('reports.gst')) {
    foreach (all('SELECT * FROM companies WHERE is_active = 1') as $co) {
        $s = row('SELECT COUNT(*) bills, COALESCE(SUM(subtotal - discount),0) taxable, COALESCE(SUM(tax_amount),0) tax, COALESCE(SUM(total),0) total
                  FROM sales WHERE is_cancelled = 0 AND company_id = ? AND sale_date BETWEEN ? AND ?', [$co['id'], $from, $to]);
        echo '<div class="card"><h2>' . e($co['name']) . ($co['is_gst'] ? ' <span class="badge badge-ok">GST</span> ' . e($co['gstin']) : ' <span class="badge badge-info">Non-GST</span>') . '</h2>';
        echo '<div class="grid-stats">';
        echo '<div class="stat"><div class="stat-label">Bills</div><div class="stat-value">' . $s['bills'] . '</div></div>';
        echo '<div class="stat"><div class="stat-label">Taxable value</div><div class="stat-value">₹' . money($s['taxable']) . '</div></div>';
        echo '<div class="stat"><div class="stat-label">GST collected</div><div class="stat-value">₹' . money($s['tax']) . '</div></div>';
        echo '<div class="stat"><div class="stat-label">Invoice total</div><div class="stat-value">₹' . money($s['total']) . '</div></div>';
        echo '</div>';
        if ($co['is_gst']) {
            $slabs = all('SELECT si.tax_rate, SUM(si.total) taxable, SUM(si.total * si.tax_rate / 100) tax
                          FROM sale_items si JOIN sales s ON s.id = si.sale_id
                          WHERE s.company_id = ? AND s.sale_date BETWEEN ? AND ? GROUP BY si.tax_rate ORDER BY si.tax_rate', [$co['id'], $from, $to]);
            echo '<div class="table-wrap" style="box-shadow:none"><table class="table-sm"><thead><tr><th>GST slab</th><th class="num">Taxable ₹</th><th class="num">CGST ₹</th><th class="num">SGST ₹</th><th class="num">Total tax ₹</th></tr></thead><tbody>';
            foreach ($slabs as $sl) {
                echo '<tr><td>' . (float)$sl['tax_rate'] . '%</td><td class="num">' . money($sl['taxable']) . '</td><td class="num">' . money($sl['tax'] / 2) . '</td><td class="num">' . money($sl['tax'] / 2) . '</td><td class="num">' . money($sl['tax']) . '</td></tr>';
            }
            echo '</tbody></table></div>';
        }
        echo '</div>';
    }
}

// ---------------- profit ----------------
if ($r === 'profit' && can('reports.profit')) {
    $rows = all('SELECT i.name, SUM(si.qty) qty, SUM(si.total) revenue, SUM(si.qty * i.purchase_price) cost
                 FROM sale_items si JOIN sales s ON s.id = si.sale_id JOIN items i ON i.id = si.item_id
                 WHERE s.is_cancelled = 0 AND s.sale_date BETWEEN ? AND ? GROUP BY si.item_id ORDER BY (SUM(si.total) - SUM(si.qty * i.purchase_price)) DESC', [$from, $to]);
    $rev = array_sum(array_column($rows, 'revenue'));
    $cost = array_sum(array_column($rows, 'cost'));
    $svc = (float)val('SELECT COALESCE(SUM(service_charge),0) FROM tasks WHERE status = "completed" AND DATE(end_time) BETWEEN ? AND ?', [$from, $to]);
    $repairProfit = (float)val('SELECT COALESCE(SUM(final_charge - outsource_cost),0) FROM repairs WHERE status = "delivered" AND delivered_date BETWEEN ? AND ?', [$from, $to]);
    echo '<div class="grid-stats">';
    echo '<div class="stat"><div class="stat-label">Revenue</div><div class="stat-value">₹' . money($rev) . '</div></div>';
    echo '<div class="stat"><div class="stat-label">Est. item cost</div><div class="stat-value">₹' . money($cost) . '</div></div>';
    echo '<div class="stat s-ok"><div class="stat-label">Gross item profit</div><div class="stat-value">₹' . money($rev - $cost) . '</div></div>';
    echo '<div class="stat s-ok"><div class="stat-label">Service + Repair profit</div><div class="stat-value">₹' . money($svc + $repairProfit) . '</div></div>';
    echo '</div>';
    echo '<div class="table-wrap"><table><thead><tr><th>Item</th><th class="num">Qty</th><th class="num">Revenue ₹</th><th class="num">Cost ₹</th><th class="num">Profit ₹</th></tr></thead><tbody>';
    foreach ($rows as $x) echo '<tr><td>' . e($x['name']) . '</td><td class="num">' . (float)$x['qty'] . '</td><td class="num">' . money($x['revenue']) . '</td><td class="num">' . money($x['cost']) . '</td><td class="num">' . money($x['revenue'] - $x['cost']) . '</td></tr>';
    echo '</tbody></table></div>';
    echo '<p class="muted">Cost = current item purchase price × qty (estimate).</p>';
}

// ---------------- party-wise sales ----------------
if ($r === 'party_sales') {
    $rows = all("SELECT COALESCE(p.name, CONCAT(s.customer_name, ' (walk-in)'), 'Walk-in') pname,
                 COUNT(*) bills, SUM(s.total) total, SUM(s.paid) paid
                 FROM sales s LEFT JOIN parties p ON p.id = s.party_id
                 WHERE s.is_cancelled = 0 AND s.sale_date BETWEEN ? AND ?
                 GROUP BY COALESCE(CONCAT('p', s.party_id), s.customer_name) ORDER BY total DESC", [$from, $to]);
    echo '<div class="table-wrap"><table><thead><tr><th>Customer / Party</th><th class="num">Bills</th><th class="num">Total ₹</th><th class="num">Paid ₹</th><th class="num">Due ₹</th></tr></thead><tbody>';
    foreach ($rows as $x) echo '<tr><td>' . e($x['pname']) . '</td><td class="num">' . $x['bills'] . '</td><td class="num">' . money($x['total']) . '</td><td class="num">' . money($x['paid']) . '</td><td class="num">' . money($x['total'] - $x['paid']) . '</td></tr>';
    echo '</tbody></table></div>';
}

// ---------------- aging / collection (as of today, ignores from/to) ----------------
if ($r === 'aging') {
    $rows = all("SELECT COALESCE(p.name, NULLIF(s.customer_name, ''), 'Walk-in') pname, s.customer_mobile,
                 p.mobile party_mobile, s.due_date, s.sale_date, (s.total - s.paid) due
                 FROM sales s LEFT JOIN parties p ON p.id = s.party_id
                 WHERE s.is_cancelled = 0 AND s.status <> 'paid'");
    $agg = [];
    foreach ($rows as $x) {
        $base = $x['due_date'] ?: $x['sale_date'];
        $days = days_between($base);
        $bucket = $days <= 30 ? 'b1' : ($days <= 60 ? 'b2' : ($days <= 90 ? 'b3' : 'b4'));
        $key = $x['pname'];
        if (!isset($agg[$key])) $agg[$key] = ['pname' => $key, 'mobile' => $x['party_mobile'] ?: $x['customer_mobile'],
            'b1' => 0, 'b2' => 0, 'b3' => 0, 'b4' => 0, 'total' => 0];
        $agg[$key][$bucket] += $x['due'];
        $agg[$key]['total'] += $x['due'];
    }
    usort($agg, fn($a, $b) => $b['total'] <=> $a['total']);
    echo '<p class="muted mb">આજની તારીખ પ્રમાણે - date filter ને લાગુ પડતું નથી (કેટલા દિવસથી due છે એ પ્રમાણે).</p>';
    echo '<div class="table-wrap"><table><thead><tr><th>Customer / Party</th><th class="num">0-30 દિવસ</th><th class="num">31-60 દિવસ</th><th class="num">61-90 દિવસ</th><th class="num">90+ દિવસ</th><th class="num">Total Due ₹</th><th></th></tr></thead><tbody>';
    $tot = ['b1' => 0, 'b2' => 0, 'b3' => 0, 'b4' => 0, 'total' => 0];
    foreach ($agg as $x) {
        foreach (['b1', 'b2', 'b3', 'b4', 'total'] as $k) $tot[$k] += $x[$k];
        echo '<tr><td>' . e($x['pname']) . '</td>'
           . '<td class="num">' . ($x['b1'] > 0.009 ? '₹' . money($x['b1']) : '·') . '</td>'
           . '<td class="num">' . ($x['b2'] > 0.009 ? '₹' . money($x['b2']) : '·') . '</td>'
           . '<td class="num">' . ($x['b3'] > 0.009 ? '₹' . money($x['b3']) : '·') . '</td>'
           . '<td class="num">' . ($x['b4'] > 0.009 ? '<span class="badge badge-bad">₹' . money($x['b4']) . '</span>' : '·') . '</td>'
           . '<td class="num"><strong>₹' . money($x['total']) . '</strong></td>'
           . '<td>' . ($x['mobile'] ? '<a class="btn btn-sm btn-wa" href="https://wa.me/91' . e(preg_replace('/\D/', '', $x['mobile'])) . '" target="_blank">📲</a>' : '') . '</td></tr>';
    }
    if (!$agg) echo '<tr><td colspan="7" class="muted">All clear 🎉</td></tr>';
    echo '</tbody></table></div>';
    echo '<div class="grid-stats">';
    echo '<div class="stat"><div class="stat-label">0-30 દિવસ</div><div class="stat-value">₹' . money($tot['b1']) . '</div></div>';
    echo '<div class="stat"><div class="stat-label">31-60 દિવસ</div><div class="stat-value">₹' . money($tot['b2']) . '</div></div>';
    echo '<div class="stat"><div class="stat-label">61-90 દિવસ</div><div class="stat-value">₹' . money($tot['b3']) . '</div></div>';
    echo '<div class="stat s-bad"><div class="stat-label">90+ દિવસ (જોખમી)</div><div class="stat-value">₹' . money($tot['b4']) . '</div></div>';
    echo '</div>';
}

// ---------------- stock report (qty + value, location-wise) ----------------
if ($r === 'stockval' && can('reports.profit')) {
    $locs = all('SELECT * FROM locations WHERE is_active = 1 ORDER BY name');
    $stockMap = [];
    foreach (all('SELECT * FROM stock') as $s) $stockMap[$s['item_id']][$s['location_id']] = (float)$s['qty'];
    $staffHeld = [];
    foreach (all('SELECT item_id, SUM(qty) q FROM staff_stock GROUP BY item_id') as $s) $staffHeld[$s['item_id']] = (float)$s['q'];
    $items = all('SELECT * FROM items WHERE is_active = 1 ORDER BY name');
    $grandQty = 0; $grandVal = 0; $grandSale = 0;
    echo '<div class="table-wrap"><table><thead><tr><th>Item</th>';
    foreach ($locs as $l) echo '<th class="num">' . e($l['code']) . '</th>';
    echo '<th class="num">Staff</th><th class="num">Total Qty</th><th class="num">Value (purchase) ₹</th><th class="num">Value (selling) ₹</th></tr></thead><tbody>';
    foreach ($items as $it) {
        $rowQty = $staffHeld[$it['id']] ?? 0;
        echo '<tr><td>' . e($it['name']) . '</td>';
        foreach ($locs as $l) {
            $qv = $stockMap[$it['id']][$l['id']] ?? 0;
            $rowQty += $qv;
            echo '<td class="num">' . ($qv ?: '·') . '</td>';
        }
        $val = $rowQty * (float)$it['purchase_price'];
        $sval = $rowQty * (float)$it['selling_price'];
        $grandQty += $rowQty; $grandVal += $val; $grandSale += $sval;
        echo '<td class="num">' . (($staffHeld[$it['id']] ?? 0) ?: '·') . '</td>';
        echo '<td class="num"><strong>' . $rowQty . '</strong> ' . e($it['unit']) . '</td>';
        echo '<td class="num">' . money($val) . '</td><td class="num">' . money($sval) . '</td></tr>';
    }
    echo '</tbody></table></div>';
    echo '<div class="grid-stats">';
    echo '<div class="stat"><div class="stat-label">કુલ સ્ટોક રોકાણ (purchase ભાવે)</div><div class="stat-value">₹' . money($grandVal) . '</div></div>';
    echo '<div class="stat s-ok"><div class="stat-label">સ્ટોક ની બજાર કિંમત (selling ભાવે)</div><div class="stat-value">₹' . money($grandSale) . '</div></div>';
    echo '<div class="stat"><div class="stat-label">શક્ય નફો stock પર</div><div class="stat-value">₹' . money($grandSale - $grandVal) . '</div></div>';
    echo '</div>';
}

// ---------------- cashbook (day-wise in/out) ----------------
if ($r === 'cashbook') {
    $in = [];
    foreach (all("SELECT pay_date d, SUM(amount) a, mode FROM payments WHERE direction='in' AND pay_date BETWEEN ? AND ? GROUP BY pay_date, mode", [$from, $to]) as $x)
        $in[] = ['d' => $x['d'], 'desc' => 'Party receipt (' . $x['mode'] . ')', 'in' => $x['a'], 'out' => 0];
    foreach (all("SELECT sale_date d, SUM(paid) a FROM sales WHERE party_id IS NULL AND paid > 0 AND sale_date BETWEEN ? AND ? GROUP BY sale_date", [$from, $to]) as $x)
        $in[] = ['d' => $x['d'], 'desc' => 'Walk-in sales collection', 'in' => $x['a'], 'out' => 0];
    foreach (all("SELECT pay_date d, SUM(amount) a, mode FROM payments WHERE direction='out' AND pay_date BETWEEN ? AND ? GROUP BY pay_date, mode", [$from, $to]) as $x)
        $in[] = ['d' => $x['d'], 'desc' => 'Supplier payment (' . $x['mode'] . ')', 'in' => 0, 'out' => $x['a']];
    if (can('expenses.view')) {
        foreach (all("SELECT exp_date d, SUM(amount) a FROM expenses WHERE exp_date BETWEEN ? AND ? GROUP BY exp_date", [$from, $to]) as $x)
            $in[] = ['d' => $x['d'], 'desc' => 'Expenses', 'in' => 0, 'out' => $x['a']];
    }
    usort($in, fn($a, $b) => strcmp($a['d'], $b['d']));
    $bal = 0;
    echo '<div class="table-wrap"><table><thead><tr><th>Date</th><th>Description</th><th class="num">In ₹</th><th class="num">Out ₹</th><th class="num">Running</th></tr></thead><tbody>';
    foreach ($in as $x) {
        $bal += $x['in'] - $x['out'];
        echo '<tr><td>' . dmy($x['d']) . '</td><td>' . e($x['desc']) . '</td><td class="num">' . ($x['in'] ? money($x['in']) : '') . '</td><td class="num">' . ($x['out'] ? money($x['out']) : '') . '</td><td class="num">' . money($bal) . '</td></tr>';
    }
    if (!$in) echo '<tr><td colspan="5" class="muted">No entries in this period.</td></tr>';
    echo '</tbody></table></div>';
    echo '<p class="muted">Note: party વાળા sales bills ની વસૂલી "Party receipt" માં ગણાય છે; walk-in ની અલગ.</p>';
}

// ---------------- expense report ----------------
if ($r === 'expense' && can('expenses.view')) {
    $rows = all('SELECT category, COUNT(*) cnt, SUM(amount) total FROM expenses WHERE exp_date BETWEEN ? AND ? GROUP BY category ORDER BY total DESC', [$from, $to]);
    echo '<div class="table-wrap"><table><thead><tr><th>Category</th><th class="num">Entries</th><th class="num">Total ₹</th></tr></thead><tbody>';
    foreach ($rows as $x) echo '<tr><td>' . e($x['category']) . '</td><td class="num">' . $x['cnt'] . '</td><td class="num">' . money($x['total']) . '</td></tr>';
    echo '<tr><td><strong>Total</strong></td><td></td><td class="num"><strong>' . money(array_sum(array_column($rows, 'total'))) . '</strong></td></tr>';
    echo '</tbody></table></div>';
}

// ---------------- bill-wise profit ----------------
if ($r === 'bill_profit' && can('reports.profit')) {
    $rows = all("SELECT s.id, s.invoice_no, s.sale_date, s.customer_name, s.total,
                 SUM(si.total) rev, SUM(si.qty * IF(si.cost_price > 0, si.cost_price, i.purchase_price)) cost
                 FROM sales s JOIN sale_items si ON si.sale_id = s.id JOIN items i ON i.id = si.item_id
                 WHERE s.is_cancelled = 0 AND s.sale_date BETWEEN ? AND ? GROUP BY s.id ORDER BY s.id DESC", [$from, $to]);
    echo '<div class="table-wrap"><table><thead><tr><th>Invoice</th><th>Date</th><th>Customer</th><th class="num">Bill ₹</th><th class="num">Cost ₹</th><th class="num">Profit ₹</th><th class="num">Margin %</th></tr></thead><tbody>';
    $tp = 0;
    foreach ($rows as $x) {
        $pf = $x['rev'] - $x['cost']; $tp += $pf;
        $mg = $x['rev'] > 0 ? round($pf / $x['rev'] * 100, 1) : 0;
        echo '<tr><td><a href="sale_view.php?id=' . $x['id'] . '">' . e($x['invoice_no']) . '</a></td><td>' . dmy($x['sale_date']) . '</td><td>' . e($x['customer_name'] ?: 'Walk-in') . '</td><td class="num">' . money($x['total']) . '</td><td class="num">' . money($x['cost']) . '</td><td class="num" style="color:' . ($pf >= 0 ? 'var(--ok)' : 'var(--bad)') . '">' . money($pf) . '</td><td class="num">' . $mg . '%</td></tr>';
    }
    echo '<tr><td colspan="5"><strong>Total profit</strong></td><td class="num"><strong>' . money($tp) . '</strong></td><td></td></tr>';
    echo '</tbody></table></div>';
    echo '<p class="muted">Cost = bill વખતનો purchase ભાવ (item દીઠ સાચવેલો). જૂના bills માટે હાલનો purchase ભાવ વપરાય છે.</p>';
}

// ---------------- staff stock ----------------
if ($r === 'staff') {
    $rows = all('SELECT u2.name staff, i.name item, ss.qty, i.unit FROM staff_stock ss
                 JOIN users u2 ON u2.id = ss.user_id JOIN items i ON i.id = ss.item_id
                 WHERE ss.qty > 0 ORDER BY u2.name, i.name');
    echo '<div class="table-wrap"><table><thead><tr><th>Staff</th><th>Item</th><th class="num">Qty held</th></tr></thead><tbody>';
    foreach ($rows as $x) echo '<tr><td>' . e($x['staff']) . '</td><td>' . e($x['item']) . '</td><td class="num">' . (float)$x['qty'] . ' ' . e($x['unit']) . '</td></tr>';
    if (!$rows) echo '<tr><td colspan="3" class="muted">No stock with staff.</td></tr>';
    echo '</tbody></table></div>';
    $used = all('SELECT u2.name staff, i.name item, SUM(tm.qty) qty FROM task_materials tm
                 JOIN tasks t ON t.id = tm.task_id JOIN users u2 ON u2.id = t.assigned_to JOIN items i ON i.id = tm.item_id
                 WHERE DATE(t.end_time) BETWEEN ? AND ? GROUP BY t.assigned_to, tm.item_id ORDER BY u2.name', [$from, $to]);
    echo '<div class="card"><h2>Material used on tasks (period)</h2><div class="table-wrap" style="box-shadow:none"><table class="table-sm"><thead><tr><th>Staff</th><th>Item</th><th class="num">Used</th></tr></thead><tbody>';
    foreach ($used as $x) echo '<tr><td>' . e($x['staff']) . '</td><td>' . e($x['item']) . '</td><td class="num">' . (float)$x['qty'] . '</td></tr>';
    echo '</tbody></table></div></div>';
}

// ---------------- repair party TAT ----------------
if ($r === 'repair_tat') {
    $rows = all('SELECT pt.name party, COUNT(*) jobs,
                 AVG(DATEDIFF(COALESCE(r.received_back_date, CURDATE()), r.sent_date)) avg_days,
                 MAX(DATEDIFF(COALESCE(r.received_back_date, CURDATE()), r.sent_date)) max_days,
                 SUM(r.received_back_date IS NULL) still_out
                 FROM repairs r JOIN parties pt ON pt.id = r.outsource_party_id
                 WHERE r.sent_date IS NOT NULL AND r.sent_date BETWEEN ? AND ?
                 GROUP BY r.outsource_party_id ORDER BY avg_days DESC', [$from, $to]);
    echo '<div class="card"><p class="muted">કઈ repairing party કેટલા દિવસ લગાડે છે — outsourced jobs નું analysis.</p></div>';
    echo '<div class="table-wrap"><table><thead><tr><th>Repair party</th><th class="num">Jobs</th><th class="num">Avg days</th><th class="num">Max days</th><th class="num">Still with them</th></tr></thead><tbody>';
    foreach ($rows as $x) echo '<tr><td>' . e($x['party']) . '</td><td class="num">' . $x['jobs'] . '</td><td class="num">' . round($x['avg_days'], 1) . '</td><td class="num">' . $x['max_days'] . '</td><td class="num">' . $x['still_out'] . '</td></tr>';
    if (!$rows) echo '<tr><td colspan="5" class="muted">No outsourced jobs in this period.</td></tr>';
    echo '</tbody></table></div>';
}

// ---------------- warranty company TAT ----------------
if ($r === 'warranty_tat') {
    $rows = all('SELECT pt.name company, COUNT(*) claims,
                 AVG(DATEDIFF(COALESCE(w.back_date, CURDATE()), w.sent_date)) avg_days,
                 MAX(DATEDIFF(COALESCE(w.back_date, CURDATE()), w.sent_date)) max_days,
                 SUM(w.back_date IS NULL AND w.status = "sent") pending
                 FROM warranty_claims w JOIN parties pt ON pt.id = w.party_id
                 WHERE w.sent_date IS NOT NULL AND w.sent_date BETWEEN ? AND ?
                 GROUP BY w.party_id ORDER BY avg_days DESC', [$from, $to]);
    echo '<div class="card"><p class="muted">કઈ company warranty claim માં કેટલો ટાઈમ લે છે — sent થી back સુધીના દિવસ.</p></div>';
    echo '<div class="table-wrap"><table><thead><tr><th>Company</th><th class="num">Claims</th><th class="num">Avg days</th><th class="num">Max days</th><th class="num">Pending</th></tr></thead><tbody>';
    foreach ($rows as $x) echo '<tr><td>' . e($x['company']) . '</td><td class="num">' . $x['claims'] . '</td><td class="num">' . round($x['avg_days'], 1) . '</td><td class="num">' . $x['max_days'] . '</td><td class="num">' . $x['pending'] . '</td></tr>';
    if (!$rows) echo '<tr><td colspan="5" class="muted">No claims sent in this period.</td></tr>';
    echo '</tbody></table></div>';
}

// ---------------- technician SLA / performance ----------------
if ($r === 'tech_sla') {
    $rows = all("SELECT u2.name staff, COUNT(*) jobs,
                 AVG(TIMESTAMPDIFF(MINUTE, t.start_time, t.end_time)) avg_min,
                 SUM(t.scheduled_date IS NOT NULL AND DATE(t.end_time) <= t.scheduled_date) on_time,
                 SUM(t.scheduled_date IS NOT NULL) has_schedule,
                 SUM(t.service_charge + t.material_total) revenue
                 FROM tasks t JOIN users u2 ON u2.id = t.assigned_to
                 WHERE t.status = 'completed' AND DATE(t.end_time) BETWEEN ? AND ?
                 GROUP BY t.assigned_to ORDER BY jobs DESC", [$from, $to]);
    echo '<div class="card"><p class="muted">Field task / installation job નું completion time અને scheduled date પ્રમાણે on-time % - technician પ્રમાણે.</p></div>';
    echo '<div class="table-wrap"><table><thead><tr><th>Technician</th><th class="num">Jobs Completed</th><th class="num">Avg Duration</th><th class="num">On-Time %</th><th class="num">Revenue ₹</th></tr></thead><tbody>';
    foreach ($rows as $x) {
        $avgMin = $x['avg_min'] !== null ? round($x['avg_min']) : null;
        $pct = $x['has_schedule'] > 0 ? round($x['on_time'] / $x['has_schedule'] * 100) : null;
        $pctCls = $pct === null ? '' : ($pct >= 80 ? 'badge-ok' : ($pct >= 50 ? 'badge-warn' : 'badge-bad'));
        echo '<tr><td>' . e($x['staff']) . '</td><td class="num">' . $x['jobs'] . '</td>'
           . '<td class="num">' . ($avgMin !== null ? ($avgMin >= 60 ? round($avgMin / 60, 1) . ' hr' : $avgMin . ' min') : '-') . '</td>'
           . '<td class="num">' . ($pct !== null ? '<span class="badge ' . $pctCls . '">' . $pct . '%</span>' : '-') . '</td>'
           . '<td class="num">₹' . money($x['revenue']) . '</td></tr>';
    }
    if (!$rows) echo '<tr><td colspan="5" class="muted">No completed tasks in this period.</td></tr>';
    echo '</tbody></table></div>';
}

// ---------------- low stock / auto purchase suggestion ----------------
if ($r === 'low') {
    $rows = all("SELECT i.id, i.name, i.min_stock, i.unit, i.tax_rate, i.purchase_price, COALESCE(SUM(s.qty),0) q,
                 (SELECT pt.name FROM purchases pu JOIN purchase_items pi2 ON pi2.purchase_id = pu.id
                  JOIN parties pt ON pt.id = pu.party_id WHERE pi2.item_id = i.id AND pu.is_cancelled = 0
                  ORDER BY pu.purchase_date DESC, pu.id DESC LIMIT 1) last_supplier
                 FROM items i LEFT JOIN stock s ON s.item_id = i.id
                 WHERE i.is_active = 1 AND i.min_stock > 0 GROUP BY i.id HAVING q < i.min_stock ORDER BY q");
    $canReorder = can('purchases.add') && $rows;
    if ($canReorder) echo '<p class="muted mb">Item(s) પસંદ કરીને નીચે "🛒 Create Purchase for Selected" દબાવો - New Purchase form માં item/qty આપોઆપ ભરાઈને ખૂલશે, party ફક્ત તમારે પસંદ કરવાની.</p>';
    echo '<div class="table-wrap"><table><thead><tr>' . ($canReorder ? '<th></th>' : '') . '<th>Item</th><th class="num">In stock</th><th class="num">Min level</th><th class="num">To order</th><th>Last Supplier</th></tr></thead><tbody>';
    foreach ($rows as $x) {
        $toOrder = max((float)$x['min_stock'] * 2 - (float)$x['q'], (float)$x['min_stock']);
        echo '<tr>';
        if ($canReorder) echo '<td><input type="checkbox" class="reorder-cb" data-id="' . $x['id'] . '" data-name="' . e($x['name']) . '" data-qty="' . $toOrder . '" data-tax="' . (float)$x['tax_rate'] . '" data-price="' . (float)$x['purchase_price'] . '"></td>';
        echo '<td>' . e($x['name']) . '</td><td class="num">' . (float)$x['q'] . '</td><td class="num">' . (float)$x['min_stock'] . '</td>'
           . '<td class="num"><strong>' . $toOrder . ' ' . e($x['unit']) . '</strong></td>'
           . '<td>' . ($x['last_supplier'] ? e($x['last_supplier']) : '<span class="muted">-</span>') . '</td></tr>';
    }
    if (!$rows) echo '<tr><td colspan="5" class="muted">Nothing below minimum. 🎉</td></tr>';
    echo '</tbody></table></div>';
    if ($canReorder) {
        echo '<button type="button" class="btn mt" onclick="buildReorder()">🛒 Create Purchase for Selected</button>';
        echo '<script>
        function buildReorder() {
          var items = [];
          document.querySelectorAll(".reorder-cb:checked").forEach(function (cb) {
            items.push({id: cb.dataset.id, name: cb.dataset.name, qty: cb.dataset.qty, tax: cb.dataset.tax, price: cb.dataset.price});
          });
          if (!items.length) { alert("ઓછામાં ઓછો એક item પસંદ કરો."); return; }
          sessionStorage.setItem("reorderItems", JSON.stringify(items));
          location = "purchases.php?action=new&reorder=1";
        }
        </script>';
    }
}

// ---------------- activity log (who did what, when) ----------------
if ($r === 'activity' && can('users.view')) {
    $logUser = (int)get('log_user');
    $logQ = trim(get('log_q'));
    $where = ['DATE(al.created_at) BETWEEN ? AND ?'];
    $params = [$from, $to];
    if ($logUser) { $where[] = 'al.user_id = ?'; $params[] = $logUser; }
    if ($logQ !== '') { $where[] = '(al.action LIKE ? OR al.details LIKE ?)'; $params[] = "%$logQ%"; $params[] = "%$logQ%"; }
    $logs = all("SELECT al.*, u2.name user_name FROM activity_log al LEFT JOIN users u2 ON u2.id = al.user_id
                 WHERE " . implode(' AND ', $where) . " ORDER BY al.id DESC LIMIT 300", $params);
    $staffAll = all('SELECT id, name FROM users ORDER BY name');
    echo '<form method="get" class="filterbar"><input type="hidden" name="r" value="activity"><input type="hidden" name="from" value="' . e($from) . '"><input type="hidden" name="to" value="' . e($to) . '">';
    echo '<div><label>Staff</label><select name="log_user"><option value="">બધા</option>';
    foreach ($staffAll as $s) echo '<option value="' . $s['id'] . '" ' . ($logUser == $s['id'] ? 'selected' : '') . '>' . e($s['name']) . '</option>';
    echo '</select></div>';
    echo '<div><label>Search (action/details)</label><input type="text" name="log_q" value="' . e($logQ) . '"></div>';
    echo '<button class="btn btn-sm" type="submit">Filter</button></form>';
    echo '<p class="muted mb">છેલ્લા 300 records (' . e($from) . ' થી ' . e($to) . ').</p>';
    echo '<div class="table-wrap"><table><thead><tr><th>Date/Time</th><th>Staff</th><th>Action</th><th>Details</th></tr></thead><tbody>';
    foreach ($logs as $l) {
        echo '<tr><td>' . dmyt($l['created_at']) . '</td><td>' . e($l['user_name'] ?: 'System') . '</td>'
           . '<td><code style="font-size:12px">' . e($l['action']) . '</code></td><td>' . e($l['details']) . '</td></tr>';
    }
    if (!$logs) echo '<tr><td colspan="4" class="muted">કંઈ મળ્યું નહીં.</td></tr>';
    echo '</tbody></table></div>';
}
?>
<?php include __DIR__ . '/includes/footer.php'; ?>
