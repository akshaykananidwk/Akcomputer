<?php
// ⭐ A tablet on the counter: the customer taps 1-5 stars (and may write a
// line) on the way out. Opened once by staff; it then resets by itself for
// the next customer. Every rating is real - nothing is filled in for them.
require_once __DIR__ . '/includes/init.php';
require_perm('sales.add');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $r = (int)post('rating');
    if ($r >= 1 && $r <= 5 && api_rate_ok('kiosk:' . current_user()['id'], 30, 300)) {
        q("INSERT INTO feedback (ref_type, ref_id, token, customer_name, rating, comment, submitted_at) VALUES ('kiosk', 0, ?, ?, ?, ?, NOW())",
          [share_token(), mb_substr(trim((string)post('name')), 0, 120), $r, mb_substr(trim((string)post('comment')), 0, 500)]);
        if ($r <= 2) owner_alert('⚠️ A customer at the counter rated ' . $r . '/5' . (post('comment') ? ': ' . mb_substr(post('comment'), 0, 150) : ''));
    }
    header('Content-Type: application/json'); echo '{"ok":1}'; exit;
}
$avg = row("SELECT AVG(rating) a, COUNT(*) n FROM feedback WHERE ref_type = 'kiosk' AND submitted_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Feedback</title>
<style>body{margin:0;min-height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:center;background:#0f172a;color:#f8fafc;font-family:system-ui,'Noto Sans Gujarati',sans-serif;text-align:center}
h1{font-size:30px;margin:0 16px 20px}.stars{display:flex;gap:8px}.stars button{font-size:56px;background:none;border:0;cursor:pointer;color:#475569;padding:0 4px}.stars button.on{color:#fbbf24}
textarea,input{width:min(90vw,460px);padding:12px;border-radius:10px;border:0;font:16px system-ui;margin:6px 0}#send{padding:14px 40px;font-size:18px;border:0;border-radius:10px;background:#22c55e;color:#fff;margin-top:8px}
#more{display:none;flex-direction:column;align-items:center}.foot{position:fixed;bottom:8px;font-size:12px;color:#64748b}</style></head><body>
<h1 id="q">How was your visit to <?= e(setting('app_name', '')) ?>?<br><small style="font-size:18px;color:#94a3b8">આજે અમારી સેવા કેવી લાગી?</small></h1>
<div class="stars" id="stars"><?php for ($i = 1; $i <= 5; $i++): ?><button type="button" data-r="<?= $i ?>" aria-label="<?= $i ?> star<?= $i > 1 ? 's' : '' ?>">★</button><?php endfor; ?></div>
<div id="more"><textarea id="comment" rows="3" placeholder="Anything to tell us? (optional)"></textarea><input id="name" placeholder="Your name (optional)"><button id="send">Send</button></div>
<div class="foot">Last 30 days: <?= $avg['n'] ? round($avg['a'], 1) . ' ★ from ' . (int)$avg['n'] : 'no ratings yet' ?></div>
<script>
var rating = 0, stars = document.querySelectorAll('#stars button');
stars.forEach(function (b) { b.addEventListener('click', function () {
  rating = +b.dataset.r; stars.forEach(function (s) { s.classList.toggle('on', +s.dataset.r <= rating); });
  document.getElementById('more').style.display = 'flex';
}); });
document.getElementById('send').addEventListener('click', function () {
  var fd = new FormData(); fd.append('csrf', <?= json_encode(csrf_token()) ?>); fd.append('rating', rating);
  fd.append('comment', document.getElementById('comment').value); fd.append('name', document.getElementById('name').value);
  fetch('kiosk.php', { method: 'POST', body: fd }).then(function () {
    document.getElementById('q').textContent = '🙏 Thank you! / આભાર!'; document.getElementById('stars').style.display = 'none'; document.getElementById('more').style.display = 'none';
    setTimeout(function () { location.reload(); }, 3500);
  });
});
</script></body></html>
