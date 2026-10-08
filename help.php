<?php
// ❓ Help: ask a question, watch a video, practise on the demo shop, learn
// the shortcut keys, or take the tour of the screen again.
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/help.php';
require_login();
$lang = ui_prefs()['lang'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'ask') {
    header('Content-Type: application/json');
    if (!api_rate_ok('help:' . current_user()['id'], 20, 3600)) { echo json_encode(['answer' => 'Too many questions this hour — please try again later.']); exit; }
    [$a, $src] = help_answer(post('q'), $lang);
    echo json_encode(['answer' => $a ?: 'No answer found. Please ask the shop owner.', 'src' => $src], JSON_UNESCAPED_UNICODE);
    exit;
}
// the platform owner lists the help videos once, for every shop
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'videos' && !tenant_active() && can('settings.edit')) {
    set_setting('help_videos', mb_substr((string)post('help_videos'), 0, 5000));
    flash('Saved.');
    redirect('help.php');
}

$demo = null;
try { $demo = pval("SELECT domain FROM tenants WHERE is_demo = 1 AND status <> 'closed' LIMIT 1"); } catch (Exception $e) {}
$page_title = 'Help';
include __DIR__ . '/includes/header.php';
?>
<div class="page-head"><h1>❓ Help</h1><a class="btn btn-sm btn-outline" href="index.php?tour=1">🧭 Show me around</a></div>

<div class="card">
  <form id="helpAsk" style="display:flex;gap:8px;flex-wrap:wrap">
    <input type="text" id="helpQ" placeholder="How do I…? (e.g. how to send a bill on WhatsApp)" style="flex:1;min-width:200px" maxlength="300" required>
    <button class="btn" type="submit">Ask</button>
  </form>
  <div id="helpA" class="mt" style="white-space:pre-line"></div>
</div>

<div class="card"><h3 style="margin-top:0">Common questions</h3>
  <?php foreach (help_faq() as [, $ans]): ?>
    <details style="margin:6px 0"><summary style="cursor:pointer"><?= e(strtok(($lang === 'gu' ? $ans[1] : $ans[0]), ':')) ?></summary>
      <p style="margin:6px 0 0"><?= e($lang === 'gu' ? $ans[1] : $ans[0]) ?></p></details>
  <?php endforeach; ?>
</div>

<?php $vids = help_videos(); if ($vids || (!tenant_active() && can('settings.edit'))): ?>
<div class="card"><h3 style="margin-top:0">🎬 Videos</h3>
  <?php foreach ($vids as [$t, $u]): ?><p style="margin:4px 0">▶️ <a href="<?= e($u) ?>" target="_blank" rel="noopener"><?= e($t) ?></a></p><?php endforeach; ?>
  <?php if (!tenant_active() && can('settings.edit')): ?>
  <form method="post" class="mt"><?= csrf_field() ?><input type="hidden" name="do" value="videos">
    <label>Videos every shop sees — one per line: <code>Title | https://youtube.com/…</code></label>
    <textarea name="help_videos" rows="4"><?= e(setting('help_videos', '')) ?></textarea>
    <button class="btn btn-sm" type="submit">Save videos</button></form>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($demo): ?>
<div class="card"><h3 style="margin-top:0">🧪 Practice</h3>
  <p>Try anything without touching your real shop: <a href="<?= e((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://') . $demo) ?>/login.php" target="_blank" rel="noopener"><b>open the practice shop</b></a>
  — login <b>demo</b> / <b>demo1234</b>. It is wiped clean every night.</p></div>
<?php endif; ?>

<div class="card"><h3 style="margin-top:0">⌨️ Shortcut keys</h3>
  <table class="rowlist"><tbody>
  <?php foreach ([['Alt + N', 'New bill'], ['Alt + P', 'Payment in'], ['Alt + E', 'New expense'], ['Alt + I', 'Items'], ['Alt + H', 'Dashboard'],
                  ['Ctrl + K', 'Search'], ['F2', 'Add item (on a bill)'], ['Ctrl + S', 'Save the bill'], ['?', 'Show these keys'], ['Esc', 'Close']] as [$k, $d]): ?>
    <tr><td><kbd><?= e($k) ?></kbd></td><td><?= e($d) ?></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>

<script>
document.getElementById('helpAsk').addEventListener('submit', function (ev) {
  ev.preventDefault();
  var out = document.getElementById('helpA'); out.textContent = '…';
  var fd = new FormData(); fd.append('csrf', CSRF_TOKEN); fd.append('do', 'ask'); fd.append('q', document.getElementById('helpQ').value);
  fetch('help.php', { method: 'POST', body: fd }).then(function (r) { return r.json(); })
    .then(function (d) { out.textContent = d.answer + (d.src === 'ai' ? '\n\n(🤖 answered by AI — check before relying on it)' : ''); })
    .catch(function () { out.textContent = 'Could not reach the server.'; });
});
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
