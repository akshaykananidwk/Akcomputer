-- v53: a payment can settle a party's OPENING BALANCE (old pre-software
-- ledger) just like a bill - the allocator shows it as its own line
ALTER TABLE payment_allocations MODIFY ref_type ENUM('sale','purchase','opening') NOT NULL;
