<?php
// LINKING PAYMENTS TO BILLS, AFTER THE FACT.
//
// A payment is money in (or out) from a party. Which bill it pays is a
// separate question, answered either when it is taken (a bill's own payment,
// payments.ref_type/ref_id, or the allocator on the Payment-In form) or any
// time later, here. A link is a payment_allocations row and the bill's paid
// amount moving with it.
//
// What a link NEVER does: create, delete or change a payment, or move a
// party's balance. The ledger counts the payment whatever bill it is on
// (party_balance_expr), so linking only decides which bills look paid - the
// receivables, aging and reminders that read bills follow by themselves.
//
// The status of a payment is worked out from those rows, never stored:
//   linked   🟢  every rupee is on a bill (or on the opening balance)
//   partial  🟡  some is on bills, the rest is not
//   unlinked 🔴  none of it is on a bill
//   advance  🔵  the owner chose to keep what is not linked as an advance
//                (payments.on_account), to be adjusted against a later bill

/** How much of payment $a is on bills: its own bill (ref_type sale/purchase)
 *  counts in full - payment_reverse() treats it so - plus every allocation. */
function paylink_linked_sql($a = 'p') {
    return "(COALESCE((SELECT SUM(pa.amount) FROM payment_allocations pa WHERE pa.payment_id = $a.id), 0)
            + IF($a.ref_type IN ('sale','purchase') AND $a.ref_id IS NOT NULL, $a.amount, 0))";
}

/** The status, worked out in SQL so lists can be filtered by it. */
function paylink_status_sql($a = 'p') {
    $l = paylink_linked_sql($a);
    return "(CASE WHEN $a.party_id IS NULL OR $a.mode = 'contra' THEN 'na'
                  WHEN $l >= $a.amount - 0.009 THEN 'linked'
                  WHEN $a.on_account = 1 THEN 'advance'
                  WHEN $l > 0.009 THEN 'partial' ELSE 'unlinked' END)";
}

const PAYLINK_LOOK = [
    'linked'   => ['🟢', 'Linked', 'ok'],
    'partial'  => ['🟡', 'Partly linked', 'warn'],
    'unlinked' => ['🔴', 'Not linked', 'bad'],
    'advance'  => ['🔵', 'Advance', 'info'],
    'na'       => ['', '', ''],
];

/** A status chip, from the status word. */
function paylink_chip($status, $unlinked = 0) {
    [$ico, $txt, $cls] = PAYLINK_LOOK[$status] ?? PAYLINK_LOOK['na'];
    if ($ico === '') return '';
    $extra = in_array($status, ['partial', 'unlinked', 'advance'], true) && $unlinked > 0.009 ? ' · ₹' . money($unlinked) . ' free' : '';
    return '<span class="badge badge-' . $cls . '" style="white-space:nowrap">' . $ico . ' ' . $txt . $extra . '</span>';
}

