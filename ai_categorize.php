<?php
// One-click AI catalog organizer: every active item gets a category +
// sub-category (existing names reused where they fit), so a 400-500 product
// catalog sorts itself in a few minutes. Runs in small batches from the
// browser - each batch is ONE Gemini call carrying only item names, and the
// page shows live progress. Re-running is safe: it just re-files items.
require_once __DIR__ . '/includes/init.php';
require_perm('items.edit');

// ---- one batch (AJAX): classify up to 25 items, create/reuse categories ----
// (25, not more: long product names make the JSON answer big, and a reply
// that outgrows the model's output window comes back truncated)
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
        $items = all("SELECT id, name, brand, model FROM items WHERE is_active = 1$w ORDER BY id LIMIT 25 OFFSET $skip");
        if (!$items) die(json_encode(['ok' => true, 'skip' => $skip, 'remaining' => $remaining, 'done' => true, 'rows' => []]));
        list($rows, $err) = ai_categorize_apply(array_map(fn($it) => ['id' => $it['id'], 'name' => trim($it['name'] . ' ' . $it['brand'] . ' ' . $it['model'])], $items));
        if ($rows === null) die(json_encode(['ok' => false, 'error' => $err]));
        log_activity('ai_categorize', "mode=$mode skip=$skip assigned=" . count($rows) . ' via=' . (gemini_last_src() ?: '?'));
        $nextSkip = $mode === 'all' ? $skip + count($items) : $skip + (count($items) - count($rows));
        $remaining = (int)val("SELECT COUNT(*) FROM items WHERE is_active = 1$w");
        die(json_encode(['ok' => true, 'skip' => $nextSkip, 'remaining' => $remaining, 'via' => gemini_last_src(),
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
    flash("$n empty categories removed.");
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
  <p class="muted">Every product is sorted into the <strong>fixed structure</strong> (dealer-site style) — the AI never invents a category outside this list, so the catalogue stays clean. Open IP Camera and only IP Camera products show.</p>
  <details style="margin:8px 0"><summary style="cursor:pointer;font-weight:700">📂 See the whole structure (<?= count(ai_taxonomy()) ?> main categories)</summary>
    <div style="font-size:13px;margin-top:8px;line-height:1.7">
    <?php foreach (ai_taxonomy() as $p => $kids): ?>
      <strong><?= e($p) ?></strong>: <span class="muted"><?= e(implode(' · ', $kids)) ?></span><br>
    <?php endforeach; ?>
    </div>
  </details>
  <div class="grid-stats">
    <div class="stat"><div class="stat-label">Total products</div><div class="stat-value"><?= $total ?></div></div>
    <div class="stat <?= $uncat ? 's-bad' : 's-ok' ?>"><div class="stat-label">without a category</div><div class="stat-value"><?= $uncat ?></div></div>
    <div class="stat"><div class="stat-label">Current category</div><div class="stat-value"><?= $nCats ?></div></div>
  </div>
  <?php if (!$haveTree): ?>
  <p class="flash flash-error">First, Settings → <strong>Migrate</strong> run it (v47 - the sub-category column).</p>
  <?php elseif (!$haveAi): ?>
  <p class="flash flash-error">No Gemini API key is set (Settings → Invoice &amp; Payment) - AI sorting cannot run without it.</p>
  <?php else: ?>
  <div class="no-print" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin:10px 0">
    <button class="btn" id="startBtn">▶ Start — <?= $uncat ?> Sort new and remaining products</button>
    <label class="check-inline" style="margin:0"><input type="checkbox" id="modeAll"> 🔁 Migration — all <?= $total ?> Re-sort products (including old ones) into the new structure</label>
    <form method="post" onsubmit="return confirm('Remove the empty categories?')"><?= csrf_field() ?><input type="hidden" name="do" value="cleanup"><button class="btn btn-outline" type="submit">🧹 Clear empty categories</button></form>
  </div>
  <?php if (!$uncat): ?><p class="muted">✅ Every product has a category — a new product gets one automatically the moment it is saved.</p><?php endif; ?>
  <div id="progWrap" style="display:none">
    <div style="background:var(--bg);border-radius:999px;overflow:hidden;height:14px"><div id="progBar" style="height:14px;width:0;background:var(--acc1,#2563eb);transition:width .3s"></div></div>
    <p id="progTxt" class="muted" style="margin-top:6px"></p>
    <div id="logBox" style="max-height:320px;overflow:auto;font-size:13px;border:1px solid var(--border);border-radius:10px;padding:8px 12px;margin-top:8px"></div>
  </div>
  <script>
  document.getElementById('startBtn').addEventListener('click', function () {
    var btn = this; btn.disabled = true; btn.textContent = '⏳ Running...';
    document.getElementById('progWrap').style.display = '';
    var log = document.getElementById('logBox');
    var mode = document.getElementById('modeAll').checked ? 'all' : 'new';
    var assigned = 0, retries = 0;
    // never-stop: a failed batch (free quota over + paid also failed, or a
    // network hiccup) retries ITSELF after a countdown - no button pressing.
    // Free-tier per-minute limits reset within a minute, so waiting usually
    // is all it takes even without a paid backup key.
    function retryLater(skip, msg) {
      retries++;
      if (retries > 8) {
        document.getElementById('progTxt').textContent = '❌ ' + msg + ' — it did not work even after many attempts. Press Start again shortly (products already done are not redone).';
        btn.disabled = false; btn.textContent = '▶ Start (again)';
        return;
      }
      var wait = 45;
      var t = setInterval(function () {
        wait--;
        document.getElementById('progTxt').textContent = '⏳ ' + msg.slice(0, 80) + ' — ' + wait + ' moves on by itself in seconds (attempt ' + retries + '/8), nothing to press...';
        if (wait <= 0) { clearInterval(t); step(skip); }
      }, 1000);
    }
    function step(skip) {
      var fd = new FormData();
      fd.append('csrf', CSRF_TOKEN); fd.append('do', 'batch'); fd.append('mode', mode); fd.append('skip', skip);
      fetch('ai_categorize.php', { method: 'POST', body: fd }).then(function (r) { return r.json(); }).then(function (d) {
        if (!d.ok) { retryLater(skip, d.error || 'error'); return; }
        retries = 0;
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
        document.getElementById('progTxt').textContent = assigned + ' products sorted' + (d.skip && mode === 'new' ? ' · ' + d.skip + ' not recognised (skipped)' : '') + ' (' + pct + '%)' + (d.via === 'paid' ? ' · 💳 paid API' : d.via === 'free' ? ' · 🟢 free API' : '');
        if (d.done) {
          document.getElementById('progBar').style.width = '100%';
          document.getElementById('progTxt').textContent = '✅ Done! ' + assigned + ' products sorted' + (mode === 'new' && d.skip ? '; ' + d.skip + ' the AI could not recognise — sort them by hand in Items.' : '.');
          btn.textContent = '✅ Done';
        } else { step(d.skip); }
      }).catch(function () { retryLater(skip, 'Network error'); });
    }
    step(0);
  });
  </script>
  <?php endif; ?>
  <p class="muted" style="font-size:12.5px">Cost: roughly, for the whole catalogue <?= max(1, (int)ceil($total / 40)) ?> small AI calls — Rs 0 in the Gemini free tier.</p>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
