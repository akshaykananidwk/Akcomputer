-- v52: real customer product reviews (for the storefront + Google Product
-- structured data - aggregateRating/review are emitted ONLY from these)
CREATE TABLE IF NOT EXISTS product_reviews (
  id INT AUTO_INCREMENT PRIMARY KEY,
  item_id INT NOT NULL,
  customer_name VARCHAR(120) NOT NULL DEFAULT '',
  mobile VARCHAR(15) NOT NULL DEFAULT '',
  rating TINYINT NOT NULL,
  comment VARCHAR(1000) NOT NULL DEFAULT '',
  is_verified TINYINT(1) NOT NULL DEFAULT 0,
  status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_pr_item (item_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
