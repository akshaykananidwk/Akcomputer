<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); die('CLI only'); }
// The settlement engine in includes/money.php — the rules payments.php used
// to carry in four near-identical copies. These tests exist so the copies can
// never come back: if a screen ever stops using the central function, the
// figures it produces will drift away from what is asserted here.

t_group('money_trim_dues() — the oldest bill absorbs covered-but-unlinked money');
t_eq('nothing to trim when the ledger agrees', money_trim_dues([1000, 500], 1500), [1000.0, 500.0]);
t_eq('the oldest bill absorbs it all', money_trim_dues([2800, 2041], 2041), [0.0, 2041.0]);
t_eq('trimming stops once the excess is used up', money_trim_dues([1000, 500, 300], 800), [0.0, 500.0, 300.0]);
t_eq('a fully covered party claims nothing', money_trim_dues([1000, 500], 0), [0.0, 0.0]);
t_eq('a negative balance is treated as zero', money_trim_dues([600], -900), [0.0]);
t_eq('partial trim of a single bill', money_trim_dues([12411], 2000), [2000.0]);
t_eq('paise survive the trim', money_trim_dues([100.55, 50.25], 50.25), [0.0, 50.25]);
t_eq('an empty list is safe', money_trim_dues([], 500), []);

t_group('sale_true_due() and the Payments list agree — one rule, two screens');
$p = t_party();
$b1 = t_sale($p, 2800, 0, '2026-07-01');
$b2 = t_sale($p, 2041, 0, '2026-07-20');
t_payment($p, 2800); // never linked to a bill
$bills = all("SELECT s.* FROM sales s WHERE s.party_id = ? AND s.status <> 'paid'
              ORDER BY s.due_date IS NULL, s.due_date, s.id", [$p]);
$capped = money_cap_bill_dues($bills, 'in');
$byId = [];
foreach ($capped as $c) $byId[(int)$c['id']] = (float)($c['adj_due'] ?? ($c['total'] - $c['paid']));
t_ok('the settled old bill drops off the Payments list', !isset($byId[$b1]));
t_eq('the Payments list shows 2041 for the new bill', $byId[$b2] ?? -1, 2041.0);
t_eq('the reminder figure for the same bill matches exactly',
     sale_true_due(row('SELECT * FROM sales WHERE id = ?', [$b2])), $byId[$b2] ?? -1);
t_eq('the old bill claims nothing anywhere',
     sale_true_due(row('SELECT * FROM sales WHERE id = ?', [$b1])), 0.0);
t_eq('the two never exceed the real balance',
     sale_true_due(row('SELECT * FROM sales WHERE id = ?', [$b1])) + sale_true_due(row('SELECT * FROM sales WHERE id = ?', [$b2])),
     party_balance($p));

t_group('money_settle_oldest_first() — a payment with no bill picked');
$p2 = t_party();
$o1 = t_sale($p2, 1000, 0, '2026-01-01');
$o2 = t_sale($p2, 1000, 0, '2026-02-01');
$o3 = t_sale($p2, 1000, 0, '2026-03-01');
$takes = money_settle_oldest_first($p2, 'in', 1500);
t_eq('it touches exactly two bills', count($takes), 2);
t_eq('the oldest bill is settled first', $takes[0]['ref_id'], $o1);
t_eq('the oldest takes its full 1000', $takes[0]['amount'], 1000.0);
t_eq('the next takes the remaining 500', $takes[1]['amount'], 500.0);
t_eq('the third bill is untouched', row('SELECT paid FROM sales WHERE id = ?', [$o3])['paid'], 0);
t_eq('bill 1 is now marked paid', row('SELECT status FROM sales WHERE id = ?', [$o1])['status'], 'paid');
t_eq('bill 2 is now partial', row('SELECT status FROM sales WHERE id = ?', [$o2])['status'], 'partial');
t_eq('it reports the ref_type the allocations need', $takes[0]['ref_type'], 'sale');

t_group('settlement never takes more than is owed');
$p3 = t_party();
$s3 = t_sale($p3, 700, 0, '2026-01-01');
$t3 = money_settle_oldest_first($p3, 'in', 5000);
t_eq('only 700 is taken from a 700 bill', $t3[0]['amount'], 700.0);
t_eq('the bill is not overpaid', row('SELECT paid FROM sales WHERE id = ?', [$s3])['paid'], 700);
t_eq('nothing to settle returns an empty list', money_settle_oldest_first($p3, 'in', 1000), []);
t_eq('a zero amount settles nothing', money_settle_oldest_first($p3, 'in', 0), []);
t_eq('a walk-in payment (no party) settles nothing', money_settle_oldest_first(null, 'in', 500), []);

t_group('money_settle_bill() — the owner picks one bill');
$p4 = t_party();
$s4 = t_sale($p4, 900, 0, '2026-01-01');
t_eq('it uses what was asked for', money_settle_bill($s4, $p4, 'in', 400), 400.0);
t_eq('the bill records it', row('SELECT paid FROM sales WHERE id = ?', [$s4])['paid'], 400);
t_eq('a second payment caps at what is left', money_settle_bill($s4, $p4, 'in', 5000), 500.0);
t_eq('the bill is now paid', row('SELECT status FROM sales WHERE id = ?', [$s4])['status'], 'paid');
t_eq('a settled bill takes nothing more', money_settle_bill($s4, $p4, 'in', 100), 0.0);
t_eq('another party cannot settle this bill', money_settle_bill($s4, t_party(), 'in', 100), 0.0);

t_group('money_reverse_bill_paid() — deleting a payment puts the bill back');
$p5 = t_party();
$s5 = t_sale($p5, 1200, 0, '2026-01-01');
money_settle_oldest_first($p5, 'in', 1200);
t_eq('bill starts out paid', row('SELECT status FROM sales WHERE id = ?', [$s5])['status'], 'paid');
money_reverse_bill_paid('sale', $s5, 1200);
t_eq('reversal returns paid to zero', row('SELECT paid FROM sales WHERE id = ?', [$s5])['paid'], 0);
t_eq('reversal returns status to due', row('SELECT status FROM sales WHERE id = ?', [$s5])['status'], 'due');
money_reverse_bill_paid('sale', $s5, 500);
t_eq('reversing past zero never goes negative', row('SELECT paid FROM sales WHERE id = ?', [$s5])['paid'], 0);
t_ok('reversing an opening allocation touches no bill', money_reverse_bill_paid('opening', $p5, 100) === null);

t_group('money_split_cash_discount() — cash settles first, discount rides behind');
list($cash, $disc) = money_split_cash_discount(
    [['ref_type' => 'sale', 'ref_id' => 1, 'amount' => 7000.0],
     ['ref_type' => 'sale', 'ref_id' => 2, 'amount' => 40.0]], 7000);
t_eq('all 7000 of cash goes to the first bill', $cash, [['ref_type' => 'sale', 'ref_id' => 1, 'amount' => 7000.0]]);
t_eq('the 40 rides the discount row', $disc, [['ref_type' => 'sale', 'ref_id' => 2, 'amount' => 40.0]]);

list($cash2, $disc2) = money_split_cash_discount([['ref_type' => 'sale', 'ref_id' => 1, 'amount' => 100.0]], 60);
t_eq('one bill can be split across both rows', $cash2, [['ref_type' => 'sale', 'ref_id' => 1, 'amount' => 60.0]]);
t_eq('…and the rest becomes discount', $disc2, [['ref_type' => 'sale', 'ref_id' => 1, 'amount' => 40.0]]);

t_group('the supplier side settles by exactly the same rule');
$sup = t_party();
$co = (int)val('SELECT id FROM companies ORDER BY id LIMIT 1');
$loc = (int)val('SELECT id FROM locations ORDER BY id LIMIT 1');
q("INSERT INTO purchases (company_id, location_id, party_id, bill_no, purchase_date, subtotal, total, paid, status, created_by)
   VALUES (?,?,?,?, '2026-01-01', 3000, 3000, 0, 'due', 1)", [$co, $loc, $sup, 'TP-' . bin2hex(random_bytes(3))]);
$pu = insert_id();
$tk = money_settle_oldest_first($sup, 'out', 1800);
t_eq('the purchase bill is settled', $tk[0]['ref_id'], $pu);
t_eq('with the purchase ref_type', $tk[0]['ref_type'], 'purchase');
t_eq('purchase becomes partial', row('SELECT status FROM purchases WHERE id = ?', [$pu])['status'], 'partial');
