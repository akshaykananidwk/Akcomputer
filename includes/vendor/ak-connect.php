<?php
/**
 * AK Connect PHP SDK v1 (single file) - send events from any PHP site to the AK Earn master.
 *
 * Setup (once, e.g. in your config file):
 *   define('AK_MASTER_URL', 'https://earn.akdwk.in');
 *   define('AK_SITE_KEY', 'sk_xxxxxxxx');
 *   define('AK_SITE_SECRET', 'your-secret-from-admin');   // keep private, never in JS
 *   require_once __DIR__ . '/ak-connect.php';
 *
 * Use:
 *   ak_track_signup('Ramesh', '9876543210', 'r@x.com');
 *   ak_track_lead('Ramesh', '9876543210', 'basic-plan', 'wants demo');
 *   ak_track_order('INV-1001', 999, 'basic-plan', 'Ramesh', '9876543210');          // paid order (payment_success)
 *   ak_track_order('INV-1001', 999, 'basic-plan', 'Ramesh', '9876543210', 'created'); // order created, not paid yet
 *   ak_track_renewal('INV-1102', 999, 'basic-plan', 'Ramesh', '9876543210');
 *   ak_track_refund('INV-1001', 'customer request');
 *   ak_track_cancel('INV-1001');
 *   ak_push_stats('2026-11-21', 120, 4, 3500.00, 3);
 *   ak_sync_products([['code' => 'basic-plan', 'name' => 'Basic', 'price' => 999, 'billing' => 'yearly']]);  // full list: missing plans get disabled
 *   ak_ping();
 *
 * Every request is signed with HMAC-SHA256 (timestamp + nonce + body). Event IDs are deterministic, so sending
 * the same event twice is safe (the master ignores duplicates). Failed requests are queued in a local file and
 * retried automatically on the next call (or call ak_flush_queue() from your cron).
 * Requires PHP 7.4+ with curl and json.
 */

