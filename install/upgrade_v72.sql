-- v72: orders placed from the customer app.
--
-- Same reason as sales.client_uuid in v70. A customer taps "Place order",
-- the phone loses signal at the wrong moment, and the app tries again. The
-- shop must not end up packing the same order twice, and the customer must
-- not be told their order failed when it did not.
--
-- The app makes the id, the column is UNIQUE, and the retry finds the order
-- that already exists instead of creating a second one. NULL for every
-- order placed from the website, and MySQL allows any number of NULLs in a
-- unique index, so nothing existing is touched.
ALTER TABLE web_orders ADD COLUMN client_uuid VARCHAR(40) DEFAULT NULL;
ALTER TABLE web_orders ADD UNIQUE KEY uniq_weborder_uuid (client_uuid);
