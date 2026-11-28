<?php
// Product names that a customer and the shop both understand at a glance,
// ending with the model number in brackets: "Brand Product (Key specs) (MODEL)".
// The owner asked for this (28-11-2026) and allowed every name to be changed.
// Shown first, Apply keeps an Undo. Prices, stock and bills are not touched
// (an old bill simply shows the clearer name when it is opened again).

/** One spelling for each brand, as the brand writes itself. */
function in_brands() {
    return ['cp plus' => 'CP PLUS', 'cp-plus' => 'CP PLUS', 'dahua' => 'Dahua', 'hi-focus' => 'Hi-Focus', 'hi focus' => 'Hi-Focus', 'ivoomi' => 'iVOOMi', 'tp-link' => 'TP-Link',
            'zebronics' => 'Zebronics', 'securus' => 'Securus', 'voltaic' => 'Voltaic', 'consistent' => 'Consistent', 'ant esports' => 'Ant Esports', 'kyiro' => 'Kyiro', 'morjim' => 'Morjim',
            'lapcare' => 'Lapcare', 'gigabyte' => 'Gigabyte', 'samsung' => 'Samsung', 'omada' => 'Omada', 'canon' => 'Canon', 'epson' => 'Epson', 'elerta' => 'Elerta', 'earthonic' => 'Earthonic',
            'fron tech' => 'Frontech', 'frontech' => 'Frontech', 'power x' => 'PowerX', 'powerx' => 'PowerX', 'zebion' => 'Zebion', 'zappix' => 'Zappix', 'metrix' => 'Metrix', 'fingers' => 'Fingers',
            'retsol' => 'Retsol', 'arktek' => 'Arktek', 'baofeng' => 'Baofeng', 'bosi' => 'Bosi', 'clariant' => 'Clariant', 'tenda' => 'Tenda', 'coreprix' => 'Coreprix', 'daichi' => 'DAICHI',
            'seagate' => 'Seagate', 'toshiba' => 'Toshiba', 'crucial' => 'Crucial', 'micron' => 'Micron', 'logitech' => 'Logitech', 'lenovo' => 'Lenovo', 'dell' => 'Dell', 'hp' => 'HP',
            'mikrotik' => 'MikroTik', 'imou' => 'IMOU', 'unv' => 'UNV', 'krystaa' => 'Krystaa', 'xiaomi' => 'Xiaomi', 'ubon' => 'UBON', 'evm' => 'EVM', 'abc' => 'ABC', 'kdm' => 'KDM',
            'force' => 'Force', 'maix' => 'MAIX', 'cablet' => 'Cablet', 'ugreen' => 'UGREEN', 'geonix' => 'Geonix', 'irvine' => 'Irvine', 'darwin' => 'Darwin', 'foxin' => 'Foxin', 'aarvex' => 'Aarvex',
            'quick heal' => 'Quick Heal', 'msi' => 'MSI', 'intel' => 'Intel', 'cyberpower' => 'CyberPower', 'rugtek' => 'Rugtek', 'n-tech' => 'N-Tech', 'sk hynix' => 'SK hynix', 'western digital' => 'Western Digital'];
}

/** Words written in capitals on purpose (never turned into "Cctv"). */
function in_keep_caps() {
    return ['CCTV', 'HDMI', 'USB', 'SSD', 'HDD', 'LED', 'LCD', 'NVR', 'DVR', 'XVR', 'SMPS', 'UPS', 'VGA', 'RAM', 'SATA', 'NVME', 'ATX', 'HD', 'FHD', 'UHD', 'IP', 'PT', 'PTZ', 'IR', 'AI', 'AC', 'DC',
            'LAN', 'WAN', 'POE', 'RGB', 'TV', 'GPU', 'CPU', 'DPI', 'MP', 'OTG', 'NFC', 'RJ45', 'RJ11', 'UTP', 'SFP', 'VPN', 'QOS', 'VLAN', 'IGMP', 'MFP', 'POS', 'PD', 'QC', 'TWS', 'ANC', 'ENC', 'FM',
            'AUX', 'TF', 'SD', 'SDHC', 'BNC', 'PIR', 'DDR3', 'DDR4', 'DDR5', 'DDR3L', 'ECC', 'UDIMM', 'IPS', 'VA', 'RMS', 'HDCVI', 'CPE', 'LTE', 'SIM', 'DIY', 'II', 'III', 'PC', 'MTR', 'PCS', 'A4', 'B&W', 'PVC',
            'PCIE', 'HDTV', 'OLED', 'CMOS', 'GPON', 'EPON', 'BAFO', 'IMOU', 'MAIX', 'UBON', 'AHD', 'CVBS', 'MIMO', 'NVMe', 'UASP', 'IPSEC', 'WAX', 'MSIX', 'PIXMA', 'IEEE', 'TVS', 'HDCP', 'MPPT', 'UASP'];
}

