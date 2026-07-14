<?php
// Bank Reconciliation: tick off which recorded payments/expenses on one
// bank account have actually cleared, by matching against the real bank
// statement - catches entries that were recorded here but never actually
// went through (or vice versa) before they quietly distort the Bank Ledger.
require_once __DIR__ . '/includes/init.php';
require_perm('accounting.view');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'reconcile') {
    require_perm('accounting.edit');
    $bankId = (int)post('bank_account_id');
    $payIds = array_map('intval', post('pay_id', []));
    $expIds = array_map('intval', post('exp_id', []));
    $rDate = post('statement_date', today());
    if ($payIds) {
        $in = implode(',', array_fill(0, count($payIds), '?'));
        q("UPDATE payments SET is_reconciled = 1, reconciled_date = ? WHERE bank_account_id = ? AND id IN ($in)",
          array_merge([$rDate, $bankId], $payIds));
    }
    if ($expIds) {
        $in = implode(',', array_fill(0, count($expIds), '?'));
        q("UPDATE expenses SET is_reconciled = 1, reconciled_date = ? WHERE bank_account_id = ? AND id IN ($in)",
          array_merge([$rDate, $bankId], $expIds));
    }
    log_activity('bank_reconcile', "bank=$bankId " . (count($payIds) + count($expIds)) . ' entries');
    flash((count($payIds) + count($expIds)) . ' entries marked reconciled.');
    redirect('bank_reconcile.php?bank_account_id=' . $bankId);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'unreconcile') {
    require_perm('accounting.edit');
    $type = post('type'); $id = (int)post('id'); $bankId = (int)post('bank_account_id');
    if ($type === 'pay') q('UPDATE payments SET is_reconciled = 0, reconciled_date = NULL WHERE id = ?', [$id]);
    else q('UPDATE expenses SET is_reconciled = 0, reconciled_date = NULL WHERE id = ?', [$id]);
    flash('Entry marked un-reconciled.');
    redirect('bank_reconcile.php?bank_account_id=' . $bankId);
}

$banks = all('SELECT * FROM bank_accounts WHERE is_active = 1 ORDER BY is_default DESC, account_name');
$bankId = (int)get('bank_account_id') ?: (int)($banks[0]['id'] ?? 0);
$statementDate = get('statement_date', today());
$statementBalance = get('statement_balance', '');
$bank = $bankId ? row('SELECT * FROM bank_accounts WHERE id = ?', [$bankId]) : null;

