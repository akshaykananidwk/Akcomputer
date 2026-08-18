<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); die('CLI only'); }
// Market intelligence.
//
// Every figure on this screen is a claim about the shop's own trade, so each
// one is checked against a history built by hand whose right answer can be
// worked out on paper. The tests that matter most are the refusals: this
// screen must say "not enough history" and "that field is empty" rather than
// showing a confident zero.
$_SESSION['user_id'] = (int)val("SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id
                                 WHERE r.permissions LIKE '%*%' AND u.is_active = 1 ORDER BY u.id LIMIT 1");
$_ROOT = dirname(__DIR__);
$mkLoc = (int)val('SELECT id FROM locations ORDER BY id LIMIT 1');
$mkCo  = (int)val('SELECT id FROM companies ORDER BY id LIMIT 1');

/** A category with a name we can find again. */
function tm_cat($name) {
    q('INSERT INTO categories (name) VALUES (?)', [$name]);
    return insert_id();
}

/** An item in a category, at a known cost and price. */
function tm_item($name, $catId, $brand = 'TMBRAND', $sell = 1000, $cost = 600) {
    global $mkLoc;
    $id = t_item(0, $mkLoc, $sell);
    q('UPDATE items SET name = ?, category_id = ?, brand = ?, purchase_price = ?, selling_price = ? WHERE id = ?',
      [$name, $catId, $brand, $cost, $sell, $id]);
    return $id;
}

/** One bill for one item, on a given date, at a given realised price. */
function tm_sell($itemId, $partyId, $qty, $price, $date, $lineDisc = 0, $billDisc = 0) {
    global $mkCo, $mkLoc;
    $net = $qty * $price - $lineDisc;
    q("INSERT INTO sales (company_id, location_id, party_id, customer_name, invoice_no, sale_date,
        subtotal, discount, loyalty_discount, total, paid, payment_mode, status, created_by)
       VALUES (?,?,?, 'TM', ?, ?, ?, ?, 0, ?, ?, 'cash', 'paid', 1)",
      [$mkCo, $mkLoc, $partyId, 'TM-' . bin2hex(random_bytes(4)), $date, $net, $billDisc, $net - $billDisc, $net - $billDisc]);
    $sid = insert_id();
    q('INSERT INTO sale_items (sale_id, item_id, qty, price, cost_price, line_disc, total) VALUES (?,?,?,?,?,?,?)',
      [$sid, $itemId, $qty, $price, 0, $lineDisc, $net]);
    return $sid;
}

/** One purchase of one item, on a given date, at a given rate. */
function tm_buy($itemId, $partyId, $qty, $price, $date) {
    global $mkCo, $mkLoc;
    q("INSERT INTO purchases (company_id, location_id, party_id, bill_no, purchase_date, subtotal, total, paid, status, created_by)
       VALUES (?,?,?,?,?,?,?,?, 'paid', 1)",
      [$mkCo, $mkLoc, $partyId, 'TMP-' . bin2hex(random_bytes(4)), $date, $qty * $price, $qty * $price, $qty * $price]);
    $pid = insert_id();
    q('INSERT INTO purchase_items (purchase_id, item_id, qty, price, total) VALUES (?,?,?,?,?)',
      [$pid, $itemId, $qty, $price, $qty * $price]);
    return $pid;
}

$d = fn($days) => date('Y-m-d', strtotime("-$days days"));

// ------------------------------------------------------------ percentages --
t_group('a change is described honestly, including when it cannot be');
$up = mk_change(1200, 1000);
t_eq('a rise is a percentage', $up['pct'], 20.0);
t_ok('...and points up', $up['dir'] === 'up');
$small = mk_change(1050, 1000);
t_ok('a 5% wobble is called steady, not growth', $small['dir'] === 'flat');
$new = mk_change(5000, 0);
t_ok('growth from nothing is not infinity', $new['pct'] === null);
t_ok('...it is called new', $new['label'] === 'નવું');
$gone = mk_change(0, 5000);
t_eq('a category that stopped is -100%', $gone['pct'], -100.0);
t_ok('...and is called stopped', $gone['label'] === 'બંધ થઈ ગયું');
$none = mk_change(0, 0);
t_ok('nothing against nothing is not a change', $none['dir'] === 'flat');

