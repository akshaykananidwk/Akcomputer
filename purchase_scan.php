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

$review = null; $scanFile = null; $doc = null; $aiWhy = '';
// Upload a PDF or a photo (many phones export bills as PDF, and the format
// varies) - not only a live camera shot.
$ALLOWED = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp', 'tif', 'tiff'];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'scan') {
    if (empty($_FILES['bill_photo']['tmp_name']) || $_FILES['bill_photo']['error'] !== UPLOAD_ERR_OK) {
        flash('Please choose a bill file (PDF or photo) to upload.', 'error');
        redirect('purchase_scan.php');
    }
    $ext = strtolower(pathinfo($_FILES['bill_photo']['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $ALLOWED, true)) {
        flash('Upload a PDF or an image (JPG, PNG, WEBP, …) of the bill.', 'error');
        redirect('purchase_scan.php');
    }
    if ($_FILES['bill_photo']['size'] > 15 * 1024 * 1024) {
        flash('File is too large (max 15MB).', 'error');
        redirect('purchase_scan.php');
    }
    $dir = __DIR__ . '/uploads/purchase_scans';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $scanFile = uniqid('scan_') . '.' . $ext;
    move_uploaded_file($_FILES['bill_photo']['tmp_name'], $dir . '/' . $scanFile);

    // A vision model reads the bill as a TABLE (qty, rate, amount per line),
    // which the token-based OCR path below cannot do. Its numbers are then
    // checked by bs_check() - ordinary arithmetic, no AI - and anything that
    // does not add up is flagged for a person rather than accepted. If AI is
    // off, out of budget or unable to read the file, $doc stays null and the
    // original OCR path runs exactly as before.
    list($doc, $aiWhy) = bs_extract($dir . '/' . $scanFile);
    if ($doc) {
        $doc = bs_check($doc);
        $review = bs_review_rows($doc);
        log_activity('purchase_scan_ai', count($doc['lines']) . ' line(s), ' . $doc['bad_lines'] . ' flagged');
    }
}
if ($doc === null && $scanFile !== null) {
    $dir = __DIR__ . '/uploads/purchase_scans';
    $text = ocr_extract_text($dir . '/' . $scanFile);
    if ($text === null) {
        flash('Could not read this bill. Try a clearer, well-lit photo or a text-based PDF. '
            . 'The built-in free reader is shared and rate-limited - if it keeps failing, add your own free OCR.space key in Settings > Invoice, then try again.'
            . ($aiWhy ? ' (AI: ' . $aiWhy . ')' : ''), 'error');
        redirect('purchase_scan.php');
    }
    $tokens = ocr_candidate_tokens($text);
    $review = ocr_match_items($tokens);
    // Best-effort: read a price off the bill for each token so the review
    // screen can pre-fill it (always editable).
    foreach ($review as &$_r) { $_r['bill_price'] = ocr_guess_price($text, $_r['token']); }
    unset($_r);
    log_activity('purchase_scan', count($tokens) . ' token(s), ' . count(array_filter($review, fn($r) => $r['matches'])) . ' matched');
}

$page_title = 'Scan Purchase Bill';
include __DIR__ . '/includes/header.php';
?>
<div class="card">
  <h2>📤 Upload Purchase Bill</h2>
  <p class="muted mb">Upload the vendor's bill as a <strong>PDF or a photo</strong> (JPG, PNG, WEBP…). The text is read automatically and matched against your item catalog by model number, so a matched product <strong>reuses your existing item — it never creates a duplicate</strong>. On the next screen you can fix the matched item, the quantity, and the price (with GST) before it goes onto the purchase.</p>
  <form method="post" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="scan">
    <div class="field"><label>Bill file (PDF or photo)</label>
      <input type="file" name="bill_photo" accept=".pdf,application/pdf,image/*" required></div>
    <p class="muted" style="font-size:13px">Tip: on a phone this lets you pick a saved PDF/photo from files or take a new photo.</p>
    <button class="btn mt" type="submit">🔍 Read Bill</button>
  </form>
</div>

<?php if ($review !== null): ?>
<?php if ($doc): ?>
<div class="card">
  <h2>🧾 બિલનો હિસાબ ચકાસ્યો</h2>
  <p class="muted" style="margin-top:0;font-size:13px">
    AI એ બિલ વાંચ્યું, પણ દરેક આંકડો કોડે સરવાળા-ગુણાકારથી ચકાસ્યો છે — નંગ × ભાવ = લાઇન ટોટલ, અને લાઇનોનો સરવાળો = બિલનો ટોટલ.
    જે બેસતું નથી એ નીચે લાલ નિશાનીથી બતાવ્યું છે અને <b>જાતે ચકાસ્યા વગર ઉમેરાશે નહીં</b>.
  </p>
  <div class="grid-stats">
    <div class="stat"><div class="stat-label">લાઇન</div><div class="stat-value"><?= count($doc['lines']) ?></div></div>
    <div class="stat <?= $doc['bad_lines'] ? 's-bad' : '' ?>"><div class="stat-label">હિસાબ બેસતો નથી</div><div class="stat-value"><?= (int)$doc['bad_lines'] ?></div></div>
    <div class="stat"><div class="stat-label">લાઇનોનો સરવાળો</div><div class="stat-value">₹<?= money($doc['line_sum']) ?></div></div>
    <div class="stat"><div class="stat-label">બિલમાં લખેલો ટોટલ</div><div class="stat-value"><?= $doc['total'] > 0 ? '₹' . money($doc['total']) : '—' ?></div></div>
  </div>
  <?php if ($doc['problems']): ?>
    <div class="flash flash-error" style="margin-top:10px">⚠️ <?= e(implode(' · ', $doc['problems'])) ?>. બિલ સામે રાખીને ચકાસો.</div>
  <?php elseif ($doc['total'] > 0): ?>
    <div class="flash flash-success" style="margin-top:10px">✅ બિલનો સરવાળો બરાબર બેસે છે.</div>
  <?php endif; ?>
  <p class="muted" style="font-size:12.5px;margin-bottom:0">
    <?= e(trim(($doc['supplier'] ? $doc['supplier'] . ' · ' : '') . ($doc['bill_no'] ? 'બિલ ' . $doc['bill_no'] . ' · ' : '') . ($doc['bill_date'] ? dmy($doc['bill_date']) : ''), ' ·')) ?>
  </p>
</div>
<?php endif; ?>

<div class="card">
  <h2>Review Matches</h2>
  <?php if (!$review): ?>
    <p class="muted mb">No model-number-like text was found on this bill. You can still add items manually on the New Purchase screen.</p>
    <a class="btn" href="purchases.php?action=new&bill_photo=<?= e($scanFile) ?>">Continue to New Purchase →</a>
  <?php else: ?>
  <div id="scanRows">
  <?php foreach ($review as $r): if ($r['matches']): $billPrice = (float)($r['bill_price'] ?? 0);
        $probs = $r['problems'] ?? []; $lineOk = !isset($r['ok']) || $r['ok']; ?>
    <div class="list-row scan-row<?= $probs ? ' scan-bad' : '' ?>" data-token="<?= e($r['token']) ?>" data-billprice="<?= $billPrice ?>"
         data-qty="<?= (float)($r['qty'] ?? 0) ?>" data-taxpct="<?= (float)($r['tax_pct'] ?? -1) ?>"
         style="cursor:default;flex-wrap:wrap;align-items:flex-start<?= $probs ? ';background:var(--bad-soft,#fef2f2)' : '' ?>">
      <div class="list-row-main" style="flex:1 1 100%">
        <label class="check-inline"><input type="checkbox" class="scan-include" <?= $lineOk ? 'checked' : '' ?>> <strong><?= e($r['token']) ?></strong>
          <?php if ($billPrice > 0): ?><span class="muted" style="font-weight:normal">· read from bill: ₹<?= money($billPrice) ?><?php if (!empty($r['qty'])): ?> × <?= rtrim(rtrim(number_format((float)$r['qty'], 2), '0'), '.') ?> નંગ = ₹<?= money($r['amount'] ?? 0) ?><?php endif; ?></span><?php endif; ?></label>
        <?php foreach ($probs as $p): ?><div style="color:var(--bad,#b91c1c);font-size:12.5px">⚠️ <?= e($p) ?> — જાતે ચકાસો</div><?php endforeach; ?>
        <?php foreach ($r['notes'] ?? [] as $n): ?><div class="muted" style="font-size:12.5px">🧮 <?= e($n) ?></div><?php endforeach; ?>
        <select class="scan-item-select mt" style="width:100%" onchange="scanItemChanged(this)">
          <?php foreach ($r['matches'] as $m): ?>
          <option value="<?= $m['id'] ?>" data-name="<?= e($m['name']) ?>" data-price="<?= (float)$m['purchase_price'] ?>" data-tax="<?= (float)$m['tax_rate'] ?>">
            <?= e($m['name']) ?><?= $m['model'] ? ' (' . e($m['model']) . ')' : '' ?> - ₹<?= money($m['purchase_price']) ?>
          </option>
          <?php endforeach; ?>
        </select>
        <div class="scan-fields" style="display:flex;flex-wrap:wrap;gap:8px;margin-top:8px;align-items:flex-end">
          <div><label style="font-size:12px" class="muted">Qty</label><br><input type="number" class="scan-qty" value="<?= !empty($r['qty']) ? (float)$r['qty'] : 1 ?>" min="0.01" step="any" style="width:70px"></div>
          <div><label style="font-size:12px" class="muted">Price ₹</label><br><input type="number" class="scan-price" value="0" min="0" step="any" style="width:100px" oninput="scanCalc(this)"></div>
          <div><label style="font-size:12px" class="muted">GST %</label><br><input type="number" class="scan-gst" value="0" min="0" step="any" style="width:70px" oninput="scanCalc(this)"></div>
          <label class="check-inline" style="font-size:13px"><input type="checkbox" class="scan-incl" onchange="scanCalc(this)"> Price incl. GST</label>
          <div class="muted scan-calc" style="font-size:12.5px"></div>
        </div>
      </div>
    </div>
  <?php else: ?>
    <div class="list-row scan-row scan-unmatched" data-token="<?= e($r['token']) ?>"
         data-billprice="<?= (float)($r['bill_price'] ?? 0) ?>" data-qty="<?= (float)($r['qty'] ?? 0) ?>"
         data-taxpct="<?= (float)($r['tax_pct'] ?? -1) ?>" style="cursor:default;background:var(--warn-soft)">
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
    row.style.alignItems = 'flex-start';
    row.innerHTML = '<div class="list-row-main" style="flex:1 1 100%"><label class="check-inline"><input type="checkbox" class="scan-include" checked> <strong>' + token + '</strong></label>' +
      '<select class="scan-item-select mt" style="width:100%" onchange="scanItemChanged(this)">' + opts + '</select>' +
      '<div class="scan-fields" style="display:flex;flex-wrap:wrap;gap:8px;margin-top:8px;align-items:flex-end">' +
      // the qty the bill actually printed survives an "add the item, re-check"
      // round trip - retyping it is exactly the work this screen exists to save
      '<div><label style="font-size:12px" class="muted">Qty</label><br><input type="number" class="scan-qty" value="' + (parseFloat(row.dataset.qty) > 0 ? parseFloat(row.dataset.qty) : 1) + '" min="0.01" step="any" style="width:70px"></div>' +
      '<div><label style="font-size:12px" class="muted">Price ₹</label><br><input type="number" class="scan-price" value="0" min="0" step="any" style="width:100px" oninput="scanCalc(this)"></div>' +
      '<div><label style="font-size:12px" class="muted">GST %</label><br><input type="number" class="scan-gst" value="0" min="0" step="any" style="width:70px" oninput="scanCalc(this)"></div>' +
      '<label class="check-inline" style="font-size:13px"><input type="checkbox" class="scan-incl" onchange="scanCalc(this)"> Price incl. GST</label>' +
      '<div class="muted scan-calc" style="font-size:12.5px"></div></div></div>';
    scanInitRow(row);
    scanUpdateState();
  });
});

