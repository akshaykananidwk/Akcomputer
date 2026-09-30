<?php
// Press "Call customer" and the shop's line rings YOU first, then joins the
// customer with the shop's number showing. This is the one door in; the
// button that draws it lives in includes/voice_bridge.php and every screen
// uses that rather than posting here by hand.
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/voice_bridge.php';

// A call costs money and rings two real phones, so it is never a GET: a
// link somebody is tricked into opening, or a page reloaded by accident,
// must not dial anybody.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { redirect('parties.php'); }
csrf_check();
require_perm('parties.view');

$partyId = (int)post('party_id');

// Where to go back to. Only our own pages: a redirect target taken from a
// form is a redirect target an attacker can set.
$back = (string)post('back', '');
if ($back === '' || preg_match('#^[a-z]+://#i', $back) || strpos($back, '//') === 0) $back = 'parties.php';

$res = voice_bridge_send($partyId);

if ($res['ok']) {
    $mine = preg_replace('/^91/', '', (string)($res['ring'] ?? ''));
    flash($res['status'] === 'test'
        ? '🧪 Test mode — nothing was dialled. A real call would ring you on ' . e($mine) . ' first, then the customer.'
        : '📞 Your phone (' . e($mine) . ') is ringing. Pick it up and the customer is connected — they will see the shop number.',
        'success');
} else {
    flash('Could not place the call: ' . $res['error'], 'error');
}
redirect($back);
