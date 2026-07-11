-- Upgrade v4 -> v5: margin %, product/service type, invoice cancel
ALTER TABLE items ADD COLUMN margin_pct DECIMAL(6,2) NOT NULL DEFAULT 0;
ALTER TABLE items ADD COLUMN item_type ENUM('product','service') NOT NULL DEFAULT 'product';
ALTER TABLE sales ADD COLUMN is_cancelled TINYINT(1) NOT NULL DEFAULT 0;
