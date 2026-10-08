<?php
// 📲 The shop phone forwards its "money received" SMS here (any SMS-forwarder
// app: POST the text to this link). It only goes into the UPI inbox - a
// person then records each one as a payment. Secret key in the link.
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/connect.php';
header('Content-Type: application/json');
$k = (string)setting('upi_hook_secret', '');
if ($k === '' || !hash_equals($k, (string)($_GET['k'] ?? '')) || !api_rate_ok('upihook:' . client_ip(), 120, 3600)) { http_response_code(403); exit('{"ok":false}'); }
$in = json_decode((string)file_get_contents('php://input'), true);
$text = (string)($in['text'] ?? $in['message'] ?? $in['body'] ?? ($_POST['text'] ?? $_POST['message'] ?? ''));
$p = upi_parse_sms($text);
if (!$p) exit('{"ok":true,"saved":false}');
$ref = $p['ref'] !== '' ? $p['ref'] : 'sms-' . substr(sha1($text), 0, 16);
$new = q('INSERT IGNORE INTO upi_inbox (amount, payer, ref, raw) VALUES (?,?,?,?)', [$p['amount'], mb_substr($p['payer'], 0, 120), $ref, mb_substr($text, 0, 500)])->rowCount() > 0;   // the same SMS twice is kept once
if ($new) big_payment_alert($p['amount'], $p['payer'], 'UPI received');
echo json_encode(['ok' => true, 'saved' => $new]);
