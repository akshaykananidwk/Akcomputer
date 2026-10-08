<?php
// 📥 Bring parties and items in from Tally: in Tally, Display → List of
// Accounts / Stock Items → Export (XML). Upload it here, look at what will be
// added, and confirm. Names already in the software are left alone; an
// opening balance is set only on a party that is new here.
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/connect.php';
require_perm('parties.add');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'read') {
    $f = $_FILES['file'] ?? null;
    if (!$f || $f['error'] !== UPLOAD_ERR_OK || $f['size'] > 20 * 1024 * 1024) { flash('Choose the Tally XML file.', 'error'); redirect('tally_import.php'); }
    $raw = file_get_contents($f['tmp_name']);
    if (substr($raw, 0, 2) === "\xFF\xFE" || substr($raw, 0, 2) === "\xFE\xFF") $raw = mb_convert_encoding($raw, 'UTF-8', 'UTF-16');   // Tally often saves UTF-16
    $_SESSION['tally_import'] = tally_parse_xml($raw);
    redirect('tally_import.php');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'confirm' && !empty($_SESSION['tally_import'])) {
    $d = $_SESSION['tally_import']; $np = 0; $ni = 0;
    $pdo = db(); $pdo->beginTransaction();
    try {
        if (post('parties')) foreach ($d['parties'] as [$name, $type, $open, $gstin, $mobile]) {
            if (val('SELECT id FROM parties WHERE name = ?', [$name])) continue;
            q('INSERT INTO parties (name, type, mobile, gstin, opening_balance) VALUES (?,?,?,?,?)', [mb_substr($name, 0, 120), $type, mb_substr($mobile, -10), mb_substr($gstin, 0, 20), post('openings') ? $open : 0]);
            $np++;
        }
        if (post('items') && can('items.add')) foreach ($d['items'] as [$name, $unit, $rate, $hsn]) {
            if (val('SELECT id FROM items WHERE name = ?', [$name])) continue;
            q("INSERT INTO items (name, unit, selling_price, hsn, item_type, is_active) VALUES (?,?,?,?, 'product', 1)", [mb_substr($name, 0, 150), mb_substr($unit, 0, 20), $rate, mb_substr($hsn, 0, 20)]);
            $ni++;
        }
        $pdo->commit();
    } catch (Exception $e) { $pdo->rollBack(); flash(plain_error($e), 'error'); redirect('tally_import.php'); }
    unset($_SESSION['tally_import']);
    log_activity('tally_import', "$np parties, $ni items");
    flash("Brought in $np parties and $ni items from Tally.");
    redirect('tally_import.php');
}
if (get('clear')) { unset($_SESSION['tally_import']); redirect('tally_import.php'); }

$d = $_SESSION['tally_import'] ?? null;
$page_title = 'Import from Tally';
include __DIR__ . '/includes/header.php';
?>
<div class="page-head"><h1>📥 Import from Tally</h1><a class="btn btn-sm btn-outline" href="tally_export.php">📤 Export to Tally</a></div>
<?php if (!$d): ?>
<form method="post" enctype="multipart/form-data" class="card"><?= csrf_field() ?><input type="hidden" name="do" value="read">
  <p style="margin-top:0">In Tally: <b>Gateway of Tally → Display → List of Accounts</b> (and <b>Stock Items</b>) → <b>Alt+E Export</b> → Format: <b>XML</b>. Upload that file:</p>
  <input type="file" name="file" accept=".xml" required> <button class="btn" type="submit">Read the file</button></form>
<?php else: $newP = array_filter($d['parties'], fn($p) => !val('SELECT id FROM parties WHERE name = ?', [$p[0]])); $newI = array_filter($d['items'], fn($i) => !val('SELECT id FROM items WHERE name = ?', [$i[0]])); ?>
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="do" value="confirm">
  <p style="margin-top:0">Found <b><?= count($d['parties']) ?></b> parties (<b><?= count($newP) ?></b> new) and <b><?= count($d['items']) ?></b> items (<b><?= count($newI) ?></b> new). Names already here are skipped.</p>
  <label class="check-inline"><input type="checkbox" name="parties" value="1" checked> Add the new parties</label><br>
  <label class="check-inline"><input type="checkbox" name="openings" value="1"> …with their Tally opening balance <span class="muted">(only if you are starting fresh here — otherwise the balance would count twice)</span></label><br>
  <label class="check-inline"><input type="checkbox" name="items" value="1" checked> Add the new items</label>
  <div class="mt"><button class="btn" type="submit">✔ Bring them in</button> <a class="btn btn-outline" href="tally_import.php?clear=1">Cancel</a></div></form>
<div class="pane"><div class="pane-head"><h3>New parties</h3></div><div class="pane-body tight"><table class="rowlist"><tbody>
<?php foreach (array_slice($newP, 0, 200) as [$n, $t, $o, $g]): ?><tr><td><?= e($n) ?></td><td><?= e($t) ?></td><td class="num"><?= $o ? ($o > 0 ? 'they owe ₹' : 'we owe ₹') . money(abs($o)) : '' ?></td><td class="muted"><?= e($g) ?></td></tr><?php endforeach; ?>
</tbody></table></div></div>
<div class="pane"><div class="pane-head"><h3>New items</h3></div><div class="pane-body tight"><table class="rowlist"><tbody>
<?php foreach (array_slice($newI, 0, 200) as [$n, $u, $r]): ?><tr><td><?= e($n) ?></td><td><?= e($u) ?></td><td class="num">₹<?= money($r) ?></td></tr><?php endforeach; ?>
</tbody></table></div></div>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
