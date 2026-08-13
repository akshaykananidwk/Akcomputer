<?php
// ============================================================================
//  SMART DASHBOARD — the data behind the owner's home screen
// ============================================================================
//  Every rupee here comes from the rules that already run the shop:
//  party_balance()/money_trim_dues() from includes/money.php for anything
//  owed, profit_cost_sql() for margin, the stock table for quantities. This
//  file does NOT invent a second way to count money - if a number here ever
//  disagreed with the Reports screen, the report would be right.
//
//  It is written to be cheap. The dashboard is the most-opened page in the
//  shop, so each section is ONE grouped query wherever possible; nothing
//  loops a query per row, and the Action Center + alerts + summary are all
//  derived in PHP from data already fetched, costing no extra queries.
// ============================================================================

/** Slow-moving cutoff, shared by the dead-stock card and the alerts. */
function dash_dead_days() { return max(1, (int)setting('dead_stock_days', 90)); }

/** How many items are really in one of dash_stock()'s lists. The lists
 *  themselves are trimmed to the worst 50 so they fit the result cache, so
 *  count() on them would under-report a big shop - the exact size travels
 *  alongside as <list>_count. */
function dash_n(array $stock, $list) {
    return (int)($stock[$list . '_count'] ?? count($stock[$list] ?? []));
}

// ----------------------------------------------------------------- cache ----

/** A tiny result cache for dashboard sections that are expensive to compute
 *  and do not change minute to minute.
 *
 *  Used sparingly and only where profiling said so - the KPIs, collection and
 *  the party ledger are computed live on every load, because an owner acting
 *  on a stale collection figure is worse than a slower page. Only the Data
 *  Health sweep (25 integrity queries, and what it reports changes over days)
 *  and the stock roll-up are cached.
 *
 *  Kept in FILES, not in the settings table. The obvious place was a settings
 *  row, and that is where this started - but setting() loads every setting on
 *  every request, so parking a 25KB blob there quietly taxed screens that have
 *  nothing to do with the dashboard. A file is read only by whoever wants it.
 *  The directory is denied to the web by its own .htaccess.
 */
function dash_cache_dir() {
    $dir = __DIR__ . '/../uploads/cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
        @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
    }
    return $dir;
}

function dash_cache_file($key) { return dash_cache_dir() . '/' . preg_replace('/[^a-z0-9_]/i', '', $key) . '.json'; }

function dash_cache($key, $ttl, callable $fn) {
    $f = dash_cache_file($key);
    if (is_file($f) && (time() - (int)@filemtime($f)) < $ttl) {
        $hit = json_decode((string)@file_get_contents($f), true);
        if (is_array($hit)) { $hit['cached_at'] = (int)@filemtime($f); return $hit; }
    }
    $val = $fn();
    // write via a temp file + rename so a reader never sees a half-written cache
    $tmp = $f . '.' . getmypid() . '.tmp';
    if (@file_put_contents($tmp, json_encode($val)) !== false) @rename($tmp, $f); else @unlink($tmp);
    $val['cached_at'] = time();
    return $val;
}

function dash_cache_forget($key) { @unlink(dash_cache_file($key)); }

// ------------------------------------------------------------------- UI ----

/** One clickable KPI tile with its comparison arrow. Lives here rather than
 *  in index.php because Customer 360 shows the same kind of tile, and two
 *  copies would drift apart. Pass $now/$before to get the arrow, or leave
 *  them null for a plain figure. */
function dash_card($label, $value, $link, $now = null, $before = null, $tone = '', $suffix = '₹') {
    $d = ($now === null) ? null : dash_delta($now, $before);
    $arrow = $d === null ? '' : ($d > 0 ? '↑' : ($d < 0 ? '↓' : '→'));
    $dTone = $d === null ? '' : ($d > 0 ? 'up' : ($d < 0 ? 'down' : ''));
    echo '<a class="kpi ' . $tone . '" href="' . e($link) . '">'
       . '<div class="kpi-label">' . e($label) . '</div>'
       . '<div class="kpi-value">' . ($suffix === '₹' ? '₹' : '') . e($value) . ($suffix !== '₹' ? $suffix : '') . '</div>'
       // no comparison to make -> an empty line, so the tiles still line up
       // without printing a dash the reader has to decode
       . ($d === null ? '<div class="kpi-delta">&nbsp;</div>'
                      : '<div class="kpi-delta ' . $dTone . '">' . $arrow . ' ' . abs($d) . '%</div>')
       . '</a>';
}

// ------------------------------------------------------------ date ranges --

/** The ranges the owner can pick, as [from, to, label]. */
function dash_range($key, $customFrom = '', $customTo = '') {
    $t = today();
    switch ($key) {
        case 'yesterday':  $d = date('Y-m-d', strtotime('-1 day', strtotime($t))); return [$d, $d, 'Yesterday'];
        case 'week':       return [date('Y-m-d', strtotime('monday this week', strtotime($t))), $t, 'This Week'];
        case 'month':      return [date('Y-m-01', strtotime($t)), $t, 'This Month'];
        case 'prev_month': $s = date('Y-m-01', strtotime('-1 month', strtotime($t)));
                           return [$s, date('Y-m-t', strtotime($s)), 'Previous Month'];
        case 'custom':
            $f = preg_match('/^\d{4}-\d{2}-\d{2}$/', $customFrom) ? $customFrom : $t;
            $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $customTo) ? $customTo : $t;
            if ($to < $f) { $tmp = $f; $f = $to; $to = $tmp; }
            return [$f, $to, dmy($f) . ' – ' . dmy($to)];
        default:           return [$t, $t, 'Today'];
    }
}

