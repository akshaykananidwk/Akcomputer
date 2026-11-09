<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); die('CLI only'); }
// ============================================================================
//  CATEGORY PLAN - the shop's real product names (Items list, 03-11-2026)
//  must land in the right main > sub category, cameras above all. Then Apply
//  must move them and Undo must put every one back.
// ============================================================================
require_once __DIR__ . '/../includes/category_plan.php';
t_group('Category plan - product names land in the right place');
$cases = [
    // name, old category, expected
    ['CP PLUS 2.4MP Dual Light Bullet CCTV Camera (3.6mm Lens, 30M IR, 20M Warm Light, Built-in Mic, IP67) (CP-URC-TC24PL3C-L-V2-0360)', 'HD Camera', 'Security > HD Camera'],
    ['DAHUA 2MP HDCVI IR Eyeball CCTV Camera (3.6mm Lens, IR LED) (DH-HAC-T1A21P-U)', 'HD Camera', 'Security > HD Camera'],
    ['Securus 2MP HD Bullet CCTV Camera with Audio 3.6mm (SS-20L2TM-TPHSD-M2.4(S))', 'HD Camera', 'Security > HD Camera'],
    ['Coreprix 4MP IP Dual Light Dome Network Camera (3.6mm Lens, Built-in Mic, PoE, 20m IR) (CPI-4M D3SL (Dual))', 'IP Camera', 'Security > IP Camera'],
    ['CP-Plus 2MP IP illumax Hybrid Bullet Camera 3.6mm With Audio (TA21L3C-LQ)', '-', 'Security > IP Camera'],
    ['UNV 2MP Network Dome IP Camera (2.8mm Lens, PoE) (IPC3612LB-SF28-A)', 'IP Camera', 'Security > IP Camera'],
    ['SECURUS PEOPLE COUNTING 1.000 5850.00 526.50 6903.00 CAM SS-NC20L2CP-YXF-M8 (4K) 6MM', 'IP Camera', 'Security > IP Camera'],
    ['DAHUA Full-Color 2MP Network Dome IP Camera (2.8mm Lens, Smart H.265+, IP67) (DH-IPC-HDW1239T2-LED)', 'IP Camera', 'Security > IP Camera'],
    ['CP PLUS 4MP Full Color Wi-Fi PT CCTV Camera (Pan & Tilt, Color Night Vision) (CP-Z45Q)', 'WiFi Camera', 'Security > PTZ | PT | 4G | WIFI Camera'],
    ['Coreprix 4MP 4G Dual Lens Starlight PT CCTV Camera (Pan & Tilt, Two-Way Audio, Human Detection) (CPG-4M PTSL-DL)', '4G Sim Camera', 'Security > PTZ | PT | 4G | WIFI Camera'],
    ['DAICHI Quad Lens Solar PTZ Wireless CCTV Camera (Dual Light, Two-Way Audio, AI Motion Detection, Built-in Battery)', 'WiFi Camera', 'Security > PTZ | PT | 4G | WIFI Camera'],
    ['V380 Smart Net WiFi IP Robot Camera (Indoor Security Camera) (EC08)', 'WiFi Camera', 'Security > PTZ | PT | 4G | WIFI Camera'],
    ['CP PLUS ezyKam+ 2MP Smart WiFi Video Door Bell (PIR Sensor, Rechargeable Battery, USB Chime) (CP-L23)', 'Video Door Phone', 'Security > Video Door Phones (VDP)'],
    ['iVOOMi NETRA Full HD USB Web Camera (1080p, Built-in Microphone, Plug & Play)', 'Webcam', 'Peripherals > Webcam'],
    ['USB Mini HD Web Camera (Plug & Play) (V3-MINI)', 'Webcam', 'Peripherals > Webcam'],
    ['CP PLUS 4 Channel HD Digital Video Recorder (1 SATA Port, Two-Way Audio, Without Hard Disk) (CP-UVR-0401E1V-I)', 'DVR', 'Security > HD DVR'],
    ['CP-Plus 8ch 2MP 1 SATA H.265 DVR (0801E1V-I)', 'DVR', 'Security > HD DVR'],
    ['CP PLUS 8CH Network Video Recorder (NVR) (H.265, HDMI/VGA Output, Remote Viewing) (CP-UNR-108F1)', 'NVR', 'Security > IP NVR'],
    ['VOLTAIC Premium 4U DVR Rack Cabinet (Wall Mount, Powder-Coated Steel)', 'Rack & Junction Box', 'Security > Rack & Accessories'],
    ['5×5 CCTV Camera Junction Box', 'Rack & Junction Box', 'Security > Rack & Accessories'],
    ['Mouse RTM 019 dvr optical mouse', 'Keyboard & Mouse', 'Peripherals > Mouse | Mousepad'],
    ['VOLTAIC Bright Series 4CH CCTV SMPS Power Supply (DC 12V, Single Output) (VC-BS-4)', 'SMPS & Power Supply', 'Security > Power Supply | Adapter'],
    ['ZAPPIX 12V 5A CCTV Power Adapter (Over Voltage, Over Current, Short Circuit & Thermal Protection)', 'SMPS & Power Supply', 'Security > Power Supply | Adapter'],
    ['9V 0.6A DC Power Adapter (100-240V AC Input)', 'SMPS & Power Supply', 'Security > Power Supply | Adapter'],
    ['IVOOMI Opus 450W SMPS Power Supply (IV450)', 'Cabinet & Power Supply', 'Desktop > Power Supply (SMPS)'],
    ['Zebronics Economy Series 450W ATX Power Supply (20+4 Pin, SATA) (ZEB-N450W DSATA)', 'Cabinet & Power Supply', 'Desktop > Power Supply (SMPS)'],
    ['VOLTAIC 3+1 CCTV Copper Cable (VC020)', 'CCTV Cable', 'Cables > 3+1 CCTV Cables'],
    ['BNC PIN', 'BNC & Connector', 'Accessories CCTV & Networking'],
    ['CLARIANT 10mm Cable Nail Clips (Round Cable Clip)', 'Other Accessories', 'Accessories CCTV & Networking'],
    ['CABEL TIE SF300ST 300*4.8', 'Other Accessories', 'Accessories CCTV & Networking'],
    ['Hi-Focus 8+2 PoE Switch Up-link Giga (HF-S08G2-120WH)', 'POE Switch', 'Networking > POE Switch'],
    ['TP-Link 24V 0.5A Passive PoE Injector Adapter (12W, Gigabit) (POE2412G)', 'Patch Cord & Accessories', 'Networking > POE Switch'],
    ['HI-FOCUS 5-Port Gigabit Ethernet Switch (Plug & Play, Green Technology) (HF-EGT05-V2)', 'Network Switch', 'Networking > Desktop Switch'],
    ['TP-Link 16-Port Gigabit Desktop/Rackmount Network Switch (TL-SG1016D)', 'Network Switch', 'Networking > Desktop Switch'],
    ['Tenda N300 Wireless Router (300Mbps, 2.4GHz, 4-in-1 Router/Access Point/Repeater/Range Extender) (N301)', 'WiFi Router', 'Networking > Router | Access Point | Range Extender'],
    ['TP-LINK EAP225 AC 1350 CEILING MOUNT ACCESS POINT', '-', 'Networking > Router | Access Point | Range Extender'],
    ['TP-Link Omada OC200 Hardware Controller (Centralized Cloud Management, SD Card Backup)', 'Patch Cord & Accessories', 'Networking > Router | Access Point | Range Extender'],
    ['ABC 900Mbps Dual Band USB WiFi Adapter (2.4GHz + 5GHz, Bluetooth) (WB-19)', 'WiFi Router', 'Networking > USB Wifi Adapter | Receiver'],
    ['TP-Link USB 3.0 to Gigabit Ethernet Adapter (SuperSpeed USB 3.0, Foldable Design, Plug & Play)', 'Cables & Adapters', 'Connector & Converter > Converter'],
    ['Voltaic 10/100/1000Mbps Gigabit Single Mode Fiber Media Converter Pair (SC, 1310/1550nm) (8334A & 8334B)', 'Fiber & ONU', 'Networking > Fiber Accessories'],
    ['Fiber Cabel 2 Core', 'Fiber & ONU', 'Cables > Fiber Cables'],
    ['UGREEN Multifunction Network Crimping Tool for RJ45/RJ12/RJ11 Connectors (8P/6P, SK5 Blades) (NW136)', 'Patch Cord & Accessories', 'Networking > Tools'],
    ['D-Link RJ45 8P8C Cat6 UTP Modular Plug (100 Pack) (NPG-5E1TRA031-100)', 'Patch Cord & Accessories', 'Connector & Converter > Connector'],
    ['Lan Cabel Cat6', 'Network Cable (Cat6)', 'Cables > LAN Cables (CAT6)'],
    ['HDMI Extender 60M Cat6/Cat6A with USB Power Full HD 1080P (ABC-HE-512)', 'Cables & Adapters', 'Peripherals > Extender | Switcher | Splitter'],
    ['4K Ultra HD 3-Port HDMI Switch (3 Input 1 Output, Remote Control)', 'Cables & Adapters', 'Peripherals > Extender | Switcher | Splitter'],
    ['2 Port VGA Switch Box (VGA-15-2)', 'Cables & Adapters', 'Peripherals > Extender | Switcher | Splitter'],
    ['ABC USB Type-C 11-in-1 Multiport Docking Station Hub with HDMI 4K, VGA, RJ45 LAN, USB 3.0, USB 2.0, SD/TF Card Reader & 100W PD Charging (ABC-CNC-122)', 'Cables & Adapters', 'Peripherals > USB | Type C Hub'],
    ['ABC DisplayPort to HDMI Adapter (4K Ultra HD, 1080P Full HD) (ABC-CNC-135)', 'Cables & Adapters', 'Connector & Converter > Converter'],
    ['VGA to HDMI Video Converter (Full HD 1080P, Stereo Audio)', 'Cables & Adapters', 'Connector & Converter > Converter'],
    ['SATA Power Splitter Cable (1 Male to 2 Female SATA Power Splitter)', 'Cables & Adapters', 'Connector & Converter > Converter'],
    ['ABC OTG 3.0 3-in-1 USB Connection Kit (Lightning, Micro USB & USB OTG) (ABC-CNC-142)', 'Cables & Adapters', 'Mobility > OTG Connectors'],
    ['ABC 3 Meter Spike Guard Extension Board (Individual Switches, Surge Protection) (SP326 IND SWITCH)', 'Power Strip & Extension', 'Peripherals > Spike'],
    ['KYIRO Premium Series 5M High Speed HDMI 2.0 Cable (MI19H05MP)', 'Cables & Adapters', 'Cables > HDMI Cables'],
    ['NP TECH 10 Meter VGA Monitor Cable (White)', 'Cables & Adapters', 'Cables > VGA Cables'],
    ['ABC Printer USB 2.0 A to B Cable 3 Meter (ABC-PRC-172)', 'Printer Accessories', 'Cables > Printer Cables'],
    ['ABC Laptop Power Cable 3 Pin AC Power Cord (High Quality Wire) (ABC-PCLT-73)', 'Laptop Accessories', 'Cables > Power Cables'],
    ['UBON Black 20W Micro USB (V8) Braided Data & Charging Cable (50000+ Bend Test) (WR-550)', 'Cables & Adapters', 'Mobility > Data Cable'],
    ['UBON SpeedUp 3.0 100W QC + PD Fast Wall Charger (USB-A + USB-C) (CH-910)', 'Cables & Adapters', 'Mobility > Chargers'],
    ['Sata Cables', 'Cables & Adapters', 'Cables > Other Cables'],
    ['HP 65W 7.4mm Non-EM Laptop AC Power Adapter (Y5Y42AA#ACJ)', 'Laptop Charger', 'Laptop > Laptop Adaptor'],
    ['iVOOMi Windy 10-19 Inch Laptop Cooling Stand with RGB Fan & 360° Rotating Base (IV-32R)', 'Laptop Accessories', 'Laptop > Laptop Cooling Pad'],
    ['Dell Inspiron 3542 Original Laptop Keyboard (Dell Inspiron 3542)', 'Laptop Accessories', 'Laptop > Laptop Keyboard'],
    ['Dell Bag', 'Laptop Accessories', 'Laptop > Laptop Accessories'],
    ['Lenovo ThinkPad L470 14-Inch Laptop (Intel Core i5-7200U, 8GB RAM, 256GB SSD, Intel HD Graphics 620)', 'Laptop', 'Laptop > Branded Laptop'],
    ['Dell Latitude 5480 Core i5 8GB 256GB NVMe SSD Touchscreen Laptop (i5-6440HQ)', 'Laptop', 'Laptop > Branded Laptop'],
    ['DELL LATITUDE 5430', 'Laptop', 'Laptop > Branded Laptop'],
    ['12.7mm SATA Second HDD Caddy Drive for 2.5 Inch HDD/SSD (SATA Interface, Optical Drive Bay)', 'Laptop Accessories', 'Storage > Casing | Copier | Card Reader'],
    ['SmartPro Classic Series M.2 NGFF & NVMe 2-in-1 SSD Enclosure (USB 3.1 Gen 2 Type-C, 10Gbps, RGB Lighting) (SP-S108)', 'SSD & Hard Drive', 'Storage > Casing | Copier | Card Reader'],
    ['DAICHI 500GB Surveillance Hard Disk Drive (SATA, 3.5 Inch, CCTV DVR/NVR)', 'Hard Disk (HDD)', 'Storage > Surveillance Hard Disk'],
    ['Seagate Surveillance Hard Disk Skyhawk 4TB SATA 3.5 Inch', 'Hard Disk (HDD)', 'Storage > Surveillance Hard Disk'],
    ['Geonix PowerShell S3 500GB 2.5 Inch Portable Hard Disk Drive (USB 3.0, 5Gbps, Plug & Play) (GXPHDD03)', 'SSD & Hard Drive', 'Storage > External Hard Disk'],
    ['DARWIN E100 256GB 2.5 Inch SATA 3.0 Solid State Drive (SSD) (E100)', 'SSD & Hard Drive', 'Storage > Internal SSD'],
    ['WD Blue 500GB 2.5-Inch SATA Internal Laptop Hard Disk Drive (5400 RPM, 8MB Cache) (WD5000LPVX)', 'SSD & Hard Drive', 'Storage > Computer Hard Disk'],
    ['Master 64GB USB 3.2 Flash Drive (USB Type-A)', 'Pendrive & Memory Card', 'Storage > Pendrive'],
    ['HI Focus Ultra 128GB Class 10 MicroSDHC Memory Card, (HF-SD128XC)', 'Pendrive & Memory Card', 'Storage > SD | Micro SD Cards'],
    ['HP DVD-R 4.7GB 16X Blank Recordable DVD Disc (120 Min Video)', 'Other Accessories', 'Peripherals > CD | DVD | DVD Writer'],
    ['Intel Core i5-3470 3.20GHz Desktop Processor (i5-3470)', 'Processor', 'Desktop > CPU (Processor)'],
    ['INTEL CPU FAN (I7-12700F LGA1700)', 'Processor', 'Desktop > CPU (Processor) Fan'],
    ['FORCE H81 Desktop Motherboard (Intel LGA1150, DDR3, Micro-ATX)', 'Motherboard', 'Desktop > Motherboard'],
    ['Crucial 8GB DDR4 2666MHz Desktop RAM (UDIMM, 1.2V, CL19) (CT8G4DFS8266.M8FE)', 'RAM', 'Desktop > Desktop RAM (Memory)'],
    ['Micron 4GB DDR3L 1600MHz Laptop RAM (PC3L-12800S, 1Rx8) (MT8KTF51264HZ-1G6E1)', 'RAM', 'Laptop > Laptop RAM'],
    ['ARKTEK 2GB Graphics Card (GPU, Dedicated Video Memory)', '-', 'Desktop > Graphics Card (GPU)'],
    ['ZEBRONICS ZEB-ICEBERG Gaming Cabinet (Tempered Glass Side Panel, Pre-Installed Fans) (ZEB-ICEBERG)', 'Cabinet & Power Supply', 'Desktop > Cabinet | Cabinet Fan'],
    ['CyberPower 600VA UPS (BU600E)', 'UPS', 'Desktop > UPS | UPS Batteries'],
    ['IVOOMI 12V 7.2Ah Lead Acid UPS Battery (IV175-7)', 'UPS Battery', 'Desktop > UPS | UPS Batteries'],
    ['MSI PRO 21.5-Inch FHD IPS Display Monitor (PRO MP225 E12L)', 'Monitor', 'Display > LED | Monitor'],
    ['EARTHONIC 32-Inch HD Smart LED TV (Android TV, Netflix, YouTube) (E32HDSF)', 'Other Accessories', 'Display > Television'],
    ['RANZ 14-26 Inch LCD/LED TV Wall Mount Kit (WALL MOUNT 14X26 FIX)', 'Other Accessories', 'Display > Wall Mount Kit | Projector Stand'],
    ['Multybyte Adjustable LED TV Wall Mount Bracket for 14-26 Inch Display (180° Swivel, 15kg Load Capacity)', 'Other Accessories', 'Display > Wall Mount Kit | Projector Stand'],
    ['Zebronics PixaPlay 1080p FHD Smart LED Projector (12000 Lumens, Dual Band Wi-Fi, Bluetooth, HDMI, USB) (ZEB-PixaPlay 58)', 'Other Accessories', 'Display > Projector | Projector Screen'],
    ['HP KM260 Wireless Keyboard and Mouse Combo (2.4GHz Wireless, 1600 DPI Optical Mouse, USB Nano Receiver) (KM260)', 'Keyboard & Mouse', 'Peripherals > Keyboard | Combo'],
    ['Logitech K120 Corded USB Keyboard (Full Size, Spill Resistant, Adjustable Tilt Legs) (K120)', 'Keyboard & Mouse', 'Peripherals > Keyboard | Combo'],
    ['Hama MW-300 2.4GHz Wireless Optical Mouse (1200 DPI, Silent Keys, USB-A Nano Receiver)', 'Keyboard & Mouse', 'Peripherals > Mouse | Mousepad'],
    ['Mouse Pad', 'Other Accessories', 'Peripherals > Mouse | Mousepad'],
    ['ANT Esports GPL300 Wireless Gamepad - Black (AEPP0238)', 'Other Accessories', 'Peripherals > Gamepad'],
    ['Maxell CR2032 3V Lithium Coin Cell Battery (Micro Lithium Cell)', 'Other Accessories', 'Peripherals > Calculator | Cmos Battery | Cell'],
    ['boAt Aavante Bar 600 Bluetooth Soundbar (Bluetooth v5.0, AUX, USB, Type-C, Up to 6 Hours Playback) (Aavante Bar 600)', 'Speaker & Headphone', 'Peripherals > Speaker'],
    ['ZEBRONICS ZEB-1000HMV Stereo Headphone with Adjustable Microphone (Dual 3.5mm Jack, Volume Control, 2m Braided Cable) (ZEB-1000HMV)', 'Speaker & Headphone', 'Mobility > Headphone'],
    ['boAt Airdopes 131 TWS Earbuds (BT v5.1, 60H Playback, Type-C)', 'Speaker & Headphone', 'Mobility > Earbuds TWS'],
    ['KDM Striker Wireless Neckband (45H, ANC, Type-C) (KDM-A1)', 'Speaker & Headphone', 'Mobility > Neckband'],
    ['KDM K226 Stereo In-Ear Earphone (3.5mm Jack, 10mm Driver, 1.2m Cable) (K226)', 'Speaker & Headphone', 'Mobility > Earphones'],
    ['Xiaomi Power Bank 4i 10000mAh Power Bank (22.5W Fast Charge, USB-C & Dual USB Output) (PB100DPD-IN)', 'Other Accessories', 'Mobility > Power Bank'],
    ['Ultra 45mm Smart Watch (Titanium Case, Orange Alpine Band) (A2684)', 'Other Accessories', 'Mobility > Smart Watch'],
    ['Zebronics Wired 1D/2D Barcode Scanner (ZEB-BS2H1000)', 'Other Accessories', 'Printers & Scanners > Barcode Scanner'],
    ['PLAIN CHROMO LAB 50x38mm Adhesive Barcode Labels Roll (3000 Labels, J150382CM)', 'Printer Accessories', 'Printers & Scanners > WAX Ribbon | Barcode Roll'],
    ['Thermal Paper Roll 25 mtr', 'Printer Accessories', 'Printers & Scanners > WAX Ribbon | Barcode Roll'],
    ['A4 paper', 'Printer Accessories', 'Printers & Scanners > Photo Paper | Paper Rim | PVC Card'],
    ['EPSON EcoTank 003 Black Ink Bottle 65ml (C13T00V198G)', 'Ink & Refill', 'Printers & Scanners > Ink Bottle'],
    ['12A Toner Refilling Cartridge', 'Cartridge & Toner', 'Printers & Scanners > Toner | DMP Cartridge'],
    ['HP 110A Black Laser Toner Cartridge (Compatible for HP Laser 108/108W, MFP 136A/136W/138 Series) (110/W1110A-O)', 'Cartridge & Toner', 'Printers & Scanners > Toner | DMP Cartridge'],
    ['HP LaserJet Pro MFP M126nw Monochrome Laser Multifunction Printer (Old)', 'Laser Printer', 'Printers & Scanners > Printer | Scanner'],
    ['Rugtek RP82A Thermal Receipt Printer (RP82)', 'Other Accessories', 'Printers & Scanners > Printer | Scanner'],
    ['Quick Heal Antivirus', 'Antivirus & Software', 'Software > Antivirus'],
    ['ACS ACR122U NFC Contactless Smart Card Reader/Writer (USB, 13.56MHz) (ACR122U-A9)', 'Biometric & Access Control', 'Security > Biometrics'],
    ['BAOFENG BF-888S UHF FM Portable Two-Way Radio (Li-ion Battery, Flashlight, Noise Reduction) (BF-888S)', 'Other Accessories', 'Telecom > Telephone Set'],
    ['Astronaut Galaxy Projector Light for Bedroom with Remote Control and Timer', 'Other Accessories', 'Mobility > Utility'],
    ['MSI PRO 21.5-Inch FHD IPS Display Monitor (100Hz, 1ms, Anti-Glare, HDMI) (PRO MP225 E 12VL)', 'Monitor', 'Display > LED | Monitor'],
    ['Canon PIXMA G2012 Ink Tank All-in-One Multifunction Printer (Print, Scan & Copy) (G2012)', 'Ink Tank Printer', 'Printers & Scanners > Printer | Scanner'],
    ['Canon PIXMA MegaTank GI-790 BLACK Printer Ink Bottle (GI-790M)', '-', 'Printers & Scanners > Ink Bottle'],
    ['CP PLUS IP 2MP DUAL LIGHT AUDIO DOME (CP-UNC-DA21L3C-LQ-2.8MM)', '-', 'Security > IP Camera'],
    ['POE Extender Switch (1 Input, 2 Output Ports, Ethernet Network Extension)', '-', 'Networking > POE Switch'],
    ['RockLight Gabbar S834 50W Wireless Bluetooth Speaker with Wireless Mic offering crystal-clear sound and easy portability', 'Speaker & Headphone', 'Peripherals > Speaker'],
    ['ELERTA 32-inch Full HD Smart LED TV (32 RS-SMART LED)', 'Monitor', 'Display > Television'],
    ['47-in-1 Electric Screwdriver Set with Cordless Drill Machine | Portable Rechargeable Drill Driver Kit with Multiple Screwdriver Bits Compact DIY Home Improvement Tool', 'Other Accessories', 'Networking > Tools'],
];
$wrong = [];
foreach ($cases as [$name, $old, $want]) {
    $got = category_plan_for(['name' => $name, 'item_type' => 'product', 'cat_name' => $old]);
    if ($got !== $want) $wrong[] = "$name → " . var_export($got, true) . " (want $want)";
}
t_eq('every sample product lands where a shop customer would look for it', $wrong, []);
t_eq('a service is never moved', category_plan_for(['name' => 'CCTV Camera installation', 'item_type' => 'service', 'cat_name' => '']), null);
t_eq('a name no rule knows keeps its category', category_plan_for(['name' => 'Nk', 'item_type' => 'product', 'cat_name' => 'Other Accessories']), null);

