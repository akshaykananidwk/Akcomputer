<?php $u = current_user(); $_navCur2 = basename($_SERVER['SCRIPT_NAME']); ?>
</main>
<?php if ($u): ?>
<nav class="bottomnav">
  <?php
  // The same six the home screen shows, in the same order, because they are
  // the same six things. The round + used to sit in the middle; the owner's
  // drawing has no + and six names instead, and a bar that disagrees with
  // the screen above it is a bar people stop trusting.
  ?>
  <?php if (can('payments.view')): ?>
    <a href="parties.php?bal=get" class="nav-get <?= $_navCur2 === 'parties.php' && ($_GET['bal'] ?? '') === 'get' ? 'active' : '' ?>"><span><?= icon('arrow-down', 20) ?></span>To Receive</a>
    <a href="parties.php?bal=give" class="nav-give <?= $_navCur2 === 'parties.php' && ($_GET['bal'] ?? '') === 'give' ? 'active' : '' ?>"><span><?= icon('arrow-up', 20) ?></span>To Pay</a>
  <?php else: ?>
    <a href="index.php" class="<?= $_navCur2 === 'index.php' ? 'active' : '' ?>"><span><?= icon('home', 20) ?></span>Home</a>
    <a href="handover.php" class="<?= $_navCur2 === 'handover.php' ? 'active' : '' ?>"><span><?= icon('handshake', 20) ?></span>Handover</a>
  <?php endif; ?>
  <?php if (can('sales.view')): ?>
    <a href="sales.php" class="<?= $_navCur2 === 'sales.php' ? 'active' : '' ?>"><span><?= icon('receipt', 20) ?></span>Sale list</a>
  <?php else: ?>
    <a href="tasks.php" class="<?= $_navCur2 === 'tasks.php' ? 'active' : '' ?>"><span><?= icon('tool', 20) ?></span>Tasks</a>
  <?php endif; ?>
  <?php if (can('purchases.view')): ?>
    <a href="purchases.php" class="<?= $_navCur2 === 'purchases.php' ? 'active' : '' ?>"><span><?= icon('box', 20) ?></span>Purchase</a>
  <?php else: ?>
    <a href="my_stock.php" class="<?= $_navCur2 === 'my_stock.php' ? 'active' : '' ?>"><span><?= icon('archive', 20) ?></span>My Stock</a>
  <?php endif; ?>
  <?php if (can('items.view')): ?>
    <a href="items.php" class="<?= $_navCur2 === 'items.php' ? 'active' : '' ?>"><span><?= icon('archive', 20) ?></span>Stock Items</a>
  <?php endif; ?>
  <?php if (can('parties.view')): ?>
    <a href="parties.php" class="<?= $_navCur2 === 'parties.php' && !isset($_GET['bal']) ? 'active' : '' ?>"><span><?= icon('users', 20) ?></span>Parties</a>
  <?php endif; ?>
</nav>
<?php endif; ?>
</body>
</html>
