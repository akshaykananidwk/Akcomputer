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
                  'voice_cooldown_hours', 'voice_max_per_day', 'voice_balance_min'] as $k) {
            set_setting($k, post($k));
        }
        // An empty token box means "leave the saved one alone", not "delete
        // it" - otherwise every save of the hours would wipe the key.
        if (post('vobiz_auth_token') !== '') set_setting('vobiz_auth_token', post('vobiz_auth_token'));
        set_setting('voice_enabled', post('voice_enabled') ? '1' : '0');
        set_setting('voice_test_mode', post('voice_test_mode') ? '1' : '0');
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
        $plan = voice_clip_plan(post('test_name') ?: 'Test', $amount, $lang);
        $script = voice_script(post('test_name') ?: 'Test', $amount, $plan['lang']);
        $token = bin2hex(random_bytes(20));
        q("INSERT INTO voice_calls (party_id, mobile, amount, lang, script, provider, token, status, test_mode, created_by, started_at)
           VALUES (0,?,?,?,?,?,?,'queued',0,?,NOW())",
          [voice_e164($to), $amount, $plan['lang'], $script, setting('voice_provider', 'vobiz'), $token, $_SESSION['user_id'] ?? null]);
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

    // ---- recordings ----
    //
    // The uploaded filename is never used to write with. Only a name that
    // matches a token this system knows about is accepted, and it is written
    // as "<token>.mp3" - so a file called ../../index.php.mp3 is simply not
    // one of the hundred and twelve names on the list, and is refused.
    if (post('do') === 'upload_clips') {
        $lang = post('clip_lang');
        if (!isset($langs[$lang]) || $lang === 'en') { flash('Pick a language.', 'error'); redirect('voice_setup.php'); }
        $allowed = array_flip(voice_clip_tokens());
        $dir = voice_clip_dir($lang);
        if (!is_dir($dir)) mkdir($dir, 0755, true);

        $saved = 0; $bad = [];
        $files = $_FILES['clips'] ?? null;
        for ($i = 0; $files && $i < count($files['name']); $i++) {
            if ((int)$files['error'][$i] !== UPLOAD_ERR_OK) continue;
            $base = strtolower(pathinfo($files['name'][$i], PATHINFO_FILENAME));
            $ext = strtolower(pathinfo($files['name'][$i], PATHINFO_EXTENSION));
            if ($ext !== 'mp3' || !isset($allowed[$base])) { $bad[] = $files['name'][$i]; continue; }
            if ((int)$files['size'][$i] > 2 * 1024 * 1024) { $bad[] = $files['name'][$i] . ' (too big)'; continue; }
            if (move_uploaded_file($files['tmp_name'][$i], $dir . '/' . $base . '.mp3')) $saved++;
            else $bad[] = $files['name'][$i];
        }
        log_activity('voice_clips_upload', $lang . ': ' . $saved . ' saved, ' . count($bad) . ' refused');
        flash($saved . ' recordings saved.' . ($bad ? ' Not recognised: ' . e(implode(', ', array_slice($bad, 0, 8))) : ''),
              $saved ? 'success' : 'error');
        redirect('voice_setup.php?lang=' . $lang);
    }
}

