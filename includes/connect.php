<?php
// Reading what other systems send us - a UPI SMS, a bank statement, a Tally
// export, a supplier's e-mail. Each reader only READS: what it finds is
// shown to a person, who decides what goes into the books.

/** A bank/UPI "money received" SMS -> [amount, ref, payer] or null when it is not a credit. */
function upi_parse_sms($text) {
    $t = preg_replace('/\s+/', ' ', (string)$text);
    if (!preg_match('/credit|received|deposited|cr\b|jama|aavya/i', $t) || preg_match('/debited|sent to|paid to|withdrawn/i', $t)) return null;
    if (!preg_match('/(?:rs\.?|inr|₹)\s*([\d,]+(?:\.\d{1,2})?)/i', $t, $m)) return null;
    $amt = (float)str_replace(',', '', $m[1]);
    if ($amt <= 0) return null;
    $ref = preg_match('/(?:upi\s*ref(?:erence)?\.?\s*(?:no\.?)?|utr|ref(?:\s*no)?\.?|rrn)\s*[:#-]?\s*(\d{9,18})/i', $t, $r) ? $r[1]
         : (preg_match('/\b(\d{12})\b/', $t, $r) ? $r[1] : '');
    $payer = preg_match('/\b([\w.\-]+@[a-z]+)\b/i', $t, $p) ? $p[1]                                   // a UPI id says who, exactly
           : (preg_match('/(?:from|by)\s+([A-Za-z][A-Za-z .]{1,40}?)(?=\s*(?:\(|on\b|via|ref|upi|\.|,|$))/i', $t, $p) ? trim($p[1]) : '');
    return ['amount' => round($amt, 2), 'ref' => $ref, 'payer' => $payer];
}

/** Rows of an .xlsx file's first sheet (no library: it is a zip of XML). */
function xlsx_rows($file) {
    $z = new ZipArchive();
    if ($z->open($file) !== true) return [];
    $shared = [];
    if (($sx = $z->getFromName('xl/sharedStrings.xml')) !== false) {
        $x = @simplexml_load_string($sx);
        if ($x) foreach ($x->si as $si) $shared[] = isset($si->t) ? (string)$si->t : implode('', array_map('strval', $si->xpath('.//*[local-name()="t"]')));
    }
    $sheet = $z->getFromName('xl/worksheets/sheet1.xml'); $z->close();
    $x = $sheet ? @simplexml_load_string($sheet) : null;
    if (!$x) return [];
    $rows = [];
    foreach ($x->sheetData->row as $row) {
        $r = [];
        foreach ($row->c as $c) {
            $col = 0; foreach (str_split(preg_replace('/\d/', '', (string)$c['r'])) as $ch) $col = $col * 26 + ord($ch) - 64;
            $v = (string)$c->v;
            if ((string)$c['t'] === 's') $v = $shared[(int)$v] ?? '';
            elseif ((string)$c['t'] === 'inlineStr') $v = (string)$c->is->t;
            $r[$col - 1] = $v;
        }
        if ($r) { $max = max(array_keys($r)); $rows[] = array_map(fn($i) => $r[$i] ?? '', range(0, $max)); }
    }
    return $rows;
}

/** A bank statement (rows incl. header) -> [[date Y-m-d, description, amount (+in/-out), ref], ...].
 *  Finds the header row and the columns by their usual names, in any bank's layout. */