/** Names that said too little, written out in full (only facts that are true for that item). */
function in_overrides() {
    return [
        'BNC PIN' => 'BNC Connector Pin for CCTV Coaxial Cable', 'DC PIN' => 'DC Power Connector Pin for CCTV Camera', 'CPU FAN' => 'CPU Cooling Fan for Desktop Processor',
        'Mouse Pad' => 'Mouse Pad for Computer Mouse', 'Sata Cables' => 'SATA Data Cable for Hard Disk / SSD', 'desktop power cable' => 'Desktop Computer Power Cable',
        'A4 paper' => 'A4 Printing Paper', 'Lan Cabel Cat6' => 'Cat6 LAN Cable (per Meter)', 'Fiber Cabel 2 Core' => '2 Core Fiber Optic Cable (per Meter)',
        'Lan cable 1 mitter' => 'LAN Patch Cable 1 Meter', 'Lan cable 1.5 miter' => 'LAN Patch Cable 1.5 Meter', 'Camera Fiting charge' => 'CCTV Camera Fitting Charge',
        'Dell Bag' => 'Dell Laptop Bag', 'HP BAG' => 'HP Laptop Bag', 'Zebion mouse' => 'Zebion USB Wired Mouse', 'Pendriv Wooden box' => 'Wooden Pendrive Gift Box',
        'CABEL TIE SF300ST 300*4.8' => 'Cable Tie 300 x 4.8mm (SF300ST)', 'Tie Closer 100X2.5' => 'Cable Tie 100 x 2.5mm', 'Tag tie mini' => 'Mini Tag Cable Tie',
        'RJ45 Waterproof Cap' => 'RJ45 Waterproof Cap for Outdoor IP Camera Cable', 'Thermal Paper Roll 25 mtr' => 'Thermal Paper Roll 25 Meter (for Receipt Printer)',
        'LED Projector T200' => 'T200 LED Mini Projector (T200)', 'Quick Heal Antivirus' => 'Quick Heal Antivirus Software (1 User)',
        'DELL LATITUDE 5430' => 'Dell Latitude 5430 Laptop (Latitude 5430)', 'HP LAPTOP 15-FD0682TU' => 'HP Laptop 15 (15-FD0682TU)',
        'POWER X 256GB SSD' => 'PowerX 256GB SATA Solid State Drive (SSD)', 'WD 256GB NVME M.2 SSD' => 'WD 256GB M.2 NVMe Solid State Drive (SSD)',
        'DAICHI MICRO SD CARD 64GB' => 'DAICHI 64GB MicroSD Memory Card', 'Intel Core i5 6th Gen Desktop Processor' => 'Intel Core i5 6th Gen Desktop Processor',
        'SECURUS PEOPLE COUNTING 1.000 5850.00 526.50 6903.00 CAM SS-NC20L2CP-YXF-M8 (4K) 6MM' => 'Securus 4K People Counting IP Camera 6mm (SS-NC20L2CP-YXF-M8)',
        'CP PLUS IP 2MP DUAL LIGHT AUDIO DOME (CP-UNC-DA21L3C-LQ-2.8MM)' => 'CP PLUS 2MP IP Dual Light Audio Dome Camera 2.8mm (CP-UNC-DA21L3C-LQ-2.8MM)',
        'TP-LINK OMADA EAP225 OUTDOOR ACCESS POINT AC1200' => 'TP-Link Omada EAP225-Outdoor AC1200 Outdoor Access Point (EAP225-Outdoor)',
        'TP-LINK EAP225 AC 1350 CEILING MOUNT ACCESS POINT' => 'TP-Link Omada EAP225 AC1350 Ceiling Mount Access Point (EAP225)',
        'ZEBRONICS 22 INCH MONITOR (A122FHD)' => 'Zebronics 22 Inch Full HD LED Monitor (A122FHD)', 'ZEBRONICS 25 inch monitor (A25FHD)' => 'Zebronics 25 Inch Full HD LED Monitor (A25FHD)',
        'FRONTECH LED 22\'\' (MON-0060)' => 'Frontech 22 Inch LED Monitor (MON-0060)', 'HOC 21 Meter High-Speed HDMI cabel (HH01-21M)' => 'HOC 21 Meter High-Speed HDMI Cable (HH01-21M)',
        'CP PLUS 3MP 4G Full HD Smart CCTVCamera (EZ-M32G)' => 'CP PLUS 3MP 4G Full HD Smart CCTV Camera (EZ-M32G)',
        'A10 Android Protable Projector with 4K – Android 13 Dual Wifi 5G' => 'A10 Android 13 Portable Projector (4K Support, Dual Band WiFi 5G) (A10)',
        'Ultra 45mm Smart Watch (Titanium Case, Orange Alpine Band) (A2684)' => 'Ultra 45mm Smart Watch (Titanium Case, Orange Alpine Band) (A2684)',
    ];
}