// ---------- the page ----------
$lang = get('lang') ?: setting('voice_lang', 'gu');
if (!isset($langs[$lang])) $lang = 'gu';
$missing = voice_clips_missing($lang);
$bal = voice_configured() ? voice_balance() : null;
$hours = voice_hours();
$recent = all('SELECT v.*, p.name FROM voice_calls v LEFT JOIN parties p ON p.id = v.party_id
               ORDER BY v.id DESC LIMIT 20');
$words = voice_words($lang);

// What each recording should say. The number ones come from the word table;
// the six sentence pieces are spelled out here because that is the text the
// owner reads into the phone.
$ph = voice_phrases($lang);
$clipText = [
    'greet' => $ph['greet'],
    'shop' => setting('app_name', 'AK Computer') . ' ' . $ph['from_shop'],
    'your' => $ph['your'],
    'due' => $ph['due'],
    'request' => $ph['request'],
    'thanks' => $ph['thanks'],
    'hundred' => $ph['hundred'], 'thousand' => $ph['thousand'],
    'lakh' => $ph['lakh'], 'crore' => $ph['crore'],
    'rupees' => $ph['rupees'], 'paise' => $ph['paise'],
];
for ($i = 0; $i <= 99; $i++) $clipText['n' . $i] = $words[$i];

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
  </div>
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
    <strong>Vobiz cannot speak Gujarati or Hindi.</strong> Their text-to-speech covers 16 languages —
    English, Danish, Dutch, French, German, Italian, Polish, Portuguese, Russian, Spanish, Swedish — and no Indian language.
    So a Gujarati call is played from recordings made in the shop's own voice, stitched together,
    the way a bank reads out a balance. Until the recordings exist the call still goes out, in English.
  </p>
  <p><strong>Example — ₹12,400:</strong></p>
  <pre style="white-space:pre-wrap;background:var(--bg);padding:12px;border-radius:10px;font-family:inherit;font-size:15px"><?= e(voice_script('રમેશભાઈ', 12400, $lang)) ?></pre>
  <p class="muted" style="font-size:12px">Clips played in order:
    <?= e(implode(' + ', array_merge(['greet', 'shop', 'your'], voice_amount_tokens(12400), ['due', 'request', 'thanks']))) ?></p>
</div>

<div class="card">
  <h3>🎙 Recordings — <?= e($langs[$lang]) ?></h3>
  <p>
    <?php foreach ($langs as $lk => $lv): if ($lk === 'en') continue; ?>
      <a class="btn btn-sm <?= $lk === $lang ? '' : 'btn-outline' ?>" href="voice_setup.php?lang=<?= $lk ?>"><?= e($lv) ?></a>
    <?php endforeach; ?>
  </p>
  <?php if (!$missing): ?>
    <div class="flash flash-info">✅ All <?= count(voice_clip_tokens()) ?> recordings are in. Calls go out in <?= e($langs[$lang]) ?>.</div>
  <?php else: ?>
    <div class="flash flash-error">
      <?= count($missing) ?> of <?= count(voice_clip_tokens()) ?> recordings are missing, so calls go out <strong>in English</strong> for now.
      One missing clip in the middle of an amount would be heard as a silent gap, so the whole language waits until it is complete.
    </div>
  <?php endif; ?>
  <p class="muted" style="font-size:13px">
    Record each line below as a small <strong>mp3</strong>, named exactly as shown (<code>n12.mp3</code>, <code>rupees.mp3</code>),
    then pick them all here at once. Short and flat is best — they are played back to back.
  </p>
  <?php if (can('settings.edit')): ?>
  <form method="post" enctype="multipart/form-data" class="mb">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="upload_clips">
    <input type="hidden" name="clip_lang" value="<?= e($lang) ?>">
    <input type="file" name="clips[]" accept="audio/mpeg,.mp3" multiple required>
    <button class="btn btn-success" type="submit">Upload recordings</button>
  </form>
  <?php endif; ?>
  <div class="table-wrap" style="max-height:340px;overflow:auto"><table class="table-sm">
    <thead><tr><th>File name</th><th>What to say</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach (voice_clip_tokens() as $t): $have = voice_clip_exists($t, $lang); ?>
      <tr>
        <td><code><?= e($t) ?>.mp3</code></td>
        <td><?= e($clipText[$t] ?? $t) ?></td>
        <td><?= $have ? '<span class="badge badge-ok" style="font-size:10px">recorded</span>' : '<span class="muted">—</span>' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
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
    <thead><tr><th>When</th><th>Customer</th><th>Number</th><th class="num">Amount</th><th>Lang</th><th>Result</th><th class="num">Seconds</th></tr></thead>
    <tbody>
    <?php foreach ($recent as $r): ?>
      <tr>
        <td><?= e(dmy(substr($r['created_at'], 0, 10))) ?> <span class="muted"><?= e(substr($r['created_at'], 11, 5)) ?></span></td>
        <td><?= $r['name'] ? e($r['name']) : '<span class="muted">test</span>' ?></td>
        <td><?= e($r['mobile']) ?></td>
        <td class="num">₹<?= money($r['amount']) ?></td>
        <td><?= e($r['lang']) ?></td>
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