$unreconciled = []; $reconciledBalance = 0;
if ($bank) {
    $reconciledBalance = (float)$bank['opening_balance']
        + (float)val("SELECT COALESCE(SUM(amount),0) FROM payments WHERE bank_account_id = ? AND direction = 'in' AND is_reconciled = 1 AND pay_date <= ?", [$bankId, $statementDate])
        - (float)val("SELECT COALESCE(SUM(amount),0) FROM payments WHERE bank_account_id = ? AND direction = 'out' AND is_reconciled = 1 AND pay_date <= ?", [$bankId, $statementDate])
        - (float)val("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE bank_account_id = ? AND is_reconciled = 1 AND exp_date <= ?", [$bankId, $statementDate]);

    foreach (all("SELECT p.*, pt.name party_name FROM payments p LEFT JOIN parties pt ON pt.id = p.party_id
                  WHERE p.bank_account_id = ? AND p.is_reconciled = 0 AND p.pay_date <= ? ORDER BY p.pay_date, p.id", [$bankId, $statementDate]) as $p) {
        $unreconciled[] = ['type' => 'pay', 'id' => $p['id'], 'date' => $p['pay_date'],
            'desc' => ($p['direction'] === 'in' ? 'Receipt' : 'Payment') . ' - ' . ($p['party_name'] ?: 'Walk-in') . ' (' . $p['mode'] . ')',
            'amount' => $p['direction'] === 'in' ? $p['amount'] : -$p['amount']];
    }
    foreach (all('SELECT * FROM expenses WHERE bank_account_id = ? AND is_reconciled = 0 AND exp_date <= ? ORDER BY exp_date, id', [$bankId, $statementDate]) as $x) {
        $unreconciled[] = ['type' => 'exp', 'id' => $x['id'], 'date' => $x['exp_date'], 'desc' => 'Expense - ' . $x['category'], 'amount' => -$x['amount']];
    }
    usort($unreconciled, fn($a, $b) => strcmp($a['date'], $b['date']));
}
$reconciledCount = $bankId ? (int)val('SELECT COUNT(*) FROM payments WHERE bank_account_id = ? AND is_reconciled = 1', [$bankId])
                            + (int)val('SELECT COUNT(*) FROM expenses WHERE bank_account_id = ? AND is_reconciled = 1', [$bankId]) : 0;

$reconciled = [];
if ($bank) {
    foreach (all("SELECT p.*, pt.name party_name FROM payments p LEFT JOIN parties pt ON pt.id = p.party_id
                  WHERE p.bank_account_id = ? AND p.is_reconciled = 1 ORDER BY p.reconciled_date DESC, p.id DESC LIMIT 100", [$bankId]) as $p) {
        $reconciled[] = ['type' => 'pay', 'id' => $p['id'], 'date' => $p['pay_date'], 'reconciled_date' => $p['reconciled_date'],
            'desc' => ($p['direction'] === 'in' ? 'Receipt' : 'Payment') . ' - ' . ($p['party_name'] ?: 'Walk-in') . ' (' . $p['mode'] . ')',
            'amount' => $p['direction'] === 'in' ? $p['amount'] : -$p['amount']];
    }
    foreach (all('SELECT * FROM expenses WHERE bank_account_id = ? AND is_reconciled = 1 ORDER BY reconciled_date DESC, id DESC LIMIT 100', [$bankId]) as $x) {
        $reconciled[] = ['type' => 'exp', 'id' => $x['id'], 'date' => $x['exp_date'], 'reconciled_date' => $x['reconciled_date'], 'desc' => 'Expense - ' . $x['category'], 'amount' => -$x['amount']];
    }
    usort($reconciled, fn($a, $b) => strcmp($b['reconciled_date'], $a['reconciled_date']));
}

$page_title = 'Bank Reconciliation';
include __DIR__ . '/includes/header.php';
?>
<div class="card">
  <p class="muted mb">Tick off every entry that actually appears on your real bank statement through the statement date, then compare against the statement's closing balance below. Anything left un-ticked either hasn't cleared yet or was never recorded correctly.</p>
  <form method="get" class="form-row cols-4">
    <div><label>Bank Account</label>
      <select name="bank_account_id" onchange="this.form.submit()">
        <?php foreach ($banks as $b): ?><option value="<?= $b['id'] ?>" <?= $b['id'] == $bankId ? 'selected' : '' ?>><?= e($b['account_name']) ?> - <?= e($b['bank_name']) ?></option><?php endforeach; ?>
      </select></div>
    <div><label>Statement Date</label><input type="date" name="statement_date" value="<?= e($statementDate) ?>" onchange="this.form.submit()"></div>
    <div><label>Statement Closing Balance (₹, optional)</label><input type="number" step="any" name="statement_balance" value="<?= e($statementBalance) ?>"></div>
    <div style="align-self:end"><button class="btn btn-sm" type="submit">Refresh</button></div>
  </form>
</div>
<?php if (!$bank): ?>
<div class="card"><p class="muted">Add a bank account first from <a href="bank_accounts.php">Bank Accounts</a>.</p></div>
<?php else: ?>
<div class="duo-cards">
  <div class="duo-card" style="background:#e0f2fe"><div class="duo-label" style="color:#075985">Reconciled Balance (as of <?= dmy($statementDate) ?>)</div><div class="duo-value" style="color:#0369a1">₹ <?= money($reconciledBalance) ?></div></div>
  <?php if ($statementBalance !== ''):
    $diff = (float)$statementBalance - $reconciledBalance; ?>
  <div class="duo-card <?= abs($diff) < 0.01 ? 'duo-get' : 'duo-give' ?>"><div class="duo-label">Difference vs Statement</div><div class="duo-value">₹ <?= money(abs($diff)) ?><?= abs($diff) < 0.01 ? ' ✓' : '' ?></div></div>
  <?php else: ?>
  <div class="duo-card"><div class="duo-label">Already Reconciled</div><div class="duo-value"><?= $reconciledCount ?> entries</div></div>
  <?php endif; ?>
</div>
<form method="post">
  <?= csrf_field() ?>
  <input type="hidden" name="do" value="reconcile">
  <input type="hidden" name="bank_account_id" value="<?= $bankId ?>">
  <input type="hidden" name="statement_date" value="<?= e($statementDate) ?>">
  <div class="card">
    <h3>Unreconciled entries through <?= dmy($statementDate) ?></h3>
    <div class="table-wrap">
    <table class="table-sm">
      <thead><tr><th style="width:30px"><input type="checkbox" id="checkAll"></th><th>Date</th><th>Description</th><th class="num">Amount ₹</th></tr></thead>
      <tbody>
      <?php foreach ($unreconciled as $x): ?>
      <tr>
        <td><input type="checkbox" name="<?= $x['type'] === 'pay' ? 'pay_id' : 'exp_id' ?>[]" value="<?= $x['id'] ?>" class="recon-cb"></td>
        <td><?= dmy($x['date']) ?></td>
        <td><?= e($x['desc']) ?></td>
        <td class="num" style="color:<?= $x['amount'] >= 0 ? 'var(--ok)' : 'var(--bad)' ?>"><?= $x['amount'] >= 0 ? '+' : '-' ?>₹<?= money(abs($x['amount'])) ?></td>
      </tr>
      <?php endforeach; if (!$unreconciled): ?><tr><td colspan="4" class="muted">Nothing left to reconcile through this date. 🎉</td></tr><?php endif; ?>
      </tbody>
    </table>
    </div>
  </div>
  <?php if ($unreconciled && can('accounting.edit')): ?>
  <button class="btn" type="submit">✓ Mark Selected as Reconciled</button>
  <?php endif; ?>
</form>
<?php if ($reconciled): ?>
<div class="card">
  <h3>Already reconciled (last 100, this bank account)</h3>
  <div class="table-wrap">
  <table class="table-sm">
    <thead><tr><th>Date</th><th>Description</th><th class="num">Amount ₹</th><th>Reconciled on</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($reconciled as $x): ?>
    <tr>
      <td><?= dmy($x['date']) ?></td>
      <td><?= e($x['desc']) ?></td>
      <td class="num" style="color:<?= $x['amount'] >= 0 ? 'var(--ok)' : 'var(--bad)' ?>"><?= $x['amount'] >= 0 ? '+' : '-' ?>₹<?= money(abs($x['amount'])) ?></td>
      <td><?= dmy($x['reconciled_date']) ?></td>
      <td><?php if (can('accounting.edit')): ?>
        <form method="post" onsubmit="return confirm('Un-reconcile this entry?')">
          <?= csrf_field() ?>
          <input type="hidden" name="do" value="unreconcile">
          <input type="hidden" name="type" value="<?= e($x['type']) ?>">
          <input type="hidden" name="id" value="<?= $x['id'] ?>">
          <input type="hidden" name="bank_account_id" value="<?= $bankId ?>">
          <button class="btn btn-sm btn-outline" type="submit">Un-reconcile</button>
        </form>
      <?php endif; ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endif; ?>
<script>
document.getElementById('checkAll').addEventListener('change', function () {
  document.querySelectorAll('.recon-cb').forEach(function (cb) { cb.checked = document.getElementById('checkAll').checked; });
});
</script>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
