<?php
// Public "pay this bill" page - no login, reached from the link sent with the
// invoice on WhatsApp.
//
// Why a page and not just a upi:// link in the message: WhatsApp (and most
// chat apps) only turn http(s) addresses into something tappable. A raw
// upi://pay?... string arrives as plain text the customer cannot use. So the
// link that gets sent is an ordinary web address, and the UPI intent is fired
// from a button on this page - which also lets the same link work for someone
// reading it on a desktop, who gets the QR and the UPI id instead.
//
// The amount is ALWAYS what the bill still owes, worked out fresh on every
// visit through the same capped rule the rest of the shop uses. A bill that
// has been part paid asks for the remainder; one that is settled says so and
// offers nothing to pay.
require_once __DIR__ . '/includes/init.php';

$id = (int)get('id');
$token = (string)get('t');
$sale = $id ? row('SELECT s.*, c.name company_name, c.phone c_phone
                   FROM sales s JOIN companies c ON c.id = s.company_id
                   WHERE s.id = ?', [$id]) : null;
if (!$sale || !$sale['share_token'] || !hash_equals($sale['share_token'], $token)) {
    http_response_code(404);
    die('આ લિંક બરાબર નથી અથવા જૂની થઈ ગઈ છે.');
}

$shop = setting('app_name', $sale['company_name']);
$bank = default_bank_account();
$vpa  = $bank['upi_id'] ?? '';
$payee = $bank['account_name'] ?? $shop;

// what is really left on this bill - the ledger-capped rule, not total-paid
$due = $sale['is_cancelled'] ? 0.0 : sale_true_due($sale);
$note = $sale['invoice_no'];

// "I have paid" tells the shop to go and check. It is a CLAIM, never a
// confirmation: a direct UPI transfer lands in the bank with nothing coming
// back to this software, so no bill is ever marked paid from here.
$said = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'paid_claim' && $due > 0.009) {
    try {
        tg_notify_admins("💬 ગ્રાહકે કહ્યું કે પેમેન્ટ કરી દીધું\n"
            . "બિલ: " . $sale['invoice_no'] . "\n"
            . "રકમ: ₹" . money($due) . "\n"
            . ($sale['customer_name'] ? "ગ્રાહક: " . $sale['customer_name'] . "\n" : '')
            . "બેંકમાં તપાસીને પેમેન્ટ નોંધી લેજો.");
    } catch (Throwable $e) { /* telling the shop must never break the page */ }
    log_activity('pay_claim', $sale['invoice_no'] . ' ₹' . money($due));
    $said = true;
}