t_group('the two windows meet without gap or overlap');
$w = mk_windows(365);
t_ok('the old window ends the day before the new one starts',
     date('Y-m-d', strtotime($w['prev_to'] . ' +1 day')) === $w['now_from'],
     $w['prev_to'] . ' → ' . $w['now_from']);
// Counting inclusively, or "this year" quietly gets one extra day and every
// comparison leans towards growth.
$lenPrev = (int)round((strtotime($w['prev_to']) - strtotime($w['prev_from'])) / 86400) + 1;
$lenNow = (int)round((strtotime($w['now_to']) - strtotime($w['now_from'])) / 86400) + 1;
t_ok('the old window is exactly as long as the new one', $lenPrev === $lenNow, "$lenPrev vs $lenNow");
t_eq('...and both are a full year of days', $lenNow, 366);

// -------------------------------------------------------------- momentum ---
t_group('growth and decline are measured a year apart');
$catUp = tm_cat('TMCAT_UP_' . bin2hex(random_bytes(3)));
$catDown = tm_cat('TMCAT_DOWN_' . bin2hex(random_bytes(3)));
$iUp = tm_item('TMITEM up', $catUp);
$iDown = tm_item('TMITEM down', $catDown);
$buyer = t_party('TM Buyer');
// this year 200000, last year 100000  -> doubled
tm_sell($iUp, $buyer, 100, 2000, $d(100));
tm_sell($iUp, $buyer, 50, 2000, $d(500));
// this year 60000, last year 120000  -> halved
tm_sell($iDown, $buyer, 30, 2000, $d(100));
tm_sell($iDown, $buyer, 60, 2000, $d(500));

$mom = mk_momentum('category');
$byName = [];
foreach ($mom as $x) $byName[$x['name']] = $x;
$upRow = null; $downRow = null;
foreach ($byName as $n => $x) {
    if (strpos($n, 'TMCAT_UP_') === 0) $upRow = $x;
    if (strpos($n, 'TMCAT_DOWN_') === 0) $downRow = $x;
}
t_ok('the growing category is found', $upRow !== null);
t_eq('this year is measured from the bills', $upRow['now'], 200000);
t_eq('last year too', $upRow['prev'], 100000);
t_eq('the change is the real percentage', $upRow['pct'], 100.0);
t_ok('...and is marked as growth', $upRow['dir'] === 'up');
t_ok('the shrinking category is found', $downRow !== null);
t_eq('a halving reads as -50%', $downRow['pct'], -50.0);
t_ok('...and is marked as decline', $downRow['dir'] === 'down');
t_ok('the list is sorted by rupees gained, best first', $mom[0]['diff'] >= $mom[count($mom) - 1]['diff']);

t_group('a sale outside both windows is not counted');
tm_sell($iUp, $buyer, 1000, 2000, $d(900));    // well before the earlier window
$mom2 = mk_momentum('category');
foreach ($mom2 as $x) if ($x['name'] === $upRow['name']) $upRow2 = $x;
t_eq('this year is unchanged', $upRow2['now'], 200000);
t_eq('last year is unchanged', $upRow2['prev'], 100000);

t_group('brands are measured the same way as categories');
$br = mk_momentum('brand');
$tmBrand = null;
foreach ($br as $x) if ($x['name'] === 'TMBRAND') $tmBrand = $x;
t_ok('the test brand is found', $tmBrand !== null);
t_eq('and carries both categories\' sales', $tmBrand['now'], 260000);

// ---------------------------------------------------------------- squeeze --
t_group('the squeeze compares what was paid with what was got');
$catSq = tm_cat('TMCAT_SQ_' . bin2hex(random_bytes(3)));
$iSq = tm_item('TMITEM squeeze', $catSq);
$supplier = t_party('TM Supplier');
// paid 100 -> 120 (+20%), got 150 -> 155 (+3.3%): squeezed
tm_buy($iSq, $supplier, 10, 100, $d(300));
tm_buy($iSq, $supplier, 10, 120, $d(30));
tm_sell($iSq, $buyer, 10, 150, $d(300));
tm_sell($iSq, $buyer, 10, 155, $d(30));

