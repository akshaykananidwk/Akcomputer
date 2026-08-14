<?php
// ============================================================================
//  BILL SCAN — the AI reads the paper, the arithmetic is checked by code
// ============================================================================
//  The old scanner pulled model-number-looking words out of OCR text and
//  guessed a price by taking the last number on the line. It could not tell a
//  quantity from a rate, so every row arrived as "qty 1" and had to be typed
//  in by hand.
//
//  A vision model reads the same bill as a TABLE - description, qty, rate,
//  amount - which is a far better first draft. It is only a draft. A model
//  that misreads 12 as 72 produces a line that still looks perfectly
//  reasonable, and no amount of prompting fixes that. So every number it
//  returns is checked here by ordinary arithmetic against the other numbers
//  on the same bill:
//
//      · amount must equal qty × rate
//      · the lines must add up to the sub-total
//      · sub-total + tax − discount must equal the bill total
//
//  A line that fails is NOT thrown away and NOT quietly corrected - it is
//  shown with the reason, unticked, for a person to fix. A bill whose totals
//  do not add up says so at the top. Nothing here writes a purchase; the
//  review screen hands the ticked lines to the ordinary New Purchase form,
//  the same one used when the AI is switched off.
// ============================================================================

function bs_rules() {
    return [
        'max_lines' => 60,
        'abs'       => 1.0,     // rupees of slack - printed bills round
        'pct'       => 0.005,   // or half a percent, whichever is larger
    ];
}

/** Is this difference small enough to be rounding rather than a mistake? */
function bs_close($a, $b) {
    $r = bs_rules();
    return abs($a - $b) <= max($r['abs'], abs($b) * $r['pct']);
}

function bs_mime($path) {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $map = ['pdf' => 'application/pdf', 'png' => 'image/png', 'webp' => 'image/webp',
            'gif' => 'image/gif', 'bmp' => 'image/bmp', 'tif' => 'image/tiff', 'tiff' => 'image/tiff'];
    return $map[$ext] ?? 'image/jpeg';
}

/** Ask the model to read the bill as a table. Returns [doc|null, reason].
 *  A null doc is the normal "carry on without me" case - the caller falls
 *  back to the OCR path that has always been there. */
function bs_extract($path) {
    list($ok, $why) = ai_can_call('bill_scan');
    if (!$ok) return [null, $why];
    if (!is_file($path) || filesize($path) > 15 * 1024 * 1024) return [null, 'ફાઇલ વાંચી શકાઈ નહીં.'];

    $prompt = "Read this supplier purchase bill and return its line items as data.\n"
        . "Copy the numbers EXACTLY as printed. Do not calculate anything, do not fill in a "
        . "number that is not printed - use 0 for anything missing or unreadable.\n"
        . "For each line: desc (the product text), model (part/model number if printed, else \"\"), "
        . "qty, rate (price per unit), amount (the line total), tax_pct (GST % if printed per line, else 0).\n"
        . 'Reply as JSON only: {"supplier":"","bill_no":"","bill_date":"YYYY-MM-DD",'
        . '"lines":[{"desc":"","model":"","qty":0,"rate":0,"amount":0,"tax_pct":0}],'
        . '"subtotal":0,"tax":0,"discount":0,"total":0}';

    $parts = [
        ['text' => $prompt],
        ['inline_data' => ['mime_type' => bs_mime($path), 'data' => base64_encode(file_get_contents($path))]],
    ];
    list($raw, $err) = ai_ask('bill_scan', $parts, 60, true);
    if ($raw === null) return [null, $err];

    $j = ai_json($raw);
    if (!is_array($j) || empty($j['lines']) || !is_array($j['lines'])) return [null, 'બિલમાંથી લાઇન વાંચી શકાઈ નહીં.'];

    $r = bs_rules();
    $lines = [];
    foreach (array_slice($j['lines'], 0, $r['max_lines']) as $ln) {
        if (!is_array($ln)) continue;
        $desc = trim(mb_substr((string)($ln['desc'] ?? ''), 0, 150));
        $model = trim(mb_substr((string)($ln['model'] ?? ''), 0, 60));
        if ($desc === '' && $model === '') continue;
        $lines[] = [
            'desc' => $desc, 'model' => $model,
            'qty' => max(0.0, (float)($ln['qty'] ?? 0)),
            'rate' => max(0.0, (float)($ln['rate'] ?? 0)),
            'amount' => max(0.0, (float)($ln['amount'] ?? 0)),
            'tax_pct' => max(0.0, min(100.0, (float)($ln['tax_pct'] ?? 0))),
        ];
    }
    if (!$lines) return [null, 'બિલમાંથી લાઇન વાંચી શકાઈ નહીં.'];

    return [[
        'supplier' => trim(mb_substr((string)($j['supplier'] ?? ''), 0, 100)),
        'bill_no'  => trim(mb_substr((string)($j['bill_no'] ?? ''), 0, 40)),
        'bill_date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($j['bill_date'] ?? '')) ? $j['bill_date'] : '',
        'lines' => $lines,
        'subtotal' => max(0.0, (float)($j['subtotal'] ?? 0)),
        'tax' => max(0.0, (float)($j['tax'] ?? 0)),
        'discount' => max(0.0, (float)($j['discount'] ?? 0)),
        'total' => max(0.0, (float)($j['total'] ?? 0)),
    ], null];
}

/** The check that makes the draft usable. Pure arithmetic, no AI, no database.
 *
 *  Every line gets 'problems' (why it cannot be trusted) and 'notes' (what was
 *  worked out from the other two numbers). A line with problems is unticked on
 *  the screen; a line with notes is ticked but says what happened. */
