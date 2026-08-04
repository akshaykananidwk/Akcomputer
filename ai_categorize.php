<?php
// One-click AI catalog organizer: every active item gets a category +
// sub-category (existing names reused where they fit), so a 400-500 product
// catalog sorts itself in a few minutes. Runs in small batches from the
// browser - each batch is ONE Gemini call carrying only item names, and the
// page shows live progress. Re-running is safe: it just re-files items.
require_once __DIR__ . '/includes/init.php';
require_perm('items.edit');

// ---- one batch (AJAX): classify up to 40 items, create/reuse categories ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'batch') {
    header('Content-Type: application/json');
    $offset = max(0, (int)post('offset'));
    $total = (int)val('SELECT COUNT(*) FROM items WHERE is_active = 1');
    $items = all('SELECT id, name, brand, model FROM items WHERE is_active = 1 ORDER BY id LIMIT 40 OFFSET ' . $offset);
    if (!$items) die(json_encode(['ok' => true, 'next' => $offset, 'total' => $total, 'done' => true, 'rows' => []]));

    $parents = array_column(all('SELECT name FROM categories WHERE parent_id IS NULL ORDER BY name'), 'name');
    $list = '';
    foreach ($items as $it) $list .= $it['id'] . '|' . trim($it['name'] . ' ' . $it['brand'] . ' ' . $it['model']) . "\n";
    $prompt = "You are organizing the product catalog of a computer & CCTV shop in India.\n"
        . "For EVERY product below, assign a short category and sub-category in English (2-3 words each, Title Case).\n"
        . ($parents ? "PREFER these existing categories when they fit: " . implode(', ', $parents) . ".\n" : '')
        . "Good examples: CCTV & Security > IP Camera / HD Camera / DVR & NVR / CCTV Accessories; Computers & Laptops > Laptop / Desktop & CPU / Monitor; Printers & Ink > Ink Tank Printer / Cartridge & Ink; Networking > WiFi Router / Switch & LAN; Accessories > Keyboard & Mouse / Cables & Adapters; Power > UPS & Battery.\n"
        . "Products (id|name):\n$list\n"
        . 'Reply ONLY a JSON array like [{"id":12,"cat":"CCTV & Security","sub":"IP Camera"}] with one object per product, every id present.';
    list($out, $err) = gemini_generate([['text' => $prompt]], 90, true);
    if ($out === null) die(json_encode(['ok' => false, 'error' => $err]));
    $map = json_decode($out, true);
    if (!is_array($map)) die(json_encode(['ok' => false, 'error' => 'AI reply was not valid JSON - run this batch again.']));

    $names = [];
    foreach ($items as $it) $names[(int)$it['id']] = $it['name'];
    $rows = []; $doneN = 0;
    foreach ($map as $m) {
        $iid = (int)($m['id'] ?? 0);
        $cat = trim((string)($m['cat'] ?? ''));
        $sub = trim((string)($m['sub'] ?? ''));
        if (!$iid || $cat === '' || !isset($names[$iid])) continue;
        $pid = (int)val('SELECT id FROM categories WHERE parent_id IS NULL AND LOWER(name) = LOWER(?)', [$cat]);
        if (!$pid) { q('INSERT INTO categories (name, parent_id) VALUES (?, NULL)', [$cat]); $pid = insert_id(); }
        $cid = $pid;
        if ($sub !== '' && mb_strtolower($sub) !== mb_strtolower($cat)) {
            $cid = (int)val('SELECT id FROM categories WHERE parent_id = ? AND LOWER(name) = LOWER(?)', [$pid, $sub]);
            if (!$cid) { q('INSERT INTO categories (name, parent_id) VALUES (?, ?)', [$sub, $pid]); $cid = insert_id(); }
        }
        q('UPDATE items SET category_id = ? WHERE id = ?', [$cid, $iid]);
        $rows[] = ['name' => $names[$iid], 'cat' => $cat, 'sub' => $sub];
        $doneN++;
    }
    log_activity('ai_categorize', "batch offset=$offset assigned=$doneN");
    $next = $offset + count($items);
    die(json_encode(['ok' => true, 'next' => $next, 'total' => $total, 'done' => $next >= $total, 'rows' => $rows], JSON_UNESCAPED_UNICODE));
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
$haveAi = setting('gemini_api_key') !== '';
try { $haveTree = true; val('SELECT parent_id FROM categories LIMIT 1'); } catch (Exception $e) { $haveTree = false; }
$page_title = 'AI Categories';
include __DIR__ . '/includes/header.php';
?>
<div class="card">
  <h2>🤖 AI Catalog Organizer</h2>
  <p class="muted">એક ક્લિકમાં બધી પ્રોડક્ટ <strong>કેટેગરી → સબ-કેટેગરી</strong> માં ગોઠવાઈ જશે (દા.ત. CCTV &amp; Security → IP Camera). AI દરેક પ્રોડક્ટનું નામ વાંચીને જાતે ગોઠવે છે; હાલની કેટેગરી બંધબેસતી હોય તો એ જ વપરાય છે. ફરી ચલાવવું સેફ છે.</p>
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
  <div class="no-print" style="display:flex;gap:10px;flex-wrap:wrap;margin:10px 0">
    <button class="btn" id="startBtn">▶ Start — બધી <?= $total ?> પ્રોડક્ટ ગોઠવો</button>
    <form method="post" onsubmit="return confirm('ખાલી કેટેગરી કાઢી નાખવી?')"><?= csrf_field() ?><input type="hidden" name="do" value="cleanup"><button class="btn btn-outline" type="submit">🧹 ખાલી કેટેગરી સાફ કરો</button></form>
  </div>
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
    function step(offset) {
      var fd = new FormData();
      fd.append('csrf', CSRF_TOKEN); fd.append('do', 'batch'); fd.append('offset', offset);
      fetch('ai_categorize.php', { method: 'POST', body: fd }).then(function (r) { return r.json(); }).then(function (d) {
        if (!d.ok) {
          document.getElementById('progTxt').textContent = '❌ ' + (d.error || 'error') + ' — ફરી Start દબાવો, જ્યાં અટક્યું ત્યાંથી આગળ વધશે.';
          btn.disabled = false; btn.textContent = '▶ Start (ફરી)';
          return;
        }
        (d.rows || []).forEach(function (x) {
          var div = document.createElement('div');
          div.textContent = x.name + '  →  ' + x.cat + (x.sub ? ' › ' + x.sub : '');
          log.prepend(div);
        });
        var pct = d.total ? Math.round(d.next / d.total * 100) : 100;
        document.getElementById('progBar').style.width = pct + '%';
        document.getElementById('progTxt').textContent = d.next + ' / ' + d.total + ' પ્રોડક્ટ થઈ (' + pct + '%)';
        if (d.done) {
          document.getElementById('progTxt').textContent = '✅ પૂરું! ' + d.total + ' પ્રોડક્ટ કેટેગરી-સબકેટેગરીમાં ગોઠવાઈ ગઈ.';
          btn.textContent = '✅ Done';
        } else { step(d.next); }
      }).catch(function () {
        document.getElementById('progTxt').textContent = '❌ નેટવર્ક ભૂલ — ફરી Start દબાવો.';
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
