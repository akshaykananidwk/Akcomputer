-- v60: a purchase-return credit remembers WHICH bills it settled.
--
-- When goods go back to a supplier and the refund mode is "adjust", the money
-- is not handed over - it stays as a credit against that supplier. The party
-- ledger has always counted it (party_balance_expr adds purchase_returns), so
-- the supplier's overall balance was right. What was NOT right was the bills:
-- every purchase bill still showed its full amount outstanding, and the credit
-- floated somewhere above them with nothing to point at.
--
-- Now the credit settles the OLDEST due purchase bill first - the same
-- oldest-first rule money_settle_oldest_first() applies to every payment - and
-- this table records exactly how much went to which bill.
--
-- It is deliberately NOT payment_allocations. That table hangs off a payments
-- row, and an adjusted return posts no payment: writing one would count the
-- credit a second time in the ledger and halve the supplier's balance. So the
-- link lives here, with its own reversal path.
--
-- The reason for storing it at all is deleting. Undoing a return has to put
-- back exactly what it took off each bill - not "recalculate", which would
-- guess wrong the moment a later payment has touched the same bill.

CREATE TABLE IF NOT EXISTS purchase_return_credits (
  id INT AUTO_INCREMENT PRIMARY KEY,
  return_id INT NOT NULL,
  bill_id INT NOT NULL,
  amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_prc_return (return_id),
  INDEX idx_prc_bill (bill_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
