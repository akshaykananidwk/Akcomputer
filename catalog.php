<?php
// Public website: proper e-commerce storefront.
// - category SIDEBAR (drawer on mobile), colourful hero, product cards that
//   link to per-product SEO pages (product.php)
// - dealer login: shows each dealer THEIR price directly (no % and no
//   struck-through MRP - the dealer never learns their discount rate)
// - dealer self-registration (pending until the shop approves)
// - daily unique-visitor tracking for the admin dashboard
// Orders land in web_orders (admin: web_orders.php) + WhatsApp alert to shop.
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/seo.php';

$app_name = setting('app_name', 'AK Computer');
$waShop = wa_normalize_number(setting('wa_shop_number'));
if (strlen($waShop) < 12) $waShop = '';
$orderOk = false;

// ---------- daily unique visitors (guests only, one row per person/day) ----------
site_visit_track('catalog');

// ---------- referral link capture (?ref=CODE) ----------
// The code rides a 30-day cookie so the partner still gets credit when the
// customer orders days later. Clicks are counted once per arrival.
if (get('ref') !== '') {
    $refRow = row('SELECT id, code FROM referrers WHERE code = ? AND is_active = 1', [strtoupper(trim(get('ref')))]);
    if ($refRow) {
        if (($_COOKIE['akc_ref'] ?? '') !== $refRow['code']) q('UPDATE referrers SET clicks = clicks + 1 WHERE id = ?', [$refRow['id']]);
        setcookie('akc_ref', $refRow['code'], time() + 30 * 86400, '/');
        $_COOKIE['akc_ref'] = $refRow['code'];
    }
}

// ---------- dealer (electrician/B2B) web login ----------
// Once logged in, every price is shown already reduced by the discount % on
// the account. The page never reveals the % or the original price - the
// dealer just sees "their" price, as the owner wants different hidden rates
// per dealer. Orders are priced the same way server-side.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'wlogin') {
    $acc = row('SELECT * FROM web_accounts WHERE mobile = ? AND is_active = 1', [preg_replace('/\D/', '', post('wmobile'))]);
    if ($acc && password_verify(post('wpassword'), $acc['password_hash'])) {
        $_SESSION['web_account_id'] = (int)$acc['id'];
        q('UPDATE web_accounts SET last_login = NOW() WHERE id = ?', [$acc['id']]);
        redirect('catalog.php');
    }
    $wloginError = 'Mobile or password is wrong. Not approved yet? The shop will confirm on WhatsApp.';
}
if (get('wlogout') === '1') { unset($_SESSION['web_account_id']); redirect('catalog.php'); }

// ---------- dealer self-registration (pending until the shop approves) ----------
$wregDone = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'wregister') {
    $rname = trim(post('rname'));
    $rmobile = preg_replace('/\D/', '', post('rmobile'));
    if ($rname === '' || strlen($rmobile) < 10 || strlen(post('rpassword')) < 4) {
        $wregError = 'Fill name, a valid mobile and a password (4+ letters).';
    } elseif (val('SELECT id FROM web_accounts WHERE mobile = ?', [$rmobile])) {
        $wregError = 'This mobile already has an account. Try logging in.';
    } else {
        q('INSERT INTO web_accounts (name, mobile, password_hash, discount_pct, is_active, self_registered) VALUES (?,?,?,0,0,1)',
          [$rname, $rmobile, password_hash(post('rpassword'), PASSWORD_DEFAULT)]);
        if ($waShop) send_whatsapp($waShop, "🆕 *Dealer registration*\n\n$rname ($rmobile) એ વેબસાઇટ પર ડીલર એકાઉન્ટ માંગ્યું છે.\nApprove: " . base_url('web_customers.php'));
        $wregDone = true;
    }
}

$webAcct = null;
if (!empty($_SESSION['web_account_id'])) {
    $webAcct = row('SELECT * FROM web_accounts WHERE id = ? AND is_active = 1', [(int)$_SESSION['web_account_id']]);
    if (!$webAcct) unset($_SESSION['web_account_id']);
}
$waPct = $webAcct ? (float)$webAcct['discount_pct'] : 0;

