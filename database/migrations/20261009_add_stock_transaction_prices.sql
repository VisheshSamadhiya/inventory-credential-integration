-- Preserve existing rows while ensuring stock transaction price/vendor columns exist.
-- Also supports databases that already received the order-details migration.
DELIMITER //
DROP PROCEDURE IF EXISTS migrate_stock_transaction_prices_20261009//
CREATE PROCEDURE migrate_stock_transaction_prices_20261009()
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inventory_transactions' AND COLUMN_NAME='unit_price') THEN
        ALTER TABLE inventory_transactions ADD COLUMN unit_price DECIMAL(13,2) NULL AFTER reason;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inventory_transactions' AND COLUMN_NAME='total_price') THEN
        ALTER TABLE inventory_transactions ADD COLUMN total_price DECIMAL(15,2) NULL AFTER unit_price;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inventory_transactions' AND COLUMN_NAME='vendor') THEN
        ALTER TABLE inventory_transactions ADD COLUMN vendor VARCHAR(180) NULL AFTER total_price;
    END IF;
END//
CALL migrate_stock_transaction_prices_20261009()//
DROP PROCEDURE migrate_stock_transaction_prices_20261009//
DELIMITER ;
