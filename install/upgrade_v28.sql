-- Cash & Bank movements + per-staff cash wallets:
-- one table records every internal money movement - cash<->bank transfers,
-- bank<->bank transfers, cash/bank balance adjustments (e.g. SMS charges),
-- and staff-to-staff cash handovers (OTP-confirmed via WhatsApp).
CREATE TABLE IF NOT EXISTS money_transfers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  txn_type VARCHAR(20) NOT NULL,      -- cash_to_bank | bank_to_cash | bank_to_bank | cash_adjust | bank_adjust | staff_transfer
  amount DECIMAL(12,2) NOT NULL,
  adjust_dir VARCHAR(6) NULL,         -- add | reduce (for *_adjust)
  from_user_id INT NULL,              -- whose cash wallet money leaves (or whose wallet an adjust applies to)
  to_user_id INT NULL,                -- whose cash wallet money enters
  from_bank_id INT NULL,
  to_bank_id INT NULL,
  txn_date DATE NOT NULL,
  notes VARCHAR(255) NULL,
  status VARCHAR(12) NOT NULL DEFAULT 'done',  -- staff_transfer starts 'pending' until the receiver's OTP is entered
  otp_hash VARCHAR(64) NULL,
  otp_expires DATETIME NULL,
  created_by INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_mt_type (txn_type, status),
  INDEX idx_mt_users (from_user_id, to_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Serial-aware manual stock adjustments need a terminal state for a unit
-- written off outside sale/return flows (damage, count fix).
ALTER TABLE item_serials MODIFY status ENUM('in_stock','with_staff','sold','claim','returned_supplier','replaced','adjusted_out') NOT NULL DEFAULT 'in_stock';
