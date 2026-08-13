<?php
// Parties (customers / suppliers) with credit days & ledger balance
require_once __DIR__ . '/includes/init.php';
require_perm('parties.view');

$action = get('action', 'list');
$id = (int)get('id');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'save') {
    require_perm($id ? 'parties.edit' : 'parties.add');
    // Every party works on both sides (can be sold to AND purchased from) -
    // no more customer/supplier/service-center type to pick, and a party
    // always stays active (deactivation only ever happens automatically,
    // from Delete, when it still has old transactions to preserve).
    $data = [post('name'), 'both', post('mobile'), post('email'), post('gstin'),
             post('address'), post('city'), post('dob') ?: null, post('anniversary') ?: null,
             (int)post('credit_days'), (float)post('opening_balance')];
    if ($id) {
        q('UPDATE parties SET name=?, type=?, mobile=?, email=?, gstin=?, address=?, city=?, dob=?, anniversary=?, credit_days=?, opening_balance=?, is_active=1 WHERE id=?',
          array_merge($data, [$id]));
        flash('Party updated.');
    } else {
        // Duplicate-party guard: the same mobile number almost always means
        // the party already exists (typed under a slightly different name) -
        // two ledgers for one person splits their balance. Block unless the
        // "create anyway" box is ticked.
        $mob10 = substr(preg_replace('/\D/', '', post('mobile')), -10);
        if (strlen($mob10) === 10 && !post('force_dupe')) {
            $dupe = row("SELECT id, name FROM parties WHERE is_active = 1 AND RIGHT(REPLACE(REPLACE(mobile,' ',''),'-',''),10) = ? LIMIT 1", [$mob10]);
            if ($dupe) {
                flash('⚠️ આ મોબાઇલ નંબરની પાર્ટી પહેલેથી છે: "' . $dupe['name'] . '". એની જ લેજરમાં એન્ટ્રી કરો, અથવા ખરેખર નવી બનાવવી હોય તો ફોર્મમાં "એ જ નંબર છતાં નવી પાર્ટી બનાવવી" ટિક કરીને ફરી Save કરો.', 'error');
                redirect('parties.php?action=new');
            }
        }
        q('INSERT INTO parties (name, type, mobile, email, gstin, address, city, dob, anniversary, credit_days, opening_balance, is_active) VALUES (?,?,?,?,?,?,?,?,?,?,?,1)', $data);
        flash('Party added.');
    }
    log_activity('party_save', post('name'));
    redirect('parties.php');
}

