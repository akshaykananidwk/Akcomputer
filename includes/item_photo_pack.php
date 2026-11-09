<?php
// Product photos shipped with the update (install/item_photos), each the
// maker's official picture of that exact model, taken from the brand's own
// website and checked by eye. At Update they go onto the matching products
// that still have no photo - a photo the owner already set is never replaced.
// The same run turns the online store ON for active products with a price
// (asked for on 09-11-2026). Runs once; the list of items it switched on is
// kept so it can be undone.

/** [regex on the lower-case item name, photo file] */
function item_photo_pack_map() {
    return [
        ['/da21l3c-lq/', 'cpplus_da21l3c_lq.jpg'],
        ['/ta21l3c-lq/', 'cpplus_ta21l3c_lq.jpg'],
        ['/4k2161-v4/', 'cpplus_nvr_4k2161v4.jpg'],
        ['/cp plus 64gb micro ?sd/', 'cpplus_um_microsd.jpg'],
        ['/rb760igs|hex s\b/', 'mikrotik_hexs.jpg'],
        ['/eap225 ac ?1350|eap225.*ceiling/', 'tplink_eap225.jpg'],
        ['/eap225.*outdoor/', 'tplink_eap225_outdoor.jpg'],
        ['/es208g/', 'tplink_es208g.jpg'],
        ['/gm320 pro/', 'ant_gm320pro.jpg'],
        ['/gpl300/', 'ant_gpl300.jpg'],
        ['/km1410/', 'ant_km1410.jpg'],
        ['/km550/', 'ant_km550.jpg'],
        ['/mk740/', 'ant_mk740.jpg'],
        ['/virtus 100.*black/', 'ant_virtus100_black.jpg'],
        ['/virtus 100.*white/', 'ant_virtus100_white.jpg'],
        ['/bf-888s/', 'baofeng_bf888s.jpg'],
        ['/foxin pro 128gb/', 'foxin_pro128.jpg'],
        ['/ideapad 130-15ikb/', 'lenovo_ideapad130_15.jpg'],
        ['/ideapad 330\b/', 'lenovo_ideapad330_15.jpg'],
        ['/zeb-450w black gold/', 'zeb_450w_blackgold.jpg'],
        ['/a122fhd|a22fhd/', 'zeb_a22fhd.jpg'],
        ['/pixaplay 58/', 'zeb_pixaplay58.jpg'],
        ['/z-eem2|ee22fhd/', 'zebster_ee22fhd.jpg'],
    ];
}

/** Photo file for one item name, or null. */
function item_photo_pack_for($name) {
    $n = mb_strtolower((string)$name);
    foreach (item_photo_pack_map() as [$re, $file]) if (preg_match($re, $n)) return $file;
    return null;
}

/** Puts the shipped photos on products without one. Returns how many. */
function item_photo_pack_apply() {
    $src = __DIR__ . '/../install/item_photos';
    $n = 0;
    foreach (all("SELECT id, name FROM items WHERE item_type = 'product' AND COALESCE(photo, '') = ''") as $it) {
        $file = item_photo_pack_for($it['name']);
        if (!$file || !is_file("$src/$file")) continue;
        $dir = up_dir('items');
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $rel = up_rel('items') . '/brand_' . $it['id'] . '_' . $file;
        if (!@copy("$src/$file", __DIR__ . '/../' . $rel)) continue;
        q("UPDATE items SET photo = ? WHERE id = ? AND COALESCE(photo, '') = ''", [$rel, $it['id']]);
        $n++;
    }
    return $n;
}

/** Turns the online store ON for every active product that has a price.
 *  A product at ₹0 stays off (it would show as free). Returns the ids. */
function item_website_on_all() {
    $ids = array_map('intval', array_column(all("SELECT id FROM items WHERE is_active = 1 AND item_type = 'product'
                                                 AND show_on_website = 0 AND selling_price > 0"), 'id'));
    if ($ids) q('UPDATE items SET show_on_website = 1 WHERE id IN (' . implode(',', $ids) . ')');
    return $ids;
}

/** Once, at the first Update that ships this file. */
function item_photo_pack_once() {
    if (setting('item_photo_pack_done') !== '') return;
    $photos = item_photo_pack_apply();
    $on = item_website_on_all();
    set_setting('item_web_on_undo', json_encode($on));
    set_setting('item_photo_pack_done', date('Y-m-d H:i:s') . " (photos $photos, website on " . count($on) . ')');
    log_activity('item_photo_pack', "photos=$photos website_on=" . count($on));
}
