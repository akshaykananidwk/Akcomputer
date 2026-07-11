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
              <?php foreach (['customer', 'supplier', 'both'] as $t): ?>
              <option value="<?= $t ?>" <?= ($p['type'] ?? 'customer') === $t ? 'selected' : '' ?>><?= ucfirst($t) ?></option>
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

if ($action === 'ledger' && $id) {
    $p = row('SELECT * FROM parties WHERE id = ?', [$id]);
    if (!$p) { flash('Party not found', 'error'); redirect('parties.php'); }
    $entries = [];
    foreach (all('SELECT id, invoice_no ref, sale_date d, total amt FROM sales WHERE party_id = ?', [$id]) as $r)
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
    <div class="card"><strong>Closing balance: ₹<?= money($bal) ?> <?= $bal >= 0 ? '(to receive)' : '(to pay)' ?></strong></div>
    <?php
    include __DIR__ . '/includes/footer.php';
    exit;
}

// ---- list (with live balance: + = you'll get, - = you'll give) ----
$parties = all("SELECT p.*,
    (p.opening_balance
     + COALESCE((SELECT SUM(total) FROM sales WHERE party_id = p.id), 0)
     - COALESCE((SELECT SUM(total) FROM sales_returns WHERE party_id = p.id), 0)
     - COALESCE((SELECT SUM(total) FROM purchases WHERE party_id = p.id), 0)
     + COALESCE((SELECT SUM(total) FROM purchase_returns WHERE party_id = p.id), 0)
     - COALESCE((SELECT SUM(amount) FROM payments WHERE party_id = p.id AND direction = 'in'), 0)
     + COALESCE((SELECT SUM(amount) FROM payments WHERE party_id = p.id AND direction = 'out'), 0)
    ) AS balance
    FROM parties p WHERE p.is_active = 1 ORDER BY p.name");
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
      <td><strong><?= e($p['name']) ?></strong> <span class="muted">(<?= e($p['type']) ?>)</span><?= $p['gstin'] ? '<br><span class="muted">' . e($p['gstin']) . '</span>' : '' ?></td>
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
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<script>tableFilter('pFilter', 'pTable');</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
