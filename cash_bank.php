<?php
// Cash & Bank: Vyapar-style money movement centre.
// - Cash in Hand split into per-staff WALLETS (each cash payment row carries
//   created_by, so the shop's cash naturally partitions by who holds it)
// - Adjust Cash / Adjust Bank balance (e.g. bank SMS charges, counting diff)
// - Cash->Bank, Bank->Cash, Bank->Bank transfers
// - Staff-to-staff cash handover confirmed by a WhatsApp OTP sent to the
//   RECEIVER: money only moves once the receiver's OTP is typed back in.
require_once __DIR__ . '/includes/init.php';
// Every staff member may open this page: without cashbank.viewall it only
// shows THEIR wallet + THEIR handover history, and the OTP staff handover is
// meant for everyone (money moves only when the receiver's OTP is entered).
require_login();
$u = current_user();

$isSelfAdjust = fn() => can('cashbank.adjust');
$canTransfer = can('cashbank.transfer');
$canAdjust = can('cashbank.adjust');
// "cashbank.viewall" (admin has it via *) = may see the WHOLE shop's money.
// Everyone else sees only their own wallet - not the admin's, not anyone's -
// though the OTP handover still lets them pass cash to any staff member.
$seeAll = can('cashbank.viewall');

// ---------- record an adjustment (cash or bank) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'adjust') {
    require_perm('cashbank.adjust');
    $kind = post('kind') === 'bank' ? 'bank_adjust' : 'cash_adjust';
    $dirAdj = post('adjust_dir') === 'reduce' ? 'reduce' : 'add';
    $amount = round((float)post('amount'), 2);
    $bankId = (int)post('bank_id') ?: null;
    $walletUser = (int)post('wallet_user') ?: $u['id'];
    if ($amount <= 0) { flash('Amount must be more than zero.', 'error'); redirect('cash_bank.php'); }
    if ($kind === 'bank_adjust' && !$bankId) { flash('Pick the bank account.', 'error'); redirect('cash_bank.php'); }
    q('INSERT INTO money_transfers (txn_type, amount, adjust_dir, from_user_id, to_bank_id, txn_date, notes, status, created_by)
       VALUES (?,?,?,?,?,?,?,\'done\',?)',
      [$kind, $amount, $dirAdj, $kind === 'cash_adjust' ? $walletUser : null, $kind === 'bank_adjust' ? $bankId : null,
       post('txn_date', today()), trim(post('notes')), $u['id']]);
    log_activity('cashbank_adjust', "$kind $dirAdj $amount");
    flash(($kind === 'cash_adjust' ? 'Cash' : 'Bank') . ' balance ' . ($dirAdj === 'add' ? 'increased' : 'reduced') . ' by ₹' . money($amount) . '.');
    redirect('cash_bank.php');
}

// ---------- cash<->bank / bank<->bank transfer ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'transfer') {
    require_perm('cashbank.transfer');
    $type = post('txn_type');
    if (!in_array($type, ['cash_to_bank', 'bank_to_cash', 'bank_to_bank'], true)) { flash('Bad transfer type.', 'error'); redirect('cash_bank.php'); }
    $amount = round((float)post('amount'), 2);
    if ($amount <= 0) { flash('Amount must be more than zero.', 'error'); redirect('cash_bank.php'); }
    $fromBank = (int)post('from_bank_id') ?: null;
    $toBank = (int)post('to_bank_id') ?: null;
    $walletUser = (int)post('wallet_user') ?: $u['id'];
    if ($type !== 'cash_to_bank' && !$fromBank && $type !== 'bank_to_cash') { /* covered below */ }
    if (in_array($type, ['bank_to_cash', 'bank_to_bank'], true) && !$fromBank) { flash('Pick the FROM bank account.', 'error'); redirect('cash_bank.php'); }
    if (in_array($type, ['cash_to_bank', 'bank_to_bank'], true) && !$toBank) { flash('Pick the TO bank account.', 'error'); redirect('cash_bank.php'); }
    if ($type === 'bank_to_bank' && $fromBank === $toBank) { flash('FROM and TO bank must differ.', 'error'); redirect('cash_bank.php'); }
    q('INSERT INTO money_transfers (txn_type, amount, from_user_id, to_user_id, from_bank_id, to_bank_id, txn_date, notes, status, created_by)
       VALUES (?,?,?,?,?,?,?,?,\'done\',?)',
      [$type, $amount,
       $type === 'cash_to_bank' ? $walletUser : null,     // whose wallet the cash left
       $type === 'bank_to_cash' ? $walletUser : null,     // whose wallet the cash entered
       in_array($type, ['bank_to_cash', 'bank_to_bank'], true) ? $fromBank : null,
       in_array($type, ['cash_to_bank', 'bank_to_bank'], true) ? $toBank : null,
       post('txn_date', today()), trim(post('notes')), $u['id']]);
    log_activity('cashbank_transfer', "$type $amount");
    flash('Transfer of ₹' . money($amount) . ' recorded.');
    redirect('cash_bank.php');
}

// ---------- staff-to-staff cash handover: step 1, create + send OTP ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'staff_transfer') {
    // any staff can HAND OVER their own cash - the sender is always the
    // logged-in user and money moves only on the receiver's OTP
    $toUser = (int)post('to_user');
    $amount = round((float)post('amount'), 2);
    $receiver = row('SELECT * FROM users WHERE id = ? AND is_active = 1', [$toUser]);
    if (!$receiver || $amount <= 0) { flash('Pick the staff member and a valid amount.', 'error'); redirect('cash_bank.php'); }
    if ($toUser === (int)$u['id']) { flash('You cannot transfer to yourself.', 'error'); redirect('cash_bank.php'); }
    $otp = (string)random_int(100000, 999999);
    q('INSERT INTO money_transfers (txn_type, amount, from_user_id, to_user_id, txn_date, notes, status, otp_hash, otp_expires, created_by)
       VALUES (\'staff_transfer\',?,?,?,?,?,\'pending\',?,?,?)',
      [$amount, $u['id'], $toUser, today(), trim(post('notes')), hash('sha256', $otp),
       date('Y-m-d H:i:s', time() + 15 * 60), $u['id']]);
    $tid = insert_id();
    $sent = false;
    if ($receiver['mobile']) {
        wa_context(['kind' => 'otp', 'code' => $otp]); // Meta fallback -> approved akc_otp template
        $sent = send_whatsapp($receiver['mobile'],
            "🔐 *Cash handover OTP*\n\n" . $u['name'] . ' is handing you ₹' . money($amount) . " cash.\nIf you HAVE received the cash, give them this OTP: *$otp*\n\nDo not share the OTP before the cash is in your hand. Valid 15 minutes.");
    }
    if ($sent) {
        log_activity('staff_transfer_start', "T-$tid ₹$amount to {$receiver['name']}");
        flash('OTP sent on WhatsApp to ' . $receiver['name'] . ' (' . $receiver['mobile'] . '). Ask them for the OTP once the cash is in their hand, then enter it below.');
    } else {
        // No mobile / gateway down: keep the transfer pending and show the
        // OTP to the SENDER once, so the handover can still be completed by
        // reading it to the receiver in person.
        log_activity('staff_transfer_start', "T-$tid ₹$amount to {$receiver['name']} (WA failed, OTP shown on screen)");
        flash('WhatsApp could not be sent (' . ($receiver['mobile'] ? whatsapp_last_error() : 'no mobile on this staff') . '). OTP for this handover: ' . $otp . ' — tell it to ' . $receiver['name'] . ' and enter it below to complete.', 'error');
    }
    redirect('cash_bank.php');
}

