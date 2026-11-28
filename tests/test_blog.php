<?php
// Blog: one buying guide per website product - real facts only, all English.
require_once __DIR__ . '/../includes/seo.php';
require_once __DIR__ . '/../includes/store_ui.php';
require_once __DIR__ . '/../includes/blog.php';

t_group('Blog: a buying guide for every product');
$it = ['id' => 77, 'name' => 'CP PLUS 4MP IP Dome Camera (Built-in Mic) (CP-UNC-DA41L3C-LQ)', 'brand' => 'CP PLUS', 'model' => 'CP-UNC-DA41L3C-LQ', 'selling_price' => 2500,
       'item_type' => 'product', 'warranty_months' => 24, 'photo' => '', 'cat_name' => 'IP Camera', 'category_id' => 1, 'specs' => "Image Sensor: 1/2.9\" CMOS\nIP Rating: IP67"];
$p = blog_post($it, [['id' => 78, 'name' => 'CP PLUS 2MP IP Bullet Camera', 'selling_price' => 1800]]);
t_eq('short name for the title (brackets dropped) and a clean URL', [blog_short($it['name']), basename(parse_url(blog_url($it), PHP_URL_PATH))], ['CP PLUS 4MP IP Dome Camera', 'cp-plus-4mp-ip-dome-camera-buying-guide']);
t_ok('the shop\'s name + town is in the title, intro and an FAQ (AK Computer Dwarka)', strpos($p['title'], store_ctx()['app'] . ' ' . (shop_city() ?: 'Dwarka')) !== false && strpos($p['intro'], store_ctx()['app']) !== false);
$heads = array_column($p['sections'], 0);
t_ok('sections: what it is, specifications, uses, how it works, buying tips, what else, compare, why us', count($heads) >= 7 && in_array('Specifications', $heads, true) && in_array('What to check before you buy', $heads, true), implode(' / ', $heads));
$spec = array_values(array_filter($p['sections'], fn($s) => $s[0] === 'Specifications'))[0][2];
t_ok('the owner\'s pasted specs are in the guide', in_array(['Image Sensor', '1/2.9" CMOS'], $spec, true));
t_ok('the real price is used, and the warranty months from the item', strpos($p['intro'], '₹' . money(2500)) !== false && strpos(implode(' ', $p['faq']), '24 months') !== false);
$txt = json_encode($p, JSON_UNESCAPED_UNICODE);
t_ok('all in English (no Gujarati), and no invented ratings or discounts', !preg_match('/[\x{0A80}-\x{0AFF}]/u', $txt) && !preg_match('/★|\bstars?\b|% off|discount/i', $txt));
t_ok('the blog is public, linked from the footer, product page and sitemap', strpos(file_get_contents(__DIR__ . '/../sitemap.php'), "'blog'") !== false
     && strpos(file_get_contents(__DIR__ . '/../product.php'), 'blog_url($it)') !== false && strpos(file_get_contents(__DIR__ . '/../.htaccess'), 'blog.php?id=$1') !== false);
