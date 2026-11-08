<?php
// Picks the right expense category from what the staff typed in Notes.
// 1) the shop's keyword list (expense_category_keywords), matched on whole
//    words so "pen" no longer fires inside "expense" or "open";
// 2) Gemini, asked to choose ONLY from the shop's own category list;
// 3) nothing - the category the staff picked stays.
// Old entries are never changed here: expense_fix.php shows the proposed
// corrections and the owner applies them (with Undo).

/** Keyword list only (no network). Returns a category or null. */
function expense_guess_keywords($text) {
    $text = ' ' . mb_strtolower(trim((string)$text)) . ' ';
    if (trim($text) === '') return null;
    static $rules = null;
    if ($rules === null) {
        $rules = all('SELECT keyword, category FROM expense_category_keywords ORDER BY CHAR_LENGTH(keyword) DESC');
        $ok = array_flip(expense_categories());
        $rules = array_values(array_filter($rules, fn($r) => isset($ok[$r['category']])));
    }
    $word = fn($k) => preg_match('/(?<![\p{L}\p{M}\p{N}])' . preg_quote($k, '/') . '(?![\p{L}\p{M}\p{N}])/u', $text);
    $hit = null;
    foreach ($rules as $r) {
        $k = mb_strtolower(trim($r['keyword']));
        if ($k !== '' && $word($k)) { $hit = $r['category']; break; }
    }
    // the same spending for the house: "nasto home", "pani home", "home light
    // bill", "papa recharge", "me and pooja trip" belong with household money
    $homeOf = ['Food & Meals' => 'Home Groceries', 'Tea & Water' => 'Home Gas & Water', 'Office Rent' => 'Home Rent',
               'Electricity' => 'Home Electricity', 'Internet & Telecom' => 'Home Mobile & DTH',
               'Petrol & Vehicle' => 'Personal Vehicle & Petrol', 'Office Maintenance' => 'Home Repair & Maintenance',
               'Business Travel' => 'Personal Miscellaneous', 'Business Miscellaneous' => 'Personal Miscellaneous'];
    if ($hit && isset($homeOf[$hit]) && !$word('office') && !$word('shop')) {
        foreach (['home', 'ghar', 'ghare', 'mom', 'papa', 'pooja', 'puja', 'family', 'wife', 'ઘર', 'ઘરે'] as $m) {
            if ($word($m)) return $homeOf[$hit];
        }
    }
    return $hit;
}

/** Gemini, for many notes at once: [key => note] in, [key => category] out.
 *  Only names from the shop's list come back; anything else is dropped. */
function expense_guess_ai(array $notes) {
    $notes = array_filter(array_map(fn($n) => trim((string)$n), $notes), 'strlen');
    if (!$notes || (setting('gemini_api_key') === '' && setting('gemini_api_key_paid') === '')) return [];
    $list = '';
    foreach (expense_category_groups() as $grp => $cats) $list .= $grp . ': ' . implode(', ', $cats) . "\n";
    $lines = '';
    foreach ($notes as $k => $n) $lines .= $k . '|' . str_replace(["\n", '|'], ' ', mb_substr($n, 0, 160)) . "\n";
    $prompt = "An Indian computer & CCTV shop records its expenses. The staff note may be English, Gujarati or Hindi.\n"
        . "Pick the ONE best category for every note FROM THIS LIST ONLY (copy the name exactly):\n$list\n"
        . "Shop spending goes to 'Business spending'; the owner's home and family spending goes to 'Household / personal'.\n"
        . "Notes (key|note):\n$lines\n"
        . 'Reply ONLY a JSON object {"key":"Category", ...} with every key present.';
    [$out] = gemini_generate([['text' => $prompt]], 45, true);
    $map = json_decode((string)$out, true);
    if (!is_array($map)) return [];
    $ok = array_flip(expense_categories());
    $res = [];
    foreach ($map as $k => $c) if (isset($notes[$k]) && is_string($c) && isset($ok[$c])) $res[$k] = $c;
    return $res;
}

