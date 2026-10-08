<?php
// 📒 Books: the year for income tax, monthly budgets, loans and their EMIs,
// the shop's assets with depreciation, partners' share of the profit, and
// closing the year. Money moves only through ordinary expenses (an EMI paid
// is one "EMI / Loan" expense); everything else here is worked out.
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/books.php';
require_perm('books.view');
$u = current_user();
$tab = get('tab', 'year');
[$fs, $fe, $fyl] = fy_of(get('fy') ? get('fy') . '-04-01' : null);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_perm('books.edit');
    $do = post('do');
    if ($do === 'budget') {
        foreach ((array)post('cat', []) as $i => $cat) {
            $amt = (float)(post('amt', [])[$i] ?? 0);
            if ($amt > 0) q('INSERT INTO expense_budgets (category, monthly) VALUES (?,?) ON DUPLICATE KEY UPDATE monthly = VALUES(monthly)', [mb_substr($cat, 0, 80), $amt]);
            else q('DELETE FROM expense_budgets WHERE category = ?', [$cat]);
        }
        flash('Budgets saved.');
    }
    if ($do === 'loan') {
        $p = (float)post('principal'); $r = (float)post('rate'); $n = (int)post('months'); $start = post('start_date');
        if ($p <= 0 || $n < 1 || $n > 480 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$start)) { flash('Fill the amount, months and first EMI date.', 'error'); redirect('books.php?tab=loans'); }
        $pdo = db(); $pdo->beginTransaction();
        q('INSERT INTO loans (lender, principal, rate_pct, start_date, months, emi, notes) VALUES (?,?,?,?,?,?,?)', [mb_substr(post('lender'), 0, 120), $p, $r, $start, $n, loan_emi($p, $r, $n), mb_substr((string)post('notes'), 0, 255)]);
        $lid = insert_id();
        foreach (loan_schedule($p, $r, $n, $start) as $s) q('INSERT INTO loan_payments (loan_id, n, due_date, emi, interest, principal_part) VALUES (?,?,?,?,?,?)', [$lid, $s['n'], $s['due'], $s['emi'], $s['interest'], $s['principal']]);
        $pdo->commit();
        log_activity('loan_add', post('lender') . ' ' . $p);
        flash('Loan added with its EMI plan.');
    }
    if ($do === 'emi_paid') {
        $lp = row('SELECT lp.*, l.lender FROM loan_payments lp JOIN loans l ON l.id = lp.loan_id WHERE lp.id = ? AND lp.paid_on IS NULL', [(int)post('id')]);
        if ($lp) {
            if (is_period_locked(today())) { flash(period_lock_message(), 'error'); redirect('books.php?tab=loans'); }
            $mode = post('mode', 'bank'); [$pmId, $bankId] = resolve_payment_target($mode, null);
            $pdo = db(); $pdo->beginTransaction();
            q('INSERT INTO expenses (exp_date, category, amount, mode, bank_account_id, payment_method_id, notes, location_id, created_by) VALUES (?,?,?,?,?,?,?,?,?)',
              [today(), 'EMI / Loan', $lp['emi'], $mode, $bankId, $pmId, 'EMI ' . $lp['n'] . ' — ' . $lp['lender'] . ' (principal ' . money($lp['principal_part']) . ' + interest ' . money($lp['interest']) . ')', $u['location_id'], $u['id']]);
            q('UPDATE loan_payments SET paid_on = ?, expense_id = ? WHERE id = ? AND paid_on IS NULL', [today(), insert_id(), $lp['id']]);
            $pdo->commit();
            log_activity('emi_paid', $lp['lender'] . ' #' . $lp['n']);
            flash('EMI ' . $lp['n'] . ' paid — written as an EMI / Loan expense.');
        }
    }
    if ($do === 'asset') {
        if (trim((string)post('name')) !== '' && (float)post('cost') > 0 && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)post('bought_on')))
            q('INSERT INTO assets (name, category, bought_on, cost, rate_pct, notes) VALUES (?,?,?,?,?,?)', [mb_substr(post('name'), 0, 150), mb_substr((string)post('category'), 0, 60), post('bought_on'), (float)post('cost'), max(0, min(100, (float)post('rate_pct'))), mb_substr((string)post('notes'), 0, 255)]);
        flash('Asset added.');
    }
    if ($do === 'asset_out') q('UPDATE assets SET disposed_on = ? WHERE id = ?', [today(), (int)post('id')]);
    if ($do === 'partner') {
        if (trim((string)post('name')) !== '' && (float)post('share_pct') > 0) q('INSERT INTO partners (name, share_pct, mobile) VALUES (?,?,?)', [mb_substr(post('name'), 0, 120), min(100, (float)post('share_pct')), mb_substr((string)post('mobile'), 0, 20)]);
    }
    if ($do === 'partner_off') q('UPDATE partners SET is_active = 0 WHERE id = ?', [(int)post('id')]);
    if ($do === 'close_year') {
        if (!is_full_admin()) { flash('Only the owner can close a year.', 'error'); redirect('books.php?tab=close'); }
        set_setting('period_lock_date', $fe);
        log_activity('year_close', $fyl . ' locked to ' . $fe);
        flash("Year $fyl closed — nothing dated on or before " . dmy($fe) . ' can be changed now.');
    }
    redirect('books.php?tab=' . urlencode($tab) . '&fy=' . substr($fs, 0, 4));
}

