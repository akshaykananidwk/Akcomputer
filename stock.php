<?php
// Stock overview: location-wise, staff-held, serials, ledger, manual adjust
require_once __DIR__ . '/includes/init.php';
require_perm('stock.view');
$u = current_user();
$action = get('action', 'list');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'adjust') {
    require_perm('stock.adjust');
    $item_id = (int)post('item_id');
    $loc_id = (int)post('location_id');
    $delta = (float)post('delta');
    if ($item_id && $loc_id && $delta != 0) {
        $item = row('SELECT * FROM items WHERE id = ?', [$item_id]);
        // Serial-tracked items: the adjustment must say WHICH serial numbers,
        // so the serial book always matches the quantity book. Adding stock
        // registers the serials as in_stock; reducing marks them adjusted_out.
        $sns = array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', (string)post('serials')))));
        if ($item && $item['serial_tracked']) {
            if (count($sns) != abs($delta)) {
                flash('This item is serial-tracked: enter exactly ' . abs($delta) . ' serial number(s) for the adjustment.', 'error');
                redirect('stock.php');
            }
            foreach ($sns as $sn) {
                $srow = row('SELECT id, status FROM item_serials WHERE item_id=? AND serial_no=?', [$item_id, $sn]);
                if ($delta > 0) {
                    if ($srow && $srow['status'] === 'in_stock') { flash("Serial $sn is already in stock.", 'error'); redirect('stock.php'); }
                    if ($srow) q("UPDATE item_serials SET status='in_stock', location_id=?, sale_id=NULL WHERE id=?", [$loc_id, $srow['id']]);
                    else q("INSERT INTO item_serials (item_id, serial_no, location_id, status, warranty_months) VALUES (?,?,?,'in_stock',?)",
                           [$item_id, $sn, $loc_id, (int)$item['warranty_months']]);
                } else {
                    if (!$srow || $srow['status'] !== 'in_stock') { flash("Serial $sn is not in stock, so it can't be adjusted out.", 'error'); redirect('stock.php'); }
                    q("UPDATE item_serials SET status='adjusted_out', location_id=NULL WHERE id=?", [$srow['id']]);
                }
            }
        }
        adjust_stock($item_id, $loc_id, $delta, 'manual_adjust', null, post('reason'));
        log_activity('stock_adjust', "item=$item_id loc=$loc_id delta=$delta " . post('reason') . ($sns ? ' SN:' . implode(',', $sns) : ''));
        flash('Stock adjusted.' . ($sns ? ' ' . count($sns) . ' serial number(s) updated too.' : ''));
    }
    redirect('stock.php');
}

// a location-locked user (godown/shop manager) sees only their own column
$locations = all('SELECT * FROM locations WHERE is_active = 1' . (locked_location_id() ? ' AND id = ' . locked_location_id() : '') . ' ORDER BY name');

