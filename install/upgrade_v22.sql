-- Upgrade v21 -> v22: API & integrations - Bearer-token REST API (also used
-- as the mobile app API), Razorpay webhook auto-reconciliation (the
-- existing payment link was fire-and-forget with no way for the app to
-- learn a customer had paid), outbound webhooks for third-party
-- integrations, and Tally export coverage for Payments/Expenses vouchers.

CREATE TABLE IF NOT EXISTS api_tokens (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  label VARCHAR(100) NOT NULL DEFAULT '',
  token_hash CHAR(64) NOT NULL,
  last_used_at DATETIME NULL,
  expires_at DATETIME NULL,
  revoked TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_apt_hash (token_hash),
  KEY idx_apt_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS webhooks (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  url VARCHAR(500) NOT NULL,
  secret_enc TEXT,
  events VARCHAR(255) NOT NULL DEFAULT '',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  last_status VARCHAR(30) DEFAULT '',
  last_triggered_at DATETIME NULL,
  created_by INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS webhook_deliveries (
  id INT AUTO_INCREMENT PRIMARY KEY,
  webhook_id INT NOT NULL,
  event VARCHAR(50) NOT NULL,
  status_code INT NOT NULL DEFAULT 0,
  ok TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_wd_webhook (webhook_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE sales ADD COLUMN razorpay_link_id VARCHAR(40) DEFAULT '';
