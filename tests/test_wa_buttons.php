<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); die('CLI only'); }
// WhatsApp tap-buttons: customers and staff work the shop by tapping.
//
// What matters most: a button only ever acts for the person it was meant
// for (a forwarded "Start" does nothing for anyone else), the API key never
// rides in a URL again, and a channel that cannot draw a button still
// carries its link or phone number as text.
require_once __DIR__ . '/../includes/wa_bot.php';

// a known setup: the gateway only, bot on
set_setting('wa_provider_mode', 'thirdparty');
set_setting('wa_api_url', 'https://bulk.example.test/api.php');
set_setting('wa_session_id', '9999900000');
set_setting('wa_api_key', 'TESTKEY123');
set_setting('wa_bot_enabled', '1');
set_setting('wa_shop_number', '9978123146');

// every gateway call is captured here instead of going out
$GLOBALS['_wa_sent'] = [];
$GLOBALS['_wa_reply'] = [200, '{"status":"success"}'];
$GLOBALS['_wa_http_mock'] = function ($method, $url, $json) {
    $GLOBALS['_wa_sent'][] = ['method' => $method, 'url' => $url, 'json' => $json];
    $r = is_callable($GLOBALS['_wa_reply']) ? ($GLOBALS['_wa_reply'])($method) : $GLOBALS['_wa_reply'];
    return [$r[1], $r[0]];
};
$last = function () { return end($GLOBALS['_wa_sent']) ?: ['method' => '', 'url' => '', 'json' => []]; };

t_group('WhatsApp buttons: the gateway format');
$g = wa_gateway_buttons([
    wa_btn('Pay now', 'url', 'https://shop.test/pay.php?id=1'),
    wa_btn('Statement', 'id', 'portal:stmt'),
    wa_btn('Call us', 'phone', '99781 23146'),
    wa_btn('UPI', 'code', 'akc@upi'),
    wa_btn('Bad link', 'url', 'javascript:alert(1)'),
    wa_btn('', 'id', 'portal:menu'),
    wa_btn('A label that is far too long for a button', 'id', 'x'),
]);
t_eq('a link, a reply, a call, a copy and a long one survive; the bad link and the blank title do not', count($g), 5);
t_ok('a link button is a url', $g[0] === ['type' => 'url', 'text' => 'Pay now', 'url' => 'https://shop.test/pay.php?id=1']);
t_ok('a reply button carries its id', $g[1] === ['type' => 'reply', 'text' => 'Statement', 'id' => 'portal:stmt']);
t_ok('a phone number is written with the country code', $g[2]['type'] === 'call' && $g[2]['phone'] === '919978123146');
t_ok('a copy button carries its code', $g[3]['type'] === 'copy' && $g[3]['code'] === 'akc@upi');
t_ok('a long label is cut to the 25 characters the gateway allows', mb_strlen($g[4]['text']) <= 25);
$many = [];
for ($i = 1; $i <= 14; $i++) $many[] = wa_btn("B$i", 'id', "x:$i");
t_eq('never more than the 10 the gateway takes', count(wa_gateway_buttons($many)), 10);

t_group('WhatsApp buttons: how they are sent');
$GLOBALS['_wa_sent'] = [];
$ok = send_whatsapp('9876543210', 'Hello', '', [wa_btn('Statement', 'id', 'portal:stmt')]);
$s = $last();
t_ok('the send succeeds', $ok);
t_eq('it goes as a POST', $s['method'], 'POST');
t_ok('the API key is in the body, not the URL', strpos($s['url'], 'TESTKEY123') === false && ($s['json']['api_key'] ?? '') === 'TESTKEY123');
t_ok('the buttons go with it', ($s['json']['buttons'][0]['id'] ?? '') === 'portal:stmt');
t_eq('the number is normalised', $s['json']['number'] ?? '', '919876543210');
$GLOBALS['_wa_sent'] = [];
send_whatsapp('9876543210', 'Plain');
t_ok('a message without buttons sends no buttons field', !isset($last()['json']['buttons']));

// a gateway that only knows the old GET form
$GLOBALS['_wa_sent'] = [];
$GLOBALS['_wa_reply'] = fn($m) => $m === 'POST' ? [405, 'Method Not Allowed'] : [200, '{"status":"success"}'];
$ok = send_whatsapp('9876543210', 'Your bill', '', [wa_btn('Pay', 'url', 'https://shop.test/pay'), wa_btn('Menu', 'id', 'portal:menu')]);
t_ok('an old GET-only gateway still gets the message', $ok && count($GLOBALS['_wa_sent']) === 2 && $last()['method'] === 'GET');
t_ok('with the link written out, since a URL cannot carry buttons', strpos($last()['url'], urlencode('Pay: https://shop.test/pay')) !== false);
t_ok('and a reply button is not written out (typed, it would mean nothing)', strpos(urldecode($last()['url']), 'portal:menu') === false);
$GLOBALS['_wa_reply'] = [200, '{"status":"success"}'];