function bank_statement_parse(array $rows) {
    $find = function (array $h, array $words) { foreach ($h as $i => $c) foreach ($words as $w) if (preg_match('/' . $w . '/i', (string)$c)) return $i; return null; };
    foreach ($rows as $hi => $h) {
        $d = $find($h, ['^\s*(txn |transaction |value )?date']); $desc = $find($h, ['narration', 'description', 'particulars', 'remarks', 'details']);
        if ($d === null || $desc === null) continue;
        $dr = $find($h, ['withdrawal', 'debit', '^\s*dr\b']); $cr = $find($h, ['deposit', 'credit', '^\s*cr\b']); $amt = $find($h, ['^\s*amount']);
        $ref = $find($h, ['ref', 'chq', 'cheque', 'utr']);
        $out = [];
        foreach (array_slice($rows, $hi + 1) as $r) {
            $date = bank_date($r[$d] ?? '');
            if (!$date) continue;
            $num = fn($v) => (float)preg_replace('/[^\d.\-]/', '', (string)$v);
            if ($dr !== null || $cr !== null) $a = $num($r[$cr] ?? 0) - $num($r[$dr] ?? 0);
            elseif ($amt !== null) $a = $num($r[$amt] ?? 0) * (preg_match('/\bdr\b|debit/i', implode(' ', $r)) ? -1 : 1);
            else continue;
            if (abs($a) < 0.005) continue;
            $out[] = [$date, trim((string)($r[$desc] ?? '')), round($a, 2), trim((string)($ref !== null ? ($r[$ref] ?? '') : ''))];
        }
        return $out;
    }
    return [];
}
function bank_date($v) {
    $v = trim((string)$v);
    if (is_numeric($v) && $v > 30000 && $v < 80000) return date('Y-m-d', ((int)$v - 25569) * 86400);   // an Excel day number
    if (preg_match('#^(\d{1,2})[/\-. ](\d{1,2}|[A-Za-z]{3})[/\-. ](\d{2,4})#', $v, $m)) {
        $mon = is_numeric($m[2]) ? (int)$m[2] : (int)date('n', strtotime('1 ' . $m[2] . ' 2000'));
        $y = (int)$m[3] < 100 ? 2000 + (int)$m[3] : (int)$m[3];
        return checkdate($mon, (int)$m[1], $y) ? sprintf('%04d-%02d-%02d', $y, $mon, $m[1]) : null;
    }
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $v, $m)) return "$m[1]-$m[2]-$m[3]";
    return null;
}

/** Tally masters XML -> ['parties' => [[name, group, opening, gstin, mobile]], 'items' => [[name, unit, rate, hsn]]]. */
function tally_parse_xml($xml) {
    $xml = preg_replace('/&#(?:[0-8]|1[0-9]|2[0-9]|3[01]);/', '', (string)$xml);   // Tally writes control characters XML does not allow
    $prev = libxml_use_internal_errors(true);
    $x = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET);
    libxml_use_internal_errors($prev);
    if (!$x) return ['parties' => [], 'items' => []];
    $out = ['parties' => [], 'items' => []];
    foreach ($x->xpath('//LEDGER') as $l) {
        $grp = (string)$l->PARENT;
        if (!preg_match('/sundry (debtors|creditors)/i', $grp)) continue;
        $name = trim((string)($l['NAME'] ?? '') ?: (string)$l->NAME);
        if ($name === '') continue;
        $out['parties'][] = [$name, stripos($grp, 'creditor') !== false ? 'supplier' : 'customer',
            // Tally: debit balances are negative numbers; a debtor owes us
            round(-(float)preg_replace('/[^\d.\-]/', '', (string)$l->OPENINGBALANCE), 2),
            strtoupper(trim((string)($l->PARTYGSTIN ?: $l->GSTREGISTRATIONNUMBER))), preg_replace('/\D/', '', (string)($l->LEDGERMOBILE ?: $l->LEDGERPHONE))];
    }
    foreach ($x->xpath('//STOCKITEM') as $s) {
        $name = trim((string)($s['NAME'] ?? '') ?: (string)$s->NAME);
        if ($name === '') continue;
        $rate = 0; if (preg_match('/([\d.]+)/', (string)($s->{'STANDARDPRICELIST.LIST'}->RATE ?? $s->OPENINGRATE), $m)) $rate = (float)$m[1];
        $out['items'][] = [$name, trim((string)$s->BASEUNITS) ?: 'PCS', $rate, trim((string)($s->{'GSTDETAILS.LIST'}->HSNCODE ?? ''))];
    }
    return $out;
}

