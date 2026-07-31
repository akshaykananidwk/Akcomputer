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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'send_aging_reminder' && can('payments.view')) {
    require_once __DIR__ . '/includes/billimage.php';
    $mobile = post('mobile');
    $amount = (float)post('amount');
    if (!$mobile || $amount <= 0.009) { flash('Missing mobile number or amount.', 'error'); redirect('reports.php?r=aging'); }
    $shopName = setting('app_name', 'AK Computer');
    $imgDir = __DIR__ . '/uploads/reminders';
    if (!is_dir($imgDir)) mkdir($imgDir, 0755, true);
    $imgName = 'reminder_' . preg_replace('/\D/', '', $mobile) . '_' . substr(md5(microtime()), 0, 6) . '.jpg';
    file_put_contents($imgDir . '/' . $imgName, reminder_image_jpg($shopName, $amount));
    $imgUrl = base_url('uploads/reminders/' . $imgName);
    $msg = wa_template('aging_reminder', ['amount' => money($amount), 'shop' => $shopName, 'customer' => post('pname')]);
    if (send_whatsapp($mobile, $msg, $imgUrl)) {
        log_activity('aging_reminder_whatsapp', post('pname') . ' ' . $mobile . ' ₹' . money($amount));
        flash('Reminder sent on WhatsApp to ' . $mobile . '.');
    } else {
        flash('WhatsApp send failed. ' . whatsapp_last_error(), 'error');
    }
    redirect('reports.php?r=aging');
}

// Bulk: send a WhatsApp reminder to every party ticked on the Aging report.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'send_aging_bulk' && can('payments.view')) {
    require_once __DIR__ . '/includes/billimage.php';
    $shopName = setting('app_name', 'AK Computer');
    $imgDir = __DIR__ . '/uploads/reminders';
    if (!is_dir($imgDir)) mkdir($imgDir, 0755, true);
    // Each ticked row arrives as "mobile|amount|name" in rem[], so a party's
    // amount and name can never drift onto another party's number.
    $rows = post('rem', []);
    $sent = 0; $failed = 0;
    foreach ($rows as $i => $val) {
        list($mobile, $amountRaw, $pname) = array_pad(explode('|', (string)$val, 3), 3, '');
        $mobile = trim($mobile);
        $amount = (float)$amountRaw;
        if (!$mobile || $amount <= 0.009) { continue; }
        $imgName = 'reminder_' . preg_replace('/\D/', '', $mobile) . '_' . substr(md5(microtime() . $i), 0, 6) . '.jpg';
        file_put_contents($imgDir . '/' . $imgName, reminder_image_jpg($shopName, $amount));
        $imgUrl = base_url('uploads/reminders/' . $imgName);
        $msg = wa_template('aging_reminder', ['amount' => money($amount), 'shop' => $shopName, 'customer' => $pname]);
        if (send_whatsapp($mobile, $msg, $imgUrl)) $sent++; else $failed++;
    }
    log_activity('aging_reminder_bulk', "sent=$sent failed=$failed");
    if ($sent && !$failed) flash("Payment reminder sent on WhatsApp to $sent party(ies).");
    elseif ($sent) flash("Sent to $sent, but $failed failed. " . whatsapp_last_error(), 'error');
    else flash('No reminders sent — tick at least one party with a mobile number. ' . whatsapp_last_error(), 'error');
    redirect('reports.php?r=aging');
}

$r = get('r', 'daily');
$from = get('from', date('Y-m-01'));
$to = get('to', today());
$fCompany = (int)get('f_company');
$fParty = (int)get('f_party');
$fStatus = get('f_status'); // '', 'paid', 'partial', 'due', 'cancelled', 'overdue'
$fUser = (int)get('f_user'); // filter a report by the staff member who made the entry
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
$usersAll = all('SELECT id, name FROM users WHERE is_active = 1 ORDER BY name');

