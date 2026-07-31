<?php
// Bill-edit approval queue (admin only). Staff may edit a sale/purchase
// freely for 24 hours after it was created; older bills' edits land here as
// a stored copy of the exact form submission. Approving REPLAYS that form
// data through sales.php / purchases.php as the admin, so the apply logic
// (stock, serials, payments, totals) is byte-for-byte the same as a normal
// edit and can never drift from it.
require_once __DIR__ . '/includes/init.php';
require_login();
if (!is_full_admin()) {
    http_response_code(403);
    include __DIR__ . '/includes/header.php';
    echo '<div class="card"><h2>Access denied</h2><p>Only the admin can review edit approvals.</p></div>';
    include __DIR__ . '/includes/footer.php';
    exit;
}
$u = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'decide') {
    $req = row("SELECT * FROM edit_requests WHERE id = ? AND status = 'pending'", [(int)post('id')]);
    if (!$req) { flash('Request not found or already decided.', 'error'); redirect('approvals.php'); }
    if (post('decision') === 'reject') {
        q("UPDATE edit_requests SET status = 'rejected', decided_by = ?, decided_at = NOW() WHERE id = ?", [$u['id'], $req['id']]);
        log_activity('edit_request_reject', $req['doc_type'] . ' #' . $req['doc_id']);
        flash('Edit request rejected — the bill stays as it is.');
        redirect('approvals.php');
    }
    // approve: mark first, then replay the stored form POST through the real
    // save script (it redirects to the bill view itself when done)
    q("UPDATE edit_requests SET status = 'approved', decided_by = ?, decided_at = NOW() WHERE id = ?", [$u['id'], $req['id']]);
    log_activity('edit_request_approve', $req['doc_type'] . ' #' . $req['doc_id']);
    flash('Edit request approved — applying the change…');
    $_POST = json_decode($req['payload'], true) ?: [];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    include __DIR__ . '/' . ($req['doc_type'] === 'purchase' ? 'purchases.php' : 'sales.php');
    exit; // the included script redirects; this is only a safety net
}

