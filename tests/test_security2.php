<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); die('CLI only'); }
// Security additions (51-100): each one must refuse what it should refuse.
require_once dirname(__DIR__) . '/includes/webauthn.php';
require_once dirname(__DIR__) . '/includes/gdrive.php';

t_group('Security: masked numbers');
if (!can('parties.contact')) {
    t_eq('without permission: first 2 and last 3 only', show_mobile('+91 98765 43210'), '91•••••••210');
    t_eq('a 10-digit number', show_mobile('9876543210'), '98•••••210');
    t_eq('no number stays empty', show_mobile(''), '');
    t_ok('no tel: link either', strpos(mobile_link('9876543210'), 'tel:') === false);
} else t_ok('(test user may see contacts - masking checked in the browser test)', true);

t_group('Security: a login from a new device');
q("INSERT INTO users (name, mobile, username, password, role_id, location_id) VALUES ('Dev Test', '9000000003', ?, 'x', (SELECT MIN(id) FROM roles), (SELECT MIN(id) FROM locations))", ['devt' . mt_rand(1000, 9999)]);
$dv = insert_id();
$_COOKIE['akdev'] = str_repeat('a', 32);
t_ok('the first device is only remembered', device_check($dv) === false && (int)val('SELECT COUNT(*) FROM known_devices WHERE user_id = ?', [$dv]) === 1);
t_ok('the same device again is quiet', device_check($dv) === false);
$_COOKIE['akdev'] = str_repeat('b', 32);
t_ok('a second device is reported to the owner', device_check($dv) === true);
t_ok('only a hash of the device id is kept', !val('SELECT id FROM known_devices WHERE device_hash = ?', [str_repeat('b', 32)]));
unset($_COOKIE['akdev']);

t_group('Security: staff only from the shop WiFi');
$keepIp = [setting('ip_whitelist', ''), setting('ip_whitelist_staff_only', '0')];
set_setting('ip_whitelist', '10.1.1.0/24'); set_setting('ip_whitelist_staff_only', '1');
$staffRole = (int)val("SELECT id FROM roles WHERE permissions NOT LIKE '%\"*\"%' LIMIT 1");
$adminRole = (int)val("SELECT id FROM roles WHERE permissions LIKE '%\"*\"%' LIMIT 1");
t_ok('staff inside the shop WiFi: yes', ip_allowed_for(['role_id' => $staffRole], '10.1.1.25'));
t_ok('staff from outside: no', !ip_allowed_for(['role_id' => $staffRole], '49.36.1.1'));
t_ok('the owner from outside: yes, when "staff only" is on', ip_allowed_for(['role_id' => $adminRole], '49.36.1.1'));
set_setting('ip_whitelist_staff_only', '0');
t_ok('...and no, when it is off', !ip_allowed_for(['role_id' => $adminRole], '49.36.1.1'));
set_setting('ip_whitelist', $keepIp[0]); set_setting('ip_whitelist_staff_only', $keepIp[1]);

t_group('Security: recycle bin brings back only what carries no money');
t_ok('a bill can never come back this way', recycle_restore(['sales' => [['id' => 1]]]) !== '');
$fid = (function () { q("INSERT INTO follow_ups (title, due_date, status, created_by) VALUES ('Recycle me', CURDATE(), 'pending', 1)"); return insert_id(); })();
$rowF = all('SELECT * FROM follow_ups WHERE id = ?', [$fid]); q('DELETE FROM follow_ups WHERE id = ?', [$fid]);
t_eq('a deleted follow-up comes back with the same id', [recycle_restore(['follow_ups' => $rowF]), (int)val('SELECT id FROM follow_ups WHERE id = ?', [$fid])], ['', $fid]);
t_ok('a column name cannot carry SQL', recycle_restore(['follow_ups' => [['id`) VALUES (1); --' => 1]]]) !== '');