// One tap: the party's WHOLE ledger as a statement PDF on their WhatsApp
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'statement_wa') {
    require_perm('parties.view');
    require_once __DIR__ . '/includes/pdf.php';
    $spid = (int)post('id');
    $sp = row('SELECT * FROM parties WHERE id = ?', [$spid]);
    if (!$sp || !$sp['mobile']) { flash('Party not found or has no mobile number.', 'error'); redirect('parties.php'); }
    $ent = [];
    foreach (all('SELECT invoice_no ref, sale_date d, total amt FROM sales WHERE party_id = ? AND is_cancelled = 0', [$spid]) as $r)
        $ent[] = ['date' => $r['d'], 'desc' => 'Sale ' . $r['ref'], 'dr' => $r['amt'], 'cr' => 0];
    foreach (all('SELECT id, bill_no ref, purchase_date d, total amt FROM purchases WHERE party_id = ? AND is_cancelled = 0', [$spid]) as $r)
        $ent[] = ['date' => $r['d'], 'desc' => 'Purchase ' . ($r['ref'] ?: '#' . $r['id']), 'dr' => 0, 'cr' => $r['amt']];
    foreach (all('SELECT direction, mode, amount, pay_date, notes FROM payments WHERE party_id = ?', [$spid]) as $r)
        $ent[] = ['date' => $r['pay_date'], 'desc' => ($r['direction'] === 'in' ? 'Payment received' : 'Payment made') . ' (' . $r['mode'] . ')',
                  'dr' => $r['direction'] === 'out' ? $r['amount'] : 0, 'cr' => $r['direction'] === 'in' ? $r['amount'] : 0];
    foreach (all('SELECT return_no, return_date d, total amt FROM sales_returns WHERE party_id = ?', [$spid]) as $r)
        $ent[] = ['date' => $r['d'], 'desc' => 'Sales Return ' . $r['return_no'], 'dr' => 0, 'cr' => $r['amt']];
    foreach (all('SELECT return_no, return_date d, total amt FROM purchase_returns WHERE party_id = ?', [$spid]) as $r)
        $ent[] = ['date' => $r['d'], 'desc' => 'Purchase Return ' . $r['return_no'], 'dr' => $r['amt'], 'cr' => 0];
    usort($ent, fn($a, $b) => strcmp($a['date'], $b['date']));
    $bytes = party_statement_pdf($sp, $ent, (float)$sp['opening_balance']);
    $dir = __DIR__ . '/uploads/statements';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $fn = 'statement_' . $spid . '_' . substr(md5(microtime()), 0, 8) . '.pdf';
    file_put_contents($dir . '/' . $fn, $bytes);
    $balNow = (float)$sp['opening_balance'] + array_sum(array_map(fn($e2) => $e2['dr'] - $e2['cr'], $ent));
    $msg = "🙏 *" . setting('app_name', 'AK Computer') . "*\n\n" . $sp['name'] . ", તમારો હિસાબ (Account Statement) આ PDF માં છે.\n"
         . ($balNow > 0.009 ? "બાકી રકમ: *₹" . money($balNow) . "*" : ($balNow < -0.009 ? "તમારી જમા: ₹" . money(abs($balNow)) : "હિસાબ ચૂકતે ✔"))
         . "\n\nThank you! 🙏";
    if (send_whatsapp($sp['mobile'], $msg, base_url('uploads/statements/' . $fn))) {
        log_activity('party_statement_wa', $sp['name']);
        flash('Statement (PDF) WhatsApp પર મોકલ્યું: ' . $sp['mobile']);
    } else {
        flash('WhatsApp send failed. ' . whatsapp_last_error(), 'error');
    }
    redirect('parties.php?action=ledger&id=' . $spid);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'delete') {
    require_perm('parties.delete');
    $pid = (int)post('id');
    $bal = party_balance($pid);
    if (abs($bal) > 0.009) {
        flash('This party has ₹' . money(abs($bal)) . ' (' . ($bal > 0 ? 'receivable' : 'payable') . ') outstanding - settle the balance first, then it can be deleted/made inactive (otherwise that amount would stop showing up anywhere).', 'error');
        redirect('parties.php');
    }
    $tx = (int)val('SELECT (SELECT COUNT(*) FROM sales WHERE party_id=?) + (SELECT COUNT(*) FROM purchases WHERE party_id=?) + (SELECT COUNT(*) FROM payments WHERE party_id=?)', [$pid,$pid,$pid]);
    if ($tx > 0) {
        q('UPDATE parties SET is_active = 0 WHERE id = ?', [$pid]);
        flash('This party has transactions, so it was made INACTIVE instead of deleted (ledger preserved).', 'info');
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
          <div><label>Mobile (WhatsApp)</label><input type="tel" name="mobile" value="<?= e($p['mobile'] ?? '') ?>"></div>
        </div>
        <?php if (empty($p['id'])): ?>
        <label class="check-inline"><input type="checkbox" name="force_dupe" value="1"> એ જ મોબાઇલ નંબરની પાર્ટી હોવા છતાં નવી બનાવવી (સામાન્ય રીતે જરૂર નથી)</label>
        <?php endif; ?>
        <div class="form-row cols-2">
          <div><label>Email</label><input type="email" name="email" value="<?= e($p['email'] ?? '') ?>"></div>
          <div><label>GSTIN</label><input type="text" name="gstin" value="<?= e($p['gstin'] ?? '') ?>"></div>
        </div>
        <div class="form-row cols-2">
          <div><label>Address</label><input type="text" name="address" value="<?= e($p['address'] ?? '') ?>"></div>
          <div><label>City</label><input type="text" name="city" value="<?= e($p['city'] ?? '') ?>"></div>
        </div>
        <div class="form-row cols-2">
          <div><label>Birthday <span class="muted" style="font-weight:normal">(automatic WhatsApp wish every year)</span></label><input type="date" name="dob" value="<?= e($p['dob'] ?? '') ?>"></div>
          <div><label>Anniversary</label><input type="date" name="anniversary" value="<?= e($p['anniversary'] ?? '') ?>"></div>
        </div>
        <div class="form-row cols-2">
          <div><label>Credit term</label>
            <select name="credit_days">
              <?php foreach ($terms as $t): ?>
              <option value="<?= $t['days'] ?>" <?= (int)($p['credit_days'] ?? 0) === (int)$t['days'] ? 'selected' : '' ?>><?= e($t['label']) ?></option>
              <?php endforeach; ?>
            </select></div>
          <div><label>Opening balance (+ receivable / - payable)</label><input type="number" step="any" name="opening_balance" value="<?= e($p['opening_balance'] ?? '0') ?>"></div>
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
        $balTxt = ($bal >= 0 ? '₹' . money($bal) . ' receivable' : '₹' . money(-$bal) . ' payable');
        $ok = send_whatsapp($mobile, wa_template('ledger', ['party' => $p['name'], 'lines' => $lines, 'balance' => $balTxt]));
        flash($ok ? 'Ledger sent on WhatsApp.' : ('WhatsApp send failed. ' . whatsapp_last_error()), $ok ? 'success' : 'error');
    } else {
        flash('No mobile number.', 'error');
    }
    redirect('parties.php?action=ledger&id=' . (int)post('id'));
}

