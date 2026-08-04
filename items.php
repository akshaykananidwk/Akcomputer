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
            (float)post('b2b_price'), post('serial_tracked') ? 1 : 0, (float)post('margin_pct'),
            post('item_type') === 'service' ? 'service' : 'product', (int)post('warranty_months'),
            (float)post('min_stock'), post('show_on_website') ? 1 : 0, $photo, post('barcode'),
            post('is_active') ? 1 : 0, post('description'),
        ];
        if ($id) {
            q('UPDATE items SET name=?, category_id=?, brand=?, model=?, unit=?, hsn=?, tax_rate=?, purchase_price=?,
               selling_price=?, b2b_price=?, serial_tracked=?, margin_pct=?, item_type=?, warranty_months=?, min_stock=?, show_on_website=?,
               photo=?, barcode=?, is_active=?, description=? WHERE id=?', array_merge($data, [$id]));
            flash('Item updated.');
        } else {
            q('INSERT INTO items (name, category_id, brand, model, unit, hsn, tax_rate, purchase_price, selling_price,
               b2b_price, serial_tracked, margin_pct, item_type, warranty_months, min_stock, show_on_website, photo, barcode, is_active, description)
               VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', $data);
            $id = insert_id();
            flash('Item added.');
        }
        // minimum-profit rule: selling never sits below purchase + margin
        // (item's own margin %, else the default % from Settings)
        $sellBefore = (float)val('SELECT selling_price FROM items WHERE id = ?', [$id]);
        enforce_min_margin($id);
        $sellAfter = (float)val('SELECT selling_price FROM items WHERE id = ?', [$id]);
        if ($sellAfter > $sellBefore + 0.005) {
            flash('વેચાણ-ભાવ ₹' . money($sellBefore) . ' થી વધારીને ₹' . money($sellAfter) . ' કર્યો — ખરીદ + ' . (0 + ((float)post('margin_pct') > 0 ? (float)post('margin_pct') : default_margin_pct())) . '% નો મિનિમમ નફો જળવાય એ માટે.', 'success');
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
        $iid = (int)post('id');
        // An item that lives on real bills is NOT silently erased: the owner
        // gets told exactly where it is linked and the item is deactivated
        // instead (history intact, item hidden everywhere). Only an unused
        // item deletes for real.
        $inSales = (int)val('SELECT COUNT(DISTINCT sale_id) FROM sale_items WHERE item_id = ?', [$iid]);
        $inPurch = (int)val('SELECT COUNT(DISTINCT purchase_id) FROM purchase_items WHERE item_id = ?', [$iid]);
        if ($inSales || $inPurch) {
            q('UPDATE items SET is_active = 0, show_on_website = 0 WHERE id = ?', [$iid]);
            flash("આ આઇટમ $inSales સેલ બિલ અને $inPurch પરચેસ બિલમાં લિંક છે — ડિલીટ કરવાને બદલે INACTIVE કરી છે (જૂનાં બિલ સલામત). પાછી જોઈએ તો Show Inactive માંથી Edit કરી Active કરો.", 'error');
            log_activity('item_delete_blocked', "#$iid sales=$inSales purch=$inPurch -> deactivated");
        } else {
            q('DELETE FROM items WHERE id = ?', [$iid]);
            flash('Item deleted.');
            log_activity('item_delete', "#$iid");
        }
        redirect('items.php' . (get('show') === 'all' ? '?show=all' : ''));
    }
    if (post('do') === 'toggle_web') {
        require_perm('items.edit');
        q('UPDATE items SET show_on_website = 1 - show_on_website WHERE id = ?', [(int)post('id')]);
        $on = val('SELECT show_on_website FROM items WHERE id = ?', [(int)post('id')]);
        flash($on ? 'Item will now show on the website ✔' : 'Item removed from the website.');
        redirect('items.php');
    }
}

