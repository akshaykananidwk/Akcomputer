<?php
// A restaurant's tables. An open table is a parked bill labelled "Table N":
// the order is added and sent to the kitchen (a KOT slip), and when the
// guests are done it is turned into the real bill. Nothing here touches
// stock or money - that only happens when the bill is saved in sales.php.
require_once __DIR__ . '/includes/init.php';
require_perm('sales.add');
if (!biz_on('biz_tables')) { flash('Tables are for restaurants — switch them on in Settings → Type of business.', 'info'); redirect('sales.php'); }

// ---- the kitchen slip: what was ordered at a table ----
if ((int)get('kot')) {
    $pb = row('SELECT * FROM parked_bills WHERE id = ?', [(int)get('kot')]);
    if (!$pb) redirect('tables.php');
    $items = json_decode($pb['payload'], true)['items'] ?? []; ?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>KOT <?= e($pb['label']) ?></title>
<style>body{font:15px/1.4 monospace;max-width:300px;margin:10px auto;color:#000;background:#fff}h2{text-align:center;margin:4px 0}
table{width:100%;border-collapse:collapse}td{padding:3px 0;border-bottom:1px dashed #999;vertical-align:top}.q{text-align:right;font-weight:bold;width:40px}
.np{margin-top:14px;display:flex;gap:8px}.np a,.np button{flex:1;padding:10px;font:inherit;text-align:center;border:1px solid #333;background:#fff;color:#000;text-decoration:none;border-radius:6px}
@media print{.np{display:none}}</style></head><body>
<h2>KITCHEN — <?= e($pb['label']) ?></h2>
<div style="text-align:center"><?= date('d-m-Y h:i A') ?></div>
<table><?php foreach ($items as $it): ?><tr><td><?= e($it['name']) ?><?= $it['description'] !== '' ? '<br><small>' . e($it['description']) . '</small>' : '' ?></td><td class="q">× <?= +$it['qty'] ?></td></tr><?php endforeach; ?></table>
<div class="np"><button onclick="print()">🖨️ Print</button><a href="tables.php">Tables</a></div>
<script>if (!location.hash) { location.hash = 'p'; setTimeout(print, 300); }</script>
</body></html>
<?php exit; }

$count = max(1, min(100, (int)setting('biz_table_count', '10')));
$open = [];
foreach (all("SELECT * FROM parked_bills WHERE label LIKE 'Table %' ORDER BY id") as $pb)
    if (preg_match('/^Table (\d+)$/', $pb['label'], $m)) $open[(int)$m[1]] = $pb;

$page_title = 'Tables';
include __DIR__ . '/includes/header.php';
?>
<div class="page-head"><h1>🍽️ Tables</h1><span class="muted"><?= count($open) ?> of <?= $count ?> busy</span></div>
<style>
.tbl-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px}
.tbl{border:1px solid var(--border,#ddd);border-radius:10px;padding:12px;background:var(--card,#fff);display:flex;flex-direction:column;gap:6px}
.tbl.busy{border-color:#f59e0b;background:#fffbeb}
.tbl-n{font-size:20px;font-weight:700}.tbl .btn{width:100%;text-align:center}
.tbl-split{display:flex;gap:4px;align-items:center;font-size:12px}.tbl-split input{width:48px;padding:4px}
</style>
<div class="tbl-grid">
<?php for ($n = 1; $n <= $count; $n++): $pb = $open[$n] ?? null; ?>
  <div class="tbl<?= $pb ? ' busy' : '' ?>">
    <div class="tbl-n"><?= $n ?></div>
    <?php if ($pb): ?>
      <div>₹<?= money($pb['amount']) ?> · <?= (int)$pb['items'] ?> item<?= (int)$pb['items'] === 1 ? '' : 's' ?></div>
      <div class="muted" style="font-size:12px">since <?= date('h:i A', strtotime($pb['created_at'])) ?></div>
      <a class="btn btn-sm" href="sales.php?action=new&amp;park=<?= (int)$pb['id'] ?>&amp;table=<?= $n ?>">➕ Add / 🧾 Bill</a>
      <a class="btn btn-sm btn-outline" href="tables.php?kot=<?= (int)$pb['id'] ?>">🍳 KOT</a>
      <label class="tbl-split">Split in <input type="number" min="1" max="50" value="1" data-amt="<?= (float)$pb['amount'] ?>"
        oninput="this.nextElementSibling.textContent = this.value > 1 ? '= ₹' + (this.dataset.amt / this.value).toFixed(2) + ' each' : ''"> <span></span></label>
    <?php else: ?>
      <div class="muted">Free</div>
      <a class="btn btn-sm btn-outline" href="sales.php?action=new&amp;table=<?= $n ?>">➕ New order</a>
    <?php endif; ?>
  </div>
<?php endfor; ?>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