// ---------- place order ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'order') {
    $cart = json_decode(post('cart_json'), true) ?: [];
    $name = trim(post('customer_name'));
    $mobile = trim(post('mobile'));
    if ($name && $mobile && $cart) {
        // re-price from DB (never trust browser prices)
        $itemsTxt = '';
        $total = 0;
        $clean = [];
        foreach ($cart as $cid => $cqty) {
            $it = row('SELECT id, name, selling_price FROM items WHERE id = ? AND is_active = 1 AND show_on_website = 1', [(int)$cid]);
            $qty = max(1, (int)$cqty);
            if (!$it) continue;
            $unit = dealer_price($it['selling_price'], $waPct);
            $line = $qty * $unit;
            $total += $line;
            $clean[] = ['id' => $it['id'], 'name' => $it['name'], 'qty' => $qty, 'price' => $unit, 'total' => $line];
            $itemsTxt .= '- ' . $it['name'] . ' x' . $qty . ' = ₹' . money($line) . "\n";
        }
        if ($clean) {
            $refCode = strtoupper(trim($_COOKIE['akc_ref'] ?? ''));
            $referrer = $refCode !== '' ? row('SELECT * FROM referrers WHERE code = ? AND is_active = 1', [$refCode]) : null;
            q('INSERT INTO web_orders (customer_name, mobile, address, notes, items_json, total, ref_code, web_account_id) VALUES (?,?,?,?,?,?,?,?)',
              [$name, $mobile, post('address'), post('order_notes'), json_encode($clean, JSON_UNESCAPED_UNICODE), $total, $referrer ? $referrer['code'] : null,
               $webAcct ? (int)$webAcct['id'] : null]);
            $oid = insert_id();
            q('UPDATE web_orders SET order_no = ? WHERE id = ?', [doc_no('WEB', $oid), $oid]);
            // partner commission books itself as PENDING right away; it turns
            // into an approved (payable) balance when the shop marks the order
            // completed, and cancels if the order cancels
            if ($referrer) {
                $comm = round($total * (float)$referrer['commission_pct'] / 100, 2);
                q('INSERT INTO referral_earnings (referrer_id, web_order_id, order_no, order_total, commission) VALUES (?,?,?,?,?)',
                  [$referrer['id'], $oid, doc_no('WEB', $oid), $total, $comm]);
                if ($referrer['mobile']) {
                    send_whatsapp($referrer['mobile'], "🎉 *" . setting('app_name', 'AK Computer') . "*\n\nતમારી લિંકથી નવો ઓર્ડર આવ્યો!\nOrder: " . doc_no('WEB', $oid) . " · ₹" . money($total) .
                        "\nતમારું કમિશન: *₹" . money($comm) . "* (ઓર્ડર પૂરો થાય એટલે જમા)\n\nસ્ટેટસ: " . base_url('referral.php?t=' . $referrer['token']));
                }
            }
            if ($waShop) {
                send_whatsapp($waShop, wa_template('weborder', [
                    'order_no' => doc_no('WEB', $oid), 'customer' => $name, 'mobile' => $mobile,
                    'address' => post('address'), 'items' => trim($itemsTxt), 'total' => money($total),
                ]));
            }
            $orderOk = doc_no('WEB', $oid);
        }
    }
}

