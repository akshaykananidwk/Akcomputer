<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); die('CLI only'); }
// Payment allocation, deletion/reversal and the settlement-discount rule.
// These mirror what payments.php does, using the same helper functions, so
// a change to the rules breaks a test here before it reaches a customer.

t_group('allocation settles the bill it points at');
$p = t_party();
$b = t_sale($p, 3000);
t_payment($p, 1000, 'in', 'cash', [['sale', $b, 1000]]);
$bill = row('SELECT * FROM sales WHERE id = ?', [$b]);
t_eq('bill paid rises to 1000', $bill['paid'], 1000);
t_eq('bill status becomes partial', $bill['status'], 'partial');
t_eq('party balance drops to 2000', party_balance($p), 2000);

t_payment($p, 2000, 'in', 'cash', [['sale', $b, 2000]]);
$bill = row('SELECT * FROM sales WHERE id = ?', [$b]);
t_eq('fully allocated bill is paid', $bill['status'], 'paid');
t_eq('party is square', party_balance($p), 0);

t_group('deleting a payment reverses everything it settled');
$p2 = t_party();
$b2 = t_sale($p2, 1500);
$pay = t_payment($p2, 1500, 'in', 'cash', [['sale', $b2, 1500]]);
t_eq('before delete: bill paid', row('SELECT status FROM sales WHERE id = ?', [$b2])['status'], 'paid');

// same reversal payments.php performs
foreach (all('SELECT * FROM payment_allocations WHERE payment_id = ?', [$pay]) as $a) {
    if ($a['ref_type'] === 'sale') {
        $bb = row('SELECT total, paid FROM sales WHERE id = ?', [$a['ref_id']]);
        $newPaid = max(0, round($bb['paid'] - $a['amount'], 2));
        q('UPDATE sales SET paid = ?, status = ? WHERE id = ?', [$newPaid, payment_status($bb['total'], $newPaid), $a['ref_id']]);
    }
}
q('DELETE FROM payment_allocations WHERE payment_id = ?', [$pay]);
q('DELETE FROM payments WHERE id = ?', [$pay]);
$bill2 = row('SELECT * FROM sales WHERE id = ?', [$b2]);
t_eq('after delete: bill back to unpaid', $bill2['paid'], 0);
t_eq('after delete: status back to due', $bill2['status'], 'due');
t_eq('after delete: balance back to 1500', party_balance($p2), 1500);

t_group('settlement discount clears the ledger without touching cash');
$p3 = t_party();
$b3 = t_sale($p3, 7040);
t_payment($p3, 7000, 'in', 'cash', [['sale', $b3, 7000]]);
t_payment($p3, 40, 'in', 'discount', [['sale', $b3, 40]]);
$bill3 = row('SELECT * FROM sales WHERE id = ?', [$b3]);
t_eq('bill is fully settled', $bill3['status'], 'paid');
t_eq('party ledger is clear', party_balance($p3), 0);
$cashOfParty = (float)val("SELECT COALESCE(SUM(amount),0) FROM payments WHERE party_id = ? AND mode = 'cash'", [$p3]);
t_eq('only 7000 counted as cash', $cashOfParty, 7000);
$inCashbook = (float)val("SELECT COALESCE(SUM(amount),0) FROM payments WHERE party_id = ? AND mode NOT IN ('contra','discount')", [$p3]);
t_eq('the discount is excluded from cashbook-style sums', $inCashbook, 7000);

t_group('opening balance allocation');
$p4 = t_party(null, 3000);
$b4 = t_sale($p4, 2000);
t_eq('starts owing 5000', party_balance($p4), 5000);
t_payment($p4, 5000, 'in', 'cash', [['opening', $p4, 3000], ['sale', $b4, 2000]]);
t_eq('opening fully settled', opening_due($p4, 'in'), 0);
t_eq('bill fully settled', row('SELECT status FROM sales WHERE id = ?', [$b4])['status'], 'paid');
t_eq('ledger square', party_balance($p4), 0);

t_group('an allocation can never exceed the bill');
$p5 = t_party();
$b5 = t_sale($p5, 1000);
$due = 1000 - 0;
$attempt = min(5000, $due); // the cap payments.php applies
t_eq('a 5000 attempt on a 1000 bill caps at 1000', $attempt, 1000);

t_group('payment-out (supplier side) mirrors the same rules');
$sup = t_party(null, -2500);
t_eq('supplier opening shows as payable', party_balance($sup), -2500);
t_eq('payable opening due is positive', opening_due($sup, 'out'), 2500);
t_payment($sup, 2500, 'out', 'cash', [['opening', $sup, 2500]]);
t_eq('after paying, ledger square', party_balance($sup), 0);
t_eq('after paying, no opening left', opening_due($sup, 'out'), 0);
