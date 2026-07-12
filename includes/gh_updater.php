<?php
// GitHub-based self-updater. Checks the configured repo/branch for a new
// commit and, on confirmation, downloads the full source as a zip and
// overwrites every app file with it - no FTP/File Manager needed. Then
// re-runs the same DB migration used by install/migrate.php so new
// tables/columns are added automatically. config.php is never part of the
// GitHub repo (it's gitignored) so it can never be touched by this; the
// uploads/ and updates/ folders (live shop data) are skipped defensively.

function gh_settings() {
    return [
        'repo' => trim(setting('gh_repo')),
        'branch' => trim(setting('gh_branch')) ?: 'main',
        'token' => vault_decrypt(setting('gh_token_enc')),
    ];
}

function gh_save_settings($repo, $branch, $token) {
    set_setting('gh_repo', trim($repo));
    set_setting('gh_branch', trim($branch) ?: 'main');
    if ($token !== '') set_setting('gh_token_enc', vault_encrypt($token)); // blank = keep existing token
}

function gh_api_get($url, $token) {
    if (!function_exists('curl_init')) return [null, 0, 'Server માં curl નથી.'];
    $ch = curl_init($url);
    $headers = ['User-Agent: AKComputer-Updater', 'Accept: application/vnd.github+json'];
    if ($token) $headers[] = 'Authorization: Bearer ' . $token;
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return [$resp, $code, $err];
}

/** Checks the latest commit on the configured branch vs the last one we applied. */
function gh_check_update() {
    $cfg = gh_settings();
    if (!$cfg['repo']) return ['ok' => false, 'error' => 'પહેલા GitHub repo (owner/repo) set કરો.'];
    $url = 'https://api.github.com/repos/' . $cfg['repo'] . '/commits/' . rawurlencode($cfg['branch']);
    list($resp, $code, $err) = gh_api_get($url, $cfg['token']);
    if ($resp === null || $code !== 200) {
        $reason = $code === 404 ? 'Repo/branch ના મળ્યા (નામ ચેક કરો; repo private હોય તો GitHub Token જોઈએ).'
                : (($code === 401 || $code === 403) ? 'Access denied - Token ખોટો/ખૂટે છે.' : ('GitHub error (HTTP ' . $code . ') ' . $err));
        return ['ok' => false, 'error' => $reason];
    }
    $data = json_decode($resp, true);
    if (!$data || empty($data['sha'])) return ['ok' => false, 'error' => 'GitHub response સમજાયો નહીં.'];
    $sha = $data['sha'];
    $current = setting('gh_last_sha');
    return [
        'ok' => true, 'sha' => $sha, 'short' => substr($sha, 0, 7),
        'message' => explode("\n", trim($data['commit']['message'] ?? ''))[0],
        'date' => $data['commit']['author']['date'] ?? '', 'author' => $data['commit']['author']['name'] ?? '',
        'current_sha' => $current, 'current_short' => $current ? substr($current, 0, 7) : '',
        'has_update' => ($current !== $sha),
    ];
}

/** Downloads + applies the given commit sha: overwrites app files, then re-runs DB migration. */
function gh_apply_update($sha) {
    if (!class_exists('ZipArchive')) return [false, 'Server માં PHP zip extension નથી - hosting ને enable કરવા કહો.'];
    $cfg = gh_settings();
    if (!$cfg['repo'] || !$sha) return [false, 'Repo અથવા commit ખૂટે છે.'];

    $url = 'https://api.github.com/repos/' . $cfg['repo'] . '/zipball/' . $sha;
    $headers = ['User-Agent: AKComputer-Updater', 'Accept: application/vnd.github+json'];
    if ($cfg['token']) $headers[] = 'Authorization: Bearer ' . $cfg['token'];
    $tmpZip = tempnam(sys_get_temp_dir(), 'ghupd') . '.zip';
    $fp = fopen($tmpZip, 'wb');
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => $headers, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => 120, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_FILE => $fp,
    ]);
    $ok = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    fclose($fp);
    if (!$ok || $code !== 200 || filesize($tmpZip) < 100) {
        @unlink($tmpZip);
        return [false, 'Download fail (HTTP ' . $code . ') ' . $err];
    }

    $zip = new ZipArchive();
    if ($zip->open($tmpZip) !== true) { @unlink($tmpZip); return [false, 'ZIP ખૂલી નહીં.']; }

    // GitHub zipballs wrap everything in one top-level "owner-repo-sha/" folder.
    $prefix = '';
    if ($zip->numFiles > 0) {
        $first = $zip->getNameIndex(0);
        $slash = strpos($first, '/');
        if ($slash !== false) $prefix = substr($first, 0, $slash + 1);
    }

    $root = dirname(__DIR__);
    $applied = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if (substr($name, -1) === '/') continue; // directory entry
        $rel = ($prefix && strpos($name, $prefix) === 0) ? substr($name, strlen($prefix)) : $name;
        if ($rel === '') continue;
        $rel = str_replace('\\', '/', $rel);
        if (strpos($rel, '..') !== false || ($rel[0] ?? '') === '/') continue; // path traversal guard
        // config.php is never in the repo (gitignored) - skipped defensively
        // anyway; uploads/ and updates/ hold live shop data, never touched.
        if ($rel === 'config.php' || strpos($rel, 'uploads/') === 0 || strpos($rel, 'updates/') === 0) continue;
        $dest = $root . '/' . $rel;
        if (!is_dir(dirname($dest))) mkdir(dirname($dest), 0755, true);
        file_put_contents($dest, $zip->getFromIndex($i));
        $applied++;
    }
    $zip->close();
    @unlink($tmpZip);

    // Files on disk just changed mid-request - clear OPcache immediately so
    // this and every future request compiles the new code, not whatever was
    // cached before the overwrite (see install/migrate.php for the same
    // lesson learned the hard way: reset must happen before anything below
    // re-requires the files that were just replaced).
    if (function_exists('opcache_reset')) opcache_reset();

    require_once __DIR__ . '/dbmigrate.php';
    $migration = run_all_migrations();

    set_setting('gh_last_sha', $sha);
    set_setting('app_version', substr($sha, 0, 7));

    $histDir = $root . '/updates';
    if (!is_dir($histDir)) mkdir($histDir, 0755, true);
    $hist = $histDir . '/history.json';
    $log = file_exists($hist) ? (json_decode(file_get_contents($hist), true) ?: []) : [];
    $log[] = ['version' => 'gh:' . substr($sha, 0, 7), 'notes' => 'GitHub auto-update',
              'files' => $applied, 'applied_at' => date('Y-m-d H:i:s'),
              'by' => current_user()['name'] ?? 'system'];
    file_put_contents($hist, json_encode($log, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    log_activity('gh_update_applied', substr($sha, 0, 7) . " ($applied files)");
    $dbMsg = $migration['totals']['failed'] ? (' — database માં ' . $migration['totals']['failed'] . ' error (history માં જુઓ)') : ' + database update';
    return [true, 'Update ' . substr($sha, 0, 7) . ' લાગી ગયું — ' . $applied . ' files' . $dbMsg . '.'];
}
