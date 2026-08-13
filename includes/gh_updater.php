<?php
// GitHub-based self-updater. Checks the configured repo/branch for a new
// commit and, on confirmation, downloads the full source as a zip and
// overwrites every app file with it - no FTP/File Manager needed. Then
// re-runs the same DB migration used by install/migrate.php so new
// tables/columns are added automatically. config.php is never part of the
// GitHub repo (it's gitignored) so it can never be touched by this; the
// uploads/ and updates/ folders (live shop data) are skipped defensively.

function update_history() {
    $hist = dirname(__DIR__) . '/updates/history.json';
    return file_exists($hist) ? array_reverse(json_decode(file_get_contents($hist), true) ?: []) : [];
}

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
    if (!function_exists('curl_init')) return [null, 0, 'The server does not have curl.'];
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
    if (!$cfg['repo']) return ['ok' => false, 'error' => 'Set the GitHub repo (owner/repo) first.'];
    $url = 'https://api.github.com/repos/' . $cfg['repo'] . '/commits/' . rawurlencode($cfg['branch']);
    list($resp, $code, $err) = gh_api_get($url, $cfg['token']);
    if ($resp === null || $code !== 200) {
        $reason = $code === 404 ? 'Repo/branch not found (check the name; a private repo needs a GitHub Token).'
                : (($code === 401 || $code === 403) ? 'Access denied - Token is wrong/missing.' : ('GitHub error (HTTP ' . $code . ') ' . $err));
        return ['ok' => false, 'error' => $reason];
    }
    $data = json_decode($resp, true);
    if (!$data || empty($data['sha'])) return ['ok' => false, 'error' => 'Could not understand the GitHub response.'];
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
/** Safety net taken immediately BEFORE an update overwrites anything:
 *  a database dump plus a copy of every application file that is about to
 *  be replaced. Kept in updates/restore/ (web-denied), newest 3 retained,
 *  so gh_rollback() can put the previous version back with one click. */
function gh_snapshot_before_update($sha) {
    $root = dirname(__DIR__);
    $stamp = date('Ymd_His') . '_' . substr($sha, 0, 7);
    $dir = $root . '/updates/restore/' . $stamp;
    if (!is_dir($dir . '/files')) mkdir($dir . '/files', 0755, true);

    // database first - if this fails the update does not proceed
    $pass = (string)setting('backup_passphrase', '');
    $sql = db_backup_sql();
    $dbFile = $dir . ($pass !== '' ? '/db.sql.enc' : '/db.sql.gz');
    $blob = $pass !== '' ? backup_encrypt($sql, $pass) : gzencode($sql, 6);
    if ($blob === '' || file_put_contents($dbFile, $blob) === false) return [false, 'Could not write the pre-update database backup.'];

    // then the current application files (skip runtime data + config)
    $copied = 0;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($it as $path) {
        $rel = str_replace('\\', '/', substr($path->getPathname(), strlen($root) + 1));
        if ($rel === '' || $rel === 'config.php') continue;
        if (preg_match('#^(uploads|updates|\.git)(/|$)#', $rel)) continue;
        if ($path->isDir()) { if (!is_dir($dir . '/files/' . $rel)) @mkdir($dir . '/files/' . $rel, 0755, true); continue; }
        $to = $dir . '/files/' . $rel;
        if (!is_dir(dirname($to))) @mkdir(dirname($to), 0755, true);
        if (@copy($path->getPathname(), $to)) $copied++;
    }
    file_put_contents($dir . '/meta.json', json_encode([
        'taken_at' => date('Y-m-d H:i:s'), 'before_sha' => setting('gh_last_sha', ''), 'updating_to' => $sha,
        'files' => $copied, 'db' => basename($dbFile), 'db_encrypted' => $pass !== '',
        'by' => current_user()['name'] ?? 'system', 'app_version' => defined('APP_VERSION') ? APP_VERSION : '',
    ], JSON_PRETTY_PRINT));

    // keep the newest 3 restore points
    $all = glob($root . '/updates/restore/*', GLOB_ONLYDIR);
    rsort($all);
    foreach (array_slice($all, 3) as $old) gh_rmtree($old);
    return [true, $stamp . " ($copied files)"];
}

function gh_rmtree($dir) {
    if (!is_dir($dir)) return;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $p) {
        $p->isDir() ? @rmdir($p->getPathname()) : @unlink($p->getPathname());
    }
    @rmdir($dir);
}

/** Restore points available for the rollback button, newest first. */
function gh_restore_points() {
    $out = [];
    foreach (array_reverse(glob(dirname(__DIR__) . '/updates/restore/*', GLOB_ONLYDIR) ?: []) as $d) {
        $meta = @json_decode(@file_get_contents($d . '/meta.json'), true) ?: [];
        $meta['id'] = basename($d);
        $out[] = $meta;
    }
    return $out;
}

/** Put the application files from a restore point back. The DATABASE is
 *  deliberately NOT auto-restored: bills entered since the update would be
 *  lost. The dump sits next to the files if a full restore is really
 *  wanted, and the message says so. */
function gh_rollback($id) {
    $id = basename((string)$id);
    $root = dirname(__DIR__);
    $dir = $root . '/updates/restore/' . $id;
    if ($id === '' || !is_dir($dir . '/files')) return [false, 'That restore point no longer exists.'];
    $restored = 0;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir . '/files', FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($it as $path) {
        if ($path->isDir()) continue;
        $rel = str_replace('\\', '/', substr($path->getPathname(), strlen($dir . '/files') + 1));
        $to = $root . '/' . $rel;
        if (!is_dir(dirname($to))) @mkdir(dirname($to), 0755, true);
        if (@copy($path->getPathname(), $to)) $restored++;
    }
    if (function_exists('opcache_reset')) opcache_reset();
    log_activity('gh_rollback', $id . " ($restored files)");
    $meta = @json_decode(@file_get_contents($dir . '/meta.json'), true) ?: [];
    return [true, "Rolled back to $id — $restored files restored. Database was NOT changed "
        . "(bills added since the update are safe). Its pre-update dump is kept at updates/restore/$id/" . ($meta['db'] ?? 'db.sql.gz') . '.'];
}

function gh_apply_update($sha) {
    if (!class_exists('ZipArchive')) return [false, 'The server does not have the PHP zip extension - ask your hosting to enable it.'];
    $cfg = gh_settings();
    if (!$cfg['repo'] || !$sha) return [false, 'Missing the repo or commit.'];

    // never overwrite live code without a way back
    list($snapOk, $snapMsg) = gh_snapshot_before_update($sha);
    if (!$snapOk) return [false, 'Update stopped before touching anything: ' . $snapMsg];

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
    if ($zip->open($tmpZip) !== true) { @unlink($tmpZip); return [false, 'Could not open the ZIP.']; }

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

    log_activity('gh_update_applied', substr($sha, 0, 7) . " ($applied files, restore point $snapMsg)");
    $dbMsg = $migration['totals']['failed'] ? (' — ' . $migration['totals']['failed'] . ' database error(s) (see history)') : ' + database update';
    return [true, 'Update ' . substr($sha, 0, 7) . ' applied — ' . $applied . ' files' . $dbMsg
        . '. 🛟 Backup taken first (' . $snapMsg . ') — use "Undo last update" below if anything looks wrong.'];
}
