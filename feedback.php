<?php
// Public post-job feedback page - no login required, reached via the
// WhatsApp link sent when a repair job is delivered / a field task is
// completed. Each token is single-use (already-submitted just shows a
// thank-you instead of the form again).
require_once __DIR__ . '/includes/init.php';

$token = get('token');
$fb = $token ? row('SELECT * FROM feedback WHERE token = ?', [$token]) : null;
if (!$fb) { http_response_code(404); die('Invalid or expired feedback link.'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'submit' && !$fb['submitted_at']) {
    $rating = max(1, min(5, (int)post('rating')));
    q('UPDATE feedback SET rating = ?, comment = ?, submitted_at = NOW() WHERE id = ?',
      [$rating, mb_substr(post('comment'), 0, 500), $fb['id']]);
    redirect('feedback.php?token=' . $token);
}
$fb = row('SELECT * FROM feedback WHERE token = ?', [$token]); // re-fetch after possible submit

$page_title = 'Feedback';
include __DIR__ . '/includes/header.php';
?>
<div class="card" style="max-width:480px;margin:20px auto">
  <?php if ($fb['submitted_at']): ?>
    <h2>🙏 Thank you!</h2>
    <p class="muted">તમારો feedback મળી ગયો.</p>
    <p class="mt">Your rating: <?php for ($i = 1; $i <= 5; $i++): ?><?= $i <= $fb['rating'] ? '⭐' : '☆' ?><?php endfor; ?></p>
    <?php if ($fb['comment']): ?><p class="mt muted">"<?= e($fb['comment']) ?>"</p><?php endif; ?>
  <?php else: ?>
    <h2>How was our service?</h2>
    <p class="muted mb">Hi <?= e($fb['customer_name'] ?: 'there') ?>, please rate your recent <?= $fb['ref_type'] === 'repair' ? 'repair job' : 'service visit' ?>.</p>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="submit">
      <div class="field">
        <label>Rating</label>
        <div id="starPick" style="font-size:36px;letter-spacing:6px;cursor:pointer">☆☆☆☆☆</div>
        <input type="hidden" name="rating" id="ratingVal" value="0">
      </div>
      <div class="field"><label>Comments (optional)</label><textarea name="comment" rows="3"></textarea></div>
      <button class="btn btn-block" type="submit">Submit Feedback</button>
    </form>
    <script>
      var stars = document.getElementById('starPick');
      function paint(n) { stars.textContent = '⭐⭐⭐⭐⭐'.slice(0, n * 2) + '☆☆☆☆☆'.slice(n * 2); }
      stars.addEventListener('click', function (e) {
        var rect = stars.getBoundingClientRect();
        var n = Math.min(5, Math.max(1, Math.ceil((e.clientX - rect.left) / (rect.width / 5))));
        document.getElementById('ratingVal').value = n;
        paint(n);
      });
    </script>
  <?php endif; ?>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
