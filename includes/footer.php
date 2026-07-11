<?php $u = current_user(); $cur = basename($_SERVER['SCRIPT_NAME']); ?>
</main>
<?php if ($u): ?>
<nav class="bottomnav">
  <a href="index.php" class="<?= $cur === 'index.php' ? 'active' : '' ?>"><span>🏠</span>Home</a>
  <?php if (can('sales.view')): ?>
    <a href="sales.php" class="<?= $cur === 'sales.php' ? 'active' : '' ?>"><span>🧾</span>Sale</a>
  <?php else: ?>
    <a href="tasks.php" class="<?= $cur === 'tasks.php' ? 'active' : '' ?>"><span>🔧</span>Tasks</a>
  <?php endif; ?>
  <button type="button" class="fab-center" id="fabBtn" aria-label="Quick add">＋</button>
  <?php if (can('purchases.view')): ?>
    <a href="purchases.php" class="<?= $cur === 'purchases.php' ? 'active' : '' ?>"><span>📦</span>Purchase</a>
  <?php else: ?>
    <a href="my_stock.php" class="<?= $cur === 'my_stock.php' ? 'active' : '' ?>"><span>🎒</span>My Stock</a>
  <?php endif; ?>
  <?php if (can('reports.view')): ?>
    <a href="reports.php" class="<?= $cur === 'reports.php' ? 'active' : '' ?>"><span>📈</span>Reports</a>
  <?php else: ?>
    <a href="handover.php" class="<?= $cur === 'handover.php' ? 'active' : '' ?>"><span>🤝</span>Handover</a>
  <?php endif; ?>
</nav>
<?php endif; ?>
</body>
</html>
