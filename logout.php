<?php
require_once __DIR__ . '/includes/init.php';
log_activity('logout');
if (!empty($_SESSION['session_row_id'])) {
    q('UPDATE user_sessions SET revoked = 1 WHERE id = ?', [$_SESSION['session_row_id']]);
}
$_SESSION = [];
session_destroy();
redirect('login.php');
