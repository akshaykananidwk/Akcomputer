-- v83: "Bot replied? ✅" meant the bot WROTE a reply, not that anybody got it.
--
-- The log showed a tick against every message while the customer's phone
-- stayed empty, because send_whatsapp() returns whether the message actually
-- went and the bot threw that answer away at every single call site. So the
-- one screen the owner would look at to check said everything was fine.
--
-- A tick now means it left the building, and a cross carries the provider's
-- own reason for why it did not.
ALTER TABLE wa_bot_log ADD COLUMN sent TINYINT(1) NULL;
ALTER TABLE wa_bot_log ADD COLUMN send_error VARCHAR(255) NOT NULL DEFAULT '';
