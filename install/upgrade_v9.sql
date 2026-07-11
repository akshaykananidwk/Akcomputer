-- Upgrade v8 -> v9: shipping charge on sales/purchases
ALTER TABLE sales ADD COLUMN shipping DECIMAL(12,2) NOT NULL DEFAULT 0;
ALTER TABLE purchases ADD COLUMN shipping DECIMAL(12,2) NOT NULL DEFAULT 0;
