-- v77: ring a whole list, not one customer at a time.
--
-- Pressing a button per customer is fine for three and useless for thirty,
-- which is the number a shop actually has to chase on a Monday. So a list is
-- picked once and the calls go out by themselves.
--
-- They do NOT all go out at once. Fifty simultaneous calls from one number
-- is what a provider's concurrency limit exists to stop, and it is also how
-- a shop's number ends up looking like a spam dialler. The picked calls wait
-- their turn and a cron dials a few every few minutes.
--
-- No new column: a waiting call is a voice_calls row whose status is
-- 'waiting', so everything that already reads that table - the Calls screen,
-- the daily cap, the cooldown - counts them without being taught anything.
INSERT IGNORE INTO settings (name, value) VALUES
    ('voice_bulk_per_run', '5'),    -- how many the cron dials each time it wakes
    ('voice_bulk_enabled', '1');
