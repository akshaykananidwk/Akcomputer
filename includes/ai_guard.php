<?php
// ============================================================================
//  AI GUARD — the one gate every AI call goes through
// ============================================================================
//  The rules this file exists to enforce, in the owner's words:
//
//    · AI must never get direct database write access.
//    · The flow is: AI suggestion → validation → human approval → transaction.
//    · Financial calculations must be deterministic code.
//    · AI must have no authority over final price, margin or ledger.
//    · Customer data must not be sent to an external provider unnecessarily.
//    · There must be API cost tracking and a monthly budget cap.
//    · An AI failure must degrade gracefully.
//
//  So: nothing in this file, and nothing that calls it, writes a business
//  row. ai_ask() returns TEXT to a screen. A human then presses a button, and
//  the ordinary deterministic code path - the same one used when AI is off -
//  does the writing. That is why every caller must also work with AI disabled,
//  and why ai_ask() returns a reason instead of throwing: the caller is
//  expected to carry on without it.
//
//  What is deliberately NOT here: any helper that turns AI output into a
//  price, a quantity, a total or a ledger entry. AI names things; code counts
//  them.
// ============================================================================

/** Every AI-backed feature, with the switch that turns it off on its own.
 *  Adding a feature here is what makes it appear in Settings. */
function ai_features() {
    return [
        'assistant' => ['🧑‍💼 Sales Assistant', 'ગ્રાહક શું માગે છે એ સમજીને કેટલોગમાંથી વસ્તુ શોધે છે'],
        'bill_scan' => ['🧾 Bill Scan', 'ખરીદીના બિલનો ફોટો વાંચીને લાઇન ભરી આપે છે'],
        'categorize' => ['🏷️ Auto Categories', 'નવી વસ્તુની કેટેગરી સૂચવે છે'],
        'enrich' => ['✨ Item Auto-Fill', 'વસ્તુની વિગત અને ફોટો શોધી આપે છે'],
        'wa_bot' => ['💬 WhatsApp Bot', 'ગ્રાહકના મેસેજનો જવાબ આપે છે'],
    ];
}

/** Budget and switches, all owner-editable. */
function ai_limits() {
    // Deliberately NOT cached in a static. setting() already keeps every
    // setting in memory for the request, so re-reading is free - and a static
    // here meant a screen that saved the budget and then checked it in the
    // same request saw the OLD value, which is exactly how a kill switch ends
    // up not killing anything.
    return [
        'enabled'      => setting('ai_enabled', '1') === '1',
        'calls_cap'    => max(0, (int)setting('ai_monthly_calls', 2000)),   // 0 = no call cap
        'budget_rs'    => max(0, (float)setting('ai_monthly_budget', 0)),   // 0 = no rupee cap
        // per-million-token rates from the shop's OWN provider bill; 0 means
        // "I do not know what I am charged", and then no rupee figure is shown
        'rate_in'      => max(0, (float)setting('ai_rate_in_per_m', 0)),
        'rate_out'     => max(0, (float)setting('ai_rate_out_per_m', 0)),
    ];
}

function ai_feature_on($feature) {
    $l = ai_limits();
    if (!$l['enabled']) return false;
    return setting('ai_feat_' . $feature, '1') === '1';
}

