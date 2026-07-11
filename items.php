<?php
// Items master (3 prices, serial tracking, warranty months, website flag, photo)
require_once __DIR__ . '/includes/init.php';
require_perm('items.view');

$action = get('action', 'list');
$id = (int)get('id');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (post('do') === 'save') {
        require_perm($id ? 'items.edit' : 'items.add');
        $photo = post('old_photo');
        if (!empty($_FILES['photo']['tmp_name'])) {
            $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
                if (!is_dir(__DIR__ . '/uploads')) mkdir(__DIR__ . '/uploads', 0755, true);
                $photo = 'uploads/item_' . time() . '_' . rand(100, 999) . '.' . $ext;
                move_uploaded_file($_FILES['photo']['tmp_name'], __DIR__ . '/' . $photo);
            }
        }
        $data = [
            post('name'), (int)post('category_id') ?: null, post('brand'), post('model'), post('unit', 'PCS'),
            post('hsn'), (float)post('tax_rate'), (float)post('purchase_price'), (float)post('selling_price'),
            (float)post('b2b_price'), post('serial_tracked') ? 1 : 0, (int)post('warranty_months'),
            (float)post('min_stock'), post('show_on_website') ? 1 : 0, $photo, post('barcode'),
            post('is_active') ? 1 : 0,
        ];
        if ($id) {
            q('UPDATE items SET name=?, category_id=?, brand=?, model=?, unit=?, hsn=?, tax_rate=?, purchase_price=?,
               selling_price=?, b2b_price=?, serial_tracked=?, warranty_months=?, min_stock=?, show_on_website=?,
               photo=?, barcode=?, is_active=? WHERE id=?', array_merge($data, [$id]));
            flash('Item updated.');
        } else {
            q('INSERT INTO items (name, category_id, brand, model, unit, hsn, tax_rate, purchase_price, selling_price,
               b2b_price, serial_tracked, warranty_months, min_stock, show_on_website, photo, barcode, is_active)
               VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', $data);
            flash('Item added.');
        }
        log_activity('item_save', post('name'));
        redirect('items.php');
    }
    if (post('do') === 'cat_add' && can('items.add')) {
        q('INSERT INTO categories (name) VALUES (?)', [post('cat_name')]);
        flash('Category added.');
        redirect('items.php?action=' . e(post('back', 'list')));
    }
    if (post('do') === 'delete') {
        require_perm('items.delete');
        q('UPDATE items SET is_active = 0 WHERE id = ?', [(int)post('id')]);
        flash('Item deactivated.');
        redirect('items.php');
    }
    if (post('do') === 'toggle_web') {
        require_perm('items.edit');
        q('UPDATE items SET show_on_website = 1 - show_on_website WHERE id = ?', [(int)post('id')]);
        $on = val('SELECT show_on_website FROM items WHERE id = ?', [(int)post('id')]);
        flash($on ? 'Item હવે website પર દેખાશે ✔' : 'Item website પરથી હટાવી.');
        redirect('items.php');
    }
}

$cats = all('SELECT * FROM categories ORDER BY name');

