DELIMITER //
DROP PROCEDURE IF EXISTS migrate_hardware_serial_modes_20261017//
CREATE PROCEDURE migrate_hardware_serial_modes_20261017()
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inventory_assets' AND COLUMN_NAME='internal_serial_number') THEN
        ALTER TABLE inventory_assets ADD COLUMN internal_serial_number VARCHAR(150) NULL AFTER serial_number;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inventory_assets' AND COLUMN_NAME='internal_serial_number' AND NON_UNIQUE=0) THEN
        ALTER TABLE inventory_assets ADD UNIQUE INDEX uq_inventory_assets_internal_serial (internal_serial_number);
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='asset_tag_jobs' AND COLUMN_NAME='serial_mode') THEN
        ALTER TABLE asset_tag_jobs ADD COLUMN serial_mode ENUM('manual','automatic','fill_later') NOT NULL DEFAULT 'fill_later' AFTER registration_type;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='asset_tag_jobs' AND COLUMN_NAME='serial_prefix') THEN
        ALTER TABLE asset_tag_jobs ADD COLUMN serial_prefix VARCHAR(60) NULL AFTER serial_mode;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='asset_tag_jobs' AND COLUMN_NAME='internal_serial_first_number') THEN
        ALTER TABLE asset_tag_jobs ADD COLUMN internal_serial_first_number BIGINT UNSIGNED NULL AFTER serial_prefix;
    END IF;
END//
DELIMITER ;
CALL migrate_hardware_serial_modes_20261017();
DROP PROCEDURE migrate_hardware_serial_modes_20261017;

CREATE TABLE IF NOT EXISTS internal_serial_prefix_sequences (
    prefix VARCHAR(60) NOT NULL PRIMARY KEY,
    next_number BIGINT UNSIGNED NOT NULL DEFAULT 1,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS manufacturer_serial_registry (
    serial_number VARCHAR(150) NOT NULL PRIMARY KEY,
    job_id BIGINT UNSIGNED NULL,
    job_offset INT UNSIGNED NULL,
    asset_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_manufacturer_serial_job_offset (job_id,job_offset),
    UNIQUE KEY uq_manufacturer_serial_asset (asset_id),
    INDEX idx_manufacturer_serial_job (job_id,job_offset),
    CONSTRAINT fk_manufacturer_serial_job FOREIGN KEY (job_id) REFERENCES asset_tag_jobs(id) ON DELETE RESTRICT,
    CONSTRAINT fk_manufacturer_serial_asset FOREIGN KEY (asset_id) REFERENCES inventory_assets(id) ON DELETE CASCADE
) ENGINE=InnoDB;

INSERT IGNORE INTO manufacturer_serial_registry(serial_number,asset_id)
    SELECT serial_number,id FROM inventory_assets WHERE serial_number IS NOT NULL;
