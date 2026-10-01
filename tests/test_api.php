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

t_group('WhatsApp — a message is not "sent" because the API said 200');

// The shop wrote "Bill" to its own number, the bot answered, the Inbox showed
// the reply "via meta" - and the customer never got it. There was no way to
// find out why, because the one place that knows was being thrown away: Meta
// posts the fate of every message back to the same webhook, and this software
// acknowledged those receipts and discarded them. A message Meta accepted and
// then failed to deliver looked exactly like one the customer had read.
$hook = file_get_contents(__DIR__ . '/../wa_webhook.php');
t_ok('delivery receipts are no longer thrown away',
     strpos($hook, "if (empty(\$v['messages'][0])) die(json_encode(['ok' => true, 'status' => 'status-event']));") === false);
t_ok('the fate of each message is read off the receipt',
     strpos($hook, "\$v['statuses']") !== false);
t_ok('and matched to the message by the provider\'s own id',
     strpos($hook, 'WHERE msg_id = ?') !== false);
t_ok('a failure keeps the reason the provider gave',
     strpos($hook, 'fail_reason') !== false && strpos($hook, "\$st['errors'][0]") !== false);
t_ok('and a failure is recorded where the owner can find it later',
     strpos($hook, "log_activity('wa_send_failed'") !== false);
t_ok('a late "sent" receipt cannot un-read a message',
     strpos($hook, "FIELD(status, 'sent', 'delivered', 'read')") !== false);

// The id has to be kept at send time or there is nothing to match to.
$meta = file_get_contents(__DIR__ . '/../includes/wa_meta.php');
t_ok('the id is kept when a plain message is sent',
     strpos($meta, "_wa_last_msg_id'] = (string)(\$data['messages'][0]['id']") !== false);
// The menu and the bills list go out as INTERACTIVE messages, so those are
// exactly the ones a customer says they never received - and their id was
// not being kept at all, which made them the ones that could not be checked.
t_ok('and when a menu or a list goes out',
     substr_count($meta, "_wa_last_msg_id'] = (string)(\$data['messages'][0]['id']") >= 2);
foreach (['includes/wa_bot.php', 'includes/wa_portal.php'] as $f) {
    $src = file_get_contents(__DIR__ . '/../' . $f);
    $n = substr_count($src, "wa_chat_log(\$mobile, 'out'");
    t_eq($f . ' logs every interactive send with its id',
         substr_count($src, "\$GLOBALS['_wa_last_msg_id']"), $n);
}
t_ok('and when a template is sent outside the 24-hour window',
     strpos($meta, "_wa_last_msg_id'] = (string)(\$d2['messages'][0]['id']") !== false);
t_ok('it is cleared first, so one send cannot inherit the previous id',
     strpos($meta, "\$GLOBALS['_wa_last_msg_id'] = '';") !== false);
$wapp = file_get_contents(__DIR__ . '/../includes/whatsapp.php');
t_ok('and it lands on the chat row', strpos($wapp, "\$GLOBALS['_wa_last_msg_id'] ?? ''") !== false);
t_ok('an outgoing row starts as "sent", not as delivered',
     strpos($wapp, "\$dir === 'in' ? '' : 'sent'") !== false);

