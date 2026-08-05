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
function er_qty($q) { return rtrim(rtrim(number_format((float)$q, 2), '0'), '.'); }

/** Old-vs-new comparison of one edit request against the CURRENT bill:
 *  changed header fields + per-item added/removed/changed lines, so the
 *  admin sees exactly what approving will do before pressing the button. */
function er_diff($req) {
    $p = json_decode($req['payload'], true) ?: [];
    $isPur = $req['doc_type'] === 'purchase';
    $doc = row($isPur ? 'SELECT * FROM purchases WHERE id = ?' : 'SELECT * FROM sales WHERE id = ?', [$req['doc_id']]);
    $out = ['fields' => [], 'items' => [], 'old_sum' => 0.0, 'new_sum' => 0.0, 'same' => 0];
    if (!$doc) return $out;

    // ---- header fields (only the ones the form actually posted) ----
    if (array_key_exists('party_id', $p) && (int)$p['party_id'] !== (int)$doc['party_id']) {
        $oldP = $doc['party_id'] ? (val('SELECT name FROM parties WHERE id = ?', [$doc['party_id']]) ?: '#' . $doc['party_id']) : 'Walk-in';
        $newP = (int)$p['party_id'] ? (val('SELECT name FROM parties WHERE id = ?', [(int)$p['party_id']]) ?: '#' . (int)$p['party_id']) : 'Walk-in';
        $out['fields'][] = ['પાર્ટી', $oldP, $newP];
    }
    if (array_key_exists('discount_val', $p)) {
        $oldT = $doc['discount_type'] ?: 'flat';
        $newT = ($p['discount_type'] ?? $oldT) === 'pct' ? 'pct' : 'flat';
        $oldV = $oldT === 'pct' ? (float)($doc['discount_pct'] ?? 0) : (float)$doc['discount'];
        $newV = (float)$p['discount_val'];
        if ($oldT !== $newT || abs($oldV - $newV) > 0.009) {
            $out['fields'][] = ['ડિસ્કાઉન્ટ', $oldT === 'pct' ? er_qty($oldV) . '%' : '₹' . money($oldV), $newT === 'pct' ? er_qty($newV) . '%' : '₹' . money($newV)];
        }
    }
    $map = $isPur
        ? [['bill_no', 'Bill No', 's'], ['purchase_date', 'તારીખ', 'd'], ['paid', 'ચૂકવેલ', 'n'], ['notes', 'નોંધ', 's']]
        : [['customer_name', 'ગ્રાહક નામ', 's'], ['customer_mobile', 'મોબાઈલ', 's'], ['sale_date', 'તારીખ', 'd'],
           ['due_date', 'Due date', 'd'], ['payment_mode', 'પેમેન્ટ મોડ', 's'], ['paid', 'ચૂકવેલ', 'n'],
           ['shipping', 'શિપિંગ', 'n'], ['adjustment', 'એડજસ્ટમેન્ટ', 'n'], ['notes', 'નોંધ', 's']];
    foreach ($map as [$k, $label, $type]) {
        if (!array_key_exists($k, $p) || !array_key_exists($k, $doc)) continue;
        $old = $doc[$k]; $new = $p[$k];
        if ($type === 'n') {
            if (abs((float)$old - (float)$new) <= 0.009) continue;
            $out['fields'][] = [$label, '₹' . money($old), '₹' . money($new)];
        } elseif ($type === 'd') {
            $o = $old ? date('Y-m-d', strtotime($old)) : ''; $n = $new ? date('Y-m-d', strtotime($new)) : '';
            if ($o === $n) continue;
            $out['fields'][] = [$label, $o ? dmy($o) : '—', $n ? dmy($n) : '—'];
        } else {
            if (trim((string)$old) === trim((string)$new)) continue;
            $out['fields'][] = [$label, trim((string)$old) !== '' ? (string)$old : '—', trim((string)$new) !== '' ? (string)$new : '—'];
        }
    }

    // ---- items: old rows vs proposed rows, keyed by item ----
    $lineTbl = $isPur ? 'purchase_items' : 'sale_items';
    $fk = $isPur ? 'purchase_id' : 'sale_id';
    $old = [];
    foreach (all("SELECT li.item_id, SUM(li.qty) qty, MAX(li.price) price,
                         COALESCE(MAX(i.name), CONCAT('Item #', li.item_id)) name
                  FROM $lineTbl li LEFT JOIN items i ON i.id = li.item_id WHERE li.$fk = ? GROUP BY li.item_id", [$doc['id']]) as $r) {
        $old[(int)$r['item_id']] = ['qty' => (float)$r['qty'], 'price' => (float)$r['price'], 'name' => $r['name']];
        $out['old_sum'] += $r['qty'] * $r['price'];
    }
    $new = [];
    foreach ((array)($p['item_id'] ?? []) as $i => $iid) {
        $iid = (int)$iid;
        $qty = (float)(((array)($p['qty'] ?? []))[$i] ?? 0);
        if (!$iid || $qty <= 0) continue;
        $price = (float)(((array)($p['price'] ?? []))[$i] ?? 0);
        if (!isset($new[$iid])) $new[$iid] = ['qty' => 0.0, 'price' => $price];
        $new[$iid]['qty'] += $qty;
        $out['new_sum'] += $qty * $price;
    }
    foreach ($new as $iid => $nl) {
        $name = $old[$iid]['name'] ?? (val('SELECT name FROM items WHERE id = ?', [$iid]) ?: 'Item #' . $iid);
        if (!isset($old[$iid])) {
            $out['items'][] = ['add', e($name) . ' × ' . er_qty($nl['qty']) . ' @ ₹' . money($nl['price'])];
        } elseif (abs($old[$iid]['qty'] - $nl['qty']) > 0.009 || abs($old[$iid]['price'] - $nl['price']) > 0.009) {
            $ch = [];
            if (abs($old[$iid]['qty'] - $nl['qty']) > 0.009) $ch[] = 'જથ્થો ' . er_qty($old[$iid]['qty']) . ' → ' . er_qty($nl['qty']);
            if (abs($old[$iid]['price'] - $nl['price']) > 0.009) $ch[] = 'ભાવ ₹' . money($old[$iid]['price']) . ' → ₹' . money($nl['price']);
            $out['items'][] = ['chg', e($name) . ': ' . implode(' · ', $ch)];
        } else {
            $out['same']++;
        }
    }
    foreach ($old as $iid => $ol) {
        if (!isset($new[$iid])) $out['items'][] = ['del', e($ol['name']) . ' × ' . er_qty($ol['qty']) . ' @ ₹' . money($ol['price'])];
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
  <?php foreach ($pending as $req): $doc = er_doc($req); $diff = er_diff($req); ?>
  <div class="list-row" style="display:block;cursor:default;border:1px solid rgba(128,128,128,.25);border-radius:12px;padding:12px;margin-bottom:10px">
    <div><strong><?= $req['doc_type'] === 'purchase' ? '📦 Purchase' : '🧾 Sale' ?>
      <?php if ($doc): ?><a href="<?= e($doc['url']) ?>"><?= e($doc['no']) ?></a> · <?= e($doc['party']) ?> · હાલ ₹<?= money($doc['total']) ?><?php else: ?>#<?= (int)$req['doc_id'] ?> (bill deleted?)<?php endif; ?></strong>
      <div class="muted list-row-sub"><?= e($req['requester']) ?> માંગે છે · <?= dmyt($req['created_at']) ?></div></div>

    <?php if ($diff['fields'] || $diff['items']): ?>
    <div style="margin-top:10px;background:var(--bg);border-radius:10px;padding:10px 12px;font-size:13.5px">
      <strong>🔍 શું બદલાય છે?</strong>
      <?php if ($diff['fields']): ?>
      <table class="table-sm" style="margin-top:6px">
        <thead><tr><th></th><th>જૂનું</th><th>નવું</th></tr></thead>
        <tbody>
        <?php foreach ($diff['fields'] as $f): ?>
        <tr><td class="muted"><?= e($f[0]) ?></td>
            <td style="color:var(--bad);text-decoration:line-through"><?= e($f[1]) ?></td>
            <td style="color:var(--ok);font-weight:700"><?= e($f[2]) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
      <?php if ($diff['items']): ?>
      <div style="margin-top:6px">
        <?php foreach ($diff['items'] as [$kind, $txt]): ?>
        <div style="margin:2px 0"><?= $kind === 'add' ? '<span style="color:var(--ok);font-weight:700">➕ નવી:</span>' : ($kind === 'del' ? '<span style="color:var(--bad);font-weight:700">➖ કાઢી:</span>' : '<span style="color:#d97706;font-weight:700">✏️ બદલી:</span>') ?> <?= $txt ?></div>
        <?php endforeach; ?>
        <?php if ($diff['same']): ?><div class="muted" style="font-size:12.5px;margin-top:3px">બીજી <?= (int)$diff['same'] ?> આઇટમ એમની એમ જ છે.</div><?php endif; ?>
      </div>
      <?php endif; ?>
      <?php if ($diff['items'] && abs($diff['old_sum'] - $diff['new_sum']) > 0.009): ?>
      <div style="margin-top:6px;font-weight:700">લાઈન ટોટલ: <span style="color:var(--bad);text-decoration:line-through">₹<?= money($diff['old_sum']) ?></span> → <span style="color:var(--ok)">₹<?= money($diff['new_sum']) ?></span> <span class="muted" style="font-weight:normal;font-size:12px">(ટેક્સ/ડિસ્કાઉન્ટ પહેલાં)</span></div>
      <?php endif; ?>
    </div>
    <?php else: ?>
    <div class="muted" style="margin-top:8px;font-size:13px">કોઈ દેખીતો ફેરફાર નથી — વિગત હાલના બિલ જેવી જ લાગે છે (કદાચ ફક્ત ફરી-સેવ છે).</div>
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