$upi = ($vpa && $due > 0.009) ? upi_uri($vpa, $payee, $due, $note) : null;
$qr  = ($vpa && $due > 0.009) ? invoice_qr_web_path($sale, $due) : null;
?><!DOCTYPE html>
<html lang="gu">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="robots" content="noindex,nofollow">
<title><?= e($shop) ?> — બિલ <?= e($sale['invoice_no']) ?></title>
<style>
  :root { color-scheme: light; --ink:#16202c; --muted:#68727e; --line:#e3e8ef; --brand:#1a56db; --ok:#0f7b3f; }
  * { box-sizing: border-box; }
  body { margin:0; background:#f4f6fa; color:var(--ink);
         font:15px/1.55 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;
         padding: env(safe-area-inset-top,0) 16px calc(24px + env(safe-area-inset-bottom,0)); }
  .wrap { max-width: 420px; margin: 0 auto; padding-block: 22px; }
  .card { background:#fff; border:1px solid var(--line); border-radius:16px; padding:20px; margin-bottom:14px; }
  .shop { font-weight:700; font-size:17px; }
  .inv { color:var(--muted); font-size:13px; margin-top:2px; }
  .amt { font-size:38px; font-weight:800; letter-spacing:-.5px; margin:14px 0 2px; }
  .amt-l { color:var(--muted); font-size:13px; text-transform:uppercase; letter-spacing:.06em; }
  .btn { display:block; width:100%; text-align:center; padding:15px 16px; border-radius:12px;
         font-weight:700; font-size:16px; text-decoration:none; border:1px solid transparent; cursor:pointer; }
  .btn-pay { background:var(--brand); color:#fff; font-size:18px; }
  .btn-app { background:#fff; color:var(--ink); border-color:var(--line); font-weight:600; font-size:15px; margin-top:8px; }
  .btn-ghost { background:#fff; color:var(--brand); border-color:var(--brand); margin-top:10px; font-size:15px; }
  .qr { text-align:center; }
  .qr img { width:200px; height:200px; image-rendering:pixelated; }
  .vpa { font-family:ui-monospace,Menlo,Consolas,monospace; background:#f4f6fa; border:1px solid var(--line);
         border-radius:9px; padding:10px 12px; text-align:center; word-break:break-all; margin-top:10px; font-size:14px; }
  .muted { color:var(--muted); font-size:13px; }
  .paid { text-align:center; padding:8px 0; }
  .paid .tick { font-size:44px; }
  .ok-box { background:#e8f6ee; border:1px solid #bfe3cd; color:var(--ok); border-radius:12px; padding:12px 14px; font-weight:600; }
  h2 { font-size:15px; margin:0 0 10px; }
  hr { border:0; border-top:1px solid var(--line); margin:16px 0; }
</style>
</head>
<body>
<div class="wrap">

  <div class="card">
    <div class="shop"><?= e($shop) ?></div>
    <div class="inv">બિલ <?= e($sale['invoice_no']) ?> · <?= dmy($sale['sale_date']) ?></div>
    <?php if ($due > 0.009): ?>
      <div class="amt-l">ચૂકવવાના</div>
      <div class="amt">₹<?= money($due) ?></div>
      <?php if (money_r($due) < money_r($sale['total'])): ?>
        <div class="muted">બિલ ₹<?= money($sale['total']) ?> માંથી ₹<?= money($sale['total'] - $due) ?> મળી ગયા છે.</div>
      <?php endif; ?>
    <?php else: ?>
      <div class="paid">
        <div class="tick">✅</div>
        <div style="font-weight:700;font-size:18px">
          <?= $sale['is_cancelled'] ? 'આ બિલ રદ થયેલું છે' : 'આ બિલ ચૂકતે થઈ ગયું છે' ?>
        </div>
        <div class="muted">બિલની રકમ ₹<?= money($sale['total']) ?> · કંઈ બાકી નથી</div>
      </div>
    <?php endif; ?>
  </div>

  <?php if ($due > 0.009 && $upi): ?>
    <?php if ($said): ?>
      <div class="card"><div class="ok-box">🙏 આભાર. દુકાનને જાણ કરી દીધી છે — તેઓ બેંકમાં તપાસીને નોંધી લેશે.</div></div>
    <?php endif; ?>

    <div class="card">
      <a class="btn btn-pay" href="<?= e($upi) ?>">₹<?= money($due) ?> ચૂકવો</a>
      <div class="muted" style="text-align:center;margin-top:8px">બટન દબાવો એટલે તમારી UPI એપ ખૂલશે</div>
      <?php foreach (upi_apps() as $app): ?>
        <a class="btn btn-app" href="<?= e(str_replace('upi://pay', $app[1], $upi)) ?>"><?= e($app[0]) ?> થી ચૂકવો</a>
      <?php endforeach; ?>
    </div>

    <?php if ($qr): ?>
    <div class="card qr">
      <h2>અથવા QR સ્કેન કરો</h2>
      <img src="<?= e(base_url($qr)) ?>" alt="UPI QR">
      <div class="muted">બીજા ફોનથી સ્કેન કરીને પણ ચૂકવી શકાય</div>
    </div>
    <?php endif; ?>

    <div class="card">
      <h2>UPI ID</h2>
      <div class="vpa" id="vpa"><?= e($vpa) ?></div>
      <button class="btn btn-ghost" type="button" onclick="cp()">UPI ID કૉપી કરો</button>
      <hr>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="paid_claim">
        <button class="btn btn-ghost" type="submit">મેં ચૂકવી દીધું છે — દુકાનને જાણ કરો</button>
      </form>
      <p class="muted" style="margin-bottom:0">
        UPI થી સીધા પૈસા દુકાનની બેંકમાં જાય છે, એટલે આ પાનું જાતે "ચૂકવાઈ ગયું" નથી કરી શકતું.
        દુકાન બેંકમાં જોઈને નોંધશે.
      </p>
    </div>
  <?php elseif ($due > 0.009 && !$vpa): ?>
    <div class="card">
      <p style="margin:0">ઓનલાઇન ચૂકવવાની સગવડ હજી ચાલુ નથી.
        <?= $sale['c_phone'] ? 'દુકાનનો સંપર્ક કરો: ' . e($sale['c_phone']) : 'દુકાનનો સંપર્ક કરો.' ?></p>
    </div>
  <?php endif; ?>

  <p class="muted" style="text-align:center">
    <?= e($shop) ?><?= $sale['c_phone'] ? ' · ' . e($sale['c_phone']) : '' ?>
  </p>
</div>
<script>
function cp() {
  var t = document.getElementById('vpa').textContent.trim();
  if (navigator.clipboard) navigator.clipboard.writeText(t).then(ok, no); else no();
  function ok() { alert('કૉપી થઈ ગયું: ' + t); }
  function no() { window.prompt('આ UPI ID કૉપી કરો:', t); }
}
</script>
</body>
</html>
