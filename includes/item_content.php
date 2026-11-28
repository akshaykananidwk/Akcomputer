<?php
// Product content for the website and Google: the facts read from each
// product's own name (megapixels, audio, night vision, RAM size, wattage,
// ports, length ...), a full description built from those facts (what it is,
// key features, where it is used, how it works and what else is needed), the
// search words customers type for it, and the filters on the store.
//
// Nothing is invented: a feature is written only when the product's name (or
// its category) says so. What a kind of product is used for and how it is
// connected is general and true for every product of that kind.

/** What kind of product this is, from its category path and name. */
function ic_kind($it) {
    $n = mb_strtolower(($it['name'] ?? '') . ' ' . ($it['cat_name'] ?? '') . ' ' . ($it['group'] ?? ''));
    $k = [
        'cctv_acc' => '/\bbnc\b|dc pin|junction box|\brack\b/', 'converter_box' => '/caddy|enclosure|card reader/',
        'surv_hdd' => '/surveillance|skyhawk/',
        'doorbell' => '/door ?bell|video door|\bvdp\b/', 'cctv_psu' => '/(cctv|camera).*(power supply|smps)|(smps|power supply).*cctv|\d+\s?ch.*smps/',
        'nvr' => '/\bnvr\b|network video recorder/', 'dvr' => '/\bdvr\b(?!.*rack)|digital video recorder/',
        'cam_4g' => '/(camera|cam\b|dome|bullet).*\b4g\b|\b4g\b.*(camera|cam\b|dome|bullet)/', 'cam_wifi' => '/(camera|cam\b).*(wi-?fi|robot)|(wi-?fi).*(camera|cam\b)/',
        'cam_ip' => '/(camera|cam\b|dome|bullet).*(\bip\b|network|poe|\bipc)|(\bip\b|network).*(camera|dome|bullet)/', 'cam_hd' => '/camera|\bcam\b|\bdome\b|\bbullet\b(?!.*(cable|series))|eyeball/',
        'cctv_cable' => '/3\s*\+\s*1|copper cable/',
        'laptop' => '/laptop(?!.*(ram|adapter|adaptor|bag|stand|keyboard|cooling|power cable|hard dis|hdd))|latitude|thinkpad|ideapad/',
        'mobo' => '/motherboard/', 'cpu' => '/processor|\bcore i[3579]-\d/', 'ram' => '/\bram\b|\bddr\d/', 'ssd' => '/\bssd\b|solid state|nvme/',
        'hdd' => '/hard dis|hard drive|\bhdd\b(?!.*(caddy|enclosure))/', 'smps' => '/smps|power supply \(atx|atx power|desktop.*power supply/',
        'ups' => '/\bups\b/', 'gpu' => '/graphics card|\bgpu\b/', 'cabinet' => '/cabinet/', 'monitor' => '/monitor/', 'tv' => '/\btv\b|television/',
        'printer' => '/printer(?!.*(cable|ink bottle))/', 'toner' => '/toner|cartridge/', 'ink' => '/ink bottle|\bink\b/', 'router' => '/router|access point|range extender|\beap\d|controller/',
        'poe' => '/\bpoe\b/', 'switch' => '/(port|gigabit|ethernet).*switch/', 'hdmi' => '/hdmi (cable|extender|splitter|switch)|hdmi 2\.0 cable|hdmi cable/', 'lan' => '/lan cab|\bcat ?6\b|patch cord/',
        'combo' => '/keyboard.*mouse|combo/', 'keyboard' => '/keyboard/', 'mouse' => '/\bmouse\b(?!.*pad)/', 'pendrive' => '/pen ?drive|flash drive|jumpdrive/',
        'sdcard' => '/micro ?sd|memory card|sdhc/', 'powerbank' => '/power ?bank/', 'charger' => '/charger/', 'datacable' => '/data (&|and) charging|charging cable|data cable|lightning/',
        'speaker' => '/speaker|soundbar/', 'headphone' => '/headphone|headset|earphone|neckband|earbuds|airdopes/', 'projector' => '/projector(?!.*light)/',
        'adapter' => '/power adapter|dc adapter|smps adapter|\d+v\s?\d+(\.\d+)?a\b/', 'converter' => '/converter|adapter|to hdmi|to vga|hub/', 'tool' => '/crimp|tester|screwdriver|tool/',
    ];
    foreach ($k as $kind => $re) if (preg_match($re . 'u', $n)) return $kind === 'converter_box' ? 'converter' : $kind;
    return 'other';
}

/** Facts read from the name: [label, value, filter key or ''] in display order. */
/** The owner's pasted Specifications (from a box / datasheet) -> [[label, value], ...].
 *  Takes "Key: Value", "Key<TAB>Value", "Key | Value" lines, or label and value on
 *  alternate lines (as copied from a website table). Header rows are skipped. */
function ic_specs_parse($text) {
    $lines = array_values(array_filter(array_map(fn($l) => trim(preg_replace('/[ \t]*\|[ \t]*$|^\|[ \t]*/', '', $l)), preg_split('/\R/', trim((string)$text))), 'strlen'));
    $head = '/^(specifications?|specs|features?|feature\s*(\||\t|\s)\s*description|description|parameters?|details|key|value)$/i';
    $lines = array_values(array_filter($lines, fn($l) => !preg_match($head, preg_replace('/\s+/', ' ', $l))));
    $rows = [];
    $sep = '/^([^:\t|]{2,40}?)\s*(?:\t+|\s*\|\s*|:\s+|\s*:\s*(?=\S))(.+)$/u';
    $withSep = count(array_filter($lines, fn($l) => preg_match($sep, $l)));
    if ($lines && $withSep >= count($lines) / 2) {
        foreach ($lines as $l) if (preg_match($sep, $l, $m)) $rows[] = [trim($m[1]), trim($m[2])];
    } else {
        for ($i = 0; $i + 1 < count($lines); $i += 2) $rows[] = [$lines[$i], $lines[$i + 1]];
    }
    return array_values(array_filter($rows, fn($r) => mb_strlen($r[0]) <= 40 && $r[1] !== ''));
}
/** Spec table rows: the owner's own Specifications first, then what we read from
 *  the name - skipping anything the owner already gave (Illumination = Night vision...). */