$items = all('SELECT i.*, c.name cat_name FROM items i
              LEFT JOIN categories c ON c.id = i.category_id
              WHERE i.is_active = 1 AND i.show_on_website = 1 ORDER BY c.name, i.name');
$cats = [];
foreach ($items as $it) { $cn = $it['cat_name'] ?: ''; if ($cn !== '') $cats[$cn] = ($cats[$cn] ?? 0) + 1; }
ksort($cats);
$metaDesc = $app_name . ' - દ્વારકા, ગુજરાતનો ભરોસાપાત્ર કમ્પ્યુટર અને CCTV સ્ટોર. Computers, Laptops, CCTV Cameras, Printers, Accessories & Repairs in Dwarka, Gujarat. ' . count($items) . '+ products online - order on WhatsApp.';
?><!DOCTYPE html>
<html lang="gu">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($app_name) ?> — Computer &amp; CCTV Store, Dwarka Gujarat | કમ્પ્યુટર · CCTV કેમેરા · લેપટોપ</title>
<meta name="description" content="<?= e($metaDesc) ?>">
<meta name="keywords" content="computer shop dwarka, cctv camera dwarka, laptop dwarka gujarat, કમ્પ્યુટર દ્વારકા, સીસીટીવી કેમેરા, લેપટોપ, printer, computer repair dwarka, <?= e(implode(', ', array_keys($cats))) ?>">
<link rel="canonical" href="<?= e(base_url('catalog.php')) ?>">
<meta property="og:type" content="website">
<meta property="og:title" content="<?= e($app_name) ?> — Online Store, Dwarka">
<meta property="og:description" content="<?= e($metaDesc) ?>">
<meta property="og:url" content="<?= e(base_url('catalog.php')) ?>">
<link rel="icon" href="assets/icon.svg" type="image/svg+xml">
<link rel="stylesheet" href="assets/style.css?v=<?= asset_v('style.css') ?>">
<?= seo_localbusiness_jsonld() ?>
<?= seo_jsonld(['@context' => 'https://schema.org', '@type' => 'WebSite',
    'name' => $app_name, 'url' => base_url('catalog.php')]) ?>
<?= seo_public_css() ?>
<style>
:root { --acc1: #4f46e5; --acc2: #06b6d4; --acc3: #f59e0b; }
body { padding-bottom: 90px; background: var(--bg); }
.shead { position: sticky; top: 0; z-index: 50; background: linear-gradient(100deg, var(--acc1), #2563eb 60%, var(--acc2)); color: #fff;
  padding: 10px 14px; display: flex; align-items: center; gap: 10px; flex-wrap: wrap; box-shadow: 0 2px 12px rgba(37,99,235,.35); }
.shead .logo { font-size: 18px; font-weight: 800; white-space: nowrap; display: flex; align-items: center; gap: 7px; }
.shead .search { flex: 1; min-width: 160px; display: flex; }
.shead .search input { width: 100%; border: 0; border-radius: 10px; padding: 10px 13px; font-size: 14px; }
.shead select { border: 0; border-radius: 10px; padding: 10px 8px; font-size: 13px; max-width: 130px; }
.shead .hbtn { background: rgba(255,255,255,.18); border: 1px solid rgba(255,255,255,.45); color: #fff; border-radius: 10px;
  padding: 8px 12px; font-size: 13px; font-weight: 700; cursor: pointer; text-decoration: none; white-space: nowrap; }
.hero { background: linear-gradient(120deg, #eef2ff, #ecfeff); text-align: center; padding: 22px 14px 18px; }
[data-theme="dark"] .hero { background: linear-gradient(120deg, #1e1b4b, #164e63); }
@media (prefers-color-scheme: dark) { :root:not([data-theme="light"]) .hero { background: linear-gradient(120deg, #1e1b4b, #164e63); } }
.hero h1 { font-size: 22px; color: var(--text); }
.hero p { color: var(--muted); margin-top: 4px; font-size: 13.5px; }
@media (min-width: 900px) { .hero { padding: 34px 14px 26px; } .hero h1 { font-size: 30px; } .hero p { font-size: 15px; } }
.hero .badges { display: flex; gap: 8px; justify-content: center; flex-wrap: wrap; margin-top: 10px; }
.hero .badges span { background: var(--card); color: var(--text); border-radius: 999px; padding: 5px 12px; font-size: 12.5px; font-weight: 600; box-shadow: 0 1px 4px rgba(0,0,0,.08); }
.dealer-bar { max-width: 1200px; margin: 10px auto 0; padding: 10px 14px; border-radius: 12px; background: linear-gradient(100deg, #059669, #10b981); color: #fff;
  display: flex; justify-content: space-between; align-items: center; gap: 10px; flex-wrap: wrap; font-size: 14px; }
.dlg-card { max-width: 460px; margin: 14px auto 0; background: var(--card); color: var(--text); border-radius: 14px; padding: 18px 16px; box-shadow: 0 2px 10px rgba(0,0,0,.1); }
.shop-layout { max-width: 1240px; margin: 0 auto; padding: 14px 12px; display: grid; grid-template-columns: 1fr; gap: 16px; }
@media (min-width: 900px) { .shop-layout { grid-template-columns: 240px 1fr; align-items: start; padding: 20px 16px; } }
.side { background: var(--card); color: var(--text); border-radius: 14px; padding: 12px; box-shadow: 0 1px 4px rgba(0,0,0,.08); }
@media (min-width: 900px) { .side { position: sticky; top: 68px; } .catsBtn { display: none; } }
@media (max-width: 899px) {
  .side { display: none; position: fixed; inset: 0 auto 0 0; width: 260px; z-index: 80; border-radius: 0 14px 14px 0; overflow-y: auto; }
  .side.open { display: block; }
}
.side-ovl { display: none; position: fixed; inset: 0; z-index: 75; background: rgba(0,0,0,.45); }
.side-ovl.show { display: block; }
.side h3 { font-size: 14px; margin-bottom: 8px; color: var(--muted); text-transform: uppercase; letter-spacing: .4px; }
.side .cat-link { display: flex; justify-content: space-between; align-items: center; gap: 8px; width: 100%; text-align: left; border: 0;
  background: none; color: var(--text); padding: 9px 10px; border-radius: 10px; font-size: 14px; cursor: pointer; }
.side .cat-link:hover { background: var(--bg); }
.side .cat-link.on { background: var(--acc1); color: #fff; font-weight: 700; }
.side .cat-link .cnt { font-size: 11.5px; background: var(--bg); color: var(--muted); border-radius: 999px; padding: 2px 8px; }
.side .cat-link.on .cnt { background: rgba(255,255,255,.25); color: #fff; }
.catsBtn { margin-bottom: 4px; }
.cat-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(165px, 1fr)); gap: 12px; }
@media (min-width: 900px) { .cat-grid { grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 16px; } }
.cat-card { background: var(--card); color: var(--text); border-radius: 14px; overflow: hidden; box-shadow: 0 1px 4px rgba(0,0,0,.08);
  display: flex; flex-direction: column; transition: transform .15s, box-shadow .15s; }
.cat-card:hover { transform: translateY(-3px); box-shadow: 0 8px 20px rgba(0,0,0,.14); }
.cat-card a.imglink { display: block; }
.cat-card img { width: 100%; height: 150px; object-fit: contain; background: #fff; padding: 8px; }
.cat-card .ph { width: 100%; height: 150px; display: flex; align-items: center; justify-content: center; font-size: 46px; background: var(--bg); }
.cat-card .cbody { padding: 10px; display: flex; flex-direction: column; gap: 4px; flex: 1; }
.cat-card .cname { font-weight: 600; font-size: 14px; }
.cat-card .cname a { color: inherit; text-decoration: none; }
.cat-card .cdesc { font-size: 12px; color: var(--muted); display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.cat-card .ctag { font-size: 11px; color: var(--acc1); font-weight: 700; text-transform: uppercase; letter-spacing: .3px; }
.cat-card .cprice { color: var(--primary); font-weight: 800; font-size: 16.5px; margin-top: auto; }
.addbtn { width: 100%; margin-top: 8px; background: linear-gradient(100deg, var(--acc1), #2563eb); border: 0; }
.qtyrow { display: flex; align-items: center; gap: 8px; margin-top: 8px; }
.qtyrow button { width: 34px; height: 34px; border-radius: 8px; border: 1px solid var(--primary); background: var(--card); color: var(--primary); font-size: 17px; font-weight: 700; cursor: pointer; }
.qtyrow span { flex: 1; text-align: center; font-weight: 700; }
.cartbar { position: fixed; left: 12px; right: 12px; bottom: 12px; z-index: 60; background: linear-gradient(100deg, #059669, #10b981); color: #fff;
  border-radius: 14px; padding: 13px 18px; display: none; justify-content: space-between; align-items: center;
  box-shadow: 0 6px 20px rgba(5,150,105,.4); cursor: pointer; font-weight: 700; }
.cartbar.show { display: flex; }
.order-modal { position: fixed; inset: 0; z-index: 90; background: rgba(0,0,0,.5); display: none; align-items: flex-end; justify-content: center; }
.order-modal.show { display: flex; }
.order-box { background: var(--card); color: var(--text); width: 100%; max-width: 520px; border-radius: 18px 18px 0 0; padding: 20px 16px; max-height: 88vh; overflow-y: auto; }
@media (min-width: 700px) { .order-modal { align-items: center; } .order-box { border-radius: 18px; } }
.oline { display: flex; justify-content: space-between; padding: 6px 0; border-bottom: 1px dashed var(--border); font-size: 14px; }
.ok-box { text-align: center; padding: 40px 16px; }
.ok-box .big { font-size: 54px; }
.sfoot { background: var(--card); color: var(--text); margin-top: 26px; padding: 24px 14px 90px; text-align: center; font-size: 13.5px; }
.sfoot .muted { font-size: 12.5px; }
</style>
</head>
<body>

<header class="shead">
  <a class="logo" href="catalog.php" style="color:#fff;text-decoration:none">🖥️ <?= e($app_name) ?></a>
  <div class="search"><input type="text" id="cFilter" placeholder="🔍 Search products… (કેમેરા, લેપટોપ, માઉસ…)"></div>
  <select id="cSort">
    <option value="">↕️ Sort</option>
    <option value="price_asc">₹ Low → High</option>
    <option value="price_desc">₹ High → Low</option>
    <option value="name">Name A–Z</option>
    <option value="new">Newest</option>
  </select>
  <?php if ($webAcct): ?>
    <a class="hbtn" href="catalog.php?wlogout=1" title="Logout">👷 <?= e(mb_substr($webAcct['name'], 0, 14)) ?> ✕</a>
  <?php else: ?>
    <a class="hbtn" href="catalog.php?dlogin=1">🔑 Dealer</a>
  <?php endif; ?>
  <?php if (!current_user()): ?>
    <a class="hbtn" href="<?= e(base_url('login.php')) ?>" title="Staff Login">👤 Staff</a>
  <?php else: ?>
    <a class="hbtn" href="<?= e(base_url('index.php')) ?>" title="Dashboard">📊 Dashboard</a>
  <?php endif; ?>
</header>

<?php if ($webAcct): ?>
<div class="dealer-bar">
  <span>👷 <strong><?= e($webAcct['name']) ?></strong> — તમારો સ્પેશિયલ ભાવ ચાલુ છે ✔ (your special prices are ON)</span>
  <a class="hbtn" href="catalog.php?wlogout=1">Logout</a>
</div>
<?php endif; ?>

<?php if (!$webAcct && (get('dlogin') === '1' || !empty($wloginError) || get('dregister') === '1' || !empty($wregError) || $wregDone)): ?>
<div class="dlg-card">
  <?php if ($wregDone): ?>
    <h3>✅ Registration મળી ગયું!</h3>
    <p class="muted mt">દુકાન તમારું એકાઉન્ટ મંજૂર કરશે એટલે તમને WhatsApp આવશે. પછી લોગિન કરો એટલે તમારો સ્પેશિયલ ભાવ દેખાશે.</p>
    <a class="btn btn-block mt" href="catalog.php">← Store પર પાછા જાઓ</a>
  <?php elseif (get('dregister') === '1' || !empty($wregError)): ?>
    <h3>📝 Dealer Registration</h3>
    <p class="muted" style="font-size:12.5px;margin-top:4px">ઇલેક્ટ્રિશિયન / રિસેલર છો? રજિસ્ટર કરો — દુકાન મંજૂર કરે એટલે તમને તમારો સ્પેશિયલ ભાવ દેખાવા લાગશે.</p>
    <?php if (!empty($wregError)): ?><p style="color:#dc2626;margin-top:6px"><?= e($wregError) ?></p><?php endif; ?>
    <form method="post" class="mt">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="wregister">
      <div class="field"><label>Name / Firm *</label><input type="text" name="rname" required placeholder="e.g. Ramesh Electricals, Dwarka"></div>
      <div class="field"><label>Mobile (WhatsApp) *</label><input type="tel" name="rmobile" required pattern="[0-9]{10,12}"></div>
      <div class="field"><label>Password *</label><input type="password" name="rpassword" required minlength="4"></div>
      <button class="btn btn-block" type="submit">Register</button>
      <p class="muted mt" style="text-align:center;font-size:12.5px">Already approved? <a href="catalog.php?dlogin=1">Login here</a></p>
    </form>
  <?php else: ?>
    <h3>🔑 Dealer Login</h3>
    <?php if (!empty($wloginError)): ?><p style="color:#dc2626;margin-top:6px"><?= e($wloginError) ?></p><?php endif; ?>
    <form method="post" class="mt">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="wlogin">
      <div class="field"><label>Mobile</label><input type="tel" name="wmobile" required></div>
      <div class="field"><label>Password</label><input type="password" name="wpassword" required></div>
      <button class="btn btn-block" type="submit">Login</button>
      <p class="muted mt" style="text-align:center;font-size:12.5px">નવા ડીલર છો? <a href="catalog.php?dregister=1">Register કરો</a> · <a href="catalog.php">Cancel</a></p>
    </form>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if (!$webAcct && get('dlogin') !== '1' && get('dregister') !== '1' && !$wregDone && empty($wloginError) && empty($wregError) && !$orderOk): ?>
<div class="hero">
  <h1>દ્વારકાની પોતાની Computer &amp; CCTV દુકાન 🛍️</h1>
  <p>Computers · Laptops · CCTV Cameras · Printers · Accessories · Repairs — બધું એક જ જગ્યાએ, ઓર્ડર WhatsApp પર</p>
  <div class="badges"><span>✅ Genuine products</span><span>🛠️ Warranty &amp; Service</span><span>🚚 Dwarka delivery</span><span>📞 Direct WhatsApp</span></div>
</div>
<?php endif; ?>

<?php if ($orderOk): ?>
<div style="max-width:560px;margin:24px auto;padding:0 12px">
  <div class="card ok-box">
    <div class="big">✅</div>
    <h2>Order received! (<?= e($orderOk) ?>)</h2>
    <p class="muted mt">We'll WhatsApp/call you soon. Thank you! 🙏</p>
    <a class="btn mt" href="catalog.php">← Back to Store</a>
  </div>
</div>
<?php else: ?>
<div class="side-ovl" id="sideOvl"></div>
<div class="shop-layout">
  <aside class="side" id="sideBar">
    <h3>Categories</h3>
    <button type="button" class="cat-link on" data-cat="">🏪 All Products <span class="cnt"><?= count($items) ?></span></button>
    <?php foreach ($cats as $cn => $cnt): ?>
    <button type="button" class="cat-link" data-cat="<?= e($cn) ?>"><?= e(cat_icon($cn)) ?> <?= e($cn) ?> <span class="cnt"><?= $cnt ?></span></button>
    <?php endforeach; ?>
  </aside>

  <main>
    <button type="button" class="btn btn-sm btn-outline catsBtn" id="catsBtn">☰ Categories</button>
    <div class="cat-grid" id="cGrid">
    <?php foreach ($items as $it): ?>
      <?php $dp = dealer_price($it['selling_price'], $waPct);
            $purl = seo_product_url($it); ?>
      <div class="cat-card" data-cat="<?= e($it['cat_name'] ?? '') ?>" data-price="<?= $dp ?>" data-name="<?= e(mb_strtolower($it['name'])) ?>" data-newid="<?= (int)$it['id'] ?>">
        <a class="imglink" href="<?= e($purl) ?>">
        <?php if ($it['photo']): ?><img src="<?= e($it['photo']) ?>" alt="<?= e($it['name']) ?>" loading="lazy">
        <?php else: ?><div class="ph"><?= e(cat_icon($it['cat_name'] ?? '')) ?></div><?php endif; ?>
        </a>
        <div class="cbody">
          <?php if ($it['cat_name']): ?><div class="ctag"><?= e($it['cat_name']) ?></div><?php endif; ?>
          <div class="cname"><a href="<?= e($purl) ?>"><?= e($it['name']) ?></a></div>
          <?php if ($it['brand']): ?><div class="muted"><?= e(trim($it['brand'] . ' ' . $it['model'])) ?></div><?php endif; ?>
          <?php if (!empty($it['description'])): ?><div class="cdesc"><?= e($it['description']) ?></div><?php endif; ?>
          <div class="cprice">₹<?= money($dp) ?></div>
          <button type="button" class="btn btn-sm addbtn" data-id="<?= $it['id'] ?>" data-name="<?= e($it['name']) ?>" data-price="<?= $dp ?>">🛒 Add to Cart</button>
          <div class="qtyrow" style="display:none" data-qid="<?= $it['id'] ?>">
            <button type="button" class="q-minus">−</button><span class="q-num">1</span><button type="button" class="q-plus">＋</button>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
    <?php if (!$items): ?><p class="muted">No products listed yet.</p><?php endif; ?>
    </div>
  </main>
</div>

  <div class="cartbar" id="cartBar"><span id="cartInfo"></span><span>Place Order →</span></div>

  <div class="order-modal" id="orderModal">
    <div class="order-box">
      <h2>🛒 Your Order</h2>
      <div id="orderLines" class="mb mt"></div>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="order">
        <input type="hidden" name="cart_json" id="cartJson">
        <div class="field"><label>Your Name *</label><input type="text" name="customer_name" required value="<?= e($webAcct['name'] ?? '') ?>"></div>
        <div class="field"><label>Mobile (WhatsApp) *</label><input type="tel" name="mobile" required pattern="[0-9]{10,12}" value="<?= e($webAcct['mobile'] ?? '') ?>"></div>
        <div class="field"><label>Address / Area</label><input type="text" name="address"></div>
        <div class="field"><label>Anything specific to mention?</label><input type="text" name="order_notes"></div>
        <button class="btn btn-success btn-block" type="submit">✅ Send Order</button>
        <button class="btn btn-muted btn-block mt" type="button" onclick="document.getElementById('orderModal').classList.remove('show')">Close</button>
      </form>
    </div>
  </div>
<?php endif; ?>

<footer class="sfoot">
  <p><strong>🖥️ <?= e($app_name) ?></strong> — Devbhoomi Dwarka, Gujarat</p>
  <p class="muted mt">Computer શોપ · CCTV Camera installation · Laptop · Printer · Repairs — દ્વારકા અને આજુબાજુના વિસ્તારમાં સર્વિસ</p>
  <p class="mt">
    <?php if ($waShop): ?><a class="btn btn-sm btn-wa" href="https://wa.me/<?= e($waShop) ?>" target="_blank" rel="noopener">📲 WhatsApp us</a><?php endif; ?>
    <a class="btn btn-sm btn-outline" href="referral.php">💰 Refer &amp; Earn</a>
    <?php if (!$webAcct): ?><a class="btn btn-sm btn-outline" href="catalog.php?dregister=1">📝 Dealer બનો</a><?php endif; ?>
  </p>
  <?php if (!current_user()): ?><p class="muted mt" style="font-size:12px"><a href="login.php" style="color:inherit">Staff Login</a></p><?php endif; ?>
  <?php if (current_user() && can('items.edit')): $hidden = (int)val('SELECT COUNT(*) FROM items WHERE is_active = 1 AND show_on_website = 0'); ?>
    <?php if ($hidden): ?><p class="muted mt">ℹ️ (Admin: <?= $hidden ?> items are OFF on the website — turn them ON via 🌐 on the Items page.)</p><?php endif; ?>
  <?php endif; ?>
</footer>

<script>
var cart = {};   // id -> qty
var meta = {};   // id -> {name, price}

function refreshCart() {
  var count = 0, total = 0;
  Object.keys(cart).forEach(function (id) {
    count += cart[id];
    total += cart[id] * meta[id].price;
  });
  var bar = document.getElementById('cartBar');
  if (!bar) return;
  if (count > 0) {
    bar.classList.add('show');
    document.getElementById('cartInfo').textContent = count + ' item · ₹' + total.toFixed(2);
  } else {
    bar.classList.remove('show');
  }
}
function addToCart(id) {
  var b = document.querySelector('.addbtn[data-id="' + id + '"]');
  if (!b) return;
  meta[id] = { name: b.dataset.name, price: parseFloat(b.dataset.price) };
  cart[id] = (cart[id] || 0) + 0 || 1;
  cart[id] = 1;
  b.style.display = 'none';
  document.querySelector('.qtyrow[data-qid="' + id + '"]').style.display = 'flex';
  refreshCart();
}
document.querySelectorAll('.addbtn').forEach(function (b) {
  b.addEventListener('click', function () { addToCart(b.dataset.id); });
});
document.querySelectorAll('.qtyrow').forEach(function (row) {
  var id = row.dataset.qid;
  row.querySelector('.q-plus').addEventListener('click', function () {
    cart[id]++; row.querySelector('.q-num').textContent = cart[id]; refreshCart();
  });
  row.querySelector('.q-minus').addEventListener('click', function () {
    cart[id]--;
    if (cart[id] <= 0) {
      delete cart[id];
      row.style.display = 'none';
      row.parentElement.querySelector('.addbtn').style.display = '';
    } else {
      row.querySelector('.q-num').textContent = cart[id];
    }
    refreshCart();
  });
});
document.getElementById('cartBar') && document.getElementById('cartBar').addEventListener('click', function () {
  var lines = '';
  var total = 0;
  Object.keys(cart).forEach(function (id) {
    var line = cart[id] * meta[id].price;
    total += line;
    lines += '<div class="oline"><span>' + meta[id].name + ' × ' + cart[id] + '</span><span>₹' + line.toFixed(2) + '</span></div>';
  });
  lines += '<div class="oline" style="font-weight:800"><span>Total</span><span>₹' + total.toFixed(2) + '</span></div>';
  document.getElementById('orderLines').innerHTML = lines;
  document.getElementById('cartJson').value = JSON.stringify(cart);
  document.getElementById('orderModal').classList.add('show');
});
// "Add to cart" arriving from a product page (product.php?...&add=ID)
(function () {
  var addId = new URLSearchParams(location.search).get('add');
  if (addId) { addToCart(addId); history.replaceState(null, '', 'catalog.php'); }
})();
// search + category filter
var filterInp = document.getElementById('cFilter');
function applyFilter() {
  var q = (filterInp.value || '').toLowerCase();
  var cat = (document.querySelector('.cat-link.on') || {}).dataset ? document.querySelector('.cat-link.on').dataset.cat : '';
  document.querySelectorAll('#cGrid .cat-card').forEach(function (c) {
    var okQ = c.textContent.toLowerCase().indexOf(q) > -1;
    var okC = !cat || c.dataset.cat === cat;
    c.style.display = okQ && okC ? '' : 'none';
  });
}
if (filterInp) filterInp.addEventListener('input', applyFilter);
// sort: reorder the cards inside the grid (works together with search + category)
var sortSel = document.getElementById('cSort');
if (sortSel) sortSel.addEventListener('change', function () {
  var grid = document.getElementById('cGrid');
  if (!grid) return;
  var cards = Array.prototype.slice.call(grid.querySelectorAll('.cat-card'));
  var v = sortSel.value;
  cards.sort(function (a, b) {
    if (v === 'price_asc') return parseFloat(a.dataset.price) - parseFloat(b.dataset.price);
    if (v === 'price_desc') return parseFloat(b.dataset.price) - parseFloat(a.dataset.price);
    if (v === 'name') return a.dataset.name < b.dataset.name ? -1 : 1;
    if (v === 'new') return parseInt(b.dataset.newid) - parseInt(a.dataset.newid);
    return 0;
  });
  cards.forEach(function (c) { grid.appendChild(c); });
});
// category sidebar (drawer on mobile)
var sideBar = document.getElementById('sideBar'), sideOvl = document.getElementById('sideOvl');
var catsBtn = document.getElementById('catsBtn');
if (catsBtn) catsBtn.addEventListener('click', function () { sideBar.classList.add('open'); sideOvl.classList.add('show'); });
if (sideOvl) sideOvl.addEventListener('click', function () { sideBar.classList.remove('open'); sideOvl.classList.remove('show'); });
document.querySelectorAll('.cat-link').forEach(function (ch) {
  ch.addEventListener('click', function () {
    document.querySelectorAll('.cat-link').forEach(function (x) { x.classList.remove('on'); });
    ch.classList.add('on');
    applyFilter();
    if (sideBar) sideBar.classList.remove('open');
    if (sideOvl) sideOvl.classList.remove('show');
  });
});
</script>
<?= seo_footer() ?>
</body>
</html>
