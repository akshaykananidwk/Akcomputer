<?php
// Payments: Vyapar-style Payment-In / Payment-Out with bill linking,
// party balance display, ledger posting and WhatsApp receipt.
require_once __DIR__ . '/includes/init.php';
require_perm('payments.view');
$u = current_user();
$action = get('action', 'list');

// ---------- save payment (with optional bill allocation) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'save_payment') {
    require_perm('payments.add');
    $party_id = (int)post('party_id');
    $dir = post('direction') === 'out' ? 'out' : 'in';
    $amount = (float)post('amount');
    $party = row('SELECT * FROM parties WHERE id = ?', [$party_id]);
    if (!$party || $amount <= 0) { flash('Party and amount required.', 'error'); redirect('payments.php?action=new&dir=' . $dir); }

    $allocIds = post('alloc_id', []);
    $allocAmts = post('alloc_amt', []);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $allocated = 0;
        $allocNotes = [];
        foreach ($allocIds as $i => $bid) {
            $bid = (int)$bid;
            $amt = round((float)($allocAmts[$i] ?? 0), 2);
            if (!$bid || $amt <= 0) continue;
            if ($dir === 'in') {
                $bill = row('SELECT * FROM sales WHERE id = ? AND party_id = ?', [$bid, $party_id]);
                if (!$bill) continue;
                $amt = min($amt, $bill['total'] - $bill['paid']);
                if ($amt <= 0) continue;
                q('UPDATE sales SET paid = paid + ?, status = ? WHERE id = ?',
                  [$amt, payment_status($bill['total'], $bill['paid'] + $amt), $bid]);
                $allocNotes[] = $bill['invoice_no'] . ': ₹' . money($amt);
            } else {
                $bill = row('SELECT * FROM purchases WHERE id = ? AND party_id = ?', [$bid, $party_id]);
                if (!$bill) continue;
                $amt = min($amt, $bill['total'] - $bill['paid']);
                if ($amt <= 0) continue;
                q('UPDATE purchases SET paid = paid + ?, status = ? WHERE id = ?',
                  [$amt, payment_status($bill['total'], $bill['paid'] + $amt), $bid]);
                $allocNotes[] = ($bill['bill_no'] ?: '#' . $bid) . ': ₹' . money($amt);
            }
            $allocated += $amt;
        }
        if ($allocated > $amount + 0.009) throw new Exception('Linked amount is more than payment amount.');

        $notes = trim(post('notes'));
        if ($allocNotes) $notes = trim($notes . ' [' . implode(', ', $allocNotes) . ']');
        q('INSERT INTO payments (party_id, direction, amount, mode, bank_account_id, payment_method_id, pay_date, notes, created_by) VALUES (?,?,?,?,?,?,?,?,?)',
          [$party_id, $dir, $amount, post('mode', 'cash'), (int)post('bank_account_id') ?: null, (int)post('payment_method_id') ?: null,
           post('pay_date', today()), $notes, $u['id']]);
        $pid = insert_id();
        $pdo->commit();
        log_activity('payment_add', "P-$pid party={$party['name']} $dir $amount");

        // WhatsApp receipt to party (payment-in only)
        if ($dir === 'in' && post('send_wa') && $party['mobile']) {
            $bal = party_balance($party_id);
            $balTxt = $bal > 0.009 ? '₹' . money($bal) . ' (due)' : ($bal < -0.009 ? '₹' . money(-$bal) . ' (advance)' : '₹0.00 (clear)');
            send_whatsapp($party['mobile'], wa_template('payment_receipt', [
                'amount' => money($amount), 'mode' => post('mode', 'cash'), 'date' => dmy(post('pay_date', today())),
                'alloc' => $allocNotes ? 'Against: ' . implode(', ', $allocNotes) . "\n" : '',
                'balance' => $balTxt, 'party' => $party['name'],
            ]));
        }
        flash(($dir === 'in' ? 'Payment-In' : 'Payment-Out') . ' of ₹' . money($amount) . ' saved' . ($allocNotes ? ' & linked to ' . count($allocNotes) . ' bill(s).' : '.'));
        redirect('payments.php');
    } catch (Exception $ex) {
        $pdo->rollBack();
        flash('Error: ' . $ex->getMessage(), 'error');
        redirect('payments.php?action=new&dir=' . $dir);
    }
}

