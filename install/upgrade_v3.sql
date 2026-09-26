-- =====================================================
-- Upgrade v2 -> v3
-- Payment linking, replacement serials, service centers,
-- website orders, message templates
-- =====================================================

-- service centers as a party type
ALTER TABLE parties MODIFY type ENUM('customer','supplier','both','service_center') NOT NULL DEFAULT 'customer';

-- replaced serial status (warranty replacement chain)
--
-- 'adjusted_out' belongs in this list even though v28 is what introduced it.
-- Every migration is RE-RUN on every Migrate, in order, so this line ran
-- again long after v28 had widened the list - and narrowing an ENUM does not
-- fail, it TRUNCATES: every serial that had been adjusted out was set to the
-- empty string, losing its status, and v28 then widened the column again over
-- data that was already gone. On a server that reports it (strict mode) the
-- statement failed instead, so Migrate said "1 failed" for ever and nothing
-- else in v3 got applied either. The list must hold every status the
-- application writes - see the test that checks exactly that.
ALTER TABLE item_serials MODIFY status ENUM('in_stock','with_staff','sold','claim','returned_supplier','replaced','adjusted_out') NOT NULL DEFAULT 'in_stock';

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
