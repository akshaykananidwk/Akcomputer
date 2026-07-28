-- Internet connection supply: each customer connection with its plan and
-- expiry date; the daily cron WhatsApps the shop (and optionally the
-- customer) when a connection is about to expire / expires.
CREATE TABLE IF NOT EXISTS net_connections (
  id INT AUTO_INCREMENT PRIMARY KEY,
  customer_name VARCHAR(120) NOT NULL,
  mobile VARCHAR(20) NOT NULL,
  address VARCHAR(200) NULL,
  plan_name VARCHAR(120) NULL,
  price DECIMAL(12,2) NOT NULL DEFAULT 0,
  start_date DATE NOT NULL,
  months INT NOT NULL DEFAULT 1,
  expiry_date DATE NOT NULL,
  status VARCHAR(12) NOT NULL DEFAULT 'active',
  notify_customer TINYINT(1) NOT NULL DEFAULT 1,
  last_alert_date DATE NULL,
  notes VARCHAR(300) NULL,
  created_by INT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_expiry (expiry_date, status)
);
