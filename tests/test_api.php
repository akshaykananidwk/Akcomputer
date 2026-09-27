<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); die('CLI only'); }
// ============================================================================
//  THE PHONE APP'S DOOR (api.php)
//
//  The app writes bills on a dead network and pushes them later. Two things
//  can go wrong there that nothing else in this codebase can go wrong with:
//
//    1. the same bill arrives twice (the reply was lost, the phone retried)
//    2. the phone never hears about a change made on the website
//
//  Everything below guards one of those two, plus the handful of rules the
//  app's money must still obey once it lands.
// ============================================================================

t_group('App API — a bill can never arrive twice');

$src = file_get_contents(__DIR__ . '/../api.php');

// --- the database itself refuses, not just the code -------------------------
// The app-level "have I seen this uuid?" check is a read followed by a write,
// and two pushes a few milliseconds apart can both pass the read. The UNIQUE
// index is what actually makes it impossible.
foreach (['sales', 'payments'] as $tbl) {
    $uniq = val("SELECT COUNT(*) FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'client_uuid' AND NON_UNIQUE = 0", [$tbl]);
    t_ok("$tbl.client_uuid is UNIQUE in the database", (int)$uniq === 1, "found $uniq unique index(es)");
}

// and prove it: the second insert must throw, not quietly duplicate
$uuid = 'test-' . bin2hex(random_bytes(8));
$pt = t_party();
t_payment($pt, 100);
q('UPDATE payments SET client_uuid = ? WHERE id = ?', [$uuid, insert_id()]);
$threw = false;
try {
    q("INSERT INTO payments (party_id, direction, amount, mode, pay_date, notes, created_by, client_uuid)
       VALUES (?, 'in', 100, 'cash', ?, 'dupe', 1, ?)", [$pt, today(), $uuid]);
} catch (Throwable $e) { $threw = true; }
t_ok('a second row with the same client_uuid is rejected', $threw);

// NULL is not a duplicate of NULL - every website payment has no uuid and
// they must all still fit.
$ok = true;
try {
    q("INSERT INTO payments (party_id, direction, amount, mode, pay_date, notes, created_by, client_uuid)
       VALUES (?, 'in', 10, 'cash', ?, 'no uuid', 1, NULL)", [$pt, today()]);
    q("INSERT INTO payments (party_id, direction, amount, mode, pay_date, notes, created_by, client_uuid)
       VALUES (?, 'in', 10, 'cash', ?, 'no uuid', 1, NULL)", [$pt, today()]);
} catch (Throwable $e) { $ok = false; }
t_ok('rows with no uuid (the website\'s own) are unaffected', $ok);

// --- the retry must be answered before anything is written ------------------
$post = substr($src, strpos($src, "if (\$r === 'sales')"));
$dupePos = strpos($post, 'SELECT id, invoice_no, total FROM sales WHERE client_uuid');
$insPos  = strpos($post, 'INSERT INTO sales');
t_ok('the duplicate check runs before the INSERT, not after',
     $dupePos !== false && $insPos !== false && $dupePos < $insPos);
t_ok('a repeat answers 200 with the bill it already has, not an error',
     (bool)preg_match("/'duplicate' => true\], 200\)/", $post));

t_group('App API — a half-written bill is never left behind');

// Every item is looked up and every qty checked BEFORE beginTransaction, so a
// bad line means nothing at all was written - no stock moved, no number burnt.
$salesPost = substr($post, strpos($post, "if (\$method === 'POST')"));
$validate = strpos($salesPost, "api_json(['error' => 'Invalid item_id");
$begin    = strpos($salesPost, 'beginTransaction');
t_ok('items are validated before the transaction opens', $validate !== false && $begin !== false && $validate < $begin);
t_ok('a rejected bill rolls back', strpos($salesPost, 'rollBack') !== false);

// Money taken at the counter has to reach the cash book. Setting sales.paid
// on its own leaves "Cash in Hand" short by exactly that amount.
t_ok('cash taken with an app bill writes a real payments row',
     strpos($salesPost, 'INSERT INTO payments') !== false);
t_ok('the app bill gets its number from doc_next_no(), the same series as the shop',
     strpos($salesPost, 'doc_next_no(') !== false);

// An app payment against a party must settle that party's oldest bills, or
// the bill stays "unpaid" on the website for ever and Receivables lies.
$payPost = substr($src, strpos($src, "if (\$r === 'payments'"));
t_ok('an app payment settles the oldest bills first', strpos($payPost, 'money_settle_oldest_first') !== false);

t_group('App API — the phone can reach it at all');

// THE DEFECT THIS EXISTS FOR: the app's page is loaded out of the apk, so its
// origin is "null" and every call is cross-origin. Without these headers the
// browser blocks the reply and the app cannot even log in - and nothing else
// in the app would have told us, because the failure looks like "no network".
$head = substr($src, 0, strpos($src, "\$u = api_require();"));
t_ok('Access-Control-Allow-Origin is sent', strpos($head, 'Access-Control-Allow-Origin') !== false);
t_ok('the Authorization header is allowed through', strpos($head, 'Access-Control-Allow-Headers: Authorization') !== false);
t_ok('the browser\'s OPTIONS question is answered 204 without a token',
     (bool)preg_match("/OPTIONS'\).*?http_response_code\(204\)/s", $head));
t_ok('login itself needs no token', strpos($head, "\$r === 'login'") !== false);

// A stolen database must not hand anybody a working phone.
$cols = row("SHOW COLUMNS FROM api_tokens LIKE 'token_hash'");
t_ok('only the hash of a token is stored, never the token', !empty($cols));
t_ok('login stores the hash', strpos($head, 'api_token_hash($token)') !== false);
t_ok('re-installing the app does not leave old keys alive',
     (bool)preg_match('/UPDATE api_tokens SET revoked = 1/', $head));
t_ok('login is throttled like the website\'s', strpos($head, 'login_throttle_blocked') !== false);

t_group('App API — the phone hears about changes');

// The sync cursor is the server's clock, never the phone's. A phone an hour
// slow would ask for the same window for ever and miss everything in between.
$sync = substr($src, strpos($src, "if (\$r === 'sync'"));
$sync = substr($sync, 0, strpos($sync, "if (\$r === 'sales')"));
t_ok('sync hands back the SERVER\'s time as the next cursor', strpos($sync, "'server_time' => date('c')") !== false);
t_ok('a first sync (no cursor) sends everything', strpos($sync, '$full = !$sinceSql') !== false);
t_ok('the cost price stays hidden from a phone not allowed to see it',
     strpos($sync, "api_can('items.cost')") !== false);

// ...and the cursor only works if the table actually stamps itself on edit.
// Without ON UPDATE CURRENT_TIMESTAMP a price change on the website would
// never reach the counter phone, silently, for ever.
foreach (['items', 'parties'] as $tbl) {
    $extra = (string)val("SELECT EXTRA FROM information_schema.COLUMNS
                          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'updated_at'", [$tbl]);
    t_ok("$tbl.updated_at stamps itself when the row is edited",
         stripos($extra, 'on update') !== false, $extra === '' ? 'no "on update" stamp' : $extra);
}

// prove the behaviour, not just the schema
$itemId = t_item(5);
q("UPDATE items SET updated_at = '2020-01-01 00:00:00' WHERE id = ?", [$itemId]);
q('UPDATE items SET selling_price = selling_price + 1 WHERE id = ?', [$itemId]);
$stamp = (string)val('SELECT updated_at FROM items WHERE id = ?', [$itemId]);
t_ok('a price change moves the stamp', strtotime($stamp) > strtotime('-5 minutes'), $stamp);

// and that the query sync actually runs picks it up, while leaving alone an
// item nobody touched
$cursor = date('Y-m-d H:i:s', strtotime('-2 minutes'));
$other = t_item(1);
q("UPDATE items SET updated_at = '2020-01-01 00:00:00' WHERE id = ?", [$other]);
$changed = all('SELECT id FROM items WHERE updated_at > ? AND id IN (?,?)', [$cursor, $itemId, $other]);
$ids = array_column($changed, 'id');
t_ok('the edited item is in the next sync', in_array($itemId, $ids) || in_array((string)$itemId, $ids));
t_ok('the untouched item is not (the daily sync stays small)', count($ids) === 1, 'got ' . count($ids));

t_group('App API — a live server answers the preflight');

// The checks above read the source; this one asks a running server, because
// a header can be correct in the file and still be stripped by the host.
// Skipped, not failed, when nothing is running locally.
$baseUrl = getenv('API_TEST_URL') ?: 'http://127.0.0.1:8088';
$ch = curl_init($baseUrl . '/api.php?r=sync');
curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => 'OPTIONS', CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 4, CURLOPT_PROXY => '']);
$resp = curl_exec($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
if ($resp === false || $code === 0) {
    echo "  \033[90m· skipped — no server at $baseUrl (set API_TEST_URL to run these)\033[0m\n";
} else {
    t_ok('OPTIONS is answered 204, with no token', $code === 204, "HTTP $code");
    t_ok('and the reply carries the CORS header', stripos($resp, 'Access-Control-Allow-Origin') !== false);

    // no token = no data. Ever.
    $ch = curl_init($baseUrl . '/api.php?r=sync');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 4, CURLOPT_PROXY => '']);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    t_ok('a request with no token is refused 401', $code === 401, "HTTP $code — " . substr((string)$body, 0, 60));

    // Bad credentials must hand back no token. Deliberately a username that
    // does not exist: knocking on a REAL account's door would trip the login
    // throttle and lock the shop out of its own app for running its tests.
    $ch = curl_init($baseUrl . '/api.php?r=login');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6,
                            CURLOPT_PROXY => '', CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                            CURLOPT_POSTFIELDS => json_encode(['username' => 'nobody_' . bin2hex(random_bytes(6)), 'password' => 'wrong'])]);
    $body = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    t_ok('a wrong password gets no token', in_array($code, [401, 429], true) && strpos($body, '"token"') === false, "HTTP $code");
}