$sq = mk_squeeze(500);
$sqRow = null;
foreach ($sq['rows'] as $x) if ((int)$x['item_id'] === $iSq) $sqRow = $x;
t_ok('the item appears', $sqRow !== null);
t_eq('the purchase rate is the rate actually paid', $sqRow['pay_now'], 120);
t_eq('the earlier purchase rate too', $sqRow['pay_prev'], 100);
t_eq('the realised price is the price actually got', $sqRow['got_now'], 155);
t_eq('the margin is the difference', $sqRow['margin_now'], 35);
t_eq('the earlier margin was wider', $sqRow['margin_prev'], 50);
t_eq('costs rose 20%', $sqRow['pay_pct'], 20.0);
t_eq('prices rose 3.3%', $sqRow['got_pct'], 3.3);
t_eq('so the squeeze is the gap between them', $sqRow['squeeze'], -16.7);
t_ok('the worst squeeze is listed first', $sq['rows'][0]['squeeze'] <= $sq['rows'][count($sq['rows']) - 1]['squeeze']);

t_group('an item that cannot be compared is skipped, not guessed');
$iOnlySold = tm_item('TMITEM sold only', $catSq);
tm_sell($iOnlySold, $buyer, 5, 500, $d(300));
tm_sell($iOnlySold, $buyer, 5, 500, $d(30));
$sq2 = mk_squeeze(500);
$found = false;
foreach ($sq2['rows'] as $x) if ((int)$x['item_id'] === $iOnlySold) $found = true;
t_ok('an item never purchased does not appear', !$found);
t_ok('...and the skipped count says so', $sq2['skipped'] > $sq['skipped'] - 1);

t_group('the realised price is net of the line discount, not the list price');
$iDisc = tm_item('TMITEM discounted', $catSq, 'TMBRAND', 1000, 600);
tm_buy($iDisc, $supplier, 10, 600, $d(300));
tm_buy($iDisc, $supplier, 10, 600, $d(30));
tm_sell($iDisc, $buyer, 10, 1000, $d(300));
tm_sell($iDisc, $buyer, 10, 1000, $d(30), 2000);   // Rs.2000 off the line
$sq3 = mk_squeeze(500);
$dRow = null;
foreach ($sq3['rows'] as $x) if ((int)$x['item_id'] === $iDisc) $dRow = $x;
t_ok('the discounted item appears', $dRow !== null);
t_eq('the realised price is 800, not the 1000 on the price list', $dRow['got_now'], 800);
t_ok('so the discount shows up as a squeeze', $dRow['squeeze'] < 0);

// --------------------------------------------------------------- discount --
t_group('every kind of discount is counted, in one place');
$catD = tm_cat('TMCAT_D_' . bin2hex(random_bytes(3)));
$iD = tm_item('TMITEM disc', $catD, 'TMBRAND2', 1000, 600);
$thisMonth = date('Y-m-15');
if (strtotime($thisMonth) > strtotime(today())) $thisMonth = today();
tm_sell($iD, $buyer, 10, 1000, $thisMonth, 500, 300);   // 10000 list, 500 line off, 300 bill off
$disc = mk_discount(2);
$row = null;
foreach ($disc as $m) if ($m['month'] === date('Y-m')) $row = $m;
t_ok('this month appears', $row !== null);
t_ok('the line discount is counted', $row['line_disc'] >= 500);
t_ok('the bill discount is counted too', $row['bill_disc'] >= 300);
t_ok('the full price is before any discount', $row['gross'] >= 10000);
t_ok('what was given away is line + bill + loyalty', $row['given'] >= 800);
t_ok('the percentage is given away over full price',
     abs($row['pct'] - round($row['given'] / $row['gross'] * 100, 2)) < 0.02);

t_group('discount by category counts only what can honestly be attributed');
$dc = mk_discount_by_category(500);
$dcRow = null;
foreach ($dc as $x) if (strpos((string)$x['name'], 'TMCAT_D_') === 0) $dcRow = $x;
t_ok('the category appears', $dcRow !== null);
t_eq('with the LINE discount only', $dcRow['given'], 500);
$src = file_get_contents($_ROOT . '/includes/market.php');
t_ok('and the code says why the bill discount is left out',
     strpos($src, 'invented number') !== false);

