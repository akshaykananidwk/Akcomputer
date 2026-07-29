-- Repair: cash payments that were carrying a bank account id.
-- The payment forms always posted the (hidden) bank dropdown's value, so a
-- CASH receipt/payment could save with a bank_account_id attached - and the
-- Bank Ledger picks rows by bank_account_id, so that cash showed up inside
-- the bank ledger and inflated the bank balance. The code paths are fixed
-- (resolve_payment_target strips the bank id from any cash-type mode); this
-- cleans the rows already written. Only true cash-type modes are touched -
-- UPI/other modes are left exactly as they are.
UPDATE payments py JOIN payment_methods pm ON pm.code = py.mode
   SET py.bank_account_id = NULL
 WHERE pm.type = 'cash' AND py.bank_account_id IS NOT NULL;
UPDATE payments SET bank_account_id = NULL
 WHERE mode = 'cash' AND bank_account_id IS NOT NULL;

UPDATE expenses e JOIN payment_methods pm ON pm.code = e.mode
   SET e.bank_account_id = NULL
 WHERE pm.type = 'cash' AND e.bank_account_id IS NOT NULL;
UPDATE expenses SET bank_account_id = NULL
 WHERE mode = 'cash' AND bank_account_id IS NOT NULL;
