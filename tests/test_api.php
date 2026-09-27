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