// It has to actually work against a real receipt, not just exist.
$num = '919054407533';
$mid = 'wamid.TEST' . bin2hex(random_bytes(6));
q("INSERT INTO wa_chats (mobile, direction, body, via, is_read, msg_id, status)
   VALUES (?, 'out', 'test reply', 'meta', 1, ?, 'sent')", [$num, $mid]);
$rowId = insert_id();
// what Meta actually posts when a message could not be delivered
$fail = ['id' => $mid, 'status' => 'failed', 'errors' => [[
    'code' => 131047, 'title' => 'Re-engagement message',
    'error_data' => ['details' => 'Message failed to send because more than 24 hours have passed.']]]];
$st = $fail;
$why = '[' . (int)$st['errors'][0]['code'] . '] '
     . trim($st['errors'][0]['title'] . ' — ' . $st['errors'][0]['error_data']['details'], " —\t\n");
q('UPDATE wa_chats SET status = ?, status_at = NOW(), fail_reason = ? WHERE msg_id = ?',
  [$st['status'], mb_substr($why, 0, 255), $mid]);
$got = row('SELECT * FROM wa_chats WHERE id = ?', [$rowId]);
t_eq('a failed receipt marks the message failed', $got['status'], 'failed');
t_ok('with words a person can act on', stripos($got['fail_reason'], '24 hours') !== false, $got['fail_reason']);
t_ok('and the error code, for looking up', strpos($got['fail_reason'], '131047') !== false);

$inbox = file_get_contents(__DIR__ . '/../wa_inbox.php');
t_ok('the Inbox says which of the four happened', strpos($inbox, 'function wa_status_mark') !== false);
t_ok('a failure is spelled out, not left as a tick',
     strpos($inbox, 'It did not reach them') !== false);
t_ok('and it shows the reason beside it', strpos($inbox, "fail_reason") !== false);

t_group('WhatsApp — Telegram is told what needs a person, not everything');

// "media / media / Bill" one after another, every one already answered by the
// bot. Alerts nobody needs are what teach a person to stop reading the alerts
// that matter.
t_ok('the ping happens after the bot has had its turn, not before',
     strpos($hook, '$notify((string)$status);') !== false
     && strpos($hook, '$notify = function') < strpos($hook, 'wa_bot_handle('));
t_ok('the default is only what nobody has answered',
     strpos($hook, "setting('wa_tg_notify', 'unanswered')") !== false);
t_ok('the outcomes that mean "no reply went out" are named, not guessed',
     strpos($hook, '$noReply = [') !== false && strpos($hook, "'unknown'") !== false);
t_ok('a menu tap never pings anybody - it is the bot talking to itself',
     strpos($hook, "\$waTapTitle !== ''") !== false);
t_ok('staff messaging the shop never pings either', strpos($hook, '$isStaffSender') !== false);
t_ok('the alert says whether anybody still has to do something',
     strpos($hook, 'Nobody has answered this') !== false);
$set = file_get_contents(__DIR__ . '/../settings.php');
t_ok('and it can be set to all, unanswered or off', strpos($set, "name=\"wa_tg_notify\"") !== false);
t_ok('with only those three accepted on save',
     strpos($set, "in_array(post('wa_tg_notify'), ['all', 'unanswered', 'off'], true)") !== false);

t_group('WhatsApp — a group message is never answered');

// The shop's number sits in a good many groups. Somebody posted a link in
// one, and the bot replied PRIVATELY to them - an unasked-for message to a
// stranger, sent in the shop's name.
//
// The old guard looked for "@g.us" in ONE field, whichever of half a dozen
// names happened to be filled first. Gateways differ: some put the group in
// "from" and the person in "participant", others put the PERSON in "from"
// and the group in "chatId". With that second shape the guard saw an
// ordinary mobile number and waved it through. That is the shape below.
t_ok('a group in "from" is a group',
     wa_is_group(['from' => '120363021234567890@g.us', 'participant' => '919825191744@s.whatsapp.net']));
t_ok('and so is a group in "chatId" with the PERSON in "from" — the one that leaked',
     wa_is_group(['from' => '919825191744@s.whatsapp.net', 'chatId' => '120363021234567890@g.us']));
t_ok('a named participant is proof by itself — a one-to-one chat has none',
     wa_is_group(['from' => '919825191744', 'participant' => '919825191744@s.whatsapp.net']));
t_ok('an author is the same thing under another name',
     wa_is_group(['from' => '919825191744', 'author' => '919825191744@s.whatsapp.net']));
t_ok('a gateway that simply says so is believed',
     wa_is_group(['from' => '919825191744', 'isGroup' => true]));
t_ok('and one that says so in words', wa_is_group(['from' => '919825191744', 'chat_type' => 'GroupChat']));
t_ok('a legacy group jid is a group',
     wa_is_group(['from' => '918888888888-1620000000@s.whatsapp.net']));
t_ok('a status or broadcast is not answered either',
     wa_is_group(['from' => 'status@broadcast']));

// And it must not swallow real customers.
t_ok('an ordinary customer still gets through',
     !wa_is_group(['from' => '919054407533@s.whatsapp.net', 'body' => 'Bill']));
t_ok('including one on the official Meta shape',
     !wa_is_group(['from' => '919054407533', 'type' => 'text', 'text' => ['body' => 'Bill']]));
// 'conversation' is the message TEXT in several gateways, so a rule that
// looked for an @ and a dash anywhere would have dropped this customer.
t_ok('a customer writing an email address with a dash is not a group',
     !wa_is_group(['from' => '919054407533@s.whatsapp.net',
                   'conversation' => 'mail me at raj@gmail.com - thanks']));
t_ok('nor is a phone number with dashes in the body',
     !wa_is_group(['from' => '919054407533@s.whatsapp.net', 'body' => 'call 98765-43210']));

$hook = file_get_contents(__DIR__ . '/../wa_webhook.php');
t_ok('the webhook asks that one question instead of checking a field itself',
     strpos($hook, 'wa_is_group($p)') !== false);
t_ok('the check happens before anything is logged or answered',
     strpos($hook, 'wa_is_group($p)') < strpos($hook, 'wa_chat_log($mobile'));
t_ok('and a skipped group is recorded, so it can be seen to be working',
     strpos($hook, "log_activity('wa_group_skip'") !== false);
t_ok('the rule is written once', substr_count(file_get_contents(__DIR__ . '/../includes/whatsapp.php'),
                                              'function wa_is_group') === 1);

require_once __DIR__ . '/../includes/wa_bot.php';   // wa_is_trigger(), wa_portal_want()

t_group('WhatsApp — a customer writing hello in their own language is answered');

// The trigger list HAD નમસ્તે, હાય, મેનુ and the rest in it. Not one of them
// could ever match, because the tidy-up in front of it stripped everything
// that was not a letter or a number - and in every Indic script the vowel
// signs are combining MARKS, not letters. નમસ્તે arrived as નમસત, હાય as હય,
// મેનુ as મન. So "hi" worked and "હાય" got silence, which is the wrong way
// round for a shop in Dwarka.
foreach (['હાય', 'નમસ્તે', 'નમસ્કાર', 'હેલો', 'મેનુ', 'મેન્યુ',
          'नमस्ते', 'नमस्कार', 'मेनू', 'मेन्यू', 'જય શ્રી કૃષ્ણ'] as $w) {
    t_ok('"' . $w . '" is answered', wa_is_trigger($w));
}
t_ok('and English still is too', wa_is_trigger('hi') && wa_is_trigger('Hello') && wa_is_trigger('MENU'));
t_ok('punctuation and emoji are still tidied away',
     wa_is_trigger('Hi!') && wa_is_trigger('hello 🙏') && wa_is_trigger('નમસ્તે.'));
t_ok('but a real sentence is not mistaken for a greeting',
     !wa_is_trigger('હાય ભાવ શું છે') && !wa_is_trigger('hello do you have a printer'));
t_ok('every word in the list can actually be reached',
     count(array_filter(wa_kw_list(), fn($k) => !wa_is_trigger($k))) === 0,
     implode(', ', array_filter(wa_kw_list(), fn($k) => !wa_is_trigger($k))));

t_group('WhatsApp — what the shop tells the customer to type, works');

// The statement message ends "Type 'bill' for bill PDFs · Type 'pay' to pay".
// An instruction the shop gives and the bot does not honour is worse than no
// instruction at all.
$says = [
    'bill' => 'portal:bills', 'Bill' => 'portal:bills', 'BILL' => 'portal:bills',
    'bills' => 'portal:bills', 'બિલ' => 'portal:bills', 'invoice' => 'portal:bills',
    'pay' => 'portal:pay', 'Pay' => 'portal:pay', 'payment' => 'portal:pay', 'પેમેન્ટ' => 'portal:pay',
    'statement' => 'portal:stmt', 'હિસાબ' => 'portal:stmt', 'બાકી' => 'portal:stmt',
    'account' => 'portal:menu', 'ખાતું' => 'portal:menu',
];
foreach ($says as $typed => $want)
    t_eq('typing "' . $typed . '" reaches ' . $want, wa_portal_want(mb_strtolower(trim($typed))), $want);
t_ok('a product name is NOT swallowed by those keywords',
     wa_portal_want('printer') === null && wa_portal_want('mouse') === null);

$inbox = file_get_contents(__DIR__ . '/../wa_inbox.php');
t_ok('the Inbox says what the bot made of each message',
     strpos($inbox, 'function wa_bot_decision') !== false);
t_ok('including when it sent nothing at all',
     strpos($inbox, 'The bot sent no reply to this') !== false);
t_ok('and when it tried and the send failed',
     strpos($inbox, 'the send failed') !== false);

t_group('WhatsApp — a tick means it went, not that the bot wrote one');

// "Bot replied? ✅" against four messages, and the customer's phone empty.
// send_whatsapp() has always returned whether the message actually left, and
// the bot threw that answer away at every single call site - so the one
// screen the owner would check to see whether things were working said they
// were.
$bot = file_get_contents(__DIR__ . '/../includes/wa_bot.php');
t_ok('there is one place that writes the bot log', strpos($bot, 'function wa_bot_log_it') !== false);
t_eq('and nothing writes that table by hand any more',
     substr_count($bot, 'INSERT INTO wa_bot_log'), 1);
t_ok('it records whether the send worked', strpos($bot, '$snt = wa_last_send();') !== false);
// Compared against the CALL, not the function's own definition further up
// the file - which is what the first draft of this test measured.
$sendAt = strpos($bot, '$ok = send_whatsapp($mobile, $reply);');
$logAt  = strpos($bot, 'wa_bot_log_it($mobile, $text, $jpeg, $reply', $sendAt);
t_ok('the reply is sent BEFORE it is written down — a row written first can only claim success',
     $sendAt !== false && $logAt !== false && $sendAt < $logAt);
t_ok('a failed send is reported as one, not as a reply',
     strpos($bot, "if (!\$ok) return 'send-failed';") !== false);
t_ok('the admin auto-reply sends first too',
     strpos($bot, '$arOk = send_whatsapp($mobile, $ar);') !== false);

$wapp = file_get_contents(__DIR__ . '/../includes/whatsapp.php');
t_ok('every send ends in one honest answer', strpos($wapp, 'function wa_mark_send') !== false
     && strpos($wapp, 'wa_mark_send(true)') !== false && strpos($wapp, 'wa_mark_send(false,') !== false);
t_ok('interactive sends mark themselves too — the menu and the bills list go that way',
     strpos(file_get_contents(__DIR__ . '/../includes/wa_meta.php'), 'wa_mark_send($ok,') !== false);

$set = file_get_contents(__DIR__ . '/../settings.php');
t_ok('the table shows a cross when it did not go', strpos($set, '❌ NOT sent') !== false);
t_ok('with the provider\'s own reason beside it', strpos($set, "\$b['send_error']") !== false);
t_ok('and rows from before this was tracked are not claimed as successes',
     strpos($set, "(\$b['sent'] ?? null) === null") !== false);

// It has to actually record the failure, not merely be able to.
wa_mark_send(false, 'Gateway: not configured');
$m9 = '919999900001';
q('DELETE FROM wa_bot_log WHERE mobile = ?', [$m9]);
wa_bot_log_it($m9, 'Bill', null, 'the bills list', 0, 0, 'customer');
$lg = row('SELECT * FROM wa_bot_log WHERE mobile = ? ORDER BY id DESC LIMIT 1', [$m9]);
t_eq('a failed send is written down as failed', (int)$lg['sent'], 0);
t_ok('with the reason', stripos($lg['send_error'], 'not configured') !== false, (string)$lg['send_error']);
wa_mark_send(true);
wa_bot_log_it($m9, 'Hi', null, 'the menu', 0, 0, 'customer');
$lg2 = row('SELECT * FROM wa_bot_log WHERE mobile = ? ORDER BY id DESC LIMIT 1', [$m9]);
t_eq('and one that worked as worked', (int)$lg2['sent'], 1);
wa_bot_log_it($m9, 'xyz', null, null, 0, 0, 'customer');
$lg3 = row('SELECT * FROM wa_bot_log WHERE mobile = ? ORDER BY id DESC LIMIT 1', [$m9]);
t_eq('a message the bot chose not to answer is neither', $lg3['sent'], null);
q('DELETE FROM wa_bot_log WHERE mobile = ?', [$m9]);

// And a failure has to reach the owner, not sit in a table.
$hook = file_get_contents(__DIR__ . '/../wa_webhook.php');
t_ok('a failure hidden inside the status still counts as unanswered',
     strpos($hook, "stripos(\$botStatus, 'failed') !== false") !== false);
t_ok('and the alert says so in as many words',
     strpos($hook, 'FAILED to send') !== false);

t_group('WhatsApp — answer on the number they wrote to');

// The shop has two WhatsApp numbers: the official Meta one and the
// gateway's. The reply went out by a fixed preference in Settings that had
// nothing to do with which number the message arrived on - so a customer who
// wrote to the Meta number was answered from the gateway's number: a
// different chat, on a different number, which from their side is
// indistinguishable from no answer at all.
$wapp = file_get_contents(__DIR__ . '/../includes/whatsapp.php');
t_ok('there is a way to say which way a reply must go', strpos($wapp, 'function wa_reply_via') !== false);
t_ok('and the order for one send is worked out in one place',
     strpos($wapp, 'function wa_providers') !== false
     && strpos($wapp, '$order = wa_providers();') !== false);
t_ok('the other provider stays as the fallback it always was',
     strpos($wapp, "\$answer === 'meta' ? 'thirdparty' : 'meta'") !== false);

$hook = file_get_contents(__DIR__ . '/../wa_webhook.php');
t_ok('the webhook works out which number it came to',
     strpos($hook, "\$inVia = 'meta'") !== false);
t_ok('and says so before the bot answers anything',
     strpos($hook, 'wa_reply_via($inVia') < strpos($hook, 'wa_bot_handle('));
t_ok('the incoming row remembers it too, so the Inbox shows both sides',
     strpos($hook, "\$inVia ?: 'whatsapp'") !== false);

// It must actually change the order, and only for a reply.
wa_reply_via('');
$fixed = setting('wa_provider_order', 'thirdparty_first');
t_ok('with nothing set, the shop\'s own order stands',
     (string)($GLOBALS['_wa_reply_via'] ?? '') === '');
wa_reply_via('meta');
t_eq('answering a Meta message puts Meta first', $GLOBALS['_wa_reply_via'], 'meta');
wa_reply_via('thirdparty');
t_eq('and a gateway message puts the gateway first', $GLOBALS['_wa_reply_via'], 'thirdparty');
wa_reply_via('nonsense');
t_eq('anything else is ignored rather than trusted', $GLOBALS['_wa_reply_via'], '');

// A bill, a reminder or a campaign is not a reply to anything, so it must
// keep following the setting.
t_ok('a message the shop starts has no conversation to answer',
     strpos($wapp, 'has no') !== false || strpos($wapp, 'of its own accord') !== false);

t_group('WhatsApp — the trigger list says what it is actually doing');

// The keyword box REPLACES the list rather than adding to it, so a shop that
// typed "Hi" into it was silently ignoring "Hey", "મેનુ" and "નમસ્તે" - and
// nothing on the screen said so.
$was = setting('wa_bot_keywords');
set_setting('wa_bot_keywords', 'Hi');
t_eq('one word in the box means one word works', count(wa_kw_list()), 1);
t_ok('so another perfectly ordinary greeting is ignored', !wa_is_trigger('Hey'));
t_ok('and so is every Gujarati one', !wa_is_trigger('નમસ્તે'));
set_setting('wa_bot_keywords', '');
t_ok('emptying the box brings all of them back', count(wa_kw_list()) > 20
     && wa_is_trigger('Hey') && wa_is_trigger('નમસ્તે'));
set_setting('wa_bot_keywords', $was);
$set = file_get_contents(__DIR__ . '/../settings.php');
t_ok('the screen shows which words are working right now', strpos($set, 'Working now:') !== false);
t_ok('and warns when the list has been cut down to almost nothing',
     strpos($set, 'replaces</strong> the list') !== false);
t_ok('the two-numbers trap is spelled out where both numbers are shown',
     strpos($set, 'This shop has two WhatsApp numbers') !== false);

t_group('WhatsApp — a custom keyword list says what it left out');

// The owner replaced the list with seven words of their own and "hi" was not
// among them - far and away the most common thing a customer types. Nothing
// said so: those customers got the "type menu" nudge instead of the menu,
// once every ten minutes, which reads as the bot half-working.
$wasK = setting('wa_bot_keywords');
set_setting('wa_bot_keywords', '');
t_eq('with the built-in list, nothing is missing', wa_kw_missing_common(), []);
set_setting('wa_bot_keywords', 'Hey, Hello, Menu, નમસ્તે, હાય, મેનુ, જય શ્રી કૃષ્ણ');
$gone = wa_kw_missing_common();
t_ok('a custom list that drops "hi" says so', in_array('hi', $gone, true), implode(', ', $gone));
t_ok('and names the Hindi ones it dropped too', in_array('नमस्ते', $gone, true));
t_ok('while the words it DOES have are not complained about',
     !in_array('hello', $gone, true) && !in_array('menu', $gone, true)
     && !in_array('નમસ્તે', $gone, true));
t_ok('and those words really do open the menu',
     wa_is_trigger('Hey') && wa_is_trigger('મેનુ') && wa_is_trigger('નમસ્તે'));
t_ok('while the missing one really does not', !wa_is_trigger('hi'));
set_setting('wa_bot_keywords', 'hi,hello,menu,start,namaste,નમસ્તે,હાય,મેનુ,नमस्ते,मेनू');
t_eq('a custom list that covers them all is left alone', wa_kw_missing_common(), []);
set_setting('wa_bot_keywords', $wasK);

$set = file_get_contents(__DIR__ . '/../settings.php');
t_ok('the screen names them', strpos($set, 'These common greetings are NOT in your list') !== false);
t_ok('and says what to do about it', strpos($set, 'Empty the box') !== false);

t_group('WhatsApp — one page, one choice at the top of it');

// Two providers were always live together and which one a message left by
// came from a priority buried under a page of other boxes. It is the first
// thing on the page now, and a provider the shop did not pick is never used
// however completely its keys happen to be filled in.
$wasMode = setting('wa_provider_mode'); $wasOrd = setting('wa_provider_order');
$GLOBALS['_wa_reply_via'] = '';

set_setting('wa_provider_mode', 'thirdparty');
t_eq('picking my own gateway means only that is tried', wa_providers(), ['thirdparty']);
t_ok('and Meta is not used at all', !in_array('meta', wa_providers(), true));
wa_reply_via('meta');
t_eq('not even to answer a message that came in on Meta', wa_providers(), ['thirdparty']);
$GLOBALS['_wa_reply_via'] = '';

set_setting('wa_provider_mode', 'meta');
t_eq('picking Meta means only Meta is tried', wa_providers(), ['meta']);
t_ok('wa_uses() answers for one provider at a time',
     wa_uses('meta') && !wa_uses('thirdparty'));

set_setting('wa_provider_mode', 'both');
set_setting('wa_provider_order', 'thirdparty_first');
t_eq('picking both follows the stated order for what the shop starts',
     wa_providers(), ['thirdparty', 'meta']);
set_setting('wa_provider_order', 'meta_first');
t_eq('and the other way round when that is chosen', wa_providers(), ['meta', 'thirdparty']);
wa_reply_via('thirdparty');
t_eq('but a reply still goes back the way it came', wa_providers(), ['thirdparty', 'meta']);
$GLOBALS['_wa_reply_via'] = '';
t_ok('and both count as in use', wa_uses('meta') && wa_uses('thirdparty'));

// An upgrade must change nothing for a shop that never touches the setting.
set_setting('wa_provider_mode', '');
t_ok('with no choice stored it falls back to whatever is configured',
     in_array(wa_provider_mode(), ['thirdparty', 'meta', 'both'], true));
set_setting('wa_provider_mode', $wasMode); set_setting('wa_provider_order', $wasOrd);

$set = file_get_contents(__DIR__ . '/../settings.php');
t_ok('the choice is a dropdown', strpos($set, 'name="wa_provider_mode"') !== false);
t_ok('it comes before either provider\'s setup',
     strpos($set, 'name="wa_provider_mode"') < strpos($set, 'name="wa_api_url"')
     && strpos($set, 'name="wa_provider_mode"') < strpos($set, 'name="meta_wa_token"'));
t_ok('each setup only draws when that one is picked',
     strpos($set, "if (wa_uses('thirdparty')): ?>") !== false
     && strpos($set, "if (wa_uses('meta')): ?>") !== false);
t_ok('the which-comes-first question only appears when both are picked',
     strpos($set, "if (\$waMode === 'both'): ?>") !== false);
t_ok('the choice has a save of its own, not one buried in a provider\'s box',
     strpos($set, "post('do') === 'save_wa_mode'") !== false);
t_ok('and only the three real answers are accepted',
     strpos($set, "in_array(post('wa_provider_mode'), ['thirdparty', 'meta', 'both'], true)") !== false);

t_ok('the address customers reach is written once, not twice',
     substr_count($set, "base_url('wa_webhook.php?key=") === 1);
t_ok('there is a place that says how to check it works', strpos($set, 'Is it working?') !== false);
t_ok('with the usual causes written down rather than asked about',
     strpos($set, 'the usual causes') !== false);
foreach (['131047', 'replaces</strong>', 'catalog_management', 'logged out'] as $why)
    t_ok('troubleshooting covers ' . $why, strpos($set, $why) !== false);
t_ok('the reference tables are folded away, not deleted',
     substr_count($set, '<details') >= 3 && strpos($set, 'Message Templates') !== false
     && strpos($set, 'What this is costing you') !== false);

t_group('Backup & Update — what you do most, at the top');

// The page opened with a download button, an encryption box and a decrypt
// form, and the Update button - pressed several times a week - was below all
// three. Worse, the one fact that matters most on a backup page, whether the
// backup actually HAPPENED, was printed nowhere at all.
$set = file_get_contents(__DIR__ . '/../settings.php');
$bk = substr($set, strpos($set, "cat === 'backup'"), strpos($set, "cat === 'about'") - strpos($set, "cat === 'backup'"));

t_ok('the page says when the last backup was taken', strpos($bk, 'Last backup') !== false);
t_ok('and shouts when there has not been one',
     strpos($bk, 'No backup has ever been taken') !== false);
t_ok('it points at the cron, which is what actually stopped',
     strpos($bk, 'cron_manager.php') !== false);
t_ok('and says whether the backup is locked, since only a locked one is ever sent',
     strpos($bk, 'Backup locked') !== false);

// Order: update first, undo second, backup third, errors fourth, the
// set-once things last.
$pos = fn($needle) => strpos($bk, $needle);
t_ok('updating comes first — it is what gets pressed most',
     $pos('<h3>Update the software</h3>') < $pos('<h3>Backup</h3>'));
t_ok('undoing an update sits right beside updating',
     $pos('<h3>Update the software</h3>') < $pos('<h3>Undo the last update</h3>')
     && $pos('<h3>Undo the last update</h3>') < $pos('<h3>Backup</h3>'));
// The heading, not the sentence higher up that points down at it.
t_ok('the set-once things come after the everyday ones',
     $pos('<h3>Where the code comes from — set once</h3>') > $pos('<h3>Backup</h3>'));
t_ok('so does opening an encrypted file, which is done about once ever',
     $pos('<h3>Open an encrypted backup file</h3>') > $pos('<h3>Backup</h3>'));
t_ok('and the update history, which is folded away',
     $pos('Update history') > $pos('<h3>Backup</h3>') && strpos($bk, '<details') !== false);
t_ok('each section is one of the reusable coloured cards',
     substr_count($bk, 'class="sect s-') >= 6);
t_ok('the page names itself and where it sits',
     strpos($bk, 'pg-crumb') !== false && strpos($bk, 'Update &amp; Backup</h1>') !== false);
t_ok('the four facts are one strip, not four cards taking a screen each',
     strpos($bk, 'fact-row') !== false && substr_count($bk, 'class="fact ') >= 3);
t_ok('it says to press Migrate after an update that needs it', strpos($bk, 'migrate.php') !== false);
t_ok('troubleshooting is written in rather than asked about',
     strpos($bk, 'The usual causes') !== false);
foreach (['Migrate', 'cron', 'passphrase'] as $why)
    t_ok('the causes cover ' . $why, stripos($bk, $why) !== false);
t_ok('nothing on the page was dropped: taking a backup by hand is still there',
     strpos($bk, "value=\"backup\"") !== false);
t_ok('so is the daily-backup setting', strpos($bk, 'save_backup_auto') !== false);
t_ok('so is rolling an update back', strpos($bk, 'gh_rollback') !== false);
t_ok('and decrypting a file', strpos($bk, 'backup_decrypt') !== false);

t_group('A warning on every cheque is a log nobody reads');

// cheque_add() read its OPTIONAL fields with no default, so the ordinary case
// - a counter cheque with no bank account picked - wrote a PHP warning every
// time. The cheque saved correctly, so nobody noticed until the error log was
// 159 lines of the same line. A log that full is a log nobody reads when
// something real goes wrong.
$h = file_get_contents(__DIR__ . '/../includes/helpers.php');
$fn = substr($h, strpos($h, 'function cheque_add'), 1400);
foreach (['party_id', 'payment_id', 'bank_account_id', 'cheque_date'] as $k)
    t_ok($k . ' has a default', strpos($fn, "\$c['" . $k . "'] ?? null") !== false);
t_ok('but a cheque with no number is still a mistake worth hearing about',
     strpos($fn, "trim((string)\$c['cheque_no'])") !== false);
t_ok('and one with no amount too', strpos($fn, "(float)\$c['amount']") !== false);

// It has to actually save, with the fields left out.
$cid = cheque_add(['cheque_no' => 'TEST-' . bin2hex(random_bytes(3)), 'amount' => 500]);
t_ok('a cheque saves with only a number and an amount', $cid > 0);
$row = row('SELECT * FROM cheques WHERE id = ?', [$cid]);
t_eq('and the fields left out are empty, not wrong', $row['bank_account_id'], null);
t_eq('the direction defaults to money coming in', $row['direction'], 'in');
t_eq('and it starts on hand', $row['status'], 'in_hand');
t_eq('with today as its date', substr((string)$row['cheque_date'], 0, 10), today());
q('DELETE FROM cheques WHERE id = ?', [$cid]);

t_group('Settings opens by finding, not by scrolling');

$set = file_get_contents(__DIR__ . '/../settings.php');

// Every category the landing page offers must have a screen behind it. A
// redesign that renames a key leaves a card that opens nothing, and the
// person who finds that is the owner, on a Sunday.
preg_match('/\$categories = \[(.*?)\n\];/s', $set, $cm);
t_ok('the category table is still one list', !empty($cm[1]));
preg_match_all("/^    '([a-z]+)'\s*=>\s*\['/m", $cm[1] ?? '', $km);
$keys = $km[1] ?? [];
t_eq('all fourteen categories are still there', count($keys), 14);
foreach (['general','transaction','whatsapp','store','invoice','reminders','party',
          'accounting','service','inventory','ai','security','backup','about'] as $k) {
    t_ok("'$k' is still offered", in_array($k, $keys, true));
    t_ok("...and '$k' still has a screen behind it", strpos($set, "\$cat === '$k'") !== false);
}

// The search is the whole point of the redesign: a person hunting for GST
// does not know it lives under General and should not have to.
t_ok('every category carries the settings inside it, for the search to read',
     substr_count($cm[1] ?? '', '|') >= 40);
t_ok('the card puts that list where the browser can search it',
     strpos($set, 'data-keys="<?= e($c[3]) ?>"') !== false);
t_ok('...and says WHICH setting matched, not just which box it is in',
     strpos($set, "class=\"cat-hit\"") !== false && strpos($set, 'hit.appendChild(s)') !== false);
// Fourteen cards is not a database question. No request means nothing to
// wait for and no debounce to get wrong.
t_ok('searching never leaves the browser',
     strpos($set, "box.addEventListener('input', run)") !== false
     && strpos(substr($set, strpos($set, 'function run()')), 'fetch(') === false);
t_ok('a ?q= link lands already filtered', strpos($set, "URLSearchParams(location.search).get('q')") !== false);
t_ok('and it says so when nothing matches', strpos($set, 'setFindNone') !== false);

// A badge is only drawn where the page can actually ANSWER the question it
// asks. Each of these is a real reading, not a decoration.
foreach ([
    'whatsapp'   => 'meta_wa_configured() || wa_thirdparty_configured()',
    'backup'     => "glob(__DIR__ . '/uploads/backups/backup_*')",
    'ai'         => 'ai_limits();',
    'accounting' => "setting('period_lock_date', '')",
    'security'   => "setting('auto_logout_minutes', 0)",
    'reminders'  => "setting('cron_last_tick', '')",
] as $what => $src)
    t_ok("the $what badge reads a real fact", strpos($set, $src) !== false);
t_ok('...and the ones with no fact to show get no badge',
     strpos($set, "\$st = \$catState[\$key] ?? null;") !== false
     && strpos($set, 'if ($st): ?><span class="cat-badge') !== false);

// This screen is opened constantly and must not care how big the shop gets.
t_ok('nothing on the landing page counts bills, parties or stock',
     preg_match('/\$catState = \[.*?\];/s', $set, $csm)
     && strpos($csm[0], 'FROM sales') === false && strpos($csm[0], 'FROM parties') === false
     && strpos($csm[0], 'all(') === false);

$css = file_get_contents(__DIR__ . '/../assets/style.css');
t_ok('the cards are a reusable layer, not one page\'s styling',
     strpos($css, 'Settings landing: find it, see its state, open it') !== false);
t_ok('and a thumb can hit the shortcuts on a phone',
     strpos($css, '@media (max-width: 1023px) { .cat-quick a { min-height: 44px;') !== false);

t_group('a colour token that was never declared takes its border with it');

// --line was used by eleven rules and declared by none. A var() that resolves
// to nothing does not fall back to something sensible: it makes the whole
// declaration invalid, so "border-bottom: 1px solid var(--line)" became no
// border at all. Every pane header, every row separator, every card outline
// on a phone and the coloured edge of every figure was silently missing, and
// nothing anywhere said so - the screens still looked plausible.
//
// So: every token this stylesheet asks for without a fallback has to exist.
$cssSrc = file_get_contents(__DIR__ . '/../assets/style.css');
preg_match_all('/var\(\s*(--[a-z0-9-]+)\s*\)/i', $cssSrc, $used);      // no fallback
// declarations can sit mid-line too - .inv-bill puts a dozen on one row
preg_match_all('/(--[a-z0-9-]+)\s*:/i', $cssSrc, $declared);
$declaredSet = array_unique($declared[1]);
$missing = array_values(array_unique(array_diff(array_unique($used[1]), $declaredSet)));
t_ok('every token used without a fallback is declared somewhere',
     !$missing, 'undeclared: ' . implode(', ', $missing));
t_ok('--line itself is declared', in_array('--line', $declaredSet, true));
// ...and in the dark theme too, or half the app loses its lines at night.
$darkBlocks = substr_count($cssSrc, '--line: #28334a;');
t_ok('...including both dark-mode blocks', $darkBlocks === 2, "found $darkBlocks");

t_group('the Dashboard opens with the shop, not with navigation');

$idx = file_get_contents(__DIR__ . '/../index.php');
// The page used to open with four big tiles - Sale List, Purchase List, Stock
// Items, Parties - and a pile of chips. On a phone that was the whole first
// screen: an owner had to scroll past four navigation buttons to find out
// whether anything had been sold. Navigation is what the sidebar is for.
// The owner then went further: the home screen is SIX things and the rest is
// folded. So the day's summary moved into the fold with everything else, and
// the four old navigation tiles became the home screen itself - rebuilt as
// .hm-tile, with the cramped .tile-grid gone.
t_ok('the day\'s summary is still shown, inside the fold',
     strpos($idx, '<?php if ($sSummary): ?><div class="pg-sub">') !== false);
t_ok('the old cramped tile grid is gone', strpos($idx, '<div class="tile-grid">') === false);
t_ok('the three things pressed all day are tiles of their own',
     substr_count($idx, 'class="qa ') >= 3
     && strpos($idx, 'sales.php?action=new"><span class="qa-i">🧾') !== false);
// Two date boxes and a Show button are four rows on a phone and are wanted
// about once a month.
t_ok('the custom date range folds away', strpos($idx, 'class="dash-custom"') !== false);
t_ok('...and opens by itself when a custom range IS the one showing',
     strpos($idx, "\$rangeKey === 'custom' ? ' open' : ''") !== false);
t_ok('the branch filter and the range are one form, one submit',
     substr_count($idx, '<form method="get" class="dash-filter no-print">') === 1);

// Nothing about what the dashboard COUNTS changed.
foreach (['dash_kpis($dFrom, $dTo, $dashOwner, $dashLoc)' => 'the period figures',
          'dash_actions($dashCtx)' => 'what to do today',
          'dash_alerts($dashCtx)'  => 'worth watching',
          'dash_summary($dashCtx)' => 'the plain-words summary',
          '$topOrder as $_w'       => 'the customisable widget order',
          'dashboard_customize.php'=> 'the customise screen',
          'user_pref($u[\'id\'], \'dashboard_widgets\', \'\')' => 'the saved widget preference'] as $needle => $what)
    t_ok($what . ' is untouched', strpos($idx, $needle) !== false);
t_ok('a staff member without the permission still sees no money',
     strpos($idx, 'if ($seeMoney)') !== false && strpos($idx, 'if ($seeProfit)') !== false);

// One figure, one component, wherever it appears.
$dashInc = file_get_contents(__DIR__ . '/../includes/dashboard.php');
t_ok('the dashboard figures use the shared kpi names',
     strpos($dashInc, '<div class="kpi-top">') !== false
     && strpos($dashInc, '<div class="kpi-val">') !== false
     && strpos($dashInc, 'kpi-sub ') !== false);
t_ok('...and the two .kpi definitions were merged into one',
     substr_count($cssSrc = file_get_contents(__DIR__ . '/../assets/style.css'), '.kpi .kpi-label') === 0);

// Copy that a person has to decode is copy that does not get read.
t_ok('the alerts read as English',
     strpos($dashInc, "'Sales are ' . abs(\$d) . '% down on '") !== false
     && strpos($dashInc, "' in the Data Health Check'") !== false);

// A wide table inside a narrow column takes the whole page sideways with it,
// and the error log only appears on the days something has gone wrong - which
// is exactly when the page is being read.
t_ok('the error log cannot push the page sideways',
     strpos($cssSrc, '.logtbl { width: 100%; min-width: 0; table-layout: fixed;') !== false);

t_group('the home screen is six things');

$idxH = file_get_contents(__DIR__ . '/../index.php');
// The owner asked for this in so many words: how much is to be collected,
// how much is to be paid, and the four lists he opens all day.
t_ok('to receive and to pay, from the ledger',
     strpos($idxH, 'class="hm-card hm-get"') !== false
     && strpos($idxH, "money(\$sBals['receivable'])") !== false
     && strpos($idxH, "money(\$sBals['payable'])") !== false);
t_ok('...and only to somebody allowed to see money',
     strpos($idxH, '<?php if ($seeMoney && $sBals): ?>') !== false);
foreach (['sales.php' => 'the sale list', 'purchases.php' => 'the purchase list',
          'items.php' => 'stock items', 'parties.php' => 'parties'] as $href => $what)
    t_ok($what . ' is one of the four tiles',
         preg_match('/class="hm-tile [^"]*" href="' . preg_quote($href, '/') . '"/', $idxH) === 1);
t_ok('each tile still asks whether this staff member may open it',
     substr_count($idxH, "<?php if (can('sales.view')): ?>\n  <a class=\"hm-tile") === 1
     && strpos($idxH, "<?php if (can('parties.view')): ?>\n  <a class=\"hm-tile") !== false);

// NOTHING WAS DELETED. Everything the dashboard works out is still worked
// out and still on the page - one tap away instead of in the way.
t_ok('everything else is folded, not removed',
     strpos($idxH, '<details class="more-opts no-print hm-more">') !== false
     && strpos($idxH, '<?php endif; endforeach; ?>' . "\n" . '</div>' . "\n" . '</details>') !== false);
foreach (['$topOrder as $_w' => 'every widget', '$sActions' => 'what to do today',
          '$sAlerts' => 'worth watching', 'dashboard_customize.php' => 'the customise screen',
          'class="dash-filter no-print"' => 'the date and branch filter'] as $needle => $what)
    t_ok($what . ' is still on the page', strpos($idxH, $needle) !== false);
// A handover waiting for an OTP needs answering, not reading, so it stays on
// top - and it only draws when there IS one.
t_ok('an OTP handover still shows above the fold',
     strpos($idxH, 'stock handover(s) pending') !== false
     && strpos($idxH, '<?php if ($myHandovers): ?>') !== false);

// Both themes, one set of rules.
$cssH = file_get_contents(__DIR__ . '/../assets/style.css');
t_ok('the home cards are drawn from tokens, so the dark theme follows',
     strpos($cssH, '--hm-solid: var(--ok);') !== false
     && strpos($cssH, '--hm-solid: var(--bad);') !== false
     && strpos($cssH, 'background: color-mix(in srgb, var(--ok) 11%, var(--card));') !== false);
t_ok('and the tiles too', strpos($cssH, 'HOME SCREEN — the six things') !== false);

// This used to check that the figure on these cards shrank to fit rather
// than being clipped to a wrong one. There is no figure to clip any more -
// the owner had it taken off, because a phone on the counter was showing
// everyone in the shop what it was owed. So the check that replaces it is
// the one that now matters: the cards must not grow a figure back.
t_ok('the two cards carry no amount at all',
     strpos($cssH, '.hm-card .hm-v') === false);
t_ok('...and on the narrowest phone the layout still gives way rather than squeezing',
     strpos($cssH, '@media (max-width: 359px) {' . "\n" . '  .hm-card { flex-direction: column;') !== false);

// The bar along the bottom names the same six as the screen above it.
$ftr = file_get_contents(__DIR__ . '/../includes/footer.php');
foreach (['To Receive', 'To Pay', 'Sale list', 'Purchase', 'Stock Items', 'Parties'] as $lbl)
    t_ok('"' . $lbl . '" is in the bottom bar', strpos($ftr, '</span>' . $lbl . '</a>') !== false);
t_ok('the money entries in it need the money permission',
     strpos($ftr, "<?php if (can('payments.view')): ?>\n    <a href=\"parties.php?bal=get\"") !== false);
t_ok('and a staff member without it still gets a full bar',
     strpos($ftr, "<a href=\"index.php\" class=\"<?= \$_navCur2 === 'index.php'") !== false
     && strpos($ftr, 'handover.php') !== false);
t_ok('the round + is gone from the middle, as the drawing has it',
     strpos($ftr, 'fab-center') === false);

t_group('every wide table becomes cards on a phone, without 43 edits');
// An audit of all 84 screens at 390px found ONE defect in 43 of them: the
// shared table rule carries min-width 550px, so any table of three columns
// or more is dragged sideways inside its own box, and at seven to nine most
// of the row is never seen. Every one of those tables had a real <thead>,
// so the labels a card needs were already in the markup.
$appT = file_get_contents(__DIR__ . '/../assets/app.js');
$cssT = file_get_contents(__DIR__ . '/../assets/style.css');
t_ok('the rule has one home, not forty-three', strpos($appT, 'var Tables = {') !== false);
t_ok('it reads the labels from the header the page already wrote',
     strpos($appT, "t.querySelectorAll('thead tr')") !== false);
t_ok('a two-tier header is read from its LOWER row, which names the columns',
     strpos($appT, 'headRows[headRows.length - 1]') !== false);
t_ok('a header cell spanning two columns labels both',
     strpos($appT, 'while (span--) labels.push(text);') !== false);
// Three, not four. A three-column table looks narrow and is not.
t_ok('three columns is already too wide at 390px', strpos($appT, 'if (labels.length < 3)') !== false);
// What it must never touch.
t_ok('a hand-designed phone screen is left alone', strpos($appT, 'rowlist|') !== false);
t_ok('an invoice keeps its columns - they ARE the document',
     strpos($appT, 'inv-table') !== false && strpos($appT, 'inv2-table') !== false);
t_ok('there is a deliberate way out', strpos($appT, 'no-cards') !== false);
t_ok('a table with no header is left alone - that is a layout grid, not a list',
     strpos($appT, "if (!headRows.length)") !== false);
t_ok('it never does the same table twice', strpos($appT, "t.dataset.cards === '1'") !== false);

// Only ONE figure leads the card. Items has four - cost, retail, B2B, stock -
// and floating them all right produced a column of bare numbers naming none.
t_ok('only the first figure leads the card', strpos($appT, "if (!amtDone) {") !== false);
t_ok('...the rest fall back to labelled chips',
     preg_match("/amtDone[\s\S]{0,200}td\.classList\.add\('rl-rest'\)/", $appT));
t_ok('...and when a row has several, the leading one is named too',
     strpos($appT, "if (figures > 1 && label) td.classList.add('rl-named');") !== false);
t_ok('the headline is never the number - a figure does not say which row you are on',
     strpos($appT, "if (!mainDone && !td.classList.contains('num') && txt !== '')") !== false);
// ...nor a tick box. The collection queue leads with one, and it was being
// promoted to the top line while the customer's name sat below as a chip.
t_ok('a tick box is a handle, not a heading',
     strpos($appT, "td.querySelector('input[type=checkbox], input[type=radio]')") !== false
     && strpos($appT, "td.classList.add('rl-ctl')") !== false);
t_ok('...and it keeps its place at the front of the line',
     strpos($cssT, '.rl-auto td.rl-ctl { order: 0;') !== false);
t_ok('an empty-state sentence is not dressed up as a field',
     strpos($appT, "tds[0].classList.add('rl-full')") !== false);

// CSS: a phone rule and nothing else.
$mqStart = strpos($cssT, '@media (max-width: 760px) {');
$autoPos = strpos($cssT, '.rl-auto, .rl-auto tbody');
t_ok('the whole thing lives inside the phone breakpoint', $autoPos > $mqStart);
t_ok('the desktop table is left exactly as it was',
     strpos(substr($cssT, strpos($cssT, '.rl-auto td.rl-blank')), '.rl-auto') === 0
     || substr_count($cssT, '.rl-auto') > 0);
// Two bugs found by measuring, each pinned so it cannot come back.
t_ok('a cell is not forced to full width, or every chip takes its own line',
     strpos($cssT, '.rl-auto td { display: block; width: auto; min-width: 0; }') !== false);
t_ok('a chip holding a whole sentence may shrink and wrap',
     strpos($cssT, '.rl-auto td.rl-rest { order: 4; flex: 0 1 auto; max-width: 100%;') !== false);
t_ok('the buttons get a thumb-sized target', strpos($cssT, '.rl-auto td.rl-act .btn { min-height: 38px;') !== false);

t_group('the storefront is sized for a customer\'s thumb');
t_ok('the wishlist heart is 44px', preg_match('/\.wishbtn, \.hbtn \{ min-height: 44px; min-width: 44px;/', $cssT));
t_ok('a carousel dot still LOOKS like a dot', strpos($cssT, '.dots button { position: relative; }') !== false);
t_ok('...but the thumb gets 44px around it', strpos($cssT, '.dots button::after') !== false);

t_group('a form on a phone is two across, not five');
// .filterbar is used for data entry as much as for filtering, and "Add
// expense" put date, category, amount, mode and notes in one 390px row:
// boxes about 70px wide and labels overlapping into "Category₹". It fitted
// the screen and could not be used. .ff-stack said the right thing already
// but only the screens somebody had looked at carried the class.
$cssF = file_get_contents(__DIR__ . '/../assets/style.css');
t_ok('half a row is the floor for EVERY bar, not only the opted-in ones',
     strpos($cssF, '.filterbar > div { flex: 1 1 calc(50% - 4px); min-width: 0; }') !== false);
t_ok('the old class still means "give this one the whole width"',
     strpos($cssF, '.filterbar > div.ff-wide { flex: 1 1 100%; }') !== false);
t_ok('the submit is not squeezed between two fields',
     strpos($cssF, '.filterbar > button, .filterbar > .btn { flex: 1 1 100%; }') !== false);
t_ok('a box never hangs off the edge again',
     strpos($cssF, '.filterbar input, .filterbar select { width: 100%; max-width: 100%; min-width: 0; }') !== false);
// It is a phone rule. A desktop filter bar is a row and stays one.
$ffPos = strpos($cssF, '.filterbar > div { flex: 1 1 calc(50% - 4px);');
$mq700 = strpos($cssF, '@media (max-width: 700px) {');
t_ok('and it only applies on a phone', $ffPos > $mq700 && $mq700 > 0);

t_group('the whole app is drawn in one design language');
// The screens rebuilt this month used .pane; the other forty-odd still used
// .card and looked a year older beside them. Same tokens now, so the two
// halves stop looking like two products - and because it is one rule, the
// dark theme follows without a second definition.
t_ok('.card has the hairline border the new panes have',
     strpos($cssF, '.card { background: var(--card); border: 1px solid var(--line); border-radius: 16px;') !== false);
t_ok('a card title reads as the top of a section',
     strpos($cssF, '.card > h2:first-child') !== false
     && strpos($cssF, 'border-bottom: 1px solid var(--line); }') !== false);
t_ok('...only the FIRST heading, so a sub-heading is not given a rule too',
     strpos($cssF, '.card > h2:first-child') !== false && strpos($cssF, '.card h2:first-child {') === false);
t_ok('every colour is a token, so both themes follow',
     !preg_match('/\.card \{[^}]*#[0-9a-f]{3,6}/i', $cssF));
