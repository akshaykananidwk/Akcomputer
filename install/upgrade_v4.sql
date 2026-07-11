-- Upgrade v3 -> v4: free quantity on sale items (Vyapar-style)
ALTER TABLE sale_items ADD COLUMN free_qty DECIMAL(12,2) NOT NULL DEFAULT 0;
