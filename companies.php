<?php
// Companies / firms: GST + non-GST, separate invoice series & bill format
require_once __DIR__ . '/includes/init.php';
require_perm('companies.view');

$id = (int)get('id');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'save') {
    require_perm($id ? 'companies.edit' : 'companies.add');
    $logo = post('old_logo');
    if (!empty($_FILES['logo']['tmp_name'])) {
        $ext = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'svg'])) {
            if (!is_dir(__DIR__ . '/uploads')) mkdir(__DIR__ . '/uploads', 0755, true);
            $logo = 'uploads/logo_' . time() . '.' . $ext;
            move_uploaded_file($_FILES['logo']['tmp_name'], __DIR__ . '/' . $logo);
        }
    }
    $data = [post('name'), post('gstin'), post('is_gst') ? 1 : 0, post('address'), post('phone'),
             post('email'), strtoupper(post('invoice_prefix', 'INV')), post('terms'), $logo, post('is_active') ? 1 : 0];
    if ($id) {
        q('UPDATE companies SET name=?, gstin=?, is_gst=?, address=?, phone=?, email=?, invoice_prefix=?, terms=?, logo=?, is_active=? WHERE id=?',
          array_merge($data, [$id]));
        flash('Company updated.');
    } else {
        q('INSERT INTO companies (name, gstin, is_gst, address, phone, email, invoice_prefix, terms, logo, is_active) VALUES (?,?,?,?,?,?,?,?,?,?)', $data);
        flash('Company added.');
    }
    log_activity('company_save', post('name'));
    redirect('companies.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'delete') {
    require_perm('companies.delete');
    $cid = (int)post('id');
    $activeCount = (int)val('SELECT COUNT(*) FROM companies WHERE is_active = 1');
    if ($activeCount <= 1) {
        flash('Cannot deactivate the last active firm - at least one is needed to create bills.', 'error');
        redirect('companies.php');
    }
    // sales/purchases already reference this firm - deactivate (not hard
    // delete) so old invoices keep showing the correct firm name.
    q('UPDATE companies SET is_active = 0 WHERE id = ?', [$cid]);
    log_activity('company_delete', "#$cid");
    flash('Firm deactivated.');
    redirect('companies.php');
}

$co = $id ? row('SELECT * FROM companies WHERE id = ?', [$id]) : null;
$cos = all('SELECT * FROM companies ORDER BY id');
$page_title = 'Companies / Firms';
include __DIR__ . '/includes/header.php';
?>
<div class="card">
  <h2><?= $co ? 'Edit Firm' : 'Add Firm' ?></h2>
  <p class="muted mb">You can keep two firms — one with GST, one without. The firm is selected while creating a bill and each runs its own invoice series.</p>
  <form method="post" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="save">
    <input type="hidden" name="old_logo" value="<?= e($co['logo'] ?? '') ?>">
    <div class="form-row cols-3">
      <div><label>Firm name *</label><input type="text" name="name" value="<?= e($co['name'] ?? '') ?>" required></div>
      <div><label>GSTIN</label><input type="text" name="gstin" value="<?= e($co['gstin'] ?? '') ?>"></div>
      <div><label>Invoice prefix</label><input type="text" name="invoice_prefix" value="<?= e($co['invoice_prefix'] ?? 'INV') ?>" maxlength="10"></div>
    </div>
    <div class="form-row cols-3">
      <div><label>Address</label><input type="text" name="address" value="<?= e($co['address'] ?? '') ?>"></div>
      <div><label>Phone</label><input type="tel" name="phone" value="<?= e($co['phone'] ?? '') ?>"></div>
      <div><label>Email</label><input type="email" name="email" value="<?= e($co['email'] ?? '') ?>"></div>
    </div>
    <div class="field"><label>Invoice footer terms</label><textarea name="terms" rows="2" placeholder="Goods once sold..."><?= e($co['terms'] ?? '') ?></textarea></div>
    <div class="form-row cols-2">
      <div><label>Logo (shown on invoice)</label><input type="file" name="logo" accept="image/*"></div>
      <?php if (!empty($co['logo'])): ?><div><img src="<?= e($co['logo']) ?>" alt="logo" style="max-height:60px"></div><?php endif; ?>
    </div>
    <div class="form-row cols-2">
      <label class="check-inline"><input type="checkbox" name="is_gst" value="1" <?= !empty($co['is_gst']) ? 'checked' : '' ?>> GST billing (tax invoice)</label>
      <label class="check-inline"><input type="checkbox" name="is_active" value="1" <?= ($co === null || $co['is_active']) ? 'checked' : '' ?>> Active</label>
    </div>
    <button class="btn" type="submit">Save</button>
    <?php if ($co): ?><a class="btn btn-muted" href="companies.php">Cancel edit</a><?php endif; ?>
  </form>
</div>
<div class="table-wrap">
<table>
  <thead><tr><th>Firm</th><th>GSTIN</th><th>Type</th><th>Prefix</th><th></th></tr></thead>
  <tbody><?php foreach ($cos as $c): ?>
    <tr>
      <td><strong><?= e($c['name']) ?></strong></td>
      <td><?= e($c['gstin'] ?: '-') ?></td>
      <td><?= $c['is_gst'] ? '<span class="badge badge-ok">GST</span>' : '<span class="badge badge-info">Non-GST</span>' ?></td>
      <td><?= e($c['invoice_prefix']) ?></td>
      <td style="white-space:nowrap">
        <?php if (can('companies.edit')): ?><a class="btn btn-sm btn-outline" href="companies.php?id=<?= $c['id'] ?>">Edit</a><?php endif; ?>
        <?php if (can('companies.delete') && $c['is_active']): ?>
        <form method="post" style="display:inline" onsubmit="return confirm('Deactivate this firm?')">
          <?= csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= $c['id'] ?>">
          <button class="btn btn-sm btn-danger" type="submit">✕</button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?></tbody>
</table>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
