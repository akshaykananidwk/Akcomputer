-- v44: repair bill payment status. A zero-total bill (e.g. 100% discount
-- purchase) was stored as status='due' because payment_status() checked
-- "paid = 0" before "paid covers total". The helper is fixed in code;
-- this repairs the rows already saved with the old logic.
UPDATE purchases SET status = 'paid' WHERE total - paid <= 0.009 AND status <> 'paid';
UPDATE sales SET status = 'paid' WHERE total - paid <= 0.009 AND status <> 'paid';