t_group('App API — the menu is the website\'s own menu');

// The whole point: ONE definition. If the app ever grows a second hard-coded
// list, a screen added to the website silently never reaches the phone.
t_ok('the menu lives in its own file, not inside a page', is_file(__DIR__ . '/../includes/menu.php'));
$hdr = file_get_contents(__DIR__ . '/../includes/header.php');
t_ok('the website draws its sidebar from it', strpos($hdr, 'nav_menu()') !== false);
t_ok('...and no longer keeps its own copy', strpos($hdr, "['group', 'items'") === false);
t_ok('the app is served the same one', strpos($src, 'nav_menu()') !== false);

$menu = nav_menu();
t_ok('every entry is a link or a group', count(array_filter($menu, fn($e) => in_array($e[0], ['link', 'group'], true))) === count($menu));
$screens = [];
foreach ($menu as $e) {
    if ($e[0] === 'link') { $screens[] = $e[1]; continue; }
    foreach ($e[4] as $it) { $screens[] = $it[0]; if ($it[3]) $screens[] = $it[3]; }
}
t_ok('the menu really does cover the shop', count($screens) > 60, count($screens) . ' screens');
// a menu entry pointing at a page that does not exist is a dead end in both
// the sidebar and the app
$missing = [];
foreach ($screens as $h) {
    $file = preg_replace('/\?.*$/', '', $h);
    if (!is_file(__DIR__ . '/../' . $file)) $missing[] = $h;
}
t_ok('every screen in the menu exists', !$missing, implode(', ', $missing));

