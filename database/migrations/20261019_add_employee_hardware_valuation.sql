-- Preserve the cost source for registered hardware and snapshot supported
-- assignment / return values on each employee hardware-history event.
DELIMITER //
DROP PROCEDURE IF EXISTS migrate_employee_hardware_valuation_20261019//
CREATE PROCEDURE migrate_employee_hardware_valuation_20261019()
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inventory_assets' AND COLUMN_NAME='acquisition_cost_layer_id') THEN
        ALTER TABLE inventory_assets ADD COLUMN acquisition_cost_layer_id BIGINT UNSIGNED NULL AFTER acquisition_unit_cost;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inventory_assets' AND COLUMN_NAME='acquisition_cost_layer_id' AND REFERENCED_TABLE_NAME='inventory_cost_layers') THEN
        ALTER TABLE inventory_assets ADD CONSTRAINT fk_inventory_asset_cost_layer FOREIGN KEY (acquisition_cost_layer_id) REFERENCES inventory_cost_layers(id) ON DELETE SET NULL;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='hardware_assignment_history' AND COLUMN_NAME='transaction_id') THEN
        ALTER TABLE hardware_assignment_history ADD COLUMN transaction_id BIGINT UNSIGNED NULL AFTER performed_by;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='hardware_assignment_history' AND COLUMN_NAME='event_quantity') THEN
        ALTER TABLE hardware_assignment_history ADD COLUMN event_quantity INT UNSIGNED NULL AFTER transaction_id;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='hardware_assignment_history' AND COLUMN_NAME='cost_covered_quantity') THEN
        ALTER TABLE hardware_assignment_history ADD COLUMN cost_covered_quantity INT UNSIGNED NULL AFTER event_quantity;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='hardware_assignment_history' AND COLUMN_NAME='acquisition_value') THEN
        ALTER TABLE hardware_assignment_history ADD COLUMN acquisition_value DECIMAL(13,2) NULL AFTER cost_covered_quantity;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='hardware_assignment_history' AND INDEX_NAME='idx_hardware_history_transaction') THEN
        ALTER TABLE hardware_assignment_history ADD INDEX idx_hardware_history_transaction (transaction_id);
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='hardware_assignment_history' AND COLUMN_NAME='transaction_id' AND REFERENCED_TABLE_NAME='inventory_transactions') THEN
        ALTER TABLE hardware_assignment_history ADD CONSTRAINT fk_hardware_history_transaction FOREIGN KEY (transaction_id) REFERENCES inventory_transactions(id) ON DELETE SET NULL;
    END IF;

    IF EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='hardware_assignment_history' AND COLUMN_NAME='action' AND (COLUMN_TYPE NOT LIKE '%reassigned%' OR COLUMN_TYPE NOT LIKE '%tagged%')) THEN
        ALTER TABLE hardware_assignment_history MODIFY action ENUM('assigned','replaced','returned','removed','reassigned','tagged') NOT NULL;
    END IF;
END//
CALL migrate_employee_hardware_valuation_20261019()//
DROP PROCEDURE migrate_employee_hardware_valuation_20261019//
DELIMITER ;

-- Cost reconciliations already identify the source layer for reconciled assets.
UPDATE inventory_assets ia
JOIN inventory_cost_reconciliations r ON r.asset_id=ia.id AND r.kind='asset'
JOIN inventory_cost_layers l ON l.reconciliation_id=r.id
SET ia.acquisition_cost_layer_id=l.id
WHERE ia.acquisition_cost_layer_id IS NULL;

-- Tag reconciliation changes an existing assignment's representation; it is
-- not a new ownership transfer and must not inflate historical assigned value.
UPDATE hardware_assignment_history
SET action='tagged'
WHERE action='assigned' AND reason LIKE 'Asset tag reconciliation from existing assignment #%';

