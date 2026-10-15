<?php
// The Data Health Check's warnings, entry by entry (part of accounting_check.php).
// READ ONLY: nothing here posts or writes.
$paid = acct_detail_paid();
$zero = acct_detail_zero_purchases();
$stk = acct_detail_stock();
$pty = acct_detail_parties();
$sys = acct_detail_system();
$tag = fn($k) => ['software' => '<span class="badge badge-info">software check</span>', 'entry' => '<span class="badge badge-warn">entry</span>',
                  'gift' => '<span class="badge badge-ok">free / gift - keep</span>'][$k] ?? '';
?>
<p class="muted">This page only reads. It changes no bill, payment, stock, purchase or journal.</p>

<div class="card" id="paid"><h3 style="margin-top:0">1. Bills: "paid" vs the payments recorded (<?= count($paid) ?>)</h3>
  <div class="table-wrap"><table><thead><tr><th>Bill</th><th class="num">Total</th><th class="num">Paid on bill</th><th class="num">With the bill</th><th class="num">Linked later</th><th class="num">Gap</th><th>What it is</th></tr></thead><tbody>
  <?php foreach ($paid as $r): ?>
    <tr><td><a href="sale_view.php?id=<?= (int)$r['id'] ?>"><?= e($r['invoice_no']) ?></a><br><small class="muted"><?= dmy($r['sale_date']) ?> · <?= e($r['party']) ?></small></td>
      <td class="num">₹<?= money($r['total']) ?></td><td class="num">₹<?= money($r['paid']) ?></td><td class="num">₹<?= money($r['direct']) ?></td>
      <td class="num">₹<?= money($r['linked']) ?></td><td class="num"><?= abs($r['gap']) < 0.01 ? '—' : '₹' . money($r['gap']) ?></td>
      <td><?= $tag($r['kind']) ?> <?= e($r['verdict']) ?></td></tr>
  <?php endforeach; if (!$paid): ?><tr><td colspan="7" class="muted">None.</td></tr><?php endif; ?>
  </tbody></table></div></div>

<div class="card" id="zero"><h3 style="margin-top:0">2. Purchase bills at ₹0 or fully discounted (<?= count($zero) ?>)</h3>
  <?php foreach ($zero as $r): ?>
    <div style="padding:10px 0;border-bottom:1px solid var(--border)">
      <div><a href="purchase_view.php?id=<?= (int)$r['id'] ?>"><strong><?= e($r['bill_no'] ?: '#' . $r['id']) ?></strong></a> · <?= dmy($r['purchase_date']) ?> · <?= e($r['party']) ?>
        <span style="float:right">subtotal ₹<?= money($r['subtotal']) ?> − discount ₹<?= money($r['discount']) ?> + GST ₹<?= money($r['tax_amount']) ?> = <b>₹<?= money($r['total']) ?></b></span></div>
      <div><?= $tag($r['kind']) ?> <?= e($r['what']) ?></div>
      <div class="muted" style="font-size:13px"><?= implode(' · ', array_map(fn($l) => e($l['name']) . ' ' . (float)$l['qty'] . ' × ₹' . money($l['price']), $r['lines'])) ?></div>
      <?php foreach ($r['effects'] as $ef): ?><div style="font-size:13px">↳ <?= e($ef) ?></div><?php endforeach; ?>
    </div>
  <?php endforeach; if (!$zero): ?><p class="muted">None.</p><?php endif; ?></div>

