<?php
// What a new shop starts with, by the kind of business it is.
//
// Picked once, at sign-up or in the first-day wizard: a few categories, a
// handful of everyday items with sensible prices to edit (never to keep
// blindly), and the switches that turn the right screens on. A medical
// store sees expiry dates; a kirana sells by weight; a salon books
// appointments. Nothing here is required - "general" adds nothing at all.
//
// item: [name, category, selling price, purchase price, unit, type, serial tracked]

function business_packs() {
    return [
        'general' => ['🏪', 'General shop', 'સામાન્ય દુકાન', [], [], []],
        'computer' => ['💻', 'Computer & CCTV', 'કમ્પ્યુટર અને CCTV',
            ['Laptops', 'Accessories', 'CCTV', 'Networking', 'Service'],
            [['Wireless Mouse', 'Accessories', 450, 280, 'pcs', 'product', 0],
             ['USB Keyboard', 'Accessories', 550, 360, 'pcs', 'product', 0],
             ['8GB DDR4 RAM', 'Accessories', 1800, 1350, 'pcs', 'product', 1],
             ['2MP CCTV Camera', 'CCTV', 1600, 1100, 'pcs', 'product', 1],
             ['Laptop Service', 'Service', 500, 0, 'job', 'service', 0]],
            ['biz_serial_label' => 'Serial No']],
        'mobile' => ['📱', 'Mobile shop', 'મોબાઇલ દુકાન',
            ['Smartphones', 'Covers', 'Screen Guards', 'Chargers', 'Repairing'],
            [['Tempered Glass', 'Screen Guards', 150, 40, 'pcs', 'product', 0],
             ['Back Cover', 'Covers', 199, 70, 'pcs', 'product', 0],
             ['20W Fast Charger', 'Chargers', 699, 420, 'pcs', 'product', 0],
             ['Screen Replacement', 'Repairing', 1500, 0, 'job', 'service', 0]],
            ['biz_serial_label' => 'IMEI']],
        'medical' => ['💊', 'Medical store', 'મેડિકલ સ્ટોર',
            ['Tablets', 'Syrups', 'Ointments', 'Surgical', 'Baby care'],
            [['Paracetamol 500mg (10 tab)', 'Tablets', 20, 14, 'strip', 'product', 0],
             ['Cough Syrup 100ml', 'Syrups', 95, 68, 'bottle', 'product', 0],
             ['Antiseptic Cream 20g', 'Ointments', 60, 42, 'tube', 'product', 0],
             ['Face Mask (pack of 10)', 'Surgical', 50, 25, 'pack', 'product', 0]],
            ['biz_expiry' => '1', 'biz_prescription' => '1']],
        'kirana' => ['🛒', 'Kirana / Grocery', 'કરિયાણું',
            ['Grains', 'Oil & Ghee', 'Spices', 'Snacks', 'Daily needs'],
            [['Rice (loose)', 'Grains', 60, 48, 'kg', 'product', 0],
             ['Sugar (loose)', 'Grains', 44, 39, 'kg', 'product', 0],
             ['Groundnut Oil 1L', 'Oil & Ghee', 190, 172, 'ltr', 'product', 0],
             ['Turmeric Powder', 'Spices', 260, 200, 'kg', 'product', 0]],
            ['biz_weight' => '1']],
        'restaurant' => ['🍽️', 'Restaurant / Cafe', 'રેસ્ટોરન્ટ / કેફે',
            ['Starters', 'Main course', 'Breads', 'Beverages', 'Desserts'],
            [['Paneer Tikka', 'Starters', 220, 0, 'plate', 'service', 0],
             ['Dal Fry', 'Main course', 160, 0, 'plate', 'service', 0],
             ['Butter Roti', 'Breads', 30, 0, 'pcs', 'service', 0],
             ['Masala Tea', 'Beverages', 20, 0, 'cup', 'service', 0]],
            ['biz_tables' => '1', 'biz_table_count' => '8']],
        'salon' => ['💇', 'Salon / Parlour', 'સલૂન / પાર્લર',
            ['Hair', 'Skin', 'Beard', 'Packages'],
            [['Haircut', 'Hair', 150, 0, 'job', 'service', 0],
             ['Hair Colour', 'Hair', 800, 0, 'job', 'service', 0],
             ['Facial', 'Skin', 600, 0, 'job', 'service', 0],
             ['Beard Trim', 'Beard', 80, 0, 'job', 'service', 0]],
            ['biz_appointments' => '1']],
        'hardware' => ['🔩', 'Hardware / Paint', 'હાર્ડવેર / પેઇન્ટ',
            ['Paints', 'Pipes', 'Tools', 'Electrical', 'Fasteners'],
            [['Wall Paint 1L', 'Paints', 420, 330, 'ltr', 'product', 0],
             ['PVC Pipe 1 inch', 'Pipes', 45, 32, 'ft', 'product', 0],
             ['Hammer', 'Tools', 280, 190, 'pcs', 'product', 0],
             ['Screws (box of 100)', 'Fasteners', 120, 80, 'box', 'product', 0]],
            ['biz_shade' => '1', 'biz_measure' => '1']],
        'garment' => ['👕', 'Garment / Footwear', 'કપડાં / ચંપલ',
            ['Men', 'Women', 'Kids', 'Footwear'],
            [['Cotton Shirt', 'Men', 799, 450, 'pcs', 'product', 0],
             ['Kurti', 'Women', 699, 380, 'pcs', 'product', 0],
             ['Kids T-shirt', 'Kids', 299, 160, 'pcs', 'product', 0],
             ['Sports Shoes', 'Footwear', 1499, 900, 'pair', 'product', 0]],
            ['biz_variants' => '1']],
        'service' => ['🛠️', 'Service business', 'સર્વિસ ધંધો',
            ['Services', 'Visits', 'Contracts'],
            [['Service Visit', 'Visits', 300, 0, 'visit', 'service', 0],
             ['Installation', 'Services', 800, 0, 'job', 'service', 0],
             ['Annual Contract', 'Contracts', 3000, 0, 'year', 'service', 0]],
            ['biz_no_stock' => '1']],
        'wholesale' => ['📦', 'Wholesale / Distributor', 'હોલસેલ / વિતરક',
            ['Fast moving', 'Cartons', 'Loose'],
            [['Biscuit Packet', 'Fast moving', 10, 8, 'pcs', 'product', 0],
             ['Soap Bar', 'Fast moving', 35, 28, 'pcs', 'product', 0]],
            ['biz_box_units' => '1', 'biz_slabs' => '1']],
        'jewellery' => ['💍', 'Jewellery', 'જ્વેલરી',
            ['Gold', 'Silver', 'Diamond', 'Repairs'],
            [['Gold Ring 22K', 'Gold', 0, 0, 'gm', 'product', 1],
             ['Silver Anklet', 'Silver', 0, 0, 'gm', 'product', 0],
             ['Polishing', 'Repairs', 200, 0, 'job', 'service', 0]],
            ['biz_jewellery' => '1', 'gold_rate_22k' => '0', 'silver_rate' => '0']],
    ];
}