$GLOBALS['_wa_sent'] = [];
$GLOBALS['_wa_reply'] = [200, '{"status":"error","message":"Button 2: link must start with http:// or https://"}'];
t_ok('a refused send reports failure', !send_whatsapp('9876543210', 'x', '', [wa_btn('A', 'id', 'a')]));
t_ok('with the gateway\'s own reason', strpos(whatsapp_last_error(), 'Button 2') !== false);
$GLOBALS['_wa_reply'] = [200, '{"status":"success"}'];

t_group('WhatsApp buttons: a long message keeps its lines');
// the gateway's button message runs every line together, so a statement
// came out as one paragraph
$GLOBALS['_wa_sent'] = [];
$ok = send_whatsapp('9876543210', "Statement\nLine one\nLine two", '', [wa_btn('Menu', 'id', 'portal:menu')]);
t_ok('it goes as two sends', $ok && count($GLOBALS['_wa_sent']) === 2);
t_ok('first the text itself, line breaks and all, with no buttons',
     $GLOBALS['_wa_sent'][0]['json']['message'] === "Statement\nLine one\nLine two" && !isset($GLOBALS['_wa_sent'][0]['json']['buttons']));
t_ok('then one short line carrying the buttons', strpos($GLOBALS['_wa_sent'][1]['json']['message'], "\n") === false
     && ($GLOBALS['_wa_sent'][1]['json']['buttons'][0]['id'] ?? '') === 'portal:menu');
$GLOBALS['_wa_sent'] = [];
send_whatsapp('9876543210', "Bill\nTotal 500", 'https://shop.test/a.pdf', [wa_btn('Pay', 'url', 'https://shop.test/pay')]);
t_ok('a bill PDF goes with its full caption, the buttons after it',
     ($GLOBALS['_wa_sent'][0]['json']['media_url'] ?? '') === 'https://shop.test/a.pdf' && empty($GLOBALS['_wa_sent'][1]['json']['media_url']));
$GLOBALS['_wa_sent'] = [];
send_whatsapp('9876543210', '*My Account*', '', [wa_btn('Bills', 'id', 'portal:bills')], 'Choose what you need');
t_ok('a one-line title goes as one message with its help line as the footer',
     count($GLOBALS['_wa_sent']) === 1 && ($last()['json']['footer'] ?? '') === 'Choose what you need');

t_group('WhatsApp buttons: the same buttons on the Meta number');
$ia = wa_buttons_interactive('Hi', [wa_btn('A', 'id', 'a'), wa_btn('B', 'id', 'b')]);
t_ok('up to three replies become Meta reply buttons', ($ia['type'] ?? '') === 'button' && count($ia['action']['buttons']) === 2);
$ia = wa_buttons_interactive('Hi', array_slice($many, 0, 6));
t_ok('more than three become a list', ($ia['type'] ?? '') === 'list' && count($ia['action']['sections'][0]['rows']) === 6);
$ia = wa_buttons_interactive('Hi', [wa_btn('Pay', 'url', 'https://shop.test/pay')]);
t_ok('one link becomes a link button', ($ia['type'] ?? '') === 'cta_url');
t_ok('a mix Meta cannot draw is left to the text', wa_buttons_interactive('Hi', [wa_btn('Pay', 'url', 'https://shop.test/pay'), wa_btn('A', 'id', 'a')]) === null);
t_ok('and written out there, link and phone both', ($tx = wa_buttons_text([wa_btn('Pay', 'url', 'https://shop.test/pay'), wa_btn('Call', 'phone', '9978123146')]))
     && strpos($tx, 'https://shop.test/pay') !== false && strpos($tx, '+919978123146') !== false);

t_group('WhatsApp buttons: what a customer message carries');
$pid = t_party('Button Customer');
q("UPDATE parties SET mobile = '9812345670' WHERE id = ?", [$pid]);
$sid = t_sale($pid, 1000, 400);
$sale = row('SELECT * FROM sales WHERE id = ?', [$sid]);
$b = wa_customer_buttons('9812345670', 'bill', $sale);
t_ok('a bill with money due leads with Pay, as a link', ($b[0]['url'] ?? '') !== '' && strpos($b[0]['url'], 'pay.php?id=' . $sid) !== false);
t_ok('and the amount on it is what is due', strpos($b[0]['title'], '600') !== false);
t_ok('then the statement', in_array('portal:stmt', array_column($b, 'id'), true));
$b = wa_customer_buttons('9812345670', 'bill', $sale, 0);
t_ok('a paid bill has no Pay button', !array_filter($b, fn($x) => isset($x['url'])));
$b = wa_customer_buttons('9812345670', 'due');
t_ok('a reminder without one bill offers Pay through the chat and a call', in_array('portal:pay', array_column($b, 'id'), true)
     && in_array('9978123146', array_column($b, 'phone'), true));
