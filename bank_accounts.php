<?php
// Bank accounts: multiple accounts, running balance, default account for invoice QR/details
require_once __DIR__ . '/includes/init.php';
require_perm('settings.view');

function bank_balance($id) {
    return (float)val("SELECT b.opening_balance
        + COALESCE((SELECT SUM(amount) FROM payments WHERE bank_account_id = b.id AND direction = 'in'), 0)
        - COALESCE((SELECT SUM(amount) FROM payments WHERE bank_account_id = b.id AND direction = 'out'), 0)
        - COALESCE((SELECT SUM(amount) FROM expenses WHERE bank_account_id = b.id), 0)
        FROM bank_accounts b WHERE b.id = ?", [$id]);
}

$id = (int)get('id');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'save') {
    require_perm('settings.edit');
    $data = [post('account_name'), post('bank_name'), post('account_number'), strtoupper(post('ifsc')),
              post('branch'), post('upi_id'), (float)post('opening_balance'), post('is_active') ? 1 : 0];
    if ($id) {
        q('UPDATE bank_accounts SET account_name=?, bank_name=?, account_number=?, ifsc=?, branch=?, upi_id=?, opening_balance=?, is_active=? WHERE id=?',
          array_merge($data, [$id]));
        flash('Bank account updated.');
    } else {
        q('INSERT INTO bank_accounts (account_name, bank_name, account_number, ifsc, branch, upi_id, opening_balance, is_active) VALUES (?,?,?,?,?,?,?,?)', $data);
        flash('Bank account added.');
        $id = insert_id();
    }
    if (post('is_default')) {
        q('UPDATE bank_accounts SET is_default = 0');
        q('UPDATE bank_accounts SET is_default = 1 WHERE id = ?', [$id]);
    }
    log_activity('bank_account_save', post('account_name'));
    redirect('bank_accounts.php');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'set_default') {
    require_perm('settings.edit');
    q('UPDATE bank_accounts SET is_default = 0');
    q('UPDATE bank_accounts SET is_default = 1 WHERE id = ?', [(int)post('id')]);
    flash('Default account set - એ જ invoice પર દેખાશે.');
    redirect('bank_accounts.php');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'delete') {
    require_perm('settings.edit');
    $bid = (int)post('id');
    // payments/expenses already reference this account - deactivate (not
    // hard delete) so old ledger entries keep pointing at a real row.
    q('UPDATE bank_accounts SET is_active = 0, is_default = 0 WHERE id = ?', [$bid]);
    log_activity('bank_account_delete', "#$bid");
    flash('Bank account deactivated.');
    redirect('bank_accounts.php');
}

$acc = $id ? row('SELECT * FROM bank_accounts WHERE id = ?', [$id]) : null;
$accounts = all('SELECT * FROM bank_accounts ORDER BY is_default DESC, account_name');
$page_title = 'Bank Accounts';
include __DIR__ . '/includes/header.php';
?>
<div class="card">
  <h2><?= $acc ? 'Edit Bank Account' : 'Add Bank Account' ?></h2>
  <p class="muted mb">"Default" account ની details + UPI QR દરેક invoice પર દેખાય છે.</p>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="save">
    <div class="form-row cols-3">
      <div><label>Account holder name *</label><input type="text" name="account_name" value="<?= e($acc['account_name'] ?? '') ?>" required></div>
      <div><label>Bank name *</label><input type="text" name="bank_name" value="<?= e($acc['bank_name'] ?? '') ?>" required></div>
      <div><label>Branch</label><input type="text" name="branch" value="<?= e($acc['branch'] ?? '') ?>"></div>
    </div>
    <div class="form-row cols-3">
      <div><label>Account number</label><input type="text" name="account_number" value="<?= e($acc['account_number'] ?? '') ?>"></div>
      <div><label>IFSC</label><input type="text" name="ifsc" value="<?= e($acc['ifsc'] ?? '') ?>"></div>
      <div><label>UPI ID (QR માટે)</label><input type="text" name="upi_id" value="<?= e($acc['upi_id'] ?? '') ?>" placeholder="name@okhdfcbank"></div>
    </div>
    <div class="form-row cols-3">
      <div><label>Opening balance (₹)</label><input type="number" step="any" name="opening_balance" value="<?= e($acc['opening_balance'] ?? '0') ?>"></div>
      <div><label class="check-inline mt"><input type="checkbox" name="is_default" value="1" <?= !empty($acc['is_default']) ? 'checked' : '' ?>> Default (invoice પર બતાવવું)</label></div>
      <div><label class="check-inline mt"><input type="checkbox" name="is_active" value="1" <?= ($acc === null || $acc['is_active']) ? 'checked' : '' ?>> Active</label></div>
    </div>
    <button class="btn" type="submit">Save</button>
    <?php if ($acc): ?><a class="btn btn-muted" href="bank_accounts.php">Cancel edit</a><?php endif; ?>
  </form>
</div>
<div class="table-wrap">
<table>
  <thead><tr><th>Account</th><th>Bank</th><th>A/C No</th><th class="num">Balance</th><th></th><th></th></tr></thead>
  <tbody><?php foreach ($accounts as $b): ?>
    <tr>
      <td><?= e($b['account_name']) ?><?= $b['is_default'] ? ' <span class="badge badge-ok">DEFAULT</span>' : '' ?></td>
      <td><?= e($b['bank_name']) ?></td>
      <td><?= e($b['account_number']) ?></td>
      <td class="num">₹<?= money(bank_balance($b['id'])) ?></td>
      <td><a class="btn btn-sm btn-outline" href="bank_accounts.php?id=<?= $b['id'] ?>">Edit</a></td>
      <td style="white-space:nowrap"><?php if (!$b['is_default']): ?>
        <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="do" value="set_default"><input type="hidden" name="id" value="<?= $b['id'] ?>">
        <button class="btn btn-sm" type="submit">Make Default</button></form>
        <form method="post" style="display:inline" onsubmit="return confirm('Bank account deactivate કરવું?')"><?= csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= $b['id'] ?>">
        <button class="btn btn-sm btn-danger" type="submit">✕</button></form>
      <?php endif; ?></td>
    </tr>
  <?php endforeach; if (!$accounts): ?><tr><td colspan="6" class="muted">હજી કોઈ bank account ઉમેર્યું નથી.</td></tr><?php endif; ?></tbody>
</table>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
