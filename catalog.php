<?php
// Public website catalog - items flagged "show on website" (no login needed)
require_once __DIR__ . '/includes/init.php';

$items = all('SELECT i.*, c.name cat_name FROM items i
              LEFT JOIN categories c ON c.id = i.category_id
              WHERE i.is_active = 1 AND i.show_on_website = 1 ORDER BY c.name, i.name');
$app_name = setting('app_name', 'AK Computer');
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($app_name) ?> - Products</title>
<link rel="stylesheet" href="assets/style.css">
<style>
.cat-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 12px; }
.cat-card { background: #fff; border-radius: 12px; overflow: hidden; box-shadow: 0 1px 4px rgba(0,0,0,.08); }
.cat-card img { width: 100%; height: 130px; object-fit: cover; background: #f1f5f9; }
.cat-card .ph { width: 100%; height: 130px; display: flex; align-items: center; justify-content: center; font-size: 40px; background: #f1f5f9; }
.cat-card .cbody { padding: 10px; }
.cat-card .cname { font-weight: 600; font-size: 14px; }
.cat-card .cprice { color: var(--primary); font-weight: 700; margin-top: 4px; }
.cat-head { text-align: center; padding: 24px 12px 8px; }
</style>
</head>
<body>
<div class="content content-full" style="justify-content:flex-start">
  <div class="cat-head">
    <h1>🖥️ <?= e($app_name) ?></h1>
    <p class="muted">Our products & prices</p>
  </div>
  <div class="searchbox"><input type="text" id="cFilter" placeholder="🔍 Search products..."></div>
  <div class="cat-grid" id="cGrid">
  <?php foreach ($items as $it): ?>
    <div class="cat-card">
      <?php if ($it['photo']): ?><img src="<?= e($it['photo']) ?>" alt="<?= e($it['name']) ?>" loading="lazy">
      <?php else: ?><div class="ph">📦</div><?php endif; ?>
      <div class="cbody">
        <div class="cname"><?= e($it['name']) ?></div>
        <?php if ($it['brand']): ?><div class="muted"><?= e($it['brand']) ?> <?= e($it['model']) ?></div><?php endif; ?>
        <div class="cprice">₹<?= money($it['selling_price']) ?></div>
      </div>
    </div>
  <?php endforeach; ?>
  <?php if (!$items): ?><p class="muted">No products listed yet.</p><?php endif; ?>
  </div>
</div>
<script>
document.getElementById('cFilter').addEventListener('input', function () {
  var q = this.value.toLowerCase();
  document.querySelectorAll('#cGrid .cat-card').forEach(function (c) {
    c.style.display = c.textContent.toLowerCase().indexOf(q) > -1 ? '' : 'none';
  });
});
</script>
</body>
</html>
