-- =====================================================
-- Upgrade v1 -> v2 (Vyapar-style features)
-- Run this ONLY on an existing installed database.
-- Fresh installs get everything from schema.sql automatically.
-- =====================================================

-- bill-wise profit: capture cost price at sale time
ALTER TABLE sale_items ADD COLUMN cost_price DECIMAL(12,2) NOT NULL DEFAULT 0;

-- company logo on invoices
ALTER TABLE companies ADD COLUMN logo VARCHAR(255) DEFAULT '';

-- ---------- Expenses ----------
CREATE TABLE IF NOT EXISTS expenses (
  id INT AUTO_INCREMENT PRIMARY KEY,
  exp_date DATE NOT NULL,
  category VARCHAR(80) NOT NULL DEFAULT 'General',
  amount DECIMAL(12,2) NOT NULL,
  mode VARCHAR(20) NOT NULL DEFAULT 'cash',
  notes VARCHAR(255) DEFAULT '',
  location_id INT NOT NULL,
  created_by INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_exp_date (exp_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- Delivery challans ----------
CREATE TABLE IF NOT EXISTS challans (
  id INT AUTO_INCREMENT PRIMARY KEY,
  challan_no VARCHAR(30) DEFAULT '',
  company_id INT NOT NULL DEFAULT 1,
  party_id INT DEFAULT NULL,
  customer_name VARCHAR(120) DEFAULT '',
  customer_mobile VARCHAR(15) DEFAULT '',
  address VARCHAR(255) DEFAULT '',
  location_id INT NOT NULL,
  challan_date DATE NOT NULL,
  status ENUM('open','converted','cancelled') NOT NULL DEFAULT 'open',
  converted_sale_id INT DEFAULT NULL,
  notes VARCHAR(255) DEFAULT '',
  created_by INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS challan_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  challan_id INT NOT NULL,
  item_id INT NOT NULL,
  qty DECIMAL(12,2) NOT NULL,
  price DECIMAL(12,2) NOT NULL DEFAULT 0,
  total DECIMAL(12,2) NOT NULL DEFAULT 0,
  KEY idx_ci (challan_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
