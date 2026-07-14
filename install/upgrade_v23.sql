-- Upgrade v22 -> v23: UX polish - dark mode, custom dashboard widgets,
-- keyboard shortcuts, global search, saved filters. Two new per-user tables
-- (nothing like this existed before - the only prior per-user state was
-- users.permissions, unrelated): a generic key/value preferences store
-- (theme choice, dashboard widget order/visibility) and a saved-filters
-- list (a user can have several named filters per page).

CREATE TABLE IF NOT EXISTS user_preferences (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  pref_key VARCHAR(50) NOT NULL,
  pref_value TEXT,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_up_user_key (user_id, pref_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS saved_filters (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  page VARCHAR(50) NOT NULL,
  name VARCHAR(100) NOT NULL,
  query_string VARCHAR(500) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_sf_user_page (user_id, page)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
