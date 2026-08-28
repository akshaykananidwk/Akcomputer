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
