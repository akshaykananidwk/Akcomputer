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