$tabs = [
    'business' => '🏢 Business Report',
    'all_txn' => '📚 All Transactions',
    'daily' => '📅 Daily Sales', 'sales' => '🧾 Sales', 'party_sales' => '👥 Party Sales',
    'aging' => '⏳ Aging / Collection',
    'purchase' => '📦 Purchase', 'payables' => '📆 Purchase Dues Calendar', 'vendor_perf' => '🚚 Vendor Performance', 'stockval' => '📊 Stock Report', 'cashbook' => '💵 Cashbook',
    'bank_ledger' => '🏦 Bank Ledger',
    'expense' => '🧾 Expenses', 'gst' => '🧮 GST', 'profit' => '💹 Product-wise Profit',
    'bill_profit' => '🧮 Bill Profit', 'branch_staff' => '📊 Branch / Staff Comparison', 'staff' => '🎒 Staff Stock',
    'web_visits' => '🌐 Website Visitors',
    'repair_tat' => '🛠️ Repair TAT', 'warranty_tat' => '🛡️ Warranty TAT', 'tech_sla' => '⏱️ Technician SLA',
    'forecast' => '🔮 AI Sales Forecast', 'low' => '⚠️ Low Stock', 'purchase_reco' => '🛒 Purchase Recommendations (AI)', 'dead_stock' => '🐌 Dead / Slow-moving Stock',
    'general_ledger' => '📗 General Ledger', 'trial_balance' => '⚖️ Trial Balance',
    'balance_sheet' => '📑 Balance Sheet', 'profit_loss' => '💹 Profit & Loss',
    'custom' => '🧩 Custom Report Builder',
    'activity' => '🔍 Activity Log', 'login_history' => '🔐 Login History',
    'health' => '🩺 Data Health Check',
];
if (!is_full_admin()) unset($tabs['health']);
if (!can('reports.gst')) unset($tabs['gst']);
if (!can('purchases.view')) unset($tabs['payables']);
if (!can('reports.profit')) { unset($tabs['profit']); unset($tabs['bill_profit']); unset($tabs['stockval']); unset($tabs['business']); unset($tabs['dead_stock']); unset($tabs['branch_staff']); }
if (!can('expenses.view')) unset($tabs['expense']);
if (!can('users.view')) { unset($tabs['activity']); unset($tabs['login_history']); }
if (!can('payments.view')) unset($tabs['bank_ledger']);
// whole-shop money reports (bank passbook, full cashbook) are for the admin /
// whoever explicitly holds cashbank.viewall - a manager only sees own cash
if (!can('cashbank.viewall')) { unset($tabs['bank_ledger']); unset($tabs['cashbook']); }
if (!can('reports.accounting')) { unset($tabs['general_ledger']); unset($tabs['trial_balance']); unset($tabs['balance_sheet']); unset($tabs['profit_loss']); }
if (!can('reports.builder')) unset($tabs['custom']);
if ($r === 'business' && !can('reports.profit')) $r = 'daily';
if ($r === 'branch_staff' && !can('reports.profit')) $r = 'daily';
if (in_array($r, ['activity', 'login_history'], true) && !can('users.view')) $r = 'daily';
if ($r === 'bank_ledger' && !can('payments.view')) $r = 'daily';
if (in_array($r, ['bank_ledger', 'cashbook'], true) && !can('cashbank.viewall')) $r = 'daily';
if (in_array($r, ['general_ledger', 'trial_balance', 'balance_sheet', 'profit_loss'], true) && !can('reports.accounting')) $r = 'daily';
if ($r === 'custom' && !can('reports.builder')) $r = 'daily';
if ($r === 'health' && !is_full_admin()) $r = 'daily';
if ($r === 'payables' && !can('purchases.view')) $r = 'daily';

