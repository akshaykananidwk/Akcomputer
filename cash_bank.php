<?php
// Cash & Bank: cash-in-hand, per-bank-account balances, today's cash summary
require_once __DIR__ . '/includes/init.php';
require_perm('payments.view');

function cash_balance() {
    return (float)val("SELECT
        COALESCE((SELECT SUM(amount) FROM payments WHERE mode = 'cash' AND direction = 'in'), 0)
        - COALESCE((SELECT SUM(amount) FROM payments WHERE mode = 'cash' AND direction = 'out'), 0)
        - COALESCE((SELECT SUM(amount) FROM expenses WHERE mode = 'cash'), 0)");
}
function bank_balance($id) {
    return (float)val("SELECT b.opening_balance
        + COALESCE((SELECT SUM(amount) FROM payments WHERE bank_account_id = b.id AND direction = 'in'), 0)
        - COALESCE((SELECT SUM(amount) FROM payments WHERE bank_account_id = b.id AND direction = 'out'), 0)
        - COALESCE((SELECT SUM(amount) FROM expenses WHERE bank_account_id = b.id), 0)
        FROM bank_accounts b WHERE b.id = ?", [$id]);
}

$today = today();
$todayCashIn = (float)val("SELECT COALESCE(SUM(amount),0) FROM payments WHERE mode = 'cash' AND direction = 'in' AND pay_date = ?", [$today]);
$todayCashOut = (float)val("SELECT COALESCE(SUM(amount),0) FROM payments WHERE mode = 'cash' AND direction = 'out' AND pay_date = ?", [$today]);
$todayCashExp = (float)val("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE mode = 'cash' AND exp_date = ?", [$today]);
$cashInHand = cash_balance();

$banks = all('SELECT * FROM bank_accounts WHERE is_active = 1 ORDER BY is_default DESC, account_name');
$totalBankBal = 0;
foreach ($banks as &$b) { $b['balance'] = bank_balance($b['id']); $totalBankBal += $b['balance']; }
unset($b);

$recentCash = all("SELECT p.*, pt.name party_name FROM payments p LEFT JOIN parties pt ON pt.id = p.party_id
                   WHERE p.mode = 'cash' ORDER BY p.id DESC LIMIT 15");

$page_title = 'Cash & Bank';
include __DIR__ . '/includes/header.php';
?>
<div class="duo-cards">
  <div class="duo-card duo-get"><div class="duo-label">💵 Cash in Hand</div><div class="duo-value">₹ <?= money($cashInHand) ?></div></div>
  <div class="duo-card" style="background:#e0f2fe"><div class="duo-label" style="color:#075985">🏦 Total Bank Balance</div><div class="duo-value" style="color:#0369a1">₹ <?= money($totalBankBal) ?></div></div>
</div>

<div class="card">
  <h2>📅 Today's Cash Summary (<?= dmy($today) ?>)</h2>
  <div class="grid-stats" style="margin-bottom:0">
    <div class="stat s-ok"><div class="stat-label">Cash Received</div><div class="stat-value">₹<?= money($todayCashIn) ?></div></div>
    <div class="stat s-bad"><div class="stat-label">Cash Paid Out</div><div class="stat-value">₹<?= money($todayCashOut) ?></div></div>
    <div class="stat s-bad"><div class="stat-label">Cash Expenses</div><div class="stat-value">₹<?= money($todayCashExp) ?></div></div>
    <div class="stat"><div class="stat-label">Net Cash Today</div><div class="stat-value">₹<?= money($todayCashIn - $todayCashOut - $todayCashExp) ?></div></div>
  </div>
</div>

<div class="card">
  <h2>🏦 Bank Accounts <a class="btn btn-sm btn-outline" style="float:right" href="bank_accounts.php">Manage →</a></h2>
  <?php if (!$banks): ?><p class="muted">No bank account added yet. <a href="bank_accounts.php">+ Add Bank Account</a></p><?php else: ?>
  <table class="table-sm">
    <?php foreach ($banks as $b): ?>
    <tr><td><a href="reports.php?r=bank_ledger&bank_id=<?= $b['id'] ?>"><?= e($b['account_name']) ?> - <?= e($b['bank_name']) ?></a><?= $b['is_default'] ? ' <span class="badge badge-ok">DEFAULT</span>' : '' ?></td>
    <td class="num">₹<?= money($b['balance']) ?></td>
    <td><a class="btn btn-sm btn-outline" href="reports.php?r=bank_ledger&bank_id=<?= $b['id'] ?>">📒 Ledger</a></td></tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Recent cash entries</h2>
  <table class="table-sm">
    <thead><tr><th>Date</th><th>Party</th><th>Dir</th><th class="num">Amount</th><th>Notes</th></tr></thead>
    <tbody><?php foreach ($recentCash as $c): ?>
    <tr>
      <td><?= dmy($c['pay_date']) ?></td>
      <td><?= $c['party_id'] ? '<a href="parties.php?action=ledger&id=' . $c['party_id'] . '">' . e($c['party_name']) . '</a>' : e($c['party_name'] ?: 'Walk-in') ?></td>
      <td><?= $c['direction'] === 'in' ? '<span class="badge badge-ok">IN</span>' : '<span class="badge badge-bad">OUT</span>' ?></td>
      <td class="num">₹<?= money($c['amount']) ?></td>
      <td><?= e($c['notes']) ?></td>
    </tr>
    <?php endforeach; if (!$recentCash): ?><tr><td colspan="5" class="muted">No cash entries.</td></tr><?php endif; ?></tbody>
  </table>
  <p class="mt"><a href="reports.php?r=cashbook">Full Cashbook Report →</a></p>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
