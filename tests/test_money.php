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

// ---------------------------------------------------------------------------
// Party-wise margin contribution — "which customer actually made me money this
// year", so the owner knows who to look after at Diwali.
// ---------------------------------------------------------------------------

t_group('party profit is revenue minus the item cost, per customer');
$ppLoc = (int)val('SELECT id FROM locations ORDER BY id LIMIT 1');
$ppCo  = (int)val('SELECT id FROM companies ORDER BY id LIMIT 1');
$ppFrom = date('Y-m-d', strtotime('-30 days'));
$ppTo = today();

/** A bill with one line: qty x price, at a known cost. */
function tpp_bill($partyId, $itemId, $qty, $price, $cost, $billDisc = 0, $date = null) {
    global $ppCo, $ppLoc;
    $net = $qty * $price;
    q("INSERT INTO sales (company_id, location_id, party_id, customer_name, invoice_no, sale_date,
        subtotal, discount, loyalty_discount, total, paid, payment_mode, status, created_by)
       VALUES (?,?,?, 'TPP', ?, ?, ?, ?, 0, ?, ?, 'cash', 'paid', 1)",
      [$ppCo, $ppLoc, $partyId, 'TPP-' . bin2hex(random_bytes(4)), $date ?: today(),
       $net, $billDisc, $net - $billDisc, $net - $billDisc]);
    $sid = insert_id();
    q('INSERT INTO sale_items (sale_id, item_id, qty, price, cost_price, total) VALUES (?,?,?,?,?,?)',
      [$sid, $itemId, $qty, $price, $cost, $net]);
    return $sid;
}

$ppItem = t_item(0, $ppLoc, 1000);
q('UPDATE items SET purchase_price = 600 WHERE id = ?', [$ppItem]);

// A: 10 x 1000 = 10,000 revenue, cost 6,000 -> 4,000 contribution
$pA = t_party('TPP Alpha');
tpp_bill($pA, $ppItem, 10, 1000, 600);
// B: same sale but 1,000 given away on the bill -> 3,000 contribution
$pB = t_party('TPP Beta');
tpp_bill($pB, $ppItem, 10, 1000, 600, 1000);

$pp = party_profit($ppFrom, $ppTo, '', [], 5000);
$byId = [];
foreach ($pp as $x) $byId[(int)$x['party_id']] = $x;

t_ok('the first customer appears', isset($byId[$pA]));
t_eq('their revenue is the line total', $byId[$pA]['revenue'], 10000);
t_eq('their cost is qty x the cost captured on the bill', $byId[$pA]['cost'], 6000);
t_eq('gross margin is the difference', $byId[$pA]['gross'], 4000);
t_eq('with nothing given away, contribution equals gross', $byId[$pA]['contribution'], 4000);
t_eq('margin percent is contribution over revenue', $byId[$pA]['pct'], 40.0);
t_eq('one bill counted once', $byId[$pA]['bills'], 1);

t_ok('the second customer appears too', isset($byId[$pB]));
t_eq('their bill-level discount is picked up', $byId[$pB]['discount'], 1000);
t_eq('...and comes off the contribution', $byId[$pB]['contribution'], 3000);
t_ok('...while gross margin still reads the same', $byId[$pB]['gross'] == $byId[$pA]['gross']);
// The discount is a column of its own, never folded into revenue - otherwise
// this report would silently disagree with the product-wise one.
t_eq('revenue is NOT reduced by the bill discount', $byId[$pB]['revenue'], 10000);

t_group('a bill discount is counted once, not once per line');
$pC = t_party('TPP Gamma');
$sid = tpp_bill($pC, $ppItem, 5, 1000, 600, 500);
q('INSERT INTO sale_items (sale_id, item_id, qty, price, cost_price, total) VALUES (?,?,?,?,?,?)',
  [$sid, $ppItem, 5, 1000, 600, 5000]);   // a second line on the SAME bill
q('UPDATE sales SET subtotal = 10000, total = 9500, paid = 9500 WHERE id = ?', [$sid]);
$pp2 = party_profit($ppFrom, $ppTo, '', [], 5000);
$rowC = null;
foreach ($pp2 as $x) if ((int)$x['party_id'] === $pC) $rowC = $x;
t_ok('the two-line bill is found', $rowC !== null);
t_eq('both lines are in the revenue', $rowC['revenue'], 10000);
t_eq('the discount is still 500, not 1000', $rowC['discount'], 500);
t_eq('so the contribution is 10000 - 6000 - 500', $rowC['contribution'], 3500);
t_eq('and it is one bill, not two', $rowC['bills'], 1);

t_group('the ranking puts the best customer on top');
$best = $pp2[0];
$worst = end($pp2);
t_ok('the list is sorted by contribution', $best['contribution'] >= $worst['contribution']);

