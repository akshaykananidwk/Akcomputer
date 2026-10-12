-- v92: the phone app keeps the WHOLE shop's books on the phone, so every
-- screen works with no signal. To send only what changed since the last
-- sync, each document table stamps itself whenever a row changes (a bill
-- getting paid, a purchase cancelled). Child rows (a bill's lines) travel
-- with their parent.
ALTER TABLE sales ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
ALTER TABLE sales ADD KEY idx_sales_updated (updated_at, id);
ALTER TABLE purchases ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
ALTER TABLE purchases ADD KEY idx_purchases_updated (updated_at, id);
ALTER TABLE payments ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
ALTER TABLE payments ADD KEY idx_payments_updated (updated_at, id);
ALTER TABLE expenses ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
ALTER TABLE expenses ADD KEY idx_expenses_updated (updated_at, id);
ALTER TABLE sales_returns ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
ALTER TABLE sales_returns ADD KEY idx_sreturns_updated (updated_at, id);
ALTER TABLE purchase_returns ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
ALTER TABLE purchase_returns ADD KEY idx_preturns_updated (updated_at, id);
