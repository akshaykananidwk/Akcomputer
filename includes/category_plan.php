<?php
// Shop-style category tree (main category > sub-category), the way a big IT
// distributor's website files its catalogue, and the rules that put each of
// our products into it from its name. categories_arrange.php shows the plan
// first; nothing moves until the owner presses Apply, and Apply keeps an
// Undo snapshot. Services are never touched. A product no rule recognises
// keeps its current category.

const CP_SEP = ' > ';

/** [regex on lower-case "name brand model", 'Main > Sub'] - first match wins,
 *  so the specific rules sit above the general ones. */
function category_plan_rules() {
    $cam = '(camera|\bcam\b|\bdome\b|\bbullet\b|eyeball)';
    return [
        // --- things whose names also contain a "bigger" word: settle them first
        ['/video door|door ?bell|\bvdp\b/', 'Security > Video Door Phones (VDP)'],
        ['/web ?cam|web camera|usb.*camera.*plug/', 'Peripherals > Webcam'],
        ['/junction box|mounting rack|rack cabinet|\bdvr rack|\brack\b/', 'Security > Rack & Accessories'],
        ['/keyboard.*mouse|mouse.*keyboard|\bcombo\b/', 'Peripherals > Keyboard | Combo'],
        ['/\bmouse\b/', 'Peripherals > Mouse | Mousepad'],
        ['/laptop.*(adapter|adaptor|charger)|(adapter|adaptor|charger).*laptop/', 'Laptop > Laptop Adaptor'],
        ['/laptop.*keyboard|keyboard.*laptop/', 'Laptop > Laptop Keyboard'],
        ['/laptop.*(cooling|cooler)|cooling (pad|stand)/', 'Laptop > Laptop Cooling Pad'],
        ['/^(?=.*(laptop|latitude|thinkpad|ideapad))(?=.*(core i[3579]|ryzen|celeron|pentium|latitude \d|thinkpad|ideapad))/', 'Laptop > Branded Laptop'],
        ['/laptop.*power cable|power cable|power cord|ac printer power/', 'Cables > Power Cables'],
        ['/laptop stand|laptop.*bag|\bbag\b(?!s)(?!.*courier)/', 'Laptop > Laptop Accessories'],
        ['/hub|docking station/', 'Peripherals > USB | Type C Hub'],
        ['/caddy|enclosure|hdd.*case|card reader(?!\/writer)/', 'Storage > Casing | Copier | Card Reader'],
        ['/nfc|smart card|biometric|fingerprint|attendance/', 'Security > Biometrics'],

        // --- CCTV
        ['/surveillance|skyhawk|\bpurple\b|cctv.*(hdd|hard)/', 'Storage > Surveillance Hard Disk'],
        ['/monitor(?!.*cable)|\bled display\b/', 'Display > LED | Monitor'],
        ['/(ink tank|multifunction|laser|receipt|thermal).*printer|printer \((?!.*cable)|all-in-one.*printer/', 'Printers & Scanners > Printer | Scanner'],
        ['/\bnvr\b|network video recorder/', 'Security > IP NVR'],
        ['/\bdvr\b|digital video recorder|\bxvr\b/', 'Security > HD DVR'],
        ["/$cam.*(\\b4g\\b|wi-?fi|\\bpt\\b|ptz|pan ?(&|and) ?tilt|solar|robot)|(\\b4g\\b|wi-?fi|ptz|solar).*$cam/", 'Security > PTZ | PT | 4G | WIFI Camera'],
        ["/$cam.*(\\bip\\b|network|\\bipc|\\bpoe\\b|ss-nc|-unc-|\\bnc\\d)|(\\bip\\b|network).*$cam/", 'Security > IP Camera'],
        ["/$cam/", 'Security > HD Camera'],
        ['/3\s*\+\s*1|cctv.*cable|copper cable/', 'Cables > 3+1 CCTV Cables'],
        ['/\bbnc\b|dc pin|waterproof cap|cable (nail )?clip|cab(le|el) tie|tie closer|tag tie|cable nail/', 'Accessories CCTV & Networking'],

        // --- networking
        ['/router|access point|range extender|\beap\d|oc200|controller|\bcpe\d/', 'Networking > Router | Access Point | Range Extender'],
        ['/media converter|\bonu\b|\bolt\b|sfp module/', 'Networking > Fiber Accessories'],
        ['/converter|display ?port|\bdp\b to|to hdmi|to vga|hdmi.*to.*vga|sound card|capture|usb to|type-c to hdd|molex|sata power/', 'Connector & Converter > Converter'],
        ['/\bpoe\b|power over ethernet/', 'Networking > POE Switch'],
        ['/extender|splitter|switch box|hdmi switch|vga switch|kvm/', 'Peripherals > Extender | Switcher | Splitter'],
        ['/fib(er|re) cab/', 'Cables > Fiber Cables'],
        ['/usb.*(ethernet|lan) adapter|(ethernet|lan) adapter|usb lan/', 'Connector & Converter > Converter'],
        ['/wi-?fi adapter|wireless.*adapter|nano adapter|wi-?fi.*receiver|nano receiver(?!.*(mouse|keyboard))/', 'Networking > USB Wifi Adapter | Receiver'],
        ['/(port|gigabit|ethernet|network).*switch|switch.*(port|gigabit)/', 'Networking > Desktop Switch'],
        ['/crimp|cable tester|tool kit|screwdriver|drill/', 'Networking > Tools'],
        ['/rj ?45.*(plug|connector|coupler)|modular plug|keystone|inline coupler/', 'Connector & Converter > Connector'],
        ['/lan cab|\bcat ?6\b|cat ?5|patch cord/', 'Cables > LAN Cables (CAT6)'],

        // --- extenders / switchers / converters / hubs / spikes
        ['/otg/', 'Mobility > OTG Connectors'],
        ['/spike|extension board|surge/', 'Peripherals > Spike'],

        // --- cables
        ['/projector(?!.*light)/', 'Display > Projector | Projector Screen'],
        ['/power ?bank/', 'Mobility > Power Bank'],
        ['/smart ?watch|\bwatch\b(?!.*battery)/', 'Mobility > Smart Watch'],
        ['/neckband/', 'Mobility > Neckband'],
        ['/earbuds|airdopes|\btws\b/', 'Mobility > Earbuds TWS'],
        ['/headphone|headset/', 'Mobility > Headphone'],
        ['/earphone|in-ear/', 'Mobility > Earphones'],
        ['/speaker|soundbar|sound bar/', 'Peripherals > Speaker'],
        ['/microphone|\bmic\b/', 'Mobility > Audio Accessories'],
        ['/lightning|data (&|and) charging cable|charging.*cable|data cable|v8\)?.*cable|type-c cable|micro usb.*cable/', 'Mobility > Data Cable'],
        ['/hdmi/', 'Cables > HDMI Cables'],
        ['/vga/', 'Cables > VGA Cables'],
        ['/printer.*cable|a to b cable|usb to printer/', 'Cables > Printer Cables'],
        ['/usb.*(extension|male|female).*cable/', 'Cables > USB Male | Female Cables'],
        ['/cable/', 'Cables > Other Cables'],

        // --- mobile, audio and gadgets
        ['/charger|charging adapter|fast charg/', 'Mobility > Chargers'],

        // --- storage
        ['/portable hard|external hard|portable.*hdd/', 'Storage > External Hard Disk'],
        ['/\bssd\b|solid state|nvme/', 'Storage > Internal SSD'],
        ['/hard dis|hard drive|\bhdd\b/', 'Storage > Computer Hard Disk'],
        ['/pen ?drive|flash drive|jumpdrive/', 'Storage > Pendrive'],
        ['/micro ?sd|memory card|\bsd card|sdhc|sdxc/', 'Storage > SD | Micro SD Cards'],
        ['/dvd|\bcd\b|blu-?ray/', 'Peripherals > CD | DVD | DVD Writer'],

        // --- desktop parts
        ['/cpu.*(fan|cool)|processor.*fan|cooling fan.*cpu/', 'Desktop > CPU (Processor) Fan'],
        ['/processor|\bcpu\b|core i[3579]-\d|ryzen \d \d{4}/', 'Desktop > CPU (Processor)'],
        ['/motherboard|mother board/', 'Desktop > Motherboard'],
        ['/laptop ram|laptop.*(ddr|\bram\b)|so-?dimm/', 'Laptop > Laptop RAM'],
        ['/\bram\b|\bddr\d|udimm|dimm/', 'Desktop > Desktop RAM (Memory)'],
        ['/graphics card|\bgpu\b|graphic card/', 'Desktop > Graphics Card (GPU)'],
        ['/cabinet|cooling fan|cabinet fan/', 'Desktop > Cabinet | Cabinet Fan'],
        ['/\d{3}\s?w\b.*(smps|power supply)|(smps|power supply).*(atx|desktop)|desktop smps|atx power/', 'Desktop > Power Supply (SMPS)'],
        ['/smps|power supply|power adapter|dc adapter/', 'Security > Power Supply | Adapter'],
        ['/\bups\b/', 'Desktop > UPS | UPS Batteries'],

        // --- laptops, displays
        ['/laptop|latitude|thinkpad|ideapad|notebook|macbook|chromebook/', 'Laptop > Branded Laptop'],
        ['/wall mount|bracket|projector stand|tv mount/', 'Display > Wall Mount Kit | Projector Stand'],
        ['/monitor|\bled display\b/', 'Display > LED | Monitor'],
        ['/\btv\b|television/', 'Display > Television'],

        // --- keyboards, mice, pads
        ['/keyboard/', 'Peripherals > Keyboard | Combo'],
        ['/mouse ?pad/', 'Peripherals > Mouse | Mousepad'],
        ['/gamepad|joystick|game pad/', 'Peripherals > Gamepad'],
        ['/coin cell|cr2032|cmos|battery cell|watch battery|calculator/', 'Peripherals > Calculator | Cmos Battery | Cell'],

        // --- printing
        ['/barcode scanner|qr scanner/', 'Printers & Scanners > Barcode Scanner'],
        ['/barcode label|label.*roll|thermal paper|paper roll|ribbon/', 'Printers & Scanners > WAX Ribbon | Barcode Roll'],
        ['/\ba4\b|paper|pvc card|photo paper/', 'Printers & Scanners > Photo Paper | Paper Rim | PVC Card'],
        ['/ink bottle|\bink\b/', 'Printers & Scanners > Ink Bottle'],
        ['/toner|cartridge|refill/', 'Printers & Scanners > Toner | DMP Cartridge'],
        ['/printer|scanner|multifunction|\bmfp\b/', 'Printers & Scanners > Printer | Scanner'],

        // --- software, everything else useful
        ['/antivirus|anti-virus|quick ?heal|\bk7\b|net protector|total security|internet security/', 'Software > Antivirus'],
        ['/walkie|two-way radio|transceiver/', 'Telecom > Telephone Set'],
        ['/light|lamp|flashlight|hologram|massag|chainsaw|money bank|wooden box|courier bag|led strip|neon/', 'Mobility > Utility'],
    ];
}