if (!function_exists('ak_track_order')) {

    function ak_cfg(string $k): string
    {
        $map = ['url' => 'AK_MASTER_URL', 'key' => 'AK_SITE_KEY', 'secret' => 'AK_SITE_SECRET'];
        return defined($map[$k]) ? rtrim((string)constant($map[$k]), '/') : '';
    }

    /** Referral code of the current visitor (from ?ref= or the ak_ref cookie set by ak-connect.js / the master link) */
    function ak_ref(): string
    {
        $r = $_GET['ref'] ?? $_POST['ak_ref'] ?? $_COOKIE['ak_ref'] ?? '';
        return strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', (string)$r), 0, 20));
    }

    function ak_visitor_id(): string
    {
        $v = (string)($_GET['ak_vid'] ?? $_POST['ak_vid'] ?? $_COOKIE['ak_vid'] ?? '');
        return preg_match('/^[a-f0-9]{32}$/', $v) ? $v : '';
    }

    function ak_queue_file(): string
    {
        $dir = defined('AK_QUEUE_DIR') ? (string)AK_QUEUE_DIR : sys_get_temp_dir();
        return rtrim($dir, '/\\') . '/ak-connect-queue-' . substr(md5(ak_cfg('key')), 0, 8) . '.jsonl';
    }

    /** Low-level signed POST. Returns decoded JSON array (or ['status'=>'error','message'=>..]). */
    function ak_request(string $path, array $payload, bool $queueOnFail = true): array
    {
        $url = ak_cfg('url');
        $key = ak_cfg('key');
        $secret = ak_cfg('secret');
        if ($url === '' || $key === '' || $secret === '') {
            return ['status' => 'error', 'message' => 'AK_MASTER_URL / AK_SITE_KEY / AK_SITE_SECRET not defined'];
        }
        $payload = ['site_key' => $key] + $payload;
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $ts = (string)time();
        $nonce = bin2hex(random_bytes(12));
        $sig = hash_hmac('sha256', $ts . "\n" . $nonce . "\n" . $body, $secret);
        $ch = curl_init($url . $path);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 6,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-AK-Key: ' . $key,
                'X-AK-Timestamp: ' . $ts,
                'X-AK-Nonce: ' . $nonce,
                'X-AK-Signature: ' . $sig,
                'User-Agent: AK-Connect-PHP/1.0',
            ],
        ]);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        if (PHP_VERSION_ID < 80000) {
            curl_close($ch); // needed only on PHP 7.x; no-op and deprecated on 8.5
        }
        $json = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($json)) {
            $json = ['status' => 'error', 'message' => $err ?: ('HTTP ' . $code)];
        }
        // Network/server problems: keep the event and retry later. 4xx (bad data) is not retried.
        if ($queueOnFail && ($code === 0 || $code >= 500 || $code === 429)) {
            @file_put_contents(ak_queue_file(), json_encode(['path' => $path, 'payload' => $payload, 'at' => time()]) . "\n", FILE_APPEND | LOCK_EX);
            $json['queued'] = true;
        }
        return $json;
    }

    /** Retry queued requests (call from cron, or it runs automatically once per request). */
    function ak_flush_queue(int $max = 50): int
    {
        $file = ak_queue_file();
        if (!is_file($file) || filesize($file) === 0) {
            return 0;
        }
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        @file_put_contents($file, '');
        $sent = 0;
        foreach ($lines as $i => $line) {
            $item = json_decode($line, true);
            if (!is_array($item) || time() - (int)($item['at'] ?? 0) > 7 * 86400) {
                continue;
            }
            if ($i >= $max) {
                @file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);
                continue;
            }
            $r = ak_request((string)$item['path'], (array)$item['payload'], true);
            if (($r['status'] ?? '') === 'ok' || (!empty($r['results']) && empty($r['queued']))) {
                $sent++;
            }
        }
        return $sent;
    }

    function ak_event(string $type, string $eventId, array $data = []): array
    {
        static $flushed = false;
        if (!$flushed) {
            $flushed = true;
            ak_flush_queue(20);
        }
        $meta = $data['meta'] ?? [];
        if (!isset($meta['visitor_id']) && ak_visitor_id() !== '') {
            $meta['visitor_id'] = ak_visitor_id();
        }
        $payload = [
            'event_id' => substr($eventId, 0, 100),
            'type' => $type,
            'ref_code' => $data['ref_code'] ?? ak_ref(),
            'occurred_at' => $data['occurred_at'] ?? date('c'),
            'meta' => $meta ?: new stdClass(),
        ];
        foreach (['order_id', 'customer_name', 'customer_mobile', 'product_code', 'amount'] as $f) {
            if (isset($data[$f]) && $data[$f] !== '') {
                $payload[$f] = $data[$f];
            }
        }
        return ak_request('/api/v1/events', $payload);
    }

    function ak_track_visit(string $page = ''): array
    {
        return ak_event('visit', 'visit-' . bin2hex(random_bytes(8)), ['meta' => ['page' => substr($page, 0, 200)]]);
    }

    function ak_track_signup(string $name, string $mobile, ?string $email = null, array $meta = []): array
    {
        if ($email) {
            $meta['email'] = $email;
        }
        return ak_event('signup', 'signup-' . preg_replace('/\D/', '', $mobile), ['customer_name' => $name, 'customer_mobile' => $mobile, 'meta' => $meta]);
    }

    function ak_track_lead(string $name, string $mobile, ?string $productCode = null, ?string $note = null, array $meta = []): array
    {
        if ($note) {
            $meta['note'] = $note;
        }
        $id = 'lead-' . md5(preg_replace('/\D/', '', $mobile) . '|' . $productCode . '|' . date('Y-m-d'));
        return ak_event('lead', $id, ['customer_name' => $name, 'customer_mobile' => $mobile, 'product_code' => $productCode, 'meta' => $meta]);
    }

    /** $status: 'paid' (default) sends payment_success, 'created' sends order_created */
    function ak_track_order(string $orderId, float $amount, ?string $productCode, string $name, string $mobile, string $status = 'paid', array $meta = []): array
    {
        $type = $status === 'created' ? 'order_created' : 'payment_success';
        return ak_event($type, $type . '-' . $orderId, ['order_id' => $orderId, 'amount' => round($amount, 2), 'product_code' => $productCode, 'customer_name' => $name, 'customer_mobile' => $mobile, 'meta' => $meta]);
    }

    function ak_track_renewal(string $orderId, float $amount, ?string $productCode, string $name, string $mobile, array $meta = []): array
    {
        return ak_event('renewal', 'renewal-' . $orderId, ['order_id' => $orderId, 'amount' => round($amount, 2), 'product_code' => $productCode, 'customer_name' => $name, 'customer_mobile' => $mobile, 'meta' => $meta]);
    }

    function ak_track_refund(string $orderId, string $reason = '', ?float $amount = null): array
    {
        return ak_event('refund', 'refund-' . $orderId, ['order_id' => $orderId, 'amount' => $amount, 'meta' => ['reason' => $reason]]);
    }

    function ak_track_cancel(string $orderId, string $reason = ''): array
    {
        return ak_event('cancel', 'cancel-' . $orderId, ['order_id' => $orderId, 'meta' => ['reason' => $reason]]);
    }

    /** For sites that can only send daily totals */
    function ak_push_stats(string $date, int $visitors, int $signups = 0, float $revenue = 0.0, int $orders = 0): array
    {
        return ak_request('/api/v1/stats', ['date' => $date, 'visitors' => $visitors, 'signups' => $signups, 'revenue' => round($revenue, 2), 'orders' => $orders]);
    }

    /**
     * $products: list of ['code','name','price','renewal_price'?,'billing'?,'description'?,'image'?,'url'?,'status'?]
     * $fullSync = true (default): this is the COMPLETE list of current plans; plans not in the list are disabled in AK Earn.
     * Pass false to send only some plans (nothing else changes).
     */
    function ak_sync_products(array $products, bool $fullSync = true): array
    {
        return ak_request('/api/v1/products', ['products' => array_values($products), 'full_sync' => $fullSync], false);
    }

    function ak_ping(): array
    {
        return ak_request('/api/v1/ping', ['ping' => true], false);
    }

    /** "Login with AK account": URL to send the user to */
    function ak_sso_login_url(string $redirectUri, string $state): string
    {
        return ak_cfg('url') . '/sso/authorize?' . http_build_query(['site_key' => ak_cfg('key'), 'redirect_uri' => $redirectUri, 'state' => $state]);
    }

    /** Exchange the ?code= on your redirect page for the AK account profile. Returns user array or null. */
    function ak_sso_exchange(string $code, string $redirectUri): ?array
    {
        $r = ak_request('/api/v1/sso/token', ['code' => $code, 'redirect_uri' => $redirectUri], false);
        return ($r['status'] ?? '') === 'ok' ? ($r['user'] ?? null) : null;
    }
}
