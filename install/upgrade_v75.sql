-- v75: the shop's number answers by itself.
--
-- Until now every call went OUT: the shop rang a customer about money. This
-- turns the same number into something a customer can ring - hear what they
-- owe, place an order, report a fault, ask about a delivery, or be put
-- through to a person - and puts both directions in one place.
--
-- voice_calls already held one row per call, so it gains a direction rather
-- than growing a second table. Everything the collection screen does with
-- outgoing calls keeps working because 'out' is the default for every row
-- that already exists.
ALTER TABLE voice_calls ADD COLUMN direction VARCHAR(3) NOT NULL DEFAULT 'out';
ALTER TABLE voice_calls ADD COLUMN from_number VARCHAR(20) DEFAULT NULL;
ALTER TABLE voice_calls ADD COLUMN to_number VARCHAR(20) DEFAULT NULL;

-- What the caller came for, and what they pressed to get there. intent is the
-- branch they ended on ('balance', 'order', 'complaint'...); ivr_path is the
-- keys in order ('2', '1-3'), which is how a menu nobody understands shows
-- itself - everyone pressing 0 for a human means the menu is wrong.
ALTER TABLE voice_calls ADD COLUMN intent VARCHAR(20) DEFAULT NULL;
ALTER TABLE voice_calls ADD COLUMN ivr_path VARCHAR(60) DEFAULT NULL;

-- A call the shop still owes an answer to. An order left on the machine at
-- nine at night is worth money only if somebody sees it in the morning, so
-- it stays open until a person closes it.
ALTER TABLE voice_calls ADD COLUMN needs_action TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE voice_calls ADD COLUMN handled_by INT DEFAULT NULL;
ALTER TABLE voice_calls ADD COLUMN handled_at DATETIME DEFAULT NULL;
ALTER TABLE voice_calls ADD COLUMN notes VARCHAR(500) DEFAULT NULL;

-- What the caller said, and where it ended up. ref_type/ref_id point at the
-- lead, ticket or task the call turned into, so the call and the work are
-- never two separate stories about the same customer.
ALTER TABLE voice_calls ADD COLUMN recording_url VARCHAR(255) DEFAULT NULL;
ALTER TABLE voice_calls ADD COLUMN recording_secs INT NOT NULL DEFAULT 0;
ALTER TABLE voice_calls ADD COLUMN ref_type VARCHAR(20) DEFAULT NULL;
ALTER TABLE voice_calls ADD COLUMN ref_id INT DEFAULT NULL;

ALTER TABLE voice_calls ADD KEY idx_voice_dir (direction, created_at);
ALTER TABLE voice_calls ADD KEY idx_voice_action (needs_action, created_at);
ALTER TABLE voice_calls ADD KEY idx_voice_from (from_number);

-- Every key pressed, in order, with the time. The call row carries where the
-- caller ended up; this carries how they got there, which is the only way to
-- see that eight people in a row pressed 1 and then hung up.
CREATE TABLE IF NOT EXISTS voice_ivr_events (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    call_id    INT          NOT NULL,
    step       VARCHAR(30)  NOT NULL,
    digit      VARCHAR(4)            DEFAULT NULL,
    detail     VARCHAR(255)          DEFAULT NULL,
    created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_ivr_call (call_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO settings (name, value) VALUES
    ('voice_inbound',        '0'),   -- off until a number is attached and heard
    ('vobiz_app_id',         ''),
    ('vobiz_inbound_number', ''),
    ('voice_agent_numbers',  ''),    -- comma separated, rung in order for "talk to a person"
    ('voice_agent_timeout',  '25'),
    ('voice_shop_open',      '9'),
    ('voice_shop_close',     '21'),
    ('voice_inbound_lang',   'gu');
