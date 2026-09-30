-- v85: ring the shopkeeper first, then the customer.
--
-- Until now every call this software placed was the software talking. What
-- was missing is the ordinary thing a shop does twenty times a day: pick up
-- the phone and call somebody.
--
-- Doing that from a personal handset has a cost nobody notices until it is
-- too late - the customer keeps the staff member's private number. They ring
-- it at eleven at night, they ring it after that person has left the shop,
-- and the shop has no record of any of it.
--
-- So the call goes out through the shop's own line in two legs: the system
-- rings the person who pressed the button, and when they pick up it dials
-- the customer with the SHOP's number as the caller ID. The customer sees
-- the shop. The staff member's number is never sent anywhere. And because
-- both legs are one call on the provider's side, the whole conversation -
-- including our own side of it - is one recording.
INSERT IGNORE INTO settings (name, value) VALUES
    ('voice_bridge', '1'),           -- may staff place connect calls at all
    ('voice_bridge_record', '1'),    -- record the conversation (both sides)
    ('voice_bridge_ring', '35');     -- seconds to ring the customer before giving up
