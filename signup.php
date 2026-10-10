<?php
// A new shop signs itself up: name, owner, mobile (checked with a WhatsApp
// OTP), the kind of business and a web address - and a minute later it has
// its own software on <address>.<platform domain>, with a free trial.
// Lives on the owner's own address only; open only when the owner has
// switched sign-ups on (Platform → Settings).
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/platform.php';

if (tenant_active()) { http_response_code(404); exit('Not here.'); }
$open = setting('platform_signup_open', '0') === '1' && platform_domain() !== '';
$needOtp = setting('platform_signup_otp', '1') === '1';
$packs = business_packs();
$err = ''; $step = 'form'; $done = null;
$in = $_SESSION['signup'] ?? [];
$ip = $_SERVER['REMOTE_ADDR'] ?? '';

if ($open && $_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'start') {
    $in = [
        'name' => trim(post('name')), 'owner_name' => trim(post('owner_name')),
        'owner_mobile' => substr(preg_replace('/\D/', '', post('mobile')), -10),
        'owner_email' => trim(post('email')), 'business_type' => isset($packs[post('btype')]) ? post('btype') : 'general',
        'slug' => strtolower(trim(post('slug'))), 'city' => trim(post('city')),
        'with_samples' => post('samples') ? 1 : 0, 'ref' => strtoupper(trim(post('ref'))),
    ];
    $pass = (string)($_POST['password'] ?? '');
    if (!api_rate_ok('signup:' . $ip, 6, 3600)) $err = 'Too many sign-ups from this connection. Please try again in an hour.';
    elseif (mb_strlen($in['name']) < 3) $err = 'Please write the shop\'s name.';
    elseif (mb_strlen($in['owner_name']) < 2) $err = 'Please write the owner\'s name.';
    elseif (strlen($in['owner_mobile']) !== 10) $err = 'Please write a 10-digit mobile number.';
    elseif ($in['owner_email'] !== '' && !filter_var($in['owner_email'], FILTER_VALIDATE_EMAIL)) $err = 'That email address does not look right.';
    elseif (!post('agree')) $err = 'Please accept the terms to continue.';
    elseif (($p = tenant_slug_problem($in['slug'])) !== '') $err = $p;
    elseif (($p = password_policy_check($pass)) !== '') $err = $p;
    else {
        $in['pass_hash'] = password_hash($pass, PASSWORD_DEFAULT);
        $in['reseller_id'] = $in['ref'] !== '' ? (pval('SELECT id FROM resellers WHERE code = ? AND is_active = 1', [$in['ref']]) ?: null) : null;
        $_SESSION['signup'] = $in;
        if ($needOtp) {
            $code = (string)random_int(100000, 999999);
            $_SESSION['signup_otp'] = ['hash' => password_hash($code, PASSWORD_DEFAULT), 'until' => time() + 600, 'tries' => 0];
            // the same code on WhatsApp and, when given, by e-mail - either one is enough
            $sentWa = send_otp_whatsapp($in['owner_mobile'], $code, 'your new shop');
            $sentMail = $in['owner_email'] !== '' && send_mail($in['owner_email'], 'Your code: ' . $code, mail_html('Your sign-up code',
                '<p>Your code to start <b>' . e($in['name']) . '</b> is:</p><p style="font-size:28px;font-weight:bold;letter-spacing:4px">' . $code . '</p><p style="color:#64748b;font-size:13px">It works for 10 minutes. If you did not ask for it, ignore this e-mail.</p>'));
            if (!$sentWa && !$sentMail) $err = 'The code could not be sent on WhatsApp' . ($in['owner_email'] !== '' ? ' or e-mail' : '') . '. Please check the number' . ($in['owner_email'] !== '' ? ' and e-mail' : ' or add an e-mail') . '.';
            else { $step = 'otp'; $_SESSION['signup_sent'] = trim(($sentWa ? 'WhatsApp ' . $in['owner_mobile'] : '') . ($sentWa && $sentMail ? ' and ' : '') . ($sentMail ? 'e-mail ' . $in['owner_email'] : '')); }
        } else {
            $step = 'make';
        }
    }
}
if ($open && $_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'verify' && $in) {
    $o = $_SESSION['signup_otp'] ?? null;
    $step = 'otp';
    if (!$o || $o['until'] < time()) { $err = 'The OTP has expired. Please start again.'; $step = 'form'; unset($_SESSION['signup_otp']); }
    elseif (++$_SESSION['signup_otp']['tries'] > 5) { $err = 'Too many wrong tries. Please start again.'; $step = 'form'; unset($_SESSION['signup_otp']); }
    elseif (!password_verify(preg_replace('/\D/', '', post('otp')), $o['hash'])) $err = 'That OTP is not right.';
    else $step = 'make';
}
if ($step === 'make') {
    try {
        $in['username'] = $in['owner_mobile'];
        $done = provision_tenant($in);
        unset($_SESSION['signup'], $_SESSION['signup_otp'], $_SESSION['signup_sent']);
        log_activity('shop_signup', $done['slug'] . ' (' . $done['name'] . ')');
        if ($in['owner_email'] !== '') {
            $loginUrl = ((defined('BASE_URL') && BASE_URL ? (parse_url(BASE_URL, PHP_URL_SCHEME) ?: 'https') : 'https') . '://' . $done['domain'] . '/login.php');
            send_mail($in['owner_email'], 'Your shop software is ready — ' . $done['name'], mail_html('Welcome! Your software is ready',
                '<p>Namaste ' . e($in['owner_name']) . ',</p><p><b>' . e($done['name']) . '</b> is ready.</p>'
                . '<p><a href="' . e($loginUrl) . '" style="display:inline-block;background:#1a56db;color:#fff;padding:11px 20px;border-radius:8px;text-decoration:none">Open your software</a></p>'
                . '<p>Address: <a href="' . e($loginUrl) . '">' . e($done['domain']) . '</a><br>Login: <b>' . e($in['owner_mobile']) . '</b> or <b>' . e($in['owner_email']) . '</b><br>Password: the one you chose.'
                . ($done['trial_ends'] ? '<br>Free till: <b>' . dmy($done['trial_ends']) . '</b>' : '') . '</p><p>Forgot the password? Use "Forgot password" on the login page — a link comes to this e-mail.</p>'));
        }
        try { tg_notify_admins("🎉 New shop signed up: {$done['name']}\n{$done['owner_name']} +91{$done['owner_mobile']}\n" . business_label($done['business_type']) . "\nhttps://{$done['domain']}"); } catch (Exception $e) {}
        $step = 'done';
    } catch (Exception $e) {
        $err = $e->getMessage(); $step = 'form';
    }
}

