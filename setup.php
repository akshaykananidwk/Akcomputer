<?php
// The first day: four short steps so a new shop's first bill already looks
// like theirs - name and address, GST and UPI, their items, their staff.
// Every step can be skipped; each one only fills things the shop could also
// set later in Settings, Companies, Bank accounts, Items and Staff.
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/business_packs.php';
require_perm('settings.edit');
$step = max(1, min(5, (int)get('step', 1)));
$co = row('SELECT * FROM companies WHERE is_active = 1 ORDER BY is_gst, id LIMIT 1');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = post('do');
    if ($do === 'shop') {
        $name = trim((string)post('name'));
        if ($name !== '') {
            set_setting('app_name', $name);
            // every firm keeps its "(GST)" tail, only the shop's name changes
            $cos = all('SELECT id, is_gst FROM companies WHERE is_active = 1');
            foreach ($cos as $c) q('UPDATE companies SET name = ? WHERE id = ?', [$name . ($c['is_gst'] && count($cos) > 1 ? ' (GST)' : ''), $c['id']]);
        }
        q('UPDATE companies SET address = ?, phone = ? WHERE is_active = 1', [mb_substr(trim((string)post('address')), 0, 255), mb_substr(trim((string)post('phone')), 0, 30)]);
        if (($city = trim((string)post('city'))) !== '') { set_setting('shop_city', $city); q('UPDATE locations SET city = ? WHERE id = (SELECT id FROM (SELECT MIN(id) id FROM locations) x)', [$city]); }
        set_setting('shop_trade', mb_substr(trim((string)post('trade')), 0, 200));
        if (!empty($_FILES['logo']['tmp_name']) && in_array(strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'webp'], true)
            && $_FILES['logo']['size'] <= 2 * 1024 * 1024) {
            up_dir();
            $logo = up_rel() . '/logo_' . time() . '.' . strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
            if (move_uploaded_file($_FILES['logo']['tmp_name'], __DIR__ . '/' . $logo)) q('UPDATE companies SET logo = ? WHERE is_active = 1', [$logo]);
        }
        log_activity('setup_shop', $name);
        redirect('setup.php?step=2');
    }
    if ($do === 'money') {
        $gstin = strtoupper(preg_replace('/\s+/', '', (string)post('gstin')));
        if ($gstin !== '') {
            if (!preg_match('/^\d{2}[A-Z]{5}\d{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/', $gstin)) { flash('That GSTIN does not look right — it has 15 letters and numbers, like 24ABCDE1234F1Z5.', 'error'); redirect('setup.php?step=2'); }
            q('UPDATE companies SET gstin = ? WHERE is_gst = 1', [$gstin]);
        }
        $upi = trim((string)post('upi_id'));
        if ($upi !== '') {
            if (!preg_match('/^[\w.\-]{2,}@[a-zA-Z]{2,}$/', $upi)) { flash('A UPI ID looks like name@okhdfcbank.', 'error'); redirect('setup.php?step=2'); }
            $bank = row('SELECT id FROM bank_accounts WHERE is_active = 1 ORDER BY is_default DESC, id LIMIT 1');
            if ($bank) q('UPDATE bank_accounts SET upi_id = ? WHERE id = ?', [$upi, $bank['id']]);
            else q("INSERT INTO bank_accounts (account_name, bank_name, account_number, ifsc, branch, upi_id, opening_balance, is_default, is_active) VALUES (?, 'UPI', '', '', '', ?, 0, 1, 1)", [setting('app_name', 'Shop'), $upi]);
        }
        log_activity('setup_money', $gstin . ' ' . $upi);
        redirect('setup.php?step=3');
    }
    if ($do === 'hide_samples') {
        // the sample items from sign-up, only those nothing was ever billed or bought on
        $names = array_column(business_packs()[biz_type()][4] ?? [], 0);
        $n = 0;
        foreach ($names as $nm) {
            $n += q('UPDATE items i SET is_active = 0 WHERE name = ? AND NOT EXISTS (SELECT 1 FROM sale_items WHERE item_id = i.id)
                     AND NOT EXISTS (SELECT 1 FROM purchase_items WHERE item_id = i.id)', [$nm])->rowCount();
        }
        flash("$n sample item" . ($n === 1 ? '' : 's') . ' hidden.');
        redirect('setup.php?step=3');
    }
    if ($do === 'done') {
        set_setting('setup_wizard_done', '1');
        log_activity('setup_done', '');
        flash('Your shop is ready. Make your first bill! 🎉');
        redirect('sales.php?action=new');
    }
    redirect('setup.php');
}

