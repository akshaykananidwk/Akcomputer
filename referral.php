<?php
// Public Refer & Earn: anyone signs up as a partner (name + WhatsApp number),
// gets a personal share link, and watches their commissions live on a private
// token dashboard. Money maths: order via their link -> commission books as
// PENDING; shop marks the order completed -> APPROVED (payable balance);
// shop pays out -> PAID.
require_once __DIR__ . '/includes/init.php';

$app_name = setting('app_name', 'AK Computer');
$defaultPct = (float)setting('referral_pct', '2');
$msg = ''; $err = '';

// ---------- partner signup ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'join') {
    $name = trim(post('name'));
    $mobile = preg_replace('/[^0-9]/', '', post('mobile'));
    if ($name === '' || strlen($mobile) < 10) {
        $err = 'Enter your name and a correct WhatsApp number.';
    } else {
        $ex = row('SELECT * FROM referrers WHERE mobile = ?', [$mobile]);
        if ($ex) {
            // already a partner - resend their links instead of duplicating
            send_whatsapp($mobile, "🤝 *$app_name Refer & Earn*\n\nYou are already a partner!\n\n🔗 Your link:\n" . base_url('catalog.php?ref=' . $ex['code']) .
                "\n\n📊 Your dashboard:\n" . base_url('referral.php?t=' . $ex['token']));
            $msg = 'You are already a partner — your link has been sent again on WhatsApp.';
        } else {
            // 6-char A-Z/0-9 code (confusable 0/O/1/I left in - it's typed
            // rarely; the link carries it anyway)
            $mkcode = function () {
                $chars = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
                $c = '';
                for ($i = 0; $i < 6; $i++) $c .= $chars[random_int(0, strlen($chars) - 1)];
                return $c;
            };
            $code = $mkcode();
            while (row('SELECT id FROM referrers WHERE code = ?', [$code])) $code = $mkcode();
            $token = bin2hex(random_bytes(20));
            q('INSERT INTO referrers (name, mobile, code, token, commission_pct) VALUES (?,?,?,?,?)',
              [$name, $mobile, $code, $token, $defaultPct]);
            log_activity('referrer_join', "$name $mobile code=$code");
            $ok = send_whatsapp($mobile, "🤝 *Welcome to $app_name Refer & Earn, $name!*\n\n*{$defaultPct}% commission* on every sale.\n\n🔗 Share this link:\n" . base_url('catalog.php?ref=' . $code) .
                "\n\n📊 Your commission dashboard (check it daily):\n" . base_url('referral.php?t=' . $token));
            // show the dashboard right away regardless of WhatsApp delivery
            redirect('referral.php?t=' . $token . '&new=1');
        }
    }
}

