<?php
// Items -> Product Descriptions & SEO. Writes a full description (what it
// is, key features read from its name, where it is used, how it works, what
// else is needed) and the search words for every product that has no
// description of its own. A description the shop wrote itself is never
// replaced. Shows a sample first; Apply can be undone in one tap.
require_once __DIR__ . '/includes/init.php';
require_perm('items.edit');
require_once __DIR__ . '/includes/item_content.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'apply') {
    $n = ic_apply();
    log_activity('item_content_apply', "$n products");
    flash("$n products now have a full description and search words. Not happy? Press Undo below.");
    redirect('products_content.php');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'undo') {
    $n = ic_undo();
    log_activity('item_content_undo', "$n products");
    flash("$n products are back to the description they had before.");
    redirect('products_content.php');
}
$plan = ic_plan();
$total = (int)val("SELECT COUNT(*) FROM items WHERE is_active = 1 AND item_type <> 'service'");
$own = (int)val("SELECT COUNT(*) FROM items i WHERE is_active = 1 AND item_type <> 'service' AND COALESCE(description, '') <> ''
                  AND NOT EXISTS (SELECT 1 FROM item_content_auto a WHERE a.item_id = i.id AND a.hd <> '')");
$undo = ic_undo_info();
$sample = array_slice($plan, 0, 3);
$page_title = 'Product Descriptions & SEO';
include __DIR__ . '/includes/header.php';
?>
<div class="card">
  <h2>📝 Product descriptions &amp; search words</h2>
  <p class="muted">For Google and the website: every product gets a full description — what it is, its features read from its own name
    (megapixels, audio, night vision, RAM size, power, ports, length …), where it is used, how it works and what else is needed —
    and the words customers search it with (brand, model, type, specs, Gujarati words, price, Dwarka and nearby towns).
    Nothing is made up: a feature is written only when the product name says so. <strong>A description you wrote yourself is never replaced.</strong></p>
  <div class="grid-stats" style="margin:12px 0">
    <div class="stat"><div class="stat-label">Products</div><div class="stat-value"><?= $total ?></div></div>
    <div class="stat"><div class="stat-label">Will get a description</div><div class="stat-value"><?= count($plan) ?></div></div>
    <div class="stat"><div class="stat-label">Your own (kept)</div><div class="stat-value"><?= max(0, $own) ?></div></div>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <?php if ($plan): ?><form method="post" onsubmit="return confirm('Write descriptions and search words for <?= count($plan) ?> products? You can Undo afterwards.')">
      <?= csrf_field() ?><input type="hidden" name="do" value="apply"><button class="btn" type="submit">✅ Apply — <?= count($plan) ?> products</button></form><?php endif; ?>
    <?php if ($undo): ?><form method="post" onsubmit="return confirm('Put back the descriptions as they were on <?= e(dmyt($undo['at'])) ?>?')">
      <?= csrf_field() ?><input type="hidden" name="do" value="undo"><button class="btn btn-outline" type="submit">↩ Undo (<?= (int)$undo['n'] ?> products, <?= e(dmyt($undo['at'])) ?>)</button></form><?php endif; ?>
    <a class="btn btn-outline" href="categories_arrange.php">🗂 Arrange Categories</a>
  </div>
  <?php if (!$plan): ?><p class="mt">✅ Every product already has a description.</p><?php endif; ?>
</div>
<?php foreach ($sample as $p): ?>
<div class="card">
  <h3 style="margin-top:0"><?= e($p['name']) ?></h3>
  <div style="white-space:pre-line;font-size:14px;line-height:1.55"><?= e($p['new']) ?></div>
  <details class="mt"><summary class="muted">Search words (<?= count(explode(', ', $p['kw'])) ?>)</summary><p class="muted" style="font-size:12.5px"><?= e($p['kw']) ?></p></details>
</div>
<?php endforeach; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
