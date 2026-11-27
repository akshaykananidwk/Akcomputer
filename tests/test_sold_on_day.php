<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); die('CLI only'); }
// ============================================================================
//  SOLD ON THE DAY (27-11-2026): a bill dated the 1st, and on the 10th one
//  more piece the customer took is added to that same bill - that piece is
//  the 10th's sale and profit, not the 1st's.
// ============================================================================
require_once __DIR__ . '/../includes/sale_save.php';
require_once __DIR__ . '/../includes/sold_on_day.php';
t_group('Sold on the day an item was put on a bill');
$u = row('SELECT u.*, l.name location_name FROM users u JOIN locations l ON l.id = u.location_id ORDER BY u.id LIMIT 1'); $u['perms'] = ['*'];
$loc = (int)$u['location_id'];
$co = (int)val('SELECT id FROM companies ORDER BY id LIMIT 1');
q("INSERT INTO items (name, selling_price, purchase_price, tax_rate, is_active, item_type) VALUES ('SOD mouse', 500, 300, 0, 1, 'product')");
$it = (int)val('SELECT MAX(id) FROM items');
adjust_stock($it, $loc, 20, 'opening', null, 't');
$D1 = '2026-09-01'; $D10 = '2026-09-10';
$_POST = ['company_id' => $co, 'location_id' => $loc, 'sale_date' => $D1, 'payment_mode' => 'cash', 'paid' => 0, 'customer_name' => 'SOD test',
          'row_n' => [1], 'item_id' => [$it], 'qty' => [2], 'price' => [500], 'tax_rate' => [0]];
$sid = (int)sale_save_form($u)['id']; $_POST = [];
q("UPDATE sales SET created_at = '$D1 11:00:00' WHERE id = ?", [$sid]);
q("UPDATE stock_ledger SET created_at = '$D1 11:00:00' WHERE ref_type = 'sale' AND ref_id = ?", [$sid]);
// the 10th: the bill is edited from 2 to 3 pieces - exactly what sales.php does
// (old lines back, new lines out, same moment)
adjust_stock($it, $loc, 2, 'sale_edit', $sid, 'edit');
adjust_stock($it, $loc, -3, 'sale_edit', $sid, 'edit');
q("UPDATE stock_ledger SET created_at = '$D10 16:00:00' WHERE ref_type = 'sale_edit' AND ref_id = ?", [$sid]);
q('UPDATE sale_items SET qty = 3, total = 1500 WHERE sale_id = ?', [$sid]);
list($ew, $ep) = report_extra_where('s', 0, 0, '', 0);
$day = function ($d) use ($ew, $ep, $sid) {
    $r = array_values(array_filter(sold_on_day_rows($d, $d, $ew, $ep), fn($x) => $x['sale_id'] === $sid));
    return [round(array_sum(array_column($r, 'units')), 2), round(array_sum(array_column($r, 'rev')), 2), round(array_sum(array_column($r, 'rev')) - array_sum(array_column($r, 'cost')), 2)];
};
t_eq('the 1st: the 2 pieces billed that day, ₹1,000, profit ₹400', $day($D1), [2.0, 1000.0, 400.0]);
t_eq('the 10th: the 1 piece added that day to the old bill, ₹500, profit ₹200', $day($D10), [1.0, 500.0, 200.0]);
t_eq('a day in between shows nothing from this bill', $day('2026-09-05'), [0.0, 0.0, 0.0]);
$all = array_values(array_filter(sold_on_day_rows($D1, $D10, $ew, $ep), fn($x) => $x['sale_id'] === $sid));
$tot = sold_on_day_totals($all);
t_eq('over the whole period it adds up to the bill: 3 pieces, ₹1,500', [round(array_sum(array_column($all, 'units')), 2), round(array_sum(array_column($all, 'rev')), 2)], [3.0, 1500.0]);
t_eq('the 10th\'s ₹500 is marked as put on an older bill', round($tot[$D10]['late'], 2), 500.0);
q('UPDATE sales SET discount = 150 WHERE id = ?', [$sid]);
t_eq('a ₹150 bill discount is shared by value: the 10th carries ₹50 of it', $day($D10)[1], 450.0);
q('UPDATE sales SET is_cancelled = 1 WHERE id = ?', [$sid]);
t_eq('a cancelled bill is not counted', $day($D10), [0.0, 0.0, 0.0]);
