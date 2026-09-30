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

// ---------- the filters ----------
//
// One bar, and the answers are the ones a shopkeeper actually asks: which
// way, did they pick up, what did they say, between which dates, and who.
// Every one of them is a plain WHERE - nothing here is clever, and nothing
// here takes anything from the query string into SQL except as a parameter.
$dir  = in_array(get('dir'), ['in', 'out'], true) ? get('dir') : '';
$show = get('show') === 'pending';
$pick = (string)get('pick');            // picked up / did not
$ans  = (string)get('ans');             // what came of it
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)get('from')) ? get('from') : '';
$to   = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)get('to')) ? get('to') : '';
$q    = trim((string)get('q'));

$where = ['1=1'];
$args = [];
if ($dir)  { $where[] = 'v.direction = ?'; $args[] = $dir; }
if ($show)   $where[] = 'v.needs_action = 1';

// "Picked up" is the answered flag, which is stamped the moment the network
// asks for the call's XML - the most reliable signal in the whole flow, and
// far better than reading a hangup cause.
if ($pick === 'yes') $where[] = 'v.answered = 1';
if ($pick === 'no')  $where[] = "v.answered = 0 AND v.status IN ('no_answer','busy','failed','ringing')";

$answers = [
    'promise' => ['📅 Gave a date',      "v.promise_date IS NOT NULL"],
    'yes'     => ['✅ Said yes',          "v.response = 'yes'"],
    'no'      => ['❌ Said no / no money', "v.response = 'no'"],
    'paid'    => ['💰 Says already paid', "v.heard_intent = 'paid'"],
    'wrong'   => ['🚫 Wrong number',      "v.heard_intent = 'wrong_person'"],
    'none'    => ['🤷 Answered, said nothing useful', "v.answered = 1 AND (v.response = '' OR v.response = 'none' OR v.response IS NULL)"],
];
if (isset($answers[$ans])) $where[] = $answers[$ans][1];

if ($from) { $where[] = 'DATE(v.created_at) >= ?'; $args[] = $from; }
if ($to)   { $where[] = 'DATE(v.created_at) <= ?'; $args[] = $to; }
if ($q !== '') {
    // A name, or any part of a number however it was typed.
    $where[] = '(p.name LIKE ? OR v.mobile LIKE ? OR v.from_number LIKE ? OR v.heard LIKE ?)';
    $like = '%' . $q . '%';
    array_push($args, $like, $like, $like, $like);
}
$wsql = implode(' AND ', $where);