$page_title = 'Books';
include __DIR__ . '/includes/header.php';
$tabs = ['year' => '📊 Year summary (income tax)', 'budget' => '🎯 Budget', 'loans' => '🏦 Loans & EMI', 'assets' => '🖥️ Assets', 'partners' => '🤝 Partners', 'close' => '🔒 Close the year'];
$yrs = []; for ($y = (int)date('Y') - (date('n') < 4 ? 1 : 0); $y >= (int)date('Y') - 6; $y--) $yrs[] = $y;
?>
<div class="page-head"><h1>📒 Books</h1>
  <form><input type="hidden" name="tab" value="<?= e($tab) ?>"><select name="fy" onchange="this.form.submit()"><?php foreach ($yrs as $y): ?><option value="<?= $y ?>" <?= substr($fs, 0, 4) == $y ? 'selected' : '' ?>>FY <?= $y ?>-<?= substr((string)($y + 1), 2) ?></option><?php endforeach; ?></select></form></div>
<div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px"><?php foreach ($tabs as $k => $l): ?><a class="btn btn-sm <?= $tab === $k ? '' : 'btn-outline' ?>" href="books.php?tab=<?= $k ?>&amp;fy=<?= substr($fs, 0, 4) ?>"><?= $l ?></a><?php endforeach; ?></div>

<?php if ($tab === 'year'): $s = it_summary($fs, min($fe, today())); ?>
<div class="card"><h3 style="margin-top:0">FY <?= e($fyl) ?> <span class="muted" style="font-weight:normal">(<?= dmy($fs) ?> – <?= dmy(min($fe, today())) ?>)</span></h3>
<table class="rowlist"><tbody>
  <tr><td>Sales (after returns)</td><td class="num">₹<?= money($s['revenue']) ?></td></tr>
  <tr><td>Cost of goods sold</td><td class="num">− ₹<?= money($s['cogs']) ?></td></tr>
  <tr><td><b>Gross profit</b></td><td class="num"><b>₹<?= money($s['gross']) ?></b></td></tr>
  <?php foreach ($s['by_category'] as $c => $v): ?><tr><td style="padding-left:20px"><?= e($c) ?></td><td class="num muted">₹<?= money($v) ?></td></tr><?php endforeach; ?>
  <?php if ($s['loan_principal'] > 0): ?><tr><td style="padding-left:20px">less: loan principal inside EMIs (not a cost)</td><td class="num muted">− ₹<?= money($s['loan_principal']) ?></td></tr><?php endif; ?>
  <tr><td>Business expenses</td><td class="num">− ₹<?= money($s['expenses']) ?></td></tr>
  <tr><td>Depreciation on assets</td><td class="num">− ₹<?= money($s['depreciation']) ?></td></tr>
  <tr><td><b>Net profit</b></td><td class="num"><b class="<?= $s['net'] < 0 ? 'text-danger' : '' ?>">₹<?= money($s['net']) ?></b></td></tr>
  <tr><td class="muted">Owner's household spending (drawings, not a business cost)</td><td class="num muted">₹<?= money($s['drawings']) ?></td></tr>
