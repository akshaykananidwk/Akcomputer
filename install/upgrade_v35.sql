-- WhatsApp bot v2 (AI assistant): track which replies used an AI call (for
-- the monthly cost cap) and whether the sender was staff/owner or a customer.
ALTER TABLE wa_bot_log ADD COLUMN used_ai TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE wa_bot_log ADD COLUMN sender_role VARCHAR(10) NOT NULL DEFAULT 'customer';