// ---------- WhatsApp reminder ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'remind') {
    $s = row('SELECT s.*, c.name company_name FROM sales s JOIN companies c ON c.id = s.company_id WHERE s.id = ?', [(int)post('sale_id')]);
    if ($s && $s['customer_mobile']) {
        send_whatsapp($s['customer_mobile'], wa_template('reminder', [
            'firm' => $s['company_name'], 'invoice_no' => $s['invoice_no'], 'date' => dmy($s['sale_date']),
            'due' => money($s['total'] - $s['paid']),
            'due_date_line' => $s['due_date'] ? 'Due date: ' . dmy($s['due_date']) . "\n" : '',
        ]));
        flash('Reminder sent on WhatsApp.');
    } else {
        flash('No customer mobile on this bill.', 'error');
    }
    redirect('payments.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'delete') {
    require_perm('payments.delete');
    q('DELETE FROM payments WHERE id = ?', [(int)post('id')]);
    flash('Payment entry deleted. (Note: linked bill paid amounts are not reversed automatically.)');
    redirect('payments.php');
}

// ---------- Payment-In / Payment-Out form ----------
if ($action === 'new') {
    require_perm('payments.add');
    $dir = get('dir') === 'out' ? 'out' : 'in';
    $presetParty = (int)get('party');
    $types = $dir === 'in' ? "('customer','both')" : "('supplier','both','service_center')";
    // ALL active parties of the right type are selectable - not just those
    // with a due, because a party can be receiving/paying an ADVANCE with
    // no bill against it yet. Parties with a pending balance in this
    // direction are just sorted to the top so the common case stays fast.
    $balExpr = party_balance_expr('p');
    $pendingFirst = $dir === 'in' ? "($balExpr > 0.009) DESC, $balExpr DESC" : "($balExpr < -0.009) DESC, $balExpr ASC";
    $parties = all("SELECT p.id, p.name, p.mobile, $balExpr AS balance FROM parties p
                    WHERE p.is_active = 1 AND p.type IN $types
                    ORDER BY $pendingFirst, p.name");
    $pendingCount = count(array_filter($parties, fn($p) => $dir === 'in' ? $p['balance'] > 0.009 : $p['balance'] < -0.009));
    $wDue = $dir === 'in' ? walkin_due() : 0;
    $pms = active_payment_methods();
    $banks = all('SELECT * FROM bank_accounts WHERE is_active = 1 ORDER BY is_default DESC, account_name');
    $page_title = $dir === 'in' ? 'Payment-In (વસૂલી)' : 'Payment-Out (ચુકવણી)';
    include __DIR__ . '/includes/header.php';
    ?>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="save_payment">
      <input type="hidden" name="direction" value="<?= $dir ?>">
      <div class="card">
        <?php if ($pendingCount === 0): ?>
        <div class="flash flash-info">ℹ️ કોઈ party <?= $dir === 'in' ? 'લેવાના' : 'દેવાના' ?> બાકી નથી — છતાં નીચેથી ગમે તે party પસંદ કરી <strong>advance payment</strong> નોંધી શકો છો.</div>
        <?php endif; ?>
        <?php if ($wDue > 0.009): ?>
        <div class="flash flash-info">ℹ️ Walk-in (party વગરના) bills માં ₹<?= money($wDue) ?> બાકી છે — એ અહીં નહીં, સીધું એ bill ખોલીને collect કરો (Sale List માંથી).</div>
        <?php endif; ?>
        <div class="form-row cols-3">
          <div><label><?= $dir === 'in' ? 'Customer / Party *' : 'Supplier / Party *' ?></label>
            <select name="party_id" id="party_id" required>
              <option value="">-- select party --</option>
              <?php if ($pendingCount): ?><optgroup label="<?= $dir === 'in' ? 'લેવાના બાકી' : 'દેવાના બાકી' ?>"><?php endif; ?>
              <?php $inGroup = true; foreach ($parties as $p):
                $pending = $dir === 'in' ? $p['balance'] > 0.009 : $p['balance'] < -0.009;
                if ($inGroup && $pendingCount && !$pending) { echo '</optgroup><optgroup label="બીજી બધી parties">'; $inGroup = false; } ?>
              <option value="<?= $p['id'] ?>" <?= $presetParty === (int)$p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?><?= abs($p['balance']) > 0.009 ? ' (₹' . money(abs($p['balance'])) . ($p['balance'] > 0 ? ' લેવાના' : ' દેવાના') . ')' : '' ?></option>
              <?php endforeach; ?>
              <?php if ($pendingCount): ?></optgroup><?php endif; ?>
            </select>
            <p class="muted mt" id="balInfo"></p>
          </div>
          <div><label>Date</label><input type="date" name="pay_date" value="<?= today() ?>"></div>
          <div><label>Mode</label>
            <select name="mode" id="pay_mode" onchange="pmChange()">
              <?php foreach ($pms as $pm): if ($pm['code'] === 'credit') continue; ?><option value="<?= e($pm['code']) ?>" data-type="<?= e($pm['type']) ?>"><?= e($pm['name']) ?></option><?php endforeach; ?>
            </select></div>
        </div>
        <div class="form-row cols-3">
          <div><label><?= $dir === 'in' ? 'Received amount (₹) *' : 'Paid amount (₹) *' ?></label>
            <input type="number" step="any" min="0.01" name="amount" id="pay_amount" required></div>
          <div id="bankAccBox" style="display:none"><label>Bank Account</label>
            <select name="bank_account_id"><?php foreach ($banks as $b): ?><option value="<?= $b['id'] ?>"><?= e($b['account_name']) ?> - <?= e($b['bank_name']) ?></option><?php endforeach; ?></select></div>
          <div><label>Notes</label><input type="text" name="notes"></div>
        </div>
        <?php if ($dir === 'in'): ?>
        <label class="check-inline"><input type="checkbox" name="send_wa" value="1" checked> Party ને WhatsApp receipt મોકલવી</label>
        <?php endif; ?>
      </div>

      <div class="card">
        <h3>🔗 Bill સાથે link કરો (optional)</h3>
        <p class="muted mb">Party select કરો એટલે એના બાકી bills દેખાશે. "Auto" દબાવો તો જૂનામાં જૂના bill થી આપોઆપ વહેંચાઈ જશે. Link ના કરો તો પણ payment ledger માં જમા થશે જ.</p>
        <div id="billList" class="muted">પહેલા party select કરો.</div>
        <button type="button" class="btn btn-sm btn-outline mt" id="autoAlloc" style="display:none">⚡ Auto-link (oldest first)</button>
      </div>
      <button class="btn btn-block <?= $dir === 'in' ? 'btn-success' : '' ?>" type="submit">💾 Save <?= $dir === 'in' ? 'Payment-In' : 'Payment-Out' ?></button>
    </form>
    <script>
    var DIR = '<?= $dir ?>';
    function pmChange() {
      var sel = document.getElementById('pay_mode');
      document.getElementById('bankAccBox').style.display = sel.options[sel.selectedIndex].dataset.type === 'bank' ? '' : 'none';
    }
    window.pmChange = pmChange;
    function loadBills() {
      var pid = document.getElementById('party_id').value;
      var box = document.getElementById('billList');
      document.getElementById('balInfo').textContent = '';
      document.getElementById('autoAlloc').style.display = 'none';
      if (!pid) { box.textContent = 'પહેલા party select કરો.'; return; }
      fetch('ajax.php?a=party_bills&dir=' + DIR + '&party_id=' + pid)
        .then(function (r) { return r.json(); })
        .then(function (d) {
          var b = d.balance;
          document.getElementById('balInfo').innerHTML = 'Party Balance: <strong style="color:' +
            (b > 0 ? 'var(--ok)' : (b < 0 ? 'var(--bad)' : 'inherit')) + '">₹' +
            Math.abs(b).toFixed(2) + (b > 0 ? ' લેવાના' : (b < 0 ? ' દેવાના' : '')) + '</strong>';
          if (!d.bills.length) { box.innerHTML = '<span class="muted">કોઈ બાકી bill નથી - payment ખાલી ledger માં જમા થશે.</span>'; return; }
          var h = '<div class="table-wrap" style="box-shadow:none"><table class="table-sm"><thead><tr><th>Bill</th><th>Date</th><th class="num">Due ₹</th><th style="width:130px">Link ₹</th></tr></thead><tbody>';
          d.bills.forEach(function (bl) {
            h += '<tr><td>' + bl.no + '<input type="hidden" name="alloc_id[]" value="' + bl.id + '"></td>' +
                 '<td>' + bl.date + '</td><td class="num">' + bl.due.toFixed(2) + '</td>' +
                 '<td><input type="number" step="any" min="0" max="' + bl.due + '" name="alloc_amt[]" value="0" class="alloc-inp" data-due="' + bl.due + '"></td></tr>';
          });
          box.innerHTML = h + '</tbody></table></div>';
          document.getElementById('autoAlloc').style.display = '';
        });
    }
    document.getElementById('party_id').addEventListener('change', loadBills);
    document.getElementById('autoAlloc').addEventListener('click', function () {
      var left = parseFloat(document.getElementById('pay_amount').value) || 0;
      document.querySelectorAll('.alloc-inp').forEach(function (inp) {
        var due = parseFloat(inp.dataset.due) || 0;
        var use = Math.min(left, due);
        inp.value = use > 0 ? use.toFixed(2) : 0;
        left -= use;
      });
    });
    if (document.getElementById('party_id').value) loadBills();
    </script>
    <?php
    include __DIR__ . '/includes/footer.php';
    exit;
}

// ---------- list ----------
$parties = all('SELECT id, name FROM parties WHERE is_active = 1 ORDER BY name');
$recent = all('SELECT p.*, pt.name party_name, u2.name by_name FROM payments p
               LEFT JOIN parties pt ON pt.id = p.party_id JOIN users u2 ON u2.id = p.created_by
               ORDER BY p.id DESC LIMIT 100');
$dueSales = all("SELECT s.*, c.name company_name FROM sales s JOIN companies c ON c.id = s.company_id
                 WHERE s.status <> 'paid' AND s.is_cancelled = 0 ORDER BY s.due_date IS NULL, s.due_date LIMIT 100");
$duePurchases = all("SELECT p.*, pt.name party_name FROM purchases p JOIN parties pt ON pt.id = p.party_id
                     WHERE p.status <> 'paid' ORDER BY p.due_date IS NULL, p.due_date LIMIT 100");

$page_title = 'Payments';
include __DIR__ . '/includes/header.php';
?>
<div class="page-actions">
  <?php if (can('payments.add')): ?>
  <a class="btn btn-success" href="payments.php?action=new&dir=in">⬇ Payment-In (વસૂલી)</a>
  <a class="btn btn-danger" href="payments.php?action=new&dir=out">⬆ Payment-Out (ચુકવણી)</a>
  <?php endif; ?>
</div>

<div class="card">
  <h2>💰 Receivables (લેવાના)</h2>
  <div class="table-wrap" style="box-shadow:none">
  <table>
    <thead><tr><th>Invoice</th><th>Customer</th><th class="num">Due ₹</th><th>Due date</th><th></th></tr></thead>
    <tbody><?php foreach ($dueSales as $s): $d = $s['total'] - $s['paid']; ?>
      <tr>
        <td><a href="sale_view.php?id=<?= $s['id'] ?>"><?= e($s['invoice_no']) ?></a></td>
        <td><?= e($s['customer_name'] ?: 'Walk-in') ?></td>
        <td class="num">₹<?= money($d) ?></td>
        <td><?= dmy($s['due_date']) ?><?= $s['due_date'] && $s['due_date'] < today() ? ' <span class="badge badge-bad">overdue</span>' : '' ?></td>
        <td style="white-space:nowrap">
          <?php if (can('payments.add') && $s['party_id']): ?>
            <a class="btn btn-sm btn-success" href="payments.php?action=new&dir=in&party=<?= $s['party_id'] ?>">Receive</a>
          <?php endif; ?>
          <?php if ($s['customer_mobile']): ?>
          <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="do" value="remind"><input type="hidden" name="sale_id" value="<?= $s['id'] ?>">
            <button class="btn btn-sm btn-wa" type="submit">📲</button></form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; if (!$dueSales): ?><tr><td colspan="5" class="muted">All clear 🎉</td></tr><?php endif; ?></tbody>
  </table>
  </div>
</div>

<div class="card">
  <h2>📤 Payables (દેવાના)</h2>
  <div class="table-wrap" style="box-shadow:none">
  <table>
    <thead><tr><th>Bill</th><th>Supplier</th><th class="num">Due ₹</th><th>Due date</th><th></th></tr></thead>
    <tbody><?php foreach ($duePurchases as $p): ?>
      <tr>
        <td><a href="purchase_view.php?id=<?= $p['id'] ?>">#<?= $p['id'] ?> <?= e($p['bill_no']) ?></a></td>
        <td><?= e($p['party_name']) ?></td>
        <td class="num">₹<?= money($p['total'] - $p['paid']) ?></td>
        <td><?= dmy($p['due_date']) ?><?= $p['due_date'] && $p['due_date'] < today() ? ' <span class="badge badge-bad">overdue</span>' : '' ?></td>
        <td><?php if (can('payments.add')): ?><a class="btn btn-sm btn-danger" href="payments.php?action=new&dir=out&party=<?= $p['party_id'] ?>">Pay</a><?php endif; ?></td>
      </tr>
    <?php endforeach; if (!$duePurchases): ?><tr><td colspan="5" class="muted">All clear 🎉</td></tr><?php endif; ?></tbody>
  </table>
  </div>
</div>

<div class="card">
  <h2>Recent entries</h2>
  <div class="table-wrap" style="box-shadow:none">
  <table>
    <thead><tr><th>#</th><th>Date</th><th>Party</th><th>Dir</th><th class="num">Amount</th><th>Mode</th><th>Notes</th><th></th></tr></thead>
    <tbody><?php foreach ($recent as $pm): ?>
      <tr>
        <td>P-<?= str_pad($pm['id'], 5, '0', STR_PAD_LEFT) ?></td>
        <td><?= dmy($pm['pay_date']) ?></td>
        <td><?= $pm['party_id'] ? '<a href="parties.php?action=ledger&id=' . $pm['party_id'] . '">' . e($pm['party_name']) . '</a>' : '<span class="muted">Walk-in</span>' ?></td>
        <td><?= $pm['direction'] === 'in' ? '<span class="badge badge-ok">IN</span>' : '<span class="badge badge-bad">OUT</span>' ?></td>
        <td class="num">₹<?= money($pm['amount']) ?></td>
        <td><?= e($pm['mode']) ?></td>
        <td><?= e($pm['notes']) ?> <span class="muted">(<?= e($pm['by_name']) ?>)</span></td>
        <td><?php if (can('payments.delete')): ?>
          <form method="post" onsubmit="return confirm('Delete entry?')" style="display:inline"><?= csrf_field() ?>
          <input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= $pm['id'] ?>">
          <button class="btn btn-sm btn-danger" type="submit">✕</button></form><?php endif; ?></td>
      </tr>
    <?php endforeach; ?></tbody>
  </table>
  </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
