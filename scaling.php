<?php
// Scaling - will this still work when the shop is three times the size?
//
// Written for someone who does not read EXPLAIN plans: how big is the data,
// how fast is it growing, what will that be in a year, what can safely be
// cleared, and what must never be touched.
require_once __DIR__ . '/includes/init.php';
require_perm('settings.view');
if (!is_full_admin()) { http_response_code(403); die('This screen is for the owner / full admin only.'); }
$u = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'trim') {
    require_perm('settings.edit');
    $r = sc_trim(post('table'), (int)post('days') ?: null);
    dash_cache_forget('scaling_summary');
    if (!$r['ok']) flash($r['why'], 'error');
    else flash($r['deleted'] . ' Old notes deleted (' . e($r['table']) . ', ' . (int)$r['days'] . ' older than days).');
    redirect('scaling.php?tab=cleanup');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'optimize') {
    require_perm('settings.edit');
    $r = sc_optimize(post('table'));
    dash_cache_forget('scaling_summary');
    if (!$r['ok']) flash($r['why'], 'error');
    else flash(e($r['table']) . ' Rebuilt — about ' . money($r['freed_mb']) . ' MB freed.');
    redirect('scaling.php?tab=space');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'save_rules') {
    require_perm('settings.edit');
    set_setting('log_keep_days', (string)max(30, (int)post('log_keep_days')));
    dash_cache_forget('scaling_summary');
    flash('Saved.');
    redirect('scaling.php?tab=cleanup');
}

$tab = get('tab', 'size');
$rules = sc_rules();
$sum = sc_summary();

$page_title = 'Scaling';
include __DIR__ . '/includes/header.php';
?>
<div class="card">
  <h2>📦 Will the system hold if the business grows?</h2>
  <div class="grid-stats">
    <div class="stat"><div class="stat-label">Data right now</div><div class="stat-value"><?= money($sum['total_mb']) ?> MB</div></div>
    <div class="stat"><div class="stat-label">will grow per year</div><div class="stat-value">+<?= money($sum['grow_mb_year']) ?> MB</div></div>
    <div class="stat"><div class="stat-label">after a year</div><div class="stat-value"><?= money($sum['in_a_year_mb']) ?> MB</div></div>
    <div class="stat <?= $sum['freeable_mb'] > 5 ? 's-warn' : '' ?>"><div class="stat-label">can be cleared</div><div class="stat-value"><?= money($sum['freeable_mb']) ?> MB</div></div>
  </div>
  <div class="range-bar">
    <?php foreach (['size' => '📊 What is how big', 'growth' => '📈 How fast it is growing',
                    'indexes' => '🗂️ Index check', 'cleanup' => '🧹 Cleanup',
                    'space' => '🕳️ Empty space', 'never' => '🔒 What may never be deleted'] as $k => $lbl): ?>
    <a class="rchip <?= $tab === $k ? 'on' : '' ?>" href="scaling.php?tab=<?= $k ?>"><?= $lbl ?></a>
    <?php endforeach; ?>
  </div>
  <p class="muted" style="font-size:12.5px;margin-bottom:0">
    All the figures <b>measured from the database as it stands</b> — not a guess.
    <?php if ($sum['redundant'] > 0): ?>
      <br>⚠️ <b><?= (int)$sum['redundant'] ?> unnecessary indexes were found — <a href="scaling.php?tab=indexes">see them</a>.
    <?php endif; ?>
  </p>
</div>

<?php if ($tab === 'size'): $tables = sc_tables(25); $db = sc_db_size(); ?>
<div class="card">
  <h2>📊 How big each table is</h2>
  <p class="muted" style="margin-top:0;font-size:13px">
    <?= (int)$db['tables'] ?> tables in all · data <?= money($db['data_mb']) ?> MB · index <?= money($db['idx_mb']) ?> MB.
    <b>Indexes</b> is a separate list kept for search speed — it takes space too, and has to be written on every entry.
  </p>
  <div class="table-wrap"><table>
    <thead><tr><th>Table</th><th class="num">About rows</th><th class="num">Data</th><th class="num">Indexes</th><th class="num">Total</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($tables as $t): if ($t['total_mb'] < 0.02 && $t['free_mb'] < 1) continue;
          $heavy = $t['data_mb'] > 0.1 && $t['idx_mb'] > $t['data_mb'] * $rules['idx_ratio']; ?>
      <tr>
        <td><?= e($t['name']) ?><?= isset(sc_log_tables()[$t['name']]) ? ' <span class="badge">log</span>' : '' ?></td>
        <td class="num muted"><?= number_format((int)$t['rows_est']) ?></td>
        <td class="num"><?= money($t['data_mb']) ?></td>
        <td class="num"><?= money($t['idx_mb']) ?></td>
        <td class="num"><b><?= money($t['total_mb']) ?></b></td>
        <td><?= $heavy ? '<span class="badge b-warn">indexes larger than the data</span>' : '' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <p class="muted" style="font-size:12px">"about rows" is MySQL estimate, not an exact count — counting exactly on a big table is expensive.</p>
