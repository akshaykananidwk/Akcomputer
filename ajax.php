<?php
// AJAX endpoints (JSON)
require_once __DIR__ . '/includes/init.php';
require_login();
header('Content-Type: application/json');

$a = get('a');

if ($a === 'item_search') {
    $qs = '%' . get('q') . '%';
    $loc = (int)get('loc');
    $items = all("SELECT i.id, i.name, i.unit, i.tax_rate, i.purchase_price, i.selling_price, i.b2b_price, i.serial_tracked,
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

if ($a === 'serial_lookup') {
    // warranty check by serial number
    $sn = get('sn');
    $r = row("SELECT isr.*, i.name AS item_name, s.invoice_no, s.sale_date, s.customer_name, s.customer_mobile
              FROM item_serials isr
              JOIN items i ON i.id = isr.item_id
              LEFT JOIN sales s ON s.id = isr.sale_id
              WHERE isr.serial_no = ? ORDER BY isr.id DESC LIMIT 1", [$sn]);
    echo json_encode($r ?: ['error' => 'Serial not found']);
    exit;
}

echo json_encode(['error' => 'Unknown action']);
