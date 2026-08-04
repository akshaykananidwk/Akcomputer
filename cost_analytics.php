<?php
// Cost Analytics: what does running this system cost? Every AI call logs its
// real token counts and every WhatsApp send logs its type into api_usage
// (v49), and this page prices them with owner-editable rates (India pricing
// defaults). Estimation only by design - real billing APIs can plug in later.
// New services (SMS / Email / OCR / Maps / storage) just log new `service`
// values via api_usage_log() and appear here automatically.
require_once __DIR__ . '/includes/init.php';
require_login();
if (!is_full_admin()) {
    http_response_code(403);
    include __DIR__ . '/includes/header.php';
    echo '<div class="card"><h2>Access denied</h2><p>Only the admin can see Cost Analytics.</p></div>';
    include __DIR__ . '/includes/footer.php';
    exit;
}

// ---- editable pricing (defaults: Gemini flash + Meta WhatsApp India) ----
$PRICE_DEFS = [
    'cost_gemini_in'   => ['26',   'Gemini input ₹ / 10 લાખ tokens'],
    'cost_gemini_out'  => ['210',  'Gemini output ₹ / 10 લાખ tokens'],
    'cost_wa_utility'  => ['0.115', 'WhatsApp Utility template ₹/msg'],
    'cost_wa_auth'     => ['0.115', 'WhatsApp Authentication (OTP) ₹/msg'],
    'cost_wa_marketing' => ['0.78', 'WhatsApp Marketing ₹/msg'],
];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'save_pricing') {
    foreach (array_keys($PRICE_DEFS) as $k) set_setting($k, (string)max(0, (float)post($k)));
    log_activity('cost_pricing_save');
    flash('Pricing saved — બધા આંકડા નવા ભાવે ગણાયા.');
    redirect('cost_analytics.php');
}
$P = [];
foreach ($PRICE_DEFS as $k => $d) $P[$k] = (float)setting($k, $d[0]);

/** Priced summary of api_usage since $from (null = lifetime).
 *  Returns ['cost','calls','rows'=>service breakdown]. */
