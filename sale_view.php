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

$items = all('SELECT si.*, i.name, i.unit, i.hsn FROM sale_items si JOIN items i ON i.id = si.item_id WHERE si.sale_id = ?', [$id]);

// ---------- WhatsApp send ----------
if (!$public && $_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'whatsapp') {
    $mobile = post('mobile') ?: $sale['customer_mobile'];
    $link = base_url('sale_view.php?id=' . $id . '&token=' . $sale['share_token']);
    $pdfUrl = base_url('sale_pdf.php?id=' . $id . '&token=' . $sale['share_token']);
    $due = $sale['total'] - $sale['paid'];
    $msg = wa_template('bill', [
        'firm' => $sale['company_name'], 'invoice_no' => $sale['invoice_no'], 'date' => dmy($sale['sale_date']),
        'total' => money($sale['total']),
        'due_line' => $due > 0.009 ? 'Balance due: ₹' . money($due) : 'Paid ✔',
        'link' => $link, 'customer' => $sale['customer_name'],
    ]);
    // bill goes as a PDF document with the message as caption
    if ($mobile && send_whatsapp($mobile, $msg, $pdfUrl)) {
        log_activity('sale_whatsapp', $sale['invoice_no'] . ' to ' . $mobile);
        flash('Bill (PDF) sent on WhatsApp to ' . $mobile);
    } else {
        flash('WhatsApp send failed. Check number & API settings.', 'error');
    }
    redirect('sale_view.php?id=' . $id);
}

// ---------- record payment ----------
if (!$public && $_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'pay' && can('payments.add')) {
    $amt = min((float)post('amount'), $sale['total'] - $sale['paid']);
    if ($amt > 0) {
        q('UPDATE sales SET paid = paid + ?, status = ? WHERE id = ?',
          [$amt, payment_status($sale['total'], $sale['paid'] + $amt), $id]);
        if ($sale['party_id']) {
            q('INSERT INTO payments (party_id, direction, amount, mode, ref_type, ref_id, pay_date, notes, created_by)
               VALUES (?,?,?,?,?,?,?,?,?)',
              [$sale['party_id'], 'in', $amt, post('mode', 'cash'), 'sale', $id, today(), 'Against ' . $sale['invoice_no'], current_user()['id']]);
        }
        flash('Payment of ₹' . money($amt) . ' recorded.');
    }
    redirect('sale_view.php?id=' . $id);
}

$page_title = 'Invoice ' . $sale['invoice_no'];
include __DIR__ . '/includes/header.php';
$due = $sale['total'] - $sale['paid'];
?>
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
  <a class="btn btn-outline" href="sales.php">← Back</a>
  <?php if (can('sales.delete')): ?>
  <form method="post" action="sales.php" onsubmit="return confirm('Delete this bill? Stock will be restored.')" style="display:inline">
    <?= csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= $id ?>">
    <button class="btn btn-danger" type="submit">Delete</button>
  </form>
  <?php endif; ?>
</div>
<?php if ($due > 0.009 && can('payments.add')): ?>
<div class="card no-print">
  <h3>Record payment (due ₹<?= money($due) ?>)</h3>
  <form method="post" class="filterbar">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="pay">
    <div><input type="number" step="any" name="amount" value="<?= money($due) ?>" max="<?= $due ?>"></div>
    <div><select name="mode"><option>cash</option><option>upi</option><option>card</option><option>bank</option></select></div>
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
      <div>Date: <?= dmy($sale['sale_date']) ?></div>
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
          <td><?= e($it['name']) ?><?= $it['serials'] ? '<br><small>SN: ' . e($it['serials']) . '</small>' : '' ?></td>
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
    <?php if ($sale['discount'] > 0): ?><div class="t-line"><span>Discount</span><span>- ₹<?= money($sale['discount']) ?></span></div><?php endif; ?>
    <?php if ($sale['is_gst']): ?>
    <div class="t-line"><span>CGST</span><span>₹<?= money($sale['tax_amount'] / 2) ?></span></div>
    <div class="t-line"><span>SGST</span><span>₹<?= money($sale['tax_amount'] / 2) ?></span></div>
    <?php endif; ?>
    <div class="t-line t-grand"><span>Total</span><span>₹<?= money($sale['total']) ?></span></div>
    <div class="t-line"><span>Paid (<?= e($sale['payment_mode']) ?>)</span><span>₹<?= money($sale['paid']) ?></span></div>
    <?php if ($due > 0.009): ?><div class="t-line"><span><strong>Balance Due</strong></span><span><strong>₹<?= money($due) ?></strong></span></div><?php endif; ?>
  </div>
  <?php if ($sale['c_terms']): ?><p class="muted mt"><?= nl2br(e($sale['c_terms'])) ?></p><?php endif; ?>
  <p class="muted mt">Billed by: <?= e($sale['staff_name']) ?><?= $sale['notes'] ? ' | ' . e($sale['notes']) : '' ?></p>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
