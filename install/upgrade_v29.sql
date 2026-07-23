-- Owner decision: every electronic payment lands in a BANK ACCOUNT.
-- UPI/PhonePe as separate modes hid WHICH account the money entered, so they
-- are retired from the pickers (historical rows keep displaying fine).
-- Card and Cheque become bank-type so the bank-account picker is required.
UPDATE payment_methods SET is_active = 0 WHERE code IN ('upi', 'phonepe');
UPDATE payment_methods SET type = 'bank' WHERE code IN ('card', 'cheque');
