<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); die('CLI only'); }
// ============================================================================
//  ONLINE STORE (28-11-2026 redesign) - the order products are shown in comes
//  from real sales, never from how many pieces are lying on the shelf; the
//  admin's pin wins; search forgives a typo and knows other words; nothing
//  shown to a customer is invented (no discount without a real MRP, no
//  sample "customer" reviews, no buying price in the public index).
// ============================================================================
require_once __DIR__ . '/../includes/store_ui.php';
require_once __DIR__ . '/../includes/sale_save.php';
t_group('Online store: smart product order');
$u = row('SELECT u.*, l.name location_name FROM users u JOIN locations l ON l.id = u.location_id ORDER BY u.id LIMIT 1'); $u['perms'] = ['*'];
$loc = (int)$u['location_id'];
$co = (int)val('SELECT id FROM companies ORDER BY id LIMIT 1');
$mk = function ($name, $price, $photo = 'uploads/x.jpg', $stock = 0, $extra = []) use ($loc) {
    q("INSERT INTO items (name, brand, model, selling_price, purchase_price, tax_rate, is_active, item_type, show_on_website, photo) VALUES (?, 'ST', '', ?, ?, 0, 1, 'product', 1, ?)",
      [$name, $price, round($price * .7, 2), $photo]);
    $id = (int)val('SELECT MAX(id) FROM items');
    if ($stock) adjust_stock($id, $loc, $stock, 'opening', null, 't');
    foreach ($extra as $k => $v) q("UPDATE items SET `$k` = ? WHERE id = ?", [$v, $id]);
    return $id;
};
$sold = $mk('ST Zeta HDMI Cable 1.5m', 150, 'uploads/a.jpg', 3);
$pile = $mk('ST Alpha Old Speaker', 900, 'uploads/b.jpg', 400);   // a big pile nobody buys
$nophoto = $mk('ST Beta Router', 1200, '', 5);
$sell = function ($id, $qty, $mobile) use ($u, $co, $loc) {
    $_POST = ['company_id' => $co, 'location_id' => $loc, 'sale_date' => today(), 'payment_mode' => 'cash', 'paid' => 0, 'customer_name' => 'ST', 'customer_mobile' => $mobile,
              'row_n' => [1], 'item_id' => [$id], 'qty' => [$qty], 'price' => [150], 'tax_rate' => [0]];
    sale_save_form($u); $_POST = [];
};
$sell($sold, 1, '9000000001'); $sell($sold, 1, '9000000002'); $sell($sold, 1, '9000000001');
$mine = array_values(array_filter(store_items(), fn($i) => in_array((int)$i['id'], [$sold, $pile, $nophoto], true)));
$order = array_map(fn($i) => (int)$i['id'], store_sort($mine));
t_eq('the product customers keep buying comes before a big pile nobody buys', array_search($sold, $order) < array_search($pile, $order), true);
t_eq('a product without a photo goes to the back', end($order), $nophoto);
q('UPDATE items SET web_priority = 1 WHERE id = ?', [$pile]);
$mine = array_map(fn($i) => (int)$i['id'] === $pile ? ['web_priority' => 1] + $i : $i, $mine);
t_eq('the admin\'s 📌 pin always comes first', (int)store_sort($mine)[0]['id'], $pile);
t_eq('no photo or no price = not pushed in the home page rows', [store_promotable(['selling_price' => 100, 'photo' => '', 'item_type' => 'product']), store_promotable(['selling_price' => 0, 'photo' => 'a.jpg', 'item_type' => 'product'])], [false, false]);

t_group('Online store: search');
$cam = ['name' => 'CP PLUS 2.4MP Dual Light Bullet CCTV Camera (CP-URC-TC24PL3C)', 'brand' => 'CP Plus', 'model' => 'CP-URC-TC24PL3C', 'barcode' => '8901', 'cat_name' => 'HD Camera', 'variant' => ''];
$kb = ['name' => 'HP KM260 Wireless Keyboard and Mouse Combo', 'brand' => 'HP', 'model' => 'KM260', 'barcode' => '', 'cat_name' => 'Keyboard', 'variant' => ''];
$hit = fn($q) => array_map(fn($x) => $x['model'], store_search([$cam, $kb], $q));
t_eq('a typo still finds it ("keybord", "camra")', [$hit('keybord'), $hit('camra')], [['KM260'], ['CP-URC-TC24PL3C']]);
t_eq('other words for the same thing ("security camera", "wireless mouse")', [$hit('security camera'), $hit('wireless mouse')], [['CP-URC-TC24PL3C'], ['KM260']]);
t_eq('Gujarati words work too (કેમેરા, માઉસ)', [$hit('કેમેરા'), $hit('માઉસ')], [['CP-URC-TC24PL3C'], ['KM260']]);
t_eq('the model number / SKU finds it, in any word order', [$hit('tc24pl3c'), $hit('8901'), $hit('combo hp')], [['CP-URC-TC24PL3C'], ['CP-URC-TC24PL3C'], ['KM260']]);
t_eq('an unrelated word finds nothing', $hit('toner'), []);

t_group('Online store: nothing invented');
t_eq('specs are read from the product name only', array_column(store_specs($cam), 1), ['2.4MP', 'Color Night']);
t_eq('a plain name gives no specs', store_specs(['name' => 'BNC PIN']), []);
t_eq('no MRP = no discount shown', store_mrp(['mrp' => null], 1599), [0, 0]);
t_eq('an MRP the shop typed above the price shows the real saving', store_mrp(['mrp' => 1999], 1599), [1999.0, 20]);
t_eq('an MRP at or below the price shows nothing', store_mrp(['mrp' => 1599], 1599), [0, 0]);
$idx = store_index(array_values(array_filter(store_items(), fn($i) => (int)$i['id'] === $sold)));
t_ok('the public search index carries no buying price or stock count', $idx && !array_intersect(array_keys($idx[0]), ['purchase_price', 'cost', 'qty', 'b2b_price']));
$catSrc = file_get_contents(__DIR__ . '/../catalog.php');
t_ok('no sample "customer" testimonials are written into the store', strpos($catSrc, 'Ramesh, Dwarka|') === false && strpos($catSrc, 'Hiren (hotel)') === false);
t_ok('home page reviews come only from approved customer reviews', strpos($catSrc, "FROM product_reviews r JOIN items i ON i.id = r.item_id\n                      WHERE r.status = 'approved'") !== false);
t_ok('the old "&amp;" banner text (shown as "&amp;" on the page) is gone', strpos($catSrc, 'computer &amp; CCTV shop') === false);
$pSrc = file_get_contents(__DIR__ . '/../product.php');
t_ok('product schema: out of stock is never marked in stock', strpos($pSrc, "'availability' => \$sk === 'out' ? 'https://schema.org/BackOrder' : 'https://schema.org/InStock'") !== false);
t_ok('orders are still re-priced from the database, not the browser', strpos($catSrc, "// re-price from DB (never trust browser prices)") !== false);