t_group('App API — opening a website screen from the app');

// A one-time key that turns the app's token into a web session. Everything
// here is about what it must REFUSE.
$uid = (int)val('SELECT id FROM users WHERE is_active = 1 ORDER BY id LIMIT 1');

foreach ([
    'https://evil.example/x'      => 'another site',
    '//evil.example/x'            => 'a protocol-relative address',
    'javascript:alert(1)'         => 'a javascript: url',
    '../../etc/passwd'            => 'a path climbing out',
    'index.php\\..\\x'            => 'a backslash',
    'no_such_page_at_all.php'     => 'a page that does not exist',
    'index.html'                  => 'a non-page file',
] as $bad => $why) {
    t_ok("a link is refused for $why", app_link_target_ok($bad) === '', var_export($bad, true));
}
t_ok('an ordinary screen is accepted', app_link_target_ok('reports.php?r=profit_loss') === 'reports.php?r=profit_loss');
t_ok('an empty target means the dashboard', app_link_target_ok('') === 'index.php');

$nonce = app_link_make($uid, 'reports.php', 'test');
t_ok('a key is issued', strlen($nonce) === 64);
t_ok('the key itself is NOT stored', !val('SELECT id FROM app_web_links WHERE nonce_hash = ?', [$nonce]));
t_ok('only its hash is', (bool)val('SELECT id FROM app_web_links WHERE nonce_hash = ?', [hash('sha256', $nonce)]));

