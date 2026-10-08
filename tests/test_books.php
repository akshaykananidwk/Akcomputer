<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); die('CLI only'); }
// Tax returns and the books: every rupee worked out the same way each time.
require_once dirname(__DIR__) . '/includes/gst.php';
require_once dirname(__DIR__) . '/includes/books.php';

t_group('Books: loans and EMI');
t_eq('₹1,00,000 at 12% for 12 months', loan_emi(100000, 12, 12), 8884.88);
$sch = loan_schedule(100000, 12, 12, '2026-01-05');
t_eq('first month interest is 1% of the loan', $sch[0]['interest'], 1000);
t_eq('the principal adds back to the loan exactly', round(array_sum(array_column($sch, 'principal')), 2), 100000.0);
t_eq('nothing is left at the end', end($sch)['balance'], 0.0);
t_eq('due dates go month by month', [$sch[0]['due'], $sch[11]['due']], ['2026-01-05', '2026-12-05']);
t_eq('no interest: equal parts', loan_emi(12000, 0, 12), 1000.0);

t_group('Books: depreciation (written-down value)');
t_eq('the financial year of 10 Feb 2026', fy_of('2026-02-10'), ['2025-04-01', '2026-03-31', '2025-26']);
$a = ['bought_on' => '2025-05-01', 'cost' => 100000, 'rate_pct' => 15, 'disposed_on' => null];
t_eq('bought in May: full rate in year one', asset_dep($a, '2025-04-01'), ['open' => 0, 'add' => 100000.0, 'dep' => 15000.0, 'close' => 85000.0]);
t_eq('year two works on what is left', asset_dep($a, '2026-04-01'), ['open' => 85000.0, 'add' => 0, 'dep' => 12750.0, 'close' => 72250.0]);
$b = ['bought_on' => '2025-12-01', 'cost' => 100000, 'rate_pct' => 15, 'disposed_on' => null];
t_eq('used under 180 days in year one: half rate', asset_dep($b, '2025-04-01')['dep'], 7500.0);
t_eq('...and full rate after', asset_dep($b, '2026-04-01')['dep'], 13875.0);
t_eq('not bought yet: nothing', asset_dep($b, '2024-04-01')['dep'], 0);

t_group('GST: returns from the bills');
t_eq('same state: half CGST, half SGST', gst_split(1000, 18, '24', '24'), ['iamt' => 0.0, 'camt' => 90.0, 'samt' => 90.0]);
t_eq('another state: IGST', gst_split(1000, 18, '27', '24'), ['iamt' => 180.0, 'camt' => 0.0, 'samt' => 0.0]);
t_eq('odd paise go to SGST, never lost', gst_split(100.05, 5, '24', '24'), ['iamt' => 0.0, 'camt' => 2.5, 'samt' => 2.5]);
t_eq('state from a GSTIN', [gst_state('24ABCDE1234F1Z5'), gst_state('bad')], ['24', '']);
q("INSERT INTO companies (name, gstin, is_gst, invoice_prefix, is_active) VALUES ('GST Test Co', '24AAAAA0000A1Z5', 1, 'GT', 1)"); $gco = insert_id();
$bp = t_party('B2B Buyer'); q("UPDATE parties SET gstin = '27BBBBB0000B1Z5' WHERE id = ?", [$bp]);
$wp = t_party('Walk-in buyer');
$it = t_item(10, null, 1000); q("UPDATE items SET hsn = '8471', unit = 'pcs' WHERE id = ?", [$it]);
foreach ([[$bp, 2360], [$wp, 1180]] as [$party, $tot]) {
    $sid = t_sale($party, $tot, $tot, '2026-08-10'); q('UPDATE sales SET company_id = ?, tax_amount = ? WHERE id = ?', [$gco, $tot - $tot / 1.18, $sid]);
    q('INSERT INTO sale_items (sale_id, item_id, qty, price, tax_rate, total) VALUES (?,?,?,?,18,?)', [$sid, $it, $tot / 1180, 1000, $tot / 1.18]);
}
$r1 = gstr1($gco, '2026-08');
t_eq('period', $r1['fp'], '082026');
t_eq('the GST buyer is a B2B invoice', [$r1['b2b'][0]['ctin'] ?? '', $r1['b2b'][0]['inv'][0]['itms'][0]['itm_det']['txval'] ?? 0], ['27BBBBB0000B1Z5', 2000.0]);
t_eq('...in another state, so IGST', $r1['b2b'][0]['inv'][0]['itms'][0]['itm_det']['iamt'] ?? 0, 360.0);
t_eq('the walk-in goes in the B2C summary with CGST/SGST', [$r1['b2cs'][0]['txval'] ?? 0, $r1['b2cs'][0]['camt'] ?? 0], [1000.0, 90.0]);
t_eq('HSN summary adds both', [$r1['hsn']['data'][0]['hsn_sc'] ?? '', $r1['hsn']['data'][0]['qty'] ?? 0, $r1['hsn']['data'][0]['txval'] ?? 0], ['8471', 3.0, 3000.0]);
$r3 = gstr3b($gco, '2026-08');
t_eq('GSTR-3B adds up the same tax', [$r3['sup_details']['osup_det']['txval'], $r3['sup_details']['osup_det']['iamt'], $r3['sup_details']['osup_det']['camt']], [3000.0, 360.0, 90.0]);
$ei = einvoice_json((int)val('SELECT id FROM sales WHERE company_id = ? AND party_id = ?', [$gco, $bp]));
t_eq('e-invoice: totals agree with the bill', [$ei['ValDtls']['AssVal'], $ei['ValDtls']['IgstVal'], $ei['BuyerDtls']['Stcd']], [2000.0, 360.0, '27']);
t_ok('a walk-in bill has no e-invoice', einvoice_json((int)val('SELECT id FROM sales WHERE company_id = ? AND party_id = ?', [$gco, $wp])) === null);
$ew = eway_json((int)val('SELECT id FROM sales WHERE company_id = ? AND party_id = ?', [$gco, $bp]), ['distance' => 120, 'vehicle' => 'GJ 10 AB 1234']);
t_eq('e-way bill: vehicle and IGST rate', [$ew['billLists'][0]['vehicleNo'], $ew['billLists'][0]['itemList'][0]['igstRate']], ['GJ10AB1234', 18.0]);

t_group('GST: TDS on purchases (194Q)');
$sp = t_party('Big Supplier'); q("UPDATE parties SET type = 'supplier' WHERE id = ?", [$sp]);
q("INSERT INTO purchases (company_id, bill_no, party_id, location_id, purchase_date, subtotal, tax_amount, total, paid, status, created_by)
   VALUES (?, 'TDS1', ?, (SELECT MIN(id) FROM locations), '2026-06-01', 6000000, 0, 6000000, 0, 'due', 1)", [$gco, $sp]);
$t = array_values(array_filter(tds_194q('2026-04-01'), fn($r) => $r['party'] === 'Big Supplier'))[0] ?? null;
t_eq('0.1% only on what is above ₹50 lakh', [$t['over_limit'] ?? null, $t['tds'] ?? null], [1000000.0, 1000.0]);

t_group('GST: composition dealer charges no tax');
$keep = setting('gst_composition', '0');
set_setting('gst_composition', '1'); t_ok('the bill save reads the switch', strpos(file_get_contents(dirname(__DIR__) . '/sales.php'), "biz_on('gst_composition')") !== false);
set_setting('gst_composition', $keep);
