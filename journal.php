<?php
// Journal / Adjustment Entries: manual double-entry postings against the
// Chart of Accounts, for anything the day-to-day Sale/Purchase/Payment/
// Expense screens don't already cover on their own - opening balances,
// corrections, depreciation, and similar accounting adjustments. Every
// entry must balance (total debit = total credit) before it can be saved.
require_once __DIR__ . '/includes/init.php';
require_perm('accounting.view');
$u = current_user();
$action = get('action', 'list');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'save') {
    require_perm('accounting.add');
    $entry_date = post('entry_date', today());
    if (is_period_locked($entry_date)) { flash(period_lock_message(), 'error'); redirect('journal.php?action=new'); }
    $accountIds = post('account_id', []);
    $debits = post('debit', []);
    $credits = post('credit', []);
    $notes = post('line_notes', []);

    $lines = [];
    foreach ($accountIds as $i => $aid) {
        $aid = (int)$aid;
        $d = round((float)($debits[$i] ?? 0), 2);
        $c = round((float)($credits[$i] ?? 0), 2);
        if (!$aid || ($d <= 0 && $c <= 0)) continue;
        if ($d > 0 && $c > 0) { flash('A single line can\'t have both a debit and a credit - use two lines.', 'error'); redirect('journal.php?action=new'); }
        $lines[] = ['account_id' => $aid, 'debit' => $d, 'credit' => $c, 'notes' => trim((string)($notes[$i] ?? ''))];
    }
    if (count($lines) < 2) { flash('A journal entry needs at least two lines.', 'error'); redirect('journal.php?action=new'); }
    $totalDebit = array_sum(array_column($lines, 'debit'));
    $totalCredit = array_sum(array_column($lines, 'credit'));
    if (abs($totalDebit - $totalCredit) > 0.009) {
        flash('Total debit (₹' . money($totalDebit) . ') must equal total credit (₹' . money($totalCredit) . ').', 'error');
        redirect('journal.php?action=new');
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $ref = next_journal_ref();
        q('INSERT INTO journal_entries (ref_no, entry_date, narration, source, created_by) VALUES (?,?,?,?,?)',
          [$ref, $entry_date, post('narration'), 'manual', $u['id']]);
        $eid = insert_id();
        foreach ($lines as $l) {
            q('INSERT INTO journal_lines (entry_id, account_id, debit, credit, notes) VALUES (?,?,?,?,?)',
              [$eid, $l['account_id'], $l['debit'], $l['credit'], $l['notes']]);
        }
        $pdo->commit();
        log_activity('journal_add', "$ref ₹" . money($totalDebit));
        flash("Journal entry $ref saved.");
        redirect('journal.php');
    } catch (Exception $ex) {
        $pdo->rollBack();
        flash('Error: ' . $ex->getMessage(), 'error');
        redirect('journal.php?action=new');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'delete') {
    require_perm('accounting.edit');
    $eid = (int)post('id');
    $entry = row('SELECT * FROM journal_entries WHERE id = ?', [$eid]);
    if ($entry) {
        if (is_period_locked($entry['entry_date'])) { flash(period_lock_message(), 'error'); redirect('journal.php'); }
        q('DELETE FROM journal_lines WHERE entry_id = ?', [$eid]);
        q('DELETE FROM journal_entries WHERE id = ?', [$eid]);
        log_activity('journal_delete', $entry['ref_no']);
        flash('Journal entry ' . $entry['ref_no'] . ' deleted.');
    }
    redirect('journal.php');
}

if ($action === 'new') {
    require_perm('accounting.add');
    $accounts = coa_all();
    $page_title = 'New Journal Entry';
    include __DIR__ . '/includes/header.php';
    ?>
    <form method="post" id="jForm">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="save">
      <div class="card">
        <div class="form-row cols-2">
          <div><label>Date</label><input type="date" name="entry_date" value="<?= today() ?>"></div>
          <div><label>Narration</label><input type="text" name="narration" placeholder="What is this entry for?"></div>
        </div>
      </div>
      <div class="card">
        <h3>Lines</h3>
        <div id="jLines"></div>
        <button type="button" class="btn btn-outline btn-sm" id="jAddLine">+ Add Line</button>
        <div class="mt" style="display:flex;gap:24px;font-weight:600">
          <span>Total Debit: ₹<span id="jTotD">0.00</span></span>
          <span>Total Credit: ₹<span id="jTotC">0.00</span></span>
          <span id="jDiff" class="badge badge-warn">Not balanced</span>
        </div>
      </div>
      <button class="btn btn-block" type="submit">💾 Save Journal Entry</button>
    </form>
    <script>
    var ACCOUNTS = <?= json_encode(array_map(fn($a) => ['id' => $a['id'], 'label' => $a['code'] . ' - ' . $a['name']], $accounts)) ?>;
    var n = 0;
    function jAddLine() {
      var i = n++;
      var div = document.createElement('div');
      div.className = 'form-row cols-4 mt';
      var opts = ACCOUNTS.map(function (a) { return '<option value="' + a.id + '">' + a.label + '</option>'; }).join('');
      div.innerHTML =
        '<div><label>Account</label><select name="account_id[]"><option value="">-- select --</option>' + opts + '</select></div>' +
        '<div><label>Debit ₹</label><input type="number" step="any" min="0" name="debit[]" value="0" class="j-debit"></div>' +
        '<div><label>Credit ₹</label><input type="number" step="any" min="0" name="credit[]" value="0" class="j-credit"></div>' +
        '<div><label>Notes</label><input type="text" name="line_notes[]"></div>';
      document.getElementById('jLines').appendChild(div);
      div.querySelector('.j-debit').addEventListener('input', function () { if (parseFloat(this.value) > 0) div.querySelector('.j-credit').value = 0; jTotals(); });
      div.querySelector('.j-credit').addEventListener('input', function () { if (parseFloat(this.value) > 0) div.querySelector('.j-debit').value = 0; jTotals(); });
    }
    function jTotals() {
      var d = 0, c = 0;
      document.querySelectorAll('.j-debit').forEach(function (i) { d += parseFloat(i.value) || 0; });
      document.querySelectorAll('.j-credit').forEach(function (i) { c += parseFloat(i.value) || 0; });
      document.getElementById('jTotD').textContent = d.toFixed(2);
      document.getElementById('jTotC').textContent = c.toFixed(2);
      var diff = document.getElementById('jDiff');
      if (Math.abs(d - c) < 0.01 && d > 0) { diff.textContent = '✓ Balanced'; diff.className = 'badge badge-ok'; }
      else { diff.textContent = 'Not balanced'; diff.className = 'badge badge-warn'; }
    }
    document.getElementById('jAddLine').addEventListener('click', jAddLine);
    jAddLine(); jAddLine();
    </script>
    <?php
    include __DIR__ . '/includes/footer.php';
    exit;
}

// ---------- list ----------
$entries = all('SELECT je.*, u2.name by_name,
                (SELECT SUM(debit) FROM journal_lines WHERE entry_id = je.id) total
                FROM journal_entries je JOIN users u2 ON u2.id = je.created_by
                ORDER BY je.id DESC LIMIT 200');
$page_title = 'Journal & Adjustment Entries';
include __DIR__ . '/includes/header.php';
?>
<div class="page-actions">
  <?php if (can('accounting.add')): ?><a class="btn" href="journal.php?action=new">+ New Journal Entry</a><?php endif; ?>
  <a class="btn btn-outline" href="accounts.php">Chart of Accounts</a>
</div>
<div class="table-wrap">
<table>
  <thead><tr><th>Ref</th><th>Date</th><th>Narration</th><th class="num">Amount</th><th>By</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($entries as $e): ?>
  <tr>
    <td><a href="journal.php?action=view&id=<?= $e['id'] ?>"><strong><?= e($e['ref_no']) ?></strong></a></td>
    <td><?= dmy($e['entry_date']) ?></td>
    <td><?= e($e['narration']) ?></td>
    <td class="num">₹<?= money($e['total']) ?></td>
    <td><?= e($e['by_name']) ?></td>
    <td>
      <?php if (can('accounting.edit')): ?>
      <form method="post" style="display:inline" onsubmit="return confirm('Delete this journal entry?')"><?= csrf_field() ?>
        <input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= $e['id'] ?>">
        <button class="btn btn-sm btn-danger" type="submit">✕</button></form>
      <?php endif; ?>
    </td>
  </tr>
  <?php endforeach; if (!$entries): ?><tr><td colspan="6" class="muted">No journal entries yet.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
<?php if ($action === 'view' && (int)get('id')):
  $eid = (int)get('id');
  $entry = row('SELECT je.*, u2.name by_name FROM journal_entries je JOIN users u2 ON u2.id = je.created_by WHERE je.id = ?', [$eid]);
  if ($entry):
    $lines = all('SELECT jl.*, ca.code, ca.name acc_name FROM journal_lines jl JOIN chart_of_accounts ca ON ca.id = jl.account_id WHERE jl.entry_id = ? ORDER BY jl.id', [$eid]);
?>
<div class="card">
  <h2><?= e($entry['ref_no']) ?> <span class="muted" style="font-weight:400;font-size:14px"><?= dmy($entry['entry_date']) ?> · <?= e($entry['by_name']) ?></span></h2>
  <p class="muted"><?= e($entry['narration']) ?></p>
  <div class="table-wrap">
  <table class="table-sm">
    <thead><tr><th>Account</th><th class="num">Debit ₹</th><th class="num">Credit ₹</th><th>Notes</th></tr></thead>
    <tbody><?php foreach ($lines as $l): ?>
    <tr><td><?= e($l['code']) ?> - <?= e($l['acc_name']) ?></td>
      <td class="num"><?= $l['debit'] > 0 ? money($l['debit']) : '' ?></td>
      <td class="num"><?= $l['credit'] > 0 ? money($l['credit']) : '' ?></td>
      <td><?= e($l['notes']) ?></td></tr>
    <?php endforeach; ?></tbody>
  </table>
  </div>
</div>
<?php endif; endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
