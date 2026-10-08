<?php
// 👆 Fingerprint / face login (WebAuthn "passkeys"). The phone keeps a
// private key that never leaves it; we keep only the public key and check
// each login's signature with it. Nothing about the finger or face ever
// reaches this server. Supports the two key kinds phones and laptops use:
// ES256 (P-256) and RS256.

function wa_b64u($s) { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function wa_unb64u($s) { return (string)base64_decode(strtr((string)$s, '-_', '+/') . str_repeat('=', (4 - strlen((string)$s) % 4) % 4)); }
function wa_rp_id() { return request_host(); }
function wa_origin() {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    return ($https ? 'https://' : 'http://') . strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
}

/** A small CBOR reader - just what WebAuthn sends (ints, byte/text strings, arrays, maps, simple values). */
function wa_cbor($data, &$pos = 0) {
    $b = ord($data[$pos++]); $major = $b >> 5; $info = $b & 31;
    if ($info < 24) $len = $info;
    elseif ($info === 24) $len = ord($data[$pos++]);
    elseif ($info === 25) { $len = unpack('n', substr($data, $pos, 2))[1]; $pos += 2; }
    elseif ($info === 26) { $len = unpack('N', substr($data, $pos, 4))[1]; $pos += 4; }
    elseif ($info === 27) { $len = unpack('J', substr($data, $pos, 8))[1]; $pos += 8; }
    else throw new Exception('cbor: unsupported length');
    switch ($major) {
        case 0: return $len;
        case 1: return -1 - $len;
        case 2: case 3: $v = substr($data, $pos, $len); $pos += $len; return $v;
        case 4: $a = []; for ($i = 0; $i < $len; $i++) $a[] = wa_cbor($data, $pos); return $a;
        case 5: $m = []; for ($i = 0; $i < $len; $i++) { $k = wa_cbor($data, $pos); $m[$k] = wa_cbor($data, $pos); } return $m;
        case 7: return $info === 20 ? false : ($info === 21 ? true : null);
    }
    throw new Exception('cbor: unsupported type');
}

function wa_der_len($n) { return $n < 128 ? chr($n) : ($n < 256 ? "\x81" . chr($n) : "\x82" . pack('n', $n)); }
function wa_der($tag, $body) { return chr($tag) . wa_der_len(strlen($body)) . $body; }
function wa_der_int($bytes) { $bytes = ltrim($bytes, "\x00"); if ($bytes === '' || ord($bytes[0]) > 127) $bytes = "\x00" . $bytes; return wa_der(0x02, $bytes); }

/** COSE public key (from the authenticator) -> PEM that openssl can verify with. */
function wa_cose_to_pem(array $k) {
    if (($k[1] ?? null) === 2 && ($k[3] ?? null) === -7 && ($k[-1] ?? null) === 1 && strlen($k[-2] ?? '') === 32 && strlen($k[-3] ?? '') === 32) {
        $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . "\x04" . $k[-2] . $k[-3];
    } elseif (($k[1] ?? null) === 3 && ($k[3] ?? null) === -257 && isset($k[-1], $k[-2])) {
        $rsa = wa_der(0x30, wa_der_int($k[-1]) . wa_der_int($k[-2]));
        $der = wa_der(0x30, wa_der(0x30, hex2bin('06092a864886f70d0101010500')) . wa_der(0x03, "\x00" . $rsa));
    } else throw new Exception('This kind of key is not supported.');
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

/** Check clientDataJSON: right ceremony, our challenge, our address. Returns its SHA-256. */
function wa_check_client($clientJson, $type, $challenge) {
    $c = json_decode($clientJson, true);
    if (!is_array($c) || ($c['type'] ?? '') !== $type) throw new Exception('Wrong request.');
    if (!hash_equals(wa_b64u($challenge), (string)($c['challenge'] ?? ''))) throw new Exception('The request has expired. Try again.');
    if (!hash_equals(wa_origin(), strtolower((string)($c['origin'] ?? '')))) throw new Exception('Wrong address.');
    return hash('sha256', $clientJson, true);
}

/** Registration: returns ['cred_id' => b64u, 'pem' => ..., 'count' => n]. */
function wa_register_verify($attObjB64, $clientB64, $challenge) {
    wa_check_client(wa_unb64u($clientB64), 'webauthn.create', $challenge);
    $att = wa_cbor(wa_unb64u($attObjB64));
    $ad = $att['authData'] ?? '';
    if (strlen($ad) < 55 || !hash_equals(hash('sha256', wa_rp_id(), true), substr($ad, 0, 32))) throw new Exception('Wrong site.');
    $flags = ord($ad[32]);
    if (!($flags & 0x01) || !($flags & 0x40)) throw new Exception('The phone did not confirm it was you.');
    $count = unpack('N', substr($ad, 33, 4))[1];
    $idLen = unpack('n', substr($ad, 53, 2))[1];
    $credId = substr($ad, 55, $idLen);
    $pos = 0; $cose = wa_cbor(substr($ad, 55 + $idLen), $pos);
    return ['cred_id' => wa_b64u($credId), 'pem' => wa_cose_to_pem($cose), 'count' => $count];
}

/** Login: checks the signature with the stored key. Returns the new signature count. */
function wa_login_verify(array $cred, $authDataB64, $clientB64, $sigB64, $challenge) {
    $clientHash = wa_check_client(wa_unb64u($clientB64), 'webauthn.get', $challenge);
    $ad = wa_unb64u($authDataB64);
    if (strlen($ad) < 37 || !hash_equals(hash('sha256', wa_rp_id(), true), substr($ad, 0, 32))) throw new Exception('Wrong site.');
    if (!(ord($ad[32]) & 0x01)) throw new Exception('The phone did not confirm it was you.');
    if (openssl_verify($ad . $clientHash, wa_unb64u($sigB64), $cred['public_key'], OPENSSL_ALGO_SHA256) !== 1) throw new Exception('The fingerprint check did not match.');
    $count = unpack('N', substr($ad, 33, 4))[1];
    if ($count > 0 && $count <= (int)$cred['sign_count']) throw new Exception('This key looks copied. Remove it and add it again.');
    return $count;
}
