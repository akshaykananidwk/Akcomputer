<?php
// 📧 Supplier bills that arrived by e-mail. "Check e-mail" reads the shop's
// mailbox (Connections → E-mail); each attached PDF/photo opens in Scan Bill,
// where a person checks every line before the purchase is saved.
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/connect.php';
require_perm('purchases.add');
$dir = up_dir('purchase_scans');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'check') {
    if (!api_rate_ok('mailcheck', 10, 600)) { flash('Checked a moment ago — wait a few minutes.', 'error'); redirect('mail_bills.php'); }
    [$n, $msg] = mail_fetch_bills();
    log_activity('mail_bills_check', $msg);
    flash($msg, $n ? 'success' : 'info');
    redirect('mail_bills.php');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'done' && preg_match('/^mail_\w+\.\w+$/', (string)post('f'))) {
    @rename("$dir/" . post('f') . '.json', "$dir/" . post('f') . '.done');
    redirect('mail_bills.php');
}
$files = [];
foreach (glob("$dir/mail_*.json") ?: [] as $j) $files[] = ['file' => basename($j, '.json')] + (json_decode((string)file_get_contents($j), true) ?: []);
usort($files, fn($a, $b) => strcmp($b['file'], $a['file']));
$page_title = 'Bills by e-mail';
include __DIR__ . '/includes/header.php';
?>
<div class="page-head"><h1>📧 Bills by e-mail</h1>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="check"><button class="btn">🔄 Check e-mail now</button></form></div>
<?php if (setting('imap_host', '') === ''): ?><div class="card">Set up the mailbox first in <a href="connections.php#mail">Connections → E-mail</a>.</div><?php endif; ?>
<div class="pane"><div class="pane-body tight"><table class="rowlist"><tbody>
<?php foreach ($files as $f): ?><tr><td data-l="From"><b><?= e($f['from'] ?? '') ?></b><br><small class="muted"><?= e($f['subject'] ?? '') ?> · <?= e($f['name'] ?? '') ?> · <?= e($f['at'] ?? '') ?></small></td>
  <td class="act"><a class="btn btn-sm" href="purchase_scan.php?mail=<?= e($f['file']) ?>">📄 Read this bill</a>
    <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="do" value="done"><input type="hidden" name="f" value="<?= e($f['file']) ?>"><button class="btn btn-sm btn-outline">Done</button></form></td></tr>
<?php endforeach; if (!$files): ?><tr><td class="muted">No bills waiting.</td></tr><?php endif; ?></tbody></table></div></div>
<?php include __DIR__ . '/includes/footer.php'; ?>
