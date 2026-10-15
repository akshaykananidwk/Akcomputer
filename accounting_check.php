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
$tb = acct_tb_totals($to);          // the OLD method, taken apart below
$nt = acct_new_tb_totals($to);      // the books now
$at = acct_attention($to);
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

<div class="card" style="border-left:4px solid <?= abs($nt['diff']) > 0.01 ? 'var(--bad)' : 'var(--ok)' ?>">
  <h3 style="margin-top:0">⚖️ Trial Balance (double entry) as of <?= dmy($to) ?></h3>
  <div class="stat-row" style="display:flex;gap:16px;flex-wrap:wrap">
    <div><div class="muted">Debit</div><strong>₹<?= money($nt['dr']) ?></strong></div>
    <div><div class="muted">Credit</div><strong>₹<?= money($nt['cr']) ?></strong></div>
    <div><div class="muted">Difference</div><strong style="color:<?= abs($nt['diff']) > 0.01 ? 'var(--bad)' : 'var(--ok)' ?>">₹<?= money($nt['diff']) ?></strong></div>
  </div>
  <p class="muted mt"><?= abs($nt['diff']) > 0.01 ? '⚠️ Only an unbalanced manual journal can do this - listed below.' : '✅ Balanced. Every entry is posted on both sides; nothing is put in a suspense account to make it agree.' ?>
    <a href="reports.php?r=trial_balance&to=<?= e($to) ?>">Open the Trial Balance</a></p>
</div>

