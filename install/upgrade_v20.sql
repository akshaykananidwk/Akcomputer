-- Upgrade v19 -> v20: Security hardening - TOTP 2FA (layered on top of the
-- existing WhatsApp-OTP login, not replacing it), login history, active
-- session/device tracking + revocation, login rate-limiting/lockout,
-- IP allowlisting, password policy, auto-logout, and a fuller audit log
-- (IP/user-agent on every activity_log row). All additive - nothing here
-- changes existing login behaviour for a shop that doesn't opt in.

ALTER TABLE users ADD COLUMN totp_secret_enc VARCHAR(255) NULL;
ALTER TABLE users ADD COLUMN totp_enabled TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE users ADD COLUMN password_changed_at DATETIME NULL;

CREATE TABLE IF NOT EXISTS totp_backup_codes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  code_hash VARCHAR(255) NOT NULL,
  used TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_tbc_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS login_history (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,
  username_attempted VARCHAR(50) DEFAULT '',
  success TINYINT(1) NOT NULL DEFAULT 0,
  reason VARCHAR(50) DEFAULT '',
  ip_address VARCHAR(45) DEFAULT '',
  user_agent VARCHAR(255) DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_lh_user (user_id),
  KEY idx_lh_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS user_sessions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  session_token VARCHAR(64) NOT NULL,
  ip_address VARCHAR(45) DEFAULT '',
  user_agent VARCHAR(255) DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  revoked TINYINT(1) NOT NULL DEFAULT 0,
  UNIQUE KEY uk_us_token (session_token),
  KEY idx_us_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS login_throttle (
  id INT AUTO_INCREMENT PRIMARY KEY,
  bucket_key VARCHAR(120) NOT NULL,
  attempt_count INT NOT NULL DEFAULT 1,
  window_start DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_lt_key (bucket_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE activity_log ADD COLUMN ip_address VARCHAR(45) DEFAULT '';
ALTER TABLE activity_log ADD COLUMN user_agent VARCHAR(255) DEFAULT '';

ALTER TABLE otp_codes ADD COLUMN attempts INT NOT NULL DEFAULT 0;

INSERT IGNORE INTO settings (name, value) VALUES ('pwd_min_length', '8');
INSERT IGNORE INTO settings (name, value) VALUES ('pwd_require_number', '1');
INSERT IGNORE INTO settings (name, value) VALUES ('pwd_require_mixed_case', '0');
INSERT IGNORE INTO settings (name, value) VALUES ('auto_logout_minutes', '0');
INSERT IGNORE INTO settings (name, value) VALUES ('ip_whitelist', '');
INSERT IGNORE INTO settings (name, value) VALUES ('login_max_attempts', '5');
INSERT IGNORE INTO settings (name, value) VALUES ('login_lockout_minutes', '15');
