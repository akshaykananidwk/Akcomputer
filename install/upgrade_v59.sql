-- v59: drop three indexes that were never earning their keep.
--
-- An index is not free. Every INSERT and every UPDATE has to write to all of
-- them, and on a billing system the hot path IS writing bills. These three
-- cost that write and buy nothing back, because a wider index that starts with
-- the same columns already serves every query they could serve: MySQL can use
-- the leading part of a composite index on its own.
--
--   sales.idx_sale_party (party_id)
--       is the leading part of idx_sale_party_status (party_id, status, due_date)
--
--   stock_ledger.idx_ledger_item (item_id)
--       is the leading part of idx_sl_item_time (item_id, created_at), added
--       in v56 - so v56 quietly made this one redundant and nobody noticed
--
--   api_usage.idx_usage_service_time (service, created_at)
--       is an EXACT duplicate of idx_svc_date (service, created_at), added in
--       v57 without checking whether the same index already existed. Two
--       identical B-trees, both written on every AI call.
--
-- Two of the three were created by earlier migrations in this same project.
-- That is exactly why the Scaling screen looks for them automatically now
-- rather than trusting anyone to remember.
--
-- Nothing here touches data, and no query loses an index it was using - the
-- wider index takes over in every case. The migration runner treats a missing
-- index (error 1091) as already-done, so re-running is safe.

ALTER TABLE sales DROP INDEX idx_sale_party;
ALTER TABLE stock_ledger DROP INDEX idx_ledger_item;
ALTER TABLE api_usage DROP INDEX idx_usage_service_time;
