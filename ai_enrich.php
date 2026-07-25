<?php
// AI Auto-Fill: walks the item list and fills missing category, description
// and photo using Google Gemini (+ Google image search for photos). The
// browser drives it in small batches (do=run) so a big catalog never hits the
// PHP time limit and the owner watches live progress. Photos obey one hard
// rule: Gemini vision must confirm the image shows exactly that product,
// otherwise NO photo is saved — a wrong photo is worse than an empty one.
require_once __DIR__ . '/includes/init.php';
require_perm('items.edit');
$u = current_user();

// WHERE for "still needs work" under the selected modes; photos only make
// sense for products (services have nothing to photograph).
function ai_need_where($text, $photo) {
    $conds = [];
    if ($text) $conds[] = "(COALESCE(description,'') = '' OR category_id IS NULL)";
    if ($photo) $conds[] = "(COALESCE(photo,'') = '' AND item_type = 'product')";
    return $conds ? 'is_active = 1 AND (' . implode(' OR ', $conds) . ')' : '0';
}

// one-click key check: 1 tiny Gemini call + 1 image search, so the owner
// knows both keys work BEFORE burning quota on a big run
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'test') {
    header('Content-Type: application/json');
    $out = [];
    list($t, $e) = gemini_generate([['text' => 'Reply with exactly: OK']], 30);
    $out['gemini'] = $e ? ('❌ ' . $e) : '✅ Gemini key works (model: ' . setting('gemini_model') . ')';
    if (setting('gcs_api_key') && setting('gcs_cx')) {
        list($urls, $e2) = gcs_image_search('cctv camera', 1);
        $out['gcs'] = $e2 ? ('❌ ' . $e2) : '✅ Image Search key works (' . count($urls) . ' result)';
    } else {
        $out['gcs'] = 'Image Search keys not set — photos will be skipped.';
    }
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'run') {
    header('Content-Type: application/json');
    $modeText = post('text') === '1';
    $modePhoto = post('photo') === '1';
    $where = ai_need_where($modeText, $modePhoto);
    $batch = $modePhoto ? 2 : 4;
    // keyset pagination (id > after_id) instead of re-querying from the top:
    // an item the AI could not fix (unidentifiable product, no photo that
    // passes the match check) still satisfies the WHERE, and without this the
    // browser loop would re-run the same items — and the same API calls —
    // forever.
    $afterId = (int)post('after_id');
    $items = all("SELECT * FROM items WHERE $where AND id > ? ORDER BY id LIMIT $batch", [$afterId]);
    $cats = all('SELECT id, name FROM categories ORDER BY name');
    $catByLower = [];
    foreach ($cats as $c) $catByLower[mb_strtolower(trim($c['name']))] = $c['id'];

    $results = []; $fatal = null; $geminiCalls = 0; $searchCalls = 0; $photoStop = null;
    foreach ($items as $it) {
        $label = trim($it['name'] . ' ' . $it['brand'] . ' ' . $it['model']);
        $r = ['id' => (int)$it['id'], 'name' => $it['name'], 'notes' => []];

        if ($modeText && ((string)$it['description'] === '' || !$it['category_id'])) {
            list($meta, $err) = gemini_item_meta($it, array_column($cats, 'name'));
            $geminiCalls++;
            if ($err === 'bad-json') {
                // one product's reply came back unreadable (twice) — skip
                // just that item; stopping the whole run for it would strand
                // hundreds of fixable items behind one odd product name
                $r['notes'][] = 'AI reply unreadable — skipped, fill by hand';
                $meta = null;
            } elseif ($err) {
                // key missing / rate limit stops the whole run so the browser
                // loop doesn't hammer a dead API
                $fatal = $err; $results[] = $r; break;
            }
            $set = []; $params = [];
            if ($meta) {
                if ((string)$it['description'] === '' && $meta['description'] !== '') {
                    $set[] = 'description = ?'; $params[] = mb_substr($meta['description'], 0, 500);
                    $r['notes'][] = 'description ✔';
                }
                if (!$it['category_id'] && $meta['category'] !== '') {
                    $ck = mb_strtolower(trim($meta['category']));
                    if (!isset($catByLower[$ck])) {
                        q('INSERT INTO categories (name) VALUES (?)', [trim($meta['category'])]);
                        $catByLower[$ck] = insert_id();
                        $cats[] = ['id' => $catByLower[$ck], 'name' => trim($meta['category'])];
                        $r['notes'][] = 'new category "' . trim($meta['category']) . '"';
                    }
                    $set[] = 'category_id = ?'; $params[] = $catByLower[$ck];
                    $r['notes'][] = 'category: ' . trim($meta['category']);
                }
                if ($meta['category'] === '' && $meta['description'] === '') $r['notes'][] = 'AI could not identify this product — fill by hand';
            }
            if ($set) { $params[] = $it['id']; q('UPDATE items SET ' . implode(', ', $set) . ' WHERE id = ?', $params); }
        }

        if ($modePhoto && !$photoStop && (string)$it['photo'] === '' && $it['item_type'] === 'product') {
            list($urls, $err) = gcs_image_search($label . ' product photo', 4);
            $searchCalls++;
            if ($err) {
                // quota / keys — disable photos for the rest of the run but
                // let the text side keep going
                $photoStop = $err; $r['notes'][] = 'photo stopped: ' . $err;
            } else {
                $saved = false; $tried = 0;
                foreach ($urls as $url) {
                    if ($tried >= 3) break;
                    $jpeg = ai_fetch_image($url);
                    if (!$jpeg) continue;
                    $tried++;
                    $v = gemini_verify_image($jpeg, $label);
                    $geminiCalls++;
                    if (strpos($v, 'err:') === 0) { $fatal = substr($v, 4); break; }
                    if ($v === 'yes') {
                        if (!is_dir(__DIR__ . '/uploads')) mkdir(__DIR__ . '/uploads', 0755, true);
                        $path = 'uploads/item_ai_' . $it['id'] . '_' . time() . '.jpg';
                        file_put_contents(__DIR__ . '/' . $path, $jpeg);
                        q('UPDATE items SET photo = ? WHERE id = ?', [$path, $it['id']]);
                        $r['notes'][] = 'photo ✔ (verified)';
                        $saved = true;
                        break;
                    }
                }
                if (!$saved && !$fatal) $r['notes'][] = $tried ? 'no photo — none passed the match check (kept empty on purpose)' : 'no photo — no usable image found';
            }
        }

        if (!$r['notes']) $r['notes'][] = 'nothing needed';
        $results[] = $r;
        if ($fatal) break;
    }

    $lastId = $results ? max(array_column($results, 'id')) : $afterId;
    $remaining = (int)val("SELECT COUNT(*) FROM items WHERE $where AND id > ?", [$lastId]);
    log_activity('ai_enrich', count($results) . ' items');
    echo json_encode([
        'results' => $results, 'remaining' => $remaining, 'last_id' => $lastId, 'fatal' => $fatal,
        'photo_stop' => $photoStop, 'gemini_calls' => $geminiCalls, 'search_calls' => $searchCalls,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$total = (int)val('SELECT COUNT(*) FROM items WHERE is_active = 1');
$noDesc = (int)val("SELECT COUNT(*) FROM items WHERE is_active = 1 AND COALESCE(description,'') = ''");
$noCat = (int)val('SELECT COUNT(*) FROM items WHERE is_active = 1 AND category_id IS NULL');
$noPhoto = (int)val("SELECT COUNT(*) FROM items WHERE is_active = 1 AND COALESCE(photo,'') = '' AND item_type = 'product'");
$geminiKey = setting('gemini_api_key') !== '' && setting('gemini_api_key') !== null;
$gcsReady = setting('gcs_api_key') && setting('gcs_cx');

$page_title = 'AI Auto-Fill (Photos, Category, Description)';
include __DIR__ . '/includes/header.php';
?>
<div class="grid-stats">
  <div class="stat"><div class="stat-label">📦 Active items</div><div class="stat-value"><?= $total ?></div></div>
  <div class="stat <?= $noDesc ? 's-warn' : 's-ok' ?>"><div class="stat-label">📝 Missing description</div><div class="stat-value"><?= $noDesc ?></div></div>
  <div class="stat <?= $noCat ? 's-warn' : 's-ok' ?>"><div class="stat-label">🏷️ Missing category</div><div class="stat-value"><?= $noCat ?></div></div>
  <div class="stat <?= $noPhoto ? 's-warn' : 's-ok' ?>"><div class="stat-label">🖼️ Missing photo</div><div class="stat-value"><?= $noPhoto ?></div></div>
</div>

<div class="card">
  <h3>🤖 What this does</h3>
  <p class="muted">For every item that is missing something, Google Gemini AI writes a short <strong>description</strong>, picks/creates the right <strong>category</strong>, and (optionally) finds a <strong>photo</strong> from Google Image Search. Every photo is double-checked by AI vision — <strong>if the image is not clearly that exact product, no photo is saved</strong>. Existing values are never overwritten.</p>
  <div class="form-row cols-2 mt">
    <label class="check-inline"><input type="checkbox" id="optText" checked <?= $geminiKey ? '' : 'disabled' ?>> Fill category + description <?= $geminiKey ? '' : '<span class="muted">(needs Gemini key)</span>' ?></label>
    <label class="check-inline"><input type="checkbox" id="optPhoto" <?= ($geminiKey && $gcsReady) ? 'checked' : 'disabled' ?>> Find + verify photos <?= ($geminiKey && $gcsReady) ? '' : '<span class="muted">(needs Gemini + Image Search keys)</span>' ?></label>
  </div>
  <?php if (!$geminiKey): ?>
    <div class="flash flash-error mt">Gemini API key is not set. Get a <strong>free</strong> key at <strong>aistudio.google.com/apikey</strong> and paste it in <a href="settings.php?cat=invoice">Settings → Invoice &amp; Payment</a>.</div>
  <?php endif; ?>
  <?php if ($geminiKey && !$gcsReady): ?>
    <div class="flash flash-success mt">Photos are off: the Google Image Search key / engine ID is not set (see <a href="settings.php?cat=invoice">Settings</a>). Category + description will still work.</div>
  <?php endif; ?>
  <button class="btn mt" id="runBtn" <?= $geminiKey ? '' : 'disabled' ?>>▶ Start Auto-Fill</button>
  <button class="btn btn-outline mt" id="testBtn" <?= $geminiKey ? '' : 'disabled' ?>>🧪 Test Keys</button>
  <button class="btn btn-muted mt" id="stopBtn" style="display:none">⏸ Stop</button>
  <div class="mt" id="progWrap" style="display:none">
    <div style="background:var(--bg);border-radius:8px;overflow:hidden;height:14px"><div id="progBar" style="height:14px;width:0%;background:var(--primary);transition:width .3s"></div></div>
    <p class="muted" id="progText" style="margin-top:6px"></p>
  </div>
  <div id="runLog" class="mt" style="max-height:340px;overflow:auto;font-size:13.5px"></div>
</div>

<div class="card">
  <h3>💰 What does it cost?</h3>
  <table>
    <thead><tr><th>Work</th><th>Service</th><th>Price</th><th>For this shop (~<?= $total ?> items)</th></tr></thead>
    <tbody>
      <tr><td>Category + description</td><td>Gemini Flash (free tier)</td><td><strong>₹0</strong> — free tier allows ~1,500 requests/day</td><td><strong>₹0</strong> (1 request per item)</td></tr>
      <tr><td>Photo check (AI vision)</td><td>Gemini Flash (free tier)</td><td><strong>₹0</strong> within the same free limit</td><td><strong>₹0</strong> (1–3 checks per photo)</td></tr>
      <tr><td>Photo search</td><td>Google Custom Search</td><td>First <strong>100/day free</strong>, then ≈ ₹450 per 1,000 searches ($5)</td><td><strong>₹0</strong> if you run ~100 items per day; all in one day ≈ ₹<?= max(0, (int)ceil(($noPhoto - 100) / 1000 * 450)) ?></td></tr>
    </tbody>
  </table>
  <p class="muted mt">Cheapest plan: leave photos ON and just run it once a day — 100 photos fill each day for free, and descriptions/categories finish entirely free on day one. The live counters above show exactly how many API calls this page has made.</p>
  <p class="muted" id="usageLine" style="display:none"></p>
</div>

<script>
(function(){
  var runBtn=document.getElementById('runBtn'),stopBtn=document.getElementById('stopBtn'),
      log=document.getElementById('runLog'),bar=document.getElementById('progBar'),
      pw=document.getElementById('progWrap'),pt=document.getElementById('progText'),
      usage=document.getElementById('usageLine');
  var stopped=false,startTotal=0,doneCount=0,afterId=0,gCalls=0,sCalls=0;
  function line(html,cls){var d=document.createElement('div');d.style.padding='4px 0';d.style.borderBottom='1px solid var(--border)';if(cls==='err')d.style.color='#dc2626';d.innerHTML=html;log.prepend(d);}
  function esc(s){var d=document.createElement('span');d.textContent=s==null?'':s;return d.innerHTML;}
  function step(){
    if(stopped){runBtn.style.display='';stopBtn.style.display='none';pt.textContent='Stopped. Press Start to continue where it left off.';return;}
    var fd=new FormData();
    fd.append('csrf','<?= csrf_token() ?>');
    fd.append('do','run');
    fd.append('text',document.getElementById('optText').checked?'1':'0');
    fd.append('photo',document.getElementById('optPhoto').checked?'1':'0');
    fd.append('after_id',afterId);
    fetch('ai_enrich.php',{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(j){
      gCalls+=j.gemini_calls||0;sCalls+=j.search_calls||0;
      usage.style.display='';usage.textContent='This session: '+gCalls+' Gemini calls (free tier), '+sCalls+' image searches ('+(sCalls>100?'over':'within')+' the 100/day free quota).';
      (j.results||[]).forEach(function(r){line('<strong>'+esc(r.name)+'</strong> — '+esc((r.notes||[]).join(', ')));});
      if(j.photo_stop){line('Photos paused: '+esc(j.photo_stop),'err');document.getElementById('optPhoto').checked=false;}
      if(j.fatal){line('Stopped: '+esc(j.fatal),'err');runBtn.style.display='';stopBtn.style.display='none';pt.textContent='Stopped — fix the message above, then press Start to continue.';return;}
      doneCount+=(j.results||[]).length;
      afterId=j.last_id||afterId;
      if(!startTotal)startTotal=doneCount+(j.remaining||0);
      bar.style.width=(startTotal?Math.min(100,Math.round(doneCount/startTotal*100)):100)+'%';
      pt.textContent=doneCount+' of '+startTotal+' items done, '+(j.remaining||0)+' left…';
      if((j.remaining||0)<=0||(j.results||[]).length===0){
        pt.textContent='Finished! '+doneCount+' items processed.';bar.style.width='100%';
        runBtn.style.display='';stopBtn.style.display='none';
        line('<strong>✅ Done.</strong> Refresh the page to see updated counts.');
        return;
      }
      setTimeout(step,800);
    }).catch(function(e){line('Network error: '+esc(e.message)+' — retrying in 5s…','err');setTimeout(step,5000);});
  }
  runBtn.addEventListener('click',function(){
    stopped=false;runBtn.style.display='none';stopBtn.style.display='';pw.style.display='';
    var t=document.getElementById('optText').checked,p=document.getElementById('optPhoto').checked;
    if(!t&&!p){alert('Tick at least one option.');runBtn.style.display='';stopBtn.style.display='none';return;}
    startTotal=0;doneCount=0;afterId=0;
    pt.textContent='Starting…';
    step();
  });
  stopBtn.addEventListener('click',function(){stopped=true;});
  var testBtn=document.getElementById('testBtn');
  testBtn.addEventListener('click',function(){
    testBtn.disabled=true;testBtn.textContent='Testing…';
    var fd=new FormData();
    fd.append('csrf','<?= csrf_token() ?>');
    fd.append('do','test');
    fetch('ai_enrich.php',{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(j){
      line('<strong>Key test:</strong><br>Gemini: '+esc(j.gemini)+'<br>Image Search: '+esc(j.gcs));
      testBtn.disabled=false;testBtn.textContent='🧪 Test Keys';
    }).catch(function(e){line('Test failed: '+esc(e.message),'err');testBtn.disabled=false;testBtn.textContent='🧪 Test Keys';});
  });
})();
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
