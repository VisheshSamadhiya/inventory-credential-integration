-- Repeatable migration for existing installations. Existing order rows are preserved.
DELIMITER //
DROP PROCEDURE IF EXISTS migrate_order_confirmation_20261012//
CREATE PROCEDURE migrate_order_confirmation_20261012()
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inventory_transactions' AND COLUMN_NAME='confirmed_at') THEN
        ALTER TABLE inventory_transactions ADD COLUMN confirmed_at DATETIME NULL AFTER status;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inventory_transactions' AND COLUMN_NAME='confirmed_by') THEN
        ALTER TABLE inventory_transactions ADD COLUMN confirmed_by BIGINT UNSIGNED NULL AFTER confirmed_at;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='inventory_transactions' AND CONSTRAINT_NAME='fk_transaction_confirmed_user') THEN
        ALTER TABLE inventory_transactions ADD CONSTRAINT fk_transaction_confirmed_user FOREIGN KEY (confirmed_by) REFERENCES users(id) ON DELETE SET NULL;
    END IF;
    -- Keep every supported order state when this older migration is rerun.
    ALTER TABLE inventory_transactions MODIFY COLUMN status ENUM('Pending','Confirmed','Rejected','Received') NULL DEFAULT 'Pending';
    CREATE TABLE IF NOT EXISTS order_reminder_attempts (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        order_id BIGINT UNSIGNED NOT NULL,
        recipient_email VARCHAR(254) NULL,
        attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        outcome ENUM('sent','failed') NOT NULL,
        error_message VARCHAR(1000) NULL,
        INDEX idx_order_reminder_attempt (order_id, attempted_at),
        INDEX idx_order_reminder_recipient (order_id, recipient_email, outcome, attempted_at),
        CONSTRAINT fk_order_reminder_transaction FOREIGN KEY (order_id) REFERENCES inventory_transactions(id) ON DELETE CASCADE
    ) ENGINE=InnoDB;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='order_reminder_attempts' AND COLUMN_NAME='recipient_email') THEN
        ALTER TABLE order_reminder_attempts ADD COLUMN recipient_email VARCHAR(254) NULL AFTER order_id;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='order_reminder_attempts' AND INDEX_NAME='idx_order_reminder_recipient') THEN
        ALTER TABLE order_reminder_attempts ADD INDEX idx_order_reminder_recipient (order_id, recipient_email, outcome, attempted_at);
    END IF;
END//
DELIMITER ;
CALL migrate_order_confirmation_20261012();
DROP PROCEDURE migrate_order_confirmation_20261012;