</div>

<?php elseif ($tab === 'growth'): $g = sc_growth(15); ?>
<div class="card">
  <h2>📈 How fast it is growing</h2>
  <p class="muted" style="margin-top:0;font-size:13px">
    <b>Last <?= (int)$rules['window'] ?> rows actually added in those days</b> is worked out from that — not from the average of all history.
    Mixing in months from when the shop was small would understate today pace.
  </p>
  <div class="table-wrap"><table>
    <thead><tr><th>Table</th><th class="num">Rows now</th><th class="num">added every month</th><th class="num">after a year</th><th class="num">then the space</th></tr></thead>
    <tbody>
    <?php foreach ($g as $x): ?>
      <tr>
        <td><?= e($x['name']) ?></td>
        <td class="num"><?= number_format($x['rows']) ?></td>
        <td class="num"><?= $x['per_month'] === null ? '<span class="muted">—</span>' : '+' . number_format($x['per_month'], 0) ?></td>
        <td class="num"><?= $x['per_month'] === null ? '<span class="muted">—</span>' : number_format($x['in_a_year']) ?></td>
        <td class="num"><?= $x['per_month'] === null ? '<span class="muted title="' . e($x['why']) . '">cannot be measured</span>'
                            : money($x['mb_in_a_year']) . ' MB' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <div class="flash flash-info">
    at this pace the data in a year <b><?= money($sum['total_mb']) ?> MB to <?= money($sum['in_a_year_mb']) ?> MB</b>.
    <?php if ($sum['in_a_year_mb'] < 500): ?>
      An ordinary hosting plan holds this comfortably — nothing to worry about.
    <?php else: ?>
      Do check once how much hosting space you have.
    <?php endif; ?>
  </div>
</div>

<?php elseif ($tab === 'indexes'): $ri = sc_redundant_indexes(); ?>
<div class="card">
  <h2>🗂️ Index check</h2>
  <p class="muted" style="margin-top:0;font-size:13px">
    An index is not free. <b>Every bill and every payment saved has to be written into all of them.</b>
    indexes whose columns are already the leading part <b>at the start</b> of another, it is useless —
    MySQL can use the leading part of a larger index on its own.
  </p>
  <?php if (!$ri): ?>
    <div class="flash flash-success">✅ No unnecessary index was found.</div>
  <?php else: ?>
    <div class="flash flash-warn"><?= count($ri) ?> unnecessary indexes found. Dropping them needs a migration file —
      This screen does not drop an index by itself, because that is a change to the database structure.</div>
    <div class="table-wrap"><table>
      <thead><tr><th>Table</th><th>Useless index</th><th>its columns</th><th>who does its work</th></tr></thead>
      <tbody>
      <?php foreach ($ri as $x): ?>
        <tr>
          <td><?= e($x['table']) ?></td>
          <td><code><?= e($x['index']) ?></code> <?= !empty($x['exact']) ? '<span class="badge b-bad">an exact copy</span>' : '' ?></td>
          <td class="muted"><?= e($x['cols']) ?></td>
          <td><code><?= e($x['covered_by']) ?></code> <span class="muted">(<?= e($x['covered_cols']) ?>)</span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
  <p class="muted" style="font-size:12.5px">
    a unique index is never counted useless — it is not there for speed, <b>so one number cannot occur twice</b> a rule is there to be kept.
  </p>
</div>

