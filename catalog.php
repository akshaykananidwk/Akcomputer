<?php
// Public website: product catalog with Add-to-Cart and order form.
// Orders land in web_orders (admin: web_orders.php) + WhatsApp alert to shop.
require_once __DIR__ . '/includes/init.php';

$app_name = setting('app_name', 'AK Computer');
$waShop = wa_normalize_number(setting('wa_shop_number'));
if (strlen($waShop) < 12) $waShop = '';
$orderOk = false;

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
            $line = $qty * (float)$it['selling_price'];
            $total += $line;
            $clean[] = ['id' => $it['id'], 'name' => $it['name'], 'qty' => $qty, 'price' => (float)$it['selling_price'], 'total' => $line];
            $itemsTxt .= '- ' . $it['name'] . ' x' . $qty . ' = ₹' . money($line) . "\n";
        }
        if ($clean) {
            $refCode = strtoupper(trim($_COOKIE['akc_ref'] ?? ''));
            $referrer = $refCode !== '' ? row('SELECT * FROM referrers WHERE code = ? AND is_active = 1', [$refCode]) : null;
            q('INSERT INTO web_orders (customer_name, mobile, address, notes, items_json, total, ref_code) VALUES (?,?,?,?,?,?,?)',
              [$name, $mobile, post('address'), post('order_notes'), json_encode($clean, JSON_UNESCAPED_UNICODE), $total, $referrer ? $referrer['code'] : null]);
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
$cats = array_values(array_unique(array_filter(array_column($items, 'cat_name'))));
?><!DOCTYPE html>
<html lang="gu">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($app_name) ?> - Online Store</title>
<link rel="icon" href="assets/icon.svg" type="image/svg+xml">
<link rel="stylesheet" href="assets/style.css?v=3">
<style>
body { padding-bottom: 90px; }
.store-hero { background: linear-gradient(120deg, #1a56db, #1e429f); color: #fff; text-align: center; padding: 34px 14px 26px; }
.store-hero h1 { font-size: 26px; }
.store-hero p { opacity: .9; margin-top: 6px; font-size: 14px; }
.store-wrap { max-width: 1100px; margin: 0 auto; padding: 16px 12px; }
.cat-chips { display: flex; gap: 8px; overflow-x: auto; padding: 4px 0 12px; }
.cat-chip { white-space: nowrap; padding: 7px 14px; border-radius: 999px; background: var(--card); color: var(--text); border: 1px solid var(--border); font-size: 13px; cursor: pointer; }
.cat-chip.on { background: var(--primary); color: #fff; border-color: var(--primary); }
.cat-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(165px, 1fr)); gap: 12px; }
.cat-card { background: var(--card); color: var(--text); border-radius: 14px; overflow: hidden; box-shadow: 0 1px 4px rgba(0,0,0,.08); display: flex; flex-direction: column; }
.cat-card img { width: 100%; height: 135px; object-fit: cover; background: var(--bg); }
.cat-card .ph { width: 100%; height: 135px; display: flex; align-items: center; justify-content: center; font-size: 42px; background: var(--bg); }
.cat-card .cbody { padding: 10px; display: flex; flex-direction: column; gap: 4px; flex: 1; }
.cat-card .cname { font-weight: 600; font-size: 14px; }
.cat-card .cprice { color: var(--primary); font-weight: 800; font-size: 16px; margin-top: auto; }
.addbtn { width: 100%; margin-top: 8px; }
.qtyrow { display: flex; align-items: center; gap: 8px; margin-top: 8px; }
.qtyrow button { width: 34px; height: 34px; border-radius: 8px; border: 1px solid var(--primary); background: var(--card); color: var(--primary); font-size: 17px; font-weight: 700; cursor: pointer; }
.qtyrow span { flex: 1; text-align: center; font-weight: 700; }
.cartbar { position: fixed; left: 12px; right: 12px; bottom: 12px; z-index: 60; background: var(--primary); color: #fff;
  border-radius: 14px; padding: 13px 18px; display: none; justify-content: space-between; align-items: center;
  box-shadow: 0 6px 20px rgba(26,86,219,.4); cursor: pointer; font-weight: 700; }
.cartbar.show { display: flex; }
.order-modal { position: fixed; inset: 0; z-index: 70; background: rgba(0,0,0,.5); display: none; align-items: flex-end; justify-content: center; }
.order-modal.show { display: flex; }
.order-box { background: var(--card); color: var(--text); width: 100%; max-width: 520px; border-radius: 18px 18px 0 0; padding: 20px 16px; max-height: 88vh; overflow-y: auto; }
@media (min-width: 700px) { .order-modal { align-items: center; } .order-box { border-radius: 18px; } }
.oline { display: flex; justify-content: space-between; padding: 6px 0; border-bottom: 1px dashed var(--border); font-size: 14px; }
.ok-box { text-align: center; padding: 40px 16px; }
.ok-box .big { font-size: 54px; }
</style>
</head>
<body>
<div class="store-hero">
  <h1>🖥️ <?= e($app_name) ?></h1>
  <p>Computers · Laptops · Accessories · CCTV · Repairs</p>
</div>
<div class="store-wrap">
<?php if ($orderOk): ?>
  <div class="card ok-box">
    <div class="big">✅</div>
    <h2>Order received! (<?= e($orderOk) ?>)</h2>
    <p class="muted mt">We'll WhatsApp/call you soon. Thank you! 🙏</p>
    <a class="btn mt" href="catalog.php">← Back to Store</a>
  </div>
<?php else: ?>
  <div class="searchbox"><input type="text" id="cFilter" placeholder="🔍 Search products..."></div>
  <?php if ($cats): ?>
  <div class="cat-chips">
    <div class="cat-chip on" data-cat="">All</div>
    <?php foreach ($cats as $ct): ?><div class="cat-chip" data-cat="<?= e($ct) ?>"><?= e($ct) ?></div><?php endforeach; ?>
  </div>
  <?php endif; ?>
  <div class="cat-grid" id="cGrid">
  <?php foreach ($items as $it): ?>
    <div class="cat-card" data-cat="<?= e($it['cat_name'] ?? '') ?>">
      <?php if ($it['photo']): ?><img src="<?= e($it['photo']) ?>" alt="<?= e($it['name']) ?>" loading="lazy">
      <?php else: ?><div class="ph">📦</div><?php endif; ?>
      <div class="cbody">
        <div class="cname"><?= e($it['name']) ?></div>
        <?php if ($it['brand']): ?><div class="muted"><?= e(trim($it['brand'] . ' ' . $it['model'])) ?></div><?php endif; ?>
        <div class="cprice">₹<?= money($it['selling_price']) ?></div>
        <button type="button" class="btn btn-sm addbtn" data-id="<?= $it['id'] ?>" data-name="<?= e($it['name']) ?>" data-price="<?= (float)$it['selling_price'] ?>">🛒 Add to Cart</button>
        <div class="qtyrow" style="display:none" data-qid="<?= $it['id'] ?>">
          <button type="button" class="q-minus">−</button><span class="q-num">1</span><button type="button" class="q-plus">＋</button>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
  <?php if (!$items): ?><p class="muted">No products listed yet.</p><?php endif; ?>
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
        <div class="field"><label>Your Name *</label><input type="text" name="customer_name" required></div>
        <div class="field"><label>Mobile (WhatsApp) *</label><input type="tel" name="mobile" required pattern="[0-9]{10,12}"></div>
        <div class="field"><label>Address / Area</label><input type="text" name="address"></div>
        <div class="field"><label>Anything specific to mention?</label><input type="text" name="order_notes"></div>
        <button class="btn btn-success btn-block" type="submit">✅ Send Order</button>
        <button class="btn btn-muted btn-block mt" type="button" onclick="document.getElementById('orderModal').classList.remove('show')">Close</button>
      </form>
    </div>
  </div>
<?php endif; ?>
  <?php if ($waShop): ?><p class="muted mt" style="text-align:center">📞 Contact directly: <a href="https://wa.me/<?= e($waShop) ?>" target="_blank" rel="noopener">WhatsApp us</a></p><?php endif; ?>
  <p class="mt" style="text-align:center"><a class="btn btn-sm btn-outline" href="referral.php">💰 Refer &amp; Earn — પાર્ટનર બનો</a></p>
  <?php if (!current_user()): ?><p class="muted mt" style="text-align:center;font-size:12px"><a href="login.php" style="color:inherit">Staff Login</a></p><?php endif; ?>
  <?php if (current_user() && can('items.edit')): $hidden = (int)val('SELECT COUNT(*) FROM items WHERE is_active = 1 AND show_on_website = 0'); ?>
    <?php if ($hidden): ?><p class="muted mt" style="text-align:center">ℹ️ (Admin: <?= $hidden ?> items are OFF on the website — turn them ON via 🌐 on the Items page.)</p><?php endif; ?>
  <?php endif; ?>
</div>
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
  if (count > 0) {
    bar.classList.add('show');
    document.getElementById('cartInfo').textContent = count + ' item · ₹' + total.toFixed(2);
  } else {
    bar.classList.remove('show');
  }
}
document.querySelectorAll('.addbtn').forEach(function (b) {
  b.addEventListener('click', function () {
    var id = b.dataset.id;
    meta[id] = { name: b.dataset.name, price: parseFloat(b.dataset.price) };
    cart[id] = 1;
    b.style.display = 'none';
    document.querySelector('.qtyrow[data-qid="' + id + '"]').style.display = 'flex';
    refreshCart();
  });
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
// search + category filter
var filterInp = document.getElementById('cFilter');
function applyFilter() {
  var q = (filterInp.value || '').toLowerCase();
  var cat = (document.querySelector('.cat-chip.on') || {}).dataset ? document.querySelector('.cat-chip.on').dataset.cat : '';
  document.querySelectorAll('#cGrid .cat-card').forEach(function (c) {
    var okQ = c.textContent.toLowerCase().indexOf(q) > -1;
    var okC = !cat || c.dataset.cat === cat;
    c.style.display = okQ && okC ? '' : 'none';
  });
}
if (filterInp) filterInp.addEventListener('input', applyFilter);
document.querySelectorAll('.cat-chip').forEach(function (ch) {
  ch.addEventListener('click', function () {
    document.querySelectorAll('.cat-chip').forEach(function (x) { x.classList.remove('on'); });
    ch.classList.add('on');
    applyFilter();
  });
});
</script>
</body>
</html>
