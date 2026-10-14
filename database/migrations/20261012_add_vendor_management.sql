-- Add vendor master data and immutable vendor details on order transactions.
-- Legacy order text in inventory_transactions.vendor is deliberately preserved.
CREATE TABLE IF NOT EXISTS vendors (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vendor_name VARCHAR(180) NOT NULL,
    contact_person VARCHAR(150) NULL,
    phone VARCHAR(40) NULL,
    email VARCHAR(190) NULL,
    address TEXT NULL,
    gstin VARCHAR(15) NULL,
    notes TEXT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_vendor_name (vendor_name),
    UNIQUE KEY uq_vendor_gstin (gstin),
    CONSTRAINT fk_vendor_created_by FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB;

DELIMITER //
DROP PROCEDURE IF EXISTS migrate_vendor_management_20261012//
CREATE PROCEDURE migrate_vendor_management_20261012()
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inventory_transactions' AND COLUMN_NAME='vendor_id') THEN
        ALTER TABLE inventory_transactions ADD COLUMN vendor_id BIGINT UNSIGNED NULL AFTER vendor;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inventory_transactions' AND COLUMN_NAME='vendor_name_snapshot') THEN
        ALTER TABLE inventory_transactions ADD COLUMN vendor_name_snapshot VARCHAR(180) NULL AFTER vendor_id;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inventory_transactions' AND COLUMN_NAME='vendor_contact_snapshot') THEN
        ALTER TABLE inventory_transactions ADD COLUMN vendor_contact_snapshot VARCHAR(150) NULL AFTER vendor_name_snapshot;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inventory_transactions' AND COLUMN_NAME='vendor_phone_snapshot') THEN
        ALTER TABLE inventory_transactions ADD COLUMN vendor_phone_snapshot VARCHAR(40) NULL AFTER vendor_contact_snapshot;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inventory_transactions' AND COLUMN_NAME='vendor_email_snapshot') THEN
        ALTER TABLE inventory_transactions ADD COLUMN vendor_email_snapshot VARCHAR(190) NULL AFTER vendor_phone_snapshot;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inventory_transactions' AND COLUMN_NAME='vendor_address_snapshot') THEN
        ALTER TABLE inventory_transactions ADD COLUMN vendor_address_snapshot TEXT NULL AFTER vendor_email_snapshot;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inventory_transactions' AND COLUMN_NAME='vendor_gstin_snapshot') THEN
        ALTER TABLE inventory_transactions ADD COLUMN vendor_gstin_snapshot VARCHAR(15) NULL AFTER vendor_address_snapshot;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inventory_transactions' AND INDEX_NAME='idx_transaction_vendor') THEN
        ALTER TABLE inventory_transactions ADD INDEX idx_transaction_vendor (vendor_id);
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='inventory_transactions' AND CONSTRAINT_NAME='fk_transaction_vendor') THEN
        ALTER TABLE inventory_transactions ADD CONSTRAINT fk_transaction_vendor FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE SET NULL;
    END IF;
END//
DELIMITER ;
CALL migrate_vendor_management_20261012();
DROP PROCEDURE migrate_vendor_management_20261012;
