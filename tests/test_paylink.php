<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); die('CLI only'); }
// ============================================================================
//  LINKING PAYMENTS TO BILLS LATER (includes/paylink.php)
//
//  A link decides which bill a payment pays. It must never create, delete or
//  change a payment, never move a party's balance, never put more on a bill
//  than it owes or more than the payment has free, and never cross parties.
// ============================================================================
t_group('Payment links — link later, split, unlink, advance');
$u = row('SELECT u.*, l.name location_name FROM users u JOIN locations l ON l.id = u.location_id ORDER BY u.id LIMIT 1'); $u['perms'] = ['*'];
$pa = t_party(); $pb = t_party();
$b1 = t_sale($pa, 1000, 0, '2026-03-01'); $b2 = t_sale($pa, 600, 0, '2026-03-05');
$other = t_sale($pb, 500, 0, '2026-03-02');
$bal0 = party_balance($pa);
$paysBefore = (int)val('SELECT COUNT(*) FROM payments');
// a payment kept as an advance: nothing is auto-linked
$_POST = ['party_id' => $pa, 'direction' => 'in', 'amount' => 1500, 'mode' => 'cash', 'pay_date' => '2026-03-10', 'on_account' => '1'];
$r = payment_save_form($u); $pid = $r['id']; $_POST = [];
$p = paylink_get($pid);
t_eq('kept as an advance: nothing is put on any bill', $p['linked'], 0);
t_ok('...status 🔵 advance', $p['st'] === 'advance');
t_eq('...and the bills stay unpaid', (float)val('SELECT paid FROM sales WHERE id = ?', [$b1]), 0);
t_eq('the party balance counts the payment all the same', party_balance($pa), $bal0 - 1500);

$bal1 = party_balance($pa);
paylink_link($pid, $b1, 1000);
t_eq('link ₹1,000 to bill 1: bill 1 is paid', (float)val('SELECT paid FROM sales WHERE id = ?', [$b1]), 1000);
t_ok('...its status is paid', val('SELECT status FROM sales WHERE id = ?', [$b1]) === 'paid');
t_ok('...the payment is 🔵 still (₹500 kept as advance)', paylink_get($pid)['st'] === 'advance');
paylink_set_advance($pid, false);
t_ok('without the advance mark the rest shows 🟡 partly linked', paylink_get($pid)['st'] === 'partial');
paylink_link($pid, $b2, 300);
paylink_link($pid, $b2, 200);
t_eq('two links to the same bill become one row', (int)val("SELECT COUNT(*) FROM payment_allocations WHERE payment_id = ? AND ref_type = 'sale' AND ref_id = ?", [$pid, $b2]), 1);
t_eq('...holding both amounts', (float)val("SELECT amount FROM payment_allocations WHERE payment_id = ? AND ref_id = ?", [$pid, $b2]), 500);
t_ok('one payment over two bills: now 🟢 fully linked', paylink_get($pid)['st'] === 'linked');
t_eq('linking never moves the party balance', party_balance($pa), $bal1);
t_eq('...and never creates or deletes a payment', (int)val('SELECT COUNT(*) FROM payments'), $paysBefore + 1);

$refused = function (callable $f) { try { $f(); } catch (PaylinkRefused $e) { return $e->getMessage(); } return ''; };
t_ok('more than the payment has free is refused', $refused(fn() => paylink_link($pid, $b2, 1)) !== '');
$_POST = ['party_id' => $pa, 'direction' => 'in', 'amount' => 900, 'mode' => 'upi', 'pay_date' => '2026-03-11', 'on_account' => '1'];
$pid2 = payment_save_form($u)['id']; $_POST = [];
t_ok('more than the bill still owes is refused', $refused(fn() => paylink_link($pid2, $b2, 200)) !== '');
t_ok('a bill of another party is refused', $refused(fn() => paylink_link($pid2, $other, 100)) !== '');
q('UPDATE sales SET is_cancelled = 1 WHERE id = ?', [$other]);
q('UPDATE sales SET party_id = ? WHERE id = ?', [$pa, $other]);
t_ok('a cancelled bill is refused', $refused(fn() => paylink_link($pid2, $other, 100)) !== '');
t_ok('a payment-in cannot go on a purchase bill', (function () use ($pid2, $pa, $refused) {
    $pu = (int)val("SELECT id FROM purchases ORDER BY id DESC LIMIT 1");
    return !$pu || $refused(fn() => paylink_link($pid2, $pu, 1)) !== '';
})());
$b3 = t_sale($pa, 400, 0, '2026-03-12');
paylink_link($pid2, $b3, 400);
t_eq('several payments on one bill over different days: second payment linked to bill 3', (float)val('SELECT paid FROM sales WHERE id = ?', [$b3]), 400);

