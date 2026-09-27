<?php
// SEO layer for the public storefront.
//
// Every product gets THREE indexable pages, each targeting a different
// search intent, plus category / brand / service landing pages:
//   /p/{id}/{slug}       product.php  - buy page ("hikvision 2mp dome camera")
//   /price/{id}/{slug}   price.php    - price page ("... price", "... price in dwarka")
//   /c/{slug}            category.php - category landing ("cctv camera dwarka")
//   /b/{slug}            brand.php    - brand landing ("hikvision dealer dwarka")
//   /s/{slug}            services.php - service landing ("cctv installation dwarka")
// The pretty URLs are rewritten in .htaccess; the plain ?id= URLs keep
// working and canonical-tag to the pretty one, so nothing ever breaks.

function seo_slug($s) {
    $s = strtolower(trim((string)$s));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return trim(preg_replace('/-+/', '-', $s), '-');
}

function seo_product_url($it) {
    return base_url('p/' . (int)$it['id'] . '/' . (seo_slug($it['name'] . ' ' . ($it['brand'] ?? '') . ' ' . ($it['model'] ?? '')) ?: 'product'));
}

function seo_price_url($it) {
    return base_url('price/' . (int)$it['id'] . '/' . (seo_slug($it['name']) ?: 'product') . '-price');
}

function seo_cat_url($name)   { $s = seo_slug($name);  return $s ? base_url('c/' . $s) : base_url('catalog.php'); }
function seo_brand_url($name) { $s = seo_slug($name);  return $s ? base_url('b/' . $s) : base_url('catalog.php'); }
function seo_service_url($slug) { return base_url('s/' . $slug); }

/** Default company (address/phone) for LocalBusiness markup + footer. */
function seo_company() {
    static $co = false;
    if ($co === false) { try { $co = row('SELECT * FROM companies WHERE is_active = 1 ORDER BY id LIMIT 1'); } catch (Exception $e) { $co = null; } }
    return $co ?: ['name' => setting('app_name', 'AK Computer'), 'address' => 'Dwarka, Gujarat', 'phone' => '', 'email' => ''];
}