// categories with their parent name, so dropdowns read "CCTV › IP Camera"
// (pre-v47 DBs have no parent_id column - fall back to the flat list)
try {
    $cats = all('SELECT c.*, p.name parent_name FROM categories c LEFT JOIN categories p ON p.id = c.parent_id
                 ORDER BY COALESCE(p.name, c.name), (c.parent_id IS NOT NULL), c.name');
} catch (Exception $e) {
    $cats = all('SELECT *, NULL parent_name FROM categories ORDER BY name');
}
foreach ($cats as &$c) $c['label'] = ($c['parent_name'] ?? '') !== '' && $c['parent_name'] !== null ? $c['parent_name'] . ' › ' . $c['name'] : $c['name'];
unset($c);

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
          <div><label>Item name *</label><input type="text" name="name" value="<?= e($it['name'] ?? get('model', '')) ?>" required></div>
          <div><label>Category</label>
            <select name="category_id"><option value="">-- none --</option>
            <?php foreach ($cats as $c): ?><option value="<?= $c['id'] ?>" <?= ($it['category_id'] ?? '') == $c['id'] ? 'selected' : '' ?>><?= e($c['label']) ?></option><?php endforeach; ?>
            </select></div>
        </div>
        <div class="form-row cols-3">
          <div><label>Brand</label><input type="text" name="brand" value="<?= e($it['brand'] ?? '') ?>"></div>
          <div><label>Model</label><input type="text" name="model" value="<?= e($it['model'] ?? get('model', '')) ?>"></div>
          <div><label>Unit</label><input type="text" name="unit" value="<?= e($it['unit'] ?? 'PCS') ?>"></div>
        </div>
        <div class="form-row cols-3">
          <div><label>Type</label>
            <select name="item_type" onchange="document.getElementById('marginBox').style.display=this.value==='service'?'none':''">
              <option value="product" <?= ($it['item_type'] ?? 'product') === 'product' ? 'selected' : '' ?>>Product</option>
              <option value="service" <?= ($it['item_type'] ?? '') === 'service' ? 'selected' : '' ?>>Service (no stock)</option>
            </select></div>
          <?php if (can('items.cost')): ?><div><label>Purchase Price</label><input type="number" step="any" name="purchase_price" id="f_pp" value="<?= e($it['purchase_price'] ?? '0') ?>" oninput="mCalc()"></div><?php else: ?><input type="hidden" name="purchase_price" value="<?= e($it['purchase_price'] ?? '0') ?>"><?php endif; ?>
          <div id="marginBox"><label>Margin % (fill this and Selling auto-calculates)</label><input type="number" step="any" name="margin_pct" id="f_mg" value="<?= e($it['margin_pct'] ?? '0') ?>" oninput="mCalc()"></div>
          <div><label>Selling Price (Retail)</label><input type="number" step="any" name="selling_price" id="f_sp" value="<?= e($it['selling_price'] ?? '0') ?>"></div>
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
        <div class="field"><label>Description (shows on the website; AI Auto-Fill can write this)</label>
          <textarea name="description" rows="3"><?= e($it['description'] ?? '') ?></textarea></div>
        <div class="form-row cols-3">
          <label class="check-inline"><input type="checkbox" name="serial_tracked" value="1" <?= !empty($it['serial_tracked']) ? 'checked' : '' ?>> Serial number tracked</label>
          <label class="check-inline"><input type="checkbox" name="show_on_website" value="1" <?= !empty($it['show_on_website']) ? 'checked' : '' ?>> Show on website</label>
          <label class="check-inline"><input type="checkbox" name="is_active" value="1" <?= ($it === null || $it['is_active']) ? 'checked' : '' ?>> Active</label>
        </div>
        <script>function mCalc(){var pp=parseFloat(document.getElementById('f_pp').value)||0,mg=parseFloat(document.getElementById('f_mg').value)||0;if(mg>0)document.getElementById('f_sp').value=(pp*(1+mg/100)).toFixed(2);}</script>
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

// ---- list (with proper filters: category / stock status / website / location) ----
$showAll = get('show') === 'all';
$fCat = (int)get('f_cat');
$fStock = get('f_stock');   // '', 'in', 'zero', 'neg', 'low'
$fWeb = get('f_web');       // '', 'on', 'off'
$fLoc = (int)get('f_loc');  // 0 = all locations, else stock AT that godown/shop
if (locked_location_id()) $fLoc = locked_location_id(); // godown/shop manager: own place only
$locsAll = all('SELECT id, name FROM locations WHERE is_active = 1' . (locked_location_id() ? ' AND id = ' . locked_location_id() : '') . ' ORDER BY name');
$w = [];
if (!$showAll) $w[] = 'i.is_active = 1';
if ($fCat) $w[] = 'i.category_id = ' . $fCat;
if ($fWeb === 'on') $w[] = 'i.show_on_website = 1';
if ($fWeb === 'off') $w[] = 'i.show_on_website = 0';
// with a location picked, the Stock column and the stock filters both work
// on THAT location's quantity - "ગોડાઉનમાં શું પડ્યું છે" in one tap
$stockExpr = $fLoc ? 'COALESCE(SUM(CASE WHEN s.location_id = ' . $fLoc . ' THEN s.qty END),0)' : 'COALESCE(SUM(s.qty),0)';
$having = '';
if ($fStock === 'in') $having = 'HAVING total_stock > 0';
if ($fStock === 'zero') $having = 'HAVING total_stock = 0';
if ($fStock === 'neg') $having = 'HAVING total_stock < 0';
if ($fStock === 'low') $having = 'HAVING i.min_stock > 0 AND total_stock < i.min_stock';
$items = all('SELECT i.*, c.name AS cat_name, ' . $stockExpr . ' AS total_stock
              FROM items i
              LEFT JOIN categories c ON c.id = i.category_id
              LEFT JOIN stock s ON s.item_id = i.id
              ' . ($w ? 'WHERE ' . implode(' AND ', $w) : '') . '
              GROUP BY i.id ' . $having . ' ORDER BY i.name');
// per-location split shown under every stock figure (all-locations view)
$locSplit = [];
if (count($locsAll) > 1) {
    foreach (all('SELECT s.item_id, l.name loc, s.qty FROM stock s JOIN locations l ON l.id = s.location_id WHERE s.qty <> 0') as $ls) {
        $locSplit[$ls['item_id']][] = $ls['loc'] . ': ' . (float)$ls['qty'];
    }
}
$fLocName = $fLoc ? (string)val('SELECT name FROM locations WHERE id = ?', [$fLoc]) : '';
$page_title = 'Items';
include __DIR__ . '/includes/header.php';
?>
<div class="page-actions">
  <?php if (can('items.add')): ?><a class="btn" href="items.php?action=new">+ New Item</a><?php endif; ?>
  <?php if (can('items.edit')): ?><a class="btn btn-outline" href="ai_categorize.php">🤖 AI Categories</a><?php endif; ?>
  <a class="btn btn-outline" href="items.php<?= $showAll ? '' : '?show=all' ?>"><?= $showAll ? 'Show Active only' : 'Show Inactive too' ?></a>
</div>
<form method="get" class="filterbar no-print">
  <?php if ($showAll): ?><input type="hidden" name="show" value="all"><?php endif; ?>
  <div style="flex:1;min-width:170px"><input type="text" id="itemFilter" placeholder="🔍 Search items..."></div>
  <div><select name="f_cat" onchange="this.form.submit()">
    <option value="">All categories</option>
    <?php foreach ($cats as $c): ?><option value="<?= $c['id'] ?>" <?= $fCat == $c['id'] ? 'selected' : '' ?>><?= e($c['label']) ?></option><?php endforeach; ?>
  </select></div>
  <div><select name="f_stock" onchange="this.form.submit()">
    <option value="">All stock</option>
    <option value="in" <?= $fStock === 'in' ? 'selected' : '' ?>>In stock</option>
    <option value="zero" <?= $fStock === 'zero' ? 'selected' : '' ?>>Zero stock</option>
    <option value="neg" <?= $fStock === 'neg' ? 'selected' : '' ?>>Negative (minus)</option>
    <option value="low" <?= $fStock === 'low' ? 'selected' : '' ?>>Low stock</option>
  </select></div>
  <div><select name="f_loc" onchange="this.form.submit()">
    <option value="">📍 All locations</option>
    <?php foreach ($locsAll as $l): ?><option value="<?= $l['id'] ?>" <?= $fLoc == $l['id'] ? 'selected' : '' ?>><?= e($l['name']) ?></option><?php endforeach; ?>
  </select></div>
  <div><select name="f_web" onchange="this.form.submit()">
    <option value="">Website: all</option>
    <option value="on" <?= $fWeb === 'on' ? 'selected' : '' ?>>Website ON</option>
    <option value="off" <?= $fWeb === 'off' ? 'selected' : '' ?>>Website OFF</option>
  </select></div>
</form>
<div class="list-count"><?= count($items) ?> items<?= $fLocName ? ' · 📍 Stock @ <strong>' . e($fLocName) . '</strong>' : '' ?></div>
<div class="table-wrap">
<table id="itemTable">
  <thead><tr><th>Item</th><th>Category</th><?php if (can('items.cost')): ?><th class="num">Purchase</th><?php endif; ?><th class="num">Retail</th><th class="num">B2B</th><th class="num">Stock</th><th>Flags</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($items as $it): ?>
    <tr>
      <td>
        <?php if ($it['photo']): ?><img src="<?= e($it['photo']) ?>" class="photo-thumb" alt=""> <?php endif; ?>
        <a href="item_view.php?id=<?= $it['id'] ?>"><strong><?= e($it['name']) ?></strong></a>
        <?php if (!$it['is_active']): ?> <span class="badge badge-bad">INACTIVE</span><?php endif; ?>
        <?php if ($it['brand'] || $it['model']): ?><br><span class="muted"><?= e(trim($it['brand'] . ' ' . $it['model'])) ?></span><?php endif; ?>
      </td>
      <td><?= e($it['cat_name'] ?? '-') ?></td>
      <?php if (can('items.cost')): ?><td class="num"><?= money($it['purchase_price']) ?></td><?php endif; ?>
      <td class="num"><?= money($it['selling_price']) ?></td>
      <td class="num"><?= money($it['b2b_price']) ?></td>
      <td class="num"><?php if ($it['item_type'] === 'service'): ?><span class="muted">-</span><?php else: ?>
        <?= (float)$it['total_stock'] ?> <?= e($it['unit']) ?>
        <?php if (!$fLoc && !empty($locSplit[$it['id']]) && count($locSplit[$it['id']]) > 0): ?>
        <br><small class="muted"><?= e(implode(' · ', $locSplit[$it['id']])) ?></small>
        <?php endif; ?>
      <?php endif; ?></td>
      <td>
        <?php if ($it['serial_tracked']): ?><span class="badge badge-info">SN</span><?php endif; ?>
        <?php if ($it['item_type'] !== 'service' && $it['min_stock'] > 0 && $it['total_stock'] < $it['min_stock']): ?><span class="badge badge-bad">LOW</span><?php endif; ?>
      </td>
      <td style="white-space:nowrap">
        <?php if (can('items.edit')): ?>
        <form method="post" style="display:inline"><?= csrf_field() ?>
          <input type="hidden" name="do" value="toggle_web"><input type="hidden" name="id" value="<?= $it['id'] ?>">
          <button class="btn btn-sm <?= $it['show_on_website'] ? 'btn-success' : 'btn-muted' ?>" type="submit" title="Toggle on the website">🌐 <?= $it['show_on_website'] ? 'ON' : 'OFF' ?></button>
        </form>
        <a class="btn btn-sm btn-outline" href="items.php?action=edit&id=<?= $it['id'] ?>">Edit</a>
        <?php elseif ($it['show_on_website']): ?><span class="badge badge-ok">WEB</span><?php endif; ?>
        <?php if (can('items.delete')): ?>
        <form method="post" style="display:inline" onsubmit="return confirm('Delete this item? If it has transaction history, it will just be made inactive.')">
          <?= csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= $it['id'] ?>">
          <button class="btn btn-sm btn-danger" type="submit">✕</button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<script>tableFilter('itemFilter', 'itemTable');</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