<div class="card" id="stock"><h3 style="margin-top:0">3a. Negative stock (<?= count($stk['negative']) ?>)</h3>
  <div class="table-wrap"><table><thead><tr><th>Item</th><th>Location</th><th class="num">Stock</th><th>Most likely</th></tr></thead><tbody>
  <?php foreach ($stk['negative'] as $n): ?><tr><td><a href="item_view.php?id=<?= (int)$n['item_id'] ?>"><?= e($n['name']) ?></a></td><td><?= e($n['loc']) ?></td><td class="num"><?= (float)$n['qty'] ?></td><td><?= $tag('entry') ?> <?= e($n['why']) ?></td></tr>
  <?php endforeach; if (!$stk['negative']): ?><tr><td colspan="4" class="muted">None.</td></tr><?php endif; ?></tbody></table></div>
  <h3>3b. Serial numbers vs stock (<?= count($stk['serials']) ?>)</h3>
  <div class="table-wrap"><table><thead><tr><th>Item</th><th class="num">Stock</th><th class="num">Serials in stock</th><th>Most likely</th></tr></thead><tbody>
  <?php foreach ($stk['serials'] as $s): ?><tr><td><a href="item_view.php?id=<?= (int)$s['id'] ?>"><?= e($s['name']) ?></a></td><td class="num"><?= (float)$s['stock_qty'] ?></td><td class="num"><?= (int)$s['serials'] ?></td><td><?= $tag('entry') ?> <?= e($s['why']) ?></td></tr>
  <?php endforeach; if (!$stk['serials']): ?><tr><td colspan="4" class="muted">None.</td></tr><?php endif; ?></tbody></table></div></div>

<div class="card" id="party"><h3 style="margin-top:0">4. Parties whose open bills are more than their ledger (<?= count($pty) ?>)</h3>
  <div class="table-wrap"><table><thead><tr><th>Party</th><th class="num">Open bills</th><th class="num">Ledger</th><th class="num">Gap</th><th>Why</th></tr></thead><tbody>
  <?php foreach ($pty as $r): ?><tr><td><a href="parties.php?action=ledger&id=<?= (int)$r['id'] ?>"><?= e($r['name']) ?></a></td><td class="num">₹<?= money($r['bills_due']) ?></td><td class="num">₹<?= money($r['ledger']) ?></td><td class="num">₹<?= money($r['gap']) ?></td><td><?= e($r['why']) ?></td></tr>
  <?php endforeach; if (!$pty): ?><tr><td colspan="5" class="muted">None.</td></tr><?php endif; ?></tbody></table></div></div>

<div class="card" id="system"><h3 style="margin-top:0">6. Backup, keys, automatic tasks, errors</h3>
  <div><?= $sys['backup_password'] ? '✅' : '⚠️' ?> Backup password <?= $sys['backup_password'] ? 'is set' : 'is NOT set - backup files are not locked' ?></div>
  <div><?= $sys['last_backup'] && strtotime($sys['last_backup']) > strtotime('-3 days') ? '✅' : '⚠️' ?> Last automatic backup: <?= $sys['last_backup'] ? dmyt($sys['last_backup']) : 'never' ?> · in the last 7 days: <?= $sys['backups_7d'] ?></div>
  <div><?= $sys['plain_keys'] ? '⚠️' : '✅' ?> Keys stored unencrypted: <?= $sys['plain_keys'] ? e(implode(', ', $sys['plain_keys'])) . ' (names only - values are not shown). They get encrypted by the daily housekeeping task, which runs on the cron.' : 'none' ?></div>
  <div><?= $sys['last_tick'] && strtotime($sys['last_tick']) > strtotime('-30 minutes') ? '✅' : '⚠️' ?> Cron last ran: <?= $sys['last_tick'] ? dmyt($sys['last_tick']) : 'never' ?></div>
  <?php foreach ($sys['cron_fail'] as $f): ?><div style="font-size:13px">⚠️ <?= e($f['job']) ?> failed <?= (int)$f['n'] ?>× (last <?= dmyt($f['last']) ?>) <?= e($f['msg']) ?></div><?php endforeach; ?>
  <h4>Errors in the last 7 days, grouped (<?= array_sum(array_column($sys['errors'], 'n')) ?>)</h4>
  <div class="table-wrap"><table><thead><tr><th class="num">Times</th><th>Last</th><th>Error</th></tr></thead><tbody>
  <?php foreach ($sys['errors'] as $er): ?><tr><td class="num"><?= (int)$er['n'] ?></td><td style="white-space:nowrap"><?= e($er['last']) ?></td><td style="font-size:12.5px;word-break:break-word"><?= e($er['msg']) ?></td></tr>
  <?php endforeach; if (!$sys['errors']): ?><tr><td colspan="3" class="muted">None.</td></tr><?php endif; ?></tbody></table></div></div>