/** The period this one should be compared against.
 *
 *  A single day is compared with the SAME WEEKDAY a week ago, not with
 *  yesterday: a shop's Sunday looks nothing like its Monday, and comparing
 *  the two produces a scary red arrow that means nothing. Longer ranges are
 *  compared with the immediately preceding stretch of the same length, and a
 *  calendar month with the previous calendar month. */
function dash_compare_range($from, $to, $key = 'today') {
    if ($key === 'month' || $key === 'prev_month') {
        $s = date('Y-m-01', strtotime('-1 month', strtotime($from)));
        // this month is only partly over, so compare like-for-like: the same
        // number of days into the previous month
        $end = $key === 'month'
            ? min(date('Y-m-t', strtotime($s)), date('Y-m-d', strtotime($s . ' +' . (int)days_between_dates($from, $to) . ' days')))
            : date('Y-m-t', strtotime($s));
        return [$s, $end, 'previous month'];
    }
    if ($from === $to) {
        $d = date('Y-m-d', strtotime('-7 days', strtotime($from)));
        return [$d, $d, 'same day last week'];
    }
    $len = (int)days_between_dates($from, $to); // inclusive span - 1
    $pTo = date('Y-m-d', strtotime('-1 day', strtotime($from)));
    return [date('Y-m-d', strtotime("-$len days", strtotime($pTo))), $pTo, 'previous period'];
}

/** Whole days from $a to $b (0 when they are the same day). */
function days_between_dates($a, $b) {
    return (int)round((strtotime($b) - strtotime($a)) / 86400);
}

/** Percentage change, or null when there is no meaningful base to compare to
 *  (showing "+100%" against a zero baseline is noise, not information). */
function dash_delta($now, $before) {
    if ($before === null || abs((float)$before) < 0.009) return null;
    return round((((float)$now - (float)$before) / abs((float)$before)) * 100, 1);
}

// ------------------------------------------------------------------ KPIs ----

/** The headline numbers for one date range.
 *
 *  $ownerId limits everything to one staff member's own records (null = the
 *  whole shop), $locId to one branch - so a user who only ever sees their own
 *  bills gets a dashboard scoped exactly the way every other screen scopes
 *  them. The filter is rebuilt per query with the right table alias instead
 *  of being string-patched, which is what makes the joined profit query safe.
 *
 *  Six queries, whatever the range. */