function cost_summary($from) {
    global $P;
    $w = $from ? "WHERE created_at >= '$from'" : '';
    $rows = [];
    try {
        $rows = all("SELECT service, provider, SUM(units_in) uin, SUM(units_out) uout, SUM(calls) c
                     FROM api_usage $w GROUP BY service, provider");
    } catch (Exception $e) { return ['cost' => 0, 'calls' => 0, 'by' => [], 'err' => true]; }
    $by = []; $cost = 0; $calls = 0;
    foreach ($rows as $r) {
        $svc = $r['service']; $prov = $r['provider'];
        $c = 0;
        if ($svc === 'gemini') {
            // only the PAID key costs money; free-tier rows price at ₹0
            if (strpos($prov, 'paid:') === 0) $c = $r['uin'] / 1e6 * $P['cost_gemini_in'] + $r['uout'] / 1e6 * $P['cost_gemini_out'];
        } elseif ($svc === 'whatsapp') {
            if (strpos($prov, 'meta:tpl:') === 0) {
                $c = $r['uout'] * (strpos($prov, 'akc_otp') !== false ? $P['cost_wa_auth'] : $P['cost_wa_utility']);
            } // meta:freeform (24h service window) and gateway = ₹0 here
        }
        $cost += $c; $calls += $r['c'];
        $by[$svc]['cost'] = ($by[$svc]['cost'] ?? 0) + $c;
        $by[$svc]['calls'] = ($by[$svc]['calls'] ?? 0) + $r['c'];
        $by[$svc]['uin'] = ($by[$svc]['uin'] ?? 0) + $r['uin'];
        $by[$svc]['uout'] = ($by[$svc]['uout'] ?? 0) + $r['uout'];
        $by[$svc]['prov'][$prov] = ['c' => $r['c'], 'uin' => $r['uin'], 'uout' => $r['uout'], 'cost' => $c];
    }
    return ['cost' => $cost, 'calls' => $calls, 'by' => $by];
}

$today = cost_summary(today() . ' 00:00:00');
$week = cost_summary(date('Y-m-d 00:00:00', strtotime('-6 days')));
$month = cost_summary(date('Y-m-01 00:00:00'));
$life = cost_summary(null);

// context ratios (this month)
$mOrders = (int)val("SELECT COUNT(*) FROM sales WHERE is_cancelled = 0 AND sale_date >= ?", [date('Y-m-01')])
         + (int)val("SELECT COUNT(*) FROM web_orders WHERE created_at >= ?", [date('Y-m-01')]);
$mUsers = 0;
try { $mUsers = (int)val("SELECT COUNT(DISTINCT mobile) FROM wa_chats WHERE created_at >= ?", [date('Y-m-01')]); } catch (Exception $e) {}
$mUsers = max($mUsers, (int)val("SELECT COUNT(DISTINCT party_id) FROM sales WHERE party_id IS NOT NULL AND sale_date >= ?", [date('Y-m-01')]));

// legacy (pre-metering) history, so "lifetime" is honest about older use
$legacyAi = 0; $legacyWa = 0;
try { $legacyAi = (int)val("SELECT COUNT(*) FROM wa_bot_log WHERE used_ai = 1"); } catch (Exception $e) {}
try { $legacyWa = (int)val("SELECT COUNT(*) FROM wa_chats WHERE direction = 'out' AND via = 'meta'"); } catch (Exception $e) {}

$svcMeta = [
    'gemini' => ['🧠', 'AI — Google Gemini', 'tokens'],
    'whatsapp' => ['💬', 'WhatsApp', 'messages'],
    'sms' => ['✉️', 'SMS', 'messages'], 'email' => ['📧', 'Email', 'messages'],
    'ocr' => ['📷', 'OCR', 'calls'], 'maps' => ['🗺️', 'Google Maps', 'calls'], 'storage' => ['💾', 'Storage', 'units'],
];
$page_title = 'Cost Analytics';
include __DIR__ . '/includes/header.php';
?>
<div class="page-actions no-print"><h2 style="margin:0">💰 Cost Analytics</h2></div>
<?php if (!empty($life['err'])): ?>
<div class="card"><p class="flash flash-error">પહેલા Settings → <strong>Migrate</strong> ચલાવો (v49 — usage મીટરિંગ ટેબલ). પછી દરેક AI કૉલ અને WhatsApp મેસેજ અહીં આપોઆપ ગણાશે.</p></div>
<?php else: ?>

<div class="grid-stats">
  <div class="stat"><div class="stat-label">આજનો ખર્ચ</div><div class="stat-value">₹<?= money($today['cost']) ?></div></div>
  <div class="stat"><div class="stat-label">7 દિવસ</div><div class="stat-value">₹<?= money($week['cost']) ?></div></div>
  <div class="stat"><div class="stat-label">આ મહિનો</div><div class="stat-value">₹<?= money($month['cost']) ?></div></div>
  <div class="stat"><div class="stat-label">Lifetime (મીટરિંગ ચાલુ થયું ત્યારથી)</div><div class="stat-value">₹<?= money($life['cost']) ?></div></div>
</div>
<div class="grid-stats">
  <div class="stat"><div class="stat-label">Cost / ગ્રાહક (મહિનો)</div><div class="stat-value">₹<?= money($mUsers ? $month['cost'] / $mUsers : 0) ?></div></div>
  <div class="stat"><div class="stat-label">Cost / ઓર્ડર-બિલ (મહિનો)</div><div class="stat-value">₹<?= money($mOrders ? $month['cost'] / $mOrders : 0) ?></div></div>
  <div class="stat"><div class="stat-label">Cost / API કૉલ (મહિનો)</div><div class="stat-value">₹<?= money($month['calls'] ? $month['cost'] / $month['calls'] : 0) ?></div></div>
  <div class="stat"><div class="stat-label">કુલ API કૉલ (મહિનો)</div><div class="stat-value"><?= number_format($month['calls']) ?></div></div>
</div>

<?php foreach ($life['by'] + array_intersect_key($svcMeta, []) as $svc => $l):
    $sm = $svcMeta[$svc] ?? ['🔌', strtoupper($svc), 'units'];
    $m = $month['by'][$svc] ?? ['cost' => 0, 'calls' => 0, 'uin' => 0, 'uout' => 0, 'prov' => []];
    $t = $today['by'][$svc] ?? ['cost' => 0, 'calls' => 0]; ?>
<div class="card">
  <h3><?= $sm[0] ?> <?= e($sm[1]) ?></h3>
  <div class="grid-stats">
    <div class="stat"><div class="stat-label">આજે</div><div class="stat-value">₹<?= money($t['cost']) ?> <span class="muted" style="font-size:12px">(<?= number_format($t['calls']) ?>)</span></div></div>
    <div class="stat"><div class="stat-label">મહિનો</div><div class="stat-value">₹<?= money($m['cost']) ?> <span class="muted" style="font-size:12px">(<?= number_format($m['calls']) ?>)</span></div></div>
    <div class="stat"><div class="stat-label">Lifetime</div><div class="stat-value">₹<?= money($l['cost']) ?> <span class="muted" style="font-size:12px">(<?= number_format($l['calls']) ?>)</span></div></div>
    <?php if ($svc === 'gemini'): ?>
    <div class="stat"><div class="stat-label">Tokens (મહિનો)</div><div class="stat-value" style="font-size:16px">In <?= number_format($m['uin']) ?> · Out <?= number_format($m['uout']) ?><br><span class="muted" style="font-size:12px">Total <?= number_format($m['uin'] + $m['uout']) ?></span></div></div>
    <?php endif; ?>
  </div>
  <div class="table-wrap" style="box-shadow:none"><table class="table-sm">
    <thead><tr><th><?= $svc === 'gemini' ? 'Key · Model' : 'પ્રકાર' ?></th><th class="num">Calls/Msgs</th><?php if ($svc === 'gemini'): ?><th class="num">In tokens</th><th class="num">Out tokens</th><?php endif; ?><th class="num">ખર્ચ (lifetime)</th></tr></thead>
    <tbody>
    <?php foreach ($l['prov'] as $prov => $pr):
        $label = $prov;
        if ($svc === 'whatsapp') $label = $prov === 'meta:freeform' ? '🟢 Free-form/મેનુ (24h window — ફ્રી)' : ($prov === 'gateway' ? '📨 થર્ડ-પાર્ટી ગેટવે (તમારું રિચાર્જ)' : (strpos($prov, 'akc_otp') !== false ? '🔐 Authentication (OTP) template' : '📋 Utility template — ' . str_replace('meta:tpl:', '', $prov)));
        if ($svc === 'gemini') $label = (strpos($prov, 'paid:') === 0 ? '💳 Paid · ' : '🟢 Free · ') . preg_replace('/^(paid|free):/', '', $prov); ?>
    <tr><td><?= e($label) ?></td><td class="num"><?= number_format($pr['c']) ?></td>
        <?php if ($svc === 'gemini'): ?><td class="num"><?= number_format($pr['uin']) ?></td><td class="num"><?= number_format($pr['uout']) ?></td><?php endif; ?>
        <td class="num">₹<?= money($pr['cost']) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endforeach; ?>
<?php if (!$life['by']): ?><div class="card"><p class="muted">હજી કોઈ usage નોંધાયું નથી — હવે પછીના દરેક AI કૉલ અને WhatsApp મેસેજ આપોઆપ અહીં ગણાશે.</p></div><?php endif; ?>

<?php if ($legacyAi || $legacyWa): ?>
<div class="card">
  <h3>🕰️ મીટરિંગ પહેલાનો જૂનો વપરાશ (અંદાજ)</h3>
  <p class="muted" style="font-size:13px">ટોકન-લેવલ ગણતરી હમણાં ચાલુ થઈ — એ પહેલા થયેલો વપરાશ: AI જવાબ ≈ <strong><?= number_format($legacyAi) ?></strong> કૉલ (ફ્રી ટિયર ≈ ₹0) · Meta WhatsApp ≈ <strong><?= number_format($legacyWa) ?></strong> મેસેજ (મહત્તમ ≈ ₹<?= money($legacyWa * $P['cost_wa_utility']) ?>, 24h-window વાળા ખરેખર ફ્રી હતા).</p>
</div>
<?php endif; ?>

<div class="card">
  <h3>⚙️ Pricing (India) — જરૂર પડે ત્યારે અહીંથી બદલો</h3>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="save_pricing">
    <div class="form-row cols-2">
    <?php foreach ($PRICE_DEFS as $k => $d): ?>
      <div><label><?= e($d[1]) ?></label><input type="number" step="0.001" min="0" name="<?= $k ?>" value="<?= e(setting($k, $d[0])) ?>"></div>
    <?php endforeach; ?>
    </div>
    <button class="btn" type="submit">Save Pricing</button>
  </form>
  <p class="muted mt" style="font-size:12.5px">📌 આ અંદાજ છે (real billing API જોડેલી નથી — જરૂર પડશે ત્યારે જોડીશું). Gemini ફ્રી-કી ના કૉલ ₹0 ગણાય છે, ફક્ત 💳 paid-key કૉલ ટોકન-ભાવે ગણાય. WhatsApp માં 24-કલાક window ના જવાબ ફ્રી, ફક્ત template મેસેજ ભાવે ગણાય. ભવિષ્યની સર્વિસ (SMS/Email/OCR/Maps) api_usage_log() થી લોગ થાય એટલે અહીં આપોઆપ દેખાશે.</p>
</div>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
