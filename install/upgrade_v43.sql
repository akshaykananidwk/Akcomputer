-- Location-locked staff: a godown manager and a shop manager work apart.
-- With the lock ON (and not a full admin) the user sees/operates only
-- their own location: stock columns, items location filter, stock audits,
-- and the location on new sale/purchase bills is forced to their own.
ALTER TABLE users ADD COLUMN location_locked TINYINT(1) NOT NULL DEFAULT 0;
