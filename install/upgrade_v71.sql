-- v71: opening a website screen from inside the phone app, without a
-- second login.
--
-- The app does the counter work itself, offline. Everything else the shop
-- does - reports, accounting, repairs, settings, the eighty other screens -
-- already exists on the website and must not be written a second time in
-- the app, or the two drift apart and the app is always the stale one.
--
-- So the app opens the real page in its own window. The problem is the
-- login: the app holds an API token, the website wants a session cookie,
-- and asking the owner to type a password again every time would make the
-- whole thing useless.
--
-- This table is the handover. The app asks for a one-time key, the website
-- takes it once and turns it into a session. It is deliberately hostile:
--
--   * stored hashed, so the table is worth nothing if it leaks
--   * one use only - used_at is stamped and checked
--   * two minutes to live; the app opens the link immediately
--   * tied to the user the API token belongs to, and to no other
--   * carries the page it is for, decided by the server at consumption
--
-- It is a key that only works once, only for a moment, and only for the
-- person who asked for it.
CREATE TABLE IF NOT EXISTS app_web_links (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    nonce_hash  CHAR(64)     NOT NULL,
    user_id     INT          NOT NULL,
    target      VARCHAR(255) NOT NULL DEFAULT 'index.php',
    device      VARCHAR(120)          DEFAULT NULL,
    expires_at  DATETIME     NOT NULL,
    used_at     DATETIME              DEFAULT NULL,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_app_link_nonce (nonce_hash),
    KEY idx_app_link_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
