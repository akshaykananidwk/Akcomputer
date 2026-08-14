<?php
// ============================================================================
//  CAMPAIGNS — messaging customers, with consent and with a measurement
// ============================================================================
//  Bulk messaging is the one feature in this system that can do real harm to
//  people who did not ask for it, so the rules come before the feature:
//
//   · Marketing consent is SEPARATE from collection consent. Being willing to
//     hear "your bill is due" is not agreement to be advertised to, and asking
//     for the offers to stop does not waive the right to be told about a bill.
//     Two flags, asked for separately, checked separately.
//   · Nobody is messaged at night. Nobody is messaged twice inside the gap.
//     Nobody gets more than the monthly cap, counted across ALL campaigns.
//   · Every message carries a working way out, and STOP really does stop it
//     (wa_webhook.php) - an opt-out line that does nothing is a lie.
//   · Every person left out is stored WITH THE REASON, not quietly dropped, so
//     "why didn't Ramesh get it" always has an answer.
//
//  And the second half, which is what makes this worth building at all:
//
//   · A share of the audience is deliberately NOT messaged. Comparing the two
//     groups afterwards is the only honest way this shop can find out whether
//     a campaign did anything - "we sent 300 messages and made 40 sales" is a
//     number with nothing to compare it against, since some of those 40 would
//     have walked in regardless.
//   · When the groups are too small for the difference to mean anything, the
//     screen says so instead of reporting a percentage that is really noise.
//
//  Nothing here is generated, scored or predicted. Every audience is a plain
//  SQL question about who bought what and when.
// ============================================================================

function cam_rules() {
    // not cached in a static - a screen that saves a cap and checks it in the
    // same request must see the new value (the Phase 6 kill-switch lesson)
    return [
        'gap_days'   => max(0, (int)setting('campaign_gap_days', 21)),      // days between two campaign messages to one person
        'max_month'  => max(0, (int)setting('campaign_max_month', 2)),      // campaign messages per person per 30 days
        'hour_from'  => min(23, max(0, (int)setting('campaign_hour_from', 10))),
        'hour_to'    => min(23, max(0, (int)setting('campaign_hour_to', 20))),
        'batch'      => max(1, (int)setting('campaign_batch', 20)),         // messages per cron tick
        'holdout'    => min(50, max(0, (int)setting('campaign_holdout_pct', 10))),
        'msg_paise'  => max(0, (int)setting('campaign_msg_paise', 0)),      // 0 = the shop has not said what a message costs
        'optout_line' => (string)setting('campaign_optout_line', 'મેસેજ બંધ કરવા "STOP" લખો.'),
        'inactive_factor' => 1.5,   // past 1.5x their own usual gap
        'min_group'  => 30,         // below this a comparison is noise, not a result
        'min_buyers' => 5,
    ];
}

/** Every audience the shop can pick, each a plain question about the books. */
function cam_audiences() {
    return [
        'inactive'  => ['💤 ઘણા વખતથી નથી આવ્યા', 'જે ગ્રાહકો પોતાની જ સામાન્ય ટેવ કરતાં ઘણા મોડા થયા છે', ''],
        'vip'       => ['⭐ સૌથી સારા ગ્રાહકો', 'છેલ્લા વર્ષમાં સૌથી વધુ ખરીદનારા', 'ઓછામાં ઓછી ખરીદી ₹'],
        'once_only' => ['1️⃣ એક જ વાર આવ્યા', 'એક જ બિલ થયું અને પછી ક્યારેય પાછા ન આવ્યા', ''],
        'birthday'  => ['🎂 આ મહિને જન્મદિવસ', 'જેમની જન્મતારીખ આ મહિનામાં છે', ''],
        'category'  => ['🏷️ કોઈ કેટેગરી ખરીદનારા', 'છેલ્લાં બે વર્ષમાં આ કેટેગરીમાંથી ખરીદનારા', 'કેટેગરી'],
        'item'      => ['📦 કોઈ વસ્તુ ખરીદનારા', 'છેલ્લાં બે વર્ષમાં આ વસ્તુ ખરીદનારા', 'વસ્તુ'],
        'all'       => ['👥 બધા ગ્રાહકો', 'મોબાઇલ નંબર હોય એવા બધા ચાલુ ગ્રાહકો', ''],
    ];
}

/** Build the audience. Returns [id, name, mobile] rows - and nothing else,
 *  because that is all a campaign needs to know about a person. */
