-- Repeatable: adds rejection metadata and keeps all existing transaction values.
DELIMITER //
DROP PROCEDURE IF EXISTS migrate_rejected_orders_20261012//
CREATE PROCEDURE migrate_rejected_orders_20261012()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inventory_transactions' AND COLUMN_NAME='rejected_at'
    ) THEN
        ALTER TABLE inventory_transactions ADD COLUMN rejected_at DATETIME NULL AFTER confirmed_by;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inventory_transactions' AND COLUMN_NAME='rejected_by'
    ) THEN
        ALTER TABLE inventory_transactions ADD COLUMN rejected_by BIGINT UNSIGNED NULL AFTER rejected_at;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inventory_transactions' AND COLUMN_NAME='rejection_reason'
    ) THEN
        ALTER TABLE inventory_transactions ADD COLUMN rejection_reason VARCHAR(500) NULL AFTER rejected_by;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='inventory_transactions'
          AND CONSTRAINT_NAME='fk_transaction_rejected_user'
    ) THEN
        ALTER TABLE inventory_transactions
            ADD CONSTRAINT fk_transaction_rejected_user FOREIGN KEY (rejected_by)
            REFERENCES users(id) ON DELETE SET NULL;
    END IF;

    ALTER TABLE inventory_transactions
        MODIFY COLUMN status ENUM('Pending','Confirmed','Rejected','Received') NULL DEFAULT 'Pending';
END//
DELIMITER ;
CALL migrate_rejected_orders_20261012();
DROP PROCEDURE migrate_rejected_orders_20261012;