function ic_spec_rows($it) {
    $own = ic_specs_parse($it['specs'] ?? '');
    $alias = ['night vision' => 'illumination|night|ir', 'weatherproof' => 'ip|protection|weather|ingress', 'compression' => 'compression|codec', 'power' => 'power', 'resolution' => 'resolution|pixel', 'lens' => 'lens', 'audio' => 'audio|mic', 'ir range' => 'illumination|ir', 'channels' => 'channel', 'technology' => 'type'];
    $have = mb_strtolower(implode(' | ', array_column($own, 0)));
    $auto = [];
    foreach (ic_facts($it) as $f) {
        $l = mb_strtolower($f[0]);
        if (strpos($have, $l) !== false || (isset($alias[$l]) && preg_match('/\b(' . $alias[$l] . ')/', $have))) continue;
        $auto[] = [$f[0], $f[1]];
    }
    return array_merge($own, $auto);
}

function ic_facts($it) {
    $sp = '';   // the owner's specs feed the same readers (filters, keywords) - "No" rows say nothing
    foreach (ic_specs_parse($it['specs'] ?? '') as [$k, $v]) if (!preg_match('/^(no|none|n\/?a|-|not supported)$/i', trim($v))) $sp .= " $k $v.";
    $n = ' ' . preg_replace('/\s+/', ' ', ($it['name'] ?? '') . ' ' . ($it['variant'] ?? '') . $sp) . ' ';
    $f = [];
    $add = function ($label, $value, $key = '') use (&$f) { foreach ($f as $x) if ($x[0] === $label) return; $f[] = [$label, $value, $key]; };
    $kind = ic_kind($it);
    $cam = strpos($kind, 'cam_') === 0 || $kind === 'doorbell';
    if (!empty($it['brand'])) $add('Brand', trim($it['brand']), 'brand');
    if (!empty($it['model'])) $add('Model', trim($it['model']));
    // pictures
    if (preg_match('/(\d+(?:\.\d+)?)\s?MP\b/i', $n, $m)) $add('Resolution', $m[1] . 'MP', 'res');
    elseif (preg_match('/\b(4K|8K)\b/i', $n, $m)) $add('Resolution', strtoupper($m[1]), 'res');
    elseif (preg_match('/full ?hd|1080p|\bFHD\b/i', $n)) $add('Resolution', 'Full HD (1080p)', 'res');
    elseif (preg_match('/\b(720p|HD Display|HD LED)\b/i', $n)) $add('Resolution', 'HD', 'res');
    if ($cam) {
        foreach (['PTZ' => '/\bPTZ\b/i', 'Pan & Tilt (PT)' => '/\bPT\b|pan ?(&|and) ?tilt/i', 'Dome' => '/\bdome\b/i', 'Bullet' => '/\bbullet\b/i', 'Eyeball / Turret' => '/eyeball|turret/i', 'Robot (indoor)' => '/robot/i', 'Video door bell' => '/door ?bell/i'] as $t => $re)
            if (preg_match($re, $n)) { $add('Camera type', $t, 'ctype'); break; }
        $add('Technology', $kind === 'cam_ip' ? 'IP (network) camera' : ($kind === 'cam_4g' ? '4G SIM camera' : ($kind === 'cam_wifi' ? 'WiFi camera' : ($kind === 'doorbell' ? 'WiFi video door bell' : 'HD analog camera (for DVR)'))), 'tech');
        if (preg_match('/full[- ]?colou?r|colou?r ?(night|vu|sense)|warm light|white light|starlight/i', $n)) $add('Night vision', preg_match('/dual light|smart dual|\bIR\b\s*(&|and|\+|\/)\s*(warm|white)/i', $n) ? 'Dual light (colour + IR)' : 'Colour night vision', 'night');
        elseif (preg_match('/dual light|\bIR\b\s*(&|and|\+|\/)\s*(warm|white)/i', $n)) $add('Night vision', 'Dual light (colour + IR)', 'night');
        elseif (preg_match('/\bIR\b|night vision|infrared|day ?& ?night/i', $n)) $add('Night vision', 'IR night vision (black & white)', 'night');
        if (preg_match('/(\d+)\s?m(?:tr)?\s?IR\b|IR.{0,8}?(\d+)\s?m\b/i', $n, $m)) $add('IR range', ($m[1] ?: $m[2]) . ' m');
        if (preg_match('/two[- ]way (audio|talk)|2 ?way audio|mic(rophone)? (&|and) speaker/i', $n)) $add('Audio', 'Two-way audio (talk & listen)', 'audio');
        elseif (preg_match('/built-?in mic|microphone|\bmic\b|audio|with sound/i', $n)) $add('Audio', 'Built-in microphone (records sound)', 'audio');
        if (preg_match('/(\d+(?:\.\d+)?)\s?mm(?:\s?\+\s?(\d+(?:\.\d+)?)\s?mm)?/i', $n, $m)) $add('Lens', $m[1] . 'mm' . (!empty($m[2]) ? ' + ' . $m[2] . 'mm' : ''));
        if (preg_match('/varifocal/i', $n)) $add('Zoom', 'Varifocal lens (adjustable)');
        elseif (preg_match('/(\d+)\s?x zoom/i', $n, $m)) $add('Zoom', $m[1] . 'x zoom');
        if (preg_match('/dual lens|quad lens/i', $n, $m)) $add('Lenses', ucwords(strtolower($m[0])));
        if (preg_match('/\bIP6[5-8]\b/i', $n, $m)) $add('Weatherproof', strtoupper($m[0]) . ' (outdoor)', 'place');
        elseif (preg_match('/outdoor|weatherproof/i', $n)) $add('Use', 'Outdoor', 'place');
        elseif (preg_match('/indoor/i', $n)) $add('Use', 'Indoor', 'place');
        if (preg_match('/human detection|motion (tracking|detection)|face detection|\bAI\b/i', $n, $m)) $add('Smart detection', ucfirst(strtolower($m[0])));
        if (preg_match('/solar/i', $n)) $add('Power', 'Solar powered');
    }
    if (preg_match('/(?<![\d.])(\d+)\s?-?(?:ch\b|channel)/i', $n, $m)) $add('Channels', $m[1] . ' channel', 'channels');
    if (preg_match('/H\.265\+?/i', $n, $m)) $add('Compression', strtoupper($m[0]));
    if (preg_match('/(\d)\s?SATA/i', $n, $m)) $add('Hard disk slots', $m[1] . ' SATA');
    if (preg_match('/without hard disk/i', $n)) $add('Hard disk', 'Not included');
    // computer parts
    if (preg_match('/\b(\d+(?:\.\d+)?)\s?(GB|TB)\b/i', $n, $m)) $add($kind === 'ram' || $kind === 'gpu' ? 'Memory' : ($kind === 'laptop' ? 'Memory / storage' : 'Capacity'), $m[1] . strtoupper($m[2]), 'cap');
    if (preg_match('/\b(DDR\d L?)\b|\b(DDR\dL?)\b/i', str_replace(' L', 'L', $n), $m)) $add('RAM type', strtoupper(trim($m[0])), 'ddr');
    if (preg_match('/(\d{4})\s?MHz/i', $n, $m)) $add('Speed', $m[1] . ' MHz');
    if ($kind === 'ram') $add('For', preg_match('/laptop|so-?dimm|\bS\)|12800S|PC3L-12800S/i', $n) ? 'Laptop' : 'Desktop', 'for');
    if (preg_match('/core (i[3579])|\b(i[3579])-\d{3,5}/i', $n, $m)) $add('Processor', 'Intel Core ' . strtolower($m[1] ?: $m[2]), 'cpu');
    elseif (preg_match('/ryzen \d/i', $n, $m)) $add('Processor', 'AMD ' . ucwords($m[0]), 'cpu');
    if (preg_match('/(\d+)(?:st|nd|rd|th) gen/i', $n, $m)) $add('Generation', $m[1] . 'th Gen');
    if (preg_match('/LGA\s?(\d{3,4})|\bAM[45]\b/i', $n, $m)) $add('Socket', strtoupper(str_replace(' ', '', $m[0])));
    if (preg_match('/m\.2|nvme/i', $n)) $add('Interface', preg_match('/nvme/i', $n) ? 'M.2 NVMe' : 'M.2', 'iface');
    elseif (preg_match('/sata/i', $n) && in_array($kind, ['ssd', 'hdd', 'surv_hdd'], true)) $add('Interface', 'SATA', 'iface');
    if (preg_match('/(2\.5|3\.5)\s?(?:-?inch|"|”|in\b)/i', $n, $m)) $add('Size', $m[1] . ' inch', 'size');
    if (preg_match('/(\d{4,5})\s?RPM/i', $n, $m)) $add('Speed', $m[1] . ' RPM');
    // screens
    if (preg_match('/(\d{2}(?:\.\d)?)\s?(?:-?inch|"|”|\'\'| inch)/i', $n, $m) && in_array($kind, ['monitor', 'tv', 'laptop', 'other'], true)) $add('Screen size', $m[1] . ' inch', 'screen');
    if (preg_match('/(?<![\w.])(\d{2,3})\s?Hz\b/i', $n, $m)) $add('Refresh rate', $m[1] . ' Hz');
    if (preg_match('/\b(IPS|VA)\b/', $n, $m)) $add('Panel', $m[1]);
    if (preg_match('/touch ?screen/i', $n)) $add('Touchscreen', 'Yes');
    if (preg_match('/smart (led )?tv|android tv/i', $n)) $add('Smart TV', 'Yes (apps)');
    // power
    $pw = in_array($kind, ['adapter', 'cctv_psu', 'charger', 'poe', 'smps', 'ups', 'speaker', 'datacable', 'powerbank', 'other', 'projector'], true);
    if (preg_match('/(?<![\w.-])(\d{1,4})\s?W\b(?!\s?RMS)/', $n, $m)) $add('Power', $m[1] . ' W', 'watt');
    if (preg_match('/(\d+)\s?W RMS/i', $n, $m)) $add('Output', $m[1] . ' W RMS');
    if ($pw && preg_match('/(?<![\w.-])(\d{1,3}(?:\.\d+)?)\s?V\b/', $n, $m)) $add('Voltage', $m[1] . ' V', 'volt');
    if ($pw && preg_match('/(?<![\w.-])(\d{1,2}(?:\.\d+)?)\s?A\b(?!h)/', $n, $m)) $add('Current', $m[1] . ' A');
    if (preg_match('/(\d+(?:\.\d+)?)\s?Ah\b/i', $n, $m)) $add('Battery', $m[1] . ' Ah');
    if (preg_match('/(\d{3,4})\s?VA\b/', $n, $m)) $add('Capacity', $m[1] . ' VA', 'va');
    if (preg_match('/(\d{4,6})\s?mAh/i', $n, $m)) $add('Battery', number_format((int)$m[1]) . ' mAh', 'mah');
    if (preg_match('/100-240V|AC 230V/i', $n, $m)) $add('Input', strtoupper(str_replace('AC ', '', $m[0])) . ' AC');
    // connections
    $conn = [];
    foreach (['PoE' => '/\bPoE\b/i', 'WiFi' => '/wi-?fi(?! adapter)/i', '4G SIM' => '/\b4G\b/i', 'Bluetooth' => '/bluetooth|\bBT\b/i', 'USB 3.x' => '/usb ?3\.\d/i', 'USB 2.0' => '/usb ?2\.0/i', 'Type-C' => '/type-?c/i', 'HDMI' => '/\bHDMI\b/i', 'VGA' => '/\bVGA\b/i', 'Gigabit LAN' => '/gigabit/i', 'RJ45' => '/\bRJ45\b/i', 'Wireless 2.4GHz' => '/2\.4 ?GHz/i', 'Dual band' => '/dual band|5 ?GHz/i'] as $lab => $re)
        if (preg_match($re, $n)) $conn[] = $lab;
    if ($conn) $add('Connectivity', implode(', ', $conn), 'conn');
    if (in_array($kind, ['keyboard', 'mouse', 'combo', 'headphone', 'speaker'], true)) $add('Connection', preg_match('/wireless|bluetooth|2\.4 ?ghz|\bBT\b/i', $n) ? 'Wireless' : 'Wired', 'wired');
    if (preg_match('/(\d+)\s?-?port/i', $n, $m)) $add('Ports', $m[1] . ' ports', 'ports');
    if (preg_match('/(\d+)\s?\+\s?(\d+)\s?poe/i', $n, $m)) $add('Ports', $m[1] . ' PoE + ' . $m[2] . ' uplink', 'ports');
    if (preg_match('/(\d+)\s?(Mbps|Gbps)/i', $n, $m)) $add('Speed', $m[1] . ' ' . $m[2]);
    if (preg_match('/(\d+)\s?DPI/i', $n, $m)) $add('Sensitivity', $m[1] . ' DPI');
    if (preg_match('/(\d+(?:\.\d+)?)\s?(?:meter|metre|mtr|m)\b(?!\s?(IR|ah|hz))/i', $n, $m) && in_array($kind, ['hdmi', 'lan', 'datacable', 'converter', 'other', 'tool', 'cctv_cable'], true)) $add('Length', $m[1] . ' m', 'len');
    if (preg_match('/(\d[\d,]*)\s?pages/i', $n, $m)) $add('Page yield', $m[1] . ' pages');
    if (preg_match('/(\d+)\s?ml\b/i', $n, $m)) $add('Volume', $m[1] . ' ml');
    if (preg_match('/monochrome|black & white/i', $n)) $add('Print', 'Black & white', 'print');
    elseif (preg_match('/colou?r/i', $n) && $kind === 'printer') $add('Print', 'Colour', 'print');
    if ($kind === 'printer' && preg_match('/all-in-one|multifunction|\bMFP\b|print, scan/i', $n)) $add('Functions', 'Print, scan & copy', 'func');
    if ($kind === 'printer') $add('Printer type', preg_match('/thermal|receipt/i', $n) ? 'Thermal receipt' : (preg_match('/laser/i', $n) ? 'Laser' : (preg_match('/ink ?tank|ecotank|megatank|inkjet/i', $n) ? 'Ink tank' : 'Printer')), 'ptype');
    if (preg_match('/\bIP6[5-8]\b/i', $n, $m) && !$cam) $add('Weatherproof', strtoupper($m[0]));
    if (preg_match('/\(old\)|refurbish|used/i', $n)) $add('Condition', preg_match('/refurbish/i', $n) ? 'Refurbished' : 'Used (old stock)', 'cond');
    if ((int)($it['warranty_months'] ?? 0) > 0) $add('Warranty', (int)$it['warranty_months'] . ' months', 'warranty');
    return $f;
}

