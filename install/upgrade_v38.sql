-- Telegram management bot: each staff account can link ONE Telegram chat
-- (via a one-time link code) and then run the shop from Telegram buttons.
ALTER TABLE users ADD COLUMN telegram_chat_id VARCHAR(30) NULL;
ALTER TABLE users ADD COLUMN tg_link_code VARCHAR(12) NULL;
