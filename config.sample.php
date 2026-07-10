<?php
// AK Computer - Configuration
// Copy this file to config.php and fill in your server details,
// or simply open /install/ in the browser - the installer creates config.php for you.

define('DB_HOST', 'localhost');
define('DB_NAME', 'akcomputer');
define('DB_USER', 'root');
define('DB_PASS', '');

// Base URL of the app WITHOUT trailing slash, e.g. https://billing.akdwk.in
define('BASE_URL', '');

// App secret (random string, used for tokens/CSRF)
define('APP_SECRET', 'change-this-to-a-long-random-string');

// Default timezone
define('APP_TZ', 'Asia/Kolkata');