$page_title = 'Start your shop\'s software';
$scheme = defined('BASE_URL') && BASE_URL ? (parse_url(BASE_URL, PHP_URL_SCHEME) ?: 'https') : 'https';
include __DIR__ . '/includes/header.php';
?>
<style>
.su-box { max-width: 560px; margin: 24px auto; padding: 0 16px; }
.su-head { text-align: center; margin-bottom: 14px; }
.su-head h1 { font-size: 22px; margin: 0 0 4px; }
.su-head p { color: var(--muted); margin: 0; }
.bt-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; }
.bt-grid label { border: 1px solid var(--border); border-radius: 11px; padding: 9px 6px; text-align: center; cursor: pointer; font-size: 12.5px; line-height: 1.3; background: var(--card); }
.bt-grid label { position: relative; }
.bt-grid input { position: absolute; opacity: 0; width: 1px; height: 1px; }
.bt-grid label:has(input:focus-visible) { outline: 2px solid var(--primary); outline-offset: 2px; }
.bt-grid input:checked + span { color: var(--primary); font-weight: 700; }
.bt-grid label:has(input:checked) { border-color: var(--primary); box-shadow: 0 0 0 2px rgba(37,99,235,.15); }
.bt-grid .bt-i { display: block; font-size: 20px; }
.slug-row { display: flex; align-items: center; gap: 6px; }
.slug-row input { flex: 1; min-width: 0; }
.slug-row span { color: var(--muted); font-size: 13px; white-space: nowrap; }
@media (max-width: 420px) { .bt-grid { grid-template-columns: repeat(2, 1fr); } }
</style>
<div class="su-box">
  <div class="su-head">
    <h1>🏪 Billing software for your shop</h1>
    <p>Bills, stock, payments, WhatsApp — ready in a minute. <?= (int)setting('platform_trial_days', '15') ?> days free.</p>
  </div>
  <div class="card">
  <?php if (!$open): ?>
    <p>New sign-ups are not open right now. Please contact us.</p>
  <?php elseif ($step === 'done'): ?>
    <h3 style="margin-top:0">✅ Your shop is ready</h3>
    <p><strong><?= e($done['name']) ?></strong> — your own address:</p>
    <p><a class="btn btn-block" href="<?= e($scheme . '://' . $done['domain'] . '/login.php') ?>"><?= e($done['domain']) ?> →</a></p>
    <p class="muted">Log in with your mobile number <strong><?= e($done['owner_mobile']) ?></strong><?= $done['owner_email'] ?? '' ? ' or e-mail <strong>' . e($done['owner_email']) . '</strong>' : '' ?> and the password you chose.
      Free trial till <strong><?= e(dmy($done['trial_ends'])) ?></strong>.</p>
  <?php elseif ($step === 'otp'): ?>
    <?php if ($err): ?><div class="flash flash-error"><?= e($err) ?></div><?php endif; ?>
    <p>We sent a 6-digit code to <strong><?= e($_SESSION['signup_sent'] ?? ('WhatsApp ' . ($in['owner_mobile'] ?? ''))) ?></strong>.</p>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="do" value="verify">
      <div class="field"><label>OTP</label><input type="text" name="otp" inputmode="numeric" maxlength="6" required autofocus></div>
      <button class="btn btn-block" type="submit">Verify and make my shop</button>
    </form>
    <p class="mt"><a href="signup.php">← Change details</a></p>
  <?php else: ?>
    <?php if ($err): ?><div class="flash flash-error"><?= e($err) ?></div><?php endif; ?>
    <form method="post" autocomplete="off">
      <?= csrf_field() ?><input type="hidden" name="do" value="start">
      <div class="field"><label>Shop name *</label><input type="text" name="name" id="suName" required maxlength="120" value="<?= e($in['name'] ?? '') ?>"></div>
      <div class="field"><label>Your web address *</label>
        <div class="slug-row"><input type="text" name="slug" id="suSlug" required pattern="[a-z0-9]{3,30}" maxlength="30" autocapitalize="none"
             value="<?= e($in['slug'] ?? '') ?>"><span>.<?= e(platform_domain()) ?></span></div>
        <div class="muted" style="font-size:12px">English letters and numbers only.</div></div>
      <div class="form-row cols-2">
        <div><label>Owner's name *</label><input type="text" name="owner_name" required maxlength="100" value="<?= e($in['owner_name'] ?? '') ?>"></div>
        <div><label>Mobile (WhatsApp) *</label><input type="tel" name="mobile" required inputmode="numeric" maxlength="14" value="<?= e($in['owner_mobile'] ?? '') ?>"></div>
      </div>
      <div class="form-row cols-2">
        <div><label>City</label><input type="text" name="city" maxlength="60" value="<?= e($in['city'] ?? '') ?>"></div>
        <div><label>Email</label><input type="email" name="email" maxlength="120" value="<?= e($in['owner_email'] ?? '') ?>"></div>
      </div>
      <div class="field"><label>What kind of shop?</label>
        <div class="bt-grid">
        <?php foreach ($packs as $k => $p): ?>
          <label><input type="radio" name="btype" value="<?= e($k) ?>" <?= ($in['business_type'] ?? 'general') === $k ? 'checked' : '' ?>>
            <span><span class="bt-i"><?= $p[0] ?></span><?= e($p[1]) ?></span></label>
        <?php endforeach; ?>
        </div></div>
      <label class="check-inline"><input type="checkbox" name="samples" value="1" <?= !isset($in['with_samples']) || $in['with_samples'] ? 'checked' : '' ?>> Add a few sample items to start with</label>
      <div class="field mt"><label>Password *</label><input type="password" name="password" required minlength="8" autocomplete="new-password"></div>
      <div class="field"><label>Referral code <span class="muted" style="font-weight:normal">(if someone told you about us)</span></label>
        <input type="text" name="ref" maxlength="20" value="<?= e($in['ref'] ?? strtoupper((string)get('ref'))) ?>"></div>
      <label class="check-inline"><input type="checkbox" name="agree" value="1" required> I accept the <a href="terms.php" target="_blank">terms</a> and <a href="privacy.php" target="_blank">privacy policy</a></label>
      <button class="btn btn-block mt" type="submit"><?= $needOtp ? 'Send OTP on WhatsApp' : 'Make my shop' ?></button>
    </form>
    <p class="mt" style="text-align:center"><a href="demo.php">Try the demo first →</a></p>
  <?php endif; ?>
  </div>
</div>
<script>
(function () {   // the address follows the shop's name until it is typed in by hand
  var n = document.getElementById('suName'), s = document.getElementById('suSlug');
  if (!n || !s) return;
  var touched = s.value !== '';
  s.addEventListener('input', function () { touched = true; s.value = s.value.toLowerCase().replace(/[^a-z0-9]/g, ''); });
  n.addEventListener('input', function () { if (!touched) s.value = n.value.toLowerCase().replace(/[^a-z0-9]/g, '').slice(0, 30); });
})();
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