// ---------- staff transfer: step 2, confirm with OTP ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'staff_confirm') {
    $tid = (int)post('id');
    $t = row("SELECT * FROM money_transfers WHERE id = ? AND txn_type = 'staff_transfer' AND status = 'pending'", [$tid]);
    if (!$t) { flash('Transfer not found or already completed.', 'error'); redirect('cash_bank.php'); }
    // only the two people involved (or someone with full cash rights) may
    // type the OTP for this handover
    if (!can('cashbank.viewall') && !can('cashbank.transfer')
        && (int)$t['from_user_id'] !== (int)$u['id'] && (int)$t['to_user_id'] !== (int)$u['id']) {
        flash('This handover is between two other staff members.', 'error'); redirect('cash_bank.php');
    }
    if ($t['otp_expires'] && $t['otp_expires'] < date('Y-m-d H:i:s')) { flash('OTP expired — cancel this transfer and start a new one.', 'error'); redirect('cash_bank.php'); }
    if (!hash_equals($t['otp_hash'], hash('sha256', trim(post('otp'))))) {
        log_activity('staff_transfer_badotp', "T-$tid");
        flash('Wrong OTP. Ask the receiver for the exact 6-digit code.', 'error');
        redirect('cash_bank.php');
    }
    q("UPDATE money_transfers SET status = 'done', otp_hash = NULL WHERE id = ?", [$tid]);
    log_activity('staff_transfer_done', "T-$tid ₹{$t['amount']}");
    flash('✅ Cash handover of ₹' . money($t['amount']) . ' confirmed — wallets updated.');
    redirect('cash_bank.php');
}

// ---------- staff transfer: cancel a pending one ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'staff_cancel') {
    $tid = (int)post('id');
    $t = row("SELECT * FROM money_transfers WHERE id = ? AND txn_type = 'staff_transfer' AND status = 'pending'", [$tid]);
    if ($t && !can('cashbank.viewall') && !can('cashbank.transfer')
        && (int)$t['from_user_id'] !== (int)$u['id'] && (int)$t['to_user_id'] !== (int)$u['id']) {
        flash('This handover is between two other staff members.', 'error'); redirect('cash_bank.php');
    }
    if ($t) { q("UPDATE money_transfers SET status = 'cancelled' WHERE id = ?", [$tid]); flash('Pending handover cancelled — no money moved.'); }
    redirect('cash_bank.php');
}

// ---------- delete a transfer / adjustment (ADMIN only) ----------
// All balances (wallets, cash total, bank) are COMPUTED from the tables, so
// deleting the row is a clean reversal - nothing else to unwind.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'mt_delete') {
    if (!is_full_admin()) { flash('Only an admin can delete this entry.', 'error'); redirect('cash_bank.php'); }
    $t = row('SELECT * FROM money_transfers WHERE id = ?', [(int)post('id')]);
    if ($t && $t['status'] !== 'pending') {
        q('DELETE FROM money_transfers WHERE id = ?', [$t['id']]);
        log_activity('cashbank_mt_delete', "T-{$t['id']} {$t['txn_type']} ₹{$t['amount']}");
        flash('Entry deleted — balances recalculated.');
    }
    redirect(post('back') === 'ledger' ? 'cash_bank.php?action=cash_ledger' : 'cash_bank.php');
}

// ---------- edit a transfer / adjustment (ADMIN only) ----------
// Amount / date / note (and add-vs-reduce on adjustments) can change; the
// accounts/wallets involved cannot - for that, delete and re-enter. Balances
// recompute from the row, so an UPDATE is as clean as the delete above.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'mt_update') {
    if (!is_full_admin()) { flash('Only an admin can edit this entry.', 'error'); redirect('cash_bank.php'); }
    $t = row('SELECT * FROM money_transfers WHERE id = ?', [(int)post('id')]);
    $amount = round((float)post('amount'), 2);
    if (!$t || $t['status'] !== 'done' || $amount <= 0) { flash('Entry not found or not editable.', 'error'); redirect('cash_bank.php'); }
    $dirAdj = in_array($t['txn_type'], ['cash_adjust', 'bank_adjust'], true)
        ? (post('adjust_dir') === 'reduce' ? 'reduce' : 'add') : $t['adjust_dir'];
    q('UPDATE money_transfers SET amount = ?, txn_date = ?, notes = ?, adjust_dir = ? WHERE id = ?',
      [$amount, post('txn_date') ?: $t['txn_date'], trim(post('notes')), $dirAdj, $t['id']]);
    log_activity('cashbank_mt_edit', "T-{$t['id']} {$t['txn_type']} ₹" . money($t['amount']) . ' → ₹' . money($amount));
    flash('Entry updated — balances recalculated.');
    redirect('cash_bank.php');
}

