<?php
// What the phone says - every sentence, in the shop's own words.
//
// The wording used to be fixed in the code, which meant the one person who
// knows how this shop talks to its customers could not change a word of it.
// Everything the phone says is editable here, per language, and every menu
// option can be switched off - and an option that is off disappears from
// what is read out as well as from what the keypad answers.
//
// Two rules make this safe to hand over:
//
//   * A blank box is not an empty sentence. It means "use the wording the
//     software ships with", so a later improvement still reaches this shop
//     and clearing a box undoes a bad edit. Saying NOTHING is a separate
//     choice - the "say this" tick.
//   * A sentence that carries a figure has to keep the slot the figure goes
//     in. An edit that drops {amount} is refused by name rather than saved,
//     because the call would otherwise say "your is outstanding" and nobody
//     would find out until a customer heard it.
//
// Nothing here dials, sends or spends. The audio for a changed line is made
// by the same button that made it the first time, on the Call Setup screen.
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/voice_in.php';
require_perm('settings.edit');

$langs = voice_langs();
$lang = get('lang') ?: voice_in_lang();
if (!isset($langs[$lang])) $lang = 'gu';

// The lines, grouped the way a shopkeeper thinks about a phone call rather
// than the way the code stores them. 'menu' itself is never listed: it is
// built from the options below, so editing it would be editing a result.
function vw_groups() {
    return [
        'The very first thing said' => ['hello'],
        'Greeting' => ['welcome', 'welcome_name', 'closed'],
        'Their account' => ['balance', 'balance_nil', 'balance_adv', 'balance_wa', 'not_known'],
        'When they want to leave something' => ['ask_order', 'ask_problem', 'ask_stock', 'noted'],
        'Their order' => ['order_status', 'order_none'],
        'Talking to a person' => ['connecting', 'no_answer', 'closed_msg'],
        'Ending the call' => ['again', 'bye'],
    ];
}
function vw_label($k) {
    $l = [
        'hello' => 'Said before anything else, on calls in AND out',
        'welcome' => 'A caller we do not recognise',
        'welcome_name' => 'A caller we do recognise — {name} is their name from their party record',
        'closed' => 'Added when the shop is shut',
        'balance' => 'Their outstanding amount — {amount}',
        'balance_nil' => 'They owe nothing',
        'balance_adv' => 'They are in credit — {amount}',
        'balance_wa' => 'Sent to WhatsApp instead of read out',
        'not_known' => 'Their number is not on any party record',
        'ask_order' => 'Before recording an order',
        'ask_problem' => 'Before recording a complaint',
        'ask_stock' => 'Before recording a price question',
        'noted' => 'After the recording is kept',
        'order_status' => 'Where their order has got to — {status}',
        'order_none' => 'They have no order running',
        'connecting' => 'While the shop phones ring',
        'no_answer' => 'Nobody picked up',
        'closed_msg' => 'They pressed for a person while the shop is shut',
        'again' => 'They pressed nothing',
        'bye' => 'Hanging up',
    ];
    return $l[$k] ?? '';
}
function vw_menu_label($d) {
    $l = ['1' => 'Their account balance', '2' => 'Place an order', '3' => 'A complaint or a repair',
          '4' => 'Ask about stock or a price', '5' => 'Where their order has got to',
          '9' => 'Talk to a person'];
    return $l[$d] ?? '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'save') {
    $lang = post('lang');
    if (!isset($langs[$lang])) $lang = 'gu';

    // The options, in the order they are read out. Order is the digit order,
    // which is also the order the caller is told them in - a menu that says
    // "press nine" before "press one" is a menu nobody follows.
    $on = array_values(array_intersect(voice_in_all_digits(), array_map('strval', post('on', []))));
    set_setting('voice_menu_opts', $on === voice_in_all_digits() ? '' : implode(',', $on));

    $said = array_map('strval', post('say', []));
    $off = [];
    foreach (array_keys(post('line', [])) as $k) if (!in_array((string)$k, $said, true)) $off[] = (string)$k;

    $r = voice_lines_save($lang, post('line', []), $off);
    log_activity('voice_words', $r['saved'] . ' line(s) in this shop\'s own words (' . $lang . ')');

    if ($r['refused']) {
        flash(count($r['refused']) . ' line(s) were NOT saved because the figure would go missing from them: '
              . implode(', ', $r['refused']) . '. Put the {…} back and save again.', 'error');
    } else {
        flash('Saved. The voice for any changed line still has to be made — '
              . 'press “Make it again” on the Call Setup screen.');
    }
    redirect('voice_words.php?lang=' . $lang);
}

$defaults = voice_in_defaults($lang);
$edited = voice_lines_edited($lang);
$live = voice_in_words($lang);
$onNow = voice_in_digits();
$status = voice_in_voice_status($lang);

