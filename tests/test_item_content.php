<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); die('CLI only'); }
// ============================================================================
//  PRODUCT CONTENT (28-11-2026): every product gets a description, its
//  features (megapixels, audio, night vision, RAM, power ...) and the words
//  customers search with - all read from the shop's own product names, the
//  real list from the Items page. Nothing invented; the owner's own
//  descriptions are never replaced; Apply can be undone.
// ============================================================================
require_once __DIR__ . '/../includes/item_content.php';
require_once __DIR__ . '/../includes/category_plan.php';
t_group('Product content: facts read from the name');
$fact = function ($name, $label) { foreach (ic_facts(['name' => $name]) as $f) if ($f[0] === $label) return $f[1]; return null; };
$cam = 'CP PLUS 2.4MP Dual Light Bullet CCTV Camera (3.6mm Lens, 30M IR, 20M Warm Light, Built-in Mic, IP67) (CP-URC-TC24PL3C-L-V2-0360)';
t_eq('camera: megapixels, type, night vision, audio, lens, IR, weatherproof', [$fact($cam, 'Resolution'), $fact($cam, 'Camera type'), $fact($cam, 'Night vision'), $fact($cam, 'Audio'), $fact($cam, 'Lens'), $fact($cam, 'IR range'), $fact($cam, 'Weatherproof')],
     ['2.4MP', 'Bullet', 'Dual light (colour + IR)', 'Built-in microphone (records sound)', '3.6mm', '30 m', 'IP67 (outdoor)']);
$pt = 'Coreprix 4MP 4G Dual Lens Starlight PT CCTV Camera (Pan & Tilt, Two-Way Audio, Human Detection) (CPG-4M PTSL-DL)';
t_eq('4G camera: two-way audio, colour night, pan & tilt', [ic_kind(['name' => $pt]), $fact($pt, 'Audio'), $fact($pt, 'Night vision'), $fact($pt, 'Camera type')], ['cam_4g', 'Two-way audio (talk & listen)', 'Colour night vision', 'Pan & Tilt (PT)']);
$ram = 'Micron 2GB DDR3L 1600MHz Laptop RAM (PC3L-12800S, 1Rx16) (MT4KTF25664HZ-1G6E2)';
t_eq('RAM: size, type, speed, laptop — a model code is not a "Hz"', [$fact($ram, 'Memory'), $fact($ram, 'RAM type'), $fact($ram, 'Speed'), $fact($ram, 'For'), $fact($ram, 'Refresh rate')], ['2GB', 'DDR3L', '1600 MHz', 'Laptop', null]);
t_eq('a toner "12A" or a code "W1660A" is never read as amperes', [$fact('12A Black LaserJet Toner Cartridge', 'Current'), $fact('Compatible H-W1660A Black Laser Toner Cartridge (1,500 Pages Yield)', 'Current')], [null, null]);
t_eq('"7.1 Channel" sound card is not "1 channel"', $fact('ABC External USB 7.1 Channel Sound Card Adapter (3D Audio, Plug & Play) (CNC-149)', 'Channels'), null);
t_eq('adapter: volts, amps, input', [$fact('9V 0.6A DC Power Adapter (100-240V AC Input)', 'Voltage'), $fact('9V 0.6A DC Power Adapter (100-240V AC Input)', 'Current'), $fact('9V 0.6A DC Power Adapter (100-240V AC Input)', 'Input')], ['9 V', '0.6 A', '100-240V AC']);
t_eq('a surveillance disk "for DVR/NVR" is a disk, not a recorder', ic_kind(['name' => 'DAICHI 500GB Surveillance Hard Disk Drive (SATA, 3.5 Inch, CCTV DVR/NVR)']), 'surv_hdd');
t_eq('a plain name gives no invented features', ic_facts(['name' => 'BNC PIN']), []);

t_group('Product content: description and search words');
$d = ic_description(['name' => $cam, 'brand' => 'CP Plus', 'model' => '', 'warranty_months' => 12]);
t_ok('the description has features, uses, how it works and what else is needed', strpos($d, 'Key features:') && strpos($d, 'Where it is used:') && strpos($d, 'How it works:') && strpos($d, 'You may also need:') && strpos($d, 'HD DVR'));
t_ok('...and says only what the name says (no "4K", no "WiFi" for this camera)', stripos($d, '4K') === false && stripos($d, 'wifi') === false);
$kw = ic_keywords(['name' => $cam, 'brand' => 'CP Plus', 'model' => 'CP-URC-TC24PL3C', 'cat_name' => 'HD Camera']);
t_ok('100+ search words, all in English: model, brand + specs, price, towns', count($kw) >= 100 && in_array('cp-urc-tc24pl3c', $kw, true) && in_array('cp plus 2.4mp cctv camera', $kw, true)
     && !preg_grep('/[\x{0A80}-\x{0AFF}]/u', $kw) && in_array('cctv camera price in dwarka', $kw, true) && in_array('audio cctv camera', $kw, true), count($kw) . '');
