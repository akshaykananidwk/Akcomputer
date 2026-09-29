-- v74: the call asks a question, and the answer is written down.
--
-- A reminder that only talks is a leaflet read aloud. The point of ringing
-- somebody is that they can answer, so the call now ends with "will you pay
-- today - press 1 for yes, 2 for no", and what they press is kept here.
--
-- A "yes" does not just sit in this table. It is logged through the same
-- promise machinery a promise taken over the counter goes through, so the
-- existing screens count it, the nightly job marks it kept or broken when
-- its day comes, and a customer who has promised is automatically left alone
-- until then. The column below is the record of what was pressed; the
-- promise is the thing the shop acts on.
ALTER TABLE voice_calls ADD COLUMN response VARCHAR(10) DEFAULT NULL;
ALTER TABLE voice_calls ADD COLUMN response_at DATETIME DEFAULT NULL;
ALTER TABLE voice_calls ADD COLUMN question_asked TINYINT(1) NOT NULL DEFAULT 0;

-- The spoken audio, made before the call is dialled rather than while the
-- customer's phone is connecting. The answer URL has only seconds to reply,
-- and generating speech inside it would be a call that rings and then dies
-- waiting. Empty means the call falls back to the provider's English voice.
ALTER TABLE voice_calls ADD COLUMN audio_file VARCHAR(255) DEFAULT NULL;

INSERT IGNORE INTO settings (name, value) VALUES
    ('voice_ivr',            '1'),      -- ask the question at the end of the call
    ('voice_tts',            '1'),      -- speak Gujarati/Hindi with generated audio
    ('voice_tts_model',      'gemini-3.8-flash-tts'),
    ('voice_tts_voice',      'Kore'),
    ('voice_tts_month_cap',  '2000'),   -- most sentences generated in a month
    ('voice_tts_month',      ''),       -- which month the counter below belongs to
    ('voice_tts_count',      '0');