$pending = all("SELECT er.*, u2.name requester FROM edit_requests er JOIN users u2 ON u2.id = er.requested_by
                WHERE er.status = 'pending' ORDER BY er.id");
$decided = all("SELECT er.*, u2.name requester, u3.name decider FROM edit_requests er
                JOIN users u2 ON u2.id = er.requested_by LEFT JOIN users u3 ON u3.id = er.decided_by
                WHERE er.status <> 'pending' ORDER BY er.decided_at DESC LIMIT 10");

// resolve doc labels + a readable summary of the proposed lines
function er_doc($req) {
    if ($req['doc_type'] === 'purchase') {
        $d = row('SELECT p.*, pa.name party FROM purchases p LEFT JOIN parties pa ON pa.id = p.party_id WHERE p.id = ?', [$req['doc_id']]);
        return $d ? ['no' => $d['bill_no'] ?: ('#' . $d['id']), 'party' => $d['party'] ?: '-', 'total' => $d['total'], 'url' => 'purchase_view.php?id=' . $d['id']] : null;
    }
    $d = row('SELECT s.*, pa.name party FROM sales s LEFT JOIN parties pa ON pa.id = s.party_id WHERE s.id = ?', [$req['doc_id']]);
    return $d ? ['no' => $d['invoice_no'], 'party' => $d['party'] ?: ($d['customer_name'] ?: 'Walk-in'), 'total' => $d['total'], 'url' => 'sale_view.php?id=' . $d['id']] : null;
}
function er_lines($req) {
    $p = json_decode($req['payload'], true) ?: [];
    $out = [];
    foreach ((array)($p['item_id'] ?? []) as $i => $iid) {
        $iid = (int)$iid;
        $qty = (float)(((array)($p['qty'] ?? []))[$i] ?? 0);
        if (!$iid || $qty <= 0) continue;
        $name = val('SELECT name FROM items WHERE id = ?', [$iid]) ?: ('Item #' . $iid);
        $price = (float)(((array)($p['price'] ?? []))[$i] ?? 0);
        $out[] = e($name) . ' × ' . rtrim(rtrim(number_format($qty, 2), '0'), '.') . ' @ ₹' . money($price);
    }
    return $out;
}

$page_title = 'Edit Approvals';
include __DIR__ . '/includes/header.php';
?>
<div class="card">
  <h2>⏳ Pending bill-edit approvals</h2>
  <p class="muted" style="font-size:13px">24 કલાકથી જૂના બિલમાં સ્ટાફે કરેલા ફેરફાર અહીં આવે છે — Approve કરો એટલે ફેરફાર બિલમાં લાગુ થઈ જાય, Reject કરો એટલે બિલ જેમ છે એમ જ રહે.</p>
  <?php if (!$pending): ?><p class="muted">🎉 કોઈ મંજૂરી બાકી નથી.</p><?php endif; ?>
  <?php foreach ($pending as $req): $doc = er_doc($req); $lines = er_lines($req); ?>
  <div class="list-row" style="display:block;cursor:default;border:1px solid rgba(128,128,128,.25);border-radius:12px;padding:12px;margin-bottom:10px">
    <div><strong><?= $req['doc_type'] === 'purchase' ? '📦 Purchase' : '🧾 Sale' ?>
      <?php if ($doc): ?><a href="<?= e($doc['url']) ?>"><?= e($doc['no']) ?></a> · <?= e($doc['party']) ?> · હાલ ₹<?= money($doc['total']) ?><?php else: ?>#<?= (int)$req['doc_id'] ?> (bill deleted?)<?php endif; ?></strong>
      <div class="muted list-row-sub"><?= e($req['requester']) ?> માંગે છે · <?= dmyt($req['created_at']) ?></div></div>
    <?php if ($lines): ?>
    <div style="margin-top:8px;font-size:13.5px"><strong>સૂચવેલી લાઈનો:</strong><br><?= implode('<br>', $lines) ?></div>
    <?php endif; ?>
    <div style="display:flex;gap:8px;margin-top:10px;flex-wrap:wrap">
      <form method="post" onsubmit="return confirm('આ ફેરફાર બિલમાં લાગુ કરવો છે?')">
        <?= csrf_field() ?><input type="hidden" name="do" value="decide"><input type="hidden" name="id" value="<?= $req['id'] ?>"><input type="hidden" name="decision" value="approve">
        <button class="btn btn-sm" type="submit">✅ Approve &amp; Apply</button>
      </form>
      <form method="post" onsubmit="return confirm('ફેરફાર નામંજૂર કરવો છે? બિલ જેમ છે એમ રહેશે.')">
        <?= csrf_field() ?><input type="hidden" name="do" value="decide"><input type="hidden" name="id" value="<?= $req['id'] ?>"><input type="hidden" name="decision" value="reject">
        <button class="btn btn-sm btn-outline" type="submit">✕ Reject</button>
      </form>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<?php if ($decided): ?>
<div class="card">
  <h2>Recent decisions</h2>
  <table class="table-sm">
    <thead><tr><th>Bill</th><th>By</th><th>Decision</th><th>When</th></tr></thead>
    <tbody>
    <?php foreach ($decided as $req): $doc = er_doc($req); ?>
    <tr><td><?= $req['doc_type'] === 'purchase' ? '📦' : '🧾' ?> <?= $doc ? '<a href="' . e($doc['url']) . '">' . e($doc['no']) . '</a>' : '#' . (int)$req['doc_id'] ?></td>
        <td><?= e($req['requester']) ?></td>
        <td><?= $req['status'] === 'approved' ? '<span class="badge badge-ok">approved</span>' : '<span class="badge badge-bad">rejected</span>' ?><?= $req['decider'] ? ' by ' . e($req['decider']) : '' ?></td>
        <td><?= dmyt($req['decided_at']) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
