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
        'portal:location' => '/^(address|સરનામું|location|લોકેશન|દુકાન ક્યાં|पता)$/iu',
        'portal:staff' => '/^(staff|માણસ|call ?me|વાત કરવી|talk|સંપર્ક|contact|स्टाफ)$/iu',
        'lang:pick' => '/^(language|lang|bhasha|ભાષા|ભાષા બદલો|भाषा)$/iu',
    ] as $id => $rx) {
        if (preg_match($rx, trim($t))) return $id;
    }
    return null;
}

/** The Account menu rows (id, label-key, description-key). The admin can
 *  hide any of them in Settings (wa_menu_off); when a slot frees up, the
 *  Change-Language row appears at the end (10-row Meta list limit). */
function wa_portal_menu_defs() {
    return [
        ['portal:bills', 'm_bills', 'm_bills_d'],
        ['portal:stmt', 'm_stmt', 'm_stmt_d'],
        ['portal:pay', 'm_pay', 'm_pay_d'],
        ['portal:repairs', 'm_repairs', 'm_repairs_d'],
        ['portal:warranty', 'm_warranty', 'm_warranty_d'],
        ['portal:orders', 'm_orders', 'm_orders_d'],
        ['portal:quotes', 'm_quotes', 'm_quotes_d'],
        ['portal:ticket', 'm_ticket', 'm_ticket_d'],
        ['portal:location', 'm_location', 'm_location_d'],
        ['portal:staff', 'm_staff', 'm_staff_d'],
    ];
}

/** One bill's summary text with public view/PDF links (share-token based). */
function wa_portal_bill_text($s) {
    if (empty($s['share_token'])) { // pre-token era bill: mint one now
        $s['share_token'] = share_token();
        q('UPDATE sales SET share_token = ? WHERE id = ?', [$s['share_token'], $s['id']]);
    }
    $due = $s['total'] - $s['paid'];
    return wa_t('bill_title', ['no' => $s['invoice_no']]) . "\n📅 " . dmy($s['sale_date'])
        . "\n" . wa_t('bill_total') . " *₹" . money($s['total']) . '*'
        . ($due > 0.009 ? "\n" . wa_t('bill_due') . " *₹" . money($due) . '*' : "\n" . wa_t('bill_paid'))
        . "\n\n" . wa_t('bill_view') . ' ' . base_url('sale_view.php?id=' . $s['id'] . '&token=' . $s['share_token'])
        . "\n⬇️ PDF: " . base_url('sale_pdf.php?id=' . $s['id'] . '&token=' . $s['share_token']);
}

/** Direct lookup: an invoice number or a serial number typed in the chat.
 *  Returns a reply text, or null if the text is neither (or not theirs). */
