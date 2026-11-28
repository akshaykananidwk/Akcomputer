<?php
// One buying-guide blog post per product on the website (/blog/{id}/...).
// Written from what we really know - the product's name, the owner's pasted
// Specifications, the price and stock today, our own similar products - plus
// plain guidance for that kind of product. Nothing is invented: no specs the
// name/specs don't give, no ratings, no fake discounts.
require_once __DIR__ . '/item_content.php';

function blog_url($it) { return base_url('blog/' . (int)$it['id'] . '/' . (seo_slug(blog_short($it['name'])) ?: 'product') . '-buying-guide'); }
function blog_index_url() { return base_url('blog'); }

/** Short name without the brackets: "CP PLUS 2.4MP Bullet Camera". */
function blog_short($name) { return trim(preg_replace('/\s+/', ' ', preg_replace('/\s*\([^()]*(\([^()]*\))?[^()]*\)\s*/', ' ', (string)$name))) ?: (string)$name; }

/** What to check before buying, for each kind of product. */
function blog_tips($kind) {
    $cam = ['Megapixels (2MP, 4MP, 5MP): more MP gives a sharper picture and lets you read faces and number plates from further away.',
            'Night vision: IR gives a black & white picture at night; colour / warm light / dual light cameras show colour at night too.',
            'Audio: a camera with a built-in mic records sound; two-way audio lets you talk through it.',
            'Indoor or outdoor: for open places pick an outdoor camera (IP66 / IP67 weatherproof).',
            'Lens: 2.8mm covers a wider area, 3.6mm is a good all-round choice, 6mm sees further but narrower.'];
    $t = [
        'cam' => $cam,
        'dvr' => ['Channels: count your cameras and leave 1-2 spare (4, 8 or 16 channel).', 'Camera support: the DVR must support the megapixels of your cameras.', 'Hard disk: pick the size by days of recording you want (1TB is about 2-3 weeks for 4 cameras at 2MP).', 'H.265 compression saves hard disk space.'],
        'nvr' => ['Channels: count your IP cameras and leave room to add more.', 'PoE: an NVR with PoE ports powers cameras directly; otherwise use a PoE switch.', 'Resolution: check the NVR supports 4MP / 4K if your cameras are 4MP or more.', 'Hard disk slots: more slots means longer recording.'],
        'surv_hdd' => ['Use a surveillance hard disk (SkyHawk / Purple type) for DVR/NVR: it is made for 24×7 recording.', 'Size: 1TB for a small setup, 2TB-4TB for 8-16 cameras or longer recording.', 'Check your DVR/NVR supports the size you choose.'],
        'cctv_psu' => ['Channels: pick a power supply with at least as many outputs as cameras.', 'Amps: each camera needs about 0.5-1A at 12V; leave some spare.', 'Keep it in a ventilated place, away from water.'],
        'adapter' => ['Match the voltage (for example 12V) and the pin size of your device.', 'Amps on the adapter must be equal to or more than the device needs.', 'For CCTV, a good adapter avoids picture lines and restarts.'],
        'cctv_cable' => ['Measure the distance from each camera to the DVR and add some extra.', 'Copper cable gives a better picture over long runs than CCA.', 'Use matching BNC and DC connectors for clean joints.'],
        'lan' => ['Cat6 is best for new wiring and IP cameras; Cat5e is fine for short runs.', 'Pure copper cable carries PoE power and data more reliably.', 'Keep a single run under 100 metres.'],
        'laptop' => ['Processor: Core i3 / Ryzen 3 for office work, i5 / Ryzen 5 or more for heavy work.', 'RAM: 8GB is the minimum today; 16GB for multitasking.', 'Storage: an SSD makes the laptop start and open programs much faster.', 'Screen size and weight if you carry it every day.'],
        'ram' => ['Type: DDR3, DDR4 and DDR5 do not fit each other - match your computer.', 'Desktop RAM and laptop RAM (SODIMM) are different sizes.', 'Speed (MHz): pick what your motherboard supports.', 'Bring your computer or its model name - we check before fitting.'],
        'ssd' => ['Type: 2.5" SATA SSD fits almost every computer; M.2 NVMe is faster but needs an M.2 slot.', 'Size: 256GB for Windows and office, 512GB or more for many files.', 'Moving Windows to the SSD gives the biggest speed jump.'],
        'hdd' => ['Size: pick by how many files, photos and backups you keep.', '3.5" is for desktops, 2.5" for laptops.', 'For CCTV use a surveillance hard disk instead.'],
        'printer' => ['Ink tank printers have the lowest cost per page for colour printing.', 'Laser printers are best for lots of black & white pages.', 'Need scan and copy too? Pick an all-in-one.', 'WiFi lets you print from phones.'],
        'toner' => ['Check the cartridge number matches your printer model.', 'Compatible cartridges cost less; original ones are from the printer company.', 'Page yield tells you roughly how many pages one cartridge prints.'],
        'ink' => ['Match the ink to your printer brand and model.', 'Fill the right colour in the right tank.'],
        'router' => ['Single band is enough for a small home; dual band (2.4 + 5GHz) is faster and less crowded.', 'Check the speed (Mbps) is more than your internet plan.', 'For a big house or office, use more antennas or an access point.'],
        'switch' => ['Ports: count your devices and keep spare ports.', 'Gigabit ports are faster than 10/100.', 'For IP cameras choose a PoE switch.'],
        'poe' => ['Count the cameras and check the total PoE power (watts).', 'Gigabit uplink is better for 4MP+ cameras.'],
        'monitor' => ['Size: 19-22" for billing and office, 24" or more for design and CCTV viewing.', 'Inputs: HDMI and VGA - check what your computer or DVR has.', 'Full HD (1080p) gives a sharp picture.'],
        'ups' => ['VA rating: 600VA for one computer, more for more devices.', 'Backup time depends on the battery and the load.'],
        'keyboard' => ['Wired is simple and never needs batteries; wireless keeps the desk clean.', 'Check the keyboard layout and keys you use most.'],
        'mouse' => ['Wired or wireless (USB receiver or Bluetooth).', 'Size and grip: pick what feels right in your hand.'],
        'combo' => ['One USB receiver for both keyboard and mouse saves a port.', 'Check the battery type and life.'],
        'pendrive' => ['Size by how much you carry: 32GB-64GB for most people.', 'USB 3.0 copies much faster (on a USB 3.0 port).'],
        'sdcard' => ['For cameras and CCTV use a high-endurance card.', 'Class 10 / U1 or better for video.', 'Check the maximum size your device supports.'],
        'charger' => ['Watts: match or exceed what your phone supports for fast charging.', 'Port: USB-A or Type-C - match your cable.'],
        'datacable' => ['Connector: Type-C, Micro USB (V8) or Lightning - match your phone.', 'Braided cables last longer.'],
        'headphone' => ['Wired or Bluetooth; for Bluetooth check the battery hours.', 'A mic is needed for calls and online classes.'],
        'speaker' => ['Bluetooth for phones, USB / AUX for computers and TV.', 'Watts give an idea of loudness.'],
    ];
    if (strpos($kind, 'cam_') === 0 || $kind === 'doorbell') return $t['cam'];
    return $t[$kind] ?? ['Check the model number and that it suits your device.', 'Ask us on WhatsApp - we tell you honestly if it fits your need.', 'Keep the bill: it is your warranty proof.'];
}

