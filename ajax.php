<?php
// AJAX endpoints (JSON)
require_once __DIR__ . '/includes/init.php';
require_login();
header('Content-Type: application/json');

$a = get('a');

if ($a === 'item_search') {
    $qs = '%' . get('q') . '%';
    $loc = (int)get('loc');
    $items = all("SELECT i.id, i.name, i.unit, i.tax_rate, i.purchase_price, i.selling_price, i.b2b_price, i.serial_tracked, i.barcode,
                  COALESCE((SELECT qty FROM stock s WHERE s.item_id = i.id AND s.location_id = ?), 0) AS stock
                  FROM items i
                  WHERE i.is_active = 1 AND (i.name LIKE ? OR i.brand LIKE ? OR i.model LIKE ? OR i.barcode LIKE ?)
                  ORDER BY i.name LIMIT 15", [$loc, $qs, $qs, $qs, $qs]);
    echo json_encode($items);
    exit;
}

if ($a === 'serials') {
    // available serial numbers of item at a location
    $item_id = (int)get('item_id');
    $loc = (int)get('loc');
    $sns = all("SELECT serial_no FROM item_serials WHERE item_id = ? AND location_id = ? AND status = 'in_stock' ORDER BY serial_no", [$item_id, $loc]);
    echo json_encode(array_column($sns, 'serial_no'));
    exit;
}

if ($a === 'staff_serials') {
    // serials held by the logged-in staff
    $item_id = (int)get('item_id');
    $sns = all("SELECT serial_no FROM item_serials WHERE item_id = ? AND user_id = ? AND status = 'with_staff' ORDER BY serial_no", [$item_id, current_user()['id']]);
    echo json_encode(array_column($sns, 'serial_no'));
    exit;
}

if ($a === 'party_add' && can('parties.add') && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = post('name');
    if ($name === '') { echo json_encode(['error' => 'Name required']); exit; }
    q('INSERT INTO parties (name, type, mobile, gstin, credit_days) VALUES (?, ?, ?, ?, ?)',
      [$name, post('type', 'customer'), post('mobile'), post('gstin'), (int)post('credit_days', 0)]);
    $id = insert_id();
    log_activity('party_add', "Quick add party #$id $name");
    echo json_encode(['id' => $id, 'name' => $name]);
    exit;
}

if ($a === 'party_bills' && can('payments.view')) {
    // unpaid bills of a party (for payment linking) + live balance
    $party_id = (int)get('party_id');
    $dir = get('dir') === 'out' ? 'out' : 'in';
    $balance = (float)val("SELECT p.opening_balance
        + COALESCE((SELECT SUM(total) FROM sales WHERE party_id = p.id), 0)
        - COALESCE((SELECT SUM(total) FROM sales_returns WHERE party_id = p.id), 0)
        - COALESCE((SELECT SUM(total) FROM purchases WHERE party_id = p.id), 0)
        + COALESCE((SELECT SUM(total) FROM purchase_returns WHERE party_id = p.id), 0)
        - COALESCE((SELECT SUM(amount) FROM payments WHERE party_id = p.id AND direction = 'in'), 0)
        + COALESCE((SELECT SUM(amount) FROM payments WHERE party_id = p.id AND direction = 'out'), 0)
        FROM parties p WHERE p.id = ?", [$party_id]);
    if ($dir === 'in') {
        $bills = all("SELECT id, invoice_no no, sale_date d, total - paid due FROM sales
                      WHERE party_id = ? AND status <> 'paid' ORDER BY sale_date, id", [$party_id]);
    } else {
        $bills = all("SELECT id, IF(bill_no = '', CONCAT('#', id), bill_no) no, purchase_date d, total - paid due FROM purchases
                      WHERE party_id = ? AND status <> 'paid' ORDER BY purchase_date, id", [$party_id]);
    }
    echo json_encode(['balance' => round($balance, 2), 'bills' => array_map(fn($b) => [
        'id' => (int)$b['id'], 'no' => $b['no'], 'date' => dmy($b['d']), 'due' => round((float)$b['due'], 2),
    ], $bills)]);
    exit;
}

if ($a === 'serial_lookup') {
    // warranty check by serial number + full history chain
    $sn = get('sn');
    $r = row("SELECT isr.*, i.name AS item_name, s.invoice_no, s.sale_date, s.customer_name, s.customer_mobile
              FROM item_serials isr
              JOIN items i ON i.id = isr.item_id
              LEFT JOIN sales s ON s.id = isr.sale_id
              WHERE isr.serial_no = ? ORDER BY isr.id DESC LIMIT 1", [$sn]);
    if (!$r) { echo json_encode(['error' => 'Serial not found']); exit; }
    // purchase source
    $r['purchase_party'] = $r['purchase_id']
        ? val('SELECT pt.name FROM purchases pu JOIN parties pt ON pt.id = pu.party_id WHERE pu.id = ?', [$r['purchase_id']])
        : null;
    // warranty claim history (this serial as original OR as replacement)
    $r['history'] = all("SELECT w.claim_no, w.status, w.serial_no, w.replacement_serial, w.sent_date, w.back_date,
                         pt.name company FROM warranty_claims w LEFT JOIN parties pt ON pt.id = w.party_id
                         WHERE w.serial_no = ? OR w.replacement_serial = ? ORDER BY w.id", [$sn, $sn]);
    echo json_encode($r);
    exit;
}

echo json_encode(['error' => 'Unknown action']);
