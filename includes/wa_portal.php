<?php
// WhatsApp Customer Self-Service Portal: the customer's own WhatsApp number
// IS the login. Everything is looked up strictly by the sender's number
// (linked party + bills/repairs/orders carrying that mobile), so nobody can
// ever see anyone else's data. Screens ride the same interactive-list /
// button / numbered-text machinery as the catalog (wa_catalog_deliver), and
// every id starts with "portal:" so new modules are one more route below.

function wa_portal_shop() { return setting('app_name', 'AK Computer'); }

/** WHERE piece matching sales owned by this number (linked party's bills +
 *  any bill typed with this mobile). Fills $params. */
function wa_portal_sales_where($mobile, &$params) {
    $party = wa_bot_party_for($mobile);
    $ten = '%' . substr($mobile, -10);
    if ($party) { $params = [(int)$party['id'], $ten]; return "(s.party_id = ? OR REPLACE(REPLACE(s.customer_mobile, '+', ''), ' ', '') LIKE ?)"; }
    $params = [$ten];
    return "REPLACE(REPLACE(s.customer_mobile, '+', ''), ' ', '') LIKE ?";
}

/** Detect portal keywords typed as the whole message ("bill", "વોરંટી"...). */
function wa_portal_want($t) {
    foreach ([
        'portal:menu' => '/^(my ?account|account|portal|એકાઉન્ટ|મારું એકાઉન્ટ|khata|ખાતું)$/iu',
        'portal:bills' => '/^(bills?|invoice|બિલ|બીલ|મારા બિલ)$/iu',
        'portal:stmt' => '/^(statement|સ્ટેટમેન્ટ|હિસાબ|બાકી|baki|udhar|ઉધાર|ledger|balance|બેલેન્સ)$/iu',
        'portal:pay' => '/^(pay(ment)?|પેમેન્ટ|ચુકવણી|paisa ?bharva|online ?pay(ment)?)$/iu',
        'portal:repairs' => '/^(repairs?|રિપેર|રીપેર|service ?status|સર્વિસ સ્ટેટસ|મારો રિપેર)$/iu',
        'portal:warranty' => '/^(warranty|વોરંટી|guarantee|ગેરંટી)$/iu',
        'portal:orders' => '/^(orders?|ઓર્ડર|મારા ઓર્ડર)$/iu',
        'portal:quotes' => '/^(quotations?|quotes?|કોટેશન|estimate|એસ્ટીમેટ)$/iu',
        'portal:ticket' => '/^(complaint|ફરિયાદ|ticket|ટિકિટ|support|સપોર્ટ|help ?desk)$/iu',
        'portal:location' => '/^(address|સરનામું|location|લોકેશન|દુકાન ક્યાં)$/iu',
        'portal:staff' => '/^(staff|માણસ|call ?me|વાત કરવી|talk|સંપર્ક|contact)$/iu',
    ] as $id => $rx) {
        if (preg_match($rx, trim($t))) return $id;
    }
    return null;
}

/** One bill's summary text with public view/PDF links (share-token based). */
function wa_portal_bill_text($s) {
    if (empty($s['share_token'])) { // pre-token era bill: mint one now
        $s['share_token'] = share_token();
        q('UPDATE sales SET share_token = ? WHERE id = ?', [$s['share_token'], $s['id']]);
    }
    $due = $s['total'] - $s['paid'];
    return "🧾 *બિલ {$s['invoice_no']}*\n📅 " . dmy($s['sale_date'])
        . "\n💰 કુલ: *₹" . money($s['total']) . '*'
        . ($due > 0.009 ? "\n🔴 બાકી: *₹" . money($due) . '*' : "\n✅ પૂરું ચૂકવેલું")
        . "\n\n📄 બિલ જુઓ: " . base_url('sale_view.php?id=' . $s['id'] . '&token=' . $s['share_token'])
        . "\n⬇️ PDF: " . base_url('sale_pdf.php?id=' . $s['id'] . '&token=' . $s['share_token']);
}

