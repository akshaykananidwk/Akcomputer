<?php
// Bulk item import from Excel (.xlsx) or CSV - no external libraries.
// Columns matched loosely by header name: Name, HSN, Unit, Purchase, Selling/Price,
// B2B, GST, Stock, Barcode, Category, Margin, Warranty
require_once __DIR__ . '/includes/init.php';
require_perm('items.add');
$u = current_user();

// ---------- tiny XLSX reader (sheet1 + sharedStrings) ----------
function read_xlsx_rows($file) {
    if (!class_exists('ZipArchive')) return null;
    $zip = new ZipArchive();
    if ($zip->open($file) !== true) return null;
    $shared = [];
    if (($ss = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
        $xml = @simplexml_load_string($ss);
        if ($xml) foreach ($xml->si as $si) {
            if (isset($si->t)) $shared[] = (string)$si->t;
            else { $t = ''; foreach ($si->r as $r) $t .= (string)$r->t; $shared[] = $t; }
        }
    }
    $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    if ($sheet === false) return null;
    $xml = @simplexml_load_string($sheet);
    if (!$xml) return null;
    $rows = [];
    foreach ($xml->sheetData->row as $xr) {
        $row = [];
        foreach ($xr->c as $c) {
            // column index from cell ref (A1, B1...)
            preg_match('/^([A-Z]+)/', (string)$c['r'], $m);
            $ci = 0;
            foreach (str_split($m[1] ?? 'A') as $ch) $ci = $ci * 26 + (ord($ch) - 64);
            $v = isset($c->v) ? (string)$c->v : '';
            if ((string)$c['t'] === 's') $v = $shared[(int)$v] ?? '';
            $row[$ci - 1] = $v;
        }
        if ($row) { ksort($row); $rows[] = $row; }
    }
    return $rows;
}

function read_csv_rows($file) {
    $rows = [];
    if (($h = fopen($file, 'r')) !== false) {
        while (($r = fgetcsv($h)) !== false) $rows[] = $r;
        fclose($h);
    }
    // strip BOM from the very first cell
    if ($rows && isset($rows[0][0])) $rows[0][0] = preg_replace('/^\xEF\xBB\xBF/', '', $rows[0][0]);
    return $rows;
}

// map header names -> field keys
function map_columns($header) {
    $map = [];
    foreach ($header as $i => $h) {
        $h = strtolower(trim((string)$h));
        if ($h === '') continue;
        if (strpos($h, 'name') !== false || strpos($h, 'item') !== false) $map['name'] = $map['name'] ?? $i;
        elseif (strpos($h, 'hsn') !== false) $map['hsn'] = $i;
        elseif (strpos($h, 'unit') !== false) $map['unit'] = $i;
        elseif (strpos($h, 'purchase') !== false || strpos($h, 'cost') !== false) $map['purchase'] = $i;
        elseif (strpos($h, 'b2b') !== false || strpos($h, 'wholesale') !== false) $map['b2b'] = $i;
        elseif (strpos($h, 'sell') !== false || strpos($h, 'sale') !== false || strpos($h, 'price') !== false || strpos($h, 'mrp') !== false) $map['selling'] = $map['selling'] ?? $i;
        elseif (strpos($h, 'gst') !== false || strpos($h, 'tax') !== false) $map['gst'] = $i;
        elseif (strpos($h, 'stock') !== false || strpos($h, 'qty') !== false || strpos($h, 'opening') !== false) $map['stock'] = $i;
        elseif (strpos($h, 'barcode') !== false || strpos($h, 'code') !== false) $map['barcode'] = $map['barcode'] ?? $i;
        elseif (strpos($h, 'categ') !== false) $map['category'] = $i;
        elseif (strpos($h, 'margin') !== false) $map['margin'] = $i;
        elseif (strpos($h, 'warrant') !== false) $map['warranty'] = $i;
        elseif (strpos($h, 'brand') !== false) $map['brand'] = $i;
    }
    return $map;
}

// ---------- sample CSV download ----------
if (get('do') === 'sample') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="items_sample.csv"');
    echo "\xEF\xBB\xBF";
    echo "Item Name,HSN,Unit,Purchase Price,Selling Price,B2B Price,GST %,Opening Stock,Barcode,Category,Margin %,Warranty Months\n";
    echo "SSD 512GB,8471,PCS,2000,2800,2500,18,5,SSD512,Storage,30,36\n";
    echo "LAN Cable Cat6,8544,MTR,12,25,18,18,100,,Cables,,0\n";
    exit;
}

