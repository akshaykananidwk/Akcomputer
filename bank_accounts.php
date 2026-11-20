<?php
// Bank accounts: multiple accounts, running balance, default account for invoice QR/details
require_once __DIR__ . '/includes/init.php';
require_perm('settings.view');

$id = (int)get('id');
// a credit card (and any account marked admin-only) is the main admin's alone
if ($id && !val('SELECT id FROM bank_accounts WHERE id = ?' . bank_hide_sql(), [$id])) { flash('Account not found.', 'error'); redirect('bank_accounts.php'); }
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'save') {
    require_perm('settings.edit');
    $isCard = post('account_type') === 'credit_card' && is_full_admin();
    // a card's balance is minus what is owed on it: the owner types what is owed today
    $opening = $isCard ? -abs((float)post('card_owed')) : (float)post('opening_balance');
    $data = [post('account_name'), post('bank_name'), post('account_number'), $isCard ? '' : strtoupper(post('ifsc')),
              post('branch'), $isCard ? '' : post('upi_id'), $opening, post('is_active') ? 1 : 0,
              $isCard ? 'credit_card' : 'bank', ($isCard || (is_full_admin() && post('admin_only'))) ? 1 : 0,
              $isCard ? max(0, (float)post('credit_limit')) : 0,
              $isCard && (int)post('bill_day') ? min(31, (int)post('bill_day')) : null, $isCard && (int)post('due_day') ? min(31, (int)post('due_day')) : null];
    if ($id) {
        q('UPDATE bank_accounts SET account_name=?, bank_name=?, account_number=?, ifsc=?, branch=?, upi_id=?, opening_balance=?, is_active=?,
           account_type=?, admin_only=?, credit_limit=?, bill_day=?, due_day=? WHERE id=?', array_merge($data, [$id]));
        flash($isCard ? 'Credit card updated.' : 'Bank account updated.');
    } else {
        q('INSERT INTO bank_accounts (account_name, bank_name, account_number, ifsc, branch, upi_id, opening_balance, is_active,
           account_type, admin_only, credit_limit, bill_day, due_day) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)', $data);
        flash($isCard ? 'Credit card added - only you (the main admin) can see it.' : 'Bank account added.');
        $id = (int)val('SELECT MAX(id) FROM bank_accounts');
    }
    if ($isCard) q('UPDATE bank_accounts SET is_default = 0 WHERE id = ?', [$id]);   // a card never shows on invoices
    elseif (post('is_default')) {
        q('UPDATE bank_accounts SET is_default = 0');
        q('UPDATE bank_accounts SET is_default = 1 WHERE id = ?', [$id]);
    }
    log_activity('bank_account_save', post('account_name'));
    redirect('bank_accounts.php');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'set_default') {
    require_perm('settings.edit');
    if (val("SELECT id FROM bank_accounts WHERE id = ? AND account_type = 'credit_card'", [(int)post('id')])) { flash('A credit card cannot be the invoice account.', 'error'); redirect('bank_accounts.php'); }
    q('UPDATE bank_accounts SET is_default = 0');
    q('UPDATE bank_accounts SET is_default = 1 WHERE id = ?', [(int)post('id')]);
    flash('Default account set - it will show on invoices.');
    redirect('bank_accounts.php');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'delete') {
    require_perm('settings.edit');
    $bid = (int)post('id');
    if (!val('SELECT id FROM bank_accounts WHERE id = ?' . bank_hide_sql(), [$bid])) redirect('bank_accounts.php');
    // payments/expenses already reference this account - deactivate (not
    // hard delete) so old ledger entries keep pointing at a real row.
    q('UPDATE bank_accounts SET is_active = 0, is_default = 0 WHERE id = ?', [$bid]);
    log_activity('bank_account_delete', "#$bid");
    flash('Bank account deactivated.');
    redirect('bank_accounts.php');
}

