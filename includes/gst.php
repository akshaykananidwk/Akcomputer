<?php
// GST returns and documents, worked out from the GST firm's bills.
//
// The figures are the bills' own: taxable value = the line total (after the
// line's discount, as the bill charged GST on it), tax = taxable x rate.
// Same state as the shop (first two digits of the GSTIN) = CGST + SGST,
// another state = IGST. The JSON files are in the government's own formats,
// to upload on the GST / e-invoice / e-way bill portals - this software does
// not file anything by itself.

function gst_state($gstin) { return preg_match('/^(\d{2})[A-Z0-9]{13}$/', strtoupper(trim((string)$gstin)), $m) ? $m[1] : ''; }
function gst_r($n) { return round((float)$n, 2); }

/** Every taxed line of a GST firm's bills in a date range. */
function gst_lines($companyId, $from, $to) {
    return all("SELECT s.id sale_id, s.invoice_no, s.sale_date, s.total bill_total, s.customer_name, p.name party_name, p.gstin party_gstin, p.address party_address,
                       si.qty, si.price, si.total txval, si.tax_rate rt, i.name item, i.hsn, i.unit, i.item_type
                FROM sales s JOIN sale_items si ON si.sale_id = s.id JOIN items i ON i.id = si.item_id LEFT JOIN parties p ON p.id = s.party_id
                WHERE s.company_id = ? AND s.is_cancelled = 0 AND s.sale_date BETWEEN ? AND ? AND si.qty > 0
                ORDER BY s.sale_date, s.id", [$companyId, $from, $to]);
}

/** Tax on one line, split by where the buyer is. */
function gst_split($txval, $rt, $buyerState, $shopState) {
    $tax = gst_r($txval * $rt / 100);
    if ($buyerState !== '' && $shopState !== '' && $buyerState !== $shopState) return ['iamt' => $tax, 'camt' => 0.0, 'samt' => 0.0];
    $c = gst_r($tax / 2);
    return ['iamt' => 0.0, 'camt' => $c, 'samt' => gst_r($tax - $c)];
}

function gst_month_range($month) { return [$month . '-01', date('Y-m-t', strtotime($month . '-01'))]; }

/** GSTR-1 for a month in the offline-tool JSON shape: B2B invoices, B2C summary, HSN summary. */
function gstr1($companyId, $month) {
    $co = row('SELECT * FROM companies WHERE id = ?', [$companyId]);
    $shop = gst_state($co['gstin'] ?? '');
    [$from, $to] = gst_month_range($month);
    $b2b = []; $b2cs = []; $hsn = [];
    foreach (gst_lines($companyId, $from, $to) as $l) {
        $buyer = gst_state($l['party_gstin']);
        $t = gst_split($l['txval'], $l['rt'], $buyer, $shop);
        if ($buyer !== '') {
            $ctin = strtoupper($l['party_gstin']);
            $inv = &$b2b[$ctin][$l['invoice_no']];
            if (!$inv) $inv = ['inum' => $l['invoice_no'], 'idt' => date('d-m-Y', strtotime($l['sale_date'])), 'val' => gst_r($l['bill_total']),
                               'pos' => $buyer, 'rchrg' => 'N', 'inv_typ' => 'R', 'itms' => []];
            $k = (string)(float)$l['rt'];
            if (!isset($inv['itms'][$k])) $inv['itms'][$k] = ['num' => count($inv['itms']) + 1, 'itm_det' => ['txval' => 0, 'rt' => (float)$l['rt'], 'iamt' => 0, 'camt' => 0, 'samt' => 0, 'csamt' => 0]];
            $d = &$inv['itms'][$k]['itm_det'];
            $d['txval'] = gst_r($d['txval'] + $l['txval']); foreach (['iamt', 'camt', 'samt'] as $x) $d[$x] = gst_r($d[$x] + $t[$x]);
            unset($d, $inv);
        } else {
            $k = ($t['iamt'] > 0 ? 'INTER' : 'INTRA') . '|' . (float)$l['rt'];
            if (!isset($b2cs[$k])) $b2cs[$k] = ['sply_ty' => $t['iamt'] > 0 ? 'INTER' : 'INTRA', 'pos' => $shop, 'typ' => 'OE', 'rt' => (float)$l['rt'], 'txval' => 0, 'iamt' => 0, 'camt' => 0, 'samt' => 0, 'csamt' => 0];
            $b2cs[$k]['txval'] = gst_r($b2cs[$k]['txval'] + $l['txval']); foreach (['iamt', 'camt', 'samt'] as $x) $b2cs[$k][$x] = gst_r($b2cs[$k][$x] + $t[$x]);
        }
        $hk = ($l['hsn'] ?: 'NA') . '|' . (float)$l['rt'];
        if (!isset($hsn[$hk])) $hsn[$hk] = ['hsn_sc' => $l['hsn'] ?: '', 'desc' => mb_substr($l['item'], 0, 30), 'uqc' => gst_uqc($l['unit'], $l['item_type']), 'qty' => 0, 'rt' => (float)$l['rt'], 'txval' => 0, 'iamt' => 0, 'camt' => 0, 'samt' => 0, 'csamt' => 0];
        $hsn[$hk]['qty'] = round($hsn[$hk]['qty'] + $l['qty'], 3); $hsn[$hk]['txval'] = gst_r($hsn[$hk]['txval'] + $l['txval']);
        foreach (['iamt', 'camt', 'samt'] as $x) $hsn[$hk][$x] = gst_r($hsn[$hk][$x] + $t[$x]);
    }
    $out = ['gstin' => strtoupper((string)($co['gstin'] ?? '')), 'fp' => date('mY', strtotime($month . '-01')), 'b2b' => [], 'b2cs' => array_values($b2cs), 'hsn' => ['data' => []]];
    foreach ($b2b as $ctin => $invs) $out['b2b'][] = ['ctin' => $ctin, 'inv' => array_values(array_map(fn($i) => array_merge($i, ['itms' => array_values($i['itms'])]), $invs))];
    $n = 0; foreach ($hsn as $h) $out['hsn']['data'][] = ['num' => ++$n] + $h;
    return $out;
}

/** The unit codes the GST portal knows, from ours. */
function gst_uqc($unit, $type = 'product') {
    if ($type === 'service') return 'NA';
    $u = strtolower(preg_replace('/\W/', '', (string)$unit));
    return ['pcs' => 'PCS', 'pc' => 'PCS', 'nos' => 'NOS', 'no' => 'NOS', 'kg' => 'KGS', 'kgs' => 'KGS', 'g' => 'GMS', 'gm' => 'GMS', 'gram' => 'GMS',
            'ltr' => 'LTR', 'l' => 'LTR', 'litre' => 'LTR', 'ml' => 'MLT', 'mtr' => 'MTR', 'm' => 'MTR', 'meter' => 'MTR', 'box' => 'BOX', 'set' => 'SET',
            'pair' => 'PRS', 'dozen' => 'DOZ', 'pack' => 'PAC', 'roll' => 'ROL', 'sqft' => 'SQF', 'bottle' => 'BTL', 'strip' => 'NOS', 'tube' => 'NOS'][$u] ?? 'OTH';
}

/** GSTR-3B summary for a month: tax on sales out, tax paid on purchases in (input credit). */
function gstr3b($companyId, $month) {
    $r1 = gstr1($companyId, $month);
    $out = ['txval' => 0, 'iamt' => 0, 'camt' => 0, 'samt' => 0, 'csamt' => 0];
    foreach ($r1['b2b'] as $c) foreach ($c['inv'] as $i) foreach ($i['itms'] as $it) foreach ($out as $k => $v) $out[$k] = gst_r($v + ($it['itm_det'][$k] ?? 0));
    foreach ($r1['b2cs'] as $b) foreach ($out as $k => $v) $out[$k] = gst_r($v + ($b[$k] ?? 0));
    [$from, $to] = gst_month_range($month);
    $itc = (float)val('SELECT COALESCE(SUM(tax_amount), 0) FROM purchases WHERE company_id = ? AND is_cancelled = 0 AND purchase_date BETWEEN ? AND ?', [$companyId, $from, $to]);
    $c = gst_r($itc / 2);
    return ['gstin' => $r1['gstin'], 'ret_period' => $r1['fp'],
            'sup_details' => ['osup_det' => $out],
            'itc_elg' => ['itc_avl' => [['ty' => 'OTH', 'iamt' => 0, 'camt' => $c, 'samt' => gst_r($itc - $c), 'csamt' => 0]]],
            'payable' => ['camt' => max(0, gst_r($out['camt'] - $c)), 'samt' => max(0, gst_r($out['samt'] - ($itc - $c))), 'iamt' => $out['iamt']]];
}

/** e-invoice (IRN) JSON for one B2B bill, NIC schema 1.1 - to upload on the e-invoice portal. */
function einvoice_json($saleId) {
    $s = row('SELECT s.*, c.name c_name, c.gstin c_gstin, c.address c_address, p.name p_name, p.gstin p_gstin, p.address p_address, p.city p_city
              FROM sales s JOIN companies c ON c.id = s.company_id LEFT JOIN parties p ON p.id = s.party_id WHERE s.id = ?', [(int)$saleId]);
    if (!$s || gst_state($s['c_gstin']) === '' || gst_state($s['p_gstin']) === '') return null;
    $shop = gst_state($s['c_gstin']); $buyer = gst_state($s['p_gstin']);
    $pin = fn($a) => preg_match('/\b(\d{6})\b/', (string)$a, $m) ? (int)$m[1] : (int)setting('shop_pin', '0');
    $items = []; $tot = ['AssVal' => 0, 'CgstVal' => 0, 'SgstVal' => 0, 'IgstVal' => 0];
    foreach (all('SELECT si.*, i.name, i.hsn, i.unit, i.item_type FROM sale_items si JOIN items i ON i.id = si.item_id WHERE si.sale_id = ? AND si.qty > 0', [$s['id']]) as $n => $l) {
        $t = gst_split($l['total'], $l['tax_rate'], $buyer, $shop);
        $items[] = ['SlNo' => (string)($n + 1), 'PrdDesc' => mb_substr($l['name'], 0, 300), 'IsServc' => $l['item_type'] === 'service' ? 'Y' : 'N', 'HsnCd' => (string)$l['hsn'],
                    'Qty' => round((float)$l['qty'], 3), 'Unit' => gst_uqc($l['unit'], $l['item_type']), 'UnitPrice' => gst_r($l['price']), 'TotAmt' => gst_r($l['qty'] * $l['price']),
                    'Discount' => gst_r($l['qty'] * $l['price'] - $l['total']), 'AssAmt' => gst_r($l['total']), 'GstRt' => (float)$l['tax_rate'],
                    'IgstAmt' => $t['iamt'], 'CgstAmt' => $t['camt'], 'SgstAmt' => $t['samt'], 'TotItemVal' => gst_r($l['total'] + $t['iamt'] + $t['camt'] + $t['samt'])];
        $tot['AssVal'] = gst_r($tot['AssVal'] + $l['total']); $tot['CgstVal'] = gst_r($tot['CgstVal'] + $t['camt']); $tot['SgstVal'] = gst_r($tot['SgstVal'] + $t['samt']); $tot['IgstVal'] = gst_r($tot['IgstVal'] + $t['iamt']);
    }
    return ['Version' => '1.1', 'TranDtls' => ['TaxSch' => 'GST', 'SupTyp' => 'B2B', 'RegRev' => 'N'],
            'DocDtls' => ['Typ' => 'INV', 'No' => $s['invoice_no'], 'Dt' => date('d/m/Y', strtotime($s['sale_date']))],
            'SellerDtls' => ['Gstin' => strtoupper($s['c_gstin']), 'LglNm' => $s['c_name'], 'Addr1' => mb_substr($s['c_address'] ?: shop_city(), 0, 100), 'Loc' => shop_city() ?: 'NA', 'Pin' => $pin($s['c_address']), 'Stcd' => $shop],
            'BuyerDtls' => ['Gstin' => strtoupper($s['p_gstin']), 'LglNm' => $s['p_name'], 'Pos' => $buyer, 'Addr1' => mb_substr($s['p_address'] ?: ($s['p_city'] ?: 'NA'), 0, 100), 'Loc' => $s['p_city'] ?: 'NA', 'Pin' => $pin($s['p_address']), 'Stcd' => $buyer],
            'ItemList' => $items,
            'ValDtls' => $tot + ['Discount' => 0, 'OthChrg' => gst_r($s['shipping']), 'RndOffAmt' => gst_r($s['round_off']), 'TotInvVal' => gst_r($s['total'])]];
}

/** e-way bill JSON (bulk upload format) for one bill and its transport. */
function eway_json($saleId, array $tr) {
    $s = row('SELECT s.*, c.name c_name, c.gstin c_gstin, c.address c_address, p.name p_name, p.gstin p_gstin, p.address p_address, p.city p_city
              FROM sales s JOIN companies c ON c.id = s.company_id LEFT JOIN parties p ON p.id = s.party_id WHERE s.id = ?', [(int)$saleId]);
    if (!$s || gst_state($s['c_gstin']) === '') return null;
    $shop = gst_state($s['c_gstin']); $buyer = gst_state($s['p_gstin']) ?: (preg_match('/^\d{2}$/', (string)($tr['to_state'] ?? '')) ? $tr['to_state'] : $shop);
    $items = []; $tv = ['cgst' => 0, 'sgst' => 0, 'igst' => 0, 'tx' => 0];
    foreach (all('SELECT si.*, i.name, i.hsn, i.unit, i.item_type FROM sale_items si JOIN items i ON i.id = si.item_id WHERE si.sale_id = ? AND si.qty > 0', [$s['id']]) as $n => $l) {
        if ($l['item_type'] === 'service') continue;   // an e-way bill is for goods
        $t = gst_split($l['total'], $l['tax_rate'], $buyer, $shop); $inter = $t['iamt'] > 0;
        $items[] = ['itemNo' => $n + 1, 'productName' => mb_substr($l['name'], 0, 100), 'hsnCode' => (int)$l['hsn'], 'quantity' => round((float)$l['qty'], 3), 'qtyUnit' => gst_uqc($l['unit']),
                    'taxableAmount' => gst_r($l['total']), 'cgstRate' => $inter ? 0 : $l['tax_rate'] / 2, 'sgstRate' => $inter ? 0 : $l['tax_rate'] / 2, 'igstRate' => $inter ? (float)$l['tax_rate'] : 0, 'cessRate' => 0];
        $tv['tx'] += $l['total']; $tv['cgst'] += $t['camt']; $tv['sgst'] += $t['samt']; $tv['igst'] += $t['iamt'];
    }
    $pin = fn($a) => preg_match('/\b(\d{6})\b/', (string)$a, $m) ? (int)$m[1] : 0;
    return ['version' => '1.0.0621', 'billLists' => [[
        'userGstin' => strtoupper($s['c_gstin']), 'supplyType' => 'O', 'subSupplyType' => 1, 'docType' => 'INV', 'docNo' => $s['invoice_no'], 'docDate' => date('d/m/Y', strtotime($s['sale_date'])),
        'fromGstin' => strtoupper($s['c_gstin']), 'fromTrdName' => $s['c_name'], 'fromAddr1' => mb_substr((string)$s['c_address'], 0, 120), 'fromPlace' => shop_city(),
        'fromPincode' => $pin($s['c_address']) ?: (int)setting('shop_pin', '0'), 'fromStateCode' => (int)$shop, 'actualFromStateCode' => (int)$shop,
        'toGstin' => gst_state($s['p_gstin']) ? strtoupper($s['p_gstin']) : 'URP', 'toTrdName' => $s['p_name'] ?: $s['customer_name'], 'toAddr1' => mb_substr((string)($s['p_address'] ?: $s['delivery_address']), 0, 120),
        'toPlace' => $s['p_city'] ?: '', 'toPincode' => (int)($tr['to_pin'] ?? 0) ?: $pin($s['p_address']), 'toStateCode' => (int)$buyer, 'actualToStateCode' => (int)$buyer,
        'totalValue' => gst_r($tv['tx']), 'cgstValue' => gst_r($tv['cgst']), 'sgstValue' => gst_r($tv['sgst']), 'igstValue' => gst_r($tv['igst']), 'cessValue' => 0, 'totInvValue' => gst_r($s['total']),
        'transMode' => (int)($tr['mode'] ?? 1), 'transDistance' => (int)($tr['distance'] ?? 0), 'transporterName' => (string)($tr['transporter'] ?? ''), 'transporterId' => (string)($tr['transporter_id'] ?? ''),
        'vehicleNo' => strtoupper(preg_replace('/\s+/', '', (string)($tr['vehicle'] ?? ''))), 'vehicleType' => 'R', 'itemList' => $items]]];
}

/** TDS u/s 194Q on purchases: in a financial year, what each supplier was paid for goods above the limit, and the TDS on it. */
function tds_194q($fyStart) {
    $limit = (float)setting('tds_194q_limit', '5000000'); $rate = (float)setting('tds_194q_rate', '0.1');
    $fyEnd = date('Y-m-d', strtotime($fyStart . ' +1 year -1 day'));
    $out = [];
    foreach (all("SELECT p.party_id, pa.name, pa.gstin, SUM(p.total - p.tax_amount) base FROM purchases p JOIN parties pa ON pa.id = p.party_id
                  WHERE p.is_cancelled = 0 AND p.purchase_date BETWEEN ? AND ? GROUP BY p.party_id, pa.name, pa.gstin ORDER BY base DESC", [$fyStart, $fyEnd]) as $r) {
        $over = max(0, (float)$r['base'] - $limit);
        $out[] = ['party' => $r['name'], 'gstin' => $r['gstin'], 'purchases' => gst_r($r['base']), 'over_limit' => gst_r($over), 'tds' => gst_r($over * $rate / 100)];
    }
    return $out;
}
