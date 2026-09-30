-- v84: choose ONE WhatsApp to connect, not both at once by accident.
--
-- Two providers were always live together, and which one a message left by
-- came from a priority setting buried under a page of other boxes. A shop
-- with two numbers therefore answered customers from whichever one that
-- setting happened to name - and nothing on the screen made the choice look
-- like a choice.
--
-- It is the first thing on the page now: which WhatsApp do you use? The
-- setup for that one opens underneath, and the other's boxes are not shown
-- at all until it is picked.
--
-- The default is worked out from what this shop already has configured, so
-- an upgrade changes nothing for anybody: a shop using both keeps both, a
-- shop with only one keeps that one.
INSERT IGNORE INTO settings (name, value) VALUES ('wa_provider_mode', '');

UPDATE settings SET value = (
    SELECT CASE
        WHEN (SELECT COUNT(*) FROM (SELECT value v FROM settings WHERE name = 'meta_wa_token') x WHERE v <> '') > 0
         AND (SELECT COUNT(*) FROM (SELECT value v FROM settings WHERE name = 'wa_api_key') y WHERE v <> '') > 0
            THEN 'both'
        WHEN (SELECT COUNT(*) FROM (SELECT value v FROM settings WHERE name = 'meta_wa_token') x WHERE v <> '') > 0
            THEN 'meta'
        ELSE 'thirdparty'
    END
) WHERE name = 'wa_provider_mode' AND value = '';
