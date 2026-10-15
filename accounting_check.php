<?php
// 🩺 Accounting check - READ ONLY. Explains why the Trial Balance does not
// balance, reason by reason, and checks banks, P&L, stock, parties and
// journals. It shows; it never changes a single entry (no form on this page
// posts anything, and includes/accounting_audit.php writes nothing).
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/accounting_audit.php';
require_once __DIR__ . '/includes/books.php';   // fy_of()
require_perm('reports.accounting');

$to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)get('to')) ? get('to') : today();
[$fyS] = fy_of($to);
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)get('from')) ? get('from') : $fyS;
$tb = acct_tb_totals($to);
$parts = acct_tb_breakdown($to);
$checks = acct_checks($from, $to);
$sum = round(array_sum(array_column($parts, 'amount')), 2);
$kinds = ['software' => ['🧮', 'How the report calculates (software)'], 'opening' => ['📂', 'Old balances from before (opening data)'], 'entry' => ['✍️', 'How particular entries were made (entries)']];
$areas = ['bank' => '🏦 Bank', 'cash' => '💵 Cash', 'pl' => '💹 Profit & Loss', 'stock' => '📦 Stock & purchase', 'party' => '👥 Parties', 'journal' => '📒 Journals & duplicates'];

$page_title = 'Accounting check';
include __DIR__ . '/includes/header.php';
?>
<div class="page-head"><h1>🩺 Accounting check</h1></div>
<?php $view = get('view') === 'entries' ? 'entries' : 'books'; ?>
<div class="chips" style="display:flex;gap:8px;margin-bottom:12px">
  <a class="btn btn-sm <?= $view === 'books' ? '' : 'btn-outline' ?>" href="accounting_check.php">⚖️ Trial Balance &amp; reports</a>
  <a class="btn btn-sm <?= $view === 'entries' ? '' : 'btn-outline' ?>" href="accounting_check.php?view=entries">🔎 Data Health warnings, entry by entry</a>
</div>
<?php if ($view === 'entries') { include __DIR__ . '/includes/accounting_check_entries.php'; include __DIR__ . '/includes/footer.php'; exit; } ?>
<form class="card" method="get" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end">
  <div><label>P&amp;L period from</label><input type="date" name="from" value="<?= e($from) ?>"></div>
  <div><label>Up to</label><input type="date" name="to" value="<?= e($to) ?>"></div>
  <div><button class="btn btn-sm">Check</button></div>
  <p class="muted" style="flex-basis:100%;margin:4px 0 0">This page only reads. It changes no entry, balance or journal.</p>
</form>

<div class="card">
  <h3 style="margin-top:0">⚖️ Trial Balance as of <?= dmy($to) ?></h3>
  <div class="stat-row" style="display:flex;gap:16px;flex-wrap:wrap">
    <div><div class="muted">Debit</div><strong>₹<?= money($tb['dr']) ?></strong></div>
    <div><div class="muted">Credit</div><strong>₹<?= money($tb['cr']) ?></strong></div>
    <div><div class="muted">Difference (debit − credit)</div><strong style="color:<?= abs($tb['diff']) > 0.01 ? 'var(--bad)' : 'var(--ok)' ?>">₹<?= money($tb['diff']) ?></strong></div>
  </div>
  <p class="muted mt">Below, the difference taken apart. The reasons add up to <strong>₹<?= money($sum) ?></strong><?= abs($sum - $tb['diff']) < 0.01 ? ' — exactly the difference ✅' : ' — ⚠️ not equal to the difference: please report this' ?>.</p>
</div>

<?php foreach ($kinds as $k => [$ico, $label]): $rows = array_filter($parts, fn($r) => $r['kind'] === $k && abs($r['amount']) > 0.009); if (!$rows) continue; ?>
<div class="card">
  <h3 style="margin-top:0"><?= $ico ?> <?= e($label) ?> · ₹<?= money(array_sum(array_column($rows, 'amount'))) ?></h3>
  <div class="table-wrap"><table><thead><tr><th>Reason</th><th class="num">Effect on difference</th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?>
    <tr><td><strong><?= e($r['what']) ?></strong><div class="muted" style="font-size:13px"><?= e($r['why']) ?><?= $r['fix'] ? '<br><b>Proper way:</b> ' . e($r['fix']) : '' ?></div></td>
        <td class="num"><?= $r['amount'] >= 0 ? '+' : '−' ?>₹<?= money(abs($r['amount'])) ?></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>
</div>
<?php endforeach; ?>

<?php foreach ($areas as $a => $label): $rows = array_filter($checks, fn($c) => $c['area'] === $a); if (!$rows) continue; ?>
<div class="card">
  <h3 style="margin-top:0"><?= $label ?></h3>
  <?php foreach ($rows as $c): ?>
    <div style="padding:8px 0;border-bottom:1px solid var(--border)">
      <div><?= $c['ok'] ? '✅' : '⚠️' ?> <strong><?= e($c['title']) ?></strong>
        <?php if ($c['amount'] !== null && (float)$c['amount'] != 0): ?><span style="float:right"><?= is_float($c['amount']) || strpos((string)$c['amount'], '.') !== false ? '₹' . money($c['amount']) : (int)$c['amount'] ?></span><?php endif; ?></div>
      <div class="muted" style="font-size:13px"><?= e($c['detail']) ?> <em>(<?= e($kinds[$c['kind']][1] ?? $c['kind']) ?>)</em></div>
    </div>
  <?php endforeach; ?>
</div>
<?php endforeach; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
