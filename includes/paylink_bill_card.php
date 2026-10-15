<?php
// A bill's payment picture, with linking from the bill's side.
// Needs: $plType ('sale'|'purchase'), $plId. Shown only to staff.
$pl = paylink_bill($plType, $plId);
if ($pl && !$pl['bill']['is_cancelled']):
    $plB = $pl['bill'];
    $plColor = ['paid' => 'var(--ok)', 'partial' => 'var(--warn,#b45309)', 'unpaid' => 'var(--bad)'][$pl['state']];
    $plLabel = ['paid' => '🟢 Fully paid', 'partial' => '🟡 Partly paid', 'unpaid' => '🔴 Unpaid'][$pl['state']];
    $plBack = ($plType === 'sale' ? 'sale_view' : 'purchase_view') . '.php?id=' . (int)$plId;
    $plCan = can('payments.edit');
?>
<div class="card no-print" id="paystatus" style="border-left:4px solid <?= $plColor ?>">
  <div style="display:flex;justify-content:space-between;align-items:baseline;gap:8px;flex-wrap:wrap">
    <h3 style="margin:0">💳 Payment status</h3><strong style="color:<?= $plColor ?>"><?= $plLabel ?></strong>
  </div>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:10px;margin:12px 0">
    <div><div class="muted" style="font-size:12px">Bill total</div><strong>₹<?= money($plB['total']) ?></strong></div>
    <div><div class="muted" style="font-size:12px">Received</div><strong>₹<?= money($plB['paid']) ?></strong></div>
    <div><div class="muted" style="font-size:12px">Linked payments</div><strong>₹<?= money($pl['linked']) ?></strong></div>
    <div><div class="muted" style="font-size:12px">Outstanding</div><strong style="color:<?= $pl['outstanding'] > 0.009 ? 'var(--bad)' : 'inherit' ?>">₹<?= money($pl['outstanding']) ?></strong></div>
    <div><div class="muted" style="font-size:12px">Party's money not on a bill</div><strong>₹<?= money($pl['free_total']) ?></strong></div>
  </div>
  <?php if (abs($pl['linked'] - $plB['paid']) > 0.01): ?>
    <p class="muted" style="font-size:13px">⚠️ "Received" (₹<?= money($plB['paid']) ?>) and the payments linked (₹<?= money($pl['linked']) ?>) differ — see Accounting check → entry by entry.</p>
  <?php endif; ?>
  <?php if ($pl['links']): ?>
  <div class="muted" style="font-size:12px;margin-top:6px">Payments on this bill</div>
  <?php foreach ($pl['links'] as $lk): ?>
    <div style="display:flex;gap:8px;align-items:center;padding:6px 0;border-bottom:1px solid var(--border)">
      <a href="payments.php?action=link&id=<?= (int)$lk['pay_id'] ?>" style="flex:1">P-<?= str_pad($lk['pay_id'], 5, '0', STR_PAD_LEFT) ?></a>
      <span class="muted" style="font-size:13px"><?= dmy($lk['pay_date']) ?> · <?= e(strtoupper($lk['mode'])) ?></span>
      <strong>₹<?= money($lk['amount']) ?></strong>
      <?php if ($plCan && $lk['mode'] !== 'contra'): ?>
      <form method="post" action="payments.php" style="display:inline" onsubmit="return confirm('Unlink ₹<?= money($lk['amount']) ?> of P-<?= (int)$lk['pay_id'] ?> from this bill? The payment stays; this bill shows it as unpaid again.')">
        <?= csrf_field() ?><input type="hidden" name="do" value="unlink"><input type="hidden" name="id" value="<?= (int)$lk['pay_id'] ?>">
        <input type="hidden" name="link" value="<?= (int)$lk['link_id'] ?>"><input type="hidden" name="back" value="<?= e($plBack) ?>">
        <button class="btn btn-sm btn-outline" type="submit">Unlink</button></form>
      <?php endif; ?>
    </div>
  <?php endforeach; endif; ?>
  <?php if ($plCan && $pl['outstanding'] > 0.009 && $pl['free']): ?>
  <div class="muted" style="font-size:12px;margin-top:12px">Link a payment already received from this party</div>
  <?php foreach ($pl['free'] as $f): $fFree = money_r($f['amount'] - $f['linked']); $fUse = min($fFree, $pl['outstanding']); ?>
    <form method="post" action="payments.php" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;padding:6px 0;border-bottom:1px solid var(--border)">
      <?= csrf_field() ?><input type="hidden" name="do" value="link"><input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
      <input type="hidden" name="bill[]" value="<?= (int)$plId ?>"><input type="hidden" name="back" value="<?= e($plBack) ?>">
      <span style="flex:1;min-width:150px">P-<?= str_pad($f['id'], 5, '0', STR_PAD_LEFT) ?> · <?= dmy($f['pay_date']) ?> · <?= e(strtoupper($f['mode'])) ?>
        <?= paylink_chip($f['on_account'] ? 'advance' : ($f['linked'] > 0.009 ? 'partial' : 'unlinked'), $fFree) ?></span>
      <input type="number" name="amt[]" value="<?= number_format($fUse, 2, '.', '') ?>" min="0.01" max="<?= $fUse ?>" step="0.01" inputmode="decimal" style="width:110px">
      <button class="btn btn-sm" type="submit">🔗 Link</button>
    </form>
  <?php endforeach; endif; ?>
</div>
<?php endif; ?>