/** Website categories (only ones that actually have live products). */
function seo_cats() {
    static $c = null;
    if ($c === null) $c = all('SELECT c.name, COUNT(*) n, MIN(i.selling_price) pmin, MAX(i.selling_price) pmax
                               FROM items i JOIN categories c ON c.id = i.category_id
                               WHERE i.is_active = 1 AND i.show_on_website = 1
                               GROUP BY c.id, c.name ORDER BY c.name');
    return $c;
}

/** Website brands (free-text items.brand, deduped). */
function seo_brands() {
    static $b = null;
    if ($b === null) $b = all("SELECT brand name, COUNT(*) n FROM items
                               WHERE is_active = 1 AND show_on_website = 1 AND brand <> ''
                               GROUP BY brand ORDER BY n DESC, brand");
    return $b;
}

function seo_jsonld($data) {
    return '<script type="application/ld+json">' .
           json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>' . "\n";
}

/** BreadcrumbList markup: pass [['Store', url], ['CCTV', url], ['Product']] */
function seo_breadcrumbs($crumbs) {
    $items = [];
    foreach ($crumbs as $i => $c) {
        $li = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $c[0]];
        if (!empty($c[1])) $li['item'] = $c[1];
        $items[] = $li;
    }
    return seo_jsonld(['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $items]);
}

function seo_localbusiness_jsonld() {
    $co = seo_company();
    $app = setting('app_name', 'AK Computer');
    $wa = wa_normalize_number(setting('wa_shop_number'));
    return seo_jsonld([
        '@context' => 'https://schema.org', '@type' => 'ElectronicsStore',
        'name' => $app, 'url' => base_url('catalog.php'),
        'image' => base_url('assets/icon.svg'),
        'telephone' => $co['phone'] ?: ($wa ? '+' . $wa : ''),
        'email' => $co['email'] ?: setting('company_email', ''),
        'address' => ['@type' => 'PostalAddress', 'streetAddress' => (string)$co['address'],
                      'addressLocality' => 'Dwarka', 'addressRegion' => 'Gujarat', 'addressCountry' => 'IN'],
        'priceRange' => '₹₹',
        'openingHours' => 'Mo-Su 09:00-21:00',
    ]);
}

function seo_faq_jsonld($qas) {
    $main = [];
    foreach ($qas as $q => $a) {
        $main[] = ['@type' => 'Question', 'name' => $q,
                   'acceptedAnswer' => ['@type' => 'Answer', 'text' => $a]];
    }
    return seo_jsonld(['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => $main]);
}

/** Shared header + card CSS for the small public SEO pages. */
function seo_public_css() {
    return '<style>
:root { --acc1: #4f46e5; --acc2: #06b6d4; }
body { background: var(--bg); }
.shead { position: sticky; top: 0; z-index: 50; background: linear-gradient(100deg, var(--acc1), #2563eb 60%, var(--acc2)); color: #fff;
  padding: 10px 14px; display: flex; align-items: center; gap: 10px; box-shadow: 0 2px 12px rgba(37,99,235,.35); }
.shead .logo { font-size: 18px; font-weight: 800; color: #fff; text-decoration: none; }
.shead .back { margin-left: auto; background: rgba(255,255,255,.18); border: 1px solid rgba(255,255,255,.45); color: #fff;
  border-radius: 10px; padding: 8px 12px; font-size: 13px; font-weight: 700; text-decoration: none; white-space: nowrap; }
.swrap { max-width: 1100px; margin: 16px auto 30px; padding: 0 12px; }
.scard { background: var(--card); color: var(--text); border-radius: 16px; padding: 20px 18px; box-shadow: 0 2px 10px rgba(0,0,0,.1); margin-bottom: 16px; }
.scard h1 { font-size: 22px; } .scard h2 { font-size: 17px; margin-bottom: 8px; }
.scard p { line-height: 1.6; margin-top: 8px; font-size: 14.5px; }
.pgrid { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 12px; margin-top: 12px; }
.pcard { background: var(--card); color: var(--text); border-radius: 12px; overflow: hidden; box-shadow: 0 1px 4px rgba(0,0,0,.08); text-decoration: none; display: block; transition: transform .15s; }
.pcard:hover { transform: translateY(-3px); }
.pcard img { width: 100%; height: 120px; object-fit: contain; background: #fff; padding: 4px; }
.pcard .ph { height: 120px; display: flex; align-items: center; justify-content: center; font-size: 38px; background: var(--bg); }
.pcard .pb { padding: 8px 10px; } .pcard .pn { font-size: 13px; font-weight: 600; } .pcard .pp { color: var(--primary); font-weight: 800; margin-top: 3px; }
.crumbs { font-size: 12.5px; color: var(--muted); margin: 10px auto 0; max-width: 1100px; padding: 0 12px; }
.crumbs a { color: var(--acc1); text-decoration: none; }
.chips { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 10px; }
.chips a { background: var(--bg); color: var(--text); border-radius: 999px; padding: 6px 13px; font-size: 13px; font-weight: 600; text-decoration: none; }
.chips a:hover { background: var(--acc1); color: #fff; }
.seofoot { background: var(--card); color: var(--text); margin-top: 24px; padding: 22px 14px 80px; font-size: 13.5px; border-top: 1px solid rgba(128,128,128,.15); }
.seofoot .in { max-width: 1100px; margin: 0 auto; display: grid; gap: 18px; grid-template-columns: 1fr; }
@media (min-width: 800px) { .seofoot .in { grid-template-columns: 1fr 1fr 1fr 1.2fr; } }
.seofoot h3 { font-size: 13px; text-transform: uppercase; letter-spacing: .4px; color: var(--muted); margin-bottom: 8px; }
.seofoot a { display: block; color: var(--text); text-decoration: none; padding: 3px 0; }
.seofoot a:hover { color: var(--acc1); }
.seofoot .cop { max-width: 1100px; margin: 16px auto 0; color: var(--muted); font-size: 12px; }
.ptable { width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 14px; }
.ptable th, .ptable td { text-align: left; padding: 9px 10px; border-bottom: 1px solid rgba(128,128,128,.18); }
.ptable td.num, .ptable th.num { text-align: right; white-space: nowrap; }
.ptable a { color: var(--acc1); text-decoration: none; font-weight: 600; }
.faq details { margin-top: 8px; background: var(--bg); border-radius: 10px; padding: 10px 12px; }
.faq summary { font-weight: 700; cursor: pointer; font-size: 14px; }
.faq p { margin-top: 6px; }
</style>';
}

function seo_public_header() {
    $app = setting('app_name', 'AK Computer');
    return '<header class="shead"><a class="logo" href="' . e(base_url('catalog.php')) . '">🖥️ ' . e($app) . '</a>'
         . '<a class="back" href="' . e(base_url('catalog.php')) . '">🛒 Store</a></header>';
}

/** Crawlable footer with links to every category / brand / service page -
 *  this internal linking is what lets Google find all 1000+ pages. */
function seo_footer() {
    $co = seo_company();
    $app = setting('app_name', 'AK Computer');
    $wa = wa_normalize_number(setting('wa_shop_number'));
    $h = '<footer class="seofoot"><div class="in">';
    $h .= '<div><h3>Categories</h3>';
    foreach (seo_cats() as $c) {
        if (seo_slug($c['name']) === '') continue;
        $h .= '<a href="' . e(seo_cat_url($c['name'])) . '">' . e(cat_icon($c['name']) . ' ' . $c['name']) . ' (' . (int)$c['n'] . ')</a>';
    }
    $h .= '</div><div><h3>Brands</h3>';
    foreach (array_slice(seo_brands(), 0, 20) as $b) {
        if (seo_slug($b['name']) === '') continue;
        $h .= '<a href="' . e(seo_brand_url($b['name'])) . '">' . e($b['name']) . '</a>';
    }
    $h .= '</div><div><h3>Services</h3>';
    foreach (seo_services() as $slug => $sv) {
        $h .= '<a href="' . e(seo_service_url($slug)) . '">' . $sv['emoji'] . ' ' . e($sv['name']) . '</a>';
    }
    $h .= '</div><div><h3>' . e($app) . ' — Dwarka, Gujarat</h3>';
    $h .= '<p>📍 ' . e($co['address'] ?: 'Dwarka, Gujarat') . '</p>';
    if ($co['phone']) $h .= '<p>📞 <a href="tel:' . e(preg_replace('/\D/', '', $co['phone'])) . '" style="display:inline">' . e($co['phone']) . '</a></p>';
    if ($wa && strlen($wa) >= 12) $h .= '<p>💬 <a href="https://wa.me/' . e($wa) . '" style="display:inline" rel="noopener">WhatsApp Order</a></p>';
    $h .= '<p style="margin-top:8px">Computer, Laptop, CCTV Camera, Printer — sales, repair &amp; installation in Dwarka, Gujarat. Genuine products with warranty &amp; doorstep service.</p>';
    $h .= '</div></div>';
    $h .= '<div class="cop">© ' . date('Y') . ' ' . e($app) . ', Dwarka · <a style="display:inline" href="' . e(base_url('') . '/') . '">Online Store</a> · <a style="display:inline" href="' . e(seo_service_url('cctv-installation')) . '">CCTV Installation</a> · <a style="display:inline" href="' . e(seo_service_url('computer-repair')) . '">Computer Repair</a> · <a style="display:inline" href="' . e(base_url('privacy.php')) . '">Privacy Policy</a> · <a style="display:inline" href="' . e(base_url('terms.php')) . '">Terms</a></div>';
    return $h . '</footer>';
}

/** Local-service landing pages: real content, one page per service keyword. */
function seo_services() {
    $app = setting('app_name', 'AK Computer');
    return [
        'cctv-installation' => [
            'name' => 'CCTV Camera Installation', 'emoji' => '📹',
            'title' => 'CCTV Camera Installation in Dwarka, Gujarat — Best Price | ' . $app,
            'desc' => 'CCTV camera installation in Dwarka at best price. HD & IP cameras, DVR/NVR setup, mobile viewing, wiring, warranty and quick service. Free site visit — WhatsApp us.',
            'paras' => [
                'CCTV camera setup for a home, shop, office, school or factory — we do it all, from the survey and wiring to installation and setting up live view on your phone. Both HD and IP cameras, branded DVR/NVR, and only genuine products.',
                'Free site visit in Dwarka and nearby. Service and warranty support after installation are our responsibility too. We repair and upgrade older systems as well.',
            ],
            'points' => ['HD / IP cameras — 2MP to 8MP', 'DVR / NVR + hard disk setup', 'Live view on your phone from anywhere', 'A complete package with wiring', 'Warranty + after-sales service'],
            'faqs' => [
                'What does installing CCTV cameras cost?' => 'The price depends on the number of cameras and the quality (2MP/5MP, HD/IP). Send us the details of the place on WhatsApp for a free quotation.',
                'Can I see the cameras on my phone?' => 'Yes, we set up the mobile app on every system — you can watch live from anywhere in the world.',
            ]],
        'computer-repair' => [
            'name' => 'Computer & Desktop Repair', 'emoji' => '🖥️',
            'title' => 'Computer Repair in Dwarka — Desktop PC Repair & Upgrade | ' . $app,
            'desc' => 'Computer and desktop repair in Dwarka, Gujarat. Slow PC, no display, virus, hardware upgrade, SSD/RAM, formatting with data safety. Same-day service at ' . $app . '.',
            'paras' => [
                'Computer not starting? Running slow? No display? — for desktop computer repairs of every kind ' . $app . ' bring it in. Most work is done the same day.',
                'An SSD or RAM upgrade makes even an old computer as fast as a new one. When formatting, we always save your data first.',
            ],
            'points' => ['No display / dead PC repair', 'SSD + RAM upgrade — double the speed', 'Virus cleaning + Windows setup', 'New and used computers bought and sold', 'We try to deliver the same day'],
            'faqs' => [
                'The computer is slow, what should I do?' => 'Most often an SSD and RAM upgrade makes a computer 5 to 10 times faster. We check it and advise you honestly.',
                'Will my data be safe?' => 'Yes, we take care of a data backup before any work.',
            ]],
        'laptop-repair' => [
            'name' => 'Laptop Repair', 'emoji' => '💻',
            'title' => 'Laptop Repair in Dwarka, Gujarat — Screen, Battery, Keyboard | ' . $app,
            'desc' => 'Laptop repair in Dwarka: broken screen replacement, battery, keyboard, hinge, charging port, SSD upgrade, chip-level service. All brands — HP, Dell, Lenovo, Acer, Asus.',
            'paras' => [
                'Broken laptop screen, dead battery, bad keyboard or not charging? HP, Dell, Lenovo, Acer, Asus — we repair laptops of every brand.',
                'Genuine quality parts and a guarantee on the work. If a laptop is slow, get an SSD upgrade — the cheapest and most effective fix.',
            ],
            'points' => ['Screen replacement', 'Battery / keyboard / hinge repair', 'Charging port and chip-level work', 'SSD upgrade + Windows', 'New and used laptops bought and sold'],
            'faqs' => [
                'What does replacing a laptop screen cost?' => 'The price depends on the size and model. WhatsApp the model number and we will quote at once.',
            ]],
        'printer-repair' => [
            'name' => 'Printer Sales & Repair', 'emoji' => '🖨️',
            'title' => 'Printer Repair & Sales in Dwarka — Ink Tank, Laser, Cartridge | ' . $app,
            'desc' => 'Printer repair, sales, ink refilling and cartridge in Dwarka, Gujarat. HP, Canon, Epson service, paper jam, print quality issues — quick turnaround at ' . $app . '.',
            'paras' => [
                'Printer not printing, paper jamming, or prints coming out faint? HP, Canon, Epson — we repair every printer. Ink refilling and cartridges available too.',
                'If you want a new printer we will advise you properly for your use (home / shop / office) and give you the best price.',
            ],
            'points' => ['Printer repair, every brand', 'Ink tank and cartridge refilling', 'New printers at the best price', 'Printer setup + WiFi printing'],
            'faqs' => [
                'Which printer is good for home?' => 'For light use an ink tank printer works out cheapest — the cost per print is far lower. Come to the shop and we will demonstrate it.',
            ]],
        'networking' => [
            'name' => 'Networking & WiFi Solutions', 'emoji' => '📡',
            'title' => 'Networking & WiFi Setup in Dwarka — Router, LAN, Office Network | ' . $app,
            'desc' => 'WiFi router setup, office LAN networking, structured cabling, range extension and network troubleshooting in Dwarka, Gujarat by ' . $app . '.',
            'paras' => [
                'WiFi slow at home or the office, or not reaching some rooms? Router setup, range extenders, office LAN wiring — we do all networking work.',
                'For a shop or office we connect CCTV, computers and printers on one network — sharing and backups become easy.',
            ],
            'points' => ['WiFi router setup + range solutions', 'Office LAN cabling', 'File / printer sharing setup', 'Network troubleshooting'],
            'faqs' => [
                'How do I extend WiFi range?' => 'Mesh WiFi or a range extender depending on the place — we suggest whichever is cheaper and right.',
            ]],
        'internet-broadband' => [
            'name' => 'Internet / Broadband Connection', 'emoji' => '🌐',
            'title' => 'Internet & Broadband Connection in Dwarka, Gujarat | ' . $app,
            'desc' => 'New internet / broadband connection in Dwarka with fast installation, WiFi router and local support by ' . $app . '. Best plans for home and business.',
            'paras' => [
                'Need a new internet connection for home or business? We arrange it with the best plan, quick installation and local support.',
                'Call us for any speed or connection trouble — having a local person come at once is the biggest advantage.',
            ],
            'points' => ['Home and business plans', 'Setup with a WiFi router', 'Local support — service at once'],
            'faqs' => [
                'How many days for a connection?' => 'Installation is usually done in 1 to 2 days. Send your address on WhatsApp and we will check.',
            ]],
        'data-recovery' => [
            'name' => 'Data Recovery', 'emoji' => '💾',
            'title' => 'Data Recovery in Dwarka — Hard Disk, Pen Drive, Memory Card | ' . $app,
            'desc' => 'Data recovery service in Dwarka, Gujarat: deleted files, corrupt hard disk, pen drive and memory card recovery with confidentiality at ' . $app . '.',
            'paras' => [
                'Data deleted by mistake, a failed hard disk, a pen drive or a memory card — we recover your valuable data wherever it is possible.',
                'Photos, documents or account files — your data stays 100% private. We check first and only then tell you what is possible and what it costs.',
            ],
            'points' => ['Deleted file recovery', 'Corrupt HDD / SSD recovery', 'Pen drive + memory card', '100% confidential'],
            'faqs' => [
                'Is data recovery guaranteed?' => 'It depends on the state of the disk. We check it free first and tell you the real chances — you pay only if it works.',
            ]],
        'amc' => [
            'name' => 'AMC — Annual Maintenance', 'emoji' => '🛡️',
            'title' => 'Computer & CCTV AMC in Dwarka — Annual Maintenance Contract | ' . $app,
            'desc' => 'Annual Maintenance Contract (AMC) for computers, CCTV and office IT in Dwarka, Gujarat. Regular servicing, priority support and fixed yearly cost by ' . $app . '.',
            'paras' => [
                'Annual maintenance contract (AMC) for the computers and CCTV of an office, shop, school or hospital — regular service, priority support and a fixed cost for the whole year.',
                'An AMC customer gets service first whatever the trouble — keeping your business running is our responsibility.',
            ],
            'points' => ['Regular check-ups and cleaning', 'Priority support', 'A fixed cost for the whole year', 'Computers, CCTV and printers all covered'],
            'faqs' => [
                'What does an AMC include?' => 'The plan is built around the number of machines — service visits, cleaning, software maintenance and so on. WhatsApp us for details.',
            ]],
        'software-installation' => [
            'name' => 'Software & Windows Installation', 'emoji' => '⚙️',
            'title' => 'Windows & Software Installation in Dwarka — Format, Setup | ' . $app,
            'desc' => 'Windows installation, formatting, MS Office, Tally, antivirus and driver setup in Dwarka, Gujarat at ' . $app . '. Data-safe formatting, same-day service.',
            'paras' => [
                'Windows installation, formatting, MS Office, Tally, antivirus, printer drivers — we set up all your software properly. We always save your data before formatting.',
                'Just bought a new computer or laptop? Bring it in for the full setup — we will have it ready to use.',
            ],
            'points' => ['Windows + driver setup', 'MS Office / Tally', 'Antivirus + security', 'Data-safe formatting'],
            'faqs' => [
                'How long does formatting take?' => 'Usually done the same day — hand it in the morning and it is ready by evening.',
            ]],
    ];
}
