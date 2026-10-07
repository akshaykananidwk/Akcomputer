<?php
// The kind of business a shop is, and the small differences it makes.
// Every switch is a setting (set by the business pack at sign-up, or later
// in Settings → Type of business); every helper answers "no" for a shop that
// has not switched it on, so the owner's computer shop sees nothing new.

function biz_on($flag) { return setting($flag, '0') === '1'; }
function biz_type() { return setting('business_type', function_exists('tenant_active') && tenant_active() ? 'general' : 'computer'); }

/** What the shop calls a unit's unique number: "Serial No", or "IMEI" for a mobile shop. */
function biz_serial_label() { return setting('biz_serial_label', '') ?: 'Serial No'; }

/** A real IMEI: 15 digits whose last one is the Luhn check digit. */
function imei_valid($s) {
    $s = preg_replace('/\s+/', '', (string)$s);
    if (!preg_match('/^\d{15}$/', $s)) return false;
    $sum = 0;
    for ($i = 0; $i < 15; $i++) {
        $d = (int)$s[$i];
        if ($i % 2 === 1) { $d *= 2; if ($d > 9) $d -= 9; }
        $sum += $d;
    }
    return $sum % 10 === 0;
}

/** For a mobile shop: the IMEIs in a list that are not real ones ([] = all fine). */
function biz_bad_imeis(array $serials) {
    if (biz_serial_label() !== 'IMEI') return [];
    return array_values(array_filter($serials, fn($s) => !imei_valid($s)));
}

/** Today's metal rates for a jewellery shop, per gram. */
function biz_metal_rates() {
    return ['22K' => (float)setting('gold_rate_22k', 0), '24K' => (float)setting('gold_rate_24k', 0),
            '18K' => (float)setting('gold_rate_18k', 0), 'Silver' => (float)setting('silver_rate', 0)];
}

/** What the billing screen needs to know (Bill.cfg.biz in assets/app.js). */
function biz_billing_cfg() {
    $c = [
        'weight' => biz_on('biz_weight'), 'measure' => biz_on('biz_measure'), 'shade' => biz_on('biz_shade'),
        'serialLabel' => biz_serial_label(), 'boxes' => biz_on('biz_box_units'), 'slabs' => biz_on('biz_slabs'),
    ];
    if (biz_on('biz_jewellery')) $c['jewel'] = biz_metal_rates();
    if (biz_on('biz_appointments')) {
        try { $c['staff'] = all('SELECT id, name FROM users WHERE is_active = 1 ORDER BY name'); } catch (Exception $e) { $c['staff'] = []; }
    }
    return $c;
}

/** Box size and quantity price-breaks of some items: [id => ['box' => n, 'slabs' => [[min, price], ...]]]. */
function biz_item_extras(array $ids) {
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (!$ids || (!biz_on('biz_box_units') && !biz_on('biz_slabs'))) return [];
    $in = implode(',', $ids); $out = [];
    try {
        foreach (all("SELECT id, box_qty FROM items WHERE id IN ($in) AND box_qty > 0") as $r) $out[(int)$r['id']]['box'] = (float)$r['box_qty'];
        foreach (all("SELECT item_id, min_qty, price FROM item_slabs WHERE item_id IN ($in) ORDER BY min_qty") as $r)
            $out[(int)$r['item_id']]['slabs'][] = [(float)$r['min_qty'], (float)$r['price']];
    } catch (Exception $e) { return []; }   // before v88
    return $out;
}

/** The price for a quantity, given an item's base price and its slabs. */
function slab_price($base, array $slabs, $qty) {
    $p = (float)$base;
    foreach ($slabs as [$min, $price]) if ($qty + 1e-9 >= $min) $p = (float)$price;
    return $p;
}

/** "10:95, 50:90" (from qty: price) -> [[10, 95], [50, 90]]; nonsense is dropped. */
function slabs_parse($text) {
    $out = [];
    foreach (preg_split('/[,\n;]+/', (string)$text) as $part) {
        if (!preg_match('/^\s*(\d+(?:\.\d+)?)\s*[:=@]\s*(\d+(?:\.\d+)?)\s*$/', $part, $m)) continue;
        if ((float)$m[1] > 0 && (float)$m[2] >= 0) $out[(string)(float)$m[1]] = [(float)$m[1], (float)$m[2]];
    }
    ksort($out, SORT_NUMERIC);
    return array_values($out);
}

/** Batches that expire within $days (or already have), for a medical store's dashboard. */
function biz_expiring_batches($days = 30) {
    try {
        return all("SELECT b.*, i.name item_name, DATEDIFF(b.expiry_date, CURDATE()) days_left FROM item_batches b JOIN items i ON i.id = b.item_id
                    WHERE b.qty > 0 AND b.expiry_date IS NOT NULL AND b.expiry_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY)
                    ORDER BY b.expiry_date LIMIT 50", [(int)$days]);
    } catch (Exception $e) { return []; }
}

/** Sizes × colours ("S,M,L" and "Red,Blue") -> ["S / Red", "S / Blue", ...]; either list may be empty. */
function variant_names($sizes, $colours) {
    $split = fn($t) => array_values(array_unique(array_filter(array_map('trim', preg_split('/[,\n]+/', (string)$t)), 'strlen')));
    $a = $split($sizes) ?: ['']; $b = $split($colours) ?: [''];
    $out = [];
    foreach ($a as $x) foreach ($b as $y) if (($v = trim("$x / $y", ' /')) !== '') $out[] = mb_substr($v, 0, 60);
    return array_slice($out, 0, 200);
}

/** The item form's extra fields for a wholesale / garment shop, saved after the item itself. Returns how many variants were made. */
function biz_item_save_extras($id) {
    $made = 0;
    try {
        if (biz_on('biz_box_units')) q('UPDATE items SET box_qty = ? WHERE id = ?', [max(0, (float)post('box_qty')), $id]);
        if (biz_on('biz_slabs')) {
            q('DELETE FROM item_slabs WHERE item_id = ?', [$id]);
            foreach (slabs_parse(post('slabs')) as [$min, $price]) q('INSERT INTO item_slabs (item_id, min_qty, price) VALUES (?,?,?)', [$id, $min, $price]);
        }
        if (biz_on('biz_variants')) {
            $cols = 'category_id, brand, model, unit, hsn, tax_rate, purchase_price, selling_price, b2b_price, serial_tracked, margin_pct, item_type, warranty_months, min_stock, photo, is_active, description';
            foreach (variant_names(post('variant_sizes'), post('variant_colours')) as $v) {
                if (val('SELECT id FROM items WHERE parent_id = ? AND variant = ?', [$id, $v])) continue;
                q("INSERT INTO items (name, parent_id, variant, $cols) SELECT CONCAT(name, ' - ', ?), id, ?, $cols FROM items WHERE id = ?", [$v, $v, $id]);
                $made++;
            }
        }
    } catch (Exception $e) { /* before v88 */ }
    return $made;
}
