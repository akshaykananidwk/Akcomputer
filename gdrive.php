<?php
// ☁️ Google sends the owner back here after "Allow" (see includes/gdrive.php).
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/gdrive.php';
require_perm('settings.edit');
if (!is_full_admin()) { flash('Only the owner can connect Google Drive.', 'error'); redirect('settings.php?cat=backup'); }
if (get('go')) redirect(gdrive_auth_url());
if (get('code') !== '' && portal_ok('gdrive', current_user()['id'], get('state'))) {
    $why = gdrive_connect(get('code'));
    log_activity('gdrive_connect', $why === '' ? 'ok' : $why);
    flash($why === '' ? '☁️ Google Drive connected — the daily encrypted backup will be copied there.' : $why, $why === '' ? 'success' : 'error');
} elseif (get('error')) flash('Google Drive was not connected (' . e(get('error')) . ').', 'error');
redirect('settings.php?cat=backup');