<div class="card">
  <h3 style="margin-top:0">👀 What the books need you to look at</h3>
  <?php $sec = function ($ok, $title, $amount, $body) { ?>
    <div style="padding:10px 0;border-bottom:1px solid var(--border)">
      <div><?= $ok ? '✅' : '⚠️' ?> <strong><?= e($title) ?></strong><?php if ($amount !== null): ?><span style="float:right">₹<?= money($amount) ?></span><?php endif; ?></div>
      <div class="muted" style="font-size:13px"><?= $body ?></div>
    </div>
  <?php }; ?>
  <?php
  $rowsHtml = '';
  foreach (array_slice($at['unclassified'], 0, 30) as $x)
      $rowsHtml .= '<li>' . dmy($x['txn_date']) . ' · ' . ($x['txn_type'] === 'cash_adjust' ? 'Cash' : 'Bank' . ($x['bank'] ? ' (' . e($x['bank']) . ')' : '')) . ' ' . ($x['adjust_dir'] === 'add' ? '+' : '−') . '₹' . money($x['amount'])
                 . ($x['notes'] ? ' · ' . e($x['notes']) : '') . ' · <a href="cash_bank.php?action=classify&id=' . (int)$x['id'] . '">Say what it was</a></li>';
  $sec(!$at['unclassified'], 'Cash / bank adjustments - what were they?', $at['unclassified_total'],
       'An adjustment changes cash or bank with nothing on the other side. The books cannot guess what it was - money you put in from home (capital), an old balance (opening), income, a counting shortage, or money taken home. Until you say, it sits under "not yet classified" (shown openly on the Balance Sheet, not hidden).'
       . ($rowsHtml ? '<ul style="margin:6px 0 0 18px">' . $rowsHtml . '</ul>' : ''));
  $labels = ['opening' => 'parties\' opening balances', 'bank_opening' => 'bank opening balances', 'stock' => 'opening stock (opening / Excel import)', 'loan' => 'loans from the Loans register (the money taken)', 'adjust' => 'adjustments you marked as opening'];
  $op = [];
  foreach ($at['opening'] as $k => $amt) $op[] = e($labels[$k] ?? $k) . ' ₹' . money($amt);
  $sec(true, 'Opening balances (from before the software)', $at['opening_total'],
       'Old balances need one opening figure on the other side - like Tally\'s "Opening balance difference". It is made of: ' . ($op ? implode(' · ', $op) : 'nothing') . '. Check each against your old books / bank statement on the start date.');
  $nb = [];
  foreach ($at['no_bank'] as $r) $nb[] = e($r['kind'] . ' ' . $r['mode']) . ': ' . (int)$r['n'] . ' entries ₹' . money($r['amt']);
  $sec(abs($at['no_bank_total']) < 0.01, 'UPI / card / cheque saved without a bank account', $at['no_bank_total'],
       'This money is in no bank and not in cash - it shows as "Money not in a bank yet". ' . ($nb ? implode(' · ', $nb) . '. Pick the bank account on these entries (or set the UPI payment method\'s bank in Settings → Payment methods so new ones go there).' : ''));
  $sec(true, 'GST', $at['gst_out'] - $at['gst_in'],
       'Collected on sales ₹' . money($at['gst_out']) . ' − input credit on purchases ₹' . money($at['gst_in']) . ' = what is owed to the government, before any GST you have already paid (paid GST typed as an expense is not deducted here).');
  $st = $at['stock'];
  $sec(abs($st['diff']) < 1, 'Stock: books vs shelf', $st['diff'],
       'Books ₹' . money($st['book']) . ' (opening + purchases − cost of sales ± corrections) · shelf today ₹' . money($st['shelf']) . ' (quantity × today\'s purchase price). The gap comes from purchase prices that changed after buying, freight / discount inside a purchase bill\'s cost, free goods, and stock that moved before the software kept a log'
       . (abs($st['qty_gap']) > 0.5 ? ' - stock quantities with no movement record are worth ₹' . money($st['qty_gap']) : '') . '. The Stock report shows the shelf; the Balance Sheet shows the books.');
  $sec(abs($at['cash_books'] - $at['cash_page']) < 0.01, 'Cash in hand: books vs Cash & Bank page', $at['cash_books'] - $at['cash_page'],
       'Books ₹' . money($at['cash_books']) . ' · Cash & Bank page ₹' . money($at['cash_page']) . '. A gap means journal entries were posted straight to 1000 Cash in Hand (the page does not see journals).');
  if (abs($at['walkin']) > 0.009) $sec(false, 'Unpaid walk-in bills (no party)', $at['walkin'], 'Shown as "Walk-in customers" receivable. Put a party on these bills to collect them through Payment-In.');
  if (abs($at['contra']) > 0.009) $sec(false, 'Contra settlements that do not net to zero', $at['contra'], 'Each contra should have an equal in and out entry.');
  if (abs($at['staff_adv']) > 0.009) $sec(true, 'Staff advances not yet recovered', $at['staff_adv'], 'Advances given ("Staff Advance" expenses) less what payroll took back from salaries.');
  if (abs($at['loans']) > 0.009) $sec(true, 'Loans outstanding', $at['loans'], 'Loans register principal less the principal part of EMIs paid through Books → Loans.');
  if ($at['unbalanced']) $sec(false, 'Journal entries whose debit ≠ credit', null, implode(', ', array_map(fn($j) => '<a href="journal.php?action=view&id=' . (int)$j['id'] . '">' . e($j['ref_no']) . '</a> (Dr ' . money($j['d']) . ' / Cr ' . money($j['c']) . ')', $at['unbalanced'])));
  ?>
</div>

<div class="card">
  <h3 style="margin-top:0">🧾 The OLD Trial Balance difference, and where it came from</h3>
  <div class="stat-row" style="display:flex;gap:16px;flex-wrap:wrap">
    <div><div class="muted">Old debit</div><strong>₹<?= money($tb['dr']) ?></strong></div>
    <div><div class="muted">Old credit</div><strong>₹<?= money($tb['cr']) ?></strong></div>
    <div><div class="muted">Old difference</div><strong>₹<?= money($tb['diff']) ?></strong></div>
  </div>
  <p class="muted mt">Before this update the Trial Balance worked each line out on its own, so it never balanced. The reasons below add up to <strong>₹<?= money($sum) ?></strong><?= abs($sum - $tb['diff']) < 0.01 ? ' — exactly that old difference ✅' : ' — ⚠️ not equal: please report this' ?>. Each is now posted properly in the books above.</p>
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
