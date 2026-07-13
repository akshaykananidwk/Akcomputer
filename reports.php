<?php
// Reports hub: sales / purchase / GST / profit / staff stock / repair TAT / warranty company TAT
require_once __DIR__ . '/includes/init.php';
require_perm('reports.view');

$r = get('r', 'daily');
$from = get('from', date('Y-m-01'));
$to = get('to', today());
$fCompany = (int)get('f_company');
$fParty = (int)get('f_party');
$fStatus = get('f_status'); // '', 'paid', 'partial', 'due'
$banksAll = all('SELECT * FROM bank_accounts WHERE is_active = 1 ORDER BY is_default DESC, account_name');
$bankId = (int)get('bank_id') ?: (int)($banksAll[0]['id'] ?? 0);
$page_title = 'Reports';
include __DIR__ . '/includes/header.php';

$companiesAll = all('SELECT id, name FROM companies ORDER BY name');
$partiesAll = all('SELECT id, name FROM parties WHERE is_active = 1 ORDER BY name');

$tabs = [
    'business' => '🏢 Business Report',
    'daily' => '📅 Daily Sales', 'sales' => '🧾 Sales', 'party_sales' => '👥 Party Sales',
    'aging' => '⏳ Aging / Collection',
    'purchase' => '📦 Purchase', 'vendor_perf' => '🚚 Vendor Performance', 'stockval' => '📊 Stock Report', 'cashbook' => '💵 Cashbook',
    'bank_ledger' => '🏦 Bank Ledger',
    'expense' => '🧾 Expenses', 'gst' => '🧮 GST', 'profit' => '💹 Profit',
    'bill_profit' => '🧮 Bill Profit', 'staff' => '🎒 Staff Stock',
    'repair_tat' => '🛠️ Repair TAT', 'warranty_tat' => '🛡️ Warranty TAT', 'tech_sla' => '⏱️ Technician SLA',
    'forecast' => '🔮 AI Sales Forecast', 'low' => '⚠️ Low Stock',
    'activity' => '🔍 Activity Log',
];
if (!can('reports.gst')) unset($tabs['gst']);
if (!can('reports.profit')) { unset($tabs['profit']); unset($tabs['bill_profit']); unset($tabs['stockval']); unset($tabs['business']); }
if (!can('expenses.view')) unset($tabs['expense']);
if (!can('users.view')) unset($tabs['activity']);
if (!can('payments.view')) unset($tabs['bank_ledger']);
if ($r === 'business' && !can('reports.profit')) $r = 'daily';
if ($r === 'activity' && !can('users.view')) $r = 'daily';
if ($r === 'bank_ledger' && !can('payments.view')) $r = 'daily';
?>
<?php
$filterExtra = '&f_company=' . $fCompany . '&f_party=' . $fParty . '&f_status=' . e($fStatus) . '&bank_id=' . $bankId;
$filterFamily = in_array($r, ['daily', 'sales', 'party_sales', 'aging', 'purchase', 'vendor_perf', 'gst', 'profit', 'bill_profit'], true);
?>
<div class="page-actions" style="overflow-x:auto;flex-wrap:nowrap">
<?php foreach ($tabs as $k => $label): ?>
  <a class="btn btn-sm <?= $r === $k ? '' : 'btn-outline' ?>" href="reports.php?r=<?= $k ?>&from=<?= e($from) ?>&to=<?= e($to) ?><?= $filterExtra ?>" style="white-space:nowrap"><?= $label ?></a>
