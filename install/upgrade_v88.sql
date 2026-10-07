-- v88: what different kinds of shops need beyond a computer shop.
--   salon      - who did each service on a bill; appointments; prepaid packages
--   medical    - the prescription photo kept with the bill
--   wholesale  - pieces in a box, and a lower price from a quantity up (slabs)
--   garment    - sizes and colours as items of one family
-- Every column is optional: a shop that is none of these never sees them.
ALTER TABLE sale_items ADD COLUMN staff_id INT NULL DEFAULT NULL;
ALTER TABLE sales ADD COLUMN prescription VARCHAR(200) NULL DEFAULT NULL;
ALTER TABLE items ADD COLUMN box_qty DECIMAL(10,2) NOT NULL DEFAULT 0;
ALTER TABLE items ADD COLUMN parent_id INT NULL DEFAULT NULL;
ALTER TABLE items ADD COLUMN variant VARCHAR(60) NULL DEFAULT NULL;
ALTER TABLE items ADD KEY idx_items_parent (parent_id);

CREATE TABLE IF NOT EXISTS item_slabs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    item_id INT NOT NULL,
    min_qty DECIMAL(10,2) NOT NULL,
    price DECIMAL(12,2) NOT NULL,
    UNIQUE KEY uq_slab (item_id, min_qty)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS appointments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    appt_date DATE NOT NULL,
    appt_time TIME NOT NULL,
    duration_min INT NOT NULL DEFAULT 30,
    party_id INT NULL,
    customer_name VARCHAR(120) NOT NULL DEFAULT '',
    customer_mobile VARCHAR(20) NOT NULL DEFAULT '',
    item_id INT NULL,                  -- the service booked
    staff_id INT NULL,                 -- who will do it
    status VARCHAR(20) NOT NULL DEFAULT 'booked',   -- booked / done / no_show / cancelled
    sale_id INT NULL,                  -- the bill it became
    notes VARCHAR(255) NOT NULL DEFAULT '',
    created_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_appt_day (appt_date, appt_time),
    KEY idx_appt_staff (staff_id, appt_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS customer_packages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    party_id INT NOT NULL,
    name VARCHAR(120) NOT NULL,
    item_id INT NULL,                  -- the service it covers
    sessions_total INT NOT NULL,
    sessions_used INT NOT NULL DEFAULT 0,
    price DECIMAL(12,2) NOT NULL DEFAULT 0,
    sale_id INT NULL,                  -- the bill that sold it
    valid_till DATE NULL,
    created_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_pkg_party (party_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS package_uses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    package_id INT NOT NULL,
    used_on DATE NOT NULL,
    staff_id INT NULL,
    note VARCHAR(200) NOT NULL DEFAULT '',
    created_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_use_pkg (package_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