</tbody></table><button class="btn btn-sm btn-outline no-print mt" onclick="print()">🖨️ Print for the CA</button></div>

<?php elseif ($tab === 'budget'): $st = []; foreach (budget_status() as $b) $st[$b['category']] = $b; ?>
<form method="post" class="pane"><?= csrf_field() ?><input type="hidden" name="do" value="budget">
<div class="pane-head"><h3>Monthly budget — <?= date('F Y') ?></h3></div><div class="pane-body tight"><table class="rowlist"><thead><tr><th>Category</th><th>Budget ₹/month</th><th>Spent this month</th></tr></thead><tbody>
<?php foreach (expense_categories() as $cat): if (expense_is_home($cat)) continue; $b = $st[$cat] ?? null; ?>
  <tr><td data-l="Category"><?= e($cat) ?><input type="hidden" name="cat[]" value="<?= e($cat) ?>"></td>
    <td data-l="Budget"><input type="number" name="amt[]" min="0" step="100" value="<?= $b ? +$b['budget'] : '' ?>" style="width:110px" placeholder="—"></td>
    <td data-l="Spent"><?php if ($b): ?><div style="background:var(--line);border-radius:6px;height:8px;max-width:180px"><div style="height:8px;border-radius:6px;width:<?= min(100, $b['pct']) ?>%;background:<?= $b['pct'] > 100 ? 'var(--bad)' : ($b['pct'] > 80 ? 'var(--warn)' : 'var(--ok)') ?>"></div></div>
      <small>₹<?= money($b['spent']) ?> · <?= $b['pct'] ?>%<?= $b['pct'] > 100 ? ' — over!' : '' ?></small><?php endif; ?></td></tr>
<?php endforeach; ?></tbody></table></div>
<?php if (can('books.edit')): ?><div style="padding:10px"><button class="btn" type="submit">💾 Save budgets</button> <span class="muted">You are warned when an expense takes a category over its budget.</span></div><?php endif; ?></form>

<?php elseif ($tab === 'loans'): $loans = all('SELECT * FROM loans ORDER BY is_closed, id DESC'); ?>
<?php if (can('books.edit')): ?>
<form method="post" class="card" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:8px;align-items:end"><?= csrf_field() ?><input type="hidden" name="do" value="loan">
  <div class="field"><label>Bank / lender</label><input name="lender" required></div><div class="field"><label>Loan ₹</label><input type="number" name="principal" min="1" required></div>
  <div class="field"><label>Interest % a year</label><input type="number" name="rate" step="0.01" min="0" value="12"></div><div class="field"><label>Months</label><input type="number" name="months" min="1" max="480" value="12"></div>
  <div class="field"><label>First EMI date</label><input type="date" name="start_date" required></div><button class="btn" type="submit">➕ Add loan</button></form>
<?php endif; ?>
<?php foreach ($loans as $l): $sch = all('SELECT * FROM loan_payments WHERE loan_id = ? ORDER BY n', [$l['id']]); $paid = array_filter($sch, fn($x) => $x['paid_on']);
  $left = round((float)$l['principal'] - array_sum(array_column($paid, 'principal_part')), 2); $next = array_values(array_filter($sch, fn($x) => !$x['paid_on']))[0] ?? null; ?>
