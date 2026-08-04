-- v49: usage metering for Cost Analytics (AI tokens, WhatsApp messages, and
-- any future service - SMS/Email/OCR/Maps just log new `service` values)
CREATE TABLE IF NOT EXISTS api_usage (
  id INT AUTO_INCREMENT PRIMARY KEY,
  service VARCHAR(20) NOT NULL,
  provider VARCHAR(60) DEFAULT '',
  units_in INT DEFAULT 0,
  units_out INT DEFAULT 0,
  calls INT DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_svc_date (service, created_at)
);