t_group('Security: the owner\'s code for a big bill change');
$_SESSION['edit_code'] = ['sale' => 77, 'hash' => password_hash('123456', PASSWORD_DEFAULT), 'until' => time() + 900, 'tries' => 0];
t_ok('wrong bill: no', !edit_code_ok(78, '123456'));
t_ok('wrong code: no', !edit_code_ok(77, '654321'));
t_ok('right code: yes', edit_code_ok(77, '123456'));
t_ok('...once only', !edit_code_ok(77, '123456'));
$_SESSION['edit_code'] = ['sale' => 77, 'hash' => password_hash('123456', PASSWORD_DEFAULT), 'until' => time() - 1, 'tries' => 0];
t_ok('an old code: no', !edit_code_ok(77, '123456'));
unset($_SESSION['edit_code']);

t_group('Security: fingerprint login checks the signature');
$_SERVER['HTTP_HOST'] = 'shop.example.in';
$key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
$det = openssl_pkey_get_details($key);
$pem = wa_cose_to_pem([1 => 2, 3 => -7, -1 => 1, -2 => str_pad($det['ec']['x'], 32, "\0", STR_PAD_LEFT), -3 => str_pad($det['ec']['y'], 32, "\0", STR_PAD_LEFT)]);
t_eq('our key reading matches openssl', openssl_pkey_get_details(openssl_pkey_get_public($pem))['key'], $det['key']);
$chal = random_bytes(32);
$client = json_encode(['type' => 'webauthn.get', 'challenge' => wa_b64u($chal), 'origin' => 'http://shop.example.in']);
$ad = hash('sha256', 'shop.example.in', true) . "\x05" . pack('N', 7);
openssl_sign($ad . hash('sha256', $client, true), $sig, $key, OPENSSL_ALGO_SHA256);
$cred = ['public_key' => $pem, 'sign_count' => 3];
t_eq('a real signature logs in', wa_login_verify($cred, wa_b64u($ad), wa_b64u($client), wa_b64u($sig), $chal), 7);
$fails = 0;
foreach ([[wa_b64u($ad), wa_b64u($client), wa_b64u($sig), random_bytes(32)],                                   // another challenge
          [wa_b64u(hash('sha256', 'evil.in', true) . substr($ad, 32)), wa_b64u($client), wa_b64u($sig), $chal],  // another site
          [wa_b64u($ad), wa_b64u($client), wa_b64u(strrev($sig)), $chal]] as $try) {                           // a broken signature
    try { wa_login_verify($cred, ...$try); } catch (Exception $e) { $fails++; }
}
t_eq('another challenge, another site or a broken signature: all refused', $fails, 3);
try { wa_login_verify(['public_key' => $pem, 'sign_count' => 9], wa_b64u($ad), wa_b64u($client), wa_b64u($sig), $chal); $copied = false; } catch (Exception $e) { $copied = true; }
t_ok('a counter that went backwards (a copied key) is refused', $copied);
unset($_SERVER['HTTP_HOST']);

t_group('Security: Drive gets only a locked backup');
$keepD = setting('gdrive_refresh_token', '');
set_setting('gdrive_refresh_token', 'rt-test');
$calls = [];
$GLOBALS['gdrive_http_mock'] = function ($url, $post, $headers, $raw) use (&$calls) {
    $calls[] = $url;
    return strpos($url, 'oauth2') !== false ? [200, ['access_token' => 'at']] : [200, ['id' => 'f1', 'name' => 'x']];
};
$tmp = tempnam(sys_get_temp_dir(), 'bk');
t_eq('an unlocked backup is never sent', [gdrive_upload($tmp)[0], count($calls)], [false, 0]);
rename($tmp, $tmp . '.enc'); file_put_contents($tmp . '.enc', 'AKENC1...');
t_ok('a locked one is', gdrive_upload($tmp . '.enc')[0] === true && count($calls) === 2);
unlink($tmp . '.enc'); unset($GLOBALS['gdrive_http_mock']);
set_setting('gdrive_refresh_token', $keepD);
