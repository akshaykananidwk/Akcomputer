<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); die('CLI only'); }
// The kind of business a shop is. What must hold: a shop that switched
// nothing on (the owner's computer shop) sees nothing new, and the small
// rules each kind adds - IMEI check, price breaks, sizes and colours - give
// the same answer every time.

$bizKeep = [];
foreach (['biz_box_units', 'biz_slabs', 'biz_variants', 'biz_no_stock', 'biz_serial_label', 'biz_appointments'] as $k) $bizKeep[$k] = setting($k, '');

t_group('Business: nothing switched on = nothing changes');
foreach (['biz_box_units', 'biz_slabs', 'biz_variants', 'biz_no_stock', 'biz_appointments'] as $k) set_setting($k, '0');
set_setting('biz_serial_label', '');
$cfg = biz_billing_cfg();
t_ok('the bill screen gets no weight / box / price-break tools', !$cfg['weight'] && !$cfg['boxes'] && !$cfg['slabs'] && !isset($cfg['jewel']) && !isset($cfg['staff']));
t_eq('a unit\'s number is still called Serial No', biz_serial_label(), 'Serial No');
t_eq('any serial is accepted', biz_bad_imeis(['ABC123']), []);
t_ok('stock screens are open', plan_allows('stock'));

t_group('Business: mobile shop IMEI check');
t_ok('a real IMEI passes', imei_valid('490154203237518'));
t_ok('...with spaces too', imei_valid('49015 42032 37518'));
t_ok('one wrong digit fails', !imei_valid('490154203237519'));
t_ok('14 digits fail', !imei_valid('49015420323751'));
set_setting('biz_serial_label', 'IMEI');
t_eq('only the bad ones are named', biz_bad_imeis(['490154203237518', '123456789012345']), ['123456789012345']);
set_setting('biz_serial_label', '');

t_group('Business: wholesale price breaks');
$sl = slabs_parse("10:95, 50:90, junk, 5=99, 10:94");
t_eq('parsed in quantity order, last one wins for the same quantity', $sl, [[5.0, 99.0], [10.0, 94.0], [50.0, 90.0]]);
t_eq('below the first break: the item price', slab_price(100, $sl, 4), 100);
t_eq('at a break: its price', slab_price(100, $sl, 10), 94);
t_eq('above the top break: the top price', slab_price(100, $sl, 75), 90);
t_eq('nonsense gives no breaks', slabs_parse('abc, :5, 10:'), []);

t_group('Business: garment sizes and colours');
t_eq('sizes × colours', variant_names('S, M', 'Red,Blue'), ['S / Red', 'S / Blue', 'M / Red', 'M / Blue']);
t_eq('sizes only', variant_names('S,M,M', ''), ['S', 'M']);
t_eq('nothing', variant_names('', ''), []);

t_group('Business: the item form saves box, breaks and variants');
$iid = t_item(0, null, 100);
set_setting('biz_box_units', '1'); set_setting('biz_slabs', '1'); set_setting('biz_variants', '1');
$_POST = ['box_qty' => '12', 'slabs' => '10:95, 50:90', 'variant_sizes' => 'S,M', 'variant_colours' => 'Red'];
t_eq('two variants made', biz_item_save_extras($iid), 2);
t_eq('box size kept', (float)val('SELECT box_qty FROM items WHERE id = ?', [$iid]), 12);
t_eq('the bill screen gets box and breaks', biz_item_extras([$iid])[$iid] ?? null, ['box' => 12.0, 'slabs' => [[10.0, 95.0], [50.0, 90.0]]]);
$kids = all('SELECT name, variant, selling_price FROM items WHERE parent_id = ? ORDER BY id', [$iid]);
t_eq('variants are named after the item', array_column($kids, 'variant'), ['S / Red', 'M / Red']);
t_ok('...and carry its price', (float)$kids[0]['selling_price'] === (float)val('SELECT selling_price FROM items WHERE id = ?', [$iid]));
t_eq('saving again makes no copies', biz_item_save_extras($iid), 0);
$_POST = ['box_qty' => '0', 'slabs' => ''];
biz_item_save_extras($iid);
t_eq('emptying the breaks removes them', (int)val('SELECT COUNT(*) FROM item_slabs WHERE item_id = ?', [$iid]), 0);
$_POST = [];

t_group('Business: a services-only shop has no stock screens');
set_setting('biz_no_stock', '1');
t_ok('stock is closed', !plan_allows('stock'));
t_ok('billing is still open', plan_allows('billing'));
set_setting('biz_no_stock', '0');

t_group('Business: a parked bill keeps who did the service');
$_POST = ['item_id' => [$iid], 'qty' => [1], 'price' => [100], 'line_staff' => [7]];
t_eq('staff kept on the parked line', sale_park_payload()['items'][0]['staff_id'] ?? null, 7);
$_POST = [];

foreach ($bizKeep as $k => $v) set_setting($k, $v);