if ($action === 'new' || $action === 'edit') {
    require_perm($action === 'new' ? 'items.add' : 'items.edit');
    $it = $id ? row('SELECT * FROM items WHERE id = ?', [$id]) : null;
    $page_title = $it ? 'Edit Item' : 'New Item';
    include __DIR__ . '/includes/header.php';
    ?>
    <div class="card">
      <h2><?= $page_title ?></h2>
      <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="save">
        <input type="hidden" name="old_photo" value="<?= e($it['photo'] ?? '') ?>">
        <div class="form-row cols-2">
          <div><label>Item name *</label><input type="text" name="name" value="<?= e($it['name'] ?? '') ?>" required></div>
          <div><label>Category</label>
            <select name="category_id"><option value="">-- none --</option>
            <?php foreach ($cats as $c): ?><option value="<?= $c['id'] ?>" <?= ($it['category_id'] ?? '') == $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
            </select></div>
        </div>
        <div class="form-row cols-3">
          <div><label>Brand</label><input type="text" name="brand" value="<?= e($it['brand'] ?? '') ?>"></div>
          <div><label>Model</label><input type="text" name="model" value="<?= e($it['model'] ?? '') ?>"></div>
          <div><label>Unit</label><input type="text" name="unit" value="<?= e($it['unit'] ?? 'PCS') ?>"></div>
        </div>
        <div class="form-row cols-3">
          <div><label>Purchase Price</label><input type="number" step="any" name="purchase_price" value="<?= e($it['purchase_price'] ?? '0') ?>"></div>
          <div><label>Selling Price (Retail)</label><input type="number" step="any" name="selling_price" value="<?= e($it['selling_price'] ?? '0') ?>"></div>
          <div><label>B2B Price</label><input type="number" step="any" name="b2b_price" value="<?= e($it['b2b_price'] ?? '0') ?>"></div>
        </div>
        <div class="form-row cols-4">
          <div><label>HSN Code</label><input type="text" name="hsn" value="<?= e($it['hsn'] ?? '') ?>"></div>
          <div><label>GST %</label><input type="number" step="any" name="tax_rate" value="<?= e($it['tax_rate'] ?? setting('default_tax', '18')) ?>"></div>
          <div><label>Warranty (months)</label><input type="number" name="warranty_months" value="<?= e($it['warranty_months'] ?? '0') ?>"></div>
          <div><label>Min stock alert</label><input type="number" step="any" name="min_stock" value="<?= e($it['min_stock'] ?? '0') ?>"></div>
        </div>
        <div class="form-row cols-2">
          <div><label>Barcode</label><input type="text" name="barcode" value="<?= e($it['barcode'] ?? '') ?>"></div>
          <div><label>Photo (for website)</label><input type="file" name="photo" accept="image/*"></div>
        </div>
        <div class="form-row cols-3">
          <label class="check-inline"><input type="checkbox" name="serial_tracked" value="1" <?= !empty($it['serial_tracked']) ? 'checked' : '' ?>> Serial number tracked</label>
          <label class="check-inline"><input type="checkbox" name="show_on_website" value="1" <?= !empty($it['show_on_website']) ? 'checked' : '' ?>> Show on website</label>
          <label class="check-inline"><input type="checkbox" name="is_active" value="1" <?= ($it === null || $it['is_active']) ? 'checked' : '' ?>> Active</label>
        </div>
        <button class="btn" type="submit">Save Item</button>
        <a class="btn btn-muted" href="items.php">Cancel</a>
      </form>
    </div>
    <div class="card">
      <h3>Quick add category</h3>
      <form method="post" class="filterbar">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="cat_add">
        <input type="hidden" name="back" value="<?= e($action) ?>">
        <div><input type="text" name="cat_name" placeholder="Category name" required></div>
        <button class="btn btn-sm" type="submit">Add</button>
      </form>
    </div>
    <?php
    include __DIR__ . '/includes/footer.php';
    exit;
}

// ---- list ----
$items = all('SELECT i.*, c.name AS cat_name, COALESCE(SUM(s.qty),0) AS total_stock
              FROM items i
              LEFT JOIN categories c ON c.id = i.category_id
              LEFT JOIN stock s ON s.item_id = i.id
              WHERE i.is_active = 1
              GROUP BY i.id ORDER BY i.name');
$page_title = 'Items';
include __DIR__ . '/includes/header.php';
?>
<div class="page-actions">
  <?php if (can('items.add')): ?><a class="btn" href="items.php?action=new">+ New Item</a><?php endif; ?>
</div>
<div class="searchbox"><input type="text" id="itemFilter" placeholder="🔍 Search items..."></div>
<div class="list-count"><?= count($items) ?> items</div>
<div class="table-wrap">
<table id="itemTable">
  <thead><tr><th>Item</th><th>Category</th><th class="num">Purchase</th><th class="num">Retail</th><th class="num">B2B</th><th class="num">Stock</th><th>Flags</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($items as $it): ?>
    <tr>
      <td>
        <?php if ($it['photo']): ?><img src="<?= e($it['photo']) ?>" class="photo-thumb" alt=""> <?php endif; ?>
        <strong><?= e($it['name']) ?></strong>
        <?php if ($it['brand'] || $it['model']): ?><br><span class="muted"><?= e(trim($it['brand'] . ' ' . $it['model'])) ?></span><?php endif; ?>
      </td>
      <td><?= e($it['cat_name'] ?? '-') ?></td>
      <td class="num"><?= money($it['purchase_price']) ?></td>
      <td class="num"><?= money($it['selling_price']) ?></td>
      <td class="num"><?= money($it['b2b_price']) ?></td>
      <td class="num"><?= (float)$it['total_stock'] ?> <?= e($it['unit']) ?></td>
      <td>
        <?php if ($it['serial_tracked']): ?><span class="badge badge-info">SN</span><?php endif; ?>
        <?php if ($it['min_stock'] > 0 && $it['total_stock'] < $it['min_stock']): ?><span class="badge badge-bad">LOW</span><?php endif; ?>
      </td>
      <td style="white-space:nowrap">
        <?php if (can('items.edit')): ?>
        <form method="post" style="display:inline"><?= csrf_field() ?>
          <input type="hidden" name="do" value="toggle_web"><input type="hidden" name="id" value="<?= $it['id'] ?>">
          <button class="btn btn-sm <?= $it['show_on_website'] ? 'btn-success' : 'btn-muted' ?>" type="submit" title="Website પર બતાવવું on/off">🌐 <?= $it['show_on_website'] ? 'ON' : 'OFF' ?></button>
        </form>
        <a class="btn btn-sm btn-outline" href="items.php?action=edit&id=<?= $it['id'] ?>">Edit</a>
        <?php elseif ($it['show_on_website']): ?><span class="badge badge-ok">WEB</span><?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<script>tableFilter('itemFilter', 'itemTable');</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
