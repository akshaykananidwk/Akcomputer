<?php
// The small till receipt of a bill: print on a 58/80 mm printer, or send to
// a Bluetooth printer from the phone. Same access as the bill itself.
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/receipt.php';
require_perm('sales.view');
$sale = row('SELECT id, created_by FROM sales WHERE id = ?', [(int)get('id')]);
if (!$sale) die('Bill not found.');
if (!can('sales.all') && $sale['created_by'] != current_user()['id']) die('Access denied.');
$lines = receipt_lines($sale['id']);
$mm = receipt_width() === 48 ? 80 : 58;
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Receipt</title>
<style>
@page { size: <?= $mm ?>mm auto; margin: 2mm; }
body { margin: 0; background: #fff; color: #000; }
pre { font: 12px/1.35 'Courier New', monospace; margin: 8px auto; width: <?= $mm - 4 ?>mm; white-space: pre; }
.np { display: flex; gap: 8px; max-width: 360px; margin: 10px auto; padding: 0 10px; }
.np button, .np a { flex: 1; padding: 10px; font: 15px sans-serif; border: 1px solid #333; border-radius: 8px; background: #fff; color: #000; text-align: center; text-decoration: none; }
@media print { .np { display: none; } }
</style></head><body>
<pre id="rcpt"><?= e(implode("\n", $lines)) ?></pre>
<div class="np">
  <button type="button" onclick="print()">🖨️ Print</button>
  <button type="button" id="btPrint">🔵 Bluetooth</button>
  <a href="sale_view.php?id=<?= (int)$sale['id'] ?>">← Bill</a>
</div>
<p id="btMsg" class="np" style="justify-content:center;font:13px sans-serif"></p>
<script src="assets/btprint.js?v=<?= asset_v('btprint.js') ?>"></script>
<script>
document.getElementById('btPrint').addEventListener('click', function () {
  var msg = document.getElementById('btMsg');
  btPrint(document.getElementById('rcpt').textContent, function (t) { msg.textContent = t; }, <?= json_encode(setting('drawer_kick') === '1') ?>);
});
</script>
</body></html>
