-- Upgrade v12 -> v13: birthday/anniversary wishes, loyalty points,
-- post-job feedback, site visit calendar
ALTER TABLE parties ADD COLUMN dob DATE DEFAULT NULL AFTER city;
ALTER TABLE parties ADD COLUMN anniversary DATE DEFAULT NULL AFTER dob;
ALTER TABLE parties ADD COLUMN loyalty_points INT NOT NULL DEFAULT 0 AFTER opening_balance;

CREATE TABLE IF NOT EXISTS loyalty_ledger (
  id INT AUTO_INCREMENT PRIMARY KEY,
  party_id INT NOT NULL,
  points INT NOT NULL,
  reason VARCHAR(120) DEFAULT '',
  ref_type VARCHAR(30) DEFAULT '',
  ref_id INT DEFAULT NULL,
  created_by INT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_loyalty_party (party_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE sales ADD COLUMN loyalty_points_used INT NOT NULL DEFAULT 0 AFTER discount;
ALTER TABLE sales ADD COLUMN loyalty_discount DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER loyalty_points_used;

CREATE TABLE IF NOT EXISTS feedback (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ref_type ENUM('repair','task') NOT NULL,
  ref_id INT NOT NULL,
  token VARCHAR(40) NOT NULL,
  customer_name VARCHAR(120) DEFAULT '',
  mobile VARCHAR(15) DEFAULT '',
  rating TINYINT DEFAULT NULL,
  comment VARCHAR(500) DEFAULT '',
  submitted_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_feedback_token (token),
  KEY idx_feedback_ref (ref_type, ref_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE sites ADD COLUMN next_visit_date DATE DEFAULT NULL AFTER warranty_till;
