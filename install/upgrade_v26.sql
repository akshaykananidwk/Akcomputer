-- Reminder module: schedule a WhatsApp reminder (title, one or more numbers,
-- a note) to go out on a chosen date & time; one-time or recurring. Fired by
-- cron.php the same way overdue reminders / AMC renewals / scheduled reports are.
CREATE TABLE IF NOT EXISTS reminders (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(200) NOT NULL,
  notes TEXT NULL,
  category VARCHAR(30) NOT NULL DEFAULT 'general',
  remind_at DATETIME NOT NULL,
  repeat_freq VARCHAR(20) NOT NULL DEFAULT 'once',
  repeat_until DATE NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  last_sent_at DATETIME NULL,
  send_count INT NOT NULL DEFAULT 0,
  party_id INT NULL,
  created_by INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_rem_due (status, remind_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS reminder_recipients (
  id INT AUTO_INCREMENT PRIMARY KEY,
  reminder_id INT NOT NULL,
  name VARCHAR(120) NULL,
  mobile VARCHAR(20) NOT NULL,
  INDEX idx_rr_rem (reminder_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
