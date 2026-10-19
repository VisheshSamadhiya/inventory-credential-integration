-- Repeatable purchase metadata upgrade; preserve all existing order states.
-- information_schema guards are portable across supported MySQL/MariaDB versions.
DELIMITER //
DROP PROCEDURE IF EXISTS migrate_order_details_20261008//
CREATE PROCEDURE migrate_order_details_20261008()
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inventory_transactions' AND COLUMN_NAME='unit_price') THEN
        ALTER TABLE inventory_transactions ADD COLUMN unit_price DECIMAL(13,2) NULL AFTER reason;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inventory_transactions' AND COLUMN_NAME='total_price') THEN
        ALTER TABLE inventory_transactions ADD COLUMN total_price DECIMAL(15,2) NULL AFTER unit_price;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inventory_transactions' AND COLUMN_NAME='order_reason') THEN
        ALTER TABLE inventory_transactions ADD COLUMN order_reason ENUM('Requested','New','Replacement') NULL AFTER total_price;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inventory_transactions' AND COLUMN_NAME='vendor') THEN
        ALTER TABLE inventory_transactions ADD COLUMN vendor VARCHAR(180) NULL AFTER order_reason;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inventory_transactions' AND COLUMN_NAME='expected_date') THEN
        ALTER TABLE inventory_transactions ADD COLUMN expected_date DATE NULL AFTER vendor;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inventory_transactions' AND COLUMN_NAME='status') THEN
        ALTER TABLE inventory_transactions ADD COLUMN status ENUM('Pending','Confirmed','Rejected','Received') NULL DEFAULT 'Pending' AFTER expected_date;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inventory_transactions' AND COLUMN_NAME='related_order_id') THEN
        ALTER TABLE inventory_transactions ADD COLUMN related_order_id BIGINT UNSIGNED NULL AFTER status;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inventory_transactions' AND INDEX_NAME='idx_transaction_related_order') THEN
        ALTER TABLE inventory_transactions ADD INDEX idx_transaction_related_order (related_order_id);
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inventory_transactions' AND COLUMN_NAME='related_order_id' AND REFERENCED_TABLE_NAME='inventory_transactions') THEN
        ALTER TABLE inventory_transactions ADD CONSTRAINT fk_transaction_order FOREIGN KEY (related_order_id) REFERENCES inventory_transactions(id) ON DELETE SET NULL;
    END IF;
END//
CALL migrate_order_details_20261008()//
DROP PROCEDURE migrate_order_details_20261008//

DROP PROCEDURE IF EXISTS migrate_order_reference_20261008//
CREATE PROCEDURE migrate_order_reference_20261008()
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inventory_transactions' AND COLUMN_NAME='related_order_id' AND REFERENCED_TABLE_NAME='inventory_transactions') THEN
        ALTER TABLE inventory_transactions ADD CONSTRAINT fk_transaction_order FOREIGN KEY (related_order_id) REFERENCES inventory_transactions(id) ON DELETE SET NULL;
    END IF;
END//
CALL migrate_order_reference_20261008()//
DROP PROCEDURE migrate_order_reference_20261008//
DELIMITER ;
