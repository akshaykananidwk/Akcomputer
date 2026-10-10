<?php
// ✉️ Sending e-mail. With an SMTP server set (Settings → Connections → E-mail
// sending - Gmail, Zoho, the hosting's own mailbox...) mail goes through it,
// signed in; otherwise PHP's mail(), which most cPanel hosting supports.
// A shop with no settings of its own uses the platform owner's.
// The password is kept encrypted like every other secret.

/** [host, port, user, pass, from, from_name] - the shop's own, else the platform's. */
function mail_conf() {
    $get = fn($k, $d = '') => (string)setting($k, $d);
    if ($get('smtp_host') === '' && function_exists('tenant_active') && tenant_active()) {
        $get = function ($k, $d = '') {
            $v = pval('SELECT value FROM settings WHERE name = ?', [$k]);
            if ($v === null) return $d;
            return strncmp((string)$v, SECRET_PREFIX, strlen(SECRET_PREFIX)) === 0 ? vault_decrypt(substr($v, strlen(SECRET_PREFIX))) : (string)$v;
        };
    }
    $host = trim($get('smtp_host'));
    $user = trim($get('smtp_user'));
    $from = trim($get('smtp_from')) ?: ($user !== '' && strpos($user, '@') ? $user : 'no-reply@' . (request_host() ?: 'localhost'));
    return ['host' => $host, 'port' => (int)($get('smtp_port') ?: 587), 'user' => $user, 'pass' => $get('smtp_pass'),
            'from' => $from, 'name' => trim($get('smtp_from_name')) ?: setting('app_name', 'Shop')];
}

function mail_last_error() { return (string)($GLOBALS['_mail_err'] ?? ''); }

/** Build the whole message (headers + body). Attachments: [[filename, bytes, mime], ...]. */
function mail_build(array $c, $to, $subject, $html, $text, array $att) {
    $enc = fn($s) => preg_match('/[^\x20-\x7E]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s;
    $alt = 'alt' . bin2hex(random_bytes(8)); $mix = 'mix' . bin2hex(random_bytes(8));
    $text = $text !== '' ? $text : trim(html_entity_decode(strip_tags(preg_replace('/<(br|\/p|\/div|\/tr|\/h\d)>/i', "\n", $html)), ENT_QUOTES, 'UTF-8'));
    $body = "--$alt\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($text))
          . "--$alt\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($html)) . "--$alt--\r\n";
    $ctype = "multipart/alternative; boundary=\"$alt\"";
    if ($att) {
        $body = "--$mix\r\nContent-Type: $ctype\r\n\r\n$body";
        foreach ($att as [$fn, $bytes, $mime]) {
            $fn = preg_replace('/[^\w.\-]/', '_', $fn);
            $body .= "--$mix\r\nContent-Type: $mime; name=\"$fn\"\r\nContent-Disposition: attachment; filename=\"$fn\"\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($bytes));
        }
        $body .= "--$mix--\r\n"; $ctype = "multipart/mixed; boundary=\"$mix\"";
    }
    $domain = substr(strrchr($c['from'], '@'), 1) ?: 'localhost';
    $head = ['From' => $enc($c['name']) . ' <' . $c['from'] . '>', 'To' => $to, 'Subject' => $enc($subject), 'Date' => date('r'),
             'Message-ID' => '<' . bin2hex(random_bytes(12)) . '@' . $domain . '>', 'MIME-Version' => '1.0', 'Content-Type' => $ctype];
    return [$head, $body];
}

/** Talk SMTP: 465 = SSL from the start, 587/25 = STARTTLS when offered. Returns '' or the error. */
function smtp_send(array $c, $to, array $head, $body) {
    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true, 'peer_name' => $c['host']]]);
    $s = @stream_socket_client(($c['port'] === 465 ? 'ssl://' : 'tcp://') . $c['host'] . ':' . $c['port'], $en, $es, 20, STREAM_CLIENT_CONNECT, $ctx);
    if (!$s) return "could not reach {$c['host']}:{$c['port']} ($es)";
    stream_set_timeout($s, 30);
    $read = function () use ($s) { $all = ''; while (($l = fgets($s, 1024)) !== false) { $all .= $l; if (strlen($l) < 4 || $l[3] === ' ') break; } return $all; };
    $say = function ($cmd, $want) use ($s, $read) { if ($cmd !== null) fwrite($s, $cmd . "\r\n"); $r = $read(); return in_array((int)substr($r, 0, 3), (array)$want, true) ? '' : (trim($r) ?: 'no answer'); };
    $ehloName = request_host() ?: 'localhost';
    if ($e = $say(null, 220)) { fclose($s); return "greeting: $e"; }
    fwrite($s, "EHLO $ehloName\r\n"); $ehlo = $read();
    if ((int)$ehlo !== 250) { fclose($s); return 'EHLO: ' . trim($ehlo); }
    if ($c['port'] !== 465 && stripos($ehlo, 'STARTTLS') !== false) {
        if ($e = $say('STARTTLS', 220)) { fclose($s); return "STARTTLS: $e"; }
        if (!@stream_socket_enable_crypto($s, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) { fclose($s); return 'could not start TLS'; }
        fwrite($s, "EHLO $ehloName\r\n"); $read();
    }
    if ($c['user'] !== '') {
        if ($e = $say('AUTH LOGIN', 334)) { fclose($s); return "AUTH: $e"; }
        if ($e = $say(base64_encode($c['user']), 334)) { fclose($s); return "user: $e"; }
        if ($e = $say(base64_encode($c['pass']), 235)) { fclose($s); return 'the mail server refused the password (for Gmail use an app password)'; }
    }
    if ($e = $say('MAIL FROM:<' . $c['from'] . '>', 250)) { fclose($s); return "from: $e"; }
    if ($e = $say('RCPT TO:<' . $to . '>', [250, 251])) { fclose($s); return "to: $e"; }
    if ($e = $say('DATA', 354)) { fclose($s); return "DATA: $e"; }
    $msg = '';
    foreach ($head as $k => $v) $msg .= "$k: $v\r\n";
    $msg .= "\r\n" . preg_replace('/^\./m', '..', str_replace(["\r\n", "\n"], ["\n", "\r\n"], $body));
    fwrite($s, $msg . "\r\n.\r\n");
    $e = $say(null, 250);
    $say('QUIT', [221, 250]); fclose($s);
    return $e === '' ? '' : "send: $e";
}

