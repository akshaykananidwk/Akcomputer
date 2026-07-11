-- Upgrade v11 -> v12: batch/expiry tracking
CREATE TABLE IF NOT EXISTS item_batches (
  id INT AUTO_INCREMENT PRIMARY KEY,
  item_id INT NOT NULL,
  location_id INT NOT NULL,
  batch_no VARCHAR(60) DEFAULT '',
  expiry_date DATE DEFAULT NULL,
  qty DECIMAL(12,2) NOT NULL DEFAULT 0,
  notes VARCHAR(255) DEFAULT '',
  created_by INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_batch_item (item_id),
  KEY idx_batch_expiry (expiry_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
