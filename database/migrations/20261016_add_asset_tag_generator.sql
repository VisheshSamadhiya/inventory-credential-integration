-- Durable, idempotent asset-tag generation and reconciliation support.
-- This migration does not change inventory_transactions or assignment quantities.
CREATE TABLE IF NOT EXISTS asset_tag_jobs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_key CHAR(64) NOT NULL UNIQUE,
    request_hash CHAR(64) NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    prefix VARCHAR(60) NOT NULL,
    starting_number BIGINT UNSIGNED NOT NULL,
    quantity INT UNSIGNED NOT NULL,
    padding_digits TINYINT UNSIGNED NOT NULL,
    asset_location VARCHAR(150) NULL,
    asset_condition VARCHAR(80) NULL,
    registration_type ENUM('available','reconciliation') NOT NULL,
    status ENUM('pending','processing','completed','failed') NOT NULL DEFAULT 'pending',
    next_offset INT UNSIGNED NOT NULL DEFAULT 0,
    batch_size SMALLINT UNSIGNED NOT NULL DEFAULT 250,
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    last_error VARCHAR(1000) NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    INDEX idx_asset_tag_jobs_work (status, id),
    INDEX idx_asset_tag_jobs_product (product_id, status),
    CONSTRAINT fk_asset_tag_job_product FOREIGN KEY (product_id) REFERENCES products(id),
    CONSTRAINT fk_asset_tag_job_user FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS asset_tag_ranges (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    job_id BIGINT UNSIGNED NOT NULL,
    prefix VARCHAR(60) NOT NULL,
    first_number BIGINT UNSIGNED NOT NULL,
    last_number BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_asset_tag_range_job (job_id),
    INDEX idx_asset_tag_range_prefix (prefix, first_number, last_number),
    CONSTRAINT fk_asset_tag_range_job FOREIGN KEY (job_id) REFERENCES asset_tag_jobs(id) ON DELETE RESTRICT,
    CONSTRAINT chk_asset_tag_range_order CHECK (last_number >= first_number)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS asset_tag_prefix_sequences (
    prefix VARCHAR(60) NOT NULL PRIMARY KEY,
    next_number BIGINT UNSIGNED NOT NULL DEFAULT 1,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS asset_tag_registry (
    asset_tag VARCHAR(100) NOT NULL PRIMARY KEY,
    prefix VARCHAR(60) NULL,
    numeric_value BIGINT UNSIGNED NULL,
    request_key CHAR(64) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_asset_tag_registry_numeric (prefix, numeric_value)
) ENGINE=InnoDB;

DELIMITER //
DROP PROCEDURE IF EXISTS migrate_asset_tag_generator_20261016//
CREATE PROCEDURE migrate_asset_tag_generator_20261016()
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inventory_assets' AND COLUMN_NAME='asset_condition') THEN
        ALTER TABLE inventory_assets ADD COLUMN asset_condition VARCHAR(80) NULL AFTER location;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inventory_assets' AND COLUMN_NAME='asset_tag_job_id') THEN
        ALTER TABLE inventory_assets ADD COLUMN asset_tag_job_id BIGINT UNSIGNED NULL AFTER asset_condition;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inventory_assets' AND INDEX_NAME='idx_inventory_asset_product_status') THEN
        ALTER TABLE inventory_assets ADD INDEX idx_inventory_asset_product_status (product_id,status);
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inventory_assets' AND INDEX_NAME='idx_inventory_asset_tag_job') THEN
        ALTER TABLE inventory_assets ADD INDEX idx_inventory_asset_tag_job (asset_tag_job_id);
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='assignments' AND INDEX_NAME='idx_assignment_product_tag_reconcile') THEN
        ALTER TABLE assignments ADD INDEX idx_assignment_product_tag_reconcile (product_id,status,returned_at,asset_id);
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='assignments' AND INDEX_NAME='idx_assignment_asset_status_returned') THEN
        ALTER TABLE assignments ADD INDEX idx_assignment_asset_status_returned (asset_id,status,returned_at);
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='inventory_assets' AND CONSTRAINT_NAME='fk_inventory_asset_tag_job') THEN
        ALTER TABLE inventory_assets ADD CONSTRAINT fk_inventory_asset_tag_job FOREIGN KEY (asset_tag_job_id) REFERENCES asset_tag_jobs(id) ON DELETE SET NULL;
    END IF;
    INSERT IGNORE INTO asset_tag_registry (asset_tag, prefix, numeric_value, request_key)
        SELECT asset_tag, NULL, NULL, NULL FROM inventory_assets;
END//
DELIMITER ;
CALL migrate_asset_tag_generator_20261016();
DROP PROCEDURE migrate_asset_tag_generator_20261016;
