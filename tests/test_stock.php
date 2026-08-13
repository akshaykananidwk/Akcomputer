<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); die('CLI only'); }
// adjust_stock() and the stock-audit post / un-complete cycle.
// Rule being protected: stock and stock_ledger can never disagree, and a
// posted audit must be perfectly reversible however many times it is
// posted and re-opened.

t_group('adjust_stock() keeps stock and ledger in step');
$loc = (int)val('SELECT id FROM locations ORDER BY id LIMIT 1');
$it = t_item(10, $loc);
t_eq('starts at 10', stock_qty($it, $loc), 10);

adjust_stock($it, $loc, -2, 'sale', 999, 'test sale');
t_eq('selling 2 leaves 8', stock_qty($it, $loc), 8);
t_eq('ledger recorded -2', (float)val('SELECT change_qty FROM stock_ledger WHERE item_id = ? ORDER BY id DESC LIMIT 1', [$it]), -2);

adjust_stock($it, $loc, 5, 'purchase', 998, 'test purchase');
t_eq('buying 5 makes 13', stock_qty($it, $loc), 13);
$ledgerSum = (float)val('SELECT COALESCE(SUM(change_qty),0) FROM stock_ledger WHERE item_id = ?', [$it]);
t_eq('ledger sum equals the movement since creation', $ledgerSum, 3); // -2 +5

t_group('stock is per location, never shared');
$loc2 = (int)val('SELECT id FROM locations WHERE id <> ? ORDER BY id LIMIT 1', [$loc]);
if ($loc2) {
    adjust_stock($it, $loc2, 7, 'adjust', null, 'godown');
    t_eq('other location holds its own 7', stock_qty($it, $loc2), 7);
    t_eq('first location is unchanged', stock_qty($it, $loc), 13);
} else {
    t_ok('other location holds its own stock', true, 'skipped - only one location configured');
    t_ok('first location is unchanged', true, 'skipped');
}

t_group('stock audit: post then un-complete restores exactly');
$loc = (int)val('SELECT id FROM locations ORDER BY id LIMIT 1');
$ai = t_item(20, $loc);
q("INSERT INTO stock_counts (location_id, notes, created_by) VALUES (?, 'test audit', 1)", [$loc]);
$cid = insert_id();
q('UPDATE stock_counts SET count_no = ? WHERE id = ?', [doc_no('AUD', $cid), $cid]);
q('INSERT INTO stock_count_items (count_id, item_id, system_qty, counted_qty) VALUES (?,?,?,?)', [$cid, $ai, 20, 18]);

// post the variance exactly as stock_audit.php does
$line = row('SELECT * FROM stock_count_items WHERE count_id = ?', [$cid]);
$variance = (float)$line['counted_qty'] - (float)$line['system_qty'];
adjust_stock($ai, $loc, $variance, 'cycle_count', $cid, 'test audit');
q("UPDATE stock_counts SET status = 'completed' WHERE id = ?", [$cid]);
t_eq('posting a -2 variance moves stock to 18', stock_qty($ai, $loc), 18);

// un-complete: reverse the NET effect (the rule that survives repeat cycles)
$net = all("SELECT item_id, location_id, SUM(change_qty) q FROM stock_ledger
            WHERE ref_type IN ('cycle_count','cycle_count_undo') AND ref_id = ?
            GROUP BY item_id, location_id HAVING ABS(SUM(change_qty)) > 0.0001", [$cid]);
foreach ($net as $n) adjust_stock($n['item_id'], $n['location_id'], -(float)$n['q'], 'cycle_count_undo', $cid, 'test undo');
q("UPDATE stock_counts SET status = 'open' WHERE id = ?", [$cid]);
t_eq('un-completing puts stock back to 20', stock_qty($ai, $loc), 20);
t_eq('counted quantity is preserved for re-posting', (float)val('SELECT counted_qty FROM stock_count_items WHERE count_id = ?', [$cid]), 18);

// post → undo → post → undo must not drift
for ($i = 0; $i < 2; $i++) {
    adjust_stock($ai, $loc, -2, 'cycle_count', $cid, 'repeat post');
    $net = all("SELECT item_id, location_id, SUM(change_qty) q FROM stock_ledger
                WHERE ref_type IN ('cycle_count','cycle_count_undo') AND ref_id = ?
                GROUP BY item_id, location_id HAVING ABS(SUM(change_qty)) > 0.0001", [$cid]);
    foreach ($net as $n) adjust_stock($n['item_id'], $n['location_id'], -(float)$n['q'], 'cycle_count_undo', $cid, 'repeat undo');
}
t_eq('two more post/undo cycles leave stock at 20', stock_qty($ai, $loc), 20);

t_group('service items never carry stock');
q("INSERT INTO items (name, selling_price, is_active, item_type) VALUES ('TESTSERVICE', 500, 1, 'service')");
$sv = insert_id();
t_eq('a service item has no stock row', stock_qty($sv, $loc), 0);
