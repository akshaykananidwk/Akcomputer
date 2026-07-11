<?php
// Parties (customers / suppliers) with credit days & ledger balance
require_once __DIR__ . '/includes/init.php';
require_perm('parties.view');

$action = get('action', 'list');
$id = (int)get('id');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'save') {
    require_perm($id ? 'parties.edit' : 'parties.add');
    $data = [post('name'), post('type', 'customer'), post('mobile'), post('email'), post('gstin'),
             post('address'), post('city'), (int)post('credit_days'), (float)post('opening_balance'),
             post('is_active') ? 1 : 0];
    if ($id) {
        q('UPDATE parties SET name=?, type=?, mobile=?, email=?, gstin=?, address=?, city=?, credit_days=?, opening_balance=?, is_active=? WHERE id=?',
          array_merge($data, [$id]));
        flash('Party updated.');
    } else {
        q('INSERT INTO parties (name, type, mobile, email, gstin, address, city, credit_days, opening_balance, is_active) VALUES (?,?,?,?,?,?,?,?,?,?)', $data);
        flash('Party added.');
    }
    log_activity('party_save', post('name'));
    redirect('parties.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'delete') {
    require_perm('parties.delete');
    $pid = (int)post('id');
    $bal = party_balance($pid);
    if (abs($bal) > 0.009) {
        flash('આ party નું ₹' . money(abs($bal)) . ' (' . ($bal > 0 ? 'લેવાના' : 'દેવાના') . ') બાકી છે - પહેલા balance ચૂકતે કરો, પછી જ delete/inactive કરી શકાશે (નહીં તો એ રકમ ક્યાંય દેખાવાની બંધ થઈ જાય).', 'error');
        redirect('parties.php');
    }
    $tx = (int)val('SELECT (SELECT COUNT(*) FROM sales WHERE party_id=?) + (SELECT COUNT(*) FROM purchases WHERE party_id=?) + (SELECT COUNT(*) FROM payments WHERE party_id=?)', [$pid,$pid,$pid]);
    if ($tx > 0) {
        q('UPDATE parties SET is_active = 0 WHERE id = ?', [$pid]);
        flash('Party ના વ્યવહારો છે એટલે delete ના બદલે INACTIVE કરી (ledger સચવાયું).', 'info');
    } else {
        q('DELETE FROM parties WHERE id = ?', [$pid]);
        flash('Party deleted.');
    }
    redirect('parties.php');
}

$terms = all('SELECT * FROM credit_terms ORDER BY days');

if ($action === 'new' || $action === 'edit') {
    require_perm($action === 'new' ? 'parties.add' : 'parties.edit');
    $p = $id ? row('SELECT * FROM parties WHERE id = ?', [$id]) : null;
    $page_title = $p ? 'Edit Party' : 'New Party';
    include __DIR__ . '/includes/header.php';
    ?>
    <div class="card">
      <h2><?= $page_title ?></h2>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="save">
        <div class="form-row cols-2">
          <div><label>Name *</label><input type="text" name="name" value="<?= e($p['name'] ?? '') ?>" required></div>
          <div><label>Type</label>
            <select name="type">
              <?php foreach (['customer' => 'Customer', 'supplier' => 'Supplier', 'both' => 'Both', 'service_center' => 'Service Center'] as $t => $tl): ?>
              <option value="<?= $t ?>" <?= ($p['type'] ?? 'customer') === $t ? 'selected' : '' ?>><?= $tl ?></option>
              <?php endforeach; ?>
            </select></div>
        </div>
        <div class="form-row cols-3">
          <div><label>Mobile (WhatsApp)</label><input type="tel" name="mobile" value="<?= e($p['mobile'] ?? '') ?>"></div>
          <div><label>Email</label><input type="email" name="email" value="<?= e($p['email'] ?? '') ?>"></div>
          <div><label>GSTIN</label><input type="text" name="gstin" value="<?= e($p['gstin'] ?? '') ?>"></div>
        </div>
        <div class="form-row cols-2">
          <div><label>Address</label><input type="text" name="address" value="<?= e($p['address'] ?? '') ?>"></div>
          <div><label>City</label><input type="text" name="city" value="<?= e($p['city'] ?? '') ?>"></div>
        </div>
        <div class="form-row cols-3">
          <div><label>Credit term</label>
            <select name="credit_days">
              <?php foreach ($terms as $t): ?>
              <option value="<?= $t['days'] ?>" <?= (int)($p['credit_days'] ?? 0) === (int)$t['days'] ? 'selected' : '' ?>><?= e($t['label']) ?></option>
              <?php endforeach; ?>
            </select></div>
          <div><label>Opening balance (+ receivable / - payable)</label><input type="number" step="any" name="opening_balance" value="<?= e($p['opening_balance'] ?? '0') ?>"></div>
          <div><label class="check-inline mt"><input type="checkbox" name="is_active" value="1" <?= ($p === null || $p['is_active']) ? 'checked' : '' ?>> Active</label></div>
        </div>
        <button class="btn" type="submit">Save Party</button>
        <a class="btn btn-muted" href="parties.php">Cancel</a>
      </form>
    </div>
    <?php
    include __DIR__ . '/includes/footer.php';
    exit;
}

// ---------- send ledger on WhatsApp ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'wa_ledger') {
    $p = row('SELECT * FROM parties WHERE id = ?', [(int)post('id')]);
    $mobile = post('mobile') ?: ($p['mobile'] ?? '');
    if ($p && $mobile) {
        $lines = post('lines');
        $bal = (float)post('balance');
        $balTxt = ($bal >= 0 ? '₹' . money($bal) . ' લેવાના' : '₹' . money(-$bal) . ' દેવાના');
        $ok = send_whatsapp($mobile, wa_template('ledger', ['party' => $p['name'], 'lines' => $lines, 'balance' => $balTxt]));
        flash($ok ? 'Ledger WhatsApp પર મોકલ્યું.' : 'WhatsApp send fail - API settings ચકાસો.', $ok ? 'success' : 'error');
    } else {
        flash('Mobile number નથી.', 'error');
    }
    redirect('parties.php?action=ledger&id=' . (int)post('id'));
}

