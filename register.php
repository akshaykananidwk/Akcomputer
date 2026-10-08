<?php
// 📲 A customer scans the QR at the counter and writes their own name and
// number - no typing for the staff. They choose for themselves whether the
// shop may send them offers on WhatsApp, and that choice is recorded.
// Staff open register.php?qr=1 to print the QR.
require_once __DIR__ . '/includes/init.php';
$shop = setting('app_name', '');

if (get('qr')) {
    require_perm('parties.add');
    $url = base_url('register.php');
    $page_title = 'Customer QR';
    include __DIR__ . '/includes/header.php'; ?>
<div class="card" style="text-align:center;max-width:420px;margin:0 auto">
  <h2 style="margin-top:0"><?= e($shop) ?></h2><p>📲 Scan to join — get your bills and offers on WhatsApp</p>
  <img src="https://api.qrserver.com/v1/create-qr-code/?size=300x300&amp;data=<?= rawurlencode($url) ?>" width="300" height="300" alt="QR code for <?= e($url) ?>">
  <p class="muted" style="word-break:break-all"><?= e($url) ?></p><button class="btn no-print" onclick="print()">🖨️ Print</button></div>
<?php include __DIR__ . '/includes/footer.php'; exit; }

$done = false; $err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim(mb_substr((string)post('name'), 0, 120)); $mobile = preg_replace('/\D/', '', (string)post('mobile'));
    $mobile = strlen($mobile) > 10 ? substr($mobile, -10) : $mobile;
    if (!api_rate_ok('register:' . client_ip(), 5, 3600)) $err = 'Too many tries. Please ask at the counter.';
    elseif ($name === '' || !preg_match('/^[6-9]\d{9}$/', $mobile)) $err = 'Please write your name and a 10-digit mobile number.';
    elseif (trim((string)post('website')) !== '') $done = true;          // a robot filled the hidden box: say thanks, keep nothing
    else {
        $consent = post('consent') ? 1 : 0;
        $p = row("SELECT id FROM parties WHERE RIGHT(REPLACE(mobile, ' ', ''), 10) = ? LIMIT 1", [$mobile]);
        if ($p) {
            $pid = (int)$p['id'];
            q('UPDATE parties SET marketing_opt_out = ? WHERE id = ?', [$consent ? 0 : 1, $pid]);
        } else {
            q("INSERT INTO parties (name, type, mobile, city, dob, marketing_opt_out) VALUES (?, 'customer', ?, ?, ?, ?)",
              [$name, $mobile, mb_substr(trim((string)post('city')), 0, 60), preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)post('dob')) ? post('dob') : null, $consent ? 0 : 1]);
            $pid = insert_id();
        }
        consent_record($pid, 'marketing', $consent, 'self-register QR');
        $done = true;
    }
}
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title><?= e($shop) ?></title>
<style>body{margin:0;font:16px/1.5 system-ui,-apple-system,'Segoe UI',Roboto,'Noto Sans Gujarati',sans-serif;background:#f1f5f9;color:#0f172a}.w{max-width:440px;margin:0 auto;padding:18px}
.c{background:#fff;border-radius:14px;padding:18px;box-shadow:0 1px 4px rgba(0,0,0,.07)}input{width:100%;box-sizing:border-box;padding:12px;border:1px solid #cbd5e1;border-radius:10px;font:inherit;margin:4px 0 12px}
button{width:100%;padding:14px;border:0;border-radius:10px;background:#1a56db;color:#fff;font:600 17px system-ui}label{font-weight:600}.e{background:#fee2e2;color:#991b1b;padding:10px;border-radius:8px;margin-bottom:10px}.hp{position:absolute;left:-5000px}</style></head>
<body><div class="w"><h1 style="font-size:22px"><?= e($shop) ?></h1><div class="c">
<?php if ($done): ?><h2>🙏 Thank you!</h2><p>You are registered. Your bills will reach you on WhatsApp.</p>
<?php else: ?>
  <?php if ($err): ?><div class="e"><?= e($err) ?></div><?php endif; ?>
  <form method="post"><?= csrf_field() ?>
    <label>Your name / તમારું નામ</label><input name="name" required maxlength="120" value="<?= e(post('name')) ?>">
    <label>Mobile / મોબાઇલ</label><input name="mobile" type="tel" inputmode="numeric" required maxlength="14" value="<?= e(post('mobile')) ?>">
    <label>City / ગામ</label><input name="city" maxlength="60">
    <label>Birthday (for a wish) <small style="font-weight:normal">optional</small></label><input name="dob" type="date">
    <input class="hp" name="website" tabindex="-1" autocomplete="off" aria-hidden="true">
    <p><label style="font-weight:normal"><input type="checkbox" name="consent" value="1" style="width:auto"> Yes, send me offers on WhatsApp. (You can stop them any time.)</label></p>
    <button type="submit">Join</button></form>
<?php endif; ?></div></div></body></html>