$page_title = 'What the phone says';
include __DIR__ . '/includes/header.php';
?>

<div class="card">
  <h2>🗣 What the phone says</h2>
  <p class="muted" style="font-size:13px">
    Every sentence, in your words. Leave a box empty to go back to the wording this software came with.
    Untick <strong>say this</strong> to have the call skip that sentence altogether.
    <a href="voice_setup.php">← Call Setup</a>
  </p>

  <div class="page-actions no-print">
    <?php foreach ($langs as $lk => $lv): ?>
      <a class="btn <?= $lang === $lk ? 'btn-success' : 'btn-outline' ?>" href="voice_words.php?lang=<?= $lk ?>"><?= e($lv) ?></a>
    <?php endforeach; ?>
  </div>

  <?php if ($lang !== voice_in_lang()): ?>
  <div class="flash flash-info">Callers hear <strong><?= e($langs[voice_in_lang()]) ?></strong> right now.
    You are editing <?= e($langs[$lang]) ?>, which is what they would hear if you switch the menu to it.</div>
  <?php endif; ?>
  <?php if ($lang !== 'en' && !$status['ready']): ?>
  <div class="flash flash-error">
    <?= (int)$status['total'] - (int)$status['done'] ?> line(s) have no voice made yet, so a caller hears them in
    English. After editing, press <strong>“Make it again”</strong> in step 2 of
    <a href="voice_setup.php">Call Setup</a>.
  </div>
  <?php endif; ?>
</div>

<form method="post">
<?= csrf_field() ?><input type="hidden" name="do" value="save"><input type="hidden" name="lang" value="<?= e($lang) ?>">

<div class="card">
  <h3>🔢 The menu — what they can press</h3>
  <p class="muted" style="font-size:13px">
    Untick an option and it is not read out <em>and</em> not answered. A shop that does not deliver has no
    business offering “press 5 for your order”.
  </p>
  <div class="table-wrap"><table class="table-sm">
    <thead><tr><th style="width:56px">Offer</th><th>Key and what the caller hears</th></tr></thead>
    <tbody>
    <?php foreach (voice_in_all_digits() as $d): $k = 'menu_' . $d; ?>
      <tr>
        <td><input type="checkbox" name="on[]" value="<?= $d ?>" <?= in_array($d, $onNow, true) ? 'checked' : '' ?>></td>
        <td>
          <div class="muted" style="font-size:12px"><strong style="font-size:14px"><?= $d ?></strong>
            — <?= e(vw_menu_label($d)) ?></div>
          <input name="line[<?= $k ?>]" style="width:100%"
                 value="<?= e(array_key_exists($k, $edited) && $edited[$k] !== null ? $edited[$k] : '') ?>"
                 placeholder="<?= e($defaults[$k] ?? '') ?>">
          <input type="hidden" name="say[]" value="<?= $k ?>">
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <p class="muted" style="font-size:12px;margin-top:8px">
    <strong>They will hear:</strong> <?= e($live['menu']) ?: '<em>nothing — every option is switched off</em>' ?>
  </p>
</div>

<?php foreach (vw_groups() as $title => $keys): ?>
<div class="card">
  <h3><?= e($title) ?></h3>
  <div class="table-wrap"><table class="table-sm">
    <thead><tr><th style="width:56px">Say this</th><th>Sentence</th></tr></thead>
    <tbody>
    <?php foreach ($keys as $k): if (!isset($defaults[$k])) continue;
      $isOff = array_key_exists($k, $edited) && $edited[$k] === null;
      $slots = voice_line_slots($defaults[$k]); ?>
      <tr>
        <td><input type="checkbox" name="say[]" value="<?= e($k) ?>" <?= $isOff ? '' : 'checked' ?>></td>
        <td>
          <div class="muted" style="font-size:12px"><?= e(vw_label($k)) ?></div>
          <input name="line[<?= e($k) ?>]" style="width:100%"
                 value="<?= e(!$isOff && array_key_exists($k, $edited) ? $edited[$k] : '') ?>"
                 placeholder="<?= e($defaults[$k]) ?>">
          <?php if ($slots): ?>
            <span class="muted" style="font-size:11px">Keep
              <?php foreach ($slots as $sl): ?><code>{<?= e($sl) ?>}</code> <?php endforeach; ?>
              in the sentence — that is where the real value goes.</span>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endforeach; ?>

<div class="card">
  <button class="btn btn-success" type="submit">Save what the phone says</button>
  <a class="btn btn-outline" href="voice_setup.php">Back to Call Setup</a>
</div>
</form>

<?php include __DIR__ . '/includes/footer.php'; ?>