// ---------- full CASH ledger (tap on the Cash in Hand card) ----------
// Every cash movement in one date-wise list - who collected/paid it, which
// bill/party it belongs to - with Open (edit/delete) on payments & expenses
// and Delete on transfers/adjustments.
if (get('action') === 'cash_ledger') {
    $from = get('from', date('Y-m-01'));
    $to = get('to', today());
    $fStaff = (int)get('staff');
    if (!$seeAll) $fStaff = (int)$u['id']; // own wallet only
    $staffAll = all('SELECT id, name FROM users ORDER BY name');

    $rows = [];
    $pw = $fStaff ? ' AND p.created_by = ' . $fStaff : '';
    foreach (all("SELECT p.*, pt.name party_name, u2.name staff_name FROM payments p
                  LEFT JOIN parties pt ON pt.id = p.party_id JOIN users u2 ON u2.id = p.created_by
                  WHERE p.mode = 'cash' AND p.pay_date BETWEEN ? AND ? $pw ORDER BY p.pay_date, p.id", [$from, $to]) as $p) {
        $desc = ($p['direction'] === 'in' ? 'Received' : 'Paid') . ' — ' . ($p['party_name'] ?: 'Walk-in');
        if ($p['notes']) $desc .= ' · ' . $p['notes'];
        $rows[] = ['sort' => $p['pay_date'] . '-2' . str_pad($p['id'], 8, '0', STR_PAD_LEFT), 'date' => $p['pay_date'],
                   'desc' => $desc, 'staff' => $p['staff_name'],
                   'in' => $p['direction'] === 'in' ? (float)$p['amount'] : 0, 'out' => $p['direction'] === 'out' ? (float)$p['amount'] : 0,
                   'open' => 'payments.php?action=view&id=' . $p['id'], 'del' => null];
    }
    $ew = $fStaff ? ' AND e.created_by = ' . $fStaff : '';
    foreach (all("SELECT e.*, u2.name staff_name FROM expenses e JOIN users u2 ON u2.id = e.created_by
                  WHERE e.mode = 'cash' AND e.exp_date BETWEEN ? AND ? $ew ORDER BY e.exp_date, e.id", [$from, $to]) as $x) {
        $rows[] = ['sort' => $x['exp_date'] . '-3' . str_pad($x['id'], 8, '0', STR_PAD_LEFT), 'date' => $x['exp_date'],
                   'desc' => 'Expense — ' . $x['category'] . ($x['notes'] ? ' · ' . $x['notes'] : ''), 'staff' => $x['staff_name'],
                   'in' => 0, 'out' => (float)$x['amount'],
                   'open' => 'expenses.php?from=' . $x['exp_date'] . '&to=' . $x['exp_date'], 'del' => null];
    }
    foreach (all("SELECT mt.*, fu.name from_name, tu.name to_name, fb.account_name from_bank, tb.account_name to_bank
                  FROM money_transfers mt
                  LEFT JOIN users fu ON fu.id = mt.from_user_id LEFT JOIN users tu ON tu.id = mt.to_user_id
                  LEFT JOIN bank_accounts fb ON fb.id = mt.from_bank_id LEFT JOIN bank_accounts tb ON tb.id = mt.to_bank_id
                  WHERE mt.status = 'done' AND mt.txn_type IN ('cash_to_bank','bank_to_cash','cash_adjust','staff_transfer')
                    AND mt.txn_date BETWEEN ? AND ? ORDER BY mt.txn_date, mt.id", [$from, $to]) as $t) {
        if ($fStaff && (int)$t['from_user_id'] !== $fStaff && (int)$t['to_user_id'] !== $fStaff) continue;
        $in = 0; $out = 0;
        switch ($t['txn_type']) {
            case 'cash_to_bank': $out = (float)$t['amount']; $desc = 'Deposited to bank (' . $t['to_bank'] . ') from ' . $t['from_name'] . "'s cash"; break;
            case 'bank_to_cash': $in = (float)$t['amount']; $desc = 'Withdrawn from bank (' . $t['from_bank'] . ') to ' . $t['to_name'] . "'s cash"; break;
            case 'cash_adjust':
                if ($t['adjust_dir'] === 'add') $in = (float)$t['amount']; else $out = (float)$t['amount'];
                $desc = 'Cash adjusted (' . $t['from_name'] . ')' . ($t['notes'] ? ' · ' . $t['notes'] : ''); break;
            default: // staff_transfer
                if ($fStaff) { if ((int)$t['to_user_id'] === $fStaff) $in = (float)$t['amount']; else $out = (float)$t['amount']; }
                else { $in = (float)$t['amount']; $out = (float)$t['amount']; } // whole-shop view: internal move, net zero
                $desc = 'Handover ' . $t['from_name'] . ' → ' . $t['to_name'];
        }
        $rows[] = ['sort' => $t['txn_date'] . '-5' . str_pad($t['id'], 8, '0', STR_PAD_LEFT), 'date' => $t['txn_date'],
                   'desc' => $desc . ($t['notes'] && $t['txn_type'] !== 'cash_adjust' ? ' · ' . $t['notes'] : ''),
                   'staff' => $t['from_name'] ?: $t['to_name'], 'in' => $in, 'out' => $out,
                   'open' => null, 'del' => (int)$t['id']];
    }
    // ---- opening balance: everything BEFORE the period start, so last
    // month's closing cash carries into this month automatically (staff had
    // ₹845 on the 31st -> this month opens showing those ₹845) ----
    $opening = 0.0;
    $opening += (float)val("SELECT COALESCE(SUM(CASE WHEN direction='in' THEN amount ELSE -amount END),0)
                            FROM payments WHERE mode='cash' AND pay_date < ?" . ($fStaff ? ' AND created_by = ' . $fStaff : ''), [$from]);
    $opening -= (float)val("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE mode='cash' AND exp_date < ?" . ($fStaff ? ' AND created_by = ' . $fStaff : ''), [$from]);
    foreach (all("SELECT * FROM money_transfers WHERE status='done' AND txn_type IN ('cash_to_bank','bank_to_cash','cash_adjust','staff_transfer')
                  AND txn_date < ?", [$from]) as $t) {
        if ($fStaff && (int)$t['from_user_id'] !== $fStaff && (int)$t['to_user_id'] !== $fStaff) continue;
        switch ($t['txn_type']) {
            case 'cash_to_bank': $opening -= (float)$t['amount']; break;
            case 'bank_to_cash': $opening += (float)$t['amount']; break;
            case 'cash_adjust': $opening += ($t['adjust_dir'] === 'add' ? 1 : -1) * (float)$t['amount']; break;
            default: if ($fStaff) $opening += ((int)$t['to_user_id'] === $fStaff ? 1 : -1) * (float)$t['amount']; // whole-shop: internal move, net zero
        }
    }

    usort($rows, fn($a, $b) => strcmp($a['sort'], $b['sort']));
    // Running balance is computed in date order, but the list is SHOWN newest
    // first - today's entries sit on top, oldest fall to the bottom, so the
    // fresh stuff never needs scrolling for.
    $bal = $opening;
    foreach ($rows as &$x) { $bal += $x['in'] - $x['out']; $x['bal'] = $bal; }
    unset($x);
    $rows = array_reverse($rows);
    // the opening row sits at the (oldest) bottom of the newest-first list;
    // tapping it re-opens the ledger from day one, so the full break-up of
    // that carried-over amount is one tap away
    $fullUrl = 'cash_bank.php?action=cash_ledger&from=2020-01-01&to=' . e(today()) . '&staff=' . $fStaff;
    $rows[] = ['date' => $from, 'desc' => '🏦 Opening Balance (' . dmy($from) . ' earlier — carried in from the previous month) · tap for the full detail', 'staff' => '',
               'in' => 0, 'out' => 0, 'bal' => $opening, 'open' => $fullUrl, 'del' => null, 'opening' => true];

    $liveBal = $fStaff ? staff_cash($fStaff) : total_cash_in_hand();
    $canDelMt = is_full_admin();
    $page_title = 'Cash Ledger';
    include __DIR__ . '/includes/header.php';
    ?>
    <div class="page-actions no-print">
      <a class="btn btn-outline" href="cash_bank.php">← Cash &amp; Bank</a>
      <button class="btn btn-outline btn-sm" onclick="window.print()">🖨️ Print</button>
    </div>
    <div class="duo-cards">
      <div class="duo-card duo-get"><div class="duo-label">💵 <?= $fStaff ? e(array_values(array_filter($staffAll, fn($s) => $s['id'] == $fStaff))[0]['name'] ?? '') . "'s cash now" : 'Cash in Hand now (total)' ?></div>
        <div class="duo-value">₹ <?= money($liveBal) ?></div></div>
    </div>
    <form method="get" class="filterbar no-print">
      <input type="hidden" name="action" value="cash_ledger">
      <div><label>From</label><input type="date" name="from" value="<?= e($from) ?>"></div>
      <div><label>To</label><input type="date" name="to" value="<?= e($to) ?>"></div>
      <?php if ($seeAll): ?>
      <div><label>Whose cash?</label>
        <select name="staff"><option value="0">Whole shop</option>
          <?php foreach ($staffAll as $s): ?><option value="<?= $s['id'] ?>" <?= $fStaff == $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?>
        </select></div>
      <?php endif; ?>
      <button class="btn btn-sm" type="submit">Show</button>
      <a class="btn btn-sm btn-outline" href="<?= e($fullUrl) ?>">📜 Full statement</a>
    </form>
    <div class="table-wrap list-style-table">
    <table>
      <thead><tr><th>Entry</th><th class="num">In ₹</th><th class="num">Out ₹</th><th class="num">Running</th><th class="no-print"></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $x): ?>
      <tr<?= !empty($x['opening']) ? ' style="background:var(--card-alt);font-weight:700"' : '' ?>>
        <td><strong><?= $x['open'] ? '<a href="' . e($x['open']) . '">' . e($x['desc']) . '</a>' : e($x['desc']) ?></strong>
          <span class="list-row-sub muted"><?= dmy($x['date']) ?> · <?= e($x['staff']) ?></span></td>
        <td class="num" style="color:var(--ok)"><?= $x['in'] ? money($x['in']) : '' ?></td>
        <td class="num" style="color:var(--bad)"><?= $x['out'] ? money($x['out']) : '' ?></td>
        <td class="num"><?= money($x['bal']) ?></td>
        <td class="no-print" style="white-space:nowrap">
          <?php if ($x['open']): ?><a class="btn btn-sm btn-outline" href="<?= e($x['open']) ?>">Open</a><?php endif; ?>
          <?php if ($x['del'] && $canDelMt): ?>
          <form method="post" style="display:inline" onsubmit="return confirm('Delete this entry? Balances will be recalculated.')">
            <?= csrf_field() ?><input type="hidden" name="do" value="mt_delete"><input type="hidden" name="id" value="<?= $x['del'] ?>"><input type="hidden" name="back" value="ledger">
            <button class="btn btn-sm btn-danger" type="submit">✕</button></form>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; if (!$rows): ?><tr><td colspan="5" class="muted">No cash entries in this period.</td></tr><?php endif; ?>
      </tbody>
    </table>
    </div>
    <p class="muted mt" style="font-size:12.5px">"Running" starts from the Opening Balance (everything before the From date — last month's cash carries over automatically). "Open" on a receipt/payment goes to its page, where it can be edited or deleted; handovers/adjustments/deposits delete here with ✕.</p>
    <?php
    include __DIR__ . '/includes/footer.php';
    exit;
}

// ---------- data for the page ----------
$today = today();
// The day's summary can be asked about any day - "what came in on Monday" is
// a question somebody has at a counter. A BALANCE cannot: cash in hand and a
// bank balance are what is there now, not what was there on a chosen date,
// so the date box moves this block and nothing else, and the cards say so.
$sumDate = get('d', $today);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $sumDate)) $sumDate = $today;
$ownW = $seeAll ? '' : ' AND created_by = ' . (int)$u['id'];
$todayCashIn = (float)val("SELECT COALESCE(SUM(amount),0) FROM payments WHERE mode = 'cash' AND direction = 'in' AND pay_date = ?$ownW", [$sumDate]);
$todayCashOut = (float)val("SELECT COALESCE(SUM(amount),0) FROM payments WHERE mode = 'cash' AND direction = 'out' AND pay_date = ?$ownW", [$sumDate]);
$todayCashExp = (float)val("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE mode = 'cash' AND exp_date = ?$ownW", [$sumDate]);
$netCash = $todayCashIn - $todayCashOut - $todayCashExp;
$cashInHand = $seeAll ? total_cash_in_hand() : staff_cash($u['id']);

$staffAll = all('SELECT id, name, mobile FROM users WHERE is_active = 1 ORDER BY name');

// The wallet LIST is not the same list as the staff dropdowns. Cash cannot be
// handed to somebody who has left the shop, so they stay out of the dropdowns
// - but if their wallet is not empty then the money is still in a hand
// somewhere, and leaving them off the screen is how the rows above a total
// stop adding up to it.
$wallets = [];
$walletSum = 0.0;
foreach (all('SELECT id, name, is_active FROM users ORDER BY name') as $s) {
    if (!$seeAll && (int)$s['id'] !== (int)$u['id']) continue; // only my wallet
    $c = staff_cash($s['id']);
    if (!$s['is_active'] && abs($c) < 0.009) continue;
    $wallets[] = ['id' => $s['id'], 'name' => $s['name'], 'cash' => $c, 'active' => (int)$s['is_active']];
    $walletSum += $c;
}

// ...AND WHATEVER IS LEFT OVER.
//
// Every wallet added together should be the shop's cash in hand: the two
// formulas are the same arithmetic, one split by whose hand it is in. On this
// shop's data they were ₹5,200 apart, because cash expenses had been recorded
// against a staff account that was later removed - the money comes off the
// drawer total but off nobody's wallet.
//
// A list of wallets printed above a total it does not add up to is the thing
// that makes an owner stop believing the screen. So the difference is shown,
// named, as a row of its own. It is not hidden and it is not quietly folded
// into somebody's wallet, because it is not theirs.
$unassigned = $seeAll ? money_r($cashInHand - $walletSum) : 0.0;

$banks = all('SELECT * FROM bank_accounts WHERE is_active = 1 ORDER BY is_default DESC, account_name');
$totalBankBal = 0;
foreach ($banks as &$b) { $b['balance'] = bank_account_balance($b['id']); $totalBankBal += $b['balance']; }
unset($b);

$ownMt = $seeAll ? '' : ' AND (mt.from_user_id = ' . (int)$u['id'] . ' OR mt.to_user_id = ' . (int)$u['id'] . ')';
$pending = all("SELECT mt.*, fu.name from_name, tu.name to_name FROM money_transfers mt
                LEFT JOIN users fu ON fu.id = mt.from_user_id LEFT JOIN users tu ON tu.id = mt.to_user_id
                WHERE mt.txn_type = 'staff_transfer' AND mt.status = 'pending'$ownMt ORDER BY mt.id DESC");
$recentMoves = all("SELECT mt.*, fu.name from_name, tu.name to_name, fb.account_name from_bank, tb.account_name to_bank
                    FROM money_transfers mt
                    LEFT JOIN users fu ON fu.id = mt.from_user_id LEFT JOIN users tu ON tu.id = mt.to_user_id
                    LEFT JOIN bank_accounts fb ON fb.id = mt.from_bank_id LEFT JOIN bank_accounts tb ON tb.id = mt.to_bank_id
                    WHERE mt.status <> 'pending'$ownMt ORDER BY mt.id DESC LIMIT 15");
$recentCash = all("SELECT p.*, pt.name party_name, u2.name staff_name FROM payments p LEFT JOIN parties pt ON pt.id = p.party_id
                   JOIN users u2 ON u2.id = p.created_by WHERE p.mode = 'cash'" . ($seeAll ? '' : ' AND p.created_by = ' . (int)$u['id']) . " ORDER BY p.id DESC LIMIT 10");

function mt_label($t) {
    switch ($t['txn_type']) {
        case 'cash_to_bank': return '💵→🏦 Cash to Bank (' . ($t['to_bank'] ?? '') . ')' . ($t['from_name'] ? ' from ' . $t['from_name'] . "'s cash" : '');
        case 'bank_to_cash': return '🏦→💵 Bank to Cash (' . ($t['from_bank'] ?? '') . ')' . ($t['to_name'] ? ' to ' . $t['to_name'] . "'s cash" : '');
        case 'bank_to_bank': return '🏦→🏦 ' . ($t['from_bank'] ?? '') . ' → ' . ($t['to_bank'] ?? '');
        case 'cash_adjust': return ($t['adjust_dir'] === 'add' ? '➕' : '➖') . ' Cash adjust (' . ($t['from_name'] ?: '') . ')';
        case 'bank_adjust': return ($t['adjust_dir'] === 'add' ? '➕' : '➖') . ' Bank adjust (' . ($t['to_bank'] ?? '') . ')';
        case 'staff_transfer': return '🤝 ' . ($t['from_name'] ?: '?') . ' → ' . ($t['to_name'] ?: '?') . ($t['status'] === 'cancelled' ? ' (cancelled)' : '');
    }
    return $t['txn_type'];
}

$page_title = 'Cash & Bank';
include __DIR__ . '/includes/header.php';
?>
<div class="pg-head">
  <div class="pg-main">
    <div class="pg-crumb">Cash &amp; Bank</div>
    <h1>💰 Cash &amp; Bank</h1>
    <div class="pg-sub">Cash, bank balances, staff wallets, transfers and the day's collections.</div>
  </div>
  <?php if ($seeAll): ?>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <a class="btn btn-outline btn-sm" href="bank_accounts.php">🏦 Manage banks</a>
    <a class="btn btn-outline btn-sm" href="reports.php?r=cashbook">📒 Full cashbook</a>
  </div>
  <?php endif; ?>
</div>

<!-- What is there RIGHT NOW. Not one of these is a period figure, which is
     why the date box further down moves the day's summary and leaves these
     alone: a balance asked "as of last Tuesday" is a different report. -->
<div class="kpi-row">
  <a class="kpi k-ok" href="cash_bank.php?action=cash_ledger">
    <div class="kpi-top">💵 <?= $seeAll ? 'Cash in Hand' : 'My Cash' ?></div>
    <div class="kpi-val">₹<?= money($cashInHand) ?></div>
    <div class="kpi-sub">View the full ledger →</div>
  </a>
  <?php if ($seeAll): ?>
  <a class="kpi k-info" href="reports.php?r=bank_ledger">
    <div class="kpi-top">🏦 Total Bank Balance</div>
    <div class="kpi-val">₹<?= money($totalBankBal) ?></div>
    <div class="kpi-sub"><?= count($banks) ?> account<?= count($banks) === 1 ? '' : 's' ?> · view the passbook →</div>
  </a>
  <?php
  // NOT a second copy of "Cash in Hand" - that is the same rupees said twice
  // and two identical figures side by side is how a screen stops being
  // believed. What this one answers is the question the other cannot: how
  // many hands the money is in, and whose hand holds the most of it.
  $holders = array_values(array_filter($wallets, fn($w) => abs($w['cash']) > 0.009));
  usort($holders, fn($x, $y) => $y['cash'] <=> $x['cash']);
  ?>
  <a class="kpi k-warn" href="#wallets">
    <div class="kpi-top">👥 Staff holding cash</div>
    <div class="kpi-val"><?= count($holders) ?></div>
    <div class="kpi-sub"><?= $holders ? e($holders[0]['name']) . ' has the most, ₹' . money($holders[0]['cash']) : 'nobody is carrying cash' ?></div>
  </a>
  <?php endif; ?>
  <a class="kpi <?= $netCash < -0.009 ? 'k-bad' : 'k-ok' ?>" href="#daysum">
    <div class="kpi-top">📅 Net cash · <?= dmy($sumDate) ?></div>
    <div class="kpi-val">₹<?= money($netCash) ?></div>
    <div class="kpi-sub">in, less what went out →</div>
  </a>
</div>

<!-- The things this screen exists to DO, before the things it exists to SHOW -->
<div class="pane">
  <div class="pane-head"><h3>⚡ Move money</h3></div>
  <div class="pane-body">
    <div class="qa-grid">
      <?php if ($canTransfer): ?>
      <button type="button" class="qa qa-ok" onclick="cbShowTransfer('cash_to_bank')">
        <span class="qa-i">💵→🏦</span><span class="qa-n">Cash to Bank</span><span class="qa-s">Deposit takings</span></button>
      <button type="button" class="qa" onclick="cbShowTransfer('bank_to_cash')">
        <span class="qa-i">🏦→💵</span><span class="qa-n">Bank to Cash</span><span class="qa-s">Withdraw for the till</span></button>
      <button type="button" class="qa" onclick="cbShowTransfer('bank_to_bank')">
        <span class="qa-i">🏦→🏦</span><span class="qa-n">Bank to Bank</span><span class="qa-s">Between two accounts</span></button>
      <?php endif; ?>
      <button type="button" class="qa qa-ok" onclick="cbShow('cbStaff')">
        <span class="qa-i">🤝</span><span class="qa-n">Staff handover</span><span class="qa-s">Cash to another person, OTP confirmed</span></button>
      <?php if ($canAdjust): ?>
      <button type="button" class="qa qa-warn" onclick="cbShow('cbAdjust')">
        <span class="qa-i">⚖️</span><span class="qa-n">Adjust cash or bank</span><span class="qa-s">Counting difference, bank charges</span></button>
      <?php endif; ?>
      <a class="qa" href="cash_bank.php?action=cash_ledger">
        <span class="qa-i">📒</span><span class="qa-n">Cash ledger</span><span class="qa-s">Every movement, date-wise</span></a>
      <?php if ($seeAll): ?>
      <a class="qa" href="reports.php?r=cashbook">
        <span class="qa-i">📊</span><span class="qa-n">Full cashbook</span><span class="qa-s">The complete report</span></a>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php if ($pending): ?>
<div class="pane" style="border:2px solid var(--warn)">
  <div class="pane-head"><h3>⏳ Handovers waiting for an OTP (<?= count($pending) ?>)</h3>
    <span class="pane-note" style="padding:0">No money has moved yet</span></div>
  <div class="pane-body">
  <?php foreach ($pending as $p): ?>
    <div style="border:1px solid var(--line);border-radius:12px;padding:12px;margin-bottom:10px">
      <div><strong><?= e($p['from_name']) ?> → <?= e($p['to_name']) ?></strong> · <strong>₹<?= money($p['amount']) ?></strong></div>
      <div class="muted" style="font-size:12.5px;margin-top:3px">Started <?= dmyt($p['created_at']) ?> · OTP valid till <?= date('h:i A', strtotime($p['otp_expires'])) ?><?= $p['notes'] ? ' · ' . e($p['notes']) : '' ?></div>
      <div style="display:flex;gap:8px;margin-top:10px;flex-wrap:wrap">
        <form method="post" style="display:flex;gap:6px">
          <?= csrf_field() ?><input type="hidden" name="do" value="staff_confirm"><input type="hidden" name="id" value="<?= $p['id'] ?>">
          <input type="text" name="otp" placeholder="6-digit OTP" inputmode="numeric" maxlength="6" style="width:130px" required>
          <button class="btn btn-sm" type="submit">✅ Confirm</button>
        </form>
        <form method="post" onsubmit="return confirm('Cancel this handover? No money will move.')">
          <?= csrf_field() ?><input type="hidden" name="do" value="staff_cancel"><input type="hidden" name="id" value="<?= $p['id'] ?>">
          <button class="btn btn-sm btn-outline" type="submit">✕ Cancel</button>
        </form>
      </div>
    </div>
  <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<div class="row-2">
  <div class="pane" id="daysum">
    <div class="pane-head"><h3>📅 Cash on one day</h3>
      <form method="get" style="display:flex;gap:6px;align-items:center">
        <input type="date" name="d" value="<?= e($sumDate) ?>" onchange="this.form.submit()">
        <noscript><button class="btn btn-sm" type="submit">Show</button></noscript>
      </form>
    </div>
    <div class="pane-body">
      <table class="rowlist">
        <tbody>
          <tr><td data-l="Cash received">Cash received</td><td class="num money-in" data-l="Cash received">₹<?= money($todayCashIn) ?></td></tr>
          <tr><td data-l="Cash paid out">Cash paid out</td><td class="num money-out" data-l="Cash paid out">₹<?= money($todayCashOut) ?></td></tr>
          <tr><td data-l="Cash expenses">Cash expenses</td><td class="num money-out" data-l="Cash expenses">₹<?= money($todayCashExp) ?></td></tr>
        </tbody>
      </table>
      <div class="big-net<?= $netCash < -0.009 ? ' neg' : '' ?>">
        <span class="bn-l">Net cash on <?= dmy($sumDate) ?></span>
        <span class="bn-v">₹<?= money($netCash) ?></span>
      </div>
    </div>
  </div>

  <div class="pane" id="wallets">
    <div class="pane-head"><h3>👥 <?= $seeAll ? 'Whose hand holds how much' : 'My wallet' ?></h3></div>
    <div class="pane-body tight">
      <table class="rowlist">
        <thead><tr><th>Staff</th><th class="num">Cash</th><th class="act"></th></tr></thead>
        <tbody>
        <?php foreach ($wallets as $w): ?>
          <tr>
            <td data-l="Staff"><?= e($w['name']) ?><?= $w['id'] == $u['id'] ? ' <span class="badge badge-info">you</span>' : '' ?><?= $w['active'] ? '' : ' <span class="badge badge-bad">left</span>' ?></td>
            <td class="num" data-l="Cash" style="font-weight:700;color:<?= $w['cash'] < -0.009 ? 'var(--bad)' : 'var(--ok)' ?>">₹<?= money($w['cash']) ?></td>
            <td class="act"><a class="btn btn-sm btn-outline" href="cash_bank.php?action=cash_ledger&staff=<?= (int)$w['id'] ?>">Wallet →</a></td>
          </tr>
        <?php endforeach; ?>
        <?php if ($seeAll && abs($unassigned) > 0.009): ?>
          <tr>
            <td data-l="Staff"><strong style="color:var(--warn)">⚠️ Not in anybody's wallet</strong>
              <div class="muted" style="font-size:11.5px;font-weight:400">Cash entries recorded under a staff account that has since been removed. The money is in the shop total but there is no hand to ask for it.</div></td>
            <td class="num" data-l="Cash" style="font-weight:700;color:var(--warn)">₹<?= money($unassigned) ?></td>
            <td class="act"></td>
          </tr>
        <?php endif; ?>
        <?php if ($seeAll): ?>
          <tr><td data-l="Total"><strong>Total cash in hand</strong></td>
              <td class="num" data-l="Total"><strong>₹<?= money($cashInHand) ?></strong></td><td class="act"></td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
    <p class="pane-note" style="padding:10px 14px 14px">A wallet is the cash that person collected, less what they paid or spent, plus or minus handovers and bank deposits. Entries from before wallets existed sit under whoever recorded them.</p>
  </div>
</div>

<?php if ($seeAll): ?>
<div class="pane">
  <div class="pane-head"><h3>🏦 Bank accounts</h3>
    <a class="btn btn-sm btn-outline" href="bank_accounts.php">＋ Add / manage</a></div>
  <div class="pane-body tight">
  <?php if (!$banks): ?>
    <p class="pane-note" style="padding:20px 14px">No bank account yet. <a href="bank_accounts.php">Add the first one →</a></p>
  <?php else: ?>
    <table class="rowlist">
      <thead><tr><th>Account</th><th>Bank</th><th class="num">Balance</th><th class="act"></th></tr></thead>
      <tbody>
      <?php foreach ($banks as $b): ?>
        <tr>
          <td data-l="Account"><a href="reports.php?r=bank_ledger&bank_id=<?= $b['id'] ?>"><strong><?= e($b['account_name']) ?></strong></a>
            <?= $b['is_default'] ? ' <span class="badge badge-ok">DEFAULT</span>' : '' ?></td>
          <td data-l="Bank" class="muted"><?= e($b['bank_name']) ?></td>
          <td class="num" data-l="Balance" style="font-weight:700;color:<?= $b['balance'] < -0.009 ? 'var(--bad)' : 'inherit' ?>">₹<?= money($b['balance']) ?></td>
          <td class="act"><a class="btn btn-sm btn-outline" href="reports.php?r=bank_ledger&bank_id=<?= $b['id'] ?>">📒 Ledger</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<?php if ($recentMoves): $isAdminMt = is_full_admin(); ?>
<div class="pane">
  <div class="pane-head"><h3>🔁 Recent transfers &amp; adjustments</h3>
    <span class="pane-note" style="padding:0">Last <?= count($recentMoves) ?></span></div>
  <div class="pane-body tight">
  <table class="rowlist rl-wide">
    <thead><tr><th>Date</th><th>What</th><th class="num">Amount</th><th>Note</th><th>Status</th><?= $isAdminMt ? '<th class="act"></th>' : '' ?></tr></thead>
    <tbody><?php foreach ($recentMoves as $t): ?>
    <tr <?= $t['status'] === 'cancelled' ? 'style="opacity:.55"' : '' ?>>
      <td data-l="Date"><?= dmy($t['txn_date']) ?></td>
      <td data-l="What"><?= e(mt_label($t)) ?></td>
      <td class="num" data-l="Amount"><strong>₹<?= money($t['amount']) ?></strong></td>
      <td data-l="Note" class="muted"><?= $t['notes'] ? e($t['notes']) : '—' ?></td>
      <td data-l="Status"><?= $t['status'] === 'cancelled'
            ? '<span class="badge badge-bad">CANCELLED</span>'
            : '<span class="badge badge-ok">DONE</span>' ?></td>
      <?php if ($isAdminMt): ?>
      <td class="act no-print">
        <?php if ($t['status'] === 'done'): ?>
        <button type="button" class="btn btn-sm btn-outline" onclick='mtEdit(<?= json_encode([
            'id' => (int)$t['id'], 'label' => mt_label($t), 'amount' => (float)$t['amount'],
            'date' => $t['txn_date'], 'notes' => (string)$t['notes'],
            'isAdj' => in_array($t['txn_type'], ['cash_adjust', 'bank_adjust'], true) ? 1 : 0,
            'dir' => (string)$t['adjust_dir'],
        ], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_TAG) ?>)'>✏️ Edit</button>
        <?php endif; ?>
        <form method="post" style="display:inline" onsubmit="return confirm('Delete this entry?\n\nEvery balance it touched — cash in hand, the wallet, the bank account — is recalculated from the remaining rows.')">
          <?= csrf_field() ?><input type="hidden" name="do" value="mt_delete"><input type="hidden" name="id" value="<?= $t['id'] ?>">
          <button class="btn btn-sm btn-danger" type="submit">🗑 Delete</button></form>
      </td>
      <?php endif; ?>
    </tr>
    <?php endforeach; ?></tbody>
  </table>
  </div>
  <?php if ($isAdminMt): ?><p class="pane-note" style="padding:10px 14px 14px">Edit and Delete are an admin's only — change or remove an entry and every balance is worked out again from what is left.</p><?php endif; ?>
</div>

<?php if ($isAdminMt): ?>
<!-- admin edit modal for a transfer/adjustment row -->
<div class="modal-overlay no-print" id="cbMtEdit">
  <div class="modal-box">
    <h3>✏️ Edit entry</h3>
    <p class="muted" id="mtEditWhat" style="font-size:13px"></p>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="do" value="mt_update"><input type="hidden" name="id" id="mtEditId">
      <div class="field" id="mtDirRow" style="display:none"><label>Add or reduce?</label>
        <select name="adjust_dir" id="mtEditDir"><option value="add">➕ Add to balance</option><option value="reduce">➖ Reduce balance</option></select></div>
      <div class="form-row cols-2">
        <div><label>Amount ₹ *</label><input type="number" step="any" min="0.01" name="amount" id="mtEditAmount" required></div>
        <div><label>Date</label><input type="date" name="txn_date" id="mtEditDate"></div>
      </div>
      <div class="field"><label>Note</label><input type="text" name="notes" id="mtEditNotes"></div>
      <p class="muted" style="font-size:12.5px">To change the bank or the wallet, remove this entry and make a new one — those cannot be edited in place.</p>
      <div class="modal-actions">
        <button type="button" class="btn btn-outline" onclick="cbHide('cbMtEdit')">Cancel</button>
        <button class="btn" type="submit">Save changes</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>
<?php endif; ?>

<div class="pane">
  <div class="pane-head"><h3>💵 Recent cash entries</h3>
    <a class="btn btn-sm btn-outline" href="reports.php?r=cashbook">📒 Full cashbook →</a></div>
  <div class="pane-body tight">
  <?php if (!$recentCash): ?>
    <p class="pane-note" style="padding:20px 14px">No cash has moved yet.</p>
  <?php else: ?>
  <!-- rl-scan: on a phone this is a list to glance down, so the party and the
       amount sit on the first line and the date, wallet and direction follow
       as one quiet strip. The desktop table is unchanged. -->
  <table class="rowlist rl-scan">
    <thead><tr><th>Party</th><th>Date</th><th>Wallet</th><th>In / out</th><th class="num">Amount</th></tr></thead>
    <tbody><?php foreach ($recentCash as $c): ?>
    <tr>
      <td class="rl-main" data-l="Party"><?= $c['party_id']
            ? '<a href="parties.php?action=ledger&id=' . (int)$c['party_id'] . '">' . e($c['party_name']) . '</a>'
            : e($c['party_name'] ?: 'Walk-in') ?></td>
      <td data-l="Date"><?= dmy($c['pay_date']) ?></td>
      <td data-l="Wallet" class="muted"><?= e($c['staff_name']) ?></td>
      <td data-l="In / out"><?= $c['direction'] === 'in'
            ? '<span class="badge badge-ok">🟢 IN</span>' : '<span class="badge badge-bad">🔴 OUT</span>' ?></td>
      <td class="num <?= $c['direction'] === 'in' ? 'money-in' : 'money-out' ?>" data-l="Amount"><strong>₹<?= money($c['amount']) ?></strong></td>
    </tr>
    <?php endforeach; ?></tbody>
  </table>
  <?php endif; ?>
  </div>
</div>

<?php if ($canAdjust): ?>
<!-- adjust modal -->
<div class="modal-overlay no-print" id="cbAdjust">
  <div class="modal-box">
    <h3>⚖️ Adjust Cash / Bank balance</h3>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="do" value="adjust">
      <div class="field"><label>What to adjust?</label>
        <select name="kind" id="adjKind" onchange="document.getElementById('adjBankRow').style.display=this.value==='bank'?'':'none';document.getElementById('adjWalletRow').style.display=this.value==='bank'?'none':''">
          <option value="cash">💵 Cash in hand</option>
          <option value="bank">🏦 Bank balance</option>
        </select></div>
      <div class="field" id="adjWalletRow"><label>Whose cash wallet?</label>
        <select name="wallet_user"><?php foreach ($staffAll as $s): ?><option value="<?= $s['id'] ?>" <?= $s['id'] == $u['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
      <div class="field" id="adjBankRow" style="display:none"><label>Bank account</label>
        <select name="bank_id"><?php foreach ($banks as $b): ?><option value="<?= $b['id'] ?>"><?= e($b['account_name']) ?> - <?= e($b['bank_name']) ?></option><?php endforeach; ?></select></div>
      <div class="form-row cols-2">
        <div><label>Add or reduce?</label>
          <select name="adjust_dir"><option value="add">➕ Add to balance</option><option value="reduce">➖ Reduce balance (charges etc.)</option></select></div>
        <div><label>Amount ₹ *</label><input type="number" step="any" min="0.01" name="amount" required></div>
      </div>
      <div class="form-row cols-2">
        <div><label>Date</label><input type="date" name="txn_date" value="<?= today() ?>"></div>
        <div><label>Reason / note</label><input type="text" name="notes" placeholder="e.g. Bank SMS charges"></div>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn btn-outline" onclick="cbHide('cbAdjust')">Cancel</button>
        <button class="btn" type="submit">Save adjustment</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php if ($canTransfer): ?>
<!-- cash/bank transfer modal -->
<div class="modal-overlay no-print" id="cbTransfer">
  <div class="modal-box">
    <h3 id="cbTransferTitle">Transfer</h3>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="do" value="transfer">
      <input type="hidden" name="txn_type" id="tType">
      <div class="field" id="tFromBankRow"><label>From bank account</label>
        <select name="from_bank_id"><?php foreach ($banks as $b): ?><option value="<?= $b['id'] ?>"><?= e($b['account_name']) ?> - <?= e($b['bank_name']) ?><?= $seeAll ? ' (₹' . money($b['balance']) . ')' : '' ?></option><?php endforeach; ?></select></div>
      <div class="field" id="tToBankRow"><label>To bank account</label>
        <select name="to_bank_id"><?php foreach ($banks as $b): ?><option value="<?= $b['id'] ?>"><?= e($b['account_name']) ?> - <?= e($b['bank_name']) ?><?= $seeAll ? ' (₹' . money($b['balance']) . ')' : '' ?></option><?php endforeach; ?></select></div>
      <div class="field" id="tWalletRow"><label>Whose cash wallet?</label>
        <select name="wallet_user"><?php foreach ($staffAll as $s): ?><option value="<?= $s['id'] ?>" <?= $s['id'] == $u['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
      <div class="form-row cols-2">
        <div><label>Amount ₹ *</label><input type="number" step="any" min="0.01" name="amount" required></div>
        <div><label>Date</label><input type="date" name="txn_date" value="<?= today() ?>"></div>
      </div>
      <div class="field"><label>Note</label><input type="text" name="notes" placeholder="optional"></div>
      <div class="modal-actions">
        <button type="button" class="btn btn-outline" onclick="cbHide('cbTransfer')">Cancel</button>
        <button class="btn" type="submit">Save transfer</button>
      </div>
    </form>
  </div>
</div>

<?php endif; ?>
<!-- staff handover modal -->
<div class="modal-overlay no-print" id="cbStaff">
  <div class="modal-box">
    <h3>🤝 Staff cash handover (with OTP)</h3>
    <p class="muted" style="font-size:13px">An OTP goes on WhatsApp to the RECEIVER. Hand over the cash, ask them for the OTP, enter it in the "Pending" box — only then the wallets move.</p>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="do" value="staff_transfer">
      <div class="field"><label>Give cash TO *</label>
        <select name="to_user" required><option value="">-- staff --</option>
          <?php foreach ($staffAll as $s): if ($s['id'] == $u['id']) continue; ?>
          <option value="<?= $s['id'] ?>"><?= e($s['name']) ?><?= $s['mobile'] ? ' (' . e($s['mobile']) . ')' : ' (no mobile!)' ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="form-row cols-2">
        <div><label>Amount ₹ *</label><input type="number" step="any" min="0.01" name="amount" required></div>
        <div><label>Note</label><input type="text" name="notes" placeholder="optional"></div>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn btn-outline" onclick="cbHide('cbStaff')">Cancel</button>
        <button class="btn" type="submit">📲 Send OTP & start handover</button>
      </div>
    </form>
  </div>
</div>

<script>
function cbShow(id) { document.getElementById(id).classList.add('show'); }
function mtEdit(d) {
  document.getElementById('mtEditId').value = d.id;
  document.getElementById('mtEditWhat').textContent = d.label;
  document.getElementById('mtEditAmount').value = d.amount;
  document.getElementById('mtEditDate').value = d.date;
  document.getElementById('mtEditNotes').value = d.notes;
  document.getElementById('mtDirRow').style.display = d.isAdj ? '' : 'none';
  if (d.isAdj) document.getElementById('mtEditDir').value = d.dir || 'add';
  cbShow('cbMtEdit');
}
function cbHide(id) { document.getElementById(id).classList.remove('show'); }
document.querySelectorAll('.modal-overlay').forEach(function (m) { m.addEventListener('click', function (e) { if (e.target === m) m.classList.remove('show'); }); });
var T_TITLES = {cash_to_bank: '💵→🏦 Cash to Bank', bank_to_cash: '🏦→💵 Bank to Cash', bank_to_bank: '🏦→🏦 Bank to Bank'};
function cbShowTransfer(type) {
  document.getElementById('tType').value = type;
  document.getElementById('cbTransferTitle').textContent = T_TITLES[type];
  document.getElementById('tFromBankRow').style.display = (type === 'bank_to_cash' || type === 'bank_to_bank') ? '' : 'none';
  document.getElementById('tToBankRow').style.display = (type === 'cash_to_bank' || type === 'bank_to_bank') ? '' : 'none';
  document.getElementById('tWalletRow').style.display = (type === 'bank_to_bank') ? 'none' : '';
  cbShow('cbTransfer');
}
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