if ($action === 'ledger' && $id) {
    $p = row('SELECT * FROM parties WHERE id = ?', [$id]);
    if (!$p) { flash('Party not found', 'error'); redirect('parties.php'); }
    $entries = [];
    foreach (all('SELECT id, invoice_no ref, sale_date d, total amt FROM sales WHERE party_id = ? AND is_cancelled = 0', [$id]) as $r)
        $entries[] = ['date' => $r['d'], 'desc' => 'Sale ' . $r['ref'], 'dr' => $r['amt'], 'cr' => 0];
    foreach (all('SELECT id, bill_no ref, purchase_date d, total amt FROM purchases WHERE party_id = ?', [$id]) as $r)
        $entries[] = ['date' => $r['d'], 'desc' => 'Purchase ' . $r['ref'], 'dr' => 0, 'cr' => $r['amt']];
    foreach (all('SELECT * FROM payments WHERE party_id = ?', [$id]) as $r)
        $entries[] = ['date' => $r['pay_date'], 'desc' => ($r['direction'] === 'in' ? 'Received' : 'Paid') . ' (' . $r['mode'] . ') ' . $r['notes'],
                      'dr' => $r['direction'] === 'out' ? $r['amount'] : 0, 'cr' => $r['direction'] === 'in' ? $r['amount'] : 0];
    foreach (all('SELECT return_no, return_date d, total amt FROM sales_returns WHERE party_id = ?', [$id]) as $r)
        $entries[] = ['date' => $r['d'], 'desc' => 'Sales Return ' . $r['return_no'], 'dr' => 0, 'cr' => $r['amt']];
    foreach (all('SELECT return_no, return_date d, total amt FROM purchase_returns WHERE party_id = ?', [$id]) as $r)
        $entries[] = ['date' => $r['d'], 'desc' => 'Purchase Return ' . $r['return_no'], 'dr' => $r['amt'], 'cr' => 0];
    usort($entries, fn($a, $b) => strcmp($a['date'], $b['date']));

    $bal = (float)$p['opening_balance'];
    $page_title = 'Ledger: ' . $p['name'];
    include __DIR__ . '/includes/header.php';
    ?>
    <div class="card">
      <h2><?= e($p['name']) ?> <span class="muted">(<?= e($p['type']) ?>, credit <?= (int)$p['credit_days'] ?> days)</span></h2>
      <p class="muted"><?= e($p['mobile']) ?> <?= $p['gstin'] ? '| GSTIN: ' . e($p['gstin']) : '' ?></p>
    </div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Date</th><th>Description</th><th class="num">Debit (bill)</th><th class="num">Credit (pay)</th><th class="num">Balance</th></tr></thead>
        <tbody>
          <tr><td>-</td><td>Opening balance</td><td class="num"></td><td class="num"></td><td class="num"><?= money($bal) ?></td></tr>
          <?php foreach ($entries as $en): $bal += $en['dr'] - $en['cr']; ?>
          <tr>
            <td><?= dmy($en['date']) ?></td><td><?= e($en['desc']) ?></td>
            <td class="num"><?= $en['dr'] ? money($en['dr']) : '' ?></td>
            <td class="num"><?= $en['cr'] ? money($en['cr']) : '' ?></td>
            <td class="num"><?= money($bal) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="card"><strong>Closing balance: ₹<?= money(abs($bal)) ?> <?= $bal >= 0 ? '(લેવાના / to receive)' : '(દેવાના / to pay)' ?></strong></div>
    <div class="card no-print">
      <div class="page-actions" style="margin:0">
        <?php if (can('payments.add')): ?>
        <a class="btn btn-success" href="payments.php?action=new&dir=in&party=<?= $p['id'] ?>">⬇ Receive Payment</a>
        <a class="btn btn-danger" href="payments.php?action=new&dir=out&party=<?= $p['id'] ?>">⬆ Pay</a>
        <?php endif; ?>
        <button class="btn btn-outline" onclick="window.print()">🖨️ Print</button>
        <form method="post" style="display:inline-flex;gap:6px">
          <?= csrf_field() ?>
          <input type="hidden" name="do" value="wa_ledger">
          <input type="hidden" name="id" value="<?= $p['id'] ?>">
          <input type="hidden" name="balance" value="<?= $bal ?>">
          <?php
          // last 10 entries as text lines for WhatsApp
          $waLines = '';
          foreach (array_slice($entries, -10) as $en) {
              $waLines .= dmy($en['date']) . ' ' . $en['desc'] . ': ' .
                          ($en['dr'] ? '₹' . money($en['dr']) . ' (bill)' : '₹' . money($en['cr']) . ' (jama)') . "\n";
          }
          ?>
          <input type="hidden" name="lines" value="<?= e(trim($waLines)) ?>">
          <input type="tel" name="mobile" value="<?= e($p['mobile']) ?>" placeholder="WhatsApp no." style="width:140px">
          <button class="btn btn-wa" type="submit">📲 Ledger WhatsApp</button>
        </form>
      </div>
    </div>
    <?php
    include __DIR__ . '/includes/footer.php';
    exit;
}

