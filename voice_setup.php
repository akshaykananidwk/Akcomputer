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
require_once __DIR__ . '/includes/voice.php';
require_perm('settings.view');

$langs = voice_langs();

// ---------- actions ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_perm('settings.edit');

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
        if (!voice_hours_ok()) {
            $h = voice_hours();
            flash('Calls are allowed between ' . $h['from'] . ':00 and ' . $h['to'] . ':00 only — a test is a real call too.', 'error');
            redirect('voice_setup.php');
        }

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
        <input name="voice_hour_from" type="number" min="9" max="21" value="<?= (int)setting('voice_hour_from', 9) ?>"></label>
      <label>Call only until
        <input name="voice_hour_to" type="number" min="9" max="21" value="<?= (int)setting('voice_hour_to', 21) ?>">
        <span class="muted" style="font-size:11px">9–21 is the legal limit; a narrower window is allowed, a wider one is not.</span></label>
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
  <h3>🧪 Try it on your own phone first</h3>
  <p class="muted" style="font-size:13px">This dials for real, even when test mode is on — that is the point of it.
    Ring yourself, listen to the whole thing, and only then let it near a customer.</p>
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
  <?php if (!$recent): ?><p class="muted">No calls yet.</p><?php else: ?>
  <div class="table-wrap"><table class="table-sm">
    <thead><tr><th>When</th><th>Customer</th><th class="num">Amount</th><th>Result</th><th>They said</th><th class="num">Sec</th></tr></thead>
    <tbody>
    <?php foreach ($recent as $r): ?>
      <tr>
        <td><?= e(dmy(substr($r['created_at'], 0, 10))) ?> <span class="muted"><?= e(substr($r['created_at'], 11, 5)) ?></span></td>
        <td><?= $r['name'] ? e($r['name']) : '<span class="muted">test</span>' ?>
          <br><span class="muted" style="font-size:11px"><?= e($r['mobile']) ?> · <?= e($r['lang']) ?></span></td>
        <td class="num">₹<?= money($r['amount']) ?></td>
        <td><?= e(voice_status_label($r['status'])) ?>
          <?= $r['error'] ? '<br><span class="muted" style="font-size:11px">' . e($r['error']) . '</span>' : '' ?></td>
        <td>
          <?php if ($r['response'] === 'yes'): ?><span class="badge badge-ok">✅ Yes — today</span>
          <?php elseif ($r['response'] === 'no'): ?><span class="badge badge-bad">❌ No</span>
          <?php elseif ($r['response'] === 'none'): ?><span class="muted" style="font-size:11px">no key pressed</span>
          <?php else: ?><span class="muted">—</span><?php endif; ?>
        </td>
        <td class="num"><?= (int)$r['duration'] ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
