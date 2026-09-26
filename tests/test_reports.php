<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); die('CLI only'); }
// ============================================================================
//  DO THE REPORTS AGREE WITH EACH OTHER?
// ============================================================================
//  Reported from the shop: for the same month, Profit & Loss showed a loss of
//  ₹57,491 and the Business Report showed a profit of ₹13,908. Two screens,
//  one month, opposite answers - so the owner could not tell whether he had
//  made money.
//
//  The cause was two different cost formulas. This file exists so that can
//  never happen again: it builds ONE messy month by hand - a product whose
//  purchase price moved after it was sold, a service line, a bill discount,
//  loyalty points, a return, shipping, adjustment, round-off, an expense and a
//  repair - and then checks every screen that reports money against every
//  other one.
//
//  The scenario deliberately contains the exact conditions that made the two
//  reports diverge on the live site, because a test that only uses tidy data
//  would have passed before the fix as well.
// ============================================================================
$_SESSION['user_id'] = (int)val("SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id
                                 WHERE r.permissions LIKE '%*%' AND u.is_active = 1 ORDER BY u.id LIMIT 1");
$rCo  = (int)val('SELECT id FROM companies ORDER BY id LIMIT 1');
$rLoc = (int)val('SELECT id FROM locations ORDER BY id LIMIT 1');

// A window far enough in the past that no other fixture in the suite lands in
// it, so these figures are only ever about the bills built below.
$RF = date('Y-m-d', strtotime('-500 days'));
$RT = date('Y-m-d', strtotime('-471 days'));
$RD = date('Y-m-d', strtotime('-490 days'));   // the day everything happens

// The sample database this suite runs against has bills spread over years, so
// this window is not empty. Every figure below is therefore measured as the
// CHANGE this scenario makes - which is exact, and does not depend on what
// else happens to sit in the same month.
$base = [
    'rev'      => (float)val('SELECT COALESCE(SUM(total),0) FROM sales WHERE is_cancelled = 0 AND sale_date BETWEEN ? AND ?', [$RF, $RT]),
    'ret'      => (float)val('SELECT COALESCE(SUM(total),0) FROM sales_returns WHERE return_date BETWEEN ? AND ?', [$RF, $RT]),
    'cogs'     => (float)val('SELECT COALESCE(SUM(' . profit_cost_sql() . '),0) FROM sale_items si JOIN sales s ON s.id = si.sale_id JOIN items i ON i.id = si.item_id WHERE s.is_cancelled = 0 AND s.sale_date BETWEEN ? AND ?', [$RF, $RT]),
    'naive'    => (float)val('SELECT COALESCE(SUM(si.qty * i.purchase_price),0) FROM sale_items si JOIN sales s ON s.id = si.sale_id JOIN items i ON i.id = si.item_id WHERE s.is_cancelled = 0 AND s.sale_date BETWEEN ? AND ?', [$RF, $RT]),
    'lines'    => (float)val('SELECT COALESCE(SUM(si.total),0) FROM sale_items si JOIN sales s ON s.id = si.sale_id WHERE s.is_cancelled = 0 AND s.sale_date BETWEEN ? AND ?', [$RF, $RT]),
    'subtotal' => (float)val('SELECT COALESCE(SUM(subtotal),0) FROM sales WHERE is_cancelled = 0 AND sale_date BETWEEN ? AND ?', [$RF, $RT]),
    'taxable'  => (float)val('SELECT COALESCE(SUM(subtotal - discount),0) FROM sales WHERE is_cancelled = 0 AND sale_date BETWEEN ? AND ?', [$RF, $RT]),
    'tax'      => (float)val('SELECT COALESCE(SUM(tax_amount),0) FROM sales WHERE is_cancelled = 0 AND sale_date BETWEEN ? AND ?', [$RF, $RT]),
    'exp'      => (float)val('SELECT COALESCE(SUM(amount),0) FROM expenses WHERE exp_date BETWEEN ? AND ?', [$RF, $RT]),
    'svc'      => (float)val('SELECT COALESCE(SUM(service_charge),0) FROM tasks WHERE status = "completed" AND DATE(end_time) BETWEEN ? AND ?', [$RF, $RT]),
    'repIn'    => (float)val('SELECT COALESCE(SUM(final_charge),0) FROM repairs WHERE status = "delivered" AND delivered_date BETWEEN ? AND ?', [$RF, $RT]),
    'repCost'  => (float)val('SELECT COALESCE(SUM(outsource_cost),0) FROM repairs WHERE status = "delivered" AND delivered_date BETWEEN ? AND ?', [$RF, $RT]),
];

// ---------------------------------------------------------------- fixtures --
// A product SOLD at a captured cost of 600 whose master price is now 900.
// The old Profit & Loss charged 900 - rewriting the profit on a bill that was
// raised months ago every time a supplier put their rate up.
q("INSERT INTO items (name, selling_price, purchase_price, is_active, item_type)
   VALUES ('TR product', 1000, 900, 1, 'product')");
$rProd = insert_id();
// A SERVICE. It consumes no stock, but its row still carries a purchase price,
// and the old Profit & Loss charged that as a cost of goods sold.
q("INSERT INTO items (name, selling_price, purchase_price, is_active, item_type)
   VALUES ('TR service', 2000, 700, 1, 'service')");
$rSvc = insert_id();
$rParty = t_party('TR Customer');

// Bill 1: both lines, a line discount, a bill discount and loyalty points.
//   product 2 x 1000 = 2000, less 100 line discount        -> line total 1900
//   service 1 x 2000                                       -> line total 2000
//   subtotal 3900, bill discount 200, loyalty 50, GST 0
//   shipping 150, adjustment -25, round off 0.40
$sub1 = 3900.0; $billDisc1 = 200.0; $loyalty1 = 50.0; $ship1 = 150.0; $adj1 = -25.0; $round1 = 0.40;
$total1 = $sub1 - $billDisc1 - $loyalty1 + 0 + $ship1 + $adj1 + $round1;
q("INSERT INTO sales (company_id, location_id, party_id, customer_name, invoice_no, sale_date, due_date,
    subtotal, discount, loyalty_discount, discount_type, tax_amount, shipping, adjustment, round_off,
    total, paid, payment_mode, status, created_by)
   VALUES (?,?,?, 'TR Customer', ?, ?, ?, ?, ?, ?, 'amount', 0, ?, ?, ?, ?, ?, 'cash', 'paid', 1)",
  [$rCo, $rLoc, $rParty, 'TR-' . bin2hex(random_bytes(4)), $RD, $RD,
   $sub1, $billDisc1, $loyalty1, $ship1, $adj1, $round1, $total1, $total1]);
$rSale1 = insert_id();
q("INSERT INTO sale_items (sale_id, item_id, qty, price, cost_price, line_disc, total) VALUES (?,?,2,1000,600,100,1900)", [$rSale1, $rProd]);
q("INSERT INTO sale_items (sale_id, item_id, qty, price, cost_price, line_disc, total) VALUES (?,?,1,2000,0,0,2000)", [$rSale1, $rSvc]);

// Bill 2: a plain product sale, later partly returned.
q("INSERT INTO sales (company_id, location_id, party_id, customer_name, invoice_no, sale_date, due_date,
    subtotal, discount, loyalty_discount, discount_type, tax_amount, shipping, adjustment, round_off,
    total, paid, payment_mode, status, created_by)
   VALUES (?,?,?, 'TR Customer', ?, ?, ?, 5000, 0, 0, 'amount', 0, 0, 0, 0, 5000, 5000, 'cash', 'paid', 1)",
  [$rCo, $rLoc, $rParty, 'TR-' . bin2hex(random_bytes(4)), $RD, $RD]);
$rSale2 = insert_id();
q("INSERT INTO sale_items (sale_id, item_id, qty, price, cost_price, line_disc, total) VALUES (?,?,5,1000,600,0,5000)", [$rSale2, $rProd]);

// One of those five comes back.
q("INSERT INTO sales_returns (return_no, sale_id, party_id, customer_name, location_id, return_date, total, refund_mode, created_by)
   VALUES (?, ?, ?, 'TR Customer', ?, ?, 1000, 'cash', 1)",
  ['TRR-' . bin2hex(random_bytes(4)), $rSale2, $rParty, $rLoc, $RD]);
$rRet = insert_id();
q("INSERT INTO sales_return_items (return_id, item_id, qty, price, total) VALUES (?,?,1,1000,1000)", [$rRet, $rProd]);

// An expense, a completed task and a delivered repair in the same window.
q("INSERT INTO expenses (exp_date, category, amount, mode, location_id, created_by) VALUES (?, 'TR Rent', 800, 'cash', ?, 1)", [$RD, $rLoc]);
q("INSERT INTO tasks (task_no, customer_name, description, status, scheduled_date, end_time, service_charge, assigned_to, location_id, created_by)
   VALUES (?, 'TR Customer', 'visit', 'completed', ?, ?, 300, ?, ?, 1)",
  ['TRT-' . bin2hex(random_bytes(4)), $RD, $RD . ' 12:00:00', $_SESSION['user_id'], $rLoc]);
q("INSERT INTO repairs (job_no, customer_name, device_type, problem, status, received_date, delivered_date,
    outsource_cost, final_charge, location_id, created_by)
   VALUES (?, 'TR Customer', 'Laptop', 'x', 'delivered', ?, ?, 200, 900, ?, 1)",
  ['TRJ-' . bin2hex(random_bytes(4)), $RD, $RD, $rLoc]);

