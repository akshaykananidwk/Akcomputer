<?php
// WhatsApp Inbox (admin): every incoming customer message and every outgoing
// message (bot/bill/receipt/staff reply) in one place - see what arrived,
// what was answered, and reply right here. Replies go through the normal
// send_whatsapp() provider order (third-party / Meta with auto-backup).
require_once __DIR__ . '/includes/init.php';
require_login();
if (!is_full_admin()) {
    http_response_code(403);
    include __DIR__ . '/includes/header.php';
    echo '<div class="card"><h2>Access denied</h2><p>Only the admin can open the WhatsApp Inbox.</p></div>';
    include __DIR__ . '/includes/footer.php';
    exit;
}

$m = preg_replace('/\D/', '', (string)get('m'));

// ---- send a reply ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'reply') {
    $to = preg_replace('/\D/', '', post('to'));
    $body = trim(post('body'));
    if ($to !== '' && $body !== '') {
        if (send_whatsapp($to, $body)) flash('Message sent ✔');
        else flash('Send error: ' . whatsapp_last_error(), 'error');
    }
    redirect('wa_inbox.php?m=' . $to);
}

/**
 * What became of one outgoing message.
 *
 * "Sent" only ever meant the provider accepted it. The receipt saying
 * whether it was delivered, read, or failed arrives later - and until this
 * was kept, a message that failed looked exactly like one that was read.
 */
function wa_status_mark(array $x) {
    $s = (string)($x['status'] ?? '');
    $when = !empty($x['status_at']) ? ' ' . dmyt($x['status_at']) : '';
    $m = [
        'sent'      => ['✓', 'The provider took it — not yet on their phone'],
        'delivered' => ['✓✓', 'On their phone'],
        'read'      => ['✓✓ read', 'They opened it'],
        'failed'    => ['❌ did not arrive', 'It never reached them'],
    ];
    if (!isset($m[$s])) return '';
    return ' · <span title="' . htmlspecialchars($m[$s][1] . $when, ENT_QUOTES) . '">'
         . $m[$s][0] . '</span>';
}

$page_title = 'WhatsApp Inbox';
include __DIR__ . '/includes/header.php';