if ($action === 'ledger' && $id) {
    $p = row('SELECT * FROM parties WHERE id = ?', [$id]);
    if (!$p) { flash('Party not found', 'error'); redirect('parties.php'); }
    // Every ledger row carries a 'link' to its own detail page (where Edit /
    // Delete live) so you can tap any transaction straight from the ledger -
    // like the party screen in Vyapar.
    $entries = [];
    foreach (all('SELECT id, invoice_no ref, sale_date d, total amt, paid FROM sales WHERE party_id = ? AND is_cancelled = 0', [$id]) as $r)
        $entries[] = ['date' => $r['d'], 'desc' => 'Sale ' . $r['ref'], 'dr' => $r['amt'], 'cr' => 0,
                      'link' => 'sale_view.php?id=' . $r['id'], 'sub' => $r['amt'] - $r['paid'] > 0.009 ? 'Balance ₹' . money($r['amt'] - $r['paid']) : 'Paid'];
    foreach (all('SELECT id, bill_no ref, purchase_date d, total amt, paid FROM purchases WHERE party_id = ? AND is_cancelled = 0', [$id]) as $r)
        $entries[] = ['date' => $r['d'], 'desc' => 'Purchase ' . ($r['ref'] ?: '#' . $r['id']), 'dr' => 0, 'cr' => $r['amt'],
                      'link' => 'purchase_view.php?id=' . $r['id'], 'sub' => $r['amt'] - $r['paid'] > 0.009 ? 'Balance ₹' . money($r['amt'] - $r['paid']) : 'Paid'];
    foreach (all('SELECT * FROM payments WHERE party_id = ?', [$id]) as $r)
        $entries[] = ['date' => $r['pay_date'], 'desc' => ($r['direction'] === 'in' ? 'Received' : 'Paid') . ' (' . $r['mode'] . ') ' . $r['notes'],
                      'dr' => $r['direction'] === 'out' ? $r['amount'] : 0, 'cr' => $r['direction'] === 'in' ? $r['amount'] : 0,
                      'link' => 'payments.php?action=view&id=' . $r['id'], 'sub' => 'Receipt P-' . str_pad($r['id'], 5, '0', STR_PAD_LEFT)];
    foreach (all('SELECT id, return_no, return_date d, total amt FROM sales_returns WHERE party_id = ?', [$id]) as $r)
        $entries[] = ['date' => $r['d'], 'desc' => 'Sales Return ' . $r['return_no'], 'dr' => 0, 'cr' => $r['amt'],
                      'link' => 'sales_return.php', 'sub' => ''];
    foreach (all('SELECT id, return_no, return_date d, total amt FROM purchase_returns WHERE party_id = ?', [$id]) as $r)
        $entries[] = ['date' => $r['d'], 'desc' => 'Purchase Return ' . $r['return_no'], 'dr' => $r['amt'], 'cr' => 0,
                      'link' => 'purchase_return.php', 'sub' => ''];
    usort($entries, fn($a, $b) => strcmp($a['date'], $b['date']));

    // ---- visit timeline: everything else linked to this customer, across
    // service/CRM modules, computed on the fly (no separate table to keep
    // in sync - same "aggregate the real tables" approach as the ledger above) ----
    $timeline = [];
    if (can('repairs.view')) foreach (all('SELECT job_no, device_type, brand_model, status, received_date FROM repairs WHERE party_id = ?', [$id]) as $r)
        $timeline[] = ['date' => $r['received_date'], 'icon' => '🔧', 'desc' => 'Repair job ' . $r['job_no'] . ' - ' . trim($r['device_type'] . ' ' . $r['brand_model']), 'status' => $r['status'], 'link' => null];
    if (can('tasks.view')) foreach (all('SELECT task_no, description, status, scheduled_date FROM tasks WHERE party_id = ?', [$id]) as $t)
        $timeline[] = ['date' => $t['scheduled_date'], 'icon' => '🛠️', 'desc' => 'Field task ' . $t['task_no'] . ' - ' . mb_substr($t['description'], 0, 60), 'status' => $t['status'], 'link' => null];
    if (can('warranty.view')) foreach (all('SELECT claim_no, issue, status, received_date FROM warranty_claims WHERE party_id = ?', [$id]) as $w)
        $timeline[] = ['date' => $w['received_date'], 'icon' => '🛡️', 'desc' => 'Warranty claim ' . $w['claim_no'] . ' - ' . mb_substr($w['issue'], 0, 60), 'status' => $w['status'], 'link' => null];
    if (can('tickets.view')) foreach (all('SELECT id, ticket_no, subject, status, created_at FROM tickets WHERE party_id = ?', [$id]) as $tk)
        $timeline[] = ['date' => $tk['created_at'], 'icon' => '🎫', 'desc' => 'Ticket ' . $tk['ticket_no'] . ' - ' . $tk['subject'], 'status' => $tk['status'], 'link' => 'tickets.php?action=view&id=' . $tk['id']];
    if (can('leads.view')) foreach (all('SELECT id, lead_no, interest, status, created_at FROM leads WHERE party_id = ?', [$id]) as $ld)
        $timeline[] = ['date' => $ld['created_at'], 'icon' => '🎯', 'desc' => 'Lead ' . $ld['lead_no'] . ' - ' . ($ld['interest'] ?: 'converted'), 'status' => $ld['status'], 'link' => 'leads.php?action=view&id=' . $ld['id']];
    if (can('followups.view')) foreach (all('SELECT title, status, due_date FROM follow_ups WHERE party_id = ?', [$id]) as $fu)
        $timeline[] = ['date' => $fu['due_date'], 'icon' => '⏰', 'desc' => 'Follow-up - ' . $fu['title'], 'status' => $fu['status'], 'link' => 'follow_ups.php'];
    usort($timeline, fn($a, $b) => strcmp($b['date'], $a['date']));

    $bal = (float)$p['opening_balance'];
    $openingBal = $bal;
    $page_title = 'Ledger: ' . $p['name'];
    include __DIR__ . '/includes/header.php';
    ?>
    <div class="card">
      <div class="page-actions no-print" style="margin:0 0 10px;justify-content:space-between">
        <h2 style="margin:0"><?= e($p['name']) ?></h2>
        <span>
          <?php if ($p['mobile']): ?>
          <form method="post" style="display:inline" onsubmit="return confirm('આખો હિસાબ (statement PDF) <?= e($p['mobile']) ?> પર WhatsApp કરવો?')">
            <?= csrf_field() ?><input type="hidden" name="do" value="statement_wa"><input type="hidden" name="id" value="<?= $p['id'] ?>">
            <button class="btn btn-sm btn-wa" type="submit">📲 Statement WhatsApp</button>
          </form>
          <?php endif; ?>
          <a class="btn btn-sm btn-outline" href="customer.php?id=<?= $p['id'] ?>">👤 Customer 360</a>
          <?php if (can('parties.edit')): ?><a class="btn btn-sm btn-outline" href="parties.php?action=edit&id=<?= $p['id'] ?>">✏️ Edit</a><?php endif; ?>
          <?php if (can('parties.delete')): ?><form method="post" style="display:inline" onsubmit="return confirm('Delete this party? If it has transactions, it will just be made inactive.')"><?= csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= $p['id'] ?>"><button class="btn btn-sm btn-danger" type="submit">✕</button></form><?php endif; ?>
        </span>
      </div>
      <p class="muted">Credit: <?= (int)$p['credit_days'] ?> days · <?= e($p['mobile']) ?> <?= $p['gstin'] ? '| GSTIN: ' . e($p['gstin']) : '' ?><?= setting('loyalty_enabled') === '1' ? ' | ⭐ ' . (int)$p['loyalty_points'] . ' points' : '' ?></p>
      <?php if (can('sites.view')): $siteCount = (int)val('SELECT COUNT(*) FROM sites WHERE party_id = ?', [$id]); ?>
      <p class="mt no-print"><a class="btn btn-sm btn-outline" href="sites.php?party_id=<?= $id ?>">🌐 Sites (<?= $siteCount ?>)</a></p>
      <?php endif; ?>
      <?php
      // closing balance shown up-front (computed once here from the same
      // running total the list below builds, so the two never disagree)
      $closingBal = $bal;
      foreach ($entries as $en) $closingBal += $en['dr'] - $en['cr'];
      ?>
      <div class="mt" style="font-size:28px;font-weight:800;color:<?= $closingBal >= 0 ? 'var(--ok)' : 'var(--bad)' ?>">
        ₹<?= money(abs($closingBal)) ?>
        <span class="muted" style="font-size:13px;font-weight:400"><?= $closingBal >= 0 ? '(to receive)' : '(to pay)' ?></span>
      </div>
    </div>
    <div class="card list-card">
      <div class="list-row" style="cursor:default">
        <div class="list-row-main"><strong>Opening Balance</strong></div>
        <div class="list-row-val"><span class="muted">₹<?= money($openingBal) ?></span></div>
      </div>
      <?php foreach ($entries as $en): $bal += $en['dr'] - $en['cr']; ?>
      <a class="list-row" href="<?= e($en['link']) ?>">
        <div class="list-row-main"><strong><?= e($en['desc']) ?></strong><div class="muted list-row-sub"><?= dmy($en['date']) ?><?= $en['sub'] ? ' · ' . e($en['sub']) : '' ?></div></div>
        <div class="list-row-val">
          <?php if ($en['dr'] > 0): ?><span class="bal-get">+₹<?= money($en['dr']) ?></span>
          <?php else: ?><span class="bal-give">-₹<?= money($en['cr']) ?></span><?php endif; ?>
          <span class="muted" style="font-size:15px;margin-left:6px">›</span>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
    <?php if ($timeline): ?>
    <div class="card">
      <h3>🕓 Visit Timeline</h3>
      <?php foreach ($timeline as $tl): ?>
      <div class="list-row" style="cursor:default">
        <div class="list-row-main"><?= $tl['icon'] ?> <?= e($tl['desc']) ?><div class="muted list-row-sub"><?= dmy($tl['date']) ?></div></div>
        <div class="list-row-val">
          <?= status_badge($tl['status']) ?>
          <?php if ($tl['link']): ?><br><a class="muted" style="font-size:12px" href="<?= e($tl['link']) ?>">Open</a><?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <div class="card no-print">
      <div class="page-actions" style="margin:0">
        <?php if (can('payments.add')): ?>
        <a class="btn btn-success" href="payments.php?action=new&dir=in&party=<?= $p['id'] ?>">⬇ Receive Payment</a>
        <a class="btn btn-danger" href="payments.php?action=new&dir=out&party=<?= $p['id'] ?>">⬆ Pay</a>
        <?php
        $saleDue = (float)val("SELECT COALESCE(SUM(total - paid),0) FROM sales WHERE party_id = ? AND status <> 'paid' AND is_cancelled = 0", [$id]);
        $purchDue = (float)val("SELECT COALESCE(SUM(total - paid),0) FROM purchases WHERE party_id = ? AND status <> 'paid'", [$id]);
        if ($saleDue > 0.009 && $purchDue > 0.009): ?>
        <a class="btn btn-outline" href="payments.php?action=contra&party=<?= $p['id'] ?>" title="They owe you ₹<?= money($saleDue) ?>, you owe them ₹<?= money($purchDue) ?> - settle directly, no cash needed">🔄 Contra / Settle</a>
        <?php endif; ?>
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
                          ($en['dr'] ? '₹' . money($en['dr']) . ' (bill)' : '₹' . money($en['cr']) . ' (paid)') . "\n";
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

// Dashboard "To Receive" / "To Pay" drill-down: ?bal=get shows only parties
// owed to us, ?bal=give shows only parties we owe, each sortable so the
// biggest amount can be found first instead of scrolling the whole list.
$balFilter = get('bal');
$sortBy = get('sort', 'name');
if ($balFilter === 'get') $parties = array_values(array_filter($parties, fn($p) => $p['balance'] > 0.009));
elseif ($balFilter === 'give') $parties = array_values(array_filter($parties, fn($p) => $p['balance'] < -0.009));
if ($sortBy === 'amount') usort($parties, fn($a, $b) => abs($b['balance']) <=> abs($a['balance']));
else usort($parties, fn($a, $b) => strcasecmp($a['name'], $b['name']));

$page_title = $balFilter === 'get' ? 'Parties to Receive From' : ($balFilter === 'give' ? 'Parties to Pay' : 'Parties');
include __DIR__ . '/includes/header.php';
?>
<div class="duo-cards">
  <a class="duo-card duo-get" href="parties.php?bal=get"><div class="duo-label">You'll Get</div><div class="duo-value">₹ <?= money($totGet) ?></div></a>
  <a class="duo-card duo-give" href="parties.php?bal=give"><div class="duo-label">You'll Give</div><div class="duo-value">₹ <?= money($totGive) ?></div></a>
</div>
<?php if ($balFilter): ?>
<div class="page-actions no-print" style="justify-content:space-between">
  <h2 style="margin:0"><?= e($page_title) ?></h2>
  <a class="btn btn-sm btn-outline" href="parties.php">✕ Clear filter</a>
</div>
<?php endif; ?>
<div class="page-actions">
  <?php if (can('parties.add') && !$balFilter): ?><a class="btn" href="parties.php?action=new">+ New Party</a><?php endif; ?>
  <select class="btn btn-outline" style="cursor:pointer;margin-left:auto" onchange="var u=new URL(location.href);u.searchParams.set('sort',this.value);location.href=u;">
    <option value="name" <?= $sortBy === 'name' ? 'selected' : '' ?>>Sort: Name-wise</option>
    <option value="amount" <?= $sortBy === 'amount' ? 'selected' : '' ?>>Sort: Amount-wise</option>
  </select>
</div>
<div class="searchbox"><input type="text" id="pFilter" placeholder="🔍 Search parties..."></div>
<div class="list-count"><?= count($parties) ?> parties</div>
<div class="card list-card" id="pList">
  <?php foreach ($parties as $p): ?>
  <a href="parties.php?action=ledger&id=<?= $p['id'] ?>" class="list-row" data-search="<?= e(mb_strtolower($p['name'] . ' ' . $p['mobile'] . ' ' . $p['city'])) ?>">
    <div class="list-row-main">
      <strong><?= e($p['name']) ?></strong><?= !$p['is_active'] ? ' <span class="badge badge-bad">INACTIVE</span>' : '' ?>
      <div class="muted list-row-sub"><?= e(trim($p['mobile'] . ($p['city'] ? ' · ' . $p['city'] : ''))) ?: '&nbsp;' ?></div>
    </div>
    <div class="list-row-val">
      <?php if ($p['balance'] > 0.009): ?>
        <span class="bal-get">₹<?= money($p['balance']) ?><span class="bal-sub">You'll Get</span></span>
      <?php elseif ($p['balance'] < -0.009): ?>
        <span class="bal-give">₹<?= money(-$p['balance']) ?><span class="bal-sub">You'll Give</span></span>
      <?php else: ?>
        <span class="muted">₹0.00</span>
      <?php endif; ?>
    </div>
  </a>
  <?php endforeach; ?>
  <?php if (!$parties): ?><p class="muted" style="padding:14px 4px">No parties yet.</p><?php endif; ?>
</div>
<script>
document.getElementById('pFilter').addEventListener('input', function () {
  var q = this.value.trim().toLowerCase();
  document.querySelectorAll('#pList .list-row').forEach(function (row) {
    row.style.display = row.dataset.search.indexOf(q) > -1 ? '' : 'none';
  });
});
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