$first = app_link_consume($nonce);
t_ok('spending it logs the right person in', $first && $first['user_id'] === $uid, json_encode($first));
t_ok('...at the right page', $first && $first['target'] === 'reports.php');
// THE one that matters: a key in a log or a history must be worthless
t_ok('spending it a SECOND time gets nothing', app_link_consume($nonce) === null);

$stale = app_link_make($uid, 'index.php', 'test');
q("UPDATE app_web_links SET expires_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE nonce_hash = ?", [hash('sha256', $stale)]);
t_ok('an expired key gets nothing', app_link_consume($stale) === null);
t_ok('a made-up key gets nothing', app_link_consume(str_repeat('a', 64)) === null);
t_ok('a malformed key gets nothing', app_link_consume('../../x') === null);
t_ok('a key for a switched-off user gets nothing', (function () {
    q("INSERT INTO users (name, username, mobile, password, role_id, location_id, is_active)
       VALUES ('T', ?, '9000000000', ?, (SELECT id FROM roles ORDER BY id LIMIT 1), (SELECT id FROM locations ORDER BY id LIMIT 1), 1)",
      ['tmp_' . bin2hex(random_bytes(4)), password_hash('x', PASSWORD_DEFAULT)]);
    $tid = insert_id();
    $n = app_link_make($tid, 'index.php', 'test');
    q('UPDATE users SET is_active = 0 WHERE id = ?', [$tid]);
    return app_link_consume($n) === null;
})());

$loginSrc = file_get_contents(__DIR__ . '/../login.php');
t_ok('login.php spends the key through the one helper', strpos($loginSrc, 'app_link_consume(') !== false);
t_ok('...and a bad key just shows the ordinary login form', strpos($loginSrc, 'if ($handover)') !== false);

t_group('App API — what the phone needs to work a full day offline');

t_ok('serial numbers on the shelf are sent down', strpos($sync, "status = 'in_stock'") !== false);
t_ok('where the stock is, is sent down', strpos($sync, 'FROM stock WHERE qty <> 0') !== false);
t_ok('the shop\'s own recent bills are sent down, not just this phone\'s', strpos($sync, 'FROM sales WHERE sale_date >= ?') !== false);
t_ok('...and its payments, so a statement works with no signal', strpos($sync, 'FROM payments WHERE pay_date >= ?') !== false);
t_ok('bills are re-sent whole, never by cursor (a paid amount changes later)',
     strpos($sync, 'sale_date >= ?') !== false && strpos($sync, 'sales.updated_at') === false);
t_ok('a staff member only gets their own bills if that is all they may see', strpos($sync, 'api_own_scope') !== false);
t_ok('serials are withheld from a phone not allowed to see stock', strpos($sync, "api_can('stock.view')") !== false);

t_group('One rule: a serial leaves the shelf the same way everywhere');

$salesSrc = file_get_contents(__DIR__ . '/../sales.php');
t_ok('the billing screen no longer works it out by hand',
     substr_count($salesSrc, "UPDATE item_serials SET status='sold'") === 0, 'hand-written copies left');
t_ok('the billing screen calls the rule', substr_count($salesSrc, 'serial_sell(') === 2, 'new sale + bill edit');
t_ok('the app calls the same rule', strpos($src, 'serial_sell(') !== false);

