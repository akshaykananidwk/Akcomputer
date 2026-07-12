<?php
// Invoice PDF download (staff via login, customer via share token - used
// as WhatsApp media_url so the bill goes as a PDF document)
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/pdf.php';

$id = (int)get('id');
$token = get('token');
$sale = $id ? row('SELECT s.*, c.name company_name, c.gstin, c.is_gst, c.address c_address, c.phone c_phone, c.terms c_terms,
                   l.name loc_name, l.city loc_city, p.name party_name, p.gstin party_gstin
                   FROM sales s
                   JOIN companies c ON c.id = s.company_id
                   JOIN locations l ON l.id = s.location_id
                   LEFT JOIN parties p ON p.id = s.party_id
                   WHERE s.id = ?', [$id]) : null;
if (!$sale) die('Invoice not found.');

$public = ($token && hash_equals($sale['share_token'], $token));
if (!$public) {
    require_perm('sales.view');
    if (!can('sales.all') && $sale['created_by'] != current_user()['id']) die('Access denied.');
}

$items = all("SELECT si.*, COALESCE(i.name, '(deleted item)') name, i.unit FROM sale_items si LEFT JOIN items i ON i.id = si.item_id WHERE si.sale_id = ?", [$id]);
$bytes = invoice_pdf($sale, $items);

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9\-_]/', '_', $sale['invoice_no']) . '.pdf"');
header('Content-Length: ' . strlen($bytes));
echo $bytes;
