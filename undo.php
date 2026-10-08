<?php
// ↩ Undo: put back what was just deleted (see undo_keep()). Only records that
// carry no money - an item never billed, a parked bill, a follow-up, a
// reminder - and only for ten minutes, only by the person who deleted it.
require_once __DIR__ . '/includes/init.php';
require_login();
$back = 'index.php';
$ref = (string)($_SERVER['HTTP_REFERER'] ?? '');
if ($ref !== '' && parse_url($ref, PHP_URL_HOST) === request_host()) $back = basename(parse_url($ref, PHP_URL_PATH)) . (($qs = parse_url($ref, PHP_URL_QUERY)) ? '?' . $qs : '');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect($back);

$u = $_SESSION['undo'] ?? null;
$ok = $u && hash_equals($u['t'], (string)post('t')) && time() - $u['at'] <= 600 && (int)$u['uid'] === (int)current_user()['id'];
$allowed = ['items', 'parked_bills', 'follow_ups', 'reminders', 'reminder_recipients'];
if ($ok) foreach (array_keys($u['sets']) as $t) if (!in_array($t, $allowed, true)) $ok = false;
if (!$ok) { flash('This can no longer be undone.', 'error'); redirect($back); }

$pdo = db();
$pdo->beginTransaction();
try {
    foreach ($u['sets'] as $table => $rows) foreach ($rows as $r) {
        $cols = array_keys($r);
        q("INSERT INTO `$table` (`" . implode('`,`', $cols) . '`) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')', array_values($r));
    }
    $pdo->commit();
    unset($_SESSION['undo']);
    log_activity('undo', $u['label']);
    flash('Brought back ✔');
} catch (Exception $e) {
    $pdo->rollBack();
    flash(plain_error($e), 'error');
}
redirect($back);
