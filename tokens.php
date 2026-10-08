<?php
// 🎟️ Queue tokens for the repair counter on a busy day: give a number, call
// the next one (WhatsApp tells them if they left a number), and a TV/tablet
// shows "Now serving". Numbers start again from 1 every morning.
require_once __DIR__ . '/includes/init.php';
require_perm('sales.view');
$t = today();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_perm('sales.add');
    if (post('do') === 'give') {
        $pdo = db(); $pdo->beginTransaction();
        $n = (int)val('SELECT COALESCE(MAX(token_no), 0) + 1 FROM queue_tokens WHERE tok_date = ? FOR UPDATE', [$t]);
        q('INSERT INTO queue_tokens (tok_date, token_no, name, mobile, purpose) VALUES (?,?,?,?,?)',
          [$t, $n, mb_substr(trim((string)post('name')), 0, 120), preg_replace('/[^\d+]/', '', (string)post('mobile')), mb_substr(trim((string)post('purpose')), 0, 120)]);
        $pdo->commit();
        flash("Token $n given.");
    }
    if (post('do') === 'next') {
        q("UPDATE queue_tokens SET status = 'done' WHERE tok_date = ? AND status = 'called'", [$t]);
        $nx = row("SELECT * FROM queue_tokens WHERE tok_date = ? AND status = 'waiting' ORDER BY token_no LIMIT 1", [$t]);
        if ($nx) {
            q("UPDATE queue_tokens SET status = 'called', called_at = NOW() WHERE id = ?", [$nx['id']]);
            if ($nx['mobile'] !== '') { try { send_whatsapp($nx['mobile'], '🎟️ Token ' . $nx['token_no'] . ' — it is your turn at ' . setting('app_name') . '. Please come to the counter.'); } catch (Throwable $e) {} }
        } else flash('No one is waiting.', 'info');
    }
    if (post('do') === 'left') q("UPDATE queue_tokens SET status = 'left' WHERE id = ?", [(int)post('id')]);
    redirect('tokens.php');
}

$now = row("SELECT * FROM queue_tokens WHERE tok_date = ? AND status = 'called' ORDER BY called_at DESC LIMIT 1", [$t]);
$waiting = all("SELECT * FROM queue_tokens WHERE tok_date = ? AND status = 'waiting' ORDER BY token_no", [$t]);
if (get('board')): ?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta http-equiv="refresh" content="6"><title>Now serving</title>
<style>body{margin:0;background:#0b1220;color:#fff;font-family:system-ui,sans-serif;text-align:center;min-height:100vh;display:flex;flex-direction:column;justify-content:center}
.n{font-size:28vmin;font-weight:800;color:#fbbf24;line-height:1}.w{font-size:5vmin;color:#94a3b8;margin-top:3vmin}</style></head><body>
<div style="font-size:6vmin">Now serving / હવે વારો</div><div class="n"><?= $now ? (int)$now['token_no'] : '—' ?></div>
<div class="w">Waiting: <?= $waiting ? implode(', ', array_map(fn($w) => (int)$w['token_no'], array_slice($waiting, 0, 12))) : 'no one' ?></div></body></html>
<?php exit; endif;
$page_title = 'Tokens';
include __DIR__ . '/includes/header.php';
?>
<div class="page-head"><h1>🎟️ Queue tokens</h1><a class="btn btn-sm btn-outline" href="tokens.php?board=1" target="_blank">📺 Show on TV</a></div>
<div class="card" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
  <div><div class="muted">Now serving</div><div style="font-size:42px;font-weight:800"><?= $now ? (int)$now['token_no'] : '—' ?></div><?= $now ? e($now['name'] . ' ' . $now['purpose']) : '' ?></div>
  <?php if (can('sales.add')): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="next"><button class="btn" style="font-size:18px;padding:14px 24px">📣 Call next</button></form><?php endif; ?>
</div>
<?php if (can('sales.add')): ?>
<form method="post" class="card" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:8px;align-items:end"><?= csrf_field() ?><input type="hidden" name="do" value="give">
  <div class="field"><label>Name</label><input type="text" name="name"></div><div class="field"><label>Mobile (for WhatsApp)</label><input type="tel" name="mobile"></div>
  <div class="field"><label>For</label><input type="text" name="purpose" placeholder="Repair / pickup / new purchase"></div><button class="btn" type="submit">🎟️ Give token</button></form>
<?php endif; ?>
<div class="pane"><div class="pane-head"><h3>Waiting (<?= count($waiting) ?>)</h3></div><div class="pane-body tight"><table class="rowlist"><tbody>
<?php foreach ($waiting as $w): ?><tr><td data-l="Token"><b style="font-size:18px"><?= (int)$w['token_no'] ?></b></td><td data-l="Name"><?= e($w['name']) ?> <span class="muted"><?= e($w['purpose']) ?></span></td><td data-l="Since" class="muted"><?= date('h:i A', strtotime($w['created_at'])) ?></td>
  <td class="act"><?php if (can('sales.add')): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="left"><input type="hidden" name="id" value="<?= (int)$w['id'] ?>"><button class="btn btn-sm btn-outline">Left</button></form><?php endif; ?></td></tr>
<?php endforeach; if (!$waiting): ?><tr><td class="muted">No one waiting.</td></tr><?php endif; ?></tbody></table></div></div>
<?php include __DIR__ . '/includes/footer.php'; ?>
