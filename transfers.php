<?php
// Multi-warehouse transfers - a focused view over handovers.type='transfer'
// (location -> location stock moves). The actual create/accept/cancel logic
// already lives in handover.php (deduct at creation, add at OTP-accept,
// restore on cancel) - this page just gives location-to-location transfers
// their own clear list instead of being buried among staff issue/return
// handovers, and links straight into handover.php for the real actions.
require_once __DIR__ . '/includes/init.php';
require_perm('handover.view');
$u = current_user();

list($scope, $params) = own_scope('handover', 'h.created_by');
$transfers = all("SELECT h.*, l.name loc_name, l2.name to_loc_name FROM handovers h
                   JOIN locations l ON l.id = h.location_id
                   LEFT JOIN locations l2 ON l2.id = h.to_location_id
                   WHERE h.type = 'transfer' $scope ORDER BY h.id DESC LIMIT 300", $params);
$page_title = 'Warehouse Transfers';
include __DIR__ . '/includes/header.php';
?>
<div class="page-actions">
  <?php if (can('handover.add')): ?><a class="btn" href="handover.php?action=new&type=transfer">+ New Transfer</a><?php endif; ?>
</div>
<div class="table-wrap">
<table>
  <thead><tr><th>No</th><th>From</th><th>To</th><th>Status</th><th>Date</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($transfers as $h): ?>
    <tr>
      <td><strong><?= e($h['handover_no']) ?></strong></td>
      <td><?= e($h['loc_name']) ?></td>
      <td><?= e($h['to_loc_name']) ?></td>
      <td><?= status_badge($h['status']) ?></td>
      <td><?= dmyt($h['created_at']) ?></td>
      <td><a class="btn btn-sm btn-outline" href="handover.php?action=view&id=<?= $h['id'] ?>">View</a></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$transfers): ?><tr><td colspan="6" class="muted">No warehouse transfers yet.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
