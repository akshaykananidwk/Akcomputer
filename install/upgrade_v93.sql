-- v93: a payment can be KEPT as an advance - money taken before the bill is
-- made, to be put against that bill later. Only marks the payment; it moves
-- no money and changes no balance. Nothing existing is linked or unlinked.
ALTER TABLE payments ADD COLUMN on_account TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE payment_allocations ADD KEY idx_alloc_ref (ref_type, ref_id);