// Prefill a matched row's price (prefer the price read off the bill, else the
// catalog price) and GST (the catalog item's tax rate), then show the split.
function scanInitRow(row) {
  var sel = row.querySelector('.scan-item-select');
  if (!sel) return;
  var opt = sel.options[sel.selectedIndex];
  var billPrice = parseFloat(row.dataset.billprice) || 0;
  var priceInp = row.querySelector('.scan-price');
  var gstInp = row.querySelector('.scan-gst');
  priceInp.value = (billPrice > 0 ? billPrice : (parseFloat(opt.dataset.price) || 0));
  var lineTax = parseFloat(row.dataset.taxpct);
  var fromTable = !isNaN(lineTax) && lineTax >= 0;   // AI read this bill as a table
  gstInp.value = (fromTable && lineTax > 0) ? lineTax : (parseFloat(opt.dataset.tax) || 0);
  // A rate printed in a bill's rate COLUMN is the pre-GST rate - the tax sits
  // in its own row at the bottom. Only the old OCR path, which grabs whatever
  // number ends the line, is treating an inclusive amount as the price.
  if (!fromTable && billPrice > 0 && (parseFloat(gstInp.value) || 0) > 0) row.querySelector('.scan-incl').checked = true;
  scanCalc(priceInp);
}
// When the user picks a different catalog item, refresh price/GST to that item
// (unless they already typed their own price).
function scanItemChanged(sel) {
  var row = sel.closest('.scan-row');
  var opt = sel.options[sel.selectedIndex];
  var priceInp = row.querySelector('.scan-price');
  if (!priceInp.dataset.touched) priceInp.value = parseFloat(opt.dataset.price) || 0;
  row.querySelector('.scan-gst').value = parseFloat(opt.dataset.tax) || 0;
  scanCalc(priceInp);
}
// Recompute and show: net price, GST amount, gross. When "incl. GST" is on the
// entered price is treated as gross and the net is backed out.
function scanCalc(el) {
  if (el.classList && el.classList.contains('scan-price')) el.dataset.touched = '1';
  var row = el.closest('.scan-row');
  var price = parseFloat(row.querySelector('.scan-price').value) || 0;
  var gst = parseFloat(row.querySelector('.scan-gst').value) || 0;
  var incl = row.querySelector('.scan-incl').checked;
  var net = incl ? price / (1 + gst / 100) : price;
  var gstAmt = net * gst / 100;
  row.querySelector('.scan-calc').textContent = 'Net ₹' + net.toFixed(2) + ' + GST ₹' + gstAmt.toFixed(2) + ' = ₹' + (net + gstAmt).toFixed(2);
}
document.querySelectorAll('.scan-row').forEach(function (row) { if (row.querySelector('.scan-item-select')) scanInitRow(row); });
scanUpdateState();

