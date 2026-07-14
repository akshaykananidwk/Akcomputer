<?php
// Outbound webhooks - fires a signed JSON POST to every active webhook
// subscribed to $event (or to "*"). Synchronous, like send_whatsapp() -
// this project has no job queue anywhere, so a slow/unreachable receiver
// adds its timeout to the request that triggered the event. Kept to a
// short per-hook timeout to bound that cost.

function fire_webhook($event, array $payload) {
    if (!function_exists('curl_init')) return;
    $hooks = all('SELECT * FROM webhooks WHERE is_active = 1');
    foreach ($hooks as $h) {
        $events = array_filter(array_map('trim', explode(',', $h['events'])));
        if (!in_array('*', $events, true) && !in_array($event, $events, true)) continue;
        webhook_deliver($h, $event, $payload);
    }
}

/** Delivers to exactly one webhook regardless of its event subscriptions - used by the "Send Test" button. */
function webhook_deliver($h, $event, array $payload) {
    if (!function_exists('curl_init')) return;
    $body = json_encode(['event' => $event, 'data' => $payload, 'timestamp' => date('c')], JSON_UNESCAPED_UNICODE);
    $secret = vault_decrypt($h['secret_enc']);
    $sig = hash_hmac('sha256', $body, $secret);
    $ch = curl_init($h['url']);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 6,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Webhook-Event: ' . $event, 'X-Webhook-Signature: sha256=' . $sig],
        CURLOPT_POSTFIELDS => $body,
    ]);
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $ok = $code >= 200 && $code < 300;
    q('UPDATE webhooks SET last_status = ?, last_triggered_at = NOW() WHERE id = ?', [($ok ? 'OK ' : 'FAIL ') . $code, $h['id']]);
    q('INSERT INTO webhook_deliveries (webhook_id, event, status_code, ok) VALUES (?,?,?,?)', [$h['id'], $event, $code, $ok ? 1 : 0]);
}
