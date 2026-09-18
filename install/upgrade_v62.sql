-- v62: the warranty chain, by serial number.
--
-- An item goes to the company under warranty and a DIFFERENT one comes back,
-- with a different serial number. Three things were being lost at that moment:
--
-- 1. THE CHAIN. Nothing joined the old serial to the new one. Look up the
--    replacement a year later and there was no way back to the original bill,
--    the original purchase, or the date the warranty actually started - which
--    is the one fact that decides whether a claim is still valid. Replace it
--    twice and the trail was gone completely.
--
--    replaced_by_id is the link forward; root_serial_id is the FIRST serial of
--    the chain, kept on every link so the beginning is one hop away however
--    long the chain grows.
--
-- 2. THE STOCK. A unit sitting at the company was still counted as sellable
--    here. The industry name for that is phantom inventory, and the standard
--    handling is to mark it "in transit, not available" until it comes back.
--    claim_id says which claim a serial is out on, and stock_out records that
--    the claim really did take one off the shelf - so putting it back happens
--    exactly once, no matter how many times the claim is saved.
--
-- 3. THE WARRANTY DATE. Both practices exist in the trade: usually the
--    replacement carries on the ORIGINAL warranty from the original purchase,
--    but some companies give a fresh period on the replacement instead. The
--    software must not decide this silently - warranty_mode records which rule
--    was applied to this claim, so the answer can still be explained later.

ALTER TABLE item_serials ADD COLUMN replaced_by_id INT NULL;
ALTER TABLE item_serials ADD COLUMN root_serial_id INT NULL;
ALTER TABLE item_serials ADD COLUMN claim_id INT NULL;
ALTER TABLE item_serials ADD INDEX idx_serial_replaced (replaced_by_id);
ALTER TABLE item_serials ADD INDEX idx_serial_root (root_serial_id);

ALTER TABLE warranty_claims ADD COLUMN warranty_mode VARCHAR(10) NOT NULL DEFAULT 'continue';
ALTER TABLE warranty_claims ADD COLUMN fresh_months INT NOT NULL DEFAULT 0;
ALTER TABLE warranty_claims ADD COLUMN stock_out TINYINT NOT NULL DEFAULT 0;
