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

$u = api_require();
$r = get('r');
$id = (int)get('id');
$method = $_SERVER['REQUEST_METHOD'];

if ($r === 'me') {
    api_json(['id' => $u['id'], 'name' => $u['name'], 'username' => $u['username'], 'role' => $u['role_name'], 'location' => $u['location_name'], 'perms' => $u['perms']]);
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
            q('INSERT INTO sales (company_id, party_id, customer_name, customer_mobile, location_id, sale_date, price_type,
               subtotal, total, paid, status, notes, created_by, share_token)
               VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
              [$company['id'], (int)($b['party_id'] ?? 0) ?: null, $b['customer_name'] ?? '', $b['customer_mobile'] ?? '', $loc,
               $b['sale_date'] ?? today(), 'retail', $subtotal, $total, (float)($b['paid'] ?? 0),
               payment_status($total, (float)($b['paid'] ?? 0)), $b['notes'] ?? '', $u['id'], share_token()]);
            $sale_id = insert_id();
            $invoice_no = $company['invoice_prefix'] . '-' . date('y') . '-' . str_pad($sale_id, 5, '0', STR_PAD_LEFT);
            q('UPDATE sales SET invoice_no = ? WHERE id = ?', [$invoice_no, $sale_id]);
            foreach ($lineRows as $lr) {
                q('INSERT INTO sale_items (sale_id, item_id, qty, price, total) VALUES (?,?,?,?,?)',
                  [$sale_id, $lr['item_id'], $lr['qty'], $lr['price'], $lr['total']]);
                adjust_stock($lr['item_id'], $loc, -$lr['qty'], 'sale', $sale_id);
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
    q('INSERT INTO payments (party_id, direction, amount, mode, pay_date, notes, created_by) VALUES (?,?,?,?,?,?,?)',
      [$partyId, $dir, $amount, $b['mode'] ?? 'cash', $b['pay_date'] ?? today(), $b['notes'] ?? '', $u['id']]);
    $pid = insert_id();
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