function dash_kpis($from, $to, $ownerId = null, $locId = 0) {
    $w = function ($alias) use ($ownerId, $locId) {
        $a = $alias ? $alias . '.' : '';
        $sql = ''; $p = [];
        if ($ownerId !== null) { $sql .= " AND {$a}created_by = ?"; $p[] = $ownerId; }
        if ($locId)            { $sql .= " AND {$a}location_id = ?"; $p[] = $locId; }
        return [$sql, $p];
    };
    list($sw, $sp) = $w('');   // plain sales table
    list($jw, $jp) = $w('s');  // sales joined as s

    $sales = row("SELECT COUNT(*) bills, COALESCE(SUM(total),0) total,
                         COALESCE(SUM(discount + loyalty_discount),0) discount,
                         COALESCE(SUM(total - paid),0) credit
                  FROM sales WHERE is_cancelled = 0 AND sale_date BETWEEN ? AND ? $sw",
                 array_merge([$from, $to], $sp));

    // collection = money actually received in the window. contra/discount
    // settle the ledger without cash, so they are excluded here exactly as
    // the cashbook excludes them.
    $collected = (float)val("SELECT COALESCE(SUM(amount),0) FROM payments
                             WHERE direction = 'in' AND mode NOT IN ('contra','discount') AND pay_date BETWEEN ? AND ?", [$from, $to]);
    $paidOut   = (float)val("SELECT COALESCE(SUM(amount),0) FROM payments
                             WHERE direction = 'out' AND mode NOT IN ('contra','discount') AND pay_date BETWEEN ? AND ?", [$from, $to]);
    $expenses  = (float)val("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE exp_date BETWEEN ? AND ?"
                            . ($locId ? ' AND location_id = ?' : ''),
                            array_merge([$from, $to], $locId ? [$locId] : []));

    // gross profit uses the SAME cost formula as the profit report and the P&L
    $gp = row("SELECT COALESCE(SUM(si.total),0) revenue, COALESCE(SUM(" . profit_cost_sql() . "),0) cost
               FROM sale_items si JOIN sales s ON s.id = si.sale_id JOIN items i ON i.id = si.item_id
               WHERE s.is_cancelled = 0 AND s.sale_date BETWEEN ? AND ? $jw",
              array_merge([$from, $to], $jp));

    $units = (float)val("SELECT COALESCE(SUM(si.qty),0) FROM sale_items si JOIN sales s ON s.id = si.sale_id
                         WHERE s.is_cancelled = 0 AND s.sale_date BETWEEN ? AND ? $jw",
                        array_merge([$from, $to], $jp));

    $newCustomers = (int)val('SELECT COUNT(*) FROM parties WHERE DATE(created_at) BETWEEN ? AND ?', [$from, $to]);

    $bills = (int)$sales['bills'];
    $profit = (float)$gp['revenue'] - (float)$gp['cost'];
    return [
        'bills'      => $bills,
        'sales'      => (float)$sales['total'],
        'discount'   => (float)$sales['discount'],
        'credit'     => (float)$sales['credit'],
        'avg_bill'   => $bills ? round((float)$sales['total'] / $bills, 2) : 0.0,
        'units'      => $units,
        'collected'  => $collected,
        'paid_out'   => $paidOut,
        'expenses'   => $expenses,
        'net_cash'   => round($collected - $paidOut - $expenses, 2),
        'revenue'    => (float)$gp['revenue'],
        'cost'       => (float)$gp['cost'],
        'profit'     => round($profit, 2),
        'margin_pct' => (float)$gp['revenue'] > 0.009 ? round($profit / (float)$gp['revenue'] * 100, 1) : 0.0,
        'new_customers' => $newCustomers,
    ];
}

// ----------------------------------------------------- receivables / dues ---

/** Everything owed to the shop and everything the shop owes, from the party
 *  ledger - the same expression the Parties list and Payment-In use.
 *
 *  ONE query for every party, then summed in PHP. Deliberately not a
 *  party_balance() call per party, which is the N+1 this replaces. */
function dash_balances() {
    $expr = party_balance_expr('p');
    $rows = all("SELECT p.id, p.name, p.mobile, p.credit_days, $expr bal
                 FROM parties p WHERE p.is_active = 1 OR ABS($expr) > 0.009");
    $recv = 0.0; $pay = 0.0; $recvCount = 0; $payCount = 0;
    foreach ($rows as $r) {
        $b = (float)$r['bal'];
        if ($b > 0.009) { $recv += $b; $recvCount++; }
        elseif ($b < -0.009) { $pay += -$b; $payCount++; }
    }
    return ['rows' => $rows, 'receivable' => round($recv, 2), 'payable' => round($pay, 2),
            'receivable_parties' => $recvCount, 'payable_parties' => $payCount,
            'walkin_due' => walkin_due()];
}

/** Collection intelligence: which customers owe, how late they are, when they
 *  last paid, and how they behave.
 *
 *  Three queries total - the open bills, the last payment per party, and the
 *  payment-behaviour roll-up - then the SAME oldest-first ledger cap the
 *  Payments screen and every reminder use, applied per party in PHP.
 */
function dash_collection(array $bals = null) {
    $bals = $bals ?: dash_balances();
    $ledger = [];
    foreach ($bals['rows'] as $r) $ledger[(int)$r['id']] = $r;

    $bills = all("SELECT s.id, s.party_id, s.invoice_no, s.sale_date, s.due_date,
                         ROUND(s.total - s.paid, 2) due
                  FROM sales s
                  WHERE s.is_cancelled = 0 AND s.status IN ('due','partial') AND s.party_id IS NOT NULL
                  ORDER BY s.party_id, s.due_date IS NULL, s.due_date, s.id");
    $lastPay = [];
    foreach (all("SELECT party_id, MAX(pay_date) d FROM payments WHERE direction = 'in' GROUP BY party_id") as $r)
        $lastPay[(int)$r['party_id']] = $r['d'];
    $behaviour = [];
    foreach (all("SELECT s.party_id, COUNT(*) bills,
                         COALESCE(SUM(CASE WHEN s.status = 'paid' AND s.due_date IS NOT NULL
                                            AND EXISTS (SELECT 1 FROM payment_allocations pa JOIN payments p2 ON p2.id = pa.payment_id
                                                        WHERE pa.ref_type = 'sale' AND pa.ref_id = s.id AND p2.pay_date > s.due_date)
                                      THEN 1 ELSE 0 END),0) late
                  FROM sales s WHERE s.is_cancelled = 0 AND s.party_id IS NOT NULL GROUP BY s.party_id") as $r)
        $behaviour[(int)$r['party_id']] = ['bills' => (int)$r['bills'], 'late' => (int)$r['late']];

    // group the bills by party, then cap each party at their real ledger
    $byParty = [];
    foreach ($bills as $b) $byParty[(int)$b['party_id']][] = $b;

    $t = today();
    $weekEnd = date('Y-m-d', strtotime('+7 days', strtotime($t)));
    $out = ['total' => 0.0, 'overdue' => 0.0, 'due_today' => 0.0, 'due_week' => 0.0,
            'oldest_days' => 0, 'oldest_party' => '', 'customers' => [], 'overdue_customers' => 0];

    foreach ($byParty as $pid => $list) {
        $cap = isset($ledger[$pid]) ? max(0.0, (float)$ledger[$pid]['bal']) : 0.0;
        $adj = money_trim_dues(array_column($list, 'due'), $cap);
        $owed = 0.0; $od = 0.0; $dToday = 0.0; $dWeek = 0.0; $oldest = null;
        foreach ($list as $i => $b) {
            $d = $adj[$i];
            if ($d <= MONEY_EPS) continue;
            $owed += $d;
            $ref = $b['due_date'] ?: $b['sale_date'];
            if ($ref < $t)        { $od += $d; if ($oldest === null || $ref < $oldest) $oldest = $ref; }
            elseif ($ref === $t)  { $dToday += $d; }
            if ($ref >= $t && $ref <= $weekEnd) $dWeek += $d;
        }
        if ($owed <= MONEY_EPS) continue;
        $name = $ledger[$pid]['name'] ?? ('#' . $pid);
        $out['total'] += $owed; $out['overdue'] += $od;
        $out['due_today'] += $dToday; $out['due_week'] += $dWeek;
        if ($od > MONEY_EPS) $out['overdue_customers']++;
        $days = $oldest ? days_between_dates($oldest, $t) : 0;
        if ($days > $out['oldest_days']) { $out['oldest_days'] = $days; $out['oldest_party'] = $name; }
        $bh = $behaviour[$pid] ?? ['bills' => 0, 'late' => 0];
        $out['customers'][] = [
            'id' => $pid, 'name' => $name, 'mobile' => $ledger[$pid]['mobile'] ?? '',
            'amount' => round($owed, 2), 'overdue' => round($od, 2), 'days' => $days,
            'last_payment' => $lastPay[$pid] ?? null,
            'behaviour' => dash_payment_behaviour($bh['bills'], $bh['late']),
        ];
    }
    usort($out['customers'], fn($a, $b) => $b['overdue'] <=> $a['overdue'] ?: $b['amount'] <=> $a['amount']);
    foreach (['total', 'overdue', 'due_today', 'due_week'] as $k) $out[$k] = round($out[$k], 2);
    return $out;
}

/** A plain-language reputation, from how often this customer paid after the
 *  bill's due date. Deterministic - no scoring model, nothing learned. */
function dash_payment_behaviour($bills, $late) {
    if ($bills < 2) return 'new';
    $r = $late / $bills;
    if ($r <= 0.1) return 'excellent';
    if ($r <= 0.3) return 'good';
    if ($r <= 0.6) return 'slow';
    return 'poor';
}

// ----------------------------------------------------------------- stock ----

/** Stock intelligence, cached briefly.
 *
 *  Profiling put this at 127ms of a 417ms page on a 25,000-bill shop - the
 *  heaviest section, because it walks every product's stock alongside its
 *  whole sales history. Two minutes of staleness costs the owner nothing (a
 *  low-stock count does not change meaningfully between two page loads) while
 *  the money figures beside it stay live. Pass $ttl = 0 for a fresh read. */
function dash_stock($locId = 0, $ttl = 120) {
    if ($ttl <= 0) return dash_stock_compute($locId);
    $s = dash_cache('stock_' . (int)$locId, $ttl, fn() => dash_stock_compute($locId));
    // JSON turns a whole-number float back into an int, so a cached read would
    // differ in TYPE from a fresh one even though the value matches. Put the
    // money fields back to float so the two are indistinguishable.
    foreach (['dead_value', 'stock_value'] as $k) $s[$k] = (float)($s[$k] ?? 0);
    foreach (($s['dead_buckets'] ?? []) as $bk => $bv) $s['dead_buckets'][$bk] = (float)$bv;
    foreach (['out', 'low', 'fast', 'slow', 'deadlist', 'high_value'] as $list) {
        foreach (($s[$list] ?? []) as $i => $it) {
            foreach (['qty', 'value', 'min_stock', 'sold90', 'per_day'] as $f) {
                if (isset($it[$f])) $s[$list][$i][$f] = (float)$it[$f];
            }
        }
    }
    return $s;
}

/** One row per item carrying its stock, its sales velocity and its last
 *  movement dates; the buckets are then sliced from that in PHP. */
function dash_stock_compute($locId = 0) {
    $dead = dash_dead_days();
    $locJoin = $locId ? ' AND st.location_id = ' . (int)$locId : '';
    $rows = all("SELECT i.id, i.name, i.unit, i.min_stock, i.purchase_price, i.selling_price, i.created_at,
                        COALESCE(SUM(st.qty),0) qty
                 FROM items i LEFT JOIN stock st ON st.item_id = i.id $locJoin
                 WHERE i.is_active = 1 AND i.item_type <> 'service'
                 GROUP BY i.id");
    // 90-day velocity + last sale/purchase, one grouped query for all items
    $vel = [];
    foreach (all("SELECT si.item_id, SUM(si.qty) sold90, MAX(s.sale_date) last_sale
                  FROM sale_items si JOIN sales s ON s.id = si.sale_id
                  WHERE s.is_cancelled = 0 AND s.sale_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
                  GROUP BY si.item_id") as $r) $vel[(int)$r['item_id']] = $r;
    $lastSaleAll = [];
    foreach (all("SELECT si.item_id, MAX(s.sale_date) d FROM sale_items si JOIN sales s ON s.id = si.sale_id
                  WHERE s.is_cancelled = 0 GROUP BY si.item_id") as $r) $lastSaleAll[(int)$r['item_id']] = $r['d'];
    $lastPurch = [];
    foreach (all("SELECT pi.item_id, MAX(p.purchase_date) d FROM purchase_items pi JOIN purchases p ON p.id = pi.purchase_id
                  WHERE p.is_cancelled = 0 GROUP BY pi.item_id") as $r) $lastPurch[(int)$r['item_id']] = $r['d'];

    $t = today();
    $out = ['low' => [], 'out' => [], 'fast' => [], 'slow' => [], 'deadlist' => [], 'high_value' => [],
            'dead_value' => 0.0, 'dead_buckets' => ['30-60' => 0.0, '60-90' => 0.0, '90-180' => 0.0, '180+' => 0.0],
            'stock_value' => 0.0, 'items' => count($rows)];

    foreach ($rows as $r) {
        $id = (int)$r['id'];
        $qty = (float)$r['qty'];
        $cost = (float)$r['purchase_price'];
        $value = $qty * $cost;
        $out['stock_value'] += $value;
        $sold90 = (float)($vel[$id]['sold90'] ?? 0);
        $perDay = $sold90 / 90;
        $lastSale = $lastSaleAll[$id] ?? null;
        $idle = $lastSale ? days_between_dates($lastSale, $t) : null;

        $item = [
            'id' => $id, 'name' => $r['name'], 'unit' => $r['unit'], 'qty' => $qty,
            'min_stock' => (float)$r['min_stock'], 'value' => round($value, 2),
            'sold90' => $sold90, 'per_day' => round($perDay, 3),
            'days_left' => $perDay > 0.0001 ? (int)floor($qty / $perDay) : null,
            'last_sale' => $lastSale, 'last_purchase' => $lastPurch[$id] ?? null,
            'idle_days' => $idle,
            // enough to cover 30 days of the current rate, minus what is on hand
            'reorder_qty' => $perDay > 0.0001 ? max(0, (int)ceil($perDay * 30 - $qty)) : 0,
        ];

        if ($qty <= 0.009) { $out['out'][] = $item; }
        elseif ($r['min_stock'] > 0 && $qty < (float)$r['min_stock']) { $out['low'][] = $item; }
        elseif ($item['days_left'] !== null && $item['days_left'] <= 14) { $out['low'][] = $item; }

        // How long has this money been sitting? For something that has sold,
        // that is the time since the last sale. For something that has NEVER
        // sold, ageing it from "forever" would drop a product bought last week
        // straight into the 180+ bucket, so it is aged from when it arrived
        // (last purchase, else when the item was created).
        $sittingSince = $lastSale ?: ($lastPurch[$id] ?? substr((string)($r['created_at'] ?? ''), 0, 10));
        $sittingDays = $sittingSince ? days_between_dates($sittingSince, $t) : 0;
        $item['idle_days'] = $sittingDays;
        $item['never_sold'] = $lastSale === null;

        if ($qty > 0.009 && $sittingDays >= $dead) {
            $out['deadlist'][] = $item;
            $out['dead_value'] += $value;
            // buckets always add up to dead_value - nothing else is counted here
            $b = $sittingDays < 60 ? '30-60' : ($sittingDays < 90 ? '60-90' : ($sittingDays < 180 ? '90-180' : '180+'));
            $out['dead_buckets'][$b] += $value;
        }

        if ($sold90 > 0) $out['fast'][] = $item;
        elseif ($qty > 0.009) $out['slow'][] = $item;
        if ($value > 0) $out['high_value'][] = $item;
    }

    usort($out['fast'], fn($a, $b) => $b['sold90'] <=> $a['sold90']);
    usort($out['slow'], fn($a, $b) => $b['value'] <=> $a['value']);
    usort($out['deadlist'], fn($a, $b) => $b['value'] <=> $a['value']);
    usort($out['high_value'], fn($a, $b) => $b['value'] <=> $a['value']);
    usort($out['low'], fn($a, $b) => ($a['days_left'] ?? 9999) <=> ($b['days_left'] ?? 9999));

    // The true sizes are kept as their own numbers BEFORE the lists are
    // trimmed, so a shop with 300 low items still reports 300 on the card
    // while only the worst 20 of each are carried. Untrimmed this was a 297KB
    // structure, and since the result is cached in the settings table - which
    // setting() loads in full on EVERY page of the app - a fat payload would
    // have slowed down screens that have nothing to do with the dashboard.
    // Nothing renders more than the worst handful, so 20 is generous.
    foreach (['out', 'low', 'fast', 'slow', 'deadlist', 'high_value'] as $k) {
        $out[$k . '_count'] = count($out[$k]);
        $out[$k] = array_slice($out[$k], 0, 20);
    }
    $out['dead_value'] = round($out['dead_value'], 2);
    $out['stock_value'] = round($out['stock_value'], 2);
    foreach ($out['dead_buckets'] as $k => $v) $out['dead_buckets'][$k] = round($v, 2);
    return $out;
}

// -------------------------------------------------------------- customers ---

/** Deterministic customer segmentation - plain rules on real dates and
 *  amounts, nothing predicted. One query, bucketed in PHP.
 *
 *  VIP        top spenders (>= 50,000 in the last year) who still buy
 *  High Value spent >= 20,000 in the last year
 *  Regular    3+ purchases in the last year
 *  New        first purchase within the last 60 days
 *  At Risk    used to buy regularly, nothing in 90-180 days
 *  Inactive   nothing in 180+ days
 *  Overdue    money outstanding past its due date
 */
function dash_segments(array $bals = null, array $collection = null) {
    $bals = $bals ?: dash_balances();
    $collection = $collection ?: dash_collection($bals);
    $overdueBy = [];
    foreach ($collection['customers'] as $c) if ($c['overdue'] > MONEY_EPS) $overdueBy[$c['id']] = $c['overdue'];

    $rows = all("SELECT p.id, p.name, p.created_at,
                        COUNT(s.id) bills,
                        COALESCE(SUM(CASE WHEN s.sale_date >= DATE_SUB(CURDATE(), INTERVAL 365 DAY) THEN s.total ELSE 0 END),0) spend_year,
                        COALESCE(SUM(CASE WHEN s.sale_date >= DATE_SUB(CURDATE(), INTERVAL 365 DAY) THEN 1 ELSE 0 END),0) bills_year,
                        MAX(s.sale_date) last_sale
                 FROM parties p
                 LEFT JOIN sales s ON s.party_id = p.id AND s.is_cancelled = 0
                 WHERE p.type <> 'supplier'
                 GROUP BY p.id");

    $segs = [];
    foreach (['vip', 'high_value', 'regular', 'new', 'at_risk', 'inactive', 'overdue'] as $k)
        $segs[$k] = ['count' => 0, 'sales' => 0.0, 'outstanding' => 0.0, 'last_purchase' => null, 'ids' => []];

    $ledger = [];
    foreach ($bals['rows'] as $r) $ledger[(int)$r['id']] = (float)$r['bal'];
    $t = today();

    foreach ($rows as $r) {
        $id = (int)$r['id'];
        $spend = (float)$r['spend_year'];
        $billsY = (int)$r['bills_year'];
        $last = $r['last_sale'];
        $idle = $last ? days_between_dates($last, $t) : null;
        $due = max(0.0, $ledger[$id] ?? 0.0);
        $mine = [];

        if ($last !== null) {
            if ($spend >= 50000 && $idle !== null && $idle <= 180) $mine[] = 'vip';
            elseif ($spend >= 20000) $mine[] = 'high_value';
            if ($billsY >= 3) $mine[] = 'regular';
            if ($idle !== null && $idle >= 180) $mine[] = 'inactive';
            elseif ($idle !== null && $idle >= 90) $mine[] = 'at_risk';
        }
        if ($r['created_at'] && days_between_dates(substr($r['created_at'], 0, 10), $t) <= 60) $mine[] = 'new';
        if (isset($overdueBy[$id])) $mine[] = 'overdue';

        foreach (array_unique($mine) as $k) {
            $segs[$k]['count']++;
            $segs[$k]['sales'] += $spend;
            $segs[$k]['outstanding'] += $k === 'overdue' ? $overdueBy[$id] : $due;
            if ($last && ($segs[$k]['last_purchase'] === null || $last > $segs[$k]['last_purchase'])) $segs[$k]['last_purchase'] = $last;
            if (count($segs[$k]['ids']) < 50) $segs[$k]['ids'][] = $id;
        }
    }
    foreach ($segs as $k => $v) {
        $segs[$k]['sales'] = round($v['sales'], 2);
        $segs[$k]['outstanding'] = round($v['outstanding'], 2);
    }
    return $segs;
}

// -------------------------------------------------------- sales intelligence -

/** Top products / categories / brands / customers for the range, plus the
 *  trend direction. Four small grouped queries, each already served by the
 *  indexes added in v54. */
function dash_sales_intel($from, $to, $limit = 5) {
    $p = [$from, $to];
    return [
        'products' => all("SELECT i.id, i.name, SUM(si.qty) qty, SUM(si.total) amount
                           FROM sale_items si JOIN sales s ON s.id = si.sale_id JOIN items i ON i.id = si.item_id
                           WHERE s.is_cancelled = 0 AND s.sale_date BETWEEN ? AND ?
                           GROUP BY si.item_id ORDER BY amount DESC LIMIT $limit", $p),
        'categories' => all("SELECT COALESCE(c.name,'(none)') name, SUM(si.total) amount
                             FROM sale_items si JOIN sales s ON s.id = si.sale_id JOIN items i ON i.id = si.item_id
                             LEFT JOIN categories c ON c.id = i.category_id
                             WHERE s.is_cancelled = 0 AND s.sale_date BETWEEN ? AND ?
                             GROUP BY i.category_id ORDER BY amount DESC LIMIT $limit", $p),
        'brands' => all("SELECT COALESCE(NULLIF(i.brand,''),'(none)') name, SUM(si.total) amount
                         FROM sale_items si JOIN sales s ON s.id = si.sale_id JOIN items i ON i.id = si.item_id
                         WHERE s.is_cancelled = 0 AND s.sale_date BETWEEN ? AND ?
                         GROUP BY i.brand ORDER BY amount DESC LIMIT $limit", $p),
        'customers' => all("SELECT p.id, p.name, COUNT(s.id) bills, SUM(s.total) amount
                            FROM sales s JOIN parties p ON p.id = s.party_id
                            WHERE s.is_cancelled = 0 AND s.sale_date BETWEEN ? AND ?
                            GROUP BY s.party_id ORDER BY amount DESC LIMIT $limit", $p),
    ];
}

/** Sales direction over the last 6 full months, using the same
 *  trend_direction() the AI-insights widget already uses. */
function dash_sales_trend() {
    $rows = all("SELECT DATE_FORMAT(sale_date, '%Y-%m') ym, SUM(total) t
                 FROM sales WHERE is_cancelled = 0 AND sale_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
                 GROUP BY ym ORDER BY ym");
    $vals = array_map(fn($r) => (float)$r['t'], $rows);
    return ['months' => $rows, 'direction' => count($vals) >= 2 ? trend_direction($vals) : 'flat'];
}

// -------------------------------------------------- action center + alerts ---

/** "What should I do right now" - built entirely from data already fetched,
 *  so it costs no extra queries. Every entry carries a link that lands on the
 *  screen where the owner can actually act. */
function dash_actions(array $ctx) {
    $a = [];
    $col = $ctx['collection'] ?? null;
    $stock = $ctx['stock'] ?? null;

    if ($col && $col['overdue_customers'] > 0) {
        $a[] = ['icon' => '💰', 'sev' => 'bad', 'group' => 'Collections',
                'text' => $col['overdue_customers'] . ' ગ્રાહકોનું પેમેન્ટ મુદત વીતી ગયું છે — ₹' . money($col['overdue']) . ' ઉઘરાવવાનું બાકી',
                'link' => 'reports.php?r=aging', 'cta' => 'ઉઘરાણી કરો'];
    }
    if ($stock && dash_n($stock, 'out')) {
        $a[] = ['icon' => '🚫', 'sev' => 'bad', 'group' => 'Stock',
                'text' => dash_n($stock, 'out') . ' પ્રોડક્ટનો સ્ટોક ખલાસ થઈ ગયો છે',
                'link' => 'reports.php?r=low', 'cta' => 'જુઓ'];
    }
    if ($stock && dash_n($stock, 'low')) {
        $a[] = ['icon' => '📉', 'sev' => 'warn', 'group' => 'Stock',
                'text' => dash_n($stock, 'low') . ' પ્રોડક્ટ થોડા દિવસમાં ખલાસ થઈ જશે',
                'link' => 'reports.php?r=low', 'cta' => 'ઓર્ડર કરો'];
    }
    if ($stock && $stock['dead_value'] > 0.009) {
        $a[] = ['icon' => '🐌', 'sev' => 'warn', 'group' => 'Dead Stock',
                'text' => '₹' . money($stock['dead_value']) . ' નો માલ ' . dash_dead_days() . '+ દિવસથી વેચાયો નથી',
                'link' => 'reports.php?r=dead_stock', 'cta' => 'જુઓ'];
    }
    if (!empty($ctx['low_margin'])) {
        $a[] = ['icon' => '🏷️', 'sev' => 'warn', 'group' => 'Pricing',
                'text' => count($ctx['low_margin']) . ' પ્રોડક્ટનું માર્જિન ટાર્ગેટ કરતાં નીચે છે',
                'link' => 'reports.php?r=profit', 'cta' => 'ભાવ તપાસો'];
    }
    if ($stock && !empty($ctx['reorder'])) {
        $a[] = ['icon' => '🛒', 'sev' => 'info', 'group' => 'Purchases',
                'text' => count($ctx['reorder']) . ' પ્રોડક્ટ ફરી મંગાવવા જેવી છે',
                'link' => 'reports.php?r=purchase_reco', 'cta' => 'સૂચન જુઓ'];
    }
    if (!empty($ctx['repairs_ready'])) {
        $a[] = ['icon' => '🛠️', 'sev' => 'info', 'group' => 'Repairs',
                'text' => $ctx['repairs_ready'] . ' રિપેર તૈયાર છે પણ ગ્રાહકને જાણ કરી નથી',
                'link' => 'repairs.php', 'cta' => 'જાણ કરો'];
    }
    if (!empty($ctx['new_orders'])) {
        $a[] = ['icon' => '🌐', 'sev' => 'info', 'group' => 'Orders',
                'text' => $ctx['new_orders'] . ' નવા વેબસાઇટ ઓર્ડર આવ્યા છે',
                'link' => 'web_orders.php', 'cta' => 'જુઓ'];
    }
    if (!empty($ctx['health_issues'])) {
        $a[] = ['icon' => '🩺', 'sev' => 'warn', 'group' => 'Data',
                'text' => count($ctx['health_issues']) . ' ડેટા ચેકમાં ધ્યાન આપવા જેવું છે',
                'link' => 'reports.php?r=health', 'cta' => 'તપાસો'];
    }
    return $a;
}

/** Rule-based business alerts. Same discipline as the Action Center: every
 *  one is a plain comparison against real data, never a prediction. */
function dash_alerts(array $ctx) {
    $al = [];
    $k = $ctx['kpis'] ?? null;
    $prev = $ctx['prev'] ?? null;
    if ($k && $prev) {
        $d = dash_delta($k['sales'], $prev['sales']);
        if ($d !== null && $d <= -20)
            $al[] = ['sev' => 'bad', 'text' => 'વેચાણ ' . abs($d) . '% ઓછું છે (' . e($ctx['compare_label'] ?? '') . ' કરતાં)', 'link' => 'reports.php?r=daily'];
        if ($d !== null && $d >= 20)
            $al[] = ['sev' => 'ok', 'text' => 'વેચાણ ' . $d . '% વધ્યું છે (' . e($ctx['compare_label'] ?? '') . ' કરતાં)', 'link' => 'reports.php?r=daily'];
    }
    if ($k && $k['margin_pct'] > 0 && $k['margin_pct'] < (float)setting('target_margin_pct', 10))
        $al[] = ['sev' => 'warn', 'text' => 'આ સમયગાળાનું માર્જિન ' . $k['margin_pct'] . '% — ટાર્ગેટ કરતાં નીચે', 'link' => 'reports.php?r=profit'];
    if (!empty($ctx['collection']) && $ctx['collection']['overdue'] > 0.009)
        $al[] = ['sev' => 'bad', 'text' => '₹' . money($ctx['collection']['overdue']) . ' ની ઉઘરાણી મુદત વીતી ગઈ છે', 'link' => 'reports.php?r=aging'];
    if (!empty($ctx['stock']) && dash_n($ctx['stock'], 'low') + dash_n($ctx['stock'], 'out') > 0)
        $al[] = ['sev' => 'warn', 'text' => (dash_n($ctx['stock'], 'low') + dash_n($ctx['stock'], 'out')) . ' પ્રોડક્ટ રીઓર્ડર લેવલથી નીચે', 'link' => 'reports.php?r=low'];
    if (!empty($ctx['price_up']))
        $al[] = ['sev' => 'warn', 'text' => count($ctx['price_up']) . ' પ્રોડક્ટના ખરીદ ભાવ વધ્યા છે', 'link' => 'reports.php?r=purchase'];
    if (!empty($ctx['health_issues']))
        $al[] = ['sev' => 'warn', 'text' => 'Data Health Check માં ' . count($ctx['health_issues']) . ' warning', 'link' => 'reports.php?r=health'];
    return $al;
}

/** Purchase prices that went UP compared with the previous purchase of the
 *  same item. One query over the last 120 days. */
function dash_price_increases($limit = 10) {
    return all("SELECT i.id, i.name, x.old_price, x.new_price, x.d
                FROM (
                  SELECT pi.item_id,
                         SUBSTRING_INDEX(GROUP_CONCAT(pi.price ORDER BY p.purchase_date DESC, pi.id DESC), ',', 1) new_price,
                         SUBSTRING_INDEX(SUBSTRING_INDEX(GROUP_CONCAT(pi.price ORDER BY p.purchase_date DESC, pi.id DESC), ',', 2), ',', -1) old_price,
                         MAX(p.purchase_date) d, COUNT(*) n
                  FROM purchase_items pi JOIN purchases p ON p.id = pi.purchase_id
                  WHERE p.is_cancelled = 0 AND p.purchase_date >= DATE_SUB(CURDATE(), INTERVAL 120 DAY)
                  GROUP BY pi.item_id HAVING n >= 2
                ) x JOIN items i ON i.id = x.item_id
                WHERE x.new_price > x.old_price * 1.02
                ORDER BY (x.new_price - x.old_price) / x.old_price DESC LIMIT $limit");
}

/** Sold items whose margin fell below the shop's target. */
function dash_low_margin($from, $to, $limit = 10) {
    $target = (float)setting('target_margin_pct', 10);
    // the aggregates are repeated in HAVING/ORDER BY rather than referenced by
    // alias: MySQL rejects an aggregate alias used inside an expression there
    $rev = 'SUM(si.total)';
    $cost = 'SUM(' . profit_cost_sql() . ')';
    return all("SELECT i.id, i.name, $rev revenue, $cost cost
                FROM sale_items si JOIN sales s ON s.id = si.sale_id JOIN items i ON i.id = si.item_id
                WHERE s.is_cancelled = 0 AND s.sale_date BETWEEN ? AND ?
                GROUP BY si.item_id
                HAVING $rev > 0 AND (($rev - $cost) / $rev) * 100 < ?
                ORDER BY (($rev - $cost) / $rev) ASC LIMIT $limit", [$from, $to, $target]);
}

// ---------------------------------------------------------------- summary ---

/** The one-paragraph "how is the shop today" line, written from the numbers
 *  themselves. Deterministic on purpose: no AI is involved in any figure the
 *  owner reads here. */
function dash_summary(array $ctx) {
    $k = $ctx['kpis']; $prev = $ctx['prev'] ?? null;
    $bits = [];
    $d = $prev ? dash_delta($k['sales'], $prev['sales']) : null;
    if ($k['sales'] <= 0.009) {
        $bits[] = 'હજી સુધી કોઈ વેચાણ નોંધાયું નથી.';
    } elseif ($d === null) {
        $bits[] = 'વેચાણ ₹' . money($k['sales']) . ' (' . $k['bills'] . ' બિલ).';
    } else {
        $word = $d >= 10 ? 'સારું છે' : ($d <= -10 ? 'ઓછું છે' : 'લગભગ સરખું છે');
        $bits[] = 'વેચાણ ₹' . money($k['sales']) . ' — ' . $ctx['compare_label'] . ' કરતાં ' .
                  ($d > 0 ? '↑' : ($d < 0 ? '↓' : '')) . abs($d) . '%, એટલે ' . $word . '.';
    }
    if (!empty($ctx['collection'])) {
        $c = $ctx['collection'];
        if ($c['overdue'] > 0.009) $bits[] = 'ઉઘરાણી ₹' . money($c['overdue']) . ' પાછળ છે (' . $c['overdue_customers'] . ' ગ્રાહક).';
        elseif ($c['total'] > 0.009) $bits[] = 'બાકી ₹' . money($c['total']) . ' છે, પણ કોઈની મુદત વીતી નથી.';
    }
    if (!empty($ctx['stock'])) {
        $s = $ctx['stock'];
        $short = count($s['out']) + count($s['low']);
        if ($short) $bits[] = $short . ' પ્રોડક્ટ ખલાસ થવાની નજીક છે.';
        if ($s['dead_value'] > 0.009) $bits[] = '₹' . money($s['dead_value']) . ' નો માલ પડી રહ્યો છે.';
    }
    if ($k['profit'] > 0.009) $bits[] = 'નફો ₹' . money($k['profit']) . ' (' . $k['margin_pct'] . '%).';
    return implode(' ', $bits);
}

/** Data Health status for the dashboard strip. Reuses the existing checks,
 *  cached for 15 minutes - see dash_cache() for why this one and not the
 *  money figures. The Data Health page itself always runs them live. */
function dash_health($ttl = 900) {
    return dash_cache('health', $ttl, 'dash_health_compute');
}

function dash_health_compute() {
    require_once __DIR__ . '/health.php';
    $checks = health_checks();
    $issues = [];
    $critical = 0;
    foreach ($checks as $c) {
        if (!count($c['rows'])) continue;
        $issues[$c['t']] = count($c['rows']);
        if ($c['sev'] !== 'warn') $critical++;
    }
    $total = count($checks);
    return ['total' => $total, 'healthy' => $total - count($issues), 'issues' => $issues,
            'critical' => $critical,
            'state' => $critical ? 'bad' : (count($issues) ? 'warn' : 'ok')];
}
