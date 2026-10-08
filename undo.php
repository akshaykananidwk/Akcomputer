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
if (!$ok) { flash('This can no longer be undone.', 'error'); redirect($back); }

if (($why = recycle_restore($u['sets'])) === '') {
    unset($_SESSION['undo']);
    try { q('UPDATE recycle_bin SET restored_at = NOW() WHERE restored_at IS NULL AND label = ? AND user_id = ? ORDER BY id DESC LIMIT 1', [mb_substr($u['label'], 0, 120), $u['uid']]); } catch (Exception $e) {}
    log_activity('undo', $u['label']);
    flash('Brought back ✔');
} else flash($why, 'error');
redirect($back);