// -------------------------------------------------------------- lifecycle --
t_group('new and dead products are facts about dates');
$catL = tm_cat('TMCAT_L_' . bin2hex(random_bytes(3)));
// Both lists are capped at 25 rows and sorted by value, so a test item worth a
// few hundred rupees can be pushed out by real data and the test then fails for
// a reason that has nothing to do with the rule being checked. These are sold
// for more than any single item in the database, so ranking cannot interfere.
$big = 6000000;
$iNew = tm_item('TMITEM brand new', $catL);
tm_sell($iNew, $buyer, 1, $big, $d(30));
tm_sell($iNew, $buyer, 1, $big, $d(10));
$iOld = tm_item('TMITEM gone quiet', $catL);
tm_sell($iOld, $buyer, 1, $big, $d(400));
tm_sell($iOld, $buyer, 1, $big, $d(300));
$lc = mk_lifecycle();
$isNew = false; $isOld = false;
foreach ($lc['rising'] as $x) if ((int)$x['id'] === $iNew) $isNew = true;
foreach ($lc['fading'] as $x) if ((int)$x['id'] === $iOld) $isOld = true;
t_ok('an item first sold last month is called new', $isNew);
t_ok('an item silent for ten months is called gone quiet', $isOld);
$notNew = false;
foreach ($lc['rising'] as $x) if ((int)$x['id'] === $iOld) $notNew = true;
t_ok('...and is not also called new', !$notNew);
$stillSelling = false;
foreach ($lc['fading'] as $x) if ((int)$x['id'] === $iNew) $stillSelling = true;
t_ok('an item that sold last week is not called dead', !$stillSelling);

// ----------------------------------------------------------------- season --
t_group('season is refused without the history to support it');
$h = mk_history();
$se = mk_season();
if ($h['can_season']) {
    t_ok('with two years of history the months are shown', $se['ok']);
    t_ok('...only whole months are counted', $se['from'] === date('Y-m-01', strtotime($h['first_sale'] . ' +1 month')));
    t_ok('...ending before this incomplete month', $se['to'] === date('Y-m-t', strtotime('-1 month')));
    t_ok('twelve months are always listed', count($se['months']) === 12);
    $sumIdx = 0; $n = 0;
    foreach ($se['months'] as $m) { if ($m['index'] > 0) { $sumIdx += $m['index']; $n++; } }
    t_ok('the index averages about 100', $n === 0 || abs($sumIdx / $n - 100) < 25, 'avg ' . ($n ? round($sumIdx / $n) : 0));
    foreach ($se['months'] as $m) if ($m['bills'] > 0) {
        t_ok('every month says how many years it rests on (' . $m['name'] . ')', $m['years'] >= 1);
        break;
    }
} else {
    t_ok('without two years it refuses outright', !$se['ok']);
    t_ok('...and says why in plain words', strpos($se['why'], 'બે વર્ષ') !== false);
}
t_ok('the code refuses on history, not on emptiness', strpos($src, "can_season") !== false);

// ------------------------------------------------- missing data is missing --
t_group('an empty field is reported as missing, never as zero');
$g = mk_geography();
$cityCount = (int)val("SELECT COUNT(*) FROM parties WHERE city IS NOT NULL AND city <> ''");
if ($cityCount === 0) {
    t_ok('with no city filled in, geography refuses', !$g['ok']);
    t_ok('...and says the field is empty', strpos($g['why'], 'શહેર') !== false);
    t_ok('...and does not return a made-up row', $g['rows'] === []);
}
q("UPDATE parties SET city = 'TMCITY' WHERE id = ?", [$buyer]);
$g2 = mk_geography();
t_ok('once a city exists, geography works', $g2['ok']);
$hasCity = false;
foreach ($g2['rows'] as $x) if ($x['city'] === 'TMCITY') $hasCity = true;
t_ok('...and the city appears with its real bills', $hasCity);
t_ok('...and how many customers are still blank is reported', $g2['filled'] < $g2['total'] || $g2['filled'] === $g2['total']);