t_group('Category plan - Apply moves products, Undo puts them back');
q("INSERT INTO categories (name) VALUES ('Old CCTV test')");
$oldCat = (int)val('SELECT MAX(id) FROM categories');
q("INSERT INTO items (name, selling_price, purchase_price, is_active, item_type, category_id) VALUES ('Test 2MP Bullet CCTV Camera', 1, 1, 1, 'product', ?)", [$oldCat]);
$cam = (int)val('SELECT MAX(id) FROM items');
q("INSERT INTO items (name, selling_price, purchase_price, is_active, item_type, category_id) VALUES ('Test CCTV fitting labour', 1, 1, 1, 'service', ?)", [$oldCat]);
$svc = (int)val('SELECT MAX(id) FROM items');
$moved = category_plan_apply();
$catNow = row('SELECT c.name, p.name parent FROM items i JOIN categories c ON c.id = i.category_id LEFT JOIN categories p ON p.id = c.parent_id WHERE i.id = ?', [$cam]);
t_ok('Apply moved at least the camera', $moved >= 1);
t_eq('the camera now sits in Security > HD Camera', [$catNow['parent'], $catNow['name']], ['Security', 'HD Camera']);
t_eq('the service stayed where it was', (int)val('SELECT category_id FROM items WHERE id = ?', [$svc]), $oldCat);
t_eq('no category name was made twice', (int)val("SELECT COUNT(*) FROM categories WHERE name = 'HD Camera'"), 1);
category_plan_undo();
t_eq('Undo put the camera back in its old category', (int)val('SELECT category_id FROM items WHERE id = ?', [$cam]), $oldCat);
t_eq('Undo leaves nothing to undo twice', setting('category_plan_undo'), '');

