-- Additive session invalidation token for account security changes.
-- Existing users and password hashes are preserved; existing sessions default to version 0.
DELIMITER //
DROP PROCEDURE IF EXISTS migrate_user_auth_version_20261013//
CREATE PROCEDURE migrate_user_auth_version_20261013()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='auth_version'
    ) THEN
        ALTER TABLE users ADD COLUMN auth_version INT UNSIGNED NOT NULL DEFAULT 0 AFTER active;
    END IF;
END//
DELIMITER ;
CALL migrate_user_auth_version_20261013();
DROP PROCEDURE migrate_user_auth_version_20261013;
