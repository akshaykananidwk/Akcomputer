-- v45: two features.
-- (1) Per-line stock location on sale items: a bill can mix godown items
--     and shop items - each line deducts from its own place, one bill total.
--     NULL = the bill's own location (all existing rows behave unchanged).
ALTER TABLE sale_items ADD COLUMN location_id INT NULL;

-- (2) Bill-edit approval queue: staff edit freely for 24h after a bill is
--     made; after that the submitted edit is parked here and applied only
--     when the admin approves (the form data is replayed exactly).
CREATE TABLE IF NOT EXISTS edit_requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  doc_type VARCHAR(10) NOT NULL,
  doc_id INT NOT NULL,
  payload MEDIUMTEXT NOT NULL,
  requested_by INT NOT NULL,
  status VARCHAR(10) NOT NULL DEFAULT 'pending',
  decided_by INT NULL,
  decided_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_doc (doc_type, doc_id),
  KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
