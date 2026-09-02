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

// ---------------------------------------- a purchase return that is a credit --
// The shop's rule: when goods go back to a supplier and we do not take cash
// back, that credit must land on the OLDEST bill we still owe them - not float
// above the bills as an unattached balance.
t_group('an adjusted purchase return credits the oldest due bill first');

/** A supplier with $bills = [total, ...] of unpaid purchase bills, oldest first. */
function ts_supplier_bills(array $totals) {
    global $co, $loc;
    $sup = t_party('TS Supplier ' . bin2hex(random_bytes(3)));
    $ids = [];
    foreach ($totals as $k => $t) {
        q("INSERT INTO purchases (company_id, location_id, party_id, bill_no, purchase_date, subtotal, total, paid, status, created_by)
           VALUES (?,?,?,?,?,?,?,0,'due',1)",
          [$co, $loc, $sup, 'TSP-' . bin2hex(random_bytes(4)), date('Y-m-d', strtotime('2026-01-0' . ($k + 1))), $t, $t]);
        $ids[] = insert_id();
    }
    return [$sup, $ids];
}
/** Books the purchase_returns row itself, exactly as purchase_return.php does. */
function ts_return($sup, $total, $mode = 'adjust') {
    global $loc;
    q("INSERT INTO purchase_returns (party_id, location_id, return_date, total, refund_mode, notes, created_by)
       VALUES (?,?,?,?,?, 'test', 1)", [$sup, $loc, '2026-02-01', $total, $mode]);
    return insert_id();
}
function ts_bill($id) { return row('SELECT total, paid, status FROM purchases WHERE id = ?', [$id]); }

$co  = (int)val('SELECT id FROM companies ORDER BY id LIMIT 1');
$loc = (int)val('SELECT id FROM locations ORDER BY id LIMIT 1');

// 300 credit against bills of 1000 (older) and 2000
list($sup, $b) = ts_supplier_bills([1000, 2000]);
$balBefore = party_balance($sup);
$ret = ts_return($sup, 300);
$done = money_apply_return_credit('purchase', $ret, $sup, 300);
t_eq('one bill is credited', count($done), 1);
t_eq('...and it is the oldest one', $done[0]['ref_id'], $b[0]);
t_eq('the oldest bill is part paid', ts_bill($b[0])['paid'], 300.0);
t_eq('...and reads as partial', ts_bill($b[0])['status'], 'partial');
t_eq('the newer bill is untouched', ts_bill($b[1])['paid'], 0.0);
t_eq('...and still reads as due', ts_bill($b[1])['status'], 'due');
// The ledger must move by the return and by NOTHING else: no payments row is
// written, because party_balance_expr() already counts purchase_returns.
t_eq('the supplier ledger moves by the return, once', party_balance($sup), $balBefore + 300);
t_eq('no payment row was created', (int)val("SELECT COUNT(*) FROM payments WHERE ref_type = 'purchase_return' AND ref_id = ?", [$ret]), 0);

// a credit bigger than the oldest bill spills onto the next one, in order
list($sup2, $b2) = ts_supplier_bills([1000, 2000]);
$ret2 = ts_return($sup2, 1500);
$done2 = money_apply_return_credit('purchase', $ret2, $sup2, 1500);
t_eq('two bills are credited', count($done2), 2);
t_eq('the oldest is settled in full', ts_bill($b2[0])['paid'], 1000.0);
t_eq('...and reads as paid', ts_bill($b2[0])['status'], 'paid');
t_eq('the remainder lands on the next bill', ts_bill($b2[1])['paid'], 500.0);
t_eq('...which is now partial', ts_bill($b2[1])['status'], 'partial');
t_eq('nothing more than the credit was applied', $done2[0]['amount'] + $done2[1]['amount'], 1500.0);

// a credit bigger than everything owed settles all of it and no more
list($sup3, $b3) = ts_supplier_bills([1000, 2000]);
$ret3 = ts_return($sup3, 5000);
$done3 = money_apply_return_credit('purchase', $ret3, $sup3, 5000);
t_eq('every bill is settled', ts_bill($b3[0])['status'] . '/' . ts_bill($b3[1])['status'], 'paid/paid');
t_eq('no bill is over-paid', ts_bill($b3[1])['paid'], 2000.0);
t_eq('only what the bills owed was applied', array_sum(array_column($done3, 'amount')), 3000.0);
// the unused 2000 is a real credit the supplier owes us back
t_eq('the leftover shows as a credit on the supplier', party_balance($sup3), 2000.0);

// a supplier with nothing outstanding: the credit simply waits
list($sup4, $b4) = ts_supplier_bills([500]);
q("UPDATE purchases SET paid = 500, status = 'paid' WHERE id = ?", [$b4[0]]);
$ret4 = ts_return($sup4, 400);
t_eq('there is no bill to credit', count(money_apply_return_credit('purchase', $ret4, $sup4, 400)), 0);
t_eq('...and nothing was written', (int)val('SELECT COUNT(*) FROM purchase_return_credits WHERE return_id = ?', [$ret4]), 0);

t_group('a cash refund settles no bill — the money already came back');
list($sup5, $b5) = ts_supplier_bills([1000]);
$ret5 = ts_return($sup5, 300, 'cash');
// purchase_return.php only calls the credit rule for 'adjust'; prove the page
// guards it rather than the rule being harmless by accident
$src = file_get_contents(dirname(__DIR__) . '/purchase_return.php');
t_ok('the page applies the credit only when the refund is adjusted',
     strpos($src, "if (\$refundMode === 'adjust')\n            \$creditedTo = money_apply_return_credit('purchase'") !== false);
t_eq('the bill is left alone', ts_bill($b5[0])['paid'], 0.0);

t_group('deleting the return gives the bills back exactly what it took');
list($sup6, $b6) = ts_supplier_bills([1000, 2000]);
$ret6 = ts_return($sup6, 1500);
money_apply_return_credit('purchase', $ret6, $sup6, 1500);
// a later payment touches the SAME bill, so a "recalculate" would guess wrong
money_settle_oldest_first($sup6, 'out', 700);
t_eq('the second bill now carries both', ts_bill($b6[1])['paid'], 1200.0);
$rows = money_reverse_return_credit('purchase', $ret6);
t_eq('both credit rows are reversed', $rows, 2);
t_eq('the oldest bill is back to nothing paid', ts_bill($b6[0])['paid'], 0.0);
t_eq('...and back to due', ts_bill($b6[0])['status'], 'due');
t_eq('the later payment survives on the second bill', ts_bill($b6[1])['paid'], 700.0);
t_eq('...which stays partial', ts_bill($b6[1])['status'], 'partial');
t_eq('the credit rows are gone', (int)val('SELECT COUNT(*) FROM purchase_return_credits WHERE return_id = ?', [$ret6]), 0);
t_ok('the delete path calls the reversal',
     strpos($src, "money_reverse_return_credit('purchase', \$rid)") !== false);

t_group('the credit never invents money');
list($sup7, $b7) = ts_supplier_bills([1000]);
$ret7 = ts_return($sup7, 0);
t_eq('a zero return credits nothing', count(money_apply_return_credit('purchase', $ret7, $sup7, 0)), 0);
t_eq('a walk-in return (no supplier) credits nothing', count(money_apply_return_credit('purchase', $ret7, 0, 500)), 0);
t_eq('the bill is untouched', ts_bill($b7[0])['paid'], 0.0);

// -------------------------------------- the same rule on the sales side too --
// Goods coming back from a customer are the mirror image: if we are not
// handing cash over, the credit belongs against the bills THEY owe us. This
// used to reach only the one original invoice, so a credit against an
// already-paid invoice - or a return with no invoice number typed in - went
// nowhere at all.
t_group('a sales return credit lands on the customer\'s bills');

function ts_customer_bills(array $totals) {
    $p = t_party('TS Customer ' . bin2hex(random_bytes(3)));
    $ids = [];
    foreach ($totals as $k => $t) $ids[] = t_sale($p, $t, 0, date('Y-m-d', strtotime('2026-01-0' . ($k + 1))));
    return [$p, $ids];
}
function ts_sret($party, $total, $mode = 'adjust') {
    global $loc;
    q("INSERT INTO sales_returns (party_id, customer_name, location_id, return_date, total, refund_mode, notes, created_by)
       VALUES (?, 'test', ?, '2026-02-01', ?, ?, 'test', 1)", [$party, $loc, $total, $mode]);
    return insert_id();
}
function ts_sale_bill($id) { return row('SELECT total, paid, status FROM sales WHERE id = ?', [$id]); }

// oldest first, exactly as on the purchase side
list($cu, $sb) = ts_customer_bills([1000, 2000]);
$balBefore = party_balance($cu);
$sr = ts_sret($cu, 1500);
$sd = money_apply_return_credit('sale', $sr, $cu, 1500);
t_eq('the oldest bill is settled in full', ts_sale_bill($sb[0])['paid'], 1000.0);
t_eq('...and reads as paid', ts_sale_bill($sb[0])['status'], 'paid');
t_eq('the rest lands on the next bill', ts_sale_bill($sb[1])['paid'], 500.0);
t_eq('the customer ledger moves by the return, once', party_balance($cu), $balBefore - 1500);
t_eq('no payment row was created', (int)val("SELECT COUNT(*) FROM payments WHERE ref_type = 'sales_return' AND ref_id = ?", [$sr]), 0);
t_eq('the rows are written on the sale side', (int)val('SELECT COUNT(*) FROM sales_return_credits WHERE return_id = ?', [$sr]), 2);

// naming a bill sends the credit THERE first, and the remainder still spills
list($cu2, $sb2) = ts_customer_bills([1000, 2000, 3000]);
$sr2 = ts_sret($cu2, 2500);
$sd2 = money_apply_return_credit('sale', $sr2, $cu2, 2500, $sb2[1]);   // the middle bill
t_eq('the named bill is credited first', $sd2[0]['ref_id'], $sb2[1]);
t_eq('...in full', ts_sale_bill($sb2[1])['paid'], 2000.0);
t_eq('the remainder runs down the oldest of the rest', ts_sale_bill($sb2[0])['paid'], 500.0);
t_eq('...and the newest is untouched', ts_sale_bill($sb2[2])['paid'], 0.0);
t_eq('the named bill is never credited twice', count(array_filter($sd2, fn($t) => $t['ref_id'] === $sb2[1])), 1);
t_eq('nothing beyond the credit was applied', array_sum(array_column($sd2, 'amount')), 2500.0);

// THE bug this replaces: the original invoice is already paid, so the old code
// applied nothing at all and the credit vanished from the bills
list($cu3, $sb3) = ts_customer_bills([1000, 2000]);
q("UPDATE sales SET paid = 1000, status = 'paid' WHERE id = ?", [$sb3[0]]);
$sr3 = ts_sret($cu3, 600);
$sd3 = money_apply_return_credit('sale', $sr3, $cu3, 600, $sb3[0]);   // names the PAID invoice
t_eq('a paid invoice takes nothing', ts_sale_bill($sb3[0])['paid'], 1000.0);
t_eq('...and the credit moves on to the bill that is open', ts_sale_bill($sb3[1])['paid'], 600.0);
t_eq('so none of it is lost', array_sum(array_column($sd3, 'amount')), 600.0);

// deleting reverses exactly, across both bills
list($cu4, $sb4) = ts_customer_bills([1000, 2000]);
$sr4 = ts_sret($cu4, 1500);
money_apply_return_credit('sale', $sr4, $cu4, 1500);
money_settle_oldest_first($cu4, 'in', 300);      // a later payment on the same bill
t_eq('both credit rows are reversed', money_reverse_return_credit('sale', $sr4), 2);
t_eq('the oldest bill is back to nothing paid', ts_sale_bill($sb4[0])['paid'], 0.0);
t_eq('the later payment survives', ts_sale_bill($sb4[1])['paid'], 300.0);

// the two sides never write into each other's table
list($cu5, $sb5) = ts_customer_bills([500]);
$sr5 = ts_sret($cu5, 500);
money_apply_return_credit('sale', $sr5, $cu5, 500);
t_eq('a sale credit writes nothing on the purchase side',
     (int)val('SELECT COUNT(*) FROM purchase_return_credits WHERE return_id = ?', [$sr5]), 0);
t_eq('the sale side has it', (int)val('SELECT COUNT(*) FROM sales_return_credits WHERE return_id = ?', [$sr5]), 1);
t_eq('the two tables are named apart', return_credit_table('sale') . '/' . return_credit_table('purchase'),
     'sales_return_credits/purchase_return_credits');

t_group('the return screens ask the three money questions');
$sr_src = file_get_contents(dirname(__DIR__) . '/sales_return.php');
$pr_src = file_get_contents(dirname(__DIR__) . '/purchase_return.php');
foreach (['sales_return.php' => $sr_src, 'purchase_return.php' => $pr_src] as $name => $src) {
    t_ok($name . ' asks how the refund is given', strpos($src, "name=\"refund_mode\" id=\"refundMode\"") !== false);
    t_ok($name . ' asks which bank account', strpos($src, 'name="bank_account_id"') !== false);
    t_ok($name . ' asks which bill to credit', strpos($src, 'name="credit_bill_id"') !== false);
    // the posted account must actually be used, not collected and ignored
    t_ok($name . ' uses the account the owner picked',
         strpos($src, "resolve_payment_target(") !== false && strpos($src, "(int)post('bank_account_id')") !== false);
    t_ok($name . ' passes the picked bill through to the rule',
         strpos($src, "post('credit_bill_id')") !== false);
}
$js = file_get_contents(dirname(__DIR__) . '/assets/app.js');
t_ok('the bill list comes from the same endpoint the payment allocator uses',
     strpos($js, "ajax.php?a=party_bills&dir=") !== false);
t_ok('the opening-balance line is not offered as a bill', strpos($js, "if (b.id === 'op') return;") !== false);

t_group('a sales return can credit a customer with no original invoice');
// the old form had no customer field at all, so an adjust without an invoice
// number had nobody to credit and silently did nothing
t_ok('the form has a customer account picker', strpos($sr_src, '<select name="party_id">') !== false);
t_ok('...and the invoice still wins when one is given',
     strpos($sr_src, '$party_id = $sale ? (int)$sale[\'party_id\'] : (int)post(\'party_id\');') !== false);
t_ok('a return with no customer says so rather than swallowing the credit',
     strpos($sr_src, 'No customer was given, so there was no account to credit.') !== false);
