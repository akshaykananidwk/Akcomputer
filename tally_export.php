<?php
// Tally-compatible XML export (Sales/Purchase/Payments/Expenses vouchers) -
// importable via Tally: Gateway of Tally > Import Data > Vouchers. Uses the
// standard TALLYMESSAGE voucher schema; ledger names default to the
// party's own name plus generic "Sales Account"/"Purchase Account"/GST
// ledgers, which is the same convention most billing-to-Tally exports use
// (rename to match your existing Tally ledgers if they differ).
require_once __DIR__ . '/includes/init.php';
require_perm('reports.view');

function tx($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_XML1, 'UTF-8'); }

// GSTIN's first 2 digits are the state code - if the firm and the party are
// in different states the correct split is IGST, not CGST+SGST. Falls back
// to the original CGST+SGST split whenever either side's GSTIN is missing
// (e.g. B2C retail bills, which is most of this app's traffic).
function tally_gst_lines($tax, $companyGstin, $partyGstin) {
    $cState = strlen((string)$companyGstin) >= 2 ? substr($companyGstin, 0, 2) : '';
    $pState = strlen((string)$partyGstin) >= 2 ? substr($partyGstin, 0, 2) : '';
    if ($cState && $pState && $cState !== $pState) {
        return [['IGST', $tax]];
    }
    $cgst = round($tax / 2, 2);
    return [['CGST', $cgst], ['SGST', $tax - $cgst]];
}

$type = in_array(get('type'), ['purchase', 'payments', 'expenses'], true) ? get('type') : 'sales';
$from = get('from', date('Y-m-01'));
$to = get('to', today());

header('Content-Type: application/xml; charset=UTF-8');
header('Content-Disposition: attachment; filename="tally_' . $type . '_' . $from . '_to_' . $to . '.xml"');
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<ENVELOPE>
 <HEADER><TALLYREQUEST>Import Data</TALLYREQUEST></HEADER>
 <BODY><IMPORTDATA><REQUESTDESC><REPORTNAME>Vouchers</REPORTNAME></REQUESTDESC><REQUESTDATA>' . "\n";

function tally_voucher($vchType, $date, $vchNo, $partyLedger, $narration, $lines) {
    echo "  <TALLYMESSAGE xmlns:UDF=\"TallyUDF\">\n";
    echo "   <VOUCHER VCHTYPE=\"$vchType\" ACTION=\"Create\">\n";
    echo '    <DATE>' . date('Ymd', strtotime($date)) . "</DATE>\n";
    echo "    <VOUCHERTYPENAME>$vchType</VOUCHERTYPENAME>\n";
    echo '    <VOUCHERNUMBER>' . tx($vchNo) . "</VOUCHERNUMBER>\n";
    echo '    <PARTYLEDGERNAME>' . tx($partyLedger) . "</PARTYLEDGERNAME>\n";
    echo '    <NARRATION>' . tx($narration) . "</NARRATION>\n";
    foreach ($lines as $l) {
        [$ledger, $isDr, $amount] = $l;
        echo "    <ALLLEDGERENTRIES.LIST>\n";
        echo '     <LEDGERNAME>' . tx($ledger) . "</LEDGERNAME>\n";
        echo '     <ISDEEMEDPOSITIVE>' . ($isDr ? 'Yes' : 'No') . "</ISDEEMEDPOSITIVE>\n";
        echo '     <AMOUNT>' . number_format($amount, 2, '.', '') . "</AMOUNT>\n";
        echo "    </ALLLEDGERENTRIES.LIST>\n";
    }
    echo "   </VOUCHER>\n";
    echo "  </TALLYMESSAGE>\n";
}

