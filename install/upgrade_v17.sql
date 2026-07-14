-- Upgrade v16 -> v17: Accounting foundation - a real Chart of Accounts,
-- double-entry Journal/Adjustment entries, bank reconciliation flags, and
-- Period Lock support. This is layered ON TOP of the existing sales/
-- purchases/payments/expenses tables (which keep working exactly as
-- before) rather than replacing them - General Ledger, Trial Balance,
-- Balance Sheet and Profit & Loss are computed by combining live totals
-- from those tables with whatever's posted here for manual adjustments.
CREATE TABLE IF NOT EXISTS chart_of_accounts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(20) NOT NULL,
  name VARCHAR(100) NOT NULL,
  type ENUM('asset','liability','equity','income','expense') NOT NULL,
  is_system TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  UNIQUE KEY uk_coa_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS journal_entries (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ref_no VARCHAR(30) NOT NULL,
  entry_date DATE NOT NULL,
  narration VARCHAR(255) DEFAULT '',
  source VARCHAR(20) NOT NULL DEFAULT 'manual',
  created_by INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS journal_lines (
  id INT AUTO_INCREMENT PRIMARY KEY,
  entry_id INT NOT NULL,
  account_id INT NOT NULL,
  debit DECIMAL(14,2) NOT NULL DEFAULT 0,
  credit DECIMAL(14,2) NOT NULL DEFAULT 0,
  notes VARCHAR(255) DEFAULT '',
  KEY idx_jl_entry (entry_id),
  KEY idx_jl_account (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE payments ADD COLUMN is_reconciled TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE payments ADD COLUMN reconciled_date DATE NULL;
ALTER TABLE expenses ADD COLUMN is_reconciled TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE expenses ADD COLUMN reconciled_date DATE NULL;

INSERT IGNORE INTO chart_of_accounts (code, name, type, is_system, sort_order) VALUES
('1000', 'Cash in Hand', 'asset', 1, 10),
('1100', 'Bank Accounts', 'asset', 1, 20),
('1200', 'Accounts Receivable', 'asset', 1, 30),
('1300', 'Inventory / Stock', 'asset', 1, 40),
('2000', 'Accounts Payable', 'liability', 1, 50),
('3000', 'Owner\'s Capital', 'equity', 1, 60),
('3900', 'Retained Earnings', 'equity', 1, 70),
('4000', 'Sales Revenue', 'income', 1, 80),
('4100', 'Sales Returns', 'income', 1, 85),
('5000', 'Cost of Goods Sold', 'expense', 1, 90);