<div class="card"><b><?= e($l['lender']) ?></b> — ₹<?= money($l['principal']) ?> at <?= +$l['rate_pct'] ?>% for <?= (int)$l['months'] ?> months · EMI <b>₹<?= money($l['emi']) ?></b>
  <div class="muted"><?= count($paid) ?>/<?= count($sch) ?> paid · still owed ₹<?= money($left) ?><?= $next ? ' · next ' . dmy($next['due_date']) : ' · fully paid ✔' ?></div>
  <?php if ($next && can('books.edit')): ?><form method="post" class="mt" style="display:flex;gap:6px;align-items:center"><?= csrf_field() ?><input type="hidden" name="do" value="emi_paid"><input type="hidden" name="id" value="<?= (int)$next['id'] ?>">
    <select name="mode"><option value="bank">Bank</option><option value="cash">Cash</option></select>
    <button class="btn btn-sm" onclick="return confirm('Mark EMI <?= (int)$next['n'] ?> of ₹<?= money($next['emi']) ?> paid? It is written as an expense.')">✔ EMI <?= (int)$next['n'] ?> paid (₹<?= money($next['emi']) ?>)</button></form><?php endif; ?>
  <details class="mt"><summary style="cursor:pointer">EMI plan</summary><table class="rowlist"><thead><tr><th>#</th><th>Due</th><th class="num">EMI</th><th class="num">Interest</th><th class="num">Principal</th><th>Paid</th></tr></thead><tbody>
  <?php foreach ($sch as $x): ?><tr><td><?= (int)$x['n'] ?></td><td><?= dmy($x['due_date']) ?></td><td class="num"><?= money($x['emi']) ?></td><td class="num"><?= money($x['interest']) ?></td><td class="num"><?= money($x['principal_part']) ?></td><td><?= $x['paid_on'] ? '✔ ' . dmy($x['paid_on']) : '' ?></td></tr><?php endforeach; ?>
  </tbody></table></details></div>
<?php endforeach; if (!$loans): ?><div class="card muted">No loans written down.</div><?php endif; ?>

<?php elseif ($tab === 'assets'): $assets = all('SELECT * FROM assets ORDER BY bought_on DESC'); ?>
<?php if (can('books.edit')): ?>
<form method="post" class="card" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:8px;align-items:end"><?= csrf_field() ?><input type="hidden" name="do" value="asset">
  <div class="field"><label>What</label><input name="name" required placeholder="Billing computer"></div>
  <div class="field"><label>Kind</label><select name="category" onchange="this.form.rate_pct.value={'Computer':40,'Furniture':10,'Vehicle':15,'Machinery':15,'Building':10}[this.value]||15"><option>Computer</option><option>Furniture</option><option>Machinery</option><option>Vehicle</option><option>Building</option></select></div>
  <div class="field"><label>Bought on</label><input type="date" name="bought_on" required></div><div class="field"><label>Cost ₹</label><input type="number" name="cost" min="1" required></div>
  <div class="field"><label>Rate %</label><input type="number" name="rate_pct" step="0.01" value="40"></div><button class="btn" type="submit">➕ Add</button></form>
<?php endif; ?>
<div class="pane"><div class="pane-head"><h3>FY <?= e($fyl) ?> — written-down value</h3></div><div class="pane-body tight"><table class="rowlist"><thead><tr><th>Asset</th><th class="num">Opening</th><th class="num">Added</th><th class="num">Depreciation</th><th class="num">Closing</th><th class="act"></th></tr></thead><tbody>
<?php $tot = ['open' => 0, 'add' => 0, 'dep' => 0, 'close' => 0]; foreach ($assets as $a): $d = asset_dep($a, $fs); foreach ($tot as $k => $v) $tot[$k] += $d[$k]; ?>
  <tr><td data-l="Asset"><b><?= e($a['name']) ?></b><br><small class="muted"><?= e($a['category']) ?> · <?= dmy($a['bought_on']) ?> · <?= +$a['rate_pct'] ?>%<?= $a['disposed_on'] ? ' · sold/scrapped ' . dmy($a['disposed_on']) : '' ?></small></td>
    <td class="num">₹<?= money($d['open']) ?></td><td class="num">₹<?= money($d['add']) ?></td><td class="num">₹<?= money($d['dep']) ?></td><td class="num">₹<?= money($d['close']) ?></td>
    <td class="act"><?php if (!$a['disposed_on'] && can('books.edit')): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="asset_out"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><button class="btn btn-sm btn-outline" onclick="return confirm('Mark it sold / scrapped today?')">Sold</button></form><?php endif; ?></td></tr>
