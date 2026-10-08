<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); die('CLI only'); }
// How amounts and words are shown. Display only - nothing here may change a
// stored amount - but a wrong comma or a wrong word on a bill is a wrong bill.

t_group('Display: amounts in the Indian way (lakh, crore)');
t_eq('below a thousand', money(999.5), '999.50');
t_eq('thousands', money(1234), '1,234.00');
t_eq('a lakh', money(123456.78), '1,23,456.78');
t_eq('ten lakh', money(1234567), '12,34,567.00');
t_eq('a crore', money(123456789.1), '12,34,56,789.10');
t_eq('negative', money(-123456), '-1,23,456.00');
t_eq('rounding keeps two places', money(1999.999), '2,000.00');
t_eq('nothing', money(0), '0.00');
t_eq('minus nothing is just nothing', money(-0.001), '0.00');

t_group('Display: amount in words');
t_eq('rupees and paise', amount_in_words(2868.58), 'Two Thousand Eight Hundred Sixty Eight Rupees and Fifty Eight Paise Only');
t_eq('lakh', amount_in_words(150000), 'One Lakh Fifty Thousand Rupees Only');

t_group('Display: Gujarati / Hindi words on the screen');
$bad = [];
foreach (i18n_phrases() as $en => [$gu, $hi]) {
    if (!preg_match('/[\x{0A80}-\x{0AFF}]/u', $gu)) $bad[] = "gu: $en";
    if (!preg_match('/[\x{0900}-\x{097F}]/u', $hi)) $bad[] = "hi: $en";
}
t_eq('every phrase has a real Gujarati and Hindi version', $bad, []);
t_ok('English has no dictionary', i18n_dict('en') === []);
t_ok('Gujarati menu word', (i18n_dict('gu')['Dashboard'] ?? '') === 'ડેશબોર્ડ');

t_group('Ease: errors in plain words');
t_eq('our own message passes as it is', plain_error(new Exception('Not enough stock for Mouse')), 'Not enough stock for Mouse');
t_ok('a duplicate becomes "already there"', strpos(plain_error(new PDOException("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'x' for key 'name'")), 'already there') !== false);
t_ok('a lock wait says to press Save again', strpos(plain_error(new PDOException('SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded')), 'Save again') !== false);
t_ok('an unknown database error never shows SQL', strpos(plain_error(new PDOException('SQLSTATE[42000]: Syntax error near SELECT')), 'SQL') === false);

t_group('Ease: help answers the same question the same way');
require_once dirname(__DIR__) . '/includes/help.php';
$h = help_search('how to send bill on whatsapp');
t_ok('a WhatsApp question finds the WhatsApp answer first', $h && strpos($h[0][1], 'WhatsApp') !== false);
t_eq('...every time', help_search('how to send bill on whatsapp'), $h);
t_ok('Gujarati words find it too', ($g = help_search('ખર્ચ કેવી રીતે')) && strpos($g[0][1], 'Expenses') !== false);
[$ans, $src] = help_answer('how do I add staff login', 'gu');
t_ok('a clear match is answered from the guide, in Gujarati, without AI', $src === 'faq' && preg_match('/[\x{0A80}-\x{0AFF}]/u', $ans));

t_group('Hardware: the small receipt');
require_once dirname(__DIR__) . '/includes/receipt.php';
$rp = t_party('Receipt Test'); $rs = t_sale($rp, 123456.5, 100000);
$lines = receipt_lines($rs, 32);
t_ok('every line fits 58 mm paper (32 characters)', $lines && max(array_map('strlen', $lines)) <= 32);
t_ok('plain letters only, so any printer can print it', !array_filter($lines, fn($l) => preg_match('/[^\x20-\x7E]/', $l)));
t_ok('the total and the balance are on it', (bool)array_filter($lines, fn($l) => strpos($l, 'TOTAL') === 0 && strpos($l, '1,23,456.50') !== false)
     && (bool)array_filter($lines, fn($l) => strpos($l, 'Balance due') === 0 && strpos($l, '23,456.50') !== false));
t_ok('words are not cut in half', !array_filter(explode(' ', amount_in_words(123456.5)), fn($w) => !array_filter($lines, fn($l) => preg_match('/\\b' . $w . '\\b/', $l))));
t_ok('80 mm paper takes 48', max(array_map('strlen', receipt_lines($rs, 48))) <= 48);