<?php elseif ($tab === 'cleanup'): $tr = sc_trimmable(); ?>
<div class="card">
  <h2>🧹 Notes that can be cleared</h2>
  <p class="muted" style="margin-top:0;font-size:13px">
    <b>Notes only</b> here — who logged in, when the cron ran, who visited the website.
    <b>No business data is on this list and none ever will be</b> — bills, payments and stock records are never deleted
    (<a href="scaling.php?tab=never">See the list</a>).
  </p>
  <div class="table-wrap"><table>
    <thead><tr><th>Log</th><th class="num">Total rows</th><th class="num">Older</th><th class="num">space is saved</th><th>How much to keep</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($tr as $t): ?>
      <tr>
        <td><?= e($t['label']) ?><div class="muted" style="font-size:12px"><?= e($t['table']) ?></div></td>
        <td class="num"><?= number_format($t['rows']) ?></td>
        <td class="num"><?= $t['old'] ? '<b>' . number_format($t['old']) . '</b>' : '<span class="muted">·</span>' ?></td>
        <td class="num muted"><?= $t['free_mb'] > 0.01 ? money($t['free_mb']) . ' MB' : '·' ?></td>
        <td class="muted"><?= (int)$t['keep'] ?> days</td>
        <td>
          <?php if ($t['old'] > 0 && can('settings.edit')): ?>
          <form method="post" onsubmit="return confirm('<?= (int)$t['old'] ?> Delete the old notes for good?')">
            <?= csrf_field() ?><input type="hidden" name="do" value="trim">
            <input type="hidden" name="table" value="<?= e($t['table']) ?>">
            <input type="hidden" name="days" value="<?= (int)$t['keep'] ?>">
            <button class="btn btn-sm btn-outline" type="submit">🧹 Clear</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <p class="muted" style="font-size:12.5px">The cron does this automatically too — this is only for doing it by hand.</p>
</div>
<div class="card">
  <h2>⚙️ Rule</h2>
  <?php if (can('settings.edit')): ?>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="do" value="save_rules">
    <div class="field"><label>Keep notes for at least this many days</label>
      <input type="number" name="log_keep_days" value="<?= (int)$rules['keep_days'] ?>" min="30">
      <p class="muted" style="font-size:12.5px">cannot be set below 30. Each note also has its own time written above.</p></div>
    <button class="btn btn-primary" type="submit">Save</button>
  </form>
  <?php else: ?><p class="muted">Changing it needs the settings.edit permission.</p><?php endif; ?>
</div>

<?php elseif ($tab === 'space'): $fr = sc_fragmentation(); ?>
<div class="card">
  <h2>🕳️ Empty space</h2>
  <p class="muted" style="margin-top:0;font-size:13px">
    When a row is deleted MySQL does not give its space <b>back immediately</b> — it stays empty inside the table and new rows
    fill it. Rebuilding the table gives that space back to the disk.
  </p>
  <div class="flash flash-info">
    ℹ️ Running tests or clearing old notes also creates this empty space, so a large figure does not mean
    "something is wrong" is not so. In all <b><?= money($sum['frag_mb']) ?> MB</b> is taken up like this at the moment.
  </div>
  <?php if (!$fr): ?><p class="muted">No table has any significant empty space.</p><?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th>Table</th><th class="num">Used</th><th class="num">empty</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($fr as $f): ?>
      <tr>
        <td><?= e($f['name']) ?></td>
        <td class="num muted"><?= money($f['total_mb']) ?> MB</td>
        <td class="num"><b><?= money($f['free_mb']) ?> MB</b></td>
        <td>
          <?php if (can('settings.edit')): ?>
          <form method="post" onsubmit="return confirm('Rebuild this table? It cannot be used for a moment — better not done while the shop is open.')">
            <?= csrf_field() ?><input type="hidden" name="do" value="optimize">
            <input type="hidden" name="table" value="<?= e($f['name']) ?>">
            <button class="btn btn-sm btn-outline" type="submit">♻️ Rebuild</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <p class="muted" style="font-size:12.5px">⚠️ Rebuilding <b>does not change the data</b> — only the arrangement changes. But while it runs
    the table stays locked, so do it when the shop is closed.</p>
  <?php endif; ?>
</div>

<?php elseif ($tab === 'never'): ?>
<div class="card">
  <h2>🔒 What is never deleted</h2>
  <p class="muted" style="margin-top:0;font-size:13px">
    Cleanup button <b>only the list of notes above</b> works only on those. Anything not on that list —
    whether from today or ten years ago — no cron, no button and no setting can delete it.
    This check <b>inside the function that does the deleting</b> itself, not in the calling screen — so a new screen cannot forget it.
  </p>
  <div class="table-wrap"><table>
    <thead><tr><th>Table</th><th>Why never</th></tr></thead>
    <tbody>
    <?php foreach (sc_never_trim() as $t => $why): ?>
      <tr><td><code><?= e($t) ?></code></td><td><?= e($why) ?></td></tr>
    <?php endforeach; ?>
      <tr><td class="muted">Bills, payments, purchases, customers, items, handovers…</td>
        <td class="muted">The core business data. It is not on the list at all, so the question never arises.</td></tr>
    </tbody>
  </table></div>
  <div class="flash flash-info">
    If space really runs short, the answer is not to delete — <a href="settings.php">back it up</a> and the answer is to get bigger hosting.
    The business data is the most valuable thing there is.
  </div>
</div>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