// Grouped the same way Vyapar's own Reports screen groups its report list -
// a vertical, categorized list (not a horizontal scrolling tab strip) so
// nothing is hidden off-screen to the side.
$tabCategories = [
    'Transaction' => ['business', 'all_txn', 'daily', 'sales', 'purchase', 'payables'],
    'Party Reports' => ['party_sales', 'aging', 'vendor_perf'],
    'GST' => ['gst'],
    'Item / Stock Reports' => ['stockval', 'low', 'purchase_reco', 'dead_stock', 'forecast'],
    'Business Status' => ['cashbook', 'bank_ledger', 'profit', 'bill_profit', 'branch_staff', 'web_visits'],
    'Accounting' => ['general_ledger', 'trial_balance', 'balance_sheet', 'profit_loss'],
    'Expense Reports' => ['expense'],
    'Staff & Service Reports' => ['staff', 'repair_tat', 'warranty_tat', 'tech_sla'],
    'Custom' => ['custom'],
    'Activity' => ['activity', 'login_history', 'health'],
];
?>
<?php
$filterExtra = '&f_company=' . $fCompany . '&f_party=' . $fParty . '&f_status=' . e($fStatus) . '&f_user=' . $fUser . '&bank_id=' . $bankId . '&gl_account=' . $glAccount . '&stock_loc=' . $stockLoc . '&types=' . e(get('types'));
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
    <?php $preset = get('preset'); ?>
    <select id="datePreset" name="preset" onchange="applyPreset(this.value)">
      <option value="">Custom</option>
      <option value="today" <?= $preset === 'today' ? 'selected' : '' ?>>Today</option>
      <option value="week" <?= $preset === 'week' ? 'selected' : '' ?>>This Week</option>
      <option value="month" <?= $preset === 'month' ? 'selected' : '' ?>>This Month</option>
      <option value="quarter" <?= $preset === 'quarter' ? 'selected' : '' ?>>This Quarter</option>
      <option value="fy" <?= $preset === 'fy' ? 'selected' : '' ?>>This Financial Year</option>
    </select>
  </div>
  <div><label>From</label><input type="date" name="from" id="fFrom" value="<?= e($from) ?>" onchange="document.getElementById('datePreset').value=''"></div>
  <div><label>To</label><input type="date" name="to" id="fTo" value="<?= e($to) ?>" onchange="document.getElementById('datePreset').value=''"></div>
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
  <input type="hidden" name="f_user" id="f_user_h" value="<?= $fUser ?>">
  <div><button class="btn btn-sm btn-outline no-print" type="button" onclick="openFilterModal()">🔽 Filters<?php $nf = ($fCompany?1:0)+($fParty?1:0)+($fStatus?1:0)+($fUser?1:0); if ($nf): ?> <span class="badge badge-info" style="padding:1px 7px"><?= $nf ?></span><?php endif; ?></button></div>
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
<?php
// Vyapar-style tabbed Filters sheet. Each tab writes into the same hidden
// f_* inputs the report form already submits; "Apply" just submits that form.
$statusOpts = ['' => 'All', 'paid' => 'Paid', 'due' => 'Unpaid', 'partial' => 'Partial', 'cancelled' => 'Cancelled', 'overdue' => 'Overdue'];
$vyTabs = [
    'firm'   => ['By Firm',   'f_company_h', array_merge([['id' => 0, 'name' => 'All Firms']], $companiesAll), $fCompany],
    'user'   => ['By User',   'f_user_h',    array_merge([['id' => 0, 'name' => 'All Users']], $usersAll), $fUser],
    'party'  => ['By Party',  'f_party_h',   array_merge([['id' => 0, 'name' => 'All Party']], $partiesAll), $fParty],
];
?>
<div class="modal-overlay no-print" id="filterModal">
  <div class="modal-box vyf-box">
    <div class="vyf-head"><h3>Filters</h3><button type="button" class="vyf-x" onclick="closeFilterModal()">✕</button></div>
    <div class="vyf-body">
      <div class="vyf-tabs" id="vyfTabs">
        <?php $first = true; foreach ($vyTabs as $key => $t): ?>
        <button type="button" class="vyf-tab<?= $first ? ' on' : '' ?>" data-tab="<?= $key ?>" onclick="vyfTab('<?= $key ?>')"><?= e($t[0]) ?></button>
        <?php $first = false; endforeach; ?>
        <button type="button" class="vyf-tab" data-tab="status" onclick="vyfTab('status')">By Status</button>
      </div>
      <div class="vyf-panels">
        <?php $first = true; foreach ($vyTabs as $key => $t): [$label, $hid, $opts, $cur] = $t; ?>
        <div class="vyf-panel<?= $first ? ' on' : '' ?>" data-panel="<?= $key ?>">
          <?php foreach ($opts as $o): ?>
          <label class="vyf-opt"><span><?= e($o['name']) ?></span>
            <input type="radio" name="vy_<?= $key ?>" value="<?= (int)$o['id'] ?>" <?= (int)$cur === (int)$o['id'] ? 'checked' : '' ?>
              onclick="document.getElementById('<?= $hid ?>').value=this.value"></label>
          <?php endforeach; ?>
        </div>
        <?php $first = false; endforeach; ?>
        <div class="vyf-panel" data-panel="status">
          <?php foreach ($statusOpts as $sv => $sl): ?>
          <label class="vyf-opt"><span><?= e($sl) ?></span>
            <input type="radio" name="vy_status" value="<?= e($sv) ?>" <?= $fStatus === $sv ? 'checked' : '' ?>
              onclick="document.getElementById('f_status_h').value=this.value"></label>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <div class="vyf-foot">
      <a class="btn btn-outline" href="reports.php?r=<?= e($r) ?>&from=<?= e($from) ?>&to=<?= e($to) ?><?= $preset !== '' ? '&preset=' . e($preset) : '' ?>">Reset</a>
      <button class="btn" type="submit" form="reportFilterForm">Apply</button>
    </div>
  </div>
