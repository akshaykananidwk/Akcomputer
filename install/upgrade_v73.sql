-- v73: payment reminder CALLS.
--
-- A WhatsApp reminder can be left unread. A phone that rings is harder to
-- ignore, which is the whole point - and also exactly why this table exists
-- rather than a column on the party. A call is an intrusion into someone's
-- day, so every one of them is written down: who, how much was said, when it
-- went, whether it was picked up, and who pressed the button. If a customer
-- ever asks "why did you keep calling me", the answer is a row, not a memory.
--
-- Three columns are doing security work and are worth spelling out:
--
--   token       The answer URL is fetched by the phone network, not by a
--               logged-in browser, so it cannot carry a session. Without a
--               secret in the URL anybody who guessed a call id could hear
--               a customer's name and outstanding amount read aloud. The
--               token is random per call, checked on the way in, and dies
--               with the call.
--   client_uuid The button is pressed, the page hangs, the owner presses
--               again. UNIQUE means the second press finds the first call
--               instead of ringing the customer twice.
--   script      What was actually spoken, kept verbatim. The amount is
--               assembled from clips at call time; storing the sentence is
--               how a dispute about "you said I owe X" gets settled.
CREATE TABLE IF NOT EXISTS voice_calls (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    party_id      INT          NOT NULL,
    mobile        VARCHAR(20)  NOT NULL,
    amount        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    due_date      DATE                  DEFAULT NULL,
    lang          VARCHAR(5)   NOT NULL DEFAULT 'gu',
    script        TEXT                  DEFAULT NULL,
    provider      VARCHAR(20)  NOT NULL DEFAULT 'vobiz',
    call_uuid     VARCHAR(64)           DEFAULT NULL,
    token         CHAR(40)     NOT NULL,
    status        VARCHAR(20)  NOT NULL DEFAULT 'queued',
    hangup_cause  VARCHAR(40)           DEFAULT NULL,
    duration      INT          NOT NULL DEFAULT 0,
    answered      TINYINT(1)   NOT NULL DEFAULT 0,
    error         VARCHAR(255)          DEFAULT NULL,
    test_mode     TINYINT(1)   NOT NULL DEFAULT 0,
    started_at    DATETIME              DEFAULT NULL,
    answered_at   DATETIME              DEFAULT NULL,
    ended_at      DATETIME              DEFAULT NULL,
    client_uuid   VARCHAR(40)           DEFAULT NULL,
    created_by    INT                   DEFAULT NULL,
    created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_voice_client_uuid (client_uuid),
    UNIQUE KEY uniq_voice_token (token),
    KEY idx_voice_party (party_id, created_at),
    KEY idx_voice_uuid (call_uuid),
    KEY idx_voice_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Do-not-call is separate from collection_opt_out on purpose. A customer may
-- be happy to get a WhatsApp reminder and still not want the phone to ring;
-- folding the two together would force them to choose silence or nothing.
ALTER TABLE parties ADD COLUMN voice_dnd TINYINT(1) NOT NULL DEFAULT 0;

-- Optional: a recording of this customer's name, so the call can greet them
-- by name in Gujarati. Empty for almost everyone, and the call simply drops
-- the name when it is missing rather than mispronouncing it.
ALTER TABLE parties ADD COLUMN voice_name_clip VARCHAR(120) DEFAULT NULL;

-- Defaults. INSERT IGNORE so a re-run never overwrites what the owner set.
--
-- voice_enabled starts at 0 deliberately: the migration must not make a live
-- shop able to ring customers before anyone has entered a caller ID, checked
-- the DLT paperwork or heard the recording once.
INSERT IGNORE INTO settings (name, value) VALUES
    ('voice_enabled',        '0'),
    ('voice_provider',       'vobiz'),
    ('voice_lang',           'gu'),
    ('voice_hour_from',      '9'),
    ('voice_hour_to',        '21'),
    ('voice_cooldown_hours', '6'),
    ('voice_max_per_day',    '50'),
    ('voice_balance_min',    '100'),
    ('voice_test_mode',      '1'),
    ('vobiz_auth_id',        ''),
    ('vobiz_auth_token',     ''),
    ('vobiz_caller_id',      ''),
    ('vobiz_webhook_secret', '');
