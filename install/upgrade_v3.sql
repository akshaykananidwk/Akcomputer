-- =====================================================
-- Upgrade v2 -> v3
-- Payment linking, replacement serials, service centers,
-- website orders, message templates
-- =====================================================

-- service centers as a party type
ALTER TABLE parties MODIFY type ENUM('customer','supplier','both','service_center') NOT NULL DEFAULT 'customer';

-- replaced serial status (warranty replacement chain)
ALTER TABLE item_serials MODIFY status ENUM('in_stock','with_staff','sold','claim','returned_supplier','replaced') NOT NULL DEFAULT 'in_stock';

-- website orders (add to cart -> order form)
CREATE TABLE IF NOT EXISTS web_orders (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_no VARCHAR(30) DEFAULT '',
  customer_name VARCHAR(120) NOT NULL,
  mobile VARCHAR(15) NOT NULL,
  address VARCHAR(255) DEFAULT '',
  notes VARCHAR(255) DEFAULT '',
  items_json TEXT,
  total DECIMAL(12,2) NOT NULL DEFAULT 0,
  status ENUM('new','contacted','completed','cancelled') NOT NULL DEFAULT 'new',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
