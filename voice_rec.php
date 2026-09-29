<?php
// Listen to what a caller said.
//
// The recording lives on Vobiz and its URL needs the account's Auth ID and
// token. A browser sends neither, so linking straight to it handed the owner
// {"error":{"code":401,...}} instead of audio - a link that could never have
// worked, from a page that looked like it did.
//
// So the file comes through here instead: the staff session is checked, the
// fetch is made server-side with the headers, and the audio is streamed back.
// The first fetch also keeps a copy, because a customer's own words are
// evidence in a dispute and are worth more than a link to somebody else's
// server that may expire, move, or start costing money.
//
// The copy is written to a folder Apache is told to refuse, and is only ever
// served through this page. The deny file cannot be the only guard - it does
// nothing under a server that ignores .htaccess - so the file name carries
// forty-eight bits out of the call's own secret token as well. Guessing it
// is not a thing anybody does by accident.
//
// The recordings folder cannot simply be covered by a blanket "no mp3" rule
// in uploads/.htaccess either: the spoken menu lives under uploads/voice/tts
// and Vobiz has to be able to fetch every file in it.
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/voice.php';
require_perm('payments.view');

$call = row('SELECT * FROM voice_calls WHERE id = ?', [(int)get('id')]);
if (!$call || !$call['recording_url']) { http_response_code(404); die('No recording for this call.'); }

$dir = __DIR__ . '/uploads/voice/rec';
if (!is_dir($dir)) mkdir($dir, 0755, true);
// Apache must refuse this folder directly; everything here is served by the
// page above, after a permission check.
if (!is_file($dir . '/.htaccess')) file_put_contents($dir . '/.htaccess', "Require all denied\n");

$local = $dir . '/call_' . (int)$call['id'] . '_' . substr(hash('sha256', $call['token']), 0, 12) . '.mp3';

if (!is_file($local) || filesize($local) < 512) {
    if (!function_exists('curl_init')) { http_response_code(500); die('The server does not have curl.'); }
    $ch = curl_init($call['recording_url']);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 60,
        // The same headers every other Vobiz request carries. Harmless if the
        // recording turns out to sit on plain storage that ignores them.
        CURLOPT_HTTPHEADER => ['X-Auth-ID: ' . setting('vobiz_auth_id', ''),
                               'X-Auth-Token: ' . setting('vobiz_auth_token', '')],
    ]);
    $audio = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);

    if ($audio === false || $code < 200 || $code >= 300 || strlen((string)$audio) < 512) {
        log_activity('voice_rec_fail', 'call ' . (int)$call['id'] . ' http ' . $code . ' ' . $cerr);
        http_response_code(502);
        die('The recording could not be fetched from the provider (HTTP ' . $code . ').'
          . ($cerr ? ' ' . e($cerr) : '')
          . ' It may not be ready yet — try again in a minute.');
    }
    file_put_contents($local, $audio);
    log_activity('voice_rec', 'call ' . (int)$call['id'] . ' saved ' . strlen($audio) . ' bytes');
}

header('Content-Type: audio/mpeg');
header('Content-Length: ' . filesize($local));
header('Content-Disposition: inline; filename="call_' . (int)$call['id'] . '.mp3"');
header('Cache-Control: private, max-age=3600');
readfile($local);
