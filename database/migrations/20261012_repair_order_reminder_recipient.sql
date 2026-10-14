-- Repeatable repair for deployments whose reminder history table predates
-- recipient tracking. Existing attempt rows are retained; the new column is
-- nullable because older rows have no recorded recipient.
DELIMITER //
DROP PROCEDURE IF EXISTS repair_order_reminder_recipient_20261012//
CREATE PROCEDURE repair_order_reminder_recipient_20261012()
BEGIN
    CREATE TABLE IF NOT EXISTS order_reminder_attempts (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        order_id BIGINT UNSIGNED NOT NULL,
        attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        outcome ENUM('sent','failed') NOT NULL,
        error_message VARCHAR(1000) NULL,
        INDEX idx_order_reminder_attempt (order_id, attempted_at),
        CONSTRAINT fk_order_reminder_transaction FOREIGN KEY (order_id) REFERENCES inventory_transactions(id) ON DELETE CASCADE
    ) ENGINE=InnoDB;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='order_reminder_attempts' AND COLUMN_NAME='recipient_email'
    ) THEN
        ALTER TABLE order_reminder_attempts ADD COLUMN recipient_email VARCHAR(254) NULL AFTER order_id;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='order_reminder_attempts' AND INDEX_NAME='idx_order_reminder_recipient'
    ) THEN
        ALTER TABLE order_reminder_attempts ADD INDEX idx_order_reminder_recipient (order_id, recipient_email, outcome, attempted_at);
    END IF;
END//
DELIMITER ;
CALL repair_order_reminder_recipient_20261012();
DROP PROCEDURE repair_order_reminder_recipient_20261012;
