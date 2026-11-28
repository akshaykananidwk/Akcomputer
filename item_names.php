<?php
// Items -> Clear Product Names. Names a customer and the shop both
// understand, with the model number in brackets at the end. Shows every
// change first; Apply can be undone. Products still without a model number
// are listed with a box to type it in.
require_once __DIR__ . '/includes/init.php';
require_perm('items.edit');
require_once __DIR__ . '/includes/item_names.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (post('do') === 'apply') { $n = in_apply(); log_activity('item_names_apply', "$n names"); flash("$n product names made clearer. Not happy? Press Undo below."); }
    if (post('do') === 'undo') { $n = in_undo(); log_activity('item_names_undo', "$n names"); flash("$n names are back as they were."); }
    if (post('do') === 'model') {
        $saved = 0;
        foreach ((array)post('model', []) as $id => $m) if (trim((string)$m) !== '' && in_set_model((int)$id, $m)) $saved++;
        log_activity('item_names_model', "$saved model numbers");
        flash("$saved model number(s) saved and added to the names.");
    }
    redirect('item_names.php');
}
[$plan, $noModel] = in_plan();
$undo = in_undo_info();
$page_title = 'Clear Product Names';
include __DIR__ . '/includes/header.php';
?>
<div class="card">
  <h2>🏷️ Clear product names</h2>
  <p class="muted">Every name should tell the customer — and you — exactly what the product is, and end with its model number in brackets:
    <strong>Brand · Product · key specs (MODEL)</strong>. Spelling is fixed (Cabel → Cable), each brand is written one way (CP-Plus / CP PLUS → CP PLUS),
    names in CAPITALS are made readable, short names are written out, and a model code already in the name is put in brackets at the end.
    Prices, stock and bills are not touched — an old bill simply shows the clearer name when you open it again.</p>
  <div class="grid-stats" style="margin:12px 0">
    <div class="stat"><div class="stat-label">Names to improve</div><div class="stat-value"><?= count($plan) ?></div></div>
    <div class="stat s-warn"><div class="stat-label">Model number missing</div><div class="stat-value"><?= count($noModel) ?></div></div>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <?php if ($plan): ?><form method="post" onsubmit="return confirm('Change <?= count($plan) ?> product names? You can Undo afterwards.')"><?= csrf_field() ?><input type="hidden" name="do" value="apply"><button class="btn" type="submit">✅ Apply — <?= count($plan) ?> names</button></form><?php endif; ?>
    <?php if ($undo): ?><form method="post" onsubmit="return confirm('Put the names back as they were on <?= e(dmyt($undo['at'])) ?>?')"><?= csrf_field() ?><input type="hidden" name="do" value="undo"><button class="btn btn-outline" type="submit">↩ Undo (<?= (int)$undo['n'] ?> names, <?= e(dmyt($undo['at'])) ?>)</button></form><?php endif; ?>
    <a class="btn btn-outline" href="products_content.php">📝 Descriptions &amp; SEO</a>
  </div>
</div>
<?php if ($plan): ?>
<div class="card"><h3 style="margin-top:0">What changes</h3>
  <div class="table-wrap" style="box-shadow:none"><table class="table-sm"><thead><tr><th>Now</th><th>Will be</th></tr></thead><tbody>
  <?php foreach ($plan as $p): ?><tr><td class="muted"><?= e($p['old']) ?><?= $p['old_brand'] !== $p['brand'] ? '<br><small>brand: ' . e($p['old_brand']) . '</small>' : '' ?></td><td><strong><?= e($p['new']) ?></strong><?= $p['old_brand'] !== $p['brand'] ? '<br><small>brand: ' . e($p['brand']) . '</small>' : '' ?></td></tr><?php endforeach; ?>
  </tbody></table></div></div>
<?php endif; ?>
<?php if ($noModel): ?>
<form class="card" method="post"><?= csrf_field() ?><input type="hidden" name="do" value="model">
  <h3 style="margin-top:0">Model number missing (<?= count($noModel) ?>)</h3>
  <p class="muted">The model number is not in these names. Type it from the box or the bill — it is saved on the item and added in brackets at the end of the name. Leave a box empty to skip it.</p>
  <div class="table-wrap" style="box-shadow:none"><table class="table-sm"><tbody>
  <?php foreach ($noModel as $m): ?><tr><td><?= e($m['name']) ?></td><td style="width:180px"><input type="text" name="model[<?= (int)$m['id'] ?>]" value="<?= e($m['model']) ?>" placeholder="Model no." style="width:100%"></td></tr><?php endforeach; ?>
  </tbody></table></div>
  <button class="btn mt" type="submit">💾 Save model numbers</button>
</form>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