/** One note, as the staff types: keywords first, then Gemini (cached, and at
 *  most 40 AI calls an hour per login so a busy form cannot run up a bill).
 *  Returns ['category' => ..., 'by' => 'keyword'|'ai'] or null. */
function expense_guess($text) {
    $text = trim((string)$text);
    if (mb_strlen($text) < 3) return null;
    if ($c = expense_guess_keywords($text)) return ['category' => $c, 'by' => 'keyword'];
    $key = mb_strtolower($text);
    $_SESSION['exp_ai_cache'] = $_SESSION['exp_ai_cache'] ?? [];
    if (array_key_exists($key, $_SESSION['exp_ai_cache'])) {
        $c = $_SESSION['exp_ai_cache'][$key];
        return $c ? ['category' => $c, 'by' => 'ai'] : null;
    }
    $win = $_SESSION['exp_ai_rate'] ?? [time(), 0];
    if (time() - $win[0] > 3600) $win = [time(), 0];
    if ($win[1] >= 40) return null;
    $_SESSION['exp_ai_rate'] = [$win[0], $win[1] + 1];
    $c = expense_guess_ai(['n' => $text])['n'] ?? null;
    if (count($_SESSION['exp_ai_cache']) > 200) $_SESSION['exp_ai_cache'] = [];
    $_SESSION['exp_ai_cache'][$key] = $c;
    return $c ? ['category' => $c, 'by' => 'ai'] : null;
}

/** Old expenses whose note points to another category than the one saved.
 *  Only entries with a note, outside locked periods, newest first. */
function expense_fix_candidates($limit = 5000) {
    $rows = all("SELECT e.id, e.exp_date, e.category, e.amount, e.notes, u.name staff
                 FROM expenses e LEFT JOIN users u ON u.id = e.created_by
                 WHERE e.notes <> '' ORDER BY e.exp_date DESC, e.id DESC LIMIT " . (int)$limit);
    $rows = array_values(array_filter($rows, fn($r) => !is_period_locked($r['exp_date'])));
    $guess = [];
    $ask = [];
    foreach ($rows as $r) {
        $g = expense_guess_keywords($r['notes']);
        if ($g) $guess[$r['id']] = [$g, 'keyword']; else $ask[$r['id']] = $r['notes'];
    }
    foreach (array_chunk(array_slice($ask, 0, 300, true), 60, true) as $part) {   // at most 5 AI calls per page
        foreach (expense_guess_ai($part) as $id => $c) $guess[$id] = [$c, 'ai'];
    }
    $out = [];
    foreach ($rows as $r) {
        [$c, $by] = $guess[$r['id']] ?? [null, null];
        if ($c && $c !== $r['category']) $out[] = $r + ['suggest' => $c, 'by' => $by];
    }
    return $out;
}

/** Moves the chosen expenses: [id => new category]. Keeps an Undo snapshot. */
function expense_fix_apply(array $changes) {
    $ok = array_flip(expense_categories());
    $undo = [];
    foreach ($changes as $id => $cat) {
        if (!isset($ok[$cat])) continue;
        $e = row('SELECT id, exp_date, category FROM expenses WHERE id = ?', [(int)$id]);
        if (!$e || $e['category'] === $cat || is_period_locked($e['exp_date'])) continue;
        $undo[$e['id']] = $e['category'];
        q('UPDATE expenses SET category = ? WHERE id = ?', [$cat, $e['id']]);
    }
    if ($undo) set_setting('expense_fix_undo', json_encode(['at' => date('Y-m-d H:i:s'), 'rows' => $undo]));
    return count($undo);
}

function expense_fix_undo() {
    $u = json_decode((string)setting('expense_fix_undo'), true);
    if (!$u) return 0;
    foreach ($u['rows'] as $id => $cat) q('UPDATE expenses SET category = ? WHERE id = ?', [$cat, (int)$id]);
    set_setting('expense_fix_undo', '');
    return count($u['rows']);
}