if ($type === 'sales' || $type === 'purchase') {
    if ($type === 'sales') {
        $vouchers = all("SELECT s.*, p.name party_name, p.gstin party_gstin, c.name company_name, c.gstin company_gstin FROM sales s
                         LEFT JOIN parties p ON p.id = s.party_id
                         JOIN companies c ON c.id = s.company_id
                         WHERE s.is_cancelled = 0 AND s.sale_date BETWEEN ? AND ? ORDER BY s.sale_date, s.id", [$from, $to]);
    } else {
        $vouchers = all("SELECT pu.*, pt.name party_name, pt.gstin party_gstin, c.name company_name, c.gstin company_gstin FROM purchases pu
                         JOIN parties pt ON pt.id = pu.party_id
                         JOIN companies c ON c.id = pu.company_id
                         WHERE pu.purchase_date BETWEEN ? AND ? ORDER BY pu.purchase_date, pu.id", [$from, $to]);
    }
    $vchType = $type === 'sales' ? 'Sales' : 'Purchase';
    $dateField = $type === 'sales' ? 'sale_date' : 'purchase_date';
    $docField = $type === 'sales' ? 'invoice_no' : 'bill_no';
    $accountLedger = $type === 'sales' ? 'Sales Account' : 'Purchase Account';

    foreach ($vouchers as $v) {
        $party = $v['party_name'] ?: ($type === 'sales' ? ($v['customer_name'] ?: 'Cash Sale') : 'Supplier');
        $taxable = (float)$v['subtotal'] - (float)$v['discount'];
        $tax = (float)$v['tax_amount'];
        $total = (float)$v['total'];
        $sign = $type === 'sales' ? -1 : 1;
        $lines = [
            [$party, $type === 'sales', $sign * $total],
            [$accountLedger, $type === 'purchase', -$sign * $taxable],
        ];
        if ($tax > 0.009) {
            foreach (tally_gst_lines($tax, $v['company_gstin'], $v['party_gstin']) as $g) {
                [$label, $amt] = $g;
                $ledger = ($type === 'sales' ? 'Output ' : 'Input ') . $label;
                $lines[] = [$ledger, $type === 'purchase', -$sign * $amt];
            }
        }
        tally_voucher($vchType, $v[$dateField], $v[$docField], $party, $v['company_name'] . ' - ' . $v[$docField], $lines);
    }
    log_activity('tally_export', "$type $from to $to (" . count($vouchers) . " vouchers)");
}

if ($type === 'payments') {
    $vouchers = all("SELECT p.*, pt.name party_name, b.account_name FROM payments p
                     LEFT JOIN parties pt ON pt.id = p.party_id
                     LEFT JOIN bank_accounts b ON b.id = p.bank_account_id
                     WHERE p.mode <> 'discount' AND p.pay_date BETWEEN ? AND ? ORDER BY p.pay_date, p.id", [$from, $to]);
    foreach ($vouchers as $v) {
        $party = $v['party_name'] ?: 'Cash';
        $cashBank = $v['account_name'] ?: 'Cash';
        $amt = (float)$v['amount'];
        $isReceipt = $v['direction'] === 'in';
        $lines = [
            [$party, $isReceipt, $isReceipt ? $amt : -$amt],
            [$cashBank, true, $isReceipt ? -$amt : $amt],
        ];
        tally_voucher($isReceipt ? 'Receipt' : 'Payment', $v['pay_date'], 'PMT-' . $v['id'], $party, trim($v['notes']) ?: ($isReceipt ? 'Receipt' : 'Payment') . ' - ' . $party, $lines);
    }
    log_activity('tally_export', "payments $from to $to (" . count($vouchers) . " vouchers)");
}

if ($type === 'expenses') {
    $vouchers = all("SELECT e.*, b.account_name FROM expenses e LEFT JOIN bank_accounts b ON b.id = e.bank_account_id
                     WHERE e.exp_date BETWEEN ? AND ? ORDER BY e.exp_date, e.id", [$from, $to]);
    foreach ($vouchers as $v) {
        $expLedger = $v['category'] ?: 'General Expense';
        $cashBank = $v['account_name'] ?: 'Cash';
        $amt = (float)$v['amount'];
        $lines = [
            [$expLedger, true, -$amt],
            [$cashBank, true, $amt],
        ];
        tally_voucher('Payment', $v['exp_date'], 'EXP-' . $v['id'], $expLedger, trim($v['notes']) ?: $expLedger, $lines);
    }
    log_activity('tally_export', "expenses $from to $to (" . count($vouchers) . " vouchers)");
}

echo " </REQUESTDATA></IMPORTDATA></BODY>\n</ENVELOPE>\n";
