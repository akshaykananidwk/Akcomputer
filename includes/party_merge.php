<?php
// Merge a party that was made twice into the original one. Every bill,
// payment, return, estimate, repair, follow-up ... of the duplicate moves to
// the original with its own date untouched; the duplicate's opening balance
// and loyalty points are added to the original, and the duplicate is closed
// (inactive, never deleted). The exact rows moved are kept in party_merges,
// so Undo puts every one back.

/** Every [table, column] that points at a party, read from the database
 *  itself so a table added later is covered too. */
function party_merge_links() {
    $out = [];
    foreach (all("SELECT table_name t, column_name c FROM information_schema.columns
                  WHERE table_schema = DATABASE() AND table_name <> 'parties' AND table_name <> 'party_merges'
                    AND (column_name LIKE '%party_id' OR column_name IN ('supplier_id', 'customer_id'))
                  ORDER BY table_name, column_name") as $r) {
        $t = $r['t'] ?? $r['TABLE_NAME'] ?? ''; $c = $r['c'] ?? $r['COLUMN_NAME'] ?? '';
        if (preg_match('/^\w+$/', $t) && preg_match('/^\w+$/', $c)) $out[] = [$t, $c];
    }
    return $out;
}

/** How many rows of each kind the duplicate has: ['sales.party_id' => 3, ...] */
function party_merge_counts($partyId) {
    $n = [];
    foreach (party_merge_links() as [$t, $c]) {
        $k = (int)val("SELECT COUNT(*) FROM `$t` WHERE `$c` = ?", [$partyId]);
        if ($k) $n["$t.$c"] = $k;
    }
    return $n;
}

/** Same customer saved more than once: parties whose mobile ends in the same
 *  10 digits (090999 61124 = 90999 61124 = 9099961124). */
function party_merge_duplicates($limit = 100) {
    $groups = [];
    foreach (all("SELECT id, name, mobile, is_active FROM parties WHERE is_active = 1 AND mobile <> '' ORDER BY id") as $p) {
        $d = preg_replace('/\D/', '', (string)$p['mobile']);
        if (strlen($d) < 10) continue;
        $groups[substr($d, -10)][] = $p;
    }
    $groups = array_filter($groups, fn($g) => count($g) > 1);
    return array_slice($groups, 0, $limit, true);
}

/** All-or-nothing: its own transaction, or a savepoint inside the caller's. */
function party_merge_tx() {
    $pdo = db();
    if (!$pdo->inTransaction()) { $pdo->beginTransaction(); return [fn() => $pdo->commit(), fn() => $pdo->rollBack()]; }
    $pdo->exec('SAVEPOINT party_merge');
    return [fn() => $pdo->exec('RELEASE SAVEPOINT party_merge'), fn() => $pdo->exec('ROLLBACK TO SAVEPOINT party_merge')];
}

/** Moves everything of party $from onto $to. Returns the merge id. */
function party_merge_apply($from, $to, $userId) {
    $from = (int)$from; $to = (int)$to;
    if ($from === $to) throw new RuntimeException('Pick two different parties.');
    $pf = row('SELECT * FROM parties WHERE id = ?', [$from]);
    $pt = row('SELECT * FROM parties WHERE id = ?', [$to]);
    if (!$pf || !$pt) throw new RuntimeException('Party not found.');
    if (!(int)$pt['is_active']) throw new RuntimeException('The party you merge into is closed - open it first.');
    [$done, $fail] = party_merge_tx();
    try {
        $moved = [];
        foreach (party_merge_links() as [$t, $c]) {
            $hasId = (bool)val("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = 'id'", [$t]);
            $ids = $hasId ? array_map('intval', array_column(all("SELECT id FROM `$t` WHERE `$c` = ?", [$from]), 'id')) : [];
            // IGNORE: a table that allows one row per party (a web login) keeps the original's own row
            $n = q("UPDATE IGNORE `$t` SET `$c` = ? WHERE `$c` = ?", [$to, $from])->rowCount();
            if ($n && $hasId) {
                $ids = array_values(array_intersect($ids, array_map('intval', array_column(all("SELECT id FROM `$t` WHERE `$c` = ?", [$to]), 'id'))));
                $moved["$t.$c"] = $ids;
            } elseif ($n) $moved["$t.$c"] = $n;
        }
        // the original takes over the duplicate's opening balance and points;
        // details the original never had (mobile, GSTIN, address ...) are filled in
        $fill = [];
        foreach (['mobile', 'email', 'gstin', 'address', 'city'] as $f) if (trim((string)$pt[$f]) === '' && trim((string)$pf[$f]) !== '') $fill[$f] = $pf[$f];
        $set = 'opening_balance = opening_balance + ?, loyalty_points = loyalty_points + ?';
        $par = [(float)$pf['opening_balance'], (int)$pf['loyalty_points']];
        foreach ($fill as $f => $v) { $set .= ", `$f` = ?"; $par[] = $v; }
        q("UPDATE parties SET $set WHERE id = ?", array_merge($par, [$to]));
        q('UPDATE parties SET opening_balance = 0, loyalty_points = 0, is_active = 0 WHERE id = ?', [$from]);
        q('INSERT INTO party_merges (from_party_id, to_party_id, moved, before_from, before_to, created_by) VALUES (?,?,?,?,?,?)',
          [$from, $to, json_encode($moved), json_encode($pf, JSON_UNESCAPED_UNICODE), json_encode(['fill' => $fill] + $pt, JSON_UNESCAPED_UNICODE), (int)$userId]);
        $mid = (int)val('SELECT MAX(id) FROM party_merges');
        $done();
    } catch (Throwable $e) { $fail(); throw $e; }
    log_activity('party_merge', "#$from {$pf['name']} -> #$to {$pt['name']}");
    return $mid;
}

/** Undoes one merge: every moved row goes back, balances and details too. */
function party_merge_undo($mergeId) {
    $m = row('SELECT * FROM party_merges WHERE id = ? AND undone_at IS NULL', [(int)$mergeId]);
    if (!$m) throw new RuntimeException('This merge was already undone.');
    $pf = json_decode($m['before_from'], true); $pt = json_decode($m['before_to'], true);
    [$done, $fail] = party_merge_tx();
    try {
        foreach (json_decode($m['moved'], true) ?: [] as $key => $ids) {
            [$t, $c] = explode('.', $key, 2);
            if (!preg_match('/^\w+$/', $t) || !preg_match('/^\w+$/', $c) || !is_array($ids) || !$ids) continue;
            q("UPDATE `$t` SET `$c` = ? WHERE `$c` = ? AND id IN (" . implode(',', array_map('intval', $ids)) . ')', [$m['from_party_id'], $m['to_party_id']]);
        }
        $set = 'opening_balance = opening_balance - ?, loyalty_points = GREATEST(loyalty_points - ?, 0)';
        $par = [(float)$pf['opening_balance'], (int)$pf['loyalty_points']];
        foreach (($pt['fill'] ?? []) as $f => $v) if (in_array($f, ['mobile', 'email', 'gstin', 'address', 'city'], true)) { $set .= ", `$f` = ?"; $par[] = $pt[$f] ?? ''; }
        q("UPDATE parties SET $set WHERE id = ?", array_merge($par, [$m['to_party_id']]));
        q('UPDATE parties SET opening_balance = ?, loyalty_points = ?, is_active = ? WHERE id = ?',
          [(float)$pf['opening_balance'], (int)$pf['loyalty_points'], (int)$pf['is_active'], $m['from_party_id']]);
        q('UPDATE party_merges SET undone_at = NOW() WHERE id = ?', [$m['id']]);
        $done();
    } catch (Throwable $e) { $fail(); throw $e; }
    log_activity('party_merge_undo', "#{$m['from_party_id']} back from #{$m['to_party_id']}");
}

/** Plain names for the row kinds, for the preview. */
function party_merge_label($key) {
    $map = ['sales.party_id' => 'Sale bills', 'purchases.party_id' => 'Purchase bills', 'payments.party_id' => 'Payments',
            'sales_returns.party_id' => 'Sales returns', 'purchase_returns.party_id' => 'Purchase returns', 'estimates.party_id' => 'Estimates',
            'challans.party_id' => 'Challans', 'repairs.party_id' => 'Repair jobs', 'follow_ups.party_id' => 'Follow-ups',
            'cheques.party_id' => 'Cheques', 'purchase_orders.party_id' => 'Purchase orders', 'amc_contracts.party_id' => 'AMC contracts',
            'warranty_claims.party_id' => 'Warranty claims', 'tickets.party_id' => 'Tickets', 'loyalty_ledger.party_id' => 'Loyalty entries'];
    return $map[$key] ?? ucfirst(str_replace(['_', '.party id'], [' ', ''], $key));
}
