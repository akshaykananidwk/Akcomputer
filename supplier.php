<?php
// 🤝 A supplier's own page, opened from the link the shop sends them: their
// bills with this shop, what has been paid, what is still due, and the
// purchase orders waiting for their "OK". No login - the link is signed for
// this one supplier and shows nothing about anyone else.
require_once __DIR__ . '/includes/init.php';
$pid = (int)get('p');
if (!api_rate_ok('supplier:' . client_ip(), 60, 300) || !portal_ok('supplier', $pid, get('s'))) { http_response_code(404); exit('This link is not valid. Please ask the shop for a new one.'); }
$party = row("SELECT id, name FROM parties WHERE id = ? AND type IN ('supplier','both')", [$pid]);
if (!$party) { http_response_code(404); exit('This link is not valid.'); }
$self = 'supplier.php?p=' . $pid . '&s=' . urlencode(get('s'));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(post('do'), ['accept', 'decline'], true)) {
    $po = row("SELECT * FROM purchase_orders WHERE id = ? AND party_id = ? AND status = 'sent'", [(int)post('po'), $pid]);
    if ($po) {
        q('UPDATE purchase_orders SET status = ?, supplier_note = ?, accepted_at = NOW() WHERE id = ?',
          [post('do') === 'accept' ? 'accepted' : 'cancelled', mb_substr(trim((string)post('note')), 0, 255), $po['id']]);
        owner_alert('🧾 ' . $party['name'] . (post('do') === 'accept' ? ' accepted ' : ' declined ') . 'PO ' . $po['po_no'] . (post('note') ? ' — ' . mb_substr(post('note'), 0, 100) : ''));
    }
    redirect($self);
}

$bal = party_balance($pid);
$bills = all('SELECT id, bill_no, purchase_date, total, paid FROM purchases WHERE party_id = ? AND is_cancelled = 0 ORDER BY purchase_date DESC, id DESC LIMIT 50', [$pid]);
$pays = all("SELECT pay_date, amount, mode FROM payments WHERE party_id = ? AND direction = 'out' ORDER BY pay_date DESC, id DESC LIMIT 30", [$pid]);
$pos = all("SELECT * FROM purchase_orders WHERE party_id = ? AND status IN ('sent','accepted') ORDER BY id DESC LIMIT 20", [$pid]);
$shop = setting('app_name', '');
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">
<title><?= e($party['name']) ?> — <?= e($shop) ?></title>
<style>
body{margin:0;font:15px/1.45 system-ui,-apple-system,'Segoe UI',Roboto,'Noto Sans Gujarati',sans-serif;background:#f1f5f9;color:#0f172a}
.w{max-width:720px;margin:0 auto;padding:16px}.c{background:#fff;border-radius:12px;padding:14px;margin:10px 0;box-shadow:0 1px 3px rgba(0,0,0,.06)}
h1{font-size:20px;margin:4px 0}h2{font-size:16px;margin:0 0 8px}table{width:100%;border-collapse:collapse}td,th{padding:7px 4px;border-bottom:1px solid #eef2f7;text-align:left}
.n{text-align:right;font-variant-numeric:tabular-nums}.big{font-size:26px;font-weight:800}.ok{color:#047857}.due{color:#b91c1c}
button{padding:9px 14px;border-radius:8px;border:1px solid #1a56db;background:#1a56db;color:#fff;font:inherit}button.o{background:#fff;color:#1a56db}input{padding:8px;border:1px solid #cbd5e1;border-radius:8px;font:inherit;width:100%;box-sizing:border-box;margin:6px 0}
</style></head><body><div class="w">
<div class="muted"><?= e($shop) ?></div><h1>👋 <?= e($party['name']) ?></h1>
<div class="c"><div><?= $bal < -0.009 ? 'We owe you' : ($bal > 0.009 ? 'You owe us' : 'All settled') ?></div>
  <div class="big <?= $bal < -0.009 ? 'due' : 'ok' ?>">₹<?= money(abs($bal)) ?></div></div>
<?php if ($pos): ?><div class="c"><h2>🧾 Purchase orders</h2>
<?php foreach ($pos as $po): $lines = all('SELECT pi.qty, pi.price, i.name FROM purchase_order_items pi JOIN items i ON i.id = pi.item_id WHERE pi.po_id = ?', [$po['id']]); ?>
  <div style="border:1px solid #e2e8f0;border-radius:10px;padding:10px;margin:8px 0"><b><?= e($po['po_no']) ?></b> · <?= dmy($po['created_at']) ?> · <?= $po['status'] === 'accepted' ? '<span class="ok">✔ accepted</span>' : 'waiting for you' ?>
    <table><?php foreach ($lines as $l): ?><tr><td><?= e($l['name']) ?></td><td class="n"><?= +$l['qty'] ?></td><td class="n"><?= $l['price'] > 0 ? '₹' . money($l['price']) : '' ?></td></tr><?php endforeach; ?></table>
    <?php if ($po['notes'] !== ''): ?><p><?= e($po['notes']) ?></p><?php endif; ?>
    <?php if ($po['status'] === 'sent'): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="po" value="<?= (int)$po['id'] ?>">
      <input type="text" name="note" placeholder="Note to the shop (delivery date, price change…)" maxlength="255">
      <button name="do" value="accept">✔ OK, will send</button> <button class="o" name="do" value="decline">Can't supply</button></form><?php endif; ?></div>
<?php endforeach; ?></div><?php endif; ?>
<div class="c"><h2>Your bills with us</h2><table><tr><th>Bill</th><th>Date</th><th class="n">Amount</th><th class="n">Paid</th></tr>
<?php foreach ($bills as $b): ?><tr><td><?= e($b['bill_no'] ?: '#' . $b['id']) ?></td><td><?= dmy($b['purchase_date']) ?></td><td class="n">₹<?= money($b['total']) ?></td><td class="n">₹<?= money($b['paid']) ?></td></tr><?php endforeach; ?>
<?php if (!$bills): ?><tr><td colspan="4">No bills yet.</td></tr><?php endif; ?></table></div>
<div class="c"><h2>Payments made to you</h2><table>
<?php foreach ($pays as $p): ?><tr><td><?= dmy($p['pay_date']) ?></td><td><?= e(strtoupper($p['mode'])) ?></td><td class="n">₹<?= money($p['amount']) ?></td></tr><?php endforeach; ?>
<?php if (!$pays): ?><tr><td>No payments yet.</td></tr><?php endif; ?></table></div>
</div></body></html>
