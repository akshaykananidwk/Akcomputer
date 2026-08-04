<?php
// One-click AI catalog organizer: every active item gets a category +
// sub-category (existing names reused where they fit), so a 400-500 product
// catalog sorts itself in a few minutes. Runs in small batches from the
// browser - each batch is ONE Gemini call carrying only item names, and the
// page shows live progress. Re-running is safe: it just re-files items.
require_once __DIR__ . '/includes/init.php';
require_perm('items.edit');

// ---- one batch (AJAX): classify up to 40 items, create/reuse categories ----
// Default mode 'new' touches ONLY uncategorized items (already-filed products
// are never re-processed - saves time and AI calls); the 'all' checkbox
// re-sorts everything. In 'new' mode assigned items drop out of the filter by
// themselves, so 'skip' only counts items the AI could not name (they are
// leapt over instead of looping forever).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'batch') {
    header('Content-Type: application/json');
    $mode = post('mode') === 'all' ? 'all' : 'new';
    $skip = max(0, (int)post('skip'));
    $w = $mode === 'new' ? ' AND (category_id IS NULL OR category_id = 0)' : '';
    try {
        $remaining = (int)val("SELECT COUNT(*) FROM items WHERE is_active = 1$w");
        $items = all("SELECT id, name, brand, model FROM items WHERE is_active = 1$w ORDER BY id LIMIT 40 OFFSET $skip");
        if (!$items) die(json_encode(['ok' => true, 'skip' => $skip, 'remaining' => $remaining, 'done' => true, 'rows' => []]));
        list($rows, $err) = ai_categorize_apply(array_map(fn($it) => ['id' => $it['id'], 'name' => trim($it['name'] . ' ' . $it['brand'] . ' ' . $it['model'])], $items));
        if ($rows === null) die(json_encode(['ok' => false, 'error' => $err]));
        log_activity('ai_categorize', "mode=$mode skip=$skip assigned=" . count($rows));
        $nextSkip = $mode === 'all' ? $skip + count($items) : $skip + (count($items) - count($rows));
        $remaining = (int)val("SELECT COUNT(*) FROM items WHERE is_active = 1$w");
        die(json_encode(['ok' => true, 'skip' => $nextSkip, 'remaining' => $remaining,
                         'done' => $remaining <= $nextSkip, 'rows' => $rows], JSON_UNESCAPED_UNICODE));
    } catch (Exception $e) {
        die(json_encode(['ok' => false, 'error' => $e->getMessage()]));
    }
}

// ---- optional cleanup: drop categories that no longer hold any item ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'cleanup') {
    $n = 0;
    foreach (all('SELECT c.id FROM categories c WHERE NOT EXISTS (SELECT 1 FROM items i WHERE i.category_id = c.id)
                  AND NOT EXISTS (SELECT 1 FROM categories k WHERE k.parent_id = c.id)') as $c) {
        q('DELETE FROM categories WHERE id = ?', [$c['id']]);
        $n++;
    }
    flash("$n ખાલી કેટેગરી કાઢી નાખી.");
    redirect('ai_categorize.php');
}

