<?php
// Customer Sites (multi-site per customer) + DVR/NVR remote access vault.
// Passwords are stored AES-256 encrypted (vault_encrypt/vault_decrypt in
// helpers.php) - never in plain text in the database.
require_once __DIR__ . '/includes/init.php';
require_perm('sites.view');
$u = current_user();
$action = get('action', 'list');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'save') {
    require_perm(post('id') ? 'sites.edit' : 'sites.add');
    $id = (int)post('id');
    $data = [(int)post('party_id'), post('name'), post('address'), post('city'), post('device_type'),
              post('ip_address'), post('port'), post('dvr_username'), vault_encrypt(post('dvr_password')),
              post('remote_app'), post('remote_id'), post('install_date') ?: null, post('warranty_till') ?: null,
              post('notes'), post('is_active') ? 1 : 0];
    if (!$data[0] || !$data[1]) { flash('Party અને Site name જરૂરી છે.', 'error'); redirect('sites.php?action=' . ($id ? "edit&id=$id" : 'new')); }
    if ($id) {
        // keep existing password if the field was left blank (edit form shows a placeholder, not the real value)
        if (post('dvr_password') === '') {
            $data[8] = row('SELECT dvr_password_enc FROM sites WHERE id = ?', [$id])['dvr_password_enc'] ?? '';
        }
        q('UPDATE sites SET party_id=?, name=?, address=?, city=?, device_type=?, ip_address=?, port=?, dvr_username=?,
           dvr_password_enc=?, remote_app=?, remote_id=?, install_date=?, warranty_till=?, notes=?, is_active=? WHERE id=?',
          array_merge($data, [$id]));
        flash('Site updated.');
    } else {
        q('INSERT INTO sites (party_id, name, address, city, device_type, ip_address, port, dvr_username,
           dvr_password_enc, remote_app, remote_id, install_date, warranty_till, notes, is_active, created_by)
           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', array_merge($data, [$u['id']]));
        flash('Site added.');
    }
    log_activity('site_save', post('name'));
    redirect('sites.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'delete') {
    require_perm('sites.delete');
    q('DELETE FROM sites WHERE id = ?', [(int)post('id')]);
    flash('Site deleted.');
    redirect('sites.php');
}

// AJAX-style reveal of one site's decrypted password (still requires login + sites.view, POST + CSRF)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'reveal') {
    header('Content-Type: application/json');
    $s = row('SELECT dvr_password_enc FROM sites WHERE id = ?', [(int)post('id')]);
    echo json_encode(['password' => $s ? vault_decrypt($s['dvr_password_enc']) : '']);
    exit;
}

$parties = all("SELECT id, name, mobile FROM parties WHERE is_active = 1 ORDER BY name");

if ($action === 'new' || $action === 'edit') {
    require_perm($action === 'edit' ? 'sites.edit' : 'sites.add');
    $s = $action === 'edit' ? row('SELECT * FROM sites WHERE id = ?', [(int)get('id')]) : null;
    if ($action === 'edit' && !$s) die('Site not found.');
    $presetParty = (int)get('party_id');
    $page_title = $s ? 'Edit Site' : 'New Site';
    include __DIR__ . '/includes/header.php';
    ?>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="save">
      <?php if ($s): ?><input type="hidden" name="id" value="<?= $s['id'] ?>"><?php endif; ?>
      <div class="card">
        <div class="form-row cols-2">
          <div><label>Customer / Party *</label>
            <select name="party_id" required>
              <option value="">-- select --</option>
              <?php foreach ($parties as $p): ?>
              <option value="<?= $p['id'] ?>" <?= ($s['party_id'] ?? $presetParty) == $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option>
              <?php endforeach; ?>
            </select></div>
          <div><label>Site name *</label><input type="text" name="name" value="<?= e($s['name'] ?? '') ?>" placeholder="દા.ત. Shop - Dwarka Main Road" required></div>
        </div>
        <div class="form-row cols-2">
          <div><label>Address</label><input type="text" name="address" value="<?= e($s['address'] ?? '') ?>"></div>
          <div><label>City</label><input type="text" name="city" value="<?= e($s['city'] ?? '') ?>"></div>
        </div>
        <h3 class="mt">🎥 DVR / NVR Remote Access</h3>
        <div class="form-row cols-4">
          <div><label>Device Type</label><input type="text" name="device_type" value="<?= e($s['device_type'] ?? '') ?>" placeholder="DVR / NVR"></div>
          <div><label>IP Address</label><input type="text" name="ip_address" value="<?= e($s['ip_address'] ?? '') ?>" placeholder="192.168.1.100"></div>
          <div><label>Port</label><input type="text" name="port" value="<?= e($s['port'] ?? '') ?>" placeholder="80"></div>
          <div><label>Username</label><input type="text" name="dvr_username" value="<?= e($s['dvr_username'] ?? '') ?>"></div>
        </div>
        <div class="form-row cols-3">
          <div><label>Password <?= $s ? '(ખાલી છોડો = બદલવું નથી)' : '' ?></label><input type="password" name="dvr_password" autocomplete="new-password" placeholder="<?= $s ? '••••••••' : '' ?>"></div>
          <div><label>Remote App (CMS/Cloud)</label><input type="text" name="remote_app" value="<?= e($s['remote_app'] ?? '') ?>" placeholder="દા.ત. V380, XMEYE, Hik-Connect"></div>
          <div><label>Remote / Cloud ID</label><input type="text" name="remote_id" value="<?= e($s['remote_id'] ?? '') ?>"></div>
        </div>
        <div class="form-row cols-3">
          <div><label>Install date</label><input type="date" name="install_date" value="<?= e($s['install_date'] ?? '') ?>"></div>
          <div><label>Warranty till</label><input type="date" name="warranty_till" value="<?= e($s['warranty_till'] ?? '') ?>"></div>
          <div><label class="check-inline mt"><input type="checkbox" name="is_active" value="1" <?= ($s === null || $s['is_active']) ? 'checked' : '' ?>> Active</label></div>
        </div>
        <div class="field"><label>Notes</label><input type="text" name="notes" value="<?= e($s['notes'] ?? '') ?>"></div>
        <button class="btn btn-block mt" type="submit">💾 Save Site</button>
      </div>
    </form>
    <?php
    include __DIR__ . '/includes/footer.php';
    exit;
}