/** Direct lookup: an invoice number or a serial number typed in the chat.
 *  Returns a reply text, or null if the text is neither (or not theirs). */
function wa_portal_lookup($mobile, $text) {
    $text = trim($text);
    if (preg_match('/^[A-Za-z]{2,5}-\d{2}-\d{2,6}$/', $text)) {
        $w = wa_portal_sales_where($mobile, $p);
        $s = row("SELECT s.* FROM sales s WHERE s.invoice_no = ? AND s.is_cancelled = 0 AND $w", array_merge([$text], $p));
        return $s ? wa_portal_bill_text($s)
            : "🙏 બિલ *$text* આ નંબર સાથે જોડાયેલું નથી મળ્યું. બિલ પરનો નોંધાયેલો નંબર જ વાપરો, અથવા 'staff' લખો.";
    }
    // serial number? (one short token with a digit) - only THEIR serials match
    if (preg_match('/^[A-Za-z0-9\/_-]{5,30}$/', $text) && preg_match('/\d/', $text) && strpos($text, '-') === false) {
        try {
            $w = wa_portal_sales_where($mobile, $p);
            $ser = row("SELECT ser.*, i.name item_name, i.warranty_months, s.invoice_no, s.sale_date
                        FROM item_serials ser JOIN items i ON i.id = ser.item_id JOIN sales s ON s.id = ser.sale_id
                        WHERE ser.serial_no = ? AND $w", array_merge([$text], $p));
            if ($ser) return wa_portal_serial_text($ser);
        } catch (Exception $e) { /* fall through to product search */ }
    }
    return null;
}

/** Warranty-card text for one sold serial. */
function wa_portal_serial_text($ser) {
    $exp = $ser['warranty_expiry'];
    $on = $exp && $exp >= today();
    return "🛡 *વોરંટી કાર્ડ*\n📦 " . $ser['item_name'] . "\n🔢 Serial: *{$ser['serial_no']}*"
        . "\n🧾 બિલ: {$ser['invoice_no']} (" . dmy($ser['sale_date']) . ')'
        . ($exp ? "\n⏳ વોરંટી: " . dmy($exp) . ' સુધી ' . ($on ? '— ✅ ચાલુ છે' : '— ❌ પૂરી થઈ ગઈ')
                : "\nવોરંટી વિગત નોંધાયેલી નથી — 'staff' લખો.")
        . ($on ? "\n\nક્લેમ કરવો હોય તો 'staff' લખો — અમે તરત મદદ કરીશું." : '');
}

/** Support ticket from the customer's next message. Returns confirm text. */
function wa_portal_ticket_create($mobile, $text) {
    $party = wa_bot_party_for($mobile);
    $loc = (int)val('SELECT id FROM locations ORDER BY id LIMIT 1') ?: 1;
    $admin = (int)val('SELECT id FROM users ORDER BY id LIMIT 1') ?: 1;
    q("INSERT INTO tickets (party_id, customer_name, customer_mobile, subject, description, priority, status, location_id, created_by)
       VALUES (?,?,?,?,?, 'medium', 'open', ?, ?)",
      [$party['id'] ?? null, $party['name'] ?? '', $mobile, mb_substr($text, 0, 120), $text, $loc, $admin]);
    $tid = insert_id();
    q('UPDATE tickets SET ticket_no = ? WHERE id = ?', [doc_no('TKT', $tid), $tid]);
    try {
        tg_notify_admins("🎫 નવી WhatsApp ફરિયાદ " . doc_no('TKT', $tid) . "\nFrom: " . ($party['name'] ?? '') . " +$mobile\n" . mb_substr($text, 0, 300)
            . "\n\n" . base_url('tickets.php?action=view&id=' . $tid));
    } catch (Exception $e) { /* telegram optional */ }
    return "✅ ફરિયાદ નોંધાઈ ગઈ!\n🎫 ટિકિટ નં: *" . doc_no('TKT', $tid) . "*\n\nઅમારા માણસ જલદી સંપર્ક કરશે. સ્ટેટસ પૂછવા આ નંબર પર 'ticket' લખો.";
}

/** Gujarati labels for repair statuses (unknown ones show as-is). */
function wa_portal_repair_status($st) {
    $map = ['received' => '📥 જમા થયું', 'in_progress' => '🔧 રિપેર ચાલુ છે', 'outsourced' => '🏭 બહાર રિપેરમાં',
            'ready' => '✅ તૈયાર છે - લઈ જાવ!', 'delivered' => '📦 ડિલિવર થઈ ગયું', 'returned_unrepaired' => '↩️ રિપેર વગર પરત'];
    return $map[$st] ?? $st;
}

/** The whole portal: route one "portal:*" id. Returns a short status. */
function wa_portal_route($mobile, $id) {
    $shop = wa_portal_shop();
    $party = wa_bot_party_for($mobile);
    $ten = '%' . substr($mobile, -10);

    // ---------- main menu ----------
    if ($id === 'portal:menu') {
        $defs = [
            ['portal:bills', '🧾 મારા બિલ', 'બિલ જુઓ / PDF ડાઉનલોડ'],
            ['portal:stmt', '💰 હિસાબ / બાકી', 'બેલેન્સ + છેલ્લી એન્ટ્રી'],
            ['portal:pay', '💳 પેમેન્ટ કરો', 'ઓનલાઇન ચુકવણી લિંક'],
            ['portal:repairs', '🔧 રિપેર સ્ટેટસ', 'સર્વિસ/રિપેર ટ્રેકિંગ'],
            ['portal:warranty', '🛡 વોરંટી', 'સિરિયલ નં + વોરંટી કાર્ડ'],
            ['portal:orders', '📦 મારા ઓર્ડર', 'વેબસાઇટ ઓર્ડર સ્ટેટસ'],
            ['portal:quotes', '📋 કોટેશન', 'મંજૂર / નામંજૂર કરો'],
            ['portal:ticket', '🎫 ફરિયાદ / સપોર્ટ', 'ટિકિટ ખોલો'],
            ['portal:location', '📍 દુકાનનું સરનામું', 'સંપર્ક વિગત'],
            ['portal:staff', '📞 માણસ સાથે વાત', 'સીધો સંપર્ક'],
        ];
        $rows = []; $map = []; $n = 1;
        $txt = "🧾 *$shop — My Account*\n" . ($party ? 'નમસ્તે *' . $party['name'] . '*!' : 'નમસ્તે!') . "\n";
        foreach ($defs as $d) {
            $rows[] = ['id' => $d[0], 'title' => wa_cat_cut($d[1], 24), 'description' => wa_cat_cut($d[2], 72)];
            $map[(string)$n] = $d[0];
            $txt .= "\n*$n)* {$d[1]}";
            $n++;
        }
        $txt .= "\n\n👉 નંબર લખીને જવાબ આપો";
        return wa_catalog_deliver($mobile, [
            'type' => 'list',
            'header' => ['type' => 'text', 'text' => wa_cat_cut('🧾 ' . $shop, 60)],
            'body' => ['text' => ($party ? 'નમસ્તે *' . $party['name'] . "*!\n" : '') . "તમારું આખું ખાતું અહીં જ — બિલ, હિસાબ, રિપેર, વોરંટી, પેમેન્ટ 👇"],
            'footer' => ['text' => wa_cat_cut($shop, 60)],
            'action' => ['button' => 'મેનુ ખોલો', 'sections' => [['title' => 'My Account', 'rows' => $rows]]],
        ], $txt, $map) === 'send-failed' ? 'menu-failed' : 'menu';
    }

    // ---------- bills ----------
    if ($id === 'portal:bills') {
        $w = wa_portal_sales_where($mobile, $p);
        $bills = all("SELECT s.id, s.invoice_no, s.sale_date, s.total, s.paid FROM sales s
                      WHERE s.is_cancelled = 0 AND $w ORDER BY s.id DESC LIMIT 9", $p);
        if (!$bills) { send_whatsapp($mobile, "🙏 *$shop*\nઆ નંબર પર કોઈ બિલ નથી મળ્યું. બિલમાં આ મોબાઈલ નોંધાયેલો હશે તો અહીં દેખાશે — 'staff' લખો તો અમે જોડી આપીશું."); return 'bills-none'; }
        $rows = []; $map = []; $n = 1;
        $txt = "🧾 *તમારા છેલ્લા બિલ*\n";
        foreach ($bills as $b) {
            $due = $b['total'] - $b['paid'];
            $rows[] = ['id' => 'portal:bill:' . $b['id'], 'title' => wa_cat_cut($b['invoice_no'], 24),
                       'description' => wa_cat_cut(dmy($b['sale_date']) . ' · ₹' . money($b['total']) . ($due > 0.009 ? ' · બાકી ₹' . money($due) : ' · ✅'), 72)];
            $map[(string)$n] = 'portal:bill:' . $b['id'];
            $txt .= "\n*$n)* {$b['invoice_no']} — " . dmy($b['sale_date']) . " — ₹" . money($b['total']) . ($due > 0.009 ? ' (બાકી ₹' . money($due) . ')' : ' ✅');
            $n++;
        }
        $txt .= "\n\n👉 નંબર લખો — બિલની વિગત + PDF લિંક મળશે";
        return wa_catalog_deliver($mobile, [
            'type' => 'list',
            'header' => ['type' => 'text', 'text' => '🧾 મારા બિલ'],
            'body' => ['text' => "બિલ પસંદ કરો — વિગત અને PDF ડાઉનલોડ લિંક તરત મળશે."],
            'action' => ['button' => 'બિલ જુઓ', 'sections' => [['title' => 'બિલ', 'rows' => $rows]]],
        ], $txt, $map) === 'send-failed' ? 'bills-failed' : 'bills';
    }
    if (preg_match('/^portal:bill:(\d+)$/', $id, $m)) {
        $w = wa_portal_sales_where($mobile, $p);
        $s = row("SELECT s.* FROM sales s WHERE s.id = ? AND s.is_cancelled = 0 AND $w", array_merge([(int)$m[1]], $p));
        if (!$s) { send_whatsapp($mobile, '🙏 આ બિલ આ નંબર સાથે જોડાયેલું નથી.'); return 'bill-denied'; }
        $due = $s['total'] - $s['paid'];
        $body = wa_portal_bill_text($s);
        if ($due > 0.009) {
            require_once __DIR__ . '/wa_meta.php';
            [$ok, ] = meta_wa_configured() ? meta_wa_send_interactive($mobile, [
                'type' => 'button', 'body' => ['text' => $body],
                'action' => ['buttons' => [['type' => 'reply', 'reply' => ['id' => 'portal:paybill:' . $s['id'], 'title' => '💳 આ બિલ ભરો']]]],
            ]) : [false, ''];
            if ($ok) { wa_chat_log($mobile, 'out', $body . "\n[💳 આ બિલ ભરો]", 'meta'); return 'bill'; }
            $body .= "\n\n💳 ઓનલાઇન ભરવા 'pay' લખો";
        }
        send_whatsapp($mobile, $body);
        return 'bill';
    }

    // ---------- statement / balance ----------
    if ($id === 'portal:stmt') {
        if (!$party) { send_whatsapp($mobile, "🙏 *$shop*\nઆ નંબર પર કોઈ ખાતું નથી મળ્યું. દુકાને આ નંબર નોંધાવેલો હશે તો હિસાબ અહીં જ મળશે — 'staff' લખો."); return 'stmt-none'; }
        $bx = party_balance_expr('p');
        $bal = (float)val("SELECT $bx FROM parties p WHERE p.id = ?", [$party['id']]);
        $out = "💰 *$shop — હિસાબ*\nનમસ્તે *{$party['name']}*!\n";
        if ($bal > 0.009) $out .= "તમારા બાકી: *₹" . money($bal) . "* (આપવાના)\n";
        elseif ($bal < -0.009) $out .= "તમારી જમા: *₹" . money(-$bal) . "*\n";
        else $out .= "હિસાબ ચોખ્ખો છે ✅\n";
        $lines = all("(SELECT sale_date d, CONCAT('બિલ ', invoice_no) label, total amt FROM sales WHERE party_id = ? AND is_cancelled = 0)
                      UNION ALL
                      (SELECT pay_date d, IF(direction='in','ચુકવણી મળી','ચુકવણી કરી') label, amount amt FROM payments WHERE party_id = ?)
                      ORDER BY d DESC LIMIT 10", [$party['id'], $party['id']]);
        if ($lines) { $out .= "\n*છેલ્લી એન્ટ્રી:*"; foreach ($lines as $l) $out .= "\n• " . dmy($l['d']) . ' — ' . $l['label'] . ' — ₹' . money($l['amt']); }
        $out .= "\n\n🧾 બિલની PDF માટે 'bill' લખો" . ($bal > 0.009 ? " · 💳 ભરવા 'pay' લખો" : '');
        send_whatsapp($mobile, $out);
        return 'stmt';
    }

    // ---------- payment ----------
    if ($id === 'portal:pay' || preg_match('/^portal:paybill:(\d+)$/', $id, $m)) {
        if (!empty($m[1])) {
            $w = wa_portal_sales_where($mobile, $p);
            $s = row("SELECT s.* FROM sales s WHERE s.id = ? AND s.is_cancelled = 0 AND $w", array_merge([(int)$m[1]], $p));
            if (!$s) { send_whatsapp($mobile, '🙏 આ બિલ આ નંબર સાથે જોડાયેલું નથી.'); return 'pay-denied'; }
            $due = $s['total'] - $s['paid'];
            $ref = $s['invoice_no']; $desc = 'Invoice ' . $s['invoice_no']; $saleId = (int)$s['id'];
            $name = $s['customer_name'];
        } else {
            $due = $party ? max(0, (float)val('SELECT ' . party_balance_expr('p') . ' FROM parties p WHERE p.id = ?', [$party['id']])) : 0;
            $ref = 'ACC-' . substr($mobile, -10); $desc = 'Account payment - ' . ($party['name'] ?? ''); $saleId = null;
            $name = $party['name'] ?? '';
        }
        if ($due <= 0.009) { send_whatsapp($mobile, "✅ *$shop*\nકંઈ બાકી નથી — હિસાબ ચોખ્ખો છે. આભાર! 🙏"); return 'pay-clear'; }
        $link = razorpay_payment_link($due, $desc, $name, $mobile, $ref . '-' . time(), $saleId);
        if ($link) {
            send_whatsapp($mobile, "💳 *$shop — ઓનલાઇન પેમેન્ટ*\nરકમ: *₹" . money($due) . "*\n\nઆ સેફ લિંકથી UPI/કાર્ડથી ભરો:\n$link\n\nપેમેન્ટ થતાં જ અમારા ચોપડે આપોઆપ જમા થઈ જશે ✅");
            return 'pay-link';
        }
        try { tg_notify_admins("💳 પેમેન્ટ કરવા માંગે છે: +$mobile (₹" . money($due) . ") — ઓનલાઇન લિંક બની નહીં, સંપર્ક કરો."); } catch (Exception $e) {}
        send_whatsapp($mobile, "💳 *$shop*\nબાકી રકમ: *₹" . money($due) . "*\nઅમારા માણસ ચુકવણી માટે તરત સંપર્ક કરશે. 🙏");
        return 'pay-manual';
    }

    // ---------- repairs ----------
    if ($id === 'portal:repairs') {
        $rp = $party ? [(int)$party['id'], $ten] : [$ten];
        $rw = $party ? "(r.party_id = ? OR REPLACE(REPLACE(r.customer_mobile, '+', ''), ' ', '') LIKE ?)" : "REPLACE(REPLACE(r.customer_mobile, '+', ''), ' ', '') LIKE ?";
        $reps = all("SELECT r.* FROM repairs r WHERE $rw ORDER BY (r.status IN ('delivered','returned_unrepaired')), r.id DESC LIMIT 9", $rp);
        if (!$reps) { send_whatsapp($mobile, "🙏 *$shop*\nઆ નંબર પર કોઈ રિપેર જોબ નથી."); return 'repairs-none'; }
        $rows = []; $map = []; $n = 1;
        $txt = "🔧 *તમારા રિપેર જોબ*\n";
        foreach ($reps as $r) {
            $rows[] = ['id' => 'portal:repair:' . $r['id'], 'title' => wa_cat_cut($r['job_no'] . ' ' . $r['device_type'], 24),
                       'description' => wa_cat_cut(wa_portal_repair_status($r['status']), 72)];
            $map[(string)$n] = 'portal:repair:' . $r['id'];
            $txt .= "\n*$n)* {$r['job_no']} {$r['device_type']} — " . wa_portal_repair_status($r['status']);
            $n++;
        }
        $txt .= "\n\n👉 નંબર લખો — આખું સ્ટેટસ મળશે";
        return wa_catalog_deliver($mobile, [
            'type' => 'list', 'header' => ['type' => 'text', 'text' => '🔧 રિપેર સ્ટેટસ'],
            'body' => ['text' => 'જોબ પસંદ કરો — લાઈવ સ્ટેટસ અને રિપોર્ટ લિંક મળશે.'],
            'action' => ['button' => 'જોબ જુઓ', 'sections' => [['title' => 'રિપેર', 'rows' => $rows]]],
        ], $txt, $map) === 'send-failed' ? 'repairs-failed' : 'repairs';
    }
    if (preg_match('/^portal:repair:(\d+)$/', $id, $m)) {
        $rp = $party ? [(int)$m[1], (int)$party['id'], $ten] : [(int)$m[1], $ten];
        $rw = $party ? "(r.party_id = ? OR REPLACE(REPLACE(r.customer_mobile, '+', ''), ' ', '') LIKE ?)" : "REPLACE(REPLACE(r.customer_mobile, '+', ''), ' ', '') LIKE ?";
        $r = row("SELECT r.* FROM repairs r WHERE r.id = ? AND $rw", $rp);
        if (!$r) { send_whatsapp($mobile, '🙏 આ જોબ આ નંબર સાથે જોડાયેલો નથી.'); return 'repair-denied'; }
        $out = "🔧 *જોબ {$r['job_no']}*\n📦 " . trim($r['device_type'] . ' ' . $r['brand_model'])
             . "\n🗓 જમા: " . dmy($r['received_date'])
             . "\n📋 તકલીફ: " . $r['problem']
             . "\n\n*સ્ટેટસ: " . wa_portal_repair_status($r['status']) . "*";
        if ((float)$r['estimate_cost'] > 0 && !in_array($r['status'], ['delivered', 'returned_unrepaired'], true)) $out .= "\n💰 અંદાજિત ખર્ચ: ₹" . money($r['estimate_cost']);
        if ((float)$r['final_charge'] > 0) $out .= "\n💰 ચાર્જ: ₹" . money($r['final_charge']);
        if ($r['report_token']) $out .= "\n\n📄 સર્વિસ રિપોર્ટ: " . base_url('service_report.php?token=' . $r['report_token']);
        send_whatsapp($mobile, $out);
        return 'repair';
    }

    // ---------- warranty ----------
    if ($id === 'portal:warranty') {
        $out = "🛡 *$shop — વોરંટી*\n";
        $found = false;
        try {
            $w = wa_portal_sales_where($mobile, $p);
            $sers = all("SELECT ser.serial_no, ser.warranty_expiry, i.name item_name, s.invoice_no
                         FROM item_serials ser JOIN items i ON i.id = ser.item_id JOIN sales s ON s.id = ser.sale_id
                         WHERE $w ORDER BY ser.id DESC LIMIT 10", $p);
            foreach ($sers as $x) {
                $found = true;
                $on = $x['warranty_expiry'] && $x['warranty_expiry'] >= today();
                $out .= "\n• {$x['item_name']}\n  SN: {$x['serial_no']} — " . ($x['warranty_expiry'] ? dmy($x['warranty_expiry']) . ' સુધી ' . ($on ? '✅' : '❌') : 'વોરંટી નોંધ નથી');
            }
        } catch (Exception $e) {}
        if (!$found) $out .= "\nઆ નંબરના બિલમાં કોઈ સિરિયલ-નંબરવાળી પ્રોડક્ટ નથી.";
        $out .= "\n\n🔎 કોઈ પ્રોડક્ટનો *સિરિયલ નંબર લખી મોકલો* — એનું વોરંટી કાર્ડ તરત મળશે.\nક્લેમ માટે 'staff' લખો.";
        send_whatsapp($mobile, $out);
        return 'warranty';
    }

    // ---------- web orders ----------
    if ($id === 'portal:orders') {
        $ords = all("SELECT order_no, total, status, created_at FROM web_orders
                     WHERE REPLACE(REPLACE(mobile, '+', ''), ' ', '') LIKE ? ORDER BY id DESC LIMIT 5", [$ten]);
        if (!$ords) { send_whatsapp($mobile, "🙏 *$shop*\nઆ નંબર પર કોઈ ઓનલાઇન ઓર્ડર નથી.\n🛒 ઓર્ડર કરવા 'catalog' લખો!"); return 'orders-none'; }
        $st = ['new' => '🆕 નવો', 'confirmed' => '✅ કન્ફર્મ', 'completed' => '📦 પૂરો થયો', 'cancelled' => '❌ કેન્સલ'];
        $out = "📦 *તમારા ઓર્ડર*\n";
        foreach ($ords as $o) $out .= "\n• {$o['order_no']} — ₹" . money($o['total']) . ' — ' . ($st[$o['status']] ?? $o['status']) . ' (' . dmy($o['created_at']) . ')';
        send_whatsapp($mobile, $out);
        return 'orders';
    }

    // ---------- quotations ----------
    if ($id === 'portal:quotes') {
        $qp = $party ? [(int)$party['id'], $ten] : [$ten];
        $qw = $party ? "(e.party_id = ? OR REPLACE(REPLACE(e.customer_mobile, '+', ''), ' ', '') LIKE ?)" : "REPLACE(REPLACE(e.customer_mobile, '+', ''), ' ', '') LIKE ?";
        $qs = all("SELECT e.* FROM estimates e WHERE e.status = 'open' AND $qw ORDER BY e.id DESC LIMIT 3", $qp);
        if (!$qs) { send_whatsapp($mobile, "🙏 *$shop*\nકોઈ ખુલ્લું કોટેશન નથી."); return 'quotes-none'; }
        require_once __DIR__ . '/wa_meta.php';
        foreach ($qs as $qt) {
            $body = "📋 *કોટેશન {$qt['estimate_no']}*\n📅 " . dmy($qt['estimate_date']) . "\n💰 કુલ: *₹" . money($qt['total']) . '*'
                  . ($qt['notes'] ? "\n📝 " . mb_substr($qt['notes'], 0, 200) : '')
                  . "\n\nમંજૂર કરશો તો અમે તરત કામ શરૂ કરીશું.";
            [$ok, ] = meta_wa_configured() ? meta_wa_send_interactive($mobile, [
                'type' => 'button', 'body' => ['text' => $body],
                'action' => ['buttons' => [
                    ['type' => 'reply', 'reply' => ['id' => 'portal:qacc:' . $qt['id'], 'title' => '✅ મંજૂર કરો']],
                    ['type' => 'reply', 'reply' => ['id' => 'portal:qrej:' . $qt['id'], 'title' => '❌ નામંજૂર']],
                ]],
            ]) : [false, ''];
            if ($ok) wa_chat_log($mobile, 'out', $body . "\n[✅ મંજૂર] [❌ નામંજૂર]", 'meta');
            else send_whatsapp($mobile, $body . "\n\n✅ મંજૂર કરવા લખો: *ok {$qt['estimate_no']}*\n❌ નામંજૂર માટે: *no {$qt['estimate_no']}*");
        }
        return 'quotes';
    }
    if (preg_match('/^portal:q(acc|rej):(\d+)$/', $id, $m)) {
        $qp = $party ? [(int)$m[2], (int)$party['id'], $ten] : [(int)$m[2], $ten];
        $qw = $party ? "(e.party_id = ? OR REPLACE(REPLACE(e.customer_mobile, '+', ''), ' ', '') LIKE ?)" : "REPLACE(REPLACE(e.customer_mobile, '+', ''), ' ', '') LIKE ?";
        $qt = row("SELECT e.* FROM estimates e WHERE e.id = ? AND e.status = 'open' AND $qw", $qp);
        if (!$qt) { send_whatsapp($mobile, '🙏 આ કોટેશન હવે ખુલ્લું નથી.'); return 'quote-gone'; }
        $acc = $m[1] === 'acc';
        try { q('UPDATE estimates SET status = ? WHERE id = ?', [$acc ? 'accepted' : 'rejected', $qt['id']]); }
        catch (Exception $e) { /* pre-v48 enum - staff acts on the Telegram ping */ }
        try { tg_notify_admins(($acc ? '✅ કોટેશન મંજૂર' : '❌ કોટેશન નામંજૂર') . ": {$qt['estimate_no']} (₹" . money($qt['total']) . ") — {$qt['customer_name']} +$mobile"); } catch (Exception $e) {}
        send_whatsapp($mobile, $acc
            ? "✅ કોટેશન *{$qt['estimate_no']}* મંજૂર થયું — આભાર! અમારા માણસ તરત સંપર્ક કરી કામ શરૂ કરશે. 🙏"
            : "નોંધ્યું — કોટેશન *{$qt['estimate_no']}* નામંજૂર. કિંમત વિશે વાત કરવી હોય તો 'staff' લખો. 🙏");
        return $acc ? 'quote-accepted' : 'quote-rejected';
    }

    // ---------- support ticket ----------
    if ($id === 'portal:ticket') {
        wa_bot_set_state($mobile, 'ticket_wait', []);
        send_whatsapp($mobile, "🎫 *$shop — સપોર્ટ*\nતમારી ફરિયાદ / સવાલ *એક મેસેજમાં* લખી મોકલો — ટિકિટ ખૂલી જશે અને અમારા માણસ સંપર્ક કરશે.");
        return 'ticket-wait';
    }

    // ---------- store location ----------
    if ($id === 'portal:location') {
        $co = row('SELECT * FROM companies ORDER BY id LIMIT 1') ?: [];
        $addr = trim(($co['address'] ?? '') !== '' ? $co['address'] : 'Dwarka, Gujarat');
        $out = "📍 *" . ($co['name'] ?? $shop) . "*\n$addr";
        if (!empty($co['phone'])) $out .= "\n📞 " . $co['phone'];
        $out .= "\n🗺 " . 'https://maps.google.com/?q=' . rawurlencode(($co['name'] ?? $shop) . ' ' . $addr)
              . "\n🌐 " . base_url('') . '/';
        send_whatsapp($mobile, $out);
        return 'location';
    }

    // ---------- talk to staff ----------
    if ($id === 'portal:staff') {
        try {
            tg_notify_admins("📞 ગ્રાહક વાત કરવા માંગે છે!\n" . ($party ? $party['name'] . ' ' : '') . "+$mobile\n\nજવાબ આપવા: " . base_url('wa_inbox.php?m=' . $mobile));
        } catch (Exception $e) {}
        send_whatsapp($mobile, "✅ અમારા માણસને જાણ કરી દીધી છે — થોડી જ વારમાં અહીં જ જવાબ મળશે. 🙏\n📞 તાત્કાલિક હોય તો કૉલ કરો: " . (setting('wa_shop_number') ?: ''));
        return 'staff';
    }

    return 'unknown';
}
