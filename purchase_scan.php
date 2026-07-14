<?php
// OCR-assisted purchase entry: upload a photo of a vendor bill, read it via
// a cloud OCR API (includes/ocr.php), and match extracted model-number-like
// text against the existing item catalog. Every unmatched candidate must be
// either added as a new item or explicitly marked "ignore" before the
// matched items can be handed off to the New Purchase form - this keeps
// the item catalog honest instead of letting a scan silently skip products
// the OCR couldn't recognise.
require_once __DIR__ . '/includes/init.php';
require_perm('purchases.add');

$review = null; $scanFile = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'scan') {
    if (empty($_FILES['bill_photo']['tmp_name']) || $_FILES['bill_photo']['error'] !== UPLOAD_ERR_OK) {
        flash('Please choose a bill photo to upload.', 'error');
        redirect('purchase_scan.php');
    }
    $ext = strtolower(pathinfo($_FILES['bill_photo']['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
        flash('Please upload a JPG or PNG photo of the bill.', 'error');
        redirect('purchase_scan.php');
    }
    if ($_FILES['bill_photo']['size'] > 8 * 1024 * 1024) {
        flash('Photo is too large (max 8MB).', 'error');
        redirect('purchase_scan.php');
    }
    $dir = __DIR__ . '/uploads/purchase_scans';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $scanFile = uniqid('scan_') . '.' . $ext;
    move_uploaded_file($_FILES['bill_photo']['tmp_name'], $dir . '/' . $scanFile);

    $text = ocr_extract_text($dir . '/' . $scanFile);
    if ($text === null) {
        flash(setting('ocr_api_key')
            ? 'Could not read the bill photo - try a clearer, well-lit photo, or add items manually.'
            : 'OCR is not set up yet - add an OCR.space API key in Settings > Invoice first.', 'error');
        redirect('purchase_scan.php');
    }
    $tokens = ocr_candidate_tokens($text);
    $review = ocr_match_items($tokens);
    log_activity('purchase_scan', count($tokens) . ' token(s), ' . count(array_filter($review, fn($r) => $r['matches'])) . ' matched');
}

$page_title = 'Scan Purchase Bill';
include __DIR__ . '/includes/header.php';
?>
<div class="card">
  <h2>📷 Scan Purchase Bill</h2>
  <p class="muted mb">Upload a photo of the vendor's bill - the text is read automatically and matched against your item catalog by model number. Anything that can't be matched must be added to your catalog (or explicitly skipped) before you continue.</p>
  <form method="post" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="scan">
    <div class="form-row cols-2">
      <div><label>Bill Photo</label><input type="file" name="bill_photo" accept="image/*" capture="environment" required></div>
    </div>
    <button class="btn mt" type="submit">🔍 Scan Bill</button>
  </form>
</div>

<?php if ($review !== null): ?>
<div class="card">
  <h2>Review Matches</h2>
  <?php if (!$review): ?>
    <p class="muted mb">No model-number-like text was found on this bill. You can still add items manually on the New Purchase screen.</p>
    <a class="btn" href="purchases.php?action=new&bill_photo=<?= e($scanFile) ?>">Continue to New Purchase →</a>
  <?php else: ?>
  <div id="scanRows">
  <?php foreach ($review as $r): if ($r['matches']): ?>
    <div class="list-row scan-row" data-token="<?= e($r['token']) ?>" style="cursor:default;flex-wrap:wrap">
      <div class="list-row-main" style="flex:1 1 100%">
        <label class="check-inline"><input type="checkbox" class="scan-include" checked> <strong><?= e($r['token']) ?></strong></label>
        <select class="scan-item-select mt" style="width:100%">
          <?php foreach ($r['matches'] as $m): ?>
          <option value="<?= $m['id'] ?>" data-name="<?= e($m['name']) ?>" data-price="<?= (float)$m['purchase_price'] ?>" data-tax="<?= (float)$m['tax_rate'] ?>">
            <?= e($m['name']) ?><?= $m['model'] ? ' (' . e($m['model']) . ')' : '' ?> - ₹<?= money($m['purchase_price']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="list-row-val">Qty <input type="number" class="scan-qty" value="1" min="0.01" step="any" style="width:70px"></div>
    </div>
  <?php else: ?>
    <div class="list-row scan-row scan-unmatched" data-token="<?= e($r['token']) ?>" style="cursor:default;background:var(--warn-soft)">
      <div class="list-row-main">
        <strong>"<?= e($r['token']) ?>"</strong> <span class="muted">- not found in your item catalog</span>
        <div class="mt">
          <a class="btn btn-sm btn-outline" href="items.php?action=new&model=<?= e(rawurlencode($r['token'])) ?>" target="_blank">+ Add New Item</a>
          <button type="button" class="btn btn-sm btn-outline scan-recheck">🔄 Re-check</button>
          <label class="check-inline"><input type="checkbox" class="scan-ignore"> Not a product, ignore</label>
        </div>
      </div>
    </div>
  <?php endif; endforeach; ?>
  </div>
  <p class="muted mt" id="scanBlockedMsg">⚠️ Resolve every unmatched item above (add it, then Re-check, or mark "ignore") before continuing.</p>
  <button class="btn mt" type="button" id="scanContinueBtn" disabled onclick="scanContinue()">Continue to New Purchase →</button>
  <?php endif; ?>
</div>
<script>
var SCAN_FILE = <?= json_encode($scanFile) ?>;
function scanUpdateState() {
  var blocked = false;
  document.querySelectorAll('.scan-unmatched').forEach(function (row) {
    var ig = row.querySelector('.scan-ignore');
    if (!ig || !ig.checked) blocked = true;
  });
  document.getElementById('scanContinueBtn').disabled = blocked;
  document.getElementById('scanBlockedMsg').style.display = blocked ? '' : 'none';
}
document.getElementById('scanRows').addEventListener('change', function (ev) {
  if (ev.target.classList.contains('scan-ignore')) scanUpdateState();
});
document.getElementById('scanRows').addEventListener('click', function (ev) {
  var btn = ev.target.closest('.scan-recheck');
  if (!btn) return;
  var row = btn.closest('.scan-row');
  var token = row.dataset.token;
  btn.disabled = true; btn.textContent = 'Checking…';
  fetch('ajax.php?a=ocr_recheck&token=' + encodeURIComponent(token)).then(function (r) { return r.json(); }).then(function (d) {
    if (!d.matches || !d.matches.length) {
      btn.disabled = false; btn.textContent = '🔄 Re-check';
      alert('Still not found - make sure you saved the new item, then try again (or check "ignore").');
      return;
    }
    var opts = d.matches.map(function (m) {
      var label = m.name + (m.model ? ' (' + m.model + ')' : '') + ' - ₹' + m.purchase_price;
      return '<option value="' + m.id + '" data-name="' + m.name.replace(/"/g, '&quot;') + '" data-price="' + m.purchase_price + '" data-tax="' + m.tax_rate + '">' + label + '</option>';
    }).join('');
    row.classList.remove('scan-unmatched');
    row.style.background = '';
    row.innerHTML = '<div class="list-row-main" style="flex:1 1 100%"><label class="check-inline"><input type="checkbox" class="scan-include" checked> <strong>' + token + '</strong></label>' +
      '<select class="scan-item-select mt" style="width:100%">' + opts + '</select></div>' +
      '<div class="list-row-val">Qty <input type="number" class="scan-qty" value="1" min="0.01" step="any" style="width:70px"></div>';
    scanUpdateState();
  });
});
scanUpdateState();
function scanContinue() {
  var items = [];
  document.querySelectorAll('.scan-row').forEach(function (row) {
    var inc = row.querySelector('.scan-include');
    if (!inc || !inc.checked) return;
    var sel = row.querySelector('.scan-item-select');
    var qty = row.querySelector('.scan-qty');
    var opt = sel.options[sel.selectedIndex];
    items.push({id: sel.value, name: opt.dataset.name, price: opt.dataset.price, tax: opt.dataset.tax, qty: qty.value});
  });
  if (!items.length) { alert('Select at least one item to add.'); return; }
  sessionStorage.setItem('reorderItems', JSON.stringify(items));
  location = 'purchases.php?action=new&reorder=1&bill_photo=' + encodeURIComponent(SCAN_FILE);
}
</script>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
