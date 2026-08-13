-- v55: customer intelligence + smart collection.
--
-- Only ONE new table. Everything else Phase 4 shows - segments, lifetime
-- value, purchase frequency, at-risk, collection priority, credit suggestion
-- - is DERIVED from sales/payments/parties at read time, so there is no second
-- copy of the truth to drift out of step.
--
-- What genuinely cannot be derived is what a human did about a debt: that the
-- customer was rung up, what they promised, whether they kept it, and when we
-- last bothered them. That is this table.
--
-- Idempotent: the runner treats "table exists" (1050) and "duplicate column"
-- (1060) / "duplicate key" (1061) as already-applied.

CREATE TABLE IF NOT EXISTS collection_events (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  party_id      INT NOT NULL,
  -- reminder  : a message went out
  -- call      : somebody phoned
  -- note      : free text the staff wanted remembered
  -- promise   : "I will pay X on DATE"
  -- kept      : that promise was honoured
  -- broken    : that promise passed unpaid
  -- snooze    : leave this customer alone until DATE
  event_type    ENUM('reminder','call','note','promise','kept','broken','snooze') NOT NULL,
  amount        DECIMAL(12,2) NOT NULL DEFAULT 0,   -- promised amount
  due_date      DATE NULL,                          -- promised-for / snooze-until
  channel       VARCHAR(20) NOT NULL DEFAULT '',    -- whatsapp / phone / sms
  note          VARCHAR(255) NOT NULL DEFAULT '',
  ref_id        INT NULL,                           -- the promise a kept/broken row settles
  status        ENUM('open','done','cancelled') NOT NULL DEFAULT 'open',
  created_by    INT NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ce_party (party_id, created_at),
  INDEX idx_ce_type (event_type, status, due_date),
  INDEX idx_ce_staff (created_by, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- A customer who has asked not to be chased is never chased again, by cron or
-- by a bulk send. Separate from the WhatsApp language preference on purpose:
-- this is about consent, not about which language to write in.
ALTER TABLE parties ADD COLUMN collection_opt_out TINYINT(1) NOT NULL DEFAULT 0;

-- The collection queue orders by how overdue a bill is, per party.
ALTER TABLE sales ADD INDEX idx_sale_party_status (party_id, status, due_date);

-- "when did we last message this customer" is read for every row of the queue.
ALTER TABLE sales ADD INDEX idx_sale_last_reminder (last_reminder);
