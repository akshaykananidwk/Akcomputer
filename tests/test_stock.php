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
// the invariant the Data Health Check relies on: stock is always exactly
// the sum of its own ledger, with nothing unexplained
$ledgerSum = (float)val('SELECT COALESCE(SUM(change_qty),0) FROM stock_ledger WHERE item_id = ? AND location_id = ?', [$it, $loc]);
t_eq('stock equals the sum of its ledger', $ledgerSum, 13); // 10 opening -2 +5

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

// ---------------------------------------------------------------------------
// Reported from the shop on 2026-08-17: a staff member with a pending handover
// could not find anywhere to type the OTP, and the goods showed in neither the
// shop nor the godown.
// ---------------------------------------------------------------------------

t_group('a handover is acceptable by exactly the right person');
$hoLoc = (int)val('SELECT id FROM locations ORDER BY id LIMIT 1');
$hoRole = null;
q("INSERT INTO roles (name, permissions, is_system) VALUES (?, ?, 0)",
  ['HOROLE_' . bin2hex(random_bytes(3)), json_encode(['handover.view', 'handover.add'])]);
$hoRole = insert_id();
q("INSERT INTO users (name, username, mobile, password, role_id, location_id, is_active)
   VALUES ('HO Staff', ?, '7900000009', 'x', ?, ?, 1)", ['hostaff_' . bin2hex(random_bytes(3)), $hoRole, $hoLoc]);
$hoStaff = insert_id();
q("INSERT INTO users (name, username, mobile, password, role_id, location_id, is_active)
   VALUES ('HO Other', ?, '7900000010', 'x', ?, ?, 1)", ['hoother_' . bin2hex(random_bytes(3)), $hoRole, $hoLoc]);
$hoOther = insert_id();

$issue = ['id' => 1, 'type' => 'issue', 'status' => 'pending', 'staff_id' => $hoStaff];
t_ok('the staff member an issue is FOR can accept it',
     handover_can_accept($issue, ['id' => $hoStaff]));
// This is the bug: the screen only ever drew the OTP box for handover.accept
// holders, so this person - the only one allowed to accept - had nowhere to
// type it.
t_ok('another member of staff cannot', !handover_can_accept($issue, ['id' => $hoOther]));
t_ok('an accepted handover cannot be accepted again',
     !handover_can_accept(['type' => 'issue', 'status' => 'accepted', 'staff_id' => $hoStaff], ['id' => $hoStaff]));
t_ok('nor a cancelled one',
     !handover_can_accept(['type' => 'issue', 'status' => 'cancelled', 'staff_id' => $hoStaff], ['id' => $hoStaff]));
t_ok('a return is accepted at the shop, not by the staff member',
     handover_can_accept(['type' => 'return', 'status' => 'pending', 'staff_id' => $hoStaff], ['id' => $hoStaff])
     === can('handover.accept'));

$page = file_get_contents(dirname(__DIR__) . '/handover.php');
t_ok('the screen and the POST handler now share one rule',
     substr_count($page, 'handover_can_accept(') >= 2);
t_ok('the OTP box is drawn from that rule, not from a permission',
     strpos($page, '$canAcceptThis = handover_can_accept') !== false);
t_ok('My Stock still offers its own OTP box',
     strpos(file_get_contents(dirname(__DIR__) . '/my_stock.php'), 'name="otp"') !== false);

t_group('stock on a pending handover is visible somewhere');
$tItem = t_item(10, $hoLoc);
q("INSERT INTO handovers (handover_no, type, location_id, staff_id, status, otp, created_by)
   VALUES (?, 'issue', ?, ?, 'pending', '111111', 1)", ['HO-T' . bin2hex(random_bytes(3)), $hoLoc, $hoStaff]);
$hoId = insert_id();
q('INSERT INTO handover_items (handover_id, item_id, qty) VALUES (?,?,3)', [$hoId, $tItem]);
adjust_stock($tItem, $hoLoc, -3, 'handover_out', $hoId);   // what creating a handover really does

t_eq('the source location is down by the handed-over quantity', stock_qty($tItem, $hoLoc), 7);
$transit = stock_in_transit($tItem);
t_ok('...and the goods turn up in transit', count($transit) === 1);
t_eq('...with the right quantity', (float)$transit[0]['qty'], 3.0);
t_ok('...naming the handover they are on', $transit[0]['handover_no'] !== '');
t_ok('...and who they are going to', transit_destination($transit[0]) === 'HO Staff');

q("UPDATE handovers SET status = 'accepted' WHERE id = ?", [$hoId]);
t_ok('once accepted they are no longer in transit', stock_in_transit($tItem) === []);
q("UPDATE handovers SET status = 'pending' WHERE id = ?", [$hoId]);

$all = stock_in_transit();
$found = false;
foreach ($all as $t) if ((int)$t['item_id'] === $tItem) $found = true;
t_ok('the whole-shop view finds it too', $found);

$stockPage = file_get_contents(dirname(__DIR__) . '/stock.php');
t_ok('Stock Levels has a transit column', strpos($stockPage, 'રસ્તામાં') !== false);
t_ok('...and it is added into the row total', strpos($stockPage, "\$rowTotal += \$tr") !== false);
$itemPage = file_get_contents(dirname(__DIR__) . '/item_view.php');
t_ok('the item page shows what is in transit', strpos($itemPage, 'stock_in_transit(') !== false);
t_ok('...and explains that it counts nowhere meanwhile',
     strpos($itemPage, 'કોઈ પણ જગ્યાના સ્ટોકમાં ગણાતો નથી') !== false);
