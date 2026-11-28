-- v103: the words customers search a product with (written by Items ->
-- Product Descriptions & SEO, editable on the item). Used by the store's
-- search; adds only.
ALTER TABLE items ADD COLUMN search_keywords TEXT NULL;
-- which descriptions / search words the software wrote (so the owner's own
-- are never replaced) and the Undo copy of what was there before
CREATE TABLE IF NOT EXISTS item_content_auto (
  item_id INT NOT NULL PRIMARY KEY,
  hd CHAR(32) NOT NULL DEFAULT '',
  hk CHAR(32) NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS item_content_undo (
  item_id INT NOT NULL PRIMARY KEY,
  old_desc MEDIUMTEXT NULL,
  old_kw MEDIUMTEXT NULL,
  at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
