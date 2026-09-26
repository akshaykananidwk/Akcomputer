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
// This suite runs with no session user, and half of this rule is a permission
// check that only answers for the logged-in one - so it has to be set here or
// the assertions below quietly test nothing.
$_SESSION['user_id'] = (int)val("SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id
                                 WHERE r.permissions LIKE '%*%' AND u.is_active = 1 ORDER BY u.id LIMIT 1");
t_ok('(the suite is running as an admin for this group)', (bool)current_user() && can('handover.accept'));
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
// The screen used to draw the OTP box only for handover.accept holders, so
// this person - the one the goods were issued to - had nowhere to type the
// code they had just been sent.
t_ok('another member of staff cannot', !handover_can_accept($issue, ['id' => $hoOther]));

// The owner asked twice for this: whoever holds handover.accept may also type
// the OTP in, because the staff member could not open their own screen and the
// stock sat counting nowhere. It is not a bypass - the code still only ever
// went to the staff member's phone, so it has to be read out.
t_ok('the logged-in admin may accept on the staff member\'s behalf',
     handover_can_accept($issue));
t_ok('...and that is flagged as being on someone else\'s behalf',
     handover_accepting_for_other($issue));
t_ok('the staff member accepting for themselves is not flagged',
     !handover_accepting_for_other($issue, ['id' => $hoStaff]));
// can() only ever answers for the session user, so asking about someone else
// must fall back to identity alone rather than lending them a permission.
t_ok('a permission is never granted to a user we did not verify',
     !handover_can_accept($issue, ['id' => $hoOther]));
t_ok('...even for a return, where the permission is the whole rule',
     !handover_can_accept(['type' => 'return', 'status' => 'pending', 'staff_id' => $hoStaff], ['id' => $hoOther]));
t_ok('an accepted handover cannot be accepted again',
     !handover_can_accept(['type' => 'issue', 'status' => 'accepted', 'staff_id' => $hoStaff], ['id' => $hoStaff]));
t_ok('nor a cancelled one',
     !handover_can_accept(['type' => 'issue', 'status' => 'cancelled', 'staff_id' => $hoStaff], ['id' => $hoStaff]));
// A return comes back INTO the shop, so being the staff member grants nothing
// there - the permission is the whole rule.
$ret = ['type' => 'return', 'status' => 'pending', 'staff_id' => $hoStaff];
t_ok('a return is accepted at the shop, by the permission', handover_can_accept($ret));
t_ok('...and being the staff member does not by itself allow it',
     !handover_can_accept($ret, ['id' => $hoStaff]));

$page = file_get_contents(dirname(__DIR__) . '/handover.php');
t_ok('the screen and the POST handler now share one rule',
     substr_count($page, 'handover_can_accept(') >= 2);
t_ok('the OTP box is drawn from that rule, not from a permission',
     strpos($page, '$canAcceptThis = handover_can_accept') !== false);
$hv = file_get_contents(dirname(__DIR__) . '/handover.php');
t_ok('accepting for someone else is written into the activity log',
     strpos($hv, 'accepted on behalf of staff #') !== false);
t_ok('...and the screen says so before you press the button',
     strpos($hv, 'નોંધમાં લખાશે કે <b>તમે</b> સ્વીકાર્યું છે') !== false);
t_ok('...and tells you whose phone the OTP went to',
     strpos($hv, 'ના WhatsApp પર ગયો છે') !== false);
t_ok('the OTP is still required by the POST handler',
     strpos($hv, "hash_equals(\$h['otp'], trim(post('otp')))") !== false);
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

// ---------------------------------------------------------------------------
// The public website used to tell customers "In stock" on every product,
// whatever the shelf actually held. Reported by the owner, who also asked for
// the real quantity to be shown.
// ---------------------------------------------------------------------------

t_group('the website tells the truth about stock');
$wLoc = (int)val('SELECT id FROM locations ORDER BY id LIMIT 1');
$wIn = t_item(12, $wLoc);
q("UPDATE items SET unit = 'pcs' WHERE id = ?", [$wIn]);
$wOut = t_item(0, $wLoc);
q("INSERT INTO items (name, selling_price, is_active, item_type) VALUES ('WEBSERVICE', 500, 1, 'service')");
$wSvc = insert_id();

$map = web_stock_map();
t_eq('the stock sweep finds the real quantity', $map[$wIn] ?? 0, 12);
t_ok('an item with no stock row is simply absent', !isset($map[$wOut]) || $map[$wOut] == 0);

$in = web_stock_line(['id' => $wIn, 'item_type' => 'product', 'unit' => 'pcs'], $map, true);
t_ok('an item in stock is marked in stock', $in[0] === 'in');
t_ok('...and says how many, in its own unit', strpos($in[1], '12 pcs') !== false, $in[1]);

$noUnit = web_stock_line(['id' => $wIn, 'item_type' => 'product', 'unit' => ''], $map, true);
t_ok('an item with no unit still reads sensibly', strpos($noUnit[1], 'નંગ') !== false, $noUnit[1]);

$hidden = web_stock_line(['id' => $wIn, 'item_type' => 'product', 'unit' => 'pcs'], $map, false);
t_ok('with quantities switched off it just says in stock', $hidden[0] === 'in' && strpos($hidden[1], '12') === false);

$out = web_stock_line(['id' => $wOut, 'item_type' => 'product', 'unit' => 'pcs'], $map, true);
t_ok('an item with nothing on the shelf is NOT called in stock', $out[0] === 'out');
t_ok('...it says so plainly', strpos($out[1], 'ખલાસ') !== false);
t_ok('...and offers to order it in', $out[2] !== '');
// This is the honesty half: hiding quantities is a display preference, but
// claiming stock that does not exist is a lie to a customer either way.
$outHidden = web_stock_line(['id' => $wOut, 'item_type' => 'product'], $map, false);
t_ok('switching quantities off never turns "out" into "in stock"', $outHidden[0] === 'out');

$svc = web_stock_line(['id' => $wSvc, 'item_type' => 'service'], $map, true);
t_ok('a service is neither in nor out of stock', $svc[0] === 'svc');
t_ok('...and never claims a quantity', strpos($svc[1], 'સ્ટોકમાં') === false);

$unknown = web_stock_line(['id' => 99999999, 'item_type' => 'product'], $map, true);
t_ok('an item the map has never heard of is out, not in', $unknown[0] === 'out');

t_group('both public pages use the one rule');
$cat = file_get_contents(dirname(__DIR__) . '/catalog.php');
$prd = file_get_contents(dirname(__DIR__) . '/product.php');
t_ok('the catalogue asks web_stock_line()', strpos($cat, 'web_stock_line(') !== false);
t_ok('the product page asks it too', strpos($prd, 'web_stock_line(') !== false);
t_ok('neither still hard-codes "In stock" on every card', strpos($cat, ">✔ In stock<") === false);
t_ok('the catalogue fetches stock in one sweep, not per card',
     strpos($cat, 'web_stock_map()') !== false);
t_ok('...and passes it INTO the card function rather than relying on a global',
     strpos($cat, 'array $stockMap = []') !== false);
t_ok('the switch is one setting, read in one place',
     substr_count($cat, 'web_show_qty()') + substr_count($prd, 'web_show_qty()') === 2);
$set = file_get_contents(dirname(__DIR__) . '/settings.php');
t_ok('the owner can turn quantities off in Settings', strpos($set, "name=\"store_show_qty\"") !== false);
t_ok('...and is warned it is a public page', strpos($set, 'હરીફ પણ જોઈ શકે') !== false);

// ------------------------------------------------- putting a serial back in --
// uk_serial is UNIQUE on (item_id, serial_no) with NO status in it. The repair
// screen used to look only for a row with status='in_stock' and then INSERT,
// so any serial already on record in another state — sold, adjusted out, out
// with staff — hit that key and killed the whole page with a duplicate-entry
// error. Every case below would have crashed before.
t_group('a serial can be put back in stock from any state');
$sItem = t_item(0, $loc);
$sLoc  = $loc;
function ts_serial($itemId, $sn, $status, $saleId = null, $userId = null) {
    q("INSERT INTO item_serials (item_id, serial_no, status, sale_id, user_id, warranty_months, created_at)
       VALUES (?,?,?,?,?,0,NOW())", [$itemId, $sn, $status, $saleId, $userId]);
    return insert_id();
}
function ts_serial_row($itemId, $sn) {
    return row('SELECT status, location_id, sale_id, user_id FROM item_serials WHERE item_id = ? AND serial_no = ?', [$itemId, $sn]);
}

$r = serial_put_in_stock($sItem, 'TSS-BRANDNEW', $sLoc);
t_eq('a serial nobody has seen is added', $r['result'], 'added');
t_eq('...in stock', ts_serial_row($sItem, 'TSS-BRANDNEW')['status'], 'in_stock');
t_eq('...at the location asked for', (int)ts_serial_row($sItem, 'TSS-BRANDNEW')['location_id'], $sLoc);

t_eq('adding it a second time changes nothing', serial_put_in_stock($sItem, 'TSS-BRANDNEW', $sLoc)['result'], 'already');
t_eq('...and never makes a second row',
     (int)val('SELECT COUNT(*) FROM item_serials WHERE item_id = ? AND serial_no = ?', [$sItem, 'TSS-BRANDNEW']), 1);

// the exact shape of the reported crash: a SOLD serial typed into the repair box
ts_serial($sItem, 'TSS-SOLD', 'sold', 4242, null);
$r = serial_put_in_stock($sItem, 'TSS-SOLD', $sLoc);
t_eq('a sold serial is restored, not inserted', $r['result'], 'restored');
t_eq('...and reports where it came from', $r['from'], 'sold');
t_eq('...leaving exactly one row',
     (int)val('SELECT COUNT(*) FROM item_serials WHERE item_id = ? AND serial_no = ?', [$sItem, 'TSS-SOLD']), 1);
$row = ts_serial_row($sItem, 'TSS-SOLD');
t_eq('...now in stock', $row['status'], 'in_stock');
t_eq('...detached from the bill it was sold on', $row['sale_id'], null);

ts_serial($sItem, 'TSS-STAFF', 'with_staff', null, 1);
$r = serial_put_in_stock($sItem, 'TSS-STAFF', $sLoc);
t_eq('a serial out with staff comes back', $r['from'], 'with_staff');
t_eq('...and is no longer against that staff member', ts_serial_row($sItem, 'TSS-STAFF')['user_id'], null);

foreach (['adjusted_out', 'returned_supplier', 'replaced', 'claim'] as $st) {
    ts_serial($sItem, 'TSS-' . $st, $st);
    t_eq('a ' . $st . ' serial is restored', serial_put_in_stock($sItem, 'TSS-' . $st, $sLoc)['result'], 'restored');
    t_eq('...and ends up in stock', ts_serial_row($sItem, 'TSS-' . $st)['status'], 'in_stock');
}

t_eq('a blank serial is ignored, not inserted', serial_put_in_stock($sItem, '   ', $sLoc)['result'], 'skipped');
t_eq('...and no item means no row either', serial_put_in_stock(0, 'TSS-NOITEM', $sLoc)['result'], 'skipped');
t_eq('nothing blank was written', (int)val("SELECT COUNT(*) FROM item_serials WHERE serial_no IN ('', '   ', 'TSS-NOITEM')"), 0);

// the same serial on a DIFFERENT item is a different piece and must be allowed
$sItem2 = t_item(0, $loc);
t_eq('the same number on another item is its own serial',
     serial_put_in_stock($sItem2, 'TSS-SOLD', $sLoc)['result'], 'added');
t_eq('...so both exist', (int)val("SELECT COUNT(*) FROM item_serials WHERE serial_no = 'TSS-SOLD'"), 2);

t_group('the repair screen goes through that one rule');
$sf = file_get_contents(dirname(__DIR__) . '/serial_fix.php');
t_ok('it calls the shared helper', strpos($sf, 'serial_put_in_stock($iid, $sn, $locId)') !== false);
t_ok('it no longer checks only for in_stock before inserting',
     strpos($sf, "AND status = 'in_stock'\", [\$iid, \$sn]") === false);
t_ok('it never inserts a serial by hand any more', strpos($sf, 'INSERT INTO item_serials') === false);
t_ok('it warns when a serial was taken back from a customer or staff member',
     strpos($sf, 'serial_live_statuses()') !== false && strpos($sf, 'બિલ સાથેની કડી કપાઈ ગઈ છે') !== false);

// Why the old code could not work, pinned to the schema rather than to the
// code that got it wrong: the unique key carries no status, so "is there one
// in stock?" can never answer "may I insert?".
$idx = all("SHOW INDEX FROM item_serials WHERE Key_name = 'uk_serial'");
$cols = array_column($idx, 'Column_name');
t_eq('uk_serial is (item_id, serial_no)', implode(',', $cols), 'item_id,serial_no');
t_ok('...with no status in it, so status can never gate an insert', !in_array('status', $cols, true));
// and the failure really does happen: same item, same number, other status
$dupItem = t_item(0, $loc);
ts_serial($dupItem, 'TSS-DUPE', 'sold');
$threw = '';
try { q("INSERT INTO item_serials (item_id, serial_no, status, warranty_months) VALUES (?,?,'in_stock',0)", [$dupItem, 'TSS-DUPE']); }
catch (PDOException $e) { $threw = (string)($e->errorInfo[1] ?? ''); }
t_eq('inserting over a sold serial is a duplicate-key error', $threw, '1062');
t_eq('...which the shared helper avoids entirely',
     serial_put_in_stock($dupItem, 'TSS-DUPE', $loc)['result'], 'restored');

// ------------------------------- a warranty replacement reaches BOTH books --
// The replacement serial used to be written into item_serials with no location
// and no stock movement at all: a unit physically arrived, the serial book said
// "in stock", and the quantity book never heard about it. That is the mismatch
// the shop kept having to clean up by hand on the Serial Repair screen.
t_group('a warranty replacement moves the stock, not just the serial book');

function tw_claim($itemId, $sn, $customer = '', $locId = null) {
    global $loc;
    q("INSERT INTO warranty_claims (claim_no, item_id, serial_no, customer_name, status, received_date, location_id, created_by)
       VALUES (?,?,?,?, 'received_back', CURDATE(), ?, 1)",
      ['TWC-' . bin2hex(random_bytes(3)), $itemId, $sn, $customer, $locId ?: $loc]);
    return row('SELECT * FROM warranty_claims WHERE id = ?', [insert_id()]);
}
function tw_ser($itemId, $sn) { return row('SELECT status, location_id, sale_id FROM item_serials WHERE item_id = ? AND serial_no = ?', [$itemId, $sn]); }

// the shop's own piece, still counted on the shelf: one good unit before,
// one good unit after, so the COUNT must not move - but the serials must
$wi = t_item(0, $loc);
q('INSERT INTO stock (item_id, location_id, qty) VALUES (?,?,10) ON DUPLICATE KEY UPDATE qty = 10', [$wi, $loc]);
ts_serial($wi, 'TW-OLD1', 'in_stock');
q("UPDATE item_serials SET location_id = ? WHERE item_id = ? AND serial_no = 'TW-OLD1'", [$loc, $wi]);
$msg = warranty_apply_replacement(tw_claim($wi, 'TW-OLD1'), 'TW-OLD1', 'TW-NEW1', $loc);
t_eq('the shelf count is unchanged', stock_qty($wi, $loc), 10.0);
t_eq('the faulty serial is marked replaced', tw_ser($wi, 'TW-OLD1')['status'], 'replaced');
t_eq('the replacement is in stock', tw_ser($wi, 'TW-NEW1')['status'], 'in_stock');
t_eq('...at a real location, not nowhere', (int)tw_ser($wi, 'TW-NEW1')['location_id'], $loc);
t_ok('...and the screen says so', strpos($msg, 'TW-NEW1') !== false);

// the faulty one had already been taken off the shelf by hand, so the
// replacement is a unit the shop genuinely did not have: +1
$wi2 = t_item(0, $loc);
q('INSERT INTO stock (item_id, location_id, qty) VALUES (?,?,10) ON DUPLICATE KEY UPDATE qty = 10', [$wi2, $loc]);
ts_serial($wi2, 'TW-OLD2', 'adjusted_out');
warranty_apply_replacement(tw_claim($wi2, 'TW-OLD2'), 'TW-OLD2', 'TW-NEW2', $loc);
t_eq('the replacement is added to the count', stock_qty($wi2, $loc), 11.0);
t_eq('...and is in stock', tw_ser($wi2, 'TW-NEW2')['status'], 'in_stock');
// the movement is traceable, not a silent edit of the number
t_ok('a stock-ledger line records it',
     (int)val("SELECT COUNT(*) FROM stock_ledger WHERE item_id = ? AND ref_type = 'warranty_replace' AND change_qty > 0", [$wi2]) >= 1);

// a CUSTOMER's piece: the replacement belongs to them and must NOT become
// sellable stock, or it can be sold to somebody else while they wait for it
$wi3 = t_item(0, $loc);
q('INSERT INTO stock (item_id, location_id, qty) VALUES (?,?,10) ON DUPLICATE KEY UPDATE qty = 10', [$wi3, $loc]);
ts_serial($wi3, 'TW-OLD3', 'sold', 4242);
$msg3 = warranty_apply_replacement(tw_claim($wi3, 'TW-OLD3', 'Ramesh'), 'TW-OLD3', 'TW-NEW3', $loc);
t_eq('the count does not move for a customer replacement', stock_qty($wi3, $loc), 10.0);
t_eq('the replacement is recorded as sold', tw_ser($wi3, 'TW-NEW3')['status'], 'sold');
t_eq('...against the same bill, so the warranty history stays joined', (int)tw_ser($wi3, 'TW-NEW3')['sale_id'], 4242);
t_ok('...and the screen explains why it is not in stock', strpos($msg3, 'સ્ટોકમાં ઉમેર્યો નથી') !== false);

// a claim that names a customer is theirs even when the old serial was never
// registered - there is no serial row to read a status from
$wi4 = t_item(0, $loc);
q('INSERT INTO stock (item_id, location_id, qty) VALUES (?,?,10) ON DUPLICATE KEY UPDATE qty = 10', [$wi4, $loc]);
warranty_apply_replacement(tw_claim($wi4, 'TW-GONE', 'Suresh'), 'TW-GONE', 'TW-NEW4', $loc);
t_eq('an unregistered customer unit still does not touch stock', stock_qty($wi4, $loc), 10.0);
t_eq('...and the replacement is theirs', tw_ser($wi4, 'TW-NEW4')['status'], 'sold');

t_group('saving the same claim twice does not add the unit twice');
$wi5 = t_item(0, $loc);
q('INSERT INTO stock (item_id, location_id, qty) VALUES (?,?,10) ON DUPLICATE KEY UPDATE qty = 10', [$wi5, $loc]);
ts_serial($wi5, 'TW-OLD5', 'adjusted_out');
$c5 = tw_claim($wi5, 'TW-OLD5');
warranty_apply_replacement($c5, 'TW-OLD5', 'TW-NEW5', $loc);
$after = stock_qty($wi5, $loc);
t_eq('the first save adds it', $after, 11.0);
t_eq('the second save is a no-op', warranty_apply_replacement($c5, 'TW-OLD5', 'TW-NEW5', $loc), '');
t_eq('...and the count stays put', stock_qty($wi5, $loc), 11.0);
t_eq('...with one serial row, not two',
     (int)val('SELECT COUNT(*) FROM item_serials WHERE item_id = ? AND serial_no = ?', [$wi5, 'TW-NEW5']), 1);
t_eq('no replacement serial at all does nothing', warranty_apply_replacement($c5, 'TW-OLD5', '', $loc), '');
t_eq('the same serial back again does nothing', warranty_apply_replacement($c5, 'TW-X', 'TW-X', $loc), '');

t_group('a stock adjustment can carry serial numbers for any item');
$st = file_get_contents(dirname(__DIR__) . '/stock.php');
t_ok('the serial box is no longer hidden for untracked items',
     strpos($st, 'id="adjSnBox" style="flex-basis:100%"') !== false);
t_ok('the label says whether it is required or optional',
     strpos($st, 'required for this item') !== false && strpos($st, 'optional') !== false);
t_ok('a flagged item still cannot be adjusted without them',
     strpos($st, "\$item['serial_tracked'] && !\$sns") !== false);
t_ok('a mismatched count is refused for ANY item',
     strpos($st, '$sns && count($sns) != abs($delta)') !== false);
t_ok('putting a serial back goes through the one shared rule',
     strpos($st, 'serial_put_in_stock($item_id, $sn, $loc_id') !== false);
$wa = file_get_contents(dirname(__DIR__) . '/warranty.php');
t_ok('the warranty screen goes through the one replacement rule',
     strpos($wa, 'warranty_apply_replacement($claimRow') !== false);
t_ok('...and no longer writes the serial by hand', strpos($wa, 'INSERT INTO item_serials') === false);

// ----------------------------------------- the warranty chain, by serial no --
// A piece goes to the company and a DIFFERENT one comes back. Three things
// were being lost at that moment: the link between the two serials, the fact
// that the piece was not on the shelf while it was away, and the date the
// warranty actually started.
t_group('a replaced serial stays joined to the one it replaced');

function tw_send($claimId) { return warranty_send_out(row('SELECT * FROM warranty_claims WHERE id = ?', [$claimId])); }
function tw_back($claimId) { return warranty_take_back(row('SELECT * FROM warranty_claims WHERE id = ?', [$claimId])); }
function tw_replace($claimId, $old, $new, $locId) {
    return warranty_apply_replacement(row('SELECT * FROM warranty_claims WHERE id = ?', [$claimId]), $old, $new, $locId);
}

$ci = t_item(0, $loc);
q('INSERT INTO stock (item_id, location_id, qty) VALUES (?,?,10) ON DUPLICATE KEY UPDATE qty = 10', [$ci, $loc]);
ts_serial($ci, 'TC-1', 'in_stock');
q("UPDATE item_serials SET location_id = ?, warranty_months = 24, warranty_expiry = '2027-06-30' WHERE item_id = ? AND serial_no = 'TC-1'", [$loc, $ci]);
$cl1 = tw_claim($ci, 'TC-1');
q("UPDATE warranty_claims SET status = 'sent', sent_date = '2026-09-02' WHERE id = ?", [$cl1['id']]);

// SENT: a piece sitting at the company is not sellable here
t_ok('sending it says so', tw_send($cl1['id']) !== '');
t_eq('the shelf count drops', stock_qty($ci, $loc), 9.0);
t_eq("...and the serial reads 'claim', not 'in stock'", tw_ser($ci, 'TC-1')['status'], 'claim');
t_eq('the claim remembers it took one off', (int)val('SELECT stock_out FROM warranty_claims WHERE id = ?', [$cl1['id']]), 1);
t_eq('sending twice does not take two', tw_send($cl1['id']), '');
t_eq('...and the count stays put', stock_qty($ci, $loc), 9.0);

// BACK, REPLACED: the replacement lands and the two serials are joined
q("UPDATE warranty_claims SET status = 'received_back', back_date = '2026-09-15', replacement_serial = 'TC-2', warranty_mode = 'continue' WHERE id = ?", [$cl1['id']]);
tw_replace($cl1['id'], 'TC-1', 'TC-2', $loc);
t_eq('the replacement is back on the shelf', stock_qty($ci, $loc), 10.0);
t_eq('...and in stock', tw_ser($ci, 'TC-2')['status'], 'in_stock');
t_eq('the old one is marked replaced', tw_ser($ci, 'TC-1')['status'], 'replaced');
t_eq('the old one points at the new one',
     (int)val('SELECT replaced_by_id FROM item_serials WHERE item_id = ? AND serial_no = ?', [$ci, 'TC-1']),
     (int)val('SELECT id FROM item_serials WHERE item_id = ? AND serial_no = ?', [$ci, 'TC-2']));
// THE POINT: the warranty runs from the first purchase, not from the swap
t_eq('the replacement carries the ORIGINAL expiry',
     val('SELECT warranty_expiry FROM item_serials WHERE item_id = ? AND serial_no = ?', [$ci, 'TC-2']), '2027-06-30');

// replaced a SECOND time - the trail must not break
$cl2 = tw_claim($ci, 'TC-2');
q("UPDATE warranty_claims SET status = 'sent', sent_date = '2026-10-02' WHERE id = ?", [$cl2['id']]);
tw_send($cl2['id']);
t_eq('the second one comes off the shelf too', stock_qty($ci, $loc), 9.0);
q("UPDATE warranty_claims SET status = 'received_back', back_date = '2026-10-20', replacement_serial = 'TC-3' WHERE id = ?", [$cl2['id']]);
tw_replace($cl2['id'], 'TC-2', 'TC-3', $loc);
t_eq('and the third one lands', stock_qty($ci, $loc), 10.0);

$chain = serial_chain('TC-3');
t_eq('the chain is three links long', count($chain), 3);
t_eq('...starting at the serial first bought', $chain[0]['serial_no'], 'TC-1');
t_eq('...and ending at the one in hand', $chain[2]['serial_no'], 'TC-3');
$origin = serial_warranty_origin('TC-3');
t_eq('looking up the NEWEST serial still finds the first', $origin['root_serial'], 'TC-1');
t_eq('...and the warranty date that came with it', $origin['expiry'], '2027-06-30');
// walking from the middle, or from the oldest, gives the same chain
t_eq('the chain reads the same from any link', array_column(serial_chain('TC-1'), 'serial_no'), ['TC-1', 'TC-2', 'TC-3']);
t_eq('...including from the middle', array_column(serial_chain('TC-2'), 'serial_no'), ['TC-1', 'TC-2', 'TC-3']);

t_group('a customer\'s piece never touches the shop\'s stock');
$cu = t_item(0, $loc);
q('INSERT INTO stock (item_id, location_id, qty) VALUES (?,?,10) ON DUPLICATE KEY UPDATE qty = 10', [$cu, $loc]);
ts_serial($cu, 'TCU-1', 'sold', 5151);
$cc = tw_claim($cu, 'TCU-1', 'Ramesh');
q("UPDATE warranty_claims SET status = 'sent' WHERE id = ?", [$cc['id']]);
t_eq('sending a sold piece takes nothing off the shelf', tw_send($cc['id']), '');
t_eq('...the count is untouched', stock_qty($cu, $loc), 10.0);
q("UPDATE warranty_claims SET status = 'received_back', back_date = '2026-09-20', replacement_serial = 'TCU-2' WHERE id = ?", [$cc['id']]);
tw_replace($cc['id'], 'TCU-1', 'TCU-2', $loc);
t_eq('the replacement does not become sellable stock', stock_qty($cu, $loc), 10.0);
t_eq('...it belongs to the customer', tw_ser($cu, 'TCU-2')['status'], 'sold');
t_eq('...on the same bill', (int)tw_ser($cu, 'TCU-2')['sale_id'], 5151);

// the trap this walked into once: warranty_send_out() puts the SHOP's own
// stock into 'claim', and reading that status as "sold" handed the shop's own
// replacement to a customer who did not exist
t_group("'out at the company' does not mean 'sold'");
$cx = t_item(0, $loc);
q('INSERT INTO stock (item_id, location_id, qty) VALUES (?,?,10) ON DUPLICATE KEY UPDATE qty = 10', [$cx, $loc]);
ts_serial($cx, 'TCX-1', 'in_stock');
q("UPDATE item_serials SET location_id = ? WHERE item_id = ? AND serial_no = 'TCX-1'", [$loc, $cx]);
$cxc = tw_claim($cx, 'TCX-1');
q("UPDATE warranty_claims SET status = 'sent' WHERE id = ?", [$cxc['id']]);
tw_send($cxc['id']);
t_eq("the serial is 'claim' while it is away", tw_ser($cx, 'TCX-1')['status'], 'claim');
q("UPDATE warranty_claims SET status = 'received_back', replacement_serial = 'TCX-2' WHERE id = ?", [$cxc['id']]);
tw_replace($cxc['id'], 'TCX-1', 'TCX-2', $loc);
t_eq('the replacement is still the SHOP\'s, so it is in stock', tw_ser($cx, 'TCX-2')['status'], 'in_stock');
t_eq('...and back in the count', stock_qty($cx, $loc), 10.0);

t_group('a piece repaired and returned goes back on the shelf');
$cp = t_item(0, $loc);
q('INSERT INTO stock (item_id, location_id, qty) VALUES (?,?,10) ON DUPLICATE KEY UPDATE qty = 10', [$cp, $loc]);
ts_serial($cp, 'TCP-1', 'in_stock');
q("UPDATE item_serials SET location_id = ? WHERE item_id = ? AND serial_no = 'TCP-1'", [$loc, $cp]);
$cpc = tw_claim($cp, 'TCP-1');
q("UPDATE warranty_claims SET status = 'sent' WHERE id = ?", [$cpc['id']]);
tw_send($cpc['id']);
t_eq('it is off the shelf while away', stock_qty($cp, $loc), 9.0);
q("UPDATE warranty_claims SET status = 'received_back', back_date = '2026-09-25' WHERE id = ?", [$cpc['id']]);
t_ok('bringing it back says so', tw_back($cpc['id']) !== '');
t_eq('...and it is countable again', stock_qty($cp, $loc), 10.0);
t_eq('...and in stock', tw_ser($cp, 'TCP-1')['status'], 'in_stock');
t_eq('saving the claim again adds nothing', tw_back($cpc['id']), '');
t_eq('...so the count holds', stock_qty($cp, $loc), 10.0);

t_group('the warranty date is recorded, never guessed');
$cf = t_item(0, $loc);
q('INSERT INTO stock (item_id, location_id, qty) VALUES (?,?,10) ON DUPLICATE KEY UPDATE qty = 10', [$cf, $loc]);
ts_serial($cf, 'TCF-1', 'in_stock');
q("UPDATE item_serials SET location_id = ?, warranty_months = 24, warranty_expiry = '2027-06-30' WHERE item_id = ? AND serial_no = 'TCF-1'", [$loc, $cf]);
$cfc = tw_claim($cf, 'TCF-1');
q("UPDATE warranty_claims SET status = 'received_back', back_date = '2026-10-20', replacement_serial = 'TCF-2',
   warranty_mode = 'fresh', fresh_months = 6 WHERE id = ?", [$cfc['id']]);
tw_replace($cfc['id'], 'TCF-1', 'TCF-2', $loc);
t_eq('a FRESH warranty runs from the day it came back',
     val('SELECT warranty_expiry FROM item_serials WHERE item_id = ? AND serial_no = ?', [$cf, 'TCF-2']), '2027-04-20');
t_ok('...which is not the original date',
     val('SELECT warranty_expiry FROM item_serials WHERE item_id = ? AND serial_no = ?', [$cf, 'TCF-2']) !== '2027-06-30');
// and the default really is "carry the original on"
t_eq('the default mode is to continue the original',
     warranty_replacement_dates(['warranty_mode' => null], ['warranty_months' => 24, 'warranty_expiry' => '2027-06-30'])[1],
     '2027-06-30');
t_eq('fresh with no months given sets no expiry rather than inventing one',
     warranty_replacement_dates(['warranty_mode' => 'fresh', 'fresh_months' => 0, 'back_date' => '2026-10-20'], null)[1], null);

t_group('the warranty screen and the serial lookup use these rules');
$wa2 = file_get_contents(dirname(__DIR__) . '/warranty.php');
t_ok('sending a claim takes the piece out of stock', strpos($wa2, 'warranty_send_out($claimRow)') !== false);
t_ok('a repaired return puts it back', strpos($wa2, 'warranty_take_back(') !== false);
t_ok('the screen asks which warranty rule applied', strpos($wa2, 'name="warranty_mode"') !== false);
t_ok('...and shows the whole chain', strpos($wa2, 'serial_chain($c[\'serial_no\'])') !== false);
$aj = file_get_contents(dirname(__DIR__) . '/ajax.php');
t_ok('a serial lookup returns the chain', strpos($aj, "\$r['chain'] = array_map") !== false);
t_ok('...and where the warranty started', strpos($aj, "\$r['origin'] = serial_warranty_origin(\$sn)") !== false);

// ------------------------------- a sales return asks WHICH piece came back --
// The screen used to say "write the serial number in Notes". Nothing posted
// serials_txt, so the handler that restored serials was dead code: every
// return brought the quantity back and left the serial still marked sold. The
// serial book and the stock book drifted apart on every single return.
t_group('a sales return records the serial that came back');
$sr_src = file_get_contents(dirname(__DIR__) . '/sales_return.php');
t_ok('the screen offers a serial picker', strpos($sr_src, "serials: true, returnMode: true") !== false);
t_ok('...and no longer tells the owner to use the Notes box',
     strpos($sr_src, 'write the serial number in "Notes"') === false);
t_ok('the picked serials are read, keyed by the row', strpos($sr_src, "post('serial_sel', [])") !== false
     && strpos($sr_src, "post('row_n', [])") !== false);
t_ok('the dead serials_txt field is no longer read', strpos($sr_src, "post('serials_txt'") === false);
t_ok('putting one back goes through the one shared rule',
     strpos($sr_src, 'serial_put_in_stock($r[\'item_id\'], $sn, $loc_id)') !== false);
$aj2 = file_get_contents(dirname(__DIR__) . '/ajax.php');
t_ok('the list a return shows is of SOLD serials, not stock',
     strpos($aj2, "get('mode') === 'return'") !== false && strpos($aj2, "isr.status = 'sold'") !== false);
t_ok('...each with the bill it went out on', strpos($aj2, "s.invoice_no") !== false);
$js2 = file_get_contents(dirname(__DIR__) . '/assets/app.js');
t_ok('the picker asks the return question', strpos($js2, 'કયો સિરિયલ પાછો આવ્યો?') !== false);
t_ok('...and fetches in return mode', strpos($js2, "(isRet ? '&mode=return' : '')") !== false);

// the refusals, checked as rules rather than through the screen: a return must
// never invent stock out of a serial that never left
t_group('a serial that did not go out cannot come back');
$ri = t_item(0, $loc);
q('INSERT INTO stock (item_id, location_id, qty) VALUES (?,?,0) ON DUPLICATE KEY UPDATE qty = 0', [$ri, $loc]);
ts_serial($ri, 'TSR-SOLD', 'sold', 7777);
ts_serial($ri, 'TSR-SHELF', 'in_stock');
q("UPDATE item_serials SET location_id = ? WHERE item_id = ? AND serial_no = 'TSR-SHELF'", [$loc, $ri]);
t_eq('a sold serial is the one that can return', tw_ser($ri, 'TSR-SOLD')['status'], 'sold');
t_eq('a serial already on the shelf is not', tw_ser($ri, 'TSR-SHELF')['status'], 'in_stock');
// the guard the screen applies, asserted on the source so it cannot be dropped
t_ok('the screen refuses a serial that is not sold',
     strpos($sr_src, "\$srow['status'] !== 'sold'") !== false);
t_ok('...refuses one that is not this item\'s', strpos($sr_src, 'આ આઇટમનો નથી') !== false);
t_ok('...refuses a serial-tracked item with nothing picked',
     strpos($sr_src, "!empty(\$item['serial_tracked']) && !\$r['sns']") !== false);
t_ok('...and refuses a count that does not match the quantity',
     strpos($sr_src, "count(\$r['sns']) != (int)\$r['qty']") !== false);
// every one of those checks runs BEFORE the first write, so a refusal leaves
// nothing behind - the guards sit above $pdo->beginTransaction()
$guardAt = strpos($sr_src, 'વેચાયેલો નથી');
$txnAt = strpos($sr_src, '$pdo->beginTransaction()');
t_ok('every serial is checked before anything is written', $guardAt !== false && $txnAt !== false && $guardAt < $txnAt);

t_group('returning a serial puts it back where it belongs');
serial_put_in_stock($ri, 'TSR-SOLD', $loc);
$back = tw_ser($ri, 'TSR-SOLD');
t_eq('it is in stock again', $back['status'], 'in_stock');
t_eq('...at the shop\'s location', (int)$back['location_id'], $loc);
t_eq('...and no longer tied to the bill it was sold on', $back['sale_id'], null);
t_eq('...with one row, not a duplicate',
     (int)val('SELECT COUNT(*) FROM item_serials WHERE item_id = ? AND serial_no = ?', [$ri, 'TSR-SOLD']), 1);

// ------------------------- a purchase return asks WHICH piece goes back too --
// The sale side got its picker; the purchase side still had one free-text box
// at the top of the page and nothing on the item itself. Sending goods back to
// a supplier takes them off OUR shelf, so the list to pick from is the serials
// in stock - the same tick-boxes as selling one.
t_group('a purchase return picks the serials going back to the supplier');
$pr_src = file_get_contents(dirname(__DIR__) . '/purchase_return.php');
t_ok('the item offers its in-stock serials', strpos($pr_src, 'serials: true, pickStock: true') !== false);
t_ok('the picked serials are read, keyed by the row',
     strpos($pr_src, "post('serial_sel', [])") !== false && strpos($pr_src, "post('row_n', [])") !== false);
t_ok('...and recorded on the return line', strpos($pr_src, "\$r['sns'] ? implode(',', \$r['sns']) : null") !== false);
t_ok('marking one returned is scoped to its own item',
     strpos($pr_src, "WHERE item_id=? AND serial_no=? AND status='in_stock'") !== false);
$js3 = file_get_contents(dirname(__DIR__) . '/assets/app.js');
t_ok('the widget can pick from stock in purchase mode',
     strpos($js3, "this.cfg.mode === 'purchase' && !this.cfg.pickStock") !== false
     && strpos($js3, "this.cfg.mode === 'sale' || this.cfg.pickStock") !== false);
t_ok('...and says what the pick is for', strpos($js3, 'કયો સિરિયલ સપ્લાયરને પાછો મોકલવાનો છે?') !== false);

t_group('a piece that is not on the shelf cannot go back to the supplier');
t_ok('a serial not in stock is refused', strpos($pr_src, "\$srow['status'] !== 'in_stock'") !== false);
t_ok('...one that is not this item\'s is refused', strpos($pr_src, 'આ આઇટમનો નથી') !== false);
t_ok('...a serial-tracked item with nothing picked is refused',
     strpos($pr_src, "!empty(\$item['serial_tracked']) && !\$r['sns']") !== false);
t_ok('...and a count that does not match the quantity is refused',
     strpos($pr_src, "count(\$r['sns']) != (int)\$r['qty']") !== false);
$g = strpos($pr_src, 'સ્ટોકમાં નથી (અત્યારે');
$t = strpos($pr_src, '$pdo->beginTransaction()');
t_ok('every serial is checked before anything is written', $g !== false && $t !== false && $g < $t);

t_group('deleting a purchase return brings the pieces back');
// this used to end with "(Please manually verify serial number status.)" -
// the software knew which serials it had sent and made the owner check anyway
t_ok('the serials on the line are put back', strpos($pr_src, 'serial_put_in_stock((int)$ri[\'item_id\'], $sn, (int)$ret[\'location_id\'])') !== false);
t_ok('...and the manual-check warning is gone', strpos($pr_src, 'manually verify serial number status') === false);

t_group('both return screens can search the party list');
foreach (['purchase_return.php', 'sales_return.php'] as $f) {
    $src = file_get_contents(dirname(__DIR__) . '/' . $f);
    t_ok($f . ' turns its party list into a search box', strpos($src, "PartyPick.init('party_id'") !== false);
    t_ok($f . ' keeps a real select behind it', strpos($src, 'name="party_id" id="party_id"') !== false);
    t_ok($f . ' carries the mobile so it is searchable', strpos($src, 'data-mobile=') !== false);
}

// ------------------------------------- a purchase return can be opened again --
// The list only ever showed a total, so "which pieces did we actually send on
// this one, and which are still here" had no answer anywhere in the software.
t_group('a purchase return has a detail view');
$pr_v = file_get_contents(dirname(__DIR__) . '/purchase_return.php');
t_ok('there is a view screen', strpos($pr_v, "if (\$action === 'view') {") !== false);
t_ok('...reachable from the list', strpos($pr_v, "purchase_return.php?action=view&id=<?= \$r['id'] ?>") !== false);
t_ok('...and it refuses an id that is not a return', strpos($pr_v, "die('Return not found.')") !== false);
t_ok('it reads the return with its supplier and location',
     strpos($pr_v, 'FROM purchase_returns pr') !== false && strpos($pr_v, 'LEFT JOIN locations l') !== false);

t_group('the view answers both halves of the question');
t_ok('WHAT WENT: the serials stored on each line are listed',
     strpos($pr_v, 'શું મોકલ્યું') !== false && strpos($pr_v, "explode(',', (string)\$ln['serials'])") !== false);
// each sent serial shows where it is NOW - still with the supplier, or back
t_ok('...each with its state today', strpos($pr_v, "\$st === 'returned_supplier'") !== false
     && strpos($pr_v, 'સપ્લાયર પાસે') !== false && strpos($pr_v, 'પાછો આવી ગયો') !== false);
t_ok('WHAT DID NOT: the same items\' serials still in stock', strpos($pr_v, 'શું નથી મોકલ્યું') !== false
     && strpos($pr_v, "status = 'in_stock'") !== false);
t_ok('...scoped to this return\'s own location', strpos($pr_v, "\$ret['location_id'] ? ' AND location_id = ?' : ''") !== false);
// only serial-tracked lines can be compared that way; for anything else the
// quantity IS the answer and a serial list would be made up
t_ok('...and only for serial-tracked items',
     strpos($pr_v, "array_filter(\$lines, fn(\$l) => !empty(\$l['serial_tracked']))") !== false);

t_group('the view shows where the money went');
t_ok('an adjusted return shows the bills it credited',
     strpos($pr_v, 'FROM purchase_return_credits c') !== false);
t_ok('...and says plainly when part of it is still unapplied', strpos($pr_v, 'હજી કોઈ બિલ સામે લાગ્યા નથી') !== false);
t_ok('a cash or bank refund shows the account it came into',
     strpos($pr_v, "WHERE p.ref_type = 'purchase_return' AND p.ref_id = ?") !== false
     && strpos($pr_v, "\$refundPay['account_name']") !== false);
t_ok('...and a return with no payment at all says so', strpos($pr_v, 'કોઈ પેમેન્ટ નોંધાયેલું નથી') !== false);

// ------------------------------- the claim's party is the SUPPLIER, not a customer --
// This is the bug the earlier fixtures walked straight past: on a warranty
// claim party_id is the Company / Supplier - the form says so - and filling it
// in is the normal thing to do. Reading it as "there is a customer" sent the
// shop's own replacement back marked sold, to a customer who did not exist,
// and it never reached the stock. Every fixture above leaves the supplier
// blank, so none of them caught it.
t_group('a claim with a supplier filled in is still the shop\'s own piece');
$spSup = t_party('TSP Supplier ' . bin2hex(random_bytes(3)));
$spIt = t_item(0, $loc);
q('INSERT INTO stock (item_id, location_id, qty) VALUES (?,?,5) ON DUPLICATE KEY UPDATE qty = 5', [$spIt, $loc]);
ts_serial($spIt, 'TSP-OLD', 'in_stock');
q("UPDATE item_serials SET location_id = ? WHERE item_id = ? AND serial_no = 'TSP-OLD'", [$loc, $spIt]);
q("INSERT INTO warranty_claims (claim_no, item_id, serial_no, party_id, customer_name, status, received_date, location_id, created_by)
   VALUES (?,?, 'TSP-OLD', ?, '', 'received_back', CURDATE(), ?, 1)",
  ['TSPC-' . bin2hex(random_bytes(3)), $spIt, $spSup, $loc]);
$spClaim = row('SELECT * FROM warranty_claims WHERE id = ?', [insert_id()]);
warranty_apply_replacement($spClaim, 'TSP-OLD', 'TSP-NEW', $loc);
t_eq('the replacement goes into stock, not to a phantom customer', tw_ser($spIt, 'TSP-NEW')['status'], 'in_stock');
t_eq('...and the count reflects it', stock_qty($spIt, $loc), 5.0);
t_eq('...with no bill attached to it', tw_ser($spIt, 'TSP-NEW')['sale_id'], null);
// a REAL customer claim still behaves, and the supplier being set changes nothing
$spIt2 = t_item(0, $loc);
q('INSERT INTO stock (item_id, location_id, qty) VALUES (?,?,5) ON DUPLICATE KEY UPDATE qty = 5', [$spIt2, $loc]);
ts_serial($spIt2, 'TSP-SOLD', 'sold', 3131);
q("INSERT INTO warranty_claims (claim_no, item_id, serial_no, party_id, customer_name, status, received_date, location_id, created_by)
   VALUES (?,?, 'TSP-SOLD', ?, 'Ramesh', 'received_back', CURDATE(), ?, 1)",
  ['TSPC2-' . bin2hex(random_bytes(3)), $spIt2, $spSup, $loc]);
$spClaim2 = row('SELECT * FROM warranty_claims WHERE id = ?', [insert_id()]);
warranty_apply_replacement($spClaim2, 'TSP-SOLD', 'TSP-SOLD2', $loc);
t_eq('a named customer still owns the replacement', tw_ser($spIt2, 'TSP-SOLD2')['status'], 'sold');
t_eq('...on the same bill', (int)tw_ser($spIt2, 'TSP-SOLD2')['sale_id'], 3131);
t_eq('...and the shop\'s count is untouched', stock_qty($spIt2, $loc), 5.0);
$hp = file_get_contents(dirname(__DIR__) . '/includes/helpers.php');
t_ok('ownership is never read from party_id', strpos($hp, "\$hasCustomer = !empty(\$claim['party_id'])") === false);

t_group('a warranty that comes back as money credits the supplier\'s bills');
// the company often cannot send the part, so it knocks the amount off what we
// owe it - that has to land on real bills or the dues stay too high for ever
$wcSup = t_party('TWC Supplier ' . bin2hex(random_bytes(3)));
$wcCo  = (int)val('SELECT id FROM companies ORDER BY id LIMIT 1');
foreach ([['TWC-B1', '2026-07-01', 4000], ['TWC-B2', '2026-08-01', 9000]] as $b)
    q("INSERT INTO purchases (company_id, location_id, party_id, bill_no, purchase_date, subtotal, total, paid, status, created_by)
       VALUES (?,?,?,?,?,?,?,0,'due',1)", [$wcCo, $loc, $wcSup, $b[0], $b[1], $b[2], $b[2]]);
$wcB2 = (int)val("SELECT id FROM purchases WHERE party_id = ? AND bill_no = 'TWC-B2'", [$wcSup]);
$wcIt = t_item(0, $loc);
q("INSERT INTO warranty_claims (claim_no, item_id, serial_no, party_id, status, received_date, back_date, location_id, created_by)
   VALUES (?,?, 'TWC-SN', ?, 'received_back', '2026-09-01', '2026-09-20', ?, 1)",
  ['TWCC-' . bin2hex(random_bytes(3)), $wcIt, $wcSup, $loc]);
$wcClaim = row('SELECT * FROM warranty_claims WHERE id = ?', [insert_id()]);

t_eq('they are owed the two bills to start with', party_balance_side($wcSup, 'out'), 13000.0);
$m = warranty_apply_credit($wcClaim, 6000, 0, 1);
t_ok('the credit says where it went', strpos($m, 'TWC-B1') !== false);
t_eq('the oldest bill is settled first', val("SELECT status FROM purchases WHERE party_id = ? AND bill_no = 'TWC-B1'", [$wcSup]), 'paid');
t_eq('...and the remainder spills onto the next', money_r((float)val("SELECT paid FROM purchases WHERE id = ?", [$wcB2])), 2000.0);
t_eq('what we owe them drops by exactly the credit', party_balance_side($wcSup, 'out'), 7000.0);
// no cash left a drawer - this must never appear in the cashbook or bank book
t_eq('it is posted as a credit note, not cash',
     val("SELECT mode FROM payments WHERE ref_type = 'warranty' AND ref_id = ?", [$wcClaim['id']]), 'credit_note');
t_ok('...and credit_note is one of the non-cash modes', in_array('credit_note', money_noncash_modes(), true));

// changing the amount re-does it from scratch rather than stacking
$wcClaim = row('SELECT * FROM warranty_claims WHERE id = ?', [$wcClaim['id']]);
warranty_apply_credit($wcClaim, 3000, $wcB2, 1);
t_eq('the old credit is taken back off the bill it had settled',
     val("SELECT status FROM purchases WHERE party_id = ? AND bill_no = 'TWC-B1'", [$wcSup]), 'due');
t_eq('...and the new one lands on the bill that was named', money_r((float)val("SELECT paid FROM purchases WHERE id = ?", [$wcB2])), 3000.0);
t_eq('the ledger follows', party_balance_side($wcSup, 'out'), 10000.0);
t_eq('...with one payment row, not two',
     (int)val("SELECT COUNT(*) FROM payments WHERE ref_type = 'warranty' AND ref_id = ?", [$wcClaim['id']]), 1);

// clearing it puts everything back
$wcClaim = row('SELECT * FROM warranty_claims WHERE id = ?', [$wcClaim['id']]);
warranty_apply_credit($wcClaim, 0, 0, 1);
t_eq('clearing the credit re-opens the bills', party_balance_side($wcSup, 'out'), 13000.0);
t_eq('...and removes its payment', (int)val("SELECT COUNT(*) FROM payments WHERE ref_type = 'warranty' AND ref_id = ?", [$wcClaim['id']]), 0);
t_eq('...leaving no orphan allocations',
     (int)val('SELECT COUNT(*) FROM payment_allocations pa LEFT JOIN payments p ON p.id = pa.payment_id WHERE p.id IS NULL'), 0);
// a credit with no supplier chosen has nowhere to go, and says so
$wcClaim = row('SELECT * FROM warranty_claims WHERE id = ?', [$wcClaim['id']]);
q('UPDATE warranty_claims SET party_id = NULL WHERE id = ?', [$wcClaim['id']]);
$wcNoParty = row('SELECT * FROM warranty_claims WHERE id = ?', [$wcClaim['id']]);
t_ok('a credit with no supplier is refused, not guessed',
     strpos(warranty_apply_credit($wcNoParty, 1000, 0, 1), 'Company / Supplier') !== false);

t_group('"no cash moved" is one list, not six copies');
$mny2 = file_get_contents(dirname(__DIR__) . '/includes/money.php');
t_ok('the list lives in one function', substr_count($mny2, 'function money_noncash_modes') === 1);
t_eq('...and holds all three', money_noncash_modes(), ['contra', 'discount', 'credit_note']);
$rb4 = file_get_contents(dirname(__DIR__) . '/includes/report_body.php');
t_ok('no report spells the list out by hand any more',
     strpos($rb4, "NOT IN ('contra','discount')") === false);
t_ok('...they all call the shared one', substr_count($rb4, 'money_cash_only_sql(') >= 6);
$wa3 = file_get_contents(dirname(__DIR__) . '/warranty.php');
t_ok('the claim screen asks for the credit', strpos($wa3, 'name="credit_amount"') !== false);
t_ok('...and which bill to put it on', strpos($wa3, 'name="credit_bill_id"') !== false);
t_ok('...and applies it on save', strpos($wa3, 'warranty_apply_credit(') !== false);

// ---------------------------------------------------------------------------
// Reported from the shop: the item page showed the 22-inch monitor with
// 1 PCS in stock and its serial marked "In stock", while New Purchase Return
// for the same item at Main Shop said "આ લોકેશનમાં આ આઇટમનો કોઈ સિરિયલ
// સ્ટોકમાં નથી". Both screens were reading the same row and telling the truth:
// the serial WAS in stock, and it was at no location, because every screen
// works out the location as "the one on the form, else the user's own" and an
// admin with no location of their own makes that 0. The item page lists
// serials without a location filter; every picker filters by location. So the
// piece was counted everywhere and pickable nowhere - and no screen could put
// it right, because nothing can pick what nothing can see.
t_group('stock and serials always land on a real shelf');

$shelfLoc = (int)val('SELECT id FROM locations WHERE is_active = 1 ORDER BY id LIMIT 1');
$nowhere  = t_item(0, $shelfLoc);

// this is the call the old code made when nobody had a location
$r = serial_put_in_stock($nowhere, 'TSL-NOWHERE', 0);
t_eq('a serial handed no location is still added', $r['result'], 'added');
$row = ts_serial_row($nowhere, 'TSL-NOWHERE');
t_eq('...in stock', $row['status'], 'in_stock');
t_ok('...but never with location 0', (int)$row['location_id'] > 0);
t_eq('...it goes to the shop\'s own shelf', (int)$row['location_id'], $shelfLoc);

// the exact query every picker runs (ajax.php a=serials) must now find it
$pickable = all("SELECT serial_no FROM item_serials
                 WHERE item_id = ? AND location_id = ? AND status = 'in_stock'", [$nowhere, $shelfLoc]);
t_eq('...so the return and billing screens can offer it',
     array_column($pickable, 'serial_no'), ['TSL-NOWHERE']);

// the same hole one step up: the stock row itself
$noLocQty = t_item(0, $shelfLoc);
adjust_stock($noLocQty, 0, 3, 'opening', null, 'no location given');
t_eq('stock with no location is not left at location 0',
     (int)val('SELECT COUNT(*) FROM stock WHERE item_id = ? AND (location_id IS NULL OR location_id = 0)', [$noLocQty]), 0);
t_eq('...it lands on the shelf, where the location-wise report can see it',
     (float)val('SELECT qty FROM stock WHERE item_id = ? AND location_id = ?', [$noLocQty, $shelfLoc]), 3.0);
t_eq('...and its movement history says the same place',
     (int)val('SELECT COUNT(*) FROM stock_ledger WHERE item_id = ? AND location_id = ?', [$noLocQty, $shelfLoc]), 1);

// a location that was deleted afterwards is no better than none
$goneItem = t_item(0, $shelfLoc);
q("INSERT INTO item_serials (item_id, serial_no, status, location_id, warranty_months, created_at)
   VALUES (?,?,'in_stock',?,0,NOW())", [$goneItem, 'TSL-GONE', 987654]);
t_eq('putting it back finds the stray location', serial_put_in_stock($goneItem, 'TSL-GONE', 987654)['result'], 'already');
t_eq('...and re-shelves it somewhere real',
     (int)ts_serial_row($goneItem, 'TSL-GONE')['location_id'], $shelfLoc);

// one already in stock with nothing under it gets a shelf too - this is the
// path the repair screen uses, and the only way to rescue a row by hand
$strandedItem = t_item(0, $shelfLoc);
q("INSERT INTO item_serials (item_id, serial_no, status, location_id, warranty_months, created_at)
   VALUES (?,?,'in_stock',0,0,NOW())", [$strandedItem, 'TSL-STRANDED']);
t_eq('a stranded serial is already in stock', serial_put_in_stock($strandedItem, 'TSL-STRANDED', 0)['result'], 'already');
t_ok('...and is given a shelf', (int)ts_serial_row($strandedItem, 'TSL-STRANDED')['location_id'] > 0);

t_group('which shelf: one rule, used everywhere');
$homeItem = t_item(0, $shelfLoc);
t_eq('a real location is taken as asked', stock_home_location($shelfLoc, $homeItem), $shelfLoc);
t_eq('a location that does not exist is not', stock_home_location(987654, $homeItem), $shelfLoc);
t_eq('nothing at all still gives a shelf', stock_home_location(0, $homeItem), $shelfLoc);
adjust_stock($homeItem, $shelfLoc, 7, 'opening', null, 'test fixture');
t_eq('...and it prefers where this item\'s stock already sits',
     stock_home_location(0, $homeItem), (int)val('SELECT location_id FROM stock WHERE item_id = ? ORDER BY qty DESC LIMIT 1', [$homeItem]));

// every screen that works a location out goes through it, so none of them can
// write a 0 again
foreach (['sales.php', 'purchases.php', 'purchase_return.php', 'sales_return.php', 'handover.php', 'stock.php'] as $scr) {
    $src = file_get_contents(dirname(__DIR__) . '/' . $scr);
    t_ok($scr . ' resolves the location it works with',
         preg_match('/\$loc_id = stock_home_location\(/', $src) === 1);
}
$hlp = file_get_contents(dirname(__DIR__) . '/includes/helpers.php');
t_ok('adjust_stock() resolves it for every caller',
     preg_match('/function adjust_stock\(.*?\$location_id = stock_home_location\(/s', $hlp) === 1);
t_ok('...and so does serial_put_in_stock()',
     preg_match('/function serial_put_in_stock\(.*?stock_home_location\(/s', $hlp) === 1);
t_ok('the rule is written once', substr_count($hlp, 'function stock_home_location') === 1);
$sfx = file_get_contents(dirname(__DIR__) . '/serial_fix.php');
t_ok('the repair screen keeps no copy of it', strpos($sfx, 'function serial_fix_loc') === false);

t_group('a serial with no shelf is visible and repairable');
$iv = file_get_contents(dirname(__DIR__) . '/item_view.php');
t_ok('the item page shows which location each serial is at', strpos($iv, '<th>Location</th>') !== false);
t_ok('...reading it from the locations table', strpos($iv, 'LEFT JOIN locations l ON l.id = s.location_id') !== false);
t_ok('...and says so loudly when there is none', strpos($iv, 'લોકેશન નથી') !== false);
$hl = file_get_contents(dirname(__DIR__) . '/includes/health.php');
t_ok('the health check looks for stranded serials', strpos($hl, 'Serial in stock but on no shelf') !== false);

// and the ones already in the books get put back by the migration
$fixItem = t_item(0, $shelfLoc);
q("INSERT INTO item_serials (item_id, serial_no, status, location_id, warranty_months, created_at)
   VALUES (?,?,'in_stock',0,0,NOW())", [$fixItem, 'TSL-OLDROW']);
q("INSERT INTO item_serials (item_id, serial_no, status, location_id, warranty_months, created_at)
   VALUES (?,?,'in_stock',NULL,0,NOW())", [$fixItem, 'TSL-OLDNULL']);
q("INSERT INTO item_serials (item_id, serial_no, status, location_id, warranty_months, created_at)
   VALUES (?,?,'sold',NULL,0,NOW())", [$fixItem, 'TSL-OLDSOLD']);
require_once dirname(__DIR__) . '/includes/dbmigrate.php';
$v65log = [];
dbmigrate_run_file('upgrade_v65.sql', file_get_contents(dirname(__DIR__) . '/install/upgrade_v65.sql'), $v65log);
t_eq('migration v65 puts a location-0 serial back on the shelf',
     (int)ts_serial_row($fixItem, 'TSL-OLDROW')['location_id'], $shelfLoc);
t_eq('...and a NULL one too', (int)ts_serial_row($fixItem, 'TSL-OLDNULL')['location_id'], $shelfLoc);
t_eq('...and leaves sold pieces alone, they are on nobody\'s shelf',
     ts_serial_row($fixItem, 'TSL-OLDSOLD')['location_id'], null);
t_eq('no serial anywhere is in stock with no shelf',
     (int)val("SELECT COUNT(*) FROM item_serials WHERE status = 'in_stock'
               AND (location_id IS NULL OR location_id = 0
                    OR NOT EXISTS (SELECT 1 FROM locations l WHERE l.id = item_serials.location_id))"), 0);
// running it twice must not move anything a second time
dbmigrate_run_file('upgrade_v65.sql', file_get_contents(dirname(__DIR__) . '/install/upgrade_v65.sql'), $v65log);
t_eq('...and running the migration again changes nothing',
     (int)ts_serial_row($fixItem, 'TSL-OLDROW')['location_id'], $shelfLoc);

t_group('a manual adjustment cannot move a serial that is somewhere else');
$stk = file_get_contents(dirname(__DIR__) . '/stock.php');
t_ok('adjusting out checks WHICH location the piece is on',
     strpos($stk, "(int)\$srow['location_id'] !== \$loc_id") !== false);
t_ok('...and says where it really is', strpos($stk, 'આ લોકેશનમાં નથી') !== false);
// a refusal half way down the list used to leave the serials above it moved
// while the quantity never changed - everything is checked before anything
// is written now
t_ok('every serial is checked before the first one is written',
     strpos($stk, 'Every serial is checked BEFORE the first one is written') !== false
     && substr_count($stk, 'foreach ($sns as $sn) {') === 2);