function cam_build($audience, $params = '') {
    $r = cam_rules();
    $base = "FROM parties p WHERE p.is_active = 1 AND p.type IN ('customer','both') AND p.mobile <> ''";
    switch ($audience) {
        case 'all':
            return all("SELECT p.id, p.name, p.mobile $base ORDER BY p.name");

        case 'birthday':
            return all("SELECT p.id, p.name, p.mobile $base AND p.dob IS NOT NULL AND MONTH(p.dob) = MONTH(CURDATE()) ORDER BY DAY(p.dob)");

        case 'vip':
            $min = max(0, (float)$params);
            return all("SELECT p.id, p.name, p.mobile, SUM(s.total) spend
                        FROM parties p JOIN sales s ON s.party_id = p.id AND s.is_cancelled = 0
                             AND s.sale_date >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)
                        WHERE p.is_active = 1 AND p.type IN ('customer','both') AND p.mobile <> ''
                        GROUP BY p.id HAVING spend >= ? ORDER BY spend DESC", [$min]);

        case 'once_only':
            // exactly one bill, ever, and it was long enough ago that they are
            // plainly not coming back on their own
            return all("SELECT p.id, p.name, p.mobile, MAX(s.sale_date) last_buy
                        FROM parties p JOIN sales s ON s.party_id = p.id AND s.is_cancelled = 0
                        WHERE p.is_active = 1 AND p.type IN ('customer','both') AND p.mobile <> ''
                        GROUP BY p.id
                        HAVING COUNT(*) = 1 AND last_buy <= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
                        ORDER BY last_buy");

        case 'category':
            $cid = (int)$params;
            if ($cid <= 0) return [];
            return all("SELECT DISTINCT p.id, p.name, p.mobile
                        FROM parties p JOIN sales s ON s.party_id = p.id AND s.is_cancelled = 0
                             AND s.sale_date >= DATE_SUB(CURDATE(), INTERVAL 730 DAY)
                        JOIN sale_items si ON si.sale_id = s.id
                        JOIN items i ON i.id = si.item_id AND i.category_id = ?
                        WHERE p.is_active = 1 AND p.type IN ('customer','both') AND p.mobile <> ''
                        ORDER BY p.name", [$cid]);

        case 'item':
            $iid = (int)$params;
            if ($iid <= 0) return [];
            return all("SELECT DISTINCT p.id, p.name, p.mobile
                        FROM parties p JOIN sales s ON s.party_id = p.id AND s.is_cancelled = 0
                             AND s.sale_date >= DATE_SUB(CURDATE(), INTERVAL 730 DAY)
                        JOIN sale_items si ON si.sale_id = s.id AND si.item_id = ?
                        WHERE p.is_active = 1 AND p.type IN ('customer','both') AND p.mobile <> ''
                        ORDER BY p.name", [$iid]);

        case 'inactive':
        default:
            // "late" measured against the customer's OWN habit, not a fixed
            // number of days - the same rule Customer Intelligence uses, so a
            // yearly buyer is not chased every quarter
            $gaps = cust_gap_map();
            if (!$gaps) return [];
            $ids = implode(',', array_map('intval', array_keys($gaps)));
            $rows = all("SELECT p.id, p.name, p.mobile, MAX(s.sale_date) last_buy,
                                DATEDIFF(CURDATE(), MAX(s.sale_date)) quiet
                         FROM parties p JOIN sales s ON s.party_id = p.id AND s.is_cancelled = 0
                         WHERE p.is_active = 1 AND p.type IN ('customer','both') AND p.mobile <> ''
                           AND p.id IN ($ids)
                         GROUP BY p.id");
            $out = [];
            foreach ($rows as $x) {
                $usual = (int)($gaps[(int)$x['id']] ?? 0);
                if ($usual > 0 && (int)$x['quiet'] > $usual * $r['inactive_factor']) {
                    $x['usual_gap'] = $usual;
                    $out[] = $x;
                }
            }
            usort($out, fn($a, $b) => $b['quiet'] <=> $a['quiet']);
            return $out;
    }
}

/** When was each of these people last sent a campaign, and how many have they
 *  had this month? One query for the whole audience, not one per person. */
function cam_recent_map(array $partyIds) {
    if (!$partyIds) return [];
    $in = implode(',', array_map('intval', $partyIds));
    $rows = all("SELECT party_id, MAX(sent_at) last_sent,
                        SUM(sent_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) month_count
                 FROM campaign_targets
                 WHERE party_id IN ($in) AND status = 'sent'
                 GROUP BY party_id");
    $out = [];
    foreach ($rows as $r) $out[(int)$r['party_id']] = ['last' => $r['last_sent'], 'month' => (int)$r['month_count']];
    return $out;
}

/** May this person be sent a marketing message? Returns '' for yes, or the
 *  reason in the owner's own language - which is then STORED against the
 *  person, so "why didn't Ramesh get it" always has an answer. */
function cam_can_message(array $p, array $recent = []) {
    $r = cam_rules();
    if (trim((string)($p['mobile'] ?? '')) === '') return 'મોબાઇલ નંબર નથી';
    if (!empty($p['marketing_opt_out'])) return 'આ ગ્રાહકે જાહેરાતના મેસેજ બંધ કરાવ્યા છે';
    $rec = $recent[(int)$p['id']] ?? null;
    if ($rec) {
        if ($r['gap_days'] > 0 && $rec['last'] && strtotime($rec['last']) > strtotime('-' . $r['gap_days'] . ' days'))
            // date to date, not timestamp to midnight - otherwise a message
            // sent three afternoons ago is reported as "2 days ago"
            return 'હમણાં જ (' . days_between(date('Y-m-d', strtotime($rec['last']))) . ' દિવસ પહેલાં) મેસેજ ગયો છે';
        if ($r['max_month'] > 0 && $rec['month'] >= $r['max_month'])
            return 'આ મહિને પહેલેથી ' . $rec['month'] . ' મેસેજ ગયા છે';
    }
    return '';
}

/** Is it a decent hour to message someone? */
function cam_quiet_ok($ts = null) {
    $r = cam_rules();
    $h = (int)date('G', $ts ?: time());
    return $h >= $r['hour_from'] && $h < $r['hour_to'];
}

function cam_quiet_text() {
    $r = cam_rules();
    return $r['hour_from'] . ':00 થી ' . $r['hour_to'] . ':00';
}

/** Deterministic, unbiased holdout.
 *
 *  Hashed with the CAMPAIGN id so a different tenth is held out every time -
 *  taking party_id % 10 would silently punish the same customers for ever, who
 *  would then never hear from the shop at all. */
function cam_is_holdout($campaignId, $partyId, $pct) {
    if ($pct <= 0) return false;
    return (crc32($campaignId . ':' . $partyId) % 100) < $pct;
}

/** Freeze the audience into rows, decided BEFORE anything is sent: who gets
 *  it, who is held back to measure against, and who is left out and why. */
function cam_prepare($campaignId) {
    $c = row('SELECT * FROM campaigns WHERE id = ?', [$campaignId]);
    if (!$c) return ['error' => 'campaign not found'];
    if (!in_array($c['status'], ['draft', 'ready'], true)) return ['error' => 'આ કેમ્પેન શરૂ થઈ ગયું છે, હવે યાદી બદલી શકાય નહીં.'];

    $people = cam_build($c['audience'], $c['audience_params']);
    $ids = array_map(fn($x) => (int)$x['id'], $people);
    $recent = cam_recent_map($ids);
    // opt-out and past spend in one sweep, not one query per person
    $flags = []; $spend = [];
    if ($ids) {
        $in = implode(',', $ids);
        foreach (all("SELECT id, marketing_opt_out FROM parties WHERE id IN ($in)") as $x)
            $flags[(int)$x['id']] = (int)$x['marketing_opt_out'];
        $days = max(1, (int)$c['measure_days']);
        foreach (all("SELECT party_id, COALESCE(SUM(total),0) amt FROM sales
                      WHERE party_id IN ($in) AND is_cancelled = 0
                        AND sale_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY) GROUP BY party_id", [$days]) as $x)
            $spend[(int)$x['party_id']] = (float)$x['amt'];
    }

    q('DELETE FROM campaign_targets WHERE campaign_id = ?', [$campaignId]);
    $counts = ['queued' => 0, 'holdout' => 0, 'skipped' => 0];
    foreach ($people as $p) {
        $pid = (int)$p['id'];
        $p['marketing_opt_out'] = $flags[$pid] ?? 0;
        $why = cam_can_message($p, $recent);
        $hold = $why === '' && cam_is_holdout($campaignId, $pid, (int)$c['holdout_pct']);
        $status = $why !== '' ? 'skipped' : 'queued';
        q("INSERT INTO campaign_targets (campaign_id, party_id, name, mobile, is_holdout, status, reason, baseline_spend)
           VALUES (?,?,?,?,?,?,?,?)",
          [$campaignId, $pid, mb_substr((string)$p['name'], 0, 120), (string)$p['mobile'],
           $hold ? 1 : 0, $status, mb_substr($why, 0, 120), $spend[$pid] ?? 0]);
        if ($why !== '') $counts['skipped']++;
        elseif ($hold) $counts['holdout']++;
        else $counts['queued']++;
    }
    q("UPDATE campaigns SET status = 'ready' WHERE id = ?", [$campaignId]);
    return ['total' => count($people)] + $counts;
}

/** The message one person will actually receive, opt-out line included. */
function cam_message_for(array $c, array $t) {
    $r = cam_rules();
    $body = str_replace(
        ['{customer}', '{shop}'],
        [trim((string)$t['name']) !== '' ? $t['name'] : 'ગ્રાહકશ્રી', setting('app_name', 'AK Computer')],
        (string)$c['message']
    );
    // The way out is appended by the code, not left to whoever wrote the
    // message - so it can never be forgotten on the campaign that needed it.
    if ($r['optout_line'] !== '' && mb_strpos($body, $r['optout_line']) === false)
        $body .= "\n\n" . $r['optout_line'];
    return $body;
}

/** Send the next few. Called by the cron and by the Send button, and both go
 *  through the same guards - quiet hours, batch size, and one more consent
 *  re-check, because a person may have opted out since the list was frozen. */
function cam_send_batch($campaignId, $limit = null) {
    $r = cam_rules();
    $limit = max(1, min(200, (int)($limit ?: $r['batch'])));
    $c = row('SELECT * FROM campaigns WHERE id = ?', [$campaignId]);
    if (!$c) return ['error' => 'campaign not found'];
    if (!in_array($c['status'], ['ready', 'sending'], true)) return ['error' => 'આ કેમ્પેન મોકલવા તૈયાર નથી.'];
    if (!cam_quiet_ok()) return ['error' => 'અત્યારે મોકલવાનો સમય નથી (' . cam_quiet_text() . ' વચ્ચે જ જાય છે).'];

    if ($c['status'] === 'ready') q("UPDATE campaigns SET status = 'sending', started_at = COALESCE(started_at, NOW()) WHERE id = ?", [$campaignId]);

    $rows = all("SELECT * FROM campaign_targets
                 WHERE campaign_id = ? AND status = 'queued' AND is_holdout = 0
                 ORDER BY id LIMIT $limit", [$campaignId]);
    $sent = 0; $failed = 0; $skipped = 0;
    foreach ($rows as $t) {
        // a person who opted out AFTER the list was frozen must not be messaged
        $p = row('SELECT id, name, mobile, marketing_opt_out FROM parties WHERE id = ?', [(int)$t['party_id']]);
        $why = $p ? cam_can_message($p, cam_recent_map([(int)$t['party_id']])) : 'ગ્રાહક મળ્યો નહીં';
        if ($why !== '') {
            q("UPDATE campaign_targets SET status = 'skipped', reason = ? WHERE id = ?", [mb_substr($why, 0, 120), $t['id']]);
            $skipped++;
            continue;
        }
        $ok = send_whatsapp($t['mobile'], cam_message_for($c, $t));
        if ($ok) {
            q("UPDATE campaign_targets SET status = 'sent', sent_at = NOW(), reason = '' WHERE id = ?", [$t['id']]);
            $sent++;
        } else {
            q("UPDATE campaign_targets SET status = 'failed', reason = ? WHERE id = ?",
              [mb_substr(whatsapp_last_error(), 0, 120), $t['id']]);
            $failed++;
        }
    }
    $left = (int)val("SELECT COUNT(*) FROM campaign_targets WHERE campaign_id = ? AND status = 'queued' AND is_holdout = 0", [$campaignId]);
    if ($left === 0) q("UPDATE campaigns SET status = 'done', finished_at = NOW() WHERE id = ?", [$campaignId]);
    return ['sent' => $sent, 'failed' => $failed, 'skipped' => $skipped, 'left' => $left];
}

/** Did it work?
 *
 *  The messaged group against the held-back group, over the same days, from
 *  the same books. If either group is too small the answer is "we cannot tell
 *  from this" - which is a real answer, and better than a percentage made of
 *  three customers. */
function cam_result($campaignId) {
    $c = row('SELECT * FROM campaigns WHERE id = ?', [$campaignId]);
    if (!$c || !$c['started_at']) return ['ok' => false, 'why' => 'આ કેમ્પેન હજી મોકલાયું નથી.'];
    $r = cam_rules();
    $from = date('Y-m-d', strtotime($c['started_at']));
    $days = max(1, (int)$c['measure_days']);
    $to = date('Y-m-d', strtotime($c['started_at'] . ' +' . $days . ' days'));
    $ended = strtotime($to) <= strtotime(today());

    // one query for both groups; the group is a column, not a second query
    $rows = all("SELECT t.is_holdout, COUNT(*) people,
                        COUNT(DISTINCT CASE WHEN s.id IS NOT NULL THEN t.party_id END) buyers,
                        COALESCE(SUM(s.total),0) amt
                 FROM campaign_targets t
                 LEFT JOIN sales s ON s.party_id = t.party_id AND s.is_cancelled = 0
                      AND s.sale_date >= ? AND s.sale_date <= ?
                 WHERE t.campaign_id = ? AND (t.status = 'sent' OR t.is_holdout = 1)
                 GROUP BY t.is_holdout", [$from, $to, $campaignId]);

    $g = ['sent' => ['people' => 0, 'buyers' => 0, 'amt' => 0.0],
          'held' => ['people' => 0, 'buyers' => 0, 'amt' => 0.0]];
    foreach ($rows as $x) {
        $k = (int)$x['is_holdout'] === 1 ? 'held' : 'sent';
        $g[$k] = ['people' => (int)$x['people'], 'buyers' => (int)$x['buyers'], 'amt' => money_r($x['amt'])];
    }
    foreach ($g as $k => $v) {
        $g[$k]['rate'] = $v['people'] > 0 ? round($v['buyers'] / $v['people'] * 100, 1) : 0.0;
        $g[$k]['per_head'] = $v['people'] > 0 ? money_r($v['amt'] / $v['people']) : 0.0;
    }

    // Can this comparison carry any weight at all? Said plainly, because the
    // alternative is an owner acting on the difference between 2 and 3 people.
    $trust = ''; $lift = null;
    if ($g['held']['people'] === 0) {
        $trust = 'આ કેમ્પેનમાં કોઈને રોકી રાખ્યા નહોતા, એટલે સરખામણી માટે કંઈ નથી. આગલી વખતે થોડા ગ્રાહકોને બાજુ પર રાખો.';
    } elseif (min($g['sent']['people'], $g['held']['people']) < $r['min_group']
           || ($g['sent']['buyers'] + $g['held']['buyers']) < $r['min_buyers']) {
        $trust = 'બંને જૂથ નાનાં છે, એટલે આ તફાવત સાચો છે એમ કહી શકાય નહીં — સંજોગ પણ હોઈ શકે.';
    } else {
        $lift = round($g['sent']['rate'] - $g['held']['rate'], 1);
    }

    $cost = $r['msg_paise'] > 0
        ? money_r((int)val("SELECT COUNT(*) FROM campaign_targets WHERE campaign_id = ? AND status = 'sent'", [$campaignId]) * $r['msg_paise'] / 100)
        : null;

    return ['ok' => true, 'from' => $from, 'to' => $to, 'ended' => $ended, 'days' => $days,
            'sent' => $g['sent'], 'held' => $g['held'], 'lift' => $lift, 'trust' => $trust,
            'cost' => $cost, 'why' => ''];
}

/** Counts for the list screen and the dashboard. */
function cam_counts($campaignId) {
    $rows = all("SELECT status, is_holdout, COUNT(*) n FROM campaign_targets WHERE campaign_id = ? GROUP BY status, is_holdout", [$campaignId]);
    $out = ['queued' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'holdout' => 0, 'total' => 0];
    foreach ($rows as $x) {
        $n = (int)$x['n'];
        $out['total'] += $n;
        if ((int)$x['is_holdout'] === 1) { $out['holdout'] += $n; continue; }
        $out[$x['status']] = ($out[$x['status']] ?? 0) + $n;
    }
    return $out;
}

/** Turn the marketing messages off for whoever sent STOP.
 *  Matched on the last 10 digits, the way every other lookup here does it. */
function cam_optout($mobile, $on = true) {
    $digits = preg_replace('/\D+/', '', (string)$mobile);
    if (strlen($digits) < 10) return 0;
    $last10 = substr($digits, -10);
    return q("UPDATE parties SET marketing_opt_out = ?
              WHERE mobile <> '' AND RIGHT(REPLACE(REPLACE(mobile,'+',''),' ',''), 10) = ?",
             [$on ? 1 : 0, $last10])->rowCount();
}

/** Does this incoming message mean "stop"? Kept deliberately narrow: a
 *  customer writing "stop sending" means stop, but "non-stop" does not. */
function cam_is_stop_word($text) {
    $t = mb_strtolower(trim((string)$text));
    if ($t === '') return false;
    foreach (['stop', 'unsubscribe', 'બંધ', 'બંધ કરો', 'ना', 'बंद', 'बंद करो'] as $w) {
        if ($t === $w || mb_strpos($t, $w . ' ') === 0) return true;
    }
    return false;
}
