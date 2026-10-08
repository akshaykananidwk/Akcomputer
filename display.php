<?php
// 🖥️ The customer's screen: open it on a second monitor (or a tablet facing
// the customer, in the same browser) and it shows what is being billed and
// the total, live from the counter screen. Nothing is sent to the server -
// the two tabs talk inside the browser.
require_once __DIR__ . '/includes/init.php';
require_perm('sales.add');
$name = setting('app_name', '');
$upi = val("SELECT upi_id FROM bank_accounts WHERE is_active = 1 AND upi_id <> '' ORDER BY is_default DESC, id LIMIT 1");
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Customer screen</title>
<style>
body{margin:0;background:#0b1220;color:#f8fafc;font-family:system-ui,-apple-system,'Segoe UI',Roboto,'Noto Sans Gujarati',sans-serif;min-height:100vh;display:flex;flex-direction:column}
header{padding:18px 26px;font-size:26px;font-weight:700;border-bottom:1px solid #1e293b}
main{flex:1;padding:16px 26px;overflow:auto}
.l{display:flex;justify-content:space-between;font-size:24px;padding:10px 0;border-bottom:1px solid #1e293b}
.l span:first-child{flex:1;padding-right:12px}.l small{color:#94a3b8}
footer{padding:20px 26px;background:#111a2e;display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}
.t{font-size:56px;font-weight:800;color:#fbbf24;font-variant-numeric:tabular-nums}
.welcome{color:#94a3b8;font-size:28px;text-align:center;margin-top:20vh}
</style></head><body>
<header><?= e($name) ?></header>
<main id="m"><div class="welcome">🙏 Welcome</div></main>
<footer><div><?= $upi ? 'UPI: <b>' . e($upi) . '</b>' : '' ?></div><div>Total <span class="t">₹<span id="t">0</span></span></div></footer>
<script>
function show(s) {
  if (!s) return;
  var esc = function (t) { return String(t).replace(/[&<>]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c]; }); };
  document.getElementById('m').innerHTML = s.lines && s.lines.length
    ? s.lines.map(function (l) { return '<div class="l"><span>' + esc(l[0]) + ' <small>× ' + l[1] + '</small></span><span>₹' + Number(l[2]).toLocaleString('en-IN') + '</span></div>'; }).join('')
    : '<div class="welcome">🙏 Welcome</div>';
  document.getElementById('t').textContent = Number(s.total || 0).toLocaleString('en-IN');
}
try { show(JSON.parse(localStorage.getItem('akDisplay') || 'null')); } catch (e) {}
if ('BroadcastChannel' in window) new BroadcastChannel('ak-display').onmessage = function (ev) { show(ev.data); };
window.addEventListener('storage', function (ev) { if (ev.key === 'akDisplay') try { show(JSON.parse(ev.newValue)); } catch (e) {} });
</script>
</body></html>
