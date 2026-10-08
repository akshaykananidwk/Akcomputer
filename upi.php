<?php
// 📲 UPI money that reached the bank (read from the shop phone's SMS).
// Nothing is booked by itself: "Record" opens a normal payment, filled in,
// for a person to check and save; or it is set aside as not a customer.
require_once __DIR__ . '/includes/init.php';
require_perm('payments.add');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'ignore') {
    q("UPDATE upi_inbox SET status = 'ignored' WHERE id = ? AND status = 'new'", [(int)post('id')]);
    redirect('upi.php');
}
$rows = all("SELECT * FROM upi_inbox ORDER BY (status = 'new') DESC, id DESC LIMIT 200");
// a likely customer: same UPI id or name seen on an earlier payment's note, else a party whose name matches
$guess = function ($payer) {
    if ($payer === '') return null;
    $p = row("SELECT p.id, p.name FROM upi_inbox u JOIN parties p ON p.id = u.party_id WHERE u.payer = ? AND u.party_id IS NOT NULL ORDER BY u.id DESC LIMIT 1", [$payer]);
    return $p ?: row('SELECT id, name FROM parties WHERE is_active = 1 AND name LIKE ? LIMIT 1', ['%' . preg_replace('/@.*/', '', $payer) . '%']);
};
$page_title = 'UPI received';
include __DIR__ . '/includes/header.php';
?>
<div class="page-head"><h1>📲 UPI received</h1><a class="btn btn-sm btn-outline" href="connections.php#upi">⚙️ Set up the phone</a></div>
<div class="pane"><div class="pane-body tight"><table class="rowlist"><thead><tr><th>When</th><th class="num">Amount</th><th>From</th><th>Ref</th><th class="act"></th></tr></thead><tbody>
<?php foreach ($rows as $r): $g = $r['status'] === 'new' ? $guess($r['payer']) : null; ?>
  <tr><td data-l="When"><?= dmyt($r['received_at']) ?></td><td class="num" data-l="Amount"><b>₹<?= money($r['amount']) ?></b></td>
    <td data-l="From"><?= e($r['payer']) ?><?= $g ? '<br><small class="muted">maybe ' . e($g['name']) . '</small>' : '' ?></td><td data-l="Ref" class="muted"><?= e($r['ref']) ?></td>
    <td class="act"><?php if ($r['status'] === 'new'): ?>
      <a class="btn btn-sm" href="payments.php?action=new&amp;mode=upi&amp;upi=<?= (int)$r['id'] ?>&amp;amount=<?= +$r['amount'] ?>&amp;notes=<?= rawurlencode('UPI ' . $r['ref'] . ' ' . $r['payer']) ?><?= $g ? '&amp;party=' . (int)$g['id'] : '' ?>">✔ Record</a>
      <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="do" value="ignore"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-sm btn-outline">Not a customer</button></form>
    <?php else: ?><span class="badge badge-<?= $r['status'] === 'recorded' ? 'ok' : 'muted' ?>"><?= e($r['status']) ?></span><?php endif; ?></td></tr>
<?php endforeach; if (!$rows): ?><tr><td class="muted">Nothing yet. Set up the shop phone in Connections.</td></tr><?php endif; ?></tbody></table></div></div>
<?php include __DIR__ . '/includes/footer.php'; ?>
