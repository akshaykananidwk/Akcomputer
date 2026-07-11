-- Upgrade v9 -> v10: AMC / recurring billing contracts
CREATE TABLE IF NOT EXISTS amc_contracts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  party_id INT NOT NULL,
  item_id INT NOT NULL,
  company_id INT NOT NULL DEFAULT 1,
  location_id INT NOT NULL,
  title VARCHAR(150) DEFAULT '',
  amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  billing_cycle ENUM('monthly','quarterly','half_yearly','yearly') NOT NULL DEFAULT 'yearly',
  start_date DATE NOT NULL,
  next_bill_date DATE NOT NULL,
  end_date DATE DEFAULT NULL,
  status ENUM('active','paused','cancelled') NOT NULL DEFAULT 'active',
  notes VARCHAR(255) DEFAULT '',
  created_by INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_amc_party (party_id),
  KEY idx_amc_next (next_bill_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
ALTER TABLE sales ADD COLUMN amc_contract_id INT DEFAULT NULL;