$acc = $id ? row('SELECT * FROM bank_accounts WHERE id = ?', [$id]) : null;
$accounts = all('SELECT * FROM bank_accounts WHERE 1=1' . bank_hide_sql() . " ORDER BY account_type = 'credit_card', is_default DESC, account_name");
$editCard = ($acc['account_type'] ?? (get('type') === 'card' ? 'credit_card' : 'bank')) === 'credit_card';
$page_title = 'Bank Accounts';
include __DIR__ . '/includes/header.php';
?>
<div class="card">
  <h2><?= $acc ? ($editCard ? 'Edit Credit Card' : 'Edit Bank Account') : 'Add Bank Account / Credit Card' ?></h2>
  <p class="muted mb">The "Default" account's details + UPI QR appear on every invoice.</p>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="save">
    <?php if (is_full_admin()): ?>
    <div class="form-row cols-3">
      <div><label>Type</label><select name="account_type" onchange="document.querySelectorAll('.only-bank').forEach(e=>e.style.display=this.value==='bank'?'':'none');document.querySelectorAll('.only-card').forEach(e=>e.style.display=this.value==='bank'?'none':'')">
        <option value="bank">🏦 Bank account</option><option value="credit_card" <?= $editCard ? 'selected' : '' ?>>💳 Credit card (only you see it)</option></select></div>
    </div>
    <?php endif; ?>
    <div class="form-row cols-3">
      <div><label>Account holder name *</label><input type="text" name="account_name" value="<?= e($acc['account_name'] ?? '') ?>" required></div>
      <div><label>Bank name *</label><input type="text" name="bank_name" value="<?= e($acc['bank_name'] ?? '') ?>" required></div>
      <div><label>Branch</label><input type="text" name="branch" value="<?= e($acc['branch'] ?? '') ?>"></div>
    </div>
    <div class="form-row cols-3">
      <div><label>Account / card number <span class="muted" style="font-weight:normal">(last 4 digits are enough)</span></label><input type="text" name="account_number" value="<?= e($acc['account_number'] ?? '') ?>"></div>
      <div class="only-bank" style="<?= $editCard ? 'display:none' : '' ?>"><label>IFSC</label><input type="text" name="ifsc" value="<?= e($acc['ifsc'] ?? '') ?>"></div>
      <div class="only-bank" style="<?= $editCard ? 'display:none' : '' ?>"><label>UPI ID (for QR)</label><input type="text" name="upi_id" value="<?= e($acc['upi_id'] ?? '') ?>" placeholder="name@okhdfcbank"></div>
    </div>
    <div class="form-row cols-3 only-card" style="<?= $editCard ? '' : 'display:none' ?>">
      <div><label>Owed on the card today (₹)</label><input type="number" step="any" min="0" name="card_owed" value="<?= $editCard ? e(-(float)($acc['opening_balance'] ?? 0)) : '0' ?>"></div>
      <div><label>Credit limit (₹)</label><input type="number" step="any" min="0" name="credit_limit" value="<?= e($acc['credit_limit'] ?? '0') ?>"></div>
      <div><label>Bill date / payment due date (day of month)</label><div style="display:flex;gap:6px">
        <input type="number" min="1" max="31" name="bill_day" placeholder="bill" value="<?= e($acc['bill_day'] ?? '') ?>">
        <input type="number" min="1" max="31" name="due_day" placeholder="due" value="<?= e($acc['due_day'] ?? '') ?>"></div></div>
    </div>
    <div class="form-row cols-3">
      <div class="only-bank" style="<?= $editCard ? 'display:none' : '' ?>"><label>Opening balance (₹)</label><input type="number" step="any" name="opening_balance" value="<?= e($acc['opening_balance'] ?? '0') ?>"></div>
      <div class="only-bank" style="<?= $editCard ? 'display:none' : '' ?>"><label class="check-inline mt"><input type="checkbox" name="is_default" value="1" <?= !empty($acc['is_default']) ? 'checked' : '' ?>> Default (show on invoice)</label></div>
      <div><label class="check-inline mt"><input type="checkbox" name="is_active" value="1" <?= ($acc === null || $acc['is_active']) ? 'checked' : '' ?>> Active</label></div>
    </div>
    <button class="btn" type="submit">Save</button>
    <?php if ($acc): ?><a class="btn btn-muted" href="bank_accounts.php">Cancel edit</a><?php endif; ?>
  </form>
</div>
<div class="table-wrap">
<table>
  <thead><tr><th>Account</th><th>Bank</th><th>A/C No</th><th class="num">Balance</th><th></th><th></th><th></th></tr></thead>
  <tbody><?php foreach ($accounts as $b): $card = $b['account_type'] === 'credit_card'; $bal = bank_account_balance($b['id']); ?>
    <tr>
      <td><?= $card ? '💳 ' : '' ?><a href="reports.php?r=bank_ledger&bank_id=<?= $b['id'] ?>"><?= e($b['account_name']) ?></a><?= $b['is_default'] ? ' <span class="badge badge-ok">DEFAULT</span>' : '' ?>
        <?= $card ? ' <span class="badge">Credit card · only you</span>' : ($b['admin_only'] ? ' <span class="badge">only you</span>' : '') ?>
        <?php if ($card && ($b['bill_day'] || $b['due_day'])): ?><br><small class="muted">bill on <?= (int)$b['bill_day'] ?: '-' ?> · pay by <?= (int)$b['due_day'] ?: '-' ?> every month</small><?php endif; ?></td>
      <td><?= e($b['bank_name']) ?></td>
      <td><?= e($b['account_number']) ?></td>
      <td class="num"><?php if ($card): ?><strong style="color:var(--bad)">Owed ₹<?= money(max(0, -$bal)) ?></strong>
        <?php if ((float)$b['credit_limit'] > 0): ?><br><small class="muted">available ₹<?= money(max(0, (float)$b['credit_limit'] + $bal)) ?> of ₹<?= money($b['credit_limit']) ?></small><?php endif; ?>
        <?php else: ?>₹<?= money($bal) ?><?php endif; ?></td>
      <td><a class="btn btn-sm btn-outline" href="reports.php?r=bank_ledger&bank_id=<?= $b['id'] ?>">📒 Ledger</a></td>
      <td><a class="btn btn-sm btn-outline" href="bank_accounts.php?id=<?= $b['id'] ?>">Edit</a></td>
      <td style="white-space:nowrap"><?php if (!$b['is_default']): ?>
        <?php if (!$card): ?><form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="do" value="set_default"><input type="hidden" name="id" value="<?= $b['id'] ?>">
        <button class="btn btn-sm" type="submit">Make Default</button></form><?php else: ?><a class="btn btn-sm" href="cash_bank.php?pay_card=<?= $b['id'] ?>">💳 Pay card bill</a><?php endif; ?>
        <form method="post" style="display:inline" onsubmit="return confirm('Deactivate this bank account?')"><?= csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= $b['id'] ?>">
        <button class="btn btn-sm btn-danger" type="submit">✕</button></form>
      <?php endif; ?></td>
    </tr>
  <?php endforeach; if (!$accounts): ?><tr><td colspan="7" class="muted">No bank account added yet.</td></tr><?php endif; ?></tbody>
</table>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
