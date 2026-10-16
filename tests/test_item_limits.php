<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); die('CLI only'); }
// ============================================================================
//  ITEM TEXT LIMITS - a long product name must save, and one longer than the
//  column must be refused with a message, never crash ("Data too long for
//  column 'name'", POST /items.php?action=new, 17-10-2026).
// ============================================================================
t_group('Item names and fields fit their columns');
$long = str_repeat('HP 15s Ryzen 5 5500U 8GB 512GB SSD FHD Laptop ', 5);   // ~230 characters
$long = mb_substr($long, 0, 230);
q("INSERT INTO items (name, selling_price, purchase_price, is_active, item_type) VALUES (?, 1, 1, 1, 'product')", [$long]);
t_eq('a 230-character product name now saves whole', mb_strlen((string)val('SELECT name FROM items WHERE id = ?', [(int)val('SELECT MAX(id) FROM items')])), 230);
$cols = [];
foreach (all("SELECT column_name c, character_maximum_length n FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'items'") as $r) $cols[strtolower($r['c'])] = (int)$r['n'];
foreach (item_text_limits() as $f => [$max]) t_eq("the form's limit for $f is exactly what the column holds", $max, $cols[$f] ?? -1);
$src = file_get_contents(__DIR__ . '/../items.php');
t_ok('the item screen refuses a too-long text with a message instead of crashing', strpos($src, 'foreach (item_text_limits() as $f => [$max, $label])') !== false);
t_ok('...and the browser stops typing at the limit', strpos($src, 'name="name" maxlength="255"') !== false);
foreach (['ajax.php' => "mb_substr(trim((string)post('name')), 0, 255)", 'api.php' => "mb_substr(trim((string)(\$b['name'] ?? '')), 0, 255)",
          'items_import.php' => "mb_substr(trim((string)\$cell(\$r, 'name')), 0, 255)"] as $f => $needle)
    t_ok("$f keeps a new item's name inside the column", strpos(file_get_contents(__DIR__ . '/../' . $f), $needle) !== false);