/** Old category name -> new place, for products whose name says too little. */
function category_plan_by_old() {
    return [
        'HD Camera' => 'Security > HD Camera', 'IP Camera' => 'Security > IP Camera',
        'WiFi Camera' => 'Security > PTZ | PT | 4G | WIFI Camera', '4G Sim Camera' => 'Security > PTZ | PT | 4G | WIFI Camera',
        'DVR' => 'Security > HD DVR', 'NVR' => 'Security > IP NVR', 'Video Door Phone' => 'Security > Video Door Phones (VDP)',
        'Biometric & Access Control' => 'Security > Biometrics', 'Rack & Junction Box' => 'Security > Rack & Accessories',
        'SMPS & Power Supply' => 'Security > Power Supply | Adapter', 'CCTV Cable' => 'Cables > 3+1 CCTV Cables',
        'BNC & Connector' => 'Accessories CCTV & Networking', 'CCTV Accessories' => 'Accessories CCTV & Networking',
        'Patch Cord & Accessories' => 'Accessories CCTV & Networking',
        'Network Switch' => 'Networking > Desktop Switch', 'POE Switch' => 'Networking > POE Switch',
        'WiFi Router' => 'Networking > Router | Access Point | Range Extender', 'Fiber & ONU' => 'Networking > Fiber Accessories',
        'Network Cable (Cat6)' => 'Cables > LAN Cables (CAT6)', 'Power Strip & Extension' => 'Peripherals > Spike',
        'Monitor' => 'Display > LED | Monitor', 'Laptop' => 'Laptop > Branded Laptop', 'Laptop Charger' => 'Laptop > Laptop Adaptor',
        'Laptop Accessories' => 'Laptop > Laptop Accessories', 'RAM' => 'Desktop > Desktop RAM (Memory)',
        'Processor' => 'Desktop > CPU (Processor)', 'Motherboard' => 'Desktop > Motherboard',
        'Cabinet & Power Supply' => 'Desktop > Cabinet | Cabinet Fan', 'UPS' => 'Desktop > UPS | UPS Batteries',
        'UPS Battery' => 'Desktop > UPS | UPS Batteries', 'Webcam' => 'Peripherals > Webcam',
        'Keyboard & Mouse' => 'Peripherals > Keyboard | Combo', 'Speaker & Headphone' => 'Peripherals > Speaker',
        'Pendrive & Memory Card' => 'Storage > Pendrive', 'SSD & Hard Drive' => 'Storage > Internal SSD',
        'Hard Disk (HDD)' => 'Storage > Computer Hard Disk', 'Cartridge & Toner' => 'Printers & Scanners > Toner | DMP Cartridge',
        'Ink & Refill' => 'Printers & Scanners > Ink Bottle', 'Laser Printer' => 'Printers & Scanners > Printer | Scanner',
        'Ink Tank Printer' => 'Printers & Scanners > Printer | Scanner', 'Antivirus & Software' => 'Software > Antivirus',
        'Cables & Adapters' => 'Connector & Converter > Converter',
    ];
}