// and the rule itself behaves
$si = t_item(3);
q('UPDATE items SET serial_tracked = 1, warranty_months = 12 WHERE id = ?', [$si]);
$sale1 = t_sale(t_party(), 500);
$sn = 'SNTEST' . bin2hex(random_bytes(3));
t_eq('an unknown serial is created as sold (advance billing)', serial_sell($si, $sn, $sale1, '2026-01-15', 12), 'created');
$s = row('SELECT status, sale_id, warranty_expiry, location_id FROM item_serials WHERE item_id = ? AND serial_no = ?', [$si, $sn]);
t_eq('...marked sold', $s['status'], 'sold');
t_eq('...to that bill', (int)$s['sale_id'], $sale1);
t_ok('...off every shelf', $s['location_id'] === null);
t_eq('warranty runs from the BILL date, not today', $s['warranty_expiry'], '2027-01-15');

$sale2 = t_sale(t_party(), 500);
$threw = false;
try { serial_sell($si, $sn, $sale2, today(), 12); } catch (Throwable $e) { $threw = true; }
t_ok('THE important one: the same serial cannot be sold on a second bill', $threw);

t_eq('re-posting the same bill\'s own serial is fine (a price-only edit)',
     serial_sell($si, $sn, $sale1, today(), 12), 'rebound');

$sn2 = 'SNTEST' . bin2hex(random_bytes(3));
serial_put_in_stock($si, $sn2, 0, 12);
t_eq('a serial sitting in stock is sold normally', serial_sell($si, $sn2, $sale1, today(), 0), 'sold');
t_ok('no warranty months means no expiry date',
     val('SELECT warranty_expiry FROM item_serials WHERE item_id = ? AND serial_no = ?', [$si, $sn2]) === null);
t_eq('a blank serial is ignored, not stored', serial_sell($si, '   ', $sale1, today(), 0), 'skipped');

t_group('App API — the customer side of the app');

// The same apk in a customer's hands. It must show exactly what the public
// website shows and nothing more, and it must not become a second way to
// reach the shop's private data.
$pub = substr($src, strpos($src, "in_array(\$r, ['catalog', 'wlogin', 'worder']"));
$pub = substr($pub, 0, strpos($pub, '$u = api_require();'));
t_ok('the public routes run BEFORE any staff token is demanded',
     strpos($src, "in_array(\$r, ['catalog', 'wlogin', 'worder']") < strpos($src, '$u = api_require();'));
t_ok('the catalogue shows only what the website publishes',
     strpos($pub, 'i.show_on_website = 1') !== false);
t_ok('...priced by the SAME rule the website prices it', strpos($pub, 'dealer_price(') !== false);
t_ok('...and says what is on the shelf the same way', strpos($pub, 'web_stock_line(') !== false);
t_ok('...honouring the setting that hides exact quantities', strpos($pub, 'web_show_qty()') !== false);

// Doors anybody may knock on have to be rate limited, and not all with one
// bucket: browsing is cheap and frequent, ordering writes.
t_ok('every public door is rate limited', strpos($pub, 'api_rate_ok(') !== false);
t_ok('...each with its own limit, not one shared bucket',
     strpos($pub, "'catalog' => [240, 60]") !== false && strpos($pub, "'worder' => [20, 60]") !== false);
t_ok('...keyed by route AND address', strpos($pub, "'shop:' . \$r . ':' . client_ip()") !== false);
t_ok('a dealer login is throttled per mobile as well', strpos($pub, "login_throttle_blocked('wapp:'") !== false);

// THE one that matters on a public write: the price comes from the item
// master, never from the phone.
t_ok('every order line is priced on the SERVER, not by the phone',
     strpos($pub, "SELECT id, name, selling_price FROM items WHERE id = ?") !== false
     && strpos($pub, "dealer_price(\$it['selling_price'], \$pct)") !== false);
t_ok('...and an item the website does not publish cannot be ordered',
     substr_count($pub, 'show_on_website = 1') >= 2);
t_ok('an order carries a client_uuid, so a lost reply cannot become two orders',
     strpos($pub, 'FROM web_orders WHERE client_uuid = ?') !== false);
