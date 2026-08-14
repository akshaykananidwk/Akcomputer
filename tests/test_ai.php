<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); die('CLI only'); }
// AI guard rails, the Sales Assistant and the bill scanner's arithmetic.
//
// The owner's rules for AI in this shop are specific: it may never write to
// the database, it may never decide a price or a margin, its failures must be
// harmless, it must be capped in rupees, and customer identity must not leave
// the building. Every one of those is a test below, and most of them are
// checked at the source, because a rule that only holds at runtime is one
// refactor away from not holding at all.
$_SESSION['user_id'] = (int)val("SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id
                                 WHERE r.permissions LIKE '%*%' AND u.is_active = 1 ORDER BY u.id LIMIT 1");
$_ROOT = dirname(__DIR__);

/** Put the AI settings in a known state. Rolled back with everything else. */
function ta_set(array $kv) { foreach ($kv as $k => $v) set_setting($k, (string)$v); }

/** A recorded AI call this month, at a known cost. */
function ta_usage($tokensIn, $tokensOut, $paise, $feature = 'assistant') {
    q("INSERT INTO api_usage (service, provider, units_in, units_out, calls, cost_paise, feature, created_at)
       VALUES ('gemini', 'test', ?, ?, 1, ?, ?, ?)",
      [$tokensIn, $tokensOut, $paise, $feature, date('Y-m-d H:i:s')]);
}

// start from a clean, fully-open configuration
q("DELETE FROM api_usage WHERE service = 'gemini'");
ta_set(['ai_enabled' => '1', 'ai_feat_assistant' => '1', 'ai_feat_bill_scan' => '1',
        'gemini_api_key' => 'TESTKEY', 'gemini_api_key_paid' => '',
        'ai_monthly_calls' => '1000', 'ai_monthly_budget' => '0',
        'ai_rate_in_per_m' => '0', 'ai_rate_out_per_m' => '0']);

// ---------------------------------------------------------------- the gate --
t_group('the AI gate answers with a reason, never an exception');
list($ok, $why) = ai_can_call('assistant');
t_ok('a configured feature is allowed', $ok, $why);

ta_set(['ai_enabled' => '0']);
list($ok, $why) = ai_can_call('assistant');
t_ok('the master switch stops every feature', !$ok);
t_ok('...and says where to turn it back on', strpos($why, 'Settings → AI') !== false, $why);

ta_set(['ai_enabled' => '1', 'ai_feat_assistant' => '0']);
list($ok, $why) = ai_can_call('assistant');
t_ok('a single feature can be switched off on its own', !$ok);
list($ok2, ) = ai_can_call('bill_scan');
t_ok('...without touching the others', $ok2);

ta_set(['ai_feat_assistant' => '1', 'gemini_api_key' => '', 'gemini_api_key_paid' => '']);
list($ok, $why) = ai_can_call('assistant');
t_ok('no key means no call', !$ok);
t_ok('...and the reason names the key', strpos($why, 'API key') !== false, $why);

t_group('the monthly caps actually bite');
ta_set(['gemini_api_key' => 'TESTKEY', 'ai_monthly_calls' => '3']);
ta_usage(1000, 500, 0); ta_usage(1000, 500, 0);
list($ok, ) = ai_can_call('assistant');
t_ok('under the call cap it still runs', $ok);
ta_usage(1000, 500, 0);
list($ok, $why) = ai_can_call('assistant');
t_ok('at the call cap it stops', !$ok);
t_ok('...and shows the count', strpos($why, '3/3') !== false, $why);

ta_set(['ai_monthly_calls' => '0', 'ai_monthly_budget' => '1']);   // 0 calls = no call cap
q("DELETE FROM api_usage WHERE service = 'gemini'");
ta_usage(1000, 500, 150);                                          // Rs 1.50 spent
list($ok, $why) = ai_can_call('assistant');
t_ok('the rupee budget stops it too', !$ok);
t_ok('...showing spent against budget', strpos($why, '1.50') !== false && strpos($why, '1.00') !== false, $why);

t_group('the caps are read fresh, not cached');
// This is a real bug that shipped for an afternoon: ai_limits() held its
// answer in a static, so a screen that SAVED the budget and then checked it in
// the same request read the old value - a kill switch that killed nothing.
ta_set(['ai_monthly_budget' => '10000']);
list($ok, ) = ai_can_call('assistant');
t_ok('raising the budget takes effect in the same request', $ok);
ta_set(['ai_enabled' => '0']);
list($ok, ) = ai_can_call('assistant');
t_ok('and so does pulling the master switch', !$ok);
t_ok('no static cache in ai_limits()',
     strpos(file_get_contents($_ROOT . '/includes/ai_guard.php'), 'static $') === false);
ta_set(['ai_enabled' => '1']);

// ------------------------------------------------------------ what it cost --
t_group('cost is counted from the shop\'s own rates');
ta_set(['ai_rate_in_per_m' => '10', 'ai_rate_out_per_m' => '40']);
t_eq('1M in + 1M out at Rs.10/Rs.40 is Rs.50', ai_cost_paise(1000000, 1000000) / 100, 50.0);
t_eq('a small call rounds to paise', ai_cost_paise(10000, 2000) / 100, 0.18, 0.005);
ta_set(['ai_rate_in_per_m' => '0', 'ai_rate_out_per_m' => '0']);
t_eq('with the rates unset the cost is honestly zero', ai_cost_paise(1000000, 1000000), 0);

q("DELETE FROM api_usage WHERE service = 'gemini'");
ta_usage(100, 50, 250, 'assistant');
ta_usage(200, 60, 125, 'bill_scan');
$u = ai_month_usage();
t_eq('this month\'s calls', $u['calls'], 2);
t_eq('this month\'s tokens in', $u['tokens_in'], 300);
t_eq('this month\'s spend in rupees', $u['cost'], 3.75);

// --------------------------------------------------------------- fallbacks --
t_group('every AI failure degrades into ordinary work');
ta_set(['ai_enabled' => '0']);
list($out, $why) = ai_ask('assistant', [['text' => 'hello']]);
t_ok('ai_ask returns null and a reason, it does not throw', $out === null && $why !== '');

$kw = sa_keywords('wifi router જોઈએ', 0);
t_ok('the assistant falls back to the typed words', $kw['src'] === 'typed');
t_ok('...and says why AI was not used', $kw['why'] !== '');
t_ok('...keeping the real product words', in_array('wifi', $kw['words'], true) && in_array('router', $kw['words'], true));

list($doc, $why) = bs_extract($_ROOT . '/includes/bill_scan.php');
t_ok('the bill scanner returns null so the OCR path can run', $doc === null && $why !== '');
$scan = file_get_contents($_ROOT . '/purchase_scan.php');
t_ok('...and the screen really does fall back to OCR', strpos($scan, 'if ($doc === null && $scanFile !== null)') !== false);
ta_set(['ai_enabled' => '1']);

t_group('AI is not called when the shop\'s own words are enough');
$kw = sa_keywords('anything at all', 3);
t_ok('three or more hits skip the call entirely', $kw['src'] === 'typed');
t_ok('...and the screen says so', strpos($kw['why'], 'AI વાપર્યું નથી') !== false, $kw['why']);

// ------------------------------------------------------------ no authority --
t_group('AI has no hand on the money or the database');
foreach (['includes/ai_guard.php', 'includes/sales_assist.php', 'includes/bill_scan.php'] as $f) {
    $src = file_get_contents($_ROOT . '/' . $f);
    // the ONE write in this whole layer is the usage meter in ai_ask()
    $writes = preg_match_all('/\b(INSERT INTO|UPDATE|DELETE FROM)\s+(\w+)/i', $src, $m);
    $tables = array_unique(array_map('strtolower', $m[2] ?? []));
    $illegal = array_diff($tables, ['api_usage']);
    t_ok("$f writes to nothing but the usage meter", !$illegal,
         $illegal ? 'writes: ' . implode(', ', $illegal) : '');
}
$assist = file_get_contents($_ROOT . '/assistant.php');
t_ok('the assistant screen writes nothing at all',
     !preg_match('/\b(INSERT INTO|UPDATE\s+\w+\s+SET|DELETE FROM)\b/i', $assist));
t_ok('...and takes no POST', strpos($assist, "\$_SERVER['REQUEST_METHOD'] === 'POST'") === false);
t_ok('the handoff goes to the ordinary bill form', strpos($assist, "location.href = 'sales.php?'") !== false);
t_ok('...which a person still has to save', strpos($scan, 'sessionStorage.setItem') !== false);

t_group('prices come from the catalogue, never from AI');
$loc = (int)val('SELECT id FROM locations ORDER BY id LIMIT 1');
$itemId = t_item(5, $loc, 777);
q("UPDATE items SET name = 'ZZTESTROUTER Alpha', selling_price = 777, purchase_price = 500 WHERE id = ?", [$itemId]);
$rows = sa_find(['ZZTESTROUTER'], $loc);
t_ok('the item is found by the deterministic search', count($rows) === 1);
t_eq('its price is the catalogue price', $rows[0]['selling_price'], 777);
t_eq('its stock is the real stock', $rows[0]['stock'], 5);
$m = sa_margin($rows[0]);
t_eq('the margin is worked out from cost and price', $m['amount'], 277);
t_ok('sa_margin refuses when there is no cost', sa_margin(['purchase_price' => 0, 'selling_price' => 100]) === null);

t_group('words the AI returns are worthless unless the catalogue agrees');
t_ok('an invented product name finds nothing', sa_find(['QQNOSUCHTHING'], $loc) === []);
t_ok('...and an empty word list finds nothing', sa_find([], $loc) === []);

// ------------------------------------------------------------------ search --
t_group('the search understands how a customer speaks');
$w = sa_words('ગ્રાહકને ઘર માટે wifi router જોઈએ છે 2000 સુધી');
t_ok('filler words are dropped', !in_array('માટે', $w, true) && !in_array('જોઈએ', $w, true));
t_ok('product words are kept', in_array('wifi', $w, true) && in_array('router', $w, true));
t_ok('the budget is not treated as a search word', !in_array('2000', $w, true));

t_eq('the budget is read by code, not by AI', sa_budget('ઘર માટે router 2000 સુધી'), 2000.0);
t_eq('27 inch 5000 સુધી picks the money, not the size', sa_budget('27 inch monitor 5000 સુધી'), 5000.0);
t_eq('a comma-written amount is understood', sa_budget('બજેટ 12,500'), 12500.0);
t_eq('no number means no budget', sa_budget('wifi router'), 0.0);

$id2 = t_item(3, $loc, 999);
q("UPDATE items SET name = 'ZZTESTROUTER Beta' WHERE id = ?", [$id2]);
t_ok('every word together is tried first', count(sa_search(['ZZTESTROUTER', 'Beta'], $loc)) === 1);
t_ok('a word that matches nothing kills the strict pass',
     sa_search(['ઘર', 'ZZTESTROUTER'], $loc) === []);
t_ok('...but the fallback still finds the routers', count(sa_find(['ઘર', 'ZZTESTROUTER'], $loc)) === 2);
$loose = sa_find(['ઘર', 'ZZTESTROUTER'], $loc);
t_ok('the best-matching rows come first', (int)$loose[0]['hits'] >= (int)$loose[count($loose) - 1]['hits']);

// Found by this suite: c.name is NULL for an item with no category, and
// "FALSE OR NULL" is NULL in SQL - so an uncategorised item vanished from the
// search entirely and its hits score came back NULL.
q('UPDATE items SET category_id = NULL WHERE id = ?', [$id2]);
t_ok('an item with no category is still found', count(sa_search(['ZZTESTROUTER', 'Beta'], $loc)) === 1);
t_ok('...and by the loose pass too', count(sa_find(['ઘર', 'ZZTESTROUTER'], $loc)) === 2);
t_ok('...with a real hits score, not NULL', (int)sa_search(['ZZTESTROUTER'], $loc)[0]['hits'] === 1);

// ----------------------------------------------------------------- privacy --
t_group('customer identity does not leave the building');
$dirty = 'Ramesh 9825012345 ramesh@example.com 24ABCDE1234F1Z5 needs a router';
$clean = ai_safe_text($dirty);
t_ok('the phone number is stripped', strpos($clean, '9825012345') === false);
t_ok('the email is stripped', strpos($clean, 'example.com') === false);
t_ok('the GST number is stripped', strpos($clean, '24ABCDE1234F1Z5') === false);
t_ok('the product words survive', strpos($clean, 'router') !== false);
t_ok('long text is cut to a sane length', mb_strlen(ai_safe_text(str_repeat('x ', 900))) <= 500);

$sa = file_get_contents($_ROOT . '/includes/sales_assist.php');
t_ok('the assistant sanitises before it sends', strpos($sa, 'ai_safe_text($q') !== false);
t_ok('...and never puts the customer in the prompt',
     !preg_match('/\$prompt\s*\.?=.*\$party/i', $sa));
t_ok('only the shop\'s own category names go along as vocabulary',
     strpos($sa, 'sa_vocabulary()') !== false);

// -------------------------------------------------------- bill arithmetic ---
t_group('the bill scanner checks its own reading');
$clean = bs_check(['lines' => [
    ['desc' => 'Router', 'model' => 'C6', 'qty' => 2, 'rate' => 1500, 'amount' => 3000, 'tax_pct' => 18],
    ['desc' => 'Cable', 'model' => '', 'qty' => 10, 'rate' => 25, 'amount' => 250, 'tax_pct' => 18],
], 'subtotal' => 3250, 'tax' => 585, 'discount' => 0, 'total' => 3835]);
t_ok('a bill that adds up raises nothing', !$clean['problems'] && $clean['bad_lines'] === 0);
t_eq('the lines are added by code', $clean['line_sum'], 3250);

$bad = bs_check(['lines' => [
    ['desc' => 'Hard Disk', 'model' => 'WD10', 'qty' => 72, 'rate' => 3200, 'amount' => 38400, 'tax_pct' => 18],
], 'subtotal' => 38400, 'tax' => 6912, 'discount' => 0, 'total' => 45312]);
t_ok('a misread quantity is caught by qty x rate', $bad['bad_lines'] === 1);
t_ok('...and the row says what does not match', strpos($bad['lines'][0]['problems'][0], '230,400') !== false);
t_ok('...and the row is not marked usable', !$bad['lines'][0]['ok']);

$calc = bs_check(['lines' => [
    ['desc' => 'Camera', 'model' => 'DS2', 'qty' => 4, 'rate' => 1250, 'amount' => 0, 'tax_pct' => 0],
], 'subtotal' => 0, 'tax' => 0, 'discount' => 0, 'total' => 5000]);
t_eq('a missing line total is worked out', $calc['lines'][0]['amount'], 5000);
t_ok('...and the row says it was worked out', $calc['lines'][0]['notes'] !== []);
t_ok('...and stays usable', $calc['lines'][0]['ok']);

$q0 = bs_check(['lines' => [
    ['desc' => 'DVR', 'model' => 'D8', 'qty' => 0, 'rate' => 4000, 'amount' => 12000, 'tax_pct' => 0],
], 'subtotal' => 12000, 'tax' => 0, 'discount' => 0, 'total' => 12000]);
t_eq('a missing quantity is worked out from amount and rate', $q0['lines'][0]['qty'], 3);

$blank = bs_check(['lines' => [
    ['desc' => 'blurred', 'model' => '', 'qty' => 0, 'rate' => 0, 'amount' => 0, 'tax_pct' => 0],
], 'subtotal' => 0, 'tax' => 0, 'discount' => 0, 'total' => 0]);
t_ok('a line with nothing readable is flagged, not invented', !$blank['lines'][0]['ok']);

$wrongTotal = bs_check(['lines' => [
    ['desc' => 'Switch', 'model' => 'SW8', 'qty' => 3, 'rate' => 900, 'amount' => 2700, 'tax_pct' => 0],
], 'subtotal' => 2700, 'tax' => 0, 'discount' => 0, 'total' => 9999]);
t_ok('a bill total that does not follow from the lines is flagged', $wrongTotal['problems'] !== []);
t_ok('...while the line itself stays fine', $wrongTotal['lines'][0]['ok']);

$subMismatch = bs_check(['lines' => [
    ['desc' => 'Mouse', 'model' => 'M1', 'qty' => 2, 'rate' => 200, 'amount' => 400, 'tax_pct' => 0],
], 'subtotal' => 5000, 'tax' => 0, 'discount' => 0, 'total' => 5000]);
t_ok('lines that do not add up to the printed sub-total are flagged', $subMismatch['problems'] !== []);

$round = bs_check(['lines' => [
    ['desc' => 'UPS', 'model' => 'U600', 'qty' => 3, 'rate' => 2333.33, 'amount' => 7000, 'tax_pct' => 0],
], 'subtotal' => 7000, 'tax' => 0, 'discount' => 0, 'total' => 7000.50]);
t_ok('a rupee of rounding is not called a mistake', !$round['problems'] && $round['bad_lines'] === 0);

$noTaxRow = bs_check(['lines' => [
    ['desc' => 'Keyboard', 'model' => 'KB1', 'qty' => 10, 'rate' => 300, 'amount' => 3000, 'tax_pct' => 18],
], 'subtotal' => 3000, 'tax' => 0, 'discount' => 0, 'total' => 3540]);
t_ok('an unread GST row falls back to the per-line percentage', !$noTaxRow['problems']);
t_eq('...and the GST it used is shown', $noTaxRow['tax_used'], 540);

$disc = bs_check(['lines' => [
    ['desc' => 'Keyboard', 'model' => 'KB1', 'qty' => 10, 'rate' => 300, 'amount' => 3000, 'tax_pct' => 0],
], 'subtotal' => 3000, 'tax' => 0, 'discount' => 100, 'total' => 2900]);
t_ok('a discount row is part of the sum', !$disc['problems']);

t_group('a flagged line cannot walk onto a purchase by itself');
$rows = bs_review_rows($bad);
t_ok('the review row carries the problem forward', $rows[0]['problems'] !== []);
t_ok('...and is marked not-ok', !$rows[0]['ok']);
t_ok('the screen unticks a flagged row', strpos($scan, '<?= $lineOk ? \'checked\' : \'\' ?>') !== false);
t_ok('...and asks before taking one anyway', strpos($scan, 'scan-bad .scan-include:checked') !== false);
t_ok('the quantity read off the bill is used, not 1', strpos($scan, "!empty(\$r['qty']) ? (float)\$r['qty'] : 1") !== false);

t_group('the scanner still matches items to the catalogue');
$rowsOk = bs_review_rows(bs_check(['lines' => [
    ['desc' => 'ZZTESTROUTER Alpha', 'model' => '', 'qty' => 1, 'rate' => 500, 'amount' => 500, 'tax_pct' => 0],
], 'subtotal' => 500, 'tax' => 0, 'discount' => 0, 'total' => 500]));
t_ok('a bill line with no part number matches on its description', !empty($rowsOk[0]['matches']));
t_eq('...to the right item', (int)$rowsOk[0]['matches'][0]['id'], $itemId);

// ------------------------------------------------------------- permissions --
t_group('the assistant respects the same gates as everywhere else');
t_ok('the screen needs items.view', strpos($assist, "require_perm('items.view')") !== false);
t_ok('cost and margin sit behind items.cost', strpos($assist, "\$showCost = can('items.cost')") !== false);
t_ok('...and the column is only drawn when allowed', substr_count($assist, 'if ($showCost)') >= 2);
t_ok('the customer\'s past price sits behind sales.view', strpos($assist, "can('sales.view')") !== false);
t_ok('the credit warning sits behind payments.view', strpos($sa, "can('payments.view')") !== false);
t_ok('a party with nothing owing raises no credit warning', sa_credit_warning(t_party()) === null);
t_ok('no party at all raises no credit warning', sa_credit_warning(0) === null);

t_group('the screens say what is AI and what is arithmetic');
t_ok('the assistant explains the division of labour', strpos($assist, 'શબ્દો સમજવા') !== false);
t_ok('...and labels which words were used', strpos($assist, 'તમે લખેલા શબ્દો') !== false);
t_ok('the scanner says the arithmetic was checked', strpos($scan, 'બિલનો હિસાબ ચકાસ્યો') !== false);
t_ok('...and tells the reader a flagged line needs checking', strpos($scan, 'જાતે ચકાસો') !== false);
