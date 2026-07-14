<?php
// Invoice view / print / WhatsApp send. Public access via ?token= (customer link).
require_once __DIR__ . '/includes/init.php';

$id = (int)get('id');
$token = get('token');
$sale = $id ? row('SELECT s.*, c.name company_name, c.gstin, c.is_gst, c.address c_address, c.phone c_phone, c.terms c_terms, c.logo c_logo,
                   l.name loc_name, l.city loc_city, u2.name staff_name, p.name party_name, p.gstin party_gstin, p.address party_address
                   FROM sales s
                   JOIN companies c ON c.id = s.company_id
                   JOIN locations l ON l.id = s.location_id
                   JOIN users u2 ON u2.id = s.created_by
                   LEFT JOIN parties p ON p.id = s.party_id
                   WHERE s.id = ?', [$id]) : null;
if (!$sale) die('Invoice not found.');

$public = ($token && hash_equals($sale['share_token'], $token));
if (!$public) {
    require_perm('sales.view');
    if (!can('sales.all') && $sale['created_by'] != current_user()['id']) {
        die('Access denied.');
    }
}

$items = all("SELECT si.*, COALESCE(i.name, '(deleted item)') name, i.unit, i.hsn FROM sale_items si LEFT JOIN items i ON i.id = si.item_id WHERE si.sale_id = ?", [$id]);

// ---------- WhatsApp send ----------
if (!$public && $_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'whatsapp') {
    $mobile = post('mobile') ?: $sale['customer_mobile'];
    $link = base_url('sale_view.php?id=' . $id . '&token=' . $sale['share_token']);
    // Sent as a photo (not a PDF document) so it opens inline in WhatsApp -
    // WhatsApp needs a URL ending in .jpg to recognise it as an image.
    require_once __DIR__ . '/includes/billimage.php';
    $imgDir = __DIR__ . '/uploads/invoices';
    if (!is_dir($imgDir)) mkdir($imgDir, 0755, true);
    $imgName = preg_replace('/[^A-Za-z0-9\-]/', '_', $sale['invoice_no']) . '_' . substr($sale['share_token'], 0, 10) . '.jpg';
    file_put_contents($imgDir . '/' . $imgName,
        invoice_image_jpg($sale, all("SELECT si.*, COALESCE(i.name, '(deleted item)') name, i.unit FROM sale_items si LEFT JOIN items i ON i.id = si.item_id WHERE si.sale_id = ?", [$id])));
    $imgUrl = base_url('uploads/invoices/' . $imgName);
    $due = $sale['total'] - $sale['paid'];
    $payLink = $due > 0.009 ? razorpay_payment_link($due, 'Invoice ' . $sale['invoice_no'], $sale['customer_name'], $sale['customer_mobile'], $sale['invoice_no'], $id) : null;
    $msg = wa_template('bill', [
        'firm' => $sale['company_name'], 'invoice_no' => $sale['invoice_no'], 'date' => dmy($sale['sale_date']),
        'total' => money($sale['total']),
        'due_line' => $due > 0.009 ? 'Balance due: ₹' . money($due) : 'Paid ✔',
        'pay_link' => $payLink ? "💳 Pay online: $payLink\n" : '',
        'link' => $link, 'customer' => $sale['customer_name'],
    ]);
    // bill goes as a photo with the message as caption
    if ($mobile && send_whatsapp($mobile, $msg, $imgUrl)) {
        log_activity('sale_whatsapp', $sale['invoice_no'] . ' to ' . $mobile);
        flash('Bill (photo) sent on WhatsApp to ' . $mobile);
    } else {
        // Include the exact image URL that was sent to the gateway - lets
        // you paste it straight into a browser (or the bulk.akdwk.in test
        // link) to check whether it's actually reachable from outside.
        flash('WhatsApp send failed' . ($mobile ? '' : ' - missing mobile number') . '. ' . whatsapp_last_error() . ' | Image URL: ' . $imgUrl, 'error');
    }
    redirect('sale_view.php?id=' . $id);
}

// ---------- standalone payment link (for copy/SMS/email) ----------
if (!$public && $_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'paylink') {
    $due = $sale['total'] - $sale['paid'];
    $link = $due > 0.009 ? razorpay_payment_link($due, 'Invoice ' . $sale['invoice_no'], $sale['customer_name'], $sale['customer_mobile'], $sale['invoice_no'], $id) : null;
    if ($link) {
        flash('Payment Link: ' . $link . ' (copy it)');
    } else {
        flash('Could not create the link - check the Razorpay Key ID/Secret in Settings, or the bill is already fully paid.', 'error');
    }
    redirect('sale_view.php?id=' . $id);
}

// ---------- Google review invite ----------
// Sends the shop's Google review link directly to the customer's WhatsApp
// (Settings > Google Review Link) - no external review-service API needed.
if (!$public && $_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'review') {
    $reviewLink = setting('google_review_link');
    $mob = post('mobile') ?: $sale['customer_mobile'];
    if ($reviewLink && $mob) {
        $msg = wa_template('review', [
            'shop' => $sale['company_name'], 'customer' => $sale['customer_name'] ?: 'Customer', 'link' => $reviewLink,
        ]);
        $ok = send_whatsapp($mob, $msg);
        flash($ok ? '⭐ Review link sent on WhatsApp.' : ('WhatsApp send failed. ' . whatsapp_last_error()), $ok ? 'success' : 'error');
    } else { flash('Missing the Google Review Link (Settings) or mobile number.', 'error'); }
    redirect('sale_view.php?id=' . $id);
}

// ---------- record payment ----------
if (!$public && $_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'pay' && can('payments.add')) {
    $amt = min((float)post('amount'), $sale['total'] - $sale['paid']);
    if ($amt > 0) {
        q('UPDATE sales SET paid = paid + ?, status = ? WHERE id = ?',
          [$amt, payment_status($sale['total'], $sale['paid'] + $amt), $id]);
        q('INSERT INTO payments (party_id, direction, amount, mode, bank_account_id, payment_method_id, ref_type, ref_id, pay_date, notes, created_by)
           VALUES (?,?,?,?,?,?,?,?,?,?,?)',
          [$sale['party_id'] ?: null, 'in', $amt, post('mode', 'cash'), (int)post('bank_account_id') ?: null,
           (int)post('payment_method_id') ?: null, 'sale', $id, today(), 'Against ' . $sale['invoice_no'], current_user()['id']]);
        fire_webhook('payment.recorded', ['sale_id' => $id, 'invoice_no' => $sale['invoice_no'], 'amount' => $amt, 'direction' => 'in', 'mode' => post('mode', 'cash')]);
        flash('Payment of ₹' . money($amt) . ' recorded.');
    }
    redirect('sale_view.php?id=' . $id);
}

$page_title = 'Invoice ' . $sale['invoice_no'];
include __DIR__ . '/includes/header.php';
$due = $sale['is_cancelled'] ? 0 : $sale['total'] - $sale['paid'];
?>
<?php if ($sale['is_cancelled']): ?><div class="flash flash-error">🚫 This INVOICE is CANCELLED.</div><?php endif; ?>
<?php if (!$public): ?>
<div class="page-actions no-print">
  <button class="btn" onclick="window.print()">🖨️ Print</button>
  <a class="btn btn-outline" href="sale_pdf.php?id=<?= $id ?>" target="_blank">📄 PDF</a>
  <form method="post" style="display:inline-flex;gap:6px">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="whatsapp">
    <input type="tel" name="mobile" value="<?= e($sale['customer_mobile']) ?>" placeholder="WhatsApp no." style="width:150px">
    <button class="btn btn-wa" type="submit">📲 Send WhatsApp</button>
  </form>
  <?php if (setting('google_review_link') && $sale['customer_mobile']): ?>
  <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="do" value="review">
    <button class="btn btn-outline" type="submit">⭐ Review Invite</button></form>
  <?php endif; ?>
  <?php if (setting('razorpay_key_id') && $due > 0.009): ?>
  <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="do" value="paylink">
    <button class="btn btn-outline" type="submit">🔗 Payment Link</button></form>
  <?php endif; ?>
  <a class="btn btn-outline" href="sales.php">← Back</a>
  <?php if (can('sales.edit') && !$sale['is_cancelled']): ?>
  <a class="btn btn-outline" href="sales.php?action=edit&id=<?= $id ?>">✏️ Edit</a>
  <?php endif; ?>
  <?php if (can('sales.delete')): ?>
  <?php if (!$sale['is_cancelled']): ?>
  <form method="post" action="sales.php" onsubmit="return confirm('Cancel this invoice? (Record stays, stock is restored)')" style="display:inline">
    <?= csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="mode" value="cancel"><input type="hidden" name="id" value="<?= $id ?>">
    <button class="btn btn-muted" type="submit">🚫 Cancel Invoice</button>
  </form>
  <?php endif; ?>
  <form method="post" action="sales.php" onsubmit="return confirm('DELETE completely? The record will be gone too!')" style="display:inline">
    <?= csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="mode" value="delete"><input type="hidden" name="id" value="<?= $id ?>">
    <button class="btn btn-danger" type="submit">Delete</button>
  </form>
  <?php endif; ?>
</div>
<?php if ($due > 0.009 && can('payments.add')):
  $pms = active_payment_methods();
  $banks = all('SELECT * FROM bank_accounts WHERE is_active = 1 ORDER BY is_default DESC, account_name'); ?>
<div class="card no-print">
  <h3>Record payment (due ₹<?= money($due) ?>)</h3>
  <form method="post" class="filterbar">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="pay">
    <div><input type="number" step="any" name="amount" value="<?= money($due) ?>" max="<?= $due ?>"></div>
    <div><select name="mode" id="pv_mode" onchange="document.getElementById('pv_bank').style.display=this.selectedOptions[0].dataset.type==='bank'?'':'none'">
      <?php foreach ($pms as $pm): ?><option value="<?= e($pm['code']) ?>" data-id="<?= $pm['id'] ?>" data-type="<?= e($pm['type']) ?>"><?= e($pm['name']) ?></option><?php endforeach; ?>
    </select></div>
    <div id="pv_bank" style="<?= ($pms[0]['type'] ?? '') === 'bank' ? '' : 'display:none' ?>">
      <select name="bank_account_id"><?php foreach ($banks as $b): ?><option value="<?= $b['id'] ?>"><?= e($b['account_name']) ?></option><?php endforeach; ?></select>
    </div>
    <button class="btn btn-success btn-sm" type="submit">Receive</button>
  </form>
</div>
<?php endif; endif; ?>

<div class="inv-paper card <?= invoice_theme_class() ?>">
  <div class="inv-head">
    <div class="inv-firm">
      <?php if (!empty($sale['c_logo'])): ?><img src="<?= e($sale['c_logo']) ?>" alt="" style="max-height:56px;margin-bottom:6px"><br><?php endif; ?>
      <h1><?= e($sale['company_name']) ?></h1>
      <div class="muted"><?= e($sale['c_address']) ?> <?= e($sale['loc_name']) ?>, <?= e($sale['loc_city']) ?><br>
      <?= $sale['c_phone'] ? 'Ph: ' . e($sale['c_phone']) : '' ?>
      <?= $sale['is_gst'] && $sale['gstin'] ? '| GSTIN: ' . e($sale['gstin']) : '' ?></div>
    </div>
    <div class="inv-meta">
      <div class="inv-title"><?= $sale['is_gst'] ? 'TAX INVOICE' : 'INVOICE' ?></div>
      <div><strong><?= e($sale['invoice_no']) ?></strong></div>
      <div>Date: <?= dmy($sale['sale_date']) ?><?= setting('add_time_transactions', '1') === '1' && $sale['created_at'] ? ' &nbsp;Time: ' . date('h:i A', strtotime($sale['created_at'])) : '' ?></div>
      <?php if ($sale['due_date']): ?><div>Due: <?= dmy($sale['due_date']) ?></div><?php endif; ?>
    </div>
  </div>
  <div class="mb">
    <strong>Bill To:</strong> <?= e($sale['customer_name'] ?: $sale['party_name'] ?: 'Walk-in Customer') ?>
    <?= $sale['customer_mobile'] ? ' | ' . e($sale['customer_mobile']) : '' ?>
    <?= $sale['party_gstin'] ? '<br>GSTIN: ' . e($sale['party_gstin']) : '' ?>
    <?= $sale['party_address'] ? '<br>' . e($sale['party_address']) : '' ?>
  </div>
  <div class="table-wrap" style="box-shadow:none">
    <table class="inv-table">
      <thead><tr><th>#</th><th>Item</th><?php if ($sale['is_gst']): ?><th>HSN</th><?php endif; ?><th class="num">Qty</th><th class="num">Rate</th><?php if ($sale['is_gst']): ?><th class="num">GST%</th><?php endif; ?><th class="num">Amount</th></tr></thead>
      <tbody>
      <?php foreach ($items as $n => $it): ?>
        <tr>
          <td><?= $n + 1 ?></td>
          <td><?= e($it['name']) ?><?= $it['serials'] ? '<br><small>SN: ' . e($it['serials']) . '</small>' : '' ?>
            <?php if (!empty($it['description'])): ?><br><small class="muted"><?= e($it['description']) ?></small><?php endif; ?>
            <?php if (!empty($it['custom_data'])): $cd = json_decode($it['custom_data'], true) ?: []; foreach ($cd as $cfLabel => $cfVal): ?>
            <br><small class="muted"><?= e($cfLabel) ?>: <?= e($cfVal) ?></small>
            <?php endforeach; endif; ?>
          </td>
          <?php if ($sale['is_gst']): ?><td><?= e($it['hsn']) ?></td><?php endif; ?>
          <td class="num"><?= (float)$it['qty'] ?><?= !empty($it['free_qty']) && $it['free_qty'] > 0 ? ' <span class="badge badge-ok">+' . (float)$it['free_qty'] . ' free</span>' : '' ?> <?= e($it['unit']) ?></td>
          <td class="num"><?= money($it['price']) ?></td>
          <?php if ($sale['is_gst']): ?><td class="num"><?= (float)$it['tax_rate'] ?>%</td><?php endif; ?>
          <td class="num"><?= money($it['total']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="bill-totals">
    <div class="t-line"><span>Subtotal</span><span>₹<?= money($sale['subtotal']) ?></span></div>
    <?php if ($sale['discount'] > 0):
        $dLabel = (!empty($sale['discount_type']) && $sale['discount_type'] === 'percent' && $sale['discount_pct'] > 0)
            ? 'Discount (' . rtrim(rtrim(number_format($sale['discount_pct'], 2), '0'), '.') . '%)' : 'Discount'; ?>
    <div class="t-line"><span><?= e($dLabel) ?></span><span>- ₹<?= money($sale['discount']) ?></span></div>
    <?php endif; ?>
    <?php if ($sale['is_gst']): ?>
    <div class="t-line"><span>CGST</span><span>₹<?= money($sale['tax_amount'] / 2) ?></span></div>
    <div class="t-line"><span>SGST</span><span>₹<?= money($sale['tax_amount'] / 2) ?></span></div>
    <?php endif; ?>
    <?php if ($sale['shipping'] > 0): ?>
    <div class="t-line"><span>Shipping</span><span>₹<?= money($sale['shipping']) ?></span></div>
    <?php endif; ?>
    <?php if (!empty($sale['loyalty_points_used']) && $sale['loyalty_points_used'] > 0): ?>
    <div class="t-line"><span>⭐ Points Discount (<?= (int)$sale['loyalty_points_used'] ?> pts)</span><span>- ₹<?= money($sale['loyalty_discount']) ?></span></div>
    <?php endif; ?>
    <?php if (!empty($sale['adjustment']) && abs($sale['adjustment']) > 0.009): ?>
    <div class="t-line"><span>Adjustment</span><span><?= $sale['adjustment'] > 0 ? '' : '- ' ?>₹<?= money(abs($sale['adjustment'])) ?></span></div>
    <?php endif; ?>
    <?php if (!empty($sale['round_off']) && abs($sale['round_off']) > 0.004): ?>
    <div class="t-line"><span>Round Off</span><span><?= $sale['round_off'] > 0 ? '' : '- ' ?>₹<?= money(abs($sale['round_off'])) ?></span></div>
    <?php endif; ?>
    <div class="t-line t-grand"><span>Total</span><span>₹<?= money($sale['total']) ?></span></div>
    <div class="t-line"><span>Paid (<?= e($sale['payment_mode']) ?>)</span><span>₹<?= money($sale['paid']) ?></span></div>
    <?php if ($due > 0.009): ?><div class="t-line"><span><strong>Balance Due</strong></span><span><strong>₹<?= money($due) ?></strong></span></div><?php endif; ?>
  </div>

  <?php if ($sale['is_gst'] && $sale['tax_amount'] > 0):
      $slabs = [];
      foreach ($items as $it) {
          $tr = (float)$it['tax_rate'];
          if (!isset($slabs[$tr])) $slabs[$tr] = 0;
          $slabs[$tr] += (float)$it['total'];
      }
      ksort($slabs); ?>
  <div class="table-wrap mt" style="box-shadow:none">
    <table class="table-sm inv-table">
      <thead><tr><th>GST Slab</th><th class="num">Taxable ₹</th><th class="num">CGST</th><th class="num">SGST</th><th class="num">Total Tax ₹</th></tr></thead>
      <tbody><?php foreach ($slabs as $tr => $tv): if ($tr <= 0) continue; $tx = $tv * $tr / 100; ?>
        <tr><td><?= $tr ?>%</td><td class="num"><?= money($tv) ?></td>
        <td class="num"><?= ($tr / 2) ?>% = <?= money($tx / 2) ?></td>
        <td class="num"><?= ($tr / 2) ?>% = <?= money($tx / 2) ?></td>
        <td class="num"><?= money($tx) ?></td></tr>
      <?php endforeach; ?></tbody>
    </table>
  </div>
  <?php endif; ?>

  <?php
  $bankAcc = default_bank_account();
  $qrWeb = invoice_qr_web_path($sale);
  if ($bankAcc || $qrWeb): ?>
  <div class="mt" style="display:flex;gap:24px;flex-wrap:wrap;align-items:flex-start;border-top:1px dashed var(--border);padding-top:12px">
    <?php if ($bankAcc): ?>
    <div style="font-size:12.5px">
      <strong>Pay via Bank Transfer:</strong><br>
      <?= e($bankAcc['account_name']) ?> - <?= e($bankAcc['bank_name']) ?><br>
      A/C No: <?= e($bankAcc['account_number']) ?> &nbsp; IFSC: <?= e($bankAcc['ifsc']) ?>
      <?= $bankAcc['branch'] ? '<br>Branch: ' . e($bankAcc['branch']) : '' ?>
    </div>
    <?php endif; ?>
    <?php if ($qrWeb): ?>
    <div style="text-align:center">
      <img src="<?= e($qrWeb) ?>" alt="Scan to pay" style="width:110px;height:110px;border:1px solid var(--border);border-radius:8px">
      <div class="muted" style="font-size:10.5px">Scan & Pay ₹<?= money($sale['total']) ?></div>
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if ($sale['c_terms']): ?><p class="muted mt" style="font-size:11.5px"><strong>Terms & Conditions:</strong><br><?= nl2br(e($sale['c_terms'])) ?></p><?php endif; ?>

  <div class="mt" style="display:flex;justify-content:space-between;gap:20px;padding-top:34px;font-size:13px">
    <div style="border-top:1px solid var(--text);padding-top:6px;min-width:160px;text-align:center">Receiver's Signature</div>
    <div style="border-top:1px solid var(--text);padding-top:6px;min-width:200px;text-align:center">For <strong><?= e($sale['company_name']) ?></strong><br>Authorised Signatory</div>
  </div>
  <p class="muted mt" style="font-size:11px">Billed by: <?= e($sale['staff_name']) ?><?= $sale['notes'] ? ' | ' . e($sale['notes']) : '' ?></p>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