/** One payment with its linked / free amounts and status. */
function paylink_get($pid) {
    return row('SELECT p.*, ' . paylink_linked_sql('p') . ' linked, ' . paylink_status_sql('p') . ' st,
                       pt.name party_name FROM payments p LEFT JOIN parties pt ON pt.id = p.party_id WHERE p.id = ?', [(int)$pid]);
}

/** Every link of one payment: [['kind' => 'alloc'|'direct', 'id', 'ref_type', 'ref_id', 'no', 'amount', 'date', 'total', 'paid']]. */
function paylink_links($pid) {
    $p = row('SELECT * FROM payments WHERE id = ?', [(int)$pid]);
    if (!$p) return [];
    $rows = [];
    $bill = function ($type, $id) {
        if ($type === 'opening') return ['no' => 'Opening balance', 'date' => null, 'total' => null, 'paid' => null];
        $t = $type === 'sale' ? 'sales' : 'purchases';
        $b = row("SELECT " . ($type === 'sale' ? 'invoice_no' : "IF(bill_no = '', CONCAT('#', id), bill_no)") . " no, "
                . ($type === 'sale' ? 'sale_date' : 'purchase_date') . " date, total, paid FROM $t WHERE id = ?", [$id]);
        return $b ?: ['no' => '#' . $id . ' (deleted)', 'date' => null, 'total' => null, 'paid' => null];
    };
    if (in_array($p['ref_type'], ['sale', 'purchase'], true) && $p['ref_id'])
        $rows[] = ['kind' => 'direct', 'id' => 0, 'ref_type' => $p['ref_type'], 'ref_id' => (int)$p['ref_id'], 'amount' => (float)$p['amount']] + $bill($p['ref_type'], $p['ref_id']);
    foreach (all('SELECT * FROM payment_allocations WHERE payment_id = ? ORDER BY id', [(int)$pid]) as $a)
        $rows[] = ['kind' => 'alloc', 'id' => (int)$a['id'], 'ref_type' => $a['ref_type'], 'ref_id' => (int)$a['ref_id'], 'amount' => (float)$a['amount']] + $bill($a['ref_type'], $a['ref_id']);
    return $rows;
}

/** Bills of this payment's party that it could still go on (the right side:
 *  a receipt pays sale bills, a payment-out pays purchase bills), each with
 *  what is left on it, plus the opening balance when some of it is open. */
function paylink_open_bills(array $pay) {
    if (!$pay['party_id']) return [];
    $dir = $pay['direction'];
    $out = [];
    $op = opening_due((int)$pay['party_id'], $dir);
    if ($op > 0.009) $out[] = ['ref' => 'op', 'no' => 'Opening balance', 'date' => null, 'total' => $op, 'paid' => 0, 'left' => $op];
    foreach (money_due_bills((int)$pay['party_id'], $dir) as $b)
        $out[] = ['ref' => (int)$b['id'], 'no' => $dir === 'in' ? $b['invoice_no'] : ($b['bill_no'] ?: '#' . $b['id']),
                  'date' => $dir === 'in' ? $b['sale_date'] : $b['purchase_date'], 'total' => (float)$b['total'], 'paid' => (float)$b['paid'],
                  'left' => money_r($b['total'] - $b['paid'])];
    return $out;
}

/** Refuses with a reason the owner can read. */
class PaylinkRefused extends Exception {}

/** Put $amount of payment $pid on one bill ($billRef = sale/purchase id, or
 *  'op' for the opening balance). Checked here, every time, whatever screen
 *  asked: same party, right side, bill not cancelled, not more than the
 *  payment has free, not more than the bill still owes, period not locked.
 *  A second link to the same bill adds to the first instead of a new row.
 *  Returns the amount put on. Runs inside the caller's transaction. */
function paylink_link($pid, $billRef, $amount) {
    $p = row('SELECT p.*, ' . paylink_linked_sql('p') . ' linked FROM payments p WHERE p.id = ? FOR UPDATE', [(int)$pid]);
    if (!$p) throw new PaylinkRefused('Payment not found.');
    if (!$p['party_id']) throw new PaylinkRefused('This payment has no party, so there are no bills to link it to.');
    if ($p['mode'] === 'contra') throw new PaylinkRefused('A contra entry settles itself - it cannot be linked by hand.');
    if (is_period_locked($p['pay_date'])) throw new PaylinkRefused(period_lock_message());
    $amount = money_r($amount);
    if ($amount <= 0.009) throw new PaylinkRefused('Enter an amount to link.');
    $free = money_r($p['amount'] - $p['linked']);
    if ($amount > $free + 0.009) throw new PaylinkRefused('Only ₹' . money(max(0, $free)) . ' of this payment is still free to link.');
    $dir = $p['direction'];
    if ($billRef === 'op') {
        $use = min($amount, opening_due((int)$p['party_id'], $dir));
        if ($use <= 0.009) throw new PaylinkRefused('Nothing is left open on the opening balance.');
        $refType = 'opening'; $refId = (int)$p['party_id'];
    } else {
        $tbl = $dir === 'in' ? 'sales' : 'purchases';
        $b = row("SELECT * FROM $tbl WHERE id = ? FOR UPDATE", [(int)$billRef]);
        if (!$b) throw new PaylinkRefused('Bill not found.');
        if ((int)$b['party_id'] !== (int)$p['party_id']) throw new PaylinkRefused('That bill belongs to another party.');
        if ($b['is_cancelled']) throw new PaylinkRefused('That bill is cancelled.');
        if (is_period_locked($dir === 'in' ? $b['sale_date'] : $b['purchase_date'])) throw new PaylinkRefused(period_lock_message());
        $left = money_r($b['total'] - $b['paid']);
        if ($left <= 0.009) throw new PaylinkRefused('That bill is already fully paid.');
        if ($amount > $left + 0.009) throw new PaylinkRefused('That bill has only ₹' . money($left) . ' left to pay.');
        $use = min($amount, $left);
        money_settle_bill($b['id'], (int)$p['party_id'], $dir, $use);
        $refType = $dir === 'in' ? 'sale' : 'purchase'; $refId = (int)$b['id'];
    }
    $have = row('SELECT id FROM payment_allocations WHERE payment_id = ? AND ref_type = ? AND ref_id = ? LIMIT 1', [(int)$pid, $refType, $refId]);
    if ($have) q('UPDATE payment_allocations SET amount = amount + ? WHERE id = ?', [$use, $have['id']]);
    else q('INSERT INTO payment_allocations (payment_id, ref_type, ref_id, amount) VALUES (?,?,?,?)', [(int)$pid, $refType, $refId, $use]);
    log_activity('payment_link', 'P-' . (int)$pid . " ₹$use → $refType #$refId");
    return $use;
}

/** Take one link off: the bill gets back exactly what this link put on it;
 *  the payment itself is untouched and its money is free again.
 *  $linkId: a payment_allocations id, or 0 for the payment's own bill. */
function paylink_unlink($pid, $linkId) {
    $p = row('SELECT * FROM payments WHERE id = ? FOR UPDATE', [(int)$pid]);
    if (!$p) throw new PaylinkRefused('Payment not found.');
    if (in_array($p['mode'], ['contra'], true)) throw new PaylinkRefused('A contra entry cannot be unlinked - delete it and record a fresh one.');
    if (is_period_locked($p['pay_date'])) throw new PaylinkRefused(period_lock_message());
    if ((int)$linkId === 0) {
        if (!in_array($p['ref_type'], ['sale', 'purchase'], true) || !$p['ref_id']) throw new PaylinkRefused('This payment has no bill of its own.');
        money_reverse_bill_paid($p['ref_type'], (int)$p['ref_id'], (float)$p['amount']);
        q('UPDATE payments SET ref_type = NULL, ref_id = NULL WHERE id = ?', [(int)$pid]);
        log_activity('payment_unlink', 'P-' . (int)$pid . ' from its own ' . $p['ref_type'] . ' #' . $p['ref_id']);
        return (float)$p['amount'];
    }
    $a = row('SELECT * FROM payment_allocations WHERE id = ? AND payment_id = ?', [(int)$linkId, (int)$pid]);
    if (!$a) throw new PaylinkRefused('That link is not on this payment.');
    money_reverse_bill_paid($a['ref_type'], (int)$a['ref_id'], (float)$a['amount']);
    q('DELETE FROM payment_allocations WHERE id = ?', [(int)$linkId]);
    log_activity('payment_unlink', 'P-' . (int)$pid . ' ₹' . $a['amount'] . ' from ' . $a['ref_type'] . ' #' . $a['ref_id']);
    return (float)$a['amount'];
}

/** Keep (or stop keeping) the free part of a payment as an advance. */
function paylink_set_advance($pid, $on) {
    q('UPDATE payments SET on_account = ? WHERE id = ?', [$on ? 1 : 0, (int)$pid]);
    log_activity('payment_advance', 'P-' . (int)$pid . ($on ? ' kept as advance' : ' advance flag removed'));
}

/** A bill's payment picture: total, received, the payments on it, what the
 *  party has free elsewhere, what is still owed, and the colour. */
function paylink_bill($type, $billId) {
    $tbl = $type === 'sale' ? 'sales' : 'purchases';
    $b = row("SELECT * FROM $tbl WHERE id = ?", [(int)$billId]);
    if (!$b) return null;
    $links = all("SELECT p.id pay_id, p.pay_date, p.mode, p.amount pay_amount, x.amount, x.link_id FROM (
                    SELECT payment_id, amount, id link_id FROM payment_allocations WHERE ref_type = ? AND ref_id = ?
                    UNION ALL SELECT id payment_id, amount, 0 link_id FROM payments WHERE ref_type = ? AND ref_id = ?
                  ) x JOIN payments p ON p.id = x.payment_id ORDER BY p.pay_date, p.id", [$type, (int)$billId, $type, (int)$billId]);
    $linked = money_r(array_sum(array_column($links, 'amount')));
    $free = [];
    if ($b['party_id'])
        $free = all('SELECT p.id, p.pay_date, p.mode, p.amount, p.on_account, ' . paylink_linked_sql('p') . ' linked FROM payments p
                     WHERE p.party_id = ? AND p.direction = ? AND p.mode <> \'contra\' HAVING p.amount - linked > 0.009 ORDER BY p.pay_date, p.id',
                    [(int)$b['party_id'], $type === 'sale' ? 'in' : 'out']);
    $out = money_r($b['total'] - $b['paid']);
    return ['bill' => $b, 'links' => $links, 'linked' => $linked, 'free' => $free,
            'free_total' => money_r(array_sum(array_map(fn($f) => $f['amount'] - $f['linked'], $free))),
            'outstanding' => $out, 'state' => $b['is_cancelled'] ? 'cancelled' : ($out <= 0.009 ? 'paid' : ($b['paid'] > 0.009 ? 'partial' : 'unpaid'))];
}
