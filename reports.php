<?php
// Reports hub: sales / purchase / GST / profit / staff stock / repair TAT / warranty company TAT
require_once __DIR__ . '/includes/init.php';
require_perm('reports.view');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'save_custom_report') {
    require_perm('reports.builder');
    $u = current_user();
    $name = trim(post('name'));
    $source = post('source');
    if ($name === '' || !isset(custom_report_sources()[$source])) { flash('Give the report a name.', 'error'); redirect('reports.php?r=custom'); }
    q('INSERT INTO custom_reports (name, source, columns_json, group_by, sort_by, created_by) VALUES (?,?,?,?,?,?)',
      [$name, $source, json_encode(post('columns', [])), post('group_by'), post('sort_by'), $u['id']]);
    flash('Report "' . $name . '" saved.');
    redirect('reports.php?r=custom');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'delete_custom_report') {
    require_perm('reports.builder');
    q('DELETE FROM custom_reports WHERE id = ?', [(int)post('id')]);
    flash('Saved report deleted.');
    redirect('reports.php?r=custom');
}

$r = get('r', 'daily');
$from = get('from', date('Y-m-01'));
$to = get('to', today());
$fCompany = (int)get('f_company');
$fParty = (int)get('f_party');
$fStatus = get('f_status'); // '', 'paid', 'partial', 'due'
$banksAll = all('SELECT * FROM bank_accounts WHERE is_active = 1 ORDER BY is_default DESC, account_name');
$bankId = (int)get('bank_id') ?: (int)($banksAll[0]['id'] ?? 0);
$coaAll = can('reports.accounting') ? coa_all() : [];
$glAccount = (int)get('gl_account') ?: (int)($coaAll[0]['id'] ?? 0);
$locsAll = all('SELECT id, name FROM locations WHERE is_active = 1 ORDER BY name');
$stockLoc = (int)get('stock_loc');
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
    'expense' => '🧾 Expenses', 'gst' => '🧮 GST', 'profit' => '💹 Product-wise Profit',
    'bill_profit' => '🧮 Bill Profit', 'branch_staff' => '📊 Branch / Staff Comparison', 'staff' => '🎒 Staff Stock',
    'repair_tat' => '🛠️ Repair TAT', 'warranty_tat' => '🛡️ Warranty TAT', 'tech_sla' => '⏱️ Technician SLA',
    'forecast' => '🔮 AI Sales Forecast', 'low' => '⚠️ Low Stock', 'purchase_reco' => '🛒 Purchase Recommendations (AI)', 'dead_stock' => '🐌 Dead / Slow-moving Stock',
    'general_ledger' => '📗 General Ledger', 'trial_balance' => '⚖️ Trial Balance',
    'balance_sheet' => '📑 Balance Sheet', 'profit_loss' => '💹 Profit & Loss',
    'custom' => '🧩 Custom Report Builder',
    'activity' => '🔍 Activity Log', 'login_history' => '🔐 Login History',
];
if (!can('reports.gst')) unset($tabs['gst']);
if (!can('reports.profit')) { unset($tabs['profit']); unset($tabs['bill_profit']); unset($tabs['stockval']); unset($tabs['business']); unset($tabs['dead_stock']); unset($tabs['branch_staff']); }
if (!can('expenses.view')) unset($tabs['expense']);
if (!can('users.view')) { unset($tabs['activity']); unset($tabs['login_history']); }
if (!can('payments.view')) unset($tabs['bank_ledger']);
if (!can('reports.accounting')) { unset($tabs['general_ledger']); unset($tabs['trial_balance']); unset($tabs['balance_sheet']); unset($tabs['profit_loss']); }
if (!can('reports.builder')) unset($tabs['custom']);
if ($r === 'business' && !can('reports.profit')) $r = 'daily';
if ($r === 'branch_staff' && !can('reports.profit')) $r = 'daily';
if (in_array($r, ['activity', 'login_history'], true) && !can('users.view')) $r = 'daily';
if ($r === 'bank_ledger' && !can('payments.view')) $r = 'daily';
if (in_array($r, ['general_ledger', 'trial_balance', 'balance_sheet', 'profit_loss'], true) && !can('reports.accounting')) $r = 'daily';
if ($r === 'custom' && !can('reports.builder')) $r = 'daily';