<?php endforeach; ?>
  <tr><td><b>Total</b></td><td class="num"><b>₹<?= money($tot['open']) ?></b></td><td class="num"><b>₹<?= money($tot['add']) ?></b></td><td class="num"><b>₹<?= money($tot['dep']) ?></b></td><td class="num"><b>₹<?= money($tot['close']) ?></b></td><td></td></tr>
</tbody></table></div></div>

<?php elseif ($tab === 'partners'): $s = it_summary($fs, min($fe, today())); $sh = partner_shares($s['net']); $sum = array_sum(array_column($sh, 'share_pct')); ?>
<div class="card">Net profit FY <?= e($fyl) ?>: <b>₹<?= money($s['net']) ?></b><?= $sum && abs($sum - 100) > 0.01 ? ' <span class="badge badge-warn">shares add to ' . +$sum . '%</span>' : '' ?></div>
<div class="pane"><div class="pane-body tight"><table class="rowlist"><thead><tr><th>Partner</th><th class="num">Share</th><th class="num">Their part</th><th class="act"></th></tr></thead><tbody>
<?php foreach ($sh as $p): ?><tr><td><b><?= e($p['name']) ?></b> <small class="muted"><?= e($p['mobile']) ?></small></td><td class="num"><?= +$p['share_pct'] ?>%</td><td class="num"><b>₹<?= money($p['amount']) ?></b></td>
  <td class="act"><?php if (can('books.edit')): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="partner_off"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><button class="btn btn-sm btn-outline" onclick="return confirm('Remove this partner?')">✕</button></form><?php endif; ?></td></tr><?php endforeach; ?>
<?php if (!$sh): ?><tr><td class="muted">No partners added.</td></tr><?php endif; ?></tbody></table></div></div>
<?php if (can('books.edit')): ?><form method="post" class="card" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end"><?= csrf_field() ?><input type="hidden" name="do" value="partner">
  <div class="field"><label>Name</label><input name="name" required></div><div class="field"><label>Share %</label><input type="number" name="share_pct" step="0.01" min="0.01" max="100" required></div>
  <div class="field"><label>Mobile</label><input name="mobile"></div><button class="btn" type="submit">➕ Add partner</button></form><?php endif; ?>

<?php else: $lock = setting('period_lock_date', ''); $prevFs = date('Y-m-d', strtotime($fs . ' -1 year')); [, $prevFe, $prevL] = fy_of($prevFs); ?>
<div class="card"><h3 style="margin-top:0">🔒 Close the year</h3>
  <p>Books are now locked up to: <b><?= $lock ? dmy($lock) : 'nothing locked' ?></b>.</p>
  <p>Closing FY <?= e($fyl) ?> locks every bill, payment and expense dated on or before <?= dmy($fe) ?>, so the figures given to the CA can no longer change. Opening balances for the new year carry forward on their own — nothing is copied or reset, and new bills keep their year's numbering.</p>
  <ul><li>Check stock with a <a href="stock_audit.php">stock audit</a>.</li><li>Give the CA the <a href="books.php?tab=year&amp;fy=<?= substr($fs, 0, 4) ?>">year summary</a> and <a href="reports.php">reports</a>.</li><li>Take a <a href="settings.php?cat=backup">backup</a>.</li></ul>
  <?php if (is_full_admin() && $fe < today() && $lock < $fe): ?><form method="post" onsubmit="return confirm('Lock everything up to <?= dmy($fe) ?>? Only an admin can move the lock back in Settings.')"><?= csrf_field() ?><input type="hidden" name="do" value="close_year">
    <button class="btn" type="submit">🔒 Close FY <?= e($fyl) ?></button></form>
  <?php elseif ($fe >= today()): ?><p class="muted">FY <?= e($fyl) ?> has not ended yet — pick an earlier year above.</p><?php endif; ?></div>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