$steps = [1 => '🏪 Your shop', 2 => '💳 GST & UPI', 3 => '📦 Items', 4 => '👥 Staff', 5 => '✅ Done'];
$page_title = 'Set up your shop';
include __DIR__ . '/includes/header.php';
?>
<style>
.wz{max-width:640px;margin:0 auto}.wz-steps{display:flex;gap:4px;margin:0 0 14px;flex-wrap:wrap}
.wz-steps a{flex:1;min-width:90px;text-align:center;padding:8px 4px;border-radius:8px;font-size:13px;text-decoration:none;background:var(--bg-soft,#eef2f7);color:inherit}
.wz-steps a.on{background:var(--primary,#1d4ed8);color:#fff;font-weight:600}.wz-steps a.past{opacity:.75}
.wz .acts{display:flex;gap:8px;justify-content:space-between;margin-top:12px}
</style>
<div class="wz">
<h1 style="margin:6px 0 10px">Let's set up your shop</h1>
<nav class="wz-steps"><?php foreach ($steps as $n => $l): ?><a href="setup.php?step=<?= $n ?>" class="<?= $n === $step ? 'on' : ($n < $step ? 'past' : '') ?>"><?= $l ?></a><?php endforeach; ?></nav>

<?php if ($step === 1): ?>
<form method="post" enctype="multipart/form-data" class="card"><?= csrf_field() ?><input type="hidden" name="do" value="shop">
  <p class="muted" style="margin-top:0">This is printed on every bill.</p>
  <div class="field"><label>Shop name</label><input type="text" name="name" value="<?= e(setting('app_name', '')) ?>" required></div>
  <div class="form-row cols-2">
    <div><label>Mobile on the bill</label><input type="tel" name="phone" value="<?= e($co['phone'] ?? '') ?>"></div>
    <div><label>City</label><input type="text" name="city" value="<?= e(shop_city()) ?>"></div>
  </div>
  <div class="field"><label>Address</label><input type="text" name="address" value="<?= e($co['address'] ?? '') ?>"></div>
  <div class="field"><label>What you sell <span class="muted">(one line under your name)</span></label><input type="text" name="trade" value="<?= e(setting('shop_trade', '')) ?>" placeholder="<?= e(shop_trade()) ?>"></div>
  <div class="field"><label>Logo <span class="muted">(optional, JPG/PNG)</span></label><input type="file" name="logo" accept="image/png,image/jpeg,image/webp"></div>
  <div class="acts"><a class="btn btn-outline" href="setup.php?step=2">Skip</a><button class="btn" type="submit">Save &amp; next →</button></div>
</form>

<?php elseif ($step === 2): $gstCo = row('SELECT gstin FROM companies WHERE is_gst = 1 AND is_active = 1 LIMIT 1');
  $upi = val("SELECT upi_id FROM bank_accounts WHERE is_active = 1 AND upi_id <> '' ORDER BY is_default DESC, id LIMIT 1"); ?>
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="do" value="money">
  <div class="field"><label>GSTIN <span class="muted">(leave empty if you have no GST)</span></label><input type="text" name="gstin" value="<?= e($gstCo['gstin'] ?? '') ?>" maxlength="15" autocapitalize="characters" placeholder="24ABCDE1234F1Z5"></div>
  <div class="field"><label>UPI ID <span class="muted">(a QR to pay is printed on the bill)</span></label><input type="text" name="upi_id" value="<?= e($upi ?: '') ?>" placeholder="yourname@okhdfcbank"></div>
  <div class="acts"><a class="btn btn-outline" href="setup.php?step=3">Skip</a><button class="btn" type="submit">Save &amp; next →</button></div>
</form>

<?php elseif ($step === 3): $cnt = (int)val('SELECT COUNT(*) FROM items WHERE is_active = 1'); ?>
<div class="card">
  <p style="margin-top:0">You have <b><?= $cnt ?></b> item<?= $cnt === 1 ? '' : 's' ?><?= $cnt ? ' — some are samples so you can try a bill straight away' : '' ?>.</p>
  <div style="display:grid;gap:8px">
    <a class="btn btn-outline" href="items.php?action=new">➕ Add an item</a>
    <a class="btn btn-outline" href="items_import.php">📥 Bring all items from Excel</a>
    <?php if ($cnt): ?><form method="post" onsubmit="return confirm('Hide the sample items? Anything already billed stays.')"><?= csrf_field() ?><input type="hidden" name="do" value="hide_samples">
      <button class="btn btn-outline" type="submit" style="width:100%">🙈 Hide the sample items</button></form><?php endif; ?>
  </div>
  <div class="acts"><a class="btn btn-outline" href="setup.php?step=2">← Back</a><a class="btn" href="setup.php?step=4">Next →</a></div>
</div>

<?php elseif ($step === 4): $staff = all('SELECT name, username FROM users WHERE is_active = 1 ORDER BY id'); ?>
<div class="card">
  <p style="margin-top:0">Who works with you? Each person gets their own login, so you see who made which bill.</p>
  <ul><?php foreach ($staff as $s): ?><li><?= e($s['name']) ?> <span class="muted">(<?= e($s['username']) ?>)</span></li><?php endforeach; ?></ul>
  <a class="btn btn-outline" href="users.php?action=new">➕ Add a staff member</a>
  <div class="acts"><a class="btn btn-outline" href="setup.php?step=3">← Back</a><a class="btn" href="setup.php?step=5">Next →</a></div>
</div>

<?php else: ?>
<form method="post" class="card" style="text-align:center"><?= csrf_field() ?><input type="hidden" name="do" value="done">
  <div style="font-size:48px">🎉</div>
  <h2 style="margin:4px 0">All set</h2>
  <p class="muted">Everything here can be changed later in Settings.</p>
  <button class="btn" type="submit">🧾 Make my first bill</button>
</form>
<?php endif; ?>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
