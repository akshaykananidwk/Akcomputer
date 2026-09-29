<?php
// Reminder Calls — set up, check, and try it on your own phone first.
//
// Everything a reminder call needs is on this one screen: the Vobiz keys,
// the hours it is allowed to ring, the recordings it speaks with, what the
// regulator expects, and a test call that goes to the owner's own number
// rather than a customer's.
//
// The keys are typed here and stored encrypted (secret_setting_keys() in
// includes/helpers.php puts vobiz_auth_token through the same AES-256 vault
// as the WhatsApp and Razorpay secrets). The token is never printed back
// into the page - the form shows whether one is saved, not what it is - so
// the browser, the page cache and anyone looking over a shoulder never see
// it. Nothing about the provider is ever sent to the customer-facing side.
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/voice_in.php';
require_perm('settings.view');

$langs = voice_langs();

// ---------- actions ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_perm('settings.edit');

    if (post('do') === 'inbound_save') {
        foreach (['voice_agent_numbers', 'voice_agent_timeout', 'voice_shop_open', 'voice_shop_close',
                  'voice_inbound_lang', 'voice_ivr_balance', 'vobiz_inbound_number'] as $k) set_setting($k, post($k));
        set_setting('voice_inbound', post('voice_inbound') ? '1' : '0');
        log_activity('voice_inbound_settings', 'incoming call settings saved');
        flash('Saved.');
        redirect('voice_setup.php');
    }

    if (post('do') === 'attach_number') {
        $appId = setting('vobiz_app_id', '');
        if ($appId === '') { flash('Make the application first.', 'error'); redirect('voice_setup.php'); }
        $a = voice_in_number_attach(post('number'), $appId);
        set_setting('vobiz_attach_trail', json_encode($a['trail'] ?? [], JSON_UNESCAPED_UNICODE));
        flash($a['ok'] ? '✅ ' . e(post('number')) . ' now rings this software.' : $a['error'],
              $a['ok'] ? 'success' : 'error');
        redirect('voice_setup.php');
    }

    if (post('do') === 'preview_caller') {
        set_setting('voice_preview_caller', post('mobile'));
        redirect('voice_setup.php#hear');
    }

    if (post('do') === 'make_in_voice') {
        $lang = post('lang') ?: voice_in_lang();
        $r = voice_in_pregenerate($lang);
        flash($r['ok'] ? '🔊 The incoming menu can now speak ' . e($langs[$lang] ?? $lang)
                       . ($r['made'] ? ' — ' . (int)$r['made'] . ' new line(s) made.' : ' — everything was already made.')
                       : (int)$r['failed'] . ' line(s) could not be made: ' . $r['error'],
              $r['ok'] ? 'success' : 'error');
        redirect('voice_setup.php');
    }

    if (post('do') === 'app_setup') {
        // Only makes (or refreshes) the application. Attaching is a separate
        // press against a number picked from the real list, because the two
        // fail for entirely different reasons and rolling them into one
        // button made a number problem look like an application problem.
        $r = voice_in_app_setup();
        flash($r['ok'] ? '✅ Application ready (' . e($r['app_id']) . ')'
                       . (!empty($r['adopted']) ? ' — the one that was already there was used, not a new one.' : '.')
                       . (!empty($r['duplicates']) ? ' ' . (int)$r['duplicates'] . ' spare application(s) also point here — delete them in the Vobiz console.' : '')
                       . ' Now pick a number below and press "Use this one".'
                       : 'Could not set up the application: ' . $r['error'],
              $r['ok'] ? 'success' : 'error');
        redirect('voice_setup.php');
    }

    if (post('do') === 'save') {
        foreach (['vobiz_auth_id', 'vobiz_caller_id', 'voice_lang', 'voice_hour_from', 'voice_hour_to',
                  'voice_cooldown_hours', 'voice_max_per_day', 'voice_balance_min',
                  'voice_tts_voice', 'voice_tts_month_cap'] as $k) {
            set_setting($k, post($k));
        }
        // An empty token box means "leave the saved one alone", not "delete
        // it" - otherwise every save of the hours would wipe the key.
        if (post('vobiz_auth_token') !== '') set_setting('vobiz_auth_token', post('vobiz_auth_token'));
        set_setting('voice_enabled', post('voice_enabled') ? '1' : '0');
        set_setting('voice_test_mode', post('voice_test_mode') ? '1' : '0');
        set_setting('voice_ivr', post('voice_ivr') ? '1' : '0');
        set_setting('voice_tts', post('voice_tts') ? '1' : '0');
        set_setting('vobiz_balance_cache', '');            // re-check against the new keys
        log_activity('voice_settings', 'reminder call settings saved');
        flash('Saved.');
        redirect('voice_setup.php');
    }

    // ---- the test call ----
    //
    // Deliberately NOT voice_call_send(): that one is about a customer who
    // owes money, and every guard in it is about them. A test is the owner
    // ringing themselves to hear the recording, so it takes a number typed
    // on this screen and goes through the provider directly.
    if (post('do') === 'test_call') {
        $to = post('test_mobile');
        $lang = post('test_lang') ?: setting('voice_lang', 'gu');
        if (!voice_mobile_ok($to)) { flash('That does not look like a 10-digit mobile number.', 'error'); redirect('voice_setup.php'); }
        if (!voice_configured()) { flash('Fill in the Vobiz Auth ID, token and caller ID first.', 'error'); redirect('voice_setup.php'); }
        // No calling-hours check here, deliberately. This dials the number
        // typed on this screen by the person who pressed the button - the
        // owner ringing their own phone to hear what it sounds like. The
        // hours exist to protect customers from being rung at a bad time,
        // and there is no customer in a test call.

        $amount = round((float)post('test_amount'), 2) ?: 12400.00;
        $plan = voice_audio_plan(post('test_name') ?: 'Test', $amount, $lang);
        $script = voice_script(post('test_name') ?: 'Test', $amount, $plan['lang']);
        if ($plan['fell_back']) flash('The ' . $langs[$lang] . ' voice could not be made, so this test goes out in English: ' . $plan['error'], 'error');
        $token = bin2hex(random_bytes(20));
        q("INSERT INTO voice_calls (party_id, mobile, amount, lang, script, provider, token, status, test_mode, created_by, audio_file, question_asked, started_at)
           VALUES (0,?,?,?,?,?,?,'queued',0,?,?,?,NOW())",
          [voice_e164($to), $amount, $plan['lang'], $script, setting('voice_provider', 'vobiz'), $token,
           $_SESSION['user_id'] ?? null, voice_audio_basename($plan['main']['url'] ?? ''), voice_ivr_on() ? 1 : 0]);
        $callId = (int)insert_id();

        [$uuid, $err] = voice_provider_call(voice_e164($to), $token);
        if ($uuid === null) {
            q("UPDATE voice_calls SET status = 'failed', error = ? WHERE id = ?", [mb_substr($err, 0, 255), $callId]);
            flash('The test call did not go: ' . $err, 'error');
        } else {
            q("UPDATE voice_calls SET call_uuid = ?, status = 'ringing' WHERE id = ?", [$uuid, $callId]);
            flash('📞 Ringing ' . e($to) . ' now — pick up and listen.');
        }
        log_activity('voice_test_call', $to . ' ' . ($uuid ?: $err));
        redirect('voice_setup.php');
    }

    // ---- make the voice, so it can be heard before any customer hears it ----
    if (post('do') === 'preview_voice') {
        $lang = post('preview_lang') ?: setting('voice_lang', 'gu');
        $amount = round((float)post('preview_amount'), 2) ?: 12400.00;
        $plan = voice_audio_plan(post('preview_name') ?: 'Test', $amount, $lang);
        set_setting('voice_preview_last', json_encode([
            'lang' => $plan['lang'], 'url' => $plan['main']['url'] ?? '',
            'question' => $plan['question']['url'] ?? '', 'error' => $plan['error'],
            'script' => voice_script(post('preview_name') ?: 'Test', $amount, $plan['lang']),
        ], JSON_UNESCAPED_UNICODE));
        flash($plan['fell_back'] ? 'The voice could not be made: ' . $plan['error'] : '🔊 Ready — play it below.',
              $plan['fell_back'] ? 'error' : 'success');
        redirect('voice_setup.php');
    }
}

