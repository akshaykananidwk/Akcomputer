<?php
// 🖐️ Counter mode for a tablet: big item tiles, a cart, and one tap to take
// the money. It writes the bill through the SAME save as the normal bill
// screen (sales.php), so stock, GST, the party ledger and the payment follow
// exactly the same rules - this page only collects the lines.
require_once __DIR__ . '/includes/init.php';
require_perm('sales.add');
$u = current_user();
$company = row('SELECT * FROM companies WHERE is_active = 1 ORDER BY id LIMIT 1');
$items = all("SELECT i.id, i.name, i.selling_price price, i.tax_rate tax, i.unit, i.item_type, COALESCE(c.name, '') cat
              FROM items i LEFT JOIN categories c ON c.id = i.category_id
              LEFT JOIN (SELECT si.item_id, COUNT(*) n FROM sale_items si JOIN sales s ON s.id = si.sale_id
                         WHERE s.sale_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY) GROUP BY si.item_id) pop ON pop.item_id = i.id
              WHERE i.is_active = 1 AND i.serial_tracked = 0 AND i.selling_price > 0
              ORDER BY COALESCE(pop.n, 0) DESC, i.name LIMIT 300");
$cats = array_values(array_unique(array_filter(array_column($items, 'cat'))));
$pms = array_values(array_filter(active_payment_methods(), fn($p) => in_array($p['code'], ['cash', 'upi', 'card'], true)));
$bank = val('SELECT id FROM bank_accounts WHERE is_active = 1 ORDER BY is_default DESC, id LIMIT 1');
$page_title = 'Counter';
include __DIR__ . '/includes/header.php';
?>
<style>
.pos{display:grid;grid-template-columns:1fr 340px;gap:12px;align-items:start}
@media (max-width:820px){.pos{grid-template-columns:1fr}.pos-cart{position:sticky;bottom:calc(var(--bottomnav-h) + 4px)}}
.pos-cats{display:flex;gap:6px;overflow-x:auto;padding-bottom:6px}
.pos-cats button{white-space:nowrap}
.pos-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:8px;margin-top:8px}
.pos-tile{border:1px solid var(--border);background:var(--card);color:var(--text);border-radius:12px;padding:12px 10px;text-align:left;min-height:84px;cursor:pointer;font:inherit}
.pos-tile b{display:block;font-size:14px;line-height:1.25;margin-bottom:6px}
.pos-tile span{color:var(--muted);font-size:13px}
.pos-tile:active{transform:scale(.97)}
.pos-cart{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:12px}
.pos-line{display:grid;grid-template-columns:1fr auto auto;gap:6px;align-items:center;padding:6px 0;border-bottom:1px solid var(--line)}
.pos-line .q{display:flex;gap:4px;align-items:center}
.pos-line .q button{width:32px;height:32px;border-radius:8px;border:1px solid var(--border);background:var(--card);color:var(--text);font-size:18px}
.pos-total{font-size:26px;font-weight:800;text-align:right;margin:10px 0}
.pos-pay{display:grid;grid-template-columns:repeat(auto-fit,minmax(90px,1fr));gap:6px}
.pos-pay button{padding:14px 6px;font-size:16px}
</style>
<?php if ($done = row('SELECT id, invoice_no, total FROM sales WHERE id = ? AND created_by = ?', [(int)get('done'), $u['id']])): ?>
<div class="flash flash-success">✔ <?= e($done['invoice_no']) ?> — ₹<?= money($done['total']) ?> saved.
  <a href="receipt.php?id=<?= (int)$done['id'] ?>">🧾 Receipt</a> · <a href="sale_view.php?id=<?= (int)$done['id'] ?>">Bill</a></div>
<?php endif; ?>
<div class="page-head"><h1>🖐️ Counter</h1>
  <div style="display:flex;gap:6px"><a class="btn btn-sm btn-outline" href="display.php" target="akDisplay">🖥️ Customer screen</a><a class="btn btn-sm btn-outline" href="sales.php?action=new">Full bill</a></div></div>
<div class="pos">
  <div>
    <input type="search" id="posFind" placeholder="Search item or scan barcode…" style="width:100%">
    <div class="pos-cats"><button type="button" class="chip on" data-cat="">All</button><?php foreach ($cats as $c): ?><button type="button" class="chip" data-cat="<?= e($c) ?>"><?= e($c) ?></button><?php endforeach; ?></div>
    <div class="pos-grid" id="posGrid"></div>
  </div>
  <form class="pos-cart" method="post" action="sales.php" id="posForm">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="save"><input type="hidden" name="from_pos" value="1">
    <input type="hidden" name="company_id" value="<?= (int)$company['id'] ?>"><input type="hidden" name="location_id" value="<?= (int)$u['location_id'] ?>">
    <input type="hidden" name="price_type" value="retail"><input type="hidden" name="sale_date" value="<?= e(today()) ?>">
    <input type="hidden" name="round_off_on" value="1"><input type="hidden" name="discount_type" value="amount"><input type="hidden" name="discount_val" value="0">
    <input type="hidden" name="payment_mode" id="posMode"><input type="hidden" name="paid" id="posPaid"><input type="hidden" name="bank_account_id" value="<?= (int)$bank ?>">
    <div id="posLines" class="muted">Tap an item to add it.</div>
    <div id="posHidden"></div>
    <div class="pos-total">₹<span id="posTotal">0</span></div>
    <input type="tel" name="customer_mobile" placeholder="Customer mobile (optional)" style="width:100%;margin-bottom:6px">
    <input type="text" name="customer_name" placeholder="Customer name (optional)" style="width:100%;margin-bottom:8px">
    <div class="pos-pay">
      <?php foreach ($pms as $p): ?><button type="button" class="btn" data-pay="<?= e($p['code']) ?>"><?= ['cash' => '💵', 'upi' => '📱', 'card' => '💳'][$p['code']] ?? '' ?> <?= e($p['name']) ?></button><?php endforeach; ?>
      <button type="button" class="btn btn-outline" data-pay="credit">📒 Udhar</button>
    </div>
  </form>
