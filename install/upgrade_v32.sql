-- Per-item (line) discount on sale bills: value + Rs/% type as typed, and
-- the computed rupee discount so reports can total "discount given" without
-- re-deriving it. sale_items.total is stored NET of this line discount.
ALTER TABLE sale_items ADD COLUMN line_disc_type VARCHAR(10) NOT NULL DEFAULT 'amount';
ALTER TABLE sale_items ADD COLUMN line_disc_val DECIMAL(12,2) NOT NULL DEFAULT 0;
ALTER TABLE sale_items ADD COLUMN line_disc DECIMAL(12,2) NOT NULL DEFAULT 0;

-- Dealer / electrician logins for the public website: each account carries
-- its own discount percentage; the catalog shows that account its discounted
-- price automatically after login. Optional link to a party for the ledger.
CREATE TABLE IF NOT EXISTS web_accounts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  mobile VARCHAR(20) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  discount_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
  party_id INT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  last_login DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

ALTER TABLE web_orders ADD COLUMN web_account_id INT NULL;