/** Plain words for each kind: [what it is, uses[], how it works, needs[], search words]. */
function ic_kind_text($kind) {
    $t = [
        'cam_hd' => ['an HD (analog) CCTV camera', ['Home, shop and office security', 'Warehouses, schools, hospitals and factories', 'Parking areas, gates and entrances', 'Watching staff, cash counters and stock'],
            'The camera sends video over a 3+1 CCTV cable (or coaxial cable with BNC and DC pins) to an HD DVR, which records it on a hard disk. The DVR connects to the internet, so you can watch live and recordings on your phone.',
            ['HD DVR (4, 8 or 16 channel)', 'Surveillance hard disk', '3+1 CCTV cable, BNC and DC pins', 'CCTV power supply (SMPS)'], ['cctv camera', 'cc camera', 'security camera', 'hd camera', 'analog camera', 'dvr camera']],
        'cam_ip' => ['an IP (network) CCTV camera', ['Offices, shops and showrooms', 'Large sites with many cameras', 'Where sharp, detailed video is needed', 'Remote viewing on phone and computer'],
            'The camera sends digital video over a LAN (Cat6) cable. With PoE, the same cable also gives it power, so no separate adapter is needed. An NVR records the video, and you watch it live on your phone.',
            ['NVR (network video recorder)', 'PoE switch', 'Cat6 LAN cable and RJ45 connectors', 'Surveillance hard disk'], ['ip camera', 'network camera', 'poe camera', 'cctv camera', 'nvr camera', 'security camera']],
        'cam_wifi' => ['a WiFi CCTV camera', ['Homes, kids and elderly care', 'Small shops and offices', 'Pets and baby monitoring', 'Places where wiring is difficult'],
            'The camera joins your home WiFi and works from its mobile app. Video is saved on a memory card in the camera (or the cloud), and you can watch and talk from anywhere.',
            ['WiFi internet at the place', 'Micro SD memory card', 'Power point near the camera'], ['wifi camera', 'wireless camera', 'home camera', 'smart camera', 'mobile camera', 'v380 camera']],
        'cam_4g' => ['a 4G SIM CCTV camera', ['Farms, fields and construction sites', 'Godowns and shops without WiFi', 'Remote houses and gates', 'Temporary sites'],
            'Put a 4G data SIM in the camera and it connects to the internet on its own — no WiFi or wiring needed. Video is saved on a memory card and watched on the mobile app from anywhere.',
            ['4G data SIM card', 'Micro SD memory card', 'Power point or solar panel'], ['4g camera', 'sim camera', '4g sim cctv', 'without wifi camera', 'farm camera', 'solar camera']],
        'doorbell' => ['a WiFi video door bell', ['Home main door', 'Flats and bungalows', 'Office entrance'], 'When someone presses the bell, you get a video call on your phone, can see and talk to the visitor, and it records motion at the door.', ['Home WiFi', 'Mobile app'], ['video door bell', 'smart doorbell', 'door camera', 'vdp']],
        'dvr' => ['an HD DVR (digital video recorder)', ['Recording HD CCTV cameras', 'Shops, homes, offices and factories'], 'HD cameras connect to the DVR with 3+1 or coaxial cable. The DVR records all cameras on a hard disk, shows them on a TV or monitor (HDMI/VGA), and lets you watch on your phone over the internet.', ['HD CCTV cameras', 'Surveillance hard disk', 'Monitor or TV', 'Internet (LAN) for phone viewing'], ['dvr', 'cctv dvr', 'digital video recorder', 'xvr', 'camera recorder']],
        'nvr' => ['an NVR (network video recorder)', ['Recording IP cameras', 'Offices, schools and big sites'], 'IP cameras connect to the NVR through a PoE switch or the network. The NVR records them on a hard disk, shows them on a monitor and on your phone.', ['IP cameras', 'PoE switch', 'Surveillance hard disk', 'Monitor or TV'], ['nvr', 'network video recorder', 'ip camera recorder', 'poe nvr']],
        'surv_hdd' => ['a surveillance hard disk for CCTV recording', ['DVR and NVR recording', '24×7 CCTV use'], 'Fitted inside the DVR or NVR, it stores the camera recordings. Surveillance disks are made to write video day and night.', ['DVR or NVR'], ['cctv hard disk', 'surveillance hdd', 'dvr hard disk', 'nvr hard disk', 'recording hard disk']],
        'cctv_psu' => ['a CCTV power supply (SMPS)', ['Powering many CCTV cameras from one box'], 'One SMPS gives 12V DC power to several cameras through the 3+1 cable, so each camera does not need its own adapter.', ['3+1 CCTV cable', 'DC pins'], ['cctv smps', 'cctv power supply', 'camera power supply', '12v smps']],
        'cctv_cable' => ['CCTV cable', ['Connecting HD cameras to the DVR'], 'The 3+1 cable carries video (coaxial core) and 12V power (3 wires) to the camera in one run.', ['BNC and DC pins', 'Cable clips'], ['3+1 cable', 'cctv wire', 'camera cable', 'coaxial cable']],
        'cctv_acc' => ['a CCTV installation accessory', ['Neat and safe CCTV wiring'], 'Used while fitting cameras: connectors join the cables, boxes and racks keep the joints and the recorder safe.', [], ['cctv accessories', 'bnc connector', 'dc pin', 'junction box']],
        'laptop' => ['a laptop', ['Office work, billing and accounts', 'Students and online classes', 'Internet, video calls and movies'], 'Ready to use with its own screen, keyboard and battery — charge it and work anywhere.', ['Laptop charger', 'Bag and mouse (optional)'], ['laptop', 'notebook', 'second hand laptop', 'used laptop', 'refurbished laptop', 'office laptop']],
        'ram' => ['RAM (memory) for computers', ['Making a slow computer faster', 'Running more programs at once', 'Upgrading desktops and laptops'], 'RAM fits in the memory slot on the motherboard. More RAM lets the computer keep more work open without slowing down. The RAM type (DDR3/DDR4) must match the motherboard.', ['A matching free RAM slot'], ['ram', 'memory', 'desktop ram', 'laptop ram', 'ram upgrade', 'ddr4 ram']],
        'ssd' => ['an SSD (solid state drive)', ['Making a computer start and run much faster', 'Upgrading old hard disks', 'Fast storage for laptops and desktops'], 'An SSD stores Windows, programs and files on chips instead of a spinning disk, so the computer starts in seconds. It fits the same SATA or M.2 slot.', ['SATA or M.2 slot in the computer'], ['ssd', 'solid state drive', 'ssd upgrade', 'fast ssd', 'sata ssd', 'nvme ssd']],
        'hdd' => ['a hard disk drive', ['Storing files, photos and backups', 'Desktop, laptop and CCTV storage'], 'The hard disk fits inside the computer (or connects by USB when external) and keeps your files.', ['SATA port or USB port'], ['hard disk', 'hdd', 'internal hard disk', 'external hard disk']],
        'cpu' => ['a desktop processor (CPU)', ['Building or upgrading a desktop computer'], 'The processor fits in the motherboard socket; the socket type must match.', ['Matching motherboard', 'CPU fan'], ['processor', 'cpu', 'intel processor', 'desktop processor']],
        'mobo' => ['a desktop motherboard', ['Building or repairing a desktop computer'], 'The motherboard joins the processor, RAM, storage and ports. The CPU socket and RAM type must match.', ['Matching processor', 'Matching RAM', 'SMPS and cabinet'], ['motherboard', 'desktop motherboard', 'mother board']],
        'smps' => ['a desktop power supply (SMPS)', ['Powering a desktop computer'], 'The SMPS converts home electricity into the power the computer parts need.', ['Desktop cabinet'], ['smps', 'power supply', 'desktop smps', 'atx power supply']],
        'ups' => ['a UPS / UPS battery', ['Keeping computers on during a power cut', 'Protecting from sudden shutdown'], 'When the power goes, the UPS battery keeps the computer running for a few minutes so you can save work.', [], ['ups', 'ups battery', 'power backup', 'computer ups']],
        'gpu' => ['a graphics card', ['Better display and graphics on a desktop'], 'Fits in the PCIe slot of the motherboard and drives the monitor.', ['Free PCIe slot'], ['graphics card', 'gpu', 'video card']],
        'cabinet' => ['a computer cabinet / fan', ['Building a desktop computer'], 'Holds the motherboard, SMPS and drives and keeps them cool.', [], ['cabinet', 'computer cabinet', 'pc case']],
        'monitor' => ['a computer monitor', ['Desktop computers, CCTV DVR/NVR display', 'Office and billing counters'], 'Connects to a computer, DVR or NVR with HDMI or VGA.', ['HDMI or VGA cable'], ['monitor', 'led monitor', 'computer monitor', 'cctv monitor']],
        'tv' => ['a TV / streaming device', ['Home entertainment', 'Showing CCTV cameras on a big screen'], 'Connect to WiFi for apps, or to a DVR/NVR with HDMI.', ['WiFi internet'], ['led tv', 'smart tv', 'android tv']],
        'printer' => ['a printer', ['Home, office and shop printing', 'Bills, documents and photocopies'], 'Connects by USB (or WiFi where the name says so) and prints from a computer or phone.', ['Ink, toner or paper roll', 'USB printer cable'], ['printer', 'office printer', 'home printer']],
        'toner' => ['a toner cartridge', ['Refilling laser printers'], 'Fits in the matching laser printer model and prints black pages.', ['Compatible laser printer'], ['toner', 'cartridge', 'laser toner', 'printer cartridge', 'toner refill']],
        'ink' => ['printer ink', ['Refilling ink tank printers'], 'Pour into the matching colour tank of the printer.', ['Compatible ink tank printer'], ['printer ink', 'ink bottle', 'ink refill']],
        'router' => ['a WiFi router / access point', ['Home and office WiFi', 'Covering big areas with WiFi'], 'Connects to your internet line (or SIM, where the name says so) and gives WiFi to phones, laptops and cameras.', ['Internet connection'], ['router', 'wifi router', 'access point', 'wifi device']],
        'poe' => ['a PoE switch / injector', ['Powering IP cameras and access points over LAN cable'], 'Sends data and power through one Cat6 cable, so IP cameras need no separate adapter.', ['Cat6 LAN cable', 'PoE devices'], ['poe switch', 'poe injector', 'ip camera switch']],
        'switch' => ['a network switch', ['Connecting computers, printers and cameras in an office'], 'Gives more LAN ports from one internet connection.', ['LAN cables'], ['network switch', 'lan switch', 'ethernet switch', 'gigabit switch']],
        'lan' => ['a LAN cable', ['Internet and network wiring', 'IP camera wiring'], 'Carries internet/network between devices; used with RJ45 connectors.', ['RJ45 connectors', 'Crimping tool'], ['lan cable', 'cat6 cable', 'network cable', 'internet cable']],
        'hdmi' => ['an HDMI cable / accessory', ['Connecting computers, DVRs and set-top boxes to a TV or monitor'], 'Carries picture and sound on one cable.', [], ['hdmi cable', 'hdmi', 'tv cable']],
        'combo' => ['a keyboard and mouse combo', ['Office, home and billing computers'], 'Plug the receiver or cable into a USB port and start typing.', ['USB port'], ['keyboard mouse combo', 'wireless keyboard mouse', 'keyboard and mouse']],
        'keyboard' => ['a keyboard', ['Office, home and billing computers'], 'Connects by USB or Bluetooth and works without setup.', [], ['keyboard', 'computer keyboard', 'usb keyboard']],
        'mouse' => ['a computer mouse', ['Office, home, billing and gaming'], 'Connects by USB (or a small wireless receiver) and works without setup.', [], ['mouse', 'computer mouse', 'usb mouse', 'wireless mouse']],
        'pendrive' => ['a USB pen drive', ['Carrying files, photos and movies', 'Backups and printing from a shop'], 'Plug into any USB port to copy files.', [], ['pendrive', 'pen drive', 'usb drive', 'flash drive']],
        'sdcard' => ['a memory card', ['WiFi/4G cameras, phones and DVR backup'], 'Insert into the device slot; it stores recordings and files.', [], ['memory card', 'micro sd card', 'sd card', 'camera memory card']],
        'powerbank' => ['a power bank', ['Charging phones on the go'], 'Charge it at home, then charge phones and gadgets anywhere.', [], ['power bank', 'mobile power bank']],
        'charger' => ['a charger', ['Charging phones and gadgets'], 'Plugs into the wall and charges through the matching cable.', [], ['charger', 'mobile charger', 'fast charger']],
        'datacable' => ['a data & charging cable', ['Charging and data transfer for phones'], 'Connects the phone to a charger or computer.', [], ['data cable', 'charging cable', 'type c cable']],
        'speaker' => ['a speaker', ['Music, TV and computers'], 'Connects by Bluetooth, AUX or USB as the name says.', [], ['speaker', 'bluetooth speaker']],
        'headphone' => ['headphones / earphones', ['Calls, music, online classes and gaming'], 'Connects by Bluetooth or a cable as the name says.', [], ['headphone', 'earphone', 'neckband', 'earbuds']],
        'projector' => ['a projector', ['Movies, classes and presentations on a big screen'], 'Connects by HDMI, USB or WiFi as the name says and shows the picture on a wall or screen.', ['Screen or white wall'], ['projector', 'home projector', 'mini projector']],
        'adapter' => ['a DC power adapter', ['Powering CCTV cameras, routers and small devices'], 'Converts home electricity to the DC voltage written on it; match the voltage and pin of the device.', [], ['adapter', 'power adapter', 'dc adapter', '12v adapter']],
        'converter' => ['a converter / adapter / hub', ['Connecting devices with different ports'], 'Plugs between two devices so different ports can work together.', [], ['converter', 'adapter', 'usb hub']],
        'tool' => ['an installation tool', ['Network and CCTV installation work'], 'Used by technicians while fitting cables and connectors.', [], ['tool', 'crimping tool', 'cable tester']],
        'other' => ['a computer / electronics product', ['Home, office and shop use'], '', [], []],
    ];
    return $t[$kind] ?? $t['other'];
}