// Grouped the same way Vyapar's own Reports screen groups its report list -
// a vertical, categorized list (not a horizontal scrolling tab strip) so
// nothing is hidden off-screen to the side.
$tabCategories = [
    'Transaction' => ['business', 'daily', 'sales', 'purchase'],
    'Party Reports' => ['party_sales', 'aging', 'vendor_perf'],
    'GST' => ['gst'],
    'Item / Stock Reports' => ['stockval', 'low', 'purchase_reco', 'dead_stock', 'forecast'],
    'Business Status' => ['cashbook', 'bank_ledger', 'profit', 'bill_profit', 'branch_staff'],
    'Accounting' => ['general_ledger', 'trial_balance', 'balance_sheet', 'profit_loss'],
    'Expense Reports' => ['expense'],
    'Staff & Service Reports' => ['staff', 'repair_tat', 'warranty_tat', 'tech_sla'],
    'Custom' => ['custom'],
    'Activity' => ['activity', 'login_history'],
];
?>
<?php
$filterExtra = '&f_company=' . $fCompany . '&f_party=' . $fParty . '&f_status=' . e($fStatus) . '&bank_id=' . $bankId . '&gl_account=' . $glAccount . '&stock_loc=' . $stockLoc;
$filterFamily = in_array($r, ['daily', 'sales', 'party_sales', 'aging', 'purchase', 'vendor_perf', 'gst', 'profit', 'bill_profit'], true);
$curLabel = preg_replace('/^\S+\s/u', '', $tabs[$r] ?? 'Report');
?>
<div class="page-actions no-print" style="flex-wrap:wrap;align-items:center">
  <button type="button" class="btn btn-sm btn-outline" id="toggleReportsList">📋 <?= e($curLabel) ?> ▾</button>
  <span id="favChips" class="fav-chips"></span>
</div>

<div class="card" id="reportsListPanel" style="display:none">
  <div class="report-cat" id="favCat" style="display:none">
    <h4 class="report-cat-h">⭐ Favorites</h4>
    <div id="favRows"></div>
  </div>
  <?php foreach ($tabCategories as $catName => $catKeys):
      $catTabs = array_intersect_key($tabs, array_flip($catKeys));
      if (!$catTabs) continue; ?>
  <div class="report-cat">
    <h4 class="report-cat-h"><?= e($catName) ?></h4>
    <?php foreach ($catTabs as $k => $label): ?>
    <div class="report-row" data-key="<?= $k ?>">
      <a href="reports.php?r=<?= $k ?>&from=<?= e($from) ?>&to=<?= e($to) ?><?= $filterExtra ?>" class="report-row-link <?= $r === $k ? 'active' : '' ?>"><?= e(preg_replace('/^\S+\s/u', '', $label)) ?></a>
      <button type="button" class="star-btn" data-key="<?= $k ?>" title="Favorite - pins to the top">☆</button>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endforeach; ?>