/** Send one e-mail. Returns true/false; mail_last_error() says why not. */
function send_mail($to, $subject, $html, $text = '', array $attachments = []) {
    $GLOBALS['_mail_err'] = '';
    $to = trim((string)$to);
    if (!filter_var($to, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $to . $subject)) { $GLOBALS['_mail_err'] = 'not a valid e-mail address'; return false; }
    $c = mail_conf();
    [$head, $body] = mail_build($c, $to, $subject, $html, $text, $attachments);
    if (isset($GLOBALS['mail_mock'])) $err = ($GLOBALS['mail_mock'])($to, $subject, $head, $body);
    elseif ($c['host'] !== '') $err = smtp_send($c, $to, $head, $body);
    else {
        $h = $head; unset($h['To'], $h['Subject']);
        $err = @mail($to, $head['Subject'], $body, implode("\r\n", array_map(fn($k, $v) => "$k: $v", array_keys($h), $h)), '-f' . $c['from']) ? '' : 'the server could not send mail (set an SMTP server in Connections)';
    }
    $GLOBALS['_mail_err'] = (string)$err;
    try { q('INSERT INTO mail_log (to_addr, subject, ok, error) VALUES (?,?,?,?)', [mb_substr($to, 0, 190), mb_substr($subject, 0, 190), $err === '' ? 1 : 0, mb_substr((string)$err, 0, 255)]); } catch (Exception $e) {}
    return $err === '';
}

/** A plain, readable e-mail around a few lines of content. */
function mail_html($title, $bodyHtml) {
    $shop = e(setting('app_name', 'Shop'));
    return '<!doctype html><html><body style="margin:0;background:#f1f5f9;font-family:Arial,sans-serif;color:#0f172a">'
         . '<div style="max-width:560px;margin:0 auto;padding:20px"><div style="background:#fff;border-radius:10px;padding:22px">'
         . '<div style="font-size:13px;color:#64748b;margin-bottom:6px">' . $shop . '</div><h2 style="margin:0 0 12px;font-size:20px">' . e($title) . '</h2>'
         . $bodyHtml . '</div><p style="font-size:11px;color:#94a3b8;text-align:center">' . $shop . '</p></div></body></html>';
}