$uniq = val("SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'web_orders'
               AND COLUMN_NAME = 'client_uuid' AND NON_UNIQUE = 0");
t_ok('...and the database enforces it', (int)$uniq === 1, "found $uniq");
t_ok('orders land in the same inbox the website\'s cart lands in',
     strpos($pub, 'INSERT INTO web_orders') !== false);

// A dealer token is NOT a staff token. It must never resolve to a user.
t_ok('a dealer token is stored against no user at all', strpos($pub, 'VALUES (0,?,?,?,NOW())') !== false);
$who = api_auth_user();   // no bearer token in CLI
t_ok('...and a token with user_id 0 resolves to nobody', $who === null);
t_ok('signing in again revokes the dealer\'s previous token',
     strpos($pub, "UPDATE api_tokens SET revoked = 1 WHERE label = ?") !== false);

t_group('App API — WhatsApp goes out as the shop, not as the phone');

t_ok('the app cannot send its own words, only name a bill',
     strpos($src, "\$res = sale_whatsapp_send(\$saleId") !== false);
t_ok('...and only for a bill it is allowed to see',
     (bool)preg_match("/sales.all.*sale\['created_by'\] != \\\$u\['id'\]/s", substr($src, strpos($src, "\$r === 'whatsapp'"))));
t_ok('a bill still on its way up is told to WAIT (409), not failed for good',
     (bool)preg_match("/has not reached the shop yet'\], 409\)/", $src));
$syncSrc = file_get_contents(__DIR__ . '/../mobile/www/sync.js');
t_ok('...and the app treats 409 as a retry, not as "needs attention"',
     strpos($syncSrc, 'res.status === 409') !== false);

// a reminder's amount is worked out from the ledger, never taken from a phone
$rem = substr($src, strpos($src, "\$r === 'reminder'"));
$rem = substr($rem, 0, 900);
t_ok('a reminder amount comes from the LEDGER, not from the phone',
     strpos($rem, 'party_balance($pid)') !== false && strpos($rem, "b['amount']") === false);
t_ok('...and nothing is sent when nothing is owed', strpos($rem, 'Nothing is owed by this party') !== false);
t_ok('...through the one rule the website also sends through',
     strpos($rem, 'collection_reminder_send(') !== false);

t_group('One rule: a bill on WhatsApp says the same thing everywhere');

$waSrc = file_get_contents(__DIR__ . '/../includes/whatsapp.php');
$svSrc = file_get_contents(__DIR__ . '/../sale_view.php');
t_ok('the message, the PDF and the pay link are decided in ONE place',
     strpos($waSrc, 'function sale_whatsapp_send(') !== false);
t_ok('the invoice screen sends through it', strpos($svSrc, 'sale_whatsapp_send(') !== false);
t_ok('...and no longer builds the message itself',
     strpos($svSrc, "wa_template('bill'") === false && strpos($svSrc, 'invoice_pdf(') === false);
t_ok('the app sends through it too', strpos($src, 'sale_whatsapp_send(') !== false);

t_group('Consent survives the English pass');

// The words a customer TYPES stay in their own language. This is not a
// translation question - failing to hear "બંધ" is ignoring a withdrawal of
// consent.
t_ok('a Gujarati customer replying stop is still understood', cam_is_stop_word('બંધ'));
t_ok('...and so is the two-word form', cam_is_stop_word('બંધ કરો'));
t_ok('...and Hindi', cam_is_stop_word('बंद'));
t_ok('English still works', cam_is_stop_word('STOP') && cam_is_stop_word('unsubscribe'));
t_ok('and an innocent word still does not stop anything', !cam_is_stop_word('non-stop music'));

$botSrc = file_get_contents(__DIR__ . '/../includes/wa_bot.php');
t_ok('the bot still recognises the Gujarati word for catalogue', strpos($botSrc, 'કેટલોગ') !== false);
t_ok('...and for the balance a customer asks about', strpos($botSrc, 'હિસાબ') !== false);
$portalSrc = file_get_contents(__DIR__ . '/../includes/wa_portal.php');
t_ok('the customer portal still recognises its own keywords', strpos($portalSrc, 'એકાઉન્ટ') !== false);
$langSrc = file_get_contents(__DIR__ . '/../includes/wa_lang.php');
t_ok('the en/gu/hi table for customers is untouched',
     strpos($langSrc, "'gu' =>") !== false && strpos($langSrc, "'hi' =>") !== false);
