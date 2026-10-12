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
// A cached API reply is a lie with a timestamp on it: a phone served a
// stale ?r=sync from the browser cache would show a serial as on the shelf
// after it was sold, and bill it a second time. Nothing here may be cached.
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
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
// needed by the public shop routes below too, which run before api_require()
$method = $_SERVER['REQUEST_METHOD'];
if ($r === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Same throttle the website's login uses - an API door must not be the
    // soft way in. A phone that keeps guessing is locked out like a browser.
    $b = api_body();
    $username = trim((string)($b['username'] ?? ''));
    $password = (string)($b['password'] ?? '');
    $device = mb_substr(trim((string)($b['device'] ?? 'Android')), 0, 120);
    if ($username === '' || $password === '') api_json(['error' => 'A username and password are needed'], 422);
    if (function_exists('login_throttle_blocked') && login_throttle_blocked($username))
        api_json(['error' => 'Too many failed attempts — try again in a while'], 429);

    $usr = row('SELECT u.*, r.name role_name, r.permissions role_permissions, l.name location_name
                FROM users u JOIN roles r ON r.id = u.role_id JOIN locations l ON l.id = u.location_id
                WHERE (u.username = ? OR (u.email IS NOT NULL AND u.email = ?)) AND u.is_active = 1', [$username, mb_strtolower($username)]);
    if (!$usr || !password_verify($password, $usr['password'])) {
        if (function_exists('login_throttle_hit')) login_throttle_hit($username);
        api_json(['error' => 'The username or password is wrong'], 401);
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

// ---------------------------------------------------------------------------
// THE CUSTOMER'S SIDE OF THE APP.
//
// The same app, handed to a customer or a dealer instead of to staff. They
// get what catalog.php gives them on the website and nothing else: the
// products the shop has chosen to publish, their price, whether it is on
// the shelf, and a way to place an order.
//
// These three need no staff token - a customer has none - so each is rate
// limited by address, and each shows exactly what the public website
// already shows. There is no new information here, only a second way in.
// ---------------------------------------------------------------------------
if (in_array($r, ['catalog', 'wlogin', 'worder'], true)) {
    // One bucket for all three was wrong: browsing is cheap and frequent,
    // ordering is rare and writes, and several customers on one shop WiFi
    // share an address. So each door gets its own limit.
    //   catalog - read only, and the app refreshes it whenever the screen opens
    //   wlogin  - guessing risk, but per-mobile throttling already guards it
    //   worder  - a write; nobody places twenty orders a minute honestly
    $rate = ['catalog' => [240, 60], 'wlogin' => [30, 60], 'worder' => [20, 60]][$r];
    if (!api_rate_ok('shop:' . $r . ':' . client_ip(), $rate[0], $rate[1]))
        api_json(['error' => 'Too many requests - try again in a minute'], 429);

    // A dealer's own token, from ?r=wlogin. Optional: without it this is a
    // walk-in customer and the price is the ordinary one.
    $acct = null;
    $wtok = api_bearer_token();
    if ($wtok !== '') {
        $acct = row('SELECT a.* FROM web_accounts a JOIN api_tokens t ON t.label = CONCAT("web:", a.id)
                     WHERE t.token_hash = ? AND t.revoked = 0 AND a.is_active = 1', [api_token_hash($wtok)]);
    }
    $pct = $acct ? (float)$acct['discount_pct'] : 0;

    if ($r === 'catalog' && $method === 'GET') {
        $stock = web_stock_map();
        $showQty = web_show_qty();
        $rows = [];
        foreach (all('SELECT i.*, c.name cat_name FROM items i LEFT JOIN categories c ON c.id = i.category_id
                      WHERE i.is_active = 1 AND i.show_on_website = 1 ORDER BY c.name, i.name LIMIT 2000') as $it) {
            list($cls, $txt, $note) = web_stock_line($it, $stock, $showQty);
            $rows[] = [
                'id' => (int)$it['id'], 'name' => $it['name'], 'category' => $it['cat_name'],
                'brand' => trim($it['brand'] . ' ' . $it['model']), 'unit' => $it['unit'],
                'photo' => $it['photo'] ? base_url($it['photo']) : '',
                'price' => round(dealer_price($it['selling_price'], $pct), 2),
                'mrp' => $pct > 0 ? round((float)$it['selling_price'], 2) : 0,
                'stock_class' => $cls, 'stock_text' => $txt, 'stock_note' => $note,
            ];
        }
        api_json(['shop' => setting('app_name', 'AK Computer'),
                  'dealer' => $acct ? ['name' => $acct['name'], 'discount_pct' => $pct] : null,
                  'whatsapp' => setting('shop_whatsapp', setting('shop_mobile', '')),
                  'items' => $rows, 'server_time' => date('c')]);
    }

    // A dealer signing in with the mobile and password the shop gave them -
    // the same web_accounts login the website uses, not a staff account.
    if ($r === 'wlogin' && $method === 'POST') {
        $b = api_body();
        $mob = preg_replace('/\D/', '', (string)($b['mobile'] ?? ''));
        $pwd = (string)($b['password'] ?? '');
        if ($mob === '' || $pwd === '') api_json(['error' => 'Enter your mobile number and password'], 422);
        if (login_throttle_blocked('wapp:' . $mob)) api_json(['error' => 'Too many attempts - try again in a few minutes'], 429);
        $a = row('SELECT * FROM web_accounts WHERE mobile = ? AND is_active = 1', [$mob]);
        if (!$a || !password_verify($pwd, $a['password_hash'])) {
            login_throttle_hit('wapp:' . $mob);
            api_json(['error' => 'Mobile or password is wrong. Not approved yet? The shop will confirm on WhatsApp.'], 401);
        }
        login_throttle_reset('wapp:' . $mob);
        q('UPDATE api_tokens SET revoked = 1 WHERE label = ? AND revoked = 0', ['web:' . $a['id']]);
        $tok = bin2hex(random_bytes(32));
        // user_id 0: this token belongs to a web account, not a staff user,
        // and must never resolve through api_auth_user()
        q('INSERT INTO api_tokens (user_id, label, token_hash, device, last_seen) VALUES (0,?,?,?,NOW())',
          ['web:' . $a['id'], api_token_hash($tok), 'Customer app']);
        q('UPDATE web_accounts SET last_login = NOW() WHERE id = ?', [$a['id']]);
        api_json(['token' => $tok, 'name' => $a['name'], 'discount_pct' => (float)$a['discount_pct']]);
    }

    // An order from the app, landing in the same web_orders queue the
    // website's cart lands in - one inbox for the shop, not two.
    if ($r === 'worder' && $method === 'POST') {
        $b = api_body();
        $name = trim((string)($b['name'] ?? ($acct['name'] ?? '')));
        $mobile = preg_replace('/\D/', '', (string)($b['mobile'] ?? ($acct['mobile'] ?? '')));
        $cart = (array)($b['items'] ?? []);
        if ($name === '' || $mobile === '') api_json(['error' => 'Name and mobile number are needed'], 422);
        if (!$cart) api_json(['error' => 'Your cart is empty'], 422);

        $cuuid = trim((string)($b['client_uuid'] ?? ''));
        if ($cuuid !== '') {
            if (!preg_match('/^[0-9a-f-]{16,36}$/i', $cuuid)) api_json(['error' => 'bad client_uuid'], 422);
            $dupe = row('SELECT id, order_no FROM web_orders WHERE client_uuid = ?', [$cuuid]);
            if ($dupe) api_json(['id' => (int)$dupe['id'], 'order_no' => $dupe['order_no'], 'duplicate' => true], 200);
        }
        // every line priced HERE, from the item master. A price posted by a
        // phone is a price a customer can edit.
        $clean = []; $total = 0;
        foreach ($cart as $line) {
            $it = row('SELECT id, name, selling_price FROM items WHERE id = ? AND is_active = 1 AND show_on_website = 1',
                      [(int)($line['item_id'] ?? 0)]);
            if (!$it) continue;
            $qty = max(1, (int)($line['qty'] ?? 1));
            $unit = round(dealer_price($it['selling_price'], $pct), 2);
            $clean[] = ['id' => (int)$it['id'], 'name' => $it['name'], 'qty' => $qty, 'price' => $unit];
            $total += $unit * $qty;
        }
        if (!$clean) api_json(['error' => 'Nothing in the cart is available any more'], 422);
        q('INSERT INTO web_orders (customer_name, mobile, address, notes, items_json, total, web_account_id, client_uuid)
           VALUES (?,?,?,?,?,?,?,?)',
          [$name, $mobile, (string)($b['address'] ?? ''), trim((string)($b['notes'] ?? '')),
           json_encode($clean, JSON_UNESCAPED_UNICODE), $total, $acct ? (int)$acct['id'] : null,
           $cuuid !== '' ? $cuuid : null]);
        $oid = insert_id();
        $ono = doc_no('WEB', $oid);
        q('UPDATE web_orders SET order_no = ? WHERE id = ?', [$ono, $oid]);
        $waShop = setting('shop_whatsapp', setting('shop_mobile', ''));
        if ($waShop) {
            send_whatsapp($waShop, wa_template('weborder', [
                'order_no' => $ono, 'customer' => $name, 'mobile' => $mobile,
                'total' => money($total), 'link' => base_url('web_orders.php'),
            ]));
        }
        log_activity('app_web_order', $ono . ' from ' . $name);
        api_json(['id' => $oid, 'order_no' => $ono, 'total' => round($total, 2)], 201);
    }
    api_json(['error' => 'Unsupported method'], 405);
}

$u = api_require();
// a token that spoke today is a phone still in somebody's hand
try { q('UPDATE api_tokens SET last_seen = NOW() WHERE token_hash = ?', [api_token_hash(api_bearer_token())]); } catch (Throwable $e) {}

$id = (int)get('id');

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

    // Serial numbers of what is actually on the shelf. A camera or a DVR is
    // sold BY its serial, so without these the app can take the order but
    // cannot finish the bill - which is exactly the gap the counter noticed.
    // Only in_stock ones: a sold serial is of no use to a bill being written.
    $serials = api_can('stock.view')
        ? all("SELECT s.id, s.item_id, s.serial_no, s.location_id
               FROM item_serials s WHERE s.status = 'in_stock' ORDER BY s.item_id, s.id LIMIT 20000")
        : [];

    // Where the stock is, so the phone can answer "is it in the shop or at
    // the godown?" standing in front of the customer, with no signal.
    $stock = api_can('stock.view')
        ? all('SELECT item_id, location_id, qty FROM stock WHERE qty <> 0 ORDER BY item_id LIMIT 20000')
        : [];

    // A window of the shop's OWN books - not just this phone's bills - so a
    // party statement, an outstanding list and the day's figures can all be
    // worked out with no signal. Always the whole window, never incremental:
    // a bill's paid amount changes when money comes in later, and a cursor
    // would quietly miss that.
    $days = min(max((int)get('days', 90), 7), 400);
    $from = date('Y-m-d', strtotime("-$days days"));
    list($scope, $scopeArgs) = api_own_scope($u, 'sales');
    $sales = api_can('sales.view') ? all(
        "SELECT id, invoice_no, sale_date, party_id, customer_name, customer_mobile, total, paid,
                status, is_cancelled, payment_mode, client_uuid
         FROM sales WHERE sale_date >= ? $scope ORDER BY id DESC LIMIT 5000",
        array_merge([$from], $scopeArgs)) : [];
    $pays = api_can('payments.view') ? all(
        "SELECT id, party_id, direction, amount, mode, pay_date, notes, ref_type, ref_id, client_uuid
         FROM payments WHERE pay_date >= ? ORDER BY id DESC LIMIT 5000", [$from]) : [];

    api_json([
        'server_time' => date('c'),
        'full' => $full,
        'items' => $items,
        'parties' => $parties,
        'serials' => $serials,
        'stock' => $stock,
        'sales' => $sales,
        'payments' => $pays,
        'days' => $days,
        'locations' => all('SELECT id, name FROM locations WHERE is_active = 1 ORDER BY id'),
        'companies' => all('SELECT id, name, invoice_prefix, is_gst FROM companies WHERE is_active = 1 ORDER BY id'),
        'payment_modes' => all("SELECT code, name, type FROM payment_methods WHERE is_active = 1 ORDER BY sort_order, id"),
        'shop' => setting('app_name', 'AK Computer'),
        'wa_share' => (int)!!setting('wa_enabled', 1),
        // what the phone needs to total a bill the way the shop's screen will
        'round_off' => (int)(setting('round_off_default', '1') === '1'),
        'composition' => (int)biz_on('gst_composition'),
        'expense_categories' => array_values(array_unique(array_map('strval', expense_categories()))),
    ]);
}

// ---------------------------------------------------------------------------
// EVERY SCREEN THE SHOP HAS, as the app's "Everything" list.
//
// Built from nav_menu() - the same definition the website's own sidebar is
// drawn from - and filtered by what this token is allowed to see. A screen
// added to the website turns up in the app by itself; there is no second
// list to keep in step, which is the only way eighty screens can be in two
// places without one of them going stale.
// ---------------------------------------------------------------------------
if ($r === 'menu' && $method === 'GET') {
    // the screens the app does ITSELF, offline. Everything else opens the
    // real website page inside the app.
    $native = ['sales.php?action=new' => 'newbill', 'sales.php' => 'bills',
               'payments.php' => 'collect', 'my_collections.php' => 'collect',
               'parties.php' => 'parties', 'items.php' => 'items', 'stock.php' => 'stock'];
    $out = [];
    foreach (nav_menu() as $e) {
        if ($e[0] === 'link') {
            if ($e[4] !== null && !api_can($e[4])) continue;
            $out[] = ['label' => $e[3], 'items' => [
                ['label' => $e[3], 'href' => $e[1], 'native' => $native[$e[1]] ?? null]]];
            continue;
        }
        $items = [];
        foreach ($e[4] as $it) {
            if ($it[2] !== null && $it[2] !== '*' && !api_can($it[2])) continue;
            if ($it[2] === '*' && !in_array('*', $u['perms'], true)) continue;
            $items[] = ['label' => $it[1], 'href' => $it[0], 'native' => $native[$it[0]] ?? null];
            if (!empty($it[3]) && (empty($it[4]) || api_can($it[4]))) {
                $items[] = ['label' => '+ ' . $it[1], 'href' => $it[3], 'native' => $native[$it[3]] ?? null];
            }
        }
        if ($items) $out[] = ['label' => $e[3], 'items' => $items];
    }
    api_json(['groups' => $out]);
}

// A one-time key that opens a website page already logged in. The app asks
// for it the moment the owner taps a screen it does not do itself.
if ($r === 'weblink' && $method === 'GET') {
    $nonce = app_link_make($u['id'], get('to', 'index.php'), 'app');
    if ($nonce === '') api_json(['error' => 'That page does not exist'], 422);
    api_json(['url' => 'login.php?app=' . $nonce, 'ttl' => APP_LINK_TTL]);
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
        // THE SAME BILL AS THE BILLING SCREEN WRITES - see sale_form_from_app()
        try {
            $_POST = sale_form_from_app($b, $u);
            $res = sale_save_form($u, $cuuid !== '' ? $cuuid : null);
        } catch (Exception $ex) {
            api_json(['error' => plain_error($ex)], 422);
        }
        log_activity('api_sale_add', $res['invoice_no'] . ' via the app');
        api_json(['id' => $res['id'], 'invoice_no' => $res['invoice_no'], 'total' => $res['total'], 'paid' => $res['paid'],
                  'party_id' => $res['party_id']], 201);
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

// ---------------------------------------------------------------------------
// MONEY IN AND OUT, PURCHASES AND EXPENSES FROM THE APP - each through the
// website screen's own save (includes/entry_save.php), laid out as that
// screen's form. Each carries the uuid the phone made, so a retry after a
// lost reply finds the first one instead of writing a second.
// ---------------------------------------------------------------------------
/** The phone's uuid, checked; '' when it sent none. */
function api_uuid(array $b) {
    $c = trim((string)($b['client_uuid'] ?? ''));
    if ($c !== '' && !preg_match('/^[0-9a-f-]{16,36}$/i', $c)) api_json(['error' => 'bad client_uuid'], 422);
    return $c;
}
/** Run one of the shared saves with $_POST = $form; refusals come back as 422 with the reason. */
function api_save(callable $fn, array $form) {
    $_POST = $form;
    try { return $fn(); }
    catch (Exception $ex) { api_json(['error' => plain_error($ex)], 422); }
}
$date = fn($v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$v) ? $v : today();

if ($r === 'payments' && $method === 'POST') {
    api_require('payments.add');
    $b = api_body();
    $cuuid = api_uuid($b);
    if ($cuuid !== '' && ($dupe = row('SELECT id FROM payments WHERE client_uuid = ?', [$cuuid])))
        api_json(['id' => (int)$dupe['id'], 'duplicate' => true], 200);
    $res = api_save(fn() => payment_save_form($u, $cuuid !== '' ? $cuuid : null), [
        'party_id' => (int)($b['party_id'] ?? 0), 'direction' => ($b['direction'] ?? 'in') === 'out' ? 'out' : 'in',
        'amount' => (float)($b['amount'] ?? 0), 'discount' => max(0, (float)($b['discount'] ?? 0)),
        'mode' => (string)($b['mode'] ?? 'cash'), 'pay_date' => $date($b['pay_date'] ?? ''),
        'notes' => (string)($b['notes'] ?? ''), 'send_wa' => !empty($b['send_wa']) ? '1' : '']);
    fire_webhook('payment.recorded', ['payment_id' => $res['id'], 'direction' => $res['dir'], 'amount' => $res['amount'], 'source' => 'api']);
    api_json(['id' => $res['id']], 201);
}

if ($r === 'purchases' && $method === 'POST') {
    api_require('purchases.add');
    $b = api_body();
    $cuuid = api_uuid($b);
    if ($cuuid !== '' && ($dupe = row('SELECT id FROM purchases WHERE client_uuid = ?', [$cuuid])))
        api_json(['id' => (int)$dupe['id'], 'duplicate' => true], 200);
    $company = row('SELECT * FROM companies WHERE id = ? AND is_active = 1', [(int)($b['company_id'] ?? 0)])
            ?: row('SELECT * FROM companies WHERE is_active = 1 ORDER BY id LIMIT 1');
    $f = ['company_id' => $company['id'] ?? 0, 'location_id' => (int)($b['location_id'] ?? $u['location_id']),
          'party_id' => (int)($b['party_id'] ?? 0), 'bill_no' => mb_substr(trim((string)($b['bill_no'] ?? '')), 0, 60),
          'purchase_date' => $date($b['purchase_date'] ?? ''), 'paid' => max(0, (float)($b['paid'] ?? 0)),
          'payment_mode' => (string)($b['payment_mode'] ?? 'cash'), 'discount_type' => 'amount',
          'discount_val' => max(0, (float)($b['discount'] ?? 0)), 'notes' => (string)($b['notes'] ?? ''), 'credit_days' => 0];
    foreach ((array)($b['items'] ?? []) as $it) {
        $item = row('SELECT id, tax_rate FROM items WHERE id = ? AND is_active = 1', [(int)($it['item_id'] ?? 0)]);
        if (!$item) api_json(['error' => 'Invalid item_id ' . ($it['item_id'] ?? '')], 422);
        $f['item_id'][] = $item['id'];
        $f['qty'][] = (float)($it['qty'] ?? 0);
        $f['price'][] = (float)($it['price'] ?? 0);
        // the supplier's bill may carry a different rate than our item master
        $f['tax_rate'][] = isset($it['tax_rate']) ? (float)$it['tax_rate'] : (float)$item['tax_rate'];
        $f['serials'][] = implode("\n", array_map('strval', (array)($it['serials'] ?? [])));
    }
    $res = api_save(fn() => purchase_save_form($u, $cuuid !== '' ? $cuuid : null), $f);
    api_json(['id' => $res['id'], 'total' => $res['total']], 201);
}

if ($r === 'expenses' && $method === 'POST') {
    api_require('expenses.add');
    $b = api_body();
    $cuuid = api_uuid($b);
    if ($cuuid !== '') {
        if ($dupe = row('SELECT id FROM expenses WHERE client_uuid = ?', [$cuuid])) api_json(['id' => (int)$dupe['id'], 'duplicate' => true], 200);
        if ($dupe = row('SELECT id FROM expense_requests WHERE client_uuid = ?', [$cuuid])) api_json(['id' => (int)$dupe['id'], 'pending' => true, 'duplicate' => true], 200);
    }
    $res = api_save(fn() => expense_save_form($u, $cuuid !== '' ? $cuuid : null), [
        'amount' => (float)($b['amount'] ?? 0), 'category' => mb_substr(trim((string)($b['category'] ?? '')) ?: 'General', 0, 60),
        'mode' => (string)($b['mode'] ?? 'cash'), 'exp_date' => $date($b['exp_date'] ?? ''), 'notes' => (string)($b['notes'] ?? '')]);
    api_json(['id' => $res['id'], 'pending' => $res['pending'], 'over' => $res['over']], 201);
}

// ---------------------------------------------------------------------------
// SEND A BILL ON WHATSAPP — through the shop's own WhatsApp account.
//
// Not through the phone's WhatsApp. That would send from whichever staff
// member's personal number happened to make the bill, with no PDF, nothing
// in the chat log, and no record that it went. This goes out from the shop,
// with the same message and the same PDF the website sends, and it is
// logged the same way.
//
// The phone sends the bill's id, never the text: the words a customer
// receives are the shop's, decided in one place (sale_whatsapp_send), not
// composed on a handset.
// ---------------------------------------------------------------------------
if ($r === 'whatsapp' && $method === 'POST') {
    api_require('sales.view');
    $b = api_body();
    $saleId = (int)($b['sale_id'] ?? 0);
    if (!$saleId && !empty($b['client_uuid'])) {
        // the phone knows the bill by the uuid it made; the server id only
        // exists after the bill has gone up
        $saleId = (int)val('SELECT id FROM sales WHERE client_uuid = ?', [trim((string)$b['client_uuid'])]);
    }
    // 409, not 404: the bill is on its way up and this errand should WAIT
    // for it, not be thrown on the "needs attention" pile. Every other 4xx
    // means "this will never work"; this one means "not yet".
    if (!$saleId) api_json(['error' => 'This bill has not reached the shop yet'], 409);
    $sale = row('SELECT created_by FROM sales WHERE id = ?', [$saleId]);
    if (!$sale) api_json(['error' => 'Bill not found'], 404);
    if (!in_array('*', $u['perms'], true) && !in_array('sales.all', $u['perms'], true)
        && $sale['created_by'] != $u['id']) api_json(['error' => 'Forbidden'], 403);

    $res = sale_whatsapp_send($saleId, $b['mobile'] ?? null);
    if (!$res['ok']) api_json(['error' => $res['error']], 422);
    api_json(['sent' => true, 'mobile' => $res['mobile']]);
}

// A payment reminder, through the shop's WhatsApp and through the SAME rule
// the website's collection screens use - the amount is worked out here from
// the ledger, never taken from the phone, so a stale balance on a handset
// cannot ask a customer for the wrong money.
if ($r === 'reminder' && $method === 'POST') {
    api_require('payments.view');
    $b = api_body();
    $pid = (int)($b['party_id'] ?? 0);
    $party = $pid ? row('SELECT id, name, mobile FROM parties WHERE id = ?', [$pid]) : null;
    if (!$party) api_json(['error' => 'Party not found'], 404);
    if (trim((string)$party['mobile']) === '') api_json(['error' => 'This party has no mobile number'], 422);
    $bal = round((float)party_balance($pid), 2);
    if ($bal <= 0.009) api_json(['error' => 'Nothing is owed by this party'], 422);
    if (!collection_reminder_send($party['mobile'], $bal, $party['name']))
        api_json(['error' => whatsapp_last_error() ?: 'WhatsApp send failed'], 422);
    log_activity('collection_reminder', $party['name'] . ' Rs ' . money($bal) . ' (app)');
    api_json(['sent' => true, 'amount' => $bal, 'mobile' => $party['mobile']]);
}

if ($r === 'stock' && $method === 'GET') {
    api_require('stock.view');
    $itemId = (int)get('item_id');
    if (!$itemId) api_json(['error' => 'item_id required'], 422);
    $rows = all('SELECT s.location_id, l.name location_name, s.qty FROM stock s JOIN locations l ON l.id = s.location_id WHERE s.item_id = ? AND s.qty <> 0', [$itemId]);
    api_json(['data' => $rows]);
}

api_json(['error' => 'Unknown resource. Available: me, sales, items, parties, payments, stock'], 404);
