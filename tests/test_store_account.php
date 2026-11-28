<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); die('CLI only'); }
// ============================================================================
//  ONLINE STORE CUSTOMER ACCOUNTS (28-11-2026): register with a mobile or an
//  e-mail, log in with a password or a one-time code, reset a forgotten
//  password, see only your own orders, keep the wishlist on the account.
//  Guest checkout and the dealer (B2B) login stay separate and unchanged.
// ============================================================================
require_once __DIR__ . '/../includes/store_ui.php';
t_group('Store accounts: register and log in');
$_SESSION = $_SESSION ?? [];
unset($_SESSION['store_cust_id']);
$_SERVER['REMOTE_ADDR'] = '10.9.8.' . random_int(1, 250);
q("DELETE FROM login_throttle WHERE bucket_key LIKE '%10.9.8.%' OR bucket_key LIKE 'rate:scotp%'");
t_eq('a mobile is read the same however it is written', [sc_parse_login('+91 98250 22222'), sc_parse_login('098250 22222'), sc_parse_login('Ri@Mail.IN')],
     [['mobile', '9825022222'], ['mobile', '9825022222'], ['email', 'ri@mail.in']]);
t_eq('a short number or a bad e-mail is refused', [sc_parse_login('12345')[0], sc_parse_login('a@b')[0]], [null, null]);
[$ok, $m] = sc_register('ST Riya', '+91 98250 22222', 'secret12');
t_ok('register with a mobile: account made and logged in', $ok && sc_current() && sc_current()['mobile'] === '9825022222', $m);
$rid = (int)sc_current()['id'];
t_ok('the password is stored as a hash, never as typed', strpos((string)val('SELECT password_hash FROM store_customers WHERE id = ?', [$rid]), 'secret12') === false);
t_eq('the same mobile cannot register twice', sc_register('Other', '9825022222', 'another1')[0], false);
t_eq('a password shorter than 6 is refused', sc_register('Short', 'short@x.in', '12345')[0], false);
unset($_SESSION['store_cust_id']);
t_eq('a wrong password does not log in', sc_login_password('9825022222', 'nope1234')[0], false);
t_ok('the right password does', sc_login_password('98250 22222', 'secret12')[0] && (int)sc_current()['id'] === $rid);
unset($_SESSION['store_cust_id']);
for ($i = 0; $i < 6; $i++) sc_login_password('9825022222', 'wrong' . $i);
t_eq('after too many wrong tries even the right password waits', sc_login_password('9825022222', 'secret12')[0], false);
q("DELETE FROM login_throttle WHERE bucket_key LIKE '%10.9.8.%'");

t_group('Store accounts: one-time codes');
$tgt = sc_otp_target('mobile', '9825022222');
$code = create_otp('store_login', $tgt, 10);
t_eq('a wrong code fails', sc_check_otp('9825022222', 'login', '000000') && $code !== '000000', false);
t_ok('the right code passes once', sc_check_otp('+91 9825022222', 'login', $code));
t_eq('...and never twice', sc_check_otp('9825022222', 'login', $code), false);
$rc = create_otp('store_reset', $tgt, 10);
t_eq('a login code cannot reset a password (each code has one purpose)', sc_check_otp('9825022222', 'login', $rc), false);
t_ok('an e-mail is never used raw as the code key', strpos(sc_otp_target('email', 'ri@mail.in'), 'ri@mail.in') === false);
$a1 = sc_send_otp('9000099999', 'login');
t_ok('asking a code for a number with no account gives the same answer (nobody can test numbers)', $a1[0] && strpos($a1[1], 'If an account exists') === 0);

t_group('Store accounts: my orders, my wishlist, no dealer prices');
q("INSERT INTO web_orders (order_no, customer_name, mobile, address, items_json, total, status) VALUES ('ST-G1', 'Riya', '+91 98250 22222', 'x', '[]', 100, 'new')");
q("INSERT INTO web_orders (order_no, customer_name, mobile, address, items_json, total, status, store_customer_id) VALUES ('ST-A1', 'Riya', '9825022222', 'x', '[]', 200, 'new', ?)", [$rid]);
q("INSERT INTO web_orders (order_no, customer_name, mobile, address, items_json, total, status) VALUES ('ST-X1', 'Someone', '9111111111', 'x', '[]', 300, 'new')");
$cust = row('SELECT * FROM store_customers WHERE id = ?', [$rid]);
$nos = fn($c) => array_column(sc_orders($c), 'order_no');
t_eq('before the mobile is verified: only orders placed on the account', array_values(array_intersect($nos($cust), ['ST-G1', 'ST-A1', 'ST-X1'])), ['ST-A1']);
q('UPDATE store_customers SET mobile_verified = 1 WHERE id = ?', [$rid]);
$cust = row('SELECT * FROM store_customers WHERE id = ?', [$rid]);
$mine = array_intersect($nos($cust), ['ST-G1', 'ST-A1', 'ST-X1']); sort($mine);
t_eq('after verifying: the guest orders with the same mobile too — never anyone else\'s', array_values($mine), ['ST-A1', 'ST-G1']);
$it = t_item(0, null, 500); q('UPDATE items SET show_on_website = 1 WHERE id = ?', [$it]);
t_eq('wishlist: what the phone had is merged into the account', sc_wishlist_sync($rid, [$it, 99999999], 'merge'), [$it]);
t_eq('...and removing it on the phone removes it on the account', sc_wishlist_sync($rid, [], 'replace'), []);
t_eq('order steps: a new order is only "Placed"', array_column(array_filter(sc_order_steps('new'), fn($x) => $x[1]), 0), ['Placed']);
t_eq('a delivered order has every step done', count(array_filter(sc_order_steps('delivered'), fn($x) => $x[1])), 4);
$src = file_get_contents(__DIR__ . '/../includes/store_ui.php');
t_ok('a customer login never brings dealer prices (they come only from the dealer login)', strpos($src, "'pct' => \$acct ? (float)\$acct['discount_pct'] : 0") !== false && strpos($src, "\$acct = !empty(\$_SESSION['web_account_id'])") !== false);
t_ok('guest checkout still works without any login', strpos(file_get_contents(__DIR__ . '/../catalog.php'), "if (\$name && \$mobile && \$cart) {") !== false);
unset($_SESSION['store_cust_id']);
