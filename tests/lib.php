<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); die('CLI only'); }
// Tiny test harness - no PHPUnit, no composer, same "hand-rolled and it
// runs anywhere" rule as the rest of the app. Run: php tests/run.php
//
// Tests execute against the CONFIGURED database inside a transaction that
// is always rolled back, so running them never leaves data behind. Any
// test that must commit says so explicitly and cleans up after itself.

$GLOBALS['_t'] = ['pass' => 0, 'fail' => 0, 'fails' => [], 'group' => ''];

function t_group($name) {
    $GLOBALS['_t']['group'] = $name;
    echo "\n\033[1m$name\033[0m\n";
}

function t_ok($label, $cond, $detail = '') {
    if ($cond) {
        $GLOBALS['_t']['pass']++;
        echo "  \033[32m✓\033[0m $label\n";
    } else {
        $GLOBALS['_t']['fail']++;
        $GLOBALS['_t']['fails'][] = $GLOBALS['_t']['group'] . ' → ' . $label . ($detail !== '' ? "  [$detail]" : '');
        echo "  \033[31m✗ $label\033[0m" . ($detail !== '' ? "  \033[90m$detail\033[0m" : '') . "\n";
    }
}

/** Float comparison with money tolerance. */
function t_eq($label, $got, $want, $tol = 0.009) {
    $same = is_numeric($got) && is_numeric($want) ? abs((float)$got - (float)$want) <= $tol : $got === $want;
    t_ok($label, $same, $same ? '' : 'got ' . var_export($got, true) . ', want ' . var_export($want, true));
}

function t_summary() {
    $p = $GLOBALS['_t']['pass']; $f = $GLOBALS['_t']['fail'];
    echo "\n" . str_repeat('─', 58) . "\n";
    if ($f === 0) {
        echo "\033[32m✓ ALL PASS\033[0m  $p assertion(s)\n";
    } else {
        echo "\033[31m✗ $f FAILED\033[0m, $p passed\n";
        foreach ($GLOBALS['_t']['fails'] as $x) echo "   • $x\n";
    }
    return $f === 0 ? 0 : 1;
}

// ---------- fixtures ----------
/** Scratch party. Returns its id. */
function t_party($name = null, $opening = 0) {
    $name = $name ?: 'TEST_' . bin2hex(random_bytes(4));
    q("INSERT INTO parties (name, type, mobile, opening_balance) VALUES (?, 'customer', '9000000000', ?)", [$name, $opening]);
    return insert_id();
}

/** Scratch credit sale. Returns its id. */
function t_sale($partyId, $total, $paid = 0, $date = null, $due = null) {
    $date = $date ?: today();
    $co = (int)val('SELECT id FROM companies ORDER BY id LIMIT 1');
    $loc = (int)val('SELECT id FROM locations ORDER BY id LIMIT 1');
    q("INSERT INTO sales (company_id, location_id, party_id, customer_name, invoice_no, sale_date, due_date,
        subtotal, total, paid, payment_mode, status, created_by)
       VALUES (?,?,?, 'Test Party', ?, ?, ?, ?, ?, ?, 'credit', ?, 1)",
      [$co, $loc, $partyId, 'T-' . bin2hex(random_bytes(4)), $date, $due, $total, $total, $paid,
       payment_status($total, $paid)]);
    return insert_id();
}

/** Scratch item with stock at a location. Returns its id. */
function t_item($qty = 0, $locId = null, $price = 100) {
    $locId = $locId ?: (int)val('SELECT id FROM locations ORDER BY id LIMIT 1');
    q("INSERT INTO items (name, selling_price, purchase_price, is_active, item_type) VALUES (?, ?, ?, 1, 'product')",
      ['TESTITEM_' . bin2hex(random_bytes(4)), $price, $price / 2]);
    $id = insert_id();
    if ($qty != 0) q('INSERT INTO stock (item_id, location_id, qty) VALUES (?,?,?)', [$id, $locId, $qty]);
    return $id;
}

/** Direct payment row + optional allocations, mirroring what payments.php writes. */
function t_payment($partyId, $amount, $dir = 'in', $mode = 'cash', array $alloc = []) {
    q("INSERT INTO payments (party_id, direction, amount, mode, pay_date, notes, created_by)
       VALUES (?,?,?,?,?, 'test', 1)", [$partyId, $dir, $amount, $mode, today()]);
    $pid = insert_id();
    foreach ($alloc as $a) {
        q('INSERT INTO payment_allocations (payment_id, ref_type, ref_id, amount) VALUES (?,?,?,?)',
          [$pid, $a[0], $a[1], $a[2]]);
        if ($a[0] === 'sale') {
            $b = row('SELECT total, paid FROM sales WHERE id = ?', [$a[1]]);
            q('UPDATE sales SET paid = paid + ?, status = ? WHERE id = ?',
              [$a[2], payment_status($b['total'], $b['paid'] + $a[2]), $a[1]]);
        }
    }
    return $pid;
}