-- Connect legacy hardware events to their stock transactions where the
-- transaction notes already carry the exact assignment reference.
UPDATE hardware_assignment_history h
JOIN assignments a ON a.id=h.assignment_id
SET h.transaction_id=(
    SELECT t.id FROM inventory_transactions t
    WHERE t.product_id=a.product_id
      AND ((h.action IN ('assigned','reassigned','tagged') AND t.transaction_type='out')
        OR (h.action IN ('returned','removed','replaced') AND t.transaction_type='in'))
      AND t.notes REGEXP CONCAT('assignment #[[:space:]]*',a.id,'([^0-9]|$)')
    ORDER BY ABS(TIMESTAMPDIFF(SECOND,h.performed_at,t.transaction_at)),t.id LIMIT 1
)
WHERE h.transaction_id IS NULL;

-- Backfill event snapshots only from the asset's recorded cost, existing FIFO
-- allocations, or the linked stock transaction. Unsupported costs stay NULL.
UPDATE hardware_assignment_history h
JOIN assignments a ON a.id=h.assignment_id
LEFT JOIN inventory_assets old_asset ON old_asset.id=h.old_asset_id
LEFT JOIN inventory_assets new_asset ON new_asset.id=h.new_asset_id
LEFT JOIN inventory_assets assigned_asset ON assigned_asset.id=a.asset_id
LEFT JOIN inventory_cost_layers old_asset_layer ON old_asset_layer.id=old_asset.acquisition_cost_layer_id
LEFT JOIN inventory_cost_layers new_asset_layer ON new_asset_layer.id=new_asset.acquisition_cost_layer_id
LEFT JOIN inventory_cost_layers assigned_asset_layer ON assigned_asset_layer.id=assigned_asset.acquisition_cost_layer_id
LEFT JOIN inventory_transactions event_tx ON event_tx.id=h.transaction_id
LEFT JOIN (
    SELECT assignment_id,
           SUM(quantity) assigned_units,
           SUM(quantity*unit_cost) assigned_value,
           SUM(returned_quantity) returned_units,
           SUM(returned_quantity*unit_cost) returned_value
    FROM inventory_cost_allocations WHERE source_type='assignment' GROUP BY assignment_id
) allocation ON allocation.assignment_id=a.id
SET h.event_quantity=COALESCE(h.event_quantity,
        CASE WHEN h.action='replaced' THEN 1
             WHEN COALESCE(h.new_asset_id,h.old_asset_id,a.asset_id) IS NOT NULL THEN 1
             WHEN event_tx.id IS NOT NULL THEN ABS(event_tx.quantity)
             ELSE a.quantity END),
    h.cost_covered_quantity=COALESCE(h.cost_covered_quantity,
        CASE
          WHEN h.action IN ('assigned','reassigned','tagged') AND COALESCE(new_asset.acquisition_unit_cost,new_asset_layer.unit_cost,assigned_asset.acquisition_unit_cost,assigned_asset_layer.unit_cost) IS NOT NULL THEN 1
          WHEN h.action IN ('returned','removed') AND event_tx.total_price IS NOT NULL THEN ABS(event_tx.quantity)
          WHEN h.action IN ('returned','removed') AND COALESCE(old_asset.acquisition_unit_cost,old_asset_layer.unit_cost) IS NOT NULL THEN 1
          WHEN h.action='replaced' AND COALESCE(new_asset.acquisition_unit_cost,new_asset_layer.unit_cost) IS NOT NULL THEN 1
          WHEN h.action IN ('assigned','reassigned','tagged') THEN LEAST(COALESCE(ABS(event_tx.quantity),a.quantity),COALESCE(allocation.assigned_units,0))
          WHEN h.action IN ('returned','removed') THEN LEAST(COALESCE(event_tx.quantity,a.returned_quantity),COALESCE(allocation.returned_units,0))
          ELSE 0
        END),
    h.acquisition_value=COALESCE(h.acquisition_value,
        CASE
          WHEN h.action IN ('assigned','reassigned','tagged') AND COALESCE(new_asset.acquisition_unit_cost,new_asset_layer.unit_cost,assigned_asset.acquisition_unit_cost,assigned_asset_layer.unit_cost) IS NOT NULL THEN COALESCE(new_asset.acquisition_unit_cost,new_asset_layer.unit_cost,assigned_asset.acquisition_unit_cost,assigned_asset_layer.unit_cost)
          WHEN h.action='replaced' AND COALESCE(new_asset.acquisition_unit_cost,new_asset_layer.unit_cost) IS NOT NULL THEN COALESCE(new_asset.acquisition_unit_cost,new_asset_layer.unit_cost)
          WHEN h.action IN ('returned','removed') AND event_tx.total_price IS NOT NULL THEN event_tx.total_price
          WHEN h.action IN ('returned','removed') AND COALESCE(old_asset.acquisition_unit_cost,old_asset_layer.unit_cost) IS NOT NULL THEN COALESCE(old_asset.acquisition_unit_cost,old_asset_layer.unit_cost)
          WHEN h.action IN ('assigned','reassigned','tagged') AND allocation.assigned_value IS NOT NULL THEN allocation.assigned_value
          ELSE NULL
        END);