/** Label of a business type in the screen's language (English for now). */
function business_label($type) {
    $p = business_packs()[$type] ?? business_packs()['general'];
    return $p[0] . ' ' . $p[1];
}

/**
 * Put one pack into a shop's database: its categories, its sample items and
 * its switches. Runs on a FRESH shop; items that already exist by name are
 * left alone, so running it twice adds nothing twice.
 */
function business_pack_apply(PDO $pdo, $type, $withItems = true) {
    $packs = business_packs();
    if (!isset($packs[$type])) $type = 'general';
    [, , , $cats, $items, $flags] = $packs[$type];
    $catId = [];
    foreach ($cats as $c) {
        $st = $pdo->prepare('SELECT id FROM categories WHERE name = ?'); $st->execute([$c]);
        $id = $st->fetchColumn();
        if (!$id) { $pdo->prepare('INSERT INTO categories (name) VALUES (?)')->execute([$c]); $id = $pdo->lastInsertId(); }
        $catId[$c] = (int)$id;
    }
    $added = 0;
    if ($withItems) {
        foreach ($items as [$name, $cat, $sell, $buy, $unit, $kind, $serial]) {
            $st = $pdo->prepare('SELECT id FROM items WHERE name = ?'); $st->execute([$name]);
            if ($st->fetchColumn()) continue;
            $pdo->prepare('INSERT INTO items (name, category_id, unit, selling_price, purchase_price, item_type, serial_tracked, is_active)
                           VALUES (?,?,?,?,?,?,?,1)')
                ->execute([$name, $catId[$cat] ?? null, $unit, $sell, $buy, $kind, $serial]);
            $added++;
        }
    }
    $set = $pdo->prepare('INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)');
    $set->execute(['business_type', $type]);
    foreach ($flags as $k => $v) $set->execute([$k, $v]);
    return ['categories' => count($cats), 'items' => $added];
}
