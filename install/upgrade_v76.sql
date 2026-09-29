-- v76: the phone asks, WhatsApp answers.
--
-- A phone call is a bad place to receive a statement. The caller cannot
-- write down eleven bills read aloud, and asking them to is the reason
-- people ring the shop instead of looking it up. So the call takes the
-- question and WhatsApp delivers the answer - the ledger, the bill list, the
-- ticket number, the order status - as something they can keep.
--
-- It is NOT sent while the caller is on the line. Sending a WhatsApp is an
-- HTTP request to Meta, and the answer URL has seconds to reply; the same
-- mistake as generating speech mid-call, with the same result. What to send
-- is written here during the call and sent when it ends.
ALTER TABLE voice_calls ADD COLUMN wa_send VARCHAR(20) DEFAULT NULL;
ALTER TABLE voice_calls ADD COLUMN wa_sent_at DATETIME DEFAULT NULL;
ALTER TABLE voice_calls ADD KEY idx_voice_wa (wa_send, wa_sent_at);

INSERT IGNORE INTO settings (name, value) VALUES
    ('voice_wa_followup', '1');   -- send the answer on WhatsApp after the call