/** The full description: intro, key features, uses, how it works, what you need. Plain text, line breaks. */
function ic_description($it) {
    $app = setting('app_name', 'AK Computer'); $city = shop_city() ?: 'Dwarka';
    $kind = ic_kind($it); [$what, $uses, $how, $needs] = ic_kind_text($kind);
    $facts = array_values(array_filter(ic_facts($it), fn($f) => !in_array($f[0], ['Brand', 'Model'], true)));
    $rows = array_values(array_filter(ic_spec_rows($it), fn($f) => !in_array($f[0], ['Brand', 'Model'], true)));
    $name = trim(preg_replace('/\s+/', ' ', $it['name']));
    $brand = trim((string)($it['brand'] ?? ''));
    $out = [];
    $out[] = $name . ' is ' . $what . ($brand !== '' && stripos($name, $brand) === false ? ' from ' . $brand : '') . '.'
        . ($facts ? ' ' . ic_summary($facts) : '') . ' Available at ' . $app . ', ' . $city . ', with ' . ((int)($it['warranty_months'] ?? 0) > 0 ? (int)$it['warranty_months'] . ' months warranty' : 'the company warranty') . '.';
    if ($rows) { $out[] = 'Key features:'; foreach ($rows as $f) $out[] = '• ' . $f[0] . ': ' . $f[1]; }
    if ($uses) { $out[] = 'Where it is used:'; foreach ($uses as $x) $out[] = '• ' . $x; }
    if ($how !== '') { $out[] = 'How it works:'; $out[] = $how; }
    if ($needs) { $out[] = 'You may also need:'; foreach ($needs as $x) $out[] = '• ' . $x; }
    $out[] = 'Buy it at ' . $app . ' in ' . $city . ' — we help you choose' . (in_array($kind, ['cam_hd', 'cam_ip', 'cam_wifi', 'cam_4g', 'dvr', 'nvr', 'router', 'poe', 'switch', 'doorbell'], true) ? ', install it and set it up on your phone' : ' the right one') . '. Order online or on WhatsApp.';
    return implode("\n", $out);
}
function ic_summary(array $facts) {
    $p = [];
    foreach ($facts as $f) if (in_array($f[0], ['Resolution', 'Camera type', 'Night vision', 'Audio', 'Capacity', 'Memory', 'RAM type', 'Power', 'Channels', 'Ports', 'Screen size', 'Connectivity', 'Printer type', 'Functions', 'Processor', 'Connection', 'Length'], true)) $p[] = $f[1];
    return $p ? 'It offers ' . implode(', ', array_slice($p, 0, 6)) . '.' : '';
}

