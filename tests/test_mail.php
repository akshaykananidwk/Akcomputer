<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); die('CLI only'); }
// E-mail: logging in with it, the reset link, and the message itself.

t_group('E-mail: logging in with it');
q("INSERT INTO users (name, mobile, username, email, password, role_id, location_id) VALUES ('Mail Test', '9000000005', ?, 'mail.test@example.com', ?, (SELECT MIN(id) FROM roles), (SELECT MIN(id) FROM locations))",
  ['mailt' . mt_rand(1000, 9999), password_hash('Abc@12345', PASSWORD_DEFAULT)]);
$mid = insert_id();
t_eq('by e-mail, any capitals', (int)(login_find_user('Mail.Test@Example.COM')['id'] ?? 0), $mid);
t_ok('an unknown e-mail finds no one', login_find_user('nobody@example.com') === null);
t_ok('an empty box finds no one', login_find_user('  ') === null);

t_group('E-mail: the password-reset link');
$link = password_reset_link($mid);
$tok = substr($link, strpos($link, 't=') + 2);
t_ok('the link carries a long random key', (bool)preg_match('/^[a-f0-9]{64}$/', $tok));
t_ok('only its hash is stored', !val('SELECT id FROM password_resets WHERE token_hash = ?', [$tok]) && (bool)val('SELECT id FROM password_resets WHERE token_hash = ?', [hash('sha256', $tok)]));
t_eq('it opens the right login', (int)(password_reset_user($tok)['id'] ?? 0), $mid);
t_ok('a changed key opens nothing', password_reset_user(substr($tok, 0, 63) . (substr($tok, -1) === 'a' ? 'b' : 'a')) === null);
$link2 = password_reset_link($mid);
t_ok('asking again cancels the older link', password_reset_user($tok) === null && password_reset_user(substr($link2, strpos($link2, 't=') + 2)) !== null);
q('UPDATE password_resets SET expires_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE user_id = ?', [$mid]);
t_ok('after 30 minutes it is dead', password_reset_user(substr($link2, strpos($link2, 't=') + 2)) === null);

t_group('E-mail: a password change ends older logins');
$fp = pwd_fingerprint(password_hash('x', PASSWORD_DEFAULT));
t_ok('every new password gives a new fingerprint', $fp !== pwd_fingerprint(password_hash('x', PASSWORD_DEFAULT)));
t_eq('the same stored hash gives the same one', pwd_fingerprint('$2y$10$abc'), pwd_fingerprint('$2y$10$abc'));

t_group('E-mail: the message');
$sent = [];
$GLOBALS['mail_mock'] = function ($to, $subj, $head, $body) use (&$sent) { $sent[] = [$to, $subj, $head, $body]; return ''; };
t_ok('it goes', send_mail('a@example.com', 'Bill ₹500 ✓', '<p>Hello <b>there</b></p>', '', [['bill 1.pdf', '%PDF-test', 'application/pdf']]));
[$to, , $head, $body] = $sent[0];
t_ok('a non-English subject is encoded', strpos($head['Subject'], '=?UTF-8?B?') === 0);
t_ok('plain text and HTML both inside', strpos($body, 'text/plain') !== false && strpos($body, 'text/html') !== false);
t_ok('the PDF is attached, with a safe file name', strpos($body, 'filename="bill_1.pdf"') !== false && strpos($body, base64_encode('%PDF-test')) !== false);
t_ok('a bad address is refused before sending', !send_mail('not-an-email', 'x', 'y') && count($sent) === 1);
t_ok('a header cannot be slipped in through the subject', !send_mail('a@example.com', "Hi\r\nBcc: x@evil.in", 'y') && count($sent) === 1);
unset($GLOBALS['mail_mock']);
