<?php
// Every call, both directions, in one place.
//
// The point of this screen is the top of it: the calls the shop still owes
// somebody an answer for. An order left on the machine at nine at night is
// only worth money if a person sees it in the morning, so those stay here,
// marked, until somebody closes them - and closing one is a button, not a
// habit somebody has to remember.
//
// Below that is the history: who rang, which way, what they came for, which
// keys they pressed, and the recording. The keys matter more than they look.
// Everyone pressing 9 for a person means the menu is not answering the
// question people are actually calling with.
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/voice_in.php';
require_perm('payments.view');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_perm('payments.add');
    if (post('do') === 'handled') {
        voice_call_handled((int)post('id'), post('note'));
        flash('Marked as handled.');
    }
    redirect('voice_calls.php' . (get('dir') ? '?dir=' . urlencode(get('dir')) : ''));
}

$dir = in_array(get('dir'), ['in', 'out'], true) ? get('dir') : '';
$show = get('show') === 'pending';
$where = ['1=1'];
$args = [];
if ($dir) { $where[] = 'v.direction = ?'; $args[] = $dir; }
if ($show) $where[] = 'v.needs_action = 1';

$rows = all('SELECT v.*, p.name, u.name uname FROM voice_calls v
             LEFT JOIN parties p ON p.id = v.party_id
             LEFT JOIN users u ON u.id = v.handled_by
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY v.id DESC LIMIT 200', $args);
$pending = voice_in_pending_count();

$today = row("SELECT
    SUM(direction = 'in') inn,
    SUM(direction = 'out') outt,
    SUM(direction = 'in' AND answered = 1) in_ans,
    SUM(direction = 'out' AND response = 'yes') said_yes
  FROM voice_calls WHERE DATE(created_at) = CURDATE()");

$page_title = 'Calls';
include __DIR__ . '/includes/header.php';
?>
<div class="card">
  <h2>📞 Calls</h2>
  <div class="grid-stats">
    <div class="stat"><div class="stat-label">Came in today</div><div class="stat-value"><?= (int)($today['inn'] ?? 0) ?></div></div>
    <div class="stat"><div class="stat-label">Went out today</div><div class="stat-value"><?= (int)($today['outt'] ?? 0) ?></div></div>
    <a class="stat <?= $pending ? 's-bad' : 's-ok' ?>" href="voice_calls.php?show=pending">
      <div class="stat-label">Waiting for an answer</div><div class="stat-value"><?= $pending ?></div></a>
    <div class="stat s-ok"><div class="stat-label">Said yes to paying</div><div class="stat-value"><?= (int)($today['said_yes'] ?? 0) ?></div></div>
  </div>
  <p class="no-print">
    <a class="btn btn-sm <?= $dir === '' && !$show ? '' : 'btn-outline' ?>" href="voice_calls.php">All</a>
    <a class="btn btn-sm <?= $dir === 'in' ? '' : 'btn-outline' ?>" href="voice_calls.php?dir=in">Incoming</a>
    <a class="btn btn-sm <?= $dir === 'out' ? '' : 'btn-outline' ?>" href="voice_calls.php?dir=out">Outgoing</a>
    <a class="btn btn-sm <?= $show ? 'btn-danger' : 'btn-outline' ?>" href="voice_calls.php?show=pending">Needs an answer</a>
    <a class="btn btn-sm btn-outline" href="voice_setup.php">⚙️ Setup</a>
  </p>
</div>

<?php
$waiting = voice_in_pending(20);
if ($waiting && !$show): ?>
<div class="card">
  <h3>🔔 These callers are still waiting</h3>
  <?php foreach ($waiting as $w): ?>
  <div class="act act-bad">
    <span class="act-ico"><?= $w['intent'] === 'complaint' ? '🔧' : ($w['intent'] === 'order' ? '🛒' : '📞') ?></span>
    <span class="act-body">
      <strong><?= e($w['name'] ?: $w['from_number']) ?></strong> — <?= e(voice_intent_label($w['intent'])) ?>
      <br><span class="muted" style="font-size:12px"><?= e(voice_ago($w['created_at'])) ?>
        <?php if ($w['recording_secs']): ?> · <?= (int)$w['recording_secs'] ?>s recording<?php endif; ?>
        <?php if ($w['ref_type']): ?> · became a <?= e($w['ref_type']) ?><?php endif; ?></span>
      <?php if ($w['recording_url']): ?>
        <br><audio controls preload="none" src="<?= e($w['recording_url']) ?>" style="width:100%;max-width:320px"></audio>
      <?php endif; ?>
    </span>
    <span style="white-space:nowrap">
      <a class="btn btn-sm btn-outline" href="tel:<?= e($w['from_number']) ?>">📱 Call back</a>
      <?php if (can('payments.add')): ?>
      <form method="post" style="display:inline">
        <?= csrf_field() ?><input type="hidden" name="do" value="handled"><input type="hidden" name="id" value="<?= (int)$w['id'] ?>">
        <button class="btn btn-sm btn-success" type="submit">✔ Done</button>
      </form>
      <?php endif; ?>
    </span>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="card">
  <h3>All calls</h3>
  <?php if (!$rows): ?><p class="muted">No calls yet.</p><?php else: ?>
  <div class="table-wrap"><table class="table-sm">
    <thead><tr>
      <th></th><th>When</th><th>Who</th><th>What for</th><th>Result</th><th></th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $in = $r['direction'] === 'in'; ?>
      <tr>
        <td title="<?= $in ? 'Incoming' : 'Outgoing' ?>"><?= $in ? '📥' : '📤' ?></td>
        <td><?= e(dmy(substr($r['created_at'], 0, 10))) ?><br>
            <span class="muted" style="font-size:11px"><?= e(substr($r['created_at'], 11, 5)) ?></span></td>
        <td>
          <?php if ($r['name']): ?><a href="customer.php?id=<?= (int)$r['party_id'] ?>"><?= e($r['name']) ?></a>
          <?php elseif ($in): ?><span class="muted">unknown</span>
          <?php else: ?><span class="muted">test</span><?php endif; ?>
          <br><span class="muted" style="font-size:11px"><?= e($in ? $r['from_number'] : $r['mobile']) ?></span>
        </td>
        <td>
          <?php if ($in): ?>
            <?= e(voice_intent_label($r['intent'])) ?>
            <?php if ($r['ivr_path']): ?><br><span class="muted" style="font-size:11px">pressed <?= e($r['ivr_path']) ?></span><?php endif; ?>
          <?php else: ?>
            💰 Reminder ₹<?= money($r['amount']) ?>
          <?php endif; ?>
        </td>
        <td>
          <?= e(voice_status_label($r['status'])) ?>
          <?php if ($r['response'] === 'yes'): ?><br><span class="badge badge-ok" style="font-size:10px">✅ said yes</span>
          <?php elseif ($r['response'] === 'no'): ?><br><span class="badge badge-bad" style="font-size:10px">❌ said no</span><?php endif; ?>
          <?php if ((int)$r['duration']): ?><br><span class="muted" style="font-size:11px"><?= (int)$r['duration'] ?>s</span><?php endif; ?>
          <?php if ($r['error']): ?><br><span class="muted" style="font-size:11px"><?= e($r['error']) ?></span><?php endif; ?>
        </td>
        <td style="white-space:nowrap">
          <?php if ($r['recording_url']): ?><a class="btn btn-sm btn-outline" href="<?= e($r['recording_url']) ?>" target="_blank">▶</a><?php endif; ?>
          <?php if ((int)$r['needs_action'] === 1 && can('payments.add')): ?>
          <form method="post" style="display:inline">
            <?= csrf_field() ?><input type="hidden" name="do" value="handled"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <button class="btn btn-sm btn-success" type="submit">✔</button>
          </form>
          <?php elseif ($r['handled_at']): ?>
            <span class="muted" style="font-size:11px" title="<?= e($r['handled_at']) ?>">✔ <?= e($r['uname'] ?: '') ?></span>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