function wa_portal_lookup($mobile, $text) {
    $text = trim($text);
    if (preg_match('/^[A-Za-z]{2,5}-\d{2}-\d{2,6}$/', $text)) {
        $w = wa_portal_sales_where($mobile, $p);
        $s = row("SELECT s.* FROM sales s WHERE s.invoice_no = ? AND s.is_cancelled = 0 AND $w", array_merge([$text], $p));
        return $s ? wa_portal_bill_text($s) : wa_t('inv_notfound', ['no' => $text]);
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
    return wa_t('wc_title') . "\n📦 " . $ser['item_name'] . "\n🔢 Serial: *{$ser['serial_no']}*"
        . "\n" . wa_t('wc_bill') . " {$ser['invoice_no']} (" . dmy($ser['sale_date']) . ')'
        . ($exp ? "\n" . wa_t('wc_warr') . ' ' . dmy($exp) . ' ' . wa_t('w_till') . ' ' . ($on ? wa_t('wc_active') : wa_t('wc_over'))
                : "\n" . wa_t('wc_none'))
        . ($on ? "\n\n" . wa_t('wc_claim') : '');
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
    return wa_t('ticket_done', ['no' => doc_no('TKT', $tid)]);
}

/** Repair status labels in the chat's language (unknown ones show as-is). */
function wa_portal_repair_status($st) {
    return in_array($st, ['received', 'in_progress', 'outsourced', 'ready', 'delivered', 'returned_unrepaired'], true)
        ? wa_t('st_' . $st) : $st;
}

/** The whole portal: route one "portal:*" id. Returns a short status. */
function wa_portal_route($mobile, $id) {
    $shop = wa_portal_shop();
    $party = wa_bot_party_for($mobile);
    $ten = '%' . substr($mobile, -10);

    // ---------- main menu ----------
    if ($id === 'portal:menu') {
        // admin-hidden rows (Settings > WhatsApp > Bot menu) are skipped;
        // when a slot is free the Change-Language row rides at the end
        // (Meta allows 10 list rows total)
        $off = array_filter(array_map('trim', explode(',', setting('wa_menu_off', ''))));
        $defs = array_values(array_filter(wa_portal_menu_defs(), fn($d) => !in_array($d[0], $off, true)));
        if (count($defs) < 10) $defs[] = ['lang:pick', 'm_lang', 'm_lang_d'];
        $hello = wa_t('hello_w') . ($party ? ' *' . $party['name'] . '*' : '') . '!';
        $rows = []; $map = []; $n = 1;
        $txt = '🧾 *' . $shop . ' — ' . wa_t('menu_title') . "*\n" . $hello . "\n";
        foreach ($defs as $d) {
            $rows[] = ['id' => $d[0], 'title' => wa_cat_cut(wa_t($d[1]), 24), 'description' => wa_cat_cut(wa_t($d[2]), 72)];
            $map[(string)$n] = $d[0];
            $txt .= "\n*$n)* " . wa_t($d[1]);
            $n++;
        }
        $txt .= "\n\n" . wa_t('reply_number');
        return wa_catalog_deliver($mobile, [
            'type' => 'list',
            'header' => ['type' => 'text', 'text' => wa_cat_cut('🧾 ' . $shop, 60)],
            'body' => ['text' => ($party ? $hello . "\n" : '') . wa_t('menu_body')],
            'footer' => ['text' => wa_cat_cut($shop, 60)],
            'action' => ['button' => wa_cat_cut(wa_t('menu_btn'), 20), 'sections' => [['title' => wa_cat_cut(wa_t('menu_title'), 24), 'rows' => $rows]]],
        ], $txt, $map) === 'send-failed' ? 'menu-failed' : 'menu';
    }

    // ---------- bills ----------
    if ($id === 'portal:bills') {
        $w = wa_portal_sales_where($mobile, $p);
        $bills = all("SELECT s.id, s.invoice_no, s.sale_date, s.total, s.paid FROM sales s
                      WHERE s.is_cancelled = 0 AND $w ORDER BY s.id DESC LIMIT 9", $p);
        if (!$bills) { send_whatsapp($mobile, wa_t('bills_none', ['shop' => $shop])); return 'bills-none'; }
        $rows = []; $map = []; $n = 1;
        $txt = wa_t('bills_head') . "\n";
        foreach ($bills as $b) {
            $due = $b['total'] - $b['paid'];
            $rows[] = ['id' => 'portal:bill:' . $b['id'], 'title' => wa_cat_cut($b['invoice_no'], 24),
                       'description' => wa_cat_cut(dmy($b['sale_date']) . ' · ₹' . money($b['total']) . ($due > 0.009 ? ' · ' . wa_t('due_w') . ' ₹' . money($due) : ' · ✅'), 72)];
            $map[(string)$n] = 'portal:bill:' . $b['id'];
            $txt .= "\n*$n)* {$b['invoice_no']} — " . dmy($b['sale_date']) . " — ₹" . money($b['total']) . ($due > 0.009 ? ' (' . wa_t('due_w') . ' ₹' . money($due) . ')' : ' ✅');
            $n++;
        }
        $txt .= "\n\n" . wa_t('bills_hint');
        return wa_catalog_deliver($mobile, [
            'type' => 'list',
            'header' => ['type' => 'text', 'text' => wa_cat_cut(wa_t('m_bills'), 60)],
            'body' => ['text' => wa_t('bills_body')],
            'action' => ['button' => wa_cat_cut(wa_t('bills_btn'), 20), 'sections' => [['title' => wa_cat_cut(wa_t('bills_sec'), 24), 'rows' => $rows]]],
        ], $txt, $map) === 'send-failed' ? 'bills-failed' : 'bills';
    }
    if (preg_match('/^portal:bill:(\d+)$/', $id, $m)) {
        $w = wa_portal_sales_where($mobile, $p);
        $s = row("SELECT s.* FROM sales s WHERE s.id = ? AND s.is_cancelled = 0 AND $w", array_merge([(int)$m[1]], $p));
        if (!$s) { send_whatsapp($mobile, wa_t('bill_denied')); return 'bill-denied'; }
        $due = $s['total'] - $s['paid'];
        $body = wa_portal_bill_text($s);
        if ($due > 0.009) {
            require_once __DIR__ . '/wa_meta.php';
            [$ok, ] = meta_wa_configured() ? meta_wa_send_interactive($mobile, [
                'type' => 'button', 'body' => ['text' => $body],
                'action' => ['buttons' => [['type' => 'reply', 'reply' => ['id' => 'portal:paybill:' . $s['id'], 'title' => wa_cat_cut(wa_t('btn_paybill'), 20)]]]],
            ]) : [false, ''];
            if ($ok) { wa_chat_log($mobile, 'out', $body . "\n[" . wa_t('btn_paybill') . ']', 'meta'); return 'bill'; }
            $body .= "\n\n" . wa_t('pay_hint');
        }
        send_whatsapp($mobile, $body);
        return 'bill';
    }

    // ---------- statement / balance ----------
    if ($id === 'portal:stmt') {
        if (!$party) { send_whatsapp($mobile, wa_t('no_account', ['shop' => $shop])); return 'stmt-none'; }
        $bx = party_balance_expr('p');
        $bal = (float)val("SELECT $bx FROM parties p WHERE p.id = ?", [$party['id']]);
        $out = wa_t('stmt_title', ['shop' => $shop]) . "\n" . wa_t('hello_w') . " *{$party['name']}*!\n";
        if ($bal > 0.009) $out .= wa_t('stmt_due', ['amt' => money($bal)]) . "\n";
        elseif ($bal < -0.009) $out .= wa_t('stmt_adv', ['amt' => money(-$bal)]) . "\n";
        else $out .= wa_t('stmt_clear') . "\n";
        $nEntries = max(3, min(25, (int)setting('wa_stmt_entries', '10')));
        $lines = all("(SELECT sale_date d, CONCAT('B|', invoice_no) label, total amt FROM sales WHERE party_id = ? AND is_cancelled = 0)
                      UNION ALL
                      (SELECT pay_date d, IF(direction='in','I|','O|') label, amount amt FROM payments WHERE party_id = ?)
                      ORDER BY d DESC LIMIT $nEntries", [$party['id'], $party['id']]);
        if ($lines) {
            $out .= "\n" . wa_t('stmt_last');
            foreach ($lines as $l) {
                [$k, $no] = array_pad(explode('|', $l['label'], 2), 2, '');
                $label = $k === 'B' ? wa_t('lbl_bill') . ' ' . $no : ($k === 'I' ? wa_t('lbl_pay_in') : wa_t('lbl_pay_out'));
                $out .= "\n• " . dmy($l['d']) . ' — ' . $label . ' — ₹' . money($l['amt']);
            }
        }
        $out .= "\n\n" . wa_t('stmt_footer') . ($bal > 0.009 ? wa_t('stmt_pay') : '');
        send_whatsapp($mobile, $out);
        return 'stmt';
    }

    // ---------- payment ----------
    if ($id === 'portal:pay' || preg_match('/^portal:paybill:(\d+)$/', $id, $m)) {
        if (!empty($m[1])) {
            $w = wa_portal_sales_where($mobile, $p);
            $s = row("SELECT s.* FROM sales s WHERE s.id = ? AND s.is_cancelled = 0 AND $w", array_merge([(int)$m[1]], $p));
            if (!$s) { send_whatsapp($mobile, wa_t('bill_denied')); return 'pay-denied'; }
            $due = $s['total'] - $s['paid'];
            $ref = $s['invoice_no']; $desc = 'Invoice ' . $s['invoice_no']; $saleId = (int)$s['id'];
            $name = $s['customer_name'];
        } else {
            $due = $party ? max(0, (float)val('SELECT ' . party_balance_expr('p') . ' FROM parties p WHERE p.id = ?', [$party['id']])) : 0;
            $ref = 'ACC-' . substr($mobile, -10); $desc = 'Account payment - ' . ($party['name'] ?? ''); $saleId = null;
            $name = $party['name'] ?? '';
        }
        if ($due <= 0.009) { send_whatsapp($mobile, wa_t('pay_clear', ['shop' => $shop])); return 'pay-clear'; }
        $link = razorpay_payment_link($due, $desc, $name, $mobile, $ref . '-' . time(), $saleId);
        if ($link) {
            send_whatsapp($mobile, wa_t('pay_link', ['shop' => $shop, 'amt' => money($due), 'link' => $link]));
            return 'pay-link';
        }
        try { tg_notify_admins("💳 પેમેન્ટ કરવા માંગે છે: +$mobile (₹" . money($due) . ") — ઓનલાઇન લિંક બની નહીં, સંપર્ક કરો."); } catch (Exception $e) {}
        send_whatsapp($mobile, wa_t('pay_manual', ['shop' => $shop, 'amt' => money($due)]));
        return 'pay-manual';
    }

    // ---------- repairs ----------
    if ($id === 'portal:repairs') {
        $rp = $party ? [(int)$party['id'], $ten] : [$ten];
        $rw = $party ? "(r.party_id = ? OR REPLACE(REPLACE(r.customer_mobile, '+', ''), ' ', '') LIKE ?)" : "REPLACE(REPLACE(r.customer_mobile, '+', ''), ' ', '') LIKE ?";
        $reps = all("SELECT r.* FROM repairs r WHERE $rw ORDER BY (r.status IN ('delivered','returned_unrepaired')), r.id DESC LIMIT 9", $rp);
        if (!$reps) { send_whatsapp($mobile, wa_t('repairs_none', ['shop' => $shop])); return 'repairs-none'; }
        $rows = []; $map = []; $n = 1;
        $txt = wa_t('repairs_head') . "\n";
        foreach ($reps as $r) {
            $rows[] = ['id' => 'portal:repair:' . $r['id'], 'title' => wa_cat_cut($r['job_no'] . ' ' . $r['device_type'], 24),
                       'description' => wa_cat_cut(wa_portal_repair_status($r['status']), 72)];
            $map[(string)$n] = 'portal:repair:' . $r['id'];
            $txt .= "\n*$n)* {$r['job_no']} {$r['device_type']} — " . wa_portal_repair_status($r['status']);
            $n++;
        }
        $txt .= "\n\n" . wa_t('repairs_hint');
        return wa_catalog_deliver($mobile, [
            'type' => 'list', 'header' => ['type' => 'text', 'text' => wa_cat_cut(wa_t('m_repairs'), 60)],
            'body' => ['text' => wa_t('repairs_body')],
            'action' => ['button' => wa_cat_cut(wa_t('repairs_btn'), 20), 'sections' => [['title' => wa_cat_cut(wa_t('repairs_sec'), 24), 'rows' => $rows]]],
        ], $txt, $map) === 'send-failed' ? 'repairs-failed' : 'repairs';
    }
    if (preg_match('/^portal:repair:(\d+)$/', $id, $m)) {
        $rp = $party ? [(int)$m[1], (int)$party['id'], $ten] : [(int)$m[1], $ten];
        $rw = $party ? "(r.party_id = ? OR REPLACE(REPLACE(r.customer_mobile, '+', ''), ' ', '') LIKE ?)" : "REPLACE(REPLACE(r.customer_mobile, '+', ''), ' ', '') LIKE ?";
        $r = row("SELECT r.* FROM repairs r WHERE r.id = ? AND $rw", $rp);
        if (!$r) { send_whatsapp($mobile, wa_t('repair_denied')); return 'repair-denied'; }
        $out = wa_t('rj_title', ['no' => $r['job_no']]) . "\n📦 " . trim($r['device_type'] . ' ' . $r['brand_model'])
             . "\n" . wa_t('rj_received') . ' ' . dmy($r['received_date'])
             . "\n" . wa_t('rj_problem') . ' ' . $r['problem']
             . "\n\n*" . wa_t('rj_status') . ': ' . wa_portal_repair_status($r['status']) . "*";
        if ((float)$r['estimate_cost'] > 0 && !in_array($r['status'], ['delivered', 'returned_unrepaired'], true)) $out .= "\n" . wa_t('rj_est', ['amt' => money($r['estimate_cost'])]);
        if ((float)$r['final_charge'] > 0) $out .= "\n" . wa_t('rj_charge', ['amt' => money($r['final_charge'])]);
        if ($r['report_token']) $out .= "\n\n" . wa_t('rj_report') . ' ' . base_url('service_report.php?token=' . $r['report_token']);
        send_whatsapp($mobile, $out);
        return 'repair';
    }

    // ---------- warranty ----------
    if ($id === 'portal:warranty') {
        $out = wa_t('warr_head', ['shop' => $shop]) . "\n";
        $found = false;
        try {
            $w = wa_portal_sales_where($mobile, $p);
            $sers = all("SELECT ser.serial_no, ser.warranty_expiry, i.name item_name, s.invoice_no
                         FROM item_serials ser JOIN items i ON i.id = ser.item_id JOIN sales s ON s.id = ser.sale_id
                         WHERE $w ORDER BY ser.id DESC LIMIT 10", $p);
            foreach ($sers as $x) {
                $found = true;
                $on = $x['warranty_expiry'] && $x['warranty_expiry'] >= today();
                $out .= "\n• {$x['item_name']}\n  SN: {$x['serial_no']} — " . ($x['warranty_expiry'] ? dmy($x['warranty_expiry']) . ' ' . wa_t('w_till') . ' ' . ($on ? '✅' : '❌') : wa_t('w_norec'));
            }
        } catch (Exception $e) {}
        if (!$found) $out .= "\n" . wa_t('warr_none');
        $out .= "\n\n" . wa_t('warr_hint');
        send_whatsapp($mobile, $out);
        return 'warranty';
    }

    // ---------- web orders ----------
    if ($id === 'portal:orders') {
        $ords = all("SELECT order_no, total, status, created_at FROM web_orders
                     WHERE REPLACE(REPLACE(mobile, '+', ''), ' ', '') LIKE ? ORDER BY id DESC LIMIT 5", [$ten]);
        if (!$ords) { send_whatsapp($mobile, wa_t('orders_none', ['shop' => $shop])); return 'orders-none'; }
        $st = ['new' => wa_t('ost_new'), 'confirmed' => wa_t('ost_confirmed'), 'completed' => wa_t('ost_completed'), 'cancelled' => wa_t('ost_cancelled')];
        $out = wa_t('orders_head') . "\n";
        foreach ($ords as $o) $out .= "\n• {$o['order_no']} — ₹" . money($o['total']) . ' — ' . ($st[$o['status']] ?? $o['status']) . ' (' . dmy($o['created_at']) . ')';
        send_whatsapp($mobile, $out);
        return 'orders';
    }

    // ---------- quotations ----------
    if ($id === 'portal:quotes') {
        $qp = $party ? [(int)$party['id'], $ten] : [$ten];
        $qw = $party ? "(e.party_id = ? OR REPLACE(REPLACE(e.customer_mobile, '+', ''), ' ', '') LIKE ?)" : "REPLACE(REPLACE(e.customer_mobile, '+', ''), ' ', '') LIKE ?";
        $qs = all("SELECT e.* FROM estimates e WHERE e.status = 'open' AND $qw ORDER BY e.id DESC LIMIT 3", $qp);
        if (!$qs) { send_whatsapp($mobile, wa_t('quotes_none', ['shop' => $shop])); return 'quotes-none'; }
        require_once __DIR__ . '/wa_meta.php';
        foreach ($qs as $qt) {
            $body = wa_t('q_title', ['no' => $qt['estimate_no']]) . "\n📅 " . dmy($qt['estimate_date']) . "\n" . wa_t('bill_total') . " *₹" . money($qt['total']) . '*'
                  . ($qt['notes'] ? "\n📝 " . mb_substr($qt['notes'], 0, 200) : '')
                  . "\n\n" . wa_t('q_note');
            [$ok, ] = meta_wa_configured() ? meta_wa_send_interactive($mobile, [
                'type' => 'button', 'body' => ['text' => $body],
                'action' => ['buttons' => [
                    ['type' => 'reply', 'reply' => ['id' => 'portal:qacc:' . $qt['id'], 'title' => wa_cat_cut(wa_t('btn_qacc'), 20)]],
                    ['type' => 'reply', 'reply' => ['id' => 'portal:qrej:' . $qt['id'], 'title' => wa_cat_cut(wa_t('btn_qrej'), 20)]],
                ]],
            ]) : [false, ''];
            if ($ok) wa_chat_log($mobile, 'out', $body . "\n[" . wa_t('btn_qacc') . '] [' . wa_t('btn_qrej') . ']', 'meta');
            else send_whatsapp($mobile, $body . "\n\n" . wa_t('q_text_hint', ['no' => $qt['estimate_no']]));
        }
        return 'quotes';
    }
    if (preg_match('/^portal:q(acc|rej):(\d+)$/', $id, $m)) {
        $qp = $party ? [(int)$m[2], (int)$party['id'], $ten] : [(int)$m[2], $ten];
        $qw = $party ? "(e.party_id = ? OR REPLACE(REPLACE(e.customer_mobile, '+', ''), ' ', '') LIKE ?)" : "REPLACE(REPLACE(e.customer_mobile, '+', ''), ' ', '') LIKE ?";
        $qt = row("SELECT e.* FROM estimates e WHERE e.id = ? AND e.status = 'open' AND $qw", $qp);
        if (!$qt) { send_whatsapp($mobile, wa_t('q_gone')); return 'quote-gone'; }
        $acc = $m[1] === 'acc';
        try { q('UPDATE estimates SET status = ? WHERE id = ?', [$acc ? 'accepted' : 'rejected', $qt['id']]); }
        catch (Exception $e) { /* pre-v48 enum - staff acts on the Telegram ping */ }
        try { tg_notify_admins(($acc ? '✅ કોટેશન મંજૂર' : '❌ કોટેશન નામંજૂર') . ": {$qt['estimate_no']} (₹" . money($qt['total']) . ") — {$qt['customer_name']} +$mobile"); } catch (Exception $e) {}
        send_whatsapp($mobile, wa_t($acc ? 'q_acc_done' : 'q_rej_done', ['no' => $qt['estimate_no']]));
        return $acc ? 'quote-accepted' : 'quote-rejected';
    }

    // ---------- support ticket ----------
    if ($id === 'portal:ticket') {
        wa_bot_set_state($mobile, 'ticket_wait', []);
        send_whatsapp($mobile, wa_t('ticket_ask', ['shop' => $shop]));
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
        send_whatsapp($mobile, wa_t('staff_ack', ['phone' => setting('wa_shop_number') ?: '']));
        return 'staff';
    }

    return 'unknown';
}
