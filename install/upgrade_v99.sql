-- v99: merging a party made twice (same customer saved as "Tanveer",
-- "Tanveer Rupen bandar", ...). Each merge keeps the exact list of rows it
-- moved and the balances it changed, so it can be undone. Adds only.
CREATE TABLE IF NOT EXISTS party_merges (
  id INT AUTO_INCREMENT PRIMARY KEY,
  from_party_id INT NOT NULL,
  to_party_id INT NOT NULL,
  moved MEDIUMTEXT,
  before_from TEXT,
  before_to TEXT,
  created_by INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  undone_at DATETIME NULL,
  KEY idx_pm_from (from_party_id),
  KEY idx_pm_to (to_party_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
