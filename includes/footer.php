<?php $u = current_user(); $_navCur2 = basename($_SERVER['SCRIPT_NAME']); ?>
</main>
<?php if ($u): ?>
<nav class="bottomnav">
  <a href="index.php" class="<?= $_navCur2 === 'index.php' ? 'active' : '' ?>"><span><?= icon('home', 21) ?></span>Home</a>
  <?php if (can('sales.view')): ?>
    <a href="sales.php" class="<?= $_navCur2 === 'sales.php' ? 'active' : '' ?>"><span><?= icon('receipt', 21) ?></span>Sale</a>
  <?php else: ?>
    <a href="tasks.php" class="<?= $_navCur2 === 'tasks.php' ? 'active' : '' ?>"><span><?= icon('tool', 21) ?></span>Tasks</a>
  <?php endif; ?>
  <button type="button" class="fab-center" id="fabBtn" aria-label="Quick add"><?= icon('plus', 26) ?></button>
  <?php if (can('purchases.view')): ?>
    <a href="purchases.php" class="<?= $_navCur2 === 'purchases.php' ? 'active' : '' ?>"><span><?= icon('box', 21) ?></span>Purchase</a>
  <?php else: ?>
    <a href="my_stock.php" class="<?= $_navCur2 === 'my_stock.php' ? 'active' : '' ?>"><span><?= icon('archive', 21) ?></span>My Stock</a>
  <?php endif; ?>
  <?php if (can('reports.view')): ?>
    <a href="reports.php" class="<?= $_navCur2 === 'reports.php' ? 'active' : '' ?>"><span><?= icon('bar-chart', 21) ?></span>Reports</a>
  <?php else: ?>
    <a href="handover.php" class="<?= $_navCur2 === 'handover.php' ? 'active' : '' ?>"><span><?= icon('handshake', 21) ?></span>Handover</a>
  <?php endif; ?>
</nav>
<?php endif; ?>
</body>
</html>
