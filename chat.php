<?php
// 💬 Staff chat inside the software: to everyone, or to one person. Only
// people logged in to this shop can read it; it is checked every few seconds.
require_once __DIR__ . '/includes/init.php';
require_login();
$u = current_user();
$with = (int)get('with');   // 0 = everyone

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'send') {
    $body = trim(mb_substr((string)post('body'), 0, 1000));
    if ($body !== '' && api_rate_ok('chat:' . $u['id'], 30, 60)) {
        $to = (int)post('to') ?: null;
        if ($to && !val('SELECT id FROM users WHERE id = ? AND is_active = 1', [$to])) $to = null;
        q('INSERT INTO chat_messages (from_user, to_user, body) VALUES (?,?,?)', [$u['id'], $to, $body]);
    }
    if (post('ajax')) { header('Content-Type: application/json'); echo '{"ok":1}'; exit; }
    redirect('chat.php' . ($with ? "?with=$with" : ''));
}

$where = $with ? '((m.from_user = :me AND m.to_user = :w) OR (m.from_user = :w AND m.to_user = :me))' : 'm.to_user IS NULL';
$st = db()->prepare("SELECT m.*, us.name FROM chat_messages m JOIN users us ON us.id = m.from_user WHERE $where AND m.id > :after ORDER BY m.id DESC LIMIT 100");
$st->execute($with ? [':me' => $u['id'], ':w' => $with, ':after' => (int)get('after')] : [':after' => (int)get('after')]);
$msgs = array_reverse($st->fetchAll());
$last = (int)val('SELECT COALESCE(MAX(id), 0) FROM chat_messages');
q('INSERT INTO chat_reads (user_id, last_id) VALUES (?,?) ON DUPLICATE KEY UPDATE last_id = GREATEST(last_id, VALUES(last_id))', [$u['id'], $last]);

if (get('ajax')) {
    header('Content-Type: application/json');
    echo json_encode(array_map(fn($m) => ['id' => (int)$m['id'], 'me' => (int)$m['from_user'] === (int)$u['id'], 'name' => $m['name'],
        'body' => $m['body'], 'at' => date('d-m h:i A', strtotime($m['created_at']))], $msgs), JSON_UNESCAPED_UNICODE);
    exit;
}
$people = all('SELECT id, name FROM users WHERE is_active = 1 AND id <> ? ORDER BY name', [$u['id']]);
$page_title = 'Staff chat';
include __DIR__ . '/includes/header.php';
?>
<style>.chat{height:55vh;overflow-y:auto;display:flex;flex-direction:column;gap:6px;padding:8px}.cm{max-width:80%;padding:7px 10px;border-radius:10px;background:var(--card-alt)}.cm.me{align-self:flex-end;background:#dbeafe;color:#0f172a}.cm small{display:block;color:var(--muted);font-size:11px}</style>
<div class="page-head"><h1>💬 Staff chat</h1>
  <form><select name="with" onchange="this.form.submit()"><option value="0">👥 Everyone</option><?php foreach ($people as $p): ?><option value="<?= (int)$p['id'] ?>" <?= $with === (int)$p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?></select></form></div>
<div class="card" style="padding:0"><div class="chat" id="chat"></div>
  <form method="post" id="chatForm" style="display:flex;gap:6px;padding:8px;border-top:1px solid var(--border)"><?= csrf_field() ?><input type="hidden" name="do" value="send"><input type="hidden" name="to" value="<?= $with ?>">
    <input type="text" name="body" id="chatBody" placeholder="Write a message…" maxlength="1000" autocomplete="off" style="flex:1" required><button class="btn" type="submit">Send</button></form></div>
<script>
(function () {
  var box = document.getElementById('chat'), after = 0, url = 'chat.php?ajax=1&with=<?= $with ?>';
  var esc = function (t) { return String(t).replace(/[&<>]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c]; }); };
  function pull() {
    fetch(url + '&after=' + after).then(function (r) { return r.json(); }).then(function (list) {
      list.forEach(function (m) {
        after = Math.max(after, m.id);
        var d = document.createElement('div'); d.className = 'cm' + (m.me ? ' me' : '');
        d.innerHTML = (m.me ? '' : '<b>' + esc(m.name) + '</b><br>') + esc(m.body) + '<small>' + m.at + '</small>';
        box.appendChild(d);
      });
      if (list.length) box.scrollTop = box.scrollHeight;
    });
  }
  document.getElementById('chatForm').addEventListener('submit', function (ev) {
    ev.preventDefault();
    var fd = new FormData(this); fd.append('ajax', '1');
    fetch('chat.php', { method: 'POST', body: fd }).then(function () { document.getElementById('chatBody').value = ''; pull(); });
  });
  pull(); setInterval(pull, 5000);
})();
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
