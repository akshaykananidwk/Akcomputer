-- v100: a credit card kept like a bank account (Cash & Bank -> Bank
-- accounts -> type "Credit card"). Spending on it raises what is owed on the
-- card; paying the card bill from a bank brings it down. In the books it is
-- a debt (2300 Credit Card), not bank money. Only the main admin sees it.
-- Adds only - every existing account stays a normal bank account.
ALTER TABLE bank_accounts ADD COLUMN account_type VARCHAR(12) NOT NULL DEFAULT 'bank';
ALTER TABLE bank_accounts ADD COLUMN admin_only TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE bank_accounts ADD COLUMN credit_limit DECIMAL(12,2) NOT NULL DEFAULT 0;
ALTER TABLE bank_accounts ADD COLUMN bill_day TINYINT NULL;
ALTER TABLE bank_accounts ADD COLUMN due_day TINYINT NULL;
INSERT IGNORE INTO chart_of_accounts (code, name, type, is_system, sort_order) VALUES
('2300', 'Credit Card', 'liability', 1, 56);
