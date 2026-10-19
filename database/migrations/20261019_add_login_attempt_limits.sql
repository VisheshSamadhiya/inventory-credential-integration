-- Additive, repeatable login throttling shared across sessions and web workers.
CREATE TABLE IF NOT EXISTS login_attempt_limits (
    bucket_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    expires_at DATETIME NOT NULL,
    INDEX idx_login_attempt_expiry (expires_at)
) ENGINE=InnoDB;
