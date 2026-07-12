<?php
// Tally-compatible XML export (Sales/Purchase vouchers) - importable via
// Tally: Gateway of Tally > Import Data > Vouchers. Uses the standard
// TALLYMESSAGE voucher schema; ledger names default to the party's own
// name plus generic "Sales Account"/"Purchase Account"/GST ledgers, which
// is the same convention most billing-to-Tally exports use (rename to
// match your existing Tally ledgers if they differ).
require_once __DIR__ . '/includes/init.php';
require_perm('reports.view');

function tx($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_XML1, 'UTF-8'); }

$type = get('type') === 'purchase' ? 'purchase' : 'sales';
$from = get('from', date('Y-m-01'));
$to = get('to', today());

if ($type === 'sales') {
    $vouchers = all("SELECT s.*, p.name party_name, c.name company_name FROM sales s
                     LEFT JOIN parties p ON p.id = s.party_id
                     JOIN companies c ON c.id = s.company_id
                     WHERE s.is_cancelled = 0 AND s.sale_date BETWEEN ? AND ? ORDER BY s.sale_date, s.id", [$from, $to]);
} else {
    $vouchers = all("SELECT p.*, pt.name party_name, c.name company_name FROM purchases p
                     JOIN parties pt ON pt.id = p.party_id
                     JOIN companies c ON c.id = p.company_id
                     WHERE p.purchase_date BETWEEN ? AND ? ORDER BY p.purchase_date, p.id", [$from, $to]);
}

header('Content-Type: application/xml; charset=UTF-8');
header('Content-Disposition: attachment; filename="tally_' . $type . '_' . $from . '_to_' . $to . '.xml"');

$vchType = $type === 'sales' ? 'Sales' : 'Purchase';
$dateField = $type === 'sales' ? 'sale_date' : 'purchase_date';
$docField = $type === 'sales' ? 'invoice_no' : 'bill_no';
$accountLedger = $type === 'sales' ? 'Sales Account' : 'Purchase Account';

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<ENVELOPE>
 <HEADER><TALLYREQUEST>Import Data</TALLYREQUEST></HEADER>
 <BODY><IMPORTDATA><REQUESTDESC><REPORTNAME>Vouchers</REPORTNAME></REQUESTDESC><REQUESTDATA>' . "\n";

foreach ($vouchers as $v) {
    $party = $v['party_name'] ?: ($type === 'sales' ? ($v['customer_name'] ?: 'Cash Sale') : 'Supplier');
    $tallyDate = date('Ymd', strtotime($v[$dateField]));
    $taxable = (float)$v['subtotal'] - (float)$v['discount'];
    $tax = (float)$v['tax_amount'];
    $total = (float)$v['total'];
    $cgst = round($tax / 2, 2);
    $sgst = $tax - $cgst;

    echo "  <TALLYMESSAGE xmlns:UDF=\"TallyUDF\">\n";
    echo "   <VOUCHER VCHTYPE=\"$vchType\" ACTION=\"Create\">\n";
    echo '    <DATE>' . $tallyDate . "</DATE>\n";
    echo "    <VOUCHERTYPENAME>$vchType</VOUCHERTYPENAME>\n";
    echo '    <VOUCHERNUMBER>' . tx($v[$docField]) . "</VOUCHERNUMBER>\n";
    echo '    <PARTYLEDGERNAME>' . tx($party) . "</PARTYLEDGERNAME>\n";
    echo '    <NARRATION>' . tx($v['company_name'] . ' - ' . $v[$docField]) . "</NARRATION>\n";

    // Party ledger entry (receivable on sales, payable on purchase)
    echo "    <ALLLEDGERENTRIES.LIST>\n";
    echo '     <LEDGERNAME>' . tx($party) . "</LEDGERNAME>\n";
    echo '     <ISDEEMEDPOSITIVE>' . ($type === 'sales' ? 'Yes' : 'No') . "</ISDEEMEDPOSITIVE>\n";
    echo '     <AMOUNT>' . ($type === 'sales' ? '-' : '') . number_format($total, 2, '.', '') . "</AMOUNT>\n";
    echo "    </ALLLEDGERENTRIES.LIST>\n";

    // Sales/Purchase account entry (taxable value)
    echo "    <ALLLEDGERENTRIES.LIST>\n";
    echo "     <LEDGERNAME>$accountLedger</LEDGERNAME>\n";
    echo '     <ISDEEMEDPOSITIVE>' . ($type === 'sales' ? 'No' : 'Yes') . "</ISDEEMEDPOSITIVE>\n";
    echo '     <AMOUNT>' . ($type === 'sales' ? '' : '-') . number_format($taxable, 2, '.', '') . "</AMOUNT>\n";
    echo "    </ALLLEDGERENTRIES.LIST>\n";

    // GST ledgers (only if this bill actually had tax)
    if ($tax > 0.009) {
        foreach ([['Output CGST', 'Input CGST', $cgst], ['Output SGST', 'Input SGST', $sgst]] as $g) {
            echo "    <ALLLEDGERENTRIES.LIST>\n";
            echo '     <LEDGERNAME>' . ($type === 'sales' ? $g[0] : $g[1]) . "</LEDGERNAME>\n";
            echo '     <ISDEEMEDPOSITIVE>' . ($type === 'sales' ? 'No' : 'Yes') . "</ISDEEMEDPOSITIVE>\n";
            echo '     <AMOUNT>' . ($type === 'sales' ? '' : '-') . number_format($g[2], 2, '.', '') . "</AMOUNT>\n";
            echo "    </ALLLEDGERENTRIES.LIST>\n";
        }
    }

    echo "   </VOUCHER>\n";
    echo "  </TALLYMESSAGE>\n";
}

echo " </REQUESTDATA></IMPORTDATA></BODY>\n</ENVELOPE>\n";
log_activity('tally_export', "$type $from to $to (" . count($vouchers) . " vouchers)");