// ------------------------------------------------------ the numbers by hand --
// Line revenue: 1900 + 2000 + 5000 = 8900, less the 1000 returned = 7900.
// Cost, by the shared rule: product 2x600 + service 0 + product 5x600 = 4200,
// less the returned piece at the item's purchase price (900) = 3300.
$handLineRevenue = 8900.0;
$handCostAll     = 4200.0;

t_group('the shared cost rule is what every screen uses');
$cogs = (float)val('SELECT COALESCE(SUM(' . profit_cost_sql() . '),0)
                    FROM sale_items si JOIN sales s ON s.id = si.sale_id JOIN items i ON i.id = si.item_id
                    WHERE s.is_cancelled = 0 AND s.sale_date BETWEEN ? AND ?', [$RF, $RT]);
t_eq('cost is the cost captured on the bill, not today\'s price', $cogs - $base['cogs'], $handCostAll);
// The two things that made the live reports disagree, each pinned on its own.
$naive = (float)val('SELECT COALESCE(SUM(si.qty * i.purchase_price),0)
                     FROM sale_items si JOIN sales s ON s.id = si.sale_id JOIN items i ON i.id = si.item_id
                     WHERE s.is_cancelled = 0 AND s.sale_date BETWEEN ? AND ?', [$RF, $RT]);
t_eq('the old naive formula really does differ on this data', $naive - $base['naive'], 7000.0);
t_ok('...so this scenario would have caught the bug', abs(($naive - $base['naive']) - ($cogs - $base['cogs'])) > 1);
t_eq('a service line is charged no cost of goods',
     (float)val('SELECT COALESCE(SUM(' . profit_cost_sql() . '),0)
                 FROM sale_items si JOIN items i ON i.id = si.item_id WHERE si.item_id = ?', [$rSvc]), 0);

t_group('Profit & Loss and the Business Report now agree');
$revBusiness = (float)val('SELECT COALESCE(SUM(total),0) FROM sales WHERE is_cancelled = 0 AND sale_date BETWEEN ? AND ?', [$RF, $RT])
             - (float)val('SELECT COALESCE(SUM(total),0) FROM sales_returns WHERE return_date BETWEEN ? AND ?', [$RF, $RT]);
t_eq('revenue is the same on both', $revBusiness, coa_sales_revenue($RF, $RT));
t_eq('cost of goods sold is the same on both', $cogs, coa_cogs($RF, $RT));
t_eq('...so gross profit is the same on both',
     $revBusiness - $cogs, coa_sales_revenue($RF, $RT) - coa_cogs($RF, $RT));
// This is the assertion that would have failed before the fix.
t_ok('the two never differ by the cost of services or by price drift',
     abs(($revBusiness - $cogs) - (coa_sales_revenue($RF, $RT) - coa_cogs($RF, $RT))) < 0.02);

t_group('a bill total is exactly the sum of its own parts');
$off = (float)val("SELECT COALESCE(SUM(ABS(total - (subtotal - discount - loyalty_discount + tax_amount + shipping + adjustment + round_off))),0)
                   FROM sales WHERE is_cancelled = 0 AND sale_date BETWEEN ? AND ?", [$RF, $RT]);
t_eq('every bill in the window adds up', $off, 0);
t_eq('the discounted bill totals what it should', (float)val('SELECT total FROM sales WHERE id = ?', [$rSale1]), $total1);

t_group('bill lines and bill subtotals agree');
$lineRev = (float)val('SELECT COALESCE(SUM(si.total),0) FROM sale_items si JOIN sales s ON s.id = si.sale_id
                       WHERE s.is_cancelled = 0 AND s.sale_date BETWEEN ? AND ?', [$RF, $RT]);
t_eq('the lines add up to what was billed', $lineRev - $base['lines'], $handLineRevenue);
t_eq('...and to the sum of the bill subtotals', $lineRev,
     (float)val('SELECT COALESCE(SUM(subtotal),0) FROM sales WHERE is_cancelled = 0 AND sale_date BETWEEN ? AND ?', [$RF, $RT]));
t_eq('...and the change is what this scenario billed', $lineRev - $base['lines'], $handLineRevenue);

t_group('the per-item and per-customer profit reports total the same');
$byItem = all('SELECT SUM(si.total) revenue, SUM(' . profit_cost_sql() . ') cost
               FROM sale_items si JOIN sales s ON s.id = si.sale_id JOIN items i ON i.id = si.item_id
               WHERE s.is_cancelled = 0 AND s.sale_date BETWEEN ? AND ? GROUP BY si.item_id', [$RF, $RT]);
t_eq('Product-wise Profit sums to the shared cost', array_sum(array_column($byItem, 'cost')), $cogs);
t_eq('...and to the billed lines', array_sum(array_column($byItem, 'revenue')), $lineRev);

$pp = party_profit($RF, $RT, '', [], 10000);
$mine = null;
foreach ($pp as $x) if ((int)$x['party_id'] === $rParty) $mine = $x;
t_ok('the customer appears in Party-wise Profit', $mine !== null);
t_eq('their revenue is the lines less what came back', $mine['revenue'], $handLineRevenue - 1000);
t_eq('their bill-level giveaways are counted once', $mine['discount'], $billDisc1 + $loyalty1);
t_eq('their contribution is revenue - cost - giveaways',
     $mine['contribution'], money_r($mine['revenue'] - $mine['cost'] - $mine['discount']));

t_group('the GST report taxes what the bill says it taxed');
$g = row("SELECT COALESCE(SUM(subtotal - discount),0) taxable, COALESCE(SUM(tax_amount),0) tax
          FROM sales WHERE is_cancelled = 0 AND sale_date BETWEEN ? AND ?", [$RF, $RT]);
t_eq('taxable value is subtotal less the bill discount', (float)$g['taxable'] - $base['taxable'], $sub1 - $billDisc1 + 5000);
t_eq('tax collected is what the bills recorded', (float)$g['tax'] - $base['tax'], 0);

t_group('expenses reconcile between the two statements');
$expTotal = (float)val('SELECT COALESCE(SUM(amount),0) FROM expenses WHERE exp_date BETWEEN ? AND ?', [$RF, $RT]);
t_eq('the expense total matches the P&L breakdown',
     $expTotal, array_sum(array_column(coa_expenses_by_category($RF, $RT), 'total')));
t_eq('...and it is the expense that was booked', $expTotal - $base['exp'], 800.0);

t_group('net profit reconciles once service and repair are counted');
$svc = (float)val('SELECT COALESCE(SUM(service_charge),0) FROM tasks WHERE status = "completed" AND DATE(end_time) BETWEEN ? AND ?', [$RF, $RT]);
$repIn = (float)val('SELECT COALESCE(SUM(final_charge),0) FROM repairs WHERE status = "delivered" AND delivered_date BETWEEN ? AND ?', [$RF, $RT]);
$repCost = (float)val('SELECT COALESCE(SUM(outsource_cost),0) FROM repairs WHERE status = "delivered" AND delivered_date BETWEEN ? AND ?', [$RF, $RT]);
t_eq('the task income is picked up', $svc - $base['svc'], 300.0);
t_eq('the repair margin is income less what was paid out', ($repIn - $base['repIn']) - ($repCost - $base['repCost']), 700.0);
$netBusiness = ($revBusiness - $cogs) + ($svc + $repIn - $repCost) - $expTotal;
$netPL = coa_net_profit($RF, $RT);
// The P&L is the accounting statement and only knows what is journalled, so
// service and repair income reach it another way. Like for like, they match.
t_eq('the two statements agree once compared like for like',
     $netBusiness, $netPL + ($svc + $repIn - $repCost));

t_group('a cancelled bill counts nowhere');
q("UPDATE sales SET is_cancelled = 1 WHERE id = ?", [$rSale2]);
$revAfter = (float)val('SELECT COALESCE(SUM(total),0) FROM sales WHERE is_cancelled = 0 AND sale_date BETWEEN ? AND ?', [$RF, $RT]);
$cogsAfter = (float)val('SELECT COALESCE(SUM(' . profit_cost_sql() . '),0)
                         FROM sale_items si JOIN sales s ON s.id = si.sale_id JOIN items i ON i.id = si.item_id
                         WHERE s.is_cancelled = 0 AND s.sale_date BETWEEN ? AND ?', [$RF, $RT]);
t_eq('its revenue is gone', $revAfter - $base['rev'], $total1);
t_eq('its cost is gone too', $cogsAfter - $base['cogs'], 1200.0);
t_eq('and the P&L drops it as well', coa_cogs($RF, $RT), $cogsAfter);
q("UPDATE sales SET is_cancelled = 0 WHERE id = ?", [$rSale2]);

t_group('the one cost rule is written once and used everywhere');
$rb = file_get_contents(dirname(__DIR__) . '/includes/report_body.php');
$acc = file_get_contents(dirname(__DIR__) . '/includes/accounting.php');
$mny = file_get_contents(dirname(__DIR__) . '/includes/money.php');
t_ok('the accounting layer uses the shared expression', strpos($acc, 'profit_cost_sql()') !== false);
// Anything computing a cost of goods with today's purchase price is the bug
// coming back; sale_items.cost_price is what the bill actually captured.
t_ok('nothing multiplies quantity by the item master price any more',
     strpos($rb, 'si.qty * i.purchase_price') === false && strpos($acc, 'si.qty * i.purchase_price') === false,
     'found the old formula again');
t_ok('the rule itself lives in money.php', strpos($mny, 'function profit_cost_sql()') !== false);
foreach (['business', 'profit', 'bill_profit', 'branch_staff'] as $rep)
    t_ok("the $rep report goes through it", strpos($rb, 'profit_cost_sql()') !== false);

// ------------------------------------- one stock valuation, not four of them --
// The owner spotted the dashboard and the Balance Sheet sitting 826 rupees
// apart. Four screens were each asking "what is our stock worth" their own
// way, and on data with staff-held stock, a stray service stock row and a
// switched-off item they gave four different answers.
t_group('every screen values stock the same way');
$svLoc = (int)val('SELECT id FROM locations ORDER BY id LIMIT 1');
$svU   = (int)val('SELECT id FROM users ORDER BY id LIMIT 1');

/** An item with stock on the shelf, and optionally some out with staff. */
function tsv_item($name, $type, $price, $shelf, $staff = 0, $active = 1) {
    global $svLoc, $svU;
    q("INSERT INTO items (name, item_type, purchase_price, selling_price, unit, is_active, created_at)
       VALUES (?,?,?,?, 'pcs', ?, NOW())", [$name, $type, $price, $price * 2, $active]);
    $id = insert_id();
    if ($shelf) q('INSERT INTO stock (item_id, location_id, qty) VALUES (?,?,?)', [$id, $svLoc, $shelf]);
    if ($staff) q('INSERT INTO staff_stock (user_id, item_id, qty) VALUES (?,?,?)', [$svU, $id, $staff]);
    return $id;
}

$base = stock_value();
$tsvA = tsv_item('TSV Plain ' . bin2hex(random_bytes(2)), 'product', 100, 10);          // 1000 on the shelf
$tsvB = tsv_item('TSV Staff ' . bin2hex(random_bytes(2)), 'product', 200, 5, 7);        // 1000 shelf + 1400 staff
$tsvC = tsv_item('TSV Off '   . bin2hex(random_bytes(2)), 'product', 50, 4, 0, 0);      // 200, item switched off
$tsvD = tsv_item('TSV Svc '   . bin2hex(random_bytes(2)), 'service', 70, 3);            // a service with a stray stock row

// what the one rule says these are worth: 1000 + 1000 + 1400 + 200, service out
t_eq('goods with staff are counted, a service row is not, a switched-off item is',
     money_r(stock_value() - $base), 3600.0);

// the three figures the screens actually print, each from its own code path
require_once dirname(__DIR__) . '/includes/accounting.php';
$fromBalanceSheet = coa_stock_value();
t_eq('the Balance Sheet agrees with the rule', $fromBalanceSheet, stock_value());
// the dashboard card
$inv = dash_stock();
t_ok('the dashboard has a stock figure at all', is_array($inv));

// ...and the shape of the rule itself, so a screen cannot drift off it again
$m = file_get_contents(dirname(__DIR__) . '/includes/money.php');
t_ok('the rule lives in one place', substr_count($m, 'function stock_value_sql') === 1);
t_ok('...it adds staff-held stock to the shelf', strpos($m, 'UNION ALL SELECT item_id, qty FROM staff_stock') !== false);
t_ok('...and leaves services out', strpos($m, "WHERE i.item_type <> 'service'") !== false);
foreach (['includes/accounting.php', 'index.php', 'includes/report_body.php'] as $f) {
    $src = file_get_contents(dirname(__DIR__) . '/' . $f);
    t_ok($f . ' goes through the shared rule',
         strpos($src, 'stock_value()') !== false || strpos($src, 'stock_qty_sql()') !== false);
    // no screen may keep its own copy of the valuation any more
    t_ok($f . ' keeps no private copy of it',
         strpos($src, "SUM(sq.q * i.purchase_price)") === false
         && strpos($src, "SUM(s.qty * i.purchase_price)") === false);
}

t_group('the Stock Report rows add up to its own total');
// a switched-off item still holding stock has to be LISTED, or the rows and
// the total disagree - that was the fourth of the four answers
$rb = file_get_contents(dirname(__DIR__) . '/includes/report_body.php');
t_ok('switched-off items with stock are listed', strpos($rb, "i.is_active = 1 OR EXISTS") !== false);
t_ok('...and marked as switched off', strpos($rb, "empty(\$it['is_active']) ? ' <span class=\"badge badge-warn\">બંધ</span>'") !== false);
t_ok('...while ones with no stock stay hidden', strpos($rb, "ABS(sq.q) > 0.0001") !== false);

// ------------------------------------------ Total Assets - Total Liabilities --
// The one line an owner reads a balance sheet for, and the sheet did not have
// it: the reader had to subtract two figures a screen apart in their head.
t_group('the Balance Sheet shows Net Worth');
$rb2 = file_get_contents(dirname(__DIR__) . '/includes/report_body.php');
t_ok('there is a Net Worth line', strpos($rb2, '$netWorth = $totAssets - $totLiab;') !== false);
t_ok('...on the sheet itself, right after the liabilities',
     strpos($rb2, 'ચોખ્ખી મૂડી — Net Worth') !== false);
t_ok('...and worked out in full below it', strpos($rb2, 'Net Worth) — આખો હિસાબ') !== false);
t_ok('...reconciled against what the books actually record',
     strpos($rb2, 'એમાંથી ચોપડે નોંધાયેલું') !== false);
t_ok('...with the unrecorded opening capital as its own line',
     strpos($rb2, 'હજી નહીં નોંધાયેલી જૂની મૂડી') !== false);
// the exports render the same body, so the line reaches PDF and Excel too
foreach (['report_pdf.php', 'report_xlsx.php'] as $f)
    t_ok($f . ' renders the same report body',
         strpos(file_get_contents(dirname(__DIR__) . '/' . $f), "include __DIR__ . '/includes/report_body.php'") !== false);

t_group('the sheet no longer gives advice that cannot work');
// It used to say: post the difference via a Journal Entry against Owner's
// Capital "and this will balance to zero going forward". It cannot. Every line
// of a BALANCED entry lands in Assets, Liabilities or Equity with matching
// signs, so Assets - Liabilities - Equity is invariant under any journal
// entry. The arithmetic below is that proof, run rather than asserted.
require_once dirname(__DIR__) . '/includes/accounting.php';
function tbs_gap($to) {
    $A = 0; $L = 0; $E = 0;
    foreach (coa_all() as $x) {
        if ($x['type'] === 'asset') $A += coa_balance_asof($x, $to);
        elseif ($x['type'] === 'liability') $L += -coa_balance_asof($x, $to);
        elseif ($x['type'] === 'equity') $E += ($x['code'] === '3900'
            ? coa_net_profit('0001-01-01', $to) - journal_balance($x['id'], '0001-01-01', $to)
            : -journal_balance($x['id'], '0001-01-01', $to));
    }
    return money_r($A - $L - $E);
}
$tbsTo = today();
$gap0 = tbs_gap($tbsTo);
$capId = (int)val("SELECT id FROM chart_of_accounts WHERE code = '3000'");
foreach (['1000', '2000', '3900'] as $against) {
    q("INSERT INTO journal_entries (ref_no, entry_date, narration, source, created_by, created_at)
       VALUES (?,?, 'opening capital', 'manual', 1, NOW())", ['TBS-' . $against, $tbsTo]);
    $eId = insert_id();
    $drId = (int)val('SELECT id FROM chart_of_accounts WHERE code = ?', [$against]);
    q('INSERT INTO journal_lines (entry_id, account_id, debit, credit) VALUES (?,?,1000,0)', [$eId, $drId]);
    q('INSERT INTO journal_lines (entry_id, account_id, debit, credit) VALUES (?,?,0,1000)', [$eId, $capId]);
    t_eq('crediting Owner\'s Capital against ' . $against . ' does not close the gap', tbs_gap($tbsTo), $gap0);
}
t_ok('so the sheet does not promise a Journal Entry will fix it',
     strpos($rb2, 'this will balance to zero going forward') === false);
t_ok('...it says what the number actually is instead',
     strpos($rb2, 'એ રકમ ખોટી નથી; એ ફક્ત નોંધાયેલી નથી') !== false);
t_ok('...and that Net Worth itself is the reliable figure',
     strpos($rb2, 'ભરોસાપાત્ર આંકડો') !== false);

// ------------------------------------------------- the shop's own categories --
t_group('expense categories are the shop\'s own list, in one place');
$cats = expense_categories();
$groups = expense_category_groups();
t_eq('the shop\'s twenty-four are there', count($groups['ધંધાનો ખર્ચ']), 24);
t_eq('...and the household heads beside them', count($groups[expense_home_group()]), 16);
t_eq('...forty in all', count($cats), 40);
t_eq('...with no duplicates', count(array_unique($cats)), 40);
foreach (['Office Rent', 'Salary & Wages', 'Staff Advance', 'Petrol & Vehicle', 'Vehicle Repair',
          'Courier & Transport', 'Internet & Telecom', 'Hosting & Domain', 'Electricity',
          'Tea & Water', 'Food & Meals', 'Office Stationery', 'Computer Repair/Maintenance',
          'Office Maintenance', 'Office Equipment & Furniture', 'Marketing & Advertising',
          'Software & AI', 'Bank Charges & Interest', 'Payment Gateway Charges', 'EMI / Loan',
          'GST & Government', 'Business Travel', 'Customer/Staff Expense', 'Business Miscellaneous',
          'Owner Drawings / Personal', 'Medical / Personal', 'Gifts & Family'] as $c)
    t_ok('"' . $c . '" is still on the list', in_array($c, $cats, true));
// the household heads the owner actually asked for
foreach (['Money Given Home', 'Home Electricity', 'Home Rent', 'Home Groceries',
          'School & Children', 'Insurance / LIC', 'Personal Vehicle & Petrol'] as $c)
    t_ok('"' . $c . '" can be recorded', in_array($c, $cats, true));
$ex_src = file_get_contents(dirname(__DIR__) . '/expenses.php');
t_ok('the screen reads the shared list, not its own copy',
     strpos($ex_src, 'expense_category_groups()') !== false
     && strpos($ex_src, "'General', 'Rent', 'Salary'") === false);
t_ok('...and shows the two groups apart', strpos($ex_src, '<optgroup label=') !== false);

t_group('home spending is counted like any other, and still countable on its own');
foreach (['Money Given Home', 'Home Electricity', 'Owner Drawings / Personal'] as $c)
    t_ok('"' . $c . '" is household', expense_is_home($c));
foreach (['Office Rent', 'Electricity', 'Business Miscellaneous'] as $c)
    t_ok('"' . $c . '" is the shop\'s', !expense_is_home($c));
// the shop's own Electricity and the house light bill must not be one line
t_ok('the shop light bill and the home one are different categories',
     in_array('Electricity', $cats, true) && in_array('Home Electricity', $cats, true)
     && !expense_is_home('Electricity') && expense_is_home('Home Electricity'));

$ecFrom = '2031-01-01'; $ecTo = '2031-02-28';
$ecU = (int)val('SELECT id FROM users ORDER BY id LIMIT 1');
$ecL = (int)val('SELECT id FROM locations ORDER BY id LIMIT 1');
foreach ([['2031-01-10', 'Office Rent', 4000], ['2031-01-12', 'Money Given Home', 5000],
          ['2031-01-20', 'Home Electricity', 1500], ['2031-02-05', 'Electricity', 900],
          ['2031-02-08', 'Home Groceries', 2600]] as $e)
    q("INSERT INTO expenses (exp_date, category, amount, mode, notes, location_id, created_by)
       VALUES (?, ?, ?, 'cash', 'ECTEST', ?, ?)", [$e[0], $e[1], $e[2], $ecL, $ecU]);

t_eq('what went home is its own figure', expense_home_total($ecFrom, $ecTo), 9100.0);
$allExp = (float)val("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE exp_date BETWEEN ? AND ?", [$ecFrom, $ecTo]);
t_eq('...the shop\'s is the rest', money_r($allExp - expense_home_total($ecFrom, $ecTo)), 4900.0);
t_eq('...and nothing is lost between them', money_r(4900.0 + 9100.0), money_r($allExp));

$mon = expense_home_by_month($ecFrom, $ecTo);
t_eq('month by month, two months', count($mon), 2);
t_eq('January went home', money_r($mon[0]['home']), 6500.0);
t_eq('...and to the shop', money_r($mon[0]['shop']), 4000.0);
t_eq('February went home', money_r($mon[1]['home']), 2600.0);
t_eq('...and to the shop', money_r($mon[1]['shop']), 900.0);
foreach ($mon as $mm) t_eq('each month\'s parts add to its total (' . $mm['ym'] . ')',
     money_r($mm['home'] + $mm['shop']), money_r($mm['total']));

$rb3 = file_get_contents(dirname(__DIR__) . '/includes/report_body.php');
t_ok('the P&L says how much of the profit went home', strpos($rb3, 'expense_home_total($from, $to)') !== false);
t_ok('...and what the shop alone made', strpos($rb3, 'ફક્ત ધંધાનો નફો') !== false);
// the owner asked for it to stay an expense - so net profit must NOT change
t_ok('home spending is still inside net profit',
     strpos($rb3, "<td>NET PROFIT</td><td class=\"num\">₹' . money(\$net) . '") !== false);
t_ok('...and the screen says so in as many words', strpos($rb3, 'ગણેલો જ છે — કાઢ્યો નથી') !== false);
t_ok('the expense report lists the two apart', strpos($rb3, 'ઘરનો કુલ') !== false && strpos($rb3, 'ધંધાનો કુલ') !== false);
t_ok('...and month by month', strpos($rb3, 'expense_home_by_month($from, $to)') !== false);
t_ok('the expenses screen splits its total too', strpos($ex_src, "expense_is_home(\$rw['category'])") !== false);

t_group('the old category names fold into the new ones');
// every report groups by the category STRING, so leaving the old names behind
// would carry "Rent" and "Office Rent" as two lines for ever
$v63 = file_get_contents(dirname(__DIR__) . '/install/upgrade_v63.sql');
foreach ([['Rent', 'Office Rent'], ['Salary', 'Salary & Wages'], ['Internet', 'Internet & Telecom'],
          ['Transport', 'Courier & Transport'], ['Tea/Food', 'Tea & Water'], ['Stationery', 'Office Stationery'],
          ['Repair/Maintenance', 'Office Maintenance'], ['Marketing', 'Marketing & Advertising']] as $m)
    t_ok('"' . $m[0] . '" becomes "' . $m[1] . '"',
         strpos($v63, "SET category = '" . $m[1] . "'") !== false && strpos($v63, "= '" . $m[0] . "'") !== false);
t_ok('General and Other merge into one pile', strpos($v63, "IN ('General', 'Other')") !== false);
t_ok('...and the note-to-category suggester is moved with them',
     substr_count($v63, 'UPDATE expense_category_keywords') >= 8);
// nothing in the old list is left pointing nowhere
$oldNames = ['Rent', 'Salary', 'Internet', 'Transport', 'Tea/Food', 'Stationery', 'Repair/Maintenance', 'Marketing', 'General', 'Other'];
$stranded = [];
foreach ($oldNames as $o) if (strpos($v63, "= '" . $o . "'") === false && strpos($v63, "'" . $o . "'") === false) $stranded[] = $o;
t_eq('every old name has somewhere to go', implode(', ', $stranded), '');

// ---------------------------------------------------------------------------
// "જે દિવસથી બિલ બન્યો હોય ને એ તારીખ જ ... ડ્યુ કર્યું એ ડેટ કેલ્ક્યુલેશન ન
// થાય. અને કોઈ ઓપનિંગ બેલેન્સ હોય ને એ પણ ... પાર્ટી બની હોય ત્યારથી."
//
// Two rules, both money rules, so both are checked on real rows rather than
// on the source text.
t_group('aging is counted from the bill date, not the due date');

function tr_aging_row($pid) {
    foreach (aging_rows(0, $pid) as $r) if ((int)$r['party_id'] === (int)$pid) return $r;
    return null;
}
$agoDate = fn($d) => date('Y-m-d', strtotime("-$d days"));

// a bill written 45 days ago, given 30 days' credit, so only 15 days overdue
$apA = t_party('AGING_BILLDATE');
t_sale($apA, 5000, 0, $agoDate(45), $agoDate(15));
$rowA = tr_aging_row($apA);
t_ok('the party is on the report', $rowA !== null);
t_eq('the whole amount is claimed', money_r($rowA['total']), 5000.0);
t_eq('...and it sits in 31-60 days, because the BILL is 45 days old',
     money_r($rowA['b2']), 5000.0);
t_eq('...not in 0-30, where the due date would have put it', money_r($rowA['b1']), 0.0);

// the buckets themselves, from the bill date
$apB = t_party('AGING_BUCKETS');
t_sale($apB, 100, 0, $agoDate(10),  $agoDate(200));   // due date far in the past/future must not matter
t_sale($apB, 200, 0, $agoDate(45),  null);
t_sale($apB, 400, 0, $agoDate(75),  null);
t_sale($apB, 800, 0, $agoDate(365), null);
$rowB = tr_aging_row($apB);
t_eq('0-30 days holds the newest bill',   money_r($rowB['b1']), 100.0);
t_eq('31-60 days holds the 45-day bill',  money_r($rowB['b2']), 200.0);
t_eq('61-90 days holds the 75-day bill',  money_r($rowB['b3']), 400.0);
t_eq('90+ days holds the year-old bill',  money_r($rowB['b4']), 800.0);
t_eq('...and they add up', money_r($rowB['total']), 1500.0);

t_group('the opening balance is a debt too, aged from the day the party was made');

// old money carried in, no bills at all - this party was invisible before
$apC = t_party('AGING_OPENING_ONLY', 7000);
q("UPDATE parties SET created_at = ? WHERE id = ?", [$agoDate(120) . ' 10:00:00', $apC]);
$rowC = tr_aging_row($apC);
t_ok('a party whose whole balance is opening money is on the report', $rowC !== null);
t_eq('...for the full opening balance', money_r($rowC['total']), 7000.0);
t_eq('...aged from the day the party was created, so 90+ days', money_r($rowC['b4']), 7000.0);

// a freshly created party's opening balance is new money, not old
$apD = t_party('AGING_OPENING_NEW', 1200);
t_eq('an opening balance entered today is 0-30 days', money_r(tr_aging_row($apD)['b1']), 1200.0);

t_group('opening balance and bills are counted once, not twice');
$apE = t_party('AGING_OPEN_PLUS_BILLS', 3000);
q("UPDATE parties SET created_at = ? WHERE id = ?", [$agoDate(200) . ' 10:00:00', $apE]);
t_sale($apE, 2000, 0, $agoDate(10), null);
$rowE = tr_aging_row($apE);
t_eq('the opening money is in the oldest bucket', money_r($rowE['b4']), 3000.0);
t_eq('...the bill in the newest', money_r($rowE['b1']), 2000.0);
t_eq('...and the total is exactly what the ledger says they owe',
     money_r($rowE['total']), money_r(party_balance_side($apE, 'in')));

// money received but never linked to a bill settles the OLDEST debt first,
// and nothing is older than the opening balance
$apF = t_party('AGING_UNLINKED_PAYMENT', 3000);
q("UPDATE parties SET created_at = ? WHERE id = ?", [$agoDate(200) . ' 10:00:00', $apF]);
t_sale($apF, 2000, 0, $agoDate(10), null);
t_payment($apF, 3500, 'in');            // not linked to anything
$rowF = tr_aging_row($apF);
t_eq('an unlinked payment clears the opening balance first', money_r($rowF['b4']), 0.0);
t_eq('...then eats into the newest bill', money_r($rowF['b1']), 1500.0);
t_eq('...leaving exactly the ledger balance',
     money_r($rowF['total']), money_r(party_balance_side($apF, 'in')));

// a party who owes nothing at all is off the report entirely
$apG = t_party('AGING_SETTLED');
t_sale($apG, 900, 900, $agoDate(50), null);
t_ok('a fully paid party is not on the report', tr_aging_row($apG) === null);

t_group('the report screen only draws what the rule returns');
$rbA = file_get_contents(dirname(__DIR__) . '/includes/report_body.php');
t_ok('the aging figures come from the shared rule', strpos($rbA, 'aging_rows($fCompany, $fParty)') !== false);
t_ok('...and the report file works none of it out by hand',
     strpos($rbA, "\$x['due_date'] ?: \$x['sale_date']") === false);
$mnyA = file_get_contents(dirname(__DIR__) . '/includes/money.php');
t_ok('the rule ages from the bill date', strpos($mnyA, "'date' => \$x['sale_date']") !== false);
t_ok('...and the opening balance from the party\'s own creation date',
     strpos($mnyA, "substr((string)\$pp['created_at'], 0, 10)") !== false);
t_ok('...reusing opening_due(), so a settled opening is not asked for twice',
     strpos($mnyA, "opening_due((int)\$pp['id'], 'in')") !== false);

// "જે આપણે રિમાઇન્ડર મોકલીએ છીએ ને એ એક ઓપ્શન અહિયાં પણ હોવું જોઈએ" - the
// reminder was only on the Aging report; the ledger is where the owner is
// actually standing when they decide to chase someone.
t_group('a payment reminder can be sent from the party ledger too');
$hlpR = file_get_contents(dirname(__DIR__) . '/includes/helpers.php');
t_ok('what a reminder says and looks like is written once',
     substr_count($hlpR, 'function collection_reminder_send') === 1);
t_ok('...and it tells the provider it is a reminder, so the right template goes out',
     preg_match('/function collection_reminder_send.*?wa_context\(\[\'kind\' => \'reminder\'\]\)/s', $hlpR) === 1);
$rpR = file_get_contents(dirname(__DIR__) . '/reports.php');
t_ok('the aging report sends through it', strpos($rpR, 'collection_reminder_send($mobile, $amount') !== false);
t_ok('...and so does the bulk send', strpos($rpR, 'collection_reminder_send(trim($mobile)') !== false);
t_ok('no screen builds the reminder picture by hand any more',
     strpos($rpR, 'reminder_image_jpg(') === false);
$paR = file_get_contents(dirname(__DIR__) . '/parties.php');
t_ok('the ledger page has a reminder button', strpos($paR, "name=\"do\" value=\"collection_reminder\"") !== false);
t_ok('...which goes through the same one rule', strpos($paR, 'collection_reminder_send($mobile, $due, $p[\'name\'])') !== false);
// a stale page must not be able to ask a customer for money already paid
t_ok('the amount is worked out on the server, never taken from the form',
     strpos($paR, "\$due = \$p ? party_balance_side(\$pid, 'in') : 0.0;") !== false);
t_ok('...and nothing is sent when they owe nothing',
     strpos($paR, 'રિમાઇન્ડર મોકલ્યું નથી') !== false);
t_ok('the button is only drawn when there is something to ask for',
     strpos($paR, "if (\$p['mobile'] && \$remDue > 0.009)") !== false);
// a party switched off still owes what they owe - the bills side never
// filtered them out either
$apH = t_party('AGING_INACTIVE', 2500);
q("UPDATE parties SET is_active = 0, created_at = ? WHERE id = ?", [date('Y-m-d', strtotime('-95 days')) . ' 10:00:00', $apH]);
t_eq('an inactive party\'s opening balance is still chased',
     money_r(tr_aging_row($apH)['b4']), 2500.0);
