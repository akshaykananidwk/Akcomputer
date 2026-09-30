-- v81: the call starts talking when the phone is answered, not when the
-- customer says something.
--
-- Machine detection was on for every call. With it on, the provider answers
-- and then LISTENS before it will ask this software what to say - it has to
-- hear enough audio to decide human or answering machine. A customer who
-- says "હલો" hands it that in a moment and the greeting follows. A customer
-- who simply puts the phone to their ear hands it nothing, so it keeps
-- listening until its own window runs out, and for those seconds they hear
-- silence. That is exactly when a person hangs up.
--
-- The trade was the wrong way round. It was bought to avoid reading a
-- reminder to an answering machine; the price was every quiet customer
-- hearing nothing at all on a call that had not started. Off by default now,
-- and still available to a shop that would rather pay that price.
INSERT IGNORE INTO settings (name, value) VALUES
    ('voice_machine_detect', '0');