/** The attachments of an e-mail (raw RFC822): [[filename, bytes], ...] - PDFs and pictures only. */
function mime_attachments($raw) {
    $raw = str_replace("\r\n", "\n", (string)$raw);
    [$head, $body] = array_pad(explode("\n\n", $raw, 2), 2, '');
    $head = preg_replace("/\n[ \t]+/", ' ', $head);
    $out = [];
    if (preg_match('/content-type:\s*multipart\/[^;]+;.*?boundary="?([^";\n]+)"?/is', $head, $m)) {
        foreach (preg_split('/^--' . preg_quote($m[1], '/') . '(?:--)?\s*$/m', $body) as $part)
            if (trim($part) !== '') $out = array_merge($out, mime_attachments(ltrim($part, "\n")));
        return $out;
    }
    $name = preg_match('/(?:filename|name)\*?="?([^";\n]+)"?/i', $head, $n) ? basename(trim($n[1])) : '';
    $isFile = preg_match('/content-type:\s*(application\/pdf|image\/(jpe?g|png|webp))/i', $head) || preg_match('/\.(pdf|jpe?g|png|webp)$/i', $name);
    if (!$isFile || $name === '' && !preg_match('/content-disposition:\s*attachment/i', $head)) return [];
    if (preg_match('/content-transfer-encoding:\s*base64/i', $head)) $body = base64_decode(preg_replace('/\s+/', '', $body));
    elseif (preg_match('/content-transfer-encoding:\s*quoted-printable/i', $head)) $body = quoted_printable_decode($body);
    return [[$name ?: 'bill.pdf', $body]];
}

/**
 * Fetch unread mails with a bill attached from the shop's mailbox (IMAP over
 * SSL - plain sockets, the hosting has no imap extension) and keep each PDF /
 * photo in the bill-scan folder. Mails are only marked read; nothing else is
 * changed in the mailbox. Returns [files saved, message].
 */
function mail_fetch_bills() {
    $host = trim((string)setting('imap_host', '')); $user = trim((string)setting('imap_user', '')); $pass = (string)setting('imap_pass', '');
    if ($host === '' || $user === '' || $pass === '') return [0, 'Fill the mailbox in Connections first.'];
    $s = @stream_socket_client('ssl://' . $host . ':993', $en, $es, 15, STREAM_CLIENT_CONNECT, stream_context_create(['ssl' => ['verify_peer' => true]]));
    if (!$s) return [0, "Could not reach $host ($es)."];
    stream_set_timeout($s, 30);
    $n = 0;
    $cmd = function ($c) use ($s, &$n) {
        $tag = 'A' . (++$n); fwrite($s, "$tag $c\r\n"); $out = '';
        while (($line = fgets($s)) !== false) {
            if (preg_match('/\{(\d+)\}\r\n$/', $line, $m)) { $line .= stream_get_contents($s, (int)$m[1]); }
            $out .= $line;
            if (strpos($line, "$tag ") === 0) return [stripos($line, "$tag OK") === 0, $out];
        }
        return [false, $out];
    };
    fgets($s);
    $q = fn($v) => '"' . addcslashes($v, '"\\') . '"';
    [$ok] = $cmd('LOGIN ' . $q($user) . ' ' . $q($pass));
    if (!$ok) { fclose($s); return [0, 'The mailbox refused the login (for Gmail use an "app password").']; }
    $cmd('SELECT INBOX');
    [, $found] = $cmd('SEARCH UNSEEN SINCE ' . date('d-M-Y', strtotime('-14 days')));
    $ids = preg_match('/\* SEARCH ([\d ]+)/', $found, $m) ? array_slice(array_filter(explode(' ', trim($m[1]))), -20) : [];
    $dir = up_dir('purchase_scans'); $saved = 0;
    foreach ($ids as $id) {
        [, $raw] = $cmd("FETCH $id BODY.PEEK[]");
        $atts = mime_attachments(preg_replace('/^.*?\{\d+\}\r\n/s', '', $raw, 1));
        if (!$atts) continue;
        $from = preg_match('/^From:\s*(.+)$/mi', $raw, $f) ? mb_substr(trim($f[1]), 0, 120) : '';
        $subj = preg_match('/^Subject:\s*(.+)$/mi', $raw, $f) ? mb_substr(trim(iconv_mime_decode($f[1], 0, 'UTF-8') ?: $f[1]), 0, 150) : '';
        foreach ($atts as [$name, $bytes]) {
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'webp'], true) || strlen($bytes) > 15 * 1024 * 1024) continue;
            $file = 'mail_' . date('YmdHis') . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
            file_put_contents("$dir/$file", $bytes);
            file_put_contents("$dir/$file.json", json_encode(['from' => $from, 'subject' => $subj, 'name' => $name, 'at' => date('Y-m-d H:i')], JSON_UNESCAPED_UNICODE));
            $saved++;
        }
        $cmd("STORE $id +FLAGS (\\Seen)");
    }
    $cmd('LOGOUT'); fclose($s);
    return [$saved, $saved ? "$saved bill file(s) from e-mail." : 'No new e-mails with a bill attached.'];
}