/** The words customers search for this product with: brand, model, type, specs,
 *  other words, Gujarati words, "price", "Dwarka". Real variations only. */
function ic_keywords($it, $max = 150) {
    $kind = ic_kind($it); $t = ic_kind_text($kind);
    $brand = mb_strtolower(trim((string)($it['brand'] ?? ''))); $model = mb_strtolower(trim((string)($it['model'] ?? '')));
    $name = mb_strtolower(trim(preg_replace('/\s*\([^)]*\)\s*/', ' ', $it['name'])));
    $types = $t[4] ?: [mb_strtolower((string)($it['cat_name'] ?? ''))];
    $facts = ic_facts($it); $fv = [];
    $short = ['audio' => fn($v) => 'audio', 'night' => fn($v) => stripos($v, 'colour') !== false || stripos($v, 'dual') !== false ? 'colour night vision' : 'night vision'];
    foreach ($facts as $f) if (in_array($f[2], ['res', 'cap', 'ddr', 'watt', 'channels', 'ports', 'screen', 'ctype', 'night', 'audio', 'for', 'cpu', 'len', 'ptype', 'va', 'mah'], true))
        $fv[] = isset($short[$f[2]]) ? $short[$f[2]]($f[1]) : mb_strtolower(preg_replace('/\s*\(.*\)$/', '', $f[1]));
    $fv = array_values(array_unique($fv));
    $city = mb_strtolower(shop_city() ?: 'dwarka');
    $towns = [$city, 'okha', 'mithapur', 'khambhalia', 'bhanvad', 'devbhumi dwarka', 'jamnagar', 'gujarat'];
    $k = [];
    $put = function ($s) use (&$k) { $s = trim(preg_replace('/\s+/', ' ', mb_strtolower((string)$s))); if ($s !== '' && mb_strlen($s) <= 80) $k[$s] = true; };
    // 1) this exact product: name, model, codes, brand + model
    $put($name);
    if ($model !== '') { $put($model); $put(str_replace(['-', ' '], '', $model)); if ($brand !== '') $put("$brand $model"); }
    if (preg_match_all('/\(([A-Za-z0-9][A-Za-z0-9\/\-\. ]{3,30})\)/', $it['name'], $m)) foreach ($m[1] as $code) if (preg_match('/\d/', $code)) { $put($code); $put(str_replace('-', '', $code)); }
    $put("$name price"); $put("$name price in india"); $put("$name $city");
    // 2) the kind of product with its brand and specs ("cp plus 2.4mp bullet camera")
    foreach ($types as $ty) {
        $put($ty); if ($brand !== '') { $put("$brand $ty"); $put("$ty $brand"); }
        foreach ($fv as $v) { $put("$v $ty"); if ($brand !== '') $put("$brand $v $ty"); }
    }
    foreach ($fv as $v) $put($v);
    // 4) buying words
    foreach ($types as $ty) {
        foreach (["$ty price", "$ty price in india", "$ty $city", "$ty shop in $city", "$ty price in $city", "best $ty", "buy $ty online", "$ty near me", "$ty for home", "$ty for shop", "$ty for office"] as $x) $put($x);
        if (in_array($kind, ['cam_hd', 'cam_ip', 'cam_wifi', 'cam_4g', 'dvr', 'nvr', 'doorbell', 'router', 'poe', 'switch', 'lan'], true)) { $put("$ty installation"); $put("$ty installation $city"); $put("$ty fitting"); }
    }
    if ($brand !== '') { $put("$brand price"); $put("$brand dealer $city"); $put("$brand $city"); $put("$brand products"); }
    // 5) the towns we serve
    foreach ($types as $ty) foreach ($towns as $town) { $put("$ty $town"); $put("$ty shop in $town"); $put("$ty price in $town"); }
    return array_slice(array_keys($k), 0, $max);
}

