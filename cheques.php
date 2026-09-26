<?php
// ચેક રજિસ્ટર — the cheques the shop is holding, and the ones it has given.
//
// A cheque is a promise on paper, not money. The day it is taken the payment
// is recorded (that is what the customer's ledger shows), but the paper still
// has to be carried to the bank, and it can still come back. This register is
// that paper: which cheque, on which bank, dated for when, and where it is in
// its life.
//
// The one rule that matters here: a BOUNCED cheque is money that never
// arrived. Its payment is reversed - through the same payment_reverse() the
// delete button uses - so every bill it appeared to settle opens again. A
// shop that quietly leaves a bounced cheque marked paid chases nobody for it.
require_once __DIR__ . '/includes/init.php';
require_perm('cheques.view');
$u = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'add') {
    require_perm('cheques.add');
    $amount = round((float)post('amount'), 2);
    $no = trim((string)post('cheque_no'));
    if ($no === '' || $amount <= 0.009) { flash('ચેક નંબર અને રકમ જરૂરી છે.', 'error'); redirect('cheques.php'); }
    cheque_add([
        'direction' => post('direction') === 'out' ? 'out' : 'in',
        'party_id' => (int)post('party_id') ?: null,
        'payment_id' => (int)post('payment_id') ?: null,
        'cheque_no' => $no, 'bank_name' => post('bank_name'),
        'cheque_date' => post('cheque_date', today()), 'amount' => $amount,
        'notes' => post('notes'),
    ]);
    log_activity('cheque_add', $no . ' ₹' . money($amount));
    flash('ચેક નોંધી લીધો.');
    redirect('cheques.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'status') {
    require_perm('cheques.edit');
    $c = row('SELECT * FROM cheques WHERE id = ?', [(int)post('id')]);
    $to = post('status');
    if (!$c || !isset(cheque_statuses()[$to])) { flash('ચેક મળ્યો નહીં.', 'error'); redirect('cheques.php'); }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $extra = '';
        if ($to === 'deposited') {
            q('UPDATE cheques SET status = ?, deposit_date = ?, bank_account_id = ? WHERE id = ?',
              [$to, post('on_date', today()), (int)post('bank_account_id') ?: null, $c['id']]);
        } elseif ($to === 'cleared') {
            q('UPDATE cheques SET status = ?, clear_date = ? WHERE id = ?', [$to, post('on_date', today()), $c['id']]);
        } elseif ($to === 'bounced') {
            // the money never arrived: take back everything that payment did
            if ($c['payment_id'] && payment_reverse((int)$c['payment_id'])) {
                $extra = ' — એની પેમેન્ટ એન્ટ્રી પાછી ખેંચી લીધી, બિલ ફરી બાકી દેખાશે.';
            }
            q('UPDATE cheques SET status = ?, clear_date = ?, payment_id = NULL WHERE id = ?',
              [$to, post('on_date', today()), $c['id']]);
            // a bounce is worth telling the customer about, and worth a note
            // on their collection history so the next reminder knows
            if ($c['party_id'] && function_exists('coll_log'))
                coll_log((int)$c['party_id'], 'note', ['amount' => (float)$c['amount'],
                    'note' => 'ચેક ' . $c['cheque_no'] . ' બાઉન્સ થયો', 'status' => 'done']);
        } else {
            q('UPDATE cheques SET status = ? WHERE id = ?', [$to, $c['id']]);
        }
        $pdo->commit();
        log_activity('cheque_status', $c['cheque_no'] . ' → ' . $to);
        flash('ચેક ' . $c['cheque_no'] . ' → ' . cheque_statuses()[$to] . $extra, $to === 'bounced' ? 'error' : 'success');
    } catch (Exception $ex) {
        $pdo->rollBack();
        flash('Error: ' . $ex->getMessage(), 'error');
    }
    redirect('cheques.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'delete') {
    require_perm('cheques.edit');
    q('DELETE FROM cheques WHERE id = ?', [(int)post('id')]);
    flash('ચેકની નોંધ કાઢી નાખી.');
    redirect('cheques.php');
}

$dir = get('dir') === 'out' ? 'out' : 'in';
$st = get('st', '');
$w = ' WHERE c.direction = ?'; $args = [$dir];
if ($st && isset(cheque_statuses()[$st])) { $w .= ' AND c.status = ?'; $args[] = $st; }
$rows = all("SELECT c.*, p.name party_name, b.account_name bank_acc
             FROM cheques c LEFT JOIN parties p ON p.id = c.party_id
             LEFT JOIN bank_accounts b ON b.id = c.bank_account_id
             $w ORDER BY c.status = 'cleared', c.cheque_date, c.id DESC LIMIT 300", $args);
$due = cheques_due();
$inHand = (float)val("SELECT COALESCE(SUM(amount),0) FROM cheques WHERE direction = 'in' AND status IN ('in_hand','deposited')");
$outHand = (float)val("SELECT COALESCE(SUM(amount),0) FROM cheques WHERE direction = 'out' AND status IN ('in_hand','deposited')");
$bounced = (int)val("SELECT COUNT(*) FROM cheques WHERE status = 'bounced'");
$parties = all('SELECT id, name FROM parties WHERE is_active = 1 ORDER BY name');
$banks = all('SELECT * FROM bank_accounts WHERE is_active = 1 ORDER BY is_default DESC, account_name');

$page_title = 'ચેક રજિસ્ટર';
include __DIR__ . '/includes/header.php';
?>
<div class="grid-stats mb">
  <div class="stat s-ok"><div class="stat-label">📥 લેવાના ચેક (પાસ થવાના બાકી)</div><div class="stat-value">₹<?= money($inHand) ?></div></div>
  <div class="stat s-bad"><div class="stat-label">📤 આપેલા ચેક (કપાવાના બાકી)</div><div class="stat-value">₹<?= money($outHand) ?></div></div>
  <div class="stat<?= $bounced ? ' s-bad' : '' ?>"><div class="stat-label">↩️ બાઉન્સ થયેલા</div><div class="stat-value"><?= $bounced ?></div></div>
</div>

<?php if ($due): ?>
<div class="card">
  <h3>⏰ આજે કે આ પહેલાં બેંકમાં નાખવાના હતા (<?= count($due) ?>)</h3>
  <p class="muted">આ ચેકની તારીખ આવી ગઈ છે પણ હજી બેંકમાં નાખ્યા નથી.</p>
  <div class="table-wrap" style="box-shadow:none">
  <table class="table-sm">
    <thead><tr><th>ચેક નં.</th><th>પાર્ટી</th><th>તારીખ</th><th class="num">રકમ</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($due as $c): ?>
      <tr>
        <td><strong><?= e($c['cheque_no']) ?></strong><?= $c['bank_name'] ? ' <span class="muted">' . e($c['bank_name']) . '</span>' : '' ?></td>
        <td><?= e($c['party_name'] ?: '-') ?></td>
        <td style="color:var(--bad);font-weight:700"><?= dmy($c['cheque_date']) ?></td>
        <td class="num">₹<?= money($c['amount']) ?></td>
        <td><?php if (can('cheques.edit')): ?>
          <form method="post" style="display:inline-flex;gap:4px">
            <?= csrf_field() ?><input type="hidden" name="do" value="status"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
            <input type="hidden" name="status" value="deposited">
            <select name="bank_account_id" style="width:130px"><?php foreach ($banks as $b): ?><option value="<?= $b['id'] ?>"><?= e($b['account_name']) ?></option><?php endforeach; ?></select>
            <button class="btn btn-sm" type="submit">બેંકમાં નાખ્યો</button>
          </form>
        <?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endif; ?>

<div class="page-actions">
  <a class="btn btn-sm <?= $dir === 'in' ? '' : 'btn-outline' ?>" href="cheques.php?dir=in">📥 લેવાના</a>
  <a class="btn btn-sm <?= $dir === 'out' ? '' : 'btn-outline' ?>" href="cheques.php?dir=out">📤 આપેલા</a>
  <?php foreach (cheque_statuses() as $k => $lbl): ?>
    <a class="btn btn-sm <?= $st === $k ? '' : 'btn-outline' ?>" href="cheques.php?dir=<?= $dir ?>&st=<?= $k ?>"><?= e($lbl) ?></a>
  <?php endforeach; ?>
  <?php if ($st): ?><a class="btn btn-sm btn-muted" href="cheques.php?dir=<?= $dir ?>">બધા</a><?php endif; ?>
</div>

<?php if (can('cheques.add')): ?>
<div class="card">
  <h3>+ ચેક નોંધો</h3>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="add">
    <div class="form-row cols-4">
      <div><label>કોનો ચેક</label>
        <select name="direction">
          <option value="in">📥 લેવાનો (ગ્રાહકે આપ્યો)</option>
          <option value="out" <?= $dir === 'out' ? 'selected' : '' ?>>📤 આપેલો (આપણે આપ્યો)</option>
        </select></div>
      <div><label>પાર્ટી</label>
        <select name="party_id" id="party_id">
          <option value="">-- પસંદ કરો --</option>
          <?php foreach ($parties as $p): ?><option value="<?= $p['id'] ?>"><?= e($p['name']) ?></option><?php endforeach; ?>
        </select></div>
      <div><label>ચેક નંબર *</label><input type="text" name="cheque_no" maxlength="40" required></div>
      <div><label>કઈ બેંકનો</label><input type="text" name="bank_name" maxlength="120" placeholder="દા.ત. SBI"></div>
      <div><label>ચેકની તારીખ *</label><input type="date" name="cheque_date" value="<?= today() ?>" required></div>
      <div><label>રકમ (₹) *</label><input type="number" step="any" min="0.01" name="amount" required></div>
      <div><label>પેમેન્ટ એન્ટ્રી નં. (હોય તો)</label><input type="number" name="payment_id" placeholder="બાઉન્સ થાય તો આ એન્ટ્રી પાછી ખેંચાશે"></div>
      <div><label>નોંધ</label><input type="text" name="notes" maxlength="200"></div>
    </div>
    <button class="btn" type="submit">ચેક નોંધો</button>
  </form>
  <script>SearchPick.init('party_id', 'પાર્ટીનું નામ ટાઇપ કરો…');</script>
</div>
<?php endif; ?>

<div class="list-count"><?= count($rows) ?> ચેક</div>
<div class="table-wrap">
<table>
  <thead><tr><th>ચેક નં.</th><th>પાર્ટી</th><th>તારીખ</th><th class="num">રકમ</th><th>સ્થિતિ</th><th>નોંધ</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $c): ?>
    <tr>
      <td><strong><?= e($c['cheque_no']) ?></strong><?= $c['bank_name'] ? '<br><span class="muted">' . e($c['bank_name']) . '</span>' : '' ?></td>
      <td><?= $c['party_id'] ? '<a href="parties.php?action=ledger&id=' . (int)$c['party_id'] . '">' . e($c['party_name']) . '</a>' : '<span class="muted">-</span>' ?></td>
      <td><?= dmy($c['cheque_date']) ?><?= $c['deposit_date'] ? '<br><span class="muted">નાખ્યો ' . dmy($c['deposit_date']) . '</span>' : '' ?></td>
      <td class="num">₹<?= money($c['amount']) ?></td>
      <td><span class="badge <?= $c['status'] === 'cleared' ? 'badge-ok' : ($c['status'] === 'bounced' ? 'badge-bad' : 'badge-info') ?>"><?= e(cheque_statuses()[$c['status']]) ?></span></td>
      <td class="muted"><?= e($c['notes']) ?></td>
      <td style="white-space:nowrap">
        <?php if (can('cheques.edit') && !in_array($c['status'], ['cleared', 'cancelled', 'bounced'], true)): ?>
          <?php foreach (['deposited' => 'બેંકમાં', 'cleared' => '✔ પાસ', 'bounced' => '↩ બાઉન્સ'] as $k => $lbl): ?>
          <form method="post" style="display:inline"<?= $k === 'bounced' ? ' onsubmit="return confirm(\'ચેક બાઉન્સ? એની પેમેન્ટ એન્ટ્રી પાછી ખેંચાશે અને બિલ ફરી બાકી દેખાશે.\')"' : '' ?>>
            <?= csrf_field() ?><input type="hidden" name="do" value="status"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
            <input type="hidden" name="status" value="<?= $k ?>">
            <button class="btn btn-sm <?= $k === 'bounced' ? 'btn-danger' : 'btn-outline' ?>" type="submit"><?= $lbl ?></button>
          </form>
          <?php endforeach; ?>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="7" class="muted">કોઈ ચેક નોંધ્યો નથી.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
