<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); die('CLI only'); }
// Party merge: a duplicate party's whole history moves onto the kept party
// untouched (same ids, numbers, dates, amounts) and the duplicate is gone.
require_once __DIR__ . '/../includes/party_merge.php';

t_group('party merge moves everything, changes nothing else');
$keep = t_party('PM Tanveer', 0);
$dup  = t_party('PM Tanveer Rupen bandar', 1500);   // carries an opening balance
q("UPDATE parties SET mobile = '', gstin = '24ABCDE1234F1Z5', loyalty_points = 7 WHERE id = ?", [$keep]);
q("UPDATE parties SET mobile = '9099961124', gstin = '', loyalty_points = 5 WHERE id = ?", [$dup]);

t_sale($keep, 1000, 0, '2026-01-10');
$s1 = t_sale($dup, 4000, 1000, '2025-12-05');
$s2 = t_sale($dup, 2500, 0, '2026-02-14');
$pay = t_payment($dup, 1200, 'in', 'upi', [['sale', $s2, 700], ['opening', $dup, 500]]);
q("UPDATE payments SET pay_date = '2026-03-01' WHERE id = ?", [$pay]);

$balKeep = party_balance($keep); $balDup = party_balance($dup);
$before = row('SELECT invoice_no, sale_date, total, paid FROM sales WHERE id = ?', [$s1]);
$preview = party_merge_preview($dup);
t_eq('preview counts the duplicate\'s 2 bills', $preview['sales'] ?? 0, 2);
t_eq('…and its 1 payment', $preview['payments'] ?? 0, 1);

$res = party_merge($dup, $keep);
t_ok('the duplicate party is deleted', !row('SELECT id FROM parties WHERE id = ?', [$dup]));
t_eq('nothing anywhere still points at it', array_sum(party_merge_preview($dup)), 0);
t_eq('kept balance = both balances added', party_balance($keep), $balKeep + $balDup);
t_eq('opening balance carried across', (float)val('SELECT opening_balance FROM parties WHERE id = ?', [$keep]), 1500);
t_eq('loyalty points added', (int)val('SELECT loyalty_points FROM parties WHERE id = ?', [$keep]), 12);
$after = row('SELECT invoice_no, sale_date, total, paid, party_id FROM sales WHERE id = ?', [$s1]);
t_ok('bill keeps its own number, date and amounts', $after['invoice_no'] === $before['invoice_no'] && $after['sale_date'] === $before['sale_date']
     && (float)$after['total'] === (float)$before['total'] && (float)$after['paid'] === (float)$before['paid']);
t_eq('…and now belongs to the kept party', (int)$after['party_id'], $keep);
t_eq('payment keeps its date', val('SELECT pay_date FROM payments WHERE id = ?', [$pay]), '2026-03-01', 0);
t_eq('payment linked to the old opening balance follows it', (int)val("SELECT ref_id FROM payment_allocations WHERE payment_id = ? AND ref_type = 'opening'", [$pay]), $keep);
t_eq('opening due is still reduced by that link', opening_due($keep, 'in'), 1000);
t_eq('blank mobile filled from the duplicate', val('SELECT mobile FROM parties WHERE id = ?', [$keep]), '9099961124', 0);
t_eq('kept party\'s own GSTIN is not overwritten', val('SELECT gstin FROM parties WHERE id = ?', [$keep]), '24ABCDE1234F1Z5', 0);
t_eq('ledger now lists all 3 bills', (int)val('SELECT COUNT(*) FROM sales WHERE party_id = ?', [$keep]), 3);

t_group('party merge refuses nonsense');
$thrown = false; try { party_merge($keep, $keep); } catch (Exception $e) { $thrown = true; }
t_ok('cannot merge a party into itself', $thrown);
$thrown = false; try { party_merge(999999999, $keep); } catch (Exception $e) { $thrown = true; }
t_ok('cannot merge a party that does not exist', $thrown);
