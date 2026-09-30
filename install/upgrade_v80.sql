-- v80: the whole call on tape, and a screen you can actually search.
--
-- The recording kept until now was only the customer's answer - what the
-- shop's own voice said before it was never on tape at all. If a customer
-- ever says "your phone told me something else", the shop has half the
-- conversation and half is no use. Recording the SESSION keeps both sides,
-- from the greeting onwards.
--
-- It is off by default and has to be switched on, because recording a phone
-- call is the shop's decision to make and not this software's - and because
-- a shop that records has to be willing to say so if asked.
--
-- If the provider ignores the request, nothing breaks: the call plays out
-- exactly as before and no full recording appears. The customer's answer is
-- recorded either way.
ALTER TABLE voice_calls ADD COLUMN full_rec_url VARCHAR(255) NOT NULL DEFAULT '';
ALTER TABLE voice_calls ADD COLUMN full_rec_secs INT NOT NULL DEFAULT 0;

INSERT IGNORE INTO settings (name, value) VALUES
    ('voice_record_all', '0'),        -- record the whole call, both sides
    ('voice_record_max', '600');      -- seconds; a runaway call is not taped forever
