-- v102: online store customer accounts (separate from dealer B2B logins in
-- web_accounts). Adds only: guest checkout keeps working, and no existing
-- order, product or bill is changed.
CREATE TABLE IF NOT EXISTS store_customers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL DEFAULT '',
  mobile VARCHAR(15) NULL,
  email VARCHAR(190) NULL,
  password_hash VARCHAR(255) NULL,
  mobile_verified TINYINT(1) NOT NULL DEFAULT 0,
  email_verified TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_login DATETIME NULL,
  UNIQUE KEY uq_sc_mobile (mobile),
  UNIQUE KEY uq_sc_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS store_addresses (
  id INT AUTO_INCREMENT PRIMARY KEY,
  customer_id INT NOT NULL,
  label VARCHAR(30) NOT NULL DEFAULT 'Home',
  name VARCHAR(120) NOT NULL DEFAULT '',
  mobile VARCHAR(15) NOT NULL DEFAULT '',
  address VARCHAR(255) NOT NULL DEFAULT '',
  area VARCHAR(120) NOT NULL DEFAULT '',
  pincode VARCHAR(10) NOT NULL DEFAULT '',
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_sa_cust (customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS store_wishlist (
  customer_id INT NOT NULL,
  item_id INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (customer_id, item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
ALTER TABLE web_orders ADD COLUMN store_customer_id INT NULL;
ALTER TABLE web_orders ADD INDEX idx_wo_cust (store_customer_id);