/** Everything a post shows. $similar = other products of the same category (rows from store_items()). */
function blog_post($it, array $similar = []) {
    $c = store_ctx(); $app = $c['app']; $city = shop_city() ?: 'Dwarka'; $brandName = "$app $city";
    $kind = ic_kind($it); [$what, $uses, $how, $needs] = ic_kind_text($kind);
    $short = blog_short($it['name']); $brand = trim((string)($it['brand'] ?? ''));
    $rows = array_values(array_filter(ic_spec_rows($it), fn($f) => !in_array($f[0], ['Brand', 'Warranty'], true)));
    $facts = array_values(array_filter(ic_facts($it), fn($f) => !in_array($f[0], ['Brand', 'Model'], true)));
    $price = dealer_price($it['selling_price'], $c['pct']);
    [$sk] = store_stock($it);
    $wm = (int)($it['warranty_months'] ?? 0);
    $install = in_array($kind, ['cam_hd', 'cam_ip', 'cam_wifi', 'cam_4g', 'dvr', 'nvr', 'router', 'poe', 'switch', 'doorbell', 'lan', 'cctv_cable'], true) && $c['own'];
    $month = date('F Y');
    $p = [];
    $p['title'] = $short . ' – Price, Specifications & Buying Guide | ' . $brandName;
    $p['h1'] = $short . ': Price, Specifications & Buying Guide';
    $p['desc'] = mb_substr("$short buying guide by $brandName: price ₹" . money($price) . " ($month), specifications, uses, how it works, what else you need and tips before you buy.", 0, 160);
    $sum = $facts ? ic_summary($facts) : '';
    $p['intro'] = "Looking for the $short" . ($brand !== '' && stripos($short, $brand) === false ? " by $brand" : '') . "? At $brandName we sell it at ₹" . money($price)
        . ($sk === 'out' ? ' (available on order)' : '') . '. This guide explains what it is, its specifications, where it is used and what to check before you buy, so you choose the right product the first time.';
    $s = [];
    $s[] = ['What is the ' . $short . '?', [ucfirst($short) . ' is ' . $what . ($brand !== '' && stripos($short, $brand) === false ? " from $brand" : '') . '.' . ($sum !== '' ? ' ' . $sum : '')
        . (!empty($it['model']) ? ' Its model number is ' . $it['model'] . ' – quote it when you order so you get exactly this one.' : '')]];
    if ($rows) $s[] = ['Specifications', [], $rows];
    if ($uses) $s[] = ['Where it is used', [], null, $uses];
    if ($how !== '') $s[] = ['How it works', [$how]];
    $s[] = ['What to check before you buy', [], null, blog_tips($kind)];
    if ($needs) $s[] = ['What else you may need', ['These usually go with it – we keep them at the shop too:'], null, $needs];
    if ($similar) $s[] = ['Compare with similar products at ' . $app, ['Prices today (' . $month . '):'], null, null, $similar];
    $why = ['Genuine products with the bill' . ($wm > 0 ? " and $wm months warranty" : ' and the company warranty') . '.',
            'Honest advice – we tell you which model suits your need and budget.',
            'Order online or on WhatsApp' . ($c['own'] ? ', or visit our shop in ' . $city : '') . '.'];
    if ($install) $why[] = 'Installation and phone setup by our technicians in ' . $city . ' and nearby areas.';
    $why[] = 'After-sales help: call or WhatsApp us whenever you need support.';
    $s[] = ['Why buy from ' . $brandName . '?', [$app . ' is a computer and CCTV shop in ' . shop_place() . '.'], null, $why];
    $p['sections'] = $s;
    $p['faq'] = [
        "What is the price of $short in $city?" => "The $short is ₹" . money($price) . " at $brandName ($month). Prices can change – check the product page or ask us on WhatsApp for today's price and dealer rates.",
        "Is the $short in stock?" => $sk === 'out' ? "It is not in stock right now – we can get it for you. Order on WhatsApp and we confirm the date." : "Yes, it is available at $brandName. Order online or on WhatsApp.",
        'Does it come with a warranty?' => $wm > 0 ? "Yes, $wm months warranty with our GST bill." : 'It comes with the company warranty, if the company gives one. Keep the bill as proof.',
    ];
    if ($install) $p['faq']["Do you install it in $city?"] = "Yes. Our technicians install and set it up, and show you how to use it on your phone.";
    $p['faq']["Where can I buy the $short near me?"] = "At $app, $city – order online on our website, on WhatsApp" . ($c['own'] ? ', or visit the shop' : '') . '.';
    return $p;
}
