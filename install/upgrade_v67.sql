-- v67: put back the serial statuses that Migrate had been wiping.
--
-- v3 re-set item_serials.status to a list that did not include
-- 'adjusted_out', which v28 had added. Migrations re-run in order on every
-- Migrate, so v3 kept narrowing the column after v28 had widened it - and
-- narrowing an ENUM truncates rather than fails: every serial that had been
-- adjusted out (taken off the shelf by a manual stock adjustment, or by the
-- Serial/Stock Repair tool) was left holding the empty string.
--
-- Such a row is in no status at all: it is not in stock, so no screen offers
-- it, and it is not sold, so nothing explains where the piece went. v3 now
-- carries the full list, so it cannot happen again; this puts the rows that
-- already lost it back.
--
-- '' can only have come from 'adjusted_out'. Every other value v28 allows was
-- already in v3's list, so nothing else could truncate.

UPDATE item_serials SET status = 'adjusted_out' WHERE status = '';
