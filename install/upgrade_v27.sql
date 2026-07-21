-- Money-flow audit fixes:
-- 1) sales_returns.adjusted_amount - how much of the return was applied
--    against the original bill's outstanding (refund_mode 'adjust'), so
--    deleting the return can un-apply exactly that much.
-- 2) purchase_returns.refund_mode - mirrors sales returns: 'adjust' (credit
--    against supplier account, default/old behaviour) or cash/upi when the
--    supplier actually hands money back (posted to the payments ledger).
ALTER TABLE sales_returns ADD COLUMN adjusted_amount DECIMAL(12,2) NOT NULL DEFAULT 0;
ALTER TABLE purchase_returns ADD COLUMN refund_mode VARCHAR(20) NOT NULL DEFAULT 'adjust';
