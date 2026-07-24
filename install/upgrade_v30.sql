-- Referral program: anyone becomes a partner, shares their link, and earns a
-- commission automatically when an order placed through their link completes.
CREATE TABLE IF NOT EXISTS referrers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  mobile VARCHAR(20) NOT NULL UNIQUE,
  code VARCHAR(16) NOT NULL UNIQUE,      -- goes in the share link (?ref=CODE)
  token VARCHAR(48) NOT NULL UNIQUE,     -- private dashboard access
  commission_pct DECIMAL(5,2) NOT NULL DEFAULT 2.00,
  clicks INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS referral_earnings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  referrer_id INT NOT NULL,
  web_order_id INT NULL,
  order_no VARCHAR(30) NULL,
  order_total DECIMAL(12,2) NOT NULL,
  commission DECIMAL(12,2) NOT NULL,
  status VARCHAR(12) NOT NULL DEFAULT 'pending',  -- pending -> approved (order completed) -> paid; or cancelled
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_re_ref (referrer_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE web_orders ADD COLUMN ref_code VARCHAR(16) NULL;