// ---------- partner dashboard (private token) ----------
$partner = get('t') !== '' ? row('SELECT * FROM referrers WHERE token = ?', [get('t')]) : null;
$earnings = []; $tot = ['pending' => 0, 'approved' => 0, 'paid' => 0];
if ($partner) {
    $earnings = all('SELECT * FROM referral_earnings WHERE referrer_id = ? ORDER BY id DESC LIMIT 100', [$partner['id']]);
    foreach (all('SELECT status, SUM(commission) s FROM referral_earnings WHERE referrer_id = ? GROUP BY status', [$partner['id']]) as $x) {
        if (isset($tot[$x['status']])) $tot[$x['status']] = (float)$x['s'];
    }
    $shareLink = base_url('catalog.php?ref=' . $partner['code']);
    $shareMsg = rawurlencode("🖥️ $app_name - computers, CCTV and laptops, all at the best price!\nOrder here: $shareLink");
}
$statusLabel = ['pending' => '⏳ Orders pending', 'approved' => '✅ Credited (yet to be paid out)', 'paid' => '💵 Paid out', 'cancelled' => '✕ Cancel'];
?><!DOCTYPE html>
<html lang="gu">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($app_name) ?> — Refer &amp; Earn</title>
<link rel="icon" href="assets/icon.svg" type="image/svg+xml">
<link rel="stylesheet" href="assets/style.css?v=3">
<style>
.store-hero { background: linear-gradient(120deg, #059669, #047857); color: #fff; text-align: center; padding: 30px 14px 24px; }
.store-wrap { max-width: 640px; margin: 0 auto; padding: 16px 12px; }
.ref-stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; }
.ref-stat { background: var(--card); color: var(--text); border-radius: 12px; padding: 12px; text-align: center; box-shadow: 0 1px 4px rgba(0,0,0,.08); }
.ref-stat .v { font-size: 18px; font-weight: 800; }
.ref-stat .l { font-size: 12px; color: var(--muted); margin-top: 3px; }
.link-box { background: var(--bg); border: 1px dashed var(--primary); border-radius: 10px; padding: 10px; font-size: 13px; word-break: break-all; }
</style>
</head>
<body>
<div class="store-hero">
  <h1>💰 Refer &amp; Earn</h1>
  <p style="opacity:.9;margin-top:6px;font-size:14px"><?= e($app_name) ?> — share the link and earn commission on every sale</p>
</div>
<div class="store-wrap">

<?php if ($partner): ?>
  <?php if (get('new')): ?><div class="flash flash-success">🎉 You are a partner now! Your link has also been sent on WhatsApp — keep this page.</div><?php endif; ?>
  <div class="card">
    <h2>Hello, <?= e($partner['name']) ?> 👋</h2>
    <p class="muted">Your code: <strong><?= e($partner['code']) ?></strong> · commission: <strong><?= (float)$partner['commission_pct'] ?>%</strong> · link clicks: <strong><?= (int)$partner['clicks'] ?></strong></p>
    <p class="mt"><strong>Your share link:</strong></p>
    <div class="link-box" id="shareLink"><?= e($shareLink) ?></div>
    <div class="page-actions mt">
      <a class="btn btn-wa" href="https://wa.me/?text=<?= $shareMsg ?>" target="_blank" rel="noopener">📲 Share on WhatsApp</a>
      <button class="btn btn-outline" type="button" onclick="navigator.clipboard.writeText(document.getElementById('shareLink').textContent).then(()=>alert('Link copied!'))">📋 Copy</button>
    </div>
  </div>

  <div class="ref-stats mb">
    <div class="ref-stat"><div class="v" style="color:#d97706">₹<?= money($tot['pending']) ?></div><div class="l">⏳ Pending</div></div>
    <div class="ref-stat"><div class="v" style="color:var(--ok)">₹<?= money($tot['approved']) ?></div><div class="l">✅ Credited balance</div></div>
    <div class="ref-stat"><div class="v" style="color:var(--primary)">₹<?= money($tot['paid']) ?></div><div class="l">💵 Paid</div></div>
  </div>

  <div class="card">
    <h3>Your orders</h3>
    <?php if (!$earnings): ?><p class="muted">No orders yet — keep sharing the link! 🚀</p><?php endif; ?>
    <table class="table-sm">
      <?php foreach ($earnings as $e2): ?>
      <tr>
        <td><?= e($e2['order_no']) ?><br><span class="muted" style="font-size:12px"><?= dmy(substr($e2['created_at'], 0, 10)) ?> · orders Rs <?= money($e2['order_total']) ?></span></td>
        <td class="num"><strong>₹<?= money($e2['commission']) ?></strong><br><span class="muted" style="font-size:12px"><?= $statusLabel[$e2['status']] ?? e($e2['status']) ?></span></td>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>
  <p class="muted" style="font-size:12.5px">⏳ Pending = the order has come in; it becomes ✅ credited once the shop completes it. A credited balance moves to 💵 once the shop pays it out.</p>

<?php else: ?>
  <div class="card">
    <h2>Become a partner — free!</h2>
    <p class="muted mb">You get your own link. Whenever anybody orders through it, you <strong><?= $defaultPct ?>% commission</strong> — check the status on the dashboard daily.</p>
    <?php if ($err): ?><div class="flash flash-error"><?= e($err) ?></div><?php endif; ?>
    <?php if ($msg): ?><div class="flash flash-success"><?= e($msg) ?></div><?php endif; ?>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="join">
      <div class="field"><label>Your name *</label><input type="text" name="name" required></div>
      <div class="field"><label>WhatsApp number *</label><input type="tel" name="mobile" required pattern="[0-9]{10,12}"></div>
      <button class="btn btn-success btn-block" type="submit">🤝 Become a partner</button>
    </form>
    <p class="muted mt" style="font-size:12.5px">Already a partner? Enter the same number — your link will come again on WhatsApp.</p>
  </div>
  <p class="mt" style="text-align:center"><a class="btn btn-sm btn-outline" href="catalog.php">← Go to the store</a></p>
<?php endif; ?>
</div>
</body>
</html>
