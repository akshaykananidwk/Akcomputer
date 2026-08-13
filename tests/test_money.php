<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); die('CLI only'); }
// payment_status() · party_balance() · opening_due() · sale_true_due()
// The four rules every screen, reminder and WhatsApp reply depends on.

t_group('payment_status() — the paid/partial/due rule');
t_eq('nothing paid on a 1000 bill = due', payment_status(1000, 0), 'due');
t_eq('part paid = partial', payment_status(1000, 400), 'partial');
t_eq('exactly paid = paid', payment_status(1000, 1000), 'paid');
t_eq('overpaid = paid', payment_status(1000, 1200), 'paid');
t_eq('a rounding paisa short still counts as paid', payment_status(1000, 999.995), 'paid');
t_eq('zero-total bill is paid, never due', payment_status(0, 0), 'paid');

t_group('party_balance() — the ledger is the truth');
$p = t_party();
t_eq('new party starts at zero', party_balance($p), 0);
$s1 = t_sale($p, 5000);
t_eq('a 5000 credit bill makes 5000 receivable', party_balance($p), 5000);
t_payment($p, 2000);
t_eq('a 2000 payment leaves 3000', party_balance($p), 3000);
t_payment($p, 3000, 'in', 'discount');
t_eq('a discount row settles the ledger like cash', party_balance($p), 0);

$p2 = t_party(null, 1500); // opening balance
t_eq('opening balance counts as receivable', party_balance($p2), 1500);
t_sale($p2, 500);
t_eq('opening + new bill add up', party_balance($p2), 2000);

t_group('opening_due() — the old pre-software ledger');
t_eq('full opening balance is due initially', opening_due($p2, 'in'), 1500);
t_payment($p2, 600, 'in', 'cash', [['opening', $p2, 600]]);
t_eq('after a 600 allocation, 900 of the opening remains', opening_due($p2, 'in'), 900);
t_eq('a receivable opening owes nothing on the payable side', opening_due($p2, 'out'), 0);
$p3 = t_party(null, -800); // supplier opening (we owe them)
t_eq('negative opening shows on the payable side', opening_due($p3, 'out'), 800);
t_eq('…and nothing on the receivable side', opening_due($p3, 'in'), 0);

t_group('sale_true_due() — never claim more than the ledger says');
$p4 = t_party();
$b1 = t_sale($p4, 12411, 0, '2026-08-02');
$row1 = row('SELECT * FROM sales WHERE id = ?', [$b1]);
t_eq('with nothing paid, true due = bill due', sale_true_due($row1), 12411);

t_payment($p4, 10411); // paid into the ledger but NOT linked to the bill
$row1 = row('SELECT * FROM sales WHERE id = ?', [$b1]);
t_eq('an unlinked payment reduces the claim to 2000', sale_true_due($row1), 2000);

t_payment($p4, 2000); // now fully covered
$row1 = row('SELECT * FROM sales WHERE id = ?', [$b1]);
t_eq('a fully covered bill claims nothing', sale_true_due($row1), 0);

// two bills, oldest absorbs the covered part first
$p5 = t_party();
$old = t_sale($p5, 2800, 0, '2026-07-01');
$new = t_sale($p5, 2041, 0, '2026-07-20');
t_payment($p5, 2800);
$rOld = row('SELECT * FROM sales WHERE id = ?', [$old]);
$rNew = row('SELECT * FROM sales WHERE id = ?', [$new]);
t_eq('the OLDEST bill absorbs the unlinked money', sale_true_due($rOld), 0);
t_eq('the newer bill still claims its full amount', sale_true_due($rNew), 2041);
t_eq('the two never exceed the real balance', sale_true_due($rOld) + sale_true_due($rNew), party_balance($p5));

// a walk-in bill (no party) has no ledger to cap against
$co = (int)val('SELECT id FROM companies ORDER BY id LIMIT 1');
$loc = (int)val('SELECT id FROM locations ORDER BY id LIMIT 1');
q("INSERT INTO sales (company_id, location_id, party_id, customer_name, invoice_no, sale_date, subtotal, total, paid, payment_mode, status, created_by)
   VALUES (?,?,NULL,'Walk-in',?,?,900,900,300,'credit','partial',1)", [$co, $loc, 'W-' . bin2hex(random_bytes(3)), today()]);
$w = row('SELECT * FROM sales WHERE id = ?', [insert_id()]);
t_eq('walk-in bill falls back to total minus paid', sale_true_due($w), 600);

t_group('sales returns and discounts also cap the claim');
$p6 = t_party();
$b6 = t_sale($p6, 500);
q('INSERT INTO sales_returns (party_id, location_id, total, return_date, created_by) VALUES (?,?,?,?,1)',
  [$p6, $loc, 200, today()]);
$r6 = row('SELECT * FROM sales WHERE id = ?', [$b6]);
t_eq('a 200 sales return leaves 300 claimable', sale_true_due($r6), 300);
