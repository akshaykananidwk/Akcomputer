<?php
// Encrypted update package system (.akupd files)
//
// Package format:  "AKUPD1" + 16-byte IV + 32-byte HMAC + AES-256-CBC(zip)
// The zip contains changed files (paths relative to app root) plus
// manifest.json: {"version":"2.3.0","notes":"...","sql":"upgrade.sql"(optional)}
// Encryption key = the Update Key set in Settings (must match the key
// used while building the package with tools/make_update.php).

function akupd_keys($key) {
    $enc = hash('sha256', 'akupd-enc|' . $key, true);
    $mac = hash('sha256', 'akupd-mac|' . $key, true);
    return [$enc, $mac];
}

/** Build encrypted package bytes from a zip file (used by tools/make_update.php). */
function akupd_build($zipPath, $key) {
    list($enc, $mac) = akupd_keys($key);
    $iv = random_bytes(16);
    $cipher = openssl_encrypt(file_get_contents($zipPath), 'aes-256-cbc', $enc, OPENSSL_RAW_DATA, $iv);
    if ($cipher === false) return false;
    return 'AKUPD1' . $iv . hash_hmac('sha256', $iv . $cipher, $mac, true) . $cipher;
}

/**
 * Apply an uploaded .akupd package. Returns [bool ok, string message].
 */
function akupd_apply($file, $key) {
    if (!class_exists('ZipArchive')) return [false, 'Server માં PHP zip extension નથી - hosting provider ને કહો enable કરવા.'];
    $raw = file_get_contents($file);
    if ($raw === false || strlen($raw) < 6 + 16 + 32 + 16) return [false, 'File વાંચી શકાઈ નહીં અથવા ખાલી છે.'];
    if (substr($raw, 0, 6) !== 'AKUPD1') return [false, 'આ માન્ય .akupd update file નથી.'];

    $iv = substr($raw, 6, 16);
    $hmac = substr($raw, 22, 32);
    $cipher = substr($raw, 54);
    list($enc, $mac) = akupd_keys($key);
    if (!hash_equals(hash_hmac('sha256', $iv . $cipher, $mac, true), $hmac)) {
        return [false, 'Update Key ખોટી છે અથવા file બગડેલી છે (signature fail).'];
    }
    $zipData = openssl_decrypt($cipher, 'aes-256-cbc', $enc, OPENSSL_RAW_DATA, $iv);
    if ($zipData === false) return [false, 'Decrypt fail - Update Key ચકાસો.'];

    $root = dirname(__DIR__);
    $tmpZip = tempnam(sys_get_temp_dir(), 'akupd') . '.zip';
    file_put_contents($tmpZip, $zipData);

    $zip = new ZipArchive();
    if ($zip->open($tmpZip) !== true) { @unlink($tmpZip); return [false, 'Package અંદરથી ખૂલી નહીં.']; }

    $manifestRaw = $zip->getFromName('manifest.json');
    $manifest = $manifestRaw ? json_decode($manifestRaw, true) : null;
    if (!$manifest || empty($manifest['version'])) { $zip->close(); @unlink($tmpZip); return [false, 'manifest.json ખૂટે છે.']; }

    // extract files (never allow escaping the app root, never touch config.php/uploads)
    $applied = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if (substr($name, -1) === '/') continue;                      // directory entry
        if ($name === 'manifest.json') continue;
        $clean = str_replace('\\', '/', $name);
        if (strpos($clean, '..') !== false || $clean[0] === '/') continue;    // path traversal guard
        if ($clean === 'config.php' || strpos($clean, 'uploads/') === 0) continue;
        if (!empty($manifest['sql']) && $clean === $manifest['sql']) continue; // migration runs, not extracted
        $dest = $root . '/' . $clean;
        if (!is_dir(dirname($dest))) mkdir(dirname($dest), 0755, true);
        file_put_contents($dest, $zip->getFromIndex($i));
        $applied++;
    }

    // optional SQL migration bundled in the package
    $sqlMsg = '';
    if (!empty($manifest['sql'])) {
        $sqlRaw = $zip->getFromName($manifest['sql']);
        if ($sqlRaw) {
            foreach (array_filter(array_map('trim', preg_split('/;\s*\n/', $sqlRaw))) as $stmt) {
                try { db()->exec($stmt); } catch (Exception $e) { $sqlMsg .= ' [SQL warn: ' . $e->getMessage() . ']'; }
            }
            $sqlMsg = ' + database migration' . $sqlMsg;
        }
    }
    $zip->close();
    @unlink($tmpZip);

    // record history
    $histDir = $root . '/updates';
    if (!is_dir($histDir)) mkdir($histDir, 0755, true);
    $hist = $histDir . '/history.json';
    $log = file_exists($hist) ? (json_decode(file_get_contents($hist), true) ?: []) : [];
    $log[] = ['version' => $manifest['version'], 'notes' => $manifest['notes'] ?? '',
              'files' => $applied, 'applied_at' => date('Y-m-d H:i:s'),
              'by' => current_user()['name'] ?? 'system'];
    file_put_contents($hist, json_encode($log, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    set_setting('app_version', $manifest['version']);
    log_activity('update_applied', 'v' . $manifest['version'] . " ($applied files)");
    return [true, 'Update v' . $manifest['version'] . ' લાગી ગયું — ' . $applied . ' files' . $sqlMsg . '.'];
}

function update_history() {
    $hist = dirname(__DIR__) . '/updates/history.json';
    return file_exists($hist) ? array_reverse(json_decode(file_get_contents($hist), true) ?: []) : [];
}