/** Where one product goes: 'Main > Sub', 'Main', or null (leave it alone). */
function category_plan_for(array $it) {
    if (($it['item_type'] ?? 'product') === 'service') return null;
    $txt = mb_strtolower(trim(($it['name'] ?? '') . ' ' . ($it['brand'] ?? '') . ' ' . ($it['model'] ?? '')));
    foreach (category_plan_rules() as [$re, $path]) {
        if (preg_match($re . 'u', $txt)) return $path;
    }
    return category_plan_by_old()[$it['cat_name'] ?? ''] ?? null;
}

/** Every product with its current and planned place. */
function category_plan_rows() {
    $rows = all("SELECT i.id, i.name, i.brand, i.model, i.item_type, i.is_active, i.category_id,
                        c.name cat_name, pc.name cat_parent
                 FROM items i LEFT JOIN categories c ON c.id = i.category_id LEFT JOIN categories pc ON pc.id = c.parent_id
                 WHERE i.item_type <> 'service' ORDER BY i.name");
    foreach ($rows as &$r) {
        $r['plan'] = category_plan_for($r);
        $r['now'] = $r['cat_name'] === null ? '' : (($r['cat_parent'] ? $r['cat_parent'] . CP_SEP : '') . $r['cat_name']);
    }
    return $rows;
}

/** Finds or makes 'Main > Sub' and returns the sub's id. An existing category
 *  with the same name is reused (moved under the new main one), so nothing is
 *  duplicated. $made collects ids created now, $moved old parents changed. */
function category_plan_cat_id($path, array &$made, array &$moved) {
    $id = null;
    $parent = null;
    foreach (explode(CP_SEP, $path) as $name) {
        $c = row('SELECT id, parent_id FROM categories WHERE name = ? ORDER BY (parent_id <=> ?) DESC, id LIMIT 1', [$name, $parent]);
        if (!$c) {
            q('INSERT INTO categories (name, parent_id) VALUES (?, ?)', [$name, $parent]);
            $id = (int)val('SELECT MAX(id) FROM categories');
            $made[] = $id;
        } else {
            $id = (int)$c['id'];
            if ((int)$c['parent_id'] !== (int)$parent && !array_key_exists($id, $moved)) {
                $moved[$id] = $c['parent_id'] === null ? null : (int)$c['parent_id'];
                q('UPDATE categories SET parent_id = ? WHERE id = ?', [$parent, $id]);
            }
        }
        $parent = $id;
    }
    return $id;
}

/** All-or-nothing: its own transaction, or a savepoint inside the caller's. */
function category_plan_tx() {
    $pdo = db();
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        return [fn() => $pdo->commit(), fn() => $pdo->rollBack()];
    }
    $pdo->exec('SAVEPOINT category_plan');
    return [fn() => $pdo->exec('RELEASE SAVEPOINT category_plan'), fn() => $pdo->exec('ROLLBACK TO SAVEPOINT category_plan')];
}

