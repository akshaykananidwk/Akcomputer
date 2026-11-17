<?php
// Party merge - the same person entered two or three times ("Tanveer",
// "Tanveer Rupen bandar", "BAHARUNI TANVIRAHMED ...") ends up with their
// bills and payments split over several ledgers. Merging moves EVERY record
// of the duplicate onto the party being kept and then deletes the duplicate.
//
// Nothing is re-created or re-dated: each bill, payment, return, repair job,
// cheque ... keeps its own id, number, date, amount and allocations - only
// the party it points at changes. So the kept party's ledger afterwards is
// exactly both ledgers put together, and its balance is the sum of the two.
//
// The tables are found from the database itself (every column named
// party_id / outsource_party_id), not from a hand-kept list, so a module
// added next year is moved too instead of being left pointing at a party
// that no longer exists.

/** Every [table, column] in this shop's database that holds a party id. */
function party_ref_columns() {
    $rows = all("SELECT TABLE_NAME t, COLUMN_NAME c FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME IN ('party_id', 'outsource_party_id')
                 ORDER BY TABLE_NAME, COLUMN_NAME");
    $out = [];
    foreach ($rows as $r) {
        // identifiers come from information_schema, but they still go into SQL
        if (preg_match('/^[A-Za-z0-9_]+$/', $r['t']) && preg_match('/^[A-Za-z0-9_]+$/', $r['c']))
            $out[] = [$r['t'], $r['c']];
    }
    return $out;
}

/** What a merge of $fromId would move: ['table' => rows, ...], only non-zero. */
function party_merge_preview($fromId) {
    $counts = [];
    foreach (party_ref_columns() as [$t, $c]) {
        $n = (int)val("SELECT COUNT(*) FROM `$t` WHERE `$c` = ?", [(int)$fromId]);
        if ($n) $counts[$t . ($c === 'party_id' ? '' : '.' . $c)] = $n;
    }
    return $counts;
}

/**
 * Move everything of party $fromId onto party $toId and delete $fromId.
 * Returns ['moved' => [table => rows], 'total' => rows].
 * Throws on bad input; the whole merge is one transaction - all or nothing.
 */
function party_merge($fromId, $toId) {
    $fromId = (int)$fromId; $toId = (int)$toId;
    if (!$fromId || !$toId || $fromId === $toId) throw new Exception('Pick two different parties.');
    $from = row('SELECT * FROM parties WHERE id = ?', [$fromId]);
    $to   = row('SELECT * FROM parties WHERE id = ?', [$toId]);
    if (!$from || !$to) throw new Exception('Party not found.');

    $pdo = db();
    $own = !$pdo->inTransaction();
    $own ? $pdo->beginTransaction() : $pdo->exec('SAVEPOINT party_merge');
    try {
        // lock both rows so a bill saved this second cannot land on the
        // party that is about to disappear
        q('SELECT id FROM parties WHERE id IN (?, ?) FOR UPDATE', [$fromId, $toId]);

        $moved = []; $total = 0;
        foreach (party_ref_columns() as [$t, $c]) {
            // UPDATE IGNORE: a table with a unique (something, party_id) key -
            // e.g. one row per campaign per party - may already hold a row for
            // the kept party; that duplicate stays behind and is dropped below
            $n = q("UPDATE IGNORE `$t` SET `$c` = ? WHERE `$c` = ?", [$toId, $fromId])->rowCount();
            $left = (int)val("SELECT COUNT(*) FROM `$t` WHERE `$c` = ?", [$fromId]);
            if ($left) q("DELETE FROM `$t` WHERE `$c` = ?", [$fromId]);
            if ($n) { $moved[$t] = ($moved[$t] ?? 0) + $n; $total += $n; }
        }

        // A payment linked to the duplicate's OPENING balance points at the
        // party itself (ref_type 'opening', ref_id = party), not at a bill -
        // it follows the opening balance across, or it would turn "unlinked"
        try {
            $n = q("UPDATE payment_allocations SET ref_id = ? WHERE ref_type = 'opening' AND ref_id = ?", [$toId, $fromId])->rowCount();
            if ($n) { $moved['payment_allocations (opening)'] = $n; $total += $n; }
        } catch (PDOException $e) { /* pre-v53: no allocations table */ }

        // The party's own numbers: the opening balance and loyalty points are
        // money/points the duplicate carried, so they are added, never lost.
        // Contact details fill only the blanks - the kept party's own win.
        $set = ['opening_balance = opening_balance + ?'];
        $args = [(float)$from['opening_balance']];
        if (array_key_exists('loyalty_points', $from)) { $set[] = 'loyalty_points = loyalty_points + ?'; $args[] = (int)$from['loyalty_points']; }
        foreach (['mobile', 'email', 'gstin', 'address', 'city', 'dob', 'anniversary'] as $f) {
            if (!array_key_exists($f, $from)) continue;
            $empty = $to[$f] === null || trim((string)$to[$f]) === '';
            if ($empty && $from[$f] !== null && trim((string)$from[$f]) !== '') { $set[] = "`$f` = ?"; $args[] = $from[$f]; }
        }
        if ((int)$to['credit_days'] === 0 && (int)$from['credit_days'] > 0) { $set[] = 'credit_days = ?'; $args[] = (int)$from['credit_days']; }
        $set[] = 'is_active = 1';
        $args[] = $toId;
        q('UPDATE parties SET ' . implode(', ', $set) . ' WHERE id = ?', $args);

        q('DELETE FROM parties WHERE id = ?', [$fromId]);

        log_activity('party_merge', '"' . $from['name'] . '" (#' . $fromId . ') merged into "' . $to['name'] . '" (#' . $toId . ') - '
            . $total . ' records moved' . ($moved ? ': ' . implode(', ', array_map(fn($k, $v) => "$k $v", array_keys($moved), $moved)) : ''));

        $own ? $pdo->commit() : $pdo->exec('RELEASE SAVEPOINT party_merge');
    } catch (Throwable $e) {
        $own ? $pdo->rollBack() : $pdo->exec('ROLLBACK TO SAVEPOINT party_merge');
        throw $e;
    }
    return ['moved' => $moved, 'total' => $total];
}