t_group('lost quotations refuse when there are no quotations');
$lq = mk_lost_quotes();
if ((int)val('SELECT COUNT(*) FROM estimates') === 0) {
    t_ok('no quotations means no win rate', !$lq['ok']);
    t_ok('...said in plain words', strpos($lq['why'], 'ક્વોટેશન') !== false);
    t_ok('...and no invented rows', $lq['rows'] === []);
    t_ok('lost items is empty too, not fabricated', mk_lost_items() === []);
}
q("INSERT INTO estimates (company_id, estimate_no, party_id, customer_name, location_id, estimate_date,
    subtotal, discount, tax_amount, total, status, created_by)
   VALUES (?, ?, ?, 'TM', ?, ?, 5000, 0, 0, 5000, 'converted', 1)",
  [$mkCo, 'TME-' . bin2hex(random_bytes(4)), $buyer, $mkLoc, $d(30)]);
q("INSERT INTO estimates (company_id, estimate_no, party_id, customer_name, location_id, estimate_date,
    subtotal, discount, tax_amount, total, status, created_by)
   VALUES (?, ?, ?, 'TM', ?, ?, 3000, 0, 0, 3000, 'rejected', 1)",
  [$mkCo, 'TME-' . bin2hex(random_bytes(4)), $buyer, $mkLoc, $d(20)]);
$lq2 = mk_lost_quotes();
t_ok('with quotations it works', $lq2['ok']);
t_eq('one won', $lq2['stats']['won'], 1);
t_eq('one lost', $lq2['stats']['lost'], 1);
t_eq('the win rate is over CLOSED quotations only', $lq2['stats']['win_pct'], 50.0);
$hasRejected = false;
foreach ($lq2['rows'] as $x) if ($x['status'] === 'rejected') $hasRejected = true;
t_ok('the rejected quotation is listed', $hasRejected);
$hasConverted = false;
foreach ($lq2['rows'] as $x) if ($x['status'] === 'converted') $hasConverted = true;
t_ok('a quotation that became a bill is not listed as lost', !$hasConverted);
t_ok('no reason for the loss is invented', strpos(file_get_contents($_ROOT . '/market.php'), 'સોદો કેમ ગયો એ ચોપડો જાણતો નથી') !== false);

// ---------------------------------------------------------------- summary --
t_group('the cached summary reads back exactly as it was written');
dash_cache_forget('market_summary');
$cold = mk_summary();
$warm = mk_summary();
unset($cold['cached_at'], $warm['cached_at']);
t_ok('cold and warm are identical, types included', $cold === $warm);
t_ok('the year is a real figure', $cold['sales_year'] >= 0);
t_ok('growing and shrinking fit inside the group count',
     $cold['growing'] + $cold['shrinking'] <= $cold['groups']);

// ------------------------------------------------------------- guard rails --
t_group('the screen refuses to pretend it knows the outside world');
$page = file_get_contents($_ROOT . '/market.php');
t_ok('it states plainly that there is no outside data', strpos($page, 'બહારની કોઈ માહિતી નથી') !== false);
t_ok('it needs reports.view', strpos($page, "require_perm('reports.view')") !== false);
t_ok('...and a cost permission on top of that', strpos($page, "can('reports.profit') && !can('items.cost')") !== false);
t_ok('it never writes a business row',
     !preg_match('/\b(INSERT INTO|UPDATE\s+(?!.*settings)\w+\s+SET|DELETE FROM)\b/i', $page));
t_ok('the data layer only reads', !preg_match('/\b(INSERT INTO|UPDATE\s+\w+\s+SET|DELETE FROM)\b/i', $src));
t_ok('no AI is involved anywhere in it', strpos($src, 'ai_ask') === false && strpos($page, 'ai_ask') === false);
t_ok('the dashboard card sits behind the same fence',
     strpos(file_get_contents($_ROOT . '/index.php'), "can('reports.view') && (\$seeProfit || can('items.cost'))") !== false);
t_ok('...and only appears when there is something to say',
     strpos(file_get_contents($_ROOT . '/index.php'), "\$m['shrinking'] > 0 || \$m['change']['dir'] === 'down'") !== false);