/** The clear name for one item ('' = keep). Also returns the brand in its one spelling. */
function in_clean(array $it) {
    $old = (string)$it['name'];
    $n = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}\x{00A0}]/u', ' ', $old);   // invisible characters from copy-paste
    $n = trim(preg_replace('/\s+/u', ' ', $n));
    $ov = in_overrides();
    if (isset($ov[$n])) $n = $ov[$n];
    // spelling
    $fix = ['/\bcabel\b/i' => 'Cable', '/\bprotable\b/i' => 'Portable', '/\bfiting\b/i' => 'Fitting', '/\bmi(t)?ter\b/i' => 'Meter', '/CCTVCamera/i' => 'CCTV Camera', '/\bwi-?fi\b/i' => 'WiFi',
            '/\(\s+/' => '(', '/\bPOE\b/' => 'PoE', '/\bLan\b/' => 'LAN', '/(\d)mp\b/' => '$1MP', '/\s+\)/' => ')', '/\s+,/' => ',', '/\bmitter\b/i' => 'Meter', '/(\d)\s?mtr\b/i' => '$1 Meter', '/\bpendriv\b/i' => 'Pendrive'];
    // the model in the last brackets is written exactly as the maker writes it: keep it aside
    $tail = '';
    if (preg_match('/\s*(\((?:[^()]|\([^()]*\))*\))\s*$/u', $n, $tm) && (in_has_model($tm[1]) || strpos($tm[1], ' ') === false)) { $tail = ' ' . $tm[1]; $n = mb_substr($n, 0, mb_strlen($n) - mb_strlen($tm[0])); }
    foreach ($fix as $re => $to) $n = preg_replace($re . 'u', $to, $n);
    // (a stray extra bracket at the end is dropped below: "(IV-WM02))")
    // words in ALL CAPS read badly ("TP-LINK OMADA OUTDOOR ..."): Title Case, keeping real acronyms and codes
    $keep = array_flip(in_keep_caps());
    // (3-letter words such as ABC, KDM, LGA, AMD stay as written)
    $n = preg_replace_callback('/(?<![\w-])([A-Z][A-Z&]{3,})(?![\w-])/u', function ($m) use ($keep) {
        $w = $m[1];
        if (isset($keep[$w]) || preg_match('/\d/', $w)) return $w === 'POE' ? 'PoE' : $w;
        return mb_convert_case(mb_strtolower($w), MB_CASE_TITLE);
    }, $n);
    // brands spelled one way, at the start of the name
    $brand = trim((string)($it['brand'] ?? ''));
    foreach (in_brands() as $k => $v) {
        $re = '/^' . preg_quote($k, '/') . '(?=[\s,(]|$)/iu';
        if (preg_match($re, $n)) { $n = preg_replace($re, $v, $n); if ($brand === '') $brand = $v; break; }   // empty Brand: the one the name starts with (gives it a brand page)
    }
    $bk = mb_strtolower($brand);
    $brandNew = in_brands()[$bk] ?? ($brand !== '' && preg_match('/^[A-Z]{4,}$/', $brand) ? mb_convert_case(mb_strtolower($brand), MB_CASE_TITLE) : $brand);
    $n .= $tail;
    if (substr_count($n, ')') > substr_count($n, '(')) $n = preg_replace('/\)\)\s*$/u', ')', $n);
    // the model number in brackets at the end: the item's model, or a model code the name already carries
    $model = trim((string)($it['model'] ?? ''));
    if ($model === '' && !in_has_model($n)) $model = in_find_code($n);
    if ($model !== '' && !in_has_model($n) && stripos(preg_replace('/\([^()]*\)\s*$/u', '', $n), '(' . $model) === false) $n .= ' (' . $model . ')';
    elseif ($model !== '' && stripos($n, $model) === false) $n .= ' (' . $model . ')';
    $n = trim(preg_replace('/\s+/u', ' ', $n));
    return [$n, $brandNew];
}