// ---------- list ----------
$partyFilter = (int)get('party_id');
$where = ' 1=1 '; $params = [];
if ($partyFilter) { $where = ' s.party_id = ? '; $params[] = $partyFilter; }
$sites = all("SELECT s.*, p.name party_name, p.mobile party_mobile FROM sites s
              JOIN parties p ON p.id = s.party_id WHERE $where ORDER BY p.name, s.name", $params);
$page_title = 'Customer Sites / DVR-NVR Vault';
include __DIR__ . '/includes/header.php';
?>
<div class="page-actions">
  <?php if (can('sites.add')): ?><a class="btn" href="sites.php?action=new<?= $partyFilter ? '&party_id=' . $partyFilter : '' ?>">+ New Site</a><?php endif; ?>
  <?php if ($partyFilter): ?><a class="btn btn-outline" href="sites.php">બધા sites જુઓ</a><?php endif; ?>
</div>
<div class="searchbox"><input type="text" id="sFilter" placeholder="🔍 Search sites/customer..."></div>
<div class="table-wrap">
<table id="sTable">
  <thead><tr><th>Site</th><th>Customer</th><th>Device</th><th>IP:Port</th><th>Login</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($sites as $s): ?>
    <tr>
      <td><strong><?= e($s['name']) ?></strong><?= !$s['is_active'] ? ' <span class="badge badge-bad">Inactive</span>' : '' ?><br><span class="muted"><?= e($s['address']) ?><?= $s['city'] ? ', ' . e($s['city']) : '' ?></span></td>
      <td><?= e($s['party_name']) ?><br><span class="muted"><?= e($s['party_mobile']) ?></span></td>
      <td><?= e($s['device_type']) ?><?= $s['remote_app'] ? '<br><span class="muted">' . e($s['remote_app']) . ($s['remote_id'] ? ': ' . e($s['remote_id']) : '') . '</span>' : '' ?></td>
      <td><?= e($s['ip_address']) ?><?= $s['port'] ? ':' . e($s['port']) : '' ?></td>
      <td>
        <?php if ($s['dvr_username'] || $s['dvr_password_enc']): ?>
        <?= e($s['dvr_username']) ?><br>
        <span class="site-pw" data-id="<?= $s['id'] ?>"><code>••••••••</code> <a href="#" onclick="revealPw(this,<?= $s['id'] ?>);return false" class="muted">show</a></span>
        <?php else: ?><span class="muted">-</span><?php endif; ?>
      </td>
      <td style="white-space:nowrap">
        <?php if (can('sites.edit')): ?><a class="btn btn-sm btn-outline" href="sites.php?action=edit&id=<?= $s['id'] ?>">Edit</a><?php endif; ?>
        <?php if (can('sites.delete')): ?><form method="post" style="display:inline" onsubmit="return confirm('Site delete કરવી?')"><?= csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= $s['id'] ?>"><button class="btn btn-sm btn-danger" type="submit">✕</button></form><?php endif; ?>
      </td>
    </tr>
  <?php endforeach; if (!$sites): ?><tr><td colspan="6" class="muted">કોઈ site નથી. "+ New Site" દબાવીને CCTV/DVR site ઉમેરો.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
<script>
  var sFilter = document.getElementById('sFilter');
  if (sFilter) sFilter.addEventListener('input', function () {
    var q = this.value.toLowerCase();
    document.querySelectorAll('#sTable tbody tr').forEach(function (tr) {
      tr.style.display = tr.textContent.toLowerCase().indexOf(q) > -1 ? '' : 'none';
    });
  });
  var csrfTok = <?= json_encode(csrf_token()) ?>;
  function revealPw(link, id) {
    var box = link.closest('.site-pw');
    var fd = new FormData();
    fd.append('csrf', csrfTok);
    fd.append('do', 'reveal');
    fd.append('id', id);
    fetch('sites.php', {method: 'POST', body: fd}).then(r => r.json()).then(function (d) {
      box.innerHTML = '<code>' + (d.password ? d.password.replace(/[<>&]/g, '') : '(ખાલી)') + '</code> <a href="#" onclick="return false" class="muted">shown</a>';
    });
  }
  window.revealPw = revealPw;
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
