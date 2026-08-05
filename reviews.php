<?php
// Product review moderation (Online Store): approve / reject / delete real
// customer reviews. Approved reviews show on the product page AND feed the
// Product structured data (aggregateRating/review) that Google reads -
// verified-purchase reviews (mobile matched a sale) auto-approve on submit.
require_once __DIR__ . '/includes/init.php';
require_perm('items.view');
$u = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'set') {
    require_perm('items.edit');
    $rv = row('SELECT * FROM product_reviews WHERE id = ?', [(int)post('id')]);
    if ($rv) {
        if (post('to') === 'delete') {
            q('DELETE FROM product_reviews WHERE id = ?', [$rv['id']]);
            flash('Review deleted.');
        } else {
            $to = post('to') === 'approved' ? 'approved' : 'rejected';
            q('UPDATE product_reviews SET status = ? WHERE id = ?', [$to, $rv['id']]);
            flash('Review ' . $to . '.');
        }
        log_activity('review_' . post('to'), 'review #' . $rv['id']);
    }
    redirect('reviews.php' . (get('st') ? '?st=' . get('st') : ''));
}

$st = in_array(get('st'), ['pending', 'approved', 'rejected'], true) ? get('st') : '';
$where = $st ? "WHERE r.status = '$st'" : '';
$rows = [];
try {
    $rows = all("SELECT r.*, i.name item_name FROM product_reviews r JOIN items i ON i.id = r.item_id $where ORDER BY (r.status='pending') DESC, r.id DESC LIMIT 200");
} catch (Exception $e) { /* pre-v52 */ }
$pendingN = 0;
try { $pendingN = (int)val("SELECT COUNT(*) FROM product_reviews WHERE status = 'pending'"); } catch (Exception $e) {}

$page_title = 'Product Reviews';
include __DIR__ . '/includes/header.php';
?>
<div class="card">
  <h3>⭐ Product Reviews <?= $pendingN ? '<span class="badge" style="background:var(--bad);color:#fff;border-radius:99px;padding:2px 10px;font-size:12px">' . $pendingN . ' pending</span>' : '' ?></h3>
  <p class="muted mb">ગ્રાહકોના સાચા રિવ્યૂ — Approve થાય એ જ વેબસાઈટ પર અને Google ના Product ડેટામાં જાય છે. ✓ Verified એટલે એ મોબાઈલ નંબર પર એ પ્રોડક્ટનું બિલ ખરેખર બનેલું છે (એ આપોઆપ Approve થાય છે).</p>
  <div class="filterbar mb">
    <a class="btn btn-sm <?= $st === '' ? '' : 'btn-outline' ?>" href="reviews.php">All</a>
    <a class="btn btn-sm <?= $st === 'pending' ? '' : 'btn-outline' ?>" href="reviews.php?st=pending">Pending</a>
    <a class="btn btn-sm <?= $st === 'approved' ? '' : 'btn-outline' ?>" href="reviews.php?st=approved">Approved</a>
    <a class="btn btn-sm <?= $st === 'rejected' ? '' : 'btn-outline' ?>" href="reviews.php?st=rejected">Rejected</a>
  </div>
  <?php if (!$rows): ?>
  <p class="muted">કોઈ રિવ્યૂ નથી<?= $st ? " ($st)" : '' ?>. (નવું v52 Migrate ચલાવેલું છે ને?)</p>
  <?php else: ?>
  <div class="table-wrap" style="box-shadow:none"><table class="table-sm">
    <thead><tr><th>Date</th><th>Product</th><th>Customer</th><th>Rating</th><th>Comment</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
    <tr <?= $r['status'] === 'pending' ? 'style="background:rgba(245,158,11,.07)"' : '' ?>>
      <td><?= dmy($r['created_at']) ?></td>
      <td><a href="<?= e(base_url('product.php?id=' . $r['item_id'])) ?>" target="_blank"><?= e($r['item_name']) ?></a></td>
      <td><?= e($r['customer_name']) ?><?= $r['is_verified'] ? ' <span style="color:#059669;font-weight:700" title="Verified purchase">✓</span>' : '' ?>
          <?php if ($r['mobile']): ?><br><span class="muted" style="font-size:12px"><?= e($r['mobile']) ?></span><?php endif; ?></td>
      <td style="color:#f59e0b;white-space:nowrap"><?= str_repeat('★', (int)$r['rating']) ?></td>
      <td style="max-width:340px"><span style="font-size:13px"><?= e(mb_substr($r['comment'], 0, 200)) ?></span></td>
      <td><?= $r['status'] === 'approved' ? '✅' : ($r['status'] === 'pending' ? '⏳' : '❌') ?> <?= e($r['status']) ?></td>
      <td class="right" style="white-space:nowrap">
        <?php if (can('items.edit')): ?>
        <?php if ($r['status'] !== 'approved'): ?>
        <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="do" value="set"><input type="hidden" name="id" value="<?= $r['id'] ?>"><input type="hidden" name="to" value="approved"><button class="btn btn-sm btn-success" type="submit">✔ Approve</button></form>
        <?php endif; ?>
        <?php if ($r['status'] !== 'rejected'): ?>
        <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="do" value="set"><input type="hidden" name="id" value="<?= $r['id'] ?>"><input type="hidden" name="to" value="rejected"><button class="btn btn-sm btn-outline" type="submit">✖ Reject</button></form>
        <?php endif; ?>
        <form method="post" style="display:inline" onsubmit="return confirm('Delete this review?')"><?= csrf_field() ?><input type="hidden" name="do" value="set"><input type="hidden" name="id" value="<?= $r['id'] ?>"><input type="hidden" name="to" value="delete"><button class="btn btn-sm btn-danger" type="submit">🗑</button></form>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
