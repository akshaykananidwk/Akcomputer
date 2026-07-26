<?php
// Dealer / electrician logins for the public website (catalog.php).
// Each account has its own discount % - after login the store shows that
// account every price already reduced, and its orders are priced the same
// way server-side. Credentials are handed out from here (and can be
// WhatsApped to the dealer in one tap).
require_once __DIR__ . '/includes/init.php';
require_perm('webcustomers.view');
$u = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'add') {
    require_perm('webcustomers.edit');
    $mobile = preg_replace('/\D/', '', post('mobile'));
    $pass = post('password') !== '' ? post('password') : substr(str_shuffle('23456789abcdefghjkmnpqrstuvwxyz'), 0, 8);
    if (!post('name') || strlen($mobile) < 10) {
        flash('Name and a valid mobile number are required.', 'error');
    } elseif (val('SELECT id FROM web_accounts WHERE mobile = ?', [$mobile])) {
        flash('An account with this mobile already exists.', 'error');
    } else {
        q('INSERT INTO web_accounts (name, mobile, password_hash, discount_pct, party_id, is_active) VALUES (?,?,?,?,?,1)',
          [post('name'), $mobile, password_hash($pass, PASSWORD_DEFAULT),
           max(0, min(90, (float)post('discount_pct'))), (int)post('party_id') ?: null]);
        log_activity('web_account_add', post('name') . " ($mobile)");
        if (post('send_wa')) {
            send_whatsapp($mobile, "🔑 *" . setting('app_name', 'AK Computer') . "*\n\nતમારું ડીલર લોગિન તૈયાર છે!\n" .
                base_url('catalog.php?dlogin=1') . "\n\nMobile: $mobile\nPassword: $pass\n\nલોગિન કરો એટલે તમારો સ્પેશિયલ ભાવ આપોઆપ દેખાશે. 🙏");
        }
        flash('Dealer account created. Password: ' . $pass . (post('send_wa') ? ' (WhatsApped to them)' : ' — note it down, it is not shown again.'));
    }
    redirect('web_customers.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'update') {
    require_perm('webcustomers.edit');
    $id = (int)post('id');
    q('UPDATE web_accounts SET discount_pct = ?, is_active = ? WHERE id = ?',
      [max(0, min(90, (float)post('discount_pct'))), post('is_active') ? 1 : 0, $id]);
    if (post('new_password') !== '') {
        q('UPDATE web_accounts SET password_hash = ? WHERE id = ?', [password_hash(post('new_password'), PASSWORD_DEFAULT), $id]);
        $acc = row('SELECT * FROM web_accounts WHERE id = ?', [$id]);
        if (post('send_wa') && $acc) {
            send_whatsapp($acc['mobile'], "🔑 *" . setting('app_name', 'AK Computer') . "*\n\nતમારો નવો પાસવર્ડ: " . post('new_password') . "\n" . base_url('catalog.php?dlogin=1'));
        }
        flash('Account updated, password changed.');
    } else {
        flash('Account updated.');
    }
    log_activity('web_account_edit', "#$id");
    redirect('web_customers.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'delete') {
    require_perm('webcustomers.edit');
    q('DELETE FROM web_accounts WHERE id = ?', [(int)post('id')]);
    log_activity('web_account_delete', '#' . (int)post('id'));
    flash('Dealer account deleted.');
    redirect('web_customers.php');
}

$accounts = all('SELECT wa.*, p.name party_name,
                 (SELECT COUNT(*) FROM web_orders wo WHERE wo.web_account_id = wa.id) orders,
                 (SELECT COALESCE(SUM(wo.total),0) FROM web_orders wo WHERE wo.web_account_id = wa.id) order_total
                 FROM web_accounts wa LEFT JOIN parties p ON p.id = wa.party_id ORDER BY wa.name');
$parties = all("SELECT id, name FROM parties WHERE is_active = 1 ORDER BY name");
$page_title = 'Dealer Website Logins (B2B)';
include __DIR__ . '/includes/header.php';
?>
<div class="card">
  <h3>How it works</h3>
  <p class="muted">Give an electrician/dealer a login here. The website shows everyone the normal price — but when they log in at <strong><?= e(base_url('catalog.php?dlogin=1')) ?></strong>, every product automatically shows THEIR price (normal price minus their discount %), and their orders are saved with those prices.</p>
</div>

<?php if (can('webcustomers.edit')): ?>
<div class="card">
  <h3>+ New dealer login</h3>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="add">
    <div class="form-row cols-4">
      <div><label>Name *</label><input type="text" name="name" required placeholder="e.g. Ramesh Electricals, Dwarka"></div>
      <div><label>Mobile (their login ID) *</label><input type="tel" name="mobile" required pattern="[0-9]{10,12}"></div>
      <div><label>Discount %</label><input type="number" step="any" min="0" max="90" name="discount_pct" value="5"></div>
      <div><label>Password <span class="muted" style="font-weight:normal">(blank = auto-generate)</span></label><input type="text" name="password" placeholder="auto"></div>
    </div>
    <div class="form-row cols-2">
      <div><label>Link to party ledger (optional)</label>
        <select name="party_id"><option value="">-- none --</option>
        <?php foreach ($parties as $p): ?><option value="<?= $p['id'] ?>"><?= e($p['name']) ?></option><?php endforeach; ?>
        </select></div>
      <label class="check-inline"><input type="checkbox" name="send_wa" value="1" checked> WhatsApp the login details to them</label>
    </div>
    <button class="btn" type="submit">Create Login</button>
  </form>
</div>
<?php endif; ?>

<div class="table-wrap">
<table>
  <thead><tr><th>Name</th><th>Mobile (login)</th><th class="num">Discount %</th><th class="num">Orders</th><th class="num">Order ₹</th><th>Last login</th><th>Status</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($accounts as $a): ?>
    <tr>
      <td><strong><?= e($a['name']) ?></strong><?= $a['party_name'] ? '<br><span class="muted">' . e($a['party_name']) . '</span>' : '' ?></td>
      <td><?= e($a['mobile']) ?></td>
      <td class="num"><?= 0 + $a['discount_pct'] ?>%</td>
      <td class="num"><?= (int)$a['orders'] ?></td>
      <td class="num">₹<?= money($a['order_total']) ?></td>
      <td><?= $a['last_login'] ? dmyt($a['last_login']) : '<span class="muted">never</span>' ?></td>
      <td><?= $a['is_active'] ? '<span class="badge badge-ok">active</span>' : '<span class="badge badge-bad">off</span>' ?></td>
      <td>
        <?php if (can('webcustomers.edit')): ?>
        <details>
          <summary class="btn btn-sm btn-outline" style="display:inline-block;cursor:pointer">Edit</summary>
          <form method="post" class="mt" style="min-width:230px">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="update"><input type="hidden" name="id" value="<?= $a['id'] ?>">
            <div class="field"><label>Discount %</label><input type="number" step="any" min="0" max="90" name="discount_pct" value="<?= 0 + $a['discount_pct'] ?>"></div>
            <div class="field"><label>New password (blank = keep)</label><input type="text" name="new_password"></div>
            <label class="check-inline"><input type="checkbox" name="is_active" value="1" <?= $a['is_active'] ? 'checked' : '' ?>> Active</label>
            <label class="check-inline"><input type="checkbox" name="send_wa" value="1"> WhatsApp new password</label>
            <div class="mt"><button class="btn btn-sm" type="submit">Save</button></div>
          </form>
          <form method="post" class="mt" onsubmit="return confirm('Delete this dealer login? Their past orders stay.')">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= $a['id'] ?>">
            <button class="btn btn-sm btn-danger" type="submit">✕ Delete login</button>
          </form>
        </details>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$accounts): ?><tr><td colspan="8" class="muted">No dealer logins yet — create the first one above.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
