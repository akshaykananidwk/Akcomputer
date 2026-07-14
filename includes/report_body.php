<?php
// Renders the table/cards for whichever report tab ($r) is selected, using
// $from/$to/$fCompany/$fParty/$fStatus already set by the caller. Split out
// of reports.php so report_pdf.php can reuse the exact same rendering code
// (captured via output buffering) instead of a second, drift-prone copy.

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
    list($ew, $ep) = report_extra_where('sales', $fCompany, $fParty, $fStatus);
    $rows = all("SELECT sale_date d, COUNT(*) bills, SUM(total) total, SUM(paid) paid FROM sales
                 WHERE sale_date BETWEEN ? AND ? $ew GROUP BY sale_date ORDER BY sale_date DESC", array_merge([$from, $to], $ep));
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
    list($ew, $ep) = report_extra_where('s', $fCompany, $fParty, $fStatus);
    $rows = all("SELECT i.name, SUM(si.qty) qty, SUM(si.total) amount FROM sale_items si
                 JOIN sales s ON s.id = si.sale_id JOIN items i ON i.id = si.item_id
                 WHERE s.is_cancelled = 0 AND s.sale_date BETWEEN ? AND ? $ew GROUP BY si.item_id ORDER BY amount DESC", array_merge([$from, $to], $ep));
    echo '<div class="table-wrap"><table><thead><tr><th>Item</th><th class="num">Qty sold</th><th class="num">Amount ₹</th></tr></thead><tbody>';
    foreach ($rows as $x) echo '<tr><td>' . e($x['name']) . '</td><td class="num">' . (float)$x['qty'] . '</td><td class="num">' . money($x['amount']) . '</td></tr>';
    echo '<tr><td><strong>Total</strong></td><td></td><td class="num"><strong>' . money(array_sum(array_column($rows, 'amount'))) . '</strong></td></tr></tbody></table></div>';
}

// ---------------- purchase ----------------
if ($r === 'purchase') {
    list($ew, $ep) = report_extra_where('p', $fCompany, $fParty, $fStatus);
    $rows = all("SELECT pt.name, COUNT(*) bills, SUM(p.total) total, SUM(p.paid) paid FROM purchases p
                 JOIN parties pt ON pt.id = p.party_id
                 WHERE p.purchase_date BETWEEN ? AND ? $ew GROUP BY p.party_id ORDER BY total DESC", array_merge([$from, $to], $ep));
    echo '<div class="table-wrap"><table><thead><tr><th>Supplier</th><th class="num">Bills</th><th class="num">Total ₹</th><th class="num">Paid ₹</th><th class="num">Due ₹</th></tr></thead><tbody>';
    foreach ($rows as $x) echo '<tr><td>' . e($x['name']) . '</td><td class="num">' . $x['bills'] . '</td><td class="num">' . money($x['total']) . '</td><td class="num">' . money($x['paid']) . '</td><td class="num">' . money($x['total'] - $x['paid']) . '</td></tr>';
    echo '</tbody></table></div>';
}

// ---------------- vendor performance ----------------
if ($r === 'vendor_perf') {
    list($ew, $ep) = report_extra_where('p', $fCompany, $fParty, $fStatus);
    $rows = all("SELECT pt.id, pt.name,
                 COUNT(p.id) bills, COALESCE(SUM(p.total),0) spend,
                 COALESCE((SELECT SUM(pr.total) FROM purchase_returns pr WHERE pr.party_id = pt.id AND pr.return_date BETWEEN ? AND ?),0) returns,
                 MAX(p.purchase_date) last_purchase
                 FROM parties pt JOIN purchases p ON p.party_id = pt.id
                 WHERE p.purchase_date BETWEEN ? AND ? $ew
                 GROUP BY pt.id ORDER BY spend DESC", array_merge([$from, $to, $from, $to], $ep));
    echo '<div class="table-wrap"><table><thead><tr><th>Supplier</th><th class="num">Bills</th><th class="num">Total Spend ₹</th><th class="num">Avg Bill ₹</th><th class="num">Returns ₹</th><th class="num">Return %</th><th>Last Purchase</th></tr></thead><tbody>';
    foreach ($rows as $x) {
        $avg = $x['bills'] > 0 ? $x['spend'] / $x['bills'] : 0;
        $retPct = $x['spend'] > 0 ? $x['returns'] / $x['spend'] * 100 : 0;
        echo '<tr><td>' . e($x['name']) . '</td><td class="num">' . $x['bills'] . '</td><td class="num">' . money($x['spend']) . '</td>'
           . '<td class="num">' . money($avg) . '</td><td class="num">' . money($x['returns']) . '</td>'
           . '<td class="num">' . number_format($retPct, 1) . '%</td><td>' . dmy($x['last_purchase']) . '</td></tr>';
    }
    if (!$rows) echo '<tr><td colspan="7" class="muted">No purchases in this period.</td></tr>';
    echo '</tbody></table></div>';
}

// ---------------- GST (company-wise) ----------------
if ($r === 'gst' && can('reports.gst')) {
    list($ew, $ep) = report_extra_where('sales', 0, $fParty, $fStatus); // company handled by the loop itself
    foreach (all('SELECT * FROM companies WHERE is_active = 1' . ($fCompany ? ' AND id = ' . (int)$fCompany : '')) as $co) {
        $s = row("SELECT COUNT(*) bills, COALESCE(SUM(subtotal - discount),0) taxable, COALESCE(SUM(tax_amount),0) tax, COALESCE(SUM(total),0) total
                  FROM sales WHERE is_cancelled = 0 AND company_id = ? AND sale_date BETWEEN ? AND ? $ew", array_merge([$co['id'], $from, $to], $ep));
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
    list($ew, $ep) = report_extra_where('s', $fCompany, $fParty, $fStatus);
    $rows = all("SELECT i.name, SUM(si.qty) qty, SUM(si.total) revenue, SUM(si.qty * i.purchase_price) cost
                 FROM sale_items si JOIN sales s ON s.id = si.sale_id JOIN items i ON i.id = si.item_id
                 WHERE s.is_cancelled = 0 AND s.sale_date BETWEEN ? AND ? $ew GROUP BY si.item_id ORDER BY (SUM(si.total) - SUM(si.qty * i.purchase_price)) DESC", array_merge([$from, $to], $ep));
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
    list($ew, $ep) = report_extra_where('s', $fCompany, $fParty, $fStatus);
    $rows = all("SELECT COALESCE(p.name, CONCAT(s.customer_name, ' (walk-in)'), 'Walk-in') pname,
                 COUNT(*) bills, SUM(s.total) total, SUM(s.paid) paid
                 FROM sales s LEFT JOIN parties p ON p.id = s.party_id
                 WHERE s.is_cancelled = 0 AND s.sale_date BETWEEN ? AND ? $ew
                 GROUP BY COALESCE(CONCAT('p', s.party_id), s.customer_name) ORDER BY total DESC", array_merge([$from, $to], $ep));
    echo '<div class="table-wrap"><table><thead><tr><th>Customer / Party</th><th class="num">Bills</th><th class="num">Total ₹</th><th class="num">Paid ₹</th><th class="num">Due ₹</th></tr></thead><tbody>';
    foreach ($rows as $x) echo '<tr><td>' . e($x['pname']) . '</td><td class="num">' . $x['bills'] . '</td><td class="num">' . money($x['total']) . '</td><td class="num">' . money($x['paid']) . '</td><td class="num">' . money($x['total'] - $x['paid']) . '</td></tr>';
    echo '</tbody></table></div>';
}

// ---------------- aging / collection (as of today, ignores from/to) ----------------
if ($r === 'aging') {
    $ewA = ''; $epA = [];
    if ($fCompany) { $ewA .= ' AND s.company_id = ?'; $epA[] = $fCompany; }
    if ($fParty) { $ewA .= ' AND s.party_id = ?'; $epA[] = $fParty; }
    $rows = all("SELECT COALESCE(p.name, NULLIF(s.customer_name, ''), 'Walk-in') pname, s.customer_mobile,
                 p.mobile party_mobile, s.due_date, s.sale_date, (s.total - s.paid) due
                 FROM sales s LEFT JOIN parties p ON p.id = s.party_id
                 WHERE s.is_cancelled = 0 AND s.status <> 'paid' $ewA", $epA);
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
    echo '<p class="muted mb">As of today - the date filter does not apply here (based on how many days overdue).</p>';
    echo '<div class="table-wrap"><table><thead><tr><th>Customer / Party</th><th class="num">0-30 days</th><th class="num">31-60 days</th><th class="num">61-90 days</th><th class="num">90+ days</th><th class="num">Total Due ₹</th><th></th></tr></thead><tbody>';
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
    echo '<div class="stat"><div class="stat-label">0-30 days</div><div class="stat-value">₹' . money($tot['b1']) . '</div></div>';
    echo '<div class="stat"><div class="stat-label">31-60 days</div><div class="stat-value">₹' . money($tot['b2']) . '</div></div>';
    echo '<div class="stat"><div class="stat-label">61-90 days</div><div class="stat-value">₹' . money($tot['b3']) . '</div></div>';
    echo '<div class="stat s-bad"><div class="stat-label">90+ days (risky)</div><div class="stat-value">₹' . money($tot['b4']) . '</div></div>';
    echo '</div>';
}

// ---------------- stock report (qty + value, location-wise) ----------------
if ($r === 'stockval' && can('reports.profit')) {
    $locs = all('SELECT * FROM locations WHERE is_active = 1 ORDER BY name');
    $stockMap = [];
    foreach (all('SELECT * FROM stock') as $s) $stockMap[$s['item_id']][$s['location_id']] = (float)$s['qty'];
    $staffHeld = [];
    foreach (all('SELECT item_id, SUM(qty) q FROM staff_stock GROUP BY item_id') as $s) $staffHeld[$s['item_id']] = (float)$s['q'];
    $items = all("SELECT * FROM items WHERE is_active = 1 AND item_type <> 'service' ORDER BY name");
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
    echo '<div class="stat"><div class="stat-label">Total Stock Investment (at purchase price)</div><div class="stat-value">₹' . money($grandVal) . '</div></div>';
    echo '<div class="stat s-ok"><div class="stat-label">Stock Market Value (at selling price)</div><div class="stat-value">₹' . money($grandSale) . '</div></div>';
    echo '<div class="stat"><div class="stat-label">Potential Profit on Stock</div><div class="stat-value">₹' . money($grandSale - $grandVal) . '</div></div>';
    echo '</div>';
}

// ---------------- cashbook (day-wise in/out) ----------------
if ($r === 'cashbook') {
    $in = [];
    // mode <> 'contra' - a Contra/Settle entry (payments.php?action=contra)
    // nets a party's sales due against their purchase due with no real cash
    // or bank movement, so it must never show up as actual cash flow here.
    foreach (all("SELECT pay_date d, SUM(amount) a, mode FROM payments WHERE direction='in' AND mode <> 'contra' AND pay_date BETWEEN ? AND ? GROUP BY pay_date, mode", [$from, $to]) as $x)
        $in[] = ['d' => $x['d'], 'desc' => 'Party receipt (' . $x['mode'] . ')', 'in' => $x['a'], 'out' => 0];
    foreach (all("SELECT sale_date d, SUM(paid) a FROM sales WHERE party_id IS NULL AND paid > 0 AND sale_date BETWEEN ? AND ? GROUP BY sale_date", [$from, $to]) as $x)
        $in[] = ['d' => $x['d'], 'desc' => 'Walk-in sales collection', 'in' => $x['a'], 'out' => 0];
    foreach (all("SELECT pay_date d, SUM(amount) a, mode FROM payments WHERE direction='out' AND mode <> 'contra' AND pay_date BETWEEN ? AND ? GROUP BY pay_date, mode", [$from, $to]) as $x)
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
    echo '<p class="muted">Note: collection on sales bills with a party is counted under "Party receipt"; walk-in is separate.</p>';
}

// ---------------- bank account ledger (Vyapar-style passbook) ----------------
// Every payment-in/out and expense already carries a bank_account_id - this
// just filters to one account, adds an opening balance carried forward from
// before $from, and enriches each row with the actual invoice/bill number
// and customer/party/category name so it reads like a real bank statement
// instead of a raw payments dump.
if ($r === 'bank_ledger' && can('payments.view')) {
    $bank = $bankId ? row('SELECT * FROM bank_accounts WHERE id = ?', [$bankId]) : null;
    if (!$bank) {
        echo '<p class="muted">First add a bank account from Settings &gt; Bank Accounts.</p>';
    } else {
        $openingBal = (float)val("SELECT b.opening_balance
            + COALESCE((SELECT SUM(amount) FROM payments WHERE bank_account_id=? AND direction='in' AND pay_date < ?),0)
            - COALESCE((SELECT SUM(amount) FROM payments WHERE bank_account_id=? AND direction='out' AND pay_date < ?),0)
            - COALESCE((SELECT SUM(amount) FROM expenses WHERE bank_account_id=? AND exp_date < ?),0)
            FROM bank_accounts b WHERE b.id=?", [$bankId, $from, $bankId, $from, $bankId, $from, $bankId]);

        $rows = [];
        $pays = all("SELECT p.*, pt.name party_name FROM payments p LEFT JOIN parties pt ON pt.id = p.party_id
                     WHERE p.bank_account_id = ? AND p.pay_date BETWEEN ? AND ? ORDER BY p.pay_date, p.id", [$bankId, $from, $to]);
        foreach ($pays as $p) {
            $refNo = '-'; $link = null;
            if ($p['ref_type'] === 'sale' && $p['ref_id']) {
                $refNo = val('SELECT invoice_no FROM sales WHERE id = ?', [$p['ref_id']]) ?: '-';
                $link = 'sale_view.php?id=' . (int)$p['ref_id'];
            } elseif ($p['ref_type'] === 'purchase' && $p['ref_id']) {
                $refNo = val("SELECT IF(bill_no = '', CONCAT('#', id), bill_no) FROM purchases WHERE id = ?", [$p['ref_id']]) ?: '-';
                $link = 'purchase_view.php?id=' . (int)$p['ref_id'];
            } elseif ($p['party_id']) {
                $link = 'parties.php?action=ledger&id=' . (int)$p['party_id'];
            }
            $rows[] = ['sort' => $p['pay_date'] . '-' . str_pad($p['id'], 8, '0', STR_PAD_LEFT),
                       'date' => $p['pay_date'], 'type' => $p['direction'] === 'in' ? 'Receipt' : 'Payment',
                       'ref' => $refNo, 'name' => $p['party_name'] ?: 'Walk-in', 'mode' => $p['mode'], 'link' => $link,
                       'in' => $p['direction'] === 'in' ? (float)$p['amount'] : 0, 'out' => $p['direction'] === 'out' ? (float)$p['amount'] : 0];
        }
        foreach (all('SELECT * FROM expenses WHERE bank_account_id = ? AND exp_date BETWEEN ? AND ? ORDER BY exp_date, id', [$bankId, $from, $to]) as $x) {
            $rows[] = ['sort' => $x['exp_date'] . '-9' . str_pad($x['id'], 8, '0', STR_PAD_LEFT),
                       'date' => $x['exp_date'], 'type' => 'Expense', 'ref' => '-', 'name' => $x['category'], 'mode' => $x['mode'],
                       'link' => (can('expenses.view') ? 'expenses.php?from=' . $x['exp_date'] . '&to=' . $x['exp_date'] : null),
                       'in' => 0, 'out' => (float)$x['amount']];
        }
        usort($rows, fn($a, $b) => strcmp($a['sort'], $b['sort']));

        $bal = $openingBal; $totalIn = 0; $totalOut = 0;
        echo '<h3>' . e($bank['account_name']) . ' - ' . e($bank['bank_name']) . ($bank['account_number'] ? ' (A/C: ' . e($bank['account_number']) . ')' : '') . '</h3>';
        // 3 columns (Description+date combined, signed Amount, running Balance)
        // instead of 8 - reads as a clean passbook-style list on screen, while
        // staying a real <table> so report_pdf.php's DOMDocument-to-PDF
        // converter and the CSV exporter (both of which only understand
        // <table> markup) keep working unchanged.
        echo '<div class="table-wrap list-style-table"><table><thead><tr><th data-w="54">Description</th><th class="num" data-w="23">Amount ₹</th><th class="num" data-w="23">Balance ₹</th></tr></thead><tbody>';
        echo '<tr><td><strong>Opening Balance</strong></td><td class="num"></td><td class="num"><strong>₹' . money($openingBal) . '</strong></td></tr>';
        foreach ($rows as $x) {
            $bal += $x['in'] - $x['out'];
            $totalIn += $x['in']; $totalOut += $x['out'];
            $amt = $x['in'] ?: -$x['out'];
            $descBits = [$x['type']];
            if ($x['ref'] !== '-') $descBits[] = $x['ref'];
            if ($x['name'] && $x['name'] !== '-') $descBits[] = $x['name'];
            $descText = e(implode(' - ', $descBits));
            $descHtml = !empty($x['link']) ? '<a href="' . e($x['link']) . '">' . $descText . '</a>' : $descText;
            echo '<tr><td><strong>' . $descHtml . '</strong> <span class="list-row-sub muted">' . dmy($x['date']) . ' · ' . e(ucfirst($x['mode'])) . '</span></td>'
               . '<td class="num" style="color:' . ($amt >= 0 ? 'var(--ok)' : 'var(--bad)') . '"><strong>' . ($amt >= 0 ? '+' : '-') . '₹' . money(abs($amt)) . '</strong></td>'
               . '<td class="num">₹' . money($bal) . '</td></tr>';
        }
        if (!$rows) echo '<tr><td colspan="3" class="muted">No transactions in this period.</td></tr>';
        echo '<tr><td><strong>Total</strong></td><td class="num"><strong>' . ($totalIn - $totalOut >= 0 ? '+' : '-') . '₹' . money(abs($totalIn - $totalOut)) . '</strong></td><td class="num"><strong>₹' . money($bal) . '</strong></td></tr>';
        echo '</tbody></table></div>';
    }
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
    list($ew, $ep) = report_extra_where('s', $fCompany, $fParty, $fStatus);
    $rows = all("SELECT s.id, s.invoice_no, s.sale_date, s.customer_name, s.total,
                 SUM(si.total) rev, SUM(si.qty * IF(si.cost_price > 0, si.cost_price, i.purchase_price)) cost
                 FROM sales s JOIN sale_items si ON si.sale_id = s.id JOIN items i ON i.id = si.item_id
                 WHERE s.is_cancelled = 0 AND s.sale_date BETWEEN ? AND ? $ew GROUP BY s.id ORDER BY s.id DESC", array_merge([$from, $to], $ep));
    echo '<div class="table-wrap"><table><thead><tr><th>Invoice</th><th>Date</th><th>Customer</th><th class="num">Bill ₹</th><th class="num">Cost ₹</th><th class="num">Profit ₹</th><th class="num">Margin %</th></tr></thead><tbody>';
    $tp = 0;
    foreach ($rows as $x) {
        $pf = $x['rev'] - $x['cost']; $tp += $pf;
        $mg = $x['rev'] > 0 ? round($pf / $x['rev'] * 100, 1) : 0;
        echo '<tr><td><a href="sale_view.php?id=' . $x['id'] . '">' . e($x['invoice_no']) . '</a></td><td>' . dmy($x['sale_date']) . '</td><td>' . e($x['customer_name'] ?: 'Walk-in') . '</td><td class="num">' . money($x['total']) . '</td><td class="num">' . money($x['cost']) . '</td><td class="num" style="color:' . ($pf >= 0 ? 'var(--ok)' : 'var(--bad)') . '">' . money($pf) . '</td><td class="num">' . $mg . '%</td></tr>';
    }
    echo '<tr><td colspan="5"><strong>Total profit</strong></td><td class="num"><strong>' . money($tp) . '</strong></td><td></td></tr>';
    echo '</tbody></table></div>';
    echo '<p class="muted">Cost = the purchase price at the time of the bill (saved per item). For old bills, the current purchase price is used.</p>';
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
    echo '<div class="card"><p class="muted">How many days each repairing party takes — analysis of outsourced jobs.</p></div>';
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
    echo '<div class="card"><p class="muted">How long each company takes on warranty claims — days from sent to back.</p></div>';
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
    echo '<div class="card"><p class="muted">On-time % per technician, based on field task/installation job completion time vs. scheduled date.</p></div>';
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

// ---------------- AI sales forecast (weighted moving average) ----------------
// Not a real ML model (no server-side ML stack on shared hosting) - a
// weighted moving average over the last 3 full calendar months, weighted
// 1:2:3 toward the most recent month, is a standard, well-understood
// forecasting technique and good enough to guide "how much should I
// reorder" without needing an external service or GPU.
if ($r === 'forecast') {
    $m3Start = date('Y-m-01', strtotime('first day of -3 months'));
    $curMonthStart = date('Y-m-01');
    $monthly = all("SELECT si.item_id, DATE_FORMAT(s.sale_date, '%Y-%m') ym, SUM(si.qty) qty
                     FROM sale_items si JOIN sales s ON s.id = si.sale_id
                     WHERE s.is_cancelled = 0 AND s.sale_date >= ? AND s.sale_date < ?
                     GROUP BY si.item_id, ym", [$m3Start, $curMonthStart]);
    $byItem = [];
    foreach ($monthly as $m) $byItem[$m['item_id']][$m['ym']] = (float)$m['qty'];
    $months = [];
    for ($i = 3; $i >= 1; $i--) $months[] = date('Y-m', strtotime("-$i months"));

    $items = all('SELECT i.*, COALESCE((SELECT SUM(qty) FROM stock WHERE item_id = i.id),0) stock
                  FROM items i WHERE i.is_active = 1 AND i.item_type = "product" ORDER BY i.name');
    $forecastRows = [];
    foreach ($items as $it) {
        $m = $byItem[$it['id']] ?? [];
        $q1 = $m[$months[0]] ?? 0; $q2 = $m[$months[1]] ?? 0; $q3 = $m[$months[2]] ?? 0;
        if ($q1 == 0 && $q2 == 0 && $q3 == 0) continue; // never sold recently - nothing to forecast
        $forecast = ($q1 * 1 + $q2 * 2 + $q3 * 3) / 6;
        $trend = $q3 > $q2 && $q2 > $q1 ? 'up' : ($q3 < $q2 && $q2 < $q1 ? 'down' : 'flat');
        $suggest = max(0, ceil($forecast - (float)$it['stock']));
        $forecastRows[] = ['it' => $it, 'q1' => $q1, 'q2' => $q2, 'q3' => $q3, 'forecast' => $forecast, 'trend' => $trend, 'suggest' => $suggest];
    }
    usort($forecastRows, fn($a, $b) => $b['forecast'] <=> $a['forecast']);
    $trendIcon = ['up' => '📈', 'down' => '📉', 'flat' => '➡️'];
    echo '<p class="muted mb">Estimate for next month based on the last 3 months\' sales (weighted average, recent months weighted higher) - not true AI/ML, but useful for trend + reorder guidance.</p>';
    echo '<div class="table-wrap"><table><thead><tr><th>Item</th><th class="num">' . $months[0] . '</th><th class="num">' . $months[1] . '</th><th class="num">' . $months[2] . '</th><th class="num">Forecast (next month)</th><th>Trend</th><th class="num">Current Stock</th><th class="num">Suggested Reorder</th></tr></thead><tbody>';
    foreach ($forecastRows as $fr) {
        echo '<tr><td>' . e($fr['it']['name']) . '</td><td class="num">' . $fr['q1'] . '</td><td class="num">' . $fr['q2'] . '</td><td class="num">' . $fr['q3'] . '</td>'
           . '<td class="num"><strong>' . number_format($fr['forecast'], 1) . '</strong> ' . e($fr['it']['unit']) . '</td>'
           . '<td>' . $trendIcon[$fr['trend']] . '</td><td class="num">' . (float)$fr['it']['stock'] . '</td>'
           . '<td class="num">' . ($fr['suggest'] > 0 ? '<strong>' . $fr['suggest'] . '</strong>' : '<span class="muted">-</span>') . '</td></tr>';
    }
    if (!$forecastRows) echo '<tr><td colspan="8" class="muted">No sales data in the last 3 months.</td></tr>';
    echo '</tbody></table></div>';
}

// ---------------- low stock / auto purchase suggestion ----------------
if ($r === 'low') {
    $rows = all("SELECT i.id, i.name, i.min_stock, i.unit, i.tax_rate, i.purchase_price, COALESCE(SUM(s.qty),0) q,
                 (SELECT pt.name FROM purchases pu JOIN purchase_items pi2 ON pi2.purchase_id = pu.id
                  JOIN parties pt ON pt.id = pu.party_id WHERE pi2.item_id = i.id AND pu.is_cancelled = 0
                  ORDER BY pu.purchase_date DESC, pu.id DESC LIMIT 1) last_supplier
                 FROM items i LEFT JOIN stock s ON s.item_id = i.id
                 WHERE i.is_active = 1 AND i.item_type <> 'service' AND i.min_stock > 0 GROUP BY i.id HAVING q < i.min_stock ORDER BY q");
    $canReorder = can('purchases.add') && $rows;
    if ($canReorder) echo '<p class="muted mb">Select item(s) and press "🛒 Create Purchase for Selected" below - the New Purchase form opens with item/qty pre-filled, you just need to pick the party.</p>';
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
          if (!items.length) { alert("Select at least one item."); return; }
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
    echo '<div><label>Staff</label><select name="log_user"><option value="">All</option>';
    foreach ($staffAll as $s) echo '<option value="' . $s['id'] . '" ' . ($logUser == $s['id'] ? 'selected' : '') . '>' . e($s['name']) . '</option>';
    echo '</select></div>';
    echo '<div><label>Search (action/details)</label><input type="text" name="log_q" value="' . e($logQ) . '"></div>';
    echo '<button class="btn btn-sm" type="submit">Filter</button></form>';
    echo '<p class="muted mb">Last 300 records (' . e($from) . ' to ' . e($to) . ').</p>';
    echo '<div class="table-wrap"><table><thead><tr><th>Date/Time</th><th>Staff</th><th>Action</th><th>Details</th></tr></thead><tbody>';
    foreach ($logs as $l) {
        echo '<tr><td>' . dmyt($l['created_at']) . '</td><td>' . e($l['user_name'] ?: 'System') . '</td>'
           . '<td><code style="font-size:12px">' . e($l['action']) . '</code></td><td>' . e($l['details']) . '</td></tr>';
    }
    if (!$logs) echo '<tr><td colspan="4" class="muted">Nothing found.</td></tr>';
    echo '</tbody></table></div>';
}

// ---------------- General Ledger (one account, transaction-level) ----------------
if ($r === 'general_ledger' && can('reports.accounting')) {
    $glAcc = coa_get($glAccount);
    if (!$glAcc) {
        echo '<p class="muted">No Chart of Accounts yet - add one from <a href="accounts.php">Chart of Accounts</a> first.</p>';
    } else {
        $openTo = date('Y-m-d', strtotime($from . ' -1 day'));
        $opening = coa_balance_asof($glAcc, $openTo);
        $glRows = coa_gl_rows($glAcc, $from, $to);
        echo '<div class="card"><h2>' . e($glAcc['code']) . ' - ' . e($glAcc['name']) . ' <span class="muted" style="font-size:13px;font-weight:normal">(' . ucfirst($glAcc['type']) . ')</span></h2>';
        echo '<div class="table-wrap"><table><thead><tr><th>Date</th><th>Description</th><th class="num">Debit</th><th class="num">Credit</th><th class="num">Balance</th></tr></thead><tbody>';
        $bal = $opening;
        echo '<tr style="font-weight:600;background:var(--card-alt,rgba(0,0,0,.03))"><td colspan="4">Opening Balance (' . dmy($from) . ')</td><td class="num">₹' . money(abs($bal)) . ' ' . ($bal < 0 ? 'Cr' : 'Dr') . '</td></tr>';
        $totDr = 0; $totCr = 0;
        foreach ($glRows as $row) {
            $bal += $row['debit'] - $row['credit'];
            $totDr += $row['debit']; $totCr += $row['credit'];
            echo '<tr><td>' . dmy($row['date']) . '</td><td>' . ($row['link'] ? '<a href="' . e($row['link']) . '">' . e($row['desc']) . '</a>' : e($row['desc'])) . '</td>'
               . '<td class="num">' . ($row['debit'] ? '₹' . money($row['debit']) : '') . '</td>'
               . '<td class="num">' . ($row['credit'] ? '₹' . money($row['credit']) : '') . '</td>'
               . '<td class="num">₹' . money(abs($bal)) . ' ' . ($bal < 0 ? 'Cr' : 'Dr') . '</td></tr>';
        }
        if (!$glRows) echo '<tr><td colspan="5" class="muted">No transactions in this period.</td></tr>';
        echo '<tr style="font-weight:600;border-top:2px solid var(--text)"><td colspan="2">Period Total</td><td class="num">₹' . money($totDr) . '</td><td class="num">₹' . money($totCr) . '</td>'
           . '<td class="num">₹' . money(abs($bal)) . ' ' . ($bal < 0 ? 'Cr' : 'Dr') . '</td></tr>';
        echo '</tbody></table></div></div>';
    }
}

// ---------------- Trial Balance (every account, as of "To" date) ----------------
if ($r === 'trial_balance' && can('reports.accounting')) {
    $accts = coa_all();
    $totDr = 0; $totCr = 0;
    echo '<div class="card"><h2>Trial Balance (as of ' . dmy($to) . ')</h2>';
    echo '<div class="table-wrap"><table><thead><tr><th>Code</th><th>Account</th><th>Type</th><th class="num">Debit</th><th class="num">Credit</th></tr></thead><tbody>';
    foreach ($accts as $a) {
        $bal = coa_balance_asof($a, $to);
        $side = coa_normal_side($a['type']);
        if ($side === 'debit') { $dr = $bal > 0 ? $bal : 0; $cr = $bal < 0 ? -$bal : 0; }
        else { $cr = $bal < 0 ? -$bal : 0; $dr = $bal > 0 ? $bal : 0; }
        if ($dr == 0 && $cr == 0) continue;
        $totDr += $dr; $totCr += $cr;
        echo '<tr><td>' . e($a['code']) . '</td><td><a href="reports.php?r=general_ledger&gl_account=' . $a['id'] . '&from=' . e($from) . '&to=' . e($to) . '">' . e($a['name']) . '</a></td><td>' . ucfirst($a['type']) . '</td>'
           . '<td class="num">' . ($dr ? '₹' . money($dr) : '') . '</td><td class="num">' . ($cr ? '₹' . money($cr) : '') . '</td></tr>';
    }
    echo '<tr style="font-weight:600;border-top:2px solid var(--text)"><td colspan="3">Total</td><td class="num">₹' . money($totDr) . '</td><td class="num">₹' . money($totCr) . '</td></tr>';
    echo '</tbody></table></div>';
    if (abs($totDr - $totCr) > 0.01) {
        echo '<p class="muted mt">Debit/Credit differ by ₹' . money(abs($totDr - $totCr)) . ' - this is the shop\'s opening equity (value built up before this Accounting module was switched on) that hasn\'t been posted yet. Post it once via <a href="journal.php?action=new">Journal Entry</a> against "Owner\'s Capital" and this will balance to zero going forward.</p>';
    }
    echo '</div>';
}

// ---------------- Balance Sheet (Assets = Liabilities + Equity, as of "To" date) ----------------
if ($r === 'balance_sheet' && can('reports.accounting')) {
    $accts = coa_all();
    $assets = array_filter($accts, fn($a) => $a['type'] === 'asset');
    $liabs = array_filter($accts, fn($a) => $a['type'] === 'liability');
    $equity = array_filter($accts, fn($a) => $a['type'] === 'equity');
    $totAssets = 0; $totLiab = 0; $totEquity = 0;

    echo '<div class="card"><h2>Balance Sheet (as of ' . dmy($to) . ')</h2>';
    echo '<div class="table-wrap"><table><thead><tr><th>Account</th><th class="num">Amount</th></tr></thead><tbody>';
    echo '<tr style="font-weight:600"><td colspan="2">Assets</td></tr>';
    foreach ($assets as $a) {
        $v = coa_balance_asof($a, $to);
        $totAssets += $v;
        echo '<tr><td style="padding-left:20px">' . e($a['name']) . '</td><td class="num">₹' . money($v) . '</td></tr>';
    }
    echo '<tr style="font-weight:600;border-top:1px solid var(--border,#ddd)"><td>Total Assets</td><td class="num">₹' . money($totAssets) . '</td></tr>';

    echo '<tr style="font-weight:600"><td colspan="2">Liabilities</td></tr>';
    foreach ($liabs as $a) {
        $v = -coa_balance_asof($a, $to);
        $totLiab += $v;
        echo '<tr><td style="padding-left:20px">' . e($a['name']) . '</td><td class="num">₹' . money($v) . '</td></tr>';
    }
    echo '<tr style="font-weight:600;border-top:1px solid var(--border,#ddd)"><td>Total Liabilities</td><td class="num">₹' . money($totLiab) . '</td></tr>';

    echo '<tr style="font-weight:600"><td colspan="2">Equity</td></tr>';
    foreach ($equity as $a) {
        $v = $a['code'] === '3900'
            ? coa_net_profit('0001-01-01', $to) - journal_balance($a['id'], '0001-01-01', $to)
            : -journal_balance($a['id'], '0001-01-01', $to);
        $totEquity += $v;
        echo '<tr><td style="padding-left:20px">' . e($a['name']) . ($a['code'] === '3900' ? ' <span class="muted">(accumulated Net Profit)</span>' : '') . '</td><td class="num">₹' . money($v) . '</td></tr>';
    }
    echo '<tr style="font-weight:600;border-top:1px solid var(--border,#ddd)"><td>Total Equity</td><td class="num">₹' . money($totEquity) . '</td></tr>';
    echo '<tr style="font-weight:700;border-top:2px solid var(--text)"><td>Total Liabilities + Equity</td><td class="num">₹' . money($totLiab + $totEquity) . '</td></tr>';
    echo '</tbody></table></div>';

    $diff = $totAssets - ($totLiab + $totEquity);
    if (abs($diff) > 0.01) {
        echo '<p class="muted mt">Assets and Liabilities+Equity differ by ₹' . money(abs($diff)) . ' - this is the shop\'s opening equity (value built up before this Accounting module was switched on) that hasn\'t been posted yet. Post it once via <a href="journal.php?action=new">Journal Entry</a> against "Owner\'s Capital" and this will balance to zero going forward.</p>';
    }
    echo '</div>';
}

// ---------------- Profit & Loss, Chart-of-Accounts based (period) ----------------
if ($r === 'profit_loss' && can('reports.accounting')) {
    $revenue = coa_sales_revenue($from, $to);
    $customIncome = array_filter(coa_all(), fn($a) => $a['type'] === 'income' && !in_array($a['code'], ['4000', '4100'], true));
    foreach ($customIncome as $a) $revenue += -journal_balance($a['id'], $from, $to);

    $cogs = coa_cogs($from, $to);
    $expCats = coa_expenses_by_category($from, $to);
    $customExpense = array_filter(coa_all(), fn($a) => $a['type'] === 'expense' && $a['code'] !== '5000');
    $totalOpex = array_sum(array_column($expCats, 'total'));
    foreach ($customExpense as $a) $totalOpex += journal_balance($a['id'], $from, $to);

    $gross = $revenue - $cogs;
    $net = $gross - $totalOpex;

    echo '<div class="grid-stats">';
    echo '<div class="stat"><div class="stat-label">Revenue</div><div class="stat-value">₹' . money($revenue) . '</div></div>';
    echo '<div class="stat s-ok"><div class="stat-label">Gross Profit</div><div class="stat-value">₹' . money($gross) . '</div></div>';
    echo '<div class="stat ' . ($net >= 0 ? 's-ok' : 's-bad') . '"><div class="stat-label">NET PROFIT</div><div class="stat-value">₹' . money($net) . '</div></div>';
    echo '</div>';

    echo '<div class="card"><h2>Profit &amp; Loss (' . dmy($from) . ' &rarr; ' . dmy($to) . ')</h2>';
    echo '<div class="table-wrap"><table><tbody>';
    echo '<tr><td>Sales Revenue (net of returns)</td><td class="num">₹' . money($revenue) . '</td></tr>';
    echo '<tr><td>Less: Cost of Goods Sold</td><td class="num" style="color:var(--bad)">-₹' . money($cogs) . '</td></tr>';
    echo '<tr style="font-weight:600;border-top:1px solid var(--border,#ddd)"><td>Gross Profit</td><td class="num">₹' . money($gross) . '</td></tr>';
    foreach ($expCats as $c) {
        if ((float)$c['total'] == 0) continue;
        echo '<tr><td style="padding-left:20px">Less: ' . e($c['category']) . '</td><td class="num" style="color:var(--bad)">-₹' . money($c['total']) . '</td></tr>';
    }
    foreach ($customExpense as $a) {
        $v = journal_balance($a['id'], $from, $to);
        if ($v == 0) continue;
        echo '<tr><td style="padding-left:20px">Less: ' . e($a['name']) . '</td><td class="num" style="color:var(--bad)">-₹' . money($v) . '</td></tr>';
    }
    echo '<tr style="font-weight:600;border-top:1px solid var(--border,#ddd)"><td>Total Expenses</td><td class="num">₹' . money($totalOpex) . '</td></tr>';
    echo '<tr style="font-weight:700;border-top:2px solid var(--text)"><td>NET PROFIT</td><td class="num">₹' . money($net) . '</td></tr>';
    echo '</tbody></table></div></div>';
}
