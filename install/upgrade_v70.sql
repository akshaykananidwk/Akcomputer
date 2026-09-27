-- v70: the phone app's side of the bargain.
--
-- client_uuid
--   A bill written on a phone with no signal is finished long before the
--   server ever hears about it. When signal comes back the phone pushes it -
--   and a push can fail in the worst possible way: the server saves the bill
--   and the reply is lost on the way home. The phone, having heard nothing,
--   tries again.
--
--   Without something to recognise it by, that second attempt is a second
--   bill: two invoice numbers, two lots of stock gone, a customer billed
--   twice. So every document a phone creates carries a UUID made ON THE
--   PHONE, and the column is UNIQUE. The retry hits that key, the server
--   finds the bill it already saved, and answers with it. Saving twice
--   becomes impossible rather than unlikely.
--
--   NULL for everything written on the website, and MySQL allows any number
--   of NULLs in a unique index, so nothing existing is affected.
--
-- api_tokens.device / last_seen
--   Which phone a token belongs to and when it last spoke, so a lost phone
--   can be found in the list and revoked.

ALTER TABLE sales ADD COLUMN client_uuid CHAR(36) NULL;
ALTER TABLE sales ADD UNIQUE KEY uk_sale_client_uuid (client_uuid);

ALTER TABLE payments ADD COLUMN client_uuid CHAR(36) NULL;
ALTER TABLE payments ADD UNIQUE KEY uk_pay_client_uuid (client_uuid);

ALTER TABLE api_tokens ADD COLUMN device VARCHAR(120) NULL;
ALTER TABLE api_tokens ADD COLUMN last_seen DATETIME NULL;

-- items.updated_at / parties.updated_at
--   The phone asks "what changed since I last spoke?" and the answer has to
--   come from somewhere. MySQL keeps these itself (ON UPDATE
--   CURRENT_TIMESTAMP), so every screen that edits an item or a party keeps
--   the sync honest without a single line of code remembering to.
--
--   Existing rows all get "now", which is right: the first sync after this
--   migration is a full one anyway.

ALTER TABLE items ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
ALTER TABLE items ADD INDEX idx_items_updated (updated_at);
ALTER TABLE parties ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
ALTER TABLE parties ADD INDEX idx_parties_updated (updated_at);