// ---------------------------------------------------------------------------
//  Writing it into the items (admin: Items → Product Descriptions & SEO)
// ---------------------------------------------------------------------------
/** Products that would get a description now: empty ones, or ones we wrote before and nobody changed. */
function ic_plan() {
    $auto = [];
    foreach (all('SELECT item_id, hd, hk FROM item_content_auto') as $a) $auto[(int)$a['item_id']] = [$a['hd'], $a['hk']];
    $rows = all("SELECT i.*, c.name cat_name FROM items i LEFT JOIN categories c ON c.id = i.category_id WHERE i.is_active = 1 AND i.item_type <> 'service' ORDER BY i.name");
    $out = [];
    foreach ($rows as $r) {
        $cur = trim((string)$r['description']); $curKw = trim((string)($r['search_keywords'] ?? ''));
        [$hD, $hK] = $auto[(int)$r['id']] ?? ['', ''];
        // the owner's own words are never replaced: only empty ones, or ones we wrote and nobody changed
        $mayD = $cur === '' || $hD === md5($cur); $mayK = $curKw === '' || $hK === md5($curKw);
        if (!$mayD && !$mayK) continue;
        $new = $mayD ? ic_description($r) : $cur;
        $kw = $mayK ? implode(', ', ic_keywords($r)) : $curKw;
        if ($cur === $new && $curKw === $kw) continue;
        $out[] = ['id' => (int)$r['id'], 'name' => $r['name'], 'old' => $cur, 'new' => $new, 'kw' => $kw, 'old_kw' => $curKw, 'mine' => [$mayD, $mayK]];
    }
    return $out;
}
function ic_apply() {
    $plan = ic_plan(); if (!$plan) return 0;
    $pdo = db(); $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction(); else $pdo->exec('SAVEPOINT item_content');
    try {
        q('DELETE FROM item_content_undo');   // Undo always means "the last Apply"
        foreach ($plan as $p) {
            q('INSERT INTO item_content_undo (item_id, old_desc, old_kw) VALUES (?,?,?)', [$p['id'], $p['old'], $p['old_kw']]);
            q('UPDATE items SET description = ?, search_keywords = ? WHERE id = ?', [$p['new'], $p['kw'], $p['id']]);
            q('REPLACE INTO item_content_auto (item_id, hd, hk) VALUES (?,?,?)', [$p['id'], $p['mine'][0] ? md5($p['new']) : '', $p['mine'][1] ? md5($p['kw']) : '']);
        }
        if ($own) $pdo->commit(); else $pdo->exec('RELEASE SAVEPOINT item_content');
    } catch (Exception $e) { if ($own) $pdo->rollBack(); else $pdo->exec('ROLLBACK TO SAVEPOINT item_content'); throw $e; }
    return count($plan);
}
/** After the owner saves an item: a description / search words we wrote (and
 *  nobody changed) are written again from the new name and Specifications. */
