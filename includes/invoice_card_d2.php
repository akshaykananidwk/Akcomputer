<?php
// Design 2 (purple / orange) on-screen + print invoice card. Mirrors
// includes/pdf.php's invoice_pdf_design2(). Expects $sale, $items, $due in
// scope (included from sale_view.php). Amounts use "Rs" to match the PDF.
$bankAcc = default_bank_account();
$qrWeb = invoice_qr_web_path($sale);
$hexBadges = [
    ['★', 'BEST QUALITY', '#6d28d9'], ['%', 'BEST PRICE', '#ff7a1a'],
    ['✓', 'TRUSTED SERVICE', '#2563eb'], ['🎧', 'AFTER SALES SUPPORT', '#0f9d58'],
];
$sqColors = ['#6d28d9', '#ff7a1a', '#2563eb', '#d0348a', '#0aa89e', '#0f9d58'];
$trust = [
    ['✔', '100% ORIGINAL', '#6d28d9'], ['🔒', 'SECURE PAYMENT', '#2563eb'],
    ['🛡', 'WARRANTY ASSURED', '#d0348a'], ['🎧', 'EXPERT SUPPORT', '#0aa89e'],
    ['🚚', 'FAST & SAFE', '#ff7a1a'],
];
?>
<div class="inv-paper card inv2-bill">
  <!-- header -->
  <div class="inv2-head">
    <div class="inv2-head-left">
      <div class="inv2-logo"><span class="ak">AK</span> <span class="cmp">COMPUTER</span></div>
      <div class="inv2-tagline">Smart Solutions, Better Future</div>
      <div class="inv2-contact">📍 <?= e(trim(($sale['c_address'] ?? '') . ' ' . $sale['loc_name'] . ', ' . $sale['loc_city'])) ?></div>
      <?php if ($sale['c_phone']): ?><div class="inv2-contact">📞 <?= e($sale['c_phone']) ?></div><?php endif; ?>
      <?php $email = setting('company_email', setting('app_email', '')); if ($email): ?><div class="inv2-contact">✉ <?= e($email) ?></div><?php endif; ?>
    </div>
    <div class="inv2-head-right">
      <div class="inv2-title"><?= $sale['is_gst'] ? 'TAX INVOICE' : 'INVOICE' ?></div>
      <div class="inv2-info">
        <div class="inv2-info-row"><span class="ib" style="background:#6d28d9">🧾</span><span class="il">Invoice No.</span><span class="iv">: <?= e($sale['invoice_no']) ?></span></div>
        <div class="inv2-info-row"><span class="ib" style="background:#ff7a1a">📅</span><span class="il">Date</span><span class="iv">: <?= dmy($sale['sale_date']) ?></span></div>
        <?php if (setting('add_time_transactions', '1') === '1' && $sale['created_at']): ?>
        <div class="inv2-info-row"><span class="ib" style="background:#2563eb">🕐</span><span class="il">Time</span><span class="iv">: <?= date('h:i A', strtotime($sale['created_at'])) ?></span></div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- bill to + hexagon badges -->
  <div class="inv2-billrow">
    <div>
      <div class="inv2-billto">👤 BILL TO :</div>
      <div class="inv2-cust"><?= e($sale['customer_name'] ?: $sale['party_name'] ?: 'Walk-in Customer') ?></div>
      <?php if ($sale['customer_mobile']): ?><div class="inv2-contact">📞 <?= e($sale['customer_mobile']) ?></div><?php endif; ?>
      <?= $sale['party_gstin'] ? '<div class="muted">GSTIN: ' . e($sale['party_gstin']) . '</div>' : '' ?>
    </div>
    <div class="inv2-badges">
      <?php foreach ($hexBadges as $b): ?>
      <div class="inv2-badge">
        <div class="inv2-hex" style="background:<?= $b[2] ?>"><?= $b[0] ?></div>
        <div class="inv2-badge-t"><?= e($b[1]) ?></div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- items -->
  <div class="table-wrap" style="box-shadow:none">
    <table class="inv-table inv2-table">
      <thead><tr><th>#</th><th>Item Description</th><?php if ($sale['is_gst']): ?><th>HSN</th><?php endif; ?><th class="num" style="text-align:center">Qty</th><th class="num">Rate</th><?php if ($sale['is_gst']): ?><th class="num">GST%</th><?php endif; ?><th class="num">Amount</th></tr></thead>
      <tbody>
      <?php foreach ($items as $n => $it): ?>
        <tr>
          <td><span class="inv2-sq" style="background:<?= $sqColors[$n % count($sqColors)] ?>"><?= sprintf('%02d', $n + 1) ?></span></td>
          <td><?= e($it['name']) ?><?= $it['serials'] ? '<br><small class="muted">SN: ' . e($it['serials']) . '</small>' : '' ?>
            <?php if (!empty($it['description'])): ?><br><small class="muted"><?= e($it['description']) ?></small><?php endif; ?>
            <?php if (!empty($it['custom_data'])): $cd = json_decode($it['custom_data'], true) ?: [];
                  foreach ($cd as $cfLabel => $cfVal): if (trim((string)$cfVal) === '') continue;
                      $cfPub = custom_field_printable($cfLabel); ?>
            <br><small class="muted<?= $cfPub ? '' : ' no-print' ?>"><?= $cfPub ? '' : '🔒 ' ?><?= e($cfLabel) ?>: <?= e($cfVal) ?></small>
            <?php endforeach; endif; ?>
          </td>
          <?php if ($sale['is_gst']): ?><td><?= e($it['hsn']) ?></td><?php endif; ?>
          <td class="num" style="text-align:center"><?= (float)$it['qty'] ?> <?= e($it['unit']) ?></td>
          <td class="num"><?= money($it['price']) ?></td>
          <?php if ($sale['is_gst']): ?><td class="num"><?= (float)$it['tax_rate'] ?>%</td><?php endif; ?>
          <td class="num"><?= money($it['total']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- bank + totals -->
  <div class="inv2-payrow">
    <div class="inv2-payblock">
      <?php if ($bankAcc): ?>
      <div class="inv2-pay-pill">PAY VIA BANK TRANSFER</div>
      <div class="inv2-bank">
        <div><span>A/C Name</span>: <strong><?= e($bankAcc['account_name']) ?></strong></div>
        <div><span>A/C No.</span>: <strong><?= e($bankAcc['account_number']) ?></strong></div>
        <div><span>IFSC Code</span>: <strong><?= e($bankAcc['ifsc']) ?></strong></div>
        <?php if ($bankAcc['branch']): ?><div><span>Branch</span>: <strong><?= e($bankAcc['branch']) ?></strong></div><?php endif; ?>
      </div>
      <?php endif; ?>
      <?php if ($qrWeb): ?>
      <div class="inv2-scanrow">
        <div><div class="inv2-scan-pill">SCAN &amp; PAY</div><img src="<?= e($qrWeb) ?>" alt="Scan to pay" class="inv2-qr"></div>
        <div>
          <div class="inv2-thanks">Thank You! ♥</div>
          <div class="muted" style="font-size:11px;max-width:220px">We truly appreciate your business and look forward to serving you again.</div>
          <div class="inv2-stars">★★★★★</div>
        </div>
      </div>
      <?php endif; ?>
    </div>
    <div class="inv2-totals">
      <div class="inv2-t"><span>SUBTOTAL</span><span>Rs <?= money($sale['subtotal']) ?></span></div>
      <?php $dLabel = (!empty($sale['discount_type']) && $sale['discount_type'] === 'percent' && $sale['discount_pct'] > 0) ? 'DISCOUNT (' . rtrim(rtrim(number_format($sale['discount_pct'], 2), '0'), '.') . '%)' : 'DISCOUNT'; ?>
      <div class="inv2-t"><span><?= e($dLabel) ?></span><span><?= $sale['discount'] > 0 ? '- ' : '' ?>Rs <?= money($sale['discount']) ?></span></div>
      <?php if ($sale['is_gst']): ?>
      <div class="inv2-t"><span>CGST</span><span>Rs <?= money($sale['tax_amount'] / 2) ?></span></div>
      <div class="inv2-t"><span>SGST</span><span>Rs <?= money($sale['tax_amount'] / 2) ?></span></div>
      <?php endif; ?>
      <?php if ($sale['shipping'] > 0): ?><div class="inv2-t"><span>SHIPPING</span><span>Rs <?= money($sale['shipping']) ?></span></div><?php endif; ?>
      <?php if (!empty($sale['adjustment']) && abs($sale['adjustment']) > 0.009): ?><div class="inv2-t"><span>ADJUSTMENT</span><span><?= $sale['adjustment'] > 0 ? '' : '- ' ?>Rs <?= money(abs($sale['adjustment'])) ?></span></div><?php endif; ?>
      <?php if (!empty($sale['round_off']) && abs($sale['round_off']) > 0.004): ?><div class="inv2-t"><span>ROUND OFF</span><span><?= $sale['round_off'] > 0 ? '' : '- ' ?>Rs <?= money(abs($sale['round_off'])) ?></span></div><?php endif; ?>
      <div class="inv2-total-bar"><span>TOTAL</span><span>Rs <?= money($sale['total']) ?></span></div>
      <div class="inv2-t"><span>PAID (<?= e(strtoupper($sale['payment_mode'])) ?>)</span><span>Rs <?= money($sale['paid']) ?></span></div>
      <?php if ($due > 0.009): ?>
      <div class="inv2-balance-bar"><span>BALANCE DUE</span><span>Rs <?= money($due) ?></span></div>
      <?php else: ?><div class="inv2-paid-bar">PAID IN FULL</div><?php endif; ?>
    </div>
  </div>

  <!-- trust badges row -->
  <div class="inv2-trustrow">
    <?php foreach ($trust as $t): ?>
    <div class="inv2-trust"><div class="inv2-trust-c" style="border-color:<?= $t[2] ?>;color:<?= $t[2] ?>"><?= $t[0] ?></div><div class="inv2-trust-t"><?= e($t[1]) ?></div></div>
    <?php endforeach; ?>
  </div>

  <!-- footer -->
  <div class="inv2-footer">
    <div><strong>Stay Connected</strong><div class="inv2-social"><span>f</span><span>IG</span><span>W</span><span>YT</span></div></div>
    <?php if ($sale['c_phone']): ?><div style="text-align:center"><strong>For Support</strong><br><?= e($sale['c_phone']) ?></div><?php endif; ?>
    <div style="text-align:right"><strong>We Deal In :</strong><br>Computers · Laptops · Accessories · CCTV · Networking · AMC</div>
  </div>
  <div class="inv2-sigrow">
    <div class="inv2-sig">Receiver's Signature</div>
    <div class="inv2-stamp">AK COMPUTER<br>★ THANK YOU ★<br><?= e(strtoupper(trim(explode('-', $sale['loc_city'])[0]))) ?></div>
    <div class="inv2-sig">For <?= e($sale['company_name']) ?> - Authorised Signatory</div>
  </div>
  <div class="inv2-bottom">This is a computer generated invoice.</div>
  <p class="muted mt" style="font-size:11px">Billed by: <?= e($sale['staff_name']) ?><?= $sale['notes'] ? ' | ' . e($sale['notes']) : '' ?></p>
</div>
