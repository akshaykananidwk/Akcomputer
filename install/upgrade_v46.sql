-- v46: WhatsApp Inbox - every incoming customer message and every outgoing
-- message (bot replies, bills, receipts, staff replies) in one chat history,
-- so the owner can SEE what arrived and answer from wa_inbox.php.
CREATE TABLE IF NOT EXISTS wa_chats (
  id INT AUTO_INCREMENT PRIMARY KEY,
  mobile VARCHAR(20) NOT NULL,
  direction VARCHAR(3) NOT NULL,
  body TEXT,
  media_url VARCHAR(255) DEFAULT NULL,
  via VARCHAR(12) NOT NULL DEFAULT '',
  is_read TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_mobile (mobile, created_at),
  KEY idx_unread (direction, is_read)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
