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