function ic_refresh($id) {
    $r = row("SELECT i.*, c.name cat_name FROM items i LEFT JOIN categories c ON c.id = i.category_id WHERE i.id = ?", [$id]);
    $a = row('SELECT hd, hk FROM item_content_auto WHERE item_id = ?', [$id]);
    if (!$r || !$a) return false;
    $d = trim((string)$r['description']); $k = trim((string)($r['search_keywords'] ?? ''));
    $mayD = $a['hd'] !== '' && $a['hd'] === md5($d); $mayK = $a['hk'] !== '' && $a['hk'] === md5($k);
    if (!$mayD && !$mayK) return false;
    $nd = $mayD ? ic_description($r) : $d; $nk = $mayK ? implode(', ', ic_keywords($r)) : $k;
    q('UPDATE items SET description = ?, search_keywords = ? WHERE id = ?', [$nd, $nk, $id]);
    q('UPDATE item_content_auto SET hd = ?, hk = ? WHERE item_id = ?', [$mayD ? md5($nd) : '', $mayK ? md5($nk) : '', $id]);
    return true;
}
/** When the last Apply ran and how many products it changed, or null. */
function ic_undo_info() { $r = row('SELECT COUNT(*) n, MAX(at) at FROM item_content_undo'); return $r && (int)$r['n'] ? $r : null; }
function ic_undo() {
    $rows = all('SELECT * FROM item_content_undo');
    foreach ($rows as $u) {
        q('UPDATE items SET description = ?, search_keywords = ? WHERE id = ?', [$u['old_desc'], $u['old_kw'], (int)$u['item_id']]);
        q('DELETE FROM item_content_auto WHERE item_id = ?', [(int)$u['item_id']]);
    }
    q('DELETE FROM item_content_undo');
    return count($rows);
}