// ---- list (with live balance: + = you'll get, - = you'll give) ----
// Inactive parties are hidden UNLESS they still carry a balance - a party
// deactivated while money was still due/payable must never quietly
// disappear from view (that money would then show nowhere at all).
$balExprList = party_balance_expr('p');
$parties = all("SELECT p.*, $balExprList AS balance
    FROM parties p WHERE p.is_active = 1 OR ABS($balExprList) > 0.009 ORDER BY p.name");
$totGet = 0; $totGive = 0;
foreach ($parties as $p) {
    if ($p['balance'] > 0.009) $totGet += $p['balance'];
    elseif ($p['balance'] < -0.009) $totGive += -$p['balance'];
}
$page_title = 'Parties';
include __DIR__ . '/includes/header.php';
?>
<div class="duo-cards">
  <div class="duo-card duo-get"><div class="duo-label">લેવાના (You'll Get)</div><div class="duo-value">₹ <?= money($totGet) ?></div></div>
  <div class="duo-card duo-give"><div class="duo-label">દેવાના (You'll Give)</div><div class="duo-value">₹ <?= money($totGive) ?></div></div>
</div>
<div class="page-actions">
  <?php if (can('parties.add')): ?><a class="btn" href="parties.php?action=new">+ New Party</a><?php endif; ?>
</div>
<div class="searchbox"><input type="text" id="pFilter" placeholder="🔍 Search parties..."></div>
<div class="table-wrap">
<table id="pTable">
  <thead><tr><th>Name</th><th class="num">Balance</th><th>Mobile</th><th>City</th><th>Credit</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($parties as $p): ?>
    <tr>
      <td><strong><?= e($p['name']) ?></strong> <span class="muted">(<?= e($p['type']) ?>)</span><?= !$p['is_active'] ? ' <span class="badge badge-bad">INACTIVE - બાકી છે</span>' : '' ?><?= $p['gstin'] ? '<br><span class="muted">' . e($p['gstin']) . '</span>' : '' ?></td>
      <td class="num">
        <?php if ($p['balance'] > 0.009): ?>
          <span class="bal-get">₹<?= money($p['balance']) ?><span class="bal-sub">You'll Get</span></span>
        <?php elseif ($p['balance'] < -0.009): ?>
          <span class="bal-give">₹<?= money(-$p['balance']) ?><span class="bal-sub">You'll Give</span></span>
        <?php else: ?>
          <span class="muted">₹0.00</span>
        <?php endif; ?>
      </td>
      <td><a href="tel:<?= e($p['mobile']) ?>"><?= e($p['mobile']) ?></a></td>
      <td><?= e($p['city']) ?></td>
      <td><?= (int)$p['credit_days'] ?> days</td>
      <td>
        <a class="btn btn-sm btn-outline" href="parties.php?action=ledger&id=<?= $p['id'] ?>">Ledger</a>
        <?php if (can('parties.edit')): ?><a class="btn btn-sm btn-outline" href="parties.php?action=edit&id=<?= $p['id'] ?>">Edit</a><?php endif; ?>
        <?php if (can('parties.delete')): ?><form method="post" style="display:inline" onsubmit="return confirm('Party delete કરવી? વ્યવહાર હશે તો ખાલી inactive થશે.')"><?= csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= $p['id'] ?>"><button class="btn btn-sm btn-danger" type="submit">✕</button></form><?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<script>tableFilter('pFilter', 'pTable');</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
