-- v69: money in, money owed, and who is allowed to spend it.
--
-- cheques
--   A cheque is not a payment the day it is handed over - it is a promise on
--   paper. Until now the only trace of one was a payments row with mode
--   'cheque', so nothing in the software knew which cheque, drawn on which
--   bank, dated for when, or whether it had been deposited at all. The
--   register keeps the cheque itself: its number, its date, where it is in
--   its life (in hand -> deposited -> cleared, or bounced), and the payment
--   row it belongs to so the two can never disagree.
--
-- installments
--   A big bill paid in parts. This is a SCHEDULE, not money: how much is due
--   on which date. What has actually been paid is read from the bill itself
--   and spread across the instalments oldest-first, so there is one figure
--   for "how much has this bill been paid" and no chance of counting the
--   same rupee in two places.
--
-- expense_requests
--   A large expense waits for the owner instead of going straight into the
--   books. It is held HERE, not as a pending row in expenses, because forty
--   different queries add expenses up - one of them forgetting to skip
--   pending rows would quietly change the profit. Nothing exists in the
--   books until it is approved, and approving writes an ordinary expense.
--
-- parties.credit_limit / interest_pct
--   How much this party may owe before the billing screen warns, and the
--   yearly rate their late payments are charged at (0 = the shop's default).

CREATE TABLE IF NOT EXISTS cheques (
  id INT AUTO_INCREMENT PRIMARY KEY,
  direction ENUM('in','out') NOT NULL DEFAULT 'in',
  party_id INT NULL,
  payment_id INT NULL,
  cheque_no VARCHAR(40) NOT NULL,
  bank_name VARCHAR(120) NULL,
  cheque_date DATE NOT NULL,
  amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  status ENUM('in_hand','deposited','cleared','bounced','cancelled') NOT NULL DEFAULT 'in_hand',
  deposit_date DATE NULL,
  clear_date DATE NULL,
  bank_account_id INT NULL,
  notes VARCHAR(255) NULL,
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_chq_status (status, cheque_date),
  INDEX idx_chq_party (party_id),
  INDEX idx_chq_pay (payment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS installments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  sale_id INT NOT NULL,
  party_id INT NULL,
  seq INT NOT NULL DEFAULT 1,
  due_date DATE NOT NULL,
  amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  last_reminder DATE NULL,
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_inst (sale_id, seq),
  INDEX idx_inst_due (due_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS expense_requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  category VARCHAR(80) NULL,
  exp_date DATE NOT NULL,
  payload MEDIUMTEXT NOT NULL,
  reason VARCHAR(255) NULL,
  status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  requested_by INT NULL,
  decided_by INT NULL,
  decided_at DATETIME NULL,
  decide_note VARCHAR(255) NULL,
  expense_id INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_expreq_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE parties ADD COLUMN credit_limit DECIMAL(12,2) NOT NULL DEFAULT 0;
ALTER TABLE parties ADD COLUMN interest_pct DECIMAL(6,2) NOT NULL DEFAULT 0;
