<?php
// Chart of Accounts: the system-seeded accounts (Cash, Bank, Accounts
// Receivable/Payable, Stock, Capital, Retained Earnings, Sales, COGS) that
// General Ledger / Trial Balance / Balance Sheet / P&L are computed
// against, plus any custom accounts (e.g. a Loan account, a specific Fixed
// Asset) an admin wants to post manual Journal entries to.
require_once __DIR__ . '/includes/init.php';
require_perm('accounting.view');

$id = (int)get('id');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'save') {
    require_perm('accounting.edit');
    $code = trim(post('code'));
    $name = trim(post('name'));
    $type = in_array(post('type'), ['asset', 'liability', 'equity', 'income', 'expense'], true) ? post('type') : 'asset';
    if (!$code || !$name) { flash('Code and name are required.', 'error'); redirect('accounts.php'); }
    if (val('SELECT id FROM chart_of_accounts WHERE code = ?', [$code])) { flash('That account code is already used.', 'error'); redirect('accounts.php'); }
    $maxSort = (int)val('SELECT COALESCE(MAX(sort_order),0) FROM chart_of_accounts');
    q('INSERT INTO chart_of_accounts (code, name, type, is_system, is_active, sort_order) VALUES (?,?,?,0,1,?)', [$code, $name, $type, $maxSort + 10]);
    log_activity('account_add', "$code $name");
    flash('Account added.');
    redirect('accounts.php');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'toggle') {
    require_perm('accounting.edit');
    $aid = (int)post('id');
    $acc = row('SELECT * FROM chart_of_accounts WHERE id = ?', [$aid]);
    if ($acc && !$acc['is_system']) {
        q('UPDATE chart_of_accounts SET is_active = ? WHERE id = ?', [$acc['is_active'] ? 0 : 1, $aid]);
        flash($acc['is_active'] ? 'Account deactivated.' : 'Account reactivated.');
    } elseif ($acc) {
        flash('System accounts can\'t be deactivated - they\'re required by the financial reports.', 'error');
    }
    redirect('accounts.php');
}

$accounts = coa_all(false);
$page_title = 'Chart of Accounts';
include __DIR__ . '/includes/header.php';
?>
<div class="card">
  <p class="muted mb">The accounts General Ledger, Trial Balance, Balance Sheet and P&amp;L are built from. System accounts (marked <span class="badge badge-info">system</span>) are computed automatically from your sales/purchases/payments/expenses and can't be removed. Add a custom account here when you need to post a <a href="journal.php">Journal / Adjustment entry</a> against something that isn't already covered - a loan, a specific fixed asset, etc.</p>
  <?php if (can('accounting.edit')): ?>
  <form method="post" class="form-row cols-4">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="save">
    <div><label>Code *</label><input type="text" name="code" placeholder="e.g. 1410" required></div>
    <div><label>Name *</label><input type="text" name="name" placeholder="e.g. Office Computer (Fixed Asset)" required></div>
    <div><label>Type</label>
      <select name="type">
        <option value="asset">Asset</option>
        <option value="liability">Liability</option>
        <option value="equity">Equity</option>
        <option value="income">Income</option>
        <option value="expense">Expense</option>
      </select></div>
    <div style="align-self:end"><button class="btn" type="submit">+ Add Account</button></div>
  </form>
  <?php endif; ?>
</div>
<div class="table-wrap">
<table>
  <thead><tr><th>Code</th><th>Name</th><th>Type</th><th>Status</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($accounts as $a): ?>
  <tr>
    <td><?= e($a['code']) ?></td>
    <td><?= e($a['name']) ?><?= $a['is_system'] ? ' <span class="badge badge-info">system</span>' : '' ?></td>
    <td><?= e(ucfirst($a['type'])) ?></td>
    <td><?= $a['is_active'] ? '<span class="badge badge-ok">active</span>' : '<span class="badge badge-bad">off</span>' ?></td>
    <td>
      <?php if (can('accounting.edit') && !$a['is_system']): ?>
      <form method="post" style="display:inline"><?= csrf_field() ?>
        <input type="hidden" name="do" value="toggle"><input type="hidden" name="id" value="<?= $a['id'] ?>">
        <button class="btn btn-sm btn-outline" type="submit"><?= $a['is_active'] ? 'Deactivate' : 'Reactivate' ?></button>
      </form>
      <?php endif; ?>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