t_group('a sale outside the window is not counted');
$pD = t_party('TPP Delta');
tpp_bill($pD, $ppItem, 10, 1000, 600, 0, date('Y-m-d', strtotime('-200 days')));
$pp3 = party_profit($ppFrom, $ppTo, '', [], 5000);
$foundD = false;
foreach ($pp3 as $x) if ((int)$x['party_id'] === $pD) $foundD = true;
t_ok('an old bill does not appear in a 30-day window', !$foundD);

t_group('the gift budget is a share of what they actually contributed');
set_setting('gift_pct', '2');
set_setting('gift_cap', '0');
t_eq('two percent of 4000', party_gift_budget(4000), 80);
t_eq('...and of 100000', party_gift_budget(100000), 2000);
// A gift is a thank-you, not an apology.
t_eq('a customer who made no money gets no gift', party_gift_budget(0), 0);
t_eq('...nor does one who lost the shop money', party_gift_budget(-5000), 0);
set_setting('gift_cap', '1000');
t_eq('the cap holds it down', party_gift_budget(100000), 1000);
t_eq('...but does not raise a small one', party_gift_budget(4000), 80);
set_setting('gift_cap', '0');
set_setting('gift_pct', '0');
t_eq('zero percent turns the suggestion off', party_gift_budget(100000), 0);
set_setting('gift_pct', '2');

t_group('the report says what it is, and what it is not');
$rb = file_get_contents(dirname(__DIR__) . '/includes/report_body.php');
t_ok('it is called contribution, not profit', strpos($rb, 'માર્જિન યોગદાન') !== false);
t_ok('...and says outright that shop costs are not deducted',
     strpos($rb, 'ભાડું, પગાર, લાઇટબિલ જેવા દુકાનના ખર્ચ આમાં બાદ થયા નથી') !== false);
t_ok('the report is behind reports.profit', strpos($rb, "\$r === 'party_profit' && can('reports.profit')") !== false);
$rep = file_get_contents(dirname(__DIR__) . '/reports.php');
t_ok('...and the tab is hidden without it', strpos($rep, "unset(\$tabs['party_profit'])") !== false);
$mn = file_get_contents(dirname(__DIR__) . '/includes/money.php');
t_ok('cost uses the one shared definition', preg_match('/function party_profit.*?profit_cost_sql\(\)/s', $mn) === 1);

// ---------------------------------------------------------------------------
// દિવસનું ક્લોઝિંગ - the night's cash count.
t_group('one cash rule, whether for today or for a night last week');

