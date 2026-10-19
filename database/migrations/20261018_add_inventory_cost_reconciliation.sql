-- Forward-only, idempotent valuation repair. Existing stock transactions are not rewritten.
DELIMITER //
DROP PROCEDURE IF EXISTS migrate_inventory_cost_reconciliation_20261018//
CREATE PROCEDURE migrate_inventory_cost_reconciliation_20261018()
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inventory_assets' AND COLUMN_NAME='acquisition_unit_cost') THEN
        ALTER TABLE inventory_assets ADD COLUMN acquisition_unit_cost DECIMAL(13,2) NULL;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inventory_cost_layers' AND COLUMN_NAME='source_transaction_id') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='inventory_cost_layers must be created before valuation reconciliation';
    END IF;
    IF EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inventory_cost_layers' AND COLUMN_NAME='source_transaction_id' AND IS_NULLABLE='NO') THEN
        ALTER TABLE inventory_cost_layers MODIFY source_transaction_id BIGINT UNSIGNED NULL;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inventory_cost_layers' AND COLUMN_NAME='reconciliation_id') THEN
        ALTER TABLE inventory_cost_layers ADD COLUMN reconciliation_id BIGINT UNSIGNED NULL AFTER source_transaction_id;
    END IF;
END//
DELIMITER ;
CALL migrate_inventory_cost_reconciliation_20261018();
DROP PROCEDURE migrate_inventory_cost_reconciliation_20261018;

CREATE TABLE IF NOT EXISTS inventory_cost_reconciliations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    kind ENUM('receipt','asset','assignment','disposal') NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    source_transaction_id BIGINT UNSIGNED NULL,
    asset_id BIGINT UNSIGNED NULL,
    assignment_id BIGINT UNSIGNED NULL,
    quantity INT UNSIGNED NOT NULL,
    unit_cost DECIMAL(13,2) NOT NULL,
    source_reference VARCHAR(180) NOT NULL,
    notes TEXT NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_cost_reconciliation_product (product_id, created_at),
    INDEX idx_cost_reconciliation_source (source_transaction_id),
    CONSTRAINT fk_cost_reconciliation_product FOREIGN KEY (product_id) REFERENCES products(id),
    CONSTRAINT fk_cost_reconciliation_transaction FOREIGN KEY (source_transaction_id) REFERENCES inventory_transactions(id) ON DELETE RESTRICT,
    CONSTRAINT fk_cost_reconciliation_asset FOREIGN KEY (asset_id) REFERENCES inventory_assets(id) ON DELETE RESTRICT,
    CONSTRAINT fk_cost_reconciliation_assignment FOREIGN KEY (assignment_id) REFERENCES assignments(id) ON DELETE RESTRICT,
    CONSTRAINT fk_cost_reconciliation_user FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- Backfill only receipt rows already linked to a priced, confirmed/received order.
-- Remaining quantity is only supported when no stock has left the product and
-- no asset registration has transferred its cost. Consumed legacy receipts need
-- explicit reconciliation; never assume their full quantity is still available.
INSERT IGNORE INTO inventory_cost_layers(product_id,source_transaction_id,unit_cost,original_quantity,remaining_quantity)
SELECT receipt.product_id,receipt.id,COALESCE(po.unit_price,po.total_price/NULLIF(ABS(po.quantity),0)),ABS(receipt.quantity),ABS(receipt.quantity)
FROM inventory_transactions receipt
JOIN inventory_transactions po ON po.id=receipt.related_order_id AND po.transaction_type='ordered'
WHERE receipt.transaction_type='in'
  AND receipt.status='Received'
  AND po.status IN ('Confirmed','Received')
  AND COALESCE(po.unit_price,po.total_price/NULLIF(ABS(po.quantity),0)) IS NOT NULL
  AND receipt.quantity>0
  AND NOT EXISTS (SELECT 1 FROM inventory_transactions movement WHERE movement.product_id=receipt.product_id AND (movement.transaction_type='out' OR (movement.transaction_type='adjustment' AND movement.quantity<0)))
  AND NOT EXISTS (SELECT 1 FROM inventory_assets asset WHERE asset.product_id=receipt.product_id);

DELIMITER //
DROP PROCEDURE IF EXISTS add_cost_layer_reconciliation_fk_20261018//
CREATE PROCEDURE add_cost_layer_reconciliation_fk_20261018()
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inventory_cost_layers' AND COLUMN_NAME='reconciliation_id' AND REFERENCED_TABLE_NAME='inventory_cost_reconciliations') THEN
        ALTER TABLE inventory_cost_layers ADD CONSTRAINT fk_inventory_cost_layer_reconciliation FOREIGN KEY (reconciliation_id) REFERENCES inventory_cost_reconciliations(id) ON DELETE RESTRICT;
    END IF;
END//
DELIMITER ;
CALL add_cost_layer_reconciliation_fk_20261018();
DROP PROCEDURE add_cost_layer_reconciliation_fk_20261018;

DELIMITER //
DROP TRIGGER IF EXISTS trg_inventory_cost_reconciliation_immutable_update//
CREATE TRIGGER trg_inventory_cost_reconciliation_immutable_update BEFORE UPDATE ON inventory_cost_reconciliations
FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Historical cost reconciliations are immutable'; END//
DROP TRIGGER IF EXISTS trg_inventory_cost_reconciliation_immutable_delete//
CREATE TRIGGER trg_inventory_cost_reconciliation_immutable_delete BEFORE DELETE ON inventory_cost_reconciliations
FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Historical cost reconciliations are immutable'; END//
DELIMITER ;
