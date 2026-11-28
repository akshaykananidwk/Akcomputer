<?php
// Public blog: one buying guide per product on the website (/blog/{id}/slug),
// and the list of all guides (/blog). Content: includes/blog.php.
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/seo.php';
require_once __DIR__ . '/includes/store_ui.php';
require_once __DIR__ . '/includes/blog.php';

$c = store_ctx(); $city = shop_city() ?: 'Dwarka'; $brandName = $c['app'] . ' ' . $city;
$all = array_values(array_filter(store_items(), fn($x) => $x['item_type'] !== 'service'));
$id = (int)get('id');

if (!$id) {
    // ---------- the list of guides ----------
    $q = trim((string)get('q'));
    $list = $q !== '' ? store_search($all, $q) : $all;
    $per = 24; $pages = max(1, (int)ceil(count($list) / $per)); $page = min(max(1, (int)get('page', 1)), $pages);
    $show = array_slice($list, ($page - 1) * $per, $per);
    $canon = blog_index_url() . ($page > 1 ? '?page=' . $page : '');
    store_head('Buying Guides & Blog | ' . $brandName, "Product buying guides by $brandName: CCTV cameras, DVR, NVR, laptops, printers, networking and computer parts – prices, specifications and tips.", $canon, '', $q !== '' ? 'noindex, follow' : '');
    store_header('back', 'Blog');
    store_nav(store_groups(store_items())); ?>
<main class="sec" style="max-width:980px">
  <h1 style="font-size:24px;margin:6px 0 4px">Buying Guides</h1>
  <p class="muted" style="margin-bottom:12px">Honest guides from <?= e($brandName) ?> for every product we sell – price, specifications, uses and what to check before you buy.</p>
  <form method="get" action="<?= e(blog_index_url()) ?>" class="field" style="flex-direction:row;gap:8px"><input name="q" value="<?= e($q) ?>" placeholder="Search guides" style="flex:1" aria-label="Search guides"><button class="btn" type="submit">Search</button></form>
  <div class="blist">
  <?php foreach ($show as $x): ?>
    <a class="panel bcard" href="<?= e(blog_url($x)) ?>"><span class="ti"><?php if ($x['photo']): ?><img src="<?= e(base_url($x['photo'])) ?>" alt="" loading="lazy"><?php else: ?><?= store_icon(store_cat_icon($x['cat_name'] ?: 'box')) ?><?php endif; ?></span>
      <span><small><?= e($x['cat_name'] ?: store_group($x)) ?></small><b><?= e(blog_short($x['name'])) ?>: Price, Specs &amp; Buying Guide</b></span></a>
  <?php endforeach; ?>
  </div>
  <?php if (!$show): ?><div class="panel empty"><h3>No guide found</h3><a class="btn ghost" href="<?= e(blog_index_url()) ?>">All guides</a></div><?php endif; ?>
  <?php if ($pages > 1): ?><nav class="chips" style="justify-content:center;margin-top:16px" aria-label="Pages">
    <?php if ($page > 1): ?><a class="chip" rel="prev" href="<?= e(blog_index_url() . '?' . http_build_query(array_filter(['q' => $q, 'page' => $page - 1]))) ?>">‹ Previous</a><?php endif; ?>
    <span class="chip on"><?= $page ?> / <?= $pages ?></span>
    <?php if ($page < $pages): ?><a class="chip" rel="next" href="<?= e(blog_index_url() . '?' . http_build_query(array_filter(['q' => $q, 'page' => $page + 1]))) ?>">Next ›</a><?php endif; ?>
  </nav><?php endif; ?>
</main>
<?php store_footer(); store_end('home'); exit;
}

