-- WhatsApp product bot: every incoming question + the bot's auto-reply is
-- logged here (also doubles as the anti-loop rate limiter).
CREATE TABLE IF NOT EXISTS wa_bot_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  mobile VARCHAR(20) NOT NULL,
  in_text TEXT NULL,
  had_image TINYINT(1) NOT NULL DEFAULT 0,
  reply TEXT NULL,
  matched INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_mobile_time (mobile, created_at)
);