-- Split legacy replacement history into its return and reassignment events.
-- The transaction references point to the corresponding stock-in and stock-out.
INSERT INTO hardware_assignment_history(employee_id,assignment_id,action,reason,new_asset_id,performed_by,transaction_id,event_quantity,cost_covered_quantity,acquisition_value,performed_at)
SELECT h.employee_id,new_assignment.id,'reassigned',h.reason,h.new_asset_id,h.performed_by,assignment_tx.id,1,
       CASE WHEN COALESCE(new_asset.acquisition_unit_cost,new_asset_layer.unit_cost) IS NULL THEN 0 ELSE 1 END,
       COALESCE(new_asset.acquisition_unit_cost,new_asset_layer.unit_cost),h.performed_at
FROM hardware_assignment_history h
JOIN assignments new_assignment ON new_assignment.asset_id=h.new_asset_id AND new_assignment.employee_record_id=h.employee_id AND new_assignment.id<>h.assignment_id
LEFT JOIN inventory_assets new_asset ON new_asset.id=h.new_asset_id
LEFT JOIN inventory_cost_layers new_asset_layer ON new_asset_layer.id=new_asset.acquisition_cost_layer_id
LEFT JOIN inventory_transactions assignment_tx ON assignment_tx.id=(
    SELECT t.id FROM inventory_transactions t
    WHERE t.product_id=new_assignment.product_id AND t.transaction_type='out'
      AND t.notes REGEXP CONCAT('assignment #[[:space:]]*',new_assignment.id,'([^0-9]|$)')
    ORDER BY t.id LIMIT 1
)
WHERE h.action='replaced'
  AND NOT EXISTS (SELECT 1 FROM hardware_assignment_history existing WHERE existing.assignment_id=new_assignment.id AND existing.action IN ('assigned','reassigned','tagged'));

UPDATE hardware_assignment_history h
LEFT JOIN inventory_assets old_asset ON old_asset.id=h.old_asset_id
LEFT JOIN inventory_cost_layers old_asset_layer ON old_asset_layer.id=old_asset.acquisition_cost_layer_id
LEFT JOIN inventory_transactions return_tx ON return_tx.id=h.transaction_id
SET h.action='returned',h.new_asset_id=NULL,h.event_quantity=1,
    h.cost_covered_quantity=CASE WHEN COALESCE(old_asset.acquisition_unit_cost,old_asset_layer.unit_cost) IS NOT NULL THEN 1 WHEN return_tx.total_price IS NOT NULL THEN ABS(return_tx.quantity) ELSE 0 END,
    h.acquisition_value=COALESCE(old_asset.acquisition_unit_cost,old_asset_layer.unit_cost,return_tx.total_price)
WHERE h.action='replaced';