</div>
<?php if ($fCompany || $fParty || $fStatus || $fUser): ?>
<div class="vyf-chips mb">
  <span class="muted">Filters Applied:</span>
  <?php if ($fCompany): $cn = array_values(array_filter($companiesAll, fn($c) => $c['id'] == $fCompany))[0]['name'] ?? ''; ?><span class="vyf-chip">Firm: <?= e($cn) ?></span><?php endif; ?>
  <?php if ($fUser): $un = array_values(array_filter($usersAll, fn($x) => $x['id'] == $fUser))[0]['name'] ?? ''; ?><span class="vyf-chip">User: <?= e($un) ?></span><?php endif; ?>
  <?php if ($fParty): $pn = array_values(array_filter($partiesAll, fn($p) => $p['id'] == $fParty))[0]['name'] ?? ''; ?><span class="vyf-chip">Party: <?= e($pn) ?></span><?php endif; ?>
  <?php if ($fStatus): ?><span class="vyf-chip">Status: <?= e($statusOpts[$fStatus] ?? ucfirst($fStatus)) ?></span><?php endif; ?>
</div>
<?php endif; endif; ?>
<script>
var TODAY = <?= json_encode(today()) ?>;
// ---- Vyapar-style Filters sheet ----
function openFilterModal() { var m = document.getElementById('filterModal'); if (m) m.classList.add('show'); }
function closeFilterModal() { var m = document.getElementById('filterModal'); if (m) m.classList.remove('show'); }
function vyfTab(key) {
  document.querySelectorAll('#filterModal .vyf-tab').forEach(function (b) { b.classList.toggle('on', b.dataset.tab === key); });
  document.querySelectorAll('#filterModal .vyf-panel').forEach(function (p) { p.classList.toggle('on', p.dataset.panel === key); });
}
(function () { var m = document.getElementById('filterModal'); if (m) m.addEventListener('click', function (e) { if (e.target === m) closeFilterModal(); }); })();
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
