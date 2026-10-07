<?php
// "Try it first": the demo shop's address and its login, on the owner's
// address. Nothing to fill in; the demo is wiped every night.
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/platform.php';
if (tenant_active()) { http_response_code(404); exit('Not here.'); }
$d = demo_shop();
$scheme = defined('BASE_URL') && BASE_URL ? (parse_url(BASE_URL, PHP_URL_SCHEME) ?: 'https') : 'https';
$page_title = 'Try the demo';
include __DIR__ . '/includes/header.php';
?>
<div class="login-box">
  <div class="login-title">🎮 Try the demo</div>
  <div class="card">
  <?php if ($d): ?>
    <p>A sample computer shop with items and parties — make bills, add stock, look around. Everything is wiped every night.</p>
    <p>Username <strong>demo</strong> · Password <strong>demo1234</strong></p>
    <a class="btn btn-block" href="<?= e($scheme . '://' . $d['domain'] . '/login.php') ?>">Open the demo →</a>
    <p class="mt" style="text-align:center"><a href="signup.php">Or start your own shop →</a></p>
  <?php else: ?>
    <p>The demo is not open right now. <a href="signup.php">Start your own shop</a> — the first days are free.</p>
  <?php endif; ?>
  </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