$total = (int)val('SELECT COUNT(*) FROM items WHERE is_active = 1');
$uncat = (int)val('SELECT COUNT(*) FROM items WHERE is_active = 1 AND (category_id IS NULL OR category_id = 0)');
$nCats = (int)val('SELECT COUNT(*) FROM categories');
$haveAi = setting('gemini_api_key') !== '' || setting('gemini_api_key_paid') !== '';
try { $haveTree = true; val('SELECT parent_id FROM categories LIMIT 1'); } catch (Exception $e) { $haveTree = false; }
$page_title = 'AI Categories';
include __DIR__ . '/includes/header.php';
?>
<div class="card">
  <h2>🤖 AI Catalog Organizer</h2>
  <p class="muted">બધી પ્રોડક્ટ નીચે બતાવેલા <strong>ફિક્સ્ડ સ્ટ્રક્ચર</strong> (ડીલર-સાઇટ સ્ટાઇલ) માં જ ગોઠવાય છે — AI આ યાદી બહારની કોઈ નવી કેટેગરી કદી નહીં બનાવે, એટલે કેટલોગ હંમેશા ચોખ્ખો રહે. IP Camera ખોલો તો ફક્ત IP Camera ની જ પ્રોડક્ટ દેખાય.</p>
  <details style="margin:8px 0"><summary style="cursor:pointer;font-weight:700">📂 આખું સ્ટ્રક્ચર જુઓ (<?= count(ai_taxonomy()) ?> મુખ્ય કેટેગરી)</summary>
    <div style="font-size:13px;margin-top:8px;line-height:1.7">
    <?php foreach (ai_taxonomy() as $p => $kids): ?>
      <strong><?= e($p) ?></strong>: <span class="muted"><?= e(implode(' · ', $kids)) ?></span><br>
    <?php endforeach; ?>
    </div>
  </details>
  <div class="grid-stats">
    <div class="stat"><div class="stat-label">કુલ પ્રોડક્ટ</div><div class="stat-value"><?= $total ?></div></div>
    <div class="stat <?= $uncat ? 's-bad' : 's-ok' ?>"><div class="stat-label">કેટેગરી વગરની</div><div class="stat-value"><?= $uncat ?></div></div>
    <div class="stat"><div class="stat-label">હાલની કેટેગરી</div><div class="stat-value"><?= $nCats ?></div></div>
  </div>
  <?php if (!$haveTree): ?>
  <p class="flash flash-error">પહેલા Settings → <strong>Migrate</strong> ચલાવો (v47 - સબ-કેટેગરી કૉલમ).</p>
  <?php elseif (!$haveAi): ?>
  <p class="flash flash-error">Gemini API key સેટ નથી (Settings → Invoice &amp; Payment) - એ વગર AI ગોઠવણ ન ચાલે.</p>
  <?php else: ?>
  <div class="no-print" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin:10px 0">
    <button class="btn" id="startBtn">▶ Start — <?= $uncat ?> નવી/બાકી પ્રોડક્ટ ગોઠવો</button>
    <label class="check-inline" style="margin:0"><input type="checkbox" id="modeAll"> 🔁 Migration — બધી <?= $total ?> પ્રોડક્ટ (જૂની સહિત) નવા સ્ટ્રક્ચરમાં ફરી ગોઠવો</label>
    <form method="post" onsubmit="return confirm('ખાલી કેટેગરી કાઢી નાખવી?')"><?= csrf_field() ?><input type="hidden" name="do" value="cleanup"><button class="btn btn-outline" type="submit">🧹 ખાલી કેટેગરી સાફ કરો</button></form>
  </div>
  <?php if (!$uncat): ?><p class="muted">✅ બધી પ્રોડક્ટને કેટેગરી લાગેલી છે — નવી પ્રોડક્ટ ઉમેરશો એટલે એની કેટેગરી સેવ થતાં જ આપોઆપ લાગી જશે.</p><?php endif; ?>
  <div id="progWrap" style="display:none">
    <div style="background:var(--bg);border-radius:999px;overflow:hidden;height:14px"><div id="progBar" style="height:14px;width:0;background:var(--acc1,#2563eb);transition:width .3s"></div></div>
    <p id="progTxt" class="muted" style="margin-top:6px"></p>
    <div id="logBox" style="max-height:320px;overflow:auto;font-size:13px;border:1px solid var(--border);border-radius:10px;padding:8px 12px;margin-top:8px"></div>
  </div>
  <script>
  document.getElementById('startBtn').addEventListener('click', function () {
    var btn = this; btn.disabled = true; btn.textContent = '⏳ ચાલે છે...';
    document.getElementById('progWrap').style.display = '';
    var log = document.getElementById('logBox');
    var mode = document.getElementById('modeAll').checked ? 'all' : 'new';
    var assigned = 0;
    function step(skip) {
      var fd = new FormData();
      fd.append('csrf', CSRF_TOKEN); fd.append('do', 'batch'); fd.append('mode', mode); fd.append('skip', skip);
      fetch('ai_categorize.php', { method: 'POST', body: fd }).then(function (r) { return r.json(); }).then(function (d) {
        if (!d.ok) {
          document.getElementById('progTxt').textContent = '❌ ' + (d.error || 'error') + ' — ફરી Start દબાવો, જ્યાં અટક્યું ત્યાંથી આગળ વધશે (થયેલી પ્રોડક્ટ ફરી નહીં થાય).';
          btn.disabled = false; btn.textContent = '▶ Start (ફરી)';
          return;
        }
        (d.rows || []).forEach(function (x) {
          var div = document.createElement('div');
          div.textContent = x.name + '  →  ' + x.cat + (x.sub ? ' › ' + x.sub : '');
          log.prepend(div);
        });
        assigned += (d.rows || []).length;
        // 'new' mode: remaining = still-uncategorized (incl. skipped); done share = assigned + skipped
        var doneCount = mode === 'all' ? d.skip : assigned + d.skip;
        var tot = mode === 'all' ? d.remaining : assigned + d.remaining;
        var pct = tot ? Math.min(100, Math.round(doneCount / tot * 100)) : 100;
        document.getElementById('progBar').style.width = pct + '%';
        document.getElementById('progTxt').textContent = assigned + ' પ્રોડક્ટ ગોઠવાઈ' + (d.skip && mode === 'new' ? ' · ' + d.skip + ' ઓળખાઈ નહીં (skip)' : '') + ' (' + pct + '%)';
        if (d.done) {
          document.getElementById('progBar').style.width = '100%';
          document.getElementById('progTxt').textContent = '✅ પૂરું! ' + assigned + ' પ્રોડક્ટ ગોઠવાઈ' + (mode === 'new' && d.skip ? '; ' + d.skip + ' ને AI ઓળખી ન શક્યું — એ Items માં જાતે ગોઠવી દો.' : '.');
          btn.textContent = '✅ Done';
        } else { step(d.skip); }
      }).catch(function () {
        document.getElementById('progTxt').textContent = '❌ નેટવર્ક ભૂલ — ફરી Start દબાવો (થયેલી ફરી નહીં થાય).';
        btn.disabled = false; btn.textContent = '▶ Start (ફરી)';
      });
    }
    step(0);
  });
  </script>
  <?php endif; ?>
  <p class="muted" style="font-size:12.5px">ખર્ચ: આખા કેટલોગ માટે આશરે <?= max(1, (int)ceil($total / 40)) ?> નાના AI કૉલ — Gemini ના ફ્રી-ટિયરમાં ₹0.</p>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