-- Older bulk assignments did not write hardware-history rows. Restore their
-- assignment event once, attaching the source transaction to prevent duplicate
-- display/counting of the same stock movement.
INSERT INTO hardware_assignment_history(employee_id,assignment_id,action,reason,new_asset_id,performed_by,transaction_id,event_quantity,cost_covered_quantity,acquisition_value,performed_at)
SELECT COALESCE(a.employee_record_id,e.id),a.id,
       CASE WHEN EXISTS (SELECT 1 FROM hardware_assignment_history prior WHERE prior.action='replaced' AND prior.employee_id=COALESCE(a.employee_record_id,e.id) AND prior.new_asset_id=a.asset_id) THEN 'reassigned' ELSE 'assigned' END,
       'Historical assignment event',NULL,a.assigned_by,tx.id,
       CASE WHEN a.asset_id IS NOT NULL THEN 1 ELSE COALESCE(ABS(tx.quantity),a.quantity) END,
       CASE WHEN a.asset_id IS NOT NULL AND COALESCE(ia.acquisition_unit_cost,ia_layer.unit_cost) IS NOT NULL THEN 1
            WHEN allocation.assigned_units IS NOT NULL THEN LEAST(COALESCE(ABS(tx.quantity),a.quantity),allocation.assigned_units)
            ELSE 0 END,
       CASE WHEN a.asset_id IS NOT NULL THEN COALESCE(ia.acquisition_unit_cost,ia_layer.unit_cost)
            WHEN allocation.assigned_value IS NOT NULL THEN allocation.assigned_value
            ELSE NULL END,
       a.assigned_at
FROM assignments a
JOIN employees e ON (a.employee_record_id=e.id OR (a.employee_record_id IS NULL AND a.employee_id=e.employee_id))
LEFT JOIN inventory_assets ia ON ia.id=a.asset_id
LEFT JOIN inventory_cost_layers ia_layer ON ia_layer.id=ia.acquisition_cost_layer_id
LEFT JOIN (
    SELECT assignment_id,SUM(quantity) assigned_units,SUM(quantity*unit_cost) assigned_value
    FROM inventory_cost_allocations WHERE source_type='assignment' GROUP BY assignment_id
) allocation ON allocation.assignment_id=a.id
LEFT JOIN inventory_transactions tx ON tx.id=(
    SELECT t.id FROM inventory_transactions t
    WHERE t.product_id=a.product_id AND t.transaction_type='out'
      AND t.notes REGEXP CONCAT('assignment #[[:space:]]*',a.id,'([^0-9]|$)')
    ORDER BY t.id LIMIT 1
)
WHERE (a.notes IS NULL OR a.notes NOT LIKE 'Tag reconciled from assignment #%')
  AND NOT EXISTS (SELECT 1 FROM hardware_assignment_history existing WHERE existing.assignment_id=a.id AND existing.action IN ('assigned','reassigned','tagged'))
  AND NOT EXISTS (SELECT 1 FROM hardware_assignment_history replacement WHERE replacement.action='replaced' AND replacement.employee_id=COALESCE(a.employee_record_id,e.id) AND replacement.new_asset_id=a.asset_id);

-- Add historical bulk returns/removals that have a distinct stock transaction
-- and no matching hardware event yet. Each transaction is one event.
INSERT INTO hardware_assignment_history(employee_id,assignment_id,action,reason,old_asset_id,performed_by,transaction_id,event_quantity,cost_covered_quantity,acquisition_value,performed_at)
SELECT COALESCE(a.employee_record_id,e.id),a.id,
       CASE WHEN LOWER(t.reason) LIKE '%hardware removed%' THEN 'removed' ELSE 'returned' END,
       t.reason,a.asset_id,t.created_by,t.id,ABS(t.quantity),
       CASE WHEN t.total_price IS NULL THEN 0 ELSE ABS(t.quantity) END,
       t.total_price,t.transaction_at
FROM inventory_transactions t
JOIN assignments a ON a.product_id=t.product_id
JOIN employees e ON (a.employee_record_id=e.id OR (a.employee_record_id IS NULL AND a.employee_id=e.employee_id))
WHERE t.transaction_type='in'
  AND (LOWER(t.reason) LIKE '%assignment return%' OR LOWER(t.reason) LIKE '%hardware returned%' OR LOWER(t.reason) LIKE '%hardware removed%')
  AND t.notes REGEXP CONCAT('assignment #[[:space:]]*',a.id,'([^0-9]|$)')
  AND NOT EXISTS (SELECT 1 FROM hardware_assignment_history existing WHERE existing.transaction_id=t.id);