// unlink: the bill gets back exactly what the link put on it
$link = (int)val("SELECT id FROM payment_allocations WHERE payment_id = ? AND ref_id = ?", [$pid, $b2]);
$balU = party_balance($pa);
paylink_unlink($pid, $link);
t_eq('unlink: bill 2 is unpaid again', (float)val('SELECT paid FROM sales WHERE id = ?', [$b2]), 0);
t_ok('...its status is back to due', val('SELECT status FROM sales WHERE id = ?', [$b2]) === 'due');
t_ok('...the payment is 🟡 partly linked again, its money free', paylink_get($pid)['st'] === 'partial' && abs(paylink_get($pid)['amount'] - paylink_get($pid)['linked'] - 500) < 0.01);
t_eq('...the party balance has not moved', party_balance($pa), $balU);
t_ok('...the payment itself is still there, unchanged', (float)val('SELECT amount FROM payments WHERE id = ?', [$pid]) === 1500.0);
// relink to the right bill
paylink_link($pid, $b2, 500);
t_eq('relink: bill 2 paid again', (float)val('SELECT paid FROM sales WHERE id = ?', [$b2]), 500);

// a bill's own payment (taken with the bill) can be unlinked and relinked
$co = (int)val('SELECT id FROM companies ORDER BY id LIMIT 1');
$_POST = ['company_id' => $co, 'location_id' => $u['location_id'], 'party_id' => $pa, 'sale_date' => '2026-03-15', 'payment_mode' => 'cash', 'paid' => 250,
          'row_n' => [1], 'item_id' => [t_item(5)], 'qty' => [1], 'price' => [250], 'tax_rate' => [0]];
$s4 = sale_save_form($u); $_POST = [];
$own = (int)val("SELECT id FROM payments WHERE ref_type = 'sale' AND ref_id = ?", [$s4['id']]);
t_ok('a payment taken with its bill shows 🟢 linked', paylink_get($own)['st'] === 'linked');
paylink_unlink($own, 0);
t_eq('...unlinked from its own bill, the bill is unpaid', (float)val('SELECT paid FROM sales WHERE id = ?', [$s4['id']]), 0);
t_ok('...and the payment is 🔴 not linked, still there', paylink_get($own)['st'] === 'unlinked');
paylink_link($own, $s4['id'], 250);
t_ok('...and can be linked back', paylink_get($own)['st'] === 'linked' && (float)val('SELECT paid FROM sales WHERE id = ?', [$s4['id']]) === 250.0);

$bill = paylink_bill('sale', $b2);
t_eq('the bill knows the payments on it', $bill['linked'], 500);
t_ok('...its colour: partly paid (₹600 bill, ₹500 on it), ₹100 outstanding', $bill['state'] === 'partial' && abs($bill['outstanding'] - 100) < 0.01);
t_ok('a fully paid bill is green', paylink_bill('sale', $b1)['state'] === 'paid');
t_eq('the party\'s free money shows on its other bills', paylink_bill('sale', $b1)['free_total'], round(1500 + 900 - 1000 - 500 - 400, 2));
$st = all('SELECT ' . paylink_status_sql('p') . ' st, COUNT(*) n FROM payments p WHERE p.party_id = ? GROUP BY st', [$pa]);
t_ok('the list can be filtered by status in SQL', count($st) >= 1);

// a period lock protects links too
set_setting('period_lock_date', '2026-03-31');
t_ok('a locked period cannot be linked or unlinked', $refused(fn() => paylink_unlink($pid, (int)val('SELECT id FROM payment_allocations WHERE payment_id = ? LIMIT 1', [$pid]))) !== '');
set_setting('period_lock_date', '');
