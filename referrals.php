<?php
// Admin: Referral partners - set each partner's %, pause them, watch earnings,
// and pay out the approved balance (payout books an expense so the cash/bank
// books stay honest).
require_once __DIR__ . '/includes/init.php';
require_perm('referrals.view');
$u = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'save') {
    require_perm('referrals.edit');
    $rid = (int)post('id');
    q('UPDATE referrers SET commission_pct = ?, is_active = ? WHERE id = ?',
      [max(0, (float)post('commission_pct')), post('is_active') ? 1 : 0, $rid]);
    flash('Partner updated.');
    redirect('referrals.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'resend') {
    require_perm('referrals.edit');
    $r = row('SELECT * FROM referrers WHERE id = ?', [(int)post('id')]);
    if ($r && $r['mobile']) {
        $ok = send_whatsapp($r['mobile'], "🤝 *" . setting('app_name', 'AK Computer') . " Refer & Earn*\n\n🔗 તમારી લિંક:\n" . base_url('catalog.php?ref=' . $r['code']) .
            "\n\n📊 ડેશબોર્ડ:\n" . base_url('referral.php?t=' . $r['token']));
        flash($ok ? 'Link re-sent on WhatsApp.' : 'WhatsApp send failed. ' . whatsapp_last_error(), $ok ? 'success' : 'error');
    }
    redirect('referrals.php');
}

// Pay out a partner's whole APPROVED balance: earnings flip to 'paid' and the
// money leaves the books as an expense (category Referral Commission).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'payout') {
    require_perm('referrals.pay');
    $r = row('SELECT * FROM referrers WHERE id = ?', [(int)post('id')]);
    $due = $r ? (float)val("SELECT COALESCE(SUM(commission),0) FROM referral_earnings WHERE referrer_id = ? AND status = 'approved'", [$r['id']]) : 0;
    if (!$r || $due <= 0.009) { flash('Nothing approved to pay for this partner.', 'error'); redirect('referrals.php'); }
    $mode = post('mode') === 'bank' ? 'bank' : 'cash';
    $bankId = $mode === 'bank' ? ((int)post('bank_account_id') ?: null) : null;
    $pdo = db();
    $pdo->beginTransaction();
    try {
        q("UPDATE referral_earnings SET status = 'paid' WHERE referrer_id = ? AND status = 'approved'", [$r['id']]);
        q('INSERT INTO expenses (exp_date, category, amount, mode, bank_account_id, notes, location_id, created_by) VALUES (?,?,?,?,?,?,?,?)',
          [today(), 'Referral Commission', $due, $mode, $bankId, 'Payout to partner ' . $r['name'] . ' (' . $r['code'] . ')', $u['location_id'], $u['id']]);
        $pdo->commit();
        log_activity('referral_payout', "{$r['name']} ₹$due $mode");
        if ($r['mobile']) {
            send_whatsapp($r['mobile'], "💵 *" . setting('app_name', 'AK Computer') . "*\n\nતમારું કમિશન *₹" . money($due) . "* ચૂકવી દેવામાં આવ્યું છે. 🎉\nઆભાર — શેર કરતા રહો!\n\n📊 " . base_url('referral.php?t=' . $r['token']));
        }
        flash('Paid ₹' . money($due) . ' to ' . $r['name'] . ' — booked as a Referral Commission expense.');
    } catch (Exception $ex) {
        $pdo->rollBack();
        flash('Error: ' . $ex->getMessage(), 'error');
    }
    redirect('referrals.php');
}

