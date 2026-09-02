-- v61: the same bill-by-bill credit for SALES returns, and one column name
-- for both sides.
--
-- v60 gave purchase returns a credit that lands on real bills. A sales return
-- is the mirror image - goods come back from a customer, and if we are not
-- handing cash over, the credit belongs against the bills THEY owe us. It had
-- only half of that: it credited the one original invoice, and if that invoice
-- was already paid, or none was typed in at all, the credit quietly went
-- nowhere. The customer's other unpaid bills never saw it.
--
-- So this table is the sale-side twin of purchase_return_credits, and the
-- purchase-side column is renamed purchase_id -> bill_id so one function can
-- serve both. The rename is guarded on information_schema rather than run
-- blind, because the migration runner re-runs every file every time and an
-- unguarded CHANGE COLUMN would report a failure on the second pass.

CREATE TABLE IF NOT EXISTS sales_return_credits (
  id INT AUTO_INCREMENT PRIMARY KEY,
  return_id INT NOT NULL,
  bill_id INT NOT NULL,
  amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_src_return (return_id),
  INDEX idx_src_bill (bill_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @prc_old := (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'purchase_return_credits' AND COLUMN_NAME = 'purchase_id');
SET @prc_sql := IF(@prc_old > 0, 'ALTER TABLE purchase_return_credits CHANGE COLUMN purchase_id bill_id INT NOT NULL', 'DO 0');
PREPARE prc_stmt FROM @prc_sql;
EXECUTE prc_stmt;
DEALLOCATE PREPARE prc_stmt;