// ---------- the page ----------
$lang = get('lang') ?: setting('voice_lang', 'gu');
if (!isset($langs[$lang])) $lang = 'gu';
$bal = voice_configured() ? voice_balance() : null;
$tts = voice_tts_usage();
$preview = json_decode(setting('voice_preview_last', ''), true) ?: null;
$myNums = voice_configured() ? voice_in_numbers() : ['ok' => false, 'error' => '', 'numbers' => []];
$diag = (get('check') === '1') ? voice_in_diagnose() : null;
$rejects = voice_in_rejects(8);
$attachTrail = json_decode((string)setting('vobiz_attach_trail', ''), true) ?: [];
$inVoice = voice_in_voice_status(voice_in_lang());
$pvMobile = (string)setting('voice_preview_caller', '');
$pv = ($pvMobile !== '' && voice_mobile_ok($pvMobile)) ? voice_in_preview($pvMobile) : null;
$hours = voice_hours();
$recent = all('SELECT v.*, p.name FROM voice_calls v LEFT JOIN parties p ON p.id = v.party_id
               ORDER BY v.id DESC LIMIT 20');

$page_title = 'Reminder Calls';
include __DIR__ . '/includes/header.php';
?>
<div class="card">
  <h2>📞 Payment Reminder Calls</h2>
  <div class="grid-stats">
    <div class="stat <?= voice_enabled() ? 's-ok' : 's-warn' ?>">
      <div class="stat-label">Calling</div><div class="stat-value" style="font-size:18px"><?= voice_enabled() ? 'On' : 'Off' ?></div></div>
    <div class="stat <?= voice_configured() ? 's-ok' : 's-bad' ?>">
      <div class="stat-label">Vobiz keys</div><div class="stat-value" style="font-size:18px"><?= voice_configured() ? 'Saved' : 'Missing' ?></div></div>
    <div class="stat <?= voice_test_mode() ? 's-warn' : 's-ok' ?>">
      <div class="stat-label">Test mode</div><div class="stat-value" style="font-size:18px"><?= voice_test_mode() ? 'On (nothing dials)' : 'Off (calls are real)' ?></div></div>
    <div class="stat <?= $bal && $bal['ok'] ? ($bal['balance'] > (float)setting('voice_balance_min', 100) ? 's-ok' : 's-bad') : '' ?>">
      <div class="stat-label">Vobiz balance</div>
      <div class="stat-value" style="font-size:18px"><?= $bal && $bal['ok'] ? '₹' . money($bal['balance']) : '—' ?></div></div>
    <div class="stat <?= voice_tts_enabled() ? ($tts['used'] >= $tts['cap'] ? 's-bad' : 's-ok') : 's-warn' ?>">
      <div class="stat-label">Gujarati voice</div>
      <div class="stat-value" style="font-size:18px">
        <?php if (!voice_tts_enabled()): ?>Off<?php else: ?><?= $tts['used'] ?> / <?= $tts['cap'] ?><?php endif; ?></div></div>
    <div class="stat <?= voice_ivr_on() ? 's-ok' : 's-warn' ?>">
      <div class="stat-label">Asks yes / no</div>
      <div class="stat-value" style="font-size:18px"><?= voice_ivr_on() ? 'Yes' : 'No' ?></div></div>
  </div>
  <?php if (!voice_tts_enabled()): ?>
  <div class="flash flash-error">
    The Gujarati voice needs the <strong>Gemini API key</strong> that the rest of the AI features use.
    Without it every call goes out in English. Put it in <a href="settings.php?cat=ai">Settings → AI</a>.
  </div>
  <?php endif; ?>
  <?php if (!voice_public_ok()): ?>
  <div class="flash flash-error">
    This site is not on <strong>https</strong>. Vobiz fetches the recordings and the call instructions over https only,
    and it skips anything it cannot fetch <em>without reporting an error</em> — so calls would ring, play silence, and hang up.
    Fix the site's address before switching calls on.
  </div>
  <?php endif; ?>
  <?php if (!$hours['legal']): ?>
  <div class="flash flash-error">
    Reminder calls are set to go out
    <?= $hours['all_day'] ? '<strong>at any hour of the day or night</strong>' : 'between <strong>' . $hours['from'] . ':00 and ' . $hours['to'] . ':00</strong>' ?>.
    The law allows calls to customers between <strong>9:00 and 21:00</strong> only.
    Fine while you are testing on your own number — put it back to 9–21 before real customers are called.
  </div>
  <?php endif; ?>
  <p class="muted" style="font-size:13px;margin:8px 0 0">
    Calls go out between <?= $hours['from'] ?>:00 and <?= $hours['to'] ?>:00,
    never twice to the same customer inside <?= voice_cooldown_hours() ?> hours,
    and never to anyone who has said not to call.
    Today: <?= voice_calls_today() ?> of <?= (int)setting('voice_max_per_day', 50) ?>.
  </p>
</div>

<div class="card">
  <h3>🗣 What the call says</h3>
  <p class="muted" style="font-size:13px">
    <strong>Vobiz cannot speak Gujarati or Hindi.</strong> Their own text-to-speech covers 16 languages —
    English, Danish, Dutch, French, German, Italian, Polish, Portuguese, Russian, Spanish, Swedish — and no Indian language.
    So the sentence is spoken by an AI voice that does have them, saved as an audio file, and played down the phone
    as ordinary audio. The same sentence is only ever made once; the second customer who owes the same amount costs nothing.
  </p>
  <pre style="white-space:pre-wrap;background:var(--bg);padding:12px;border-radius:10px;font-family:inherit;font-size:15px"><?= e(voice_script('રમેશભાઈ', 12400, $lang)) ?><?= voice_ivr_on() ? "\n\n" . e(voice_question($lang)['plain']) : '' ?></pre>
  <p class="muted" style="font-size:12px">
    The amount comes from the ledger and is written out in words before the voice ever sees it —
    ₹12,400 becomes “<?= e(voice_tokens_text(voice_amount_tokens(12400), $lang)) ?>”.
    The AI is given words to pronounce, never a number to work out.
  </p>

  <?php if (can('settings.edit')): ?>
  <h4>Hear it first</h4>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="do" value="preview_voice">
    <div class="grid-2">
      <label>Name<input name="preview_name" value="રમેશભાઈ"></label>
      <label>Amount<input name="preview_amount" type="number" step="0.01" value="12400"></label>
      <label>Language
        <select name="preview_lang">
          <?php foreach ($langs as $lk => $lv): ?>
            <option value="<?= $lk ?>" <?= $lang === $lk ? 'selected' : '' ?>><?= e($lv) ?></option>
          <?php endforeach; ?>
        </select></label>
    </div>
    <button class="btn btn-success mt" type="submit">🔊 Make the voice and play it</button>
  </form>
  <?php endif; ?>

  <?php if ($preview && !empty($preview['url'])): ?>
    <div class="mt">
      <p class="muted" style="font-size:12px">Last one made — <?= e($langs[$preview['lang']] ?? $preview['lang']) ?>:</p>
      <audio controls preload="none" src="<?= e($preview['url']) ?>" style="width:100%"></audio>
      <?php if (!empty($preview['question'])): ?>
        <p class="muted" style="font-size:12px;margin-top:8px">The question at the end:</p>
        <audio controls preload="none" src="<?= e($preview['question']) ?>" style="width:100%"></audio>
      <?php endif; ?>
    </div>
  <?php elseif ($preview && !empty($preview['error'])): ?>
    <div class="flash flash-error mt"><?= e($preview['error']) ?></div>
  <?php endif; ?>
</div>

<div class="card">
  <h3>🔢 The question at the end</h3>
  <?php if (!voice_ivr_on()): ?>
    <p class="muted">Switched off — the call just gives the reminder and hangs up.</p>
  <?php else: ?>
  <p class="muted" style="font-size:13px">After the reminder the call asks whether they will pay today, and waits ten seconds for one key.</p>
  <table class="table-sm">
    <thead><tr><th>They press</th><th>They hear</th><th>What gets written down</th></tr></thead>
    <tbody>
      <tr><td><strong>1</strong> — yes</td><td><?= e(voice_question($lang)['yes']) ?></td>
          <td><span class="badge badge-ok">A promise to pay today</span><br>
              <span class="muted" style="font-size:11px">Shows in Promises on the collection screen · no more reminders until the day is out ·
              the nightly job marks it kept or broken on its own</span></td></tr>
      <tr><td><strong>2</strong> — no</td><td><?= e(voice_question($lang)['no']) ?></td>
          <td><span class="badge badge-warn">“Said no”</span><br>
              <span class="muted" style="font-size:11px">Kept against the customer, so the next person to look knows the phone was answered</span></td></tr>
      <tr><td>nothing</td><td><?= e(voice_question($lang)['none']) ?></td>
          <td><span class="muted">Written down as “did not answer the question”</span></td></tr>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<?php if (can('settings.edit')): ?>
<div class="card">
  <h3>⚙️ Settings</h3>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="do" value="save">
    <div class="grid-2">
      <label>Vobiz Auth ID
        <input name="vobiz_auth_id" value="<?= e(setting('vobiz_auth_id')) ?>" placeholder="from the Vobiz console"></label>
      <label>Vobiz Auth Token
        <input name="vobiz_auth_token" type="password" autocomplete="new-password"
               placeholder="<?= setting('vobiz_auth_token') ? '•••••••• saved — leave blank to keep it' : 'not saved yet' ?>">
        <span class="muted" style="font-size:11px">Stored encrypted. Never shown again, never sent to the browser.</span></label>
      <label>Caller ID (the number customers see)
        <input name="vobiz_caller_id" value="<?= e(setting('vobiz_caller_id')) ?>" placeholder="919876543210"></label>
      <label>Language
        <select name="voice_lang">
          <?php foreach ($langs as $lk => $lv): ?>
            <option value="<?= $lk ?>" <?= setting('voice_lang', 'gu') === $lk ? 'selected' : '' ?>><?= e($lv) ?></option>
          <?php endforeach; ?>
        </select></label>
      <label>Call only from
        <input name="voice_hour_from" type="number" min="0" max="24" value="<?= (int)setting('voice_hour_from', 9) ?>"></label>
      <label>Call only until
        <input name="voice_hour_to" type="number" min="0" max="24" value="<?= (int)setting('voice_hour_to', 21) ?>">
        <span class="muted" style="font-size:11px">
          <strong>9 to 21</strong> is what the law allows for calls to customers, and is the default.
          <strong>0 to 24</strong> means any hour — useful while testing.
          The test call below ignores this setting either way.</span></label>
      <label>Same customer not called again for (hours)
        <input name="voice_cooldown_hours" type="number" min="1" max="72" value="<?= (int)setting('voice_cooldown_hours', 6) ?>"></label>
      <label>Most calls in a day
        <input name="voice_max_per_day" type="number" min="1" max="500" value="<?= (int)setting('voice_max_per_day', 50) ?>"></label>
      <label>Warn me when balance falls below (₹)
        <input name="voice_balance_min" type="number" min="0" value="<?= (int)setting('voice_balance_min', 100) ?>"></label>
      <label>AI voice
        <select name="voice_tts_voice">
          <?php foreach (['Kore' => 'Kore (woman, calm)', 'Leda' => 'Leda (woman, warm)', 'Aoede' => 'Aoede (woman, bright)',
                          'Charon' => 'Charon (man, steady)', 'Puck' => 'Puck (man, light)'] as $vk => $vv): ?>
            <option value="<?= $vk ?>" <?= setting('voice_tts_voice', 'Kore') === $vk ? 'selected' : '' ?>><?= e($vv) ?></option>
          <?php endforeach; ?>
        </select>
        <span class="muted" style="font-size:11px">Changing this makes every sentence again — listen before you switch.</span></label>
      <label>Most sentences made in a month
        <input name="voice_tts_month_cap" type="number" min="1" value="<?= (int)setting('voice_tts_month_cap', 2000) ?>">
        <span class="muted" style="font-size:11px">Past this, calls keep going out in English rather than run up a bill.</span></label>
      <label style="display:flex;align-items:center;gap:8px">
        <input type="checkbox" name="voice_tts" value="1" <?= (int)setting('voice_tts', 1) === 1 ? 'checked' : '' ?>>
        Speak Gujarati/Hindi with the AI voice</label>
      <label style="display:flex;align-items:center;gap:8px">
        <input type="checkbox" name="voice_ivr" value="1" <?= voice_ivr_on() ? 'checked' : '' ?>>
        Ask “will you pay today?” and record the answer</label>
      <label style="display:flex;align-items:center;gap:8px">
        <input type="checkbox" name="voice_test_mode" value="1" <?= voice_test_mode() ? 'checked' : '' ?>>
        Test mode — show what would be said, dial nothing</label>
      <label style="display:flex;align-items:center;gap:8px">
        <input type="checkbox" name="voice_enabled" value="1" <?= voice_enabled() ? 'checked' : '' ?>>
        Reminder calls are switched on</label>
    </div>
    <button class="btn btn-success mt" type="submit">Save</button>
  </form>
</div>

<div class="card">
  <h3>📥 Incoming calls — the shop's number answers by itself</h3>
  <p class="muted" style="font-size:13px">
    A Vobiz number does not hold a web address of its own. The address lives on an <strong>Application</strong>,
    and the number is pointed at one. Both steps are done from here.
  </p>
  <div class="grid-stats">
    <div class="stat <?= voice_in_on() ? 's-ok' : 's-warn' ?>">
      <div class="stat-label">Answering</div><div class="stat-value" style="font-size:18px"><?= voice_in_on() ? 'On' : 'Off' ?></div></div>
    <div class="stat <?= setting('vobiz_app_id') ? 's-ok' : 's-bad' ?>">
      <div class="stat-label">Application</div>
      <div class="stat-value" style="font-size:14px"><?= setting('vobiz_app_id') ? e(setting('vobiz_app_id')) : 'not made' ?></div></div>
    <div class="stat <?= setting('vobiz_inbound_number') ? 's-ok' : 's-bad' ?>">
      <div class="stat-label">Number</div>
      <div class="stat-value" style="font-size:16px"><?= setting('vobiz_inbound_number') ? e(setting('vobiz_inbound_number')) : 'none' ?></div></div>
    <div class="stat <?= voice_in_pending_count() ? 's-bad' : 's-ok' ?>">
      <div class="stat-label">Waiting for an answer</div>
      <div class="stat-value"><?= voice_in_pending_count() ?></div></div>
  </div>

  <?php if (can('settings.edit')): ?>
  <h4>🩺 Is it working?</h4>
  <p class="muted" style="font-size:13px">
    A call that cuts off the moment it connects means one link in the chain is broken.
    This walks all of them and names the broken one.
  </p>
  <p><a class="btn btn-outline" href="voice_setup.php?check=1#check">Check my setup</a></p>
  <?php if ($diag !== null): ?>
  <div id="check" class="table-wrap mb"><table class="table-sm">
    <tbody>
    <?php foreach ($diag as $d): ?>
      <tr>
        <td style="width:26px"><?= $d['ok'] ? '✅' : '❌' ?></td>
        <td><?= e($d['name']) ?></td>
        <td class="muted" style="font-size:12px"><?= e($d['detail']) ?>
          <?php if (!empty($d['attach']) && can('settings.edit')): ?>
            <form method="post" style="display:inline;margin-left:6px">
              <?= csrf_field() ?><input type="hidden" name="do" value="attach_number">
              <input type="hidden" name="number" value="<?= e($d['attach']) ?>">
              <button class="btn btn-sm btn-success" type="submit">Attach <?= e($d['attach']) ?> now</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>

  <?php if ($rejects): ?>
  <h4>📵 Calls that arrived and were turned away</h4>
  <p class="muted" style="font-size:12px">If your test call is in this list, the reason next to it is the whole answer.</p>
  <div class="table-wrap mb"><table class="table-sm">
    <thead><tr><th>When</th><th>Why</th></tr></thead>
    <tbody>
    <?php foreach ($rejects as $rj): ?>
      <tr><td style="white-space:nowrap"><?= e(dmy(substr($rj['created_at'], 0, 10))) ?>
            <span class="muted"><?= e(substr($rj['created_at'], 11, 5)) ?></span></td>
          <td><?= e($rj['details']) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>

  <?php if ($attachTrail && !setting('vobiz_inbound_number')): ?>
  <div class="flash flash-info">
    <strong>Do it in the Vobiz console instead — it is the same thing.</strong><br>
    Phone Numbers → <?= e($myNums['numbers'][0]['e164'] ?? 'your number') ?> →
    set its <strong>Application</strong> to <strong><?= e(setting('vobiz_app_id')) ?></strong>, and save.<br>
    Then press <strong>Check my setup</strong> above — it should turn green without anything else being done here.
  </div>
  <h4>What Vobiz said to each way of attaching it</h4>
  <p class="muted" style="font-size:12px">
    All of these are the same request written four ways. If every one was refused, the number is on your
    account but Vobiz is not letting this account point it at an application — that is theirs to fix.
    Send them this list and the application id.
  </p>
  <div class="table-wrap mb"><table class="table-sm">
    <thead><tr><th>Tried</th><th>Vobiz said</th></tr></thead>
    <tbody>
    <?php foreach ($attachTrail as $tr): ?>
      <tr><td style="font-size:12px"><?= e($tr['tried'] ?? '') ?></td>
          <td class="muted" style="font-size:12px"><?= e($tr['said'] ?? '') ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>

  <?php if (!setting('vobiz_inbound_number') && $myNums['numbers'] && can('settings.edit')): ?>
  <form method="post" class="mb">
    <?= csrf_field() ?><input type="hidden" name="do" value="inbound_save">
    <?php foreach (['voice_agent_numbers','voice_agent_timeout','voice_shop_open','voice_shop_close',
                    'voice_inbound_lang','voice_ivr_balance'] as $k): ?>
      <input type="hidden" name="<?= $k ?>" value="<?= e(setting($k)) ?>">
    <?php endforeach; ?>
    <input type="hidden" name="voice_inbound" value="<?= voice_in_on() ? '1' : '' ?>">
    <input type="hidden" name="vobiz_inbound_number" value="<?= e($myNums['numbers'][0]['e164']) ?>">
    <button class="btn btn-outline" type="submit">I attached it in the Vobiz console — remember <?= e($myNums['numbers'][0]['e164']) ?> here</button>
  </form>
  <?php endif; ?>

  <h4>Step 1 — point a number at this software</h4>
  <p class="muted" style="font-size:13px">
    Only a number <strong>Vobiz sold you</strong> can be attached — your own mobile cannot.
    These are the ones on your account right now.
  </p>

  <?php if (!voice_configured()): ?>
    <p class="muted">Save the Vobiz keys above first.</p>
  <?php elseif (!$myNums['ok']): ?>
    <div class="flash flash-error">Could not read your numbers: <?= e($myNums['error']) ?></div>
  <?php elseif (!$myNums['numbers']): ?>
    <div class="flash flash-error">
      This Vobiz account owns <strong>no numbers yet</strong>. Buy one in the Vobiz console
      (Phone Numbers → Buy), then come back here — it will appear in this list.
    </div>
  <?php else: ?>
    <div class="table-wrap"><table class="table-sm">
      <thead><tr><th>Number</th><th>Can take calls</th><th>Now pointed at</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($myNums['numbers'] as $n):
        $mineNow = $n['app_id'] !== '' && $n['app_id'] === setting('vobiz_app_id');
        $usable = $n['voice'] && !$n['blocked'] && (!$n['kyc_need'] || $n['kyc_done']); ?>
        <tr>
          <td><strong><?= e($n['e164']) ?></strong>
            <?php if ($n['status']): ?><br><span class="muted" style="font-size:11px"><?= e($n['status']) ?></span><?php endif; ?></td>
          <td>
            <?php if ($n['blocked']): ?><span class="badge badge-bad">blocked</span>
            <?php elseif (!$n['voice']): ?><span class="badge badge-bad">no voice</span>
            <?php elseif ($n['kyc_need'] && !$n['kyc_done']): ?><span class="badge badge-warn">KYC not done</span>
            <?php else: ?><span class="badge badge-ok">yes</span><?php endif; ?>
          </td>
          <td>
            <?php if ($mineNow): ?><span class="badge badge-ok">✔ this software</span>
            <?php elseif ($n['app_id']): ?><span class="muted" style="font-size:11px">another application</span>
            <?php else: ?><span class="muted">nothing</span><?php endif; ?>
          </td>
          <td>
            <?php if (!$mineNow && can('settings.edit')): ?>
            <form method="post" style="display:inline">
              <?= csrf_field() ?><input type="hidden" name="do" value="attach_number">
              <input type="hidden" name="number" value="<?= e($n['e164']) ?>">
              <button class="btn btn-sm btn-success" type="submit" <?= $usable ? '' : 'disabled title="This number cannot take calls yet"' ?>>Use this one</button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>

  <?php if (can('settings.edit')): ?>
  <p class="muted" style="font-size:12px;margin-top:10px">
    <?= setting('vobiz_app_id') ? 'Application already made.' : 'No application yet.' ?>
    The button below finds the one that already points here and uses it, and only makes a new one if there is none —
    so pressing it twice is safe.
  </p>
  <form method="post" class="mb">
    <?= csrf_field() ?><input type="hidden" name="do" value="app_setup">
    <button class="btn btn-outline" type="submit" <?= voice_configured() ? '' : 'disabled' ?>>
      <?= setting('vobiz_app_id') ? 'Find / refresh the application' : 'Make the application' ?></button>
  </form>
  <?php endif; ?>

  <h4>Step 2 — how it should answer</h4>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="do" value="inbound_save">
    <div class="grid-2">
      <label>Language of the menu
        <select name="voice_inbound_lang">
          <?php foreach ($langs as $lk => $lv): ?>
            <option value="<?= $lk ?>" <?= voice_in_lang() === $lk ? 'selected' : '' ?>><?= e($lv) ?></option>
          <?php endforeach; ?>
        </select></label>
      <label>When a caller asks for their balance
        <select name="voice_ivr_balance">
          <?php foreach (['speak' => 'Read the amount out to them',
                          'whatsapp' => 'Send it to their WhatsApp instead (safer)',
                          'off' => 'Do not answer money questions by phone'] as $mk => $mv): ?>
            <option value="<?= $mk ?>" <?= setting('voice_ivr_balance', 'speak') === $mk ? 'selected' : '' ?>><?= e($mv) ?></option>
          <?php endforeach; ?>
        </select>
        <span class="muted" style="font-size:11px">Caller ID can be faked. Reading the figure out trusts whoever is on the line;
          sending it to WhatsApp needs their actual SIM.</span></label>
      <label>Ring these phones for "talk to us"
        <input name="voice_agent_numbers" value="<?= e(setting('voice_agent_numbers')) ?>" placeholder="9824537749, 9825012345">
        <span class="muted" style="font-size:11px">Comma separated. They ring together; first to pick up gets the call.</span></label>
      <label>Ring for how many seconds
        <input name="voice_agent_timeout" type="number" min="10" max="60" value="<?= (int)setting('voice_agent_timeout', 25) ?>"></label>
      <label>Shop opens at
        <input name="voice_shop_open" type="number" min="0" max="24" value="<?= (int)setting('voice_shop_open', 9) ?>"></label>
      <label>Shop closes at
        <input name="voice_shop_close" type="number" min="0" max="24" value="<?= (int)setting('voice_shop_close', 21) ?>">
        <span class="muted" style="font-size:11px">Outside these hours orders and complaints are still recorded — only "talk to us" takes a message instead of ringing a phone.</span></label>
      <label style="display:flex;align-items:center;gap:8px">
        <input type="checkbox" name="voice_inbound" value="1" <?= voice_in_on() ? 'checked' : '' ?>>
        Answer incoming calls</label>
    </div>
    <button class="btn btn-success mt" type="submit">Save</button>
  </form>
  <?php endif; ?>

  <h4>🔊 The voice the menu speaks</h4>
  <?php if ($inVoice['ready']): ?>
    <div class="flash flash-info">✅ Ready — callers hear <strong><?= e($langs[voice_in_lang()]) ?></strong>.</div>
  <?php else: ?>
    <div class="flash flash-error">
      <strong>Callers are hearing English.</strong>
      <?= (int)$inVoice['done'] ?> of <?= (int)$inVoice['total'] ?> lines have been made in <?= e($langs[voice_in_lang()]) ?>.
      <br>An incoming call arrives unannounced — there is no moment to make speech while somebody is already on the line,
      so the menu has to be made <em>before</em> the first call. Press the button once.
    </div>
  <?php endif; ?>
  <?php if (can('settings.edit')): ?>
  <form method="post" class="mb">
    <?= csrf_field() ?><input type="hidden" name="do" value="make_in_voice">
    <input type="hidden" name="lang" value="<?= e(voice_in_lang()) ?>">
    <button class="btn <?= $inVoice['ready'] ? 'btn-outline' : 'btn-success' ?>" type="submit"
            <?= voice_tts_enabled() ? '' : 'disabled' ?>>
      🔊 <?= $inVoice['ready'] ? 'Make it again' : 'Make the menu speak ' . e($langs[voice_in_lang()]) ?></button>
    <?php if (!voice_tts_enabled()): ?><span class="muted"> — the Gemini key is missing (Settings → AI)</span><?php endif; ?>
    <span class="muted" style="font-size:12px"> Do this again if you change the menu language or the AI voice.</span>
  </form>
  <?php endif; ?>

  <h4 id="hear">🔎 What would this caller hear?</h4>
  <p class="muted" style="font-size:13px">
    Type a mobile number and see exactly what the menu would say to it — without ringing anybody.
    This is the quickest way to check a customer who says the balance read out was wrong.
  </p>
  <?php if (can('settings.edit')): ?>
  <form method="post" class="mb">
    <?= csrf_field() ?><input type="hidden" name="do" value="preview_caller">
    <div class="grid-2">
      <label>Their mobile number<input name="mobile" value="<?= e($pvMobile) ?>" placeholder="9824537749"></label>
    </div>
    <button class="btn btn-outline mt" type="submit">Show me</button>
  </form>
  <?php endif; ?>
  <?php if ($pv): ?>
    <?php if (!$pv['party']): ?>
      <div class="flash flash-error">
        <strong><?= e($pv['mobile']) ?> is not matched to any customer.</strong>
        The caller would be told their number is not registered. Add the number to their party record.
      </div>
    <?php else: ?>
      <div class="flash flash-info">
        Matched to <strong><a href="customer.php?id=<?= (int)$pv['party']['id'] ?>"><?= e($pv['party']['name']) ?></a></strong>
        (<?= e($pv['party']['mobile']) ?>) — outstanding <strong>₹<?= money($pv['due']) ?></strong>
      </div>
    <?php endif; ?>
    <?php if (!empty($pv['short'])): ?>
      <div class="flash flash-error">
        <strong><?= count($pv['short']) ?> customer record(s) have a mobile number shorter than 10 digits.</strong>
        A caller is matched on the last 10 digits of what is stored, so a short one matches
        <em>anybody</em> whose number happens to end with it — and that caller then hears the wrong ledger.
        Fix or clear these:
        <ul style="margin:6px 0 0">
        <?php foreach ($pv['short'] as $o): ?>
          <li><a href="customer.php?id=<?= (int)$o['id'] ?>"><?= e($o['name']) ?></a> — [<?= e($o['mobile']) ?>]</li>
        <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>
    <?php if (count($pv['others']) > 1): ?>
      <div class="flash flash-error">
        <strong><?= count($pv['others']) ?> customer records share this number.</strong>
        The call reads the ledger of whichever one it matched, so the figure can look wrong when it is simply
        the wrong record. Merge them, or clear the number from the ones it does not belong to:
        <ul style="margin:6px 0 0">
        <?php foreach ($pv['others'] as $o): ?>
          <li><a href="customer.php?id=<?= (int)$o['id'] ?>"><?= e($o['name']) ?></a>
              — <?= e($o['mobile']) ?> — ₹<?= money($o['due']) ?></li>
        <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>
    <div class="table-wrap mb"><table class="table-sm">
      <tbody>
        <tr><td style="width:90px" class="muted">Greeting</td><td><?= e($pv['lines']['greeting']) ?></td></tr>
        <tr><td class="muted">Menu</td><td><?= e($pv['lines']['menu']) ?></td></tr>
        <tr><td class="muted">Press 1</td><td><strong><?= e($pv['lines']['press_1']) ?></strong></td></tr>
      </tbody>
    </table></div>
  <?php endif; ?>

  <h4>What a caller hears</h4>
  <pre style="white-space:pre-wrap;background:var(--bg);padding:12px;border-radius:10px;font-family:inherit;font-size:14px"><?php
    $w = voice_in_words(voice_in_lang(), ['name' => 'રમેશભાઈ']);
    echo e($w['welcome_name']) . "\n" . e($w['menu']);
  ?></pre>
  <div class="table-wrap"><table class="table-sm">
    <thead><tr><th>Key</th><th>What happens</th><th>What the shop gets</th></tr></thead>
    <tbody>
      <tr><td><strong>1</strong></td><td>Their outstanding amount</td><td><span class="muted">nothing to do — answered itself</span></td></tr>
      <tr><td><strong>2</strong></td><td>They say what they want to order</td><td><span class="badge badge-ok">a lead, with the recording</span></td></tr>
      <tr><td><strong>3</strong></td><td>They describe the fault</td><td><span class="badge badge-ok">a ticket, with the recording</span></td></tr>
      <tr><td><strong>4</strong></td><td>They ask a price or whether something is in stock</td><td><span class="badge badge-ok">a lead, with the recording</span></td></tr>
      <tr><td><strong>5</strong></td><td>Where their order or repair has got to</td><td><span class="muted">nothing to do</span></td></tr>
      <tr><td><strong>9</strong></td><td>The phones above ring; nobody answers → they leave a message</td><td><span class="badge badge-warn">on the waiting list</span></td></tr>
    </tbody>
  </table></div>
  <p class="muted" style="font-size:12px">Everything a caller leaves shows on <a href="voice_calls.php">Calls</a> until somebody marks it done.</p>
</div>


<div class="card">
  <h3>🧪 Try it on your own phone first</h3>
  <p class="muted" style="font-size:13px">This dials for real, even when test mode is on — that is the point of it.
    Ring yourself, listen to the whole thing, and only then let it near a customer.
    <strong>It works at any hour</strong>: the calling-hours setting protects customers, and there is no customer here.</p>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="do" value="test_call">
    <div class="grid-2">
      <label>My mobile number<input name="test_mobile" required placeholder="9876543210"></label>
      <label>Name to say<input name="test_name" value="<?= e(current_user()['name'] ?? '') ?>"></label>
      <label>Amount to say<input name="test_amount" type="number" step="0.01" value="12400"></label>
      <label>Language
        <select name="test_lang">
          <?php foreach ($langs as $lk => $lv): ?>
            <option value="<?= $lk ?>" <?= $lang === $lk ? 'selected' : '' ?>><?= e($lv) ?></option>
          <?php endforeach; ?>
        </select></label>
    </div>
    <button class="btn btn-success mt" type="submit" <?= voice_configured() ? '' : 'disabled' ?>>📞 Call me now</button>
    <?php if (!voice_configured()): ?><span class="muted"> — save the Vobiz keys first</span><?php endif; ?>
  </form>
</div>
<?php endif; ?>

<div class="card">
  <h3>📋 Before the first customer is called</h3>
  <p class="muted" style="font-size:13px">These are on Vobiz's side and the regulator's, not this software's. It cannot check them for you.</p>
  <ul style="line-height:1.9">
    <li><strong>KYC finished on Vobiz</strong> — an unverified account cannot place outbound calls in India.</li>
    <li><strong>Caller ID approved</strong> — the number customers see has to be verified with the provider, or the call shows an unknown number and gets cut.</li>
    <li><strong>DLT registration</strong> — the entity registered on a DLT portal, and the reminder script filed as the template being played. A payment reminder to your own customer is transactional, not promotional; it still has to be on file.</li>
    <li><strong>9am–9pm only</strong> — enforced above, and it applies to the test call too.</li>
    <li><strong>DND</strong> — transactional calls to your own customers are allowed on DND numbers, but a customer who says stop must be marked "do not call" on their party page, and that switch is separate from the WhatsApp opt-out.</li>
    <li><strong>Keep the recordings honest</strong> — the amount spoken comes from the ledger. Never record a clip that claims anything else.</li>
  </ul>
</div>

<div class="card">
  <h3>🕘 Last 20 calls</h3>
  <p class="muted" style="font-size:12px">📥 came in · 📤 went out. The full list, with recordings, is on <a href="voice_calls.php">Calls</a>.</p>
  <?php if (!$recent): ?><p class="muted">No calls yet.</p><?php else: ?>
  <div class="table-wrap"><table class="table-sm">
    <thead><tr><th></th><th>When</th><th>Who</th><th>What for</th><th>Result</th><th class="num">Sec</th></tr></thead>
    <tbody>
    <?php foreach ($recent as $r): $in = ($r['direction'] ?? 'out') === 'in'; ?>
      <tr>
        <td title="<?= $in ? 'Incoming' : 'Outgoing' ?>"><?= $in ? '📥' : '📤' ?></td>
        <td style="white-space:nowrap"><?= e(dmy(substr($r['created_at'], 0, 10))) ?>
          <br><span class="muted" style="font-size:11px"><?= e(substr($r['created_at'], 11, 5)) ?></span></td>
        <td>
          <?php if ($r['name']): ?><?= e($r['name']) ?>
          <?php elseif ($in): ?><span class="muted">unknown caller</span>
          <?php else: ?><span class="muted">test</span><?php endif; ?>
          <br><span class="muted" style="font-size:11px"><?= e($in ? ($r['from_number'] ?: $r['mobile']) : $r['mobile']) ?> · <?= e($r['lang']) ?></span></td>
        <td>
          <?php if ($in): ?>
            <?= e(voice_intent_label($r['intent'])) ?>
            <?php if ($r['ivr_path']): ?><br><span class="muted" style="font-size:11px">pressed <?= e($r['ivr_path']) ?></span><?php endif; ?>
            <?php if ($r['wa_sent_at']): ?><br><span class="badge badge-ok" style="font-size:10px">📲 sent on WhatsApp</span><?php endif; ?>
          <?php else: ?>
            💰 ₹<?= money($r['amount']) ?>
            <?php if ($r['response'] === 'yes'): ?><br><span class="badge badge-ok" style="font-size:10px">✅ Yes — today</span>
            <?php elseif ($r['response'] === 'no'): ?><br><span class="badge badge-bad" style="font-size:10px">❌ No</span>
            <?php elseif ($r['response'] === 'none'): ?><br><span class="muted" style="font-size:11px">no key pressed</span><?php endif; ?>
          <?php endif; ?>
        </td>
        <td><?= e(voice_status_label($r['status'])) ?>
          <?= $r['error'] ? '<br><span class="muted" style="font-size:11px">' . e($r['error']) . '</span>' : '' ?></td>
        <td class="num"><?= (int)$r['duration'] ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