</div>
<script>
(function () {
  var KEY = 'reportFavorites';
  function getFavs() { try { return JSON.parse(localStorage.getItem(KEY) || '[]'); } catch (e) { return []; } }
  function setFavs(f) { localStorage.setItem(KEY, JSON.stringify(f)); }
  var favCat = document.getElementById('favCat');
  var favRows = document.getElementById('favRows');
  var favChips = document.getElementById('favChips');

  function bindStar(b) {
    b.addEventListener('click', function (ev) {
      ev.preventDefault(); ev.stopPropagation();
      var k = b.dataset.key;
      var f = getFavs();
      var i = f.indexOf(k);
      if (i > -1) f.splice(i, 1); else f.push(k);
      setFavs(f);
      render();
    });
  }

  function render() {
    var favs = getFavs();
    document.querySelectorAll('.star-btn').forEach(function (b) {
      var on = favs.indexOf(b.dataset.key) > -1;
      b.textContent = on ? '★' : '☆';
      b.classList.toggle('is-fav', on);
    });
    favRows.innerHTML = '';
    var chipsHtml = '';
    favs.forEach(function (k) {
      var row = document.querySelector('.report-cat:not(#favCat) .report-row[data-key="' + k + '"]');
      if (!row) return;
      var clone = row.cloneNode(true);
      favRows.appendChild(clone);
      var link = row.querySelector('.report-row-link');
      chipsHtml += '<a class="btn btn-sm btn-outline fav-chip" href="' + link.getAttribute('href') + '">' + link.textContent + '</a>';
    });
    favCat.style.display = favs.length ? '' : 'none';
    favRows.querySelectorAll('.star-btn').forEach(bindStar);
    favChips.innerHTML = chipsHtml;
  }
  document.querySelectorAll('.star-btn').forEach(bindStar);
  render();

  document.getElementById('toggleReportsList').addEventListener('click', function () {
    var p = document.getElementById('reportsListPanel');
    p.style.display = p.style.display === 'none' ? '' : 'none';
  });
})();
</script>

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
  <?php if ($r === 'general_ledger'): ?>
  <div><label>Account</label>
    <select name="gl_account" onchange="document.getElementById('reportFilterForm').submit()">
      <?php foreach ($coaAll as $a): ?>
      <option value="<?= $a['id'] ?>" <?= $glAccount == $a['id'] ? 'selected' : '' ?>><?= e($a['code']) ?> - <?= e($a['name']) ?></option>
      <?php endforeach; ?>
    </select></div>
  <?php endif; ?>
  <?php if (in_array($r, ['low', 'dead_stock'], true)): ?>
  <div><label>Location</label>
    <select name="stock_loc" onchange="document.getElementById('reportFilterForm').submit()">
      <option value="0">All locations</option>
      <?php foreach ($locsAll as $l): ?>
      <option value="<?= $l['id'] ?>" <?= $stockLoc == $l['id'] ? 'selected' : '' ?>><?= e($l['name']) ?></option>
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
  <button class="btn btn-sm btn-outline no-print" type="button" onclick="openExportDialog('csv')">⬇ CSV</button>
  <button class="btn btn-sm btn-outline no-print" type="button" onclick="openExportDialog('xlsx')">⬇ Excel</button>
  <button class="btn btn-sm btn-outline no-print" type="button" onclick="openExportDialog('pdf')">⬇ PDF</button>
  <?php $tallyType = ['sales' => 'sales', 'purchase' => 'purchase', 'cashbook' => 'payments', 'expense' => 'expenses'][$r] ?? null; ?>
  <?php if ($tallyType): ?>
  <a class="btn btn-sm btn-outline no-print" href="tally_export.php?type=<?= $tallyType ?>&from=<?= e($from) ?>&to=<?= e($to) ?>">⬇ Tally XML</a>
  <?php endif; ?>
</form>
<?= render_saved_filters(current_user()['id'], 'reports_' . $r) ?>

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
  if (exportType === 'csv') exportCsv(cols, fname);
  else if (exportType === 'xlsx') exportXlsx(cols, fname);
  else exportPdf(cols, fname);
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
function exportXlsx(cols, fname) {
  var url = 'report_xlsx.php?r=<?= e($r) ?>&from=<?= e($from) ?>&to=<?= e($to) ?><?= $filterExtra ?>&cols=' + cols.join(',') + '&fname=' + encodeURIComponent(fname);
  window.open(url, '_blank');
}
function exportPdf(cols, fname) {
  var url = 'report_pdf.php?r=<?= e($r) ?>&from=<?= e($from) ?>&to=<?= e($to) ?><?= $filterExtra ?>&cols=' + cols.join(',') + '&fname=' + encodeURIComponent(fname);
  window.open(url, '_blank');
}
</script>

<?php include __DIR__ . '/includes/report_body.php'; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
