<?php $u = current_user(); ?>
</main>
<?php if ($u): ?>
<nav class="bottomnav">
  <a href="index.php" class="<?= basename($_SERVER['SCRIPT_NAME']) === 'index.php' ? 'active' : '' ?>"><span>🏠</span>Home</a>
  <?php if (can('sales.add')): ?><a href="sales.php?action=new" class="<?= basename($_SERVER['SCRIPT_NAME']) === 'sales.php' ? 'active' : '' ?>"><span>🧾</span>Bill</a><?php endif; ?>
  <?php if (can('stock.view')): ?><a href="stock.php" class="<?= basename($_SERVER['SCRIPT_NAME']) === 'stock.php' ? 'active' : '' ?>"><span>📊</span>Stock</a>
  <?php else: ?><a href="my_stock.php" class="<?= basename($_SERVER['SCRIPT_NAME']) === 'my_stock.php' ? 'active' : '' ?>"><span>🎒</span>My Stock</a><?php endif; ?>
  <?php if (can('tasks.view')): ?><a href="tasks.php" class="<?= basename($_SERVER['SCRIPT_NAME']) === 'tasks.php' ? 'active' : '' ?>"><span>🔧</span>Tasks</a><?php endif; ?>
  <?php if (can('reports.view')): ?><a href="reports.php" class="<?= basename($_SERVER['SCRIPT_NAME']) === 'reports.php' ? 'active' : '' ?>"><span>📈</span>Reports</a><?php endif; ?>
</nav>
<?php endif; ?>
</body>
</html>
