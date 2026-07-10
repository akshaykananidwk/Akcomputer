<?php
require_once __DIR__ . '/includes/init.php';
log_activity('logout');
session_destroy();
redirect('login.php');