// ---------- one guide ----------
$it = null; foreach ($all as $x) if ((int)$x['id'] === $id) { $it = $x; break; }
if (!$it) {
    http_response_code(404);
    store_head('Guide not available | ' . $c['app'], 'This guide is not available.', '', '', 'noindex');
    store_header('back', 'Not found');
    echo '<main class="sec" style="max-width:520px"><div class="panel empty">' . store_icon('search') . '<h3>This guide is not available</h3><a class="btn" href="' . e(blog_index_url()) . '">All guides</a></div></main>';
    store_footer(); store_end('home'); exit;
}
$similar = array_slice(array_values(array_filter($all, fn($x) => (int)$x['id'] !== $id && $x['category_id'] && $x['category_id'] == $it['category_id'] && (float)$x['selling_price'] > 0)), 0, 6);
$p = blog_post($it, $similar);
$url = blog_url($it); $purl = seo_product_url($it);
$ld = ['@context' => 'https://schema.org', '@type' => 'BlogPosting', 'headline' => mb_substr($p['h1'], 0, 110), 'description' => $p['desc'], 'url' => $url,
       'mainEntityOfPage' => $url, 'datePublished' => date('c', strtotime($it['created_at'] ?? 'now')), 'dateModified' => date('c', strtotime($it['updated_at'] ?? $it['created_at'] ?? 'now')),
       'author' => ['@type' => 'Organization', 'name' => $brandName], 'publisher' => ['@type' => 'Organization', 'name' => $brandName, 'logo' => ['@type' => 'ImageObject', 'url' => $c['logo'] ?: base_url('assets/icon-192.png')]],
       'about' => ['@type' => 'Product', 'name' => $it['name'], 'url' => $purl]];
if ($it['photo']) $ld['image'] = [base_url($it['photo'])];
$head = seo_jsonld($ld) . seo_faq_jsonld($p['faq']) . seo_breadcrumbs([[$c['app'], base_url('') . '/'], ['Blog', blog_index_url()], [blog_short($it['name'])]])
      . ($it['photo'] ? '<meta property="og:image" content="' . e(base_url($it['photo'])) . '"><meta property="og:type" content="article">' : '');
store_head($p['title'], $p['desc'], $url, $head);
store_header('back', 'Buying guide');
store_nav(store_groups(store_items()), store_group($it));
$dp = dealer_price($it['selling_price'], $c['pct']); ?>
<main class="sec blog" style="max-width:820px">
  <p class="muted" style="font-size:13px"><a href="<?= e(blog_index_url()) ?>">Blog</a> › <?= e($it['cat_name'] ?: store_group($it)) ?></p>
  <h1><?= e($p['h1']) ?></h1>
  <p class="muted" style="font-size:13px">By <?= e($brandName) ?> · Updated <?= date('j F Y') ?></p>
  <?php if ($it['photo']): ?><img class="bimg" src="<?= e(base_url($it['photo'])) ?>" alt="<?= e($it['name']) ?>"><?php endif; ?>
  <p class="lead"><?= e($p['intro']) ?></p>
  <div class="panel bbuy"><span><b><?= e(blog_short($it['name'])) ?></b><br><span class="price">₹<?= money($dp) ?></span></span>
    <a class="btn" href="<?= e($purl) ?>">View product</a><?php if ($c['wa']): ?><a class="btn wa" href="https://wa.me/<?= e($c['wa']) ?>?text=<?= rawurlencode('Hello! I want: ' . $it['name'] . "\n" . $purl) ?>" target="_blank" rel="noopener"><?= store_icon('wa') ?>WhatsApp</a><?php endif; ?></div>
  <?php foreach ($p['sections'] as $sec): [$h, $paras] = $sec; $tbl = $sec[2] ?? null; $ul = $sec[3] ?? null; $cmp = $sec[4] ?? null; ?>
  <h2><?= e($h) ?></h2>
  <?php foreach ($paras as $x): ?><p><?= e($x) ?></p><?php endforeach; ?>
  <?php if ($tbl): ?><table class="spec"><tbody><?php foreach ($tbl as [$k, $v]): ?><tr><th scope="row"><?= e($k) ?></th><td><?= e($v) ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?>
  <?php if ($ul): ?><ul><?php foreach ($ul as $x): ?><li><?= e($x) ?></li><?php endforeach; ?></ul><?php endif; ?>
  <?php if ($cmp): ?><table class="spec cmp"><tbody><?php foreach ($cmp as $x): ?><tr><th scope="row"><a href="<?= e(blog_url($x)) ?>"><?= e(blog_short($x['name'])) ?></a></th><td>₹<?= money(dealer_price($x['selling_price'], $c['pct'])) ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?>
  <?php endforeach; ?>
  <h2>Frequently asked questions</h2>
  <?php foreach ($p['faq'] as $q => $a): ?><details class="panel faq" style="padding:0 16px;margin-bottom:8px"><summary><?= e($q) ?></summary><p style="padding-bottom:12px"><?= e($a) ?></p></details><?php endforeach; ?>
  <p style="margin-top:18px"><a class="btn block" href="<?= e($purl) ?>">See price &amp; order <?= e(blog_short($it['name'])) ?></a></p>
</main>
<?php store_footer(); store_end('home');
