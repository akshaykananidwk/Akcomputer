<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); die('CLI only'); }
// ============================================================================
//  SERIALS ARE OPTIONAL ON A BILL AND A HANDOVER (27-11-2026: "Error: Select 6
//  serial(s) for HP KM260 ..." should not stop the shop). All, some or none
//  of the serials may be picked; only more serials than pieces is refused.
// ============================================================================
require_once __DIR__ . '/../includes/sale_save.php';
t_group('Serial numbers: pick all, some or none');
$u = row('SELECT u.*, l.name location_name FROM users u JOIN locations l ON l.id = u.location_id ORDER BY u.id LIMIT 1'); $u['perms'] = ['*'];
$loc = (int)$u['location_id'];
$co = (int)val('SELECT id FROM companies ORDER BY id LIMIT 1');
q("INSERT INTO items (name, selling_price, purchase_price, tax_rate, is_active, item_type, serial_tracked) VALUES ('SO HP KM260 combo', 965, 743, 0, 1, 'product', 1)");
$it = (int)val('SELECT MAX(id) FROM items');
adjust_stock($it, $loc, 12, 'opening', null, 't');
for ($i = 1; $i <= 12; $i++) q("INSERT INTO item_serials (item_id, serial_no, status, location_id) VALUES (?, ?, 'in_stock', ?)", [$it, "SO-KM-$i", $loc]);
$sell = function ($qty, array $sns) use ($u, $co, $loc, $it) {
    $_POST = ['company_id' => $co, 'location_id' => $loc, 'sale_date' => today(), 'payment_mode' => 'cash', 'paid' => 0, 'customer_name' => 'SO test',
              'row_n' => [1], 'item_id' => [$it], 'qty' => [$qty], 'price' => [965], 'tax_rate' => [0], 'serial_sel' => [1 => $sns]];
    try { $r = sale_save_form($u); $_POST = []; return $r['id']; } catch (Exception $e) { $_POST = []; return $e->getMessage(); }
};
$s1 = $sell(6, ['SO-KM-1', 'SO-KM-2', 'SO-KM-3']);
t_ok('6 pieces with only 3 serials picked: the bill saves', is_int($s1));
t_eq('...the 3 picked serials are sold on it', (int)val("SELECT COUNT(*) FROM item_serials WHERE item_id = ? AND status = 'sold'", [$it]), 3);
t_eq('...and all 6 pieces left the stock', stock_qty($it, $loc), 6.0);
$s2 = $sell(2, []);
t_ok('2 pieces with no serial picked: the bill saves', is_int($s2));
$s3 = $sell(2, ['SO-KM-4', 'SO-KM-5', 'SO-KM-6']);
t_eq('3 serials picked with qty 2: three pieces were scanned, so the bill is for 3', is_int($s3) ? (float)val('SELECT qty FROM sale_items WHERE sale_id = ?', [$s3]) : $s3, 3.0);
t_eq('6 typed with 3 serials stays a bill for 6 pieces (never silently fewer)', (float)val('SELECT qty FROM sale_items WHERE sale_id = ?', [$s1]), 6.0);
$h = file_get_contents(__DIR__ . '/../handover.php');
t_ok('a handover no longer demands every serial', strpos($h, "count(\$serials) != \$r['qty']") === false && strpos($h, "count(\$serials) > \$r['qty']") !== false);
t_ok('editing a bill follows the same rule', strpos(file_get_contents(__DIR__ . '/../sales.php'), "count(\$serials) > \$r['qty']") !== false);
