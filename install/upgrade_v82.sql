-- v82: a message is not "sent" because the API said 200.
--
-- The shop wrote "Bill" to its own WhatsApp number, the bot answered, the
-- Inbox showed the reply sitting there "via meta" - and the customer never
-- received it. There was no way to find out why, because the one place that
-- KNOWS was being thrown away:
--
--     if (empty($v['messages'][0])) die(... 'status-event');
--
-- Meta posts the fate of every message back to the same webhook - sent,
-- delivered, read, or failed with a reason and an error code - and this
-- software acknowledged those and discarded them. So a message that Meta
-- accepted and then failed to deliver looked, on screen, exactly like one
-- the customer had read. The owner was left guessing at gateways.
--
-- Now the id of every outgoing message is kept, the receipts are matched
-- against it, and the Inbox says which of the four happened - with Meta's
-- own words when it failed.
ALTER TABLE wa_chats ADD COLUMN msg_id VARCHAR(80) NOT NULL DEFAULT '';
ALTER TABLE wa_chats ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT '';
ALTER TABLE wa_chats ADD COLUMN status_at DATETIME NULL;
ALTER TABLE wa_chats ADD COLUMN fail_reason VARCHAR(255) NOT NULL DEFAULT '';
CREATE INDEX idx_wa_chats_msg ON wa_chats (msg_id);

-- Telegram was being told about every incoming message, including the ones
-- the bot had already answered by itself. "media / media / Bill" with no
-- answer needed is noise, and noise is what makes a person stop reading the
-- alerts that do matter. Default: only the messages nobody has answered.
INSERT IGNORE INTO settings (name, value) VALUES
    ('wa_tg_notify', 'unanswered');   -- all | unanswered | off
