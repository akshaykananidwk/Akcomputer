<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); die('CLI only'); }
// Billing finds the customer by mobile, however the number was typed.

t_group('a mobile number is the same however it is typed');
foreach (['+91 90999 61124', '090999 61124', '91-9099961124', '9099961124', '+91-909-996-1124', '(+91) 9099961124'] as $typed)
    t_eq("\"$typed\" is 9099961124", mobile10($typed), '9099961124', 0);
t_eq('a short number is left as its digits', mobile10('12 345'), '12345', 0);

t_group('the saved party is found by its number in any shape');
$tp = t_party('MF Tanveer');
$n = '7' . random_int(100000000, 999999999);   // a number no other test uses
q("UPDATE parties SET mobile = ? WHERE id = ?", ['0' . substr($n, 0, 5) . ' ' . substr($n, 5), $tp]);
foreach ([$n, '+91 ' . $n, '91 ' . substr($n, 0, 5) . '-' . substr($n, 5)] as $typed)
    t_eq("typed \"$typed\" finds them", (int)(party_by_mobile($typed)['id'] ?? 0), $tp);
t_ok('a different number finds nobody', party_by_mobile('9811111111') === null);
t_ok('half a number finds nobody', party_by_mobile('90999') === null);

t_group('the bill form asks for the mobile first and looks the party up');
$src = file_get_contents(__DIR__ . '/../sales.php');
t_ok('the mobile box comes before the party picker', strpos($src, 'name="customer_mobile"') < strpos($src, 'name="party_id" id="party_id"'));
t_ok('…and before the customer name', strpos($src, 'name="customer_mobile"') < strpos($src, 'name="customer_name"'));
t_ok('the save matches the number in any shape', strpos($src, 'party_by_mobile($custMobile)') !== false);
t_ok('quick-billing save does too', strpos(file_get_contents(__DIR__ . '/../includes/sale_save.php'), 'party_by_mobile($custMobile)') !== false);
