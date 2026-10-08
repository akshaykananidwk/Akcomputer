<?php
// Loans, assets, budgets, partners and the year's summary. Pure arithmetic
// on what is recorded - the same numbers every time it is asked.

/** Financial year (April-March) that a date falls in: ['2026-04-01', '2027-03-31', '2026-27']. */
function fy_of($date = null) {
    $t = strtotime($date ?: today());
    $y = (int)date('Y', $t) - ((int)date('n', $t) < 4 ? 1 : 0);
    return ["$y-04-01", ($y + 1) . '-03-31', $y . '-' . substr((string)($y + 1), 2)];
}

/** The monthly instalment of a loan (reducing balance). */
function loan_emi($principal, $ratePct, $months) {
    $p = (float)$principal; $n = max(1, (int)$months); $r = (float)$ratePct / 1200;
    if ($r <= 0) return round($p / $n, 2);
    return round($p * $r * pow(1 + $r, $n) / (pow(1 + $r, $n) - 1), 2);
}

/** Every instalment: [n, due_date, emi, interest, principal, balance after]. The last one takes the paise left over. */
function loan_schedule($principal, $ratePct, $months, $startDate) {
    $bal = round((float)$principal, 2); $r = (float)$ratePct / 1200; $emi = loan_emi($principal, $ratePct, $months); $out = [];
    for ($i = 1; $i <= $months; $i++) {
        $int = round($bal * $r, 2);
        $prin = $i === (int)$months ? $bal : round($emi - $int, 2);
        $pay = $i === (int)$months ? round($prin + $int, 2) : $emi;
        $bal = round($bal - $prin, 2);
        $out[] = ['n' => $i, 'due' => date('Y-m-d', strtotime($startDate . ' +' . ($i - 1) . ' month')), 'emi' => $pay, 'interest' => $int, 'principal' => $prin, 'balance' => $bal];
    }
    return $out;
}

/** Depreciation of one asset for one financial year, written-down value (Income-tax way):
 *  half the rate in the year it was bought if it was used for less than 180 days. */
function asset_dep(array $a, $fyStart) {
    $fyEnd = date('Y-m-d', strtotime($fyStart . ' +1 year -1 day'));
    if ($a['bought_on'] > $fyEnd) return ['open' => 0, 'add' => 0, 'dep' => 0, 'close' => 0];
    $wdv = (float)$a['cost']; $rate = (float)$a['rate_pct'] / 100;
    [$s] = fy_of($a['bought_on']);
    while ($s < $fyStart) {                                   // the years before this one
        $half = $s === fy_of($a['bought_on'])[0] && (strtotime(date('Y-m-d', strtotime($s . ' +1 year -1 day'))) - strtotime($a['bought_on'])) / 86400 + 1 < 180;
        $wdv = round($wdv - $wdv * $rate * ($half ? 0.5 : 1), 2);
        $s = date('Y-m-d', strtotime($s . ' +1 year'));
    }
    $boughtNow = $a['bought_on'] >= $fyStart;
    if (!empty($a['disposed_on']) && $a['disposed_on'] < $fyStart) return ['open' => 0, 'add' => 0, 'dep' => 0, 'close' => 0];
    $half = $boughtNow && (strtotime($fyEnd) - strtotime($a['bought_on'])) / 86400 + 1 < 180;
    $dep = round($wdv * $rate * ($half ? 0.5 : 1), 2);
    return ['open' => $boughtNow ? 0 : $wdv, 'add' => $boughtNow ? $wdv : 0, 'dep' => $dep, 'close' => round($wdv - $dep, 2)];
}

/** The year in one place, for the CA and for income tax. Loan principal paid through
 *  "EMI / Loan" expenses is taken back out (it repays a debt, it is not a cost);
 *  household expenses are shown as the owner's drawings, not as business cost. */
function it_summary($from, $to) {
    $rev = coa_sales_revenue($from, $to); $cogs = coa_cogs($from, $to);
    $exp = 0.0; $home = 0.0; $byCat = [];
    foreach (coa_expenses_by_category($from, $to) as $c) {
        if (expense_is_home($c['category'])) { $home += (float)$c['total']; continue; }
        $exp += (float)$c['total']; $byCat[$c['category']] = (float)$c['total'];
    }
    $principal = 0.0;
    try { $principal = (float)val('SELECT COALESCE(SUM(lp.principal_part), 0) FROM loan_payments lp WHERE lp.paid_on BETWEEN ? AND ? AND lp.expense_id IS NOT NULL', [$from, $to]); } catch (Exception $e) {}
    $dep = 0.0;
    try { foreach (all('SELECT * FROM assets') as $a) $dep += asset_dep($a, fy_of($to)[0])['dep']; } catch (Exception $e) {}
    $exp = round($exp - $principal, 2);
    return ['revenue' => round($rev, 2), 'cogs' => round($cogs, 2), 'gross' => round($rev - $cogs, 2), 'expenses' => $exp, 'by_category' => $byCat,
            'loan_principal' => round($principal, 2), 'depreciation' => round($dep, 2), 'net' => round($rev - $cogs - $exp - $dep, 2), 'drawings' => round($home, 2)];
}

/** This month's spending against each budget: [category, budget, spent, pct]. */
function budget_status($month = null) {
    $month = $month ?: date('Y-m');
    $out = [];
    try {
        foreach (all("SELECT b.category, b.monthly, COALESCE(SUM(e.amount), 0) spent FROM expense_budgets b
                      LEFT JOIN expenses e ON e.category = b.category AND DATE_FORMAT(e.exp_date, '%Y-%m') = ?
                      GROUP BY b.category, b.monthly ORDER BY b.category", [$month]) as $r)
            $out[] = ['category' => $r['category'], 'budget' => (float)$r['monthly'], 'spent' => (float)$r['spent'], 'pct' => $r['monthly'] > 0 ? round($r['spent'] * 100 / $r['monthly']) : 0];
    } catch (Exception $e) {}
    return $out;
}

/** Each partner's part of a profit. Shares need not add to 100 - whatever is left is shown. */
function partner_shares($profit) {
    $out = [];
    try { foreach (all('SELECT * FROM partners WHERE is_active = 1 ORDER BY share_pct DESC') as $p) $out[] = $p + ['amount' => round($profit * $p['share_pct'] / 100, 2)]; } catch (Exception $e) {}
    return $out;
}
