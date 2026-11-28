-- v105: the product's full Specifications as the owner pastes them from the box
-- or datasheet (Model, Resolution, Lens, Power ...). Shown as the spec table on
-- the website and used by its filters. Adds only.
ALTER TABLE items ADD COLUMN specs TEXT NULL;