/** Moves every planned product. Returns how many moved. Saves an Undo. */
function category_plan_apply() {
    $made = []; $moved = []; $items = []; $ids = [];
    [$done, $fail] = category_plan_tx();
    try {
        foreach (category_plan_rows() as $r) {
            if (!$r['plan']) continue;
            $ids[$r['plan']] ??= category_plan_cat_id($r['plan'], $made, $moved);
            if ((int)$r['category_id'] === $ids[$r['plan']]) continue;
            $items[$r['id']] = $r['category_id'] === null ? null : (int)$r['category_id'];
            q('UPDATE items SET category_id = ? WHERE id = ?', [$ids[$r['plan']], $r['id']]);
        }
        set_setting('category_plan_undo', json_encode(['at' => date('Y-m-d H:i:s'), 'items' => $items, 'moved' => $moved, 'made' => $made]));
        $done();
    } catch (Exception $e) { $fail(); throw $e; }
    return count($items);
}

/** Puts every product and category back where Apply found it. */
function category_plan_undo() {
    $u = json_decode((string)setting('category_plan_undo'), true);
    if (!$u) return 0;
    [$done, $fail] = category_plan_tx();
    try {
        foreach ($u['items'] as $id => $cat) q('UPDATE items SET category_id = ? WHERE id = ?', [$cat, (int)$id]);
        foreach ($u['moved'] as $id => $par) q('UPDATE categories SET parent_id = ? WHERE id = ?', [$par, (int)$id]);
        // categories Apply created are removed only when nothing uses them now
        foreach (array_reverse($u['made']) as $id) {
            if (!val('SELECT 1 FROM items WHERE category_id = ? LIMIT 1', [$id]) && !val('SELECT 1 FROM categories WHERE parent_id = ? LIMIT 1', [$id])) {
                q('DELETE FROM categories WHERE id = ?', [$id]);
            }
        }
        set_setting('category_plan_undo', '');
        $done();
    } catch (Exception $e) { $fail(); throw $e; }
    return count($u['items']);
}
