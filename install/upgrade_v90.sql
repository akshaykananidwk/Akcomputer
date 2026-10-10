-- v90: e-mail. Each login may have an e-mail (to log in with, and to get a
-- password-reset link); reset links are kept only as a hash; every e-mail
-- sent is noted briefly so "it never came" can be checked.
ALTER TABLE users ADD COLUMN email VARCHAR(120) NULL DEFAULT NULL;
ALTER TABLE users ADD UNIQUE KEY uq_users_email (email);

CREATE TABLE IF NOT EXISTS password_resets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    ip VARCHAR(45) NOT NULL DEFAULT '',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_reset_token (token_hash),
    KEY idx_reset_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mail_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    to_addr VARCHAR(190) NOT NULL, subject VARCHAR(190) NOT NULL,
    ok TINYINT(1) NOT NULL, error VARCHAR(255) NOT NULL DEFAULT '',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_mail_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
