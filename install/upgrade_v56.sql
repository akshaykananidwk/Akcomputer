-- v56: purchase intelligence.
--
-- Only ONE new column, and it exists because the answer genuinely is not in
-- the data. Working out WHAT to reorder is arithmetic over sales history, but
-- working out WHEN needs to know how long a supplier takes to deliver - and
-- purchases records only the bill date, never a delivery date. Lead time
-- therefore cannot be measured; it has to be told to us. Rather than guess a
-- number and dress it up as analysis, the shop enters it per supplier and a
-- global default covers the rest.
--
-- Everything else Phase 5 shows - suggested quantities, days until a product
-- runs out, dead-stock ageing, supplier price history, margin warnings, the
-- lost-sales estimate - is derived at read time from sales, purchases and
-- stock_ledger, so none of it can drift out of step with the stock itself.
--
-- Idempotent: the runner treats "duplicate column" (1060) and "duplicate key"
-- (1061) as already-applied.

ALTER TABLE parties ADD COLUMN lead_days SMALLINT NOT NULL DEFAULT 0;

-- Reconstructing when an item was out of stock walks stock_ledger per item in
-- date order; without this it is a full scan of every movement ever recorded.
ALTER TABLE stock_ledger ADD INDEX idx_sl_item_time (item_id, created_at);

-- "what did we pay this supplier for this item, and when" - read for every
-- row of the reorder list and on the item page's supplier comparison.
ALTER TABLE purchase_items ADD INDEX idx_pi_item (item_id, purchase_id);
