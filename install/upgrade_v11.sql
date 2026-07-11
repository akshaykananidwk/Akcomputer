-- Upgrade v10 -> v11: digital job-card signature, multi-site + DVR/NVR vault
ALTER TABLE tasks ADD COLUMN signature LONGTEXT DEFAULT NULL;
CREATE TABLE IF NOT EXISTS sites (
  id INT AUTO_INCREMENT PRIMARY KEY,
  party_id INT NOT NULL,
  name VARCHAR(150) NOT NULL,
  address VARCHAR(255) DEFAULT '',
  city VARCHAR(60) DEFAULT '',
  device_type VARCHAR(40) DEFAULT '',
  ip_address VARCHAR(60) DEFAULT '',
  port VARCHAR(10) DEFAULT '',
  dvr_username VARCHAR(80) DEFAULT '',
  dvr_password_enc VARCHAR(255) DEFAULT '',
  remote_app VARCHAR(80) DEFAULT '',
  remote_id VARCHAR(80) DEFAULT '',
  install_date DATE DEFAULT NULL,
  warranty_till DATE DEFAULT NULL,
  notes VARCHAR(255) DEFAULT '',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_site_party (party_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
