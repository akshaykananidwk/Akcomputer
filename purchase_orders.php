<?php
// 📝 Purchase orders: what we want from a supplier, sent as a link on
// WhatsApp; the supplier presses "OK" on their own page. A PO changes no
// stock and no money - the purchase bill does that when the goods arrive.
require_once __DIR__ . '/includes/init.php';
require_perm('purchases.view');
$u = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'create') {
    require_perm('purchases.add');
    $party = row("SELECT id, name, mobile FROM parties WHERE id = ? AND type IN ('supplier','both')", [(int)post('party_id')]);
    $lines = [];
    foreach ((array)post('item_id', []) as $i => $iid) {
        $q = (float)(post('qty', [])[$i] ?? 0);
        if ((int)$iid && $q > 0) $lines[] = [(int)$iid, $q, max(0, (float)(post('price', [])[$i] ?? 0))];
    }
    if (!$party || !$lines) { flash('Pick the supplier and at least one item.', 'error'); redirect('purchase_orders.php?action=new'); }
    $pdo = db(); $pdo->beginTransaction();
    $no = 'PO-' . date('ym') . '-' . str_pad((string)((int)val("SELECT COUNT(*) FROM purchase_orders WHERE po_no LIKE ?", ['PO-' . date('ym') . '-%']) + 1), 3, '0', STR_PAD_LEFT);
    q('INSERT INTO purchase_orders (po_no, party_id, notes, share_token, created_by) VALUES (?,?,?,?,?)', [$no, $party['id'], mb_substr((string)post('notes'), 0, 255), share_token(), $u['id']]);
    $poId = insert_id();
    foreach ($lines as [$iid, $q, $pr]) q('INSERT INTO purchase_order_items (po_id, item_id, qty, price) VALUES (?,?,?,?)', [$poId, $iid, $q, $pr]);
    $pdo->commit();
    log_activity('po_create', $no . ' ' . $party['name']);
    $msg = "🧾 Purchase order $no from " . setting('app_name') . "\n" . count($lines) . " item(s). Please open and press OK:\n" . supplier_portal_url($party['id']);
    if (post('send') && $party['mobile'] !== '') { try { send_whatsapp($party['mobile'], $msg); flash("$no sent to {$party['name']} on WhatsApp."); } catch (Throwable $e) { flash("$no saved; WhatsApp did not go — share the link instead.", 'error'); } }
    else flash("$no saved.");
    redirect('purchase_orders.php');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(post('do'), ['received', 'cancelled'], true)) {
    require_perm('purchases.add');
    q("UPDATE purchase_orders SET status = ? WHERE id = ? AND status IN ('sent','accepted')", [post('do'), (int)post('id')]);
    log_activity('po_' . post('do'), '#' . (int)post('id'));
    if (post('do') === 'received') { flash('Marked received — now write the purchase bill so stock and the supplier account update.'); redirect('purchases.php?action=new'); }
    redirect('purchase_orders.php');
}

$page_title = 'Purchase orders';
include __DIR__ . '/includes/header.php';
if (get('action') === 'new'):
    require_perm('purchases.add');
    $sup = all("SELECT id, name FROM parties WHERE type IN ('supplier','both') AND is_active = 1 ORDER BY name");
    $items = all("SELECT id, name, purchase_price FROM items WHERE is_active = 1 AND item_type = 'product' ORDER BY name"); ?>
<div class="page-head"><h1>📝 New purchase order</h1></div>
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="do" value="create">
  <div class="field"><label>Supplier</label><select name="party_id" required><option value="">—</option><?php foreach ($sup as $s): ?><option value="<?= (int)$s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
  <datalist id="poItems"><?php foreach ($items as $i): ?><option value="<?= e($i['name']) ?>" data-id="<?= (int)$i['id'] ?>" data-p="<?= +$i['purchase_price'] ?>"></option><?php endforeach; ?></datalist>
  <div id="poLines"></div>
  <button type="button" class="btn btn-sm btn-outline" onclick="poLine()">➕ Another item</button>
  <div class="field mt"><label>Note to the supplier</label><input type="text" name="notes" maxlength="255" placeholder="Deliver by Saturday"></div>
  <label class="check-inline"><input type="checkbox" name="send" value="1" checked> Send on WhatsApp now</label>
  <div class="mt"><button class="btn" type="submit">💾 Save PO</button></div>
</form>
<script>
var ITEMS = {}; document.querySelectorAll('#poItems option').forEach(function (o) { ITEMS[o.value] = [o.dataset.id, o.dataset.p]; });
function poLine() {
  var d = document.createElement('div'); d.style.cssText = 'display:grid;grid-template-columns:minmax(0,1fr) 64px 84px;gap:6px;margin:6px 0';
  d.innerHTML = '<input list="poItems" placeholder="Item" required style="min-width:0;width:100%"><input type="number" name="qty[]" min="0" step="any" value="1" placeholder="Qty" style="min-width:0;width:100%"><input type="number" name="price[]" min="0" step="any" placeholder="Rate" style="min-width:0;width:100%"><input type="hidden" name="item_id[]">';
  var n = d.children[0], h = d.children[3], pr = d.children[2];
  n.addEventListener('change', function () { var x = ITEMS[n.value]; h.value = x ? x[0] : ''; if (x && !pr.value) pr.value = x[1]; n.setCustomValidity(x ? '' : 'Pick an item from the list'); });
  document.getElementById('poLines').appendChild(d);
}
poLine();
</script>
<?php else:
  $pos = all('SELECT po.*, p.name party, (SELECT COUNT(*) FROM purchase_order_items WHERE po_id = po.id) n FROM purchase_orders po JOIN parties p ON p.id = po.party_id ORDER BY po.id DESC LIMIT 100'); ?>
<div class="page-head"><h1>📝 Purchase orders</h1><?php if (can('purchases.add')): ?><a class="btn" href="purchase_orders.php?action=new">➕ New PO</a><?php endif; ?></div>
<div class="pane"><div class="pane-body tight"><table class="rowlist"><thead><tr><th>PO</th><th>Supplier</th><th class="num">Items</th><th>Status</th><th>Supplier says</th><th class="act"></th></tr></thead><tbody>
<?php foreach ($pos as $po): ?>
  <tr><td data-l="PO"><b><?= e($po['po_no']) ?></b><br><small class="muted"><?= dmy($po['created_at']) ?></small></td><td data-l="Supplier"><?= e($po['party']) ?></td><td class="num" data-l="Items"><?= (int)$po['n'] ?></td>
    <td data-l="Status"><span class="badge badge-<?= ['sent' => 'warn', 'accepted' => 'info', 'received' => 'ok', 'cancelled' => 'bad'][$po['status']] ?? 'info' ?>"><?= e($po['status']) ?></span></td>
    <td data-l="Supplier says"><?= e($po['supplier_note']) ?></td>
    <td class="act"><a class="btn btn-sm btn-outline" target="_blank" href="<?= e(supplier_portal_url($po['party_id'])) ?>">🔗 Link</a>
      <?php if (in_array($po['status'], ['sent', 'accepted'], true) && can('purchases.add')): ?><form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$po['id'] ?>">
        <button class="btn btn-sm" name="do" value="received">📦 Received</button><button class="btn btn-sm btn-outline" name="do" value="cancelled" onclick="return confirm('Cancel this PO?')">✕</button></form><?php endif; ?></td></tr>
<?php endforeach; if (!$pos): ?><tr><td class="muted">No purchase orders yet.</td></tr><?php endif; ?></tbody></table></div></div>
<?php endif; include __DIR__ . '/includes/footer.php'; ?>
