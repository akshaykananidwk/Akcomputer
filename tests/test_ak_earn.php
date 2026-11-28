<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); die('CLI only'); }
// AK Earn partner tracking: what is reported, when, and that it can never
// break a page, leak the secret or report another shop's business.
t_group('AK Earn: the shapes sent to the master');
t_ok('the suite never sends anything (dry run)', defined('AK_EARN_DRY_RUN'));
t_eq('an item has a stable product code', ak_earn_item_code(42), 'item-42', 0);
t_eq('an order is reported under the line with the most money', ak_earn_order_code([
    ['id' => 1, 'qty' => 3, 'price' => 100], ['id' => 2, 'qty' => 1, 'price' => 500], ['id' => 3, 'total' => 450]]), 'item-2', 0);
$wo = ak_earn_web_order(['id' => 9, 'order_no' => 'WEB-00009', 'total' => '1250.50', 'customer_name' => 'R', 'mobile' => '+91 98765 43210',
                         'items_json' => json_encode([['id' => 5, 'qty' => 2, 'price' => 625.25, 'total' => 1250.5]])]);
t_eq('the order id is the order number', $wo['id'], 'WEB-00009', 0);
t_eq('the amount is the order total', $wo['amount'], 1250.5);
t_eq('the mobile is sent as 10 digits', $wo['mobile'], '9876543210', 0);
t_ok('no commission figure is ever part of it', strpos(json_encode($wo), 'commission') === false);
$pr = ak_earn_item_product(['id' => 7, 'name' => 'Mouse', 'variant' => '', 'selling_price' => 450, 'description' => '<b>Wireless</b>',
                            'photo' => 'uploads/m.jpg', 'is_active' => 1, 'show_on_website' => 1]);
t_eq('a website item is an active one-time product', $pr['billing'] . '/' . $pr['status'], 'one_time/active', 0);
t_ok('its picture and page are full addresses', strpos($pr['url'], 'product.php?id=7') !== false && strpos($pr['image'] ?? 'https://', 'http') === 0);
t_eq('no HTML in the description', $pr['description'], 'Wireless', 0);
t_eq('an item taken off the website is inactive', ak_earn_item_product(['id' => 7, 'name' => 'M', 'selling_price' => 1, 'is_active' => 1, 'show_on_website' => 0])['status'], 'inactive', 0);
t_eq('a yearly plan has its own code', ak_earn_plan_code(['code' => 'Pro', 'id' => 3], 12), 'plan-pro-yearly', 0);

t_group('AK Earn: only this shop\'s own business, never another shop\'s');
$GLOBALS['_ak_earn_jobs'] = [];
$GLOBALS['_tenant'] = ['id' => 999, 'slug' => 'othershop'];
ak_earn(fn() => null, 'x', 'shop');
t_ok('a shop event on another shop\'s address is dropped', empty($GLOBALS['_ak_earn_jobs']));
t_eq('and their pages get no script', ak_earn_tag(), '', 0);
unset($GLOBALS['_tenant']);
$GLOBALS['_ak_earn_jobs'] = [];
t_ok('ak_earn() never throws, even for nonsense', (function () { ak_earn('not_a_function_at_all'); return true; })());
$GLOBALS['_ak_earn_jobs'] = [];

t_group('AK Earn: the secret stays on the server');
t_ok('the secret is one of the encrypted settings', is_secret_setting('ak_earn_site_secret'));
$tag = ak_earn_tag();
$c = ak_earn_conf();
t_ok('the page script carries no secret', $c['secret'] === '' || strpos($tag, $c['secret']) === false);
t_ok('the SDK file is the downloaded one, unedited', hash_file('sha256', __DIR__ . '/../includes/vendor/ak-connect.php') === '576f7879d4dad87b533102e3b944af5c1104b0bd8da79b1d42a74e5e3bb457ff');
t_ok('the library folder is closed to the web', strpos((string)@file_get_contents(__DIR__ . '/../includes/vendor/.htaccess'), 'Require all denied') !== false);

t_group('AK Earn: each event is hooked where it happens');
$src = fn($f) => (string)file_get_contents(__DIR__ . '/../' . $f);
t_ok('dealer sign-up on the store', strpos($src('catalog.php'), "ak_track_signup(\$rname, mobile10(\$rmobile)") !== false);
t_ok('store order placed (not paid)', strpos($src('catalog.php'), 'ak_earn_order_created($oid)') !== false);
t_ok('shop-app order placed (not paid)', strpos($src('api.php'), 'ak_earn_order_created($oid)') !== false);
t_ok('web order completed / cancelled', strpos($src('web_orders.php'), 'ak_earn_order_status($oid, $oldStatus, $newStatus)') !== false);
t_ok('software sign-up', strpos($src('signup.php'), 'ak_earn_shop_signup($done)') !== false);
t_ok('plan paid only when the webhook really marked it paid', strpos($src('razorpay_webhook.php'), "if (\$res === 'ok') ak_earn_plan_paid(\$refId);") !== false);
t_ok('an unpaid plan is never reported as paid', strpos($src('includes/ak_earn.php'), "if (\$p['status'] !== 'paid') return null;") !== false);
foreach (['catalog.php', 'product.php', 'category.php', 'brand.php', 'price.php', 'services.php', 'privacy.php', 'terms.php', 'referral.php'] as $pf)
    t_ok("$pf carries the browser script", strpos($src($pf), '<?= ak_earn_tag()') !== false
         || (preg_match('/store_end\(|store_landing\(/', $src($pf)) && strpos($src('includes/store_ui.php'), '<?= ak_earn_tag() ?>') !== false));
t_ok('staff screens do not count as visits', strpos($src('includes/footer.php'), '!current_user()) echo ak_earn_tag()') !== false);

t_group('AK Earn: a partial product update never switches the rest off');
$ak = (string)file_get_contents(__DIR__ . '/../includes/ak_earn.php');
t_ok('one item is sent as a partial update', strpos($ak, 'ak_sync_products([$p], false)') !== false);
t_ok('plans alone are sent as a partial update', strpos((string)file_get_contents(__DIR__ . '/../platform.php'), 'ak_sync_products(ak_earn_plan_products(), false)') !== false);
t_ok('the full list goes in one request, parts only as partial', strpos($ak, '$full ? [$products] : array_chunk($products, 200)') !== false);
