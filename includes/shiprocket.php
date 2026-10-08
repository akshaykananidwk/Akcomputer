<?php
// 📦 Courier through the shop's own Shiprocket account: make the shipment from
// a bill, get the AWB number, track it and send the tracking link to the
// customer. Login details are kept encrypted (Connections → Courier).

function sr_http($method, $path, array $body = null, $token = '') {
    if (isset($GLOBALS['sr_http_mock'])) return ($GLOBALS['sr_http_mock'])($method, $path, $body);
    $ch = curl_init('https://apiv2.shiprocket.in/v1/external' . $path);
    $h = ['Content-Type: application/json']; if ($token !== '') $h[] = 'Authorization: Bearer ' . $token;
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $h]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    $r = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return [$code, json_decode((string)$r, true) ?: []];
}
/** A login token, made again every 9 days (Shiprocket's last 10). */
function sr_token() {
    $t = (string)setting('shiprocket_token', '');
    if ($t !== '' && (int)setting('shiprocket_token_at', '0') > time() - 9 * 86400) return $t;
    [$st, $j] = sr_http('POST', '/auth/login', ['email' => setting('shiprocket_email', ''), 'password' => setting('shiprocket_password', '')]);
    if ($st !== 200 || empty($j['token'])) return '';
    set_setting('shiprocket_token', $j['token']); set_setting('shiprocket_token_at', (string)time());
    return $j['token'];
}
/** Make the shipment for a bill and get its AWB. $to = name, address, city, pincode, state, phone, email; $pkg = weight kg, l, b, h cm, cod. Returns [ok, message]. */
function sr_ship($saleId, array $to, array $pkg) {
    $s = row('SELECT * FROM sales WHERE id = ? AND is_cancelled = 0', [(int)$saleId]);
    if (!$s) return [false, 'Bill not found.'];
    $tok = sr_token();
    if ($tok === '') return [false, 'Shiprocket login failed — check Connections → Courier.'];
    $items = array_map(fn($l) => ['name' => mb_substr($l['name'], 0, 100), 'sku' => 'IT' . $l['item_id'], 'units' => max(1, (int)round($l['qty'])), 'selling_price' => round($l['total'] / max(1, $l['qty']), 2)],
                       all('SELECT si.*, i.name FROM sale_items si JOIN items i ON i.id = si.item_id WHERE si.sale_id = ? AND si.qty > 0', [$s['id']]));
    $due = round($s['total'] - $s['paid'], 2);
    [$st, $j] = sr_http('POST', '/orders/create/adhoc', [
        'order_id' => $s['invoice_no'], 'order_date' => date('Y-m-d H:i', strtotime($s['created_at'])), 'pickup_location' => setting('shiprocket_pickup', 'Primary'),
        'billing_customer_name' => $to['name'], 'billing_last_name' => '', 'billing_address' => $to['address'], 'billing_city' => $to['city'], 'billing_pincode' => $to['pincode'],
        'billing_state' => $to['state'], 'billing_country' => 'India', 'billing_email' => $to['email'] ?: 'noreply@example.com', 'billing_phone' => $to['phone'],
        'shipping_is_billing' => true, 'order_items' => $items, 'payment_method' => !empty($pkg['cod']) && $due > 0 ? 'COD' : 'Prepaid',
        'sub_total' => !empty($pkg['cod']) && $due > 0 ? $due : round($s['total'], 2),
        'length' => (float)$pkg['l'], 'breadth' => (float)$pkg['b'], 'height' => (float)$pkg['h'], 'weight' => (float)$pkg['weight']], $tok);
    if (!in_array($st, [200, 201], true) || empty($j['shipment_id'])) return [false, 'Shiprocket: ' . ($j['message'] ?? 'the order was not made') . '.'];
    q("INSERT INTO shipments (sale_id, order_ref, status) VALUES (?, ?, 'created')", [$s['id'], (string)$j['order_id']]);
    $shipId = insert_id();
    [$st2, $a] = sr_http('POST', '/courier/assign/awb', ['shipment_id' => $j['shipment_id']], $tok);
    $awb = $a['response']['data']['awb_code'] ?? '';
    if ($awb === '') return [true, 'Order made in Shiprocket; choose the courier there (' . ($a['message'] ?? 'no AWB yet') . ').'];
    q("UPDATE shipments SET awb = ?, courier = ?, status = 'awb', tracking_url = ?, updated_at = NOW() WHERE id = ?",
      [$awb, mb_substr((string)($a['response']['data']['courier_name'] ?? ''), 0, 80), 'https://shiprocket.co/tracking/' . $awb, $shipId]);
    return [true, "AWB $awb with " . ($a['response']['data']['courier_name'] ?? 'the courier') . '.'];
}
/** Latest status of a shipment from the courier. */
function sr_track(array $sh) {
    if ($sh['awb'] === '' || ($tok = sr_token()) === '') return $sh['status'];
    [$st, $j] = sr_http('GET', '/courier/track/awb/' . rawurlencode($sh['awb']), null, $tok);
    $status = (string)($j['tracking_data']['shipment_track'][0]['current_status'] ?? '');
    if ($status !== '') q('UPDATE shipments SET status = ?, updated_at = NOW() WHERE id = ?', [mb_substr($status, 0, 40), $sh['id']]);
    return $status ?: $sh['status'];
}
