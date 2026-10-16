-- v95: longer item names. Product names copied from a supplier's site or an
-- online listing ran past 150 characters and the save failed with "Data too
-- long for column 'name'". Widening only; no existing value changes.
ALTER TABLE items MODIFY name VARCHAR(255) NOT NULL;
ALTER TABLE items MODIFY brand VARCHAR(120) DEFAULT '';
ALTER TABLE items MODIFY model VARCHAR(120) DEFAULT '';