// the date-bounded version must agree exactly with the live figure when no
// date is given - they are the same formula and must not be allowed to drift
$cashNow = (float)val("SELECT
    COALESCE((SELECT SUM(amount) FROM payments WHERE mode='cash' AND direction='in'),0)
  - COALESCE((SELECT SUM(amount) FROM payments WHERE mode='cash' AND direction='out'),0)
  - COALESCE((SELECT SUM(amount) FROM expenses WHERE mode='cash'),0)
  - COALESCE((SELECT SUM(amount) FROM money_transfers WHERE status='done' AND txn_type='cash_to_bank'),0)
  + COALESCE((SELECT SUM(amount) FROM money_transfers WHERE status='done' AND txn_type='bank_to_cash'),0)
  + COALESCE((SELECT SUM(amount) FROM money_transfers WHERE status='done' AND txn_type='cash_adjust' AND adjust_dir='add'),0)
  - COALESCE((SELECT SUM(amount) FROM money_transfers WHERE status='done' AND txn_type='cash_adjust' AND adjust_dir='reduce'),0)");
t_eq('the open-ended figure is the old formula, to the paisa',
     money_r(cash_in_hand_upto(null)), money_r($cashNow));
t_eq('...and total_cash_in_hand() is that same function',
     money_r(total_cash_in_hand()), money_r(cash_in_hand_upto(null)));

$dcParty = t_party('DAYCLOSE_TEST');
$dcToday = today();
$dcYest  = date('Y-m-d', strtotime('-1 day'));
$dcTomorrow = date('Y-m-d', strtotime('+1 day'));
$beforeToday = money_r(cash_in_hand_upto($dcYest));
// other fixtures in this suite have already put cash through today, so the
// day's figures are compared as a DELTA, not as absolutes
$f0 = day_close_figures($dcToday);

t_payment($dcParty, 4000, 'in', 'cash');
q("UPDATE payments SET pay_date = ? WHERE id = ?", [$dcToday, (int)val('SELECT MAX(id) FROM payments')]);
t_payment($dcParty, 1500, 'out', 'cash');
q("UPDATE payments SET pay_date = ? WHERE id = ?", [$dcToday, (int)val('SELECT MAX(id) FROM payments')]);
q("INSERT INTO expenses (exp_date, category, amount, mode, location_id, created_by) VALUES (?, 'Tea & Water', 300, 'cash', ?, 1)",
  [$dcToday, (int)val('SELECT id FROM locations ORDER BY id LIMIT 1')]);

$f = day_close_figures($dcToday);
t_eq('the cash that came in today', money_r($f['in'] - $f0['in']), 4000.0);
t_eq('the cash that went out today', money_r($f['out'] - $f0['out']), 1500.0);
t_eq('the cash spent today', money_r($f['expense'] - $f0['expense']), 300.0);
// the whole point: the day has to ADD UP to the closing figure
t_eq('opening + what moved = what should be in the drawer',
     money_r($f['opening'] + $f['moved']), money_r($f['expected']));
t_eq('...and that closing figure is the live cash figure',
     money_r($f['expected']), money_r(total_cash_in_hand()));
t_eq('...with yesterday\'s close as this morning\'s opening',
     money_r($f['opening']), $beforeToday);

// a cheque-dated-forward entry must not be counted as money in the drawer
t_payment($dcParty, 9999, 'in', 'cash');
q("UPDATE payments SET pay_date = ? WHERE id = ?", [$dcTomorrow, (int)val('SELECT MAX(id) FROM payments')]);
$f2 = day_close_figures($dcToday);
t_eq('money dated tomorrow is not in tonight\'s drawer', money_r($f2['expected']), money_r($f['expected']));
t_ok('...though it is in the live all-time figure',
     money_r(total_cash_in_hand()) > money_r($f2['expected']));

t_group('the counting slip');
t_eq('the notes counted are read back', cash_denom_parse('500x4,100x7'), [500 => 4, 100 => 7]);
t_eq('rubbish in the slip is ignored, not guessed', cash_denom_parse('500x4,rubbish,x,200x0'), [500 => 4]);
t_eq('an empty slip is simply empty', cash_denom_parse(''), []);
$dcSrc = file_get_contents(dirname(__DIR__) . '/day_close.php');
// a total typed into a hidden field is a claim about the count, not the count
t_ok('the counted total is added up on the server, from the note boxes',
     strpos($dcSrc, "\$counted += \$v * \$n;") !== false);
t_ok('the difference is worked out against the shared rule, not a posted figure',
     strpos($dcSrc, 'day_close_figures($date)') !== false);
t_ok('a gap is never written into the books by itself',
     strpos($dcSrc, "if (post('post_adjust') && abs(\$diff) > 0.009)") !== false);
t_ok('...and when it is, it goes through the one cash-correction path',
     strpos($dcSrc, "txn_type, amount, adjust_dir") !== false && strpos($dcSrc, "'cash_adjust'") !== false);
t_ok('a future date cannot be closed', strpos($dcSrc, "if (\$date > today())") !== false);
t_ok('a locked period cannot be closed', strpos($dcSrc, 'is_period_locked($date)') !== false);

// ---------------------------------------------------------------------------
// Found while running Migrate: it had been reporting "1 failed" for a long
// time. v3 re-set item_serials.status to a list that did not include
// 'adjusted_out', which v28 had added - and migrations re-run in order every
// single time. Narrowing an ENUM does not fail, it TRUNCATES, so on a server
// that does not report it every adjusted-out serial was left holding the
// empty string: in no status at all, offered by no screen, explained by
// nothing.
t_group('a migration can never narrow away a status the code writes');
$serialStatuses = ['in_stock', 'with_staff', 'sold', 'claim', 'returned_supplier', 'replaced', 'adjusted_out'];
foreach (glob(dirname(__DIR__) . '/install/*.sql') as $sqlFile) {
    $sql = file_get_contents($sqlFile);
    if (!preg_match_all('/item_serials[^;]*?status ENUM\(([^)]*)\)/i', $sql, $m)) continue;
    foreach ($m[1] as $list) {
        $missing = [];
        foreach ($serialStatuses as $st) if (strpos($list, "'" . $st . "'") === false) $missing[] = $st;
        t_eq(basename($sqlFile) . ' keeps every serial status the code writes', implode(', ', $missing), '');
    }
}
// and the rows that already lost it are put back
$v67 = file_get_contents(dirname(__DIR__) . '/install/upgrade_v67.sql');
t_ok('migration v67 restores the wiped statuses',
     strpos($v67, "UPDATE item_serials SET status = 'adjusted_out' WHERE status = ''") !== false);
t_eq('no serial is left in no status at all',
     (int)val("SELECT COUNT(*) FROM item_serials WHERE status = ''"), 0);