t_group('Product photos are looked for on the maker\'s own website first');
foreach ([
    'CP PLUS 2.4MP Dual Light Bullet CCTV Camera' => 'cpplusworld.com', 'TP-Link Omada ER605 Gigabit VPN Router' => 'tp-link.com',
    'DAHUA 2MP HDCVI IR Eyeball CCTV Camera' => 'dahuasecurity.com', 'HP 110A Black Laser Toner Cartridge' => 'hp.com',
    'ZEBRONICS ZEB-K65 Wired USB Keyboard' => 'zebronics.com', 'UNV 2MP Network Dome IP Camera' => 'uniview.com',
    'Seagate Surveillance Hard Disk Skyhawk 4TB' => 'seagate.com', 'Smart Intelligent Dome Camera' => null,
    'BNC PIN' => null, 'Model 5 Meter VGA Cable' => null,
] as $name => $want) t_eq("$name → " . ($want ?? 'no brand site'), brand_official_site($name), $want);
$src = file_get_contents(__DIR__ . '/../ai_enrich.php');
t_ok('Auto-Fill tries the brand site before the open web', strpos($src, 'gcs_image_search($label, 4, $site)') !== false);
t_ok('...and every saved photo still has to pass the AI match check', strpos($src, '$v = gemini_verify_image($jpeg, $label);') !== false);