// ---------------------------------------------------------------------------
//  Store filters ("Audio: Yes", "Resolution: 4MP", "RAM type: DDR4" ...)
// ---------------------------------------------------------------------------
/** Filter key => label, in the order they are offered. */
function ic_facet_labels() {
    return ['tech' => 'Camera technology', 'ctype' => 'Camera type', 'res' => 'Resolution', 'night' => 'Night vision', 'audio' => 'Audio', 'place' => 'Indoor / outdoor',
            'channels' => 'Channels', 'cap' => 'Capacity', 'ddr' => 'RAM type', 'for' => 'For', 'iface' => 'Interface', 'size' => 'Size', 'cpu' => 'Processor', 'screen' => 'Screen size',
            'ptype' => 'Printer type', 'func' => 'Functions', 'print' => 'Print', 'wired' => 'Connection', 'ports' => 'Ports', 'watt' => 'Power', 'len' => 'Length', 'cond' => 'Condition'];
}
/** item id => [key => value] for the filters (values shortened for chips). */
function ic_facet_map(array $items) {
    $out = [];
    foreach ($items as $it) {
        $m = [];
        foreach (ic_facts($it) as [$label, $value, $key]) {
            if (!isset(ic_facet_labels()[$key]) || isset($m[$key])) continue;
            $v = trim(preg_replace('/\s*\(.*\)$/', '', $value));
            if ($key === 'audio') $v = stripos($value, 'two-way') !== false ? 'Two-way audio' : 'With audio';
            if ($key === 'night') $v = stripos($value, 'dual') !== false ? 'Dual light' : (stripos($value, 'colour') !== false ? 'Colour night' : 'IR night');
            if ($key === 'place') $v = stripos($value, 'indoor') !== false ? 'Indoor' : 'Outdoor';
            $m[$key] = $v;
        }
        $out[(int)$it['id']] = $m;
    }
    return $out;
}

/** Description text -> HTML: "Heading:" lines become headings, "• " lines a list. */
function ic_render($text) {
    $h = ''; $inList = false;
    foreach (preg_split('/\R/', trim((string)$text)) as $ln) {
        $ln = trim($ln); if ($ln === '') continue;
        if (strpos($ln, '•') === 0) { if (!$inList) { $h .= '<ul class="ic-ul">'; $inList = true; } $h .= '<li>' . e(trim(mb_substr($ln, 1))) . '</li>'; continue; }
        if ($inList) { $h .= '</ul>'; $inList = false; }
        $h .= preg_match('/^[^.:]{3,40}:$/u', $ln) ? '<h3 class="ic-h">' . e(rtrim($ln, ':')) . '</h3>' : '<p>' . e($ln) . '</p>';
    }
    return $h . ($inList ? '</ul>' : '');
}

/** Once, at the first Update after this ships: arrange categories (CCTV items
 *  under Security) and write descriptions + search words. Both keep an Undo
 *  (Items -> Arrange Categories / Product Descriptions & SEO). */
function item_content_once() {
    // bump $ver when names / category rules / descriptions improve, so the
    // next Update runs them again (the first run's flag had no names step yet)
    $ver = '3';
    if (setting('item_content_once_ver') === $ver) return;
    set_setting('item_content_once_ver', $ver);
    set_setting('item_content_once_done', date('Y-m-d H:i:s'));
    try { require_once __DIR__ . '/item_names.php'; $m = in_apply(); log_activity('item_names_apply', "$m names (update)"); } catch (Throwable $e) { error_log('item names once: ' . $e->getMessage()); }
    try { require_once __DIR__ . '/category_plan.php'; $c = category_plan_apply(); log_activity('category_plan_apply', "$c items moved (update)"); } catch (Throwable $e) { error_log('category plan once: ' . $e->getMessage()); }
    try { $n = ic_apply(); log_activity('item_content_apply', "$n products (update)"); } catch (Throwable $e) { error_log('item content once: ' . $e->getMessage()); }
}
