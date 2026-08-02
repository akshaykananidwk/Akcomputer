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
    $h .= '<p style="margin-top:8px">Computer, Laptop, CCTV Camera, Printer — sales, repair &amp; installation in Dwarka (દ્વારકા), Gujarat. Genuine products with warranty &amp; doorstep service.</p>';
    $h .= '</div></div>';
    $h .= '<div class="cop">© ' . date('Y') . ' ' . e($app) . ', Dwarka · <a style="display:inline" href="' . e(base_url('catalog.php')) . '">Online Store</a> · <a style="display:inline" href="' . e(seo_service_url('cctv-installation')) . '">CCTV Installation</a> · <a style="display:inline" href="' . e(seo_service_url('computer-repair')) . '">Computer Repair</a> · <a style="display:inline" href="' . e(base_url('privacy.php')) . '">Privacy Policy</a> · <a style="display:inline" href="' . e(base_url('terms.php')) . '">Terms</a></div>';
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
                'ઘર, દુકાન, ઓફિસ, સ્કૂલ કે ફેક્ટરી માટે CCTV કેમેરા સેટઅપ — સર્વે, વાયરિંગ, ઇન્સ્ટોલેશન અને મોબાઇલમાં લાઈવ જોવાની સેટિંગ સુધીનું બધું કામ અમે કરીએ છીએ. HD તથા IP બંને પ્રકારના કેમેરા, બ્રાન્ડેડ DVR/NVR અને ઓરિજિનલ પ્રોડક્ટ જ વાપરીએ છીએ.',
                'Dwarka અને આસપાસના વિસ્તારમાં ફ્રી સાઇટ વિઝિટ. ઇન્સ્ટોલેશન પછી પણ સર્વિસ અને વોરંટી સપોર્ટ અમારી જવાબદારી. જૂની સિસ્ટમ રિપેર / અપગ્રેડ પણ કરી આપીએ છીએ.',
            ],
            'points' => ['HD / IP કેમેરા — 2MP થી 8MP', 'DVR / NVR + હાર્ડ ડિસ્ક સેટઅપ', 'મોબાઇલમાં ગમે ત્યાંથી લાઈવ વ્યૂ', 'વાયરિંગ સાથે કમ્પ્લીટ પેકેજ', 'વોરંટી + આફ્ટર-સેલ સર્વિસ'],
            'faqs' => [
                'CCTV કેમેરા લગાવવાનો ખર્ચ કેટલો થાય?' => 'કેમેરાની સંખ્યા અને ક્વોલિટી (2MP/5MP, HD/IP) પ્રમાણે ભાવ થાય. WhatsApp પર જગ્યાની વિગત મોકલો એટલે ફ્રી quotation મળશે.',
                'મોબાઇલમાં કેમેરા જોઈ શકાય?' => 'હા, દરેક સિસ્ટમમાં મોબાઇલ એપ સેટ કરી આપીએ છીએ — દુનિયામાં ગમે ત્યાંથી લાઈવ જોઈ શકાય.',
            ]],
        'computer-repair' => [
            'name' => 'Computer & Desktop Repair', 'emoji' => '🖥️',
            'title' => 'Computer Repair in Dwarka — Desktop PC Repair & Upgrade | ' . $app,
            'desc' => 'Computer and desktop repair in Dwarka, Gujarat. Slow PC, no display, virus, hardware upgrade, SSD/RAM, formatting with data safety. Same-day service at ' . $app . '.',
            'paras' => [
                'કમ્પ્યુટર ચાલુ નથી થતું? ધીમું ચાલે છે? ડિસ્પ્લે નથી આવતી? — દરેક પ્રકારના ડેસ્કટોપ કમ્પ્યુટર રિપેરિંગ માટે ' . $app . ' પર લઈ આવો. મોટા ભાગનું કામ એ જ દિવસે થઈ જાય છે.',
                'SSD/RAM અપગ્રેડ કરાવવાથી જૂનું કમ્પ્યુટર પણ નવા જેવું ફાસ્ટ ચાલે છે. ફોર્મેટિંગ વખતે તમારો ડેટા સાચવીને જ કામ કરીએ છીએ.',
            ],
            'points' => ['No display / dead PC repair', 'SSD + RAM અપગ્રેડ — સ્પીડ ડબલ', 'Virus સફાઈ + Windows setup', 'નવા-જૂના કમ્પ્યુટર ખરીદ-વેચાણ', 'એ જ દિવસે ડિલિવરીની કોશિશ'],
            'faqs' => [
                'કમ્પ્યુટર ધીમું ચાલે છે, શું કરવું?' => 'મોટા ભાગે SSD + RAM અપગ્રેડથી કમ્પ્યુટર 5-10 ગણું ફાસ્ટ થઈ જાય છે. ચેકિંગ કરીને સાચી સલાહ આપીશું.',
                'ડેટા સેફ રહેશે?' => 'હા, કોઈ પણ કામ પહેલા ડેટા બેકઅપની ચિંતા અમે રાખીએ છીએ.',
            ]],
        'laptop-repair' => [
            'name' => 'Laptop Repair', 'emoji' => '💻',
            'title' => 'Laptop Repair in Dwarka, Gujarat — Screen, Battery, Keyboard | ' . $app,
            'desc' => 'Laptop repair in Dwarka: broken screen replacement, battery, keyboard, hinge, charging port, SSD upgrade, chip-level service. All brands — HP, Dell, Lenovo, Acer, Asus.',
            'paras' => [
                'લેપટોપની સ્ક્રીન તૂટી ગઈ છે, બેટરી નથી ચાલતી, કીબોર્ડ ખરાબ છે કે ચાર્જિંગ નથી થતું? HP, Dell, Lenovo, Acer, Asus — બધી બ્રાન્ડના લેપટોપ રિપેર કરીએ છીએ.',
                'ઓરિજિનલ ક્વોલિટીના પાર્ટ્સ અને કામની ગેરંટી. લેપટોપ ધીમું હોય તો SSD અપગ્રેડ કરાવો — સૌથી સસ્તો અને અસરકારક ઉપાય.',
            ],
            'points' => ['સ્ક્રીન રિપ્લેસમેન્ટ', 'બેટરી / કીબોર્ડ / હિન્જ રિપેર', 'ચાર્જિંગ પોર્ટ + ચિપ લેવલ કામ', 'SSD અપગ્રેડ + Windows', 'નવા-જૂના લેપટોપ ખરીદ-વેચાણ'],
            'faqs' => [
                'લેપટોપ સ્ક્રીન બદલવાનો ભાવ કેટલો?' => 'સાઇઝ અને મોડેલ પ્રમાણે ભાવ થાય. મોડેલ નંબર WhatsApp કરો એટલે તરત ભાવ કહી દઈએ.',
            ]],
        'printer-repair' => [
            'name' => 'Printer Sales & Repair', 'emoji' => '🖨️',
            'title' => 'Printer Repair & Sales in Dwarka — Ink Tank, Laser, Cartridge | ' . $app,
            'desc' => 'Printer repair, sales, ink refilling and cartridge in Dwarka, Gujarat. HP, Canon, Epson service, paper jam, print quality issues — quick turnaround at ' . $app . '.',
            'paras' => [
                'પ્રિન્ટર પ્રિન્ટ નથી કરતું, પેપર જામ થાય છે કે પ્રિન્ટ ઝાંખી આવે છે? HP, Canon, Epson — બધા પ્રિન્ટર રિપેર કરીએ છીએ. ઇન્ક રિફિલિંગ અને કાર્ટ્રિજ પણ મળે છે.',
                'નવું પ્રિન્ટર લેવું હોય તો તમારા વપરાશ પ્રમાણે (ઘર / દુકાન / ઓફિસ) સાચી સલાહ સાથે બેસ્ટ ભાવે આપીશું.',
            ],
            'points' => ['બધી બ્રાન્ડના પ્રિન્ટર રિપેર', 'Ink tank + cartridge રિફિલિંગ', 'નવા પ્રિન્ટર બેસ્ટ ભાવે', 'Printer setup + WiFi printing'],
            'faqs' => [
                'ઘર માટે કયું પ્રિન્ટર સારું?' => 'ઓછા વપરાશ માટે ink tank printer સૌથી સસ્તું પડે છે — પ્રિન્ટ દીઠ ખર્ચ ઘણો ઓછો. દુકાને આવો, ડેમો સાથે સમજાવીશું.',
            ]],
        'networking' => [
            'name' => 'Networking & WiFi Solutions', 'emoji' => '📡',
            'title' => 'Networking & WiFi Setup in Dwarka — Router, LAN, Office Network | ' . $app,
            'desc' => 'WiFi router setup, office LAN networking, structured cabling, range extension and network troubleshooting in Dwarka, Gujarat by ' . $app . '.',
            'paras' => [
                'ઘર કે ઓફિસમાં WiFi ધીમું ચાલે છે કે અમુક રૂમમાં પહોંચતું નથી? રાઉટર સેટઅપ, રેન્જ એક્સટેન્ડર, ઓફિસ LAN વાયરિંગ અને નેટવર્કનું બધું કામ કરીએ છીએ.',
                'દુકાન / ઓફિસ માટે CCTV + કમ્પ્યુટર + પ્રિન્ટર બધું એક નેટવર્કમાં જોડી આપીએ — શેરિંગ અને બેકઅપ સહેલું બને.',
            ],
            'points' => ['WiFi router setup + રેન્જ સોલ્યુશન', 'Office LAN cabling', 'File / printer sharing setup', 'Network troubleshooting'],
            'faqs' => [
                'WiFi ની રેન્જ કેમ વધારવી?' => 'જગ્યા પ્રમાણે mesh WiFi કે range extender — બંનેમાંથી જે સસ્તું અને સાચું હોય એ સૂચવીશું.',
            ]],
        'internet-broadband' => [
            'name' => 'Internet / Broadband Connection', 'emoji' => '🌐',
            'title' => 'Internet & Broadband Connection in Dwarka, Gujarat | ' . $app,
            'desc' => 'New internet / broadband connection in Dwarka with fast installation, WiFi router and local support by ' . $app . '. Best plans for home and business.',
            'paras' => [
                'ઘર કે ધંધા માટે નવું ઇન્ટરનેટ કનેક્શન જોઈએ છે? બેસ્ટ પ્લાન, ઝડપી ઇન્સ્ટોલેશન અને લોકલ સપોર્ટ સાથે કનેક્શન અપાવીએ છીએ.',
                'સ્પીડ કે કનેક્શનની કોઈ પણ તકલીફમાં ફોન કરો — લોકલ માણસ તરત મદદે આવે એ જ સૌથી મોટો ફાયદો.',
            ],
            'points' => ['હોમ + બિઝનેસ પ્લાન', 'WiFi router સાથે સેટઅપ', 'લોકલ સપોર્ટ — તરત સર્વિસ'],
            'faqs' => [
                'કનેક્શન કેટલા દિવસમાં મળે?' => 'મોટા ભાગે 1-2 દિવસમાં ઇન્સ્ટોલેશન થઈ જાય છે. WhatsApp પર એડ્રેસ મોકલી ચેક કરાવો.',
            ]],
        'data-recovery' => [
            'name' => 'Data Recovery', 'emoji' => '💾',
            'title' => 'Data Recovery in Dwarka — Hard Disk, Pen Drive, Memory Card | ' . $app,
            'desc' => 'Data recovery service in Dwarka, Gujarat: deleted files, corrupt hard disk, pen drive and memory card recovery with confidentiality at ' . $app . '.',
            'paras' => [
                'ભૂલથી ડિલીટ થયેલો ડેટા, ખરાબ થયેલી હાર્ડ ડિસ્ક, પેન ડ્રાઇવ કે મેમરી કાર્ડ — શક્ય હોય ત્યાં સુધી તમારો કિંમતી ડેટા પાછો કાઢી આપીએ છીએ.',
                'ફોટા, ડોક્યુમેન્ટ કે હિસાબની ફાઇલ — તમારો ડેટા 100% ખાનગી રહે છે. પહેલા ચેક કરીને જ શક્યતા અને ખર્ચ કહીશું.',
            ],
            'points' => ['Deleted file recovery', 'Corrupt HDD / SSD recovery', 'Pen drive + memory card', '100% confidential'],
            'faqs' => [
                'ડેટા પાછો આવવાની ગેરંટી ખરી?' => 'ડિસ્કની હાલત પર આધાર છે. પહેલા ફ્રી ચેકિંગ કરીને જ સાચી શક્યતા કહીએ છીએ — કામ થાય તો જ ચાર્જ.',
            ]],
        'amc' => [
            'name' => 'AMC — Annual Maintenance', 'emoji' => '🛡️',
            'title' => 'Computer & CCTV AMC in Dwarka — Annual Maintenance Contract | ' . $app,
            'desc' => 'Annual Maintenance Contract (AMC) for computers, CCTV and office IT in Dwarka, Gujarat. Regular servicing, priority support and fixed yearly cost by ' . $app . '.',
            'paras' => [
                'ઓફિસ, દુકાન, સ્કૂલ કે હોસ્પિટલના કમ્પ્યુટર / CCTV માટે વાર્ષિક મેન્ટેનન્સ કોન્ટ્રાક્ટ (AMC) — નિયમિત સર્વિસ, પ્રાયોરિટી સપોર્ટ અને આખા વર્ષનો ફિક્સ ખર્ચ.',
                'AMC લેનાર ગ્રાહકને કોઈ પણ તકલીફમાં પહેલા સર્વિસ મળે છે — ધંધો અટકે નહીં એ અમારી જવાબદારી.',
            ],
            'points' => ['નિયમિત ચેકઅપ + સફાઈ', 'પ્રાયોરિટી સપોર્ટ', 'આખા વર્ષનો ફિક્સ ખર્ચ', 'Computer + CCTV + Printer બધું કવર'],
            'faqs' => [
                'AMC માં શું શું આવે?' => 'મશીનની સંખ્યા પ્રમાણે પ્લાન બને છે — સર્વિસ વિઝિટ, સફાઈ, સોફ્ટવેર મેન્ટેનન્સ વગેરે. વિગત માટે WhatsApp કરો.',
            ]],
        'software-installation' => [
            'name' => 'Software & Windows Installation', 'emoji' => '⚙️',
            'title' => 'Windows & Software Installation in Dwarka — Format, Setup | ' . $app,
            'desc' => 'Windows installation, formatting, MS Office, Tally, antivirus and driver setup in Dwarka, Gujarat at ' . $app . '. Data-safe formatting, same-day service.',
            'paras' => [
                'Windows ઇન્સ્ટોલેશન, ફોર્મેટિંગ, MS Office, Tally, એન્ટીવાયરસ, પ્રિન્ટર ડ્રાઇવર — સોફ્ટવેરનું બધું કામ વ્યવસ્થિત કરી આપીએ છીએ. ડેટા સાચવીને જ ફોર્મેટ કરીએ છીએ.',
                'નવું કમ્પ્યુટર / લેપટોપ લીધું હોય તો બધો સેટઅપ કરાવવા લઈ આવો — તરત વાપરવા લાયક કરી આપીશું.',
            ],
            'points' => ['Windows + driver setup', 'MS Office / Tally', 'Antivirus + સિક્યોરિટી', 'ડેટા-સેફ ફોર્મેટિંગ'],
            'faqs' => [
                'ફોર્મેટ કરવામાં કેટલો સમય લાગે?' => 'મોટા ભાગે એ જ દિવસે થઈ જાય છે — સવારે આપો તો સાંજે તૈયાર.',
            ]],
    ];
}