$rows = all('SELECT v.*, p.name, u.name uname FROM voice_calls v
             LEFT JOIN parties p ON p.id = v.party_id
             LEFT JOIN users u ON u.id = v.handled_by
             WHERE ' . $wsql . '
             ORDER BY v.id DESC LIMIT 300', $args);

// What the filter itself found, so the numbers on screen answer the question
// that was asked rather than always describing today.
$found = row('SELECT COUNT(*) n, SUM(v.answered = 1) ans, SUM(v.promise_date IS NOT NULL) prom,
                     SUM(v.duration) secs
              FROM voice_calls v LEFT JOIN parties p ON p.id = v.party_id
              WHERE ' . $wsql, $args);
$filtered = $dir || $show || $pick || $ans || $from || $to || $q !== '';
$qs = function (array $over = []) use ($dir, $show, $pick, $ans, $from, $to, $q) {
    $a = array_filter(['dir' => $dir, 'show' => $show ? 'pending' : '', 'pick' => $pick,
                       'ans' => $ans, 'from' => $from, 'to' => $to, 'q' => $q] + [], 'strlen');
    foreach ($over as $k => $v) { if ($v === '' || $v === null) unset($a[$k]); else $a[$k] = $v; }
    return 'voice_calls.php' . ($a ? '?' . http_build_query($a) : '');
};
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
    <a class="btn btn-sm <?= $dir === '' && !$show ? '' : 'btn-outline' ?>" href="<?= e($qs(['dir' => '', 'show' => ''])) ?>">All</a>
    <a class="btn btn-sm <?= $dir === 'in' ? '' : 'btn-outline' ?>" href="<?= e($qs(['dir' => 'in'])) ?>">📥 Incoming</a>
    <a class="btn btn-sm <?= $dir === 'out' ? '' : 'btn-outline' ?>" href="<?= e($qs(['dir' => 'out'])) ?>">📤 Outgoing</a>
    <a class="btn btn-sm <?= $show ? 'btn-danger' : 'btn-outline' ?>" href="<?= e($qs(['show' => 'pending'])) ?>">Needs an answer</a>
    <a class="btn btn-sm btn-outline" href="voice_setup.php">⚙️ Setup</a>
  </p>
</div>

<div class="card no-print">
  <h3>🔎 Find a call</h3>
  <form method="get" class="filterbar">
    <?php if ($show): ?><input type="hidden" name="show" value="pending"><?php endif; ?>
    <div><label>Which way</label>
      <select name="dir">
        <option value="">Both</option>
        <option value="out" <?= $dir === 'out' ? 'selected' : '' ?>>📤 We rang them</option>
        <option value="in" <?= $dir === 'in' ? 'selected' : '' ?>>📥 They rang us</option>
      </select></div>
    <div><label>Did they pick up</label>
      <select name="pick">
        <option value="">Either</option>
        <option value="yes" <?= $pick === 'yes' ? 'selected' : '' ?>>☎️ Picked up</option>
        <option value="no" <?= $pick === 'no' ? 'selected' : '' ?>>📵 Did not pick up</option>
      </select></div>
    <div><label>What came of it</label>
      <select name="ans">
        <option value="">Anything</option>
        <?php foreach ($answers as $ak => $av): ?>
          <option value="<?= e($ak) ?>" <?= $ans === $ak ? 'selected' : '' ?>><?= e($av[0]) ?></option>
        <?php endforeach; ?>
      </select></div>
    <div><label>From</label><input type="date" name="from" value="<?= e($from) ?>"></div>
    <div><label>To</label><input type="date" name="to" value="<?= e($to) ?>"></div>
    <div><label>Name, number, or what they said</label>
      <input name="q" value="<?= e($q) ?>" placeholder="રમેશ / 98765 / કાલે"></div>
    <button class="btn btn-sm" type="submit">Find</button>
    <?php if ($filtered): ?><a class="btn btn-sm btn-outline" href="voice_calls.php">Clear</a><?php endif; ?>
  </form>

  <?php if ($filtered): ?>
  <div class="grid-stats mt">
    <div class="stat"><div class="stat-label">Calls found</div><div class="stat-value"><?= (int)($found['n'] ?? 0) ?></div></div>
    <div class="stat s-ok"><div class="stat-label">Picked up</div>
      <div class="stat-value"><?= (int)($found['ans'] ?? 0) ?>
        <span style="font-size:13px;opacity:.6"><?= (int)($found['n'] ?? 0) ? '· ' . round(100 * (int)$found['ans'] / (int)$found['n']) . '%' : '' ?></span></div></div>
    <div class="stat s-ok"><div class="stat-label">Gave a date</div><div class="stat-value"><?= (int)($found['prom'] ?? 0) ?></div></div>
    <div class="stat"><div class="stat-label">Time on the phone</div>
      <div class="stat-value" style="font-size:18px"><?= (int)round((int)($found['secs'] ?? 0) / 60) ?> min</div></div>
  </div>
  <?php if ((int)($found['n'] ?? 0) > count($rows)): ?>
    <p class="muted" style="font-size:12px">Showing the most recent <?= count($rows) ?> of <?= (int)$found['n'] ?> — narrow the dates to see the rest.</p>
  <?php endif; ?>
  <?php endif; ?>
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
        <br><audio controls preload="none" src="voice_rec.php?id=<?= (int)$w['id'] ?>" style="width:100%;max-width:320px"></audio>
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
          <?php if (!empty($r['heard'])): ?>
            <br><span class="muted" style="font-size:11px">🗣 “<?= e($r['heard']) ?>”</span>
          <?php endif; ?>
          <?php if (!empty($r['promise_date'])): ?>
            <br><span class="badge badge-ok" style="font-size:10px">📅 said <?= e(dmy($r['promise_date'])) ?></span>
          <?php elseif (($r['heard_intent'] ?? '') === 'paid'): ?>
            <br><span class="badge badge-warn" style="font-size:10px">says already paid</span>
          <?php elseif (($r['heard_intent'] ?? '') === 'wrong_person'): ?>
            <br><span class="badge badge-bad" style="font-size:10px">wrong number</span>
          <?php elseif ($r['response'] === 'yes'): ?><br><span class="badge badge-ok" style="font-size:10px">✅ said yes</span>
          <?php elseif ($r['response'] === 'no'): ?><br><span class="badge badge-bad" style="font-size:10px">❌ said no</span><?php endif; ?>
          <?php if ((int)$r['duration']): ?><br><span class="muted" style="font-size:11px"><?= (int)$r['duration'] ?>s</span><?php endif; ?>
          <?php if ($r['error']): ?><br><span class="muted" style="font-size:11px"><?= e($r['error']) ?></span><?php endif; ?>
        </td>
        <td style="white-space:nowrap">
          <?php if (!empty($r['full_rec_url'])): ?>
            <a class="btn btn-sm btn-outline" href="voice_rec.php?id=<?= (int)$r['id'] ?>&full=1" target="_blank"
               title="The whole call, both sides, from the greeting">▶ all<?php if ((int)$r['full_rec_secs']): ?>
               <span class="muted" style="font-size:10px"><?= (int)$r['full_rec_secs'] ?>s</span><?php endif; ?></a>
          <?php endif; ?>
          <?php if ($r['recording_url']): ?><a class="btn btn-sm btn-outline" href="voice_rec.php?id=<?= (int)$r['id'] ?>" target="_blank"
               title="What the customer said">▶</a><?php endif; ?>
          <?php /* Ring them again. The same confirm screen as everywhere else,
                    which is what re-checks the rules, shows the amount and
                    plays the recording - nothing is dialled from this list. */ ?>
          <?= voice_call_button((int)$r['party_id'], 'voice_calls.php', true) ?>
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