// party names by last-10-digits so chats show WHO it is, not just a number
function wa_inbox_party($mobile) {
    static $cache = [];
    $ten = substr($mobile, -10);
    if (!isset($cache[$ten])) {
        $cache[$ten] = row("SELECT id, name FROM parties WHERE REPLACE(REPLACE(mobile, '+', ''), ' ', '') LIKE ? LIMIT 1", ['%' . $ten]);
    }
    return $cache[$ten];
}
?>
<style>
.wa-list .list-row-sub { font-size: 12.5px; }
.wa-unread { background: #22c55e; color: #fff; border-radius: 999px; font-size: 11.5px; font-weight: 800; padding: 2px 8px; }
.wa-thread { display: flex; flex-direction: column; gap: 8px; padding: 6px 0 90px; }
.wa-b { max-width: 82%; border-radius: 14px; padding: 9px 12px; font-size: 14.5px; line-height: 1.45; white-space: pre-wrap; word-break: break-word; }
.wa-in { align-self: flex-start; background: var(--card); border: 1px solid var(--border); border-bottom-left-radius: 4px; }
.wa-out { align-self: flex-end; background: #dcf8c6; color: #111; border-bottom-right-radius: 4px; }
[data-theme="dark"] .wa-out { background: #005c4b; color: #e8edf5; }
@media (prefers-color-scheme: dark) { :root:not([data-theme="light"]) .wa-out { background: #005c4b; color: #e8edf5; } }
.wa-meta { font-size: 11px; color: var(--muted); margin-top: 4px; }
.wa-fail { font-size: 12px; margin-top: 6px; padding: 6px 8px; border-radius: 8px;
           background: rgba(220,38,38,.10); color: #b91c1c; }
.wa-replybar { position: sticky; bottom: calc(var(--bottomnav-h) + 8px); display: flex; gap: 8px; background: var(--card); border: 1px solid var(--border); border-radius: 14px; padding: 8px; box-shadow: 0 4px 14px rgba(0,0,0,.15); }
.wa-replybar textarea { flex: 1; border: 0; resize: none; background: transparent; color: var(--text); font-size: 15px; min-height: 42px; }
.wa-replybar textarea:focus { outline: none; box-shadow: none; }
</style>

<?php if ($m === ''):
    // ---------------- conversation list ----------------
    try {
        $convos = all("SELECT mobile, MAX(created_at) last_at,
                       SUM(CASE WHEN direction = 'in' AND is_read = 0 THEN 1 ELSE 0 END) unread,
                       SUBSTRING_INDEX(GROUP_CONCAT(CONCAT(direction, ': ', LEFT(COALESCE(body,''), 80)) ORDER BY id DESC SEPARATOR '\n'), '\n', 1) last_msg
                       FROM wa_chats GROUP BY mobile ORDER BY last_at DESC LIMIT 100");
    } catch (Exception $e) {
        echo '<div class="card"><p>Run Settings → Migrate first (v46 - the chat table).</p></div>';
        include __DIR__ . '/includes/footer.php';
        exit;
    } ?>
<div class="card wa-list">
  <h2>💬 WhatsApp Inbox</h2>
  <p class="muted" style="font-size:13px">Every message received and every reply sent (bot, bills and receipts included). Open a chat and reply directly. A new message is also announced on Telegram.</p>
  <?php if (!$convos): ?><p class="muted">No chats yet — as soon as a customer messages the shop WhatsApp number it appears here.</p><?php endif; ?>
  <?php foreach ($convos as $c): $pt = wa_inbox_party($c['mobile']); ?>
  <a class="list-row" href="wa_inbox.php?m=<?= e($c['mobile']) ?>">
    <div class="list-row-main">
      <strong><?= $pt ? e($pt['name']) : '+' . e($c['mobile']) ?></strong>
      <?= $c['unread'] > 0 ? ' <span class="wa-unread">' . (int)$c['unread'] . ' New</span>' : '' ?>
      <div class="list-row-sub muted"><?= e(mb_substr(preg_replace('/^(in|out): /', '', $c['last_msg'] ?? ''), 0, 70)) ?></div>
    </div>
    <div class="muted" style="font-size:12px;white-space:nowrap"><?= dmyt($c['last_at']) ?></div>
  </a>
  <?php endforeach; ?>
</div>
<script>setTimeout(function () { location.reload(); }, 30000);</script>

<?php else:
    // ---------------- one conversation ----------------
    try {
        q("UPDATE wa_chats SET is_read = 1 WHERE mobile = ? AND direction = 'in'", [$m]);
        $msgs = all('SELECT * FROM wa_chats WHERE mobile = ? ORDER BY id DESC LIMIT 200', [$m]);
    } catch (Exception $e) { $msgs = []; }
    $msgs = array_reverse($msgs);
    $pt = wa_inbox_party($m); ?>
<div class="page-actions no-print">
  <a class="btn btn-outline btn-sm" href="wa_inbox.php">← Inbox</a>
  <?php if ($pt): ?><a class="btn btn-outline btn-sm" href="parties.php?action=ledger&id=<?= (int)$pt['id'] ?>">👤 <?= e($pt['name']) ?> account</a><?php endif; ?>
  <a class="btn btn-outline btn-sm" href="https://wa.me/<?= e($m) ?>" target="_blank" rel="noopener">📱 Open in WhatsApp</a>
</div>
<div class="card">
  <h2><?= $pt ? e($pt['name']) : '+' . e($m) ?> <span class="muted" style="font-weight:400;font-size:13px">+<?= e($m) ?></span></h2>
  <div class="wa-thread">
    <?php if (!$msgs): ?><p class="muted">There are no messages with this number yet.</p><?php endif; ?>
    <?php foreach ($msgs as $x): ?>
    <div class="wa-b <?= $x['direction'] === 'in' ? 'wa-in' : 'wa-out' ?>">
      <?= e($x['body']) ?>
      <?php if ($x['media_url']): ?><div><a href="<?= e($x['media_url']) ?>" target="_blank" rel="noopener">📎 Attachment</a></div><?php endif; ?>
      <div class="wa-meta"><?= dmyt($x['created_at']) ?><?= $x['direction'] === 'out' && $x['via'] ? ' · via ' . e($x['via']) : '' ?>
        <?php if ($x['direction'] === 'out'): ?><?= wa_status_mark($x) ?><?php endif; ?></div>
      <?php if ($x['direction'] === 'out' && ($x['status'] ?? '') === 'failed'): ?>
        <div class="wa-fail">❌ <strong>It did not reach them.</strong>
          <?= e($x['fail_reason'] ?: 'The provider gave no reason.') ?></div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
  <form method="post" class="wa-replybar no-print">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="reply">
    <input type="hidden" name="to" value="<?= e($m) ?>">
    <textarea name="body" id="rbox" placeholder="Type a reply..." required></textarea>
    <button class="btn" type="submit" style="align-self:flex-end">📤 Send</button>
  </form>
</div>
<script>
window.scrollTo(0, document.body.scrollHeight);
setTimeout(function () { var r = document.getElementById('rbox'); if (!r || r.value.trim() === '') location.reload(); }, 30000);
</script>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