function bs_check(array $doc) {
    $sum = 0.0;
    foreach ($doc['lines'] as &$ln) {
        $problems = []; $notes = [];
        $qty = $ln['qty']; $rate = $ln['rate']; $amt = $ln['amount'];

        // Fill in the ONE missing number from the other two - that is
        // arithmetic, not a guess, and it is said out loud either way.
        if ($amt <= 0 && $qty > 0 && $rate > 0) { $amt = money_r($qty * $rate); $notes[] = 'લાઇન ટોટલ ગણી કાઢ્યો'; }
        elseif ($qty <= 0 && $rate > 0 && $amt > 0) { $qty = money_r($amt / $rate); $notes[] = 'નંગ ગણી કાઢ્યા'; }
        elseif ($rate <= 0 && $qty > 0 && $amt > 0) { $rate = money_r($amt / $qty); $notes[] = 'ભાવ ગણી કાઢ્યો'; }

        if ($qty <= 0) $problems[] = 'નંગ વંચાયા નથી';
        if ($rate <= 0 && $amt <= 0) $problems[] = 'ભાવ વંચાયો નથી';
        if ($qty > 0 && $rate > 0 && $amt > 0 && !bs_close($qty * $rate, $amt))
            $problems[] = 'નંગ × ભાવ = ₹' . money($qty * $rate) . ', પણ બિલમાં ₹' . money($amt);

        $ln['qty'] = $qty; $ln['rate'] = $rate; $ln['amount'] = $amt;
        $ln['problems'] = $problems; $ln['notes'] = $notes;
        $ln['ok'] = !$problems;
        $sum += $amt;
    }
    unset($ln);

    // Bill-level arithmetic: only checked against numbers the bill actually
    // printed. A missing sub-total is not a mismatch, it is a missing number.
    $bill = [];
    $sum = money_r($sum);
    if ($doc['subtotal'] > 0 && !bs_close($sum, $doc['subtotal']))
        $bill[] = 'લાઇનોનો સરવાળો ₹' . money($sum) . ' છે, બિલમાં લખ્યું ₹' . money($doc['subtotal']);
    $base = $doc['subtotal'] > 0 ? $doc['subtotal'] : $sum;
    // When the tax row was not read, the per-line GST% is the honest stand-in -
    // otherwise every ordinary GST bill would be reported as "does not add up".
    $tax = $doc['tax'];
    if ($tax <= 0) {
        foreach ($doc['lines'] as $l) $tax += $l['amount'] * $l['tax_pct'] / 100;
        $tax = money_r($tax);
    }
    $calcTotal = money_r($base + $tax - $doc['discount']);
    if ($doc['total'] > 0 && !bs_close($calcTotal, $doc['total']))
        $bill[] = 'સરવાળો + GST − વટાવ = ₹' . money($calcTotal) . ', પણ બિલનો ટોટલ ₹' . money($doc['total']);
    $doc['tax_used'] = $tax;

    $doc['line_sum'] = $sum;
    $doc['problems'] = $bill;
    $doc['ok_lines'] = count(array_filter($doc['lines'], fn($l) => $l['ok']));
    $doc['bad_lines'] = count($doc['lines']) - $doc['ok_lines'];
    return $doc;
}

/** Turn checked lines into the review screen's rows, matched against the
 *  catalogue by the same lookup the OCR path uses - so a scanned bill still
 *  reuses an existing item and never creates a duplicate. */
function bs_review_rows(array $doc) {
    $out = [];
    foreach ($doc['lines'] as $ln) {
        $tok = $ln['model'] !== '' ? $ln['model'] : $ln['desc'];
        $matches = [];
        // the printed model number first, then the longest model-number-looking
        // word in the description
        foreach (array_filter([$ln['model'], bs_best_token($ln['desc'])]) as $try) {
            $m = ocr_match_items([$try]);
            if (!empty($m[0]['matches'])) { $matches = $m[0]['matches']; break; }
        }
        // ...and failing both, the description's own words. Plenty of bills
        // print "Hikvision Hard Disk 1TB" and no part number at all, and that
        // text names the catalogue item perfectly well.
        if (!$matches) $matches = bs_match_desc($ln['desc']);
        $out[] = [
            'token' => $tok, 'matches' => $matches,
            'bill_price' => $ln['rate'],
            'qty' => $ln['qty'], 'rate' => $ln['rate'], 'amount' => $ln['amount'],
            'tax_pct' => $ln['tax_pct'], 'problems' => $ln['problems'], 'notes' => $ln['notes'], 'ok' => $ln['ok'],
        ];
    }
    return $out;
}

/** Match a bill's product text against the catalogue word by word: every word
 *  must appear in the item's name, brand or model. Returns the same columns as
 *  ocr_match_items(), so the review screen cannot tell the two apart. */
function bs_match_desc($desc) {
    $words = array_values(array_filter(preg_split('/[\s,.\/\-()]+/u', (string)$desc),
        fn($w) => mb_strlen(trim($w)) >= 3));
    $words = array_slice($words, 0, 5);
    if (!$words) return [];
    $conds = []; $params = [];
    foreach ($words as $w) {
        $like = '%' . trim($w) . '%';
        $conds[] = '(name LIKE ? OR brand LIKE ? OR model LIKE ?)';
        array_push($params, $like, $like, $like);
    }
    return all('SELECT id, name, model, barcode, purchase_price, tax_rate FROM items
                WHERE is_active = 1 AND ' . implode(' AND ', $conds) . ' ORDER BY name LIMIT 5', $params);
}

/** The most model-number-like word in a description. */
function bs_best_token($desc) {
    $toks = ocr_candidate_tokens((string)$desc);
    if (!$toks) return '';
    usort($toks, fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));
    return $toks[0];
}
