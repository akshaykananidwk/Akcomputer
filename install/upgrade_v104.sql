-- v104: Items -> Clear Product Names keeps the names (and brand spellings)
-- as they were before the last Apply, so Undo can put them back. Adds only.
CREATE TABLE IF NOT EXISTS item_name_undo (
  item_id INT NOT NULL PRIMARY KEY,
  old_name VARCHAR(255) NOT NULL,
  old_brand VARCHAR(120) NULL,
  at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
