-- WhatsApp bot v3: tiny per-number conversation memory so the bot can ask a
-- follow-up question ("IP કે HD કેમેરા?") and understand the next reply.
CREATE TABLE IF NOT EXISTS wa_bot_state (
  mobile VARCHAR(20) PRIMARY KEY,
  state VARCHAR(40) NOT NULL,
  data TEXT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
