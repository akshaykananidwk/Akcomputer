-- v68: the counter work - ten things a shop does at the billing table.
--
-- doc_counters
--   Bill numbers ran off the sale's own row id: INV-26-00252 was simply the
--   252nd row in the table, for ever, so the number never restarted and the
--   year in it came from the day the bill was SAVED rather than the year it
--   belongs to. Numbering now runs per firm, per financial year, from a
--   counter that is locked while it is read (SELECT ... FOR UPDATE), because
--   two people billing at the same second must not be handed the same number.
--   doc_type is there so estimates and challans can use the same counter
--   later without a second table.
--
-- parked_bills
--   A customer walks off mid-bill and the next one is already waiting. The
--   half-made bill is put aside as it stands - items, prices, party, the lot -
--   and picked up again later. It is NOT a sale: no number, no stock, no
--   ledger. Just the form, kept.
--
-- trade_ins
--   The old part comes back across the counter and its value comes off the
--   bill. The money side goes through the bill's existing adjustment, so
--   every total in the software keeps adding up exactly as before; this table
--   is what came in, so it can reach stock and be traced later.
--
-- item_kit_parts
--   "4 કેમેરાનું સેટ" is sold as one line but leaves the shelf as a camera, a
--   DVR, a reel of wire and a supply. The kit itself is never stocked - the
--   parts are - so selling one takes the parts down.
--
-- sales.delivery_address / signature
--   The bill goes to the office and the goods go to the site; and the person
--   taking delivery signs for them on the spot.

CREATE TABLE IF NOT EXISTS doc_counters (
  id INT AUTO_INCREMENT PRIMARY KEY,
  company_id INT NOT NULL,
  doc_type VARCHAR(20) NOT NULL DEFAULT 'sale',
  fy VARCHAR(10) NOT NULL,
  seq INT NOT NULL DEFAULT 0,
  UNIQUE KEY uk_doc_counter (company_id, doc_type, fy)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS parked_bills (
  id INT AUTO_INCREMENT PRIMARY KEY,
  label VARCHAR(120) NULL,
  customer_name VARCHAR(120) NULL,
  amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  items INT NOT NULL DEFAULT 0,
  payload MEDIUMTEXT NOT NULL,
  location_id INT NULL,
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_park_user (created_by),
  INDEX idx_park_date (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS trade_ins (
  id INT AUTO_INCREMENT PRIMARY KEY,
  sale_id INT NOT NULL,
  item_id INT NULL,
  descr VARCHAR(160) NOT NULL,
  serial_no VARCHAR(100) NULL,
  qty DECIMAL(12,2) NOT NULL DEFAULT 1,
  value DECIMAL(12,2) NOT NULL DEFAULT 0,
  location_id INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_tradein_sale (sale_id),
  INDEX idx_tradein_item (item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS item_kit_parts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  kit_item_id INT NOT NULL,
  part_item_id INT NOT NULL,
  qty DECIMAL(12,3) NOT NULL DEFAULT 1,
  UNIQUE KEY uk_kit_part (kit_item_id, part_item_id),
  INDEX idx_kit (kit_item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE sales ADD COLUMN delivery_address VARCHAR(255) NULL;
ALTER TABLE sales ADD COLUMN signature VARCHAR(120) NULL;
ALTER TABLE sales ADD COLUMN trade_in DECIMAL(12,2) NOT NULL DEFAULT 0;

-- Seed the counters from the bills already written, so the series does not
-- restart in the MIDDLE of a financial year. A GST invoice series has to run
-- consecutively within its year, and this shop is already 250-odd bills into
-- 26-27: starting again at 1 today would put two bills in the same year with
-- the same position in the series. The next April is where it restarts at 1.
--
-- The financial year of a bill is the year of (its date minus 3 months):
-- 1 April 2026 -> 2026, 31 March 2027 -> 2026.
--
-- GREATEST() keeps this safe to re-run: a counter that has already moved past
-- the bill count is never pulled back down.
INSERT INTO doc_counters (company_id, doc_type, fy, seq)
SELECT s.company_id, 'sale',
       CONCAT(LPAD(RIGHT(YEAR(DATE_SUB(s.sale_date, INTERVAL 3 MONTH)), 2), 2, '0'), '-',
              LPAD(RIGHT(YEAR(DATE_SUB(s.sale_date, INTERVAL 3 MONTH)) + 1, 2), 2, '0')),
       COUNT(*)
  FROM sales s
 GROUP BY s.company_id,
       CONCAT(LPAD(RIGHT(YEAR(DATE_SUB(s.sale_date, INTERVAL 3 MONTH)), 2), 2, '0'), '-',
              LPAD(RIGHT(YEAR(DATE_SUB(s.sale_date, INTERVAL 3 MONTH)) + 1, 2), 2, '0'))
ON DUPLICATE KEY UPDATE seq = GREATEST(doc_counters.seq, VALUES(seq));