$partners = all("SELECT r.*,
    COALESCE((SELECT SUM(commission) FROM referral_earnings WHERE referrer_id = r.id AND status = 'pending'), 0) pending_amt,
    COALESCE((SELECT SUM(commission) FROM referral_earnings WHERE referrer_id = r.id AND status = 'approved'), 0) approved_amt,
    COALESCE((SELECT SUM(commission) FROM referral_earnings WHERE referrer_id = r.id AND status = 'paid'), 0) paid_amt,
    (SELECT COUNT(*) FROM referral_earnings WHERE referrer_id = r.id) orders_cnt
    FROM referrers r ORDER BY approved_amt DESC, r.id DESC");
$recent = all('SELECT re.*, r.name r_name FROM referral_earnings re JOIN referrers r ON r.id = re.referrer_id ORDER BY re.id DESC LIMIT 30');
$banks = all('SELECT * FROM bank_accounts WHERE is_active = 1 ORDER BY is_default DESC, account_name');
$totApproved = array_sum(array_column($partners, 'approved_amt'));

$page_title = 'Referral Partners';
include __DIR__ . '/includes/header.php';
?>
<div class="grid-stats mb">
  <div class="stat"><div class="stat-label">🤝 Partners</div><div class="stat-value"><?= count($partners) ?></div></div>
  <div class="stat s-bad"><div class="stat-label">✅ To pay (approved)</div><div class="stat-value">₹<?= money($totApproved) ?></div></div>
  <div class="stat s-ok"><div class="stat-label">💵 Paid till now</div><div class="stat-value">₹<?= money(array_sum(array_column($partners, 'paid_amt'))) ?></div></div>
</div>
<p class="muted mb">Public signup page: <a href="referral.php" target="_blank"><?= e(base_url('referral.php')) ?></a> — share this to recruit partners.</p>

<?php foreach ($partners as $p): ?>
<div class="card">
  <h3><?= e($p['name']) ?> <?= $p['is_active'] ? '' : '<span class="badge badge-bad">PAUSED</span>' ?>
    <span class="muted" style="font-weight:normal;font-size:13px">· <?= e($p['mobile']) ?> · code <?= e($p['code']) ?> · <?= (int)$p['clicks'] ?> clicks · <?= (int)$p['orders_cnt'] ?> orders</span></h3>
  <p style="font-size:14px">⏳ Pending ₹<?= money($p['pending_amt']) ?> · ✅ <strong>To pay ₹<?= money($p['approved_amt']) ?></strong> · 💵 Paid ₹<?= money($p['paid_amt']) ?></p>
  <div class="page-actions mt">
    <?php if (can('referrals.edit')): ?>
    <form method="post" class="filterbar" style="margin:0">
      <?= csrf_field() ?><input type="hidden" name="do" value="save"><input type="hidden" name="id" value="<?= $p['id'] ?>">
      <div><label>%</label><input type="number" step="any" name="commission_pct" value="<?= (float)$p['commission_pct'] ?>" style="width:80px"></div>
      <label class="check-inline"><input type="checkbox" name="is_active" value="1" <?= $p['is_active'] ? 'checked' : '' ?>> Active</label>
      <button class="btn btn-sm" type="submit">Save</button>
    </form>
    <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="do" value="resend"><input type="hidden" name="id" value="<?= $p['id'] ?>">
      <button class="btn btn-sm btn-outline" type="submit">📲 Re-send link</button></form>
    <?php endif; ?>
    <?php if (can('referrals.pay') && $p['approved_amt'] > 0.009): ?>
    <form method="post" class="filterbar" style="margin:0" onsubmit="return confirm('Pay ₹<?= money($p['approved_amt']) ?> to <?= e($p['name']) ?>? An expense entry will be created.')">
      <?= csrf_field() ?><input type="hidden" name="do" value="payout"><input type="hidden" name="id" value="<?= $p['id'] ?>">
      <div><select name="mode" onchange="this.form.querySelector('.pb').style.display=this.value==='bank'?'':'none'">
        <option value="cash">Cash</option><option value="bank">Bank</option></select></div>
      <div class="pb" style="display:none"><select name="bank_account_id">
        <?php foreach ($banks as $b): ?><option value="<?= $b['id'] ?>"><?= e($b['account_name']) ?></option><?php endforeach; ?>
      </select></div>
      <button class="btn btn-sm btn-success" type="submit">💵 Pay ₹<?= money($p['approved_amt']) ?></button>
    </form>
    <?php endif; ?>
  </div>
</div>
<?php endforeach; if (!$partners): ?><div class="card"><p class="muted">No partners yet — share the signup page above.</p></div><?php endif; ?>

<?php if ($recent): ?>
<div class="card">
  <h3>Recent referral orders</h3>
  <table class="table-sm">
    <thead><tr><th>Order</th><th>Partner</th><th class="num">Order ₹</th><th class="num">Commission</th><th>Status</th></tr></thead>
    <tbody><?php foreach ($recent as $e2): ?>
    <tr><td><?= e($e2['order_no']) ?><br><span class="muted" style="font-size:12px"><?= dmyt($e2['created_at']) ?></span></td>
      <td><?= e($e2['r_name']) ?></td>
      <td class="num">₹<?= money($e2['order_total']) ?></td>
      <td class="num"><strong>₹<?= money($e2['commission']) ?></strong></td>
      <td><?= e($e2['status']) ?></td></tr>
    <?php endforeach; ?></tbody>
  </table>
</div>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