</div>
<script>
(function () {
  var ITEMS = <?= json_encode(array_map(fn($i) => ['id' => (int)$i['id'], 'name' => $i['name'], 'price' => (float)$i['price'], 'tax' => (float)$i['tax'], 'unit' => $i['unit'], 'cat' => $i['cat']], $items), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
  var GST = <?= json_encode((bool)$company['is_gst']) ?>, SHOP = <?= json_encode(setting('app_name', ''), JSON_HEX_TAG) ?>;
  var cart = [], cat = '', grid = document.getElementById('posGrid'), find = document.getElementById('posFind');
  var esc = function (t) { return String(t).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); };
  var chan = ('BroadcastChannel' in window) ? new BroadcastChannel('ak-display') : null;
  function drawGrid() {
    var q = find.value.trim().toLowerCase();
    grid.innerHTML = ITEMS.filter(function (it) { return (!cat || it.cat === cat) && (!q || it.name.toLowerCase().indexOf(q) >= 0); }).slice(0, 120)
      .map(function (it) { return '<button type="button" class="pos-tile" data-id="' + it.id + '"><b>' + esc(it.name) + '</b><span>₹' + it.price + ' / ' + esc(it.unit) + '</span></button>'; }).join('');
  }
  function total() {
    var t = 0; cart.forEach(function (l) { var g = l.qty * l.price; t += g + (GST ? g * l.tax / 100 : 0); });
    return Math.round(t);
  }
  function draw() {
    var box = document.getElementById('posLines'), hid = document.getElementById('posHidden');
    box.className = cart.length ? '' : 'muted';
    box.innerHTML = cart.length ? cart.map(function (l, i) {
      return '<div class="pos-line"><div>' + esc(l.name) + '<br><small class="muted">₹' + l.price + '</small></div>' +
        '<div class="q"><button type="button" data-dec="' + i + '">−</button><b>' + l.qty + '</b><button type="button" data-inc="' + i + '">+</button></div>' +
        '<div style="min-width:64px;text-align:right">₹' + Math.round(l.qty * l.price) + '</div></div>';
    }).join('') : 'Tap an item to add it.';
    hid.innerHTML = cart.map(function (l, i) {
      return '<input type="hidden" name="item_id[]" value="' + l.id + '"><input type="hidden" name="row_n[]" value="' + (i + 1) + '">' +
        '<input type="hidden" name="qty[]" value="' + l.qty + '"><input type="hidden" name="price[]" value="' + l.price + '"><input type="hidden" name="tax_rate[]" value="' + l.tax + '">';
    }).join('');
    var t = total();
    document.getElementById('posTotal').textContent = t.toLocaleString('en-IN');
    var state = { shop: SHOP, total: t, lines: cart.map(function (l) { return [l.name, l.qty, Math.round(l.qty * l.price)]; }) };
    if (chan) chan.postMessage(state);
    try { localStorage.setItem('akDisplay', JSON.stringify(state)); } catch (e) {}
  }
  function add(id) {
    var it = ITEMS.filter(function (x) { return x.id === id; })[0]; if (!it) return;
    var l = cart.filter(function (x) { return x.id === id; })[0];
    if (l) l.qty++; else cart.push({ id: it.id, name: it.name, price: it.price, tax: it.tax, qty: 1 });
    draw();
  }
  grid.addEventListener('click', function (ev) { var b = ev.target.closest('[data-id]'); if (b) add(+b.dataset.id); });
  document.querySelector('.pos-cats').addEventListener('click', function (ev) {
    var b = ev.target.closest('[data-cat]'); if (!b) return;
    cat = b.dataset.cat; document.querySelectorAll('.pos-cats .chip').forEach(function (c) { c.classList.toggle('on', c === b); }); drawGrid();
  });
  find.addEventListener('input', drawGrid);
  find.addEventListener('keydown', function (ev) {          // a barcode gun types the name/code and presses Enter
    if (ev.key !== 'Enter') return; ev.preventDefault();
    var first = grid.querySelector('[data-id]'); if (first) { add(+first.dataset.id); find.value = ''; drawGrid(); }
  });
  document.getElementById('posLines').addEventListener('click', function (ev) {
    var i = ev.target.dataset.inc, d = ev.target.dataset.dec;
    if (i !== undefined) cart[+i].qty++;
    if (d !== undefined && --cart[+d].qty <= 0) cart.splice(+d, 1);
    draw();
  });
  document.querySelector('.pos-pay').addEventListener('click', function (ev) {
    var b = ev.target.closest('[data-pay]'); if (!b) return;
    if (!cart.length) { alert('Add an item first.'); return; }
    var f = document.getElementById('posForm');
    if (b.dataset.pay === 'credit' && !f.customer_mobile.value.trim()) { alert('Udhar needs the customer\'s mobile number.'); f.customer_mobile.focus(); return; }
    document.getElementById('posMode').value = b.dataset.pay;
    document.getElementById('posPaid').value = b.dataset.pay === 'credit' ? 0 : total();
    document.querySelectorAll('.pos-pay button').forEach(function (x) { x.disabled = true; });
    f.submit();
  });
  drawGrid(); draw();
})();
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
