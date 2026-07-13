-- Upgrade v13 -> v14: Vyapar-style Sale entry additions (Adjustment,
-- Round Off) so the bill's own total can be free-form nudged or rounded
-- to the nearest rupee without disturbing the item-line math.
ALTER TABLE sales ADD COLUMN adjustment DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER shipping;
ALTER TABLE sales ADD COLUMN round_off DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER adjustment;