function scanContinue() {
  var items = [];
  var bad = false;
  document.querySelectorAll('.scan-row').forEach(function (row) {
    var inc = row.querySelector('.scan-include');
    if (!inc || !inc.checked) return;
    var sel = row.querySelector('.scan-item-select');
    if (!sel) return;
    var opt = sel.options[sel.selectedIndex];
    var qty = parseFloat(row.querySelector('.scan-qty').value) || 0;
    var price = parseFloat(row.querySelector('.scan-price').value) || 0;
    var gst = parseFloat(row.querySelector('.scan-gst').value) || 0;
    var incl = row.querySelector('.scan-incl').checked;
    if (qty <= 0) { bad = true; return; }
    // Store the NET (GST-exclusive) price - the purchase form adds GST on top
    // via the tax %, so the bill's inclusive total is reproduced exactly.
    var net = incl ? price / (1 + gst / 100) : price;
    items.push({id: sel.value, name: opt.dataset.name, price: net.toFixed(2), tax: gst, qty: qty});
  });
  if (bad) { alert('Every included item needs a quantity greater than 0.'); return; }
  if (!items.length) { alert('Select at least one item to add.'); return; }
  // a line whose arithmetic did not add up can still be taken - but only
  // deliberately, and only after the person says they checked it
  var flagged = document.querySelectorAll('.scan-bad .scan-include:checked').length;
  if (flagged && !confirm(flagged + ' લાઇનનો હિસાબ બિલ સાથે બેસતો નથી. તમે જાતે ચકાસી લીધું છે?')) return;
  sessionStorage.setItem('reorderItems', JSON.stringify(items));
  location = 'purchases.php?action=new&reorder=1&bill_photo=' + encodeURIComponent(SCAN_FILE);
}
</script>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