/** A model code the name carries outside brackets: "L3216", "H61", "80HR", "12A", "i96". */
function in_find_code($name) {
    $head = preg_split('/\(/u', $name)[0];
    foreach (preg_split('/[\s,|]+/u', $head) as $tok) {
        $t = trim($tok, " -–.:;");
        if (mb_strlen($t) < 2 || mb_strlen($t) > 20 || !preg_match('/\d/', $t) || !preg_match('/[A-Za-z]/', $t)) continue;
        if (preg_match('/^\d+(\.\d+)?(gb|tb|mp|w|v|a|mm|hz|mhz|ghz|mah|ah|ml|inch|k|ch|port|m|meter|mtr|pcs|x|th|nd|rd|st|d|mbps|gbps|p|g)$/i', $t)) continue;   // 500GB, 4MP, 450W, 4K, 5D, 4G ...
        if (preg_match('/^(ddr\dl?|usb\d?|h\.26\d\+?|wi-?fi\d\w*|cat\d\w*|ip\d\d|rj\d\d|pc\d|\d+-in-\d+|\d+x\d+|\d+×\d+|type-?c|a4|lga\d+|am\d|i[3579]|core|gen\d)$/i', $t)) continue;
        if (preg_match('/^i[3579]-/i', $t) || preg_match('/^\d+-(port|inch|in|channel|ch|way|pin|core)$/i', $t) || preg_match('/^\d{3}\.\d/', $t) || strpos($t, '/') !== false) continue;   // a processor named inside a laptop name is not the laptop's model
        return $t;
    }
    return '';
}

/** Does the name end with a model number in brackets? (letters + digits, no long sentence) */
function in_has_model($name) {
    if (!preg_match('/\(([^()]+)\)\s*$/u', $name, $m)) return false;
    $c = trim($m[1]);
    return (bool)preg_match('/\d/', $c) && (bool)preg_match('/[A-Za-z]/', $c) && mb_strlen($c) <= 40 && substr_count($c, ' ') <= 3 && !preg_match('/,|\b(lens|support|with|and)\b/i', $c);
}

/** Every product whose name or brand would change, and the ones still missing a model number. */
function in_plan() {
    $rows = all("SELECT id, name, brand, model, item_type FROM items WHERE is_active = 1 ORDER BY name");
    $changes = []; $noModel = [];
    foreach ($rows as $r) {
        if ($r['item_type'] === 'service') continue;
        [$n, $b] = in_clean($r);
        if ($n !== $r['name'] || $b !== (string)$r['brand']) $changes[] = ['id' => (int)$r['id'], 'old' => $r['name'], 'new' => $n, 'old_brand' => (string)$r['brand'], 'brand' => $b];
        if (!in_has_model($n)) $noModel[] = ['id' => (int)$r['id'], 'name' => $n, 'model' => (string)$r['model']];
    }
    return [$changes, $noModel];
}
function in_apply() {
    [$plan] = in_plan(); if (!$plan) return 0;
    $pdo = db(); $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction(); else $pdo->exec('SAVEPOINT item_names');
    try {
        q('DELETE FROM item_name_undo');
        foreach ($plan as $p) {
            q('INSERT INTO item_name_undo (item_id, old_name, old_brand) VALUES (?,?,?)', [$p['id'], $p['old'], $p['old_brand']]);
            q('UPDATE items SET name = ?, brand = ? WHERE id = ?', [mb_substr($p['new'], 0, 255), $p['brand'], $p['id']]);
        }
        if ($own) $pdo->commit(); else $pdo->exec('RELEASE SAVEPOINT item_names');
    } catch (Exception $e) { if ($own) $pdo->rollBack(); else $pdo->exec('ROLLBACK TO SAVEPOINT item_names'); throw $e; }
    return count($plan);
}
function in_undo_info() { $r = row('SELECT COUNT(*) n, MAX(at) at FROM item_name_undo'); return $r && (int)$r['n'] ? $r : null; }
function in_undo() {
    $rows = all('SELECT * FROM item_name_undo');
    foreach ($rows as $u) q('UPDATE items SET name = ?, brand = ? WHERE id = ?', [$u['old_name'], $u['old_brand'], (int)$u['item_id']]);
    q('DELETE FROM item_name_undo');
    return count($rows);
}
/** The owner types a model number: saved on the item and put at the end of its name. */
function in_set_model($id, $model) {
    $model = trim(mb_substr((string)$model, 0, 60));
    $it = row('SELECT id, name, brand, model FROM items WHERE id = ?', [(int)$id]);
    if (!$it || $model === '') return false;
    $name = stripos($it['name'], $model) === false ? $it['name'] . ' (' . $model . ')' : $it['name'];
    q('UPDATE items SET model = ?, name = ? WHERE id = ?', [$model, mb_substr($name, 0, 255), (int)$id]);
    return true;
}
