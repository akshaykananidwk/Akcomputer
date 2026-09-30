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

/**
 * Which settings the form that was just submitted actually carried.
 *
 * The page is a list of steps now, so one form holds the keys, another the
 * hours, another the switch. Every form used to post every setting - and an
 * unticked checkbox posts NOTHING, which reads exactly the same as "that box
 * was not on this form". So saving the hours on one step would have switched
 * calling off on another. Each form therefore names what it owns, in own[],
 * and nothing else is touched.
 *
 * A form that names nothing is taken to own everything, which is what the old
 * single form did; a test keeps every form on this page honest about it.
 */
function voice_setup_own(array $all) {
    $own = array_map('strval', (array)post('own', []));
    if (!$own) return $all;
    return array_values(array_intersect($all, $own));
}
function voice_setup_owns($key) {
    $own = array_map('strval', (array)post('own', []));
    return !$own || in_array((string)$key, $own, true);
}

// ---------- actions ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_perm('settings.edit');

    if (post('do') === 'inbound_save') {
        foreach (voice_setup_own(['voice_agent_numbers', 'voice_agent_timeout', 'voice_shop_open',
                                  'voice_shop_close', 'voice_inbound_lang', 'voice_ivr_balance',
                                  'vobiz_inbound_number']) as $k) set_setting($k, post($k));
        if (voice_setup_owns('voice_inbound')) set_setting('voice_inbound', post('voice_inbound') ? '1' : '0');
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

    if (post('do') === 'make_talk_voice') {
        $r = voice_talk_pregenerate(setting('voice_lang', 'gu'));
        flash($r['ok'] ? '🗣 The conversation can now be spoken — ' . (int)$r['made'] . ' new line(s) made.'
                       : (int)$r['failed'] . ' line(s) could not be made: ' . $r['error'],
              $r['ok'] ? 'success' : 'error');
        redirect('voice_setup.php');
    }

    if (post('do') === 'save_record') {
        set_setting('voice_record_all', post('voice_record_all') ? '1' : '0');
        set_setting('voice_record_max', (string)max(30, min(3600, (int)post('voice_record_max'))));
        log_activity('voice_record_settings', post('voice_record_all') ? 'whole-call recording on' : 'off');
        flash('Saved.');
        redirect('voice_setup.php');
    }

    if (post('do') === 'save_talk') {
        foreach (['voice_talk_turns', 'voice_talk_max_days', 'voice_talk_secs', 'voice_talk_month_cap'] as $k)
            set_setting($k, (string)max(1, (int)post($k)));
        set_setting('voice_talk', post('voice_talk') ? '1' : '0');
        log_activity('voice_talk_settings', 'conversation settings saved');
        flash('Saved.');
        redirect('voice_setup.php');
    }

    if (post('do') === 'make_greetings') {
        $r = voice_in_greet_make();
        flash($r['failed'] ? $r['made'] . ' made, then stopped: ' . $r['error']
                           : ($r['made'] ? '🗣 ' . $r['made'] . ' greeting(s) made — ' . $r['left'] . ' still to go. '
                                         . 'The rest are made by themselves every half hour.'
                                        : 'Everybody who is likely to ring is already greeted by name.'),
              $r['failed'] ? 'error' : 'success');
        redirect('voice_setup.php');
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
        foreach (voice_setup_own(['vobiz_auth_id', 'vobiz_caller_id', 'voice_lang', 'voice_hour_from',
                                  'voice_hour_to', 'voice_cooldown_hours', 'voice_max_per_day',
                                  'voice_balance_min', 'voice_tts_voice', 'voice_tts_month_cap']) as $k) {
            set_setting($k, post($k));
        }
        // An empty token box means "leave the saved one alone", not "delete
        // it" - otherwise every save of the hours would wipe the key.
        if (voice_setup_owns('vobiz_auth_token') && post('vobiz_auth_token') !== '')
            set_setting('vobiz_auth_token', post('vobiz_auth_token'));
        foreach (['voice_enabled', 'voice_test_mode', 'voice_ivr', 'voice_tts'] as $k)
            if (voice_setup_owns($k)) set_setting($k, post($k) ? '1' : '0');
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

// ---------- the five steps, and where the owner has got to ----------
//
// This screen used to be one long scroll of fifteen headings, all open at
// once, with the day-to-day list at the very bottom. It is a list of steps
// now: the one still to be done is open, the finished ones are folded away,
// and everything that is only reference sits at the end behind a heading the
// owner never has to open twice.
$testedOnce = (int)val("SELECT COUNT(*) FROM voice_calls WHERE direction = 'out' AND party_id = 0") > 0;
$steps = [
    ['key' => 'keys',  'n' => 1, 'title' => 'Vobiz keys',
     'done' => voice_configured(),
     'sub'  => voice_configured() ? 'Saved — caller ID ' . setting('vobiz_caller_id') : 'Not saved yet'],
    ['key' => 'voice', 'n' => 2, 'title' => 'The Gujarati / Hindi voice',
     'done' => voice_tts_enabled() && $inVoice['ready'],
     'sub'  => !voice_tts_enabled() ? 'Off — every call would go out in English'
               : ($inVoice['ready'] ? 'Ready in ' . ($langs[voice_in_lang()] ?? voice_in_lang())
                                    : (int)$inVoice['done'] . ' of ' . (int)$inVoice['total'] . ' menu lines made')],
    ['key' => 'in',    'n' => 3, 'title' => "The shop's number answers by itself",
     'done' => setting('vobiz_app_id') !== '' && setting('vobiz_inbound_number') !== '' && voice_in_on(),
     'sub'  => setting('vobiz_inbound_number') !== ''
               ? (voice_in_on() ? setting('vobiz_inbound_number') . ' is answering'
                                : setting('vobiz_inbound_number') . ' is attached but answering is switched off')
               : 'No number is pointed at this software yet'],
    ['key' => 'test',  'n' => 4, 'title' => 'Ring your own phone and listen',
     'done' => $testedOnce,
     'sub'  => $testedOnce ? 'Done at least once' : 'Never tested'],
    ['key' => 'go',    'n' => 5, 'title' => 'Let it call customers',
     'done' => voice_enabled() && !voice_test_mode(),
     'sub'  => !voice_enabled() ? 'Calling is switched off'
               : (voice_test_mode() ? 'Test mode is ON — calls are recorded but nobody is dialled'
                                    : 'Live — calls go out between ' . $hours['from'] . ':00 and ' . $hours['to'] . ':00')],
];
$doneCount = count(array_filter($steps, fn($x) => $x['done']));
// Only the first unfinished step is open. Once all five are done nothing is
// open, and the screen is the short everyday one.
$firstOpen = '';
foreach ($steps as $st) if (!$st['done']) { $firstOpen = $st['key']; break; }
$stepOpen = function ($key) use ($firstOpen) { return $firstOpen === $key ? ' open' : ''; };
$recent = all('SELECT v.*, p.name FROM voice_calls v LEFT JOIN parties p ON p.id = v.party_id
               ORDER BY v.id DESC LIMIT 20');

$page_title = 'Reminder Calls';
include __DIR__ . '/includes/header.php';
?>

<style>
/* A step is a card that folds. Only the step still to be done is open, so the
   screen is short once the setting up is finished. */
.vstep { border:1px solid var(--line); border-radius:12px; margin-bottom:10px; background:var(--card) }
.vstep > summary { display:flex; align-items:center; gap:10px; padding:12px 14px; cursor:pointer;
                   list-style:none; font-weight:700 }
.vstep > summary::-webkit-details-marker { display:none }
.vstep[open] > summary { border-bottom:1px solid var(--line) }
.vstep .vbody { padding:14px }
.vnum { flex:0 0 26px; width:26px; height:26px; border-radius:50%; display:grid; place-items:center;
        font-size:13px; background:var(--bg); border:1px solid var(--line) }
.vstep.vdone .vnum { background:#16a34a; color:#fff; border-color:#16a34a }
.vsub { font-weight:400; font-size:12px; opacity:.7; display:block }
.vstep > summary > span.vt { flex:1; min-width:0 }
</style>

<div class="card">
  <h2>📞 Reminder Calls</h2>
  <div class="grid-stats">
    <a class="stat <?= voice_enabled() && !voice_test_mode() ? 's-ok' : 's-warn' ?>" href="voice_calls.php">
      <div class="stat-label">Calling</div><div class="stat-value" style="font-size:18px"><?php
        echo !voice_enabled() ? 'Off' : (voice_test_mode() ? 'Test only' : 'Live'); ?></div></a>
    <a class="stat <?= voice_in_pending_count() ? 's-bad' : 's-ok' ?>" href="voice_calls.php?f=todo">
      <div class="stat-label">Callers waiting for an answer</div>
      <div class="stat-value"><?= voice_in_pending_count() ?></div></a>
    <div class="stat <?= $bal && $bal['ok'] ? ($bal['balance'] > (float)setting('voice_balance_min', 100) ? 's-ok' : 's-bad') : '' ?>">
      <div class="stat-label">Vobiz balance</div>
      <div class="stat-value" style="font-size:18px"><?= $bal && $bal['ok'] ? '₹' . money($bal['balance']) : '—' ?></div></div>
    <div class="stat">
      <div class="stat-label">Calls today</div>
      <div class="stat-value"><?= voice_calls_today() ?> <span style="font-size:13px;opacity:.6">/ <?= (int)setting('voice_max_per_day', 50) ?></span></div></div>
  </div>

  <?php if (voice_queue_count()): ?>
  <div class="flash flash-info">📞 <?= voice_queue_count() ?> call(s) are queued and go out
    <?= (int)setting('voice_bulk_per_run', 5) ?> at a time over the next few minutes.</div>
  <?php endif; ?>

  <p class="muted" style="font-size:13px;margin:10px 0 0">
    Change what the phone says — every sentence, and which menu options are offered — on
    <a href="voice_words.php">What the Phone Says</a>.<br>
    Pick who to ring on the <a href="collection.php">Collection Queue</a> or the
    <a href="reports.php?r=aging">aging report</a> — tick the rows and press <strong>📞 Call the selected</strong>.
    Everything that came in or went out is on <a href="voice_calls.php">Calls</a>.
  </p>
</div>

<?php /* ================= the five steps ================= */ ?>
<div class="card">
  <h3 style="margin-bottom:4px">🪜 Setting up — <?= $doneCount ?> of <?= count($steps) ?> done</h3>
  <p class="muted" style="font-size:12px;margin-top:0">
    <?php if ($doneCount === count($steps)): ?>
      All done. Open a step only if you want to change something.
    <?php else: ?>
      The step that still needs doing is open below. Do them in order.
    <?php endif; ?>
  </p>

  <?php $stTitle = function ($st) { ?>
    <summary><span class="vnum"><?= $st['done'] ? '✓' : (int)$st['n'] ?></span>
      <span class="vt"><?= e($st['title']) ?><span class="vsub"><?= e($st['sub']) ?></span></span></summary>
  <?php }; ?>

  <?php /* ---- 1. the keys ---- */ $st = $steps[0]; ?>
  <details class="vstep <?= $st['done'] ? 'vdone' : '' ?>"<?= $stepOpen('keys') ?>>
    <?php $stTitle($st); ?>
    <div class="vbody">
      <?php if (!can('settings.edit')): ?>
        <p class="muted">Only someone who can change settings may edit these.</p>
      <?php else: ?>
      <p class="muted" style="font-size:13px">From the Vobiz console. The token is stored encrypted and is never
        sent back to a browser.</p>
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="do" value="save">
        <?php foreach (['vobiz_auth_id', 'vobiz_auth_token', 'vobiz_caller_id', 'voice_lang'] as $k): ?>
          <input type="hidden" name="own[]" value="<?= $k ?>"><?php endforeach; ?>
        <div class="grid-2">
          <label>Vobiz Auth ID
            <input name="vobiz_auth_id" value="<?= e(setting('vobiz_auth_id')) ?>" placeholder="from the Vobiz console"></label>
          <label>Vobiz Auth Token
            <input name="vobiz_auth_token" type="password" autocomplete="new-password"
                   placeholder="<?= setting('vobiz_auth_token') ? '•••••••• saved — leave blank to keep it' : 'not saved yet' ?>"></label>
          <label>Caller ID — the number customers see
            <input name="vobiz_caller_id" value="<?= e(setting('vobiz_caller_id')) ?>" placeholder="919876543210"></label>
          <label>Language of the reminder
            <select name="voice_lang">
              <?php foreach ($langs as $lk => $lv): ?>
                <option value="<?= $lk ?>" <?= setting('voice_lang', 'gu') === $lk ? 'selected' : '' ?>><?= e($lv) ?></option>
              <?php endforeach; ?>
            </select></label>
        </div>
        <button class="btn btn-success mt" type="submit">Save</button>
      </form>
      <?php endif; ?>
      <?php if (!voice_public_ok()): ?>
      <div class="flash flash-error mt">
        This site is not on <strong>https</strong>. Vobiz fetches the recordings and the call instructions over
        https only, and skips anything it cannot fetch <em>without reporting an error</em> — so calls would ring,
        play silence and hang up. Fix the site's address before switching calls on.
      </div>
      <?php endif; ?>
    </div>
  </details>

  <?php /* ---- 2. the voice ---- */ $st = $steps[1]; ?>
  <details class="vstep <?= $st['done'] ? 'vdone' : '' ?>"<?= $stepOpen('voice') ?>>
    <?php $stTitle($st); ?>
    <div class="vbody">
      <p class="muted" style="font-size:13px">
        Vobiz cannot speak Gujarati or Hindi — their own voice covers 16 languages and no Indian one. So the
        sentence is spoken by an AI voice, saved as an audio file, and played down the phone. Each sentence is
        made once; the next customer who owes the same amount costs nothing.
      </p>
      <?php if (!voice_tts_enabled()): ?>
      <div class="flash flash-error">
        This needs the <strong>Gemini API key</strong> the rest of the AI features use.
        Put it in <a href="settings.php?cat=ai">Settings → AI</a>, then come back.
      </div>
      <?php else: ?>
      <div class="flash flash-info">Sentences made this month: <?= $tts['used'] ?> of <?= $tts['cap'] ?>.
        Past the cap, calls keep going out in English rather than run up a bill.</div>
      <?php endif; ?>

      <?php if (can('settings.edit')): ?>
      <form method="post" class="mb">
        <?= csrf_field() ?><input type="hidden" name="do" value="save">
        <?php foreach (['voice_tts', 'voice_tts_voice', 'voice_tts_month_cap'] as $k): ?>
          <input type="hidden" name="own[]" value="<?= $k ?>"><?php endforeach; ?>
        <div class="grid-2">
          <label>AI voice
            <select name="voice_tts_voice">
              <?php foreach (['Kore' => 'Kore (woman, calm)', 'Leda' => 'Leda (woman, warm)', 'Aoede' => 'Aoede (woman, bright)',
                              'Charon' => 'Charon (man, steady)', 'Puck' => 'Puck (man, light)'] as $vk => $vv): ?>
                <option value="<?= $vk ?>" <?= setting('voice_tts_voice', 'Kore') === $vk ? 'selected' : '' ?>><?= e($vv) ?></option>
              <?php endforeach; ?>
            </select>
            <span class="muted" style="font-size:11px">Changing this makes every sentence again — listen before you switch.</span></label>
          <label>Most sentences in a month
            <input name="voice_tts_month_cap" type="number" min="1" value="<?= (int)setting('voice_tts_month_cap', 2000) ?>"></label>
          <label style="display:flex;align-items:center;gap:8px">
            <input type="checkbox" name="voice_tts" value="1" <?= (int)setting('voice_tts', 1) === 1 ? 'checked' : '' ?>>
            Speak Gujarati / Hindi with the AI voice</label>
        </div>
        <button class="btn btn-success mt" type="submit">Save</button>
      </form>

      <p><a class="btn btn-outline" href="voice_words.php">🗣 Change what the phone says</a>
        <span class="muted" style="font-size:12px"> — every sentence, and which menu options are offered.</span></p>

      <h4>The menu the callers hear</h4>
      <?php if ($inVoice['ready']): ?>
        <div class="flash flash-info">✅ Ready — callers hear <strong><?= e($langs[voice_in_lang()]) ?></strong>.</div>
      <?php else: ?>
        <div class="flash flash-error">
          <strong>Callers are hearing English.</strong>
          <?= (int)$inVoice['done'] ?> of <?= (int)$inVoice['total'] ?> lines have been made in <?= e($langs[voice_in_lang()]) ?>.
          An incoming call arrives unannounced — there is no moment to make speech while somebody is already on
          the line, so the menu has to be made <em>before</em> the first call. Press the button once.
        </div>
      <?php endif; ?>
      <form method="post" class="mb">
        <?= csrf_field() ?><input type="hidden" name="do" value="make_in_voice">
        <input type="hidden" name="lang" value="<?= e(voice_in_lang()) ?>">
        <button class="btn <?= $inVoice['ready'] ? 'btn-outline' : 'btn-success' ?>" type="submit"
                <?= voice_tts_enabled() ? '' : 'disabled' ?>>
          🔊 <?= $inVoice['ready'] ? 'Make it again' : 'Make the menu speak ' . e($langs[voice_in_lang()]) ?></button>
        <span class="muted" style="font-size:12px"> Do this again if you change the menu language or the AI voice.</span>
      </form>

      <h4>Saying the caller's own name</h4>
      <p class="muted" style="font-size:13px">
        “નમસ્કાર રમેશભાઈ…” is one sentence per customer, so it cannot be made once and shared — and nothing may
        be made while somebody is already on the line. It is made ahead of the call instead, for the people
        likely to ring: anybody who has rung before, and anybody who owes money. Until a customer's greeting is
        ready they hear the greeting without their name, exactly as before.
      </p>
      <?php $greet = voice_in_greet_status(voice_in_lang()); ?>
      <?php if ($greet['total'] === 0): ?>
        <p class="muted">Nobody to greet yet — no incoming calls and nothing outstanding.</p>
      <?php elseif (!$greet['left']): ?>
        <div class="flash flash-info">✅ All <?= (int)$greet['total'] ?> of them are greeted by name.</div>
      <?php else: ?>
        <div class="flash flash-info"><?= (int)$greet['ready'] ?> of <?= (int)$greet['total'] ?> customers are
          greeted by name. The rest are made by themselves, <?= (int)setting('voice_greet_per_run', 10) ?> every
          half hour — or press the button to do a batch now.</div>
      <?php endif; ?>
      <?php if ($greet['left']): ?>
      <form method="post" class="mb">
        <?= csrf_field() ?><input type="hidden" name="do" value="make_greetings">
        <button class="btn btn-outline" type="submit" <?= voice_tts_enabled() ? '' : 'disabled' ?>>
          🗣 Make the next <?= (int)setting('voice_greet_per_run', 10) ?> now</button>
      </form>
      <?php endif; ?>

      <h4>Hear the reminder before a customer does</h4>
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
      <?php endif; ?>
    </div>
  </details>

  <?php /* ---- 3. incoming ---- */ $st = $steps[2]; ?>
  <details class="vstep <?= $st['done'] ? 'vdone' : '' ?>"<?= $stepOpen('in') ?>>
    <?php $stTitle($st); ?>
    <div class="vbody">
      <p class="muted" style="font-size:13px">
        A Vobiz number holds no web address of its own. The address lives on an <strong>Application</strong>,
        and the number is pointed at one. Both are done here. Only a number <strong>Vobiz sold you</strong> can
        be pointed — your own mobile cannot.
      </p>

      <?php if (!voice_configured()): ?>
        <p class="muted">Finish step 1 first.</p>
      <?php else: ?>

      <?php if (can('settings.edit')): ?>
      <p class="muted" style="font-size:12px">
        <?= setting('vobiz_app_id') ? 'Application ' . e(setting('vobiz_app_id')) . ' is in use.' : 'No application yet.' ?>
        The button finds the one that already points here and uses it, and only makes a new one if there is
        none — so pressing it twice is safe.
      </p>
      <form method="post" class="mb">
        <?= csrf_field() ?><input type="hidden" name="do" value="app_setup">
        <button class="btn btn-outline" type="submit">
          <?= setting('vobiz_app_id') ? 'Find / refresh the application' : 'Make the application' ?></button>
      </form>
      <?php endif; ?>

      <h4>Your Vobiz numbers</h4>
      <?php if (!$myNums['ok']): ?>
        <div class="flash flash-error">Could not read your numbers: <?= e($myNums['error']) ?></div>
      <?php elseif (!$myNums['numbers']): ?>
        <div class="flash flash-error">
          This Vobiz account owns <strong>no numbers yet</strong>. Buy one in the Vobiz console
          (Phone Numbers → Buy), then come back — it will appear here.
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

      <?php if ($attachTrail && !setting('vobiz_inbound_number')): ?>
      <div class="flash flash-info mt">
        <strong>Do it in the Vobiz console instead — it is the same thing.</strong><br>
        Phone Numbers → <?= e($myNums['numbers'][0]['e164'] ?? 'your number') ?> →
        set its <strong>Application</strong> to <strong><?= e(setting('vobiz_app_id')) ?></strong>, and save.<br>
        Then press <strong>Check my setup</strong> at the bottom of this page — it should turn green on its own.
      </div>
      <details class="mb"><summary class="muted" style="font-size:12px;cursor:pointer">What Vobiz said to each way of attaching it</summary>
        <p class="muted" style="font-size:12px">
          These are the same request written several ways. If every one was refused, the number is on your
          account but Vobiz is not letting this account point it at an application — that is theirs to fix.
          Send them this list and the application id.
        </p>
        <div class="table-wrap"><table class="table-sm">
          <thead><tr><th>Tried</th><th>Vobiz said</th></tr></thead>
          <tbody>
          <?php foreach ($attachTrail as $tr): ?>
            <tr><td style="font-size:12px"><?= e($tr['tried'] ?? '') ?></td>
                <td class="muted" style="font-size:12px"><?= e($tr['said'] ?? '') ?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
      </details>
      <?php endif; ?>

      <?php if (!setting('vobiz_inbound_number') && $myNums['numbers'] && can('settings.edit')): ?>
      <form method="post" class="mb">
        <?= csrf_field() ?><input type="hidden" name="do" value="inbound_save">
        <input type="hidden" name="own[]" value="vobiz_inbound_number">
        <input type="hidden" name="vobiz_inbound_number" value="<?= e($myNums['numbers'][0]['e164']) ?>">
        <button class="btn btn-outline" type="submit">I attached it in the Vobiz console — remember <?= e($myNums['numbers'][0]['e164']) ?> here</button>
      </form>
      <?php endif; ?>

      <?php if (can('settings.edit')): ?>
      <h4>How it should answer</h4>
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="do" value="inbound_save">
        <?php foreach (['voice_inbound_lang', 'voice_ivr_balance', 'voice_agent_numbers', 'voice_agent_timeout',
                        'voice_shop_open', 'voice_shop_close', 'voice_inbound'] as $k): ?>
          <input type="hidden" name="own[]" value="<?= $k ?>"><?php endforeach; ?>
        <div class="grid-2">
          <label>Language of the menu
            <select name="voice_inbound_lang">
              <?php foreach ($langs as $lk => $lv): ?>
                <option value="<?= $lk ?>" <?= voice_in_lang() === $lk ? 'selected' : '' ?>><?= e($lv) ?></option>
              <?php endforeach; ?>
            </select>
            <span class="muted" style="font-size:11px">Change this and press “Make it again” in step 2.</span></label>
          <label>When a caller asks for their balance
            <select name="voice_ivr_balance">
              <?php foreach (['speak' => 'Read the amount out to them',
                              'whatsapp' => 'Send it to their WhatsApp instead (safer)',
                              'off' => 'Do not answer money questions by phone'] as $mk => $mv): ?>
                <option value="<?= $mk ?>" <?= setting('voice_ivr_balance', 'speak') === $mk ? 'selected' : '' ?>><?= e($mv) ?></option>
              <?php endforeach; ?>
            </select>
            <span class="muted" style="font-size:11px">Caller ID can be faked. Reading the figure out trusts whoever is
              on the line; WhatsApp needs their actual SIM.</span></label>
          <label>Ring these phones for “talk to us”
            <input name="voice_agent_numbers" value="<?= e(setting('voice_agent_numbers')) ?>" placeholder="9824537749, 9825012345">
            <span class="muted" style="font-size:11px">Comma separated. They ring together; first to pick up gets the call.</span></label>
          <label>Ring for how many seconds
            <input name="voice_agent_timeout" type="number" min="10" max="60" value="<?= (int)setting('voice_agent_timeout', 25) ?>"></label>
          <label>Shop opens at
            <input name="voice_shop_open" type="number" min="0" max="24" value="<?= (int)setting('voice_shop_open', 9) ?>"></label>
          <label>Shop closes at
            <input name="voice_shop_close" type="number" min="0" max="24" value="<?= (int)setting('voice_shop_close', 21) ?>">
            <span class="muted" style="font-size:11px">Outside these hours orders and complaints are still recorded — only
              “talk to us” takes a message instead of ringing a phone.</span></label>
          <label style="display:flex;align-items:center;gap:8px">
            <input type="checkbox" name="voice_inbound" value="1" <?= voice_in_on() ? 'checked' : '' ?>>
            Answer incoming calls</label>
        </div>
        <button class="btn btn-success mt" type="submit">Save</button>
      </form>
      <?php endif; ?>
      <?php endif; ?>
    </div>
  </details>

  <?php /* ---- 4. test on your own phone ---- */ $st = $steps[3]; ?>
  <details class="vstep <?= $st['done'] ? 'vdone' : '' ?>"<?= $stepOpen('test') ?>>
    <?php $stTitle($st); ?>
    <div class="vbody">
      <?php if (!can('settings.edit')): ?>
        <p class="muted">Only someone who can change settings may place a test call.</p>
      <?php else: ?>
      <p class="muted" style="font-size:13px">This dials for real, even when test mode is on — that is the point of it.
        Ring yourself, listen to the whole thing, and only then let it near a customer.
        <strong>It works at any hour</strong>: the calling-hours setting protects customers, and there is no
        customer here.</p>
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
        <?php if (!voice_configured()): ?><span class="muted"> — finish step 1 first</span><?php endif; ?>
      </form>
      <p class="muted" style="font-size:12px;margin-top:10px">Then ring the shop's number from your own phone too,
        and walk the whole menu. What you hear is what a customer in the market will hear.</p>
      <?php endif; ?>
    </div>
  </details>

  <?php /* ---- 5. go live ---- */ $st = $steps[4]; ?>
  <details class="vstep <?= $st['done'] ? 'vdone' : '' ?>"<?= $stepOpen('go') ?>>
    <?php $stTitle($st); ?>
    <div class="vbody">
      <?php if (!can('settings.edit')): ?>
        <p class="muted">Only someone who can change settings may switch calling on.</p>
      <?php else: ?>
      <?php if (voice_test_mode()): ?>
      <div class="flash flash-error">
        <strong>Test mode is ON.</strong> Every reminder is written down with what it would have said, and
        <strong>nobody is dialled</strong>. This is why a “Remind Now” shows in the list but no phone rings.
        Untick it below when you are ready.
      </div>
      <?php endif; ?>
      <?php if (!$hours['legal']): ?>
      <div class="flash flash-error">
        Calls are set to go out
        <?= $hours['all_day'] ? '<strong>at any hour of the day or night</strong>' : 'between <strong>' . $hours['from'] . ':00 and ' . $hours['to'] . ':00</strong>' ?>.
        The law allows calls to customers between <strong>9:00 and 21:00</strong> only.
        Fine while you are testing on your own number — put it back to 9–21 before real customers are called.
      </div>
      <?php endif; ?>
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="do" value="save">
        <?php foreach (['voice_hour_from', 'voice_hour_to', 'voice_cooldown_hours', 'voice_max_per_day',
                        'voice_balance_min', 'voice_ivr', 'voice_test_mode', 'voice_enabled'] as $k): ?>
          <input type="hidden" name="own[]" value="<?= $k ?>"><?php endforeach; ?>
        <div class="grid-2">
          <label>Call only from
            <input name="voice_hour_from" type="number" min="0" max="24" value="<?= (int)setting('voice_hour_from', 9) ?>"></label>
          <label>Call only until
            <input name="voice_hour_to" type="number" min="0" max="24" value="<?= (int)setting('voice_hour_to', 21) ?>">
            <span class="muted" style="font-size:11px"><strong>9 to 21</strong> is what the law allows and is the default.
              <strong>0 to 24</strong> means any hour — useful while testing. The test call ignores this either way.</span></label>
          <label>Same customer not called again for (hours)
            <input name="voice_cooldown_hours" type="number" min="1" max="72" value="<?= (int)setting('voice_cooldown_hours', 6) ?>"></label>
          <label>Most calls in a day
            <input name="voice_max_per_day" type="number" min="1" max="500" value="<?= (int)setting('voice_max_per_day', 50) ?>"></label>
          <label>Warn me when the balance falls below (₹)
            <input name="voice_balance_min" type="number" min="0" value="<?= (int)setting('voice_balance_min', 100) ?>"></label>
          <label style="display:flex;align-items:center;gap:8px">
            <input type="checkbox" name="voice_ivr" value="1" <?= voice_ivr_on() ? 'checked' : '' ?>>
            Ask “will you pay today?” and record the answer</label>
          <label style="display:flex;align-items:center;gap:8px">
            <input type="checkbox" name="voice_test_mode" value="1" <?= voice_test_mode() ? 'checked' : '' ?>>
            Test mode — write down what would be said, dial nobody</label>
          <label style="display:flex;align-items:center;gap:8px">
            <input type="checkbox" name="voice_enabled" value="1" <?= voice_enabled() ? 'checked' : '' ?>>
            Reminder calls are switched on</label>
        </div>
        <button class="btn btn-success mt" type="submit">Save</button>
      </form>
      <?php endif; ?>
    </div>
  </details>
</div>

<?php $talkLang = setting('voice_lang', 'gu'); $talkV = voice_talk_voice_status($talkLang);
      $talkUse = voice_talk_usage(); ?>
<div class="card">
  <h3>💬 Talk to them, instead of reading at them</h3>
  <p class="muted" style="font-size:13px">
    With this on, the reminder call greets the customer by name, asks how they are, and then asks
    <em>“<?= e(voice_talk_line('talk_ask', $talkLang)) ?>”</em> — and <strong>listens to the answer</strong>.
    “કાલે”, “આવતા સોમવારે”, “પૈસા ભરી દીધા છે” are all understood, and a date becomes a promise in the
    collection screen exactly as if you had typed it. Change any of the wording on
    <a href="voice_words.php">What the Phone Says</a>.
  </p>
  <div class="flash flash-info" style="font-size:13px">
    <strong>What the AI does, and what it does not.</strong><br>
    It only <strong>listens</strong>. Every word the customer hears is one of your own sentences, in your own
    voice — the AI never makes up a line, never says an amount, and never offers anything.
    The date it thinks it heard is checked by ordinary rules before anything is written down: a real date, not
    in the past, and no further out than <?= (int)voice_talk_max_days() ?> days. It is told the recording and
    today's date — not the name, not the number, not what they owe.
    If it fails or is slow, the call falls back to the old “press 1 for yes” question.
  </div>

  <?php if (!voice_tts_enabled()): ?>
    <div class="flash flash-error">This needs the Gemini key — see step 2 above.</div>
  <?php elseif (!voice_ivr_on()): ?>
    <div class="flash flash-error">Switch on “Ask &ldquo;will you pay today?&rdquo;” in step 5 first — the
      conversation replaces that question, and without it the call has nothing to ask.</div>
  <?php elseif (!$talkV['ready']): ?>
    <div class="flash flash-error">
      <?= (int)$talkV['total'] - (int)$talkV['done'] ?> of <?= (int)$talkV['total'] ?> sentences have no voice
      made yet. <strong>Until every one is made the conversation does not start at all</strong> — a call that
      opens a conversation and then cannot say “I did not catch that” is worse than one that never opened it.
    </div>
  <?php else: ?>
    <div class="flash flash-info">✅ All <?= (int)$talkV['total'] ?> sentences are ready in
      <?= e($langs[$talkLang] ?? $talkLang) ?>. Listened <?= (int)$talkUse['used'] ?> of
      <?= (int)$talkUse['cap'] ?> times this month.</div>
  <?php endif; ?>

  <?php if (can('settings.edit')): ?>
  <?php if (voice_tts_enabled() && !$talkV['ready']): ?>
  <form method="post" class="mb">
    <?= csrf_field() ?><input type="hidden" name="do" value="make_talk_voice">
    <button class="btn btn-success" type="submit">🔊 Make the conversation speak
      <?= e($langs[$talkLang] ?? $talkLang) ?></button>
    <span class="muted" style="font-size:12px"> Do this again whenever you change the wording.</span>
  </form>
  <?php endif; ?>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="do" value="save_talk">
    <div class="grid-2">
      <label>How many times to ask again if the answer is unclear
        <input name="voice_talk_turns" type="number" min="1" max="4" value="<?= voice_talk_turns() ?>"></label>
      <label>How long they may speak (seconds)
        <input name="voice_talk_secs" type="number" min="3" max="20" value="<?= voice_talk_secs() ?>"></label>
      <label>A promised date further out than this is not accepted (days)
        <input name="voice_talk_max_days" type="number" min="1" max="365" value="<?= voice_talk_max_days() ?>">
        <span class="muted" style="font-size:11px">Past this the call simply asks again instead of writing down
          a date nobody meant.</span></label>
      <label>Most answers listened to in a month
        <input name="voice_talk_month_cap" type="number" min="1" value="<?= (int)$talkUse['cap'] ?>">
        <span class="muted" style="font-size:11px">Past this the call goes back to the keypad question rather
          than run up a bill.</span></label>
      <label style="display:flex;align-items:center;gap:8px">
        <input type="checkbox" name="voice_talk" value="1" <?= (int)setting('voice_talk', 0) === 1 ? 'checked' : '' ?>>
        Talk to the customer instead of reading at them</label>
    </div>
    <button class="btn btn-success mt" type="submit">Save</button>
    <span class="muted" style="font-size:12px"> Ring your own phone (step 4) and have the conversation
      yourself before a customer does.</span>
  </form>
  <?php endif; ?>
</div>

<?php if (can('settings.edit')): ?>
<div class="card">
  <h3>🎙 Keep the whole call on tape</h3>
  <p class="muted" style="font-size:13px">
    What is kept today is the customer's <em>answer</em> only — what your own voice said before it is not on
    tape at all. Half a conversation is no use if a customer ever says the phone told them something else.
    With this on, the recording starts before the greeting and keeps both sides.
  </p>
  <div class="flash flash-info" style="font-size:13px">
    Recording a phone call is <strong>your decision, not the software's</strong>, which is why this is off until
    you switch it on — and why a shop that records should be willing to say so if a customer asks.
    If your provider does not support recording a whole session, nothing breaks: the call plays out exactly as
    before, no “▶ all” appears on the Calls screen, and the customer's own answer is still recorded.
  </div>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="do" value="save_record">
    <div class="grid-2">
      <label>Stop taping after (seconds)
        <input name="voice_record_max" type="number" min="30" max="3600" value="<?= (int)setting('voice_record_max', 600) ?>">
        <span class="muted" style="font-size:11px">A call nobody hung up is not taped forever.</span></label>
      <label style="display:flex;align-items:center;gap:8px">
        <input type="checkbox" name="voice_record_all" value="1" <?= (int)setting('voice_record_all', 0) === 1 ? 'checked' : '' ?>>
        Record the whole call, both sides</label>
    </div>
    <button class="btn btn-success mt" type="submit">Save</button>
    <span class="muted" style="font-size:12px"> Recordings play on
      <a href="voice_calls.php">Calls</a> — “▶ all” is the whole call, “▶” is their answer.</span>
  </form>
</div>
<?php endif; ?>

<?php /* ================= reference, opened when wanted ================= */ ?>
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
            <?php if ($r['recording_url']): ?>
              <br><a href="voice_rec.php?id=<?= (int)$r['id'] ?>" target="_blank">▶ hear what they said<?php if ($r['recording_secs']): ?> (<?= (int)$r['recording_secs'] ?>s)<?php endif; ?></a>
            <?php endif; ?>
          <?php else: ?>
            💰 ₹<?= money($r['amount']) ?>
            <?php if (!empty($r['heard'])): ?>
              <br><span class="muted" style="font-size:11px">🗣 “<?= e($r['heard']) ?>”</span>
            <?php endif; ?>
            <?php if (!empty($r['promise_date'])): ?>
              <br><span class="badge badge-ok" style="font-size:10px">📅 said <?= e(dmy($r['promise_date'])) ?></span>
            <?php elseif ($r['heard_intent'] === 'paid'): ?>
              <br><span class="badge badge-warn" style="font-size:10px">says already paid</span>
            <?php elseif ($r['heard_intent'] === 'wrong_person'): ?>
              <br><span class="badge badge-bad" style="font-size:10px">wrong number</span>
            <?php endif; ?>
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

<div class="card">
  <h3>🔧 If something is not working</h3>

  <?php if (can('settings.edit')): ?>
  <p class="muted" style="font-size:13px">A call that cuts off the moment it connects means one link in the chain
    is broken. This walks all of them and names the broken one.</p>
  <p><a class="btn btn-outline" href="voice_setup.php?check=1#check">Check my setup</a></p>
  <?php if ($diag !== null): ?>
  <div id="check" class="table-wrap mb"><table class="table-sm">
    <tbody>
    <?php foreach ($diag as $d): ?>
      <tr>
        <td style="width:26px"><?= $d['ok'] ? '✅' : '❌' ?></td>
        <td><?= e($d['name']) ?></td>
        <td class="muted" style="font-size:12px"><?= e($d['detail']) ?>
          <?php if (!empty($d['attach'])): ?>
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
  <?php endif; ?>

  <?php if ($rejects): ?>
  <details class="mb"><summary style="cursor:pointer;font-weight:700">📵 Calls that arrived and were turned away (<?= count($rejects) ?>)</summary>
    <p class="muted" style="font-size:12px">If your test call is in this list, the reason next to it is the whole answer.</p>
    <div class="table-wrap"><table class="table-sm">
      <thead><tr><th>When</th><th>Why</th></tr></thead>
      <tbody>
      <?php foreach ($rejects as $rj): ?>
        <tr><td style="white-space:nowrap"><?= e(dmy(substr($rj['created_at'], 0, 10))) ?>
              <span class="muted"><?= e(substr($rj['created_at'], 11, 5)) ?></span></td>
            <td><?= e($rj['details']) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </details>
  <?php endif; ?>

  <details id="hear" class="mb" <?= $pv ? 'open' : '' ?>>
    <summary style="cursor:pointer;font-weight:700">🔎 What would this caller hear?</summary>
    <p class="muted" style="font-size:13px">Type a mobile number and see exactly what the menu would say to it,
      without ringing anybody. The quickest way to check a customer who says the balance read out was wrong.</p>
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
          <span class="muted" style="font-size:12px">— the same figure their WhatsApp statement shows</span>
        </div>
        <?php if (abs((float)$pv['chase'] - (float)$pv['due']) > 0.009): ?>
        <div class="flash flash-error">
          <strong>A reminder call to this customer would say ₹<?= money($pv['chase']) ?>, not ₹<?= money($pv['due']) ?>.</strong><br>
          The collection screen chases what a customer has bought and does not subtract what the shop has bought
          <em>from</em> them. For a customer who only buys, the two are the same number — they differ here because
          this party is on both sides. Their statement, and the phone menu, both say ₹<?= money($pv['due']) ?>.
          <br><span class="muted" style="font-size:12px">Nothing is broken, but the shop would be quoting two figures.
          Say the word and the reminder can be made to follow the statement too.</span>
        </div>
        <?php endif; ?>
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
  </details>

  <details class="mb"><summary style="cursor:pointer;font-weight:700">🗣 What the calls actually say</summary>
    <h4>Going out — the reminder</h4>
    <pre style="white-space:pre-wrap;background:var(--bg);padding:12px;border-radius:10px;font-family:inherit;font-size:15px"><?= e(voice_script('રમેશભાઈ', 12400, $lang)) ?><?= voice_ivr_on() ? "\n\n" . e(voice_question($lang)['plain']) : '' ?></pre>
    <p class="muted" style="font-size:12px">
      The amount comes from the ledger and is written out in words before the voice ever sees it —
      ₹12,400 becomes “<?= e(voice_tokens_text(voice_amount_tokens(12400), $lang)) ?>”.
      The AI is given words to pronounce, never a number to work out.
    </p>
    <?php if (voice_ivr_on()): ?>
    <table class="table-sm">
      <thead><tr><th>They press</th><th>They hear</th><th>What gets written down</th></tr></thead>
      <tbody>
        <tr><td><strong>1</strong> — yes</td><td><?= e(voice_question($lang)['yes']) ?></td>
            <td><span class="badge badge-ok">A promise to pay today</span><br>
                <span class="muted" style="font-size:11px">Shows in Promises on the collection screen · no more reminders
                until the day is out · the nightly job marks it kept or broken on its own</span></td></tr>
        <tr><td><strong>2</strong> — no</td><td><?= e(voice_question($lang)['no']) ?></td>
            <td><span class="badge badge-warn">“Said no”</span><br>
                <span class="muted" style="font-size:11px">Kept against the customer, so the next person to look knows
                the phone was answered</span></td></tr>
        <tr><td>nothing</td><td><?= e(voice_question($lang)['none']) ?></td>
            <td><span class="muted">Written down as “did not answer the question”</span></td></tr>
      </tbody>
    </table>
    <?php endif; ?>

    <h4>Coming in — the menu</h4>
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
    <p class="muted" style="font-size:12px">Everything a caller leaves shows on <a href="voice_calls.php">Calls</a>
      until somebody marks it done.</p>
  </details>

  <details><summary style="cursor:pointer;font-weight:700">📋 What the law and Vobiz need — this software cannot check these</summary>
    <ul style="line-height:1.9">
      <li><strong>KYC finished on Vobiz</strong> — an unverified account cannot place outbound calls in India.</li>
      <li><strong>Caller ID approved</strong> — the number customers see has to be verified with the provider, or the call
        shows an unknown number and gets cut.</li>
      <li><strong>DLT registration</strong> — the entity registered on a DLT portal, and the reminder script filed as the
        template being played. A payment reminder to your own customer is transactional, not promotional; it still has
        to be on file.</li>
      <li><strong>9am–9pm only</strong> — enforced in step 5, and it applies to the test call too.</li>
      <li><strong>DND</strong> — transactional calls to your own customers are allowed on DND numbers, but a customer who
        says stop must be marked “do not call” on their party page, and that switch is separate from the WhatsApp opt-out.</li>
      <li><strong>Keep the recordings honest</strong> — the amount spoken comes from the ledger. Never record a clip that
        claims anything else.</li>
    </ul>
  </details>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