t_eq('no word is repeated', count($kw), count(array_unique($kw)));

t_group('Product content: Apply / Undo, never over the owner\'s words');
q("INSERT INTO items (name, brand, selling_price, purchase_price, tax_rate, is_active, item_type) VALUES ('IC Hikvision 2MP Dome Camera (Built-in Mic)', 'Hikvision', 1500, 1000, 18, 1, 'product')");
$a = (int)val('SELECT MAX(id) FROM items');
q("INSERT INTO items (name, brand, selling_price, purchase_price, tax_rate, is_active, item_type, description) VALUES ('IC Owner Camera 4MP', 'X', 1500, 1000, 18, 1, 'product', 'My own words about this camera.')");
$b = (int)val('SELECT MAX(id) FROM items');
$n = ic_apply();
t_ok('Apply writes a description and search words for an empty product', $n > 0 && strpos((string)val('SELECT description FROM items WHERE id = ?', [$a]), 'Key features:') !== false && trim((string)val('SELECT search_keywords FROM items WHERE id = ?', [$a])) !== '');
t_eq('the owner\'s own description is left exactly as it was', val('SELECT description FROM items WHERE id = ?', [$b]), 'My own words about this camera.');
q("UPDATE items SET search_keywords = 'my own words' WHERE id = ?", [$a]);
ic_apply();
t_eq('search words the owner changed are kept on the next Apply too', val('SELECT search_keywords FROM items WHERE id = ?', [$a]), 'my own words');
ic_undo();
t_eq('Undo puts the empty product back to empty', (string)val('SELECT description FROM items WHERE id = ?', [$a]), '');

t_group('Product content: store filters, categories');
$fm = ic_facet_map([['id' => 1, 'name' => $cam], ['id' => 2, 'name' => $pt], ['id' => 3, 'name' => 'DAHUA 2MP HDCVI IR Eyeball CCTV Camera (3.6mm Lens, IR LED) (DH-HAC-T1A21P-U)']]);
t_eq('filter values: audio / no audio, night type, megapixels', [$fm[1]['audio'] ?? null, $fm[2]['audio'] ?? null, $fm[3]['audio'] ?? null, $fm[1]['night'], $fm[3]['night'], $fm[2]['res']],
     ['With audio', 'Two-way audio', null, 'Dual light', 'IR night', '4MP']);
$cat = fn($n) => category_plan_for(['name' => $n]);
t_eq('every CCTV item goes under Security (cameras, recorders, disks, cable, accessories, power)', [$cat('CP-Plus IP NVR 4K 16CH (4K2161-V4)'), $cat('DAICHI Surveillance Hard Disk 2TB SATA 3.5 Inch'), $cat('VOLTAIC 3+1 CCTV Copper Cable (VC020)'), $cat('BNC PIN'), $cat('ABC CCTV Camera Power Supply 12V 8 Channel (ABC-SMPS-225)')],
     ['Security > IP NVR', 'Security > Surveillance Hard Disk', 'Security > 3+1 CCTV Cables', 'Security > CCTV Accessories', 'Security > Power Supply | Adapter']);
t_eq('a motherboard "with M.2 NVMe SSD / HDMI" is a motherboard; a keyboard "with cable" is a keyboard; a "Bullet Series" cable is a cable',
     [$cat('POWERX H110 Desktop Motherboard (LGA1151, DDR4, M.2 NVMe SSD Support)'), $cat('Zebronics H81S M2 LGA1150 DDR3 Micro ATX Motherboard (M.2 Slot, HDMI, VGA, Gigabit LAN) (ZEB-H81S M2)'), $cat('ZEBRONICS ZEB-K65 Wired USB Keyboard (104 Keys, UV Coated Keys, Retractable Stand, 1.2m Cable) (ZEB-K65)'), $cat('UBON Black Bullet Series 22W USB to Type-C Data & Charging Cable (High Speed) (WR-560)')],
     ['Desktop > Motherboard', 'Desktop > Motherboard', 'Peripherals > Keyboard | Combo', 'Mobility > Data Cable']);
t_eq('the last category mistakes: toner, neckband, karaoke speaker, printer cable, hub, power cable, charging adapter, PS/2 converter, USB switch, DVR/NVR',
     [$cat('137A Black Laser Toner Cartridge for HP Compatible Printers (H-W137A)'), $cat('UBON Black Bullet Series Bluetooth Wireless Neckband Earphones (CL-35)'),
      $cat('HAMMOK Duet 5W Bluetooth Karaoke Speaker with Wireless Microphone (Bluetooth v5.3, RGB LED, TWS, USB) (DUET)'), $cat('USB to Printer Cable 3 Meter'),
      $cat('Data Cables 4 Port USB 2.0 Hub with Micro USB Power Input (1.2 Meter Cable)'), $cat('Data Cables Computer Power Cable (5 Meter)'),
      $cat('Bali 67W USB Fast Charging Adapter with Type-C Cable (BA-WC26)'), $cat('DI USB to Dual PS/2 Keyboard & Mouse Converter Cable (Heavy Duty) (P7020)'),
      $cat('2-Port USB 2.0 Manual Switch Selector (1 Input, 2 Output Ports, Push Button Control)'), $cat('CP PLUS 4 Channel HD Digital Video Recorder (1 SATA Port, Without Hard Disk) (CP-UVR-0401E1V-I)'),
      $cat('DAHUA 16 Channel 4K Network Video Recorder (NVR, H.265+, 4K HDMI Output) (DHI-NVR2116HS-4KS3)')],
     ['Printers & Scanners > Toner | DMP Cartridge', 'Mobility > Neckband', 'Peripherals > Speaker', 'Cables > Printer Cables', 'Peripherals > USB | Type C Hub', 'Cables > Power Cables',
      'Mobility > Chargers', 'Connector & Converter > Converter', 'Peripherals > Extender | Switcher | Splitter', 'Security > HD DVR', 'Security > IP NVR']);