$result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'import' && !empty($_FILES['file']['tmp_name'])) {
    $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
    $rows = $ext === 'xlsx' ? read_xlsx_rows($_FILES['file']['tmp_name']) : read_csv_rows($_FILES['file']['tmp_name']);
    if (!$rows || count($rows) < 2) {
        flash($ext === 'xlsx' && !class_exists('ZipArchive') ? 'Server માં zip extension નથી - .csv વાપરો.' : 'File વંચાઈ નહીં અથવા ખાલી છે. Sample format જુઓ.', 'error');
    } else {
        $map = map_columns($rows[0]);
        if (!isset($map['name'])) {
            flash('Header row માં "Name" column ન મળ્યો. Sample file જેવા headers રાખો.', 'error');
        } else {
            $loc_id = (int)post('location_id') ?: $u['location_id'];
            $added = 0; $updated = 0; $skipped = 0; $stocked = 0;
            $pdo = db();
            $pdo->beginTransaction();
            $cell = fn($r, $k) => isset($map[$k], $r[$map[$k]]) ? trim((string)$r[$map[$k]]) : '';
            foreach (array_slice($rows, 1) as $r) {
                $name = $cell($r, 'name');
                if ($name === '') { $skipped++; continue; }
                $catId = null;
                if (($cn = $cell($r, 'category')) !== '') {
                    $catId = val('SELECT id FROM categories WHERE name = ?', [$cn]);
                    if (!$catId) { q('INSERT INTO categories (name) VALUES (?)', [$cn]); $catId = insert_id(); }
                }
                $vals = [
                    'hsn' => $cell($r, 'hsn'), 'unit' => $cell($r, 'unit') ?: 'PCS',
                    'purchase' => (float)$cell($r, 'purchase'), 'selling' => (float)$cell($r, 'selling'),
                    'b2b' => (float)$cell($r, 'b2b'), 'gst' => $cell($r, 'gst') !== '' ? (float)$cell($r, 'gst') : (float)setting('default_tax', 18),
                    'stock' => (float)$cell($r, 'stock'), 'barcode' => $cell($r, 'barcode'),
                    'margin' => (float)$cell($r, 'margin'), 'warranty' => (int)$cell($r, 'warranty'), 'brand' => $cell($r, 'brand'),
                ];
                if ($vals['margin'] > 0 && $vals['selling'] <= 0) $vals['selling'] = round($vals['purchase'] * (1 + $vals['margin'] / 100), 2);
                $exist = row('SELECT id FROM items WHERE name = ?', [$name]);
                if ($exist) {
                    q('UPDATE items SET hsn=?, unit=?, purchase_price=?, selling_price=?, b2b_price=?, tax_rate=?, barcode=?, margin_pct=?, warranty_months=?, brand=?, is_active=1 WHERE id=?',
                      [$vals['hsn'], $vals['unit'], $vals['purchase'], $vals['selling'], $vals['b2b'], $vals['gst'],
                       $vals['barcode'], $vals['margin'], $vals['warranty'], $vals['brand'], $exist['id']]);
                    $itemId = (int)$exist['id'];
                    $updated++;
                } else {
                    q('INSERT INTO items (name, category_id, brand, unit, hsn, tax_rate, purchase_price, selling_price, b2b_price, margin_pct, warranty_months, barcode)
                       VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
                      [$name, $catId, $vals['brand'], $vals['unit'], $vals['hsn'], $vals['gst'], $vals['purchase'],
                       $vals['selling'], $vals['b2b'], $vals['margin'], $vals['warranty'], $vals['barcode']]);
                    $itemId = insert_id();
                    $added++;
                }
                if ($vals['stock'] > 0 && post('import_stock')) {
                    adjust_stock($itemId, $loc_id, $vals['stock'], 'import', null, 'Excel/CSV import');
                    $stocked++;
                }
            }
            $pdo->commit();
            log_activity('items_import', "added=$added updated=$updated stock=$stocked");
            flash("Import પૂરું: $added નવી items, $updated update, $stocked ને opening stock" . ($skipped ? ", $skipped ખાલી rows skip" : '') . '.');
            redirect('items.php');
        }
    }
}

$locations = all('SELECT * FROM locations WHERE is_active = 1 ORDER BY name');
$page_title = 'Import Items (Excel/CSV)';
include __DIR__ . '/includes/header.php';
?>
<div class="card">
  <h2>📥 Excel / CSV થી Items Import</h2>
  <p class="muted mb">
    File ની પહેલી row માં headers જોઈએ: <code>Item Name, HSN, Unit, Purchase Price, Selling Price, B2B Price, GST %, Opening Stock, Barcode, Category, Margin %, Warranty Months</code><br>
    (કોઈ column ઓછો હોય તો ચાલે — ખાલી Name ફરજિયાત. Item નું નામ પહેલેથી હોય તો ભાવ update થશે, બે વાર નહીં બને.)
  </p>
  <p class="mb"><a class="btn btn-outline btn-sm" href="items_import.php?do=sample">⬇ Sample CSV download</a></p>
  <form method="post" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="import">
    <div class="form-row cols-2">
      <div><label>File (.xlsx અથવા .csv) *</label><input type="file" name="file" accept=".xlsx,.csv" required></div>
      <div><label>Opening stock કયા location માં?</label>
        <select name="location_id">
          <?php foreach ($locations as $l): ?>
          <option value="<?= $l['id'] ?>" <?= $l['id'] == $u['location_id'] ? 'selected' : '' ?>><?= e($l['name']) ?></option>
          <?php endforeach; ?>
        </select></div>
    </div>
    <label class="check-inline mb"><input type="checkbox" name="import_stock" value="1" checked> "Opening Stock" column માંથી stock પણ ચડાવવો</label>
    <button class="btn" type="submit">⬆ Import Now</button>
  </form>
</div>
<div class="card">
  <h3>ટિપ્સ</h3>
  <p class="muted">• Vyapar માંથી નીકળવું હોય તો: Vyapar → Items → Export to Excel કરી એ file અહીં ચડાવી દો (headers આપોઆપ ઓળખાઈ જશે).<br>
  • Serial-tracked items import પછી Items page પર ખોલીને "Serial number tracked" tick કરવું.<br>
  • Margin % આપો ને Selling ખાલી રાખો તો Selling = Purchase + Margin% આપોઆપ ગણાશે.</p>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
