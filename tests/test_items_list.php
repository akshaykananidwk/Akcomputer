<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); die('CLI only'); }
// The Items screen must never send the whole catalogue to the phone: with a
// few thousand items that page took 16 s to open and froze while searching.
$src = file_get_contents(__DIR__ . '/../items.php');
t_group('Items list sends one page, and searches in the database');
t_ok('the list is cut to a page (LIMIT … OFFSET)', strpos($src, "LIMIT ' . \$per . ' OFFSET '") !== false);
t_ok('a page is a sensible size', preg_match('/\$per = (\d+);/', $src, $m) && (int)$m[1] <= 100);
t_ok('search words go to SQL as parameters, not into the query text', strpos($src, "\$wa[] = '%' . addcslashes(\$wd") !== false);
t_ok('the search box asks the server for just the list', strpos($src, "get('part') === '1'") !== false && strpos($src, "part=1") !== false);
t_ok('the old in-browser filter over every row is gone', strpos($src, "tableFilter('itemFilter'") === false);
t_ok('photos load only when scrolled to', strpos($src, 'loading="lazy"') !== false);
t_ok('the location split is read for this page only', strpos($src, 's.item_id IN ($ids)') !== false);
