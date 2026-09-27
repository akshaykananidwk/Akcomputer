<?php
// Bearer-token REST API - also the app's mobile API (a native/mobile client
// is just another API caller). Auth via "Authorization: Bearer <token>"
// (see api_tokens.php to issue tokens, includes/api_auth.php for the
// resolver), NOT the browser session - every action is gated by the same
// permission strings the rest of the app uses (can()/permission_catalog()),
// checked against the token owner's role + extra perms.
//
// Query-string routing (?r=<resource>&id=<id>), matching this codebase's
// existing single-file-dispatcher convention (see ajax.php) rather than
// path-based routing, since no URL rewrite rules are assumed on shared
// hosting. Every response is JSON.
require_once __DIR__ . '/includes/init.php';

// ---------------------------------------------------------------------------
// The phone app's page is loaded from inside the apk (file://), so every call
// it makes to this API is cross-origin and the browser will refuse it unless
// these headers come back. Without them the app cannot even log in.
//
// "*" is the right answer here and not a hole: this API is opened by a Bearer
// token in a header, never by a cookie, and a browser will not attach cookies
// to a "*" origin at all. A stranger's web page still needs a token it does
// not have.
// ---------------------------------------------------------------------------
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Max-Age: 86400');
header('Vary: Origin');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }


// ---------------------------------------------------------------------------
// The phone app's front door. Everything below api_require() needs a token;
// these two do not, because getting one is the point.
// ---------------------------------------------------------------------------
$r = get('r');
if ($r === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Same throttle the website's login uses - an API door must not be the
    // soft way in. A phone that keeps guessing is locked out like a browser.
    $b = api_body();
    $username = trim((string)($b['username'] ?? ''));
    $password = (string)($b['password'] ?? '');
    $device = mb_substr(trim((string)($b['device'] ?? 'Android')), 0, 120);
    if ($username === '' || $password === '') api_json(['error' => 'username અને password જોઈએ'], 422);
    if (function_exists('login_throttle_blocked') && login_throttle_blocked($username))
        api_json(['error' => 'બહુ વાર ખોટો પ્રયત્ન — થોડી વાર પછી ફરી કરો'], 429);

    $usr = row('SELECT u.*, r.name role_name, r.permissions role_permissions, l.name location_name
                FROM users u JOIN roles r ON r.id = u.role_id JOIN locations l ON l.id = u.location_id
                WHERE u.username = ? AND u.is_active = 1', [$username]);
    if (!$usr || !password_verify($password, $usr['password'])) {
        if (function_exists('login_throttle_hit')) login_throttle_hit($username);
        api_json(['error' => 'યુઝરનેમ કે પાસવર્ડ ખોટો છે'], 401);
    }
    if (function_exists('login_throttle_reset')) login_throttle_reset($username);

    // One live token per device name, so re-installing the app does not leave
    // a trail of forgotten keys behind on the account.
    q("UPDATE api_tokens SET revoked = 1 WHERE user_id = ? AND device = ? AND revoked = 0", [$usr['id'], $device]);
    $token = bin2hex(random_bytes(32));
    q('INSERT INTO api_tokens (user_id, label, token_hash, device, last_seen) VALUES (?,?,?,?,NOW())',
      [$usr['id'], 'App: ' . $device, api_token_hash($token), $device]);
    log_activity('api_login', $username . ' (' . $device . ')');

    $perms = array_unique(array_merge(json_decode($usr['role_permissions'] ?: '[]', true) ?: [],
                                      json_decode($usr['permissions'] ?: '[]', true) ?: []));
    api_json(['token' => $token, 'user' => [
        'id' => (int)$usr['id'], 'name' => $usr['name'], 'username' => $usr['username'],
        'role' => $usr['role_name'], 'location_id' => (int)$usr['location_id'],
        'location' => $usr['location_name'], 'perms' => array_values($perms),
    ], 'shop' => setting('app_name', 'AK Computer'), 'server_time' => date('c')]);
}

$u = api_require();
// a token that spoke today is a phone still in somebody's hand
try { q('UPDATE api_tokens SET last_seen = NOW() WHERE token_hash = ?', [api_token_hash(api_bearer_token())]); } catch (Throwable $e) {}

$id = (int)get('id');
$method = $_SERVER['REQUEST_METHOD'];

if ($r === 'me') {
    api_json(['id' => $u['id'], 'name' => $u['name'], 'username' => $u['username'], 'role' => $u['role_name'], 'location' => $u['location_name'], 'perms' => $u['perms']]);
}

// ---------------------------------------------------------------------------
// EVERYTHING THE PHONE NEEDS TO WORK WITH NO SIGNAL, in one call.
//
// The app keeps its own copy of the masters (items, parties, locations,
// payment modes) so a bill can be written on a dead network. This hands them
// over. With ?since=<ISO time> only what changed after that comes back, so
// the daily sync is small; without it, everything, which is what a fresh
// install needs.
//
// server_time comes back with the data and the phone stores it as its next
// "since". Using the PHONE's clock would be a quiet disaster: a phone an
// hour behind would ask for changes from an hour ago every time and never
// notice the ones in between.
// ---------------------------------------------------------------------------
if ($r === 'sync' && $method === 'GET') {
    api_require('items.view');
    $since = trim((string)get('since'));
    $sinceSql = ($since && strtotime($since)) ? date('Y-m-d H:i:s', strtotime($since)) : null;
    $full = !$sinceSql;

    $itemW = $full ? '' : ' AND i.updated_at > ?';
    $itemA = $full ? [] : [$sinceSql];
    $items = all("SELECT i.id, i.name, i.unit, i.barcode, i.hsn, i.tax_rate, i.selling_price, i.b2b_price,
                         i.purchase_price, i.serial_tracked, i.item_type, i.is_active,
                         COALESCE((SELECT SUM(st.qty) FROM stock st WHERE st.item_id = i.id), 0) stock
                  FROM items i WHERE 1=1 $itemW ORDER BY i.id LIMIT 5000", $itemA);
    // the cost price is a guarded number on the website; it is guarded here too
    if (!api_can('items.cost')) foreach ($items as &$_it) { $_it['purchase_price'] = 0; } unset($_it);

    $partyW = $full ? '' : ' AND p.updated_at > ?';
    $partyA = $full ? [] : [$sinceSql];
    $parties = all("SELECT p.id, p.name, p.mobile, p.type, p.credit_days, p.credit_limit, p.is_active,
                           " . party_balance_expr('p') . " balance
                    FROM parties p WHERE 1=1 $partyW ORDER BY p.id LIMIT 5000", $partyA);

    api_json([
        'server_time' => date('c'),
        'full' => $full,
        'items' => $items,
        'parties' => $parties,
        'locations' => all('SELECT id, name FROM locations WHERE is_active = 1 ORDER BY id'),
        'companies' => all('SELECT id, name, invoice_prefix, is_gst FROM companies ORDER BY id'),
        'payment_modes' => all("SELECT code, name, type FROM payment_methods WHERE is_active = 1 ORDER BY sort_order, id"),
        'shop' => setting('app_name', 'AK Computer'),
    ]);
}

if ($r === 'sales') {
    if ($method === 'GET' && $id) {
        api_require('sales.view');
        $sale = row('SELECT * FROM sales WHERE id = ?', [$id]);
        if (!$sale) api_json(['error' => 'Not found'], 404);
        if (!in_array('*', $u['perms'], true) && !in_array('sales.all', $u['perms'], true) && $sale['created_by'] != $u['id']) api_json(['error' => 'Forbidden'], 403);
        $sale['items'] = all("SELECT si.*, COALESCE(i.name,'(deleted item)') name FROM sale_items si LEFT JOIN items i ON i.id = si.item_id WHERE si.sale_id = ?", [$id]);
        api_json($sale);
    }
    if ($method === 'GET') {
        api_require('sales.view');
        list($scope, $params) = api_own_scope($u, 'sales');
        $from = get('from', date('Y-m-01')); $to = get('to', today());
        $limit = min(max((int)get('limit', 50), 1), 200);
        $rows = all("SELECT id, invoice_no, sale_date, customer_name, total, paid, status, is_cancelled FROM sales
                     WHERE sale_date BETWEEN ? AND ? $scope ORDER BY id DESC LIMIT $limit",
                    array_merge([$from, $to], $params));
        api_json(['data' => $rows]);
    }
    if ($method === 'POST') {
        api_require('sales.add');
        $b = api_body();
        $items = $b['items'] ?? [];
        if (empty($items)) api_json(['error' => 'items[] required, each {item_id, qty, price}'], 422);

        // THE SAME BILL TWICE IS THE ONE THING THIS MUST NEVER DO.
        //
        // A phone pushes a bill; the server saves it; the reply is lost on
        // the way back. The phone hears nothing and tries again. Recognised
        // by the UUID the phone made, that second attempt returns the bill
        // that already exists instead of writing a second one.
        $cuuid = trim((string)($b['client_uuid'] ?? ''));
        if ($cuuid !== '') {
            if (!preg_match('/^[0-9a-f-]{16,36}$/i', $cuuid)) api_json(['error' => 'bad client_uuid'], 422);
            $dupe = row('SELECT id, invoice_no, total FROM sales WHERE client_uuid = ?', [$cuuid]);
            if ($dupe) api_json(['id' => (int)$dupe['id'], 'invoice_no' => $dupe['invoice_no'],
                                 'total' => (float)$dupe['total'], 'duplicate' => true], 200);
        }
        $company = row('SELECT * FROM companies WHERE is_active = 1 ORDER BY id LIMIT 1');
        $loc = (int)($b['location_id'] ?? $u['location_id']);
        $subtotal = 0;
        $lineRows = [];
        foreach ($items as $it) {
            $item = row('SELECT * FROM items WHERE id = ? AND is_active = 1', [(int)($it['item_id'] ?? 0)]);
            if (!$item) api_json(['error' => 'Invalid item_id ' . ($it['item_id'] ?? '')], 422);
            $qty = (float)($it['qty'] ?? 0);
            $price = isset($it['price']) ? (float)$it['price'] : (float)$item['selling_price'];
            if ($qty <= 0) api_json(['error' => 'qty must be > 0'], 422);
            $lineTotal = $qty * $price;
            $subtotal += $lineTotal;
            $lineRows[] = ['item_id' => $item['id'], 'qty' => $qty, 'price' => $price, 'total' => $lineTotal];
        }
        $total = $subtotal;
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $paid = max(0.0, min((float)($b['paid'] ?? 0), $total));
            $saleDate = $b['sale_date'] ?? today();
            $mode = $b['payment_mode'] ?? ($paid > 0.009 ? 'cash' : 'credit');
            list($pmId, $bankAccId) = resolve_payment_target($mode, 0);
            q('INSERT INTO sales (company_id, party_id, customer_name, customer_mobile, location_id, sale_date, price_type,
               subtotal, total, paid, payment_mode, payment_method_id, status, notes, created_by, share_token, client_uuid)
               VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
              [$company['id'], (int)($b['party_id'] ?? 0) ?: null, $b['customer_name'] ?? '', $b['customer_mobile'] ?? '', $loc,
               $saleDate, 'retail', $subtotal, $total, $paid, $mode, $pmId,
               payment_status($total, $paid), $b['notes'] ?? '', $u['id'], share_token(), $cuuid !== '' ? $cuuid : null]);
            $sale_id = insert_id();
            // the SAME numbering rule the billing screen uses - one series per
            // firm per financial year. The API used to build its own number
            // from the row id, which is the format the website stopped using.
            $invoice_no = doc_next_no($company['id'], $saleDate, $company['invoice_prefix']);
            q('UPDATE sales SET invoice_no = ? WHERE id = ?', [$invoice_no, $sale_id]);
            foreach ($lineRows as $lr) {
                q('INSERT INTO sale_items (sale_id, item_id, qty, price, total) VALUES (?,?,?,?,?)',
                  [$sale_id, $lr['item_id'], $lr['qty'], $lr['price'], $lr['total']]);
                adjust_stock($lr['item_id'], $loc, -$lr['qty'], 'sale', $sale_id);
            }
            // money received has to reach the cash book, or "Cash in Hand" is
            // wrong from the moment the phone syncs. The website's billing
            // screen writes this row; the API used to set sales.paid and
            // write nothing, so every app payment was invisible to the books.
            if ($paid > 0.009) {
                q('INSERT INTO payments (party_id, direction, amount, mode, payment_method_id, ref_type, ref_id, pay_date, notes, created_by)
                   VALUES (?,?,?,?,?,?,?,?,?,?)',
                  [(int)($b['party_id'] ?? 0) ?: null, 'in', $paid, $mode, $pmId, 'sale', $sale_id,
                   $saleDate, 'With bill ' . $invoice_no . ' (app)', $u['id']]);
            }
            $pdo->commit();
            log_activity('api_sale_add', "$invoice_no via API token");
            fire_webhook('sale.created', ['sale_id' => $sale_id, 'invoice_no' => $invoice_no, 'total' => $total, 'source' => 'api']);
            api_json(['id' => $sale_id, 'invoice_no' => $invoice_no, 'total' => $total], 201);
        } catch (Exception $ex) {
            $pdo->rollBack();
            api_json(['error' => $ex->getMessage()], 422);
        }
    }
    api_json(['error' => 'Unsupported method'], 405);
}

if ($r === 'items') {
    api_require('items.view');
    if ($id) {
        $item = row('SELECT id, name, barcode, hsn, unit, selling_price, purchase_price, item_type FROM items WHERE id = ?', [$id]);
        if (!$item) api_json(['error' => 'Not found'], 404);
        $item['stock'] = (float)val('SELECT COALESCE(SUM(qty),0) FROM stock WHERE item_id = ?', [$id]);
        api_json($item);
    }
    $q = get('q');
    $limit = min(max((int)get('limit', 50), 1), 200);
    $params = [];
    $where = 'is_active = 1';
    if ($q !== '') { $where .= ' AND (name LIKE ? OR barcode LIKE ?)'; $params[] = "%$q%"; $params[] = "%$q%"; }
    $rows = all("SELECT id, name, barcode, unit, selling_price, item_type FROM items WHERE $where ORDER BY name LIMIT $limit", $params);
    api_json(['data' => $rows]);
}

if ($r === 'parties') {
    api_require('parties.view');
    if ($id) {
        $p = row('SELECT id, name, type, mobile, email, gstin, address, city FROM parties WHERE id = ?', [$id]);
        if (!$p) api_json(['error' => 'Not found'], 404);
        $p['balance'] = party_balance($id);
        api_json($p);
    }
    if ($method === 'GET') {
        $q = get('q');
        $limit = min(max((int)get('limit', 50), 1), 200);
        $params = [];
        $where = 'is_active = 1';
        if ($q !== '') { $where .= ' AND (name LIKE ? OR mobile LIKE ?)'; $params[] = "%$q%"; $params[] = "%$q%"; }
        $rows = all("SELECT id, name, type, mobile, city FROM parties WHERE $where ORDER BY name LIMIT $limit", $params);
        api_json(['data' => $rows]);
    }
    if ($method === 'POST') {
        api_require('parties.add');
        $b = api_body();
        if (empty($b['name'])) api_json(['error' => 'name required'], 422);
        q('INSERT INTO parties (name, type, mobile, email, gstin, address, city, credit_days) VALUES (?,?,?,?,?,?,?,?)',
          [$b['name'], $b['type'] ?? 'customer', $b['mobile'] ?? '', $b['email'] ?? '', $b['gstin'] ?? '', $b['address'] ?? '', $b['city'] ?? '', (int)($b['credit_days'] ?? 0)]);
        $pid = insert_id();
        log_activity('api_party_add', $b['name'] . ' via API token');
        api_json(['id' => $pid], 201);
    }
    api_json(['error' => 'Unsupported method'], 405);
}

if ($r === 'payments' && $method === 'POST') {
    api_require('payments.add');
    $b = api_body();
    $amount = (float)($b['amount'] ?? 0);
    $dir = ($b['direction'] ?? 'in') === 'out' ? 'out' : 'in';
    if ($amount <= 0) api_json(['error' => 'amount must be > 0'], 422);
    $partyId = (int)($b['party_id'] ?? 0) ?: null;
    // retry-safe, exactly as for a bill
    $cuuid = trim((string)($b['client_uuid'] ?? ''));
    if ($cuuid !== '') {
        if (!preg_match('/^[0-9a-f-]{16,36}$/i', $cuuid)) api_json(['error' => 'bad client_uuid'], 422);
        $dupe = row('SELECT id FROM payments WHERE client_uuid = ?', [$cuuid]);
        if ($dupe) api_json(['id' => (int)$dupe['id'], 'duplicate' => true], 200);
    }
    list($pmId2, ) = resolve_payment_target($b['mode'] ?? 'cash', 0);
    q('INSERT INTO payments (party_id, direction, amount, mode, payment_method_id, pay_date, notes, created_by, client_uuid) VALUES (?,?,?,?,?,?,?,?,?)',
      [$partyId, $dir, $amount, $b['mode'] ?? 'cash', $pmId2, $b['pay_date'] ?? today(), $b['notes'] ?? '', $u['id'],
       $cuuid !== '' ? $cuuid : null]);
    $pid = insert_id();
    // an unlinked payment still has to settle the party's oldest bills, the
    // same way the website's Payment-In does - otherwise the app's
    // collections never clear anything and Receivables lies
    if ($partyId) {
        foreach (money_settle_oldest_first($partyId, $dir, $amount) as $t) {
            q('INSERT INTO payment_allocations (payment_id, ref_type, ref_id, amount) VALUES (?,?,?,?)',
              [$pid, $t['ref_type'], $t['ref_id'], $t['amount']]);
        }
    }
    log_activity('api_payment_add', "P-$pid via API token");
    fire_webhook('payment.recorded', ['payment_id' => $pid, 'direction' => $dir, 'amount' => $amount, 'source' => 'api']);
    api_json(['id' => $pid], 201);
}

if ($r === 'stock' && $method === 'GET') {
    api_require('stock.view');
    $itemId = (int)get('item_id');
    if (!$itemId) api_json(['error' => 'item_id required'], 422);
    $rows = all('SELECT s.location_id, l.name location_name, s.qty FROM stock s JOIN locations l ON l.id = s.location_id WHERE s.item_id = ? AND s.qty <> 0', [$itemId]);
    api_json(['data' => $rows]);
}

api_json(['error' => 'Unknown resource. Available: me, sales, items, parties, payments, stock'], 404);
