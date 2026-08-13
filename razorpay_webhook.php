<?php
// Inbound Razorpay webhook. Subscribe "payment_link.paid" to this URL under
// Razorpay Dashboard > Settings > Webhooks (using the secret configured in
// Settings > Invoice) so a bill auto-marks paid the moment a customer
// actually pays through the online payment link - previously the app only
// ever generated the link and had no way to learn whether it was paid (see
// razorpay_payment_link() in includes/helpers.php), so staff had to
// remember to record the payment manually.
require_once __DIR__ . '/includes/init.php';

$raw = file_get_contents('php://input');
$sig = $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? '';
$secret = setting('razorpay_webhook_secret');

// Always answer 200 quickly (once the signature is valid) so Razorpay
// doesn't keep retrying over something on our end; a bad/missing signature
// is rejected outright since it can't be trusted either way.
http_response_code(200);
header('Content-Type: text/plain');

if (!$secret || !$sig || !hash_equals(hash_hmac('sha256', $raw, $secret), $sig)) {
    log_activity('razorpay_webhook_reject', $secret ? 'bad signature' : 'webhook secret not configured');
    if (function_exists('app_error')) app_error('razorpay', $secret ? 'webhook rejected: bad signature' : 'webhook rejected: secret not configured', 'razorpay_webhook.php');
    exit('ignored');
}

$data = json_decode($raw, true);
$event = $data['event'] ?? '';
if ($event !== 'payment_link.paid') exit('ignored (event: ' . $event . ')');

$linkId = $data['payload']['payment_link']['entity']['id'] ?? '';
$payId = $data['payload']['payment']['entity']['id'] ?? '';
$payAmountPaise = (float)($data['payload']['payment']['entity']['amount'] ?? 0);
if (!$linkId || !$payId) exit('malformed payload');

$sale = row('SELECT * FROM sales WHERE razorpay_link_id = ? AND is_cancelled = 0', [$linkId]);
if (!$sale) { log_activity('razorpay_webhook_nomatch', $linkId); exit('no matching sale'); }

// Idempotency: Razorpay retries webhooks on any non-2xx/timeout, so a retry
// of an already-applied event must not double-credit the payment.
$already = row("SELECT id FROM payments WHERE ref_type = 'sale' AND ref_id = ? AND notes LIKE ?", [$sale['id'], '%' . $payId . '%']);
if ($already) exit('already processed');

$amt = min($payAmountPaise / 100, $sale['total'] - $sale['paid']);
if ($amt > 0.009) {
    q('UPDATE sales SET paid = paid + ?, status = ? WHERE id = ?',
      [$amt, payment_status($sale['total'], $sale['paid'] + $amt), $sale['id']]);
    q("INSERT INTO payments (party_id, direction, amount, mode, ref_type, ref_id, pay_date, notes, created_by) VALUES (?,?,?,?,?,?,?,?,?)",
      [$sale['party_id'] ?: null, 'in', $amt, 'online', 'sale', $sale['id'], today(), 'Razorpay auto-reconciled (' . $payId . ')', $sale['created_by']]);
    log_activity('razorpay_webhook_paid', $sale['invoice_no'] . ' amount ' . $amt);
    fire_webhook('payment.recorded', ['sale_id' => $sale['id'], 'invoice_no' => $sale['invoice_no'], 'amount' => $amt, 'source' => 'razorpay']);
}
echo 'ok';
