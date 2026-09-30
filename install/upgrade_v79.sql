-- v79: the reminder call becomes a conversation.
--
-- Until now the call read a sentence out and offered a keypad: press 1 for
-- yes, 2 for no. That is not how anybody in a market talks. The owner asked
-- for the call to greet the customer by name, ask how they are, and then ask
-- politely WHEN the payment will come - and to listen to the spoken answer
-- and reply to it.
--
-- What the AI is allowed to do, and what it is not:
--
--   * It LISTENS. The customer's recorded answer goes to the model and comes
--     back as a classification - did they promise, when, or was it refusal,
--     confusion, a wrong number. That is all it returns.
--   * It never speaks. Every word the customer hears is one of the shop's own
--     sentences, editable on the wording screen, spoken in the shop's own
--     generated voice. A model that improvises down a phone line is a model
--     that one day promises a discount nobody authorised.
--   * It never writes. The date it heard is checked by ordinary code first -
--     a real date, not in the past, not further out than the shop allows -
--     and only then does a promise go into the ledger's own history, through
--     coll_log(), exactly as if a person had typed it.
--   * It is told as little as possible: the recording and today's date. Not
--     the customer's name, not their number, not what they owe.
--   * When it fails, is slow, or is switched off, the call falls back to the
--     keypad question that was there before. A caller never hears silence.
ALTER TABLE voice_calls ADD COLUMN heard VARCHAR(500) NOT NULL DEFAULT '';
ALTER TABLE voice_calls ADD COLUMN heard_intent VARCHAR(20) NOT NULL DEFAULT '';
ALTER TABLE voice_calls ADD COLUMN promise_date DATE NULL;
ALTER TABLE voice_calls ADD COLUMN talk_turns TINYINT(1) NOT NULL DEFAULT 0;

INSERT IGNORE INTO settings (name, value) VALUES
    ('voice_talk', '0'),              -- off until the owner has heard it themselves
    ('voice_talk_turns', '2'),        -- how many times we may ask again before giving up
    ('voice_talk_max_days', '30'),    -- a date further out than this is not accepted as a promise
    ('voice_talk_secs', '8'),         -- how long the customer's answer may be
    ('voice_talk_month_cap', '500'),  -- listens per month, so a runaway loop cannot run up a bill
    ('voice_talk_month', ''),
    ('voice_talk_count', '0');
