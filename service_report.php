<?php
// Public digital service report - no login required, reached via the link
// generated/shared from a repair job's edit page (repairs.php action=share_report).
// Shows the checklist, parts used, photos and charges for that job - the
// same shape of "share a token URL publicly" as feedback.php.
require_once __DIR__ . '/includes/init.php';

$token = get('token');
$job = $token ? row('SELECT * FROM repairs WHERE report_token = ?', [$token]) : null;
if (!$job) { http_response_code(404); die('Invalid or expired report link.'); }

$materials = all('SELECT rm.*, i.name, i.unit FROM repair_materials rm JOIN items i ON i.id = rm.item_id WHERE rm.repair_id = ? ORDER BY rm.id', [$job['id']]);
$materialTotal = array_sum(array_column($materials, 'total'));
$checklist = all('SELECT rc.*, ci.label FROM repair_checklist rc JOIN service_checklist_items ci ON ci.id = rc.checklist_item_id
                  WHERE rc.repair_id = ? ORDER BY ci.sort_order', [$job['id']]);
$photosBefore = all("SELECT * FROM repair_photos WHERE repair_id = ? AND type = 'before' ORDER BY created_at", [$job['id']]);
$photosAfter = all("SELECT * FROM repair_photos WHERE repair_id = ? AND type = 'after' ORDER BY created_at", [$job['id']]);
$tech = $job['assigned_to'] ? row('SELECT name FROM users WHERE id = ?', [$job['assigned_to']]) : null;

$page_title = 'Service Report - ' . $job['job_no'];
include __DIR__ . '/includes/header.php';
?>
<div class="card" style="max-width:720px;margin:20px auto">
  <div class="page-actions no-print" style="justify-content:flex-end;margin:0 0 10px">
    <button class="btn btn-outline btn-sm" onclick="window.print()">🖨️ Print</button>
  </div>
  <h2>🧾 Digital Service Report</h2>
  <p class="muted">Job <?= e($job['job_no']) ?> <?= status_badge($job['status']) ?></p>
  <div class="form-row cols-2 mt">
    <div><strong>Customer:</strong> <?= e($job['customer_name']) ?><br><span class="muted"><?= e($job['customer_mobile']) ?></span></div>
    <div><strong>Device:</strong> <?= e($job['device_type']) ?> <?= e($job['brand_model']) ?><br><span class="muted">SN: <?= e($job['serial_no'] ?: '-') ?></span></div>
  </div>
  <div class="form-row cols-2 mt">
    <div><strong>Received:</strong> <?= dmy($job['received_date']) ?></div>
    <div><strong>Delivered:</strong> <?= dmy($job['delivered_date']) ?></div>
  </div>
  <?php if ($tech): ?><p class="mt"><strong>Technician:</strong> <?= e($tech['name']) ?></p><?php endif; ?>
  <p class="mt"><strong>Problem reported:</strong><br><?= nl2br(e($job['problem'])) ?></p>
  <?php if ($job['notes']): ?><p class="mt"><strong>Notes:</strong><br><?= nl2br(e($job['notes'])) ?></p><?php endif; ?>
</div>

<?php if ($checklist): ?>
<div class="card" style="max-width:720px;margin:14px auto">
  <h3>✅ Service checklist</h3>
  <table class="table-sm">
    <thead><tr><th>Item</th><th>Result</th><th>Notes</th></tr></thead>
    <?php foreach ($checklist as $c): ?>
    <tr><td><?= e($c['label']) ?></td><td><?= status_badge($c['result']) ?></td><td><?= e($c['notes']) ?></td></tr>
    <?php endforeach; ?>
  </table>
</div>
<?php endif; ?>

<?php if ($materials): ?>
<div class="card" style="max-width:720px;margin:14px auto">
  <h3>🔧 Parts used</h3>
  <table class="table-sm">
    <?php foreach ($materials as $m): ?>
    <tr><td><?= e($m['name']) ?></td><td class="num"><?= (float)$m['qty'] ?> <?= e($m['unit']) ?></td><td class="num">₹<?= money($m['total']) ?></td></tr>
    <?php endforeach; ?>
    <tr><td><strong>Parts total</strong></td><td></td><td class="num"><strong>₹<?= money($materialTotal) ?></strong></td></tr>
  </table>
</div>
<?php endif; ?>

<?php if ($photosBefore || $photosAfter): ?>
<div class="card" style="max-width:720px;margin:14px auto">
  <h3>📷 Photos</h3>
  <div class="form-row cols-2">
    <div>
      <strong class="muted">Before</strong>
      <div class="mt" style="display:flex;flex-wrap:wrap;gap:8px">
        <?php foreach ($photosBefore as $p): ?><a href="<?= e($p['path']) ?>" target="_blank"><img src="<?= e($p['path']) ?>" style="width:100px;height:100px;object-fit:cover;border-radius:8px;border:1px solid var(--border)"></a><?php endforeach; ?>
        <?php if (!$photosBefore): ?><span class="muted">-</span><?php endif; ?>
      </div>
    </div>
    <div>
      <strong class="muted">After</strong>
      <div class="mt" style="display:flex;flex-wrap:wrap;gap:8px">
        <?php foreach ($photosAfter as $p): ?><a href="<?= e($p['path']) ?>" target="_blank"><img src="<?= e($p['path']) ?>" style="width:100px;height:100px;object-fit:cover;border-radius:8px;border:1px solid var(--border)"></a><?php endforeach; ?>
        <?php if (!$photosAfter): ?><span class="muted">-</span><?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="card" style="max-width:720px;margin:14px auto">
  <h3>💰 Charges</h3>
  <table class="table-sm">
    <tr><td>Estimate</td><td class="num">₹<?= money($job['estimate_cost']) ?></td></tr>
    <tr><td>Final charge</td><td class="num">₹<?= money($job['final_charge']) ?></td></tr>
    <tr><td>Advance paid</td><td class="num">₹<?= money($job['advance']) ?></td></tr>
    <tr style="border-top:1px solid var(--border)"><td><strong>Balance</strong></td><td class="num"><strong>₹<?= money(max(0, $job['final_charge'] - $job['advance'])) ?></strong></td></tr>
  </table>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
