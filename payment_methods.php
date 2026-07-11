<?php
// Payment method settings: the list of modes offered across Sale/Purchase/
// Payment/Expense forms (Cash, UPI, Card, Bank Transfer, Cheque, Credit...).
require_once __DIR__ . '/includes/init.php';
require_perm('settings.view');

$id = (int)get('id');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'save') {
    require_perm('settings.edit');
    $data = [post('name'), post('type', 'other'), (int)post('bank_account_id') ?: null, post('is_active') ? 1 : 0];
    if ($id) {
        q('UPDATE payment_methods SET name=?, type=?, bank_account_id=?, is_active=? WHERE id=?', array_merge($data, [$id]));
        flash('Payment method updated.');
    } else {
        $code = trim(preg_replace('/[^a-z0-9]+/', '_', strtolower(post('name'))), '_');
        if (!$code || val('SELECT id FROM payment_methods WHERE code = ?', [$code])) $code .= '_' . substr(md5(microtime()), 0, 4);
        $maxSort = (int)val('SELECT COALESCE(MAX(sort_order),0) FROM payment_methods');
        q('INSERT INTO payment_methods (code, name, type, bank_account_id, is_active, sort_order) VALUES (?,?,?,?,?,?)',
          array_merge([$code], $data, [$maxSort + 1]));
        flash('Payment method added.');
    }
    log_activity('payment_method_save', post('name'));
    redirect('payment_methods.php');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'delete') {
    require_perm('settings.edit');
    $pid = (int)post('id');
    if (val('SELECT is_system FROM payment_methods WHERE id = ?', [$pid])) {
        flash('System payment method deactivate જ કરી શકાય, delete નહીં.', 'error');
        q('UPDATE payment_methods SET is_active = 0 WHERE id = ?', [$pid]);
    } else {
        q('DELETE FROM payment_methods WHERE id = ?', [$pid]);
        flash('Payment method deleted.');
    }
    redirect('payment_methods.php');
}

$pm = $id ? row('SELECT * FROM payment_methods WHERE id = ?', [$id]) : null;
$methods = all('SELECT pm.*, b.account_name FROM payment_methods pm LEFT JOIN bank_accounts b ON b.id = pm.bank_account_id ORDER BY pm.sort_order, pm.id');
$banks = all('SELECT * FROM bank_accounts WHERE is_active = 1 ORDER BY account_name');
$page_title = 'Payment Methods';
include __DIR__ . '/includes/header.php';
?>
<div class="card">
  <h2><?= $pm ? 'Edit Payment Method' : 'Add Payment Method' ?></h2>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="save">
    <div class="form-row cols-3">
      <div><label>Name *</label><input type="text" name="name" value="<?= e($pm['name'] ?? '') ?>" required <?= !empty($pm['is_system']) ? '' : '' ?>></div>
      <div><label>Type</label>
        <select name="type" onchange="document.getElementById('bankSel').style.display=this.value==='bank'?'':'none'">
          <option value="cash" <?= ($pm['type'] ?? '') === 'cash' ? 'selected' : '' ?>>Cash</option>
          <option value="bank" <?= ($pm['type'] ?? '') === 'bank' ? 'selected' : '' ?>>Bank</option>
          <option value="other" <?= ($pm['type'] ?? 'other') === 'other' ? 'selected' : '' ?>>Other</option>
        </select></div>
      <div id="bankSel" style="<?= ($pm['type'] ?? '') === 'bank' ? '' : 'display:none' ?>"><label>Bank account (bookkeeping માટે)</label>
        <select name="bank_account_id"><option value="">-- select --</option>
        <?php foreach ($banks as $b): ?><option value="<?= $b['id'] ?>" <?= ($pm['bank_account_id'] ?? '') == $b['id'] ? 'selected' : '' ?>><?= e($b['account_name']) ?></option><?php endforeach; ?>
        </select></div>
    </div>
    <label class="check-inline mb"><input type="checkbox" name="is_active" value="1" <?= ($pm === null || $pm['is_active']) ? 'checked' : '' ?>> Active (bill બનાવતી વખતે dropdown માં દેખાય)</label>
    <button class="btn" type="submit">Save</button>
    <?php if ($pm): ?><a class="btn btn-muted" href="payment_methods.php">Cancel edit</a><?php endif; ?>
  </form>
</div>
<div class="table-wrap">
<table>
  <thead><tr><th>Name</th><th>Type</th><th>Bank Account</th><th>Status</th><th></th></tr></thead>
  <tbody><?php foreach ($methods as $m): ?>
    <tr>
      <td><?= e($m['name']) ?><?= $m['is_system'] ? ' <span class="badge badge-info">system</span>' : '' ?></td>
      <td><?= e($m['type']) ?></td>
      <td><?= e($m['account_name'] ?: '-') ?></td>
      <td><?= $m['is_active'] ? '<span class="badge badge-ok">active</span>' : '<span class="badge badge-bad">off</span>' ?></td>
      <td>
        <a class="btn btn-sm btn-outline" href="payment_methods.php?id=<?= $m['id'] ?>">Edit</a>
        <form method="post" style="display:inline" onsubmit="return confirm('Remove this payment method?')"><?= csrf_field() ?>
          <input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= $m['id'] ?>">
          <button class="btn btn-sm btn-danger" type="submit">✕</button></form>
      </td>
    </tr>
  <?php endforeach; ?></tbody>
</table>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