t_ok('never more than three, the number tested to show', count(wa_customer_buttons('9812345670', 'receipt')) <= 3);
wa_lang_set('919812345670', 'gu');
$b = wa_customer_buttons('9812345670', 'receipt');
t_ok('labels follow the language the customer chose', $b[0]['title'] === wa_strings()['btn_stmt']['gu']);
set_setting('wa_bot_enabled', '0');
$b = wa_customer_buttons('9812345670', 'due', $sale);
t_ok('with the bot off, no reply buttons (nobody would answer them)', !array_filter($b, fn($x) => isset($x['id'])));
t_ok('but Pay and Call still go', count($b) === 2);
set_setting('wa_bot_enabled', '1');
wa_lang_set('919812345670', 'en');

t_group('WhatsApp buttons: menus become buttons on the gateway');
$GLOBALS['_wa_sent'] = [];
$st = wa_portal_route('919812345670', 'portal:menu');
$s = $last();
t_eq('the account menu goes out', $st, 'menu');
t_ok('as buttons, one per menu row', count($s['json']['buttons'] ?? []) >= 5
     && in_array('portal:bills', array_column($s['json']['buttons'], 'id'), true));
t_ok('without the numbered list the buttons already show', strpos($s['json']['message'], '*1)*') === false);
t_ok('a typed number still works', val('SELECT state FROM wa_bot_state WHERE mobile = ?', ['919812345670']) === 'catalog_pick');
$GLOBALS['_wa_sent'] = [];
wa_portal_route('919812345670', 'portal:bill:' . $sid);
t_ok('one bill comes with Pay this bill / My bills / Menu',
     array_column($last()['json']['buttons'] ?? [], 'id') === ['portal:paybill:' . $sid, 'portal:bills', 'portal:menu']);
$GLOBALS['_wa_sent'] = [];
wa_portal_route('919999988888', 'portal:bill:' . $sid);
t_ok('somebody else\'s bill is refused, with no buttons', empty($last()['json']['buttons']) && strpos($last()['json']['message'], '9812345670') === false);
$GLOBALS['_wa_sent'] = [];
wa_portal_route('919812345670', 'portal:stmt');
t_ok('the statement offers Pay when money is due', (array_column($last()['json']['buttons'] ?? [], 'id')[0] ?? '') === 'portal:pay');

