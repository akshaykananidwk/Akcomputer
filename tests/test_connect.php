<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); die('CLI only'); }
// Reading other systems' messages and files. Wrong reading = a wrong suggestion
// in front of a person (nothing is booked by itself) - still, read it right.
require_once dirname(__DIR__) . '/includes/connect.php';

t_group('Connect: a UPI "money received" SMS');
t_eq('HDFC-style', upi_parse_sms('Rs.1,250.00 credited to a/c XX1234 on 08-10-26 by VPA ramesh@okaxis (UPI Ref No 628145521234). -HDFC Bank'),
     ['amount' => 1250.0, 'ref' => '628145521234', 'payer' => 'ramesh@okaxis']);
t_eq('paytm-style', upi_parse_sms('Received ₹500 from Suresh Patel. UPI Ref: 412398765432'), ['amount' => 500.0, 'ref' => '412398765432', 'payer' => 'Suresh Patel']);
t_ok('money going OUT is never read as received', upi_parse_sms('Rs 900 debited from a/c XX1234 to VPA shop@ybl UPI Ref 112233445566') === null);
t_ok('an OTP message is not money', upi_parse_sms('Your OTP is 123456. Do not share.') === null);

t_group('Connect: a bank statement in any layout');
$rows = [['Account statement'], ['Date', 'Narration', 'Chq/Ref No', 'Withdrawal Amt', 'Deposit Amt', 'Balance'],
         ['01/10/26', 'UPI/RAMESH/628145521234', '628145521234', '', '1,250.00', '10,000'], ['02/10/26', 'NEFT TO SHIV DIST', 'N123', '5,000.00', '', '5,000'], ['', 'Opening', '', '', '', '']];
t_eq('separate deposit / withdrawal columns', bank_statement_parse($rows), [['2026-10-01', 'UPI/RAMESH/628145521234', 1250.0, '628145521234'], ['2026-10-02', 'NEFT TO SHIV DIST', -5000.0, 'N123']]);
t_eq('one amount column with Dr/Cr', bank_statement_parse([['Txn Date', 'Description', 'Amount', 'Type'], ['05-Oct-2026', 'CASH DEP', '2000', 'CR'], ['06-Oct-2026', 'ATM', '700', 'DR']]),
     [['2026-10-05', 'CASH DEP', 2000.0, ''], ['2026-10-06', 'ATM', -700.0, '']]);
t_eq('an Excel day number is a date', bank_date('46303'), '2026-10-08');

t_group('Connect: Tally masters');
$tx = '<ENVELOPE><BODY><DATA><TALLYMESSAGE><LEDGER NAME="Mahesh Traders"><PARENT>Sundry Debtors</PARENT><OPENINGBALANCE>-15000.00</OPENINGBALANCE><PARTYGSTIN>24ABCDE1234F1Z5</PARTYGSTIN></LEDGER></TALLYMESSAGE>'
    . '<TALLYMESSAGE><LEDGER NAME="Shiv Supply"><PARENT>Sundry Creditors</PARENT><OPENINGBALANCE>8000</OPENINGBALANCE></LEDGER></TALLYMESSAGE>'
    . '<TALLYMESSAGE><LEDGER NAME="Cash"><PARENT>Cash-in-Hand</PARENT></LEDGER></TALLYMESSAGE>'
    . '<TALLYMESSAGE><STOCKITEM NAME="HP Mouse"><BASEUNITS>Nos</BASEUNITS><OPENINGRATE>350/Nos</OPENINGRATE></STOCKITEM></TALLYMESSAGE></DATA></BODY></ENVELOPE>';
$tp = tally_parse_xml($tx);
t_eq('debtor: they owe us 15,000', $tp['parties'][0], ['Mahesh Traders', 'customer', 15000.0, '24ABCDE1234F1Z5', '']);
t_eq('creditor: we owe them 8,000', $tp['parties'][1], ['Shiv Supply', 'supplier', -8000.0, '', '']);
t_eq('cash and other ledgers are left out', count($tp['parties']), 2);
t_eq('stock item with its rate', $tp['items'][0], ['HP Mouse', 'Nos', 350.0, '']);
$xxe = tally_parse_xml('<!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><ENVELOPE><LEDGER><NAME>&e;</NAME><PARENT>Sundry Debtors</PARENT></LEDGER></ENVELOPE>');
t_ok('a file on the server can never be pulled in through the XML', strpos(json_encode($xxe), 'root:') === false);

t_group('Connect: bills from e-mail');
$pdf = '%PDF-1.4 test';
$mail = "From: a@b.in\r\nSubject: Bill\r\nContent-Type: multipart/mixed; boundary=\"XX\"\r\n\r\n--XX\r\nContent-Type: text/plain\r\n\r\nPlease find bill\r\n--XX\r\nContent-Type: application/pdf; name=\"inv 42.pdf\"\r\nContent-Disposition: attachment; filename=\"inv 42.pdf\"\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($pdf)) . "--XX--\r\n";
t_eq('the PDF comes out, the text does not', mime_attachments($mail), [['inv 42.pdf', $pdf]]);

t_group('Connect: courier (Shiprocket, with a stand-in server)');
require_once dirname(__DIR__) . '/includes/shiprocket.php';
$keepSr = [setting('shiprocket_email', ''), setting('shiprocket_token', ''), setting('shiprocket_token_at', '0')];
set_setting('shiprocket_email', 'shop@x.in'); set_setting('shiprocket_token', ''); set_setting('shiprocket_token_at', '0');
$srCalls = [];
$GLOBALS['sr_http_mock'] = function ($m, $path, $body) use (&$srCalls) {
    $srCalls[] = [$path, $body];
    if ($path === '/auth/login') return [200, ['token' => 'tok']];
    if ($path === '/orders/create/adhoc') return [200, ['order_id' => 991, 'shipment_id' => 552]];
    if ($path === '/courier/assign/awb') return [200, ['response' => ['data' => ['awb_code' => 'AWB123', 'courier_name' => 'Delhivery']]]];
    return [404, []];
};
$sp2 = t_party('Courier buyer'); $ss = t_sale($sp2, 1180, 0);
$si = t_item(5, null, 1000); q('INSERT INTO sale_items (sale_id, item_id, qty, price, tax_rate, total) VALUES (?,?,1,1000,18,1000)', [$ss, $si]);
[$okS, $msgS] = sr_ship($ss, ['name' => 'Ravi', 'address' => 'Main road', 'city' => 'Rajkot', 'pincode' => '360001', 'state' => 'Gujarat', 'phone' => '9876543210', 'email' => ''], ['weight' => 0.5, 'l' => 10, 'b' => 10, 'h' => 5, 'cod' => 1]);
t_ok('the shipment is booked and the AWB kept', $okS && val('SELECT awb FROM shipments WHERE sale_id = ?', [$ss]) === 'AWB123', $msgS);
$order = $srCalls[1][1] ?? [];
t_eq('an unpaid bill goes cash-on-delivery for what is due', [$order['payment_method'] ?? '', $order['sub_total'] ?? 0], ['COD', 1180.0]);
t_ok('the login is reused, not repeated', sr_token() === 'tok' && count(array_filter($srCalls, fn($c) => $c[0] === '/auth/login')) === 1);
unset($GLOBALS['sr_http_mock']);
set_setting('shiprocket_email', $keepSr[0]); set_setting('shiprocket_token', $keepSr[1]); set_setting('shiprocket_token_at', $keepSr[2]);
