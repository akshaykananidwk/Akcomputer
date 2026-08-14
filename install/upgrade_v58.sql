-- v58: campaigns.
--
-- Three things this schema is shaped by, all of them about consent rather
-- than marketing:
--
-- 1. marketing_opt_out is a SEPARATE column from collection_opt_out. They are
--    different consents. A customer who is happy to be reminded about a bill
--    they owe has not thereby agreed to be advertised to, and one who asks to
--    stop the offers has not waived their right to be told a payment is due.
--    Folding the two into one flag would silently break one promise or the
--    other, so there are two flags and each is asked for separately.
--
-- 2. campaign_targets stores ONE ROW PER RECIPIENT, decided before anything is
--    sent. That is what makes a send resumable after a crash, auditable
--    afterwards ("who did we message, when, and what happened"), and honest
--    about refusals: a person left out is stored with the REASON, not dropped.
--
-- 3. is_holdout exists so the shop can find out whether a campaign did
--    anything. A share of the audience is deliberately NOT messaged, and the
--    two groups are compared afterwards. Without it, "we sent 300 messages and
--    made 40 sales" is a number with nothing to compare it to - those 40 might
--    have walked in anyway. A holdout is the only honest way this shop can
--    measure a campaign, so it is part of the table, not an afterthought.

CREATE TABLE IF NOT EXISTS campaigns (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  audience VARCHAR(40) NOT NULL,
  audience_params VARCHAR(500) NOT NULL DEFAULT '',
  message TEXT NOT NULL,
  holdout_pct TINYINT NOT NULL DEFAULT 10,
  measure_days SMALLINT NOT NULL DEFAULT 30,
  status ENUM('draft','ready','sending','done','cancelled') NOT NULL DEFAULT 'draft',
  notes VARCHAR(255) NOT NULL DEFAULT '',
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  started_at DATETIME NULL,
  finished_at DATETIME NULL,
  INDEX idx_camp_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS campaign_targets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  campaign_id INT NOT NULL,
  party_id INT NOT NULL,
  name VARCHAR(120) NOT NULL DEFAULT '',
  mobile VARCHAR(15) NOT NULL DEFAULT '',
  is_holdout TINYINT NOT NULL DEFAULT 0,
  status ENUM('queued','sent','failed','skipped') NOT NULL DEFAULT 'queued',
  reason VARCHAR(120) NOT NULL DEFAULT '',
  sent_at DATETIME NULL,
  -- the customer's ledger position when the campaign was prepared, so the
  -- result can be measured against what they bought AFTER it
  baseline_spend DECIMAL(12,2) NOT NULL DEFAULT 0,
  UNIQUE KEY uk_camp_party (campaign_id, party_id),
  INDEX idx_ct_status (campaign_id, status),
  INDEX idx_ct_party (party_id, sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Consent to be advertised to. Separate from collection_opt_out on purpose;
-- see the note at the top.
ALTER TABLE parties ADD COLUMN marketing_opt_out TINYINT NOT NULL DEFAULT 0;
