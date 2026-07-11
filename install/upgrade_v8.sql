-- Upgrade v7 -> v8: purchase bill cancel/delete
ALTER TABLE purchases ADD COLUMN is_cancelled TINYINT(1) NOT NULL DEFAULT 0;
