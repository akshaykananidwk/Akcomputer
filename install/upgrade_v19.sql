-- Upgrade v18 -> v19: Inventory operations - stock audit/cycle counting,
-- FIFO/weighted-average cost layers (additive, opt-in via settings -
-- existing "current purchase_price" valuation keeps working untouched),
-- bin/rack sub-locations, stock reservations. Multi-warehouse transfers
-- already exist via handovers.type='transfer' - not duplicated here.
-- Reorder suggestions extend the existing Low Stock report in place.

CREATE TABLE IF NOT EXISTS stock_cost_layers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  item_id INT NOT NULL,
  location_id INT NOT NULL,
  qty_remaining DECIMAL(12,2) NOT NULL,
  unit_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
  ref_type VARCHAR(30) NOT NULL DEFAULT '',
  ref_id INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_scl_item_loc (item_id, location_id),
  KEY idx_scl_ref (ref_type, ref_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS stock_counts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  count_no VARCHAR(30) DEFAULT '',
  location_id INT NOT NULL,
  status ENUM('open','completed','cancelled') NOT NULL DEFAULT 'open',
  notes VARCHAR(255) DEFAULT '',
  created_by INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_by INT NULL,
  completed_at DATETIME NULL,
  KEY idx_sc_location (location_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS stock_count_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  count_id INT NOT NULL,
  item_id INT NOT NULL,
  system_qty DECIMAL(12,2) NOT NULL DEFAULT 0,
  counted_qty DECIMAL(12,2) NULL,
  notes VARCHAR(255) DEFAULT '',
  UNIQUE KEY uk_sci (count_id, item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS location_bins (
  id INT AUTO_INCREMENT PRIMARY KEY,
  location_id INT NOT NULL,
  code VARCHAR(30) NOT NULL,
  name VARCHAR(100) DEFAULT '',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uk_bin_code (location_id, code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS stock_bins (
  id INT AUTO_INCREMENT PRIMARY KEY,
  item_id INT NOT NULL,
  location_id INT NOT NULL,
  bin_id INT NOT NULL,
  qty DECIMAL(12,2) NOT NULL DEFAULT 0,
  UNIQUE KEY uk_stock_bin (item_id, bin_id),
  KEY idx_sb_location (location_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS stock_reservations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  item_id INT NOT NULL,
  location_id INT NOT NULL,
  qty DECIMAL(12,2) NOT NULL,
  party_id INT NULL,
  ref_type VARCHAR(20) DEFAULT '',
  ref_id INT NULL,
  status ENUM('active','released','fulfilled') NOT NULL DEFAULT 'active',
  notes VARCHAR(255) DEFAULT '',
  created_by INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  released_at DATETIME NULL,
  KEY idx_sr_item_loc (item_id, location_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO settings (name, value) VALUES ('costing_method', 'current');
INSERT IGNORE INTO settings (name, value) VALUES ('dead_stock_days', '90');
