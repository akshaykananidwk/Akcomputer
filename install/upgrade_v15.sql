-- Upgrade v14 -> v15: configurable per-item custom fields + a description
-- line on each sale item (Vyapar-style "Add Items to Sale" screen).
CREATE TABLE IF NOT EXISTS item_custom_fields (
  id INT AUTO_INCREMENT PRIMARY KEY,
  label VARCHAR(60) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE sale_items ADD COLUMN description VARCHAR(255) NOT NULL DEFAULT '' AFTER serials;
ALTER TABLE sale_items ADD COLUMN custom_data TEXT NULL AFTER description;
