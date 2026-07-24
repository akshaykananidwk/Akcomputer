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
        $err = 'નામ અને સાચો WhatsApp નંબર લખો.';
    } else {
        $ex = row('SELECT * FROM referrers WHERE mobile = ?', [$mobile]);
        if ($ex) {
            // already a partner - resend their links instead of duplicating
            send_whatsapp($mobile, "🤝 *$app_name Refer & Earn*\n\nતમે પહેલેથી પાર્ટનર છો!\n\n🔗 તમારી લિંક:\n" . base_url('catalog.php?ref=' . $ex['code']) .
                "\n\n📊 તમારું ડેશબોર્ડ:\n" . base_url('referral.php?t=' . $ex['token']));
            $msg = 'તમે પહેલેથી પાર્ટનર છો — તમારી લિંક WhatsApp પર ફરી મોકલી છે.';
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
            $ok = send_whatsapp($mobile, "🤝 *$app_name Refer & Earn માં સ્વાગત, $name!*\n\nદરેક વેચાણ પર *{$defaultPct}% કમિશન*.\n\n🔗 આ લિંક શેર કરો:\n" . base_url('catalog.php?ref=' . $code) .
                "\n\n📊 તમારું કમિશન ડેશબોર્ડ (રોજ જુઓ):\n" . base_url('referral.php?t=' . $token));
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
    $shareMsg = rawurlencode("🖥️ $app_name - કમ્પ્યુટર, CCTV, લેપટોપ બધું બેસ્ટ ભાવે!\nઅહીંથી ઓર્ડર કરો: $shareLink");
}
$statusLabel = ['pending' => '⏳ ઓર્ડર બાકી', 'approved' => '✅ જમા (ચૂકવવાનું બાકી)', 'paid' => '💵 ચૂકવાઈ ગયું', 'cancelled' => '✕ કેન્સલ'];
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
  <p style="opacity:.9;margin-top:6px;font-size:14px"><?= e($app_name) ?> — લિંક શેર કરો, દરેક વેચાણ પર કમિશન કમાઓ</p>
</div>
<div class="store-wrap">

<?php if ($partner): ?>
  <?php if (get('new')): ?><div class="flash flash-success">🎉 તમે પાર્ટનર બની ગયા! તમારી લિંક WhatsApp પર પણ મોકલી છે — આ પેજ સાચવી રાખો.</div><?php endif; ?>
  <div class="card">
    <h2>નમસ્તે, <?= e($partner['name']) ?> 👋</h2>
    <p class="muted">તમારો કોડ: <strong><?= e($partner['code']) ?></strong> · કમિશન: <strong><?= (float)$partner['commission_pct'] ?>%</strong> · લિંક ક્લિક: <strong><?= (int)$partner['clicks'] ?></strong></p>
    <p class="mt"><strong>તમારી શેર-લિંક:</strong></p>
    <div class="link-box" id="shareLink"><?= e($shareLink) ?></div>
    <div class="page-actions mt">
      <a class="btn btn-wa" href="https://wa.me/?text=<?= $shareMsg ?>" target="_blank" rel="noopener">📲 WhatsApp પર શેર કરો</a>
      <button class="btn btn-outline" type="button" onclick="navigator.clipboard.writeText(document.getElementById('shareLink').textContent).then(()=>alert('લિંક કોપી થઈ ગઈ!'))">📋 Copy</button>
    </div>
  </div>

  <div class="ref-stats mb">
    <div class="ref-stat"><div class="v" style="color:#d97706">₹<?= money($tot['pending']) ?></div><div class="l">⏳ Pending</div></div>
    <div class="ref-stat"><div class="v" style="color:var(--ok)">₹<?= money($tot['approved']) ?></div><div class="l">✅ જમા બેલેન્સ</div></div>
    <div class="ref-stat"><div class="v" style="color:var(--primary)">₹<?= money($tot['paid']) ?></div><div class="l">💵 ચૂકવાયું</div></div>
  </div>

  <div class="card">
    <h3>તમારા ઓર્ડર</h3>
    <?php if (!$earnings): ?><p class="muted">હજી કોઈ ઓર્ડર નથી — લિંક શેર કરતા રહો! 🚀</p><?php endif; ?>
    <table class="table-sm">
      <?php foreach ($earnings as $e2): ?>
      <tr>
        <td><?= e($e2['order_no']) ?><br><span class="muted" style="font-size:12px"><?= dmy(substr($e2['created_at'], 0, 10)) ?> · ઓર્ડર ₹<?= money($e2['order_total']) ?></span></td>
        <td class="num"><strong>₹<?= money($e2['commission']) ?></strong><br><span class="muted" style="font-size:12px"><?= $statusLabel[$e2['status']] ?? e($e2['status']) ?></span></td>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>
  <p class="muted" style="font-size:12.5px">⏳ Pending = ઓર્ડર આવ્યો છે, દુકાન પૂરો કરે એટલે ✅ જમા થાય. જમા બેલેન્સ દુકાનેથી ચૂકવાય એટલે 💵 માં જાય.</p>

<?php else: ?>
  <div class="card">
    <h2>પાર્ટનર બનો — ફ્રીમાં!</h2>
    <p class="muted mb">તમારી ખાસ લિંક મળશે. એ લિંકથી કોઈ પણ ઓર્ડર કરે એટલે તમને <strong><?= $defaultPct ?>% કમિશન</strong> — સ્ટેટસ ડેશબોર્ડમાં રોજ જુઓ.</p>
    <?php if ($err): ?><div class="flash flash-error"><?= e($err) ?></div><?php endif; ?>
    <?php if ($msg): ?><div class="flash flash-success"><?= e($msg) ?></div><?php endif; ?>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="join">
      <div class="field"><label>તમારું નામ *</label><input type="text" name="name" required></div>
      <div class="field"><label>WhatsApp નંબર *</label><input type="tel" name="mobile" required pattern="[0-9]{10,12}"></div>
      <button class="btn btn-success btn-block" type="submit">🤝 પાર્ટનર બનો</button>
    </form>
    <p class="muted mt" style="font-size:12.5px">પહેલેથી પાર્ટનર છો? એ જ નંબર નાખો — તમારી લિંક WhatsApp પર ફરી આવી જશે.</p>
  </div>
  <p class="mt" style="text-align:center"><a class="btn btn-sm btn-outline" href="catalog.php">← સ્ટોર પર જાઓ</a></p>
<?php endif; ?>
</div>
</body>
</html>
