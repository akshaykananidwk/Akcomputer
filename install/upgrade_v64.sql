-- v64: a warranty claim that comes back as CREDIT instead of a part.
--
-- The company often cannot send the same thing back, so it knocks the money
-- off what the shop owes it instead. That credit has to reach a real purchase
-- bill, the same way a purchase return's credit does - otherwise the shop is
-- left holding a claim that settled in money with nothing in the books to show
-- for it, and the supplier's dues stay too high for ever.
--
-- credit_payment_id is the payments row the credit posts. It is kept so the
-- credit can be undone exactly: re-opening a claim and changing the amount has
-- to take back what the old one settled, bill by bill, not guess at it.
--
-- The payment is written with mode 'credit_note', which money_noncash_modes()
-- excludes from every cashbook and bank figure - no cash left a drawer here,
-- only what the supplier is owed changed.

ALTER TABLE warranty_claims ADD COLUMN credit_amount DECIMAL(12,2) NOT NULL DEFAULT 0;
ALTER TABLE warranty_claims ADD COLUMN credit_bill_id INT NULL;
ALTER TABLE warranty_claims ADD COLUMN credit_payment_id INT NULL;
ALTER TABLE warranty_claims ADD INDEX idx_wc_credit_pay (credit_payment_id);