if ($action === 'ledger') {
    $item_id = (int)get('item_id');
    $item = row('SELECT * FROM items WHERE id = ?', [$item_id]);
    $ledger = all('SELECT sl.*, l.name loc_name, u2.name user_name, cb.name by_name FROM stock_ledger sl
                   LEFT JOIN locations l ON l.id = sl.location_id
                   LEFT JOIN users u2 ON u2.id = sl.user_id
                   LEFT JOIN users cb ON cb.id = sl.created_by
                   WHERE sl.item_id = ? ORDER BY sl.id DESC LIMIT 200', [$item_id]);
    $page_title = 'Ledger: ' . ($item['name'] ?? '');
    include __DIR__ . '/includes/header.php';
    ?>
    <div class="page-actions"><a class="btn btn-outline" href="stock.php">← Back to stock</a></div>
    <div class="table-wrap">
    <table>
      <thead><tr><th>Date</th><th>Where</th><th class="num">Change</th><th>Ref</th><th>By</th><th>Note</th></tr></thead>
      <tbody><?php foreach ($ledger as $le): ?>
        <tr>
          <td><?= dmyt($le['created_at']) ?></td>
          <td><?= e($le['loc_name'] ?: ('Staff: ' . $le['user_name'])) ?></td>
          <td class="num" style="color:<?= $le['change_qty'] >= 0 ? 'var(--ok)' : 'var(--bad)' ?>"><?= $le['change_qty'] > 0 ? '+' : '' ?><?= (float)$le['change_qty'] ?></td>
          <td><?= e($le['ref_type']) ?> #<?= e($le['ref_id']) ?></td>
          <td><?= e($le['by_name']) ?></td>
          <td><?= e($le['note']) ?></td>
        </tr>
      <?php endforeach; ?></tbody>
    </table>
    </div>
    <?php
    include __DIR__ . '/includes/footer.php';
    exit;
}

// main stock matrix
$loc_filter = (int)get('loc');
$stockRows = all("SELECT i.id, i.name, i.unit, i.min_stock, i.serial_tracked FROM items i WHERE i.is_active = 1 AND i.item_type <> 'service' ORDER BY i.name");
$stockMap = [];
foreach (all('SELECT * FROM stock') as $s) $stockMap[$s['item_id']][$s['location_id']] = (float)$s['qty'];
$staffHeld = [];
foreach (all('SELECT ss.item_id, SUM(ss.qty) q FROM staff_stock ss GROUP BY ss.item_id') as $s) $staffHeld[$s['item_id']] = (float)$s['q'];
$staffDetail = all('SELECT ss.*, u2.name staff_name, i.name item_name, i.unit FROM staff_stock ss
                    JOIN users u2 ON u2.id = ss.user_id JOIN items i ON i.id = ss.item_id
                    WHERE ss.qty > 0 ORDER BY u2.name, i.name');
// Goods on a pending handover have left the source and not arrived anywhere.
// Without this column they show in no location, under no staff member, and the
// Total lies - which is exactly how "main shop shows 0 and the godown does not
// have it either" got reported from the shop.
$transitRows = stock_in_transit();
$transitHeld = [];
foreach ($transitRows as $t) $transitHeld[(int)$t['item_id']] = ($transitHeld[(int)$t['item_id']] ?? 0) + (float)$t['qty'];

$page_title = 'Stock';
include __DIR__ . '/includes/header.php';
?>
<div class="searchbox" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
  <input type="text" id="sFilter" placeholder="🔍 Search item..." style="flex:1;min-width:180px">
  <label class="check-inline" style="white-space:nowrap"><input type="checkbox" id="hideZero"> Hide zero-stock items</label>
  <label class="check-inline" style="white-space:nowrap"><input type="checkbox" id="onlyNeg"> ⚠️ Only transfer-needed</label>
</div>
<div class="table-wrap">
<table id="sTable">
  <thead><tr><th>Item</th>
  <?php foreach ($locations as $l): ?><th class="num"><?= e($l['code']) ?></th><?php endforeach; ?>
  <th class="num">Staff</th><th class="num" title="પેન્ડિંગ હેન્ડઓવરમાં — કોઈ જગ્યાએ નથી ગણાતો">રસ્તામાં</th><th class="num">Total</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($stockRows as $it):
      $rowTotal = 0;
      // godown sold past zero while the office still has pieces (or the other
      // way round): the negative cell turns red and the row gets a one-tap
      // "Transfer" hint - exactly the "office ma che, godown ma minus" case.
      $hasNeg = false; $hasPos = false;
      foreach ($locations as $l) { $q0 = $stockMap[$it['id']][$l['id']] ?? 0; if ($q0 < 0) $hasNeg = true; if ($q0 > 0) $hasPos = true; }
      $needTransfer = $hasNeg && $hasPos; ?>
    <tr data-neg="<?= $needTransfer ? 1 : 0 ?>">
      <td><?= e($it['name']) ?><?= $it['serial_tracked'] ? ' <span class="badge badge-info">SN</span>' : '' ?>
        <?= $needTransfer ? ' <span class="badge badge-bad">⚠️ TRANSFER</span>' : '' ?></td>
      <?php foreach ($locations as $l): $qv = $stockMap[$it['id']][$l['id']] ?? 0; $rowTotal += $qv; ?>
        <td class="num"<?= $qv < 0 ? ' style="color:var(--bad);font-weight:700"' : '' ?>><?= $qv ?: '·' ?></td>
      <?php endforeach; $sh = $staffHeld[$it['id']] ?? 0; $rowTotal += $sh; ?>
      <td class="num"><?= $sh ?: '·' ?></td>
      <?php $tr = $transitHeld[(int)$it['id']] ?? 0; $rowTotal += $tr; ?>
      <td class="num"><?= $tr ? '<a href="item_view.php?id=' . (int)$it['id'] . '" title="પેન્ડિંગ હેન્ડઓવરમાં">' . rtrim(rtrim(number_format($tr, 2), '0'), '.') . '</a>' : '·' ?></td>
      <td class="num" data-total="<?= $rowTotal ?>"><strong><?= $rowTotal ?></strong>
        <?= $it['min_stock'] > 0 && $rowTotal < $it['min_stock'] ? '<span class="badge badge-bad">LOW</span>' : '' ?></td>
      <td style="white-space:nowrap"><a class="btn btn-sm btn-outline" href="stock.php?action=ledger&item_id=<?= $it['id'] ?>">Ledger</a>
        <?= $needTransfer ? ' <a class="btn btn-sm" href="handover.php?action=new&type=transfer" title="Move stock between locations">→ Transfer</a>' : '' ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<script>
(function () {
  function apply() {
    var hz = document.getElementById('hideZero').checked;
    var on = document.getElementById('onlyNeg').checked;
    document.querySelectorAll('#sTable tbody tr').forEach(function (tr) {
      var tot = parseFloat((tr.querySelector('[data-total]') || {}).dataset ? tr.querySelector('[data-total]').dataset.total : '0') || 0;
      var neg = tr.dataset.neg === '1';
      var hide = (hz && tot === 0 && !neg) || (on && !neg);
      tr.dataset.zhide = hide ? '1' : '0';
      tr.style.display = hide ? 'none' : '';
    });
  }
  document.getElementById('hideZero').addEventListener('change', apply);
  document.getElementById('onlyNeg').addEventListener('change', apply);
})();
</script>

<?php if ($transitRows): ?>
<div class="card">
  <h2>⏳ રસ્તામાં પડેલો માલ <span class="muted" style="font-weight:400;font-size:13px">· સ્વીકારવાનું બાકી</span></h2>
  <p class="muted mb" style="font-size:13px">
    હેન્ડઓવર બનતાં જ માલ મોકલનારી જગ્યામાંથી ઓછો થઈ જાય છે અને સામેવાળો OTP થી સ્વીકારે ત્યારે જ ઉમેરાય છે.
    વચ્ચે એ <b>કોઈ જગ્યાના સ્ટોકમાં ગણાતો નથી</b> — એટલે ખોવાયો નથી, અહીં છે.
  </p>
  <div class="table-wrap" style="box-shadow:none">
  <table class="table-sm">
    <thead><tr><th>હેન્ડઓવર</th><th>વસ્તુ</th><th class="num">નંગ</th><th>ક્યાંથી</th><th>કોની પાસે</th><th class="num">દિવસ</th></tr></thead>
    <tbody><?php foreach ($transitRows as $t): ?>
      <tr>
        <td><a href="handover.php?action=view&id=<?= (int)$t['id'] ?>"><?= e($t['handover_no']) ?></a></td>
        <td><?= e($t['item_name']) ?></td>
        <td class="num"><?= rtrim(rtrim(number_format((float)$t['qty'], 2), '0'), '.') ?></td>
        <td class="muted"><?= e($t['from_loc']) ?></td>
        <td><?= e(transit_destination($t)) ?></td>
        <td class="num <?= days_between(date('Y-m-d', strtotime($t['created_at']))) >= 3 ? 'text-bad' : 'muted' ?>">
          <?= (int)days_between(date('Y-m-d', strtotime($t['created_at']))) ?></td>
      </tr>
    <?php endforeach; ?></tbody>
  </table>
  </div>
</div>
<?php endif; ?>

<?php if ($staffDetail): ?>
<div class="card">
  <h2>🎒 Stock held by staff</h2>
  <div class="table-wrap" style="box-shadow:none">
  <table class="table-sm">
    <thead><tr><th>Staff</th><th>Item</th><th class="num">Qty</th></tr></thead>
    <tbody><?php foreach ($staffDetail as $s): ?>
      <tr><td><?= e($s['staff_name']) ?></td><td><?= e($s['item_name']) ?></td><td class="num"><?= (float)$s['qty'] ?> <?= e($s['unit']) ?></td></tr>
    <?php endforeach; ?></tbody>
  </table>
  </div>
</div>
<?php endif; ?>

<?php if (can('stock.adjust')): ?>
<div class="card">
  <h2>Manual stock adjust</h2>
  <form method="post" class="filterbar">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="adjust">
    <div><label>Item</label>
      <select name="item_id" id="adjItem" onchange="adjSN()"><?php foreach ($stockRows as $it): ?><option value="<?= $it['id'] ?>" data-sn="<?= (int)$it['serial_tracked'] ?>"><?= e($it['name']) ?></option><?php endforeach; ?></select></div>
    <div><label>Location</label>
      <select name="location_id"><?php foreach ($locations as $l): ?><option value="<?= $l['id'] ?>"><?= e($l['name']) ?></option><?php endforeach; ?></select></div>
    <div><label>+/- Qty</label><input type="number" step="any" name="delta" required placeholder="-2 or 5"></div>
    <div><label>Reason</label><input type="text" name="reason" required placeholder="opening / damage / count fix"></div>
    <div id="adjSnBox" style="display:none;flex-basis:100%"><label>Serial numbers (one per line — must match the qty)</label>
      <textarea name="serials" rows="2" placeholder="SN001&#10;SN002"></textarea></div>
    <button class="btn btn-sm" type="submit">Adjust</button>
  </form>
  <script>
    function adjSN() {
      var sel = document.getElementById('adjItem');
      document.getElementById('adjSnBox').style.display = sel.options[sel.selectedIndex].dataset.sn === '1' ? '' : 'none';
    }
    adjSN();
  </script>
</div>
<?php endif; ?>
<script>tableFilter('sFilter', 'sTable');</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
