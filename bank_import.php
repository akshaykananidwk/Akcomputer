<?php
// 🏦 Bank statement: upload the bank's CSV or Excel file; each line is
// matched to a payment already in the books (same amount, within 3 days).
// A person ticks a match and it is marked reconciled. Lines with no match
// are shown, so a missing entry is found - nothing is added by itself.
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/connect.php';
require_perm('accounting.view');
$banks = all('SELECT * FROM bank_accounts WHERE is_active = 1 ORDER BY is_default DESC, account_name');
$bank = (int)(get('bank') ?: post('bank') ?: ($banks[0]['id'] ?? 0));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'upload') {
    require_perm('accounting.edit');
    $f = $_FILES['file'] ?? null;
    if (!$f || $f['error'] !== UPLOAD_ERR_OK || $f['size'] > 5 * 1024 * 1024) { flash('Choose the statement file (CSV or Excel, up to 5 MB).', 'error'); redirect('bank_import.php'); }
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if ($ext === 'xlsx') $rows = xlsx_rows($f['tmp_name']);
    elseif (in_array($ext, ['csv', 'txt'], true)) { $rows = []; $h = fopen($f['tmp_name'], 'r'); while (($r = fgetcsv($h)) !== false) $rows[] = $r; fclose($h); }
    else { flash('Save the statement as CSV or .xlsx and upload again (old .xls files cannot be read).', 'error'); redirect('bank_import.php'); }
    $lines = bank_statement_parse($rows);
    $added = 0;
    foreach ($lines as [$d, $desc, $amt, $ref]) {
        $fp = sha1($bank . '|' . $d . '|' . $amt . '|' . $desc . '|' . $ref);
        $added += q('INSERT IGNORE INTO bank_lines (bank_account_id, line_date, description, amount, ref, fingerprint) VALUES (?,?,?,?,?,?)',
                    [$bank, $d, mb_substr($desc, 0, 255), $amt, mb_substr($ref, 0, 80), $fp])->rowCount();
    }
    log_activity('bank_import', count($lines) . ' lines, ' . $added . ' new');
    flash($lines ? count($lines) . " lines read, $added new (the rest were already in)." : 'No lines found — is this the bank statement with a Date and Narration column?', $lines ? 'success' : 'error');
    redirect("bank_import.php?bank=$bank");
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'match') {
    require_perm('accounting.edit');
    $l = row('SELECT * FROM bank_lines WHERE id = ? AND matched_id IS NULL', [(int)post('line')]);
    $p = $l ? row('SELECT * FROM payments WHERE id = ? AND ABS(amount - ?) < 0.01', [(int)post('payment'), abs($l['amount'])]) : null;
    if ($l && $p) {
        q("UPDATE bank_lines SET matched_type = 'payment', matched_id = ? WHERE id = ?", [$p['id'], $l['id']]);
        q('UPDATE payments SET is_reconciled = 1, reconciled_date = ? WHERE id = ?', [$l['line_date'], $p['id']]);
        log_activity('bank_match', 'line ' . $l['id'] . ' = P-' . $p['id']);
    }
    redirect("bank_import.php?bank=$bank");
}

$lines = all('SELECT * FROM bank_lines WHERE bank_account_id = ? ORDER BY matched_id IS NULL DESC, line_date DESC LIMIT 300', [$bank]);
$page_title = 'Bank statement';
include __DIR__ . '/includes/header.php';
?>
<div class="page-head"><h1>🏦 Bank statement</h1><a class="btn btn-sm btn-outline" href="bank_reconcile.php">Reconciliation</a></div>
<?php if (can('accounting.edit')): ?>
<form method="post" enctype="multipart/form-data" class="card" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end"><?= csrf_field() ?><input type="hidden" name="do" value="upload">
  <div class="field"><label>Bank account</label><select name="bank"><?php foreach ($banks as $b): ?><option value="<?= (int)$b['id'] ?>" <?= $b['id'] == $bank ? 'selected' : '' ?>><?= e($b['account_name'] . ' - ' . $b['bank_name']) ?></option><?php endforeach; ?></select></div>
  <div class="field"><label>Statement (CSV or .xlsx from net banking)</label><input type="file" name="file" accept=".csv,.xlsx,.txt" required></div>
  <button class="btn" type="submit">⬆️ Read it</button></form>
<?php endif; ?>
<div class="pane"><div class="pane-body tight"><table class="rowlist"><thead><tr><th>Date</th><th>Bank says</th><th class="num">Amount</th><th>In our books</th></tr></thead><tbody>
<?php foreach ($lines as $l):
  $cands = $l['matched_id'] ? [] : all("SELECT p.id, p.pay_date, p.amount, pa.name FROM payments p JOIN parties pa ON pa.id = p.party_id
              WHERE p.direction = ? AND ABS(p.amount - ?) < 0.01 AND p.pay_date BETWEEN DATE_SUB(?, INTERVAL 3 DAY) AND DATE_ADD(?, INTERVAL 3 DAY) AND p.is_reconciled = 0
              AND p.id NOT IN (SELECT matched_id FROM bank_lines WHERE matched_type = 'payment' AND matched_id IS NOT NULL) LIMIT 3",
              [$l['amount'] > 0 ? 'in' : 'out', abs($l['amount']), $l['line_date'], $l['line_date']]); ?>
  <tr><td data-l="Date"><?= dmy($l['line_date']) ?></td><td data-l="Bank says"><?= e($l['description']) ?> <small class="muted"><?= e($l['ref']) ?></small></td>
    <td class="num" data-l="Amount" style="color:<?= $l['amount'] > 0 ? 'var(--ok)' : 'var(--bad)' ?>"><?= $l['amount'] > 0 ? '+' : '−' ?>₹<?= money(abs($l['amount'])) ?></td>
    <td data-l="Books"><?php if ($l['matched_id']): ?><span class="badge badge-ok">matched P-<?= (int)$l['matched_id'] ?></span>
      <?php elseif ($cands): foreach ($cands as $c): ?><form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="do" value="match"><input type="hidden" name="bank" value="<?= $bank ?>"><input type="hidden" name="line" value="<?= (int)$l['id'] ?>"><input type="hidden" name="payment" value="<?= (int)$c['id'] ?>">
        <button class="btn btn-sm btn-outline" <?= can('accounting.edit') ? '' : 'disabled' ?>>✔ <?= e($c['name']) ?> · <?= dmy($c['pay_date']) ?></button></form>
      <?php endforeach; else: ?><span class="badge badge-warn">not in the books</span>
        <?php if ($l['amount'] > 0 && can('payments.add')): ?><a class="btn btn-sm btn-outline" href="payments.php?action=new&amp;mode=bank&amp;amount=<?= +$l['amount'] ?>&amp;notes=<?= rawurlencode('Bank: ' . mb_substr($l['description'], 0, 80)) ?>">➕ Record</a><?php endif; ?>
      <?php endif; ?></td></tr>
<?php endforeach; if (!$lines): ?><tr><td class="muted">Upload a statement to start.</td></tr><?php endif; ?></tbody></table></div></div>
<?php include __DIR__ . '/includes/footer.php'; ?>
