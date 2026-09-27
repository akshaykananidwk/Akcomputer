<?php
// Today collections — the round, on the phone that is going out on it.
//
// collection.php is the owner's desk view: the whole queue, with every reason
// and every guard. This is the same list stripped to what is useful standing
// outside somebody's shop: who, how much, where, tap to call, tap for the
// map, tap to record what was collected.
//
// And the other half of a collection round: the money in the staff member's
// pocket. Every cash payment carries who took it, so what they are holding is
// already known - this shows it and sends them to hand it over.
require_once __DIR__ . '/includes/init.php';
require_perm('payments.view');
$u = current_user();

$limit = max(10, min(100, (int)get('n', 40)));
// the same queue, in the same order, that the owner's desk shows -
// coll_queue() is the one place that decides who is worth chasing today
$rows = coll_queue($limit);

$todayIn = (float)val("SELECT COALESCE(SUM(amount),0) FROM payments
                       WHERE direction = 'in' AND mode = 'cash' AND pay_date = ? AND created_by = ?",
                      [today(), $u['id']]);
$todayAll = (float)val("SELECT COALESCE(SUM(amount),0) FROM payments
                        WHERE direction = 'in' AND pay_date = ? AND created_by = ?", [today(), $u['id']]);
$inPocket = staff_cash($u['id']);

$page_title = 'Today collections';
include __DIR__ . '/includes/header.php';
?>
<div class="grid-stats mb">
  <div class="stat s-ok"><div class="stat-label">you took today</div><div class="stat-value">₹<?= money($todayAll) ?></div></div>
  <div class="stat"><div class="stat-label">of which cash</div><div class="stat-value">₹<?= money($todayIn) ?></div></div>
  <div class="stat<?= $inPocket > 0.009 ? ' s-warn' : '' ?>"><div class="stat-label">You are holding cash</div><div class="stat-value">₹<?= money($inPocket) ?></div></div>
</div>

<?php if ($inPocket > 0.009): ?>
<div class="card no-print">
  <p style="margin:0 0 8px">Hand this cash in at the shop at the end of the day — once you do, nothing is outstanding in your name.</p>
  <a class="btn" href="cash_bank.php">💵 Hand in the cash</a>
</div>
<?php endif; ?>

<?php if (!$rows): ?>
<div class="card"><p class="muted" style="margin:0">🎉 Nobody is left to visit right now.</p></div>
<?php else: ?>
<div class="list-count"><?= count($rows) ?> parties — the most urgent first</div>
<?php foreach ($rows as $r):
    $amt = (float)($r['outstanding'] ?? 0);
    if ($amt <= 0.009) continue;
    $mob = preg_replace('/\D/', '', (string)($r['mobile'] ?? ''));
    $addr = trim((string)($r['city'] ?? ''));
?>
<div class="card" style="padding:14px">
  <div style="display:flex;justify-content:space-between;gap:10px;align-items:flex-start">
    <div>
      <div style="font-weight:700;font-size:16px">
        <a href="parties.php?action=ledger&id=<?= (int)$r['id'] ?>"><?= e($r['name']) ?></a>
      </div>
      <?php if (!empty($r['days'])): ?><div class="muted"><?= (int)$r['days'] ?> days old<?php
          $dstep = dunning_step((int)$r['days']); echo $dstep ? ' · ' . $dstep['label'] : ''; ?></div><?php endif; ?>
      <?php if (!empty($r['promise_open'])): ?><div class="muted">🤝 Promise: <?= dmy($r['promise_open']) ?></div><?php endif; ?>
      <?php if ($addr !== ''): ?><div class="muted">📍 <?= e($addr) ?></div><?php endif; ?>
    </div>
    <div style="text-align:right;white-space:nowrap">
      <div style="font-size:20px;font-weight:800;color:var(--bad)">₹<?= money($amt) ?></div>
    </div>
  </div>
  <div class="page-actions no-print" style="margin:10px 0 0">
    <?php if ($mob !== ''): ?>
      <a class="btn btn-sm" href="tel:<?= e($mob) ?>">📞 Phone</a>
      <a class="btn btn-sm btn-wa" href="https://wa.me/<?= e(strlen($mob) === 10 ? '91' . $mob : $mob) ?>" target="_blank" rel="noopener">WhatsApp</a>
    <?php endif; ?>
    <?php if ($addr !== ''): ?>
      <a class="btn btn-sm btn-outline" target="_blank" rel="noopener"
         href="https://www.google.com/maps/search/?api=1&query=<?= rawurlencode($addr) ?>">🗺️ Route</a>
    <?php endif; ?>
    <?php if (can('payments.add')): ?>
      <a class="btn btn-sm btn-success" href="payments.php?action=new&dir=in&party=<?= (int)$r['id'] ?>">Rs received</a>
    <?php endif; ?>
  </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<p class="muted no-print">To see the full list and the reason for each <a href="collection.php">Collection queue</a> open it.</p>
<?php include __DIR__ . '/includes/footer.php'; ?>