<?php endforeach; ?>
</div>
<form method="get" class="filterbar" id="reportFilterForm">
  <input type="hidden" name="r" value="<?= e($r) ?>">
  <div><label>Period</label>
    <select id="datePreset" onchange="applyPreset(this.value)">
      <option value="">Custom</option>
      <option value="today">Today</option>
      <option value="week">This Week</option>
      <option value="month">This Month</option>
      <option value="quarter">This Quarter</option>
      <option value="fy">This Financial Year</option>
    </select>
  </div>
  <div><label>From</label><input type="date" name="from" id="fFrom" value="<?= e($from) ?>"></div>
  <div><label>To</label><input type="date" name="to" id="fTo" value="<?= e($to) ?>"></div>
  <?php if ($r === 'bank_ledger'): ?>
  <div><label>Bank Account</label>
    <select name="bank_id" onchange="document.getElementById('reportFilterForm').submit()">
      <?php if (!$banksAll): ?><option value="0">-- No bank account --</option><?php endif; ?>
      <?php foreach ($banksAll as $b): ?>
      <option value="<?= $b['id'] ?>" <?= $bankId == $b['id'] ? 'selected' : '' ?>><?= e($b['account_name']) ?> - <?= e($b['bank_name']) ?></option>
      <?php endforeach; ?>
    </select></div>
  <?php endif; ?>
  <?php if ($filterFamily): ?>
  <input type="hidden" name="f_company" id="f_company_h" value="<?= $fCompany ?>">
  <input type="hidden" name="f_party" id="f_party_h" value="<?= $fParty ?>">
  <input type="hidden" name="f_status" id="f_status_h" value="<?= e($fStatus) ?>">
  <div><button class="btn btn-sm btn-outline no-print" type="button" onclick="var p=document.getElementById('filterPanel'); p.style.display = p.style.display === 'none' ? '' : 'none'">🔽 Filters</button></div>
  <?php endif; ?>
  <button class="btn btn-sm" type="submit">Apply</button>
  <button class="btn btn-sm btn-outline no-print" type="button" onclick="window.print()">🖨️ Print</button>
  <button class="btn btn-sm btn-outline no-print" type="button" onclick="openExportDialog('csv')">⬇ Excel/CSV</button>
  <button class="btn btn-sm btn-outline no-print" type="button" onclick="openExportDialog('pdf')">⬇ PDF</button>
  <?php if ($r === 'sales' || $r === 'purchase'): ?>
  <a class="btn btn-sm btn-outline no-print" href="tally_export.php?type=<?= $r ?>&from=<?= e($from) ?>&to=<?= e($to) ?>">⬇ Tally XML</a>
  <?php endif; ?>
</form>

<div class="modal-overlay no-print" id="exportModal">
  <div class="modal-box">
    <h3>What to display?</h3>
    <div id="exportCheckList"></div>
    <div class="field">
      <label>File name</label>
      <input type="text" id="exportFname">
    </div>
    <div class="modal-actions">
      <button class="btn btn-outline" type="button" onclick="closeExportDialog()">Cancel</button>
      <button class="btn" type="button" onclick="applyExport()">Apply</button>
    </div>
  </div>
</div>
<?php if ($filterFamily): ?>
<div class="card" id="filterPanel" style="display:none">
  <div class="form-row cols-3">
    <div><label>Firm</label><select onchange="document.getElementById('f_company_h').value=this.value">
      <option value="0">All Firms</option>
      <?php foreach ($companiesAll as $c): ?><option value="<?= $c['id'] ?>" <?= $fCompany == $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
    </select></div>
    <div><label>Party</label><select onchange="document.getElementById('f_party_h').value=this.value">
      <option value="0">All Parties</option>
      <?php foreach ($partiesAll as $p): ?><option value="<?= $p['id'] ?>" <?= $fParty == $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?>
    </select></div>
    <div><label>Status</label><select onchange="document.getElementById('f_status_h').value=this.value">
      <?php foreach (['' => 'All', 'paid' => 'Paid', 'partial' => 'Partial', 'due' => 'Due'] as $sv => $sl): ?>
      <option value="<?= $sv ?>" <?= $fStatus === $sv ? 'selected' : '' ?>><?= $sl ?></option>
      <?php endforeach; ?>
    </select></div>
  </div>
  <div class="page-actions" style="margin:10px 0 0">
    <a class="btn btn-sm btn-outline" href="reports.php?r=<?= e($r) ?>&from=<?= e($from) ?>&to=<?= e($to) ?>">Reset</a>
    <button class="btn btn-sm" type="submit" form="reportFilterForm">Apply</button>
  </div>