/** This calendar month's AI usage: calls, tokens and cost so far. */
function ai_month_usage() {
    $from = date('Y-m-01 00:00:00');
    $r = row("SELECT COUNT(*) calls, COALESCE(SUM(units_in),0) tin,
                     COALESCE(SUM(units_out),0) tout, COALESCE(SUM(cost_paise),0) paise
              FROM api_usage WHERE service = 'gemini' AND created_at >= ?", [$from]);
    return [
        'calls' => (int)($r['calls'] ?? 0),
        'tokens_in' => (int)($r['tin'] ?? 0),
        'tokens_out' => (int)($r['tout'] ?? 0),
        'cost' => round(((int)($r['paise'] ?? 0)) / 100, 2),
    ];
}

/** What one call cost, in paise, from the shop's own configured rates.
 *  Returns 0 when the rates are unknown - a cost of zero is then honestly
 *  "not priced", which is why the budget cap falls back to counting calls. */
function ai_cost_paise($tokensIn, $tokensOut) {
    $l = ai_limits();
    if ($l['rate_in'] <= 0 && $l['rate_out'] <= 0) return 0;
    $rs = ($tokensIn / 1000000) * $l['rate_in'] + ($tokensOut / 1000000) * $l['rate_out'];
    return (int)round($rs * 100);
}

/** May this feature call the provider right now, and if not, why not?
 *  The reasons are written for the owner, because they are shown on screen. */
function ai_can_call($feature) {
    $l = ai_limits();
    if (!$l['enabled'])          return [false, 'AI બંધ છે (Settings → AI).'];
    if (!ai_feature_on($feature)) return [false, 'આ સુવિધા બંધ છે (Settings → AI).'];
    if (trim((string)setting('gemini_api_key')) === '' && trim((string)setting('gemini_api_key_paid')) === '')
        return [false, 'AI ની ચાવી (API key) સેટ નથી.'];
    $u = ai_month_usage();
    if ($l['calls_cap'] > 0 && $u['calls'] >= $l['calls_cap'])
        return [false, 'આ મહિનાની AI મર્યાદા પૂરી થઈ ગઈ (' . $u['calls'] . '/' . $l['calls_cap'] . ' કૉલ).'];
    if ($l['budget_rs'] > 0 && $u['cost'] >= $l['budget_rs'])
        return [false, 'આ મહિનાનું AI બજેટ પૂરું થયું (₹' . money($u['cost']) . ' / ₹' . money($l['budget_rs']) . ').'];
    return [true, ''];
}

/** THE gate. Every AI call in the application goes through this.
 *
 *  Returns [output|null, reason]. A null output is normal, not exceptional -
 *  the feature is off, the budget is spent, the key is missing or the provider
 *  is down - and every caller is written to carry on without it. That is the
 *  graceful-degradation rule, enforced by making refusal the ordinary case
 *  rather than an error.
 *
 *  $parts is passed straight to gemini_generate(); it is the CALLER's job to
 *  have put nothing private in it (see ai_safe_text()).
 */
function ai_ask($feature, array $parts, $timeout = 30, $forceJson = false) {
    list($ok, $why) = ai_can_call($feature);
    if (!$ok) return [null, $why];

    list($out, $err) = gemini_generate($parts, $timeout, $forceJson);

    // record what it cost, against the feature that spent it
    $u = $GLOBALS['_gemini_last_usage'] ?? [];
    $in = (int)($u['in'] ?? 0);
    $outTok = (int)($u['out'] ?? 0);
    try {
        q("UPDATE api_usage SET feature = ?, cost_paise = ?
           WHERE service = 'gemini' ORDER BY id DESC LIMIT 1",
          [mb_substr($feature, 0, 40), ai_cost_paise($in, $outTok)]);
    } catch (Exception $e) { /* metering must never block work */ }

    if ($out === null) return [null, 'AI અત્યારે જવાબ આપી શક્યું નહીં — સામાન્ય રીતે કામ ચાલુ રહેશે.'];
    return [$out, null];
}

/** Strip anything that identifies a person before text goes to a provider.
 *
 *  The shop's rule is that customer data must not be sent unnecessarily. In
 *  practice the only thing an AI feature here needs is the PRODUCT words, so
 *  this removes phone numbers, emails, GST numbers and long digit strings, and
 *  the callers pass names as "the customer" rather than the real name. */
function ai_safe_text($text, $maxLen = 500) {
    $t = (string)$text;
    $t = preg_replace('/[\w.+-]+@[\w-]+\.[\w.]+/u', ' ', $t);              // emails
    $t = preg_replace('/\b\d{2}[A-Z]{5}\d{4}[A-Z]\d[Z][A-Z\d]\b/iu', ' ', $t); // GSTIN
    $t = preg_replace('/\b\d{6,}\b/u', ' ', $t);                          // phone / long numbers
    $t = preg_replace('/\s+/u', ' ', $t);
    return trim(mb_substr($t, 0, $maxLen));
}

/** Decode a JSON answer defensively. Models wrap JSON in prose or code
 *  fences often enough that a plain json_decode() would fail on perfectly
 *  usable output; a caller that gets null here falls back, it never guesses. */
function ai_json($raw) {
    if (!is_string($raw) || $raw === '') return null;
    $s = trim($raw);
    if (preg_match('/```(?:json)?\s*(.+?)```/s', $s, $m)) $s = trim($m[1]);
    $first = strcspn($s, '[{');
    if ($first > 0 && $first < strlen($s)) $s = substr($s, $first);
    $last = max(strrpos($s, ']'), strrpos($s, '}'));
    if ($last !== false) $s = substr($s, 0, $last + 1);
    $j = json_decode($s, true);
    return is_array($j) ? $j : null;
}
