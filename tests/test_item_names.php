<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); die('CLI only'); }
// ============================================================================
//  CLEAR PRODUCT NAMES (28-11-2026): names a customer and the shop both
//  understand, ending with the model number in brackets. Real names from the
//  shop's Items list. Prices, stock and services are not touched; Undo.
// ============================================================================
require_once __DIR__ . '/../includes/item_names.php';
t_group('Clear names: real names from the Items list');
$c = fn($n, $model = '', $brand = '') => in_clean(['name' => $n, 'model' => $model, 'brand' => $brand])[0];
t_eq('spelling, one brand spelling, capitals made readable', [$c('Lan Cabel Cat6'), $c(' CP-Plus 8ch 2MP 1 SATA H.265 DVR (0801E1V-I)'), $c('TP-LINK OMADA EAP225 OUTDOOR ACCESS POINT AC1200')],
     ['Cat6 LAN Cable (per Meter)', 'CP PLUS 8ch 2MP 1 SATA H.265 DVR (0801E1V-I)', 'TP-Link Omada EAP225-Outdoor AC1200 Outdoor Access Point (EAP225-Outdoor)']);
t_eq('acronyms stay in capitals (CCTV, SMPS, DC), the model code exactly as the maker writes it', $c('VOLTAIC Bright Series 4CH CCTV SMPS Power Supply (DC 12V, Single Output) (VC-BS-4)'),
     'Voltaic Bright Series 4CH CCTV SMPS Power Supply (DC 12V, Single Output) (VC-BS-4)');
t_eq('a model code inside the name goes in brackets at the end', [$c('Epson EcoTank L3216 All-in-One Ink Tank Printer (Old)'), $c('MAIX H61 Desktop Motherboard (Intel LGA1155, DDR3)')],
     ['Epson EcoTank L3216 All-in-One Ink Tank Printer (Old) (L3216)', 'MAIX H61 Desktop Motherboard (Intel LGA1155, DDR3) (H61)']);
t_eq('sizes and standards are not taken for a model ("3-Port", "19-Inch", "802.3af/at", "500GB")', [$c('4K Ultra HD 3-Port HDMI Switch (3 Input 1 Output, Remote Control)'), $c('METRIX 19-Inch LED Monitor (HD Display, Desktop Computer Monitor)'), $c('DAICHI Surveillance Hard Disk 2TB SATA 3.5 Inch')],
     ['4K Ultra HD 3-Port HDMI Switch (3 Input 1 Output, Remote Control)', 'Metrix 19-Inch LED Monitor (HD Display, Desktop Computer Monitor)', 'DAICHI Surveillance Hard Disk 2TB SATA 3.5 Inch']);
t_eq('the item\'s own model number is added when the name lacks it', $c('Zebion mouse', 'ZB-M100'), 'Zebion USB Wired Mouse (ZB-M100)');
t_eq('a broken pasted name is written out; a stray bracket and invisible characters go', [$c("SECURUS PEOPLE COUNTING 1.000 5850.00 526.50 6903.00\u{FEFF} CAM SS-NC20L2CP-YXF-M8 \u{FEFF} (4K) 6MM"), $c(' iVOOMi OPAQUE Wireless Optical Mouse (2.4GHz, USB Nano Receiver, White) (IV-WM02))')],
     ['Securus 4K People Counting IP Camera 6mm (SS-NC20L2CP-YXF-M8)', 'iVOOMi Opaque Wireless Optical Mouse (2.4GHz, USB Nano Receiver, White) (IV-WM02)']);
t_eq('a nested model code stays whole', $c('SECURUS 4CH H.265 Digital Video Recorder with AI Human & Face Detection (HDMI/VGA 4K Output) (SS-8041-TPHD-M1(SF))'),
     'Securus 4CH H.265 Digital Video Recorder with AI Human & Face Detection (HDMI/VGA 4K Output) (SS-8041-TPHD-M1(SF))');
t_eq('the brand column is written one way too', in_clean(['name' => 'CP-Plus 8ch DVR', 'brand' => 'Cp-plus', 'model' => ''])[1], 'CP PLUS');
t_eq('a good name is left alone', $c('Hi-Focus 4+2 PoE Switch (HF-S04E2-65WH)'), 'Hi-Focus 4+2 PoE Switch (HF-S04E2-65WH)');

t_group('Clear names: Apply / Undo, prices and services untouched');
q("INSERT INTO items (name, brand, selling_price, purchase_price, tax_rate, is_active, item_type) VALUES ('IN Lan Cabel Cat6', 'Katvision', 14.08, 10.83, 18, 1, 'product')");
$a = (int)val('SELECT MAX(id) FROM items');
q("INSERT INTO items (name, selling_price, purchase_price, tax_rate, is_active, item_type) VALUES ('IN camera fiting charge', 500, 150, 18, 1, 'service')");
$sv = (int)val('SELECT MAX(id) FROM items');
in_apply();
t_eq('Apply: the name is clear, the price exactly as it was', [val('SELECT name FROM items WHERE id = ?', [$a]), (float)val('SELECT selling_price FROM items WHERE id = ?', [$a])], ['IN LAN Cable Cat6', 14.08]);
t_eq('a service is never renamed', val('SELECT name FROM items WHERE id = ?', [$sv]), 'IN camera fiting charge');
t_ok('a model typed on the page is saved and added to the name', in_set_model($a, 'KV-CAT6') && val('SELECT name FROM items WHERE id = ?', [$a]) === 'IN LAN Cable Cat6 (KV-CAT6)' && val('SELECT model FROM items WHERE id = ?', [$a]) === 'KV-CAT6');
in_undo();
t_eq('Undo puts the name from before Apply back (the typed model stays on the item)', [val('SELECT name FROM items WHERE id = ?', [$a]), val('SELECT model FROM items WHERE id = ?', [$a])], ['IN Lan Cabel Cat6', 'KV-CAT6']);
