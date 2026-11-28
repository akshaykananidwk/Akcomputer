-- v101: online store - the admin can pin a product to the front of the store
-- (📌 on a product card). 0 = normal order (real sales / stock / newness),
-- 1 = always shown first. Nothing else about the item changes.
ALTER TABLE items ADD COLUMN web_priority TINYINT NOT NULL DEFAULT 0;
-- The printed MRP (optional). The website shows a struck-through MRP and the
-- "% OFF" only when the shop has typed a real MRP above the selling price -
-- never an invented discount.
ALTER TABLE items ADD COLUMN mrp DECIMAL(12,2) NULL DEFAULT NULL;
