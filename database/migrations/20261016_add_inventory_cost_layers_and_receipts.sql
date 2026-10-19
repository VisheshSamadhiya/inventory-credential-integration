DELIMITER //
DROP PROCEDURE IF EXISTS migrate_inventory_cost_layers_20261016//
CREATE PROCEDURE migrate_inventory_cost_layers_20261016()
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inventory_transactions' AND COLUMN_NAME='reference_number') THEN
        ALTER TABLE inventory_transactions ADD COLUMN reference_number VARCHAR(180) NULL AFTER notes;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inventory_assets' AND COLUMN_NAME='acquisition_unit_cost') THEN
        ALTER TABLE inventory_assets ADD COLUMN acquisition_unit_cost DECIMAL(13,2) NULL;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='assignments' AND COLUMN_NAME='returned_quantity') THEN
        ALTER TABLE assignments ADD COLUMN returned_quantity INT UNSIGNED NOT NULL DEFAULT 0 AFTER quantity;
    END IF;
END//
DELIMITER ;
CALL migrate_inventory_cost_layers_20261016();
DROP PROCEDURE migrate_inventory_cost_layers_20261016;

CREATE TABLE IF NOT EXISTS inventory_cost_layers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id BIGINT UNSIGNED NOT NULL,
    source_transaction_id BIGINT UNSIGNED NOT NULL UNIQUE,
    unit_cost DECIMAL(13,2) NOT NULL,
    original_quantity INT UNSIGNED NOT NULL,
    remaining_quantity INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_inventory_cost_layers_available (product_id, remaining_quantity, id),
    CONSTRAINT fk_inventory_cost_layer_product FOREIGN KEY (product_id) REFERENCES products(id),
    CONSTRAINT fk_inventory_cost_layer_transaction FOREIGN KEY (source_transaction_id) REFERENCES inventory_transactions(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS inventory_cost_allocations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cost_layer_id BIGINT UNSIGNED NOT NULL,
    assignment_id BIGINT UNSIGNED NULL,
    source_transaction_id BIGINT UNSIGNED NULL,
    source_type ENUM('assignment','stock_removal') NOT NULL,
    quantity INT UNSIGNED NOT NULL,
    returned_quantity INT UNSIGNED NOT NULL DEFAULT 0,
    unit_cost DECIMAL(13,2) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_inventory_cost_allocation_assignment (assignment_id, id),
    INDEX idx_inventory_cost_allocation_transaction (source_transaction_id),
    CONSTRAINT fk_inventory_cost_allocation_layer FOREIGN KEY (cost_layer_id) REFERENCES inventory_cost_layers(id) ON DELETE RESTRICT,
    CONSTRAINT fk_inventory_cost_allocation_assignment FOREIGN KEY (assignment_id) REFERENCES assignments(id) ON DELETE RESTRICT,
    CONSTRAINT fk_inventory_cost_allocation_transaction FOREIGN KEY (source_transaction_id) REFERENCES inventory_transactions(id) ON DELETE RESTRICT
) ENGINE=InnoDB;
