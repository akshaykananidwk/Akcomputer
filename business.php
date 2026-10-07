<?php
// Settings → Type of business. Picking a type switches on what that kind of
// shop needs (IMEI, expiry, weight, tables...); each switch can then be
// turned on or off by hand. Nothing here changes a bill already made.
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/business_packs.php';
require_perm('settings.edit');

$flags = [
    'biz_expiry'       => ['💊', 'Batch & expiry dates', 'Warns on the dashboard about medicines that are expiring'],
    'biz_prescription' => ['📄', 'Prescription with the bill', 'Attach the doctor\'s slip photo to a bill'],
    'biz_weight'       => ['⚖️', 'Sell by weight', 'Quick 250 g / 500 g / 1 kg buttons on the bill'],
    'biz_measure'      => ['📐', 'Length × width', 'Glass, tiles, flex: type the size, the area becomes the quantity'],
    'biz_shade'        => ['🎨', 'Shade / colour code', 'Paint shops: the shade is written on the bill line'],
    'biz_variants'     => ['👕', 'Sizes & colours', 'Make S/M/L × Red/Blue items in one go'],
    'biz_box_units'    => ['📦', 'Boxes', 'Sell by box; the pieces are counted for you'],
    'biz_slabs'        => ['🏷️', 'Price breaks', 'A lower price from a quantity up (10+ at ₹95)'],
    'biz_jewellery'    => ['💍', 'Jewellery', 'Weight × today\'s gold rate + making charge'],
    'biz_appointments' => ['📅', 'Appointments & staff', 'Bookings, who did each service, prepaid packages'],
    'biz_tables'       => ['🍽️', 'Restaurant tables', 'Orders per table, kitchen slip (KOT), split the bill'],
    'biz_no_stock'     => ['🧰', 'Services only (no stock)', 'Hides stock screens — for service businesses'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (post('do') === 'type' && isset(business_packs()[post('type')])) {
        foreach (array_keys($flags) as $k) set_setting($k, '0');
        set_setting('biz_serial_label', '');
        business_pack_apply(db(), post('type'), false);   // its switches and categories, not the sample items
        log_activity('business_type', post('type'));
        flash('Now set up as ' . business_label(post('type')) . '.');
    } elseif (post('do') === 'flags') {
        foreach (array_keys($flags) as $k) set_setting($k, post($k) ? '1' : '0');
        set_setting('biz_serial_label', post('serial_label') === 'IMEI' ? 'IMEI' : 'Serial No');
        set_setting('biz_table_count', (string)max(1, min(100, (int)post('table_count') ?: 10)));
        foreach (['gold_rate_22k', 'gold_rate_24k', 'gold_rate_18k', 'silver_rate'] as $k) set_setting($k, (string)max(0, round((float)post($k), 2)));
        log_activity('business_flags', implode(',', array_keys(array_filter($flags, fn($v, $k) => post($k), ARRAY_FILTER_USE_BOTH))));
        flash('Saved.');
    }
    redirect('business.php');
}

$type = biz_type();
$page_title = 'Type of business';
include __DIR__ . '/includes/header.php';
?>
<a class="settings-back" href="settings.php">← Settings</a>
<div class="page-head"><h1>🏷️ Type of business</h1><span class="muted">Now: <?= e(business_label($type)) ?></span></div>

<div class="card">
  <p style="margin-top:0">Pick what your shop is — the right options switch on by themselves.</p>
  <form method="post" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:8px">
    <?= csrf_field() ?><input type="hidden" name="do" value="type">
    <?php foreach (business_packs() as $k => $p): ?>
      <button class="btn <?= $k === $type ? '' : 'btn-outline' ?>" type="submit" name="type" value="<?= e($k) ?>"
        onclick="return <?= $k === $type ? 'true' : "confirm('Switch to " . e($p[1]) . "? Your items and bills stay as they are.')" ?>"><?= $p[0] ?> <?= e($p[1]) ?></button>
    <?php endforeach; ?>
  </form>
</div>

<form method="post" class="card">
  <?= csrf_field() ?><input type="hidden" name="do" value="flags">
  <h3 style="margin-top:0">Options</h3>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:10px">
  <?php foreach ($flags as $k => [$ico, $label, $hint]): ?>
    <label class="check-inline" style="align-items:flex-start;gap:8px"><input type="checkbox" name="<?= $k ?>" value="1" <?= biz_on($k) ? 'checked' : '' ?>>
      <span><?= $ico ?> <b><?= e($label) ?></b><br><small class="muted"><?= e($hint) ?></small></span></label>
  <?php endforeach; ?>
  </div>
  <div class="form-row cols-3 mt">
    <div><label>Unique number of a unit is called</label><select name="serial_label">
      <option value="Serial No">Serial No</option><option value="IMEI" <?= biz_serial_label() === 'IMEI' ? 'selected' : '' ?>>IMEI (mobiles — checked for mistakes)</option></select></div>
    <div><label>Number of tables</label><input type="number" name="table_count" min="1" max="100" value="<?= (int)setting('biz_table_count', '10') ?>"></div>
  </div>
  <h3>💍 Today's rates (per gram)</h3>
  <div class="form-row cols-4">
    <?php foreach (['gold_rate_22k' => 'Gold 22K', 'gold_rate_24k' => 'Gold 24K', 'gold_rate_18k' => 'Gold 18K', 'silver_rate' => 'Silver'] as $k => $l): ?>
      <div><label><?= $l ?> ₹</label><input type="number" step="0.01" min="0" name="<?= $k ?>" value="<?= e(setting($k, '0')) ?>"></div>
    <?php endforeach; ?>
  </div>
  <button class="btn" type="submit">💾 Save</button>
</form>
<?php include __DIR__ . '/includes/footer.php'; ?>
