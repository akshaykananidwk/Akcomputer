<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); die('CLI only'); }
// ============================================================================
//  PARTY MERGE - one customer saved three times (Tanveer / Tanveer Rupen
//  bandar / BAHARUNI TANVIRAHMED, same mobile, Parties screen 17-11-2026).
//  Merging moves every entry to the kept party with its own date, keeps the
//  books exactly where they were, and Undo puts every row back.
// ============================================================================
require_once __DIR__ . '/../includes/party_merge.php';
require_once __DIR__ . '/../includes/entry_save.php';
t_group('Merge duplicate parties');
$T = '2099-12-31';
$u = row('SELECT u.*, l.name location_name FROM users u JOIN locations l ON l.id = u.location_id ORDER BY u.id LIMIT 1'); $u['perms'] = ['*'];
$co = (int)val('SELECT id FROM companies ORDER BY id LIMIT 1');
$loc = (int)$u['location_id'];
$mkParty = function ($name, $mobile, $opening) {
    q("INSERT INTO parties (name, type, mobile, opening_balance, is_active) VALUES (?, 'customer', ?, ?, 1)", [$name, $mobile, $opening]);
    return (int)val('SELECT MAX(id) FROM parties');
};
$keep = $mkParty('PM Tanveer', '90999 61124', 100);
$d1 = $mkParty('PM Tanveer Rupen bandar', '9099961124', 0);
$d2 = $mkParty('PM BAHARUNI TANVIRAHMED', '090999 61124', -40);
q("UPDATE parties SET gstin = '24ABCDE1234F1Z5' WHERE id = ?", [$d1]);
q("INSERT INTO items (name, selling_price, purchase_price, tax_rate, is_active, item_type) VALUES ('PM Item', 500, 300, 0, 1, 'product')");
$it = (int)val('SELECT MAX(id) FROM items');
adjust_stock($it, $loc, 5, 'opening', null, 't');
$_POST = ['company_id' => $co, 'location_id' => $loc, 'party_id' => $d1, 'sale_date' => '2026-03-03', 'payment_mode' => 'credit', 'paid' => 0,
          'row_n' => [1], 'item_id' => [$it], 'qty' => [1], 'price' => [500], 'tax_rate' => [0]];
$sale = sale_save_form($u);
$_POST = ['party_id' => $d2, 'direction' => 'in', 'amount' => 200, 'mode' => 'cash', 'pay_date' => '2026-04-05'];
payment_save_form($u);
q("INSERT INTO follow_ups (party_id, title, due_date, created_by) VALUES (?, 'call', CURDATE(), 1)", [$d1]);
$_POST = [];

$dupe = array_values(array_filter(party_merge_duplicates(), fn($g) => in_array($keep, array_map(fn($x) => (int)$x['id'], $g), true)));
t_eq('the three are found as one customer (same last 10 digits of mobile)', count($dupe[0] ?? []), 3);
$sumBal = fn() => round(party_balance($keep) + party_balance($d1) + party_balance($d2), 2);
$books = fn() => ledger_balances($T);
$before = [$sumBal(), $books()];
t_eq('together they stand at ₹100 + ₹500 - ₹200 - ₹40', $before[0], 360.0);
$saleDate = val('SELECT sale_date FROM sales WHERE id = ?', [$sale['id']]);

$m1 = party_merge_apply($d1, $keep, 1);
$m2 = party_merge_apply($d2, $keep, 1);
t_eq('after the merge the kept party has the whole ₹360', round(party_balance($keep), 2), 360.0);
t_eq('the closed ones show nothing', [round(party_balance($d1), 2), round(party_balance($d2), 2)], [0.0, 0.0]);
t_eq('the sale bill now belongs to the kept party', (int)val('SELECT party_id FROM sales WHERE id = ?', [$sale['id']]), $keep);
t_eq('...with its own date untouched', val('SELECT sale_date FROM sales WHERE id = ?', [$sale['id']]), $saleDate);
t_eq('the payment and the follow-up moved too', [(int)val('SELECT COUNT(*) FROM payments WHERE party_id = ?', [$keep]), (int)val('SELECT COUNT(*) FROM follow_ups WHERE party_id = ?', [$keep])], [1, 1]);
t_eq('the opening balances were added together', (float)val('SELECT opening_balance FROM parties WHERE id = ?', [$keep]), 60.0);
t_eq('a GSTIN only the duplicate had is kept', val('SELECT gstin FROM parties WHERE id = ?', [$keep]), '24ABCDE1234F1Z5');
t_eq('the duplicates are closed, not deleted', array_map('intval', array_column(all('SELECT is_active FROM parties WHERE id IN (?, ?) ORDER BY id', [$d1, $d2]), 'is_active')), [0, 0]);
$after = $books(); $diff = [];
foreach (array_unique(array_merge(array_keys($after), array_keys($before[1]))) as $c) { $x = round(($after[$c] ?? 0) - ($before[1][$c] ?? 0), 2); if (abs($x) > 0.004) $diff[$c] = $x; }
// one customer now: the advance one duplicate held (₹240, "you'll give") is set
// against what the others owed ("you'll get") - net money is the same
t_eq('only receivable and payable move, by the same amount (advance set against dues)', [array_keys($diff), round(array_sum($diff), 2)], [[1200, 2000], 0.0]);
t_eq('...and the amount is exactly the advance that was sitting on the duplicate', $diff[2000] ?? 0, 240.0);
t_ok('moving into a closed party is refused', (function () use ($keep, $d1) { try { party_merge_apply($keep, $d1, 1); return false; } catch (RuntimeException $e) { return true; } })());

party_merge_undo($m2);
party_merge_undo($m1);
t_eq('Undo: the sale is back with the duplicate', (int)val('SELECT party_id FROM sales WHERE id = ?', [$sale['id']]), $d1);
t_eq('Undo: each party has its own balance again', [round(party_balance($keep), 2), round(party_balance($d1), 2), round(party_balance($d2), 2)], [100.0, 500.0, -240.0]);
t_eq('Undo: opening balances back', array_map('floatval', array_column(all('SELECT opening_balance FROM parties WHERE id IN (?, ?, ?) ORDER BY id', [$keep, $d1, $d2]), 'opening_balance')), [100.0, 0.0, -40.0]);
t_eq('Undo: the GSTIN the kept party never had is gone again', (string)val('SELECT gstin FROM parties WHERE id = ?', [$keep]), '');
t_eq('Undo: the duplicates are open again', array_map('intval', array_column(all('SELECT is_active FROM parties WHERE id IN (?, ?) ORDER BY id', [$d1, $d2]), 'is_active')), [1, 1]);
t_ok('Undo cannot run twice', (function () use ($m1) { try { party_merge_undo($m1); return false; } catch (RuntimeException $e) { return true; } })());