t_group('WhatsApp buttons: staff tasks');
$loc = (int)val('SELECT id FROM locations ORDER BY id LIMIT 1');
$fieldRole = (int)val("SELECT id FROM roles WHERE name = 'Field Staff'") ?: (int)val('SELECT id FROM roles ORDER BY id DESC LIMIT 1');
q("INSERT INTO users (name, username, mobile, password, role_id, location_id, is_active) VALUES ('Ravi Test', 'ravi_btn_t', '9811100001', 'x', ?, ?, 1)", [$fieldRole, $loc]);
$ravi = row('SELECT * FROM users WHERE id = ?', [insert_id()]);
q("INSERT INTO users (name, username, mobile, password, role_id, location_id, is_active) VALUES ('Mohan Test', 'mohan_btn_t', '9811100002', 'x', ?, ?, 1)", [$fieldRole, $loc]);
$mohan = row('SELECT * FROM users WHERE id = ?', [insert_id()]);
q("INSERT INTO tasks (customer_name, customer_mobile, address, assigned_to, description, location_id, created_by)
   VALUES ('Shah Traders', '9822200003', 'Station Road', ?, 'Printer not printing', ?, ?)", [$ravi['id'], $loc, $ravi['id']]);
$tid = insert_id();
q('UPDATE tasks SET task_no = ? WHERE id = ?', [doc_no('TSK', $tid), $tid]);

$GLOBALS['_wa_sent'] = [];
t_ok('the new-task message goes to the staff member', wa_task_notify($ravi, row('SELECT * FROM tasks WHERE id = ?', [$tid])));
$s = $last();
t_eq('to their number', $s['json']['number'] ?? '', '919811100001');
$types = array_column($s['json']['buttons'] ?? [], 'type');
t_ok('with Start, Open and Call customer', $types === ['reply', 'url', 'call']
     && $s['json']['buttons'][0]['id'] === 'task:start:' . $tid
     && $s['json']['buttons'][2]['phone'] === '919822200003');

$GLOBALS['_wa_sent'] = [];
$st = wa_bot_handle('919811100002', 'task:start:' . $tid);
t_eq('another staff member\'s tap is refused', $st, 'staff:task-denied');
t_eq('and the task is untouched', val('SELECT status FROM tasks WHERE id = ?', [$tid]), 'assigned');
$st = wa_bot_handle('919822200003', 'task:start:' . $tid);
t_eq('a customer\'s tap does nothing to it either', val('SELECT status FROM tasks WHERE id = ?', [$tid]), 'assigned');

$GLOBALS['_wa_sent'] = [];
$st = wa_bot_handle('919811100001', 'task:start:' . $tid);
t_eq('Ravi\'s own tap starts it', $st, 'staff:task-start');
t_ok('the task is started, with the time', val('SELECT status FROM tasks WHERE id = ?', [$tid]) === 'started'
     && val('SELECT start_time FROM tasks WHERE id = ?', [$tid]) !== null);
t_ok('the answer now offers Finish (the form), not Start again',
     !in_array('reply', array_column($last()['json']['buttons'] ?? [], 'type'), true)
     && strpos($last()['json']['buttons'][0]['url'] ?? '', 'tasks.php?action=view&id=' . $tid) !== false);
wa_bot_handle('919811100001', 'task:start:' . $tid);
t_eq('a second tap does not restart the clock', val('SELECT status FROM tasks WHERE id = ?', [$tid]), 'started');

$GLOBALS['_wa_sent'] = [];
wa_bot_handle('919811100001', 'staff:tasks');
t_ok('My tasks lists the open task as a button', in_array('task:open:' . $tid, array_column($last()['json']['buttons'] ?? [], 'id'), true));
$GLOBALS['_wa_sent'] = [];
wa_bot_handle('919811100002', 'staff:tasks');
t_ok('and Mohan sees none of Ravi\'s', strpos($last()['json']['message'] ?? '', 'Shah Traders') === false);

$GLOBALS['_wa_sent'] = [];
wa_bot_handle('919811100001', 'hi');
$ids = array_column($last()['json']['buttons'] ?? [], 'id');
t_ok('a greeting from staff opens the staff menu', in_array('staff:tasks', $ids, true));
t_ok('without the sales figures for a role that may not see reports', !in_array('sales', $ids, true));
t_ok('the role check is the same rule as on screen', wa_staff_can($ravi, 'reports.view') === false
     && wa_staff_can(row("SELECT u.* FROM users u JOIN roles r ON r.id = u.role_id WHERE r.permissions LIKE '%*%' LIMIT 1"), 'reports.view'));

t_group('WhatsApp buttons: the wiring');
$hook = file_get_contents(__DIR__ . '/../wa_webhook.php');
t_ok('a gateway tap is read from its button field', strpos($hook, "\$p['button']['id']") !== false);
$tasks = file_get_contents(__DIR__ . '/../tasks.php');
t_ok('assigning a task sends the button message', strpos($tasks, 'wa_task_notify(') !== false);
$wa = file_get_contents(__DIR__ . '/../includes/whatsapp.php');
t_ok('a bill goes with its buttons', strpos($wa, "wa_customer_buttons(\$mobile, 'bill', \$sale, \$due)") !== false);
foreach (['payments.php' => 2, 'repairs.php' => 3, 'collection.php' => 1, 'includes/cron_jobs.php' => 2] as $f => $n)
    t_ok("$f sends its customer messages with buttons", substr_count(file_get_contents(__DIR__ . '/../' . $f), 'wa_customer_buttons(') >= $n);

unset($GLOBALS['_wa_http_mock']);

t_group('WhatsApp buttons: staff who tap a customer button');
$GLOBALS['_wa_http_mock'] = function ($method, $url, $json) {
    $GLOBALS['_wa_sent'][] = ['method' => $method, 'url' => $url, 'json' => $json];
    return ['{"status":"success"}', 200];
};
$GLOBALS['_wa_sent'] = [];
$st = wa_bot_handle('919811100001', 'portal:menu');
t_eq('get the customer screen, not the shop assistant', $st, 'portal:menu');
$GLOBALS['_wa_sent'] = [];
wa_bot_handle('919811100001', 'portal:bill:' . $sid);
t_ok('and still only for their own number', strpos($last()['json']['message'] ?? '', $sale['invoice_no']) === false);
unset($GLOBALS['_wa_http_mock']);