t_group('Product content: the owner\'s pasted Specifications');
$tbl = "Specifications\nFeature\nDescription\nModel\nCP-UNC-DA41L3C-LQ\nResolution\n4MP (2688×1520)\nLens\n3.6mm F1.6 M12\nIllumination\nIR & Warm Light 30m\nImage Sensor\n1/2.9\" CMOS\nAudio\nBuilt-in Mic\nVideo Compression\nH.265/H.264/MJPEG\nIP Rating\nIP67\nPower\n12V DC / PoE (802.3af)\nOperating Temp.\n–40°C to +60°C";
$ps = ic_specs_parse($tbl);
t_eq('a table copied from a website (label and value on alternate lines, header skipped) is read row by row', [count($ps), $ps[0], $ps[1], $ps[9]],
     [10, ['Model', 'CP-UNC-DA41L3C-LQ'], ['Resolution', '4MP (2688×1520)'], ['Operating Temp.', '–40°C to +60°C']]);
t_eq('"Key: Value", tab and "|" lines work too (a ":" inside a value is kept)', ic_specs_parse("Feature\tDescription\nLens\t3.6mm\nPower: 12V DC\nIP Rating | IP67\nAspect: 16:9"),
     [['Lens', '3.6mm'], ['Power', '12V DC'], ['IP Rating', 'IP67'], ['Aspect', '16:9']]);
$sit = ['id' => 9, 'name' => 'CP PLUS 4MP IP Dome Camera (CP-UNC-DA41L3C-LQ)', 'brand' => 'CP PLUS', 'model' => 'CP-UNC-DA41L3C-LQ', 'specs' => $tbl];
$rows = ic_spec_rows($sit); $labs = array_column($rows, 0);
t_ok('spec table: the owner\'s rows come first, in their order and words', array_slice($labs, 0, 3) === ['Model', 'Resolution', 'Lens']);
t_ok('...and what we read from the name is not shown twice (no second Resolution / Night vision / Weatherproof)', count(array_keys($labs, 'Resolution')) === 1 && !in_array('Night vision', $labs, true) && !in_array('Weatherproof', $labs, true), implode(', ', $labs));
$fm = ic_facet_map([$sit]);
t_eq('the website filters read the pasted specs too (audio, IP67 outdoor, IR + warm light = dual light)', [$fm[9]['audio'] ?? null, $fm[9]['place'] ?? null, $fm[9]['night'] ?? null], ['With audio', 'Outdoor', 'Dual light']);
$fm = ic_facet_map([['id' => 8, 'name' => 'X 2MP Dome Camera', 'specs' => "Audio: No\nIllumination: IR 20m"]]);
t_eq('an "Audio: No" row does not make it an audio camera', [$fm[8]['audio'] ?? null, $fm[8]['night'] ?? null], [null, 'IR night']);
t_ok('the description\'s Key features list has the owner\'s specs', strpos(ic_description($sit), '• Image Sensor: 1/2.9" CMOS') !== false);
q("INSERT INTO items (name, brand, selling_price, purchase_price, tax_rate, is_active, item_type) VALUES ('IC Spec Dome Camera 4MP', 'X', 1500, 1000, 18, 1, 'product')");
$s1 = (int)val('SELECT MAX(id) FROM items');
ic_apply();
q('UPDATE items SET specs = ? WHERE id = ?', ["Image Sensor: 1/2.8\" Sony CMOS", $s1]);
ic_refresh($s1);
t_ok('saving new specs re-writes a description we wrote (and nobody changed)', strpos((string)val('SELECT description FROM items WHERE id = ?', [$s1]), 'Sony CMOS') !== false);
q("UPDATE items SET description = 'Owner text', specs = 'Lens: 6mm' WHERE id = ?", [$s1]);
ic_refresh($s1);
t_eq('...but never the owner\'s own description', val('SELECT description FROM items WHERE id = ?', [$s1]), 'Owner text');
t_ok('the one-time Update step is versioned, so new names / categories / descriptions run again once', strpos(file_get_contents(__DIR__ . '/../includes/item_content.php'), "setting('item_content_once_ver') === \$ver") !== false);