</div>
<?php if ($fCompany || $fParty || $fStatus): ?>
<div class="mb">
  <span class="muted">Filters Applied: </span>
  <?php if ($fCompany): $cn = array_values(array_filter($companiesAll, fn($c) => $c['id'] == $fCompany))[0]['name'] ?? ''; ?><span class="badge badge-info">Firm: <?= e($cn) ?></span><?php endif; ?>
  <?php if ($fParty): $pn = array_values(array_filter($partiesAll, fn($p) => $p['id'] == $fParty))[0]['name'] ?? ''; ?><span class="badge badge-info">Party: <?= e($pn) ?></span><?php endif; ?>
  <?php if ($fStatus): ?><span class="badge badge-info">Status: <?= e(ucfirst($fStatus)) ?></span><?php endif; ?>
</div>
<?php endif; endif; ?>
<script>
var TODAY = <?= json_encode(today()) ?>;
function fmt(d) { return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); }
function applyPreset(p) {
  if (!p) return;
  var t = new Date(TODAY + 'T00:00:00');
  var from, to = new Date(t);
  if (p === 'today') { from = new Date(t); }
  else if (p === 'week') { from = new Date(t); from.setDate(t.getDate() - t.getDay()); }
  else if (p === 'month') { from = new Date(t.getFullYear(), t.getMonth(), 1); }
  else if (p === 'quarter') { from = new Date(t.getFullYear(), Math.floor(t.getMonth() / 3) * 3, 1); }
  else if (p === 'fy') { var fyStartYear = t.getMonth() >= 3 ? t.getFullYear() : t.getFullYear() - 1; from = new Date(fyStartYear, 3, 1); }
  else return;
  document.getElementById('fFrom').value = fmt(from);
  document.getElementById('fTo').value = fmt(to);
  document.getElementById('reportFilterForm').submit();
}
// "What to display?" dialog (Vyapar-style) - lets the user pick which
// columns go into the export and rename the file, for both CSV and PDF.
// The checkbox list is built from the currently-shown report table's own
// <thead> so it always matches whatever columns that tab actually has.
var exportType = 'csv';
function openExportDialog(type) {
  exportType = type;
  var ths = document.querySelectorAll('.content table thead th');
  var list = document.getElementById('exportCheckList');
  list.innerHTML = '';
  ths.forEach(function (th, i) {
    var label = th.innerText.trim() || ('Column ' + (i + 1));
    var row = document.createElement('label');
    row.className = 'modal-check-row';
    row.innerHTML = '<input type="checkbox" checked data-col="' + i + '"> <span>' + label + '</span>';
    list.appendChild(row);
  });
  document.getElementById('exportFname').value = 'report-<?= e($r) ?>-<?= e($from) ?>-to-<?= e($to) ?>';
  document.getElementById('exportModal').classList.add('show');
}
function closeExportDialog() {
  document.getElementById('exportModal').classList.remove('show');
}
function applyExport() {
  var cols = [];
  document.querySelectorAll('#exportCheckList input:checked').forEach(function (c) { cols.push(parseInt(c.dataset.col, 10)); });
  var fname = document.getElementById('exportFname').value.trim() || 'report';
  closeExportDialog();
  if (exportType === 'csv') exportCsv(cols, fname); else exportPdf(cols, fname);
}
function exportCsv(cols, fname) {
  var rows = [];
  document.querySelectorAll('.content table').forEach(function (table) {
    table.querySelectorAll('tr').forEach(function (tr) {
      var cells = [];
      tr.querySelectorAll('th,td').forEach(function (c, i) {
        if (cols.indexOf(i) === -1) return;
        cells.push('"' + c.innerText.trim().replace(/"/g, '""') + '"');
      });
      if (cells.length) rows.push(cells.join(','));
    });
  });
  var blob = new Blob(["﻿" + rows.join('\n')], {type: 'text/csv;charset=utf-8'});
  var a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = fname + '.csv';
  a.click();
}
function exportPdf(cols, fname) {
  var url = 'report_pdf.php?r=<?= e($r) ?>&from=<?= e($from) ?>&to=<?= e($to) ?><?= $filterExtra ?>&cols=' + cols.join(',') + '&fname=' + encodeURIComponent(fname);
  window.open(url, '_blank');
}
</script>

<?php include __DIR__ . '/includes/report_body.php'; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
