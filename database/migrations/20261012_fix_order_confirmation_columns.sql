-- Existing Docker volumes do not rerun database/schema.sql. This migration is
-- repeatable and changes only order metadata; transaction/stock data is kept.
DELIMITER //
DROP PROCEDURE IF EXISTS migrate_order_confirmation_columns_20261012//
CREATE PROCEDURE migrate_order_confirmation_columns_20261012()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inventory_transactions' AND COLUMN_NAME='status'
    ) THEN
        ALTER TABLE inventory_transactions
            ADD COLUMN status ENUM('Pending','Confirmed','Rejected','Received') NULL DEFAULT 'Pending' AFTER expected_date;
    ELSE
        ALTER TABLE inventory_transactions
            MODIFY COLUMN status ENUM('Pending','Confirmed','Rejected','Received') NULL DEFAULT 'Pending';
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inventory_transactions' AND COLUMN_NAME='confirmed_at'
    ) THEN
        ALTER TABLE inventory_transactions ADD COLUMN confirmed_at DATETIME NULL AFTER status;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inventory_transactions' AND COLUMN_NAME='confirmed_by'
    ) THEN
        ALTER TABLE inventory_transactions ADD COLUMN confirmed_by BIGINT UNSIGNED NULL AFTER confirmed_at;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='inventory_transactions'
          AND CONSTRAINT_NAME='fk_transaction_confirmed_user'
    ) THEN
        ALTER TABLE inventory_transactions
            ADD CONSTRAINT fk_transaction_confirmed_user FOREIGN KEY (confirmed_by)
            REFERENCES users(id) ON DELETE SET NULL;
    END IF;
END//
DELIMITER ;
CALL migrate_order_confirmation_columns_20261012();
DROP PROCEDURE migrate_order_confirmation_columns_20261012;
