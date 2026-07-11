-- Upgrade v6 -> v7: discount %, bank accounts, payment methods, QR/bank on invoice
ALTER TABLE sales ADD COLUMN discount_type ENUM('amount','percent') NOT NULL DEFAULT 'amount';
ALTER TABLE sales ADD COLUMN discount_pct DECIMAL(6,2) NOT NULL DEFAULT 0;
ALTER TABLE sales ADD COLUMN bank_account_id INT DEFAULT NULL;
ALTER TABLE purchases ADD COLUMN discount_type ENUM('amount','percent') NOT NULL DEFAULT 'amount';
ALTER TABLE purchases ADD COLUMN discount_pct DECIMAL(6,2) NOT NULL DEFAULT 0;
ALTER TABLE purchases ADD COLUMN bank_account_id INT DEFAULT NULL;
ALTER TABLE payments MODIFY party_id INT NULL;
ALTER TABLE payments ADD COLUMN bank_account_id INT DEFAULT NULL;
ALTER TABLE expenses ADD COLUMN bank_account_id INT DEFAULT NULL;
ALTER TABLE expenses ADD COLUMN payment_method_id INT DEFAULT NULL;
ALTER TABLE sales ADD COLUMN payment_method_id INT DEFAULT NULL;
ALTER TABLE purchases ADD COLUMN payment_method_id INT DEFAULT NULL;
ALTER TABLE payments ADD COLUMN payment_method_id INT DEFAULT NULL;

CREATE TABLE IF NOT EXISTS bank_accounts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  account_name VARCHAR(120) NOT NULL,
  bank_name VARCHAR(120) NOT NULL,
  account_number VARCHAR(40) DEFAULT '',
  ifsc VARCHAR(20) DEFAULT '',
  branch VARCHAR(120) DEFAULT '',
  upi_id VARCHAR(80) DEFAULT '',
  opening_balance DECIMAL(12,2) NOT NULL DEFAULT 0,
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS payment_methods (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(30) NOT NULL,
  name VARCHAR(60) NOT NULL,
  type ENUM('cash','bank','other') NOT NULL DEFAULT 'other',
  bank_account_id INT DEFAULT NULL,
  is_system TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  UNIQUE KEY uk_pm_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO payment_methods (code, name, type, is_system, sort_order) VALUES
  ('cash', 'Cash', 'cash', 1, 1),
  ('upi', 'UPI', 'other', 1, 2),
  ('card', 'Card', 'other', 1, 3),
  ('bank', 'Bank Transfer', 'bank', 1, 4),
  ('cheque', 'Cheque', 'other', 1, 5),
  ('credit', 'Credit / Udhar', 'other', 1, 6)
ON DUPLICATE KEY UPDATE code = code;
