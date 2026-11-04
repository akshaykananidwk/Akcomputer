<?php
// Arrange every product into shop-style main > sub categories (Security >
// HD Camera, Networking > POE Switch, ...). Shows the full plan first;
// nothing moves until Apply, and Apply can be undone in one tap.
require_once __DIR__ . '/includes/init.php';
require_perm('items.edit');
require_once __DIR__ . '/includes/category_plan.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'apply') {
    $n = category_plan_apply();
    log_activity('category_plan_apply', "$n items moved");
    flash("$n products moved into the new categories. Not happy? Press Undo below.");
    redirect('categories_arrange.php');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'undo') {
    $n = category_plan_undo();
    log_activity('category_plan_undo', "$n items restored");
    flash("$n products are back in their old categories.");
    redirect('categories_arrange.php');
}

$rows = category_plan_rows();
$tree = []; $stay = []; $changes = 0;
foreach ($rows as $r) {
    if (!$r['plan']) { $stay[] = $r; continue; }
    [$main, $sub] = array_pad(explode(CP_SEP, $r['plan'], 2), 2, '');
    $tree[$main][$sub][] = $r;
    if ($r['now'] !== $r['plan']) $changes++;
}
ksort($tree);
$undo = json_decode((string)setting('category_plan_undo'), true);
$empty = (int)val('SELECT COUNT(*) FROM categories c WHERE NOT EXISTS (SELECT 1 FROM items i WHERE i.category_id = c.id)
                   AND NOT EXISTS (SELECT 1 FROM categories k WHERE k.parent_id = c.id)');
$page_title = 'Arrange Categories';
include __DIR__ . '/includes/header.php';
?>
<div class="card">
  <h2>🗂 Arrange products into categories</h2>
  <p class="muted">Every product is filed under a main category and a sub-category, the way big IT shop websites do it
    (Security → HD Camera / IP Camera / PTZ | PT | 4G | WIFI Camera, Networking → POE Switch, Storage → Internal SSD …).
    Check the list below first — <strong>nothing moves until you press Apply</strong>. Services are not touched, and only the category changes:
    prices, stock and bills stay exactly as they are.</p>
  <div class="grid-stats" style="margin:12px 0">
    <div class="stat"><div class="stat-label">Products</div><div class="stat-value"><?= count($rows) ?></div></div>
    <div class="stat"><div class="stat-label">Will move</div><div class="stat-value"><?= $changes ?></div></div>
    <div class="stat"><div class="stat-label">Already in place</div><div class="stat-value"><?= count($rows) - count($stay) - $changes ?></div></div>
    <div class="stat"><div class="stat-label">Not recognised (stay)</div><div class="stat-value"><?= count($stay) ?></div></div>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <?php if ($changes): ?>
    <form method="post" onsubmit="return confirm('Move <?= $changes ?> products into the new categories? You can Undo afterwards.')">
      <?= csrf_field() ?><input type="hidden" name="do" value="apply"><button class="btn" type="submit">✅ Apply — move <?= $changes ?> products</button>
    </form>
    <?php endif; ?>
    <?php if ($undo): ?>
    <form method="post" onsubmit="return confirm('Put every product back in the category it had on <?= e(dmyt($undo['at'])) ?>?')">
      <?= csrf_field() ?><input type="hidden" name="do" value="undo"><button class="btn btn-outline" type="submit">↩ Undo (<?= count($undo['items']) ?> products, <?= e(dmyt($undo['at'])) ?>)</button>
    </form>
    <?php endif; ?>
    <?php if ($empty && !$changes): ?>
    <form method="post" action="ai_categorize.php" onsubmit="return confirm('Remove <?= $empty ?> categories that have no product left?')">
      <?= csrf_field() ?><input type="hidden" name="do" value="cleanup"><button class="btn btn-muted" type="submit">🧹 Remove <?= $empty ?> empty old categories</button>
    </form>
    <?php endif; ?>
  </div>
</div>

<?php foreach ($tree as $main => $subs): ksort($subs); ?>
<div class="card">
  <h3><?= e($main) ?> <span class="muted" style="font-weight:normal">· <?= array_sum(array_map('count', $subs)) ?> products</span></h3>
  <?php foreach ($subs as $sub => $list): $mv = count(array_filter($list, fn($r) => $r['now'] !== $r['plan'])); ?>
  <details style="border-top:1px solid var(--border,#e5e7eb);padding:8px 0">
    <summary style="cursor:pointer"><strong><?= e($sub !== '' ? $sub : $main) ?></strong> — <?= count($list) ?><?= $mv ? ' <span class="muted">(' . $mv . ' moving in)</span>' : '' ?></summary>
    <div class="table-wrap" style="box-shadow:none"><table>
      <thead><tr><th>Product</th><th>Now in</th></tr></thead>
      <tbody>
      <?php foreach ($list as $r): ?>
        <tr<?= $r['is_active'] ? '' : ' class="muted"' ?>><td><a href="item_view.php?id=<?= (int)$r['id'] ?>"><?= e($r['name']) ?></a></td>
          <td><?= $r['now'] === $r['plan'] ? '✓ already here' : e($r['now'] ?: '— none —') ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </details>
  <?php endforeach; ?>
</div>
<?php endforeach; ?>

<?php if ($stay): ?>
<div class="card">
  <h3>Not recognised — these keep their category <span class="muted" style="font-weight:normal">· <?= count($stay) ?></span></h3>
  <p class="muted">Set these by hand from Edit if needed.</p>
  <div class="table-wrap" style="box-shadow:none"><table>
    <thead><tr><th>Product</th><th>Category</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($stay as $r): ?>
      <tr><td><?= e($r['name']) ?></td><td><?= e($r['now'] ?: '— none —') ?></td><td><a href="items.php?action=edit&amp;id=<?= (int)$r['id'] ?>">Edit</a></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
