-- Additive, repeatable migration. Existing accounts and order history are preserved.
DELIMITER //
DROP PROCEDURE IF EXISTS migrate_order_email_outbox_20261015//
CREATE PROCEDURE migrate_order_email_outbox_20261015()
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='email') THEN
        ALTER TABLE users ADD COLUMN email VARCHAR(190) NULL AFTER username;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inventory_transactions' AND COLUMN_NAME='request_key') THEN
        ALTER TABLE inventory_transactions ADD COLUMN request_key CHAR(64) NULL AFTER rejection_reason;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inventory_transactions' AND INDEX_NAME='uq_inventory_transaction_request_key') THEN
        ALTER TABLE inventory_transactions ADD UNIQUE INDEX uq_inventory_transaction_request_key (request_key);
    END IF;
    CREATE TABLE IF NOT EXISTS order_email_outbox (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        event_key VARCHAR(191) NOT NULL UNIQUE,
        event_type VARCHAR(40) NOT NULL,
        recipient_email VARCHAR(254) NOT NULL,
        subject VARCHAR(255) NOT NULL,
        html_body LONGTEXT NOT NULL,
        text_body LONGTEXT NOT NULL,
        status ENUM('pending','processing','sent','failed') NOT NULL DEFAULT 'pending',
        attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
        available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        locked_at DATETIME NULL,
        sent_at DATETIME NULL,
        last_error VARCHAR(1000) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_order_email_outbox_ready (status, available_at, id),
        INDEX idx_order_email_outbox_locked (status, locked_at)
    ) ENGINE=InnoDB;
END//
DELIMITER ;
CALL migrate_order_email_outbox_20261015();
DROP PROCEDURE migrate_order_email_outbox_20261015;
