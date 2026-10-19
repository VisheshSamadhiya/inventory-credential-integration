-- Per-user credential-only TOTP policy and enrollment state.
-- Safe to apply repeatedly; existing account-wide login behavior is unchanged.
CREATE TABLE IF NOT EXISTS user_credential_totp (
    user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    required TINYINT(1) NOT NULL DEFAULT 0,
    status ENUM('pending','active','reset_required') NULL DEFAULT NULL,
    secret_enc TEXT NULL,
    pending_secret_enc TEXT NULL,
    last_accepted_step BIGINT NOT NULL DEFAULT -1,
    failed_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    locked_until DATETIME NULL,
    generation INT UNSIGNED NOT NULL DEFAULT 1,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_user_credential_totp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_user_credential_totp_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
